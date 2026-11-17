<?php
/*
 * POST /api/tournament/move/  - Submit a move in a tournament match
 * Body: {match_id, position, symbol}
 * Returns: {ok, board_state, current_turn, status, winner_id, result}
 */
require __DIR__ . '/../../../includes/auth.php';
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function jout($code, $data) { http_response_code($code); echo json_encode($data); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jout(405, array('ok' => false, 'error' => 'Method not allowed')); }

$user = current_user();
if (!$user) { jout(401, array('ok' => false, 'error' => 'Not authenticated')); }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { jout(400, array('ok' => false, 'error' => 'Invalid JSON')); }

$matchId = isset($in['match_id']) ? (string) $in['match_id'] : '';
$position = isset($in['position']) ? (int) $in['position'] : -1;
$symbol = isset($in['symbol']) ? (string) $in['symbol'] : '';

if (!$matchId || $position < 0 || !in_array($symbol, array('X', 'O'), true)) {
    jout(400, array('ok' => false, 'error' => 'Invalid parameters'));
}

// Get match
list($matches, , $r) = sb_select(tbl('tourney_matches'), array('id' => 'eq.' . $matchId), 1, 0);
if (!sb_ok($r) || empty($matches)) { jout(404, array('ok' => false, 'error' => 'Match not found')); }
$match = $matches[0];

// Verify user is a player
if ($match['player1_id'] !== $user['id'] && $match['player2_id'] !== $user['id']) {
    jout(403, array('ok' => false, 'error' => 'Not a participant in this match'));
}

// Check turn
if ($match['current_turn'] !== $user['id']) {
    jout(400, array('ok' => false, 'error' => 'Not your turn'));
}

// Check game not over
if (in_array($match['status'], array('completed', 'walkover', 'cancelled', 'disputed'))) {
    jout(400, array('ok' => false, 'error' => 'Match already finished'));
}

// Validate symbol matches player
$isPlayer1 = $match['player1_id'] === $user['id'];
$expectedSymbol = $isPlayer1 ? 'X' : 'O';
if ($symbol !== $expectedSymbol) {
    jout(400, array('ok' => false, 'error' => 'Invalid symbol for your player'));
}

// Get board state
$boardSize = $match['board_size'] ?? 3;
$winCondition = $match['win_condition'] ?? 3;
$boardState = $match['board_state'] ?? array_fill(0, $boardSize * $boardSize, '');
$moveHistory = $match['move_history'] ?? array();

if ($boardState[$position] !== '') {
    jout(400, array('ok' => false, 'error' => 'Cell already occupied'));
}

// Make move
$boardState[$position] = $symbol;
$moveHistory[] = array('player_id' => $user['id'], 'position' => $position, 'symbol' => $symbol, 'timestamp' => gmdate('c'));

// Check winner
function checkWinner($board, $size, $win) {
    $lines = [];
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
        foreach (array(array(0,1), array(1,0), array(1,1), array(1,-1)) as $d) {
            $er = $r + $d[0]*($win-1); $ec = $c + $d[1]*($win-1);
            if ($er < 0 || $er >= $size || $ec < 0 || $ec >= $size) continue;
            $line = [];
            for ($i = 0; $i < $win; $i++) $line[] = ($r + $d[0]*$i)*$size + $c + $d[1]*$i;
            $lines[] = $line;
        }
    }
    foreach ($lines as $line) {
        $v = $board[$line[0]];
        if ($v && array_reduce($line, function($carry, $idx) use ($board, $v) { return $carry && $board[$idx] === $v; }, true)) {
            return array('player' => $v, 'line' => $line);
        }
    }
    return null;
}

$winner = checkWinner($boardState, $boardSize, $winCondition);
$empty = array_filter($boardState, function($v) { return $v === ''; });

$newStatus = $match['status'];
$winnerId = null;
$result = null;

if ($winner) {
    $newStatus = 'completed';
    $winnerId = $winner['player'] === 'X' ? $match['player1_id'] : $match['player2_id'];
    $loserId = $winnerId === $match['player1_id'] ? $match['player2_id'] : $match['player1_id'];
    $result = array('winner_id' => $winnerId, 'loser_id' => $loserId, 'draws' => 0, 'reason' => 'win', 'winning_line' => $winner['line']);
} elseif (empty($empty)) {
    $newStatus = 'completed';
    $result = array('winner_id' => null, 'loser_id' => null, 'draws' => 1, 'reason' => 'draw');
} else {
    $newStatus = 'in_progress';
}

// Switch turn
$nextTurn = $isPlayer1 ? $match['player2_id'] : $match['player1_id'];

// Update match
$updateData = array(
    'board_state' => $boardState,
    'move_history' => $moveHistory,
    'current_turn' => $nextTurn,
    'status' => $newStatus,
    'updated_at' => gmdate('c'),
);
if ($newStatus === 'in_progress' && $match['status'] === 'ready') {
    $updateData['started_at'] = gmdate('c');
}
if ($newStatus === 'completed') {
    $updateData['completed_at'] = gmdate('c');
    if ($winnerId) { $updateData['winner_id'] = $winnerId; }
    if ($result) { $updateData['result'] = $result; }
}

$r = sb_update(tbl('tourney_matches'), array('id' => 'eq.' . $matchId), $updateData);
if (!sb_ok($r)) { jout(500, array('ok' => false, 'error' => 'Failed to update match')); }

// Update user stats if completed
if ($newStatus === 'completed') {
    $winnerUser = find_user_by_id($winnerId);
    $loserUser = find_user_by_id($loserId ?? '');

    if ($winnerUser) {
        $newWins = ($winnerUser['total_wins'] ?? 0) + 1;
        $newRating = ($winnerUser['rating'] ?? 1000) + 25;
        update_user($winnerId, array('total_wins' => $newWins, 'rating' => $newRating, 'coins' => ($winnerUser['coins'] ?? 1000) + 100));
    }
    if ($loserUser && !$result['draws']) {
        $newLosses = ($loserUser['total_losses'] ?? 0) + 1;
        $newRating = max(100, ($loserUser['rating'] ?? 1000) - 15);
        update_user($loserId, array('total_losses' => $newLosses, 'rating' => $newRating));
    }
    if ($result && $result['draws']) {
        if ($winnerUser) { update_user($winnerId, array('total_draws' => ($winnerUser['total_draws'] ?? 0) + 1)); }
        if ($loserUser) { update_user($loserId, array('total_draws' => ($loserUser['total_draws'] ?? 0) + 1)); }
    }

    // Update participant record
    if ($winnerId) {
        sb_update(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $match['tournament_id'], 'user_id' => 'eq.' . $winnerId), array('status' => 'confirmed'));
    }
    if ($loserId) {
        sb_update(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $match['tournament_id'], 'user_id' => 'eq.' . $loserId), array('status' => 'eliminated', 'eliminated_at' => gmdate('c'), 'eliminated_round' => $match['round']));
    }

    // Create notifications
    if ($winnerId) {
        create_user_notification($winnerId, 'match_result', 'Match Won!', 'You won your match in ' . $match['tournament_id'], array('match_id' => $matchId), '/tournaments/match/?id=' . $matchId);
    }
    if ($loserId) {
        create_user_notification($loserId, 'match_result', 'Match Lost', 'You were eliminated from the tournament', array('match_id' => $matchId), '/tournaments/match/?id=' . $matchId);
    }
}

jout(200, array(
    'ok' => true,
    'board_state' => $boardState,
    'current_turn' => $nextTurn,
    'status' => $newStatus,
    'winner_id' => $winnerId,
    'result' => $result,
    'move_history' => $moveHistory,
));