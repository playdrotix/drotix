<?php
/* Mobile App: players online now (heartbeat in the last 90 s), auto-refresh every 30 s. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$o = app_online(1000);   // every player online right now (whole app)
app_head('Online now', 'online');
echo '<div class="row"><a class="btn ghost sm" href="/admin/app/online/">Refresh</a><span class="muted">All players of the app who are online now &middot; auto-refreshes every 30 s &middot; online = seen in the last 90 s &middot; ' . e(date('H:i:s')) . '</span></div><div data-autorefresh="30"></div>';
if (!$o['ok']) { echo '<div class="flash err">Could not load: ' . e(app_rpc_error(array('error' => $o['error'], 'status' => 0))) . '</div>'; admin_foot(); exit; }
echo '<div class="cards sm"><div class="card"><div class="n teal">' . app_num($o['count']) . '</div><div class="l">Online now</div></div>';
$inMatch = 0; foreach ($o['items'] as $i) { if (!empty($i['in_match'])) { $inMatch++; } }
echo '<div class="card"><div class="n">' . app_num($inMatch) . '</div><div class="l">In a match</div></div></div>';
echo '<div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Status line</th><th>Activity</th><th>Last seen</th></tr>';
foreach ($o['items'] as $i) {
    $uid = (string) g($i, 'user_id', '');
    $href = '/admin/app/users/?id=' . rawurlencode($uid);
    echo '<tr class="click" data-href="' . e($href) . '"><td><div class="ucell"><span class="avw">' . app_avatar(g($i, 'avatar_url'), g($i, 'player_no')) . '<i class="dot on"></i></span><a href="' . e($href) . '"><b>' . e(g($i, 'username', '(no name)')) . '</b></a></div></td>';
    echo '<td class="pno">#' . e(app_pno(g($i, 'player_no'))) . '</td><td>' . (g($i, 'status_text') ? '&ldquo;' . e($i['status_text']) . '&rdquo;' : '<span class="muted">-</span>') . '</td>';
    echo '<td>' . (!empty($i['in_match']) ? '<span class="badge b-pink">In a match</span>' : '<span class="badge b-teal">Online</span>') . '</td><td class="nowrap">' . e(app_ago(g($i, 'last_seen_at'))) . '</td></tr>';
}
if (!$o['items']) { echo '<tr><td colspan="5" class="muted">Nobody is online right now.</td></tr>'; }
echo '</table></div>';
admin_foot();
