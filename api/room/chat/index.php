<?php
/*
 * GET  /api/room/chat/?room_id=  - Get chat messages
 * POST /api/room/chat/  - Send chat message
 */
require __DIR__ . '/../../../includes/auth.php';
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function jout($code, $data) { http_response_code($code); echo json_encode($data); exit; }

$user = current_user();
if (!$user) { jout(401, array('ok' => false, 'error' => 'Not authenticated')); }

$roomId = $_GET['room_id'] ?? ($_POST['room_id'] ?? '');
if (!$roomId) { jout(400, array('ok' => false, 'error' => 'Missing room ID')); }

// Verify user is a participant
list($parts, , $r) = sb_select(tbl('room_participants'), array('room_id' => 'eq.' . $roomId, 'user_id' => 'eq.' . $user['id']), 1, 0);
if (!sb_ok($r) || empty($parts)) { jout(403, array('ok' => false, 'error' => 'Not a participant')); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    list($messages, , $r) = sb_select(tbl('room_chat'), array('room_id' => 'eq.' . $roomId, 'order' => 'created_at.asc'), 100, 0);
    if (!sb_ok($r)) { jout(500, array('ok' => false, 'error' => 'Failed to fetch messages')); }

    $enriched = array();
    foreach ($messages as $msg) {
        $sender = find_user_by_id($msg['user_id']);
        $enriched[] = array_merge($msg, array('username' => $sender ? $sender['username'] : 'Unknown'));
    }
    jout(200, array('ok' => true, 'messages' => $enriched));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) { jout(400, array('ok' => false, 'error' => 'Invalid JSON')); }

    $message = clean_str($in['message'] ?? '', 1000);
    if (strlen($message) < 1) { jout(400, array('ok' => false, 'error' => 'Message too short')); }

    $r = sb_insert(tbl('room_chat'), array(
        'room_id' => $roomId,
        'user_id' => $user['id'],
        'message' => $message,
        'type' => 'chat',
    ));

    if (!sb_ok($r)) { jout(500, array('ok' => false, 'error' => 'Failed to send message')); }

    jout(200, array('ok' => true));
}

jout(405, array('ok' => false, 'error' => 'Method not allowed'));