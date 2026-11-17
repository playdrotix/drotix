<?php
require __DIR__ . '/../../includes/auth.php';
require_guest();

$returnUrl = isset($_GET['return']) ? $_GET['return'] : '/dashboard/';
if (strpos($returnUrl, '://') !== false || $returnUrl[0] !== '/') { $returnUrl = '/dashboard/'; }

// Generate state and redirect to Facebook
$state = fb_oauth_generate_state($returnUrl);
if (!$state) {
    header('Location: /auth/login/?error=oauth_state_failed');
    exit;
}

$loginUrl = fb_oauth_get_login_url($state);
header('Location: ' . $loginUrl);
exit;