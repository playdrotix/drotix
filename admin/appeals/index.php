<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$table = tbl('suspension_appeals');
$allowedStatus = array('pending', 'under_review', 'approved', 'rejected', 'escalated');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = $_POST['id'] ?? '';
    $act = $_POST['action'] ?? '';
    if (uuid_ok($id)) {
        if ($act === 'status') {
            $st = $_POST['status'] ?? '';
            if (in_array($st, $allowedStatus, true)) {
                $data = array('status' => $st, 'reviewed_by' => $_SESSION['admin'], 'reviewed_at' => gmdate('c'), 'updated_at' => gmdate('c'));
                if (!empty($_POST['review_notes'])) { $data['review_notes'] = clean_str($_POST['review_notes'], 2000); }
                if (!empty($_POST['admin_response'])) { $data['admin_response'] = clean_str($_POST['admin_response'], 2000); }
                $r = sb_update($table, array('id' => 'eq.' . $id), $data);
                if (sb_ok($r)) {
                    audit_log('appeal_status', $id, $st);
                    // Notify user
                    list($old, , ) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
                    if ($old && !empty($old[0]['user_id']) && !empty($data['admin_response'])) {
                        create_user_notification($old[0]['user_id'], 'suspension_appeal_result',
                            'Appeal ' . ucfirst($st),
                            'Your suspension appeal has been ' . $st . '.',
                            array('appeal_id' => $id),
                            '/appeal/'
                        );
                    }
                    flash_set('ok', 'Appeal status updated and user notified.');
                } else { flash_set('err', $r['error']); }
            }
        }
    }
    redirect('/admin/appeals/' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

$st = $_GET['status'] ?? '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowedStatus, true)) { $query['status'] = 'eq.' . $st; }
if (isset($_GET['export'])) { csv_out('appeals.csv', sb_all($table, $query)); }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Suspension Appeals', 'appeals');
echo '<div class="cards">';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.pending')) ? ' hot' : '') . '"><div class="n">' . e(sb_count($table, array('status' => 'eq.pending')) ?? '?') . '</div><div class="l">Pending Review</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.under_review')) ?? '?') . '</div><div class="l">Under Review</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.approved')) ?? '?') . '</div><div class="l">Approved</div></div>';
echo '<div class="card"><div class="n">' . e(sb_count($table, array('status' => 'eq.rejected')) ?? '?') . '</div><div class="l">Rejected</div></div>';
echo '</div>';

echo '<form class="row" method="get"><select name="status"><option value="">All statuses</option>';
foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($st === $s ? ' selected' : '') . '>' . e(ucfirst(str_replace('_', ' ', $s))) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';

if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>User</th><th>Email</th><th>Status</th><th>Submitted</th><th>Reason</th><th>Evidence</th><th>Review</th><th></th></tr>';
foreach ($rows as $a) {
    echo '<tr>';
    echo '<td><b>' . e($a['username']) . '</b><div class="muted">#' . e(substr($a['user_id'], 0, 8)) . '</div></td>';
    echo '<td><a href="mailto:' . e($a['email']) . '">' . e($a['email']) . '</a></td>';
    echo '<td>' . badge($a['status']) . '</td>';
    echo '<td>' . fmt_dt($a['created_at']) . '</td>';
    echo '<td class="clip" style="max-width:200px">' . e($a['reason']) . '</td>';
    echo '<td class="clip" style="max-width:200px">' . e($a['evidence'] ?? '') . '</td>';
    echo '<td>';
    if ($a['reviewed_at']) {
        echo 'By: ' . e($a['reviewed_by'] ?? 'Admin') . '<br>';
        echo fmt_dt($a['reviewed_at']) . '<br>';
        if ($a['review_notes']) { echo '<div class="muted" style="font-size:12px">' . e($a['review_notes']) . '</div>'; }
    } else { echo '<span class="muted">Not reviewed</span>'; }
    echo '</td>';
    echo '<td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($a['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowedStatus as $s) { echo '<option value="' . e($s) . '"' . ($a['status'] === $s ? ' selected' : '') . '>' . e(ucfirst(str_replace('_', ' ', $s))) . '</option>'; }
    echo '</select>';
    echo '<input name="review_notes" placeholder="Internal notes" style="min-width:150px">';
    echo '<input name="admin_response" placeholder="Message to user" style="min-width:150px">';
    echo '<button class="btn sm" type="submit">Update</button></form>';
    echo '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="8" class="muted">No appeals found.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();