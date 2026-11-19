<?php
/* Mobile App: Refer & Earn overview (read only, whole app). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$r = app_referral_overview(50);
$d = $r['ok'] ? $r['data'] : array();
$newBonus = (int) g($d, 'new_player_bonus', 50); $refBonus = (int) g($d, 'referrer_bonus', 100);

app_head('Refer & Earn', 'refer');
if (!$r['ok']) { echo '<div class="flash err">Could not load referrals: ' . e(app_rpc_error($r)) . '</div>'; admin_foot(); exit; }
echo '<p class="muted" style="margin-top:-8px">Friend <b class="pos">+' . e($newBonus) . '</b> / Referrer <b class="pos">+' . e($refBonus) . '</b> coins. A new player enters a friend\'s code on the code screen after sign-up; in the wallet history these show as <b>Referral welcome</b> and <b>Referral reward</b>.</p>';
echo '<div class="cards">';
echo '<div class="card"><div class="n">' . app_num(g($d, 'total_referrals')) . '</div><div class="l">Total referrals</div></div>';
echo '<div class="card"><div class="n teal">' . app_num(g($d, 'referrals_today')) . '</div><div class="l">Referrals today</div></div>';
echo '<div class="card"><div class="n gold">' . app_num(g($d, 'coins_to_new_players')) . '</div><div class="l">Coins to new players (Referral welcome)</div></div>';
echo '<div class="card"><div class="n gold">' . app_num(g($d, 'coins_to_referrers')) . '</div><div class="l">Coins to referrers (Referral reward)</div></div></div>';

$top = (array) g($d, 'top_referrers', array());
echo '<h2>Top referrers</h2><div class="tbl-wrap"><table><tr><th>#</th><th>Player</th><th>Code</th><th>Friends joined</th><th>Account</th></tr>';
foreach (array_values($top) as $i => $t) {
    $uid = (string) g($t, 'user_id', ''); $href = '/admin/app/users/?id=' . rawurlencode($uid);
    echo '<tr class="click" data-href="' . e($href) . '"><td><b>' . ($i + 1) . '</b></td><td>' . app_user_link($uid, g($t, 'username', '(no name)')) . ' <span class="pno">#' . e(app_pno(g($t, 'player_no'))) . '</span></td>';
    echo '<td><span class="code">' . e(g($t, 'referral_code', '-')) . '</span></td><td><b>' . app_num(g($t, 'friends')) . '</b></td><td>' . app_badge(g($t, 'account_status')) . '</td></tr>';
}
if (!$top) { echo '<tr><td colspan="5" class="muted">No referrals yet.</td></tr>'; }
echo '</table></div>';

$latest = (array) g($d, 'latest', array());
echo '<h2>Latest referrals</h2><div class="tbl-wrap"><table><tr><th>New player</th><th></th><th>Invited by</th><th>Code</th><th>Joined</th></tr>';
foreach ($latest as $l) {
    echo '<tr><td>' . app_user_link(g($l, 'user_id'), g($l, 'username', '(no name)')) . '</td><td class="muted">&larr;</td><td>' . app_user_link(g($l, 'referrer_id'), g($l, 'referrer_username', '(unknown)')) . '</td>';
    echo '<td><span class="code">' . e(g($l, 'referral_code', '-')) . '</span></td><td class="nowrap">' . app_dt(g($l, 'joined_at')) . '</td></tr>';
}
if (!$latest) { echo '<tr><td colspan="5" class="muted">No referrals yet.</td></tr>'; }
echo '</table></div>';
admin_foot();
