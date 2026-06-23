<?php
declare(strict_types=1);

/*
 * PZ Cloud — files.php
 * All filesystem operations, written defensively. Every path the caller supplies
 * is sanitized segment-by-segment and confirmed to stay inside the share folder,
 * so directory traversal (../../etc/passwd) is impossible. Files are only ever
 * served through pzc_stream(); they are never directly reachable over HTTP
 * because storage/.htaccess denies all and disables the PHP engine.
 */

require_once __DIR__ . '/store.php';

/** Sanitize one path segment (a single file or folder name) to something safe. */
function pzc_sanitize_name(string $name): string {
    // Strip separators, NUL, and HTML-dangerous chars (defense-in-depth vs stored
    // XSS through filenames; output is also escaped at render time).
    $name = str_replace(array('/', '\\', "\0", '"', '<', '>'), '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$name);
    $name = trim((string)$name);
    $name = basename($name);
    if (preg_match('/^\.+$/', $name)) return '';   // ".", "..", "..."
    $name = ltrim($name, '.');                      // no hidden / .htaccess style names
    $name = trim($name);
    if ($name === '') return '';
    if (strlen($name) > 180) $name = substr($name, 0, 180);
    return $name;
}

/**
 * Turn a caller-supplied relative path into a safe, normalized relative path
 * (segments separated by '/'). Returns '' for the share root, or null if the
 * input tries anything fishy (.. or null bytes).
 */
function pzc_safe_rel(string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);
    if (strpos($rel, "\0") !== false) return null;
    $out = array();
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $clean = pzc_sanitize_name($seg);
        if ($clean === '') return null;
        $out[] = $clean;
    }
    return implode('/', $out);
}

/** Absolute path of a share's storage folder (created on demand). */
function pzc_share_dir(array $share): string {
    $dir = PZC_STORAGE . '/' . $share['slug'];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Resolve ($share, $rel) to an absolute path and guarantee it is inside the
 * share folder. $mustExist=false allows pointing at a not-yet-created file.
 * Returns absolute path or null on any violation.
 */
function pzc_resolve(array $share, string $rel, bool $mustExist = true): ?string {
    $safe = pzc_safe_rel($rel);
    if ($safe === null) return null;
    $base = pzc_share_dir($share);
    $baseReal = realpath($base);
    if ($baseReal === false) return null;
    $abs = $safe === '' ? $baseReal : $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);

    if ($mustExist) {
        $real = realpath($abs);
        if ($real === false) return null;
        if (!pzc_path_inside($baseReal, $real)) return null;
        return $real;
    }
    // For new files: the *parent* must already exist and be inside the base.
    $parentReal = realpath(dirname($abs));
    if ($parentReal === false) return null;
    if (!pzc_path_inside($baseReal, $parentReal)) return null;
    return $parentReal . DIRECTORY_SEPARATOR . basename($abs);
}

/** True if $path is the base dir itself or located underneath it. */
function pzc_path_inside(string $base, string $path): bool {
    $base = rtrim($base, DIRECTORY_SEPARATOR);
    if ($path === $base) return true;
    return strpos($path, $base . DIRECTORY_SEPARATOR) === 0;
}

/* ------------------------------------------------------------- listing */

function pzc_list(array $share, string $rel): array {
    $dir = pzc_resolve($share, $rel, true);
    if ($dir === null || !is_dir($dir)) return array('ok' => false, 'items' => array());
    $items = array();
    $dh = @opendir($dir);
    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            if ($f === '.' || $f === '..') continue;
            if ($f[0] === '.') continue; // hide .htaccess and .pzcup-*.part temp files
            $abs = $dir . DIRECTORY_SEPARATOR . $f;
            $isDir = is_dir($abs);
            $items[] = array(
                'name'  => $f,
                'type'  => $isDir ? 'dir' : 'file',
                'size'  => $isDir ? 0 : (int)@filesize($abs),
                'mtime' => (int)@filemtime($abs),
                'ctime' => (int)@filectime($abs), // ~ created/added time
                'rel'   => ($rel !== '' ? rtrim($rel, '/') . '/' : '') . $f,
            );
        }
        closedir($dh);
    }
    usort($items, function ($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return array('ok' => true, 'items' => $items);
}

/* ------------------------------------------------------------ mutations */

function pzc_mkdir(array $share, string $parentRel, string $name): bool {
    $name = pzc_sanitize_name($name);
    if ($name === '') return false;
    $parent = pzc_resolve($share, $parentRel, true);
    if ($parent === null || !is_dir($parent)) return false;
    $target = $parent . DIRECTORY_SEPARATOR . $name;
    if (file_exists($target)) return false;
    if (!pzc_path_inside(realpath(pzc_share_dir($share)) ?: '', $target)) return false;
    return @mkdir($target, 0755);
}

/** Safely create a nested directory chain ($rel) inside the share. Idempotent. */
function pzc_mkdirp(array $share, string $rel): bool {
    $safe = pzc_safe_rel($rel);
    if ($safe === null) return false;
    if ($safe === '') return true; // the share root always exists
    $base = realpath(pzc_share_dir($share));
    if ($base === false) return false;
    $cur = $base;
    foreach (explode('/', $safe) as $seg) {
        $next = $cur . DIRECTORY_SEPARATOR . $seg;
        if (is_link($next)) return false;                 // never descend through a symlink
        if (is_dir($next)) {
            $r = realpath($next);
            if ($r === false || !pzc_path_inside($base, $r)) return false;
            $cur = $r; continue;
        }
        if (file_exists($next)) return false;             // a non-dir occupies the name
        if (!@mkdir($next, 0755)) return false;
        $r = realpath($next);
        if ($r === false || !pzc_path_inside($base, $r)) { @rmdir($next); return false; }
        $cur = $r;
    }
    return true;
}

function pzc_delete(array $share, string $rel): bool {
    $abs = pzc_resolve($share, $rel, true);
    if ($abs === null) return false;
    $baseReal = realpath(pzc_share_dir($share));
    if ($baseReal === false || $abs === $baseReal) return false; // never delete the share root
    if (!pzc_path_inside($baseReal, $abs)) return false;
    return is_dir($abs) ? pzc_rrmdir($abs, $baseReal) : @unlink($abs);
}

/** Recursive delete, hard-bounded to stay under $baseReal. */
function pzc_rrmdir(string $dir, string $baseReal): bool {
    $real = realpath($dir);
    if ($real === false || !pzc_path_inside($baseReal, $real) || $real === $baseReal) return false;
    foreach (scandir($real) ?: array() as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $real . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) pzc_rrmdir($p, $baseReal); else @unlink($p);
    }
    return @rmdir($real);
}

function pzc_rename(array $share, string $rel, string $newName): bool {
    $newName = pzc_sanitize_name($newName);
    if ($newName === '') return false;
    $abs = pzc_resolve($share, $rel, true);
    if ($abs === null) return false;
    $baseReal = realpath(pzc_share_dir($share));
    if ($baseReal === false || $abs === $baseReal) return false;
    $target = dirname($abs) . DIRECTORY_SEPARATOR . $newName;
    if (file_exists($target)) return false;
    if (!pzc_path_inside($baseReal, $target)) return false;
    return @rename($abs, $target);
}

/** Move $rel (file or folder) INTO the folder $toDirRel, keeping its name. */
function pzc_move(array $share, string $rel, string $toDirRel): bool {
    $from = pzc_resolve($share, $rel, true);
    if ($from === null) return false;
    $baseReal = realpath(pzc_share_dir($share));
    if ($baseReal === false || $from === $baseReal) return false; // never move the root
    $toDir = pzc_resolve($share, $toDirRel, true);
    if ($toDir === null || !is_dir($toDir) || !pzc_path_inside($baseReal, $toDir)) return false;
    // refuse to move a folder into itself or into one of its own descendants
    if (is_dir($from)) {
        $fromReal = realpath($from);
        if ($fromReal !== false && ($toDir === $fromReal || pzc_path_inside($fromReal, $toDir))) return false;
    }
    $target = $toDir . DIRECTORY_SEPARATOR . basename($from);
    if (!pzc_path_inside($baseReal, $target)) return false;
    if ($from === $target || file_exists($target)) return false; // already there / name clash
    return @rename($from, $target);
}

/* ------------------------------------------------------- chunked upload */

/**
 * Accept one chunk of a chunked upload. The client POSTs the raw chunk bytes as
 * the request body (NOT multipart), so the only PHP limit in play is
 * post_max_size — and each chunk is kept well under it. Chunks are appended to a
 * hidden .part file and atomically renamed into place after the last one.
 * Returns an array ready to be JSON-encoded.
 */
/** Remove abandoned upload temp files (.pzcup-*.part) older than $maxAgeSec. */
function pzc_upload_gc(string $dir, int $maxAgeSec = 21600): void {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '.pzcup-*.part') ?: array() as $p) {
        $m = @filemtime($p);
        if ($m !== false && (time() - (int)$m) > $maxAgeSec) @unlink($p);
    }
}

function pzc_upload_chunk(array $share, string $relDir, string $rawName, int $chunk, int $total): array {
    if ($chunk < 0 || $total < 1 || $total > 1000000 || $chunk >= $total) {
        return array('ok' => false, 'msg' => 'Špatné parametry chunku.');
    }
    $name = pzc_sanitize_name($rawName);
    if ($name === '') return array('ok' => false, 'msg' => 'Neplatný název souboru.');

    $dir = pzc_resolve($share, $relDir, true);
    if ($dir === null || !is_dir($dir)) {
        // Auto-create the target folder chain (so a dropped folder's files land
        // even if the dir wasn't pre-created). Fails safely on an invalid path.
        if (!pzc_mkdirp($share, $relDir)) return array('ok' => false, 'msg' => 'Cílovou složku nelze vytvořit.');
        $dir = pzc_resolve($share, $relDir, true);
        if ($dir === null || !is_dir($dir)) return array('ok' => false, 'msg' => 'Cílová složka neexistuje.');
    }

    $baseReal = realpath(pzc_share_dir($share));
    if ($baseReal === false) return array('ok' => false, 'msg' => 'Chyba úložiště.');
    $final = $dir . DIRECTORY_SEPARATOR . $name;
    if (!pzc_path_inside($baseReal, $final)) return array('ok' => false, 'msg' => 'Neplatná cesta.');
    if (is_dir($final)) return array('ok' => false, 'msg' => 'Existuje složka se stejným názvem.');

    $uid  = sha1($relDir . '/' . $name . '|' . session_id());
    $part = $dir . DIRECTORY_SEPARATOR . '.pzcup-' . $uid . '.part';

    if ($chunk === 0) { @unlink($part); pzc_upload_gc($dir); }

    $data = file_get_contents('php://input');
    if ($data === false) return array('ok' => false, 'msg' => 'Žádná data.');

    // Refuse to keep writing when the volume is nearly full (host DoS guard),
    // regardless of the per-file cap.
    $minFree = (int)pzc_cfg('min_free_mb', 200) * 1024 * 1024;
    if ($minFree > 0) {
        $free = @disk_free_space(PZC_STORAGE);
        if ($free !== false && $free < $minFree) { @unlink($part); return array('ok' => false, 'msg' => 'Na serveru není dost místa. Kontaktuj správce.'); }
    }

    // Enforce the per-file size cap BEFORE committing the bytes to disk.
    $cap = (int)pzc_cfg('max_upload_mb', 0);
    if ($cap > 0) {
        $existing = is_file($part) ? (int)@filesize($part) : 0;
        if ($existing + strlen($data) > $cap * 1024 * 1024) {
            @unlink($part);
            return array('ok' => false, 'msg' => 'Soubor přesahuje limit ' . $cap . ' MB.');
        }
    }

    $fh = @fopen($part, 'ab');
    if (!$fh) return array('ok' => false, 'msg' => 'Nelze zapisovat do úložiště.');
    $ok = false;
    if (flock($fh, LOCK_EX)) { $ok = (fwrite($fh, $data) !== false); fflush($fh); flock($fh, LOCK_UN); }
    fclose($fh);
    if (!$ok) return array('ok' => false, 'msg' => 'Zápis selhal.');

    if ($chunk === $total - 1) {
        if (is_file($final)) @unlink($final);
        if (!@rename($part, $final)) { @unlink($part); return array('ok' => false, 'msg' => 'Dokončení uploadu selhalo.'); }
        @chmod($final, 0644);
        pzc_upload_gc($dir); // also sweep any other stale temp parts in this folder
        pzc_audit('upload', array('share' => $share['id'], 'name' => $name, 'size' => (int)@filesize($final)));
        return array('ok' => true, 'done' => true, 'name' => $name, 'size' => (int)@filesize($final));
    }
    return array('ok' => true, 'done' => false, 'chunk' => $chunk);
}

/* --------------------------------------------------------------- serving */

function pzc_mime(string $path): string {
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        // NOTE: no finfo_close() — deprecated in PHP 8.5 (finfo is freed automatically);
        // calling it spams php-error.log with E_DEPRECATED.
        if ($fi) { $mime = (string)finfo_file($fi, $path); }
    }
    return $mime !== '' ? $mime : 'application/octet-stream';
}

/** MIME types we are willing to render inline in the browser (safe to preview). */
function pzc_is_inline_safe(string $mime): bool {
    if (strpos($mime, 'image/') === 0 && $mime !== 'image/svg+xml') return true;
    if (strpos($mime, 'video/') === 0) return true;
    if (strpos($mime, 'audio/') === 0) return true;
    if ($mime === 'application/pdf') return true;
    if ($mime === 'text/plain') return true;
    return false;
}

/**
 * Stream a file to the client. $inline=true previews it in the browser (only for
 * inline-safe types — anything else is forced to download); $inline=false sends
 * it as an attachment. Supports HTTP Range so video/audio seeking and resumable
 * downloads work.
 */
function pzc_stream(string $absPath, string $downloadName, bool $inline): void {
    if (!is_file($absPath)) { http_response_code(404); echo 'Soubor nenalezen.'; exit; }
    $mime = pzc_mime($absPath);

    // Never render scriptable content inline; downgrade to a safe disposition/type.
    if ($inline && !pzc_is_inline_safe($mime)) { $inline = false; }
    if (!$inline) $mime = 'application/octet-stream';

    while (ob_get_level() > 0) ob_end_clean();
    @set_time_limit(0);

    $size = (int)filesize($absPath);
    $start = 0; $end = $size - 1; $code = 200;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            // suffix range: "bytes=-N" means the LAST N bytes
            $start = max(0, $size - (int)$m[2]);
            $end   = $size - 1;
        } else {
            if ($m[1] !== '') $start = (int)$m[1];
            if ($m[2] !== '') $end = (int)$m[2];
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $end = min($end, $size - 1);
        $code = 206;
    }

    $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $downloadName);
    $asciiName = str_replace('"', '', $asciiName);

    http_response_code($code);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('Cache-Control: private, max-age=0, must-revalidate');
    if ($code === 206) header("Content-Range: bytes $start-$end/$size");

    $fh = fopen($absPath, 'rb');
    if (!$fh) { exit; }
    fseek($fh, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = (int)min(262144, $remaining);
        $buf = fread($fh, $chunk);
        if ($buf === false) break;
        echo $buf;
        $remaining -= strlen($buf);
        if (function_exists('ob_get_level') && ob_get_level() > 0) @ob_flush();
        @flush();
    }
    fclose($fh);
    exit;
}

/** Recursively add a directory's files into $zip under $prefix. Returns count. */
function pzc_zip_collect(string $absDir, string $prefix, ZipArchive $zip): int {
    $base = realpath($absDir);
    if ($base === false) return 0;
    $n = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $abs = $f->getPathname();
            if (basename($abs)[0] === '.') continue; // skip hidden / .pzcup-*.part
            $local = $prefix . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, strlen($base))), '/');
            if ($local !== '') { $zip->addFile($abs, $local); $n++; }
        }
    } catch (\Throwable $e) { /* best-effort */ }
    return $n;
}

/**
 * Stream a ZIP. With $rels (list of relative paths) it zips exactly those
 * files/folders (selected items); otherwise it zips the whole $rel folder.
 */
function pzc_stream_zip(array $share, string $rel, array $rels = array()): void {
    if (!class_exists('ZipArchive')) { http_response_code(501); echo 'ZIP není na serveru dostupný.'; exit; }

    $tmp = PZC_DATA . '/.zip-' . bin2hex(random_bytes(8)) . '.zip';
    register_shutdown_function(function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); }); // clean up even on client disconnect
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { http_response_code(500); echo 'Nelze vytvořit ZIP.'; exit; }

    $count = 0; $label = '';
    if (!empty($rels)) {
        foreach ($rels as $r) {
            $abs = pzc_resolve($share, (string)$r, true); // each path re-validated inside the share
            if ($abs === null) continue;
            if (is_file($abs)) { if (basename($abs)[0] !== '.') { $zip->addFile($abs, basename($abs)); $count++; } }
            elseif (is_dir($abs)) { $count += pzc_zip_collect($abs, basename($abs) . '/', $zip); }
        }
        $label = 'vyber';
    } else {
        $dir = pzc_resolve($share, $rel, true);
        if ($dir === null || !is_dir($dir)) { $zip->close(); @unlink($tmp); http_response_code(404); echo 'Složka nenalezena.'; exit; }
        $count = pzc_zip_collect($dir, '', $zip);
        $label = ($rel !== '' ? str_replace('/', '-', $rel) : '');
    }
    $zip->close();

    if ($count === 0) { @unlink($tmp); http_response_code(404); echo 'Nic ke stažení.'; exit; }

    $name = ($share['name'] !== '' ? $share['name'] : 'soubory') . ($label !== '' ? '-' . $label : '');
    $name = preg_replace('/\s+/', '_', trim($name)) . '.zip';
    $ascii = str_replace('"', '', preg_replace('/[^\x20-\x7E]/', '_', $name));

    while (ob_get_level() > 0) ob_end_clean();
    @set_time_limit(0);
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)filesize($tmp));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Recursively sum the byte size of a share (for the admin overview). */
function pzc_share_size(array $share): int {
    $real = realpath(pzc_share_dir($share));
    if ($real === false) return 0;
    $total = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD // skip unreadable subdirs instead of throwing
        );
        foreach ($it as $f) {
            if ($f->isFile()) { $s = @$f->getSize(); if ($s !== false) $total += (int)$s; }
        }
    } catch (\Throwable $e) { /* best-effort size */ }
    return $total;
}

function pzc_human_size(int $bytes): string {
    $u = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0; $n = (float)$bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)$bytes : number_format($n, 1)) . ' ' . $u[$i];
}
