<?php
declare(strict_types=1);

/*
 * PZ Cloud — bootstrap.php
 * Loaded first by every entry point. Defines paths, config access, sessions,
 * security headers, CSRF and a few helpers. Modeled on Petr's auth.php style.
 */

// In production we never echo PHP errors to the visitor (they could leak paths).
@ini_set('display_errors', '0');
error_reporting(E_ALL);

define('PZC_ROOT',    dirname(__DIR__));
define('PZC_LIB',     PZC_ROOT . '/lib');
define('PZC_DATA',    PZC_ROOT . '/data');
define('PZC_STORAGE', PZC_ROOT . '/storage');
define('PZC_CONFIG',  PZC_ROOT . '/config.php');

// Log PHP errors to a file inside the protected data dir (never to screen).
@ini_set('log_errors', '1');
@ini_set('error_log', PZC_DATA . '/php-error.log');

/* ------------------------------------------------------------------ config */

function pzc_cfg_load(): array {
    if (!isset($GLOBALS['__pzc_cfg'])) {
        $c = is_file(PZC_CONFIG) ? (require PZC_CONFIG) : array();
        $GLOBALS['__pzc_cfg'] = is_array($c) ? $c : array();
    }
    return $GLOBALS['__pzc_cfg'];
}

function pzc_cfg(?string $key = null, $default = null) {
    $c = pzc_cfg_load();
    if ($key === null) return $c;
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

/** Persist a partial config change to config.php (atomic) and refresh memory. */
function pzc_cfg_save(array $patch): bool {
    $c = array_merge(pzc_cfg_load(), $patch);
    $php = "<?php\n// PZ Cloud configuration — generated. Edit via the admin Settings page.\nreturn "
         . var_export($c, true) . ";\n";
    if (!pzc_atomic_write(PZC_CONFIG, $php)) return false;
    $GLOBALS['__pzc_cfg'] = $c;
    return true;
}

function pzc_is_configured(): bool {
    return pzc_cfg('setup_done') === true
        && is_string(pzc_cfg('admin_hash')) && pzc_cfg('admin_hash') !== '';
}

/* ------------------------------------------------------------- filesystem */

function pzc_atomic_write(string $file, string $content): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Temp name ends in .php on purpose: config.php is written through here and
    // lives in the web root. A ".php" temp is executed (not served as text) by
    // Apache/FPM, so a leaked/observed temp name still reveals nothing. Data temp
    // files live under data/ (denied) so they're protected regardless.
    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp.php';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

/** Read a JSON file into an array (atomic-rename writers guarantee consistency). */
function pzc_read_json(string $path): array {
    if (!is_file($path)) return array();
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : array();
}

/**
 * Serialized + crash-safe read-modify-write of a JSON file. Locks a stable
 * sibling .lock file (so concurrent writers are serialized even across the
 * atomic rename), reads the JSON, lets $fn(&$data) mutate it, then writes via
 * temp-file + rename so a crash/full-disk can never leave a half-written store.
 * Returns whatever $fn returns, or null if the lock could not be taken.
 */
function pzc_locked_update(string $path, callable $fn) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $lh = @fopen($path . '.lock', 'c');
    if (!$lh) return null;
    $ret = null;
    if (flock($lh, LOCK_EX)) {
        $data = pzc_read_json($path);
        $ret = $fn($data);
        $enc = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($enc !== false) { // never overwrite the store with a failed encode
            $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $enc) !== false) { @chmod($tmp, 0640); @rename($tmp, $path); }
            else @unlink($tmp);
        }
        flock($lh, LOCK_UN);
    }
    fclose($lh);
    return $ret;
}

/** Make sure data/ and storage/ exist and carry their own deny-all .htaccess. */
function pzc_ensure_dirs(): void {
    foreach (array(PZC_DATA, PZC_STORAGE) as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
    $deny = PZC_DATA . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $denyS = PZC_STORAGE . '/.htaccess';
    if (!is_file($denyS)) {
        // 'Require all denied' is what actually blocks HTTP access (works under
        // PHP-FPM too). The handler/engine lines are defense-in-depth. php_*flag
        // is wrapped in <IfModule mod_php.c> so it never 500s on FPM hosts.
        @file_put_contents($denyS,
            "Require all denied\n" .
            "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" .
            "Options -ExecCGI -Indexes\n" .
            "SetHandler default-handler\n" .
            "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .pht .phar\n" .
            "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .pht .phar\n" .
            "<IfModule mod_php.c>\nphp_admin_flag engine off\n</IfModule>\n");
    }
}

/* ---------------------------------------------------------------- session */

function pzc_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function pzc_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    session_name('pzc_session');
    // path '/' on purpose: scoping to the subfolder would collide with any existing
    // '/'-scoped cookie of the same name and cause a login loop. SameSite=Strict +
    // Secure + HttpOnly give the protection that matters here.
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => pzc_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ));
    session_start();
}

/** Per-request CSP nonce for our own inline <script> blocks. */
function pzc_nonce(): string {
    if (!isset($GLOBALS['__pzc_nonce'])) $GLOBALS['__pzc_nonce'] = base64_encode(random_bytes(16));
    return $GLOBALS['__pzc_nonce'];
}

/* ------------------------------------------------------- security headers */

function pzc_secure_headers(bool $json = false): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // No includeSubDomains: this app lives in a subfolder of a shared apex domain
    // and must not speak for sibling hosts.
    if (pzc_is_https()) header('Strict-Transport-Security: max-age=31536000');
    // script-src uses a per-request nonce (NOT 'unsafe-inline'), so an injected
    // inline event handler / <script> from a malicious filename cannot execute.
    $nonce = pzc_nonce();
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob:; " .
        "connect-src 'self'; object-src 'none'; base-uri 'self'; " .
        "form-action 'self'; frame-ancestors 'none'"
    );
    if ($json) header('Content-Type: application/json; charset=utf-8');
}

/* ------------------------------------------------------------------- CSRF */

function pzc_csrf_token(): string {
    pzc_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/** For HTML form POSTs (token in a hidden field). */
function pzc_csrf_ok_form(): bool {
    pzc_start_session();
    $sent = (string)($_POST['csrf'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');
    return $have !== '' && hash_equals($have, $sent);
}

/** For fetch()/XHR JSON requests (token in the X-CSRF header). */
function pzc_csrf_ok_header(): bool {
    pzc_start_session();
    $sent = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');
    return $have !== '' && hash_equals($have, $sent);
}

function pzc_require_csrf_header(): void {
    if (!pzc_csrf_ok_header()) pzc_json(array('ok' => false, 'msg' => 'Neplatný bezpečnostní token (CSRF).'), 403);
}

/* ---------------------------------------------------------------- helpers */

function pzc_json($data, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pzc_e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pzc_redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** Best-effort public base URL of the install (no trailing slash). */
function pzc_base_url(): string {
    $cfg = (string)pzc_cfg('base_url', '');
    if ($cfg !== '') return rtrim($cfg, '/');
    $scheme = pzc_is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // If we're inside one of the app's subfolders, climb back to the install root.
    $dir = preg_replace('#/(admin|app)$#', '', $dir);
    return $scheme . '://' . $host . $dir;
}

function pzc_url(string $path = ''): string {
    return pzc_base_url() . '/' . ltrim($path, '/');
}

function pzc_now(): int { return time(); }

function pzc_norm_email(string $e): string {
    return strtolower(trim($e));
}

function pzc_valid_email(string $e): bool {
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 254;
}

/** Append a line to a log file, rotating to .1 when it grows past $maxBytes. */
function pzc_log_append(string $file, string $line, int $maxBytes = 5242880): void {
    if (is_file($file) && (int)@filesize($file) > $maxBytes) @rename($file, $file . '.1');
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Append a line to the audit log (who did what). Never throws. */
function pzc_audit(string $event, array $detail = array()): void {
    $line = json_encode(array(
        'ts'    => date('c'),
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        'event' => $event,
        'detail'=> $detail,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    pzc_log_append(PZC_DATA . '/audit.log', $line);
}

/** Cheap per-request housekeeping: keep the PHP error log from growing forever. */
function pzc_maintain(): void {
    $el = PZC_DATA . '/php-error.log';
    if (is_file($el) && (int)@filesize($el) > 2097152) @rename($el, $el . '.1');
    // sweep orphaned ZIP temp files (client disconnected mid-download) older than 1 h
    foreach (glob(PZC_DATA . '/.zip-*.zip') ?: array() as $z) {
        $m = @filemtime($z); if ($m !== false && (time() - (int)$m) > 3600) @unlink($z);
    }
}

// Run once per request.
pzc_ensure_dirs();
pzc_maintain();
