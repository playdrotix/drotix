<?php
/* App admin: suspension appeals queue. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $aid = isset($_POST['appeal_id']) ? (string) $_POST['appeal_id'] : '';
    $dec = isset($_POST['decision']) ? (string) $_POST['decision'] : '';
    if (!uuid_ok($aid) || !in_array($dec, array('approved', 'rejected'), true)) { flash_set('err', 'Bad request.'); redirect('/admin/app/appeals/'); }
    app_flash_result(app_review_appeal($aid, $dec, app_post('notes', 2000)), $dec === 'approved' ? 'Appeal approved: the player is reinstated.' : 'Appeal rejected.');
    redirect('/admin/app/appeals/');
}

$r = app_list_appeals(200);
$items = app_items($r);
app_head('Appeals', 'appeals');
echo app_error_box($r);
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Player</th><th>Suspension reason</th><th>Appeal</th><th>Status</th><th>Decision</th></tr>';
foreach ($items as $x) {
    $aid = (string) g($x, 'id', '');
    $st = (string) g($x, 'status', '');
    echo '<tr><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td><b>' . app_user_link(gx($x, array('user_id', 'player_id')), gx($x, array('username', 'user_username'))) . '</b></td>';
    echo '<td>' . app_text(gx($x, array('suspension_reason', 'reason'))) . '</td><td class="chat">' . app_text(gx($x, array('message', 'appeal_text', 'text', 'body'))) . (g($x, 'admin_notes') ? '<div class="muted">Note: ' . e(g($x, 'admin_notes')) . '</div>' : '') . '</td>';
    echo '<td>' . app_badge($st) . '</td><td>';
    if (uuid_ok($aid) && !in_array($st, array('approved', 'rejected'), true)) {
        echo '<form method="post" class="row" style="margin:0;gap:6px" data-confirm="Apply this decision to the appeal? Approving lifts the suspension.">' . csrf_field() . '<input type="hidden" name="appeal_id" value="' . e($aid) . '"><input name="notes" maxlength="2000" placeholder="Notes (optional)" style="min-width:160px">';
        echo '<button class="btn sm" name="decision" value="approved" type="submit">Approve</button><button class="btn danger sm" name="decision" value="rejected" type="submit">Reject</button></form>';
    } else {
        echo '<span class="muted">' . app_dt(gx($x, array('reviewed_at', 'updated_at'))) . '</span>';
    }
    echo '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="6" class="muted">' . ($r['ok'] ? 'No appeals.' : 'Could not load appeals.') . '</td></tr>'; }
echo '</table></div><p class="muted">Approving an appeal lifts the suspension.</p>';
admin_foot();
