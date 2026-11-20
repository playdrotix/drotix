<?php
/* Mobile App: player reports (chat abuse, cheating ...). Approve (valid, resolved) / Reject (dismiss) / Suspend the reported player.
   Shows the reported chat messages and the screenshot. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$RT = app_has_table('reports') ? 'reports' : null;
if (!$RT) { foreach (app_tables() as $t => $i) { if (preg_match('/report/', $t) && !preg_match('/evidence|attach|log/', $t)) { $RT = $t; break; } } }
$CT = null;
foreach (app_tables() as $t => $i) { if (preg_match('/chat|message/', $t) && isset($i['cols']['message']) || preg_match('/chat/', $t) && isset($i['cols']['body'])) { $CT = $t; break; } }

function rcol($t, $re, $exclude = null) { foreach (app_user_cols($t) as $c) { if (preg_match($re, $c) && (!$exclude || !preg_match($exclude, $c))) { return $c; } } return null; }
$RC = $RT ? app_cols($RT) : array();
$cReporter = $RT ? rcol($RT, '/reporter|reported_by|created_by/') : null;
$cReported = $RT ? (rcol($RT, '/reported_(user|player|id)|^reported$|target|accused|offender|against/', '/by/') ?: rcol($RT, '/reported/', '/by/')) : null;
$S = array(
    'approve' => array('resolved', 'approved', 'actioned', 'closed'),
    'reject' => array('dismissed', 'rejected', 'invalid', 'closed'),
    'review' => array('under_review', 'reviewing', 'in_review', 'pending'),
);
function report_status($RT, $kind, $S) { return app_pick_value($RT, 'status', $S[$kind]); }
function set_report($RT, $id, $kind, $notes, $S) {
    $st = report_status($RT, $kind, $S);
    $r = app_rpc('admin_update_report', array('p_report_id' => $id, 'p_status' => $st, 'p_admin_notes' => $notes !== '' ? $notes : null));
    if ($r['ok']) { return $r; }
    $patch = array('status' => $st); $c = app_cols($RT);
    if ($notes !== '' && isset($c['admin_notes'])) { $patch['admin_notes'] = $notes; }
    foreach (array('reviewed_at', 'resolved_at') as $k) { if (isset($c[$k])) { $patch[$k] = date('c'); } }
    $r = app_db('PATCH', $RT, array(app_pk($RT) ?: 'id' => 'eq.' . $id), $patch, 'return=minimal');
    // the game's function notifies the reporter itself; when we wrote directly, notify here
    global $cReporter;
    if ($r['ok'] && $cReporter) {
        list($rows, , ) = app_select($RT, array(app_pk($RT) ?: 'id' => 'eq.' . $id), 1, 0, false);
        $rep = $rows ? g($rows[0], $cReporter) : null;
        if ($rep) { app_notify($rep, 'Report ' . app_ref('R', $id) . ' updated', 'Your report is now: ' . str_replace('_', ' ', $st) . ($notes !== '' ? '. ' . $notes : '.')); }
    }
    return $r;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $RT) {
    csrf_verify();
    $rid = isset($_POST['report_id']) ? (string) $_POST['report_id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $notes = app_post('notes', 2000);
    $back = !empty($_POST['back']) && strpos((string) $_POST['back'], '/admin/app/reports/') === 0 ? (string) $_POST['back'] : '/admin/app/reports/';
    if ($rid === '') { flash_set('err', 'Bad report.'); redirect($back); }
    if (in_array($act, array('approve', 'reject', 'review'), true)) {
        $r = set_report($RT, $rid, $act, $notes, $S);
        if ($r['ok']) {
            list($rows0, , ) = app_select($RT, array((app_pk($RT) ?: 'id') => 'eq.' . $rid), 1, 0, false);
            if ($rows0 && $cReporter && g($rows0[0], $cReporter)) {
                $repName = ''; if ($cReported && g($rows0[0], $cReported)) { list($pp0, , ) = app_select(app_profiles_table(), array(app_profile_idcol() => 'eq.' . $rows0[0][$cReported], 'select' => 'username'), 1, 0, false); $repName = $pp0 ? (string) g($pp0[0], 'username', '') : ''; }
                $stKey = array('approve' => 'resolved', 'reject' => 'dismissed', 'review' => 'under_review')[$act];
                app_mail_report($rows0[0][$cReporter], $rid, $stKey, $repName, array('resolved' => 'Action taken', 'dismissed' => 'No action', 'under_review' => 'Being reviewed')[$stKey], $notes);
            }
        }
        if ($r['ok']) { app_log('report_' . $act, $rid, $notes); flash_set('ok', app_ref('R', $rid) . ': ' . array('approve' => 'approved (resolved).', 'reject' => 'rejected (dismissed).', 'review' => 'marked under review.')[$act] . ' The reporter is notified.'); }
        else { flash_set('err', 'Failed: ' . $r['error']); }
    } elseif ($act === 'suspend') {
        $uid = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
        $reason = app_post('reason', 500);
        if (!uuid_ok($uid) || $reason === '') { flash_set('err', 'Player and reason are required.'); redirect($back); }
        $r = app_rpc('admin_suspend_user', array('p_user_id' => $uid, 'p_reason' => $reason, 'p_report_id' => uuid_ok($rid) ? $rid : null, 'p_admin_notes' => $notes !== '' ? $notes : null));
        if (!$r['ok']) { $r = app_set_status($uid, true, $reason); if ($r['ok']) { set_report($RT, $rid, 'approve', $notes !== '' ? $notes : 'Suspended: ' . $reason, $S); } }
        else { app_auth_update($uid, array('ban_duration' => '876000h')); }
        if ($r['ok']) {
            app_log('report_suspend', $uid, array('report' => $rid, 'reason' => $reason));
            app_mail_suspended($uid, $reason, app_ref('R', $rid));
            list($rows0, , ) = app_select($RT, array((app_pk($RT) ?: 'id') => 'eq.' . $rid), 1, 0, false);
            if ($rows0 && $cReporter && g($rows0[0], $cReporter)) {
                list($pp0, , ) = app_select(app_profiles_table(), array(app_profile_idcol() => 'eq.' . $uid, 'select' => 'username'), 1, 0, false);
                app_mail_report($rows0[0][$cReporter], $rid, 'resolved', $pp0 ? (string) g($pp0[0], 'username', '') : '', 'Player suspended', 'The player you reported was suspended.');
            }
            if (!empty($_POST['tell'])) { app_notify($uid, 'Account suspended', 'Your account was suspended: ' . $reason); }
            flash_set('ok', 'Player suspended; report ' . app_ref('R', $rid) . ' resolved (the reporter sees "Player suspended").');
        } else { flash_set('err', 'Suspend failed: ' . $r['error']); }
    }
    redirect($back);
}

app_head('Reports', 'reports');
if (!$RT) { echo '<div class="flash err">No reports table found in the drotix schema.</div>'; admin_foot(); exit; }
$pk = app_pk($RT) ?: 'id';

/** Action buttons for one report. */
function report_actions($x, $pk, $cReported, $back) {
    $rid = (string) g($x, $pk, ''); $uid = $cReported ? (string) g($x, $cReported, '') : '';
    $h = csrf_field() . '<input type="hidden" name="report_id" value="' . e($rid) . '"><input type="hidden" name="back" value="' . e($back) . '">';
    $o = '<div class="actions" style="margin:0"><span class="muted hint">' . e(app_ref('R', $rid)) . ' &middot; the reporter is notified</span>';
    $o .= '<form method="post" data-confirm="Approve report ' . e(app_ref('R', $rid)) . ' (valid) and mark it resolved? The reporter is notified.">' . $h . '<input type="hidden" name="action" value="approve"><button class="btn sm" type="submit">Approve</button></form>';
    $o .= '<form method="post" data-confirm="Reject report ' . e(app_ref('R', $rid)) . ' (not valid) and dismiss it? The reporter is notified.">' . $h . '<input type="hidden" name="action" value="reject"><button class="btn ghost sm" type="submit">Reject</button></form>';
    if (uuid_ok($uid)) {
        $o .= '<details class="act"><summary class="btn danger sm">Suspend</summary><form class="pop" method="post" data-confirm="Suspend the reported player? Report ' . e(app_ref('R', $rid)) . ' is resolved automatically and the reporter sees Player suspended.">' . $h . '<input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="' . e($uid) . '">'
            . '<label>Reason (shown to the player)<input name="reason" required maxlength="500" value="' . e((string) gx($x, array('reason', 'category'), '')) . '"></label><label>Admin notes<textarea name="notes" maxlength="2000"></textarea></label>'
            . '<label class="chk"><input type="checkbox" name="tell" value="1" checked> Send the player a notification</label><button class="btn danger sm" type="submit">Suspend player</button></form></details>';
    }
    return $o . '</div>';
}
$names = array();
function nm($names, $id) { return $id ? app_user_link($id, isset($names[$id]) ? $names[$id] : substr($id, 0, 8)) : '<span class="muted">-</span>'; }
function load_names($ids) {
    $ids = array_values(array_unique(array_filter($ids, 'uuid_ok'))); if (!$ids) { return array(); }
    $pt = app_profiles_table(); $idc = app_profile_idcol(); $o = array();
    list($rows, , ) = app_select($pt, array($idc => 'in.(' . implode(',', $ids) . ')'), 300, 0, false);
    foreach ($rows as $r) { $o[$r[$idc]] = gx($r, array('username', 'display_name'), substr($r[$idc], 0, 8)); }
    return $o;
}

/* ================= one report (full view with chat) ================= */
$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id !== '') {
    list($rows, , $r) = app_select($RT, array($pk => 'eq.' . $id), 1, 0, false);
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/reports/">&larr; All reports</a><a class="btn ghost sm" href="/admin/app/data/?t=' . e(rawurlencode($RT)) . '&amp;edit=' . e(rawurlencode($id)) . '">Edit all fields</a></div>' . app_err_box($r);
    if (!$rows) { echo '<div class="flash err">Report not found.</div>'; admin_foot(); exit; }
    $x = $rows[0];
    $rep = $cReported ? g($x, $cReported) : null; $by = $cReporter ? g($x, $cReporter) : null;
    $names = load_names(array($rep, $by));
    echo '<div class="panel"><dl class="kv"><dt>Ticket</dt><dd class="ref">' . e(app_ref('R', $id)) . '</dd><dt>Reported player</dt><dd><b>' . nm($names, $rep) . '</b></dd><dt>Reported by</dt><dd>' . nm($names, $by) . '</dd>';
    foreach ($x as $k => $v) { if (in_array($k, array($cReported, $cReporter, $pk), true)) { continue; } echo '<dt>' . e(str_replace('_', ' ', $k)) . '</dt><dd>' . app_cell($k, $v, isset($RC[$k]) ? $RC[$k] : '') . '</dd>'; }
    echo '</dl><div style="margin-top:16px">' . report_actions($x, $pk, $cReported, '/admin/app/reports/?id=' . rawurlencode($id)) . '</div></div>';

    if ($CT) {
        $cc = app_cols($CT); $sender = app_owner_col($CT) ?: rcol($CT, '/sender|user|author/');
        $msgCol = isset($cc['message']) ? 'message' : 'body';
        $q = array(); $title = '';
        $mid = gx($x, array('message_id', 'chat_message_id'));
        $room = gx($x, array('room_id', 'match_id', 'chat_room_id'));
        if ($mid && app_pk($CT)) { $q[app_pk($CT)] = 'eq.' . $mid; $title = 'Reported message'; }
        elseif ($room && (isset($cc['room_id']) || isset($cc['match_id']))) { $q[isset($cc['room_id']) ? 'room_id' : 'match_id'] = 'eq.' . $room; $title = 'Chat in that room'; }
        elseif ($rep && $sender) { $q[$sender] = 'eq.' . $rep; $title = 'Latest chat messages of the reported player'; }
        if ($q) {
            if (isset($cc['created_at'])) { $q['order'] = 'created_at.desc'; }
            list($msgs, , ) = app_select($CT, $q, 50, 0, false);
            $names = $names + load_names(array_map(function ($m) use ($sender) { return $sender ? g($m, $sender) : null; }, $msgs));
            echo '<h2>' . e($title) . ' (' . count($msgs) . ')</h2><div class="tbl-wrap"><table><tr><th>When</th><th>From</th><th>Message</th></tr>';
            foreach ($msgs as $m) {
                $from = $sender ? g($m, $sender) : null;
                echo '<tr' . ($from && $from === $rep ? ' class="flag"' : '') . '><td class="nowrap">' . app_dt(g($m, 'created_at')) . '</td><td>' . nm($names, $from) . '</td><td class="chat">' . e((string) g($m, $msgCol, '')) . '</td></tr>';
            }
            if (!$msgs) { echo '<tr><td colspan="3" class="muted">No messages found.</td></tr>'; }
            echo '</table></div>';
        }
    }
    admin_foot(); exit;
}

/* ================= queue ================= */
$fs = app_get('status', 40);
$q = array();
if (isset($RC['created_at'])) { $q['order'] = 'created_at.desc'; }
if ($fs === 'open') { $q['status'] = 'in.(' . implode(',', array_unique(array_filter(array(report_status($RT, 'review', $S), 'pending', 'open', 'new', 'under_review')))) . ')'; }
elseif ($fs !== '' && app_valid_ident($fs)) { $q['status'] = 'eq.' . $fs; }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 30;
$ticket = app_get('ticket', 20);
if ($ticket !== '') {
    list($all, , $r) = app_select($RT, $q, 2000, 0, false);
    $rows = array_values(array_filter($all, function ($x) use ($pk, $ticket) { return app_ref_match('R', g($x, $pk), $ticket); }));
    $total = count($rows);
} else {
    list($rows, $total, $r) = app_select($RT, $q, $per, ($page - 1) * $per);
}
$ids = array(); foreach ($rows as $x) { $ids[] = $cReported ? g($x, $cReported) : null; $ids[] = $cReporter ? g($x, $cReporter) : null; }
$names = load_names($ids);

$opts = app_enum($RT, 'status') ?: array('pending', 'under_review', 'resolved', 'dismissed');
echo '<form class="row" method="get"><input name="ticket" value="' . e($ticket) . '" placeholder="Ticket ID, e.g. R-20481" style="width:190px"><select name="status"><option value="">All</option><option value="open"' . ($fs === 'open' ? ' selected' : '') . '>Open (to review)</option>';
foreach ($opts as $o) { echo '<option' . ($fs === $o ? ' selected' : '') . '>' . e($o) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><span class="muted">' . app_num($total) . ' reports</span></form>' . app_err_box($r);
echo '<div class="tbl-wrap"><table><tr><th>Ticket</th><th>When</th><th>Reported player</th><th>By</th><th>Reason</th><th>Details</th><th>Proof</th><th>Status</th><th>Actions</th></tr>';
foreach ($rows as $x) {
    $rid = (string) g($x, $pk, '');
    $href = '/admin/app/reports/?id=' . rawurlencode($rid);
    $shot = g($x, 'screenshot_path');
    echo '<tr><td class="ref"><a href="' . e($href) . '">' . e(app_ref('R', $rid)) . '</a></td><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td><b>' . nm($names, $cReported ? g($x, $cReported) : null) . '</b></td><td>' . nm($names, $cReporter ? g($x, $cReporter) : null) . '</td>';
    echo '<td>' . app_cell('reason', gx($x, array('reason', 'category'))) . '</td><td class="chat">' . app_cell('d', gx($x, array('details', 'description', 'message', 'comment'))) . '</td>';
    echo '<td>' . ($shot ? '<a href="/admin/app/evidence/?path=' . e(rawurlencode($shot)) . '" target="_blank" rel="noopener">Screenshot</a><br>' : '') . '<a href="' . e($href) . '">Chat &amp; details</a></td>';
    echo '<td>' . app_badge(g($x, 'status')) . '</td><td>' . report_actions($x, $pk, $cReported, $_SERVER['REQUEST_URI']) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="9" class="muted">' . ($r['ok'] ? ($ticket !== '' ? 'No report with that ticket ID.' : 'No reports.') : 'Could not load reports.') . '</td></tr>'; }
echo '</table></div>' . ($ticket === '' && $total !== null ? pager($page, $total, $per) : '');
admin_foot();
