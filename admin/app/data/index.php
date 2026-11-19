<?php
/* Mobile App: browse and edit every table of the drotix schema (service key, server side).
   /admin/app/data/                       list of tables
   /admin/app/data/?t=TABLE               rows (filter, sort, page, CSV)
   /admin/app/data/?t=TABLE&user=UUID     rows linked to one player
   /admin/app/data/?t=TABLE&edit=PK       edit / delete one row
   /admin/app/data/?t=TABLE&new=1         add a row */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$tables = app_tables();
$t = isset($_GET['t']) ? (string) $_GET['t'] : '';
if ($t !== '' && !isset($tables[$t])) { flash_set('err', 'Unknown table.'); redirect('/admin/app/data/'); }

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $t !== '') {
    csrf_verify();
    $pk = app_pk($t);
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $key = isset($_POST['pk']) ? (string) $_POST['pk'] : '';
    $base = '/admin/app/data/?t=' . rawurlencode($t);
    if ($act === 'save' && $pk && $key !== '') {
        list($rows, , ) = app_select($t, array($pk => 'eq.' . $key), 1, 0, false);
        if (!$rows) { flash_set('err', 'Row not found.'); redirect($base); }
        list($patch, $errs) = app_patch_from_post($t, $rows[0]);
        unset($patch[$pk]);
        if ($errs) { flash_set('err', 'Invalid value in: ' . implode(', ', $errs)); redirect($base . '&edit=' . rawurlencode($key)); }
        if (!$patch) { flash_set('ok', 'Nothing changed.'); redirect($base . '&edit=' . rawurlencode($key)); }
        $r = app_db('PATCH', $t, array($pk => 'eq.' . $key), $patch, 'return=minimal');
        if ($r['ok']) { app_log('row_update', $t . ':' . $key, $patch); flash_set('ok', 'Saved: ' . implode(', ', array_keys($patch)) . '.'); } else { flash_set('err', 'Save failed: ' . $r['error']); }
        redirect($base . '&edit=' . rawurlencode($key));
    }
    if ($act === 'delete' && $pk && $key !== '') {
        $r = app_db('DELETE', $t, array($pk => 'eq.' . $key), null, 'return=minimal');
        if ($r['ok']) { app_log('row_delete', $t . ':' . $key); flash_set('ok', 'Row deleted.'); } else { flash_set('err', 'Delete failed: ' . $r['error']); }
        redirect($base);
    }
    if ($act === 'insert') {
        $cols = app_cols($t); $row = array(); $errs = array();
        foreach ((isset($_POST['f']) && is_array($_POST['f']) ? $_POST['f'] : array()) as $c => $raw) {
            if (!isset($cols[$c])) { continue; }
            $v = app_cast($cols[$c], $raw);
            if ($v === '__skip') { $errs[] = $c; continue; }
            if ($v !== null) { $row[$c] = $v; }   // empty fields use the database default
        }
        if ($errs) { flash_set('err', 'Invalid value in: ' . implode(', ', $errs)); redirect($base . '&new=1'); }
        $r = app_db('POST', $t, array(), $row, 'return=representation');
        if ($r['ok']) {
            app_log('row_insert', $t, $row);
            $nk = ($pk && isset($r['data'][0][$pk])) ? (string) $r['data'][0][$pk] : '';
            flash_set('ok', 'Row added.');
            redirect($nk !== '' ? $base . '&edit=' . rawurlencode($nk) : $base);
        }
        flash_set('err', 'Insert failed: ' . $r['error']);
        redirect($base . '&new=1');
    }
    redirect($base);
}

/* ---------- table list ---------- */
if ($t === '') {
    app_head('All app data', 'data');
    echo '<p class="muted">Every table of the mobile app (schema <b>drotix</b>). Changes here go straight to the live database. <a href="?refresh_schema=1">Reload table list</a></p>';
    echo '<div class="tbl-wrap"><table><tr><th>Table</th><th>Columns</th><th>Linked to players by</th></tr>';
    foreach ($tables as $name => $info) {
        echo '<tr class="click" data-href="/admin/app/data/?t=' . e(rawurlencode($name)) . '"><td><a href="/admin/app/data/?t=' . e(rawurlencode($name)) . '"><b>' . e($name) . '</b></a></td><td class="muted">' . e(implode(', ', array_keys($info['cols']))) . '</td><td>' . e(implode(', ', app_user_cols($name))) . '</td></tr>';
    }
    if (!$tables) { echo '<tr><td colspan="3" class="muted">No tables found.</td></tr>'; }
    echo '</table></div>';
    admin_foot(); exit;
}

$cols = app_cols($t);
$pk = app_pk($t);
$sub = 'data';

/* ---------- edit one row ---------- */
if (isset($_GET['edit']) && $pk) {
    $key = (string) $_GET['edit'];
    list($rows, , $r) = app_select($t, array($pk => 'eq.' . $key), 1, 0, false);
    app_head($t . ': edit row', $sub);
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/data/?t=' . e(rawurlencode($t)) . '">&larr; Back to ' . e($t) . '</a></div>' . app_err_box($r);
    if (!$rows) { echo '<div class="flash err">Row not found.</div>'; admin_foot(); exit; }
    $row = $rows[0];
    $links = array();
    foreach (app_user_cols($t) as $c) { if (!empty($row[$c]) && uuid_ok($row[$c])) { $links[] = e($c) . ': ' . app_user_link($row[$c], 'open player'); } }
    if (!empty($row['screenshot_path'])) { $links[] = '<a href="/admin/app/evidence/?path=' . e(rawurlencode($row['screenshot_path'])) . '" target="_blank" rel="noopener">Open screenshot</a>'; }
    if ($links) { echo '<div class="row">' . implode(' &middot; ', $links) . '</div>'; }
    echo '<form method="post" class="panel" data-confirm="Save the changes to this row?">' . csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="pk" value="' . e($key) . '"><div class="fgrid">';
    echo '<label>' . e($pk) . ' <span class="muted" style="font-weight:400">primary key</span><input value="' . e($key) . '" disabled></label>';
    foreach ($cols as $c => $f) { if ($c === $pk) { continue; } echo '<label>' . e($c) . ' <span class="muted" style="font-weight:400">' . e($f) . '</span>' . app_input($c, $f, array_key_exists($c, $row) ? $row[$c] : null) . '</label>'; }
    echo '</div><button class="btn" type="submit">Save</button></form>';
    echo '<form method="post" data-confirm="Delete this row permanently?">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="pk" value="' . e($key) . '"><button class="btn danger sm" type="submit">Delete row</button></form>';
    admin_foot(); exit;
}

/* ---------- new row ---------- */
if (isset($_GET['new'])) {
    app_head($t . ': add row', $sub);
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/data/?t=' . e(rawurlencode($t)) . '">&larr; Back</a></div><p class="muted">Leave a field empty to use the database default.</p>';
    echo '<form method="post" class="panel" data-confirm="Add this row?">' . csrf_field() . '<input type="hidden" name="action" value="insert"><div class="fgrid">';
    foreach ($cols as $c => $f) { echo '<label>' . e($c) . ' <span class="muted" style="font-weight:400">' . e($f) . '</span>' . app_input($c, $f, null) . '</label>'; }
    echo '</div><button class="btn" type="submit">Add row</button></form>';
    admin_foot(); exit;
}

/* ---------- rows ---------- */
$query = array();
$fc = app_get('fc', 63); $fv = app_get('fv', 200); $user = app_get('user', 40);
$sort = app_get('sort', 63); $dir = app_get('dir', 4) === 'asc' ? 'asc' : 'desc';
if ($sort === '' || !isset($cols[$sort])) { $sort = isset($cols['created_at']) ? 'created_at' : ($pk ?: ''); }
if ($sort !== '') { $query['order'] = $sort . '.' . $dir; }
if ($fc !== '' && isset($cols[$fc]) && $fv !== '') {
    $f = $cols[$fc];
    $query[$fc] = (app_is_num_fmt($f) || $f === 'uuid' || $f === 'boolean' || strpos($f, 'timestamp') === 0) ? 'eq.' . $fv : 'ilike.*' . pg_safe($fv) . '*';
}
if ($user !== '' && uuid_ok($user)) {
    $ors = array(); foreach (app_user_cols($t) as $c) { $ors[] = $c . '.eq.' . $user; }
    if ($ors) { $query['or'] = '(' . implode(',', $ors) . ')'; }
}
if (isset($_GET['export'])) {
    $all = array(); for ($o = 0; $o < 50000; $o += 1000) { list($ch, , ) = app_select($t, $query, 1000, $o, false); $all = array_merge($all, $ch); if (count($ch) < 1000) { break; } }
    csv_out('drotix_' . $t . '.csv', $all);
}
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
list($rows, $total, $r) = app_select($t, $query, $per, ($page - 1) * $per);

app_head('drotix.' . $t, $sub);
echo '<form class="row" method="get"><input type="hidden" name="t" value="' . e($t) . '">' . ($user !== '' ? '<input type="hidden" name="user" value="' . e($user) . '">' : '');
echo '<select name="fc"><option value="">Filter column…</option>';
foreach ($cols as $c => $f) { echo '<option' . ($fc === $c ? ' selected' : '') . '>' . e($c) . '</option>'; }
echo '</select><input name="fv" value="' . e($fv) . '" placeholder="value"><select name="sort">';
foreach ($cols as $c => $f) { echo '<option' . ($sort === $c ? ' selected' : '') . '>' . e($c) . '</option>'; }
echo '</select><select name="dir"><option value="desc">newest / Z-A</option><option value="asc"' . ($dir === 'asc' ? ' selected' : '') . '>oldest / A-Z</option></select>';
echo '<button class="btn sm" type="submit">Apply</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1, 'page' => null))) . '">Export CSV</a><a class="btn ghost sm" href="/admin/app/data/?t=' . e(rawurlencode($t)) . '&amp;new=1">+ Add row</a>';
echo '<span class="muted">' . app_num($total) . ' rows' . ($user !== '' ? ' for player ' . app_user_link($user, substr($user, 0, 8)) . ' (<a href="/admin/app/data/?t=' . e(rawurlencode($t)) . '">show all</a>)' : '') . '</span></form>';
echo app_err_box($r) . app_rows_table($rows, $t, 'No rows.') . ($total !== null ? pager($page, $total, $per) : app_pager_simple($page, count($rows), $per));
admin_foot();
