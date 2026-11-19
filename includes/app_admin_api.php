<?php
/*
 * DROTIX App admin API: the ONLY place that talks to the game backend (Supabase schema `drotix`).
 *
 * - Calls only the drotix.admin_* RPC functions (never tables, never SQL).
 * - Single login: once the website admin is logged in to /admin/, the SERVER signs in to Supabase
 *   automatically with one app-admin account from includes/app_config.php (email/password).
 *   No second login screen. Requests use the PUBLISHABLE / anon key + that account's access token, so the
 *   database still checks drotix.profiles.is_admin. The service_role / secret key is NEVER used here.
 * - Credentials and tokens stay on the server (app_config.php + PHP session); they never reach the browser.
 *
 * Every RPC wrapper returns an AppResult:
 *   array('ok' => bool, 'data' => array|null, 'error' => string, 'forbidden' => bool)
 */
require_once __DIR__ . '/admin_layout.php';

const APP_SCHEMA = 'drotix';
const APP_EVIDENCE_BUCKET = 'drotix-report-evidence';

/* ---------------- config ----------------
 * Mobile app connection lives in its OWN file: includes/app_config.php (copy of app_config.sample.php).
 * (Fallback: an 'app_admin' block inside includes/config.php.) */
function app_conf() {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/app_config.php';
        $c = is_file($f) ? require $f : cfg('app_admin', array());
        if (!is_array($c)) { $c = array(); }
    }
    return $c;
}
function app_cfg() {
    $c = app_conf();
    $url = !empty($c['supabase_url']) ? $c['supabase_url'] : cfg('supabase_url', '');
    $key = isset($c['anon_key']) ? (string) $c['anon_key'] : '';
    return array(rtrim((string) $url, '/'), $key);
}
/** Supabase app-admin account the server signs in with: array(email, password). */
function app_cfg_account() {
    $c = app_conf();
    return array(isset($c['email']) ? (string) $c['email'] : '', isset($c['password']) ? (string) $c['password'] : '');
}
/** '' when OK, otherwise a message telling what to fix. Refuses secret / service_role keys. */
function app_config_problem() {
    list($u, $k) = app_cfg();
    if ($u === '') { return 'Supabase URL is missing in includes/config.php.'; }
    if (!is_file(__DIR__ . '/app_config.php') && !cfg('app_admin')) { return 'includes/app_config.php is missing. On the server copy includes/app_config.sample.php to includes/app_config.php and fill it in.'; }
    if ($k === '' || strpos($k, 'PASTE_') === 0) { return "Fill 'anon_key' in includes/app_config.php (Supabase -> Project Settings -> API Keys -> Publishable key)."; }
    $kind = sb_key_kind($k);
    if (in_array($kind[0], array('secret', 'service_role'), true)) { return "'anon_key' in includes/app_config.php must be the PUBLISHABLE / anon key, never the secret or service_role key."; }
    list($em, $pw) = app_cfg_account();
    if ($em === '' || $pw === '' || strpos($pw, 'PASTE_') === 0) { return "Fill 'email' and 'password' in includes/app_config.php: a Supabase account of the app that is an app admin (drotix.profiles.is_admin = true)."; }
    return '';
}

/* ---------------- HTTP ---------------- */
/** Returns array(status, data, error). $path starts with /rest/v1, /auth/v1 or /storage/v1. */
function app_http($method, $path, $body = null, $token = null, $extraHeaders = array()) {
    list($base, $key) = app_cfg();
    $h = array('apikey: ' . $key, 'Accept: application/json');
    $h[] = 'Authorization: Bearer ' . ($token !== null ? $token : $key);
    if ($body !== null) { $h[] = 'Content-Type: application/json'; }
    foreach ($extraHeaders as $k => $v) { $h[] = $k . ': ' . $v; }
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $err = $raw === false ? curl_error($ch) : '';
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($status >= 400 && $err === '') {
        if (is_array($data)) {
            foreach (array('message', 'msg', 'error_description', 'error') as $f) {
                if (!empty($data[$f]) && is_string($data[$f])) { $err = $data[$f]; break; }
            }
        }
        if ($err === '') { $err = 'HTTP ' . $status; }
    }
    return array($status, $data, $err);
}

/* ---------------- auth (Supabase email + password) ---------------- */
function app_session_store($d) {
    $_SESSION['app'] = array(
        'access_token' => (string) $d['access_token'],
        'refresh_token' => (string) $d['refresh_token'],
        'expires_at' => time() + (int) (isset($d['expires_in']) ? $d['expires_in'] : 3600),
        'user_id' => isset($d['user']['id']) ? (string) $d['user']['id'] : '',
        'email' => isset($d['user']['email']) ? (string) $d['user']['email'] : '',
    );
}
$GLOBALS['__app_auth_error'] = '';
/** Server-side sign-in with the configured app-admin account. Returns '' on success or an error message. */
function app_auto_sign_in() {
    if (($p = app_config_problem()) !== '') { return $p; }
    list($email, $password) = app_cfg_account();
    list($st, $d, $err) = app_http('POST', '/auth/v1/token?grant_type=password', array('email' => $email, 'password' => $password));
    if ($st !== 200 || empty($d['access_token'])) { return 'Supabase sign-in for the app-admin account failed: ' . ($err !== '' ? $err : 'HTTP ' . $st) . '. Check email/password in includes/app_config.php.'; }
    app_session_store($d);
    return '';
}
function app_me() { return isset($_SESSION['app']) ? $_SESSION['app'] : array('email' => '', 'user_id' => ''); }
/** Valid access token: reuses / refreshes the session token, or signs in automatically. null on failure. */
function app_token() {
    if (!empty($_SESSION['app']['access_token'])) {
        if ($_SESSION['app']['expires_at'] - 60 > time()) { return $_SESSION['app']['access_token']; }
        list($st, $d, ) = app_http('POST', '/auth/v1/token?grant_type=refresh_token', array('refresh_token' => $_SESSION['app']['refresh_token']));
        if ($st === 200 && !empty($d['access_token'])) { app_session_store($d); return $_SESSION['app']['access_token']; }
        unset($_SESSION['app']);
    }
    $err = app_auto_sign_in();
    if ($err === '') { return $_SESSION['app']['access_token']; }
    $GLOBALS['__app_auth_error'] = $err;
    return null;
}
/** Page guard: only the normal website admin login. The Supabase connection is made automatically. */
function app_require() {
    admin_require_login();
    if (app_token() === null) {
        app_head('Mobile App', 'users');
        echo '<div class="flash err"><b>The Mobile App section is not connected yet.</b> ' . e($GLOBALS['__app_auth_error']) . '</div>';
        echo '<div class="panel"><p style="margin-top:0">Open <code>includes/app_config.php</code> on the server (copy it from <code>includes/app_config.sample.php</code> if it does not exist) and fill in: <b>anon_key</b> (Publishable key), <b>email</b> and <b>password</b> of an app-admin account.</p></div>';
        admin_foot();
        exit;
    }
}

/* ---------------- RPC ---------------- */
/** @return array{ok:bool,data:?array,error:string,forbidden:bool} */
function app_rpc($fn, $params = array()) {
    $out = array('ok' => false, 'data' => null, 'error' => '', 'forbidden' => false);
    if (!preg_match('/^admin_[a-z_]+$/', $fn)) { $out['error'] = 'Invalid function'; return $out; }
    $tok = app_token();
    if ($tok === null) { $out['error'] = $GLOBALS['__app_auth_error'] !== '' ? $GLOBALS['__app_auth_error'] : 'Not connected to the app backend.'; return $out; }
    list($st, $d, $err) = app_http('POST', '/rest/v1/rpc/' . $fn, (object) $params, $tok,
        array('Content-Profile' => APP_SCHEMA, 'Accept-Profile' => APP_SCHEMA));
    if ($st === 401) {   // token revoked/expired early: sign in again once and retry
        unset($_SESSION['app']);
        $tok = app_token();
        if ($tok !== null) {
            list($st, $d, $err) = app_http('POST', '/rest/v1/rpc/' . $fn, (object) $params, $tok,
                array('Content-Profile' => APP_SCHEMA, 'Accept-Profile' => APP_SCHEMA));
        }
    }
    if ($st >= 200 && $st < 300) {
        if (is_array($d) && isset($d['status']) && $d['status'] === 'error') {
            $out['error'] = isset($d['message']) ? (string) $d['message'] : 'Error';
        } else {
            $out['ok'] = true;
            $out['data'] = is_array($d) ? $d : array();
            return $out;
        }
    } else {
        $out['error'] = $err !== '' ? $err : 'Request failed';
    }
    $out['forbidden'] = stripos($out['error'], 'forbidden') !== false || $st === 401 || $st === 403;
    return $out;
}

/* ---------- typed wrappers (one per backend function) ---------- */
function app_dashboard() { return app_rpc('admin_dashboard'); }
function app_list_users($search, $status, $limit, $offset) {
    return app_rpc('admin_list_users', array('p_search' => $search !== '' ? $search : null, 'p_status' => $status !== '' ? $status : null, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset));
}
function app_get_user($id) { return app_rpc('admin_get_user', array('p_user_id' => $id)); }
function app_user_matches($id, $limit, $offset) { return app_rpc('admin_user_matches', array('p_user_id' => $id, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset)); }
function app_user_tournaments($id) { return app_rpc('admin_user_tournaments', array('p_user_id' => $id)); }
function app_user_transactions($id, $limit, $offset, $reason) {
    return app_rpc('admin_user_transactions', array('p_user_id' => $id, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset, 'p_reason' => $reason !== '' ? $reason : null));
}
function app_user_logins($id, $limit) { return app_rpc('admin_user_logins', array('p_user_id' => $id, 'p_limit' => (int) $limit)); }
function app_user_chat($id, $limit) { return app_rpc('admin_user_chat', array('p_user_id' => $id, 'p_limit' => (int) $limit)); }
function app_user_reports($id) { return app_rpc('admin_user_reports', array('p_user_id' => $id)); }
function app_list_reports($limit) { return app_rpc('admin_list_reports', array('p_limit' => (int) $limit)); }
function app_list_appeals($limit) { return app_rpc('admin_list_appeals', array('p_limit' => (int) $limit)); }
function app_list_tournaments($limit) { return app_rpc('admin_list_tournaments', array('p_limit' => (int) $limit)); }
function app_list_audit($userId, $limit, $offset) { return app_rpc('admin_list_audit', array('p_user_id' => $userId, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset)); }

function app_wallet_adjust($id, $amount, $direction, $note, $reference) {
    return app_rpc('admin_wallet_adjust', array('p_user_id' => $id, 'p_amount' => (int) $amount, 'p_direction' => $direction, 'p_note' => $note, 'p_reference' => $reference !== '' ? $reference : null));
}
function app_suspend_user($id, $reason, $reportId, $notes) {
    return app_rpc('admin_suspend_user', array('p_user_id' => $id, 'p_reason' => $reason, 'p_report_id' => $reportId !== '' ? $reportId : null, 'p_admin_notes' => $notes !== '' ? $notes : null));
}
function app_reinstate_user($id, $notes) { return app_rpc('admin_reinstate_user', array('p_user_id' => $id, 'p_notes' => $notes !== '' ? $notes : null)); }
function app_update_user_profile($id, $username, $clearAvatar) {
    return app_rpc('admin_update_user_profile', array('p_user_id' => $id, 'p_username' => $username !== '' ? $username : null, 'p_clear_avatar' => (bool) $clearAvatar));
}
function app_set_admin($id, $isAdmin) { return app_rpc('admin_set_admin', array('p_user_id' => $id, 'p_is_admin' => (bool) $isAdmin)); }
function app_send_notification($userId, $title, $body) { return app_rpc('admin_send_notification', array('p_user_id' => $userId, 'p_title' => $title, 'p_body' => $body)); }
function app_update_report($id, $status, $notes) { return app_rpc('admin_update_report', array('p_report_id' => $id, 'p_status' => $status, 'p_admin_notes' => $notes !== '' ? $notes : null)); }
function app_review_appeal($id, $decision, $notes) { return app_rpc('admin_review_appeal', array('p_appeal_id' => $id, 'p_decision' => $decision, 'p_admin_notes' => $notes !== '' ? $notes : null)); }
function app_create_tournament($name, $fee, $max, $closesAt, $startsAt, $moveSec, $joinSec) {
    return app_rpc('admin_create_tournament', array('p_name' => $name, 'p_entry_fee' => (int) $fee, 'p_max_participants' => (int) $max,
        'p_registration_closes_at' => $closesAt, 'p_starts_at' => $startsAt, 'p_move_timeout_sec' => (int) $moveSec, 'p_join_timeout_sec' => (int) $joinSec));
}
function app_generate_bracket($id) { return app_rpc('admin_generate_bracket', array('p_tournament_id' => $id)); }

/** Signed URL (5 min) for a report screenshot in the private bucket, or '' on error. */
function app_evidence_url($path) {
    $path = ltrim((string) $path, '/');
    if ($path === '' || strpos($path, '..') !== false) { return ''; }
    $tok = app_token();
    if ($tok === null) { return ''; }
    $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
    list($st, $d, ) = app_http('POST', '/storage/v1/object/sign/' . APP_EVIDENCE_BUCKET . '/' . $enc, array('expiresIn' => 300), $tok);
    if ($st !== 200 || empty($d['signedURL'])) { return ''; }
    list($base, ) = app_cfg();
    return $base . '/storage/v1' . (strpos($d['signedURL'], '/') === 0 ? '' : '/') . $d['signedURL'];
}

/* ---------------- view helpers ---------------- */
/** Null-safe nested get: g($arr, 'profile.username', '-') */
function g($a, $path, $def = null) {
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a) || $a[$k] === null) { return $def; }
        $a = $a[$k];
    }
    return $a;
}
/** First non-empty value among several keys of a row. */
function gx($row, $keys, $def = null) {
    foreach ((array) $keys as $k) { $v = g($row, $k); if ($v !== null && $v !== '') { return $v; } }
    return $def;
}
function app_items($r, $key = 'items') {
    if (!$r['ok'] || !is_array($r['data'])) { return array(); }
    if (isset($r['data'][$key]) && is_array($r['data'][$key])) { return $r['data'][$key]; }
    return (isset($r['data'][0]) && is_array($r['data'][0])) ? $r['data'] : array();   // plain array result
}
function app_num($v) { return ($v === null || $v === '' || !is_numeric($v)) ? '-' : number_format((float) $v, (floor((float) $v) == (float) $v) ? 0 : 2); }
function app_pno($n) { return ($n === null || $n === '') ? '---' : str_pad((string) (int) $n, 3, '0', STR_PAD_LEFT); }
function app_dt($iso) { return fmt_dt($iso); }
function app_badge($s) {
    $s = is_scalar($s) ? (string) $s : '';
    $map = array('active' => 'b-teal', 'ok' => 'b-teal', 'resolved' => 'b-teal', 'approved' => 'b-teal', 'win' => 'b-teal', 'won' => 'b-teal', 'credit' => 'b-teal', 'completed' => 'b-teal', 'finished' => 'b-teal',
                 'live' => 'b-pink', 'in_progress' => 'b-pink', 'running' => 'b-pink', 'playing' => 'b-pink',
                 'suspended' => 'b-red', 'rejected' => 'b-red', 'loss' => 'b-red', 'debit' => 'b-red', 'banned' => 'b-red', 'eliminated' => 'b-red',
                 'under_review' => 'b-gold', 'pending' => 'b-gold', 'open' => 'b-gold', 'registration' => 'b-gold', 'draw' => 'b-gold', 'scheduled' => 'b-gold',
                 'dismissed' => 'b-grey', 'cancelled' => 'b-grey');
    if ($s === '') { return '<span class="muted">-</span>'; }
    return '<span class="badge ' . (isset($map[$s]) ? $map[$s] : 'b-grey') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}
function app_avatar($url, $pno, $size = 36) {
    $url = is_string($url) ? $url : '';
    if ($url !== '' && preg_match('~^https://~i', $url)) {
        return '<img class="av" src="' . e($url) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" referrerpolicy="no-referrer">';
    }
    return '<span class="av av-ph" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;font-size:' . max(10, (int) ($size / 3.2)) . 'px">' . e(app_pno($pno)) . '</span>';
}
function app_user_link($id, $label) {
    $label = ($label !== null && $label !== '') ? (string) $label : (string) $id;
    if (!$id || !uuid_ok($id)) { return e($label !== '' ? $label : '-'); }
    return '<a href="/admin/app/users/?id=' . e($id) . '">' . e($label) . '</a>';
}
function app_text($v) {
    if ($v === null || $v === '') { return '<span class="muted">-</span>'; }
    if (is_bool($v)) { return $v ? 'yes' : 'no'; }
    if (is_array($v)) { return '<code class="j">' . e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>'; }
    return e($v);
}
/** Flash an RPC result after an action. */
function app_flash_result($r, $okMsg) {
    if ($r['ok']) { flash_set('ok', $okMsg); }
    else { flash_set('err', ($r['forbidden'] ? 'Forbidden: the account in includes/app_config.php is not an app admin. ' : '') . $r['error']); }
}
function app_error_box($r) {
    if ($r['ok']) { return ''; }
    return '<div class="flash err">' . ($r['forbidden'] ? '<b>Not allowed.</b> The account in includes/app_config.php is not an app admin (drotix.profiles.is_admin). ' : '') . e($r['error']) . '</div>';
}
function app_post($k, $max = 2000) { return clean_str(isset($_POST[$k]) ? $_POST[$k] : '', $max); }
function app_get($k, $max = 200) { return clean_str(isset($_GET[$k]) ? $_GET[$k] : '', $max); }
/** Pager when the total is unknown: shows Next if the page was full. */
function app_pager_simple($page, $count, $per) {
    if ($page <= 1 && $count < $per) { return ''; }
    $h = '<div class="pager">';
    if ($page > 1) { $h .= '<a href="' . e(qs(array('page' => $page - 1))) . '">&larr; Prev</a>'; }
    $h .= '<span>Page ' . (int) $page . '</span>';
    if ($count >= $per) { $h .= '<a href="' . e(qs(array('page' => $page + 1))) . '">Next &rarr;</a>'; }
    return $h . '</div>';
}
/** datetime-local input value -> ISO 8601 with the panel timezone offset, or null. */
function app_local_to_iso($v) {
    $v = trim((string) $v);
    if ($v === '') { return null; }
    $t = strtotime($v);
    return $t ? date('c', $t) : null;
}

/** Page header for App pages: CSP allowing avatar images, sidebar (App active), App sub-navigation. */
function app_head($title, $sub) {
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; form-action 'self'; frame-ancestors 'none'", true);
    admin_head($title, 'app');
    $tabs = array('users' => array('/admin/app/users/', 'Users'), 'dash' => array('/admin/app/', 'Stats'), 'reports' => array('/admin/app/reports/', 'Reports'),
                  'appeals' => array('/admin/app/appeals/', 'Appeals'), 'tour' => array('/admin/app/tournaments/', 'Tournaments'),
                  'cast' => array('/admin/app/broadcast/', 'Broadcast'), 'audit' => array('/admin/app/audit/', 'Audit log'));
    echo '<link rel="stylesheet" href="/admin/assets/app.css?v=1"><script src="/admin/assets/app.js?v=1" defer></script>';
    echo '<div class="apptop"><nav class="subnav">';
    foreach ($tabs as $k => $t) { echo '<a href="' . $t[0] . '"' . ($k === $sub ? ' class="on"' : '') . '>' . e($t[1]) . '</a>'; }
    echo '</nav></div>';
}
