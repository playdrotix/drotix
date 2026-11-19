<?php
/*
 * DROTIX Mobile App admin: server-side access to the game data (Supabase schema `drotix` + Supabase Auth).
 *
 * - ONE login only: whoever is logged in to /admin/ can see and manage everything here. No Supabase sign-in.
 * - Uses the Supabase SERVICE (secret) key, server side only (PHP). It never reaches the browser.
 *   URL + key come from the single config file includes/config.php (supabase_url, supabase_key).
 * - Tables and columns are discovered automatically from the database (PostgREST OpenAPI), so the panel
 *   follows the real schema without hard-coding it.
 * - Every change is written to the website audit log (action names start with "app_").
 */
require_once __DIR__ . '/admin_layout.php';

const APP_SCHEMA = 'drotix';
const APP_EVIDENCE_BUCKET = 'drotix-report-evidence';
$GLOBALS['__app_schema'] = null;

/* ================= config =================
 * Everything comes from the ONE config file: includes/config.php  (supabase_url + supabase_key = secret key). */
/** array(url, service_key) */
function app_base() {
    return array(rtrim((string) cfg('supabase_url', ''), '/'), (string) cfg('supabase_key', ''));
}
function app_config_problem() {
    list($u, $k) = app_base();
    if ($u === '' || $k === '') { return 'Supabase URL / key missing. Fill supabase_url and supabase_key in includes/config.php.'; }
    $kind = sb_key_kind($k);
    if (!in_array($kind[0], array('secret', 'service_role'), true)) { return 'supabase_key in includes/config.php must be the SECRET / service_role key (Supabase -> Project Settings -> API Keys -> Secret key, starts with sb_secret_). Now: ' . $kind[2]; }
    return '';
}

/* ================= HTTP ================= */
function app_headers($extra = array(), $hasBody = false) {
    list(, $key) = app_base();
    $h = array('apikey: ' . $key, 'Accept: application/json');
    if (strpos($key, 'eyJ') === 0) { $h[] = 'Authorization: Bearer ' . $key; }
    if ($hasBody) { $h[] = 'Content-Type: application/json'; }
    foreach ($extra as $k => $v) { $h[] = $k . ': ' . $v; }
    return $h;
}
function app_curl($method, $path, $body, $extra) {
    list($base, ) = app_base();
    $ch = curl_init($base . $path);
    $rh = array();
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => app_headers($extra, $body !== null),
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$rh) { $p = explode(':', $line, 2); if (count($p) === 2) { $rh[strtolower(trim($p[0]))] = trim($p[1]); } return strlen($line); },
    ));
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    return array($ch, function () use (&$rh) { return $rh; });
}
function app_parse($status, $raw, $curlErr) {
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $err = $curlErr;
    if ($status >= 400 && $err === '') {
        if (is_array($data)) {
            foreach (array('message', 'msg', 'error_description', 'error') as $f) { if (!empty($data[$f]) && is_string($data[$f])) { $err = $data[$f]; break; } }
            if (!empty($data['details']) && is_string($data['details'])) { $err .= ' (' . $data['details'] . ')'; }
        }
        if ($err === '') { $err = 'HTTP ' . $status; }
    }
    return array($data, $err);
}
/** Returns array('ok', 'status', 'data', 'error', 'headers') */
function app_http($method, $path, $body = null, $extra = array()) {
    list($ch, $hdr) = app_curl($method, $path, $body, $extra);
    $raw = curl_exec($ch);
    $cerr = $raw === false ? curl_error($ch) : '';
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    list($data, $err) = app_parse($st, $raw, $cerr);
    return array('ok' => $st >= 200 && $st < 300 && $cerr === '', 'status' => $st, 'data' => $data, 'error' => $err, 'headers' => $hdr());
}
/** Parallel GETs: array(key => path) -> array(key => result) */
function app_http_multi($paths) {
    $mh = curl_multi_init(); $hs = array(); $out = array();
    foreach ($paths as $k => $p) { list($ch, ) = app_curl('GET', $p, null, array()); curl_multi_add_handle($mh, $ch); $hs[$k] = $ch; }
    do { $s = curl_multi_exec($mh, $run); if ($run) { curl_multi_select($mh, 1.0); } } while ($run && $s === CURLM_OK);
    foreach ($hs as $k => $ch) {
        $raw = curl_multi_getcontent($ch); $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        list($data, $err) = app_parse($st, $raw, '');
        $out[$k] = array('ok' => $st >= 200 && $st < 300, 'status' => $st, 'data' => $data, 'error' => $err);
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/* ================= database (schema drotix) ================= */
function app_valid_ident($s) { return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', (string) $s); }
/** PostgREST request on a drotix table. $query = PostgREST params. Result + 'total' (when counted). */
function app_db($method, $table, $query = array(), $body = null, $prefer = '') {
    if (!app_valid_ident($table)) { return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'Invalid table', 'total' => null); }
    $path = '/rest/v1/' . $table . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    $h = array('Accept-Profile' => APP_SCHEMA, 'Content-Profile' => APP_SCHEMA);
    if ($prefer !== '') { $h['Prefer'] = $prefer; }
    $r = app_http($method, $path, $body, $h);
    $r['total'] = null;
    if (!empty($r['headers']['content-range']) && preg_match('~/(\d+)$~', $r['headers']['content-range'], $m)) { $r['total'] = (int) $m[1]; }
    return $r;
}
/** array(rows, total, result) */
function app_select($table, $query = array(), $limit = 25, $offset = 0, $count = true) {
    $query['limit'] = (int) $limit; $query['offset'] = (int) $offset;
    if (!isset($query['select'])) { $query['select'] = '*'; }
    $r = app_db('GET', $table, $query, null, $count ? 'count=exact' : '');
    return array(($r['ok'] && is_array($r['data'])) ? $r['data'] : array(), $r['total'], $r);
}
function app_count($table, $query = array()) {
    $query['select'] = '*';
    list(, $t, $r) = app_select($table, $query, 1, 0, true);
    return $r['ok'] ? $t : null;
}
/** Call a drotix RPC with the service key (some admin functions may refuse a service caller). */
function app_rpc($fn, $params = array()) {
    if (!app_valid_ident($fn)) { return array('ok' => false, 'data' => null, 'error' => 'Invalid function'); }
    $r = app_http('POST', '/rest/v1/rpc/' . $fn, (object) $params, array('Content-Profile' => APP_SCHEMA, 'Accept-Profile' => APP_SCHEMA));
    if ($r['ok'] && is_array($r['data']) && isset($r['data']['status']) && $r['data']['status'] === 'error') {
        $r['ok'] = false; $r['error'] = isset($r['data']['message']) ? (string) $r['data']['message'] : 'Error';
    }
    return $r;
}

/* ---------- schema discovery ---------- */
/**
 * array('ok', 'error', 'tables' => array(name => array('cols' => array(col => format), 'pk' => col|null)), 'rpc' => array(names))
 * Cached in the PHP session for 10 minutes (?refresh_schema=1 to reload).
 */
function app_schema() {
    if ($GLOBALS['__app_schema'] !== null) { return $GLOBALS['__app_schema']; }
    $c = isset($_SESSION['app_schema']) ? $_SESSION['app_schema'] : null;
    if (!$c || $c['at'] < time() - 600 || isset($_GET['refresh_schema'])) {
        $r = app_http('GET', '/rest/v1/', null, array('Accept-Profile' => APP_SCHEMA, 'Accept' => 'application/openapi+json'));
        $c = array('at' => time(), 'ok' => false, 'error' => $r['error'], 'tables' => array(), 'rpc' => array());
        if ($r['ok'] && is_array($r['data'])) {
            $c['ok'] = true;
            foreach ((array) g($r['data'], 'definitions', array()) as $t => $def) {
                $cols = array(); $pk = null;
                foreach ((array) g($def, 'properties', array()) as $col => $p) {
                    $cols[$col] = (string) gx($p, array('format', 'type'), 'text');
                    if (strpos((string) g($p, 'description', ''), '<pk/>') !== false && $pk === null) { $pk = $col; }
                }
                if ($pk === null && isset($cols['id'])) { $pk = 'id'; }
                $c['tables'][$t] = array('cols' => $cols, 'pk' => $pk);
            }
            ksort($c['tables']);
            foreach (array_keys((array) g($r['data'], 'paths', array())) as $p) { if (strpos($p, '/rpc/') === 0) { $c['rpc'][] = substr($p, 5); } }
        }
        if ($c['ok']) { $_SESSION['app_schema'] = $c; }
    }
    return $GLOBALS['__app_schema'] = $c;
}
function app_tables() { $s = app_schema(); return $s['tables']; }
function app_has_table($t) { $ts = app_tables(); return isset($ts[$t]); }
function app_cols($t) { $ts = app_tables(); return isset($ts[$t]) ? $ts[$t]['cols'] : array(); }
function app_pk($t) { $ts = app_tables(); return isset($ts[$t]) ? $ts[$t]['pk'] : null; }
function app_has_rpc($fn) { $s = app_schema(); return in_array($fn, $s['rpc'], true); }
/** First table that has ALL the given columns (optionally name must match a regex). */
function app_find_table($needCols, $nameRe = null) {
    foreach (app_tables() as $t => $info) {
        if ($nameRe && !preg_match($nameRe, $t)) { continue; }
        $ok = true;
        foreach ($needCols as $c) { if (!isset($info['cols'][$c])) { $ok = false; break; } }
        if ($ok) { return $t; }
    }
    return null;
}
/** Columns of a table that point at a player (uuid columns named like user_id, player_x_id, reporter_id ...). */
function app_user_cols($t) {
    $out = array();
    foreach (app_cols($t) as $c => $f) {
        if ($f !== 'uuid') { continue; }
        if (preg_match('/(^|_)(user|player|profile|owner|reporter|reported|sender|receiver|recipient|winner|loser|friend|referrer|referred|inviter|invitee|host|guest|challenger|opponent|created_by|admin|target|member|blocker|blocked)(_[a-z0-9]+)?(_id)?$/', $c)) { $out[] = $c; }
    }
    return $out;
}
function app_profiles_table() { return app_has_table('profiles') ? 'profiles' : app_find_table(array('username'), '/profile|player|user/'); }
/** Column in profiles that equals the auth user id. */
function app_profile_idcol() { $t = app_profiles_table(); $c = app_cols($t); return isset($c['id']) ? 'id' : (isset($c['user_id']) ? 'user_id' : 'id'); }
function app_wallet_table() { return app_find_table(array('balance_minor')); }
function app_tx_table() { return app_find_table(array('amount_minor', 'direction')); }
function app_owner_col($t) { $u = app_user_cols($t); foreach (array('user_id', 'player_id', 'profile_id', 'owner_id') as $c) { if (in_array($c, $u, true)) { return $c; } } return $u ? $u[0] : null; }

/* ================= Supabase Auth (admin API, service key) ================= */
function app_auth_get($id) { return app_http('GET', '/auth/v1/admin/users/' . rawurlencode($id)); }
/** Parallel fetch: array(id => user|null) */
function app_auth_many($ids) {
    $paths = array();
    foreach (array_unique($ids) as $id) { if (uuid_ok($id)) { $paths[$id] = '/auth/v1/admin/users/' . rawurlencode($id); } }
    $out = array();
    foreach (app_http_multi($paths) as $id => $r) { $out[$id] = ($r['ok'] && is_array($r['data'])) ? $r['data'] : null; }
    return $out;
}
function app_auth_list($page, $per) { return app_http('GET', '/auth/v1/admin/users?page=' . (int) $page . '&per_page=' . (int) $per); }
function app_auth_update($id, $attrs) { return app_http('PUT', '/auth/v1/admin/users/' . rawurlencode($id), $attrs); }
function app_auth_delete($id) { return app_http('DELETE', '/auth/v1/admin/users/' . rawurlencode($id)); }
/** Find auth users whose email contains $needle (scans up to 10,000 accounts). array of user arrays */
function app_auth_search_email($needle, $max = 50) {
    $found = array(); $needle = strtolower($needle);
    for ($p = 1; $p <= 10 && count($found) < $max; $p++) {
        $r = app_auth_list($p, 1000);
        $users = $r['ok'] ? (array) g($r['data'], 'users', array()) : array();
        foreach ($users as $u) { if (strpos(strtolower((string) g($u, 'email', '')), $needle) !== false) { $found[] = $u; } }
        if (count($users) < 1000) { break; }
    }
    return $found;
}

/* ================= storage ================= */
function app_evidence_url($path) {
    $path = ltrim((string) $path, '/');
    if ($path === '' || strpos($path, '..') !== false) { return ''; }
    $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
    $r = app_http('POST', '/storage/v1/object/sign/' . APP_EVIDENCE_BUCKET . '/' . $enc, array('expiresIn' => 300));
    if (!$r['ok'] || empty($r['data']['signedURL'])) { return ''; }
    list($base, ) = app_base();
    return $base . '/storage/v1' . (strpos($r['data']['signedURL'], '/') === 0 ? '' : '/') . $r['data']['signedURL'];
}

/* ================= generic row editing ================= */
function app_is_json_fmt($f) { return in_array($f, array('json', 'jsonb'), true) || substr($f, -2) === '[]' || strpos($f, 'ARRAY') !== false; }
function app_is_int_fmt($f) { return in_array($f, array('integer', 'bigint', 'smallint'), true); }
function app_is_num_fmt($f) { return app_is_int_fmt($f) || in_array($f, array('numeric', 'real', 'double precision', 'number'), true); }
/** Form input for one column. */
function app_input($col, $fmt, $val) {
    $n = 'f[' . e($col) . ']';
    if ($fmt === 'boolean') {
        $o = '<select name="' . $n . '"><option value="__null"' . ($val === null ? ' selected' : '') . '>(null)</option><option value="true"' . ($val === true ? ' selected' : '') . '>true</option><option value="false"' . ($val === false ? ' selected' : '') . '>false</option></select>';
        return $o;
    }
    if (app_is_json_fmt($fmt)) {
        return '<textarea name="' . $n . '" rows="3">' . e($val === null ? '' : json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</textarea>';
    }
    $type = app_is_num_fmt($fmt) ? 'text" inputmode="decimal' : 'text';
    $v = is_array($val) ? json_encode($val) : (string) $val;
    if (strlen($v) > 120) { return '<textarea name="' . $n . '" rows="3">' . e($v) . '</textarea>'; }
    return '<input type="' . $type . '" name="' . $n . '" value="' . e($v) . '"' . ($val === null ? ' placeholder="(null)"' : '') . '>';
}
/** Raw form string -> typed value (or the string '__skip' when invalid JSON etc.). Empty = null. */
function app_cast($fmt, $raw) {
    $raw = is_string($raw) ? $raw : '';
    if ($raw === '__null') { return null; }
    if ($fmt === 'boolean') { return $raw === 'true' ? true : ($raw === 'false' ? false : null); }
    if (trim($raw) === '') { return null; }
    if (app_is_json_fmt($fmt)) { $d = json_decode($raw, true); return json_last_error() === JSON_ERROR_NONE ? $d : '__skip'; }
    if (app_is_int_fmt($fmt)) { return preg_match('/^-?\d+$/', trim($raw)) ? (int) trim($raw) : '__skip'; }
    if (app_is_num_fmt($fmt)) { return is_numeric(trim($raw)) ? (float) trim($raw) : '__skip'; }
    return $raw;
}
/** Build a PATCH body from posted f[] comparing to the current row. Returns array(patch, errors). */
function app_patch_from_post($table, $row) {
    $cols = app_cols($table); $patch = array(); $errs = array();
    $f = isset($_POST['f']) && is_array($_POST['f']) ? $_POST['f'] : array();
    foreach ($f as $col => $raw) {
        if (!isset($cols[$col])) { continue; }
        $v = app_cast($cols[$col], $raw);
        if ($v === '__skip') { $errs[] = $col; continue; }
        $old = array_key_exists($col, (array) $row) ? $row[$col] : null;
        if (json_encode($v) !== json_encode($old)) {
            if (is_string($old) && is_string($v) && strtotime($old) && strtotime($v) && strtotime($old) === strtotime($v)) { continue; }
            $patch[$col] = $v;
        }
    }
    return array($patch, $errs);
}
function app_log($action, $target = '', $details = '') { audit_log('app_' . $action, $target, is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : (string) $details); }

/* ================= view helpers ================= */
function g($a, $path, $def = null) {
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a) || $a[$k] === null) { return $def; }
        $a = $a[$k];
    }
    return $a;
}
function gx($row, $keys, $def = null) {
    foreach ((array) $keys as $k) { $v = g($row, $k); if ($v !== null && $v !== '') { return $v; } }
    return $def;
}
function app_num($v) { return ($v === null || $v === '' || !is_numeric($v)) ? '-' : number_format((float) $v, (floor((float) $v) == (float) $v) ? 0 : 2); }
function app_pno($n) { return ($n === null || $n === '') ? '---' : str_pad((string) (int) $n, 3, '0', STR_PAD_LEFT); }
function app_dt($iso) { return fmt_dt($iso); }
function app_badge($s) {
    $s = is_scalar($s) ? (string) $s : '';
    $map = array('active' => 'b-teal', 'resolved' => 'b-teal', 'approved' => 'b-teal', 'win' => 'b-teal', 'credit' => 'b-teal', 'completed' => 'b-teal', 'finished' => 'b-teal',
                 'live' => 'b-pink', 'in_progress' => 'b-pink', 'running' => 'b-pink', 'suspended' => 'b-red', 'rejected' => 'b-red', 'loss' => 'b-red', 'debit' => 'b-red', 'banned' => 'b-red',
                 'under_review' => 'b-gold', 'pending' => 'b-gold', 'open' => 'b-gold', 'draw' => 'b-gold', 'dismissed' => 'b-grey', 'cancelled' => 'b-grey');
    if ($s === '') { return '<span class="muted">-</span>'; }
    return '<span class="badge ' . (isset($map[$s]) ? $map[$s] : 'b-grey') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}
function app_avatar($url, $pno, $size = 36) {
    $url = is_string($url) ? $url : '';
    if ($url !== '' && preg_match('~^https://~i', $url)) {
        return '<img class="av" src="' . e($url) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" referrerpolicy="no-referrer">';
    }
    return '<span class="av av-ph" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;font-size:' . max(10, (int) ($size / 3.2)) . 'px">' . e(app_pno($pno)) . '</span>';
}
function app_user_link($id, $label = null) {
    $label = ($label !== null && $label !== '') ? (string) $label : (string) $id;
    if (!$id || !uuid_ok($id)) { return e($label !== '' ? $label : '-'); }
    return '<a href="/admin/app/users/?id=' . e($id) . '">' . e($label) . '</a>';
}
/** Display one cell value. */
function app_cell($col, $v, $fmt = '') {
    if ($v === null || $v === '') { return '<span class="muted">-</span>'; }
    if (is_bool($v)) { return $v ? '<span class="pos">true</span>' : '<span class="muted">false</span>'; }
    if (is_array($v)) { $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); return '<code class="j">' . e(strlen($j) > 160 ? substr($j, 0, 160) . '…' : $j) . '</code>'; }
    if ($col === 'screenshot_path' || substr($col, -11) === '_screenshot') { return '<a href="/admin/app/evidence/?path=' . e(rawurlencode((string) $v)) . '" target="_blank" rel="noopener">Open image</a>'; }
    if ($fmt === 'uuid' && uuid_ok($v) && preg_match('/(user|player|profile|owner|reporter|reported|sender|receiver|winner|friend|referr|target)/', $col)) { return app_user_link($v, substr($v, 0, 8)); }
    if (strpos($fmt, 'timestamp') === 0 || (is_string($v) && preg_match('/_at$/', $col))) { return '<span class="nowrap">' . app_dt($v) . '</span>'; }
    if (in_array($col, array('status', 'account_status', 'direction', 'result'), true)) { return app_badge($v); }
    $s = (string) $v;
    if (is_string($v) && preg_match('~^https://\S+\.(png|jpe?g|webp|gif)(\?\S*)?$~i', $s)) { return '<img class="av" src="' . e($s) . '" width="32" height="32" alt="">'; }
    return '<span class="cellv">' . e(strlen($s) > 200 ? substr($s, 0, 200) . '…' : $s) . '</span>';
}
/** Generic table of rows. $linkTable: link each row to the Data editor. */
function app_rows_table($rows, $table = null, $empty = 'No rows.') {
    if (!$rows) { return '<div class="tbl-wrap"><table><tr><td class="muted">' . e($empty) . '</td></tr></table></div>'; }
    $cols = array(); $fmts = $table ? app_cols($table) : array(); $pk = $table ? app_pk($table) : null;
    foreach ($rows as $r) { foreach ((array) $r as $k => $v) { if (!in_array($k, $cols, true)) { $cols[] = $k; } } }
    $h = '<div class="tbl-wrap"><table><tr>' . ($pk ? '<th></th>' : '');
    foreach ($cols as $c) { $h .= '<th>' . e(str_replace('_', ' ', $c)) . '</th>'; }
    $h .= '</tr>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        if ($pk) { $h .= '<td>' . (isset($r[$pk]) ? '<a class="btn ghost sm" href="/admin/app/data/?t=' . e(rawurlencode($table)) . '&amp;edit=' . e(rawurlencode((string) $r[$pk])) . '">Edit</a>' : '') . '</td>'; }
        foreach ($cols as $c) { $h .= '<td>' . app_cell($c, isset($r[$c]) ? $r[$c] : null, isset($fmts[$c]) ? $fmts[$c] : '') . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</table></div>';
}
function app_post($k, $max = 2000) { return clean_str(isset($_POST[$k]) ? $_POST[$k] : '', $max); }
function app_get($k, $max = 200) { return clean_str(isset($_GET[$k]) ? $_GET[$k] : '', $max); }
function app_err_box($r, $prefix = '') { return (!$r || $r['ok']) ? '' : '<div class="flash err">' . e($prefix . $r['error']) . '</div>'; }
function app_pager_simple($page, $count, $per) {
    if ($page <= 1 && $count < $per) { return ''; }
    $h = '<div class="pager">';
    if ($page > 1) { $h .= '<a href="' . e(qs(array('page' => $page - 1))) . '">&larr; Prev</a>'; }
    $h .= '<span>Page ' . (int) $page . '</span>';
    if ($count >= $per) { $h .= '<a href="' . e(qs(array('page' => $page + 1))) . '">Next &rarr;</a>'; }
    return $h . '</div>';
}

/** Page guard: the normal /admin/ login only. Shows a setup message if the key or schema is not reachable. */
function app_require() {
    admin_require_login();
    $p = app_config_problem();
    $s = $p === '' ? app_schema() : null;
    if ($p !== '' || !$s['ok'] || !app_profiles_table()) {
        app_head('Mobile App', 'users');
        if ($p !== '') { echo '<div class="flash err">' . e($p) . '</div>'; }
        elseif (!$s['ok']) { echo '<div class="flash err">Could not read the <b>drotix</b> schema: ' . e($s['error']) . '</div><div class="panel">Check that the key is the <b>secret</b> key and that <b>drotix</b> is listed in Supabase &rarr; Project Settings &rarr; Data API &rarr; Exposed schemas.</div>'; }
        else { echo '<div class="flash err">No profiles table found in the drotix schema.</div>'; }
        admin_foot(); exit;
    }
}
/** Header for Mobile App pages: CSP allowing avatar images, sidebar, sub navigation. */
function app_head($title, $sub) {
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; form-action 'self'; frame-ancestors 'none'", true);
    admin_head($title, 'app');
    $tabs = array('users' => array('/admin/app/users/', 'Players'), 'dash' => array('/admin/app/', 'Stats'), 'data' => array('/admin/app/data/', 'All data'));
    foreach (array('reports' => '/report/', 'appeals' => '/appeal/', 'tournaments' => '/^tournaments?$/') as $k => $re) {
        if (!empty($GLOBALS['__app_schema']['tables'])) {
            foreach (array_keys($GLOBALS['__app_schema']['tables']) as $t) { if (preg_match($re, $t)) { $tabs['t:' . $t] = array('/admin/app/data/?t=' . rawurlencode($t), ucfirst(str_replace('_', ' ', $t))); break; } }
        }
    }
    $tabs['audit'] = array('/admin/app/audit/', 'Audit log');
    echo '<link rel="stylesheet" href="/admin/assets/app.css?v=2"><script src="/admin/assets/app.js?v=2" defer></script>';
    echo '<div class="apptop"><nav class="subnav">';
    foreach ($tabs as $k => $t) { echo '<a href="' . e($t[0]) . '"' . ($k === $sub ? ' class="on"' : '') . '>' . e($t[1]) . '</a>'; }
    echo '</nav></div>';
}
