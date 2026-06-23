<?php
declare(strict_types=1);

/*
 * MyDrive — setup.php
 * First-run wizard. Sets the owner password (stored only as a bcrypt hash),
 * generates the app secret, and writes config.php. Refuses to run once the app
 * is already configured, so it cannot be used to hijack a live install.
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ui.php';
pzc_secure_headers();
pzc_start_session();

if (pzc_is_configured()) {
    pzc_redirect(pzc_url('index.php'));
}

$error = '';
$csrf  = pzc_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pzc_csrf_ok_form()) {
        $error = 'Neplatný formulář. Obnov stránku.';
    } else {
        $user  = trim((string)($_POST['admin_user'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        $site  = trim((string)($_POST['site_name'] ?? 'MyDrive'));
        $base  = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $quota = max(0, (int)($_POST['quota_gb'] ?? 0));

        if ($user === '' || strlen($user) > 64) {
            $error = 'Zadej platné přihlašovací jméno.';
        } elseif (strlen($pass) < 8) {
            $error = 'Heslo musí mít aspoň 8 znaků.';
        } elseif ($pass !== $pass2) {
            $error = 'Hesla se neshodují.';
        } else {
            $ok = pzc_cfg_save(array(
                'setup_done'      => true,
                'admin_user'      => $user,
                'admin_hash'      => password_hash($pass, PASSWORD_BCRYPT, array('cost' => 12)),
                'app_secret'      => bin2hex(random_bytes(32)),
                'site_name'       => $site !== '' ? $site : 'MyDrive',
                'base_url'        => $base,
                'quota_gb'        => $quota,
                'max_upload_mb'   => 0,
                'min_free_mb'     => 200,
                'session_ttl_min' => 720,
            ));
            if ($ok) {
                pzc_audit('setup_done', array('admin_user' => $user));
                pzc_redirect(pzc_url('index.php'));
            }
            $error = 'Nepodařilo se zapsat config.php — zkontroluj práva k zápisu ve složce aplikace.';
        }
    }
}

$guessBase = pzc_base_url();
pzc_head('Instalace', true);
?>
<form class="wrap" method="post" autocomplete="off">
  <div class="card">
    <div class="brandline"><?= pzc_ic('cloud') ?> <b>My<span>Drive</span></b></div>
    <h1>Vítej — pojďme to nastavit</h1>
    <div class="sub">Jednorázová instalace tvého osobního cloudu. Heslo se uloží jen jako bezpečný otisk (bcrypt), nikdy jako čitelný text.</div>
    <?php if ($error !== ''): ?><div class="err"><?= pzc_e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= pzc_e($csrf) ?>">

    <label>Přihlašovací jméno</label>
    <input name="admin_user" required autofocus placeholder="např. petr">

    <label>Heslo</label>
    <input type="password" name="password" required minlength="8" placeholder="aspoň 8 znaků">

    <label>Heslo znovu</label>
    <input type="password" name="password2" required minlength="8">

    <label>Název disku</label>
    <input name="site_name" value="MyDrive" placeholder="MyDrive">

    <label>Veřejná adresa instalace</label>
    <input name="base_url" value="<?= pzc_e($guessBase) ?>" placeholder="https://www.tvojedomena.cz/mydrive">
    <div class="hint">Detekováno automaticky — uprav, jen pokud je špatně. Bez lomítka na konci.</div>

    <label>Limit úložiště v GB (0 = bez limitu)</label>
    <input name="quota_gb" type="number" min="0" value="0">
    <div class="hint">Jen pro ukazatel zaplnění. Skutečný strop dává tvůj hosting.</div>

    <button class="btn btn-p btn-block" type="submit">Dokončit instalaci</button>
  </div>
</form>
<?php pzc_foot();
