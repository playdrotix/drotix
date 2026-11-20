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
$pp = app_list_iap_purchases(null, 500, 0);
$rf = app_referral_overview(1);
if ($rf['ok']) { $cards[] = array(g($rf['data'], 'referrals_today', 0), 'Referrals today', '/admin/app/referrals/'); }
$dl = app_account_deletions(30);
if ($dl['ok']) { $cards[] = array(g($dl['data'], 'last_days', 0), 'Accounts deleted (30 days)', '/admin/app/deletions/'); }
$ca = app_chat_abuse_list('new', null, 1, 0);
if ($ca['ok']) { $cc = (array) g($ca['data'], 'counts', array()); $cards[] = array(g($cc, 'today', 0), 'Chat abuse today (' . (int) g($cc, 'review', 0) . ' need review)', '/admin/app/chat/'); }
$tk = app_list_tickets('active', '', '', 1, 0);
if ($tk['ok']) { $tc = (array) g($tk['data'], 'counts', array()); $cards[] = array((int) g($tc, 'open', 0) + (int) g($tc, 'in_progress', 0) + (int) g($tc, 'waiting_user', 0), 'Support: open tickets (' . (int) g($tc, 'unread', 0) . ' unread)', '/admin/app/tickets/'); }
if ($pp['ok']) {
    $midnight = strtotime('today'); $pc = 0; $pinr = 0.0; $rej = 0;
    foreach ((array) g($pp['data'], 'items', array()) as $x) {
        if (strtotime((string) g($x, 'created_at', '')) < $midnight) { continue; }
        if (g($x, 'status') === 'credited') { $pc++; $pinr += (float) g($x, 'price_inr', 0); } elseif (g($x, 'status') === 'rejected') { $rej++; }
    }
}
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
if ($pp['ok']) {
    echo '<h2>Purchases today</h2><div class="cards"><div class="card"><div class="n teal">' . app_num($pc) . '</div><div class="l"><a href="/admin/app/purchases/?status=credited">Purchases today</a></div><div class="s">' . e(app_inr($pinr)) . ' paid</div></div>';
    echo '<div class="card' . ($rej ? ' hot' : '') . '"><div class="n' . ($rej ? ' neg' : '') . '"' . ($rej ? ' style="color:var(--rd)"' : '') . '>' . app_num($rej) . '</div><div class="l"><a href="/admin/app/purchases/?status=rejected">Rejected today</a></div></div></div>';
}

echo '<h2>All app tables</h2><div class="tbl-wrap"><table><tr><th>Table</th><th>Rows</th></tr>';
foreach (app_tables() as $t => $info) {
    echo '<tr><td><a href="/admin/app/data/?t=' . e(rawurlencode($t)) . '">' . e($t) . '</a></td><td>' . app_num(app_count($t)) . '</td></tr>';
}
echo '</table></div>';
admin_foot();
