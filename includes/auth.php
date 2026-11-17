<?php
/* User authentication helpers for Drotix Tournament Gaming Platform */
require_once __DIR__ . '/bootstrap.php';

/* ---------- User Session Management ---------- */

function user_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('drotix_user');
    session_set_cookie_params(array(
        'lifetime' => (int) cfg('auth.session_lifetime_days', 30) * 86400,
        'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ));
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function user_session_regenerate() {
    session_regenerate_id(true);
}

function user_session_destroy() {
    $_SESSION = array();
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
}

/* ---------- Current User ---------- */

function current_user() {
    user_session_start();
    if (empty($_SESSION['user_id'])) { return null; }

    // Check session expiry
    if (!empty($_SESSION['session_expires']) && $_SESSION['session_expires'] < time()) {
        user_logout();
        return null;
    }

    // Update last activity
    $_SESSION['last_activity'] = time();

    return array(
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'username' => $_SESSION['user_username'],
        'avatar_url' => $_SESSION['user_avatar'] ?? null,
        'coins' => $_SESSION['user_coins'] ?? 1000,
        'rating' => $_SESSION['user_rating'] ?? 1000,
        'status' => $_SESSION['user_status'] ?? 'active',
        'provider' => $_SESSION['user_provider'] ?? 'email',
    );
}

function require_login($redirect = true) {
    $user = current_user();
    if (!$user) {
        if ($redirect) {
            $return = urlencode($_SERVER['REQUEST_URI']);
            header('Location: /auth/login/?return=' . $return);
            exit;
        }
        return false;
    }
    if ($user['status'] === 'banned') {
        user_logout();
        if ($redirect) {
            header('Location: /auth/banned/');
            exit;
        }
        return false;
    }
    if ($user['status'] === 'suspended' && !empty($user['suspended_until']) && $user['suspended_until'] > time()) {
        if ($redirect) {
            header('Location: /auth/suspended/');
            exit;
        }
        return false;
    }
    return $user;
}

function require_guest($redirect = true) {
    $user = current_user();
    if ($user) {
        if ($redirect) {
            header('Location: /dashboard/');
            exit;
        }
        return false;
    }
    return true;
}

/* ---------- Login / Logout ---------- */

function user_login($user, $remember = false) {
    user_session_regenerate();
    $lifetime = $remember ? (int) cfg('auth.session_lifetime_days', 30) * 86400 : 0;

    // Update session cookie lifetime
    session_set_cookie_params(array(
        'lifetime' => $lifetime,
        'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ));

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_avatar'] = $user['avatar_url'] ?? null;
    $_SESSION['user_coins'] = $user['coins'] ?? 1000;
    $_SESSION['user_rating'] = $user['rating'] ?? 1000;
    $_SESSION['user_status'] = $user['status'] ?? 'active';
    $_SESSION['user_provider'] = $user['provider'] ?? 'email';
    $_SESSION['last_activity'] = time();
    $_SESSION['session_expires'] = $lifetime > 0 ? time() + $lifetime : 0;

    // Update last login in database
    sb_update(tbl('users'), array('id' => 'eq.' . $user['id']), array(
        'last_login_at' => gmdate('c'),
        'last_login_ip' => client_ip(),
    ));
}

function user_logout() {
    $user = current_user();
    if ($user) {
        // Optionally invalidate server-side session token
        sb_delete(tbl('user_sessions'), array('user_id' => 'eq.' . $user['id'], 'session_token' => 'eq.' . session_id()));
    }
    user_session_destroy();
}

/* ---------- Password ---------- */

function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

function validate_password($password) {
    $min = (int) cfg('auth.password_min_length', 8);
    if (strlen($password) < $min) {
        return "Password must be at least {$min} characters.";
    }
    // Optional: add complexity requirements
    return true;
}

/* ---------- Rate Limiting for Auth ---------- */

function auth_rate_limit($action, $identifier, $maxAttempts = null, $windowMinutes = null) {
    $max = $maxAttempts ?? (int) cfg('auth.max_login_attempts', 5);
    $window = ($windowMinutes ?? (int) cfg('auth.lockout_minutes', 15)) * 60;
    $key = "auth:{$action}:{$identifier}";
    $count = rl_count($key, $window);
    if ($count >= $max) {
        $reset = $window - (time() - min(rl_read($key, $window)));
        return array(false, "Too many attempts. Try again in " . ceil($reset / 60) . " minutes.");
    }
    rl_add($key, $window);
    return array(true, null);
}

function auth_clear_rate_limit($action, $identifier) {
    rl_clear("auth:{$action}:{$identifier}");
}

/* ---------- Email Verification ---------- */

function generate_verification_token() {
    return bin2hex(random_bytes(32));
}

function send_verification_email($user, $token) {
    $url = rtrim(cfg('site_url'), '/') . '/auth/verify/?token=' . urlencode($token);
    $subject = 'Verify your email - ' . cfg('site_name');
    $body = "Hi {$user['username']},\n\n";
    $body .= "Please verify your email address by clicking the link below:\n\n";
    $body .= $url . "\n\n";
    $body .= "This link expires in " . (int) cfg('auth.verification_token_hours', 24) . " hours.\n\n";
    $body .= "--\n" . cfg('site_name') . " Team";

    $from = header_safe(cfg('mail_from', 'no-reply@localhost'));
    $headers = "From: " . $from . "\r\nReply-To: " . header_safe(cfg('support_email', $from)) . "\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($user['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/* ---------- Password Reset ---------- */

function generate_reset_token() {
    return bin2hex(random_bytes(32));
}

function send_reset_email($user, $token) {
    $url = rtrim(cfg('site_url'), '/') . '/auth/reset/?token=' . urlencode($token);
    $subject = 'Reset your password - ' . cfg('site_name');
    $body = "Hi {$user['username']},\n\n";
    $body .= "You requested a password reset. Click the link below to set a new password:\n\n";
    $body .= $url . "\n\n";
    $body .= "This link expires in " . (int) cfg('auth.reset_token_hours', 2) . " hours.\n\n";
    $body .= "If you didn't request this, please ignore this email.\n\n";
    $body .= "--\n" . cfg('site_name') . " Team";

    $from = header_safe(cfg('mail_from', 'no-reply@localhost'));
    $headers = "From: " . $from . "\r\nReply-To: " . header_safe(cfg('support_email', $from)) . "\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($user['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/* ---------- User Creation / Lookup ---------- */

function find_user_by_email($email) {
    list($rows, , $r) = sb_select(tbl('users'), array('email' => 'eq.' . strtolower($email), 'select' => '*'), 1, 0);
    if (sb_ok($r) && !empty($rows)) { return $rows[0]; }
    return null;
}

function find_user_by_id($id) {
    list($rows, , $r) = sb_select(tbl('users'), array('id' => 'eq.' . $id, 'select' => '*'), 1, 0);
    if (sb_ok($r) && !empty($rows)) { return $rows[0]; }
    return null;
}

function find_user_by_username($username) {
    list($rows, , $r) = sb_select(tbl('users'), array('username' => 'eq.' . $username, 'select' => '*'), 1, 0);
    if (sb_ok($r) && !empty($rows)) { return $rows[0]; }
    return null;
}

function find_user_by_provider($provider, $providerId) {
    list($rows, , $r) = sb_select(tbl('users'), array(
        'provider' => 'eq.' . $provider,
        'provider_id' => 'eq.' . $providerId,
        'select' => '*'
    ), 1, 0);
    if (sb_ok($r) && !empty($rows)) { return $rows[0]; }
    return null;
}

function create_user($data) {
    $defaults = array(
        'coins' => 1000,
        'total_wins' => 0,
        'total_losses' => 0,
        'total_draws' => 0,
        'rating' => 1000,
        'status' => 'active',
        'provider' => 'email',
    );
    $data = array_merge($defaults, $data);
    $r = sb_insert(tbl('users'), $data);
    return sb_ok($r) ? $r['data'] : null;
}

function update_user($userId, $data) {
    $r = sb_update(tbl('users'), array('id' => 'eq.' . $userId), $data);
    return sb_ok($r);
}

/* ---------- Server-side Session Tokens (for API validation) ---------- */

function create_user_session($userId, $ip = null, $ua = null) {
    $token = bin2hex(random_bytes(32));
    $expires = gmdate('c', time() + (int) cfg('auth.session_lifetime_days', 30) * 86400);
    $r = sb_insert(tbl('user_sessions'), array(
        'user_id' => $userId,
        'session_token' => $token,
        'expires_at' => $expires,
        'ip' => $ip ?? client_ip(),
        'user_agent' => $ua ?? (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
    ));
    return sb_ok($r) ? $token : null;
}

function validate_user_session($token) {
    list($rows, , $r) = sb_select(tbl('user_sessions'), array(
        'session_token' => 'eq.' . $token,
        'expires_at' => 'gt.' . gmdate('c'),
        'select' => '*,user:users(*)'
    ), 1, 0);
    if (sb_ok($r) && !empty($rows)) {
        // Update last activity
        sb_update(tbl('user_sessions'), array('session_token' => 'eq.' . $token), array('last_activity' => gmdate('c')));
        return $rows[0];
    }
    return null;
}

function delete_user_session($token) {
    return sb_delete(tbl('user_sessions'), array('session_token' => 'eq.' . $token));
}

function delete_user_sessions($userId, $exceptToken = null) {
    $filter = array('user_id' => 'eq.' . $userId);
    if ($exceptToken) { $filter['session_token'] = 'neq.' . $exceptToken; }
    return sb_delete(tbl('user_sessions'), $filter);
}

/* ---------- Facebook OAuth ---------- */

function fb_oauth_generate_state($redirectUrl = '/') {
    $state = bin2hex(random_bytes(32));
    $expires = gmdate('c', time() + 600); // 10 minutes
    $r = sb_insert(tbl('fb_oauth_states'), array(
        'state' => $state,
        'redirect_url' => $redirectUrl,
        'expires_at' => $expires,
    ));
    return sb_ok($r) ? $state : null;
}

function fb_oauth_validate_state($state) {
    list($rows, , $r) = sb_select(tbl('fb_oauth_states'), array(
        'state' => 'eq.' . $state,
        'expires_at' => 'gt.' . gmdate('c'),
    ), 1, 0);
    if (sb_ok($r) && !empty($rows)) {
        // Delete used state
        sb_delete(tbl('fb_oauth_states'), array('state' => 'eq.' . $state));
        return $rows[0]['redirect_url'];
    }
    return null;
}

function fb_oauth_get_login_url($state, $redirectUri = null) {
    $fb = cfg('facebook', array());
    $url = 'https://www.facebook.com/' . ($fb['graph_version'] ?? 'v18.0') . '/dialog/oauth';
    $params = array(
        'client_id' => $fb['app_id'],
        'redirect_uri' => $redirectUri ?? $fb['redirect_uri'],
        'state' => $state,
        'scope' => implode(',', $fb['scopes'] ?? array('email', 'public_profile')),
        'response_type' => 'code',
        'auth_type' => 'rerequest',
    );
    return $url . '?' . http_build_query($params);
}

function fb_oauth_exchange_code($code, $redirectUri = null) {
    $fb = cfg('facebook', array());
    $url = 'https://graph.facebook.com/' . ($fb['graph_version'] ?? 'v18.0') . '/oauth/access_token';
    $params = array(
        'client_id' => $fb['app_id'],
        'client_secret' => $fb['app_secret'],
        'redirect_uri' => $redirectUri ?? $fb['redirect_uri'],
        'code' => $code,
    );
    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ));
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data['access_token'])) {
        return $data['access_token'];
    }
    return null;
}

function fb_oauth_get_user($accessToken) {
    $fb = cfg('facebook', array());
    $url = 'https://graph.facebook.com/' . ($fb['graph_version'] ?? 'v18.0') . '/me';
    $params = array(
        'fields' => 'id,name,email,picture.type(large)',
        'access_token' => $accessToken,
    );
    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ));
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data['id'], $data['email'])) {
        return array(
            'id' => $data['id'],
            'name' => $data['name'] ?? '',
            'email' => $data['email'],
            'avatar' => isset($data['picture']['data']['url']) ? $data['picture']['data']['url'] : null,
        );
    }
    return null;
}

/* ---------- Notifications ---------- */

function create_user_notification($userId, $type, $title, $body = '', $data = array(), $actionUrl = null, $expiresHours = 168) {
    $expires = $expiresHours ? gmdate('c', time() + $expiresHours * 3600) : null;
    $r = sb_insert(tbl('user_notifications'), array(
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'data' => $data,
        'action_url' => $actionUrl,
        'expires_at' => $expires,
    ));
    return sb_ok($r);
}

function get_user_notifications($userId, $unreadOnly = false, $limit = 20) {
    $query = array('user_id' => 'eq.' . $userId, 'order' => 'created_at.desc', 'limit' => $limit);
    if ($unreadOnly) { $query['read_at'] = 'is.null'; }
    list($rows, , $r) = sb_select(tbl('user_notifications'), $query, $limit, 0);
    return sb_ok($r) ? $rows : array();
}

function mark_notifications_read($userId, $notificationIds = null) {
    $filter = array('user_id' => 'eq.' . $userId, 'read_at' => 'is.null');
    if ($notificationIds) { $filter['id'] = 'in.(' . implode(',', $notificationIds) . ')'; }
    return sb_update(tbl('user_notifications'), $filter, array('read_at' => gmdate('c')));
}

function get_unread_notification_count($userId) {
    $count = sb_count(tbl('user_notifications'), array('user_id' => 'eq.' . $userId, 'read_at' => 'is.null'));
    return $count ?? 0;
}

/* ---------- Username Generation ---------- */

function generate_unique_username($base) {
    $base = preg_replace('/[^a-zA-Z0-9_]/', '', $base);
    $base = substr($base, 0, 30);
    if ($base === '') { $base = 'player'; }

    $username = $base;
    $counter = 1;
    while (find_user_by_username($username)) {
        $username = $base . '_' . $counter++;
        if ($counter > 9999) { $username = $base . '_' . substr(bin2hex(random_bytes(4)), 0, 6); break; }
    }
    return $username;
}