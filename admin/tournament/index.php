<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();
$table = tbl('tournament');
$allowed = array('new', 'confirmed', 'waitlisted', 'rejected');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if (uuid_ok($id)) {
        if ($act === 'delete') {
            $r = sb_delete($table, array('id' => 'eq.' . $id));
            if (sb_ok($r)) { audit_log('tournament_delete', $id); flash_set('ok', 'Registration removed.'); } else { flash_set('err', $r['error']); }
        } elseif ($act === 'status') {
            $st = isset($_POST['status']) ? (string) $_POST['status'] : '';
            if (in_array($st, $allowed, true)) {
                list($old, , ) = sb_select($table, array('id' => 'eq.' . $id), 1, 0);
                $r = sb_update($table, array('id' => 'eq.' . $id), array('status' => $st));
                if (sb_ok($r)) {
                    audit_log('tournament_status', $id, $st);
                    $msg = clean_str(isset($_POST['note']) ? $_POST['note'] : '', 1000);
                    $titles = array('confirmed' => 'You are in! Drotix Cup seat confirmed', 'waitlisted' => 'Drotix Cup: you are on the waiting list',
                                    'rejected' => 'Drotix Cup: registration update', 'new' => '');
                    $changed = $old && $old[0]['status'] !== $st;
                    $emailed = '';
                    if ($old && !empty($old[0]['email']) && ($msg !== '' || ($changed && $titles[$st] !== ''))) {
                        require_once __DIR__ . '/../../includes/mailer.php';
                        $label = array('confirmed' => 'Confirmed', 'waitlisted' => 'Waitlisted', 'rejected' => 'Not selected', 'new' => 'Received')[$st];
                        list($mok, ) = mail_send_template($old[0]['email'], 'tournament_status', $titles[$st] !== '' ? $titles[$st] : 'Message about Drotix Cup', array('name' => $old[0]['name'], 'username' => $old[0]['username'],
                            'status' => $label, 'tournament' => isset($old[0]['tournament']) ? $old[0]['tournament'] : 'Drotix Cup', 'starts_at' => 'See the message below', 'message' => $msg !== '' ? $msg : 'Open your WhatsApp / email for the match details.'));
                        $emailed = $mok ? ' Email sent.' : ' (Email failed.)';
                    }
                    if ($old && !empty($old[0]['visitor_id']) && ($msg !== '' || ($changed && $titles[$st] !== ''))) {
                        sb_insert(tbl('notify'), array('visitor_id' => $old[0]['visitor_id'], 'kind' => 'status',
                            'title' => $titles[$st] !== '' ? $titles[$st] : 'Message about Drotix Cup',
                            'body' => $msg !== '' ? $msg : 'Open your WhatsApp / email for the match details.', 'ref_table' => $table, 'ref_id' => $id));
                        flash_set('ok', 'Status updated and the player was notified on the website.' . $emailed);
                    } else { flash_set('ok', 'Status updated.' . $emailed); }
                } else { flash_set('err', $r['error']); }
            }
        }
    }
    redirect('/admin/tournament/' . (!empty($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
}

$q = pg_safe(isset($_GET['q']) ? $_GET['q'] : '');
$st = isset($_GET['status']) ? (string) $_GET['status'] : '';
$query = array('order' => 'created_at.desc');
if (in_array($st, $allowed, true)) { $query['status'] = 'eq.' . $st; }
if ($q !== '') { $query['or'] = '(name.ilike.*' . $q . '*,email.ilike.*' . $q . '*,username.ilike.*' . $q . '*,phone.ilike.*' . $q . '*,city.ilike.*' . $q . '*)'; }
if (isset($_GET['export'])) { csv_out('tournament_registrations.csv', sb_all($table, $query)); }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Tournament registrations', 'tour');
$n = function ($v) { return $v === null ? '?' : (string) $v; };
echo '<div class="cards"><div class="card"><div class="n">' . e($n(sb_count($table))) . '</div><div class="l">Total registered</div></div>';
echo '<div class="card' . (sb_count($table, array('status' => 'eq.new')) ? ' hot' : '') . '"><div class="n">' . e($n(sb_count($table, array('status' => 'eq.new')))) . '</div><div class="l">New (to review)</div></div>';
echo '<div class="card"><div class="n">' . e($n(sb_count($table, array('status' => 'eq.confirmed')))) . '</div><div class="l">Confirmed</div></div></div>';
echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Search name, email, phone, player, city"><select name="status"><option value="">All statuses</option>';
foreach ($allowed as $a) { echo '<option value="' . e($a) . '"' . ($st === $a ? ' selected' : '') . '>' . e($a) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';
if (!sb_ok($r)) { echo '<div class="flash err">' . e($r['error']) . '</div>'; }
echo '<div class="tbl-wrap"><table><tr><th>Registered</th><th>Player</th><th>Contact</th><th>City / level</th><th>Status</th><th></th></tr>';
foreach ($rows as $w) {
    echo '<tr><td>' . fmt_dt($w['created_at']) . '</td><td><b>' . e($w['username']) . '</b><div class="muted">' . e($w['name']) . '</div></td>';
    echo '<td><a href="mailto:' . e($w['email']) . '">' . e($w['email']) . '</a><div class="muted"><a href="https://wa.me/' . e(preg_replace('/\D/', '', (string) $w['phone'])) . '" target="_blank" rel="noopener">' . e($w['phone']) . '</a></div></td>';
    echo '<td>' . e(isset($w['city']) ? $w['city'] : '') . '<div class="muted">' . e(isset($w['experience']) ? $w['experience'] : '') . '</div></td><td>' . badge($w['status']) . '</td><td>';
    echo '<form method="post" class="row" style="gap:6px">' . csrf_field() . '<input type="hidden" name="id" value="' . e($w['id']) . '"><input type="hidden" name="action" value="status"><select name="status">';
    foreach ($allowed as $a) { echo '<option value="' . e($a) . '"' . ($w['status'] === $a ? ' selected' : '') . '>' . e($a) . '</option>'; }
    echo '</select><input name="note" maxlength="1000" placeholder="Message to player (optional)" style="min-width:170px"><button class="btn sm" type="submit">Save &amp; notify</button></form>';
    echo '<form method="post" data-confirm="Remove this registration?">' . csrf_field() . '<input type="hidden" name="id" value="' . e($w['id']) . '"><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit">Remove</button></form></td></tr>';
}
if (!$rows) { echo '<tr><td colspan="6" class="muted">No registrations yet.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
