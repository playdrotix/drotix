<?php
/*
 * POST /api/admin/report-message/read/  - Mark admin message as read
 * Body: {id}
 */
require __DIR__ . '/../../../includes/admin_layout.php';
admin_require_login();
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(array('ok' => false)); exit; }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(array('ok' => false)); exit; }

$id = $in['id'] ?? '';
if (!$id) { http_response_code(400); echo json_encode(array('ok' => false)); exit; }

$r = sb_update(tbl('report_messages'), array('id' => 'eq.' . $id), array('read_by_admin' => true, 'read_at' => gmdate('c')));
echo json_encode(array('ok' => sb_ok($r)));