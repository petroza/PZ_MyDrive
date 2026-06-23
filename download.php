<?php
declare(strict_types=1);

/*
 * MyDrive — download.php
 * The ONLY way file bytes leave the server. storage/ is not reachable directly
 * over HTTP (storage/.htaccess denies all); this gateway authenticates the
 * owner, re-runs the path-traversal guard, and only then streams the file.
 *   ?rel=<path>&mode=inline|download        single file
 *   ?rel=<folder>&zip=1                      whole-folder ZIP
 *   ?zip=1&rels[]=<a>&rels[]=<b>             selected items as ZIP
 *   &thumb=1                                 (hint; same bytes, used by the grid)
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/drive.php';
pzc_start_session();
pzc_secure_headers();
header('Cross-Origin-Resource-Policy: same-origin');

function md_dl_deny(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!pzc_is_configured()) md_dl_deny(403, 'Aplikace není nainstalována.');
if (!pzc_is_admin())      md_dl_deny(401, 'Nepřihlášen.');

$drive = md_drive();
$rel   = (string)($_GET['rel'] ?? '');
$mode  = ($_GET['mode'] ?? 'download') === 'inline' ? 'inline' : 'download';

// Thumbnail/preview of a trashed item (so the Koš can show photo previews).
if (!empty($_GET['trash'])) {
    $rec = md_trash_index()[(string)$_GET['trash']] ?? null;
    if (!is_array($rec)) md_dl_deny(404, 'Nenalezeno.');
    $tabs = pzc_resolve(md_trash_share(), (string)$rec['phys'], true);
    if ($tabs === null || !is_file($tabs)) md_dl_deny(404, 'Nenalezeno.');
    $att = ($mode !== 'inline');
    if (!$att) { $tm = pzc_mime($tabs); if (!pzc_is_inline_safe($tm)) $att = true; }
    pzc_stream($tabs, (string)$rec['name'], !$att);
}

// Whole-folder or multi-item ZIP.
if (!empty($_GET['zip'])) {
    $rels = (isset($_GET['rels']) && is_array($_GET['rels'])) ? array_slice($_GET['rels'], 0, 500) : array();
    pzc_stream_zip($drive, $rel, $rels);
}

$abs = pzc_resolve($drive, $rel, true);
if ($abs === null || !is_file($abs)) md_dl_deny(404, 'Soubor nenalezen.');

// Inline preview only for safe types; everything else is forced to download.
$asAttachment = ($mode === 'download');
if ($mode === 'inline') {
    $mime = pzc_mime($abs);
    if (!pzc_is_inline_safe($mime)) $asAttachment = true;
}

// Count real downloads only (not thumbnail/inline-preview fetches).
if (empty($_GET['thumb']) && $asAttachment) {
    md_log_transfer('down', (int)@filesize($abs), basename($abs), md_owner_animal());
}

pzc_stream($abs, basename($abs), !$asAttachment);
