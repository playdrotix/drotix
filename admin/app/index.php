<?php
/* App admin: dashboard (KPIs from drotix.admin_dashboard). */
require __DIR__ . '/../../includes/app_admin_api.php';
app_require();

$r = app_dashboard();
$d = $r['ok'] ? $r['data'] : array();
app_head('Mobile App stats', 'dash');

echo '<div class="row"><a class="btn ghost sm" href="/admin/app/">Refresh</a><span class="muted">Auto-refreshes every 60 s &middot; updated ' . e(date('H:i:s')) . '</span></div>';
echo '<div data-autorefresh="60"></div>';
echo app_error_box($r);

$cards = array(
    array('Players', array(
        array('users_total', 'Total players', '', '/admin/app/users/'),
        array('users_today', 'New today', 'teal', ''),
        array('users_7d', 'New last 7 days', 'teal', ''),
        array('active_24h', 'Active last 24 h', 'teal', ''),
        array('email_verified', 'Email verified', '', ''),
        array('suspended', 'Suspended', 'hot', '/admin/app/users/?status=suspended'),
    )),
    array('Games', array(
        array('matches_today', 'Matches today', '', ''),
        array('online_rooms_live', 'Online rooms live', 'teal', ''),
        array('tournaments_live', 'Tournaments live', 'teal', '/admin/app/tournaments/'),
        array('tournaments_open', 'Tournaments open', 'gold', '/admin/app/tournaments/'),
    )),
    array('Coins', array(
        array('coins_in_wallets', 'Coins in wallets', 'gold', ''),
        array('coins_credited_today', 'Credited today', 'teal', ''),
        array('coins_debited_today', 'Debited today', '', ''),
    )),
    array('Moderation', array(
        array('reports_open', 'Open reports', 'hot', '/admin/app/reports/'),
        array('appeals_open', 'Open appeals', 'hot', '/admin/app/appeals/'),
    )),
);
foreach ($cards as $grp) {
    echo '<h2>' . e($grp[0]) . '</h2><div class="cards">';
    foreach ($grp[1] as $c) {
        $v = g($d, $c[0]);
        $hot = $c[2] === 'hot' && (int) $v > 0;
        $label = $c[3] !== '' ? '<a href="' . e($c[3]) . '">' . e($c[1]) . '</a>' : e($c[1]);
        echo '<div class="card' . ($hot ? ' hot' : '') . '"><div class="n ' . ($c[2] === 'teal' || $c[2] === 'gold' ? $c[2] : '') . '">' . ($r['ok'] ? app_num($v) : '?') . '</div><div class="l">' . $label . '</div></div>';
    }
    echo '</div>';
}
admin_foot();
