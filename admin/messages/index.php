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
    if ($act === 'reply') {
        $body = clean_str(isset($_POST['reply']) ? $_POST['reply'] : '', 1500);
        $viaMail = !empty($_POST['also_email']);
        list($mrows, , ) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
        if (!$mrows || strlen($body) < 2) { flash_set('err', 'Write a reply first.'); redirect('/admin/messages/?id=' . $id); }
        $m = $mrows[0]; $sent = array();
        if (!empty($m['visitor_id'])) {
            $r = sb_insert(tbl('notify'), array(
                'visitor_id' => $m['visitor_id'], 'kind' => 'reply', 'title' => 'Reply from the Drotix team',
                'body' => $body, 'ref_table' => $table, 'ref_id' => $id,
            ));
            if (sb_ok($r)) { $sent[] = 'website notification'; } else { flash_set('err', 'Could not send the notification: ' . $r['error']); redirect('/admin/messages/?id=' . $id); }
        }
        if ($viaMail && !empty($m['email'])) {
            $from = header_safe(cfg('mail_from', 'no-reply@localhost'));
            $hdr = 'From: ' . $from . "\r\nReply-To: " . header_safe(cfg('support_email', $from)) . "\r\nContent-Type: text/plain; charset=UTF-8";
            if (@mail($m['email'], '=?UTF-8?B?' . base64_encode('Re: ' . header_safe(isset($m['topic']) && $m['topic'] !== '' ? $m['topic'] : 'Your message to Drotix')) . '?=', $body . "\n\n-- Drotix team", $hdr)) { $sent[] = 'email'; }
        }
        if (!$sent) { flash_set('err', 'This visitor has no site ID (message sent before notifications existed). Tick "also email" to reply.'); redirect('/admin/messages/?id=' . $id); }
        sb_update($table, array('id' => 'eq.' . $id), array('status' => 'replied'));
        audit_log('message_reply', $id, implode('+', $sent));
        flash_set('ok', 'Reply sent: ' . implode(' + ', $sent) . '.');
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
    $hasVid = !empty($m['visitor_id']);
    echo '<h2>Reply to visitor</h2><form method="post" class="panel">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="action" value="reply">';
    echo '<p class="muted" style="margin-top:0">' . ($hasVid ? 'The visitor will see an animated notification (bell icon) the next time their browser is open on the website, and it stays in their inbox.' : 'No site ID is stored for this message (it was sent before notifications were added), so it can only be answered by email.') . '</p>';
    echo '<textarea name="reply" maxlength="1500" required placeholder="Write your reply..."></textarea><div class="row" style="margin-top:12px"><label><input type="checkbox" name="also_email" value="1"' . ($hasVid ? '' : ' checked') . '> also send by email</label><button class="btn" type="submit">Send reply</button></div></form>';
    list($hist, , ) = sb_select(tbl('notify'), array('ref_id' => 'eq.' . $id, 'order' => 'created_at.asc'), 20, 0);
    if ($hist) {
        echo '<h2>Replies sent</h2><div class="tbl-wrap"><table><tr><th>Sent</th><th>Reply</th><th>Visitor</th></tr>';
        foreach ($hist as $h) { echo '<tr><td>' . fmt_dt($h['created_at']) . '</td><td style="white-space:pre-wrap">' . e($h['body']) . '</td><td>' . (!empty($h['read_at']) ? '<span class="badge b-teal">seen ' . fmt_dt($h['read_at']) . '</span>' : '<span class="badge b-gold">not seen yet</span>') . '</td></tr>'; }
        echo '</table></div>';
    }
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
