<?php
require __DIR__ . '/../../../../includes/auth.php';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';
$errorDesc = $_GET['error_description'] ?? '';

if ($error) {
    error_log('[drotix] Facebook OAuth error: ' . $error . ' - ' . $errorDesc);
    header('Location: /auth/login/?error=facebook_' . urlencode($error));
    exit;
}

if (!$code || !$state) {
    header('Location: /auth/login/?error=invalid_callback');
    exit;
}

// Validate state
$redirectUrl = fb_oauth_validate_state($state);
if (!$redirectUrl) {
    header('Location: /auth/login/?error=invalid_state');
    exit;
}

// Exchange code for access token
$accessToken = fb_oauth_exchange_code($code);
if (!$accessToken) {
    header('Location: /auth/login/?error=token_exchange_failed');
    exit;
}

// Get user info from Facebook
$fbUser = fb_oauth_get_user($accessToken);
if (!$fbUser) {
    header('Location: /auth/login/?error=user_info_failed');
    exit;
}

// Check if user exists with this Facebook ID
$user = find_user_by_provider('facebook', $fbUser['id']);

if ($user) {
    // Existing Facebook user - log them in
    if ($user['status'] === 'banned' || ($user['status'] === 'suspended' && !empty($user['suspended_until']) && $user['suspended_until'] > time())) {
        header('Location: /auth/login/?error=account_suspended');
        exit;
    }

    // Update avatar if changed
    if ($fbUser['avatar'] && $fbUser['avatar'] !== $user['avatar_url']) {
        update_user($user['id'], array('avatar_url' => $fbUser['avatar']));
    }

    user_login($user);
    header('Location: ' . $redirectUrl);
    exit;
}

// Check if email already exists with email provider
$existingByEmail = find_user_by_email($fbUser['email']);
if ($existingByEmail) {
    // Link Facebook to existing account
    if ($existingByEmail['provider'] === 'email') {
        update_user($existingByEmail['id'], array(
            'provider' => 'facebook',
            'provider_id' => $fbUser['id'],
            'avatar_url' => $fbUser['avatar'] ?? $existingByEmail['avatar_url'],
            'email_verified' => true,
        ));
        user_login($existingByEmail);
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        // Email exists but with different provider - shouldn't happen but handle it
        header('Location: /auth/login/?error=account_conflict');
        exit;
    }
}

// Create new user from Facebook
$baseUsername = preg_replace('/[^a-zA-Z0-9]/', '', $fbUser['name']);
$username = generate_unique_username($baseUsername ?: 'player');

$userData = array(
    'email' => $fbUser['email'],
    'username' => $username,
    'password_hash' => null, // No password for Facebook accounts
    'avatar_url' => $fbUser['avatar'],
    'provider' => 'facebook',
    'provider_id' => $fbUser['id'],
    'email_verified' => true,
    'status' => 'active',
);

$r = sb_insert(tbl('users'), $userData, array(), 'return=representation');
if (sb_ok($r) && !empty($r['data'])) {
    $newUser = $r['data'][0];
    user_login($newUser);
    header('Location: ' . $redirectUrl);
    exit;
}

header('Location: /auth/login/?error=registration_failed');
exit;