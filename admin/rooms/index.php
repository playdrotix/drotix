<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$table = tbl('private_rooms');
$allowedStatus = array('waiting', 'in_progress', 'completed', 'abandoned');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = $_POST['id'] ?? '';
    $act = $_POST['action'] ?? '';
    if (uuid_ok($id)) {
        if ($act === 'status') {
            $st = $_POST['status'] ?? '';
            if (in_array($st, $allowedStatus, true)) {
                $r = sb_update($table, array('id' => 'eq.' . $id), array('status' => $st, 'updated_at' => gmdate('c')));
                if (sb_ok($r)) { audit_log('room_status', $id, $st); flash_set('ok', 'Room status updated.'); }
                else { flash_set('err', $r['error']); }
            }
        } elseif ($act === 'delete') {
            $r = sb_delete($table, array('id' => 'eq.' . $id));
            if (sb_ok($r)) { audit_log('room_delete', $id); flash_set('ok', 'Room deleted.'); }
            else { flash_set('err', $r['error']); }
        }
    }
    redirect('/admin/rooms/' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$q = pg_safe($_GET['q'] ?? '');
$st = $_GET['status'] ?? '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowedStatus, true)) { $query['status'] = 'eq.' . $st; }
if ($q !== '') { $query['or'] = '(room_code.ilike.*' . $q . '*,slug.ilike.*' . $q . '*,name.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('rooms.csv', sb_all($table, $query)); }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Private Rooms', 'rooms');
echo '<div class="cards">';
echo '<div class="card"><div class="n">' . e(sb_count($table) ?? '?') . '</div><div class="l">Total Rooms</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.waiting')) ?? '?') . '</div><div class="l">Waiting</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.in_progress')) ?? '?') . '</div><div class="l">In Progress</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.completed')) ?? '?') . '</div><div class="l">Completed</div></div>';
echo '</div>';

echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search code, slug, name"><select name="status"><option value="">All statuses</option>';
foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($st === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';

if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Room</th><th>Code</th><th>Host</th><th>Status</th><th>Board</th><th>Players</th><th>Created</th><th></th></tr>';
foreach ($rows as $rm) {
    $host = find_user_by_id($rm['host_id']);
    $p1 = $rm['player1_id'] ? find_user_by_id($rm['player1_id']) : null;
    $p2 = $rm['player2_id'] ? find_user_by_id($rm['player2_id']) : null;

    echo '<tr>';
    echo '<td><b>' . e($rm['name'] ?? 'Unnamed') . '</b><div class="muted">#' . e(substr($rm['id'], 0, 8)) . '</div></td>';
    echo '<td><code>' . e($rm['room_code']) . '</code><div class="muted"><a href="/rooms/?code=' . e($rm['room_code']) . '" target="_blank">/' . e($rm['slug']) . '</a></div></td>';
    echo '<td>' . ($host ? e($host['username']) : 'Unknown') . '</td>';
    echo '<td>' . badge($rm['status']) . '</td>';
    echo '<td>' . (int)$rm['board_size'] . '×' . (int)$rm['board_size'] . ' (win: ' . (int)$rm['win_condition'] . ')' . ($rm['is_ranked'] ? ' <span class="badge b-gold">Ranked</span>' : '') . '</td>';
    echo '<td>' . ($p1 ? e($p1['username']) : 'TBD') . ' vs ' . ($p2 ? e($p2['username']) : 'TBD') . '</td>';
    echo '<td>' . fmt_dt($rm['created_at']) . '</td>';
    echo '<td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($rm['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($rm['status'] === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Update</button></form>';
    echo '<a class="btn ghost sm" href="/rooms/?code=' . e($rm['room_code']) . '" target="_blank">View</a>';
    echo '<form method="post" data-confirm="Delete this room?">' . csrf_field() . '<input type="hidden" name="id" value="' . e($rm['id']) . '"><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit">Delete</button></form>';
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="8" class="muted">No rooms found.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();