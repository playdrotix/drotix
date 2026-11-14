<?php
/* Data Explorer: read any Supabase table that you list in includes/config.php ('explorer'). Read-only. */
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

$sources = cfg('explorer', array());
if (!is_array($sources)) { $sources = array(); }
$si = isset($_GET['s']) ? (int) $_GET['s'] : 0;
if ($si < 0 || $si >= count($sources)) { $si = 0; }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
$per = 25;

$src = null; $rows = array(); $total = null; $res = null; $table = '';
if ($sources) {
    $s = $sources[$si];
    $table = isset($s['table']) ? (string) $s['table'] : '';
    $src = array('url' => isset($s['url']) ? $s['url'] : null, 'key' => isset($s['key']) ? $s['key'] : null);
    if (sb_valid_table($table)) {
        list($rows, $total, $res) = sb_select($table, array('order' => 'created_at.desc'), $per, ($page - 1) * $per, $src);
        if (!sb_ok($res)) { /* the table may have no created_at column: retry without ordering */
            list($rows, $total, $res) = sb_select($table, array(), $per, ($page - 1) * $per, $src);
        }
        if (isset($_GET['export']) && sb_ok($res)) {
            $q = array('order' => 'created_at.desc');
            $all = sb_all($table, $q, 5000, $src);
            if (!$all) { $all = sb_all($table, array(), 5000, $src); }
            csv_out($table . '.csv', $all);
        }
    }
}

admin_head('Data Explorer', 'exp');
echo '<p class="muted">Read-only view of your Supabase tables. To add a table or another Supabase project, edit <code>explorer</code> in <code>includes/config.php</code> (API URL, key, table name).</p>';
if (!$sources) { echo '<div class="flash err">No tables listed yet.</div>'; admin_foot(); exit; }
echo '<div class="row">';
foreach ($sources as $i => $s) {
    echo '<a class="btn sm ' . ($i === $si ? '' : 'ghost') . '" href="/admin/explorer/?s=' . (int) $i . '">' . e(isset($s['label']) ? $s['label'] : $s['table']) . '</a>';
}
echo '</div>';
if (!sb_valid_table($table)) { echo '<div class="flash err">Invalid table name in config.</div>'; admin_foot(); exit; }
if (!sb_configured($src)) { echo '<div class="flash err">This source has no Supabase URL or key. Set it in includes/config.php.</div>'; admin_foot(); exit; }
if (!sb_ok($res)) {
    echo '<div class="flash err">Could not read <b>' . e($table) . '</b> (HTTP ' . (int) $res['status'] . '): ' . e($res['error']) . '</div>';
    admin_foot(); exit;
}
echo '<div class="row"><b>' . e($table) . '</b><span class="muted">' . ($total === null ? '' : (int) $total . ' rows') . '</span><a class="btn ghost sm" href="' . e(qs(array('export' => 1))) . '">Export CSV</a></div>';
$cols = array();
foreach ($rows as $r) { foreach (array_keys($r) as $k) { if (!in_array($k, $cols, true)) { $cols[] = $k; } } }
echo '<div class="tbl-wrap"><table><tr>';
foreach ($cols as $c) { echo '<th>' . e($c) . '</th>'; }
echo '</tr>';
foreach ($rows as $r) {
    echo '<tr>';
    foreach ($cols as $c) {
        $v = isset($r[$c]) ? $r[$c] : '';
        if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
        $v = (string) $v;
        $short = (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 90) ? mb_substr($v, 0, 90, 'UTF-8') . '...' : $v;
        echo '<td class="clip" title="' . e($v) . '">' . e($short) . '</td>';
    }
    echo '</tr>';
}
if (!$rows) { echo '<tr><td class="muted">This table is empty.</td></tr>'; }
echo '</table></div>' . pager($page, $total, $per);
admin_foot();
