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
    $h = isset($extra['apikey']) ? array('Accept: application/json') : array('apikey: ' . $key, 'Accept: application/json');
    if (strpos($key, 'eyJ') === 0 && !isset($extra['Authorization'])) { $h[] = 'Authorization: Bearer ' . $key; }
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
/**
 * Session of an app admin (drotix profiles.is_admin = true), created by the SERVER with the service key:
 * admin generate_link (no email is sent) + verify -> access token. No password, no login screen.
 * The game's own admin_* functions check that the caller is an app admin, so they need this token.
 * Optional: 'app_admin_email' in includes/config.php picks which admin account is used.
 */
function app_admin_token($force = false) {
    $c = isset($_SESSION['app_admin_tok']) ? $_SESSION['app_admin_tok'] : null;
    if (!$force && $c && $c['exp'] - 120 > time()) { return $c['token']; }
    if (!$force && $c && isset($c['fail']) && $c['fail'] > time() - 60) { return null; }   // do not retry on every request
    $steps = array(); $tok = null;
    $email = (string) cfg('app_admin_email', '');
    if ($email !== '') { $steps[] = array(true, 'Using app_admin_email from config.php: ' . $email); }
    else {
        $pt = app_profiles_table(); $pc = app_cols($pt); $idc = app_profile_idcol();
        if (!isset($pc['is_admin'])) { $steps[] = array(false, 'The profiles table has no is_admin column.'); }
        else {
            list($rows, , $rr) = app_select($pt, array('is_admin' => 'eq.true', 'select' => $idc), 10, 0, false);
            if (!$rr['ok']) { $steps[] = array(false, 'Could not read profiles: ' . $rr['error']); }
            elseif (!$rows) { $steps[] = array(false, 'No player has is_admin = true yet.'); }
            else {
                $steps[] = array(true, count($rows) . ' app admin profile(s) found.');
                foreach ($rows as $r) {
                    $u = app_auth_get($r[$idc]);
                    if ($u['ok'] && g($u['data'], 'email')) { $email = $u['data']['email']; break; }
                    $steps[] = array(false, 'Admin ' . substr($r[$idc], 0, 8) . ' has no login email' . ($u['ok'] ? '' : ' (' . $u['error'] . ')') . '.');
                }
                if ($email !== '') { $steps[] = array(true, 'Acting as ' . $email); }
            }
        }
    }
    if ($email !== '') {
        $l = app_http('POST', '/auth/v1/admin/generate_link', array('type' => 'magiclink', 'email' => $email));
        $hash = gx($l['data'], array('hashed_token', 'properties.hashed_token'));
        if (!$l['ok'] || !$hash) { $steps[] = array(false, 'Could not create a session link: ' . ($l['error'] !== '' ? $l['error'] : 'no token returned')); }
        else {
            $last = '';
            foreach (array('magiclink', 'email') as $ty) {
                $v = app_http('POST', '/auth/v1/verify', array('type' => $ty, 'token_hash' => $hash));
                if ($v['ok'] && !empty($v['data']['access_token'])) { $tok = $v['data']['access_token']; $exp = time() + (int) g($v['data'], 'expires_in', 3600); break; }
                $last = $v['error'];
            }
            $steps[] = $tok ? array(true, 'Admin session created.') : array(false, 'Could not open the admin session: ' . $last);
        }
    }
    $_SESSION['app_admin_steps'] = $steps;
    $_SESSION['app_admin_tok'] = $tok ? array('token' => $tok, 'exp' => $exp, 'email' => $email) : array('fail' => time(), 'exp' => 0, 'token' => null, 'email' => $email);
    return $tok;
}
/** Short reason why there is no admin session ('' when fine). */
function app_admin_why() {
    foreach (array_reverse(isset($_SESSION['app_admin_steps']) ? $_SESSION['app_admin_steps'] : array()) as $st) { if (!$st[0]) { return $st[1]; } }
    return '';
}
function app_admin_email() { return isset($_SESSION['app_admin_tok']['email']) ? (string) $_SESSION['app_admin_tok']['email'] : ''; }

/** Call an RPC with values by POSITION: the real argument names are read from the database schema.
 *  $fallbackNames is used when the schema does not list the function. */
function app_rpc_pos($fn, $values, $fallbackNames) {
    $sc = app_schema(); $names = isset($sc['rpc_args'][$fn]) && $sc['rpc_args'][$fn] ? $sc['rpc_args'][$fn] : $fallbackNames;
    $params = array(); foreach (array_values($values) as $i => $v) { if (isset($names[$i])) { $params[$names[$i]] = $v; } }
    return app_rpc($fn, $params);
}
/** Call a drotix RPC: as an app admin when possible, otherwise with the service key. */
function app_rpc($fn, $params = array()) {
    if (!app_valid_ident($fn)) { return array('ok' => false, 'data' => null, 'error' => 'Invalid function'); }
    $h = array('Content-Profile' => APP_SCHEMA, 'Accept-Profile' => APP_SCHEMA);
    $tok = strpos($fn, 'admin_') === 0 ? app_admin_token() : null;
    if ($tok) {
        $h['Authorization'] = 'Bearer ' . $tok;
        $pub = (string) cfg('supabase_anon_key', '');
        if ($pub !== '') { $h['apikey'] = $pub; }   // optional: publishable key for calls made as the admin user
    }
    $r = app_http('POST', '/rest/v1/rpc/' . $fn, (object) $params, $h);
    if (!$r['ok'] && $tok && $r['status'] === 401) { unset($_SESSION['app_admin_tok']); }
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
    $c = isset($_SESSION['app_schema4']) ? $_SESSION['app_schema4'] : null;
    if (!$c || $c['at'] < time() - 600 || isset($_GET['refresh_schema'])) {
        $r = app_http('GET', '/rest/v1/', null, array('Accept-Profile' => APP_SCHEMA, 'Accept' => 'application/openapi+json'));
        $c = array('at' => time(), 'ok' => false, 'error' => $r['error'], 'tables' => array(), 'rpc' => array());
        if ($r['ok'] && is_array($r['data'])) {
            $c['ok'] = true;
            foreach ((array) g($r['data'], 'definitions', array()) as $t => $def) {
                $cols = array(); $pk = null; $enums = array(); $defaults = array();
                foreach ((array) g($def, 'properties', array()) as $col => $p) {
                    $cols[$col] = (string) gx($p, array('format', 'type'), 'text');
                    if (strpos((string) g($p, 'description', ''), '<pk/>') !== false && $pk === null) { $pk = $col; }
                    if (is_array(g($p, 'enum'))) { $enums[$col] = array_values($p['enum']); }
                    if (is_array($p) && array_key_exists('default', $p)) { $defaults[$col] = true; }
                }
                if ($pk === null && isset($cols['id'])) { $pk = 'id'; }
                $c['tables'][$t] = array('cols' => $cols, 'pk' => $pk, 'required' => array_values((array) g($def, 'required', array())), 'enums' => $enums, 'defaults' => $defaults);
            }
            ksort($c['tables']);
            foreach ((array) g($r['data'], 'paths', array()) as $p => $def) {
                if (strpos($p, '/rpc/') !== 0) { continue; }
                $fn = substr($p, 5); $c['rpc'][] = $fn;
                $args = array();   // argument names in declared order (for calls by position)
                foreach ((array) g($def, 'post.parameters', array()) as $prm) { foreach (array_keys((array) g($prm, 'schema.properties', array())) as $a) { $args[] = $a; } }
                $c['rpc_args'][$fn] = $args;
            }
        }
        if ($c['ok']) { $_SESSION['app_schema4'] = $c; }
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

/* ================= legal documents + app releases (game functions) ================= */
/** {status, privacy: Doc|null, terms: Doc|null}  Doc = {id, doc_type, version, title, sections:[{heading, body}], contact_website, contact_email, summary, requires_acceptance, published_at} */
function app_get_legal_documents() { return app_rpc('get_legal_documents'); }
/** {status, versions: [Doc + accepted_count], players_total} */
function app_list_legal() {
    $r = app_rpc('admin_list_legal');
    if ($r['ok']) { $r['via'] = 'game'; return $r; }
    $t = app_legal_table();
    if (!$t) { return $r; }
    $q = array('order' => 'version.desc');
    list($rows, , $x) = app_select($t, $q, 500, 0, false);
    if (!$x['ok']) { return $r; }
    $acc = app_legal_accept_table();
    foreach ($rows as &$v) {
        $v['accepted_count'] = null;
        if ($acc && isset($v['id'])) { $v['accepted_count'] = app_count($acc['table'], array($acc['doc'] => 'eq.' . $v['id'])); }
        if (isset($v['sections']) && is_string($v['sections'])) { $d = json_decode($v['sections'], true); if (is_array($d)) { $v['sections'] = $d; } }
    }
    unset($v);
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'versions' => $rows, 'players_total' => (int) app_count(app_profiles_table())));
}
/** The game's legal documents table (doc_type + version). */
function app_legal_table() {
    foreach (app_tables() as $t => $i) { if (isset($i['cols']['doc_type'], $i['cols']['version']) && !preg_match('/accept|consent/', $t)) { return $t; } }
    return null;
}
/** Acceptance table: array(table, doc column) or null. */
function app_legal_accept_table() {
    foreach (app_tables() as $t => $i) {
        if (!preg_match('/(accept|consent|agree)/', $t)) { continue; }
        foreach (array('document_id', 'legal_document_id', 'doc_id', 'legal_id', 'version_id') as $c) { if (isset($i['cols'][$c])) { return array('table' => $t, 'doc' => $c); } }
    }
    return null;
}
/** -> {status, id, version}. $sections = array(array('heading' => ..., 'body' => ...), ...) */
function app_publish_legal($type, $title, $sections, $website, $email, $summary, $requiresAcceptance) {
    $r = app_rpc('admin_publish_legal', array('p_doc_type' => $type, 'p_title' => $title, 'p_sections' => $sections,
        'p_contact_website' => $website !== '' ? $website : null, 'p_contact_email' => $email !== '' ? $email : null,
        'p_summary' => $summary !== '' ? $summary : null, 'p_requires_acceptance' => (bool) $requiresAcceptance));
    if ($r['ok']) { return $r; }
    // fallback: write version N+1 straight into the game's table; the app shows the newest version to every player
    $t = app_legal_table();
    if (!$t) { return $r; }
    list($last, , ) = app_select($t, array('doc_type' => 'eq.' . $type, 'order' => 'version.desc', 'select' => 'version'), 1, 0, false);
    $ver = $last ? ((int) $last[0]['version']) + 1 : 1;
    $c = app_cols($t);
    $row = array('doc_type' => $type, 'version' => $ver);
    foreach (array('title' => $title, 'sections' => $sections, 'contact_website' => $website !== '' ? $website : null, 'contact_email' => $email !== '' ? $email : null,
                   'summary' => $summary !== '' ? $summary : null, 'requires_acceptance' => (bool) $requiresAcceptance, 'published_at' => date('c'), 'is_published' => true, 'active' => true) as $k => $v) {
        if (isset($c[$k])) { $row[$k] = $v; }
    }
    $row = app_fill_required($t, $row);
    $x = app_db('POST', $t, array(), $row, 'return=representation');
    if (!$x['ok']) { $x['error'] = 'Publish failed: ' . $x['error'] . ' (game function: ' . $r['error'] . ')'; return $x; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'id' => g($x['data'], '0.id'), 'version' => $ver));
}
/** {status, items: [{id, platform, version_name, version_code, min_supported_version_code, update_available, apk_url, apk_sha256, apk_size_bytes, ios_app_store_url, release_notes[], published_at}]} */
function app_list_app_releases($limit = 30) {
    $r = app_rpc('admin_list_app_releases', array('p_limit' => (int) $limit));
    if ($r['ok']) { $r['via'] = 'game'; return $r; }
    $t = app_release_table();
    if (!$t) { return $r; }
    $c = app_cols($t);
    $q = array('order' => (isset($c['published_at']) ? 'published_at' : (isset($c['created_at']) ? 'created_at' : 'version_code')) . '.desc');
    list($rows, , $x) = app_select($t, $q, (int) $limit, 0, false);
    if (!$x['ok']) { return $r; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'items' => $rows));
}
/** The game's app releases table (version_code + apk / min supported). */
function app_release_table() {
    foreach (app_tables() as $t => $i) { if (isset($i['cols']['version_code']) && (isset($i['cols']['apk_url']) || isset($i['cols']['min_supported_version_code']))) { return $t; } }
    return null;
}
/** -> {status, id} | {status:'error', message} */
function app_publish_app_release($a) {
    $r = app_rpc('admin_publish_app_release', array(
        'p_version_name' => $a['version_name'], 'p_version_code' => (int) $a['version_code'], 'p_min_supported_version_code' => (int) $a['min_supported'],
        'p_apk_url' => $a['apk_url'] !== '' ? $a['apk_url'] : null, 'p_apk_sha256' => $a['apk_sha256'] !== '' ? $a['apk_sha256'] : null,
        'p_apk_size_bytes' => $a['apk_size'] !== '' ? (int) $a['apk_size'] : null, 'p_release_notes' => $a['notes'],
        'p_platform' => $a['platform'], 'p_ios_app_store_url' => $a['ios_url'] !== '' ? $a['ios_url'] : null, 'p_update_available' => (bool) $a['update_available']));
    if ($r['ok']) { return $r; }
    // fallback: insert the release row directly; the app checks the newest row for every player
    $t = app_release_table();
    if (!$t) { return $r; }
    $c = app_cols($t); $row = array();
    foreach (array('platform' => $a['platform'], 'version_name' => $a['version_name'], 'version_code' => (int) $a['version_code'], 'min_supported_version_code' => (int) $a['min_supported'],
                   'apk_url' => $a['apk_url'] !== '' ? $a['apk_url'] : null, 'apk_sha256' => $a['apk_sha256'] !== '' ? $a['apk_sha256'] : null,
                   'apk_size_bytes' => $a['apk_size'] !== '' ? (int) $a['apk_size'] : null, 'release_notes' => $a['notes'],
                   'ios_app_store_url' => $a['ios_url'] !== '' ? $a['ios_url'] : null, 'update_available' => (bool) $a['update_available'], 'published_at' => date('c')) as $k => $v) {
        if (isset($c[$k])) { $row[$k] = $v; }
    }
    $row = app_fill_required($t, $row);
    $x = app_db('POST', $t, array(), $row, 'return=representation');
    if (!$x['ok']) { $x['error'] = 'Publish failed: ' . $x['error'] . ' (game function: ' . $r['error'] . ')'; return $x; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'id' => g($x['data'], '0.id')));
}
/* ---------- coupons ----------
 * Coupon = {code, coins, max_uses|null, used_count, starts_at|null, expires_at|null, active, note|null, created_at, updated_at,
 *           state: 'live'|'scheduled'|'expired'|'used_up'|'disabled'} */
/** {status, total_coins_given, items: [Coupon]} */
function app_list_coupons($search) {
    $r = app_rpc('admin_list_coupons', array('p_search' => $search !== '' ? $search : null));
    if ($r['ok']) { return $r; }
    $t = app_coupon_table();
    if (!$t) { return $r; }
    $q = array('order' => (array_key_exists('created_at', app_cols($t)) ? 'created_at' : 'code') . '.desc');
    if ($search !== '') { $s = pg_safe($search); $q['or'] = '(code.ilike.*' . $s . '*' . (array_key_exists('note', app_cols($t)) ? ',note.ilike.*' . $s . '*' : '') . ')'; }
    list($rows, , $x) = app_select($t, $q, 1000, 0, false);
    if (!$x['ok']) { return $r; }
    $given = 0;
    foreach ($rows as &$c) { $c = app_coupon_norm($c); $given += (int) $c['used_count'] * (int) $c['coins']; }
    unset($c);
    $rt = app_coupon_redemption_table();
    if ($rt && $rt['coins']) {   // exact coins given (coins can change over time)
        list($all, , $y) = app_select($rt['table'], array('select' => $rt['coins']), 100000, 0, false);
        if ($y['ok']) { $given = 0; foreach ($all as $a) { $given += (int) g($a, $rt['coins'], 0); } }
    }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'total_coins_given' => $given, 'items' => $rows));
}
/** The game's coupons table (code + coins). */
function app_coupon_table() {
    if (app_has_table('coupons') && array_key_exists('code', app_cols('coupons'))) { return 'coupons'; }
    foreach (app_tables() as $t => $i) { if (preg_match('/coupon|promo|voucher/', $t) && isset($i['cols']['code'], $i['cols']['coins'])) { return $t; } }
    return null;
}
/** Redemptions table: array(table, link col, link kind code|id, user col, coins col|null, when col|null) or null. */
function app_coupon_redemption_table() {
    $ct = app_coupon_table();
    foreach (app_tables() as $t => $i) {
        if ($t === $ct || !preg_match('/(coupon|promo|voucher|redeem|redemption|referral_code_use|code_use)/', $t)) { continue; }
        $link = null; $kind = 'code';
        foreach (array('coupon_code', 'code') as $c) { if (isset($i['cols'][$c])) { $link = $c; break; } }
        if (!$link) { foreach (array('coupon_id', 'promo_id', 'voucher_id') as $c) { if (isset($i['cols'][$c])) { $link = $c; $kind = 'id'; break; } } }
        $u = app_owner_col($t);
        if (!$link || !$u) { continue; }
        $coins = null; foreach (array('coins', 'coins_granted', 'amount', 'reward_coins') as $c) { if (isset($i['cols'][$c])) { $coins = $c; break; } }
        $when = null; foreach (array('redeemed_at', 'created_at', 'used_at') as $c) { if (isset($i['cols'][$c])) { $when = $c; break; } }
        return array('table' => $t, 'link' => $link, 'kind' => $kind, 'user' => $u, 'coins' => $coins, 'when' => $when);
    }
    return null;
}
/** Make a coupon row look like the game's Coupon object (adds state). */
function app_coupon_norm($c) {
    $c['used_count'] = (int) gx($c, array('used_count', 'uses', 'redeemed_count'), 0);
    $c['max_uses'] = isset($c['max_uses']) ? $c['max_uses'] : null;
    $c['active'] = isset($c['active']) ? (bool) $c['active'] : (isset($c['is_active']) ? (bool) $c['is_active'] : true);
    if (!isset($c['state'])) {
        $now = time();
        if (!$c['active']) { $c['state'] = 'disabled'; }
        elseif (!empty($c['expires_at']) && strtotime($c['expires_at']) <= $now) { $c['state'] = 'expired'; }
        elseif ($c['max_uses'] !== null && $c['used_count'] >= (int) $c['max_uses']) { $c['state'] = 'used_up'; }
        elseif (!empty($c['starts_at']) && strtotime($c['starts_at']) > $now) { $c['state'] = 'scheduled'; }
        else { $c['state'] = 'live'; }
    }
    return $c;
}
/** Create or edit (same code). -> {status, created: bool, coupon: Coupon} | {status:'error', message} */
function app_save_coupon($code, $coins, $maxUses, $startsAt, $expiresAt, $note, $active) {
    $code = strtoupper($code);
    $r = app_rpc('admin_save_coupon', array('p_code' => $code, 'p_coins' => (int) $coins, 'p_max_uses' => $maxUses === null ? null : (int) $maxUses,
        'p_starts_at' => $startsAt, 'p_expires_at' => $expiresAt, 'p_note' => $note !== '' ? $note : null, 'p_active' => (bool) $active));
    if ($r['ok']) { return $r; }
    // fallback: write the coupon straight into the game's coupons table (same rules as the game)
    $t = app_coupon_table();
    if (!$t) { return $r; }
    $pt = app_profiles_table();
    if (array_key_exists('referral_code', app_cols($pt)) && app_count($pt, array('referral_code' => 'ilike.' . $code))) {
        return array('ok' => false, 'error' => 'This code is already a player\'s referral code. Choose another code.', 'data' => null);
    }
    $c = app_cols($t);
    list($old, , ) = app_select($t, array('code' => 'eq.' . $code), 1, 0, false);
    $row = array();
    foreach (array('coins' => (int) $coins, 'max_uses' => $maxUses === null ? null : (int) $maxUses, 'starts_at' => $startsAt, 'expires_at' => $expiresAt,
                   'note' => $note !== '' ? $note : null, 'active' => (bool) $active, 'is_active' => (bool) $active, 'updated_at' => date('c')) as $k => $v) {
        if (isset($c[$k])) { $row[$k] = $v; }
    }
    if ($old) { $x = app_db('PATCH', $t, array('code' => 'eq.' . $code), $row, 'return=representation'); $created = false; }
    else { $row['code'] = $code; $x = app_db('POST', $t, array(), app_fill_required($t, $row), 'return=representation'); $created = true; }
    if (!$x['ok']) { $x['error'] = 'Save failed: ' . $x['error']; return $x; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'created' => $created, 'coupon' => app_coupon_norm((array) g($x['data'], '0', array()))));
}
/** -> {status, coupon} */
function app_set_coupon_active($code, $active) {
    $r = app_rpc('admin_set_coupon_active', array('p_code' => $code, 'p_active' => (bool) $active));
    if ($r['ok']) { return $r; }
    $t = app_coupon_table();
    if (!$t) { return $r; }
    $col = array_key_exists('active', app_cols($t)) ? 'active' : (array_key_exists('is_active', app_cols($t)) ? 'is_active' : null);
    if (!$col) { return $r; }
    $patch = array($col => (bool) $active); if (array_key_exists('updated_at', app_cols($t))) { $patch['updated_at'] = date('c'); }
    $x = app_db('PATCH', $t, array('code' => 'eq.' . strtoupper($code)), $patch, 'return=representation');
    if (!$x['ok']) { return $x; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'coupon' => app_coupon_norm((array) g($x['data'], '0', array()))));
}
/** -> {status, total, items: [{user_id, username, player_no, coins, redeemed_at}]} */
function app_coupon_redemptions($code, $limit = 100, $offset = 0) {
    $r = app_rpc('admin_coupon_redemptions', array('p_code' => $code, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset));
    if ($r['ok']) { return $r; }
    $rt = app_coupon_redemption_table();
    if (!$rt) { return $r; }
    $val = strtoupper($code);
    if ($rt['kind'] === 'id') {
        $ct = app_coupon_table(); list($cr, , ) = $ct ? app_select($ct, array('code' => 'eq.' . $val), 1, 0, false) : array(array(), null, null);
        if (!$cr || !isset($cr[0]['id'])) { return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'total' => 0, 'items' => array())); }
        $val = $cr[0]['id'];
    }
    $q = array($rt['link'] => 'eq.' . $val); if ($rt['when']) { $q['order'] = $rt['when'] . '.desc'; }
    list($rows, $total, $x) = app_select($rt['table'], $q, (int) $limit, (int) $offset, true);
    if (!$x['ok']) { return $r; }
    $ids = array(); foreach ($rows as $w) { $ids[] = $w[$rt['user']]; }
    $prof = array();
    if ($ids) { $pt = app_profiles_table(); $idc = app_profile_idcol(); list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', array_unique($ids)) . ')'), count($ids) + 5, 0, false); foreach ($ps as $p) { $prof[$p[$idc]] = $p; } }
    $items = array();
    foreach ($rows as $w) {
        $u = $w[$rt['user']]; $p = isset($prof[$u]) ? $prof[$u] : array();
        $items[] = array('user_id' => $u, 'username' => g($p, 'username'), 'player_no' => g($p, 'player_no'), 'coins' => $rt['coins'] ? g($w, $rt['coins']) : null, 'redeemed_at' => $rt['when'] ? g($w, $rt['when']) : null);
    }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'total' => $total !== null ? $total : count($items), 'items' => $items));
}

/* ---------- coin purchases (money spent) per player, read from the game's purchase table ---------- */
/** Detect the purchase table + its money / coins / currency / status columns. null when none. */
function app_purchase_source() {
    static $src = false;
    if ($src !== false) { return $src; }
    $src = null;
    $moneyCols = array('amount_paid_minor', 'price_minor', 'price_paise', 'amount_minor', 'paid_minor', 'revenue_minor', 'price_amount_micros', 'price_micros', 'amount_micros', 'amount_paid', 'price', 'amount', 'amount_inr', 'paid_amount', 'price_inr');
    $tabs = app_tables();
    if (isset($tabs['iap_purchases'])) { $tabs = array('iap_purchases' => $tabs['iap_purchases']) + $tabs; }
    foreach ($tabs as $t => $i) {
        if (!preg_match('/(purchase|iap|order|payment|billing)/', $t)) { continue; }
        $uc = app_owner_col($t); if (!$uc) { continue; }
        $money = null; foreach ($moneyCols as $c) { if (isset($i['cols'][$c])) { $money = $c; break; } }
        $coins = null; foreach (array('coins', 'coins_granted', 'coin_amount', 'amount_coins', 'coins_minor', 'granted_coins') as $c) { if (isset($i['cols'][$c])) { $coins = $c; break; } }
        if (!$money && !$coins) { continue; }
        $cur = null; foreach (array('currency', 'currency_code', 'price_currency_code', 'price_currency') as $c) { if (isset($i['cols'][$c])) { $cur = $c; break; } }
        $when = null; foreach (array('purchased_at', 'created_at', 'paid_at', 'granted_at') as $c) { if (isset($i['cols'][$c])) { $when = $c; break; } }
        $prod = null; foreach (array('product_id', 'sku', 'product', 'pack_id', 'item_id') as $c) { if (isset($i['cols'][$c])) { $prod = $c; break; } }
        $src = array('table' => $t, 'user' => $uc, 'money' => $money, 'coins' => $coins, 'currency' => $cur, 'when' => $when, 'product' => $prod, 'status' => isset($i['cols']['status']) ? 'status' : null);
        break;
    }
    return $src;
}
/** Money value of one purchase in major units (e.g. 49.00), from the column's naming. */
function app_money_value($col, $v) {
    if (!is_numeric($v)) { return 0.0; }
    if (preg_match('/micros/', $col)) { return $v / 1000000; }
    if (preg_match('/(_minor|paise)$/', $col)) { return $v / 100; }
    return (float) $v;
}
function app_purchase_ok($row, $src) {
    if (!$src || !$src['status']) { return true; }
    return !preg_match('/(refund|fail|cancel|pending|revok|void|chargeback|reject)/i', (string) g($row, $src['status'], ''));
}
/**
 * Purchases of many players: array('source' => src|null, 'by' => array(user_id => array(count, money, coins, currency, last_at)),
 *  'totals' => array(buyers, count, money, coins, currency), 'note' => text)
 * Falls back to coin transactions with reason iap_grant (coins only) when no purchase table exists.
 */
function app_purchase_stats($userIds) {
    $ids = array_values(array_unique(array_filter($userIds, 'uuid_ok')));
    $by = array(); $tot = array('buyers' => 0, 'count' => 0, 'money' => 0.0, 'coins' => 0, 'currency' => '');
    $src = app_purchase_source(); $note = '';
    $add = function ($uid, $money, $coins, $cur, $at) use (&$by, &$tot) {
        if (!isset($by[$uid])) { $by[$uid] = array('count' => 0, 'money' => 0.0, 'coins' => 0, 'currency' => $cur, 'last_at' => null); $tot['buyers']++; }
        $by[$uid]['count']++; $by[$uid]['money'] += $money; $by[$uid]['coins'] += $coins;
        if ($at && (!$by[$uid]['last_at'] || strcmp($at, $by[$uid]['last_at']) > 0)) { $by[$uid]['last_at'] = $at; }
        $tot['count']++; $tot['money'] += $money; $tot['coins'] += $coins; if ($cur && !$tot['currency']) { $tot['currency'] = $cur; }
    };
    if ($src) {
        foreach (array_chunk($ids, 150) as $ch) {
            list($rows, , ) = app_select($src['table'], array($src['user'] => 'in.(' . implode(',', $ch) . ')'), 10000, 0, false);
            foreach ($rows as $r) {
                if (!app_purchase_ok($r, $src)) { continue; }
                $add($r[$src['user']], $src['money'] ? app_money_value($src['money'], g($r, $src['money'])) : 0, $src['coins'] ? (int) g($r, $src['coins'], 0) : 0,
                     $src['currency'] ? (string) g($r, $src['currency'], '') : '', $src['when'] ? g($r, $src['when']) : null);
            }
        }
        if (!$src['money']) { $note = 'The purchase table has no price column, so only coins are shown.'; }
    } else {
        $tt = app_tx_table(); $oc = $tt ? app_owner_col($tt) : null;
        if ($tt && $oc && array_key_exists('reason', app_cols($tt))) {
            foreach (array_chunk($ids, 150) as $ch) {
                list($rows, , ) = app_select($tt, array($oc => 'in.(' . implode(',', $ch) . ')', 'reason' => 'eq.iap_grant'), 10000, 0, false);
                foreach ($rows as $r) { $add($r[$oc], 0, (int) g($r, 'amount_minor', 0), '', g($r, 'created_at')); }
            }
            $note = 'No purchase table with prices was found: showing coins bought (iap_grant), not money.';
        } else { $note = 'No purchase data found.'; }
    }
    return array('source' => $src, 'by' => $by, 'totals' => $tot, 'note' => $note);
}
/** Purchase history rows of one player (newest first). array(src|null, rows) */
function app_purchase_history($uid) {
    $src = app_purchase_source();
    if ($src) {
        $q = array($src['user'] => 'eq.' . $uid); if ($src['when']) { $q['order'] = $src['when'] . '.desc'; }
        list($rows, , ) = app_select($src['table'], $q, 500, 0, false);
        return array($src, $rows);
    }
    $tt = app_tx_table(); $oc = $tt ? app_owner_col($tt) : null;
    if (!$tt || !$oc) { return array(null, array()); }
    list($rows, , ) = app_select($tt, array($oc => 'eq.' . $uid, 'reason' => 'eq.iap_grant', 'order' => 'created_at.desc'), 500, 0, false);
    return array(null, $rows);
}
function app_money($v, $cur) {
    $sym = array('INR' => "\u{20B9}", 'USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}");
    $c = strtoupper((string) $cur);
    return (isset($sym[$c]) ? $sym[$c] : ($c !== '' ? $c . ' ' : "\u{20B9}")) . number_format((float) $v, 2);
}

/* ---------- home banners + coin packs (whole app) ----------
 * Banner = {id, title, subtitle, image_url, action_type 'none'|'route'|'url', action_value, audience 'all'|'signed_in'|'guests',
 *           starts_at, ends_at, active, sort_order, created_at, updated_at, state 'live'|'scheduled'|'ended'|'disabled'}
 * Pack   = {id, product_id, title, coins, bonus_coins, total_coins, price_inr, badge, active, sort_order, created_at, updated_at} */
const APP_BANNER_BUCKET = 'drotix-banners';
function app_banner_table() { return app_has_table('home_banners') ? 'home_banners' : app_find_table(array('image_url', 'action_type'), '/banner|poster|promo/'); }
function app_pack_table() { return app_has_table('coin_packages') ? 'coin_packages' : app_find_table(array('product_id', 'coins'), '/pack|package|product|shop/'); }
function app_banner_norm($b) {
    $b['active'] = isset($b['active']) ? (bool) $b['active'] : true;
    $b['sort_order'] = (int) g($b, 'sort_order', 0);
    if (!isset($b['state'])) {
        $now = time();
        if (!$b['active']) { $b['state'] = 'disabled'; }
        elseif (!empty($b['ends_at']) && strtotime($b['ends_at']) <= $now) { $b['state'] = 'ended'; }
        elseif (!empty($b['starts_at']) && strtotime($b['starts_at']) > $now) { $b['state'] = 'scheduled'; }
        else { $b['state'] = 'live'; }
    }
    return $b;
}
function app_pack_norm($k) {
    $k['active'] = isset($k['active']) ? (bool) $k['active'] : true;
    $k['bonus_coins'] = (int) g($k, 'bonus_coins', 0);
    $k['total_coins'] = isset($k['total_coins']) ? (int) $k['total_coins'] : (int) g($k, 'coins', 0) + $k['bonus_coins'];
    $k['sort_order'] = (int) g($k, 'sort_order', 0);
    return $k;
}
function app_sort_rows($rows) { usort($rows, function ($a, $b) { return ((int) g($a, 'sort_order', 0) - (int) g($b, 'sort_order', 0)) ?: strcmp((string) g($a, 'created_at', ''), (string) g($b, 'created_at', '')); }); return $rows; }

/** {status, items: [Banner]} */
function app_list_banners() {
    $r = app_rpc('admin_list_banners');
    if ($r['ok']) { $r['data']['items'] = app_sort_rows(array_map('app_banner_norm', (array) g($r['data'], 'items', array()))); return $r; }
    $t = app_banner_table(); if (!$t) { return $r; }
    list($rows, , $x) = app_select($t, array(), 500, 0, false);
    if (!$x['ok']) { return $r; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'items' => app_sort_rows(array_map('app_banner_norm', $rows))));
}
/** $b = array(id|null, image_url, title, subtitle, action_type, action_value, audience, starts_at, ends_at, active, sort_order) -> {status, banner} */
function app_save_banner($b) {
    $r = app_rpc('admin_save_banner', array('p_id' => $b['id'] ?: null, 'p_image_url' => $b['image_url'], 'p_title' => $b['title'] !== '' ? $b['title'] : null,
        'p_subtitle' => $b['subtitle'] !== '' ? $b['subtitle'] : null, 'p_action_type' => $b['action_type'], 'p_action_value' => $b['action_value'] !== '' ? $b['action_value'] : null,
        'p_audience' => $b['audience'], 'p_starts_at' => $b['starts_at'], 'p_ends_at' => $b['ends_at'], 'p_active' => (bool) $b['active'], 'p_sort_order' => (int) $b['sort_order']));
    if ($r['ok']) { return $r; }
    $t = app_banner_table(); if (!$t) { return $r; }
    $c = app_cols($t); $row = array();
    foreach (array('image_url', 'title', 'subtitle', 'action_type', 'action_value', 'audience', 'starts_at', 'ends_at', 'active', 'sort_order') as $k) {
        if (isset($c[$k])) { $v = $b[$k]; $row[$k] = ($v === '' ? null : $v); }
    }
    if (isset($c['updated_at'])) { $row['updated_at'] = date('c'); }
    $x = $b['id'] ? app_db('PATCH', $t, array('id' => 'eq.' . $b['id']), $row, 'return=representation') : app_db('POST', $t, array(), app_fill_required($t, $row), 'return=representation');
    if (!$x['ok']) { $x['error'] = 'Save failed: ' . $x['error']; return $x; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'banner' => app_banner_norm((array) g($x['data'], '0', array()))));
}
function app_delete_banner($id) {
    $r = app_rpc('admin_delete_banner', array('p_id' => $id));
    if ($r['ok']) { return $r; }
    $t = app_banner_table(); if (!$t) { return $r; }
    return app_db('DELETE', $t, array('id' => 'eq.' . $id), null, 'return=minimal');
}
/** What the app shows now (live banners in order). */
function app_home_banners() { return app_rpc('get_home_banners'); }

/** {status, items: [Pack]} */
function app_list_coin_packages() {
    $r = app_rpc('admin_list_coin_packages');
    if ($r['ok']) { $r['data']['items'] = app_sort_rows(array_map('app_pack_norm', (array) g($r['data'], 'items', array()))); return $r; }
    $t = app_pack_table(); if (!$t) { return $r; }
    list($rows, , $x) = app_select($t, array(), 500, 0, false);
    if (!$x['ok']) { return $r; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'items' => app_sort_rows(array_map('app_pack_norm', $rows))));
}
/** $k = array(id|null, product_id, title, coins, price_inr, bonus_coins, badge, active, sort_order) -> {status, pack} */
function app_save_coin_package($k) {
    $r = app_rpc('admin_save_coin_package', array('p_id' => $k['id'] ?: null, 'p_product_id' => $k['product_id'], 'p_title' => $k['title'], 'p_coins' => (int) $k['coins'],
        'p_price_inr' => (float) $k['price_inr'], 'p_bonus_coins' => (int) $k['bonus_coins'], 'p_badge' => $k['badge'] !== '' ? $k['badge'] : null, 'p_active' => (bool) $k['active'], 'p_sort_order' => (int) $k['sort_order']));
    if ($r['ok']) { return $r; }
    $t = app_pack_table(); if (!$t) { return $r; }
    $c = app_cols($t); $row = array();
    foreach (array('product_id', 'title', 'coins', 'price_inr', 'bonus_coins', 'badge', 'active', 'sort_order') as $f) { if (isset($c[$f])) { $row[$f] = ($k[$f] === '' ? null : $k[$f]); } }
    if (isset($c['updated_at'])) { $row['updated_at'] = date('c'); }
    $x = $k['id'] ? app_db('PATCH', $t, array('id' => 'eq.' . $k['id']), $row, 'return=representation') : app_db('POST', $t, array(), app_fill_required($t, $row), 'return=representation');
    if (!$x['ok']) { $x['error'] = 'Save failed: ' . $x['error']; return $x; }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'pack' => app_pack_norm((array) g($x['data'], '0', array()))));
}
function app_set_coin_package_active($id, $on) {
    $r = app_rpc('admin_set_coin_package_active', array('p_id' => $id, 'p_active' => (bool) $on));
    if ($r['ok']) { return $r; }
    $t = app_pack_table(); if (!$t) { return $r; }
    return app_db('PATCH', $t, array('id' => 'eq.' . $id), array('active' => (bool) $on), 'return=minimal');
}
/** Active packs as the Shop shows them. */
function app_get_coin_packages() { return app_rpc('get_coin_packages'); }
/** Price: 2 decimals only when needed (49 -> 49, 49.5 -> 49.50). */
function app_inr($v) { $v = (float) $v; return "\u{20B9}" . (floor($v) == $v ? number_format($v) : number_format($v, 2)); }

/** One-time signed upload link to any bucket. array(uploadUrl, publicUrl, error) */
function app_signed_upload_to($bucket, $path, $upsert = false) {
    $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
    $r = app_http('POST', '/storage/v1/object/upload/sign/' . rawurlencode($bucket) . '/' . $enc, (object) array(), $upsert ? array('x-upsert' => 'true') : array());
    list($base, ) = app_base();
    $u = (string) gx($r['data'], array('url', 'signedURL'), '');
    if (!$r['ok'] || $u === '') { return array('', '', $r['error'] !== '' ? $r['error'] : 'Could not create the upload link (does the bucket ' . $bucket . ' exist?)'); }
    return array($base . '/storage/v1' . (strpos($u, '/') === 0 ? '' : '/') . $u, $base . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . $enc, '');
}

/* ---------- Google Play purchases (read only: verify-play-purchase writes them) ----------
 * Purchase = {id, user_id, username, platform, product_id, order_id, coins, price_inr, status 'credited'|'rejected'|'refunded', verified, created_at} */
/** {status, total, items: [Purchase]} — newest first. $userId null = every player. */
function app_list_iap_purchases($userId = null, $limit = 100, $offset = 0) {
    $r = app_rpc('admin_list_iap_purchases', array('p_user_id' => $userId, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset));
    if ($r['ok']) { $r['data']['items'] = (array) g($r['data'], 'items', array()); return $r; }
    $t = app_has_table('iap_purchases') ? 'iap_purchases' : null;
    $src = app_purchase_source();
    if (!$t && $src) { $t = $src['table']; }
    if (!$t) { return $r; }
    $c = app_cols($t); $uc = app_owner_col($t);
    $q = array('order' => (isset($c['created_at']) ? 'created_at' : 'id') . '.desc');
    if ($userId && $uc) { $q[$uc] = 'eq.' . $userId; }
    list($rows, $total, $x) = app_select($t, $q, (int) $limit, (int) $offset, true);
    if (!$x['ok']) { return $r; }
    $ids = array(); foreach ($rows as $w) { if ($uc && !empty($w[$uc])) { $ids[] = $w[$uc]; } }
    $names = array();
    if ($ids) { $pt = app_profiles_table(); $idc = app_profile_idcol(); list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', array_unique($ids)) . ')', 'select' => $idc . ',username'), 600, 0, false); foreach ($ps as $pp) { $names[$pp[$idc]] = g($pp, 'username'); } }
    $items = array();
    foreach ($rows as $w) {
        $u = $uc ? g($w, $uc) : null;
        $items[] = array('id' => g($w, 'id'), 'user_id' => $u, 'username' => $u && isset($names[$u]) ? $names[$u] : null, 'platform' => g($w, 'platform', 'android'),
            'product_id' => gx($w, array('product_id', 'sku')), 'order_id' => gx($w, array('order_id', 'google_order_id', 'transaction_id')), 'coins' => gx($w, array('coins', 'coins_granted', 'total_coins')),
            'price_inr' => gx($w, array('price_inr', 'amount_inr')), 'status' => g($w, 'status', 'credited'), 'verified' => (bool) gx($w, array('verified', 'is_verified'), false), 'created_at' => gx($w, array('created_at', 'purchased_at')));
    }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'total' => $total !== null ? $total : count($items), 'items' => $items));
}
/** Credited / rejected / coins / rupees of a list of purchases. */
function app_purchase_summary($items) {
    $s = array('credited' => 0, 'rejected' => 0, 'refunded' => 0, 'coins' => 0, 'inr' => 0.0);
    foreach ($items as $i) {
        $st = (string) g($i, 'status', '');
        if (isset($s[$st])) { $s[$st]++; }
        if ($st === 'credited') { $s['coins'] += (int) g($i, 'coins', 0); $s['inr'] += (float) g($i, 'price_inr', 0); }
    }
    return $s;
}
function app_purchase_badge($st) {
    $c = array('credited' => 'b-green', 'rejected' => 'b-red', 'refunded' => 'b-orange');
    return '<span class="badge ' . (isset($c[$st]) ? $c[$st] : 'b-grey') . '">' . e(ucfirst((string) $st ?: '-')) . '</span>';
}

/* ---------- support tickets ----------
 * Ticket = {id, ticket_no, ref "T-00012", user_id, category, subject, status, priority 'normal'|'high', order_id, created_at, updated_at,
 *           last_message_at, user_unread, admin_unread, closed_at} (+ username, player_no, avatar_url, last_message in lists) */
const APP_SUPPORT_BUCKET = 'drotix-support';
function app_ticket_table() { return app_has_table('support_tickets') ? 'support_tickets' : app_find_table(array('subject', 'category'), '/ticket|support/'); }
function app_ticket_msg_table() { return app_has_table('support_messages') ? 'support_messages' : app_find_table(array('ticket_id', 'body'), '/ticket|support/'); }
function app_ticket_ref($t) { $r = g($t, 'ref'); if ($r) { return (string) $r; } $n = g($t, 'ticket_no'); return $n !== null ? 'T-' . str_pad((string) (int) $n, 5, '0', STR_PAD_LEFT) : app_ref('T', g($t, 'id')); }
function app_ticket_count_unread($t) { $u = g($t, 'admin_unread'); return is_bool($u) ? ($u ? 1 : 0) : (int) $u; }
/** Add username / player_no / avatar_url to ticket rows (direct path). */
function app_ticket_people($rows) {
    $ids = array(); foreach ($rows as $t) { if (!empty($t['user_id'])) { $ids[] = $t['user_id']; } }
    if (!$ids) { return $rows; }
    $pt = app_profiles_table(); $idc = app_profile_idcol(); $pr = array();
    list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', array_unique($ids)) . ')'), 500, 0, false);
    foreach ($ps as $pp) { $pr[$pp[$idc]] = $pp; }
    foreach ($rows as &$t) { $p = isset($pr[g($t, 'user_id')]) ? $pr[$t['user_id']] : array(); foreach (array('username', 'player_no', 'avatar_url') as $k) { if (!isset($t[$k])) { $t[$k] = g($p, $k); } } }
    unset($t);
    return $rows;
}
/** {status, counts{open,in_progress,waiting_user,resolved,closed,unread}, items[]}. $status: null all | 'active' | one status. */
function app_list_tickets($status, $category, $search, $limit = 50, $offset = 0) {
    $r = app_rpc('admin_list_tickets', array('p_status' => $status ?: null, 'p_category' => $category ?: null, 'p_search' => $search !== '' ? $search : null, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset));
    if ($r['ok']) { return $r; }
    $t = app_ticket_table(); if (!$t) { return $r; }
    list($all, , $x) = app_select($t, array('order' => 'updated_at.desc'), 5000, 0, false);
    if (!$x['ok']) { list($all, , $x) = app_select($t, array(), 5000, 0, false); }
    if (!$x['ok']) { return $r; }
    $counts = array('open' => 0, 'in_progress' => 0, 'waiting_user' => 0, 'resolved' => 0, 'closed' => 0, 'unread' => 0);
    foreach ($all as $row) { $st = (string) g($row, 'status', 'open'); if (isset($counts[$st])) { $counts[$st]++; } if (app_ticket_count_unread($row) && !in_array($st, array('resolved', 'closed'), true)) { $counts['unread']++; } }
    $all = app_ticket_people($all);
    $needle = strtolower(trim((string) $search)); $digits = preg_replace('/\D/', '', $needle);
    $rows = array_values(array_filter($all, function ($row) use ($status, $category, $needle, $digits) {
        $st = (string) g($row, 'status', 'open');
        if ($status === 'active' && !in_array($st, array('open', 'in_progress', 'waiting_user'), true)) { return false; }
        if ($status && $status !== 'active' && $st !== $status) { return false; }
        if ($category && g($row, 'category') !== $category) { return false; }
        if ($needle === '') { return true; }
        if ($digits !== '' && (int) $digits === (int) g($row, 'ticket_no', -1)) { return true; }
        foreach (array('username', 'order_id', 'subject') as $k) { if (strpos(strtolower((string) g($row, $k, '')), $needle) !== false) { return true; } }
        return false;
    }));
    usort($rows, function ($a, $b) {
        $u = app_ticket_count_unread($b) - app_ticket_count_unread($a); if ($u) { return $u; }
        $h = (g($b, 'priority') === 'high') - (g($a, 'priority') === 'high'); if ($h) { return $h; }
        return strcmp((string) gx($b, array('last_message_at', 'updated_at', 'created_at'), ''), (string) gx($a, array('last_message_at', 'updated_at', 'created_at'), ''));
    });
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'counts' => $counts, 'items' => array_slice($rows, (int) $offset, (int) $limit)));
}
/** {status, ticket, messages[], user{}, purchases[]}. Opening marks the player's messages as read. */
function app_get_ticket($id) {
    $r = app_rpc('admin_get_ticket', array('p_ticket_id' => $id));
    if ($r['ok']) { return $r; }
    $t = app_ticket_table(); $m = app_ticket_msg_table(); if (!$t) { return $r; }
    list($rows, , $x) = app_select($t, array('id' => 'eq.' . $id), 1, 0, false);
    if (!$x['ok'] || !$rows) { return $x['ok'] ? array('ok' => false, 'error' => 'Ticket not found.', 'data' => null) : $r; }
    $ticket = $rows[0];
    $msgs = array();
    if ($m) { list($msgs, , ) = app_select($m, array('ticket_id' => 'eq.' . $id, 'order' => 'created_at.asc'), 1000, 0, false); }
    $uid = (string) g($ticket, 'user_id', ''); $user = array('id' => $uid);
    if (uuid_ok($uid)) {
        $pt = app_profiles_table(); $idc = app_profile_idcol();
        list($ps, , ) = app_select($pt, array($idc => 'eq.' . $uid), 1, 0, false);
        if ($ps) { foreach (array('username', 'player_no', 'avatar_url', 'account_status') as $k) { $user[$k] = g($ps[0], $k); } }
        $wt = app_wallet_table(); $oc = $wt ? app_owner_col($wt) : null;
        if ($oc) { list($ws, , ) = app_select($wt, array($oc => 'eq.' . $uid), 1, 0, false); $user['balance'] = $ws ? g($ws[0], 'balance_minor') : null; }
    }
    $pp = uuid_ok($uid) ? app_list_iap_purchases($uid, 50, 0) : array('ok' => false);
    if (array_key_exists('admin_unread', app_cols($t)) && app_ticket_count_unread($ticket)) {
        app_db('PATCH', $t, array('id' => 'eq.' . $id), array('admin_unread' => is_bool($ticket['admin_unread']) ? false : 0), 'return=minimal');
    }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'ticket' => $ticket, 'messages' => $msgs, 'user' => $user, 'purchases' => $pp['ok'] ? (array) g($pp['data'], 'items', array()) : array()));
}
/** Reply + status in one step; the player gets a push + in-app notification. */
function app_reply_ticket($id, $message, $status = 'waiting_user') {
    $r = app_rpc('admin_reply_ticket', array('p_ticket_id' => $id, 'p_message' => $message, 'p_status' => $status));
    if ($r['ok']) { return $r; }
    $t = app_ticket_table(); $m = app_ticket_msg_table(); if (!$t || !$m) { return $r; }
    list($rows, , ) = app_select($t, array('id' => 'eq.' . $id), 1, 0, false);
    if (!$rows) { return array('ok' => false, 'error' => 'Ticket not found.', 'data' => null); }
    $x = app_db('POST', $m, array(), app_fill_required($m, array('ticket_id' => $id, 'sender' => 'admin', 'body' => $message)), 'return=minimal');
    if (!$x['ok']) { $x['error'] = 'Reply failed: ' . $x['error']; return $x; }
    $c = app_cols($t); $patch = array();
    foreach (array('status' => $status, 'updated_at' => date('c'), 'last_message_at' => date('c'), 'closed_at' => $status === 'closed' ? date('c') : null) as $k => $v) { if (isset($c[$k])) { $patch[$k] = $v; } }
    if (isset($c['user_unread'])) { $patch['user_unread'] = is_bool(g($rows[0], 'user_unread')) ? true : ((int) g($rows[0], 'user_unread', 0)) + 1; }
    app_db('PATCH', $t, array('id' => 'eq.' . $id), $patch, 'return=minimal');
    app_notify($rows[0]['user_id'], 'Support ' . app_ticket_ref($rows[0]) . ': new reply', mb_substr($message, 0, 140));
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok'));
}
/** Status / priority change. resolved / closed / in_progress add a system line + notify the player. */
function app_set_ticket_status($id, $status, $priority = null) {
    $r = app_rpc('admin_set_ticket_status', array('p_ticket_id' => $id, 'p_status' => $status, 'p_priority' => $priority));
    if ($r['ok']) { return $r; }
    $t = app_ticket_table(); if (!$t) { return $r; }
    list($rows, , ) = app_select($t, array('id' => 'eq.' . $id), 1, 0, false);
    if (!$rows) { return array('ok' => false, 'error' => 'Ticket not found.', 'data' => null); }
    $c = app_cols($t); $patch = array();
    foreach (array('status' => $status, 'priority' => $priority, 'updated_at' => date('c'), 'closed_at' => $status === 'closed' ? date('c') : null) as $k => $v) { if (isset($c[$k]) && !($k === 'priority' && $v === null)) { $patch[$k] = $v; } }
    $x = app_db('PATCH', $t, array('id' => 'eq.' . $id), $patch, 'return=minimal');
    if (!$x['ok']) { return $x; }
    if ($status !== (string) g($rows[0], 'status') && in_array($status, array('resolved', 'closed', 'in_progress'), true)) {
        $line = array('resolved' => 'Ticket marked as resolved.', 'closed' => 'Ticket closed.', 'in_progress' => 'Our team is working on it.')[$status];
        $m = app_ticket_msg_table(); if ($m) { app_db('POST', $m, array(), app_fill_required($m, array('ticket_id' => $id, 'sender' => 'system', 'body' => $line)), 'return=minimal'); }
        app_notify($rows[0]['user_id'], 'Support ' . app_ticket_ref($rows[0]), $line);
    }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok'));
}
/** Signed URL for a private file (support screenshots: 600 s). '' on error. */
function app_signed_url($bucket, $path, $seconds = 600) {
    $path = ltrim((string) $path, '/');
    if ($path === '' || strpos($path, '..') !== false) { return ''; }
    if (strpos($path, $bucket . '/') === 0) { $path = substr($path, strlen($bucket) + 1); }
    $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
    $r = app_http('POST', '/storage/v1/object/sign/' . rawurlencode($bucket) . '/' . $enc, array('expiresIn' => (int) $seconds));
    if (!$r['ok'] || empty($r['data']['signedURL'])) { return ''; }
    list($base, ) = app_base();
    return $base . '/storage/v1' . (strpos($r['data']['signedURL'], '/') === 0 ? '' : '/') . $r['data']['signedURL'];
}
/**
 * Credit / debit a player (same as the user page "Adjust coins"): game function first, else direct with a
 * duplicate check on the reference. Returns array(ok, message, duplicate).
 */
function app_wallet_adjust_any($uid, $amount, $dir, $note, $ref) {
    if (app_has_rpc('admin_wallet_adjust')) {
        $r = app_rpc('admin_wallet_adjust', array('p_user_id' => $uid, 'p_amount' => (int) $amount, 'p_direction' => $dir, 'p_note' => $note, 'p_reference' => $ref !== '' ? $ref : null));
        if ($r['ok'] && !empty($r['data']['duplicate'])) { return array(false, 'Duplicate: reference ' . $ref . ' was already credited earlier. Nothing changed.', true); }
        if ($r['ok']) { app_log('wallet_' . $dir, $uid, array('amount' => $amount, 'note' => $note, 'reference' => $ref)); return array(true, ($dir === 'credit' ? 'Credited ' : 'Debited ') . number_format($amount) . ' coins. New balance: ' . app_num(g($r['data'], 'balance_minor')) . '.', false); }
    }
    $WT = app_wallet_table(); $TT = app_tx_table(); $oc = $WT ? app_owner_col($WT) : null;
    if (!$WT || !$oc) { return array(false, 'No wallet table found.', false); }
    if ($ref !== '' && $TT) {
        $tc = app_cols($TT); $ors = array();
        if (isset($tc['ref_id'])) { $ors[] = 'ref_id.eq.' . pg_safe($ref); }
        if (isset($tc['idempotency_key'])) { $ors[] = 'idempotency_key.eq.admin:' . pg_safe($ref); }
        if ($ors && app_count($TT, array('or' => '(' . implode(',', $ors) . ')'))) { return array(false, 'Duplicate: reference ' . $ref . ' was already credited earlier. Nothing changed.', true); }
    }
    list($ws, , ) = app_select($WT, array($oc => 'eq.' . $uid), 1, 0, false); $w = $ws ? $ws[0] : null;
    $bal = (int) g($w, 'balance_minor', 0); $new = $dir === 'credit' ? $bal + $amount : $bal - $amount;
    if ($new < 0) { return array(false, 'Not enough coins (balance ' . number_format($bal) . ').', false); }
    $x = $w ? app_db('PATCH', $WT, array($oc => 'eq.' . $uid), array('balance_minor' => $new), 'return=minimal') : app_db('POST', $WT, array(), array($oc => $uid, 'balance_minor' => $new), 'return=minimal');
    if (!$x['ok']) { return array(false, 'Wallet update failed: ' . $x['error'], false); }
    if ($TT && ($toc = app_owner_col($TT))) {
        $tc = app_cols($TT); $tx = array($toc => $uid, 'amount_minor' => (int) $amount, 'direction' => $dir);
        foreach (array('reason' => 'admin_' . $dir, 'balance_after' => $new, 'ref_type' => 'admin', 'ref_id' => $ref !== '' ? $ref : null, 'note' => $note, 'idempotency_key' => 'admin:' . ($ref !== '' ? $ref : bin2hex(random_bytes(8)))) as $k => $v) { if (isset($tc[$k])) { $tx[$k] = $v; } }
        app_db('POST', $TT, array(), $tx, 'return=minimal');
    }
    app_log('wallet_' . $dir, $uid, array('amount' => $amount, 'note' => $note, 'reference' => $ref));
    return array(true, ($dir === 'credit' ? 'Credited ' : 'Debited ') . number_format($amount) . ' coins. New balance: ' . number_format($new) . '.', false);
}

/** Readable wallet transaction reason. */
function app_reason_label($reason) {
    $m = array('referral_bonus' => 'Referral welcome', 'referral_reward' => 'Referral reward');
    return isset($m[$reason]) ? $m[$reason] : $reason;
}

/* ---------- Refer & Earn (read only) ----------
 * {status, new_player_bonus, referrer_bonus, total_referrals, referrals_today, coins_to_new_players, coins_to_referrers,
 *  top_referrers: [{user_id, username, player_no, referral_code, account_status, friends}],
 *  latest: [{user_id, username, joined_at, referrer_id, referrer_username, referral_code}]} */
function app_referral_overview($limit = 50) {
    $r = app_rpc('admin_referral_overview', array('p_limit' => (int) $limit));
    if ($r['ok']) { return $r; }
    // fallback: profiles.referred_by + wallet history (referral_bonus / referral_reward)
    $pt = app_profiles_table(); $pc = app_cols($pt); $idc = app_profile_idcol();
    $refCol = null; foreach (array('referred_by', 'referrer_id', 'referred_by_id', 'invited_by') as $c) { if (isset($pc[$c])) { $refCol = $c; break; } }
    if (!$refCol) { return $r; }
    $sel = $idc . ',' . $refCol . (isset($pc['username']) ? ',username' : '') . (isset($pc['player_no']) ? ',player_no' : '') . (isset($pc['referral_code']) ? ',referral_code' : '') . (isset($pc['account_status']) ? ',account_status' : '') . (isset($pc['created_at']) ? ',created_at' : '');
    $q = array('select' => $sel, $refCol => 'not.is.null'); if (isset($pc['created_at'])) { $q['order'] = 'created_at.desc'; }
    list($refd, $total, $x) = app_select($pt, $q, 20000, 0, true);
    if (!$x['ok']) { return $r; }
    $friends = array(); $today = 0; $mid = strtotime('today');
    foreach ($refd as $p) { $friends[$p[$refCol]] = (isset($friends[$p[$refCol]]) ? $friends[$p[$refCol]] : 0) + 1; if (strtotime((string) g($p, 'created_at', '')) >= $mid) { $today++; } }
    arsort($friends);
    $topIds = array_slice(array_keys($friends), 0, (int) $limit);
    $latest = array_slice($refd, 0, (int) $limit);
    $need = $topIds; foreach ($latest as $p) { $need[] = $p[$refCol]; }
    $prof = array();
    $need = array_values(array_unique(array_filter($need, 'uuid_ok')));
    foreach (array_chunk($need, 150) as $ch) { list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', $ch) . ')', 'select' => $sel), 200, 0, false); foreach ($ps as $pp) { $prof[$pp[$idc]] = $pp; } }
    $top = array(); foreach ($topIds as $u) { $p = isset($prof[$u]) ? $prof[$u] : array(); $top[] = array('user_id' => $u, 'username' => g($p, 'username'), 'player_no' => g($p, 'player_no'), 'referral_code' => g($p, 'referral_code'), 'account_status' => g($p, 'account_status'), 'friends' => $friends[$u]); }
    $lat = array(); foreach ($latest as $p) { $ref = $p[$refCol]; $rp = isset($prof[$ref]) ? $prof[$ref] : array(); $lat[] = array('user_id' => $p[$idc], 'username' => g($p, 'username'), 'joined_at' => g($p, 'created_at'), 'referrer_id' => $ref, 'referrer_username' => g($rp, 'username'), 'referral_code' => g($rp, 'referral_code')); }
    $coinsNew = null; $coinsRef = null;
    $tt = app_tx_table();
    if ($tt && array_key_exists('reason', app_cols($tt))) {
        foreach (array('referral_bonus' => 'coinsNew', 'referral_reward' => 'coinsRef') as $reason => $var) {
            list($rows, , $y) = app_select($tt, array('reason' => 'eq.' . $reason, 'select' => 'amount_minor'), 100000, 0, false);
            if ($y['ok']) { $sum = 0; foreach ($rows as $w) { $sum += (int) g($w, 'amount_minor', 0); } $$var = $sum; }
        }
    }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'new_player_bonus' => 50, 'referrer_bonus' => 100,
        'total_referrals' => $total !== null ? $total : count($refd), 'referrals_today' => $today, 'coins_to_new_players' => $coinsNew, 'coins_to_referrers' => $coinsRef, 'top_referrers' => $top, 'latest' => $lat));
}

/* ---------- emails to app players (templates in includes/emails/, sent by includes/mailer.php) ---------- */
/** Email one app player (address from Supabase Auth). Returns array(ok, error). Never breaks the admin action. */
function app_mail_player($uid, $template, $subject, $vars = array()) {
    if (!uuid_ok($uid)) { return array(false, 'bad user'); }
    $a = app_auth_get($uid);
    $email = $a['ok'] ? (string) g($a['data'], 'email', '') : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return array(false, 'player has no email'); }
    if (!isset($vars['name'])) {
        $pt = app_profiles_table(); $idc = app_profile_idcol();
        list($ps, , ) = app_select($pt, array($idc => 'eq.' . $uid, 'select' => $idc . ',username'), 1, 0, false);
        $vars['name'] = $ps && g($ps[0], 'username') ? $ps[0]['username'] : 'player';
    }
    require_once __DIR__ . '/mailer.php';
    try { return mail_send_template($email, $template, $subject, $vars); } catch (Throwable $e) { return array(false, $e->getMessage()); }
}
function app_mail_suspended($uid, $reason, $ref = '') {
    return app_mail_player($uid, 'account_suspended', 'Your DROTIX account is suspended', array('reason' => $reason !== '' ? $reason : 'Breaking the fair-play rules', 'date' => date('d M Y, H:i'), 'ref' => $ref !== '' ? $ref : '-'));
}
function app_mail_reactivated($uid, $message = '') {
    return app_mail_player($uid, 'account_reactivated', 'Your DROTIX account is active again', array('message' => $message !== '' ? $message : 'Your suspension was lifted by our team.'));
}
/** Readable ticket status for players. */
function app_ticket_status_label($st) {
    $m = array('open' => 'Received', 'in_progress' => 'In progress', 'waiting_user' => 'Waiting for your reply', 'resolved' => 'Resolved', 'closed' => 'Closed');
    return isset($m[$st]) ? $m[$st] : ucfirst(str_replace('_', ' ', (string) $st));
}
/** Ticket row by id (table, or the game function). */
function app_ticket_row($id) {
    $t = app_ticket_table();
    if ($t) { list($rows, , ) = app_select($t, array('id' => 'eq.' . $id), 1, 0, false); if ($rows) { return $rows[0]; } }
    $r = app_get_ticket($id);
    return $r['ok'] ? (array) g($r['data'], 'ticket', array()) : null;
}
/** Email the player about a ticket: $kind = received | reply | status. */
function app_mail_ticket($ticket, $kind, $status, $message = '') {
    if (!$ticket || !uuid_ok(g($ticket, 'user_id'))) { return array(false, 'no ticket'); }
    $ref = app_ticket_ref($ticket); $label = app_ticket_status_label($status);
    $heading = array('received' => 'We got your ticket', 'reply' => 'We replied', 'status' => 'Ticket ' . strtolower($label));
    $intro = array('received' => 'Thanks for contacting DROTIX support. Your ticket is open and our team will answer soon, usually within 24 hours.',
                   'reply' => 'Our support team replied to your ticket. Here is the answer:',
                   'status' => 'The status of your support ticket changed to ' . $label . '.');
    $msg = $message !== '' ? $message : array('resolved' => 'We marked this ticket as resolved. If the problem is still there, reply in the app and we will reopen it.',
        'closed' => 'This ticket is closed. If you need more help, open a new ticket in the app.', 'in_progress' => 'Our team is working on it.', 'open' => 'Subject: ' . g($ticket, 'subject', ''))[in_array($status, array('resolved', 'closed', 'in_progress'), true) ? $status : 'open'];
    return app_mail_player($ticket['user_id'], 'ticket_update', 'Support ' . $ref . ': ' . ($kind === 'reply' ? 'new reply' : $label),
        array('ref' => $ref, 'subject' => (string) g($ticket, 'subject', '-'), 'status' => $label, 'heading' => $heading[$kind], 'intro' => $intro[$kind], 'message' => $msg));
}
/** Email the reporter about their report. $status: received | resolved | dismissed | closed | under_review */
function app_mail_report($reporterId, $reportId, $status, $reportedName, $outcome, $message = '') {
    $label = ucfirst(str_replace('_', ' ', $status === 'received' ? 'received' : $status));
    $intro = array('received' => 'We received your report. Our team will review it.', 'resolved' => 'We reviewed your report and took action.',
                   'dismissed' => 'We reviewed your report and did not find a rule break this time.', 'closed' => 'Your report is closed.', 'under_review' => 'Our team is reviewing your report.');
    return app_mail_player($reporterId, 'report_update', 'Your report ' . app_ref('R', $reportId) . ': ' . $label,
        array('ref' => app_ref('R', $reportId), 'status' => $label, 'reported' => $reportedName !== '' ? $reportedName : '-', 'outcome' => $outcome,
              'intro' => isset($intro[$status]) ? $intro[$status] : 'Your report was updated.', 'message' => $message !== '' ? $message : 'Thank you for reporting.'));
}

/* ---------- account deletion ----------
 * Edge Function delete-account (checks the caller is an app admin, logs 'delete_account', deletes on the server).
 * Result: {status:'ok', deleted:true, login_deleted:bool} | {status:'error', reason:'admin'|'in_match'|'tournament'|'forbidden'|'note_required'|..., message} */
/** array('ok' => bool, 'data' => array, 'reason' => string, 'error' => string) */
function app_delete_account($uid, $note) {
    $tok = app_admin_token();
    if (!$tok) { return array('ok' => false, 'data' => null, 'reason' => 'forbidden', 'error' => 'No app admin session. ' . app_admin_why()); }
    $h = array('Authorization' => 'Bearer ' . $tok);
    $pub = (string) cfg('supabase_anon_key', ''); if ($pub !== '') { $h['apikey'] = $pub; }
    $r = app_http('POST', '/functions/v1/delete-account', array('confirm' => 'DELETE', 'user_id' => $uid, 'note' => $note), $h);
    $d = is_array($r['data']) ? $r['data'] : array();   // error responses also carry a JSON body
    $ok = $r['ok'] && g($d, 'status') === 'ok' && !empty($d['deleted']);
    $reason = $ok ? '' : (string) gx($d, array('reason', 'code', 'error'), ($r['status'] === 401 || $r['status'] === 403) ? 'forbidden' : 'unknown');
    return array('ok' => $ok, 'data' => $d, 'reason' => $reason, 'error' => $ok ? '' : (string) gx($d, array('message', 'error'), $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status']));
}
/** {status, total, last_days, items: [{deleted_at, source 'app'|'web'|'admin', login_kept}]} — no personal data by design. */
function app_account_deletions($days = 30) {
    $r = app_rpc('admin_account_deletions', array('p_days' => (int) $days));
    if ($r['ok']) { return $r; }
    $t = app_has_table('account_deletions') ? 'account_deletions' : app_find_table(array('deleted_at'), '/delet/');
    if (!$t) { return $r; }
    $since = date('c', strtotime('-' . (int) $days . ' days'));
    list($rows, , $x) = app_select($t, array('deleted_at' => 'gte.' . $since, 'order' => 'deleted_at.desc', 'select' => '*'), 2000, 0, false);
    if (!$x['ok']) { return $r; }
    $items = array(); foreach ($rows as $w) { $items[] = array('deleted_at' => g($w, 'deleted_at'), 'source' => g($w, 'source', 'app'), 'login_kept' => (bool) g($w, 'login_kept', false)); }
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'total' => app_count($t), 'last_days' => count($items), 'items' => $items));
}

/* ---------- chat moderation (abusive words blocked by the server in match chat) ----------
 * Event = {id, user_id, username, player_no, avatar_url, account_status, room_id, message, matched, strikes_24h, strikes_7d,
 *          muted_until, muted_now_until, strikes_total, severity 'normal'|'review', status 'new'|'reviewed'|'dismissed'|'actioned',
 *          admin_note, reviewed_at, created_at}
 * Word  = {id, word, norm, kind 'block'|'allow', active, created_at} */
/** {status, counts{new, review, today, total}, items[Event]}. $status null = all. */
function app_chat_abuse_list($status = 'new', $userId = null, $limit = 50, $offset = 0) {
    $r = app_rpc('admin_chat_abuse_list', array('p_status' => $status, 'p_user_id' => $userId, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset));
    if ($r['ok'] || !app_has_table('chat_abuse_events')) { return $r; }
    // read-only fallback (actions need the game functions)
    $t = 'chat_abuse_events'; $q = array('order' => 'created_at.desc');
    if ($status) { $q['status'] = 'eq.' . $status; }
    if ($userId) { $q['user_id'] = 'eq.' . $userId; }
    list($rows, , $x) = app_select($t, $q, (int) $limit, (int) $offset, false);
    if (!$x['ok']) { return $r; }
    $rows = app_ticket_people($rows);
    $counts = array('new' => app_count($t, array('status' => 'eq.new')), 'review' => app_count($t, array('severity' => 'eq.review', 'status' => 'eq.new')),
                    'today' => app_count($t, array('created_at' => 'gte.' . date('c', strtotime('today')))), 'total' => app_count($t));
    return array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'counts' => $counts, 'items' => $rows));
}
/** $action: reviewed | dismissed | mute_24h | unmute */
function app_chat_abuse_review($eventId, $action, $note = '') { return app_rpc('admin_chat_abuse_review', array('p_event_id' => $eventId, 'p_action' => $action, 'p_note' => $note !== '' ? $note : null)); }
/** {status, items[{user_id, username, player_no, account_status, strikes, last_at, muted_until}]} */
function app_chat_offenders($days = 7, $limit = 50) { return app_rpc('admin_chat_offenders', array('p_days' => (int) $days, 'p_limit' => (int) $limit)); }
/** {status, items[Word]} */
function app_chat_words($search = '') {
    $r = app_rpc('admin_chat_words', array('p_search' => $search !== '' ? $search : null));
    if ($r['ok']) { return $r; }
    $t = app_has_table('chat_words') ? 'chat_words' : app_find_table(array('word', 'kind'), '/word|profan|abuse/');
    if (!$t) { return $r; }
    $q = array('order' => 'word.asc'); if ($search !== '') { $q['word'] = 'ilike.*' . pg_safe($search) . '*'; }
    list($rows, , $x) = app_select($t, $q, 2000, 0, false);
    return $x['ok'] ? array('ok' => true, 'via' => 'table', 'error' => '', 'data' => array('status' => 'ok', 'items' => $rows)) : $r;
}
/** Saving an existing word updates it. -> {status, id, norm} */
function app_chat_word_save($word, $kind = 'block', $active = true) { return app_rpc('admin_chat_word_save', array('p_word' => $word, 'p_kind' => $kind, 'p_active' => (bool) $active)); }
function app_chat_word_delete($id) { return app_rpc('admin_chat_word_delete', array('p_id' => $id)); }
/** -> {status, blocked, matched, cleaned} */
function app_chat_test($text) { return app_rpc('admin_chat_test', array('p_text' => $text)); }

/* ---------- Nearby & Rank ----------
 * overview: {ranked_players, nearby_joined, nearby_sharing_now, nearby_ghost, nearby_banned, rp_given_today, rp_taken_today,
 *            ranked_challenges_today, missions_claimed_today, ad_tickets_today, rank_ups_today, tiers[{tier, label, players}], settings{}} */
function app_rank_overview() { return app_rpc('admin_rank_overview'); }
/** {total, items[{user_id, username, player_no, tier, tier_override, rp, tickets, wins, losses, draws, nearby_enabled, ghost, banned, last_location_at, account_status}]} */
function app_rank_players($search, $tier, $limit = 50, $offset = 0) {
    return app_rpc('admin_rank_players', array('p_search' => $search !== '' ? $search : null, 'p_tier' => $tier !== '' ? $tier : null, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset));
}
/** {profile, rank, stats{matches, friends, streak_days}, nearby{}, last_location{lat, lng, at}, ledger[], promotions[], mission_claims[], ranked_challenges[]}. Audited by the server. */
function app_rank_player($uid) { return app_rpc('admin_rank_player', array('p_user' => $uid)); }
/** $tierOverride: 'bronze'|'silver'|'gold'|'elite' lock, '' back to automatic, null unchanged. $tickets null = unchanged. */
function app_rank_update($uid, $tierOverride, $rpDelta, $tickets, $note) {
    return app_rpc_pos('admin_rank_update', array($uid, $tierOverride, $rpDelta === null ? null : (int) $rpDelta, $tickets === null ? null : (int) $tickets, $note), array('p_user', 'p_tier_override', 'p_rp_delta', 'p_tickets', 'p_note'));
}
function app_nearby_ban($uid, $ban, $reason) { return app_rpc_pos('admin_nearby_ban', array($uid, (bool) $ban, $reason !== '' ? $reason : null), array('p_user', 'p_ban', 'p_reason')); }
function app_rank_tiers() {
    if (!app_has_table('rank_tiers')) { return array(); }
    $c = app_cols('rank_tiers');
    list($rows, , ) = app_select('rank_tiers', isset($c['sort']) ? array('order' => 'sort.asc') : array(), 20, 0, false);
    return $rows;
}
function app_rank_tier_save($tier, $data) { return app_rpc_pos('admin_rank_tier_save', array($tier, (object) $data), array('p_tier', 'p_data')); }
function app_rank_settings_save($data) { return app_rpc_pos('admin_rank_settings_save', array((object) $data), array('p_data')); }
/** {items[{id, code, title, description, kind, target, reward_rp, reward_tickets, active, sort, claims_today}]} */
function app_rank_missions() { return app_rpc('admin_rank_missions'); }
function app_rank_mission_save($id, $data) { return app_rpc_pos('admin_rank_mission_save', array($id ?: null, (object) $data), array('p_id', 'p_data')); }
function app_rank_mission_delete($id) { return app_rpc_pos('admin_rank_mission_delete', array($id), array('p_id')); }
function app_rank_season_reset($keepPercent, $seasonName) { return app_rpc_pos('admin_rank_season_reset', array((int) $keepPercent, $seasonName, 'RESET'), array('p_keep_percent', 'p_season_name', 'p_confirm')); }
/** Latest rank promotion (for the live toast). */
function app_rank_latest_promotion() {
    if (!app_has_table('rank_promotions')) { return null; }
    $c = app_cols('rank_promotions');
    list($rows, , ) = app_select('rank_promotions', isset($c['created_at']) ? array('order' => 'created_at.desc') : array(), 1, 0, false);
    return $rows ? $rows[0] : null;
}
function app_tier_badge($t, $locked = false) {
    $c = array('bronze' => '#CD7F32', 'silver' => '#C0C0C8', 'gold' => '#FFC24D', 'elite' => '#ED1B76');
    $col = isset($c[$t]) ? $c[$t] : '#A8A8B5';
    return '<span class="badge" style="color:' . $col . ';border-color:' . $col . '">' . e(ucfirst((string) $t ?: '-')) . '</span>' . ($locked ? ' <span class="badge b-gold" title="tier locked by admin">locked</span>' : '');
}

const APP_UPDATES_BUCKET = 'drotix-app-updates';
/** One-time signed upload URL (browser uploads the APK straight to Storage; no key in the browser). array(uploadUrl, publicUrl, error) */
function app_signed_upload($path) {
    $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
    $r = app_http('POST', '/storage/v1/object/upload/sign/' . APP_UPDATES_BUCKET . '/' . $enc, (object) array(), array('x-upsert' => 'true'));
    list($base, ) = app_base();
    $u = (string) gx($r['data'], array('url', 'signedURL'), '');
    if (!$r['ok'] || $u === '') { return array('', '', $r['error'] !== '' ? $r['error'] : 'Could not create the upload link (does the bucket ' . APP_UPDATES_BUCKET . ' exist?)'); }
    return array($base . '/storage/v1' . (strpos($u, '/') === 0 ? '' : '/') . $u, $base . '/storage/v1/object/public/' . APP_UPDATES_BUCKET . '/' . $enc, '');
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
function app_required($t) { $ts = app_tables(); return isset($ts[$t]['required']) ? $ts[$t]['required'] : array(); }
function app_enum($t, $col) { $ts = app_tables(); return isset($ts[$t]['enums'][$col]) ? $ts[$t]['enums'][$col] : array(); }
/** Pick a value for a column: allowed enum value / value already used in the table, preferring $prefer. */
function app_pick_value($t, $col, $prefer) {
    $opts = app_enum($t, $col);
    if (!$opts) {
        list($rows, , ) = app_select($t, array('select' => $col, $col => 'not.is.null', 'order' => $col . '.asc'), 300, 0, false);
        foreach ($rows as $r) { if (isset($r[$col]) && is_scalar($r[$col]) && !in_array($r[$col], $opts, true)) { $opts[] = $r[$col]; } }
    }
    $prefer = (array) $prefer;
    foreach ($prefer as $p) { if (in_array($p, $opts, true)) { return $p; } }
    foreach ($prefer as $p) { foreach ($opts as $o) { if (is_string($o) && stripos($o, $p) !== false) { return $o; } } }
    $isEnum = (bool) app_enum($t, $col);
    return ($isEnum && $opts) ? $opts[0] : $prefer[0];   // not an enum: use our preferred value, never another existing one
}
/** Fill required columns that have no value and no database default (enum / text / json / bool / number). */
function app_fill_required($t, $row, $hints = array()) {
    $ts = app_tables(); $info = isset($ts[$t]) ? $ts[$t] : null;
    if (!$info) { return $row; }
    foreach ($info['required'] as $c) {
        if (array_key_exists($c, $row) || isset($info['defaults'][$c]) || $c === $info['pk']) { continue; }
        $f = $info['cols'][$c];
        if (isset($hints[$c])) { $row[$c] = app_pick_value($t, $c, $hints[$c]); }
        elseif (isset($info['enums'][$c])) { $row[$c] = $info['enums'][$c][0]; }
        elseif ($f === 'boolean') { $row[$c] = false; }
        elseif (app_is_json_fmt($f)) { $row[$c] = new stdClass(); }
        elseif (app_is_num_fmt($f)) { $row[$c] = 0; }
        elseif (strpos($f, 'timestamp') === 0) { $row[$c] = date('c'); }
        elseif ($f !== 'uuid') { $row[$c] = app_pick_value($t, $c, array('admin', 'system', 'general')); }
    }
    return $row;
}
function app_notif_table() { return app_has_table('notifications') ? 'notifications' : app_find_table(array('title', 'body'), '/notif/'); }
/**
 * Send a notification. $userId null = every player. Tries the game's function first (push + in-app),
 * then writes rows to the notifications table directly (in-app). Returns array(ok, error, count|null, via).
 */
function app_notify($userId, $title, $body) {
    if (app_has_rpc('admin_send_notification')) {
        $r = app_rpc('admin_send_notification', array('p_user_id' => $userId, 'p_title' => $title, 'p_body' => $body));
        if ($r['ok']) { return array('ok' => true, 'error' => '', 'count' => gx($r['data'], array('sent', 'recipients', 'count')), 'via' => 'game'); }
        $rpcErr = $r['error'];
    }
    $nt = app_notif_table(); $oc = $nt ? app_owner_col($nt) : null;
    if (!$nt || !$oc) { return array('ok' => false, 'error' => isset($rpcErr) ? $rpcErr : 'No notifications table found.', 'count' => null, 'via' => ''); }
    $hints = array('type' => array('admin', 'system', 'announcement', 'broadcast', 'admin_message', 'info', 'general'), 'kind' => array('admin', 'system'), 'category' => array('admin', 'system'));
    $ids = array();
    if ($userId !== null) { $ids = array($userId); }
    else {
        $pt = app_profiles_table(); $idc = app_profile_idcol(); $q = array('select' => $idc);
        if (array_key_exists('account_status', app_cols($pt))) { $q['account_status'] = 'neq.deleted'; }   // every player of the app
        for ($o = 0; $o < 200000; $o += 1000) { list($rows, , ) = app_select($pt, $q, 1000, $o, false); foreach ($rows as $r) { $ids[] = $r[$idc]; } if (count($rows) < 1000) { break; } }
    }
    $base = app_fill_required($nt, array($oc => $ids ? $ids[0] : null, 'title' => $title, 'body' => $body), $hints);
    $sent = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $rows = array(); foreach ($chunk as $id) { $b = $base; $b[$oc] = $id; $rows[] = $b; }
        $r = app_db('POST', $nt, array(), $rows, 'return=minimal');
        if (!$r['ok']) { return array('ok' => false, 'error' => $r['error'], 'count' => $sent, 'via' => 'table'); }
        $sent += count($chunk);
    }
    return array('ok' => true, 'error' => '', 'count' => $sent, 'via' => 'table');
}
/** Change a profile's account status directly (fallback when the game's functions are not usable). */
function app_set_status($uid, $suspend, $reason) {
    $pt = app_profiles_table(); $pc = app_cols($pt); $patch = array();
    if (isset($pc['account_status'])) { $patch['account_status'] = $suspend ? 'suspended' : 'active'; }
    if (isset($pc['suspension_reason'])) { $patch['suspension_reason'] = $suspend ? $reason : null; }
    if (isset($pc['suspended_at'])) { $patch['suspended_at'] = $suspend ? date('c') : null; }
    $r = $patch ? app_db('PATCH', $pt, array(app_profile_idcol() => 'eq.' . $uid), $patch, 'return=minimal') : array('ok' => true, 'error' => '');
    app_auth_update($uid, array('ban_duration' => $suspend ? '876000h' : 'none'));
    return $r;
}
/** Find a table whose name matches $re and has all $cols. */
function app_table_like($re, $cols = array()) { return app_find_table($cols, $re); }

/** Ticket id exactly like the app: hex digits of the id, first 8 as hex, mod 100000, 5 digits -> "R-20481". */
function app_ref($prefix, $id) {
    $hex = preg_replace('/[^0-9a-fA-F]/', '', (string) $id);
    if (strlen($hex) < 8) { return $prefix . '-?????'; }
    return $prefix . '-' . str_pad((string) (hexdec(substr($hex, 0, 8)) % 100000), 5, '0', STR_PAD_LEFT);
}
/** Does a typed search ("R-20481", "20481", "r20481") match this row's ticket id? */
function app_ref_match($prefix, $id, $search) {
    $d = preg_replace('/\D/', '', (string) $search);
    return $d !== '' && substr(app_ref($prefix, $id), 2) === str_pad($d, 5, '0', STR_PAD_LEFT);
}
function app_ago($iso) {
    $t = strtotime((string) $iso); if (!$t) { return '-'; }
    $s = time() - $t;
    if ($s < 60) { return 'just now'; }
    if ($s < 3600) { return floor($s / 60) . ' min ago'; }
    if ($s < 86400) { return floor($s / 3600) . ' h ago'; }
    return floor($s / 86400) . ' d ago';
}
/** Players online now (admin_online_now). array('ok', 'count', 'items', 'ids' => set, 'error') — fetched once per request. */
function app_online($limit = 500) {
    static $c = array();
    if (isset($c[$limit])) { return $c[$limit]; }
    $r = app_rpc('admin_online_now', array('p_limit' => (int) $limit));
    if ($r['ok']) {
        $items = (array) g($r['data'], 'items', array());
        $count = g($r['data'], 'online_count', count($items));
    } else {
        $d = app_online_direct($limit);
        if ($d === null) { return $c[$limit] = array('ok' => false, 'count' => null, 'items' => array(), 'ids' => array(), 'error' => $r['error']); }
        list($items, $count) = $d;
    }
    $ids = array(); foreach ($items as $i) { if (!empty($i['user_id'])) { $ids[$i['user_id']] = $i; } }
    return $c[$limit] = array('ok' => true, 'count' => $count, 'items' => $items, 'ids' => $ids, 'error' => '');
}
/** Presence source: array(table, user col, last-seen col, in-match col|null) or null. */
function app_presence_source() {
    $best = null;
    foreach (app_tables() as $t => $i) {
        $seen = null; foreach (array('last_seen_at', 'last_heartbeat_at', 'heartbeat_at', 'seen_at', 'last_active_at') as $s) { if (isset($i['cols'][$s])) { $seen = $s; break; } }
        if (!$seen) { continue; }
        $u = $t === app_profiles_table() ? app_profile_idcol() : app_owner_col($t);
        if (!$u) { continue; }
        $im = null; foreach (array('in_match', 'is_in_match', 'playing') as $m) { if (isset($i['cols'][$m])) { $im = $m; break; } }
        $cand = array('table' => $t, 'user' => $u, 'seen' => $seen, 'in_match' => $im);
        if (preg_match('/(presence|heartbeat|online)/', $t)) { return $cand; }
        if (!$best) { $best = $cand; }
    }
    return $best;
}
/** Online players read directly (seen in the last 90 s). array(items, count) or null. */
function app_online_direct($limit) {
    $src = app_presence_source();
    if (!$src) { return null; }
    $since = date('c', time() - 90);
    list($rows, $total, $r) = app_select($src['table'], array($src['seen'] => 'gte.' . $since, 'order' => $src['seen'] . '.desc'), (int) $limit, 0, true);
    if (!$r['ok']) { return null; }
    $ids = array(); foreach ($rows as $x) { $ids[] = $x[$src['user']]; }
    $prof = array();
    if ($ids) {
        $pt = app_profiles_table(); $idc = app_profile_idcol();
        list($ps, , ) = app_select($pt, array($idc => 'in.(' . implode(',', $ids) . ')'), count($ids) + 5, 0, false);
        foreach ($ps as $p) { $prof[$p[$idc]] = $p; }
    }
    $items = array();
    foreach ($rows as $x) {
        $u = $x[$src['user']]; $p = isset($prof[$u]) ? $prof[$u] : array();
        $items[] = array('user_id' => $u, 'username' => g($p, 'username'), 'player_no' => g($p, 'player_no'), 'avatar_url' => g($p, 'avatar_url'),
                         'status_text' => g($p, 'status_text'), 'in_match' => $src['in_match'] ? (bool) g($x, $src['in_match']) : false, 'last_seen_at' => $x[$src['seen']]);
    }
    return array($items, $total !== null ? $total : count($items));
}
/** Last-seen time for many players: array(user_id => iso). */
function app_last_seen($ids) {
    $src = app_presence_source(); $out = array();
    $ids = array_values(array_filter($ids, 'uuid_ok'));
    if (!$src || !$ids) { return $out; }
    list($rows, , ) = app_select($src['table'], array($src['user'] => 'in.(' . implode(',', $ids) . ')', 'select' => $src['user'] . ',' . $src['seen']), count($ids) + 5, 0, false);
    foreach ($rows as $r) { $out[$r[$src['user']]] = $r[$src['seen']]; }
    return $out;
}
/** Readable labels for audit actions (game audit + this panel). */
function app_action_label($a) {
    $a = preg_replace('/^app_/', '', (string) $a);
    $m = array('clear_status_text' => 'Removed status line', 'remove_block' => 'Removed block', 'cancel_challenge' => 'Cancelled challenge',
               'suspend' => 'Suspended player', 'suspend_user' => 'Suspended player', 'reinstate' => 'Reinstated player', 'reinstate_user' => 'Reinstated player',
               'wallet_credit' => 'Coins credited', 'wallet_debit' => 'Coins debited', 'wallet_adjust' => 'Coins adjusted', 'notify' => 'Sent notification', 'send_notification' => 'Sent notification',
               'broadcast' => 'Broadcast to all', 'email_change' => 'Changed email', 'password_change' => 'Changed password', 'email_confirm' => 'Verified email',
               'profile_update' => 'Edited profile', 'update_user_profile' => 'Edited profile', 'account_delete' => 'Deleted account', 'set_admin' => 'Changed admin rights',
               'report_approve' => 'Report approved', 'report_reject' => 'Report rejected', 'report_review' => 'Report under review', 'report_suspend' => 'Suspended from report', 'update_report' => 'Report status changed',
               'appeal_approved' => 'Appeal approved', 'appeal_rejected' => 'Appeal rejected', 'review_appeal' => 'Appeal reviewed', 'appeal_suspend' => 'Suspended (appeal)',
               'tournament_create' => 'Created tournament', 'create_tournament' => 'Created tournament', 'tournament_start' => 'Started tournament', 'generate_bracket' => 'Started tournament',
               'tournament_status' => 'Tournament status', 'tournament_notify' => 'Notified tournament', 'row_update' => 'Edited data row', 'row_insert' => 'Added data row', 'row_delete' => 'Deleted data row', 'delete_account' => 'Deleted player account',
               'chat_review' => 'Chat report reviewed', 'rank_update' => 'Changed player rank', 'nearby_ban' => 'Nearby ban', 'nearby_unban' => 'Nearby unban',
               'rank_tier_save' => 'Edited rank tier', 'rank_settings_save' => 'Changed rank settings', 'rank_mission_save' => 'Saved mission', 'rank_mission_delete' => 'Deleted mission',
               'rank_season_reset' => 'New season (RP reset)', 'rank_player_view' => 'Viewed player rank', 'chat_word_save' => 'Saved chat word', 'chat_word_delete' => 'Deleted chat word',
               'publish_legal' => 'Published legal document', 'publish_app_release' => 'Published app release',
               'create_coupon' => 'Created coupon', 'edit_coupon' => 'Edited coupon', 'enable_coupon' => 'Enabled coupon', 'disable_coupon' => 'Disabled coupon',
               'create_banner' => 'Created home banner', 'edit_banner' => 'Edited home banner', 'delete_banner' => 'Deleted home banner',
               'reply_ticket' => 'Replied to support ticket', 'ticket_status' => 'Changed ticket status', 'create_coin_pack' => 'Created coin pack', 'edit_coin_pack' => 'Edited coin pack', 'enable_coin_pack' => 'Enabled coin pack', 'disable_coin_pack' => 'Disabled coin pack');
    return isset($m[$a]) ? $m[$a] : ucfirst(str_replace('_', ' ', $a));
}
/** Error text for a failed game function, with the fix when no app admin exists. */
function app_rpc_error($r) {
    $e = (string) $r['error'];
    if (stripos($e, 'forbidden: admin only') !== false && app_admin_token()) { return 'You are not an admin (the account the panel uses has is_admin = false). Fix it in Mobile App -> Admin check.'; }
    if (stripos($e, 'forbidden') !== false || (isset($r['status']) && $r['status'] === 401)) {
        $why = app_admin_token() ? 'the admin session was refused' : app_admin_why();
        $e .= ' (' . ($why !== '' ? $why . ' ' : '') . 'Open Mobile App -> Admin check to fix it.)';
    }
    return $e;
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
    if ($col === 'reason' && is_string($v) && ($lbl = app_reason_label($v)) !== $v) { return e($lbl) . ' <span class="muted" style="font-size:11px">' . e($v) . '</span>'; }
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
    list($sbUrl, ) = app_base();
    $origin = preg_match('~^https://[^/]+~', $sbUrl, $m) ? ' ' . $m[0] : '';
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'" . $origin . "; form-action 'self'; frame-ancestors 'none'", true);
    admin_head($title, 'app');
    // Two groups: pages about individual players, and pages that apply to the WHOLE app (every player).
    $groups = array(
        array('Players', 'One player at a time', array(
            'users' => array('/admin/app/users/', 'Players'), 'reports' => array('/admin/app/reports/', 'Reports'),
            'appeals' => array('/admin/app/appeals/', 'Appeals'), 'tickets' => array('/admin/app/tickets/', 'Support tickets'), 'deletions' => array('/admin/app/deletions/', 'Account deletions'), 'chat' => array('/admin/app/chat/', 'Chat moderation'))),
        array('Whole app', 'Applies to all players', array(
            'online' => array('/admin/app/online/', 'Online now'), 'cast' => array('/admin/app/broadcast/', 'Broadcast'),
            'legal' => array('/admin/app/legal/', 'Privacy & Terms'), 'rel' => array('/admin/app/releases/', 'App update'),
            'tour' => array('/admin/app/tournaments/', 'Tournaments'), 'coupons' => array('/admin/app/coupons/', 'Coupons'), 'banners' => array('/admin/app/banners/', 'Home banners'), 'packs' => array('/admin/app/packs/', 'Coin packs'), 'purch' => array('/admin/app/purchases/', 'Purchases'), 'refer' => array('/admin/app/referrals/', 'Refer & Earn'), 'rank' => array('/admin/app/rank/', 'Nearby & Rank'))),
        array('Data', 'Reports & raw data', array(
            'dash' => array('/admin/app/', 'Stats'), 'data' => array('/admin/app/data/', 'All data'), 'audit' => array('/admin/app/audit/', 'Audit log'), 'check' => array('/admin/app/check/', 'Admin check'))),
    );
    $global = array('online' => 'Every player of the app: who is online right now (live) and who is offline.', 'cast' => 'Sent to every player of the app, not to one player.',
                    'legal' => 'One Privacy Policy and one Terms of Service for the whole app: a new version is shown to every player in the app.',
                    'rel' => 'One update for the whole app: every player on an older version sees the update screen.',
                    'tour' => 'Tournaments are open to all players.', 'banners' => 'Home banners are shown on the Home screen of every player of the app.',
                    'packs' => 'Coin packs are shown in the Shop of every player of the app.',
                    'rank' => 'Ranks, RP, missions and the Nearby map work the same for every player of the app.',
                    'refer' => 'Refer & Earn works for every player: a new player who enters a friend\'s code gets +50, the friend gets +100.',
                    'purch' => 'Google Play coin purchases of every player (verified on the server by verify-play-purchase).', 'coupons' => 'A coupon works for every player of the app: any player can enter it once (on the code screen right after sign-up).');
    echo '<link rel="stylesheet" href="/admin/assets/app.css?v=8"><script src="/admin/assets/app.js?v=5" defer></script>';
    echo '<div class="apptop"><div class="navgroups">';
    foreach ($groups as $gr) {
        echo '<div class="navgroup"><div class="ngh">' . e($gr[0]) . ' <span>' . e($gr[1]) . '</span></div><nav class="subnav">';
        foreach ($gr[2] as $k => $t) { echo '<a href="' . e($t[0]) . '"' . ($k === $sub ? ' class="on"' : '') . '>' . e($t[1]) . '</a>'; }
        echo '</nav></div>';
    }
    echo '</div></div>';
    if (isset($global[$sub])) { echo '<div class="scope"><span class="badge b-teal">ALL PLAYERS</span> ' . e($global[$sub]) . '</div>'; }
}
