<?php
declare(strict_types=1);

/*
 * MyDrive — drive.php
 * Turns the multi-share storage engine (files.php) into a single personal
 * cloud drive for the owner. Adds the Drive-style extras that the raw engine
 * doesn't have: a Trash (soft-delete + restore), Starred items, a Recent feed,
 * and recursive Search — all built on the same safe path primitives, so the
 * directory-traversal guarantees from files.php still hold everywhere.
 */

require_once __DIR__ . '/files.php';

/* ------------------------------------------------------ the two fixed areas */

/** The owner's personal drive. Lives at storage/drive/. */
function md_drive(): array {
    return array('id' => 'mydrive', 'name' => 'Můj disk', 'slug' => 'drive');
}

/** The trash bin. Lives at storage/trashbin/ (web-denied like the rest). */
function md_trash_share(): array {
    return array('id' => 'trash', 'name' => 'Koš', 'slug' => 'trashbin');
}

/* ----------------------------------------------------------------- stars */
/* A star just records a drive-relative path. Kept in data/stars.json. */

function md_stars(): array {
    $d = pzc_db_read('stars');
    return is_array($d) ? $d : array();
}

function md_is_starred(string $rel): bool {
    $s = md_stars();
    return !empty($s[$rel]);
}

function md_star_toggle(string $rel): bool {
    $rel = (string)pzc_safe_rel($rel);
    if ($rel === '') return false;
    return (bool)pzc_db_mutate('stars', function (array &$s) use ($rel) {
        if (!empty($s[$rel])) { unset($s[$rel]); $s['__on'] = false; }
        else { $s[$rel] = true; $s['__on'] = true; }
        $on = !empty($s['__on']); unset($s['__on']);
        return $on;
    });
}

/** Re-key a star when its item is renamed/moved; drop it when removed. */
function md_star_rekey(string $oldRel, ?string $newRel): void {
    pzc_db_mutate('stars', function (array &$s) use ($oldRel, $newRel) {
        $hit = false;
        foreach (array_keys($s) as $k) {
            if ($k === $oldRel || strpos($k, $oldRel . '/') === 0) {
                $tail = substr($k, strlen($oldRel));
                unset($s[$k]);
                if ($newRel !== null) $s[$newRel . $tail] = true;
                $hit = true;
            }
        }
        return $hit;
    });
}

/** Every starred item that still exists on disk, newest first. */
function md_starred_items(): array {
    $drive = md_drive();
    $out = array();
    foreach (array_keys(md_stars()) as $rel) {
        $abs = pzc_resolve($drive, $rel, true);
        if ($abs === null) continue;
        $isDir = is_dir($abs);
        $out[] = array(
            'name'  => basename($rel),
            'type'  => $isDir ? 'dir' : 'file',
            'size'  => $isDir ? 0 : (int)@filesize($abs),
            'mtime' => (int)@filemtime($abs),
            'ctime' => (int)@filectime($abs),
            'rel'   => $rel,
        );
    }
    usort($out, function ($a, $b) { return (int)$b['mtime'] - (int)$a['mtime']; });
    return $out;
}

/* ------------------------------------------------------------- recursive scan */
/* Shared walker used by both Recent and Search. Bounded so a huge drive can
 * never blow the time/memory budget. Returns flat file entries with rel paths. */

function md_walk(int $maxFiles = 4000): array {
    $drive = md_drive();
    $base = realpath(pzc_share_dir($drive));
    if ($base === false) return array();
    $out = array(); $n = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $abs = $f->getPathname();
            if (basename($abs)[0] === '.') continue; // skip .pzcup-*.part etc.
            $rel = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, strlen($base))), '/');
            if ($rel === '') continue;
            $out[] = array(
                'name'  => basename($abs),
                'type'  => 'file',
                'size'  => (int)@$f->getSize(),
                'mtime' => (int)@$f->getMTime(),
                'ctime' => (int)@filectime($abs),
                'rel'   => $rel,
                'dir'   => trim(dirname($rel) === '.' ? '' : dirname($rel), '/'),
            );
            if (++$n >= $maxFiles) break;
        }
    } catch (\Throwable $e) { /* best-effort */ }
    return $out;
}

/** The $limit most-recently-modified files across the whole drive. */
function md_recent(int $limit = 60): array {
    $all = md_walk();
    usort($all, function ($a, $b) { return (int)$b['mtime'] - (int)$a['mtime']; });
    return array_slice($all, 0, $limit);
}

/** Case-insensitive filename search across the whole drive. */
function md_search(string $q, int $limit = 200): array {
    $q = trim(mb_strtolower($q));
    if ($q === '') return array();
    $out = array();
    foreach (md_walk() as $f) {
        if (mb_strpos(mb_strtolower($f['name']), $q) !== false) {
            $out[] = $f;
            if (count($out) >= $limit) break;
        }
    }
    usort($out, function ($a, $b) { return (int)$b['mtime'] - (int)$a['mtime']; });
    return $out;
}

/* ----------------------------------------------------------------- trash */
/* Soft-delete moves the item from the drive into storage/trashbin/ under a
 * unique physical name, and records where it came from in data/trash.json so
 * Restore can put it back. Permanent delete / empty trash wipe the bytes. */

function md_trash_index(): array {
    $d = pzc_db_read('trash');
    return is_array($d) ? $d : array();
}

/** Move ($rel) from the drive into the trash. Returns true on success. */
function md_trash_send(string $rel): bool {
    $drive = md_drive();
    $trash = md_trash_share();
    $from  = pzc_resolve($drive, $rel, true);
    if ($from === null) return false;
    $driveBase = realpath(pzc_share_dir($drive));
    if ($driveBase === false || $from === $driveBase) return false; // never trash the root
    if (!pzc_path_inside($driveBase, $from)) return false;

    $isDir = is_dir($from);
    $size  = $isDir ? 0 : (int)@filesize($from);
    $tid   = 't' . bin2hex(random_bytes(8));
    $phys  = $tid . '__' . basename($from);

    $target = pzc_resolve($trash, $phys, false); // parent (trashbin) must exist
    if ($target === null) return false;
    if (!@rename($from, $target)) return false;

    pzc_db_mutate('trash', function (array &$t) use ($tid, $phys, $rel, $isDir, $size) {
        $t[$tid] = array(
            'id'      => $tid,
            'phys'    => $phys,
            'orig'    => $rel,
            'name'    => basename($rel),
            'is_dir'  => $isDir,
            'size'    => $size,
            'deleted' => time(),
        );
        return true;
    });
    md_star_rekey($rel, null);          // a trashed item is no longer starred
    pzc_audit('trash_send', array('rel' => $rel, 'id' => $tid));
    return true;
}

/** Restore a trashed item back to its original location (or a free name). */
function md_trash_restore(string $tid): bool {
    $rec = md_trash_index()[$tid] ?? null;
    if (!is_array($rec)) return false;
    $drive = md_drive();
    $trash = md_trash_share();

    $src = pzc_resolve($trash, (string)$rec['phys'], true);
    if ($src === null) return false;

    // Make sure the original parent folder still exists.
    $orig    = (string)$rec['orig'];
    $parent  = trim(dirname($orig) === '.' ? '' : dirname($orig), '/');
    if ($parent !== '' && !pzc_mkdirp($drive, $parent)) return false;

    // Find a non-clashing destination name.
    $base = pzc_safe_rel($orig);
    if ($base === null) return false;
    $dest = pzc_resolve($drive, $base, false);
    if ($dest === null) return false;
    if (file_exists($dest)) {
        $name = basename($base);
        $dot  = strrpos($name, '.');
        $stem = ($dot !== false && !$rec['is_dir']) ? substr($name, 0, $dot) : $name;
        $ext  = ($dot !== false && !$rec['is_dir']) ? substr($name, $dot) : '';
        $i = 1;
        do {
            $cand = ($parent !== '' ? $parent . '/' : '') . $stem . ' (obnoveno' . ($i > 1 ? ' ' . $i : '') . ')' . $ext;
            $dest = pzc_resolve($drive, $cand, false);
            $i++;
        } while ($dest !== null && file_exists($dest) && $i < 100);
        $base = $cand;
    }
    if ($dest === null || !@rename($src, $dest)) return false;

    pzc_db_mutate('trash', function (array &$t) use ($tid) { unset($t[$tid]); return true; });
    pzc_audit('trash_restore', array('id' => $tid, 'to' => $base));
    return true;
}

/** Permanently delete one trashed item (bytes + record). */
function md_trash_purge(string $tid): bool {
    $rec = md_trash_index()[$tid] ?? null;
    if (!is_array($rec)) return false;
    $trash = md_trash_share();
    $abs = pzc_resolve($trash, (string)$rec['phys'], true);
    if ($abs !== null) {
        $base = realpath(pzc_share_dir($trash));
        if ($base !== false) { is_dir($abs) ? pzc_rrmdir($abs, $base) : @unlink($abs); }
    }
    pzc_db_mutate('trash', function (array &$t) use ($tid) { unset($t[$tid]); return true; });
    pzc_audit('trash_purge', array('id' => $tid));
    return true;
}

/** Empty the whole trash. */
function md_trash_empty(): bool {
    foreach (array_keys(md_trash_index()) as $tid) md_trash_purge((string)$tid);
    return true;
}

/** Trash contents for the UI, newest first. */
function md_trash_items(): array {
    $out = array();
    foreach (md_trash_index() as $rec) {
        if (!is_array($rec)) continue;
        $out[] = array(
            'id'      => $rec['id'],
            'name'    => $rec['name'],
            'type'    => !empty($rec['is_dir']) ? 'dir' : 'file',
            'size'    => (int)($rec['size'] ?? 0),
            'orig'    => $rec['orig'] ?? '',
            'deleted' => (int)($rec['deleted'] ?? 0),
        );
    }
    usort($out, function ($a, $b) { return (int)$b['deleted'] - (int)$a['deleted']; });
    return $out;
}

/* ----------------------------------------------------------- storage meter */

/** Used bytes, quota bytes (0 = unlimited), and a 0–100 percentage. */
function md_storage(): array {
    $used  = pzc_share_size(md_drive());
    $quota = (int)pzc_cfg('quota_gb', 0) * 1024 * 1024 * 1024;
    $pct   = $quota > 0 ? min(100, (int)round($used / $quota * 100)) : 0;
    return array('used' => $used, 'quota' => $quota, 'pct' => $pct);
}

/* ----------------------------------------------------------- public links */
/* A public share link grants read + download of ONE drive item (file or folder)
 * to anyone who has the token — optionally password-gated, optionally expiring.
 * Like Google Drive's "Anyone with the link". Tokens live in data/links.json
 * (web-denied). Strictly read-only: there is no public write path. */

function md_links_all(): array { $d = pzc_db_read('links'); return is_array($d) ? $d : array(); }

function md_link_active(array $l): bool {
    $e = (int)($l['expires'] ?? 0);
    return $e === 0 || $e > time();
}

/** Create a public link for drive item $rel. Returns ['id'=>,'token'=>raw] or null. */
function md_link_create(string $rel, bool $isDir, string $name, string $password = '', int $expires = 0, string $perm = 'read'): ?array {
    $raw = bin2hex(random_bytes(24));
    $rec = array(
        'id'        => 'l' . bin2hex(random_bytes(6)),
        'token'     => $raw,
        'rel'       => $rel,
        'name'      => $name,
        'is_dir'    => $isDir,
        'perm'      => ($perm === 'write' && $isDir) ? 'write' : 'read', // write only makes sense on folders
        'animal'    => md_random_animal(),
        'pass_hash' => $password !== '' ? password_hash($password, PASSWORD_BCRYPT, array('cost' => 12)) : '',
        'expires'   => $expires,
        'created'   => time(),
    );
    $ok = pzc_db_mutate('links', function (array &$l) use ($rec) { $l[] = $rec; return true; });
    if ($ok !== true) return null;
    pzc_audit('link_create', array('rel' => $rel, 'id' => $rec['id']));
    return array('id' => $rec['id'], 'token' => $raw);
}

/** Resolve a raw token to its (active) link record, or null. Constant-time match. */
function md_link_resolve(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '' || !ctype_xdigit($raw) || strlen($raw) > 200) return null;
    foreach (md_links_all() as $l) {
        if (hash_equals((string)($l['token'] ?? ''), $raw)) {
            return md_link_active($l) ? $l : null;
        }
    }
    return null;
}

function md_link_get(string $id): ?array {
    foreach (md_links_all() as $l) if (($l['id'] ?? '') === $id) return $l;
    return null;
}

/** All links pointing at a specific drive item. */
function md_links_for(string $rel): array {
    $out = array();
    foreach (md_links_all() as $l) if ((string)($l['rel'] ?? '') === $rel) $out[] = $l;
    return $out;
}

function md_link_delete(string $id): bool {
    pzc_db_mutate('links', function (array &$l) use ($id) {
        $l = array_values(array_filter($l, function ($x) use ($id) { return ($x['id'] ?? '') !== $id; }));
        return true;
    });
    pzc_audit('link_delete', array('id' => $id));
    return true;
}

/** Update an existing link: password (''=remove, null=keep), perm, expires (null=keep). */
function md_link_update(string $id, ?string $password, ?string $perm, ?int $expires): bool {
    return (bool)pzc_db_mutate('links', function (array &$l) use ($id, $password, $perm, $expires) {
        foreach ($l as &$x) {
            if (($x['id'] ?? '') === $id) {
                if ($password !== null) $x['pass_hash'] = $password !== '' ? password_hash($password, PASSWORD_BCRYPT, array('cost' => 12)) : '';
                if ($perm !== null && !empty($x['is_dir'])) $x['perm'] = $perm === 'write' ? 'write' : 'read';
                if ($expires !== null) $x['expires'] = $expires;
                return true;
            }
        }
        unset($x);
        return false;
    });
}

/** Public absolute URL for a link token. */
function md_link_url(string $token): string {
    return pzc_url('share.php?t=' . rawurlencode($token));
}

/* ----------------------------------------------------------- animal avatars */
/* Each share link (and the owner) gets a stable random animal emoji so the admin
 * can tell recipients apart at a glance — who has access where, who uploaded what. */

function md_animals(): array {
    return array('🦊','🐼','🦁','🐯','🐨','🐵','🐶','🐱','🦉','🐺','🦝','🐰','🐸','🐷','🐮','🐔','🐧','🦄','🐙','🦋','🐢','🐬','🦅','🦌','🐝','🐳','🦜','🐴','🐲','🦔','🦩','🐞');
}
function md_random_animal(): string {
    $a = md_animals();
    return $a[random_int(0, count($a) - 1)];
}
/** The owner's animal — generated once and stored in config. */
function md_owner_animal(): string {
    $a = (string)pzc_cfg('owner_animal', '');
    if ($a === '') { $a = md_random_animal(); pzc_cfg_save(array('owner_animal' => $a)); }
    return $a;
}

/* ----------------------------------------------------- transfer statistics */
/* Daily aggregates in data/stats.json (down/up bytes + counts) and a recent
 * activity ring in data/activity.json (who/what/when) for the admin panel. */

function md_log_transfer(string $dir, int $bytes, string $name = '', string $who = ''): void {
    $dir = ($dir === 'up') ? 'up' : 'down';
    $day = date('Y-m-d');
    pzc_db_mutate('stats', function (array &$s) use ($day, $dir, $bytes) {
        if (!isset($s[$day]) || !is_array($s[$day])) $s[$day] = array('down' => 0, 'up' => 0, 'dn' => 0, 'un' => 0);
        $s[$day][$dir] = (int)($s[$day][$dir] ?? 0) + max(0, $bytes);
        $s[$day][$dir === 'up' ? 'un' : 'dn'] = (int)($s[$day][$dir === 'up' ? 'un' : 'dn'] ?? 0) + 1;
        return true;
    });
    pzc_db_mutate('activity', function (array &$a) use ($dir, $bytes, $name, $who) {
        $a[] = array('ts' => time(), 'dir' => $dir, 'bytes' => max(0, $bytes), 'name' => $name, 'who' => $who);
        if (count($a) > 120) $a = array_slice($a, -120); // keep last 120
        return true;
    });
}

/** Summary for the admin: today + this-month totals, last 14 days, last 6 months. */
function md_stats_summary(): array {
    $s = pzc_db_read('stats'); if (!is_array($s)) $s = array();
    $today = date('Y-m-d'); $month = date('Y-m');
    $days = array();
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400); $r = is_array($s[$d] ?? null) ? $s[$d] : array();
        $days[] = array('day' => $d, 'down' => (int)($r['down'] ?? 0), 'up' => (int)($r['up'] ?? 0));
    }
    $monAgg = array();
    foreach ($s as $d => $r) {
        if (!is_array($r)) continue;
        $m = substr((string)$d, 0, 7);
        if (!isset($monAgg[$m])) $monAgg[$m] = array('down' => 0, 'up' => 0);
        $monAgg[$m]['down'] += (int)($r['down'] ?? 0);
        $monAgg[$m]['up']   += (int)($r['up'] ?? 0);
    }
    krsort($monAgg); $monAgg = array_slice($monAgg, 0, 6, true);
    $months = array();
    foreach ($monAgg as $m => $r) $months[] = array('month' => $m, 'down' => $r['down'], 'up' => $r['up']);
    $months = array_reverse($months);
    $tR = is_array($s[$today] ?? null) ? $s[$today] : array();
    $mDown = 0; $mUp = 0;
    foreach ($s as $d => $r) { if (is_array($r) && substr((string)$d, 0, 7) === $month) { $mDown += (int)($r['down'] ?? 0); $mUp += (int)($r['up'] ?? 0); } }
    return array(
        'today'  => array('down' => (int)($tR['down'] ?? 0), 'up' => (int)($tR['up'] ?? 0)),
        'month'  => array('down' => $mDown, 'up' => $mUp),
        'days'   => $days,
        'months' => $months,
    );
}

/** Recent transfer activity (newest first) for the admin feed. */
function md_activity_recent(int $limit = 30): array {
    $a = pzc_db_read('activity'); if (!is_array($a)) $a = array();
    $a = array_reverse($a);
    return array_slice($a, 0, $limit);
}
