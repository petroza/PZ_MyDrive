<?php
declare(strict_types=1);

/*
 * PZ Cloud — security.php
 * Authentication for two roles: the admin (password) and external users
 * (e-mail + password). Sessions get idle + absolute timeouts and are single-role.
 * Brute-force throttling is per-IP AND per-account. Modeled on Petr's auth.php.
 */

require_once __DIR__ . '/bootstrap.php';

/* -------------------------------------------------- request fingerprinting */

function pzc_session_fingerprint(): string {
    return hash('sha256', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200));
}

function pzc_client_key(): string {
    // Throttle bucket keyed on IP ONLY. The User-Agent must NOT be part of this
    // key — it is attacker-controlled, so folding it in would let an attacker
    // rotate the UA and land in a fresh bucket on every guess, defeating the
    // lockout. (The UA still legitimately feeds pzc_session_fingerprint().)
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/* --------------------------------------------- brute-force throttle */
/* State in data/throttle.json. TWO independent buckets per attempt: one keyed on
 * client IP (DoS guard) and one on the target account (admin name / e-mail), so
 * a single account can't be brute-forced across rotating IPs, and one shared IP
 * can't lock everyone out alone. Updates go through a locked RMW (no lost counts).
 * 6 fails in 15 min -> locked 15 min. */

function pzc_throttle_keys(string $action, string $accountId): array {
    $keys = array($action . ':ip:' . pzc_client_key());
    $accountId = strtolower(trim($accountId));
    if ($accountId !== '') $keys[] = $action . ':acct:' . hash('sha256', $accountId);
    return $keys;
}

function pzc_login_blocked(string $action = 'admin', string $accountId = ''): int {
    $s = pzc_read_json(PZC_DATA . '/throttle.json');
    $now = time(); $until = 0;
    foreach (pzc_throttle_keys($action, $accountId) as $k) {
        $u = (int)($s[$k]['blocked_until'] ?? 0);
        if ($u > $now) $until = max($until, $u);
    }
    return $until;
}

function pzc_login_fail(string $action = 'admin', string $accountId = '', int $maxTries = 6, int $lockSec = 900): void {
    // Per-IP bucket locks at $maxTries. The per-account bucket is deliberately
    // more lenient (so a known e-mail/username can't be trivially lockout-DoS'd)
    // while still bounding distributed cross-IP brute force.
    $buckets = array(array($action . ':ip:' . pzc_client_key(), $maxTries));
    $acct = strtolower(trim($accountId));
    if ($acct !== '') $buckets[] = array($action . ':acct:' . hash('sha256', $acct), max(20, $maxTries * 4));
    pzc_locked_update(PZC_DATA . '/throttle.json', function (array &$s) use ($buckets, $lockSec) {
        $now = time();
        foreach ($buckets as $b) {
            $k = $b[0]; $limit = $b[1];
            $item = $s[$k] ?? array('count' => 0, 'first' => $now, 'blocked_until' => 0);
            if (($now - (int)($item['first'] ?? $now)) > 900) $item = array('count' => 0, 'first' => $now, 'blocked_until' => 0);
            $item['count'] = (int)($item['count'] ?? 0) + 1;
            if ($item['count'] >= $limit) $item['blocked_until'] = $now + $lockSec;
            $s[$k] = $item;
        }
        foreach ($s as $key => $v) { // prune stale
            if ((int)($v['blocked_until'] ?? 0) < $now && ($now - (int)($v['first'] ?? $now)) > 3600) unset($s[$key]);
        }
        return true;
    });
}

function pzc_login_success(string $action = 'admin', string $accountId = ''): void {
    $keys = pzc_throttle_keys($action, $accountId);
    pzc_locked_update(PZC_DATA . '/throttle.json', function (array &$s) use ($keys) {
        foreach ($keys as $k) unset($s[$k]);
        return true;
    });
}

/* ----------------------------------------------------------- admin (Petr) */

function pzc_admin_password_ok(string $password): bool {
    $hash = (string)pzc_cfg('admin_hash', '');
    return $hash !== '' && password_verify($password, $hash);
}

function pzc_admin_user_ok(string $user): bool {
    $u = (string)pzc_cfg('admin_user', 'admin');
    return hash_equals($u, $user);
}

function pzc_admin_login(): void {
    pzc_start_session();
    session_regenerate_id(true);
    // one session = one role: drop any external-user state and rotate the CSRF
    unset($_SESSION['pzc_user'], $_SESSION['pzc_user_fp'], $_SESSION['pzc_user_seen'], $_SESSION['pzc_user_since']);
    $_SESSION['pzc_admin']       = true;
    $_SESSION['pzc_fp']          = pzc_session_fingerprint();
    $_SESSION['pzc_admin_since'] = time();
    $_SESSION['pzc_admin_seen']  = time();
    unset($_SESSION['csrf']);
    pzc_csrf_token();
    pzc_audit('admin_login', array());
}

function pzc_is_admin(): bool {
    pzc_start_session();
    if (empty($_SESSION['pzc_admin']) || !isset($_SESSION['pzc_fp'])
        || !hash_equals((string)$_SESSION['pzc_fp'], pzc_session_fingerprint())) return false;
    $now  = time();
    $idle = max(5, (int)pzc_cfg('session_ttl_min', 720)) * 60;
    $abs  = 24 * 3600; // absolute cap regardless of activity
    if ($now - (int)($_SESSION['pzc_admin_seen'] ?? 0) > $idle) { pzc_full_logout(); return false; }
    if ($now - (int)($_SESSION['pzc_admin_since'] ?? 0) > $abs)  { pzc_full_logout(); return false; }
    $_SESSION['pzc_admin_seen'] = $now;
    return true;
}

/** For HTML admin pages: redirect to login if not authenticated. */
function pzc_require_admin_page(): void {
    if (!pzc_is_admin()) pzc_redirect(pzc_url('index.php'));
}

/** For admin JSON endpoints: 401 + CSRF check. */
function pzc_require_admin_api(): void {
    if (!pzc_is_admin()) pzc_json(array('ok' => false, 'msg' => 'Nepřihlášen (admin).'), 401);
    pzc_require_csrf_header();
}

/* --------------------------------------------------- external user (email) */

function pzc_user_login(string $email): void {
    pzc_start_session();
    session_regenerate_id(true);
    // one session = one role: drop any admin state and rotate the CSRF
    unset($_SESSION['pzc_admin'], $_SESSION['pzc_fp'], $_SESSION['pzc_admin_since'], $_SESSION['pzc_admin_seen']);
    $_SESSION['pzc_user']       = pzc_norm_email($email);
    $_SESSION['pzc_user_fp']    = pzc_session_fingerprint();
    $_SESSION['pzc_user_seen']  = time();
    $_SESSION['pzc_user_since'] = time();
    unset($_SESSION['csrf']);
    pzc_csrf_token();
    pzc_audit('user_login', array('email' => pzc_norm_email($email)));
}

function pzc_is_user(): bool {
    pzc_start_session();
    if (empty($_SESSION['pzc_user'])) return false;
    if (!isset($_SESSION['pzc_user_fp']) || !hash_equals((string)$_SESSION['pzc_user_fp'], pzc_session_fingerprint())) return false;
    $now  = time();
    $idle = max(5, (int)pzc_cfg('session_ttl_min', 720)) * 60;
    $abs  = 7 * 24 * 3600;
    if ($now - (int)($_SESSION['pzc_user_seen'] ?? 0) > $idle) { pzc_full_logout(); return false; }
    if ($now - (int)($_SESSION['pzc_user_since'] ?? 0) > $abs)  { pzc_full_logout(); return false; }
    // access revoked while logged in? end the session immediately (cheap re-check)
    if (function_exists('pzc_email_has_any_grant') && !pzc_email_has_any_grant((string)$_SESSION['pzc_user'])) {
        pzc_full_logout(); return false;
    }
    $_SESSION['pzc_user_seen'] = $now;
    return true;
}

function pzc_current_email(): string {
    pzc_start_session();
    return (string)($_SESSION['pzc_user'] ?? '');
}

function pzc_user_logout(): void {
    pzc_start_session();
    unset($_SESSION['pzc_user'], $_SESSION['pzc_user_fp'], $_SESSION['pzc_user_seen']);
}

function pzc_require_user_page(): void {
    if (!pzc_is_user()) pzc_redirect(pzc_url('access.php'));
}

function pzc_require_user_api(): void {
    if (!pzc_is_user()) pzc_json(array('ok' => false, 'msg' => 'Nepřihlášen.'), 401);
    pzc_require_csrf_header();
}

function pzc_full_logout(): void {
    pzc_start_session();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
