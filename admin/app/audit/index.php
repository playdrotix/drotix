<?php
/* Mobile App: audit log. 1) changes made from this panel (website audit table)  2) the game's own admin audit (admin_list_audit). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$src = app_get('src', 10) === 'game' ? 'game' : 'panel';
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
app_head('Mobile App audit log', 'audit');
echo '<div class="row"><a class="btn sm' . ($src === 'panel' ? '' : ' ghost') . '" href="/admin/app/audit/">This panel</a><a class="btn sm' . ($src === 'game' ? '' : ' ghost') . '" href="/admin/app/audit/?src=game">Game audit (admin functions)</a></div>';

if ($src === 'game') {
    $r = app_rpc('admin_list_audit', array('p_user_id' => null, 'p_limit' => $per, 'p_offset' => ($page - 1) * $per));
    $rows = $r['ok'] ? (array) g($r['data'], 'items', array()) : array();
    if (!$r['ok']) { echo '<div class="flash err">' . e(app_rpc_error($r)) . '</div>'; }
    echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Action</th><th>Admin</th><th>Target</th><th>Details</th></tr>';
    foreach ($rows as $a) {
        echo '<tr><td class="nowrap">' . app_dt(g($a, 'created_at')) . '</td><td><b>' . e(app_action_label(g($a, 'action', ''))) . '</b><div class="muted" style="font-size:12px">' . e(g($a, 'action', '')) . '</div></td>';
        echo '<td>' . e(g($a, 'admin_username', '-')) . '</td><td>' . app_user_link(gx($a, array('target_user_id', 'target_id')), g($a, 'target_username')) . '</td><td>' . app_cell('details', g($a, 'details')) . '</td></tr>';
    }
    if (!$rows) { echo '<tr><td colspan="5" class="muted">No entries.</td></tr>'; }
    echo '</table></div>' . app_pager_simple($page, count($rows), $per);
    admin_foot(); exit;
}

list($rows, $total, $r) = sb_select(tbl('audit'), array('action' => 'like.app_*', 'order' => 'created_at.desc'), $per, ($page - 1) * $per);
if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th></tr>';
foreach ($rows as $a) {
    $target = (string) g($a, 'target', '');
    $tgt = uuid_ok($target) ? app_user_link($target, substr($target, 0, 8)) : e($target);
    echo '<tr><td class="nowrap">' . app_dt(g($a, 'created_at')) . '</td><td>' . e(g($a, 'admin_user', '-')) . '</td><td><b>' . e(app_action_label(g($a, 'action', ''))) . '</b></td><td>' . $tgt . '</td><td class="chat">' . e(g($a, 'details', '')) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="5" class="muted">No changes yet.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
