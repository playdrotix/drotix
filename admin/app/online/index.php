<?php
/* Mobile App: who is online (whole app). Live counts + every player with online / offline status.
   Auto-refreshes every 10 s. Online = heartbeat in the last 90 s. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$o = app_online(1000);
$PT = app_profiles_table(); $PC = app_cols($PT); $IDC = app_profile_idcol();
$totalPlayers = app_count($PT);
$inMatch = 0; foreach ($o['items'] as $i) { if (!empty($i['in_match'])) { $inMatch++; } }

$show = app_get('show', 10) === 'all' ? 'all' : 'online';
$q = app_get('q', 60);

app_head('Online now', 'online');
echo '<div class="row"><a class="btn ghost sm" href="' . e(qs(array())) . '">Refresh</a><span class="muted"><i class="dot on"></i> Live: updates every 10 s &middot; online = active in the app in the last 90 s &middot; ' . e(date('H:i:s')) . '</span></div><div data-autorefresh="10"></div>';
if (!$o['ok']) { echo '<div class="flash err">Could not read who is online: ' . e(app_rpc_error(array('error' => $o['error'], 'status' => 0))) . '</div>'; }

echo '<div class="cards">';
echo '<div class="card hot"><div class="n teal">' . app_num($o['count']) . '</div><div class="l"><i class="dot on"></i> Online now</div></div>';
echo '<div class="card"><div class="n">' . app_num($inMatch) . '</div><div class="l">Playing a match</div></div>';
echo '<div class="card"><div class="n">' . app_num(max(0, (int) $o['count'] - $inMatch)) . '</div><div class="l">Online, not in a match</div></div>';
echo '<div class="card"><div class="n gold">' . app_num($totalPlayers) . '</div><div class="l">All players in the app</div></div>';
echo '</div>';

echo '<nav class="tabs"><a href="/admin/app/online/"' . ($show === 'online' ? ' class="on"' : '') . '>Online now (' . app_num($o['count']) . ')</a><a href="/admin/app/online/?show=all"' . ($show === 'all' ? ' class="on"' : '') . '>All players (' . app_num($totalPlayers) . ')</a></nav>';

if ($show === 'online') {
    echo '<div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Status line</th><th>Activity</th><th>Last seen</th></tr>';
    foreach ($o['items'] as $i) {
        $uid = (string) g($i, 'user_id', ''); $href = '/admin/app/users/?id=' . rawurlencode($uid);
        echo '<tr class="click" data-href="' . e($href) . '"><td><div class="ucell"><span class="avw">' . app_avatar(g($i, 'avatar_url'), g($i, 'player_no')) . '<i class="dot on"></i></span><a href="' . e($href) . '"><b>' . e(g($i, 'username', '(no name)')) . '</b></a></div></td>';
        echo '<td class="pno">#' . e(app_pno(g($i, 'player_no'))) . '</td><td>' . (g($i, 'status_text') ? '&ldquo;' . e($i['status_text']) . '&rdquo;' : '<span class="muted">-</span>') . '</td>';
        echo '<td>' . (!empty($i['in_match']) ? '<span class="badge b-pink">In a match</span>' : '<span class="badge b-teal">Online</span>') . '</td><td class="nowrap">' . e(app_ago(g($i, 'last_seen_at'))) . '</td></tr>';
    }
    if (!$o['items']) { echo '<tr><td colspan="5" class="muted">Nobody is online right now.</td></tr>'; }
    echo '</table></div>';
    admin_foot(); exit;
}

/* every player of the app with live status */
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
$query = array(); if (isset($PC['created_at'])) { $query['order'] = 'created_at.desc'; }
if ($q !== '') {
    $s = pg_safe($q); $ors = array();
    foreach (array('username', 'display_name') as $c) { if (isset($PC[$c])) { $ors[] = $c . '.ilike.*' . $s . '*'; } }
    if (ctype_digit($q) && isset($PC['player_no'])) { $ors[] = 'player_no.eq.' . (int) $q; }
    if ($ors) { $query['or'] = '(' . implode(',', $ors) . ')'; }
}
list($rows, $total, $r) = app_select($PT, $query, $per, ($page - 1) * $per);
$ids = array(); foreach ($rows as $p) { $ids[] = $p[$IDC]; }
$seen = app_last_seen($ids);
echo '<form class="row" method="get"><input type="hidden" name="show" value="all"><input name="q" value="' . e($q) . '" placeholder="Username or player no"><button class="btn sm" type="submit">Search</button></form>' . app_err_box($r);
echo '<div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Status</th><th>Last seen</th><th>Account</th></tr>';
foreach ($rows as $p) {
    $uid = (string) $p[$IDC]; $href = '/admin/app/users/?id=' . rawurlencode($uid);
    $on = isset($o['ids'][$uid]) ? $o['ids'][$uid] : null;
    $ls = $on ? g($on, 'last_seen_at') : (isset($seen[$uid]) ? $seen[$uid] : null);
    echo '<tr class="click" data-href="' . e($href) . '"><td><div class="ucell"><span class="avw">' . app_avatar(g($p, 'avatar_url'), g($p, 'player_no')) . '<i class="dot ' . ($on ? 'on' : 'off') . '"></i></span><a href="' . e($href) . '"><b>' . e(gx($p, array('username', 'display_name'), '(no name)')) . '</b></a></div></td>';
    echo '<td class="pno">#' . e(app_pno(g($p, 'player_no'))) . '</td><td>' . ($on ? (!empty($on['in_match']) ? '<span class="badge b-pink">In a match</span>' : '<span class="badge b-teal">Online</span>') : '<span class="badge b-grey">Offline</span>') . '</td>';
    echo '<td class="nowrap">' . ($ls ? e(app_ago($ls)) : '<span class="muted">never</span>') . '</td><td>' . app_badge(g($p, 'account_status')) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="5" class="muted">No players.</td></tr>'; }
echo '</table></div>' . ($total !== null ? pager($page, $total, $per) : '');
admin_foot();
