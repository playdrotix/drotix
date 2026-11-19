<?php
/* App admin: game tournaments (list, create, start now). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($act === 'create') {
        $name = app_post('name', 80);
        $fee = (int) (isset($_POST['entry_fee']) ? $_POST['entry_fee'] : 0);
        $max = (int) (isset($_POST['max_participants']) ? $_POST['max_participants'] : 0);
        $closes = app_local_to_iso(isset($_POST['closes_at']) ? $_POST['closes_at'] : '');
        $starts = app_local_to_iso(isset($_POST['starts_at']) ? $_POST['starts_at'] : '');
        $move = max(5, (int) (isset($_POST['move_timeout']) ? $_POST['move_timeout'] : 20));
        $join = max(10, (int) (isset($_POST['join_timeout']) ? $_POST['join_timeout'] : 60));
        $errs = array();
        if ($name === '') { $errs[] = 'name'; }
        if ($fee < 0) { $errs[] = 'entry fee (0 or more)'; }
        if ($max < 2 || $max > 256) { $errs[] = 'max players (2-256)'; }
        if (!$closes || !$starts) { $errs[] = 'dates'; }
        elseif (strtotime($closes) > strtotime($starts)) { $errs[] = 'registration must close before the start'; }
        if ($errs) { flash_set('err', 'Check: ' . implode(', ', $errs) . '.'); redirect('/admin/app/tournaments/?new=1'); }
        app_flash_result(app_create_tournament($name, $fee, $max, $closes, $starts, $move, $join), 'Tournament "' . $name . '" created.');
    } elseif ($act === 'start') {
        $tid = isset($_POST['tournament_id']) ? (string) $_POST['tournament_id'] : '';
        if (!uuid_ok($tid)) { flash_set('err', 'Bad tournament id.'); }
        else { app_flash_result(app_generate_bracket($tid), 'Bracket generated: the tournament has started.'); }
    }
    redirect('/admin/app/tournaments/');
}

$r = app_list_tournaments(100);
$items = app_items($r);
app_head('Tournaments', 'tour');

echo '<details class="panel"' . (isset($_GET['new']) ? ' open' : '') . '><summary class="btn sm">+ Create tournament</summary>';
$def1 = date('Y-m-d\TH:i', strtotime('+1 day 18:00'));
$def2 = date('Y-m-d\TH:i', strtotime('+1 day 18:30'));
echo '<form method="post" style="margin-top:16px" data-confirm="Create this tournament? Players will see it in the app.">' . csrf_field() . '<input type="hidden" name="action" value="create"><div class="fgrid">';
echo '<label>Name<input name="name" required maxlength="80" placeholder="Sunday Cup"></label>';
echo '<label>Entry fee (coins, 0 = free)<input name="entry_fee" type="number" min="0" step="1" value="0" required></label>';
echo '<label>Max players (2–256)<input name="max_participants" type="number" min="2" max="256" value="16" required></label>';
echo '<label>Registration closes<input name="closes_at" type="datetime-local" value="' . e($def1) . '" required></label>';
echo '<label>Starts at<input name="starts_at" type="datetime-local" value="' . e($def2) . '" required></label>';
echo '<label>Move timeout (sec)<input name="move_timeout" type="number" min="5" max="300" value="20"></label>';
echo '<label>Join timeout (sec)<input name="join_timeout" type="number" min="10" max="900" value="60"></label>';
echo '</div><p class="muted" style="margin-top:0">Times are in ' . e(date_default_timezone_get()) . ' (' . e(date('P')) . ').</p><button class="btn" type="submit">Create tournament</button></form></details>';

echo app_error_box($r);
echo '<div class="tbl-wrap"><table><tr><th>Name</th><th>Status</th><th>Players</th><th>Entry fee</th><th>Registration closes</th><th>Starts</th><th>Winner</th><th></th></tr>';
foreach ($items as $t) {
    $tid = (string) g($t, 'id', '');
    $st = (string) gx($t, array('status', 'tournament_status'), '');
    $players = g($t, 'players');
    $max = gx($t, array('max_participants', 'bracket_size'));
    echo '<tr><td><b>' . e(g($t, 'name', '-')) . '</b><div class="muted" style="font-size:12px">' . e(substr($tid, 0, 8)) . '</div></td><td>' . app_badge($st) . '</td>';
    echo '<td>' . app_num(is_array($players) ? count($players) : $players) . ($max !== null ? ' / ' . app_num($max) : '') . '</td><td>' . app_num(gx($t, array('entry_fee_minor', 'entry_fee'))) . '</td>';
    echo '<td class="nowrap">' . app_dt(g($t, 'registration_closes_at')) . '</td><td class="nowrap">' . app_dt(g($t, 'starts_at')) . '</td><td>' . app_text(g($t, 'winner_username')) . '</td><td>';
    if (uuid_ok($tid) && !in_array($st, array('live', 'in_progress', 'running', 'playing', 'finished', 'completed', 'cancelled', 'canceled'), true)) {
        echo '<form method="post" data-confirm="START NOW: this closes registration, seeds the players and creates the bracket (byes for empty seats). It cannot be undone. Continue?">' . csrf_field()
            . '<input type="hidden" name="action" value="start"><input type="hidden" name="tournament_id" value="' . e($tid) . '"><button class="btn danger sm" type="submit">Start now</button></form>';
    }
    echo '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="8" class="muted">' . ($r['ok'] ? 'No tournaments yet.' : 'Could not load tournaments.') . '</td></tr>'; }
echo '</table></div>';
admin_foot();
