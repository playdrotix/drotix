<?php
/* Mobile App: audit log of every change made from this section (stored in the website audit table). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
list($rows, $total, $r) = sb_select(tbl('audit'), array('action' => 'like.app_*', 'order' => 'created_at.desc'), $per, ($page - 1) * $per);
app_head('Mobile App audit log', 'audit');
if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th></tr>';
foreach ($rows as $a) {
    $target = (string) g($a, 'target', '');
    $tgt = uuid_ok($target) ? app_user_link($target, substr($target, 0, 8)) : e($target);
    echo '<tr><td class="nowrap">' . app_dt(g($a, 'created_at')) . '</td><td>' . e(g($a, 'admin_user', '-')) . '</td><td>' . app_badge(substr((string) g($a, 'action', ''), 4)) . '</td><td>' . $tgt . '</td><td class="chat">' . e(g($a, 'details', '')) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="5" class="muted">No changes yet.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
