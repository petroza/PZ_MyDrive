<?php
declare(strict_types=1);

// MyDrive — logout.php : ends the owner session.
// Requires POST + CSRF so a third-party page can't force a logout via <img>/link.
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ui.php';
pzc_secure_headers();
pzc_start_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && pzc_csrf_ok_form()) {
    pzc_full_logout();
    pzc_redirect(pzc_url('index.php'));
}

$csrf = pzc_csrf_token();
pzc_head('Odhlášení', true);
?>
<form class="wrap" method="post">
  <div class="card">
    <div class="brandline"><?= pzc_ic('cloud') ?> <b>My<span>Drive</span></b></div>
    <h1>Odhlásit se?</h1>
    <input type="hidden" name="csrf" value="<?= pzc_e($csrf) ?>">
    <button class="btn btn-p btn-block" type="submit">Ano, odhlásit</button>
    <div class="hint" style="margin-top:14px"><a href="<?= pzc_e(pzc_url('drive.php')) ?>">Zpět na disk</a></div>
  </div>
</form>
<?php pzc_foot();
