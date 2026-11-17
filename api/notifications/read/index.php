<?php
/*
 * GET /api/notifications/read/?ids[]=id1&ids[]=id2&redirect=url
 * Marks notifications as read and redirects
 */
require __DIR__ . '/../../../includes/auth.php';
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
security_headers(false);

$user = current_user();
if (!$user) {
    header('Location: /auth/login/?return=' . urlencode($_GET['redirect'] ?? '/'));
    exit;
}

$ids = $_GET['ids'] ?? array();
if (!is_array($ids)) { $ids = array($ids); }
$ids = array_filter(array_map(function($v) { return preg_replace('/[^a-fA-F0-9-]/', '', (string) $v); }, $ids));

if (!empty($ids)) {
    mark_notifications_read($user['id'], $ids);
}

$redirect = $_GET['redirect'] ?? '/notifications/';
if (strpos($redirect, '://') !== false || $redirect[0] !== '/') { $redirect = '/notifications/'; }
header('Location: ' . $redirect);
exit;