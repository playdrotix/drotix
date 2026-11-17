<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$table = tbl('user_reports');
$allowedStatus = array('open', 'investigating', 'resolved', 'rejected', 'escalated');
$allowedPriority = array('low', 'normal', 'high', 'urgent');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = $_POST['id'] ?? '';
    $act = $_POST['action'] ?? '';
    if (uuid_ok($id)) {
        if ($act === 'status') {
            $st = $_POST['status'] ?? '';
            $pr = $_POST['priority'] ?? '';
            $assigned = $_POST['assigned_to'] ?? '';
            if (in_array($st, $allowedStatus, true)) {
                $data = array('status' => $st, 'updated_at' => gmdate('c'));
                if (in_array($pr, $allowedPriority, true)) { $data['priority'] = $pr; }
                if ($assigned && uuid_ok($assigned)) { $data['assigned_to'] = $assigned; }
                if ($st === 'resolved') { $data['resolved_by'] = $_SESSION['admin']; $data['resolved_at'] = gmdate('c'); $data['resolution'] = clean_str($_POST['resolution'] ?? '', 2000); }
                if ($st === 'rejected') { $data['resolved_by'] = $_SESSION['admin']; $data['resolved_at'] = gmdate('c'); $data['resolution'] = clean_str($_POST['resolution'] ?? '', 2000); }
                if (!empty($_POST['admin_notes'])) { $data['admin_notes'] = clean_str($_POST['admin_notes'], 2000); }
                $r = sb_update($table, array('id' => 'eq.' . $id), $data);
                if (sb_ok($r)) { audit_log('report_status', $id, $st); flash_set('ok', 'Report status updated.'); }
                else { flash_set('err', $r['error']); }
            }
        } elseif ($act === 'reply') {
            $message = clean_str($_POST['message'] ?? '', 2000);
            if (strlen($message) > 1) {
                $r = sb_insert(tbl('report_messages'), array(
                    'report_id' => $id, 'user_id' => null, 'message' => $message, 'is_admin' => true,
                ));
                if (sb_ok($r)) {
                    // Notify reporter
                    list($rep, , ) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
                    if ($rep && !empty($rep[0]['reporter_id'])) {
                        create_user_notification($rep[0]['reporter_id'], 'report_reply',
                            'Update on your report: ' . $rep[0]['subject'],
                            'Admin replied: ' . $message,
                            array('report_id' => $id),
                            '/reports/?id=' . $id
                        );
                    }
                    flash_set('ok', 'Reply sent and user notified.');
                } else { flash_set('err', $r['error']); }
            }
        }
    }
    redirect('/admin/reports/' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$q = pg_safe($_GET['q'] ?? '');
$st = $_GET['status'] ?? '';
$pr = $_GET['priority'] ?? '';
$type = $_GET['type'] ?? '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowedStatus, true)) { $query['status'] = 'eq.' . $st; }
if (in_array($pr, $allowedPriority, true)) { $query['priority'] = 'eq.' . $pr; }
if ($type && in_array($type, array('cheating', 'harassment', 'inappropriate_name', 'bug', 'payment', 'other'), true)) { $query['type'] = 'eq.' . $type; }
if ($q !== '') { $query['or'] = '(subject.ilike.*' . $q . '*,description.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('reports.csv', sb_all($table, $query)); }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

// Get admins for assignment
list($admins, , ) = sb_select(tbl('users'), array('status' => 'eq.active'), 100, 0);
// Filter to actual admins (simplified)
$adminUsers = array();

admin_head('User Reports', 'reports');
echo '<div class="cards">';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.open')) ? ' hot' : '') . '"><div class="n">' . e(sb_count($table, array('status' => 'eq.open')) ?? '?') . '</div><div class="l">Open</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.investigating')) ?? '?') . '</div><div class="l">Investigating</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.resolved')) ?? '?') . '</div><div class="l">Resolved</div></div>';
echo '<div class="card' . (sb_count($table, array('priority' => 'eq.urgent')) ? ' hot' : '') . '"><div class="n">' . e(sb_count($table, array('priority' => 'eq.urgent')) ?? '?') . '</div><div class="l">Urgent</div></div>';
echo '</div>';

echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search subject, description"><select name="status"><option value="">All statuses</option>';
foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($st === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
echo '</select><select name="priority"><option value="">All priorities</option>';
foreach ($allowedPriority as $p) { echo '<option value="' . e($p) . '"' . ($pr === $p ? ' selected' : '') . '>' . e(ucfirst($p)) . '</option>'; }
echo '</select><select name="type"><option value="">All types</option>';
foreach (array('cheating', 'harassment', 'inappropriate_name', 'bug', 'payment', 'other') as $t) { echo '<option value="' . e($t) . '"' . ($type === $t ? ' selected' : '') . '>' . e(ucfirst(str_replace('_', ' ', $t))) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';

if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Ticket</th><th>Type</th><th>Subject</th><th>Reporter</th><th>Reported</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Created</th><th></th></tr>';
foreach ($rows as $rep) {
    $reporter = find_user_by_id($rep['reporter_id']);
    $reported = $rep['reported_user_id'] ? find_user_by_id($rep['reported_user_id']) : null;
    $assigned = $rep['assigned_to'] ? find_user_by_id($rep['assigned_to']) : null;

    // Count unread admin messages
    $unreadAdmin = sb_count(tbl('report_messages'), array('report_id' => 'eq.' . $rep['id'], 'is_admin' => 'eq.false', 'read_by_admin' => 'eq.false'));
    $unreadUser = sb_count(tbl('report_messages'), array('report_id' => 'eq.' . $rep['id'], 'is_admin' => 'eq.true', 'read_by_user' => 'eq.false'));

    echo '<tr>';
    echo '<td><code>' . e(substr($rep['id'], 0, 8)) . '</code></td>';
    echo '<td>' . e(ucfirst(str_replace('_', ' ', $rep['type']))) . '</td>';
    echo '<td><a href="/admin/reports/?id=' . e($rep['id']) . '">' . e($rep['subject']) . '</a></td>';
    echo '<td>' . ($reporter ? e($reporter['username']) : 'Unknown') . '</td>';
    echo '<td>' . ($reported ? e($reported['username']) : '—') . '</td>';
    echo '<td><span class="priority ' . e($rep['priority']) . '">' . e($rep['priority']) . '</span></td>';
    echo '<td>' . badge($rep['status']) . ($unreadAdmin > 0 ? ' <span class="badge b-gold" title="User has unread replies">● ' . (int)$unreadAdmin . '</span>' : '') . ($unreadUser > 0 ? ' <span class="badge b-pink" title="Admin has unread messages">● ' . (int)$unreadUser . '</span>' : '') . '</td>';
    echo '<td>' . ($assigned ? e($assigned['username']) : '<span class="muted">Unassigned</span>') . '</td>';
    echo '<td>' . fmt_dt($rep['created_at']) . '</td>';
    echo '<td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($rep['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($rep['status'] === $s ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>'; }
    echo '</select><select name="priority">';
    foreach ($allowedPriority as $p) { echo '<option value="' . e($p) . '"' . ($rep['priority'] === $p ? ' selected' : '') . '>' . e(ucfirst($p)) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Update</button></form>';
    echo '<a class="btn ghost sm" href="/admin/reports/?id=' . e($rep['id']) . '">View</a>';
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="10" class="muted">No reports found.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);

// Detail view if ID provided
$id = $_GET['id'] ?? '';
if ($id && uuid_ok($id)) {
    list($rows, , $r) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
    if (sb_ok($r) && !empty($rows)) {
        $rep = $rows[0];
        $reporter = find_user_by_id($rep['reporter_id']);
        $reported = $rep['reported_user_id'] ? find_user_by_id($rep['reported_user_id']) : null;
        $assigned = $rep['assigned_to'] ? find_user_by_id($rep['assigned_to']) : null;

        // Mark admin messages as read
        sb_update(tbl('report_messages'), array('report_id' => 'eq.' . $id, 'is_admin' => 'eq.true', 'read_by_admin' => 'eq.false'), array('read_by_admin' => true));

        list($messages, , ) = sb_select(tbl('report_messages'), array('report_id' => 'eq.' . $id, 'order' => 'created_at.asc'), 100, 0);

        echo '<h2>Ticket Details</h2><div class="panel"><dl class="kv">';
        echo '<dt>Ticket ID</dt><dd>' . e($rep['id']) . '</dd>';
        echo '<dt>Type</dt><dd>' . e(ucfirst(str_replace('_', ' ', $rep['type']))) . '</dd>';
        echo '<dt>Priority</dt><dd><span class="priority ' . e($rep['priority']) . '">' . e($rep['priority']) . '</span></dd>';
        echo '<dt>Status</dt><dd>' . badge($rep['status']) . '</dd>';
        echo '<dt>Reporter</dt><dd>' . ($reporter ? '<a href="/admin/users/?q=' . urlencode($reporter['username']) . '">' . e($reporter['username']) . '</a> (' . e($reporter['email']) . ')' : 'Unknown') . '</dd>';
        echo '<dt>Reported User</dt><dd>' . ($reported ? '<a href="/admin/users/?q=' . urlencode($reported['username']) . '">' . e($reported['username']) . '</a>' : '—') . '</dd>';
        echo '<dt>Assigned To</dt><dd>' . ($assigned ? e($assigned['username']) : 'Unassigned') . '</dd>';
        echo '<dt>Created</dt><dd>' . fmt_dt($rep['created_at']) . '</dd>';
        if ($rep['resolved_at']) { echo '<dt>Resolved</dt><dd>' . fmt_dt($rep['resolved_at']) . '</dd>'; }
        echo '</dl></div>';

        echo '<h2>Description</h2><div class="panel"><div class="msg">' . e($rep['description']) . '</div>';
        if ($rep['evidence']) { echo '<h3>Evidence</h3><div class="msg" style="word-break:break-all">' . e($rep['evidence']) . '</div>'; }
        echo '</div>';

        echo '<h2>Conversation</h2><div class="panel" style="max-height:500px;overflow-y:auto">';
        foreach ($messages as $msg) {
            $sender = $msg['user_id'] ? find_user_by_id($msg['user_id']) : null;
            $isAdmin = $msg['is_admin'];
            echo '<div style="padding:16px;border-bottom:1px solid var(--border);' . ($isAdmin ? 'background:rgba(237,27,118,.05)' : '') . '">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">';
            echo '<span style="font-weight:800;color:' . ($isAdmin ? 'var(--mageta)' : 'var(--text)') . '">' . ($isAdmin ? 'Admin' . ($sender ? ' (' . e($sender['username']) . ')' : '') : 'User (' . e($reporter['username']) . ')') . '</span>';
            echo '<span class="muted" style="font-size:12px">' . fmt_dt($msg['created_at']) . '</span>';
            if ($isAdmin && !$msg['read_by_user']) { echo '<span class="badge b-gold" style="font-size:10px">Unread by user</span>'; }
            if (!$isAdmin && !$msg['read_by_admin']) { echo '<span class="badge b-pink" style="font-size:10px">Unread by admin</span>'; }
            echo '</div>';
            echo '<div style="white-space:pre-wrap;color:var(--text)">' . e($msg['message']) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // Admin reply form
        echo '<h2>Admin Reply</h2><form method="post" class="panel">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="reply">';
        echo '<textarea name="message" required maxlength="2000" placeholder="Reply to user..."></textarea>';
        echo '<div class="row"><button class="btn" type="submit">Send Reply</button></div></form>';
    }
}
admin_foot();