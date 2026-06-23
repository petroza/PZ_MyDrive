<?php
declare(strict_types=1);

/*
 * MyDrive — share.php
 * PUBLIC endpoint for share links ("Kdokoli s odkazem"). Anyone with the token
 * (and password, if set) can browse/download the ONE shared file or folder —
 * strictly read-only. No write paths. Everything is confined to the link's item:
 * all paths are relative to (drive, link.rel) and re-validated with pzc_resolve,
 * and the subpath is run through pzc_safe_rel (rejects "..") so a folder link
 * can't reach siblings.
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/ui.php';
pzc_secure_headers();
header('Cross-Origin-Resource-Policy: same-origin');
pzc_start_session();
if (!pzc_is_configured()) pzc_redirect(pzc_url('setup.php'));

function share_brand(): string {
    return '<div class="brandline">' . pzc_ic('cloud') . ' <b>My<span>Drive</span></b></div>';
}
function share_err(string $msg): void {
    pzc_head('Odkaz', true);
    echo '<div class="wrap"><div class="card">' . share_brand()
       . '<h1>Odkaz nedostupný</h1><div class="err">' . pzc_e($msg) . '</div></div></div>';
    pzc_foot();
    exit;
}

$raw  = (string)($_GET['t'] ?? '');
$link = md_link_resolve($raw);
if ($link === null) share_err('Tento odkaz neplatí nebo vypršel.');
$share = md_drive();

$linkRel  = (string)$link['rel'];
$linkName = (string)($link['name'] ?? 'Sdílení');
$site     = (string)pzc_cfg('site_name', 'MyDrive');
$sessKey  = 'pzc_lnk_' . $link['id'];
$perm     = (($link['perm'] ?? 'read') === 'write') ? 'write' : 'read';
$animal   = (string)($link['animal'] ?? '🔗');

/* ---- password gate ---- */
if (!empty($link['pass_hash']) && empty($_SESSION[$sessKey])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $blk = pzc_login_blocked('link', (string)$link['id']);
        if ($blk > 0) {
            $err = 'Příliš mnoho pokusů. Zkus to za ' . ceil(($blk - time()) / 60) . ' min.';
        } elseif (!pzc_csrf_ok_form()) {
            $err = 'Neplatný formulář. Obnov stránku.';
        } elseif (password_verify((string)($_POST['password'] ?? ''), (string)$link['pass_hash'])) {
            pzc_login_success('link', (string)$link['id']);
            $_SESSION[$sessKey] = true;
            pzc_redirect(pzc_url('share.php?t=' . rawurlencode($raw)));
        } else {
            pzc_login_fail('link', (string)$link['id']);
            $err = 'Nesprávné heslo.';
        }
    }
    $csrf = pzc_csrf_token();
    pzc_head('Chráněný odkaz', true);
    echo '<form class="wrap" method="post"><div class="card">' . share_brand() . '<h1>' . pzc_e($site) . '</h1>'
       . '<div class="sub">Tento odkaz je chráněný heslem.</div>'
       . ($err !== '' ? '<div class="err">' . pzc_e($err) . '</div>' : '')
       . '<input type="hidden" name="csrf" value="' . pzc_e($csrf) . '">'
       . '<label>Heslo</label><input type="password" name="password" required autofocus>'
       . '<button class="btn btn-p btn-block" type="submit">Otevřít</button></div></form>';
    pzc_foot();
    exit;
}

/* ---- helper: join the link base with a sanitized subpath ---- */
function share_join(string $base, string $sub): string {
    if ($sub === '') return $base;
    return $base === '' ? $sub : $base . '/' . $sub;
}

/* ---- FILE link ---- */
if (empty($link['is_dir'])) {
    $abs = pzc_resolve($share, $linkRel, true);
    if ($abs === null || !is_file($abs)) share_err('Sdílený soubor už neexistuje.');
    if (!empty($_GET['dl']))   { md_log_transfer('down', (int)@filesize($abs), basename($abs), $animal); pzc_stream($abs, basename($abs), false); }
    if (!empty($_GET['prev'])) pzc_stream($abs, basename($abs), true);
    $mime = pzc_mime($abs);
    pzc_head('Sdílený soubor', true);
    echo '<div class="wrap" style="width:min(560px,100%)"><div class="card">' . share_brand()
       . '<div class="sub">Sdílený soubor</div><div style="text-align:center">';
    if (pzc_is_inline_safe($mime)) {
        $pv = pzc_e(pzc_url('share.php?t=' . rawurlencode($raw) . '&prev=1'));
        if (strpos($mime, 'image/') === 0) echo '<img src="' . $pv . '" style="max-width:100%;border-radius:12px">';
        elseif (strpos($mime, 'video/') === 0) echo '<video src="' . $pv . '" controls style="max-width:100%;border-radius:12px"></video>';
        elseif ($mime === 'application/pdf') echo '<a class="lnk" href="' . $pv . '" target="_blank" rel="noopener">Otevřít PDF náhled</a>';
    }
    echo '<div style="margin-top:14px;font-weight:600">' . pzc_e(basename($abs)) . '</div>'
       . '<div class="muted small">' . pzc_e(pzc_human_size((int)@filesize($abs))) . '</div></div>'
       . '<a class="btn btn-p btn-block" href="' . pzc_e(pzc_url('share.php?t=' . rawurlencode($raw) . '&dl=1')) . '">⬇ Stáhnout soubor</a>'
       . '</div></div>';
    pzc_foot();
    exit;
}

/* ---- FOLDER link ---- */
$p = pzc_safe_rel((string)($_GET['p'] ?? ''));
if ($p === null) share_err('Neplatná cesta.');
$effective = share_join($linkRel, $p);

/* ---- public WRITE: chunked upload (only when the link grants write) ---- */
if (!empty($_GET['up'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($perm !== 'write') { http_response_code(403); echo json_encode(array('ok' => false, 'msg' => 'Zápis není povolen.')); exit; }
    @set_time_limit(0);
    $upName = (string)($_GET['name'] ?? '');
    $chunk  = isset($_GET['chunk']) ? (int)$_GET['chunk'] : -1;
    $total  = isset($_GET['total']) ? (int)$_GET['total'] : -1;
    // confined to the link's folder (+ safe subpath); pzc_upload_chunk sanitizes the name and guards disk space
    $res = pzc_upload_chunk($share, $effective, $upName, $chunk, $total);
    if (!empty($res['done'])) md_log_transfer('up', (int)($res['size'] ?? 0), (string)($res['name'] ?? $upName), $animal);
    echo json_encode($res); exit;
}

if (isset($_GET['dl'])) {
    $fileRel = pzc_safe_rel((string)$_GET['dl']);
    if ($fileRel === null || $fileRel === '') share_err('Neplatná cesta.');
    $fileEff = share_join($linkRel, $fileRel);
    $fabs = pzc_resolve($share, $fileEff, true);
    if ($fabs === null || !is_file($fabs)) share_err('Soubor nenalezen.');
    md_log_transfer('down', (int)@filesize($fabs), basename($fabs), $animal);
    pzc_stream($fabs, basename($fabs), false);
}
if (!empty($_GET['zip'])) {
    // throttle the public ZIP builder (CPU/disk heavy) — ~10 per 10 min per link/IP
    if (pzc_login_blocked('ziplink', (string)$link['id']) > 0) share_err('Příliš mnoho požadavků na ZIP. Zkus to za chvíli.');
    pzc_login_fail('ziplink', (string)$link['id'], 10, 600);
    pzc_stream_zip($share, $effective);
}

$dirAbs = pzc_resolve($share, $effective, true);
if ($dirAbs === null || !is_dir($dirAbs)) share_err('Složka nenalezena.');
$res = pzc_list($share, $effective);

$linkBase = pzc_url('share.php?t=' . rawurlencode($raw));
pzc_head('Sdílená složka', true);
echo '<div class="wrap" style="width:min(820px,100%)"><div class="card">';
echo '<div class="row">' . share_brand() . '<a class="btn btn-sm btn-p right" href="' . pzc_e($linkBase . '&zip=1&p=' . rawurlencode($p)) . '">⬇ Stáhnout vše (ZIP)</a></div>';

// breadcrumb within the link
$crumb = '<a href="' . pzc_e($linkBase) . '">' . pzc_e($linkName) . '</a>';
$acc = '';
if ($p !== '') {
    foreach (explode('/', $p) as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumb .= ' / <a href="' . pzc_e($linkBase . '&p=' . rawurlencode($acc)) . '">' . pzc_e($seg) . '</a>';
    }
}
echo '<div class="crumb" style="margin:.8rem 0">' . $crumb . '</div>';

if ($perm === 'write') {
    echo '<div style="margin:.2rem 0 1rem"><label class="btn btn-p" style="cursor:pointer">⬆ Nahrát soubory<input type="file" id="upInput" multiple style="display:none"></label> '
       . '<span class="muted small" id="upTxt">Sem můžeš nahrávat soubory.</span></div>'
       . '<div class="prog" id="upProg"><div class="bar" id="upBar"></div></div>';
}

echo '<div class="fl">';
if (empty($res['items'])) {
    echo '<div class="fl-empty"><span class="em">📂</span>Složka je prázdná.</div>';
} else {
    foreach ($res['items'] as $it) {
        $childP = share_join($p, $it['name']);
        if ($it['type'] === 'dir') {
            echo '<div class="fl-row" style="grid-template-columns:46px 1fr auto">'
               . '<div class="ic">📁</div>'
               . '<div class="nm"><a href="' . pzc_e($linkBase . '&p=' . rawurlencode($childP)) . '">' . pzc_e($it['name']) . '</a></div>'
               . '<div></div></div>';
        } else {
            $dl = $linkBase . '&dl=' . rawurlencode($childP);
            echo '<div class="fl-row" style="grid-template-columns:46px 1fr 120px auto">'
               . '<div class="ic">📄</div>'
               . '<div class="nm">' . pzc_e($it['name']) . '</div>'
               . '<div class="sz">' . pzc_e(pzc_human_size((int)$it['size'])) . '</div>'
               . '<div class="row" style="justify-content:flex-end"><a class="btn btn-sm" href="' . pzc_e($dl) . '">Stáhnout</a></div></div>';
        }
    }
}
echo '</div></div></div>';

if ($perm === 'write') {
    $nonce = pzc_e(pzc_nonce());
    $tok = json_encode($raw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    $pj  = json_encode($p,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<script nonce="' . $nonce . '">'
       . '(function(){var TOKEN=' . $tok . ',P=' . $pj . ',CHUNK=5*1024*1024;'
       . 'var input=document.getElementById("upInput");if(!input)return;'
       . 'var txt=document.getElementById("upTxt"),prog=document.getElementById("upProg"),bar=document.getElementById("upBar");'
       . 'input.onchange=function(e){var f=Array.prototype.slice.call(e.target.files||[]);e.target.value="";if(f.length)uploadAll(f);};'
       . 'function uploadAll(files){var i=0;(function next(){if(i>=files.length){txt.textContent="Hotovo, načítám…";setTimeout(function(){location.reload();},600);return;}uploadOne(files[i],function(){i++;next();});})();}'
       . 'function uploadOne(file,done){var total=Math.max(1,Math.ceil(file.size/CHUNK)),c=0;prog.style.display="block";bar.style.width="0";'
       . '(function nextChunk(){if(c>=total){done();return;}var start=c*CHUNK,blob=file.slice(start,start+CHUNK);'
       . 'var url="share.php?t="+encodeURIComponent(TOKEN)+"&up=1&p="+encodeURIComponent(P)+"&name="+encodeURIComponent(file.name)+"&chunk="+c+"&total="+total;'
       . 'fetch(url,{method:"POST",body:blob,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){if(!d||!d.ok){txt.textContent="Chyba: "+((d&&d.msg)||"nahrávání selhalo");prog.style.display="none";return;}c++;bar.style.width=Math.round(c/total*100)+"%";txt.textContent="Nahrávám "+file.name+" — "+Math.round(c/total*100)+"%";nextChunk();}).catch(function(){txt.textContent="Chyba sítě.";});})();}'
       . '})();</script>';
}
pzc_foot();
