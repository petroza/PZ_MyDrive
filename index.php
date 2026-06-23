<?php
declare(strict_types=1);

/*
 * MyDrive — index.php
 * Owner login. Redirects to setup on first run, and straight to the drive once
 * authenticated. Brute-force throttling + CSRF are enforced server-side.
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ui.php';
pzc_secure_headers();
pzc_start_session();

if (!pzc_is_configured()) {
    pzc_redirect(pzc_url('setup.php'));
}

if (pzc_is_admin()) {
    pzc_redirect(pzc_url('drive.php'));
}

$error = '';
$csrf  = pzc_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['admin_user'] ?? ''));
    $blockedUntil = pzc_login_blocked('admin', $user);
    if ($blockedUntil > 0) {
        $error = 'Příliš mnoho pokusů. Zkus to za ' . ceil(($blockedUntil - time()) / 60) . ' min.';
    } elseif (!pzc_csrf_ok_form()) {
        $error = 'Neplatný formulář. Obnov stránku.';
    } else {
        $pass = (string)($_POST['password'] ?? '');
        if (pzc_admin_user_ok($user) && pzc_admin_password_ok($pass)) {
            pzc_login_success('admin', $user);
            pzc_admin_login();
            pzc_redirect(pzc_url('drive.php'));
        }
        pzc_login_fail('admin', $user);
        $error = 'Nesprávné jméno nebo heslo.';
    }
}

pzc_head('Přihlášení', true);
?>
<form class="wrap" method="post" autocomplete="off">
  <div class="card">
    <div class="brandline"><?= pzc_ic('cloud') ?> <b>My<span>Drive</span></b></div>
    <h1><?= pzc_e(pzc_cfg('site_name', 'MyDrive')) ?></h1>
    <div class="sub">Přihlas se ke svému disku.</div>
    <?php if ($error !== ''): ?><div class="err"><?= pzc_e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= pzc_e($csrf) ?>">

    <label>Jméno</label>
    <input name="admin_user" required autofocus value="<?= pzc_e(pzc_cfg('admin_user', '')) ?>">

    <label>Heslo</label>
    <input type="password" name="password" required>

    <button class="btn btn-p btn-block" type="submit">Přihlásit</button>
  </div>
</form>
<?php pzc_foot();
