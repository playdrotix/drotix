<?php
/*
 * Web admin accounts (the /admin/ login): profile, change password, forgot / reset password.
 * Stored in the Supabase table public.admin_users (database/admin_accounts.sql), server key only.
 * The old accounts in includes/config.php ('admins') still work until they log in once: then they are
 * copied into the table automatically, and from then on only the table password counts.
 */
require_once __DIR__ . '/admin_layout.php';

const ADMIN_MIN_PASSWORD = 8;
const ADMIN_RESET_MINUTES = 30;

function adm_table() { return 'admin_users'; }
/** Is the admin_users table there? (cached per request) */
function adm_ready() {
    static $ok = null;
    if ($ok === null) { $r = sb_request('GET', adm_table(), array('select' => 'id', 'limit' => 1)); $ok = sb_ok($r); }
    return $ok;
}
/** Find one account by username or email (case-insensitive). */
function adm_find($login) {
    $login = trim((string) $login);
    if ($login === '' || !adm_ready()) { return null; }
    $v = pg_safe($login);
    if ($v === '') { return null; }
    $col = strpos($login, '@') !== false ? 'email' : 'username';
    list($rows, , ) = sb_select(adm_table(), array($col => 'ilike.' . $v), 2, 0);
    foreach ($rows as $r) { if (strtolower((string) $r[$col]) === strtolower($login)) { return $r; } }
    return null;
}
function adm_get($username) { return adm_find($username); }
function adm_update($id, $patch) {
    $patch['updated_at'] = date('c');
    return sb_update(adm_table(), array('id' => 'eq.' . $id), $patch);
}
function adm_password_problem($pw, $confirm) {
    if (strlen($pw) < ADMIN_MIN_PASSWORD) { return 'The new password must be at least ' . ADMIN_MIN_PASSWORD . ' characters.'; }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) { return 'Use letters and at least one number.'; }
    if ($pw !== $confirm) { return 'The two new passwords do not match.'; }
    return '';
}

/**
 * Check a login. Returns array(username|null, error).
 * 1) account in admin_users -> only that password counts
 * 2) else the old config.php account -> on success it is copied into admin_users
 */
function adm_check_login($login, $pass) {
    $dummy = '$2y$10$usesomesillystringforsaltuMf1oQn1g3j6vYQqWmuQw5wq5xJ6a';
    $row = adm_find($login);
    if ($row) {
        if (!empty($row['disabled'])) { password_verify($pass, $dummy); return array(null, 'This admin account is disabled.'); }
        if (password_verify($pass, (string) $row['password_hash'])) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) { adm_update($row['id'], array('password_hash' => password_hash($pass, PASSWORD_DEFAULT))); }
            adm_update($row['id'], array('last_login_at' => date('c')));
            return array((string) $row['username'], '');
        }
        return array(null, 'Wrong username or password.');
    }
    $admins = cfg('admins', array());
    $hash = isset($admins[$login]) ? (string) $admins[$login] : '';
    if ($hash === 'PASTE_PASSWORD_HASH_HERE') { password_verify($pass, $dummy); return array(null, 'Admin password is not set yet. See README step 5.'); }
    if ($hash === '' || $hash[0] !== '$') { password_verify($pass, $dummy); return array(null, 'Wrong username or password.'); }
    if (!password_verify($pass, $hash)) { return array(null, 'Wrong username or password.'); }
    if (adm_ready() && preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $login)) {   // copy the config account into the table (once)
        sb_insert(adm_table(), array('username' => $login, 'display_name' => $login, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'last_login_at' => date('c')));
    }
    return array($login, '');
}

/* ---------- forgot / reset ---------- */
/** Always behaves the same for unknown emails (no account enumeration). Returns '' or an internal error. */
function adm_send_reset($email) {
    $row = filter_var($email, FILTER_VALIDATE_EMAIL) ? adm_find($email) : null;
    if (!$row || !empty($row['disabled'])) { return ''; }
    $token = bin2hex(random_bytes(32));
    $r = adm_update($row['id'], array('reset_token_hash' => hash('sha256', $token), 'reset_expires_at' => date('c', time() + ADMIN_RESET_MINUTES * 60)));
    if (!sb_ok($r)) { return $r['error']; }
    $link = rtrim((string) cfg('site_url', ''), '/') . '/admin/reset/?t=' . $token;
    $from = header_safe(cfg('mail_from', 'no-reply@localhost'));
    $body = "Hello " . ($row['display_name'] ?: $row['username']) . ",\n\nSomeone asked to reset the password of your DROTIX admin account (" . $row['username'] . ").\n"
          . "Open this link within " . ADMIN_RESET_MINUTES . " minutes to choose a new password:\n\n" . $link . "\n\nIf this was not you, ignore this email; your password stays the same.\n\n-- DROTIX admin";
    $ok = @mail($row['email'], '=?UTF-8?B?' . base64_encode('Reset your DROTIX admin password') . '?=', $body, 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8");
    audit_log('admin_reset_requested', $row['username'], $ok ? 'mail sent' : 'mail() failed');
    return $ok ? '' : 'mail() failed';
}
/** Account for a valid, unexpired reset token, or null. */
function adm_by_token($token) {
    if (!preg_match('/^[0-9a-f]{64}$/', (string) $token) || !adm_ready()) { return null; }
    list($rows, , ) = sb_select(adm_table(), array('reset_token_hash' => 'eq.' . hash('sha256', $token)), 1, 0);
    if (!$rows) { return null; }
    $exp = strtotime((string) $rows[0]['reset_expires_at']);
    return ($exp && $exp > time() && empty($rows[0]['disabled'])) ? $rows[0] : null;
}
