<?php
/* Mobile App: coupons (one code per account, entered right after sign-up; coupon coins, else +50 referral).
   List / create / edit / on-off (game functions) and per-coupon tracking: who joined with it and how much
   money those players have spent on coins since (read from the game's purchase table). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$states = array('live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'used_up' => 'Used up', 'disabled' => 'Disabled');
function coupon_badge($st) {
    $c = array('live' => 'b-green', 'scheduled' => 'b-blue', 'expired' => 'b-grey', 'used_up' => 'b-orange', 'disabled' => 'b-red');
    $l = array('live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'used_up' => 'Used up', 'disabled' => 'Disabled');
    return '<span class="badge ' . (isset($c[$st]) ? $c[$st] : 'b-grey') . '">' . e(isset($l[$st]) ? $l[$st] : ($st ?: '-')) . '</span>';
}
function valid_range($c) {
    $a = g($c, 'starts_at') ? app_dt($c['starts_at']) : '&mdash;';
    $b = g($c, 'expires_at') ? app_dt($c['expires_at']) : '&mdash;';
    return '<span class="nowrap">' . $a . ' &rarr; ' . $b . '</span>';
}
function used_cell($c) {
    $u = (int) g($c, 'used_count', 0); $m = g($c, 'max_uses');
    $pct = $m ? min(100, round($u * 100 / max(1, (int) $m))) : 0;
    return '<div class="nowrap">' . number_format($u) . ' / ' . ($m ? number_format((int) $m) : '&infin;') . '</div>' . ($m ? '<div class="bar sm"><i style="width:' . $pct . '%"></i></div>' : '');
}
function to_local_input($iso) { $t = strtotime((string) $iso); return $t ? date('Y-m-d\TH:i', $t) : ''; }

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($act === 'toggle') {
        $code = strtoupper(app_post('code', 20)); $on = !empty($_POST['active']);
        $r = app_set_coupon_active($code, $on);
        if ($r['ok']) { app_log($on ? 'enable_coupon' : 'disable_coupon', $code); flash_set('ok', 'Coupon ' . $code . ($on ? ' enabled.' : ' disabled.')); } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); }
        redirect(!empty($_POST['back']) && strpos((string) $_POST['back'], '/admin/app/coupons/') === 0 ? (string) $_POST['back'] : '/admin/app/coupons/');
    }
    if ($act === 'save') {
        $editing = !empty($_POST['editing']);
        $code = strtoupper(preg_replace('/\s+/', '', app_post('code', 30)));
        $coins = (int) (isset($_POST['coins']) ? $_POST['coins'] : 0);
        $unl = !empty($_POST['unlimited']);
        $max = $unl ? null : (int) (isset($_POST['max_uses']) ? $_POST['max_uses'] : 0);
        $st = trim((string) (isset($_POST['starts_at']) ? $_POST['starts_at'] : '')); $ex = trim((string) (isset($_POST['expires_at']) ? $_POST['expires_at'] : ''));
        $stIso = $st !== '' && strtotime($st) ? date('c', strtotime($st)) : null; $exIso = $ex !== '' && strtotime($ex) ? date('c', strtotime($ex)) : null;
        $err = '';
        if (!preg_match('/^[A-Z0-9_]{3,20}$/', $code)) { $err = 'Code must be 3–20 characters: A–Z, 0–9 or _.'; }
        elseif ($coins < 1 || $coins > 100000) { $err = 'Coins must be between 1 and 100,000.'; }
        elseif (!$unl && $max < 1) { $err = 'Max uses must be 1 or more (or tick Unlimited).'; }
        elseif ($stIso && $exIso && strtotime($stIso) >= strtotime($exIso)) { $err = 'The start must be before the expiry.'; }
        $r = $err === '' ? app_save_coupon($code, $coins, $max, $stIso, $exIso, app_post('note', 500), !empty($_POST['active'])) : null;
        if ($r && $r['ok']) {
            unset($_SESSION['coupon_draft']);
            $created = !empty($r['data']['created']);
            app_log($created ? 'create_coupon' : 'edit_coupon', $code, array('coins' => $coins, 'max_uses' => $max));
            flash_set('ok', 'Coupon ' . $code . ($created ? ' created.' : ' saved.') . (!$created ? ' New coin amount applies to players who redeem from now on.' : ''));
            redirect('/admin/app/coupons/?code=' . rawurlencode($code));
        }
        $_SESSION['coupon_draft'] = $_POST + array('error' => $err !== '' ? $err : app_rpc_error($r));
        redirect('/admin/app/coupons/?' . ($editing ? 'edit=' . rawurlencode($code) : 'new=1') . '&draft=1');
    }
    redirect('/admin/app/coupons/');
}

$lr = app_list_coupons(app_get('q', 60));
$items = $lr['ok'] ? (array) g($lr['data'], 'items', array()) : array();
$byCode = array(); foreach ($items as $c) { $byCode[(string) g($c, 'code')] = $c; }

/* ================= coupon detail + tracking ================= */
$code = isset($_GET['code']) ? strtoupper((string) $_GET['code']) : '';
if ($code !== '') {
    app_head('Coupon ' . $code, 'coupons');
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/coupons/">&larr; All coupons</a><a class="btn ghost sm" href="/admin/app/coupons/?edit=' . e(rawurlencode($code)) . '">Edit</a></div>';
    if (!$lr['ok']) { echo '<div class="flash err">' . e(app_rpc_error($lr)) . '</div>'; admin_foot(); exit; }
    if (!isset($byCode[$code])) { $lr2 = app_list_coupons($code); foreach ((array) g($lr2['data'], 'items', array()) as $c) { if (g($c, 'code') === $code) { $byCode[$code] = $c; } } }
    if (!isset($byCode[$code])) { echo '<div class="flash err">Coupon not found.</div>'; admin_foot(); exit; }
    $C = $byCode[$code];

    // all redemptions (for totals) + this page
    $all = array(); $rErr = null;
    for ($o = 0; $o < 20000; $o += 500) {
        $rr = app_coupon_redemptions($code, 500, $o);
        if (!$rr['ok']) { $rErr = $rr; break; }
        $chunk = (array) g($rr['data'], 'items', array()); $all = array_merge($all, $chunk);
        if (count($chunk) < 500) { break; }
    }
    $uids = array(); foreach ($all as $x) { $uids[] = g($x, 'user_id'); }
    $ps = app_purchase_stats($uids);
    $T = $ps['totals']; $cur = $T['currency'] ?: 'INR';
    $hasMoney = $ps['source'] && $ps['source']['money'];

    echo '<div class="panel"><div class="uhead"><div class="who"><h2><span class="code">' . e($code) . '</span> <button type="button" class="btn ghost sm" data-copy="' . e($code) . '">Copy</button> ' . coupon_badge(g($C, 'state')) . '</h2>' . (g($C, 'note') ? '<div class="muted">' . e($C['note']) . '</div>' : '') . '</div><div class="meta">';
    echo '<div><span>Coins per player:</span> <b>' . app_num(g($C, 'coins')) . '</b></div><div><span>Used:</span> ' . used_cell($C) . '</div>';
    echo '<div><span>Valid:</span> ' . valid_range($C) . '</div><div><span>Active switch:</span> ' . (g($C, 'active') ? 'on' : 'off') . '</div>';
    echo '<div><span>Created:</span> ' . app_dt(g($C, 'created_at')) . '</div><div><span>Updated:</span> ' . app_dt(g($C, 'updated_at')) . '</div></div></div>';
    $h = csrf_field() . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="code" value="' . e($code) . '"><input type="hidden" name="back" value="/admin/app/coupons/?code=' . e(rawurlencode($code)) . '">';
    echo '<form method="post" style="margin-top:14px" data-confirm="' . (g($C, 'active') ? 'Disable' : 'Enable') . ' coupon ' . e($code) . '?">' . $h . '<input type="hidden" name="active" value="' . (g($C, 'active') ? '' : '1') . '"><button class="btn ' . (g($C, 'active') ? 'danger' : '') . ' sm" type="submit">' . (g($C, 'active') ? 'Disable coupon' : 'Enable coupon') . '</button></form></div>';

    echo '<h2>Tracking</h2><div class="cards sm">';
    echo '<div class="card"><div class="n">' . app_num(count($all)) . '</div><div class="l">Players joined with this code</div></div>';
    echo '<div class="card"><div class="n gold">' . app_num(array_sum(array_map(function ($x) { return (int) g($x, 'coins', 0); }, $all))) . '</div><div class="l">Coupon coins given</div></div>';
    echo '<div class="card"><div class="n teal">' . app_num($T['buyers']) . '</div><div class="l">Of them bought coins</div><div class="s">' . (count($all) ? round($T['buyers'] * 100 / count($all)) : 0) . '% conversion</div></div>';
    if ($hasMoney) { echo '<div class="card hot"><div class="n">' . e(app_money($T['money'], $cur)) . '</div><div class="l">Money spent on coins</div><div class="s">' . app_num($T['count']) . ' purchases</div></div>'; }
    echo '<div class="card"><div class="n">' . app_num($T['coins']) . '</div><div class="l">Coins bought</div></div></div>';
    if ($ps['note'] !== '') { echo '<p class="muted">' . e($ps['note']) . '</p>'; }
    if ($rErr) { echo '<div class="flash err">' . e(app_rpc_error($rErr)) . '</div>'; }

    // one player's purchase history
    $hu = isset($_GET['user']) ? (string) $_GET['user'] : '';
    if (uuid_ok($hu)) {
        list($src, $rows) = app_purchase_history($hu);
        $nm = $hu; foreach ($all as $x) { if (g($x, 'user_id') === $hu) { $nm = (string) g($x, 'username', $hu); } }
        echo '<h2>Purchase history: ' . app_user_link($hu, $nm) . ' <a class="muted" style="font-size:13px" href="/admin/app/coupons/?code=' . e(rawurlencode($code)) . '">close</a></h2><div class="tbl-wrap"><table><tr><th>When</th><th>Product</th><th>Coins</th><th>Paid</th><th>Status</th></tr>';
        foreach ($rows as $p) {
            $ok = app_purchase_ok($p, $src);
            echo '<tr' . ($ok ? '' : ' class="muted"') . '><td class="nowrap">' . app_dt($src ? g($p, $src['when'] ?: 'created_at') : g($p, 'created_at')) . '</td><td>' . app_cell('p', $src && $src['product'] ? g($p, $src['product']) : g($p, 'reason')) . '</td>';
            echo '<td>' . app_num($src ? ($src['coins'] ? g($p, $src['coins']) : null) : g($p, 'amount_minor')) . '</td><td>' . ($src && $src['money'] ? e(app_money(app_money_value($src['money'], g($p, $src['money'])), $src['currency'] ? g($p, $src['currency'], 'INR') : 'INR')) : '<span class="muted">-</span>') . '</td>';
            echo '<td>' . app_badge($src && $src['status'] ? g($p, $src['status']) : 'completed') . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5" class="muted">No purchases yet.</td></tr>'; }
        echo '</table></div>';
    }

    // redemptions (paginated) with each player's spend
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
    $pageRows = array_slice($all, ($page - 1) * $per, $per);
    echo '<h2>Redemptions (' . app_num(count($all)) . ')</h2><div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Coupon coins</th><th>Redeemed</th><th>Purchases</th><th>Coins bought</th>' . ($hasMoney ? '<th>Money spent</th>' : '') . '<th>Last purchase</th><th></th></tr>';
    foreach ($pageRows as $x) {
        $u = (string) g($x, 'user_id', ''); $b = isset($ps['by'][$u]) ? $ps['by'][$u] : null;
        echo '<tr' . ($b ? ' class="win"' : '') . '><td><b>' . app_user_link($u, g($x, 'username')) . '</b></td><td class="pno">#' . e(app_pno(g($x, 'player_no'))) . '</td><td>' . app_num(g($x, 'coins')) . '</td><td class="nowrap">' . app_dt(g($x, 'redeemed_at')) . '</td>';
        echo '<td>' . app_num($b ? $b['count'] : 0) . '</td><td>' . app_num($b ? $b['coins'] : 0) . '</td>' . ($hasMoney ? '<td class="pos">' . ($b ? e(app_money($b['money'], $b['currency'] ?: $cur)) : '<span class="muted">-</span>') . '</td>' : '');
        echo '<td class="nowrap">' . ($b ? app_dt($b['last_at']) : '<span class="muted">-</span>') . '</td><td>' . (uuid_ok($u) ? '<a class="btn ghost sm" href="/admin/app/coupons/?code=' . e(rawurlencode($code)) . '&amp;user=' . e($u) . '&amp;page=' . $page . '">History</a>' : '') . '</td></tr>';
    }
    if (!$pageRows) { echo '<tr><td colspan="9" class="muted">Nobody has used this code yet.</td></tr>'; }
    echo '</table></div>' . pager($page, count($all), $per);
    admin_foot(); exit;
}

/* ================= list ================= */
$fs = app_get('state', 20);
$shown = $fs !== '' && isset($states[$fs]) ? array_values(array_filter($items, function ($c) use ($fs) { return g($c, 'state') === $fs; })) : $items;
$live = 0; $redeemed = 0; foreach ($items as $c) { if (g($c, 'state') === 'live') { $live++; } $redeemed += (int) g($c, 'used_count', 0); }

app_head('Coupons', 'coupons');
echo '<p class="muted" style="margin-top:-8px">After sign-up a player can enter ONE code: a coupon gives its coins, any other code counts as a friend\'s referral (+50). One code per account.</p>';
echo '<div class="cards sm"><div class="card"><div class="n teal">' . ($lr['ok'] ? app_num($live) : '?') . '</div><div class="l">Live coupons</div></div>';
echo '<div class="card"><div class="n">' . ($lr['ok'] ? app_num($redeemed) : '?') . '</div><div class="l">Total redemptions</div></div>';
echo '<div class="card"><div class="n gold">' . ($lr['ok'] ? app_num(g($lr['data'], 'total_coins_given', 0)) : '?') . '</div><div class="l">Coins given</div></div></div>';

/* create / edit form */
$editCode = isset($_GET['edit']) ? strtoupper((string) $_GET['edit']) : '';
$E = $editCode !== '' && isset($byCode[$editCode]) ? $byCode[$editCode] : null;
$D = isset($_GET['draft']) && !empty($_SESSION['coupon_draft']) ? $_SESSION['coupon_draft'] : null;
$f = array('code' => $E ? $E['code'] : '', 'coins' => $E ? (string) g($E, 'coins') : '100', 'unlimited' => $E ? g($E, 'max_uses') === null : true, 'max_uses' => $E ? (string) g($E, 'max_uses', '') : '',
           'starts_at' => $E ? to_local_input(g($E, 'starts_at')) : '', 'expires_at' => $E ? to_local_input(g($E, 'expires_at')) : '', 'note' => $E ? (string) g($E, 'note', '') : '', 'active' => $E ? (bool) g($E, 'active') : true);
if ($D) { foreach (array('code', 'coins', 'max_uses', 'starts_at', 'expires_at', 'note') as $k) { $f[$k] = isset($D[$k]) ? (string) $D[$k] : ''; } $f['unlimited'] = !empty($D['unlimited']); $f['active'] = !empty($D['active']); }
$editing = $E !== null || ($D && !empty($D['editing']));
echo '<details class="panel" id="couponForm"' . ($editing || isset($_GET['new']) ? ' open' : '') . '><summary class="btn">' . ($editing ? 'Edit coupon ' . e($f['code']) : '+ Create coupon') . '</summary>';
echo '<form method="post" style="margin-top:16px" data-coupon data-confirm="' . ($editing ? 'Save the changes to ' . e($f['code']) . '? A new coin amount only applies to players who redeem from now on.' : 'Create this coupon? Players can use it right away (if live).') . '">' . csrf_field() . '<input type="hidden" name="action" value="save">' . ($editing ? '<input type="hidden" name="editing" value="1">' : '');
echo '<div class="fgrid"><label>Code<div class="row" style="margin:0;gap:6px;flex-wrap:nowrap"><input name="code" id="cpCode" required maxlength="20" pattern="[A-Za-z0-9_]{3,20}" class="code" value="' . e($f['code']) . '"' . ($editing ? ' readonly' : '') . ' placeholder="WELCOME100">' . ($editing ? '' : '<button type="button" class="btn ghost sm" id="cpGen">Generate</button>') . '</div><span class="muted" style="font-weight:400">3–20 characters: A–Z, 0–9, _</span></label>';
echo '<label>Coins per player<input name="coins" type="number" min="1" max="100000" step="1" required value="' . e($f['coins']) . '"></label>';
echo '<label>Max uses<div class="row" style="margin:0;gap:10px;flex-wrap:nowrap"><label class="chk"><input type="checkbox" name="unlimited" id="cpUnl" value="1"' . ($f['unlimited'] ? ' checked' : '') . '> Unlimited</label><input name="max_uses" id="cpMax" type="number" min="1" step="1" value="' . e($f['max_uses']) . '" placeholder="e.g. 500"></div></label>';
echo '<label>Starts (optional)<input name="starts_at" type="datetime-local" value="' . e($f['starts_at']) . '"></label>';
echo '<label>Expires (optional)<input name="expires_at" type="datetime-local" value="' . e($f['expires_at']) . '"></label>';
echo '<label>Note (only admins see it)<input name="note" maxlength="500" value="' . e($f['note']) . '" placeholder="e.g. Instagram campaign Nov"></label></div>';
echo '<label class="chk" style="margin-bottom:12px"><input type="checkbox" name="active" value="1"' . ($f['active'] ? ' checked' : '') . '> Active</label>';
if ($D && !empty($D['error'])) { echo '<div class="flash err" style="margin:0 0 12px">' . e($D['error']) . '</div>'; }
echo '<div class="row" style="margin:0"><button class="btn" type="submit">' . ($editing ? 'Save coupon' : 'Create coupon') . '</button>' . ($editing ? '<a class="btn ghost" href="/admin/app/coupons/">Cancel</a>' : '') . '<span class="muted">Times are in ' . e(date_default_timezone_get()) . '</span></div></form></details>';

echo '<form class="row" method="get"><input name="q" value="' . e(app_get('q', 60)) . '" placeholder="Search code or note"><select name="state"><option value="">All</option>';
foreach ($states as $k => $v) { echo '<option value="' . $k . '"' . ($fs === $k ? ' selected' : '') . '>' . e($v) . '</option>'; }
echo '</select><button class="btn sm" type="submit">Search</button>' . (app_get('q', 60) !== '' || $fs !== '' ? '<a class="btn ghost sm" href="/admin/app/coupons/">Clear</a>' : '') . '<span class="muted">' . count($shown) . ' coupons</span></form>';
if (!$lr['ok']) { echo '<div class="flash err">Could not load coupons: ' . e(app_rpc_error($lr)) . '</div>'; }

echo '<div class="tbl-wrap"><table><tr><th>Code</th><th>Coins</th><th>Used</th><th>Valid</th><th>State</th><th>Note</th><th>On/Off</th><th></th></tr>';
$back = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/admin/app/coupons/';
foreach ($shown as $c) {
    $cd = (string) g($c, 'code', ''); $href = '/admin/app/coupons/?code=' . rawurlencode($cd); $on = (bool) g($c, 'active');
    echo '<tr class="click" data-href="' . e($href) . '"><td class="nowrap"><a class="code" href="' . e($href) . '">' . e($cd) . '</a> <button type="button" class="btn ghost sm" data-copy="' . e($cd) . '" title="Copy">&#10697;</button></td><td><b>' . app_num(g($c, 'coins')) . '</b></td><td style="min-width:110px">' . used_cell($c) . '</td>';
    echo '<td>' . valid_range($c) . '</td><td>' . coupon_badge(g($c, 'state')) . '</td><td class="chat">' . app_cell('note', g($c, 'note')) . '</td>';
    echo '<td><form method="post" data-confirm="' . ($on ? 'Disable' : 'Enable') . ' coupon ' . e($cd) . '?">' . csrf_field() . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="code" value="' . e($cd) . '"><input type="hidden" name="back" value="' . e($back) . '"><input type="hidden" name="active" value="' . ($on ? '' : '1') . '">'
        . '<button type="submit" class="switch' . ($on ? ' on' : '') . '" title="' . ($on ? 'On: click to disable' : 'Off: click to enable') . '"><i></i></button></form></td>';
    echo '<td><a class="btn ghost sm" href="/admin/app/coupons/?edit=' . e(rawurlencode($cd)) . '#couponForm">Edit</a></td></tr>';
}
if (!$shown) { echo '<tr><td colspan="8" class="muted">' . ($lr['ok'] ? 'No coupons' . ($fs !== '' || app_get('q', 60) !== '' ? ' match.' : ' yet. Create the first one above.') : 'Could not load.') . '</td></tr>'; }
echo '</table></div>';
echo '<script src="/admin/assets/app-coupons.js?v=1" defer></script>';
admin_foot();
