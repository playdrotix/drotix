<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();
$table = tbl('waitlist');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    if (uuid_ok($id) && isset($_POST['action']) && $_POST['action'] === 'delete') {
        $r = sb_delete($table, array('id' => 'eq.' . $id));
        if (sb_ok($r)) { audit_log('waitlist_delete', $id); flash_set('ok', 'Removed from the waitlist.'); } else { flash_set('err', $r['error']); }
    }
    redirect('/admin/waitlist/');
}
$q = pg_safe(isset($_GET['q']) ? $_GET['q'] : '');
$query = array('order' => 'created_at.desc');
if ($q !== '') { $query['email'] = 'ilike.*' . $q . '*'; }
if (isset($_GET['export'])) { csv_out('waitlist.csv', sb_all($table, $query)); }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Waitlist', 'wait');
echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search email"><button class="btn sm" type="submit">Search</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';
if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Joined</th><th>Email</th><th>Source</th><th></th></tr>';
foreach ($rows as $w) {
    echo '<tr><td>' . fmt_dt($w['created_at']) . '</td><td>' . e($w['email']) . '</td><td>' . e(isset($w['source']) ? $w['source'] : '') . '</td><td><form method="post" data-confirm="Remove this email?">' . csrf_field() . '<input type="hidden" name="id" value="' . e($w['id']) . '"><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit">Remove</button></form></td></tr>';
}
if (!$rows) { echo '<tr><td colspan="4" class="muted">No signups yet.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
