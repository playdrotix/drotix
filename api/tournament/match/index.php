<?php
/*
 * GET /api/tournament/match/?id=  - Get match state for polling
 * Returns: {ok, board_state, current_turn, status, winner_id, result}
 */
require __DIR__ . '/../../../includes/auth.php';
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function jout($code, $data) { http_response_code($code); echo json_encode($data); exit; }

$user = current_user();
if (!$user) { jout(401, array('ok' => false, 'error' => 'Not authenticated')); }

$matchId = $_GET['id'] ?? '';
if (!$matchId) { jout(400, array('ok' => false, 'error' => 'Missing match ID')); }

list($matches, , $r) = sb_select(tbl('tourney_matches'), array('id' => 'eq.' . $matchId), 1, 0);
if (!sb_ok($r) || empty($matches)) { jout(404, array('ok' => false, 'error' => 'Match not found')); }
$match = $matches[0];

// Verify user is a player
if ($match['player1_id'] !== $user['id'] && $match['player2_id'] !== $user['id']) {
    jout(403, array('ok' => false, 'error' => 'Not a participant'));
}

jout(200, array(
    'ok' => true,
    'board_state' => $match['board_state'] ?? array_fill(0, ($match['board_size'] ?? 3) * ($match['board_size'] ?? 3), ''),
    'current_turn' => $match['current_turn'],
    'status' => $match['status'],
    'winner_id' => $match['winner_id'],
    'result' => $match['result'],
    'move_history' => $match['move_history'] ?? array(),
));