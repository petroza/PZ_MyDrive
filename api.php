<?php
declare(strict_types=1);

/*
 * MyDrive — api.php
 * JSON API for the owner. Every request is admin-authenticated and CSRF-checked.
 * All file actions target the single personal drive; "delete" is a soft-delete
 * into the Trash, matching the Google Drive behaviour.
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/drive.php';
pzc_secure_headers(true);
pzc_require_admin_api();

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = array();
$action = (string)($in['action'] ?? '');
$rel    = (string)($in['rel'] ?? '');

$drive = md_drive();

switch ($action) {

    case 'list': {
        $res = pzc_list($drive, $rel);
        // annotate which items are starred / publicly shared so the browser can show it
        $stars = md_stars();
        $sharedRels = array();
        foreach (md_links_all() as $l) { if (md_link_active($l)) $sharedRels[(string)($l['rel'] ?? '')] = true; }
        foreach ($res['items'] as &$it) {
            $it['starred'] = !empty($stars[$it['rel']]);
            $it['shared']  = !empty($sharedRels[$it['rel']]);
        }
        unset($it);
        pzc_json(array('ok' => $res['ok'], 'items' => $res['items']));
    }

    case 'mkdir': {
        $ok = pzc_mkdir($drive, $rel, (string)($in['name'] ?? ''));
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Složku se nepodařilo vytvořit.'));
    }

    case 'mkdirp': {
        $ok = pzc_mkdirp($drive, $rel);
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Složku se nepodařilo vytvořit.'));
    }

    case 'delete': { // soft-delete -> Trash
        $ok = md_trash_send($rel);
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Přesun do koše selhal.'));
    }

    case 'rename': {
        $newName = (string)($in['name'] ?? '');
        $ok = pzc_rename($drive, $rel, $newName);
        if ($ok) {
            $parent  = trim(dirname($rel) === '.' ? '' : dirname($rel), '/');
            $clean   = pzc_safe_rel(($parent !== '' ? $parent . '/' : '') . $newName);
            if ($clean !== null) md_star_rekey($rel, $clean);
        }
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Přejmenování selhalo.'));
    }

    case 'move': {
        $to = (string)($in['to'] ?? '');
        $ok = pzc_move($drive, $rel, $to);
        if ($ok) {
            $dest = pzc_safe_rel(($to !== '' ? rtrim($to, '/') . '/' : '') . basename($rel));
            if ($dest !== null) md_star_rekey($rel, $dest);
        }
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Přesun selhal.'));
    }

    case 'star': {
        $on = md_star_toggle($rel);
        pzc_json(array('ok' => true, 'starred' => (bool)$on));
    }

    case 'recent':
        pzc_json(array('ok' => true, 'items' => md_recent(60)));

    case 'starred':
        pzc_json(array('ok' => true, 'items' => md_starred_items()));

    case 'search':
        pzc_json(array('ok' => true, 'items' => md_search((string)($in['q'] ?? ''))));

    case 'trash_list':
        pzc_json(array('ok' => true, 'items' => md_trash_items()));

    case 'trash_restore': {
        $ok = md_trash_restore((string)($in['id'] ?? ''));
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Obnovení selhalo.'));
    }

    case 'trash_purge': {
        $ok = md_trash_purge((string)($in['id'] ?? ''));
        pzc_json(array('ok' => $ok));
    }

    case 'trash_empty':
        md_trash_empty();
        pzc_json(array('ok' => true));

    case 'storage':
        pzc_json(array('ok' => true, 'storage' => md_storage()));

    /* -------- public share links ("Kdokoli s odkazem") -------- */
    case 'link_get': {
        $out = array();
        foreach (md_links_for($rel) as $l) {
            $out[] = array(
                'id'       => $l['id'],
                'url'      => md_link_url((string)$l['token']),
                'has_pass' => !empty($l['pass_hash']),
                'expires'  => (int)($l['expires'] ?? 0),
                'perm'     => ($l['perm'] ?? 'read') === 'write' ? 'write' : 'read',
            );
        }
        pzc_json(array('ok' => true, 'links' => $out));
    }

    case 'link_create': {
        $abs = pzc_resolve($drive, $rel, true);
        if ($abs === null) pzc_json(array('ok' => false, 'msg' => 'Položka neexistuje.'));
        $pw = (string)($in['password'] ?? '');
        if ($pw !== '' && strlen($pw) < 4) pzc_json(array('ok' => false, 'msg' => 'Heslo musí mít aspoň 4 znaky.'));
        $exp = 0;
        $days = (int)($in['days'] ?? 0);
        if ($days > 0) $exp = time() + $days * 86400;
        $perm = ((string)($in['perm'] ?? 'read') === 'write') ? 'write' : 'read';
        $name = basename($rel) !== '' ? basename($rel) : (string)pzc_cfg('site_name', 'Můj disk');
        $res = md_link_create($rel, is_dir($abs), $name, $pw, $exp, $perm);
        if ($res === null) pzc_json(array('ok' => false, 'msg' => 'Odkaz se nepodařilo vytvořit.'));
        pzc_json(array('ok' => true, 'url' => md_link_url($res['token']), 'id' => $res['id']));
    }

    case 'link_delete': {
        $ok = md_link_delete((string)($in['id'] ?? ''));
        pzc_json(array('ok' => $ok));
    }

    case 'link_update': {
        $id = (string)($in['id'] ?? '');
        $pw = array_key_exists('password', $in) ? (string)$in['password'] : null;
        if ($pw !== null && $pw !== '' && strlen($pw) < 4) pzc_json(array('ok' => false, 'msg' => 'Heslo musí mít aspoň 4 znaky.'));
        $perm = array_key_exists('perm', $in) ? (((string)$in['perm'] === 'write') ? 'write' : 'read') : null;
        $exp = null;
        if (array_key_exists('days', $in)) { $d = (int)$in['days']; $exp = $d > 0 ? time() + $d * 86400 : 0; }
        $ok = md_link_update($id, $pw, $perm, $exp);
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Úprava selhala.'));
    }

    /* -------- admin panel: overview of all shares + settings -------- */
    case 'admin_overview': {
        $links = array();
        foreach (md_links_all() as $l) {
            $r = (string)($l['rel'] ?? '');
            $links[] = array(
                'id'      => $l['id'],
                'rel'     => $r,
                'name'    => (string)($l['name'] ?? basename($r)),
                'is_dir'  => !empty($l['is_dir']),
                'url'     => md_link_url((string)$l['token']),
                'has_pass'=> !empty($l['pass_hash']),
                'expires' => (int)($l['expires'] ?? 0),
                'perm'    => ($l['perm'] ?? 'read') === 'write' ? 'write' : 'read',
                'animal'  => (string)($l['animal'] ?? '🔗'),
                'expired' => !md_link_active($l),
                'missing' => (pzc_resolve($drive, $r, true) === null),
                'created' => (int)($l['created'] ?? 0),
            );
        }
        usort($links, function ($a, $b) { return $b['created'] - $a['created']; });
        pzc_json(array('ok' => true,
            'links' => $links,
            'stats' => md_stats_summary(),
            'activity' => md_activity_recent(30),
            'settings' => array(
                'site_name'   => (string)pzc_cfg('site_name', 'MyDrive'),
                'quota_gb'    => (int)pzc_cfg('quota_gb', 0),
                'admin_user'  => (string)pzc_cfg('admin_user', ''),
                'owner_animal'=> md_owner_animal(),
            ),
        ));
    }

    case 'admin_save': {
        $patch = array();
        if (isset($in['site_name'])) {
            $sn = trim((string)$in['site_name']);
            if ($sn !== '') $patch['site_name'] = mb_substr($sn, 0, 60);
        }
        if (isset($in['quota_gb'])) {
            $q = (int)$in['quota_gb']; if ($q < 0) $q = 0;
            $patch['quota_gb'] = $q;
        }
        if (isset($in['password']) && (string)$in['password'] !== '') {
            $pw = (string)$in['password'];
            if (strlen($pw) < 6) pzc_json(array('ok' => false, 'msg' => 'Heslo musí mít aspoň 6 znaků.'));
            $patch['admin_hash'] = password_hash($pw, PASSWORD_BCRYPT, array('cost' => 12));
        }
        if (!$patch) pzc_json(array('ok' => false, 'msg' => 'Nic ke změně.'));
        $ok = pzc_cfg_save($patch);
        pzc_audit('admin_save', array('keys' => array_keys($patch)));
        pzc_json(array('ok' => $ok, 'msg' => $ok ? '' : 'Uložení selhalo.'));
    }

    default:
        pzc_json(array('ok' => false, 'msg' => 'Neznámá akce.'), 400);
}
