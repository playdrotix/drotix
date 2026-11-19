<?php
/* Mobile App: Google Play coin purchases of every player (read only).
   Written by the Edge Function verify-play-purchase: credited once per real, paid purchase; fakes are 'rejected'. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$q = app_get('q', 100);
$st = app_get('status', 20); if (!in_array($st, array('credited', 'rejected', 'refunded'), true)) { $st = ''; }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
$filtering = $q !== '' || $st !== '';

// filters are applied on the loaded rows (newest first); with a filter the latest 1,000 purchases are searched
$pr = $filtering ? app_list_iap_purchases(null, 1000, 0) : app_list_iap_purchases(null, $per, ($page - 1) * $per);
$items = $pr['ok'] ? (array) g($pr['data'], 'items', array()) : array();
if ($filtering) {
    $needle = strtolower($q);
    $items = array_values(array_filter($items, function ($x) use ($needle, $st) {
        if ($st !== '' && g($x, 'status') !== $st) { return false; }
        if ($needle === '') { return true; }
        foreach (array('username', 'order_id', 'product_id') as $k) { if (strpos(strtolower((string) g($x, $k, '')), $needle) !== false) { return true; } }
        return false;
    }));
    $total = count($items);
    $items = array_slice($items, ($page - 1) * $per, $per);
} else { $total = $pr['ok'] ? (int) g($pr['data'], 'total', count($items)) : 0; }

$titles = array(); $pl = app_list_coin_packages(); foreach ((array) g($pl['data'], 'items', array()) as $k) { $titles[(string) g($k, 'product_id')] = (string) g($k, 'title'); }
$sm = app_purchase_summary($items);

app_head('Purchases', 'purch');
echo '<p class="muted" style="margin-top:-8px">Players buy coin packs through Google Play. The server (verify-play-purchase) checks every purchase with Google and adds the coins once, only if it is real and paid. Fake or unpaid purchases are saved as <b>rejected</b> and give no coins. This page only reads the results.</p>';
echo '<div class="cards sm">';
echo '<div class="card"><div class="n teal">' . app_num($sm['credited']) . '</div><div class="l">Credited (this page)</div></div>';
echo '<div class="card' . ($sm['rejected'] ? ' hot' : '') . '"><div class="n' . ($sm['rejected'] ? ' neg' : '') . '">' . app_num($sm['rejected']) . '</div><div class="l">Rejected (this page)</div></div>';
echo '<div class="card"><div class="n gold">' . app_num($sm['coins']) . '</div><div class="l">Coins given</div></div>';
echo '<div class="card"><div class="n">' . e(app_inr($sm['inr'])) . '</div><div class="l">Paid (credited)</div></div></div>';

echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Username, order id (GPA…) or product id" style="min-width:300px"><select name="status"><option value="">All</option>';
foreach (array('credited' => 'Credited', 'rejected' => 'Rejected', 'refunded' => 'Refunded') as $k => $v) { echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $v . '</option>'; }
echo '</select><button class="btn sm" type="submit">Search</button>' . ($filtering ? '<a class="btn ghost sm" href="/admin/app/purchases/">Clear</a><span class="muted">searching the latest 1,000 purchases</span>' : '') . '<span class="muted">' . app_num($total) . ' purchases</span></form>';
if (!$pr['ok']) { echo '<div class="flash err">Could not load purchases: ' . e(app_rpc_error($pr)) . '</div>'; }

echo '<div class="tbl-wrap"><table><tr><th>Date</th><th>Player</th><th>Product</th><th>Coins</th><th>Price</th><th>Google order id</th><th>Status</th><th>Verified</th></tr>';
foreach ($items as $x) {
    $pid = (string) g($x, 'product_id', ''); $oid = (string) g($x, 'order_id', '');
    echo '<tr><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td>' . app_user_link(g($x, 'user_id'), g($x, 'username')) . '</td>';
    echo '<td><code class="j">' . e($pid) . '</code>' . (isset($titles[$pid]) ? '<div class="muted" style="font-size:12px">' . e($titles[$pid]) . '</div>' : '') . '</td>';
    echo '<td>' . app_num(g($x, 'coins')) . '</td><td class="nowrap">' . (g($x, 'price_inr') !== null ? e(app_inr($x['price_inr'])) : '-') . '</td>';
    echo '<td class="nowrap">' . ($oid !== '' ? '<code class="j">' . e($oid) . '</code> <button type="button" class="btn ghost sm" data-copy="' . e($oid) . '">Copy</button>' : '<span class="muted">-</span>') . '</td>';
    echo '<td>' . app_purchase_badge(g($x, 'status')) . '</td><td class="nowrap">' . (g($x, 'verified') ? '<span class="pos">Verified by Google &#10003;</span>' : '<span class="neg">&#10007;</span>') . '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="8" class="muted">' . ($pr['ok'] ? ($filtering ? 'No purchase matches.' : 'No purchases yet.') : 'Could not load.') . '</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
echo '<script src="/admin/assets/app-coupons.js?v=1" defer></script>';   // copy buttons
admin_foot();
