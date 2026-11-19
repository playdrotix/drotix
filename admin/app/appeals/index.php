<?php
/* Mobile App: suspension appeals. Approve (lift the suspension) / Reject. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$AT = app_has_table('appeals') ? 'appeals' : null;
if (!$AT) { foreach (app_tables() as $t => $i) { if (preg_match('/appeal/', $t)) { $AT = $t; break; } } }
$pk = $AT ? (app_pk($AT) ?: 'id') : 'id';
$uc = $AT ? app_owner_col($AT) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $AT) {
    csrf_verify();
    $aid = isset($_POST['appeal_id']) ? (string) $_POST['appeal_id'] : '';
    $dec = isset($_POST['decision']) ? (string) $_POST['decision'] : '';
    $notes = app_post('notes', 2000);
    if ($aid === '' || !in_array($dec, array('approved', 'rejected'), true)) { flash_set('err', 'Bad request.'); redirect('/admin/app/appeals/'); }
    $r = app_rpc('admin_review_appeal', array('p_appeal_id' => $aid, 'p_decision' => $dec, 'p_admin_notes' => $notes !== '' ? $notes : null));
    if (!$r['ok']) {   // fallback: update the row + profile directly
        list($rows, , ) = app_select($AT, array($pk => 'eq.' . $aid), 1, 0, false);
        $st = app_pick_value($AT, 'status', $dec === 'approved' ? array('approved', 'accepted', 'resolved') : array('rejected', 'denied', 'dismissed'));
        $patch = array('status' => $st); $c = app_cols($AT);
        if ($notes !== '' && isset($c['admin_notes'])) { $patch['admin_notes'] = $notes; }
        if (isset($c['reviewed_at'])) { $patch['reviewed_at'] = date('c'); }
        $r = app_db('PATCH', $AT, array($pk => 'eq.' . $aid), $patch, 'return=minimal');
        if ($r['ok'] && $dec === 'approved' && $rows && $uc && !empty($rows[0][$uc])) { app_set_status($rows[0][$uc], false, ''); }
    } elseif ($dec === 'approved') {
        list($rows, , ) = app_select($AT, array($pk => 'eq.' . $aid), 1, 0, false);
        if ($rows && $uc && !empty($rows[0][$uc])) { app_auth_update($rows[0][$uc], array('ban_duration' => 'none')); }
    }
    if ($r['ok']) {
        app_log('appeal_' . $dec, $aid, $notes);
        if (!empty($_POST['tell']) && $uc) {
            list($rows, , ) = app_select($AT, array($pk => 'eq.' . $aid), 1, 0, false);
            if ($rows && !empty($rows[0][$uc])) { app_notify($rows[0][$uc], $dec === 'approved' ? 'Appeal approved' : 'Appeal rejected', $dec === 'approved' ? 'Your account is active again.' . ($notes !== '' ? ' ' . $notes : '') : 'Your appeal was rejected.' . ($notes !== '' ? ' ' . $notes : '')); }
        }
        flash_set('ok', $dec === 'approved' ? 'Appeal approved: the player is active again.' : 'Appeal rejected.');
    } else { flash_set('err', 'Failed: ' . $r['error']); }
    redirect('/admin/app/appeals/' . (isset($_GET['status']) ? '?status=' . rawurlencode((string) $_GET['status']) : ''));
}

app_head('Appeals', 'appeals');
if (!$AT) { echo '<div class="flash err">No appeals table found in the drotix schema.</div>'; admin_foot(); exit; }
$AC = app_cols($AT);
$fs = app_get('status', 40);
$q = array(); if (isset($AC['created_at'])) { $q['order'] = 'created_at.desc'; }
if ($fs !== '' && app_valid_ident($fs)) { $q['status'] = 'eq.' . $fs; }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 30;
list($rows, $total, $r) = app_select($AT, $q, $per, ($page - 1) * $per);
$ids = array(); foreach ($rows as $x) { if ($uc && !empty($x[$uc])) { $ids[] = $x[$uc]; } }
$prof = array();
if ($ids) {
    $pt = app_profiles_table(); $idc = app_profile_idcol();
    list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', array_unique($ids)) . ')'), 300, 0, false);
    foreach ($ps as $p) { $prof[$p[$idc]] = $p; }
}
$opts = app_enum($AT, 'status') ?: array('pending', 'approved', 'rejected');
echo '<form class="row" method="get"><select name="status"><option value="">All</option>';
foreach ($opts as $o) { echo '<option' . ($fs === $o ? ' selected' : '') . '>' . e($o) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><span class="muted">' . app_num($total) . ' appeals</span></form>' . app_err_box($r);
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Player</th><th>Why suspended</th><th>Appeal message</th><th>Status</th><th>Decision</th></tr>';
foreach ($rows as $x) {
    $aid = (string) g($x, $pk, ''); $u = $uc ? g($x, $uc) : null; $p = $u && isset($prof[$u]) ? $prof[$u] : array();
    $st = (string) g($x, 'status', '');
    echo '<tr><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td><b>' . app_user_link($u, gx($p, array('username'), $u ? substr($u, 0, 8) : '-')) . '</b> ' . app_badge(g($p, 'account_status')) . '</td>';
    echo '<td>' . app_cell('r', gx($x, array('suspension_reason'), g($p, 'suspension_reason'))) . '</td><td class="chat">' . app_cell('m', gx($x, array('message', 'appeal_text', 'text', 'body', 'reason'))) . (g($x, 'admin_notes') ? '<div class="muted">Note: ' . e(g($x, 'admin_notes')) . '</div>' : '') . '</td><td>' . app_badge($st) . '</td><td>';
    if (!preg_match('/approv|reject|accept|deni|resolv|dismiss/', $st)) {
        echo '<form method="post" style="display:grid;gap:6px;min-width:220px" data-confirm="Apply this decision? Approving makes the player active again.">' . csrf_field() . '<input type="hidden" name="appeal_id" value="' . e($aid) . '"><input name="notes" maxlength="2000" placeholder="Note to the player (optional)">';
        echo '<label class="chk"><input type="checkbox" name="tell" value="1" checked> Notify the player</label><div class="row" style="margin:0;gap:6px"><button class="btn sm" name="decision" value="approved" type="submit">Approve</button><button class="btn danger sm" name="decision" value="rejected" type="submit">Reject</button></div></form>';
    } else { echo '<span class="muted">' . app_dt(gx($x, array('reviewed_at', 'updated_at'))) . '</span>'; }
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="6" class="muted">' . ($r['ok'] ? 'No appeals.' : 'Could not load appeals.') . '</td></tr>'; }
echo '</table></div>' . ($total !== null ? pager($page, $total, $per) : '');
admin_foot();
