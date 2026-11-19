<?php
/* Mobile App: suspension appeals (ticket ids A-xxxxx like the app).
   Approve = lift the suspension, Reject = stay suspended, Suspend = suspend again with a new reason.
   The player is ALWAYS notified of the decision. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$AT = app_has_table('appeals') ? 'appeals' : null;
if (!$AT) { foreach (app_tables() as $t => $i) { if (preg_match('/appeal/', $t)) { $AT = $t; break; } } }
$pk = $AT ? (app_pk($AT) ?: 'id') : 'id';
$uc = $AT ? app_owner_col($AT) : null;

function appeal_row($AT, $pk, $aid) { list($rows, , ) = app_select($AT, array($pk => 'eq.' . $aid), 1, 0, false); return $rows ? $rows[0] : null; }
/** Set the appeal row status directly (used when the game's function is not available). */
function appeal_patch($AT, $pk, $aid, $kind, $notes) {
    $st = app_pick_value($AT, 'status', $kind === 'approved' ? array('approved', 'accepted', 'resolved') : array('rejected', 'denied', 'dismissed'));
    $patch = array('status' => $st); $c = app_cols($AT);
    if ($notes !== '' && isset($c['admin_notes'])) { $patch['admin_notes'] = $notes; }
    foreach (array('reviewed_at', 'decided_at', 'resolved_at') as $k) { if (isset($c[$k])) { $patch[$k] = date('c'); } }
    return app_db('PATCH', $AT, array($pk => 'eq.' . $aid), $patch, 'return=minimal');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $AT) {
    csrf_verify();
    $aid = isset($_POST['appeal_id']) ? (string) $_POST['appeal_id'] : '';
    $dec = isset($_POST['decision']) ? (string) $_POST['decision'] : '';
    $notes = app_post('notes', 2000);
    $back = '/admin/app/appeals/' . (!empty($_POST['qs']) && $_POST['qs'][0] === '?' ? (string) $_POST['qs'] : '');
    $row = $aid !== '' ? appeal_row($AT, $pk, $aid) : null;
    if (!$row || !in_array($dec, array('approved', 'rejected', 'suspend'), true)) { flash_set('err', 'Appeal not found or bad decision.'); redirect($back); }
    $uid = $uc ? (string) g($row, $uc, '') : '';
    $ref = app_ref('A', $aid);
    $msgs = array();

    if ($dec === 'suspend') {
        $reason = app_post('reason', 500);
        if (!uuid_ok($uid) || $reason === '') { flash_set('err', 'A suspension reason is required.'); redirect($back); }
        $r = app_rpc('admin_review_appeal', array('p_appeal_id' => $aid, 'p_decision' => 'rejected', 'p_admin_notes' => $notes !== '' ? $notes : $reason));
        if (!$r['ok']) { $r = appeal_patch($AT, $pk, $aid, 'rejected', $notes !== '' ? $notes : $reason); }
        if ($r['ok']) {
            $s = app_rpc('admin_suspend_user', array('p_user_id' => $uid, 'p_reason' => $reason, 'p_report_id' => null, 'p_admin_notes' => $notes !== '' ? $notes : null));
            if (!$s['ok']) { $s = app_set_status($uid, true, $reason); } else { app_auth_update($uid, array('ban_duration' => '876000h')); }
            if (!$s['ok']) { $r = $s; }
        }
        $title = 'Appeal ' . $ref . ': account suspended';
        $body = 'Your appeal was rejected and your account is suspended. Reason: ' . $reason . ($notes !== '' ? ' ' . $notes : '');
    } else {
        $r = app_rpc('admin_review_appeal', array('p_appeal_id' => $aid, 'p_decision' => $dec, 'p_admin_notes' => $notes !== '' ? $notes : null));
        if (!$r['ok']) {
            $r = appeal_patch($AT, $pk, $aid, $dec, $notes);
            if ($r['ok'] && $dec === 'approved' && uuid_ok($uid)) { $r = app_set_status($uid, false, ''); }
        } elseif ($dec === 'approved' && uuid_ok($uid)) {
            app_auth_update($uid, array('ban_duration' => 'none'));   // make sure the login block is lifted too
        }
        $title = $dec === 'approved' ? 'Appeal ' . $ref . ' approved' : 'Appeal ' . $ref . ' rejected';
        $body = $dec === 'approved' ? 'Good news: your account is active again.' . ($notes !== '' ? ' ' . $notes : '')
                                    : 'Your appeal was reviewed and rejected. Your account stays suspended.' . ($notes !== '' ? ' ' . $notes : '');
    }

    if ($r['ok']) {
        app_log($dec === 'suspend' ? 'appeal_suspend' : 'appeal_' . $dec, $aid, $notes);
        if (uuid_ok($uid)) { $n = app_notify($uid, $title, $body); $msgs[] = $n['ok'] ? 'player notified' : 'notification failed: ' . $n['error']; }
        flash_set('ok', array('approved' => 'Appeal approved: the player is active again', 'rejected' => 'Appeal rejected', 'suspend' => 'Appeal rejected and player suspended')[$dec] . ' (' . $ref . ($msgs ? ', ' . implode(', ', $msgs) : '') . ').');
    } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); }
    redirect($back);
}

app_head('Appeals', 'appeals');
if (!$AT) { echo '<div class="flash err">No appeals table found in the drotix schema.</div>'; admin_foot(); exit; }
$AC = app_cols($AT);
$fs = app_get('status', 40); $ticket = app_get('ticket', 20);
$q = array(); if (isset($AC['created_at'])) { $q['order'] = 'created_at.desc'; }
if ($fs !== '' && app_valid_ident($fs)) { $q['status'] = 'eq.' . $fs; }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 30;
if ($ticket !== '') {   // ticket search: compare the ticket id of recent rows
    list($all, , $r) = app_select($AT, $q, 2000, 0, false);
    $rows = array_values(array_filter($all, function ($x) use ($pk, $ticket) { return app_ref_match('A', g($x, $pk), $ticket); }));
    $total = count($rows);
} else {
    list($rows, $total, $r) = app_select($AT, $q, $per, ($page - 1) * $per);
}
$ids = array(); foreach ($rows as $x) { if ($uc && !empty($x[$uc])) { $ids[] = $x[$uc]; } }
$prof = array();
if ($ids) {
    $pt = app_profiles_table(); $idc = app_profile_idcol();
    list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', array_unique($ids)) . ')'), 300, 0, false);
    foreach ($ps as $p) { $prof[$p[$idc]] = $p; }
}
$opts = app_enum($AT, 'status') ?: array('pending', 'approved', 'rejected');
$qsNow = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
echo '<form class="row" method="get"><input name="ticket" value="' . e($ticket) . '" placeholder="Ticket ID, e.g. A-20481" style="width:190px"><select name="status"><option value="">All</option>';
foreach ($opts as $o) { echo '<option' . ($fs === $o ? ' selected' : '') . '>' . e($o) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Search</button>' . ($ticket !== '' || $fs !== '' ? '<a class="btn ghost sm" href="/admin/app/appeals/">Clear</a>' : '') . '<span class="muted">' . app_num($total) . ' appeals &middot; the player is notified of every decision</span></form>' . app_err_box($r);
echo '<div class="tbl-wrap"><table><tr><th>Ticket</th><th>When</th><th>Player</th><th>Why suspended</th><th>Appeal message</th><th>Status</th><th>Decision</th></tr>';
foreach ($rows as $x) {
    $aid = (string) g($x, $pk, ''); $u = $uc ? g($x, $uc) : null; $p = $u && isset($prof[$u]) ? $prof[$u] : array();
    $st = (string) g($x, 'status', '');
    echo '<tr><td class="ref">' . e(app_ref('A', $aid)) . '</td><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td><b>' . app_user_link($u, gx($p, array('username'), $u ? substr($u, 0, 8) : '-')) . '</b><br>' . app_badge(g($p, 'account_status')) . '</td>';
    echo '<td>' . app_cell('r', gx($x, array('suspension_reason'), g($p, 'suspension_reason'))) . '</td><td class="chat">' . app_cell('m', gx($x, array('message', 'appeal_text', 'text', 'body', 'reason'))) . (g($x, 'admin_notes') ? '<div class="muted">Note: ' . e(g($x, 'admin_notes')) . '</div>' : '') . '</td><td>' . app_badge($st) . '</td><td>';
    if (!preg_match('/approv|reject|accept|deni|resolv|dismiss/', $st)) {
        $h = csrf_field() . '<input type="hidden" name="appeal_id" value="' . e($aid) . '"><input type="hidden" name="qs" value="' . e($qsNow) . '">';
        echo '<div class="actions" style="margin:0">';
        echo '<details class="act"><summary class="btn sm">Approve</summary><form class="pop" method="post" data-confirm="Approve appeal ' . e(app_ref('A', $aid)) . '? The player becomes active again and is notified.">' . $h . '<input type="hidden" name="decision" value="approved"><label>Message to the player (optional)<textarea name="notes" maxlength="2000"></textarea></label><button class="btn sm" type="submit">Approve &amp; notify</button></form></details>';
        echo '<details class="act"><summary class="btn ghost sm">Reject</summary><form class="pop" method="post" data-confirm="Reject appeal ' . e(app_ref('A', $aid)) . '? The player stays suspended and is notified.">' . $h . '<input type="hidden" name="decision" value="rejected"><label>Message to the player (optional)<textarea name="notes" maxlength="2000"></textarea></label><button class="btn danger sm" type="submit">Reject &amp; notify</button></form></details>';
        if ($u && uuid_ok($u)) {
            echo '<details class="act"><summary class="btn danger sm">Suspend</summary><form class="pop" method="post" data-confirm="Reject the appeal and suspend this player with the new reason?">' . $h . '<input type="hidden" name="decision" value="suspend"><label>Suspension reason (shown to the player)<input name="reason" required maxlength="500"></label><label>Admin notes<textarea name="notes" maxlength="2000"></textarea></label><button class="btn danger sm" type="submit">Suspend &amp; notify</button></form></details>';
        }
        echo '</div>';
    } else { echo '<span class="muted">' . app_dt(gx($x, array('reviewed_at', 'decided_at', 'updated_at'))) . '</span>'; }
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="7" class="muted">' . ($r['ok'] ? ($ticket !== '' ? 'No appeal with that ticket ID.' : 'No appeals.') : 'Could not load appeals.') . '</td></tr>'; }
echo '</table></div>' . ($ticket === '' && $total !== null ? pager($page, $total, $per) : '');
admin_foot();
