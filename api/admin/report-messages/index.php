<?php
/*
 * GET /api/admin/report-messages/?id=report_id
 * Returns messages for a report with sender info
 */
require __DIR__ . '/../../../includes/admin_layout.php';
admin_require_login();
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = $_GET['id'] ?? '';
if (!$id) { http_response_code(400); echo json_encode(array('ok' => false, 'error' => 'Missing report ID')); exit; }

list($messages, , $r) = sb_select(tbl('report_messages'), array('report_id' => 'eq.' . $id, 'order' => 'created_at.asc'), 100, 0);
if (!sb_ok($r)) { http_response_code(500); echo json_encode(array('ok' => false, 'error' => 'Failed to fetch messages')); exit; }

// Enrich with sender info
$enriched = array();
foreach ($messages as $msg) {
    $sender = null;
    if ($msg['user_id']) {
        list($uRows, , ) = sb_select(tbl('users'), array('id' => 'eq.' . $msg['user_id']), 1, 0);
        if (!empty($uRows)) $sender = $uRows[0];
    }
    $enriched[] = array_merge($msg, array('sender' => $sender));
}

echo json_encode(array('ok' => true, 'messages' => $enriched));