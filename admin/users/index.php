<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$table = tbl('users');
$allowedStatus = array('active', 'suspended', 'banned', 'pending_verification');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = $_POST['id'] ?? '';
    $act = $_POST['action'] ?? '';
    if (uuid_ok($id)) {
        if ($act === 'status') {
            $st = $_POST['status'] ?? '';
            if (in_array($st, $allowedStatus, true)) {
                $data = array('status' => $st);
                if ($st === 'suspended') {
                    $data['suspended_at'] = gmdate('c');
                    $data['suspended_until'] = $_POST['suspended_until'] ? gmdate('c', strtotime($_POST['suspended_until'])) : null;
                    $data['suspension_reason'] = clean_str($_POST['suspension_reason'] ?? '', 500);
                } elseif ($st === 'active') {
                    $data['suspended_at'] = null;
                    $data['suspended_until'] = null;
                    $data['suspension_reason'] = null;
                }
                $r = sb_update($table, array('id' => 'eq.' . $id), $data);
                if (sb_ok($r)) { audit_log('user_status', $id, $st); flash_set('ok', 'User status updated.'); }
                else { flash_set('err', $r['error']); }
            }
        } elseif ($act === 'coins') {
            $amount = (int) ($_POST['amount'] ?? 0);
            if ($amount !== 0) {
                list($rows, , ) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
                if ($rows) {
                    $newCoins = max(0, ($rows[0]['coins'] ?? 0) + $amount);
                    $r = sb_update($table, array('id' => 'eq.' . $id), array('coins' => $newCoins));
                    if (sb_ok($r)) { audit_log('user_coins', $id, ($amount > 0 ? '+' : '') . $amount); flash_set('ok', 'Coins updated.'); }
                    else { flash_set('err', $r['error']); }
                }
            }
        } elseif ($act === 'ban') {
            $r = sb_update($table, array('id' => 'eq.' . $id), array('status' => 'banned', 'suspended_at' => gmdate('c')));
            if (sb_ok($r)) { audit_log('user_ban', $id); flash_set('ok', 'User banned.'); }
            else { flash_set('err', $r['error']); }
        }
    }
    redirect('/admin/users/' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$q = pg_safe($_GET['q'] ?? '');
$st = $_GET['status'] ?? '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowedStatus, true)) { $query['status'] = 'eq.' . $st; }
if ($q !== '') { $query['or'] = '(username.ilike.*' . $q . '*,email.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('users.csv', sb_all($table, $query)); }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Users', 'users');
$n = function ($v) { return $v === null ? '?' : (string) $v; };
echo '<div class="cards"><div class="card"><div class="n">' . e($n(sb_count($table))) . '</div><div class="l">Total Users</div></div>';
echo '<div class="card"><div class="n">' . e($n(sb_count($table, array('status' => 'eq.active')))) . '</div><div class="l">Active</div></div>';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.suspended')) ? ' hot' : '') . '"><div class="n">' . e($n(sb_count($table, array('status' => 'eq.suspended')))) . '</div><div class="l">Suspended</div></div>';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.banned')) ? ' hot' : '') . '"><div class="n">' . e($n(sb_count($table, array('status' => 'eq.banned')))) . '</div><div class="l">Banned</div></div></div>';

echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search username, email"><select name="status"><option value="">All statuses</option>';
foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($st === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';

if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>User</th><th>Email</th><th>Provider</th><th>Coins</th><th>Rating</th><th>W/L/D</th><th>Status</th><th>Joined</th><th>Last Login</th><th></th></tr>';
foreach ($rows as $u) {
    echo '<tr>';
    echo '<td><b>' . e($u['username']) . '</b><div class="muted">#' . e(substr($u['id'], 0, 8)) . '</div></td>';
    echo '<td><a href="mailto:' . e($u['email']) . '">' . e($u['email']) . '</a></td>';
    echo '<td>' . e(ucfirst($u['provider'])) . ($u['provider_id'] ? ' <span class="muted">(' . e(substr($u['provider_id'], 0, 12)) . ')</span>' : '') . '</td>';
    echo '<td><b>' . e($n($u['coins'])) . '</b> 🪙</td>';
    echo '<td>' . e($n($u['rating'])) . '</td>';
    echo '<td>' . e($n($u['total_wins'])) . ' / ' . e($n($u['total_losses'])) . ' / ' . e($n($u['total_draws'])) . '</td>';
    echo '<td>' . badge($u['status']) . '</td>';
    echo '<td>' . fmt_dt($u['created_at']) . '</td>';
    echo '<td>' . fmt_dt($u['last_login_at']) . '</td>';
    echo '<td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($u['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($u['status'] === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
    echo '</select>';
    if ($u['status'] === 'suspended' || $u['status'] === 'active') {
        echo '<input name="suspended_until" type="datetime-local" placeholder="Until (for suspended)" style="min-width:170px">';
        echo '<input name="suspension_reason" placeholder="Reason" style="min-width:170px">';
    }
    echo '<button class="btn sm" type="submit">Update</button></form>';
    echo '<form method="post" class="row" style="gap:6px;margin-top:4px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($u['id']) . '"><input type="hidden" name="action" value="coins"><input name="amount" type="number" placeholder="± Coins" style="width:80px"><button class="btn sm" type="submit">Adjust</button></form>';
    if ($u['status'] !== 'banned') {
        echo '<form method="post" data-confirm="Ban this user permanently?">' . csrf_field() . '<input type="hidden" name="id" value="' . e($u['id']) . '"><input type="hidden" name="action" value="ban"><button class="btn danger sm" type="submit">Ban</button></form>';
    }
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="10" class="muted">No users found.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();