<?php
/*
 * GET  /api/tournament/chat/?match_id=  - Get chat messages
 * POST /api/tournament/chat/  - Send chat message
 * Body: {match_id, message}
 */
require __DIR__ . '/../../../includes/auth.php';
require __DIR__ . '/../../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function jout($code, $data) { http_response_code($code); echo json_encode($data); exit; }

$user = current_user();
if (!$user) { jout(401, array('ok' => false, 'error' => 'Not authenticated')); }

$matchId = $_GET['match_id'] ?? ($_POST['match_id'] ?? '');
if (!$matchId) { jout(400, array('ok' => false, 'error' => 'Missing match ID')); }

// Verify user is a player in this match
list($matches, , $r) = sb_select(tbl('tourney_matches'), array('id' => 'eq.' . $matchId), 1, 0);
if (!sb_ok($r) || empty($matches)) { jout(404, array('ok' => false, 'error' => 'Match not found')); }
$match = $matches[0];

if ($match['player1_id'] !== $user['id'] && $match['player2_id'] !== $user['id']) {
    jout(403, array('ok' => false, 'error' => 'Not a participant'));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    list($messages, , $r) = sb_select(tbl('tourney_chat'), array('match_id' => 'eq.' . $matchId, 'order' => 'created_at.asc'), 100, 0);
    if (!sb_ok($r)) { jout(500, array('ok' => false, 'error' => 'Failed to fetch messages')); }

    // Enrich with usernames
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

    $r = sb_insert(tbl('tourney_chat'), array(
        'match_id' => $matchId,
        'user_id' => $user['id'],
        'message' => $message,
        'type' => 'chat',
    ));

    if (!sb_ok($r)) { jout(500, array('ok' => false, 'error' => 'Failed to send message')); }

    // Mark as read by sender
    sb_update(tbl('tourney_chat'), array('id' => 'eq.' . $r['data'][0]['id']), array('read_by' => array($user['id'])));

    jout(200, array('ok' => true));
}

jout(405, array('ok' => false, 'error' => 'Method not allowed'));