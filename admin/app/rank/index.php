<?php
/* Mobile App: Nearby & Rank (whole app). Overview, Players, Player detail, Tiers, Settings, Missions, New season. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$TABS = array('overview' => 'Overview', 'players' => 'Players', 'tiers' => 'Tiers', 'settings' => 'Settings', 'missions' => 'Missions', 'season' => 'New season');
$TIERS = array('bronze', 'silver', 'gold', 'elite');
$KINDS = array('play_any', 'play_online', 'win_online', 'send_challenge', 'ranked_challenge', 'add_friend', 'watch_ad');
$uid = isset($_GET['user']) && uuid_ok($_GET['user']) ? (string) $_GET['user'] : '';
$tab = $uid !== '' ? 'players' : (isset($_GET['tab'], $TABS[$_GET['tab']]) ? (string) $_GET['tab'] : 'overview');

/* ---------- live poll: latest rank promotion ---------- */
if (isset($_GET['poll'])) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $p = app_rank_latest_promotion(); $name = '';
    if ($p) { $u = gx($p, array('user_id', 'player_id')); if (uuid_ok($u)) { list($pp, , ) = app_select(app_profiles_table(), array(app_profile_idcol() => 'eq.' . $u, 'select' => 'username'), 1, 0, false); $name = $pp ? (string) g($pp[0], 'username', '') : ''; } }
    echo json_encode(array('id' => (string) gx($p, array('id', 'created_at'), ''), 'text' => $p ? (($name ?: 'A player') . ': ' . gx($p, array('from_tier', 'old_tier'), '?') . ' → ' . gx($p, array('to_tier', 'new_tier'), '?')) : ''));
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $back = isset($_POST['back']) && strpos((string) $_POST['back'], '/admin/app/rank/') === 0 ? (string) $_POST['back'] : '/admin/app/rank/';
    $num = function ($k, $def = null) { return (isset($_POST[$k]) && trim((string) $_POST[$k]) !== '' && is_numeric($_POST[$k])) ? $_POST[$k] + 0 : $def; };
    $done = function ($r, $ok, $log, $target, $det = '') { if ($r['ok']) { app_log($log, $target, $det); flash_set('ok', $ok); } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); } };
    if ($act === 'rank_update') {
        $u = (string) $_POST['user_id']; $note = app_post('note', 500);
        $to = isset($_POST['tier_override']) ? (string) $_POST['tier_override'] : '__keep';
        $tier = $to === '__keep' ? null : ($to === '__auto' ? '' : (in_array($to, $TIERS, true) ? $to : null));
        $rp = $num('rp_delta'); $tk = $num('tickets');
        if ($note === '') { flash_set('err', 'A note is required.'); redirect($back); }
        if ($tk !== null && ($tk < 0 || $tk > 99)) { flash_set('err', 'Tickets must be 0–99.'); redirect($back); }
        $done(app_rank_update($u, $tier, $rp, $tk, $note), 'Rank updated.', 'rank_update', $u, array('tier' => $tier, 'rp' => $rp, 'tickets' => $tk, 'note' => $note));
    } elseif ($act === 'nearby_ban' || $act === 'nearby_unban') {
        $u = (string) $_POST['user_id']; $ban = $act === 'nearby_ban'; $reason = app_post('reason', 300);
        if ($ban && $reason === '') { flash_set('err', 'Give a reason.'); redirect($back); }
        $done(app_nearby_ban($u, $ban, $reason), $ban ? 'Banned from Nearby. Their location was removed.' : 'Nearby ban lifted.', $act, $u, $reason);
    } elseif ($act === 'tier_save') {
        $t = (string) $_POST['tier'];
        $d = array('label' => app_post('label', 40));
        foreach (array('min_matches', 'min_friends', 'min_streak_days', 'min_rp', 'top_percent', 'friend_requests_per_day', 'challenge_tickets_per_day', 'map_radius_km') as $k) { $v = $num($k); if ($v !== null) { $d[$k] = $v; } }
        $d['pin_frame'] = !empty($_POST['pin_frame']);
        $col = app_post('color', 7); if (preg_match('/^#[0-9A-Fa-f]{6}$/', $col)) { $d['color'] = strtoupper($col); }
        if (isset($d['map_radius_km']) && ($d['map_radius_km'] < 0.1 || $d['map_radius_km'] > 50)) { flash_set('err', 'Map radius must be 0.1–50 km.'); redirect($back); }
        $done(app_rank_tier_save($t, $d), ucfirst($t) . ' saved.', 'rank_tier_save', $t, $d);
    } elseif ($act === 'settings_save') {
        $d = array();
        foreach (array('nearby_enabled', 'rank_enabled', 'ad_require_ssv') as $k) { $d[$k] = !empty($_POST[$k]); }
        foreach (array('rp_win', 'rp_draw', 'rp_loss', 'rp_challenge_win', 'rp_challenge_draw', 'rp_challenge_loss', 'rp_daily_cap', 'same_opponent_daily_limit', 'ad_tickets_per_day', 'location_ttl_minutes', 'stranger_fuzz_m') as $k) { $v = $num($k); if ($v !== null) { $d[$k] = (int) $v; } }
        if (isset($d['location_ttl_minutes']) && ($d['location_ttl_minutes'] < 5 || $d['location_ttl_minutes'] > 240)) { flash_set('err', 'Location TTL must be 5–240 minutes.'); redirect($back); }
        if (isset($d['stranger_fuzz_m']) && ($d['stranger_fuzz_m'] < 100 || $d['stranger_fuzz_m'] > 5000)) { flash_set('err', 'Stranger blur must be 100–5000 m.'); redirect($back); }
        $d['season_name'] = app_post('season_name', 60);
        $d['season_ends_at'] = app_local_to_iso(isset($_POST['season_ends_at']) ? $_POST['season_ends_at'] : '');
        $done(app_rank_settings_save($d), 'Settings saved.', 'rank_settings_save', 'settings', $d);
    } elseif ($act === 'mission_save') {
        $mid = isset($_POST['mission_id']) && $_POST['mission_id'] !== '' ? (string) $_POST['mission_id'] : null;
        $d = array('code' => app_post('code', 40), 'title' => app_post('title', 80), 'description' => app_post('description', 300), 'kind' => in_array($_POST['kind'], $KINDS, true) ? $_POST['kind'] : 'play_any',
                   'target' => max(1, min(100, (int) $num('target', 1))), 'reward_rp' => max(0, min(500, (int) $num('reward_rp', 0))), 'reward_tickets' => max(0, min(10, (int) $num('reward_tickets', 0))),
                   'active' => !empty($_POST['active']), 'sort' => (int) $num('sort', 0));
        if ($d['code'] === '' || $d['title'] === '') { flash_set('err', 'Code and title are required.'); redirect($back); }
        $done(app_rank_mission_save($mid, $d), 'Mission saved.', 'rank_mission_save', $d['code'], $d);
        $back = '/admin/app/rank/?tab=missions';
    } elseif ($act === 'mission_delete') {
        $done(app_rank_mission_delete((string) $_POST['mission_id']), 'Mission deleted (or switched off if it was ever claimed).', 'rank_mission_delete', (string) $_POST['mission_id']);
    } elseif ($act === 'season_reset') {
        if (trim((string) (isset($_POST['confirm']) ? $_POST['confirm'] : '')) !== 'RESET') { flash_set('err', 'Type RESET to confirm.'); redirect($back); }
        $keep = (int) $num('keep_percent', 0); $name = app_post('season_name', 60);
        if ($keep < 0 || $keep > 100 || $name === '') { flash_set('err', 'Keep % must be 0–100 and the season needs a name.'); redirect($back); }
        $done(app_rank_season_reset($keep, $name), 'New season "' . $name . '" started. Everyone kept ' . $keep . '% of their RP.', 'rank_season_reset', $name, array('keep' => $keep));
    }
    redirect($back);
}

$RULES = '<div class="panel" style="font-size:14px;line-height:1.7"><b>Rules</b><ul style="margin:6px 0 0;padding-left:20px">'
    . '<li><b>RP has no money value.</b> Coins are never involved. Ranked challenges are free; strangers cost 1 ticket, friends cost nothing.</li>'
    . '<li><b>Days reset at midnight Asia/Kolkata.</b></li>'
    . '<li><b>Only verified-email, non-guest players over 18 can use Nearby.</b> There is no chat on the map; chat only happens inside a match.</li></ul></div>';
$me = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/admin/app/rank/';
function kv_table($arr, $skip = array()) {
    if (!$arr) { return '<p class="muted">-</p>'; }
    $h = '<dl class="kv">';
    foreach ((array) $arr as $k => $v) { if (in_array($k, $skip, true)) { continue; } $h .= '<dt>' . e(str_replace('_', ' ', $k)) . '</dt><dd>' . app_cell($k, $v) . '</dd>'; }
    return $h . '</dl>';
}

app_head('Nearby & Rank', 'rank');
echo '<nav class="tabs" style="margin-top:0">'; foreach ($TABS as $k => $v) { echo '<a href="/admin/app/rank/?tab=' . $k . '"' . ($tab === $k ? ' class="on"' : '') . '>' . e($v) . '</a>'; } echo '</nav>';

/* ================= Player detail ================= */
if ($uid !== '') {
    $r = app_rank_player($uid);
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/rank/?tab=players">&larr; All ranked players</a><a class="btn ghost sm" href="/admin/app/users/?id=' . e($uid) . '">Open App user page</a></div>';
    if (!$r['ok']) { echo '<div class="flash err">' . e(app_rpc_error($r)) . '</div>'; admin_foot(); exit; }
    $D = $r['data']; $P = (array) g($D, 'profile', array()); $R = (array) g($D, 'rank', array()); $N = (array) g($D, 'nearby', array()); $L = g($D, 'last_location');
    echo '<div class="panel"><div class="uhead">' . app_avatar(g($P, 'avatar_url'), g($P, 'player_no'), 64) . '<div class="who"><h2>' . e(g($P, 'username', '(no name)')) . ' <span class="pno">#' . e(app_pno(g($P, 'player_no'))) . '</span> ' . app_tier_badge(g($R, 'tier'), (bool) g($R, 'tier_override')) . ' ' . app_badge(g($P, 'account_status')) . '</h2>';
    echo '<div class="muted">RP <b style="color:var(--t)">' . app_num(g($R, 'rp')) . '</b> &middot; tickets ' . app_num(g($R, 'tickets')) . ' &middot; ranked W/L/D ' . app_num(g($R, 'wins', 0)) . '/' . app_num(g($R, 'losses', 0)) . '/' . app_num(g($R, 'draws', 0)) . '</div></div>';
    echo '<div class="meta"><div><span>Matches:</span> ' . app_num(g($D, 'stats.matches')) . '</div><div><span>Friends:</span> ' . app_num(g($D, 'stats.friends')) . '</div><div><span>Streak:</span> ' . app_num(g($D, 'stats.streak_days')) . ' days</div>';
    echo '<div><span>Nearby:</span> ' . (g($N, 'enabled', g($N, 'nearby_enabled')) ? 'on' : 'off') . (g($N, 'ghost') ? ' &middot; ghost' : '') . (g($N, 'banned') ? ' &middot; <span class="neg">banned</span>' : '') . '</div>';
    echo '<div><span>Status text:</span> ' . app_cell('s', g($N, 'status_text')) . '</div><div><span>Visibility:</span> ' . app_cell('v', g($N, 'visibility')) . '</div>';
    echo '<div><span>Last location:</span> ' . ($L ? e(number_format((float) gx($L, array('lat', 'latitude'), 0), 3)) . ', ' . e(number_format((float) gx($L, array('lng', 'lon', 'longitude'), 0), 3)) . ' <span class="muted">(~100 m, private)</span> &middot; ' . app_dt(gx($L, array('at', 'updated_at', 'created_at'))) : '<span class="muted">-</span>') . '</div></div></div>';
    echo '<p class="muted" style="font-size:12px;margin:10px 0 0">Opening this page is written to the audit log. Location is private: no map pin is shown.</p></div>';

    $h = csrf_field() . '<input type="hidden" name="user_id" value="' . e($uid) . '"><input type="hidden" name="back" value="' . e($me) . '">';
    echo '<div class="actions">';
    echo '<details class="act"><summary class="btn sm">Change rank</summary><form class="pop" method="post" data-confirm="Change the rank of ' . e(g($P, 'username', 'this player')) . '?">' . $h . '<input type="hidden" name="action" value="rank_update">'
        . '<label>Tier<select name="tier_override"><option value="__keep">Leave unchanged</option><option value="__auto">Automatic (remove lock)</option>';
    foreach ($TIERS as $t) { echo '<option value="' . $t . '">Lock: ' . ucfirst($t) . '</option>'; }
    echo '</select></label><label>RP change (&plusmn;, optional)<input name="rp_delta" type="number" step="1" placeholder="e.g. 50 or -30"></label><label>Today\'s tickets (0–99, optional)<input name="tickets" type="number" min="0" max="99" step="1"></label>'
        . '<label>Note (required)<input name="note" required maxlength="500"></label><button class="btn sm" type="submit">Save</button></form></details>';
    if (g($N, 'banned')) {
        echo '<form method="post" data-confirm="Lift the Nearby ban?">' . $h . '<input type="hidden" name="action" value="nearby_unban"><button class="btn ghost sm" type="submit">Unban from Nearby</button></form>';
    } else {
        echo '<details class="act"><summary class="btn danger sm">Ban from Nearby</summary><form class="pop" method="post" data-confirm="Ban this player from Nearby? Their location is removed at once.">' . $h . '<input type="hidden" name="action" value="nearby_ban"><label>Reason<input name="reason" required maxlength="300"></label><button class="btn danger sm" type="submit">Ban from Nearby</button></form></details>';
    }
    echo '</div>';
    echo '<h2>Rank row</h2><div class="panel">' . kv_table($R) . '</div>';
    foreach (array('ledger' => 'RP ledger', 'promotions' => 'Promotions', 'mission_claims' => 'Mission claims', 'ranked_challenges' => 'Ranked challenges') as $k => $t) {
        $rows = (array) g($D, $k, array()); echo '<h2>' . e($t) . ' (' . count($rows) . ')</h2>' . app_rows_table($rows, null, 'None.');
    }
    admin_foot(); exit;
}

/* ================= Overview ================= */
if ($tab === 'overview') {
    $r = app_rank_overview(); $D = $r['ok'] ? $r['data'] : array();
    echo '<div data-rankpoll="/admin/app/rank/?poll=1"></div><div class="toasts" id="toasts"></div>';
    if (!$r['ok']) { echo '<div class="flash err">' . e(app_rpc_error($r)) . '</div>'; }
    $cards = array('ranked_players' => 'Ranked players', 'nearby_joined' => 'Joined Nearby', 'nearby_sharing_now' => 'Live on the map now', 'nearby_ghost' => 'Ghost mode', 'nearby_banned' => 'Banned from Nearby',
                   'rp_given_today' => 'RP given today', 'rp_taken_today' => 'RP taken today', 'ranked_challenges_today' => 'Ranked challenges today', 'missions_claimed_today' => 'Missions claimed today',
                   'ad_tickets_today' => 'Ad tickets today', 'rank_ups_today' => 'Rank-ups today');
    echo '<div class="cards">'; foreach ($cards as $k => $l) { echo '<div class="card"><div class="n' . ($k === 'nearby_sharing_now' ? ' teal' : '') . '">' . app_num(g($D, $k)) . '</div><div class="l">' . e($l) . '</div></div>'; } echo '</div>';
    $tiers = (array) g($D, 'tiers', array());
    echo '<h2>Players per tier</h2><div class="tbl-wrap"><table><tr><th>Tier</th><th>Players</th></tr>';
    foreach ($tiers as $t) { echo '<tr><td>' . app_tier_badge(gx($t, array('tier', 'code'))) . ' ' . e(g($t, 'label', '')) . '</td><td><b>' . app_num(g($t, 'players')) . '</b></td></tr>'; }
    if (!$tiers) { echo '<tr><td colspan="2" class="muted">No data.</td></tr>'; }
    echo '</table></div>';
    echo '<h2>Low balance</h2><div class="panel" style="font-size:14px;line-height:1.7"><ul style="margin:0;padding-left:20px">'
        . '<li>New wallets start with <b>50 coins</b> (was 500). Existing wallets are not changed.</li>'
        . '<li>The server refuses a coin match, a stake or a 5-coin challenge when the balance is too low.</li>'
        . '<li>The app shows "Your balance is low" and opens the Wallet.</li></ul></div>';
    echo $RULES;
}

/* ================= Players ================= */
elseif ($tab === 'players') {
    $q = app_get('q', 80); $ft = app_get('tier', 20); if (!in_array($ft, $TIERS, true)) { $ft = ''; }
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
    $r = app_rank_players($q, $ft, $per, ($page - 1) * $per);
    $items = $r['ok'] ? (array) g($r['data'], 'items', array()) : array();
    echo '<form class="row" method="get"><input type="hidden" name="tab" value="players"><input name="q" value="' . e($q) . '" placeholder="Username, user id or player no"><select name="tier"><option value="">All tiers</option>';
    foreach ($TIERS as $t) { echo '<option value="' . $t . '"' . ($ft === $t ? ' selected' : '') . '>' . ucfirst($t) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Search</button><span class="muted">' . app_num($r['ok'] ? g($r['data'], 'total') : null) . ' players</span></form>';
    if (!$r['ok']) { echo '<div class="flash err">' . e(app_rpc_error($r)) . '</div>'; }
    echo '<div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Tier</th><th>RP</th><th>Tickets</th><th>Ranked W/L/D</th><th>Nearby</th><th>Ghost</th><th>Banned</th><th>Last location</th><th>Account</th></tr>';
    foreach ($items as $x) {
        $u = (string) gx($x, array('user_id', 'id'), ''); $href = '/admin/app/rank/?user=' . rawurlencode($u);
        echo '<tr class="click" data-href="' . e($href) . '"><td><a href="' . e($href) . '"><b>' . e(g($x, 'username', '(no name)')) . '</b></a></td><td class="pno">#' . e(app_pno(g($x, 'player_no'))) . '</td><td>' . app_tier_badge(g($x, 'tier'), (bool) g($x, 'tier_override')) . '</td>';
        echo '<td><b>' . app_num(g($x, 'rp')) . '</b></td><td>' . app_num(g($x, 'tickets')) . '</td><td class="nowrap">' . app_num(g($x, 'wins', g($x, 'ranked_wins', 0))) . '/' . app_num(g($x, 'losses', g($x, 'ranked_losses', 0))) . '/' . app_num(g($x, 'draws', g($x, 'ranked_draws', 0))) . '</td>';
        echo '<td>' . (gx($x, array('nearby_enabled', 'nearby')) ? '<span class="pos">on</span>' : '<span class="muted">off</span>') . '</td><td>' . (g($x, 'ghost') ? 'yes' : '-') . '</td><td>' . (g($x, 'banned') ? '<span class="neg">yes</span>' : '-') . '</td>';
        echo '<td class="nowrap">' . (g($x, 'last_location_at') ? e(app_ago($x['last_location_at'])) : '<span class="muted">-</span>') . '</td><td>' . app_badge(g($x, 'account_status')) . '</td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="11" class="muted">' . ($r['ok'] ? 'No players.' : 'Could not load.') . '</td></tr>'; }
    echo '</table></div>' . ($r['ok'] ? pager($page, (int) g($r['data'], 'total', 0), $per) : '');
}

/* ================= Tiers ================= */
elseif ($tab === 'tiers') {
    $tiers = app_rank_tiers();
    echo '<p class="muted" style="margin-top:0">Tiers stack: a player must meet the lower tier first. Defaults: Bronze basic (3 requests, 0 tickets, 1 km) · Silver 20 matches + 5 friends (7, 1, 3 km) · Gold 60 matches + 7-day streak (15, 3, 5 km) · Elite top 10 % (25, 5 + pin frame, 10 km).</p>';
    if (!$tiers) { echo '<div class="flash err">Could not read drotix.rank_tiers.</div>'; }
    echo '<div class="bcards">';
    foreach ($tiers as $t) {
        $code = (string) gx($t, array('tier', 'code', 'id'), '');
        echo '<form method="post" class="bcard" data-confirm="Save the ' . e(ucfirst($code)) . ' tier? It applies to every player.">' . csrf_field() . '<input type="hidden" name="action" value="tier_save"><input type="hidden" name="tier" value="' . e($code) . '"><input type="hidden" name="back" value="/admin/app/rank/?tab=tiers">';
        echo '<div class="row" style="margin:0;justify-content:space-between">' . app_tier_badge($code) . '<input name="color" type="color" value="' . e(g($t, 'color', '#A8A8B5')) . '" title="Color" style="width:48px;padding:2px"></div><div class="fgrid" style="grid-template-columns:1fr 1fr;margin:0">';
        $fields = array('label' => 'Label', 'min_matches' => 'Min matches', 'min_friends' => 'Min friends', 'min_streak_days' => 'Min streak days', 'min_rp' => 'Min RP', 'top_percent' => 'Top % (Elite)',
                        'friend_requests_per_day' => 'Friend requests / day', 'challenge_tickets_per_day' => 'Tickets / day', 'map_radius_km' => 'Map radius km (0.1–50)');
        foreach ($fields as $k => $l) { echo '<label>' . e($l) . '<input name="' . $k . '"' . ($k === 'label' ? ' maxlength="40"' : ' type="number" step="' . ($k === 'map_radius_km' || $k === 'top_percent' ? '0.1' : '1') . '"') . ' value="' . e(g($t, $k, '')) . '"></label>'; }
        echo '</div><label class="chk"><input type="checkbox" name="pin_frame" value="1"' . (g($t, 'pin_frame') ? ' checked' : '') . '> Pin frame on the map</label><button class="btn sm" type="submit">Save ' . e(ucfirst($code)) . '</button></form>';
    }
    echo '</div>';
}

/* ================= Settings ================= */
elseif ($tab === 'settings') {
    $r = app_rank_overview(); $S = $r['ok'] ? (array) g($r['data'], 'settings', array()) : array();
    if (!$r['ok']) { echo '<div class="flash err">' . e(app_rpc_error($r)) . '</div>'; }
    $n = function ($k, $l, $def, $extra = '') use ($S) { return '<label>' . $l . '<input name="' . $k . '" type="number" step="1" value="' . e(g($S, $k, $def)) . '"' . $extra . '></label>'; };
    echo '<form method="post" class="panel" data-confirm="Save the Nearby & Rank settings? They apply to every player right away.">' . csrf_field() . '<input type="hidden" name="action" value="settings_save"><input type="hidden" name="back" value="/admin/app/rank/?tab=settings">';
    echo '<div class="row"><label class="chk"><input type="checkbox" name="nearby_enabled" value="1"' . (g($S, 'nearby_enabled', true) ? ' checked' : '') . '> <b>Nearby map on</b> (kill switch)</label><label class="chk"><input type="checkbox" name="rank_enabled" value="1"' . (g($S, 'rank_enabled', true) ? ' checked' : '') . '> <b>Rank on</b> (off stops RP changes)</label></div>';
    echo '<h3>RP per result</h3><div class="fgrid">' . $n('rp_win', 'Online win', 20) . $n('rp_draw', 'Online draw', 5) . $n('rp_loss', 'Online loss', -10) . $n('rp_challenge_win', 'Ranked challenge win', 30) . $n('rp_challenge_draw', 'Ranked challenge draw', 8) . $n('rp_challenge_loss', 'Ranked challenge loss', -15) . '</div>';
    echo '<h3>Limits</h3><div class="fgrid">' . $n('rp_daily_cap', 'RP daily cap', 300) . $n('same_opponent_daily_limit', 'Same opponent per day (stops farming)', 3) . $n('ad_tickets_per_day', 'Ad tickets per day', 2) . '</div>';
    echo '<label class="chk"><input type="checkbox" name="ad_require_ssv" value="1"' . (g($S, 'ad_require_ssv') ? ' checked' : '') . '> Require AdMob SSV for ad tickets (turn on only after SSV is set up)</label>';
    echo '<h3>Location</h3><div class="fgrid">' . $n('location_ttl_minutes', 'Hide a location older than (min, 5–240)', 30, ' min="5" max="240"') . $n('stranger_fuzz_m', 'Blur strangers by (m, 100–5000)', 400, ' min="100" max="5000"') . '</div>';
    $se = g($S, 'season_ends_at'); $seT = $se && strtotime((string) $se) ? date('Y-m-d\TH:i', strtotime((string) $se)) : '';
    echo '<h3>Season</h3><div class="fgrid"><label>Season name<input name="season_name" maxlength="60" value="' . e(g($S, 'season_name', '')) . '"></label><label>Season ends<input name="season_ends_at" type="datetime-local" value="' . e($seT) . '"></label></div>';
    echo '<button class="btn" type="submit">Save settings</button></form>' . $RULES;
}

/* ================= Missions ================= */
elseif ($tab === 'missions') {
    $r = app_rank_missions(); $items = $r['ok'] ? (array) g($r['data'], 'items', (isset($r['data'][0]) ? $r['data'] : array())) : array();
    $E = null; if (isset($_GET['edit'])) { foreach ($items as $m) { if ((string) g($m, 'id') === (string) $_GET['edit']) { $E = $m; } } }
    if (!$r['ok']) { echo '<div class="flash err">' . e(app_rpc_error($r)) . '</div>'; }
    echo '<details class="panel" id="mform"' . ($E || isset($_GET['new']) ? ' open' : '') . '><summary class="btn">' . ($E ? 'Edit mission ' . e(g($E, 'code')) : '+ New mission') . '</summary><form method="post" style="margin-top:14px" data-confirm="Save this mission? Players see it in the app.">' . csrf_field()
        . '<input type="hidden" name="action" value="mission_save"><input type="hidden" name="mission_id" value="' . e(g($E, 'id', '')) . '"><div class="fgrid">'
        . '<label>Code<input name="code" required maxlength="40" value="' . e(g($E, 'code', '')) . '" placeholder="win_3_online"></label><label>Title<input name="title" required maxlength="80" value="' . e(g($E, 'title', '')) . '"></label>'
        . '<label>Kind<select name="kind">';
    foreach ($KINDS as $k) { echo '<option' . (g($E, 'kind') === $k ? ' selected' : '') . '>' . $k . '</option>'; }
    echo '</select></label><label>Target (1–100)<input name="target" type="number" min="1" max="100" value="' . e(g($E, 'target', 1)) . '"></label><label>Reward RP (0–500)<input name="reward_rp" type="number" min="0" max="500" value="' . e(g($E, 'reward_rp', 0)) . '"></label>'
        . '<label>Reward tickets (0–10)<input name="reward_tickets" type="number" min="0" max="10" value="' . e(g($E, 'reward_tickets', 0)) . '"></label><label>Sort<input name="sort" type="number" value="' . e(g($E, 'sort', 0)) . '"></label></div>'
        . '<label class="fl">Description<input name="description" maxlength="300" value="' . e(g($E, 'description', '')) . '"></label><label class="chk" style="margin:10px 0"><input type="checkbox" name="active" value="1"' . (!$E || g($E, 'active') ? ' checked' : '') . '> Active</label>'
        . '<button class="btn" type="submit">Save mission</button>' . ($E ? ' <a class="btn ghost" href="/admin/app/rank/?tab=missions">Cancel</a>' : '') . '</form></details>';
    echo '<div class="tbl-wrap"><table><tr><th>Sort</th><th>Code</th><th>Title</th><th>Kind</th><th>Target</th><th>Reward</th><th>Claims today</th><th>Active</th><th></th></tr>';
    foreach ($items as $m) {
        $mid = (string) g($m, 'id', '');
        echo '<tr' . (g($m, 'active') ? '' : ' class="muted"') . '><td>' . e(g($m, 'sort', 0)) . '</td><td><code class="j">' . e(g($m, 'code', '')) . '</code></td><td><b>' . e(g($m, 'title', '')) . '</b><div class="muted" style="font-size:12px">' . e(g($m, 'description', '')) . '</div></td>';
        echo '<td>' . e(g($m, 'kind', '')) . '</td><td>' . app_num(g($m, 'target')) . '</td><td class="nowrap">' . app_num(g($m, 'reward_rp', 0)) . ' RP' . ((int) g($m, 'reward_tickets', 0) ? ' + ' . app_num($m['reward_tickets']) . ' tickets' : '') . '</td><td>' . app_num(g($m, 'claims_today', 0)) . '</td><td>' . (g($m, 'active') ? 'yes' : 'no') . '</td>';
        echo '<td class="nowrap"><a class="btn ghost sm" href="/admin/app/rank/?tab=missions&amp;edit=' . e(rawurlencode($mid)) . '#mform">Edit</a> <form method="post" style="display:inline" data-confirm="Delete this mission? If it was ever claimed it is switched off instead.">' . csrf_field() . '<input type="hidden" name="action" value="mission_delete"><input type="hidden" name="mission_id" value="' . e($mid) . '"><input type="hidden" name="back" value="/admin/app/rank/?tab=missions"><button class="btn danger sm" type="submit">Delete</button></form></td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="9" class="muted">' . ($r['ok'] ? 'No missions yet.' : 'Could not load.') . '</td></tr>'; }
    echo '</table></div>';
}

/* ================= New season ================= */
else {
    $r = app_rank_overview();
    echo '<div class="danger-zone"><h2>Start a new season</h2><p>Everyone keeps only the chosen percentage of their RP. Every change is written to the RP ledger. <b>This cannot be undone.</b> Current season: <b>' . e(g($r['data'], 'settings.season_name', '-')) . '</b>.</p>';
    echo '<form method="post" class="fgrid" style="align-items:end" data-confirm="Start a NEW SEASON? Every player\'s RP is cut to the chosen percentage. This cannot be undone.">' . csrf_field() . '<input type="hidden" name="action" value="season_reset"><input type="hidden" name="back" value="/admin/app/rank/?tab=season">'
        . '<label>Keep % of RP (0–100)<input name="keep_percent" type="number" min="0" max="100" value="25" required></label><label>New season name<input name="season_name" required maxlength="60" placeholder="Season 2"></label>'
        . '<label>Type RESET to confirm<input name="confirm" required autocomplete="off" pattern="RESET"></label><button class="btn danger" type="submit" style="padding:16px 22px;font-size:15px">Start new season</button></form></div>';
}
echo '<script src="/admin/assets/app-rank.js?v=1" defer></script>';
admin_foot();
