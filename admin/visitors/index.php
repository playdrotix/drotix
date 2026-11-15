<?php
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();
$table = tbl('visits');

$q = pg_safe(isset($_GET['q']) ? $_GET['q'] : '');
$query = array('order' => 'created_at.desc');
if ($q !== '') { $query['path'] = 'ilike.*' . $q . '*'; }
if (isset($_GET['export'])) { csv_out('site_visits.csv', sb_all($table, $query, 10000)); }

$todayIso = gmdate('c', strtotime('today'));
$weekIso  = gmdate('c', time() - 7 * 86400);
$monthIso = gmdate('c', time() - 30 * 86400);
$cTotal   = sb_count($table);
$cUnique  = sb_count($table, array('is_new' => 'eq.true'));
$cToday   = sb_count($table, array('created_at' => 'gte.' . $todayIso));
$cWeek    = sb_count($table, array('created_at' => 'gte.' . $weekIso));
$cMonth   = sb_count($table, array('created_at' => 'gte.' . $monthIso));
$cNewWeek = sb_count($table, array('created_at' => 'gte.' . $weekIso, 'is_new' => 'eq.true'));

/* top pages + devices from the latest 3000 visits */
$sample = sb_all($table, array('order' => 'created_at.desc', 'select' => 'path,device,visitor_id,referrer'), 3000);
$pages = array(); $devs = array(); $refs = array(); $seen = array();
foreach ($sample as $v) {
    $p = isset($v['path']) && $v['path'] !== '' ? $v['path'] : '/';
    $pages[$p] = (isset($pages[$p]) ? $pages[$p] : 0) + 1;
    $d = !empty($v['device']) ? $v['device'] : 'unknown';
    $devs[$d] = (isset($devs[$d]) ? $devs[$d] : 0) + 1;
    $rh = !empty($v['referrer']) ? parse_url($v['referrer'], PHP_URL_HOST) : '';
    $rh = $rh ? $rh : 'direct';
    $refs[$rh] = (isset($refs[$rh]) ? $refs[$rh] : 0) + 1;
}
arsort($pages); arsort($devs); arsort($refs);

$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
list($rows, $total, $r) = sb_select($table, $query, $per, ($page - 1) * $per);

admin_head('Site visitors', 'vis');
$n = function ($v) { return $v === null ? '?' : number_format((int) $v); };
if (!sb_ok($r)) { echo '<div class="flash err">Could not read site_visits. Did you run the new block of database/schema.sql? ' . e($r['error']) . '</div>'; }
echo '<div class="cards">';
echo '<div class="card"><div class="n">' . e($n($cTotal)) . '</div><div class="l">Total visits (all pages)</div></div>';
echo '<div class="card"><div class="n">' . e($n($cUnique)) . '</div><div class="l">Unique visitors (all time)</div></div>';
echo '<div class="card"><div class="n">' . e($n($cToday)) . '</div><div class="l">Visits today</div></div>';
echo '<div class="card"><div class="n">' . e($n($cWeek)) . '</div><div class="l">Last 7 days (' . e($n($cNewWeek)) . ' new)</div></div>';
echo '<div class="card"><div class="n">' . e($n($cMonth)) . '</div><div class="l">Last 30 days</div></div></div>';

echo '<div class="cards">';
echo '<div class="card"><div class="l"><b>Top pages</b> (latest 3000)</div>';
$i = 0; foreach ($pages as $k => $c) { if (++$i > 6) { break; } echo '<div>' . e($k) . ' <span class="muted">&middot; ' . (int) $c . '</span></div>'; }
echo '</div><div class="card"><div class="l"><b>Devices</b></div>';
foreach ($devs as $k => $c) { echo '<div>' . e($k) . ' <span class="muted">&middot; ' . (int) $c . '</span></div>'; }
echo '</div><div class="card"><div class="l"><b>Where from</b></div>';
$i = 0; foreach ($refs as $k => $c) { if (++$i > 6) { break; } echo '<div>' . e($k) . ' <span class="muted">&middot; ' . (int) $c . '</span></div>'; }
echo '</div></div>';

echo '<h2>Visit history</h2><form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Filter by page, e.g. /faq/"><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></form>';
echo '<div class="tbl-wrap"><table><tr><th>When</th><th>Page</th><th>Visitor</th><th>Device</th><th>From</th><th>IP</th></tr>';
foreach ($rows as $v) {
    $rh = !empty($v['referrer']) ? parse_url($v['referrer'], PHP_URL_HOST) : '';
    echo '<tr><td>' . fmt_dt($v['created_at']) . '</td><td>' . e(isset($v['path']) ? $v['path'] : '') . '</td><td>' . e(substr((string) $v['visitor_id'], 0, 8)) . (!empty($v['is_new']) ? ' <span class="badge b-teal">new</span>' : '') . '</td><td>' . e(isset($v['device']) ? $v['device'] : '') . '</td><td>' . e($rh ? $rh : 'direct') . '</td><td>' . e(isset($v['ip']) ? $v['ip'] : '') . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="6" class="muted">No visits recorded yet.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
