<?php
/* Mobile App: account deletions (read only, no personal data by design). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$days = (int) (isset($_GET['days']) ? $_GET['days'] : 30);
if (!in_array($days, array(7, 30, 90), true)) { $days = 30; }
$r = app_account_deletions($days);
$d = $r['ok'] ? $r['data'] : array();
$items = (array) g($d, 'items', array());

app_head('Account deletions', 'deletions');
echo '<div class="panel"><b>Deletion request by email?</b><ol style="margin:8px 0 0;padding-left:20px;line-height:1.8">'
    . '<li>Find the player in <a href="/admin/app/users/">App users</a> by the email the request came from. Only delete when the request was sent from the account\'s own email address.</li>'
    . '<li>Open the player &rarr; <b>Danger zone</b> &rarr; <b>Delete account</b>. Write a note like "Email request ' . e(date('d M Y')) . '".</li>'
    . '<li>Reply to the player within 7 days that the account was deleted.</li></ol>'
    . '<p class="muted" style="margin:10px 0 0">Deletion page for Play Console: <code>https://playdrotix.in/delete-account/</code></p></div>';

echo '<div class="row"><span class="muted">Period:</span>';
foreach (array(7, 30, 90) as $n) { echo '<a class="btn sm' . ($n === $days ? '' : ' ghost') . '" href="/admin/app/deletions/?days=' . $n . '">' . $n . ' days</a>'; }
echo '</div>';
if (!$r['ok']) { echo '<div class="flash err">Could not load deletions: ' . e(app_rpc_error($r)) . '</div>'; admin_foot(); exit; }

echo '<div class="cards sm"><div class="card"><div class="n">' . app_num(g($d, 'total', 0)) . '</div><div class="l">Total deleted</div></div>';
echo '<div class="card"><div class="n teal">' . app_num(g($d, 'last_days', count($items))) . '</div><div class="l">Last ' . $days . ' days</div></div></div>';

$srcBadge = array('app' => '<span class="badge b-blue">App</span>', 'web' => '<span class="badge b-teal">Web</span>', 'admin' => '<span class="badge b-pink">Admin</span>');
echo '<div class="tbl-wrap"><table><tr><th>Deleted</th><th>Source</th><th>Website login kept</th></tr>';
foreach ($items as $x) {
    $s = (string) g($x, 'source', '');
    echo '<tr><td class="nowrap">' . app_dt(g($x, 'deleted_at')) . '</td><td>' . (isset($srcBadge[$s]) ? $srcBadge[$s] : app_badge($s)) . '</td><td>' . (g($x, 'login_kept') ? 'yes' : 'no') . '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="3" class="muted">No accounts deleted in the last ' . $days . ' days.</td></tr>'; }
echo '</table></div><p class="muted">No names or emails are kept for deleted accounts, by design.</p>';
admin_foot();
