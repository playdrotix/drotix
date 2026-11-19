<?php
/* Mobile App: coin packs in the Shop of EVERY player. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

function pack_from_row($k) {
    return array('id' => (string) g($k, 'id', ''), 'product_id' => (string) g($k, 'product_id', ''), 'title' => (string) g($k, 'title', ''), 'coins' => (int) g($k, 'coins', 0),
                 'price_inr' => (float) g($k, 'price_inr', 0), 'bonus_coins' => (int) g($k, 'bonus_coins', 0), 'badge' => (string) g($k, 'badge', ''), 'active' => (bool) g($k, 'active', true), 'sort_order' => (int) g($k, 'sort_order', 0));
}
function pack_row_html($k) {
    $coins = (int) g($k, 'coins', 0); $bonus = (int) g($k, 'bonus_coins', 0);
    return '<div class="pk"><div class="pk-coin">&#129689;</div><div class="pk-main"><b>' . number_format($coins) . ' coins' . ($bonus ? ' <span class="pk-bonus">+ ' . number_format($bonus) . ' bonus</span>' : '') . '</b><span>' . e(g($k, 'title', '')) . '</span></div>'
        . (g($k, 'badge') ? '<span class="pk-badge">' . e($k['badge']) . '</span>' : '') . '<span class="pk-price">' . e(app_inr(g($k, 'price_inr', 0))) . '</span></div>';
}

$lr = app_list_coin_packages();
$items = $lr['ok'] ? (array) g($lr['data'], 'items', array()) : array();
$byId = array(); foreach ($items as $k) { $byId[(string) g($k, 'id')] = $k; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    if ($act === 'save') {
        $k = array('id' => uuid_ok($id) ? $id : '', 'product_id' => strtolower(app_post('product_id', 100)), 'title' => app_post('title', 80), 'coins' => (int) (isset($_POST['coins']) ? $_POST['coins'] : 0),
                   'price_inr' => round((float) str_replace(',', '', isset($_POST['price_inr']) ? (string) $_POST['price_inr'] : '0'), 2), 'bonus_coins' => (int) (isset($_POST['bonus_coins']) ? $_POST['bonus_coins'] : 0),
                   'badge' => app_post('badge', 30), 'active' => !empty($_POST['active']), 'sort_order' => (int) (isset($_POST['sort_order']) ? $_POST['sort_order'] : 0));
        $err = '';
        if (!preg_match('/^[a-z0-9_.]{3,100}$/', $k['product_id'])) { $err = 'Product id: 3–100 characters, lowercase a–z, 0–9, _ or . (same as in Google Play).'; }
        elseif ($k['title'] === '') { $err = 'Enter a title.'; }
        elseif ($k['coins'] < 1 || $k['coins'] > 10000000) { $err = 'Coins must be between 1 and 10,000,000.'; }
        elseif ($k['price_inr'] < 0.01 || $k['price_inr'] > 100000) { $err = 'Price must be between ₹0.01 and ₹1,00,000.'; }
        elseif ($k['bonus_coins'] < 0) { $err = 'Bonus coins cannot be negative.'; }
        else { foreach ($items as $o) { if (strtolower((string) g($o, 'product_id')) === $k['product_id'] && (string) g($o, 'id') !== $k['id']) { $err = 'Another pack already uses this product id.'; } } }
        $r = $err === '' ? app_save_coin_package($k) : null;
        if ($r && $r['ok']) {
            unset($_SESSION['pack_draft']);
            app_log($k['id'] ? 'edit_coin_pack' : 'create_coin_pack', $k['product_id'], array('coins' => $k['coins'], 'bonus' => $k['bonus_coins'], 'price_inr' => $k['price_inr']));
            flash_set('ok', 'Coin pack ' . ($k['id'] ? 'saved' : 'created') . '. Every player\'s Shop updates within a second.');
            redirect('/admin/app/packs/');
        }
        $_SESSION['pack_draft'] = $_POST + array('error' => $err !== '' ? $err : app_rpc_error($r));
        redirect('/admin/app/packs/?' . ($k['id'] ? 'edit=' . rawurlencode($k['id']) : 'new=1') . '&draft=1#packForm');
    }
    if (!isset($byId[$id])) { flash_set('err', 'Pack not found.'); redirect('/admin/app/packs/'); }
    if ($act === 'toggle') {
        $on = !g($byId[$id], 'active');
        $r = app_set_coin_package_active($id, $on);
        if ($r['ok']) { app_log($on ? 'enable_coin_pack' : 'disable_coin_pack', (string) g($byId[$id], 'product_id', $id)); flash_set('ok', 'Pack ' . ($on ? 'shown in the Shop.' : 'hidden from the Shop.')); } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'move') {
        $dir = (isset($_POST['dir']) && $_POST['dir'] === 'up') ? -1 : 1;
        $ids = array_keys($byId); $pos = array_search($id, $ids, true); $to = $pos + $dir;
        if ($pos !== false && $to >= 0 && $to < count($ids)) {
            $tmp = $ids[$pos]; $ids[$pos] = $ids[$to]; $ids[$to] = $tmp; $fail = '';
            foreach ($ids as $i => $pid) {
                $want = ($i + 1) * 10; if ((int) g($byId[$pid], 'sort_order', 0) === $want) { continue; }
                $k = pack_from_row($byId[$pid]); $k['sort_order'] = $want;
                $r = app_save_coin_package($k); if (!$r['ok']) { $fail = app_rpc_error($r); break; }
            }
            if ($fail === '') { app_log('edit_coin_pack', (string) g($byId[$id], 'product_id', $id), 'order'); flash_set('ok', 'Order changed.'); } else { flash_set('err', 'Could not change the order: ' . $fail); }
        }
    }
    redirect('/admin/app/packs/');
}

app_head('Coin packs', 'packs');
echo '<div class="warn">Each product id must exist in <b>Play Console &rarr; Monetize &rarr; In-app products</b> with the same price. Coins are added only after Google confirms the purchase.</div>';
$sold = array(); $pp = app_list_iap_purchases(null, 2000, 0);
foreach ((array) g($pp['data'], 'items', array()) as $x) { if (g($x, 'status') === 'credited') { $pid = (string) g($x, 'product_id', ''); $sold[$pid] = (isset($sold[$pid]) ? $sold[$pid] : 0) + 1; } }
if (!$lr['ok']) { echo '<div class="flash err">Could not load coin packs: ' . e(app_rpc_error($lr)) . '</div>'; }

/* ---------- form ---------- */
$E = isset($_GET['edit']) && isset($byId[(string) $_GET['edit']]) ? pack_from_row($byId[(string) $_GET['edit']]) : null;
$D = isset($_GET['draft']) && !empty($_SESSION['pack_draft']) ? $_SESSION['pack_draft'] : null;
$f = $E ?: array('id' => '', 'product_id' => '', 'title' => '', 'coins' => 1000, 'price_inr' => 49, 'bonus_coins' => 0, 'badge' => '', 'active' => true, 'sort_order' => (count($items) + 1) * 10);
if ($D) { foreach (array('id', 'product_id', 'title', 'coins', 'price_inr', 'bonus_coins', 'badge', 'sort_order') as $x) { $f[$x] = isset($D[$x]) ? (string) $D[$x] : ''; } $f['active'] = !empty($D['active']); }
$editing = $f['id'] !== '';
echo '<details class="panel" id="packForm"' . ($editing || isset($_GET['new']) ? ' open' : '') . '><summary class="btn">' . ($editing ? 'Edit coin pack' : '+ Create coin pack') . '</summary>';
echo '<div class="legal-grid" style="margin-top:16px"><form method="post" id="pkForm" data-confirm="' . ($editing ? 'Save this coin pack? Every player\'s Shop shows the change.' : 'Create this coin pack? It appears in every player\'s Shop (when active).') . '">' . csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . e($f['id']) . '"><div class="fgrid">';
echo '<label>Google Play product id<input name="product_id" id="pkPid" required maxlength="100" pattern="[a-z0-9_.]{3,100}" class="code" style="text-transform:none" value="' . e($f['product_id']) . '" placeholder="coins_1000"><span class="muted" style="font-weight:400;color:var(--gd)">Create the same product id and price in Play Console before billing goes live.</span></label>';
echo '<label>Title<input name="title" id="pkTitle" required maxlength="80" value="' . e($f['title']) . '" placeholder="Starter pack"></label>';
echo '<label>Coins<input name="coins" id="pkCoins" type="number" min="1" max="10000000" step="1" required value="' . e($f['coins']) . '"></label>';
echo '<label>Bonus coins<input name="bonus_coins" id="pkBonus" type="number" min="0" step="1" value="' . e($f['bonus_coins']) . '"></label>';
echo '<label>Price (₹)<input name="price_inr" id="pkPrice" type="number" min="0.01" max="100000" step="0.01" required value="' . e($f['price_inr']) . '"></label>';
echo '<label>Badge (optional)<input name="badge" id="pkBadge" maxlength="30" value="' . e($f['badge']) . '" placeholder="Best value"></label>';
echo '<label>Sort order (small = first)<input name="sort_order" type="number" step="1" value="' . e($f['sort_order']) . '"></label></div>';
echo '<label class="chk" style="margin-bottom:12px"><input type="checkbox" name="active" value="1"' . ($f['active'] ? ' checked' : '') . '> Active (shown in the Shop)</label>';
if ($D && !empty($D['error'])) { echo '<div class="flash err" style="margin:0 0 12px">' . e($D['error']) . '</div>'; }
echo '<div class="row" style="margin:0"><button class="btn" type="submit">' . ($editing ? 'Save pack' : 'Create pack') . '</button>' . ($editing ? '<a class="btn ghost" href="/admin/app/packs/">Cancel</a>' : '') . '<span class="muted" id="pkRate"></span></div></form>';
echo '<div><div class="muted" style="margin-bottom:8px">Preview (as in the Shop)</div><div class="shop" id="pkPreview">' . pack_row_html($f) . '</div></div></div></details>';

/* ---------- table ---------- */
echo '<h2>Coin packs (' . count($items) . ')</h2><div class="tbl-wrap"><table><tr><th>Order</th><th>Title</th><th>Product id</th><th>Coins</th><th>Total</th><th>Price</th><th>Coins per ₹1</th><th>Badge</th><th>Sold</th><th>On/Off</th><th></th></tr>';
$n = count($items);
foreach (array_values($items) as $i => $k) {
    $id = (string) g($k, 'id', ''); $on = (bool) g($k, 'active'); $h = csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '">';
    $price = (float) g($k, 'price_inr', 0); $total = (int) g($k, 'total_coins', 0);
    echo '<tr' . ($on ? '' : ' class="muted"') . '><td class="nowrap"><span class="muted">' . e(g($k, 'sort_order', 0)) . '</span> ';
    if ($i > 0) { echo '<form method="post" style="display:inline">' . $h . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="up"><button class="btn ghost sm" type="submit" title="Move up">&uarr;</button></form>'; }
    if ($i < $n - 1) { echo '<form method="post" style="display:inline">' . $h . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="down"><button class="btn ghost sm" type="submit" title="Move down">&darr;</button></form>'; }
    echo '</td><td><b>' . e(g($k, 'title', '')) . '</b></td><td><code class="j">' . e(g($k, 'product_id', '')) . '</code></td>';
    echo '<td class="nowrap">' . app_num(g($k, 'coins')) . ((int) g($k, 'bonus_coins', 0) ? ' <span class="pos">+' . app_num($k['bonus_coins']) . '</span>' : '') . '</td><td><b>' . app_num($total) . '</b></td><td class="nowrap"><b>' . e(app_inr($price)) . '</b></td>';
    echo '<td>' . ($price > 0 ? app_num(round($total / $price, 1)) : '-') . '</td><td>' . (g($k, 'badge') ? '<span class="badge b-gold">' . e($k['badge']) . '</span>' : '<span class="muted">-</span>') . '</td><td class="nowrap">Sold: <b>' . app_num(isset($sold[(string) g($k, 'product_id')]) ? $sold[(string) g($k, 'product_id')] : 0) . '</b></td>';
    echo '<td><form method="post" data-confirm="' . ($on ? 'Hide this pack from every player\'s Shop?' : 'Show this pack in every player\'s Shop?') . '">' . $h . '<input type="hidden" name="action" value="toggle"><button type="submit" class="switch' . ($on ? ' on' : '') . '"><i></i></button></form></td>';
    echo '<td><a class="btn ghost sm" href="/admin/app/packs/?edit=' . e(rawurlencode($id)) . '#packForm">Edit</a></td></tr>';
}
if (!$items) { echo '<tr><td colspan="11" class="muted">' . ($lr['ok'] ? 'No coin packs yet. Create the first one above.' : 'Could not load.') . '</td></tr>'; }
echo '</table></div>';

/* ---------- shop as players see it ---------- */
$act = array_values(array_filter($items, function ($k) { return g($k, 'active'); }));
echo '<h2>Shop as players see it (' . count($act) . ' active)</h2><div class="shop" style="max-width:460px">';
foreach ($act as $k) { echo pack_row_html($k); }
if (!$act) { echo '<p class="muted" style="padding:16px">No active pack: the Shop is empty.</p>'; }
echo '</div>';
echo '<script src="/admin/assets/app-packs.js?v=1" defer></script>';
admin_foot();
