<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$table = tbl('tournaments');
$allowedStatus = array('draft', 'registration_open', 'registration_closed', 'in_progress', 'completed', 'cancelled');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = $_POST['id'] ?? '';
    $act = $_POST['action'] ?? '';
    if (uuid_ok($id)) {
        if ($act === 'status') {
            $st = $_POST['status'] ?? '';
            if (in_array($st, $allowedStatus, true)) {
                $r = sb_update($table, array('id' => 'eq.' . $id), array('status' => $st, 'updated_at' => gmdate('c')));
                if (sb_ok($r)) { audit_log('tournament_status', $id, $st); flash_set('ok', 'Tournament status updated.'); }
                else { flash_set('err', $r['error']); }
            }
        } elseif ($act === 'delete') {
            $r = sb_delete($table, array('id' => 'eq.' . $id));
            if (sb_ok($r)) { audit_log('tournament_delete', $id); flash_set('ok', 'Tournament deleted.'); }
            else { flash_set('err', $r['error']); }
        } elseif ($act === 'create') {
            // Create new tournament
            $data = array(
                'name' => clean_str($_POST['name'] ?? '', 120),
                'slug' => clean_str($_POST['slug'] ?? '', 120),
                'description' => clean_str($_POST['description'] ?? '', 2000),
                'status' => 'draft',
                'format' => $_POST['format'] ?? 'single_elimination',
                'max_players' => (int) ($_POST['max_players'] ?? 32),
                'min_players' => (int) ($_POST['min_players'] ?? 8),
                'entry_fee' => (int) ($_POST['entry_fee'] ?? 0),
                'prize_pool' => (int) ($_POST['prize_pool'] ?? 0),
                'board_size' => (int) ($_POST['board_size'] ?? 3),
                'win_condition' => (int) ($_POST['win_condition'] ?? 3),
                'turn_time_seconds' => (int) ($_POST['turn_time'] ?? 20),
                'registration_starts_at' => $_POST['reg_start'] ? gmdate('c', strtotime($_POST['reg_start'])) : null,
                'registration_ends_at' => $_POST['reg_end'] ? gmdate('c', strtotime($_POST['reg_end'])) : null,
                'starts_at' => $_POST['starts_at'] ? gmdate('c', strtotime($_POST['starts_at'])) : null,
                'created_by' => isset($_SESSION['admin']) ? $_SESSION['admin'] : null,
            );
            $r = sb_insert($table, $data, array(), 'return=representation');
            if (sb_ok($r) && !empty($r['data'])) { audit_log('tournament_create', $r['data'][0]['id']); flash_set('ok', 'Tournament created.'); }
            else { flash_set('err', 'Failed to create tournament: ' . $r['error']); }
        } elseif ($act === 'generate_bracket') {
            // Generate bracket for tournament
            // This would be a more complex operation - simplified here
            flash_set('ok', 'Bracket generation started (implement full logic).');
        }
    }
    redirect('/admin/tournaments/' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$q = pg_safe($_GET['q'] ?? '');
$st = $_GET['status'] ?? '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowedStatus, true)) { $query['status'] = 'eq.' . $st; }
if ($q !== '') { $query['or'] = '(name.ilike.*' . $q . '*,slug.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('tournaments.csv', sb_all($table, $query)); }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Tournaments', 'tournaments');
echo '<div class="cards">';
echo '<div class="card"><div class="n">' . e(sb_count($table) ?? '?') . '</div><div class="l">Total Tournaments</div></div>';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.registration_open')) ? ' hot' : '') . '"><div class="n">' . e(sb_count($table, array('status' => 'eq.registration_open')) ?? '?') . '</div><div class="l">Open for Registration</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.in_progress')) ?? '?') . '</div><div class="l">In Progress</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.completed')) ?? '?') . '</div><div class="l">Completed</div></div>';
echo '</div>';

echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search name, slug"><select name="status"><option value="">All statuses</option>';
foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($st === $s ? ' selected' : '') . '>' . e(ucfirst(str_replace('_', ' ', $s))) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';

if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Tournament</th><th>Slug</th><th>Status</th><th>Format</th><th>Players</th><th>Entry/Prize</th><th>Dates</th><th></th></tr>';
foreach ($rows as $t) {
    echo '<tr>';
    echo '<td><b>' . e($t['name']) . '</b><div class="muted">#' . e(substr($t['id'], 0, 8)) . '</div></td>';
    echo '<td><code>' . e($t['slug']) . '</code></td>';
    echo '<td>' . badge($t['status']) . '</td>';
    echo '<td>' . e(ucfirst(str_replace('_', ' ', $t['format']))) . '</td>';
    echo '<td>';
    $pc = sb_count(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $t['id'], 'status' => 'in.("registered","confirmed","checked_in")'));
    echo e($pc ?? 0) . ' / ' . e((int)$t['max_players']);
    echo '</td>';
    echo '<td>' . e((int)$t['entry_fee']) . ' 🪙 / ' . e((int)$t['prize_pool']) . ' 🪙</td>';
    echo '<td>';
    echo $t['registration_starts_at'] ? 'Reg: ' . date('d M', strtotime($t['registration_starts_at'])) : 'Reg: Open';
    echo $t['registration_ends_at'] ? ' - ' . date('d M', strtotime($t['registration_ends_at'])) : '';
    echo '<br>';
    echo $t['starts_at'] ? 'Start: ' . date('d M Y H:i', strtotime($t['starts_at'])) : 'Start: TBA';
    echo '</td>';
    echo '<td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($t['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($t['status'] === $s ? ' selected' : '') . '>' . e(ucfirst(str_replace('_', ' ', $s))) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Update</button></form>';
    echo '<a class="btn ghost sm" href="/tournaments/?slug=' . e($t['slug']) . '" target="_blank">View</a>';
    echo '<form method="post" data-confirm="Delete tournament and all data?">' . csrf_field() . '<input type="hidden" name="id" value="' . e($t['id']) . '"><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit">Delete</button></form>';
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="8" class="muted">No tournaments yet.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);

// Create tournament form
echo '<h2>Create New Tournament</h2><form method="post" class="panel">' . csrf_field() . '<input type="hidden" name="action" value="create">';
echo '<div class="row"><div class="form-field" style="flex:1"><label>Name</label><input name="name" required maxlength="120"></div><div class="form-field" style="flex:1"><label>Slug (URL)</label><input name="slug" required maxlength="120" placeholder="drotix-cup-season-1"></div></div>';
echo '<div class="form-field"><label>Description</label><textarea name="description" rows="3" maxlength="2000"></textarea></div>';
echo '<div class="row">';
echo '<div class="form-field"><label>Format</label><select name="format"><option value="single_elimination">Single Elimination</option><option value="double_elimination">Double Elimination</option><option value="round_robin">Round Robin</option><option value="swiss">Swiss</option></select></div>';
echo '<div class="form-field"><label>Max Players</label><input name="max_players" type="number" value="32" min="4" max="256"></div>';
echo '<div class="form-field"><label>Min Players</label><input name="min_players" type="number" value="8" min="2" max="256"></div>';
echo '<div class="form-field"><label>Entry Fee (coins)</label><input name="entry_fee" type="number" value="0" min="0"></div>';
echo '<div class="form-field"><label>Prize Pool (coins)</label><input name="prize_pool" type="number" value="0" min="0"></div>';
echo '</div>';
echo '<div class="row">';
echo '<div class="form-field"><label>Board Size</label><select name="board_size"><option value="3">3×3</option><option value="4">4×4</option><option value="5">5×5</option></select></div>';
echo '<div class="form-field"><label>Win Condition</label><input name="win_condition" type="number" value="3" min="3" max="5"></div>';
echo '<div class="form-field"><label>Turn Time (seconds)</label><input name="turn_time" type="number" value="20" min="10" max="120"></div>';
echo '</div>';
echo '<div class="row">';
echo '<div class="form-field"><label>Registration Starts</label><input name="reg_start" type="datetime-local"></div>';
echo '<div class="form-field"><label>Registration Ends</label><input name="reg_end" type="datetime-local"></div>';
echo '<div class="form-field"><label>Tournament Starts</label><input name="starts_at" type="datetime-local"></div>';
echo '</div>';
echo '<div class="row"><button class="btn" type="submit">Create Tournament</button></div></form>';
admin_foot();