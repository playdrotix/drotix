<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();
$table = tbl('deletions');
$statuses = array('pending', 'verifying', 'completed', 'rejected');
$days = max(1, (int) cfg('deletion_days', 2));

/* optional: delete the player from your app table */
$ad = cfg('app_delete', array());
$adOn = is_array($ad) && !empty($ad['enabled']) && !empty($ad['table']) && !empty($ad['email_column'])
    && sb_valid_table($ad['table']) && sb_valid_table($ad['email_column']);
$adSrc = $adOn ? array('url' => isset($ad['url']) ? $ad['url'] : null, 'key' => isset($ad['key']) ? $ad['key'] : null) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if (!uuid_ok($id)) { flash_set('err', 'Bad id.'); redirect('/admin/deletions/'); }
    list($rows, , $r0) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
    if (!$rows) { flash_set('err', 'Request not found.'); redirect('/admin/deletions/'); }
    $req = $rows[0];

    if ($act === 'update') {
        $st = isset($_POST['status']) ? (string) $_POST['status'] : 'pending';
        if (!in_array($st, $statuses, true)) { $st = 'pending'; }
        $note = clean_str(isset($_POST['admin_note']) ? $_POST['admin_note'] : '', 2000);
        $patch = array('status' => $st, 'admin_note' => $note,
                       'completed_at' => ($st === 'completed') ? (!empty($req['completed_at']) ? $req['completed_at'] : gmdate('c')) : null);
        $r = sb_update($table, array('id' => 'eq.' . $id), $patch);
        if (sb_ok($r)) { audit_log('deletion_update', $id, $st); flash_set('ok', 'Saved.'); } else { flash_set('err', 'Could not save: ' . $r['error']); }
        redirect('/admin/deletions/?id=' . $id);
    }
    if ($act === 'app_delete' && $adOn) {
        $typed = strtolower(trim(isset($_POST['confirm_email']) ? (string) $_POST['confirm_email'] : ''));
        if ($typed === '' || $typed !== strtolower($req['email'])) {
            flash_set('err', 'The email you typed does not match. Nothing was deleted.');
            redirect('/admin/deletions/?id=' . $id);
        }
        $r = sb_request('DELETE', $ad['table'], array($ad['email_column'] => 'eq.' . $req['email']), null,
                        array('Prefer' => 'return=representation'), $adSrc);
        if (!sb_ok($r)) { flash_set('err', 'App delete failed: ' . $r['error']); redirect('/admin/deletions/?id=' . $id); }
        $n = is_array($r['data']) ? count($r['data']) : 0;
        $note = trim((string) $req['admin_note'] . "\nApp data deleted: " . $n . ' row(s) on ' . gmdate('Y-m-d H:i') . ' UTC by ' . $_SESSION['admin']);
        sb_update($table, array('id' => 'eq.' . $id), array('status' => 'completed', 'completed_at' => gmdate('c'), 'admin_note' => $note));
        audit_log('app_data_deleted', $id, $n . ' row(s) in ' . $ad['table']);
        flash_set('ok', 'Deleted ' . $n . ' row(s) from ' . $ad['table'] . ' and marked the request completed.');
        redirect('/admin/deletions/?id=' . $id);
    }
    redirect('/admin/deletions/');
}

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id !== '' && uuid_ok($id)) {
    list($rows, , $r) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
    admin_head('Deletion request', 'del');
    if (!$rows) { echo '<div class="flash err">Not found. ' . e($r['error']) . '</div><a class="btn ghost" href="/admin/deletions/">Back</a>'; admin_foot(); exit; }
    $d = $rows[0];
    echo '<div class="panel"><dl class="kv"><dt>Requested</dt><dd>' . fmt_dt($d['created_at']) . '</dd><dt>Deadline</dt><dd>' . fmt_dt($d['due_at']) . ' ' . due_label($d['due_at'], $d['status']) . '</dd><dt>Username</dt><dd>' . e($d['username']) . '</dd><dt>Email</dt><dd><a href="mailto:' . e($d['email']) . '?subject=' . rawurlencode('Your Drotix Tic Tac Toe account deletion request') . '">' . e($d['email']) . '</a></dd><dt>Status</dt><dd>' . badge($d['status']) . '</dd><dt>Completed</dt><dd>' . fmt_dt(isset($d['completed_at']) ? $d['completed_at'] : '') . '</dd><dt>Player notes</dt><dd>' . e(isset($d['notes']) ? $d['notes'] : '') . '</dd><dt>IP</dt><dd>' . e(isset($d['ip']) ? $d['ip'] : '') . '</dd></dl></div>';
    echo '<div class="flash ok">Before you delete anything, make sure the request really comes from the account owner (for example, ask them to reply from the account email, then set the status to "verifying").</div>';
    echo '<form method="post" class="panel">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="update"><div class="row"><select name="status">';
    foreach ($statuses as $s) { echo '<option' . ($d['status'] === $s ? ' selected' : '') . '>' . e($s) . '</option>'; }
    echo '</select></div><textarea name="admin_note" placeholder="Private note">' . e(isset($d['admin_note']) ? $d['admin_note'] : '') . '</textarea><div class="row" style="margin-top:12px"><button class="btn" type="submit">Save</button><a class="btn ghost" href="/admin/deletions/">Back</a></div></form>';

    echo '<h2>Player data in your app</h2>';
    if (!$adOn) {
        echo '<div class="panel muted">Not connected. To delete from here, set <code>app_delete</code> to enabled in includes/config.php (table and email column). Until then, delete the player in your app, then set the status to "completed".</div>';
    } else {
        list($found, , $rf) = sb_select($ad['table'], array($ad['email_column'] => 'eq.' . $d['email']), 5, 0, $adSrc);
        if (!sb_ok($rf)) { echo '<div class="flash err">Could not read ' . e($ad['table']) . ': ' . e($rf['error']) . '</div>'; }
        elseif (!$found) { echo '<div class="panel muted">No player in <b>' . e($ad['table']) . '</b> has this email.</div>'; }
        else {
            echo '<div class="panel"><div class="muted">Rows in <b>' . e($ad['table']) . '</b> with this email (showing up to 5). Check them before deleting:</div>';
            foreach ($found as $row) { echo '<details open><summary>Row</summary><pre>' . e(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>'; }
            echo '<form method="post" class="row" data-confirm="Permanently delete these player rows? This cannot be undone.">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="app_delete"><input name="confirm_email" placeholder="Type the email to confirm" size="30" autocomplete="off"><button class="btn danger" type="submit">Delete from app data</button></form></div>';
        }
    }
    admin_foot(); exit;
}

$status = isset($_GET['status']) ? (string) $_GET['status'] : 'open';
$query = array();
if ($status === 'open') { $query['status'] = 'in.(pending,verifying)'; $query['order'] = 'due_at.asc'; }
elseif (in_array($status, $statuses, true)) { $query['status'] = 'eq.' . $status; $query['order'] = 'created_at.desc'; }
else { $query['order'] = 'created_at.desc'; }
if (isset($_GET['export'])) { csv_out('deletion_requests.csv', sb_all($table, $query)); }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 25;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Deletion requests', 'del');
echo '<p class="muted">Promise to players: account deleted within ' . $days . ' days. Open requests are sorted by deadline.</p>';
echo '<form class="row" method="get"><select name="status">';
foreach (array('open' => 'Open (pending + verifying)', 'all' => 'All') as $k => $l) { echo '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . e($l) . '</option>'; }
foreach ($statuses as $s) { echo '<option' . ($status === $s ? ' selected' : '') . '>' . e($s) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';
if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Requested</th><th>Player</th><th>Status</th><th>Deadline</th></tr>';
foreach ($rows as $d) {
    echo '<tr><td>' . fmt_dt($d['created_at']) . '</td><td><a href="/admin/deletions/?id=' . e($d['id']) . '">' . e($d['username']) . '</a><div class="muted">' . e($d['email']) . '</div></td><td>' . badge($d['status']) . '</td><td>' . due_label($d['due_at'], $d['status']) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="4" class="muted">Nothing here.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
