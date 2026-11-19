<?php
/* App admin: Users list (/admin/app/users/) and User page (/admin/app/users/?id=<uuid>&tab=...). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$tabs = array('overview' => 'Overview', 'matches' => 'Matches', 'tournaments' => 'Tournaments', 'wallet' => 'Wallet', 'logins' => 'Logins',
              'chat' => 'Chat', 'moderation' => 'Reports & moderation', 'audit' => 'Audit');
$tab = isset($_GET['tab'], $tabs[$_GET['tab']]) ? (string) $_GET['tab'] : 'overview';
$reasons = array('iap_grant', 'ad_reward', 'daily_reward', 'match_reward', 'match_stake', 'tournament_entry', 'tournament_prize', 'tournament_refund', 'referral_bonus', 'admin_credit', 'admin_debit');

/* ================= actions ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $back = '/admin/app/users/?id=' . rawurlencode($uid) . '&tab=' . rawurlencode(isset($_POST['tab'], $tabs[$_POST['tab']]) ? $_POST['tab'] : 'overview');
    if (!uuid_ok($uid)) { flash_set('err', 'Bad user id.'); redirect('/admin/app/users/'); }

    if ($act === 'wallet') {
        $amount = (int) (isset($_POST['amount']) ? $_POST['amount'] : 0);
        $dir = (isset($_POST['direction']) && $_POST['direction'] === 'debit') ? 'debit' : 'credit';
        $note = app_post('note', 500);
        $ref = app_post('reference', 200);
        if ($amount <= 0 || $note === '') { flash_set('err', 'Amount (> 0) and note are required.'); redirect($back); }
        $r = app_wallet_adjust($uid, $amount, $dir, $note, $ref);
        if ($r['ok']) {
            $dup = !empty($r['data']['duplicate']);
            flash_set('ok', ($dup ? 'Already applied earlier with this reference (nothing changed). ' : ($dir === 'credit' ? 'Credited ' : 'Debited ') . number_format($amount) . ' coins. ')
                . 'New balance: ' . app_num(g($r['data'], 'balance_minor')) . ' coins.' . (!$dup && $dir === 'credit' ? ' The player was notified.' : ''));
        } else { app_flash_result($r, ''); }
    } elseif ($act === 'suspend') {
        $reason = app_post('reason', 500);
        $rep = isset($_POST['report_id']) && uuid_ok($_POST['report_id']) ? (string) $_POST['report_id'] : '';
        if ($reason === '') { flash_set('err', 'A reason is required.'); redirect($back); }
        app_flash_result(app_suspend_user($uid, $reason, $rep, app_post('notes', 2000)), 'Player suspended.');
    } elseif ($act === 'reinstate') {
        app_flash_result(app_reinstate_user($uid, app_post('notes', 2000)), 'Player reinstated.');
    } elseif ($act === 'rename') {
        $u = app_post('username', 40);
        if ($u === '') { flash_set('err', 'Enter a username.'); redirect($back); }
        app_flash_result(app_update_user_profile($uid, $u, false), 'Username changed to ' . $u . '.');
    } elseif ($act === 'clear_avatar') {
        app_flash_result(app_update_user_profile($uid, '', true), 'Profile photo removed.');
    } elseif ($act === 'notify') {
        $t = app_post('title', 80); $b = app_post('body', 500);
        if ($t === '' || $b === '') { flash_set('err', 'Title and message are required.'); redirect($back); }
        app_flash_result(app_send_notification($uid, $t, $b), 'Notification sent.');
    } elseif ($act === 'set_admin') {
        $on = !empty($_POST['value']);
        app_flash_result(app_set_admin($uid, $on), $on ? 'Player is now an app admin.' : 'Admin rights removed.');
    }
    redirect($back);
}

/* ---------- small renderers ---------- */
function tbl_generic($rows, $empty = 'Nothing here.') {
    if (!$rows) { return '<div class="tbl-wrap"><table><tr><td class="muted">' . e($empty) . '</td></tr></table></div>'; }
    $cols = array();
    foreach ($rows as $r) { if (is_array($r)) { foreach ($r as $k => $v) { if (!in_array($k, $cols, true)) { $cols[] = $k; } } } }
    $h = '<div class="tbl-wrap"><table><tr>';
    foreach ($cols as $c) { $h .= '<th>' . e(str_replace('_', ' ', $c)) . '</th>'; }
    $h .= '</tr>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as $c) {
            $v = is_array($r) && isset($r[$c]) ? $r[$c] : null;
            if (is_string($v) && preg_match('/(_at|_on)$/', $c)) { $h .= '<td class="nowrap">' . app_dt($v) . '</td>'; }
            elseif ($c === 'status' || $c === 'decision') { $h .= '<td>' . app_badge($v) . '</td>'; }
            else { $h .= '<td>' . app_text($v) . '</td>'; }
        }
        $h .= '</tr>';
    }
    return $h . '</table></div>';
}
function stat_card($n, $label, $sub = '', $cls = '') {
    return '<div class="card"><div class="n ' . $cls . '">' . $n . '</div><div class="l">' . e($label) . '</div>' . ($sub !== '' ? '<div class="s">' . $sub . '</div>' : '') . '</div>';
}
function hidden_user($uid, $tab, $act) {
    return csrf_field() . '<input type="hidden" name="user_id" value="' . e($uid) . '"><input type="hidden" name="tab" value="' . e($tab) . '"><input type="hidden" name="action" value="' . e($act) . '">';
}

/* ================= list ================= */
if ($id === '') {
    $q = app_get('q', 100);
    $st = app_get('status', 20);
    if (!in_array($st, array('active', 'suspended', 'under_review'), true)) { $st = ''; }
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 25;
    $r = app_list_users($q, $st, $per, ($page - 1) * $per);
    $items = app_items($r);
    $total = $r['ok'] ? (int) g($r['data'], 'total', 0) : null;

    app_head('App users', 'users');
    echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Username, email, player no or user id" style="min-width:280px"><select name="status"><option value="">All statuses</option>';
    foreach (array('active' => 'Active', 'suspended' => 'Suspended', 'under_review' => 'Under review') as $k => $v) { echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $v . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Search</button>' . ($q !== '' || $st !== '' ? '<a class="btn ghost sm" href="/admin/app/users/">Clear</a>' : '');
    if ($total !== null) { echo '<span class="muted">' . number_format($total) . ' players</span>'; }
    echo '</form>' . app_error_box($r);
    echo '<div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Email</th><th>Status</th><th>Verified</th><th>Balance</th><th>Joined</th><th>Last sign-in</th></tr>';
    foreach ($items as $u) {
        $uid = (string) g($u, 'id', '');
        $href = '/admin/app/users/?id=' . rawurlencode($uid);
        echo '<tr class="click" data-href="' . e($href) . '"><td><div class="ucell">' . app_avatar(g($u, 'avatar_url'), g($u, 'player_no')) . '<div><a href="' . e($href) . '"><b>' . e(g($u, 'username', '(no name)')) . '</b></a>'
            . (g($u, 'is_admin') ? ' <span class="badge b-pink">admin</span>' : '') . '</div></div></td>';
        echo '<td class="pno">#' . e(app_pno(g($u, 'player_no'))) . '</td><td>' . app_text(g($u, 'email')) . '</td><td>' . app_badge(g($u, 'account_status')) . '</td>';
        echo '<td>' . (g($u, 'email_verified_at') ? '<span class="pos">&#10003;</span>' : '<span class="muted">-</span>') . '</td><td>' . app_num(g($u, 'balance')) . '</td>';
        echo '<td class="nowrap">' . app_dt(g($u, 'created_at')) . '</td><td class="nowrap">' . app_dt(g($u, 'last_sign_in_at')) . '</td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="8" class="muted">' . ($r['ok'] ? 'No players found.' : 'Could not load players.') . '</td></tr>'; }
    echo '</table></div>' . ($total !== null ? pager($page, $total, $per) : app_pager_simple($page, count($items), $per));
    admin_foot(); exit;
}

/* ================= user page ================= */
if (!uuid_ok($id)) { flash_set('err', 'Bad user id.'); redirect('/admin/app/users/'); }

/* CSV export of transactions (before any output) */
if ($tab === 'wallet' && isset($_GET['export'])) {
    $reason = app_get('reason', 40);
    $rows = array();
    for ($off = 0; $off < 20000; $off += 500) {
        $tr = app_user_transactions($id, 500, $off, $reason);
        $chunk = app_items($tr);
        $rows = array_merge($rows, $chunk);
        if (count($chunk) < 500) { break; }
    }
    csv_out('drotix_transactions_' . substr($id, 0, 8) . ($reason !== '' ? '_' . $reason : '') . '.csv', $rows);
}

$ur = app_get_user($id);
$u = $ur['ok'] ? $ur['data'] : array();
$p = g($u, 'profile', array());
$name = (string) g($p, 'username', '(no name)');
$status = (string) g($p, 'account_status', '');
$isAdmin = (bool) g($p, 'is_admin', false);
$balance = g($u, 'wallet.balance_minor');
$me = app_me();
$isMe = $me['user_id'] !== '' && $me['user_id'] === $id;

app_head('Player', 'users');
echo '<div class="row"><a class="btn ghost sm" href="/admin/app/users/">&larr; All players</a></div>';
if (!$ur['ok']) { echo app_error_box($ur); admin_foot(); exit; }

/* ---------- header ---------- */
$providers = g($u, 'auth.providers', array());
$devices = g($u, 'devices', array());
$devTxt = array();
foreach ((array) $devices as $dv) { $devTxt[] = trim(g($dv, 'platform', '?') . ' ' . g($dv, 'locale', '')) . ' (' . app_dt(g($dv, 'updated_at')) . ')'; }
echo '<div class="panel"><div class="uhead">' . app_avatar(g($p, 'avatar_url'), g($p, 'player_no'), 84);
echo '<div class="who"><h2>' . e($name) . ' <span class="pno">#' . e(app_pno(g($p, 'player_no'))) . '</span> ' . app_badge($status) . ($isAdmin ? ' <span class="badge b-pink">app admin</span>' : '') . '</h2>';
if ($status === 'suspended' && g($p, 'suspension_reason')) { echo '<div class="muted">Suspended ' . app_dt(g($p, 'suspended_at')) . ': ' . e(g($p, 'suspension_reason')) . '</div>'; }
echo '<div class="muted" style="font-size:12px">User id: ' . e($id) . '</div></div>';
echo '<div class="meta">';
$email = g($u, 'auth.email');
echo '<div><span>Email:</span> ' . app_text($email) . ' ' . (g($u, 'auth.email_confirmed_at') || g($p, 'email_verified_at') ? '<span class="badge b-teal">verified</span>' : '<span class="badge b-grey">not verified</span>') . '</div>';
echo '<div><span>Phone:</span> ' . app_text(gx($u, array('profile.phone_e164', 'auth.phone'))) . '</div>';
echo '<div><span>Joined:</span> ' . app_dt(gx($u, array('profile.created_at', 'auth.created_at'))) . '</div>';
echo '<div><span>Last sign-in:</span> ' . app_dt(g($u, 'auth.last_sign_in_at')) . '</div>';
echo '<div><span>Login count:</span> ' . (g($u, 'auth.login_count') === null ? '<span class="muted">audit log off</span>' : app_num(g($u, 'auth.login_count'))) . '</div>';
echo '<div><span>Sign-in with:</span> ' . ($providers ? e(implode(', ', (array) $providers)) : '<span class="muted">-</span>') . '</div>';
echo '<div><span>Country:</span> ' . app_text(g($p, 'country_iso2')) . '</div>';
echo '<div><span>Referral code:</span> ' . app_text(g($p, 'referral_code')) . '</div>';
echo '<div><span>Banned until:</span> ' . (g($u, 'auth.banned_until') ? app_dt(g($u, 'auth.banned_until')) : '<span class="muted">-</span>') . '</div>';
echo '<div><span>Devices:</span> ' . ($devTxt ? e(implode(' · ', $devTxt)) : '<span class="muted">-</span>') . '</div>';
echo '</div></div>';

/* ---------- actions ---------- */
$h = function ($act) use ($id, $tab) { return hidden_user($id, $tab, $act); };
echo '<div class="actions">';
if ($status !== 'suspended') {
    echo '<details class="act"><summary class="btn danger sm">Suspend</summary><form class="pop" method="post" data-confirm="Suspend ' . e($name) . '? They will be signed out of the app.">' . $h('suspend')
        . '<label>Reason (shown to the player)<input name="reason" required maxlength="500"></label><label>Admin notes (private)<textarea name="notes" maxlength="2000"></textarea></label>'
        . '<label>Linked report id (optional)<input name="report_id" placeholder="uuid" pattern="[0-9a-fA-F-]{36}"></label><button class="btn danger sm" type="submit">Suspend player</button></form></details>';
} else {
    echo '<details class="act"><summary class="btn sm">Reinstate</summary><form class="pop" method="post" data-confirm="Lift the suspension of ' . e($name) . '? An open appeal will be approved.">' . $h('reinstate')
        . '<label>Notes (optional)<textarea name="notes" maxlength="2000"></textarea></label><button class="btn sm" type="submit">Reinstate player</button></form></details>';
}
echo '<details class="act"><summary class="btn ghost sm">Adjust coins</summary><form class="pop" method="post" data-wallet data-balance="' . e((int) $balance) . '" data-user="' . e($name) . '">' . $h('wallet')
    . '<div class="muted">Current balance: <b>' . app_num($balance) . '</b> coins</div>'
    . '<label>Direction<select name="direction"><option value="credit">Credit (add coins)</option><option value="debit">Debit (remove coins)</option></select></label>'
    . '<label>Amount (coins)<input name="amount" type="number" min="1" step="1" required></label><label>Note (required)<input name="note" required maxlength="500" placeholder="e.g. Refund for failed purchase"></label>'
    . '<label>Reference (optional, applied once)<input name="reference" maxlength="200" placeholder="Play order id / ticket no."></label><button class="btn sm" type="submit">Apply</button></form></details>';
echo '<details class="act"><summary class="btn ghost sm">Edit username</summary><form class="pop" method="post" data-confirm="Change the username of ' . e($name) . '?">' . $h('rename')
    . '<label>New username<input name="username" required maxlength="40" value="' . e($name) . '"></label><button class="btn sm" type="submit">Save</button></form></details>';
if (g($p, 'avatar_url')) {
    echo '<form method="post" data-confirm="Remove the profile photo of ' . e($name) . '?">' . $h('clear_avatar') . '<button class="btn ghost sm" type="submit">Remove photo</button></form>';
}
echo '<details class="act"><summary class="btn ghost sm">Send notification</summary><form class="pop" method="post" data-confirm="Send this notification to ' . e($name) . '?">' . $h('notify')
    . '<label>Title<input name="title" required maxlength="80"></label><label>Message<textarea name="body" required maxlength="500"></textarea></label><button class="btn sm" type="submit">Send (push + in-app)</button></form></details>';
if (!$isMe) {
    echo '<form method="post" data-confirm="' . ($isAdmin ? 'Remove app admin rights from ' : 'Make ') . e($name) . ($isAdmin ? '?' : ' an app admin? They will be able to use this panel.') . '">' . $h('set_admin')
        . '<input type="hidden" name="value" value="' . ($isAdmin ? '0' : '1') . '"><button class="btn ' . ($isAdmin ? 'danger' : 'ghost') . ' sm" type="submit">' . ($isAdmin ? 'Remove admin' : 'Make admin') . '</button></form>';
}
echo '</div></div>';

/* ---------- tabs ---------- */
echo '<nav class="tabs">';
foreach ($tabs as $k => $v) { echo '<a href="/admin/app/users/?id=' . e($id) . '&amp;tab=' . $k . '"' . ($k === $tab ? ' class="on"' : '') . '>' . e($v) . '</a>'; }
echo '</nav>';
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

if ($tab === 'overview') {
    $m = g($u, 'matches', array()); $t = g($u, 'tournaments', array()); $c = g($u, 'coins', array()); $s = g($u, 'social', array()); $mo = g($u, 'moderation', array());
    $delta = g($m, 'coin_delta');
    echo '<h2>Matches</h2><div class="cards sm">';
    echo stat_card(app_num(g($m, 'total')), 'Matches played', 'Last: ' . app_dt(g($m, 'last_played_at')));
    echo stat_card(app_num(g($m, 'wins')) . ' / ' . app_num(g($m, 'losses')) . ' / ' . app_num(g($m, 'draws')), 'Wins / losses / draws', '', 'teal');
    echo stat_card(app_num(g($m, 'online')) . ' / ' . app_num(g($m, 'vs_ai')), 'Online / vs AI');
    echo stat_card(($delta > 0 ? '+' : '') . app_num($delta), 'Coins won/lost in matches', '', $delta < 0 ? '' : 'teal');
    echo '</div><h2>Tournaments</h2><div class="cards sm">';
    echo stat_card(app_num(g($t, 'joined')), 'Joined') . stat_card(app_num(g($t, 'won')), 'Won', '', 'gold') . stat_card(app_num(g($t, 'prizes')), 'Prizes (coins)', '', 'teal') . stat_card(app_num(g($t, 'entry_paid')), 'Entry fees paid');
    echo '</div><h2>Coins</h2><div class="cards sm">';
    echo stat_card(app_num($balance), 'Balance', g($u, 'wallet.updated_at') ? 'Updated ' . app_dt(g($u, 'wallet.updated_at')) : 'No wallet yet', 'gold');
    echo stat_card(app_num(g($c, 'credited')), 'Total credited', '', 'teal') . stat_card(app_num(g($c, 'debited')), 'Total debited');
    echo stat_card(app_num(g($c, 'purchased')), 'Purchased (IAP)', '', 'teal') . stat_card(app_num(g($c, 'ad_rewards')), 'Ad rewards') . stat_card(app_num(g($c, 'daily_rewards')), 'Daily rewards');
    echo stat_card(app_num(g($c, 'admin_adjustments')), 'Admin adjustments') . stat_card(app_num(g($c, 'transactions')), 'Transactions');
    echo '</div><h2>Social &amp; moderation</h2><div class="cards sm">';
    echo stat_card(app_num(g($s, 'friends')), 'Friends') . stat_card(app_num(g($s, 'challenges_sent')) . ' / ' . app_num(g($s, 'challenges_received')), 'Challenges sent / received');
    echo stat_card(app_num(g($s, 'chat_messages')), 'Chat messages') . stat_card(app_num(g($s, 'referred_count')), 'Players referred', 'Referred by: ' . e(g($s, 'referred_by', '-')));
    echo stat_card(app_num(g($mo, 'reports_received')), 'Reports received', '', (int) g($mo, 'reports_received') > 0 ? '' : 'teal') . stat_card(app_num(g($mo, 'reports_made')), 'Reports made');
    echo stat_card(app_num(g($u, 'achievements')), 'Achievements') . stat_card(app_num(g($u, 'missions_claimed')), 'Missions claimed');
    echo '</div>';
    echo '<details style="margin-top:20px"><summary class="muted">Full profile (raw)</summary><pre>' . e(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
}

elseif ($tab === 'matches') {
    $per = 25;
    $r = app_user_matches($id, $per, ($page - 1) * $per);
    $items = app_items($r);
    echo app_error_box($r) . '<div class="tbl-wrap"><table><tr><th>Played</th><th>Mode</th><th>Result</th><th>Opponent</th><th>Board</th><th>Difficulty</th><th>Stake</th><th>Coins</th><th>Room</th></tr>';
    foreach ($items as $m) {
        $cd = g($m, 'coin_delta');
        echo '<tr><td class="nowrap">' . app_dt(g($m, 'played_at')) . '</td><td>' . e(str_replace('_', ' ', (string) g($m, 'mode', '-'))) . '</td><td>' . app_badge(g($m, 'result')) . '</td>';
        echo '<td>' . app_text(gx($m, array('opponent_username', 'opponent_name'))) . '</td><td>' . app_text(g($m, 'board_size') ? g($m, 'board_size') . '×' . g($m, 'board_size') : null) . '</td>';
        echo '<td>' . app_text(g($m, 'difficulty')) . '</td><td>' . app_num(g($m, 'stake')) . '</td><td class="' . ($cd > 0 ? 'pos' : ($cd < 0 ? 'neg' : '')) . '">' . ($cd > 0 ? '+' : '') . app_num($cd) . '</td>';
        echo '<td><code class="j">' . e(substr((string) g($m, 'room_id', ''), 0, 8)) . '</code></td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="9" class="muted">' . ($r['ok'] ? 'No matches.' : 'Could not load matches.') . '</td></tr>'; }
    echo '</table></div>' . app_pager_simple($page, count($items), $per);
}

elseif ($tab === 'tournaments') {
    $r = app_user_tournaments($id);
    $items = app_items($r);
    echo app_error_box($r) . '<div class="tbl-wrap"><table><tr><th>Tournament</th><th>Status</th><th>Starts</th><th>Entry fee</th><th>Bracket</th><th>Player status</th><th>Seed</th><th>Rounds won</th><th>Out in round</th><th>Place</th><th>Prize</th><th>Paid</th><th>Joined</th></tr>';
    foreach ($items as $t) {
        echo '<tr><td><b>' . e(g($t, 'name', '-')) . '</b></td><td>' . app_badge(g($t, 'tournament_status')) . '</td><td class="nowrap">' . app_dt(g($t, 'starts_at')) . '</td><td>' . app_num(g($t, 'entry_fee_minor')) . '</td>';
        echo '<td>' . app_num(g($t, 'bracket_size')) . '</td><td>' . app_badge(g($t, 'my_status')) . '</td><td>' . app_num(g($t, 'seed')) . '</td><td>' . app_num(g($t, 'rounds_won')) . '</td><td>' . app_num(g($t, 'eliminated_round')) . '</td>';
        echo '<td>' . app_num(g($t, 'final_place')) . '</td><td class="pos">' . app_num(g($t, 'prize_minor')) . '</td><td>' . app_num(g($t, 'paid_minor')) . '</td><td class="nowrap">' . app_dt(g($t, 'joined_at')) . '</td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="13" class="muted">' . ($r['ok'] ? 'No tournaments joined.' : 'Could not load tournaments.') . '</td></tr>'; }
    echo '</table></div>';
}

elseif ($tab === 'wallet') {
    $reason = app_get('reason', 40);
    $per = 50;
    $r = app_user_transactions($id, $per, ($page - 1) * $per, $reason);
    $items = app_items($r);
    $total = $r['ok'] && g($r['data'], 'total') !== null ? (int) g($r['data'], 'total') : null;
    echo '<div class="cards sm">' . stat_card(app_num($balance), 'Balance (coins)', e(g($u, 'wallet.currency_code', '')), 'gold') . stat_card($total === null ? '-' : app_num($total), 'Transactions' . ($reason !== '' ? ' (' . $reason . ')' : '')) . '</div>';
    echo '<form class="row" method="get"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="tab" value="wallet"><select name="reason"><option value="">All reasons</option>';
    foreach ($reasons as $rs) { echo '<option' . ($reason === $rs ? ' selected' : '') . '>' . e($rs) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1, 'page' => null))) . '">Export CSV</a></form>';
    echo app_error_box($r) . '<div class="tbl-wrap"><table><tr><th>When</th><th>Amount</th><th>Reason</th><th>Reference</th><th>Balance after</th><th>Idempotency key</th></tr>';
    foreach ($items as $t) {
        $cr = g($t, 'direction') === 'credit';
        echo '<tr><td class="nowrap">' . app_dt(g($t, 'created_at')) . '</td><td class="' . ($cr ? 'pos' : 'neg') . ' nowrap">' . ($cr ? '+' : '&minus;') . app_num(g($t, 'amount_minor')) . '</td>';
        echo '<td>' . e(str_replace('_', ' ', (string) g($t, 'reason', '-'))) . '</td><td>' . app_text(trim(g($t, 'ref_type', '') . ' ' . g($t, 'ref_id', ''))) . '</td>';
        echo '<td>' . app_num(g($t, 'balance_after')) . '</td><td><code class="j">' . e(g($t, 'idempotency_key', '')) . '</code></td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="6" class="muted">' . ($r['ok'] ? 'No transactions.' : 'Could not load transactions.') . '</td></tr>'; }
    echo '</table></div>' . ($total !== null ? pager($page, $total, $per) : app_pager_simple($page, count($items), $per));
}

elseif ($tab === 'logins') {
    $r = app_user_logins($id, 100);
    $items = app_items($r);
    echo app_error_box($r);
    if ($r['ok']) { echo '<p>Last sign-in: <b>' . app_dt(g($r['data'], 'last_sign_in_at')) . '</b></p>'; }
    if ($r['ok'] && !$items) {
        echo '<div class="warn">Audit log disabled: Supabase Auth audit logs are off, so the login history is not available. Only the last sign-in time is known.</div>';
    } else {
        echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Action</th><th>IP address</th></tr>';
        foreach ($items as $l) { echo '<tr><td class="nowrap">' . app_dt(g($l, 'created_at')) . '</td><td>' . app_text(g($l, 'action')) . '</td><td>' . app_text(g($l, 'ip_address')) . '</td></tr>'; }
        echo '</table></div>';
    }
}

elseif ($tab === 'chat') {
    $r = app_user_chat($id, 200);
    $items = app_items($r);
    echo app_error_box($r) . '<p class="muted">Read-only. Latest 200 messages sent by this player.</p><div class="tbl-wrap"><table><tr><th>When</th><th>Room</th><th>Message</th></tr>';
    foreach ($items as $c) { echo '<tr><td class="nowrap">' . app_dt(g($c, 'created_at')) . '</td><td><code class="j">' . e(substr((string) g($c, 'room_id', ''), 0, 8)) . '</code></td><td class="chat">' . e(g($c, 'message', '')) . '</td></tr>'; }
    if (!$items) { echo '<tr><td colspan="3" class="muted">' . ($r['ok'] ? 'No chat messages.' : 'Could not load chat.') . '</td></tr>'; }
    echo '</table></div>';
}

elseif ($tab === 'moderation') {
    $r = app_user_reports($id);
    echo app_error_box($r);
    $rep = function ($rows, $otherKey, $otherIdKeys, $title) {
        echo '<h2>' . e($title) . ' (' . count($rows) . ')</h2><div class="tbl-wrap"><table><tr><th>When</th><th>' . ($otherKey === 'reporter_username' ? 'Reported by' : 'Reported player') . '</th><th>Reason</th><th>Details</th><th>Status</th><th>Screenshot</th><th>Admin notes</th></tr>';
        foreach ($rows as $x) {
            $shot = g($x, 'screenshot_path');
            echo '<tr><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td>' . app_user_link(gx($x, $otherIdKeys), g($x, $otherKey)) . '</td><td>' . app_text(gx($x, array('reason', 'category'))) . '</td>';
            echo '<td class="chat">' . app_text(gx($x, array('details', 'description', 'message'))) . '</td><td>' . app_badge(g($x, 'status')) . '</td>';
            echo '<td>' . ($shot ? '<a href="/admin/app/evidence/?path=' . e(rawurlencode($shot)) . '" target="_blank" rel="noopener">Open</a>' : '<span class="muted">-</span>') . '</td><td>' . app_text(g($x, 'admin_notes')) . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="7" class="muted">None.</td></tr>'; }
        echo '</table></div>';
    };
    $rep(app_items($r, 'received'), 'reporter_username', array('reporter_id', 'reporter_user_id'), 'Reports received');
    $rep(app_items($r, 'made'), 'reported_username', array('reported_id', 'reported_user_id'), 'Reports made');
    echo '<h2>Suspensions</h2>' . tbl_generic((array) g($u, 'moderation.suspensions', array()), 'No suspensions.');
    echo '<h2>Appeals</h2>' . tbl_generic((array) g($u, 'moderation.appeals', array()), 'No appeals.');
}

elseif ($tab === 'audit') {
    $per = 50;
    $r = app_list_audit($id, $per, ($page - 1) * $per);
    $items = app_items($r);
    echo app_error_box($r) . '<div class="tbl-wrap"><table><tr><th>When</th><th>Action</th><th>Admin</th><th>Target</th><th>Details</th></tr>';
    foreach ($items as $a) { echo '<tr><td class="nowrap">' . app_dt(g($a, 'created_at')) . '</td><td>' . app_badge(g($a, 'action')) . '</td><td>' . app_text(g($a, 'admin_username')) . '</td><td>' . app_text(g($a, 'target_username')) . '</td><td>' . app_text(g($a, 'details')) . '</td></tr>'; }
    if (!$items) { echo '<tr><td colspan="5" class="muted">' . ($r['ok'] ? 'No admin actions on this player.' : 'Could not load the audit log.') . '</td></tr>'; }
    echo '</table></div>' . app_pager_simple($page, count($items), $per);
}
admin_foot();
