<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();
$table = tbl('contact');
$statuses = array('new', 'read', 'replied', 'archived');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if (!uuid_ok($id)) { flash_set('err', 'Bad id.'); redirect('/admin/messages/'); }
    if ($act === 'update') {
        $st = isset($_POST['status']) ? (string) $_POST['status'] : 'read';
        if (!in_array($st, $statuses, true)) { $st = 'read'; }
        $note = clean_str(isset($_POST['admin_note']) ? $_POST['admin_note'] : '', 2000);
        $r = sb_update($table, array('id' => 'eq.' . $id), array('status' => $st, 'admin_note' => $note));
        if (sb_ok($r)) { audit_log('message_update', $id, $st); flash_set('ok', 'Saved.'); } else { flash_set('err', 'Could not save: ' . $r['error']); }
        redirect('/admin/messages/?id=' . $id);
    }
    if ($act === 'delete') {
        $r = sb_delete($table, array('id' => 'eq.' . $id));
        if (sb_ok($r)) { audit_log('message_delete', $id); flash_set('ok', 'Message deleted.'); } else { flash_set('err', 'Could not delete: ' . $r['error']); }
        redirect('/admin/messages/');
    }
    redirect('/admin/messages/');
}

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id !== '' && uuid_ok($id)) {
    list($rows, , $r) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
    admin_head('Message', 'msg');
    if (!$rows) { echo '<div class="flash err">Message not found. ' . e($r['error']) . '</div><a class="btn ghost" href="/admin/messages/">Back</a>'; admin_foot(); exit; }
    $m = $rows[0];
    if ($m['status'] === 'new') { sb_update($table, array('id' => 'eq.' . $id), array('status' => 'read')); $m['status'] = 'read'; }
    echo '<div class="panel"><dl class="kv"><dt>Received</dt><dd>' . fmt_dt($m['created_at']) . '</dd><dt>Name</dt><dd>' . e($m['name']) . '</dd><dt>Email</dt><dd><a href="mailto:' . e($m['email']) . '?subject=' . rawurlencode('Re: Drotix Tic Tac Toe') . '">' . e($m['email']) . '</a></dd><dt>Topic</dt><dd>' . e(isset($m['topic']) ? $m['topic'] : '') . '</dd><dt>Status</dt><dd>' . badge($m['status']) . '</dd><dt>IP</dt><dd>' . e(isset($m['ip']) ? $m['ip'] : '') . '</dd></dl></div>';
    echo '<h2>Message</h2><div class="msg">' . e($m['message']) . '</div>';
    echo '<h2>Update</h2><form method="post" class="panel">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="update">';
    echo '<div class="row"><select name="status">';
    foreach ($statuses as $s) { echo '<option' . ($m['status'] === $s ? ' selected' : '') . '>' . e($s) . '</option>'; }
    echo '</select></div><textarea name="admin_note" placeholder="Private note">' . e(isset($m['admin_note']) ? $m['admin_note'] : '') . '</textarea><div class="row" style="margin-top:12px"><button class="btn" type="submit">Save</button><a class="btn ghost" href="/admin/messages/">Back</a></div></form>';
    echo '<form method="post" data-confirm="Delete this message for good?">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit">Delete message</button></form>';
    admin_foot(); exit;
}

$status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$q = pg_safe(isset($_GET['q']) ? $_GET['q'] : '');
$query = array('order' => 'created_at.desc');
if (in_array($status, $statuses, true)) { $query['status'] = 'eq.' . $status; }
if ($q !== '') { $query['or'] = '(name.ilike.*' . $q . '*,email.ilike.*' . $q . '*,message.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('contact_messages.csv', sb_all($table, $query)); }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 25;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Messages', 'msg');
echo '<form class="row" method="get"><select name="status"><option value="">All statuses</option>';
foreach ($statuses as $s) { echo '<option' . ($status === $s ? ' selected' : '') . '>' . e($s) . '</option>'; }
echo '</select><input name="q" value="' . e($q) . '" placeholder="Search name, email, message"><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';
if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>From</th><th>Topic</th><th>Message</th><th>Status</th></tr>';
foreach ($rows as $m) {
    echo '<tr><td>' . fmt_dt($m['created_at']) . '</td><td><a href="/admin/messages/?id=' . e($m['id']) . '">' . e($m['name']) . '</a><div class="muted">' . e($m['email']) . '</div></td><td>' . e(isset($m['topic']) ? $m['topic'] : '') . '</td><td class="clip">' . e($m['message']) . '</td><td>' . badge($m['status']) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="5" class="muted">Nothing here.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
