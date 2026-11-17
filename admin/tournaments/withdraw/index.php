<?php
require __DIR__ . '/../../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../../includes/bootstrap.php';

$tournamentId = $_POST['tournament_id'] ?? '';
$userId = $_POST['user_id'] ?? '';

if (!$tournamentId || !$userId || $userId !== $user['id']) {
    header('Location: /tournaments/');
    exit;
}

csrf_verify();

$r = sb_update(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $tournamentId, 'user_id' => 'eq.' . $userId), array('status' => 'withdrawn'));

if (sb_ok($r)) {
    // Refund entry fee
    list($tRows, , ) = sb_select(tbl('tournaments'), array('id' => 'eq.' . $tournamentId), 1, 0);
    if ($tRows && $tRows[0]['entry_fee'] > 0) {
        $newCoins = $user['coins'] + $tRows[0]['entry_fee'];
        update_user($user['id'], array('coins' => $newCoins));
        $_SESSION['user_coins'] = $newCoins;
    }
    flash_set('ok', 'You have withdrawn from the tournament. Entry fee refunded.');
} else {
    flash_set('err', 'Failed to withdraw: ' . $r['error']);
}

header('Location: /tournaments/');
exit;