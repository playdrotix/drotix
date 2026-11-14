<?php
/* Shared helpers: config, security, sessions, CSRF, rate limiting. */
if (!defined('DROTIX')) { define('DROTIX', true); }
$GLOBALS['__drotix_cfg'] = null;

function cfg($key = null, $default = null) {
    if ($GLOBALS['__drotix_cfg'] === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Setup needed: copy includes/config.sample.php to includes/config.php and fill it in.');
        }
        $c = require $file;
        $GLOBALS['__drotix_cfg'] = is_array($c) ? $c : array();
    }
    if ($key === null) { return $GLOBALS['__drotix_cfg']; }
    return array_key_exists($key, $GLOBALS['__drotix_cfg']) ? $GLOBALS['__drotix_cfg'][$key] : $default;
}

date_default_timezone_set((string) cfg('timezone', 'Asia/Kolkata'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function tbl($name) {
    $t = cfg('tables', array());
    return isset($t[$name]) ? $t[$name] : $name;
}
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function client_ip() {
    $h = (string) cfg('trust_proxy_header', '');
    if ($h !== '' && !empty($_SERVER[$h])) {
        $ip = trim(explode(',', $_SERVER[$h])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}
function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}
function security_headers($admin = false) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) { header('Strict-Transport-Security: max-age=31536000'); }
    if ($admin) {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
    }
}

/* ---------- sessions + CSRF (admin only) ---------- */
function admin_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name((string) cfg('session_name', 'drotix_admin'));
    session_set_cookie_params(array(
        'lifetime' => 0, 'path' => '/admin/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ));
    ini_set('session.use_strict_mode', '1');
    session_start();
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify() {
    $t = isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('Security check failed. Go back, refresh the page and try again.');
    }
}
function flash_set($type, $msg) { $_SESSION['flash'] = array($type, $msg); }
function flash_get() {
    if (empty($_SESSION['flash'])) { return null; }
    $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
}

/* ---------- tiny file-based rate limiter (no database needed) ---------- */
function rl_file($key) {
    $dir = sys_get_temp_dir() . '/drotix_rl';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir . '/' . sha1($key) . '.json';
}
function rl_read($key, $window) {
    $f = rl_file($key); $t = array();
    if (is_file($f)) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d)) { $t = $d; }
    }
    $min = time() - $window;
    $out = array();
    foreach ($t as $x) { if ($x > $min) { $out[] = $x; } }
    return $out;
}
function rl_count($key, $window) { return count(rl_read($key, $window)); }
function rl_add($key, $window) {
    $t = rl_read($key, $window); $t[] = time();
    @file_put_contents(rl_file($key), json_encode($t), LOCK_EX);
    return count($t);
}
function rl_clear($key) { @unlink(rl_file($key)); }

/* ---------- misc ---------- */
function clean_str($v, $max) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    $v = trim($v);
    if (function_exists('mb_substr')) { return mb_substr($v, 0, $max, 'UTF-8'); }
    return substr($v, 0, $max);
}
function header_safe($v) { return trim(preg_replace('/[\r\n]+/', ' ', (string) $v)); }
function send_notice($subject, $body) {
    $to = (string) cfg('notify_admin_email', '');
    if ($to === '') { return; }
    $from = header_safe(cfg('mail_from', 'no-reply@localhost'));
    $headers = "From: " . $from . "\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($to, '=?UTF-8?B?' . base64_encode(header_safe($subject)) . '?=', $body, $headers);
}
