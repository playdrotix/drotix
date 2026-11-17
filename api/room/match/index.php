<?php
/*
 * GET /api/room/match/?id=  - Get room game state for polling
 */
require __DIR__ . '/../../../includes/auth.php';
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function jout($code, $data) { http_response_code($code); echo json_encode($data); exit; }

$user = current_user();
if (!$user) { jout(401, array('ok' => false, 'error' => 'Not authenticated')); }

$roomId = $_GET['id'] ?? '';
if (!$roomId) { jout(400, array('ok' => false, 'error' => 'Missing room ID')); }

list($rooms, , $r) = sb_select(tbl('private_rooms'), array('id' => 'eq.' . $roomId), 1, 0);
if (!sb_ok($r) || empty($rooms)) { jout(404, array('ok' => false, 'error' => 'Room not found')); }
$room = $rooms[0];

// Verify user is a player
if ($room['player1_id'] !== $user['id'] && $room['player2_id'] !== $user['id']) {
    jout(403, array('ok' => false, 'error' => 'Not a player in this room'));
}

jout(200, array(
    'ok' => true,
    'board_state' => $room['board_state'] ?? array_fill(0, ($room['board_size'] ?? 3) * ($room['board_size'] ?? 3), ''),
    'current_turn' => $room['current_turn'],
    'status' => $room['status'],
    'winner_id' => $room['winner_id'],
    'result' => $room['result'],
    'move_history' => $room['move_history'] ?? array(),
));