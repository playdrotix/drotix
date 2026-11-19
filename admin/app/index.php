<?php
/* Mobile App: stats (counts read directly from the drotix tables and Supabase Auth). */
require __DIR__ . '/../../includes/app_admin_api.php';
app_require();

$pt = app_profiles_table();
$pc = app_cols($pt);
$today = date('Y-m-d') . 'T00:00:00' . date('P');
$week = date('c', strtotime('-7 days'));
$cards = array();
$cards[] = array(app_count($pt), 'Players', '/admin/app/users/');
$on = app_online(100);
if ($on['ok']) { $cards[] = array($on['count'], 'Online now', '/admin/app/online/'); }
if (isset($pc['created_at'])) {
    $cards[] = array(app_count($pt, array('created_at' => 'gte.' . $today)), 'New today', '');
    $cards[] = array(app_count($pt, array('created_at' => 'gte.' . $week)), 'New last 7 days', '');
}
if (isset($pc['account_status'])) {
    $cards[] = array(app_count($pt, array('account_status' => 'eq.suspended')), 'Suspended', '/admin/app/users/?status=suspended');
}
if (isset($pc['is_admin'])) { $cards[] = array(app_count($pt, array('is_admin' => 'eq.true')), 'App admins', ''); }
$wt = app_wallet_table();
if ($wt) {
    list($rows, , ) = app_select($wt, array('select' => 'balance_minor'), 10000, 0, false);
    $sum = 0; foreach ($rows as $r) { $sum += (int) g($r, 'balance_minor', 0); }
    $cards[] = array($sum, 'Coins in wallets', '');
}

app_head('Mobile App stats', 'dash');
echo '<div class="row"><a class="btn ghost sm" href="/admin/app/">Refresh</a><span class="muted">Auto-refreshes every 60 s &middot; ' . e(date('H:i:s')) . '</span></div><div data-autorefresh="60"></div>';
echo '<div class="cards">';
foreach ($cards as $c) { echo '<div class="card"><div class="n">' . app_num($c[0]) . '</div><div class="l">' . ($c[2] ? '<a href="' . e($c[2]) . '">' . e($c[1]) . '</a>' : e($c[1])) . '</div></div>'; }
echo '</div>';

echo '<h2>All app tables</h2><div class="tbl-wrap"><table><tr><th>Table</th><th>Rows</th></tr>';
foreach (app_tables() as $t => $info) {
    echo '<tr><td><a href="/admin/app/data/?t=' . e(rawurlencode($t)) . '">' . e($t) . '</a></td><td>' . app_num(app_count($t)) . '</td></tr>';
}
echo '</table></div>';
admin_foot();
