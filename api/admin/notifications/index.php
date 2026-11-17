<?php
/*
 * GET /api/admin/notifications/?type=messages|reports|appeals|deletions
 * Returns unread counts for admin panel real-time updates
 */
require __DIR__ . '/../../../includes/admin_layout.php';
admin_require_login();
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$type = $_GET['type'] ?? '';
$count = 0;

switch ($type) {
    case 'messages':
        $count = sb_count(tbl('contact'), array('status' => 'eq.new'));
        break;
    case 'reports':
        $count = sb_count(tbl('user_reports'), array('status' => 'in.("open","investigating")'));
        break;
    case 'appeals':
        $count = sb_count(tbl('suspension_appeals'), array('status' => 'in.("pending","under_review")'));
        break;
    case 'deletions':
        $count = sb_count(tbl('deletions'), array('status' => 'in.("pending","verifying")'));
        break;
    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Invalid type'));
        exit;
}

echo json_encode(array('ok' => true, 'count' => (int) $count));