<?php
/* App admin: player reports queue. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();
$statuses = array('pending', 'under_review', 'resolved', 'dismissed');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rid = isset($_POST['report_id']) ? (string) $_POST['report_id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if (!uuid_ok($rid)) { flash_set('err', 'Bad report id.'); redirect('/admin/app/reports/'); }
    if ($act === 'status') {
        $st = isset($_POST['status']) ? (string) $_POST['status'] : '';
        if (!in_array($st, $statuses, true)) { flash_set('err', 'Bad status.'); redirect('/admin/app/reports/'); }
        app_flash_result(app_update_report($rid, $st, app_post('notes', 2000)), 'Report set to ' . str_replace('_', ' ', $st) . '.');
    } elseif ($act === 'suspend') {
        $uid = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
        $reason = app_post('reason', 500);
        if (!uuid_ok($uid) || $reason === '') { flash_set('err', 'Player and reason are required.'); redirect('/admin/app/reports/'); }
        app_flash_result(app_suspend_user($uid, $reason, $rid, app_post('notes', 2000)), 'Player suspended from this report.');
    }
    redirect('/admin/app/reports/' . (isset($_GET['status']) ? '?status=' . rawurlencode((string) $_GET['status']) : ''));
}

$r = app_list_reports(200);
$items = app_items($r);
$filter = app_get('status', 20);
if (in_array($filter, $statuses, true)) { $items = array_values(array_filter($items, function ($x) use ($filter) { return g($x, 'status') === $filter; })); }

app_head('Player reports', 'reports');
echo '<form class="row" method="get"><select name="status"><option value="">All statuses</option>';
foreach ($statuses as $s) { echo '<option value="' . $s . '"' . ($filter === $s ? ' selected' : '') . '>' . e(str_replace('_', ' ', $s)) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><span class="muted">' . count($items) . ' reports</span></form>';
echo app_error_box($r);
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Reported player</th><th>Reported by</th><th>Reason</th><th>Details</th><th>Screenshot</th><th>Status</th><th>Actions</th></tr>';
foreach ($items as $x) {
    $rid = (string) g($x, 'id', '');
    $uid = (string) gx($x, array('reported_id', 'reported_user_id'), '');
    $shot = g($x, 'screenshot_path');
    echo '<tr><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td><b>' . app_user_link($uid, g($x, 'reported_username')) . '</b></td>';
    echo '<td>' . app_user_link(gx($x, array('reporter_id', 'reporter_user_id')), g($x, 'reporter_username')) . '</td><td>' . app_text(gx($x, array('reason', 'category'))) . '</td>';
    echo '<td class="chat">' . app_text(gx($x, array('details', 'description', 'message'))) . (g($x, 'admin_notes') ? '<div class="muted">Note: ' . e(g($x, 'admin_notes')) . '</div>' : '') . '</td>';
    echo '<td>' . ($shot ? '<a href="/admin/app/evidence/?path=' . e(rawurlencode($shot)) . '" target="_blank" rel="noopener">Open</a>' : '<span class="muted">-</span>') . '</td><td>' . app_badge(g($x, 'status')) . '</td><td>';
    if (uuid_ok($rid)) {
        echo '<div class="actions" style="margin:0">';
        echo '<details class="act"><summary class="btn ghost sm">Status</summary><form class="pop" method="post">' . csrf_field() . '<input type="hidden" name="report_id" value="' . e($rid) . '"><input type="hidden" name="action" value="status">';
        echo '<label>New status<select name="status">';
        foreach ($statuses as $s) { echo '<option value="' . $s . '"' . (g($x, 'status') === $s ? ' selected' : '') . '>' . e(str_replace('_', ' ', $s)) . '</option>'; }
        echo '</select></label><label>Admin notes<textarea name="notes" maxlength="2000">' . e(g($x, 'admin_notes', '')) . '</textarea></label><button class="btn sm" type="submit">Save</button></form></details>';
        if (uuid_ok($uid)) {
            echo '<details class="act"><summary class="btn danger sm">Suspend</summary><form class="pop" method="post" data-confirm="Suspend ' . e(g($x, 'reported_username', 'this player')) . ' based on this report?">' . csrf_field()
                . '<input type="hidden" name="report_id" value="' . e($rid) . '"><input type="hidden" name="user_id" value="' . e($uid) . '"><input type="hidden" name="action" value="suspend">'
                . '<label>Reason (shown to the player)<input name="reason" required maxlength="500" value="' . e(gx($x, array('reason', 'category'), '')) . '"></label><label>Admin notes<textarea name="notes" maxlength="2000"></textarea></label>'
                . '<button class="btn danger sm" type="submit">Suspend from this report</button></form></details>';
        }
        echo '</div>';
    }
    echo '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="8" class="muted">' . ($r['ok'] ? 'No reports.' : 'Could not load reports.') . '</td></tr>'; }
echo '</table></div>';
admin_foot();
