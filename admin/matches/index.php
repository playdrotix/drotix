<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$table = tbl('tourney_matches');
$allowedStatus = array('pending', 'scheduled', 'ready', 'in_progress', 'completed', 'walkover', 'disputed', 'cancelled');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = $_POST['id'] ?? '';
    $act = $_POST['action'] ?? '';
    if (uuid_ok($id)) {
        if ($act === 'status') {
            $st = $_POST['status'] ?? '';
            if (in_array($st, $allowedStatus, true)) {
                $data = array('status' => $st, 'updated_at' => gmdate('c'));
                if ($st === 'completed') { $data['completed_at'] = gmdate('c'); }
                if ($st === 'in_progress') { $data['started_at'] = gmdate('c'); }
                $r = sb_update($table, array('id' => 'eq.' . $id), $data);
                if (sb_ok($r)) { audit_log('match_status', $id, $st); flash_set('ok', 'Match status updated.'); }
                else { flash_set('err', $r['error']); }
            }
        } elseif ($act === 'result') {
            $winnerId = $_POST['winner_id'] ?? '';
            $reason = $_POST['reason'] ?? 'admin';
            $data = array('status' => 'completed', 'completed_at' => gmdate('c'), 'updated_at' => gmdate('c'));
            if ($winnerId && uuid_ok($winnerId)) { $data['winner_id'] = $winnerId; }
            $data['result'] = array('winner_id' => $winnerId ?: null, 'loser_id' => null, 'reason' => $reason);
            $r = sb_update($table, array('id' => 'eq.' . $id), $data);
            if (sb_ok($r)) { audit_log('match_result', $id, $reason); flash_set('ok', 'Match result recorded.'); }
            else { flash_set('err', $r['error']); }
        }
    }
    redirect('/admin/matches/' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$q = pg_safe($_GET['q'] ?? '');
$st = $_GET['status'] ?? '';
$tourneyId = $_GET['tournament_id'] ?? '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowedStatus, true)) { $query['status'] = 'eq.' . $st; }
if ($tourneyId && uuid_ok($tourneyId)) { $query['tournament_id'] = 'eq.' . $tourneyId; }
if ($q !== '') { $query['or'] = '(bracket_position.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('matches.csv', sb_all($table, $query)); }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

// Get tournaments for filter
list($tourneys, , ) = sb_select(tbl('tournaments'), array('order' => 'name.asc'), 100, 0);

admin_head('Matches', 'matches');
echo '<div class="cards">';
echo '<div class="card"><div class="n">' . e(sb_count($table) ?? '?') . '</div><div class="l">Total Matches</div></div>';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.in_progress')) ? ' hot' : '') . '"><div class="n">' . e(sb_count($table, array('status' => 'eq.in_progress')) ?? '?') . '</div><div class="l">In Progress</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.ready')) ?? '?') . '</div><div class="l">Ready to Start</div></div>';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.disputed')) ? ' hot' : '') . '"><div class="n">' . e(sb_count($table, array('status' => 'eq.disputed')) ?? '?') . '</div><div class="l">Disputed</div></div>';
echo '</div>';

echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search bracket position"><select name="status"><option value="">All statuses</option>';
foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($st === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
echo '</select><select name="tournament_id"><option value="">All tournaments</option>';
foreach ($tourneys as $t) { echo '<option value="' . e($t['id']) . '"' . ($tourneyId === $t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';

if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Tournament</th><th>Bracket</th><th>Round</th><th>Player 1</th><th>Player 2</th><th>Status</th><th>Winner</th><th>Scheduled</th><th></th></tr>';
foreach ($rows as $m) {
    $p1 = $m['player1_id'] ? find_user_by_id($m['player1_id']) : null;
    $p2 = $m['player2_id'] ? find_user_by_id($m['player2_id']) : null;
    $w = $m['winner_id'] ? find_user_by_id($m['winner_id']) : null;
    $tourney = find_user_by_id($m['tournament_id']); // This should be tournament table
    list($tRows, , ) = sb_select(tbl('tournaments'), array('id' => 'eq.' . $m['tournament_id']), 1, 0);
    $tName = $tRows ? $tRows[0]['name'] : 'Unknown';

    echo '<tr>';
    echo '<td>' . e($tName) . '</td>';
    echo '<td><b>' . e($m['bracket_position']) . '</b></td>';
    echo '<td>R' . (int)$m['round'] . ' M' . (int)$m['match_number'] . '</td>';
    echo '<td>' . ($p1 ? e($p1['username']) : 'TBD') . '</td>';
    echo '<td>' . ($p2 ? e($p2['username']) : 'TBD') . '</td>';
    echo '<td>' . badge($m['status']) . '</td>';
    echo '<td>' . ($w ? e($w['username']) : '—') . '</td>';
    echo '<td>' . ($m['scheduled_at'] ? fmt_dt($m['scheduled_at']) : ($m['started_at'] ? fmt_dt($m['started_at']) : '—')) . '</td>';
    echo '<td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($m['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($m['status'] === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Update</button></form>';
    if (in_array($m['status'], array('ready', 'in_progress', 'disputed'))) {
        echo '<form method="post" class="row" style="gap:6px;margin-top:4px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($m['id']) . '"><input type="hidden" name="action" value="result">';
        echo '<select name="winner_id"><option value="">-- Draw / No Winner --</option>';
        if ($p1) echo '<option value="' . e($p1['id']) . '">' . e($p1['username']) . '</option>';
        if ($p2) echo '<option value="' . e($p2['id']) . '">' . e($p2['username']) . '</option>';
        echo '</select><input name="reason" placeholder="Reason" value="admin" style="min-width:100px"><button class="btn sm" type="submit">Set Result</button></form>';
    }
    echo '<a class="btn ghost sm" href="/tournaments/match/?id=' . e($m['id']) . '" target="_blank">View</a>';
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="9" class="muted">No matches found.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();