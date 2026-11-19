<?php
/* App admin: audit log of all admin actions on the game. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
$r = app_list_audit(null, $per, ($page - 1) * $per);
$items = app_items($r);
app_head('App audit log', 'audit');
echo app_error_box($r);
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Action</th><th>Admin</th><th>Target</th><th>Details</th></tr>';
foreach ($items as $a) {
    echo '<tr><td class="nowrap">' . app_dt(g($a, 'created_at')) . '</td><td>' . app_badge(g($a, 'action')) . '</td><td>' . app_text(g($a, 'admin_username')) . '</td>';
    echo '<td>' . app_user_link(gx($a, array('target_user_id', 'target_id')), g($a, 'target_username')) . '</td><td>' . app_text(g($a, 'details')) . '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="5" class="muted">' . ($r['ok'] ? 'No admin actions yet.' : 'Could not load the audit log.') . '</td></tr>'; }
echo '</table></div>' . app_pager_simple($page, count($items), $per);
admin_foot();
