<?php
declare(strict_types=1);

/*
 * MyDrive — upload.php
 * Chunked upload endpoint. The raw chunk bytes are the POST body (php://input),
 * so the only PHP limit in play is post_max_size and each chunk stays well under
 * it. Owner-authenticated + CSRF.
 *   ?rel=<folder>&name=<file>&chunk=<n>&total=<n>
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/drive.php';
pzc_secure_headers(true);
pzc_require_admin_api();
@set_time_limit(0);

$rel   = (string)($_GET['rel'] ?? '');
$name  = (string)($_GET['name'] ?? '');
$chunk = isset($_GET['chunk']) ? (int)$_GET['chunk'] : -1;
$total = isset($_GET['total']) ? (int)$_GET['total'] : -1;

$res = pzc_upload_chunk(md_drive(), $rel, $name, $chunk, $total);
if (!empty($res['done'])) md_log_transfer('up', (int)($res['size'] ?? 0), (string)($res['name'] ?? $name), md_owner_animal());
pzc_json($res, !empty($res['ok']) ? 200 : 400);
