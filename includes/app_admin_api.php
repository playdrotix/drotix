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
function app_admin_token() {
    $c = isset($_SESSION['app_admin_tok']) ? $_SESSION['app_admin_tok'] : null;
    if ($c && $c['exp'] - 120 > time()) { return $c['token']; }
    if ($c && isset($c['fail']) && $c['fail'] > time() - 120) { return null; }   // do not retry every request
    $email = (string) cfg('app_admin_email', '');
    if ($email === '') {
        $pt = app_profiles_table(); $pc = app_cols($pt);
        if (isset($pc['is_admin'])) {
            list($rows, , ) = app_select($pt, array('is_admin' => 'eq.true', 'select' => app_profile_idcol()), 5, 0, false);
            foreach ($rows as $r) { $u = app_auth_get($r[app_profile_idcol()]); if ($u['ok'] && g($u['data'], 'email')) { $email = $u['data']['email']; break; } }
        }
    }
    $tok = null;
    if ($email !== '') {
        $l = app_http('POST', '/auth/v1/admin/generate_link', array('type' => 'magiclink', 'email' => $email));
        $hash = gx($l['data'], array('hashed_token', 'properties.hashed_token'));
        if ($l['ok'] && $hash) {
            foreach (array('magiclink', 'email') as $ty) {
                $v = app_http('POST', '/auth/v1/verify', array('type' => $ty, 'token_hash' => $hash));
                if ($v['ok'] && !empty($v['data']['access_token'])) {
                    $tok = $v['data']['access_token'];
                    $_SESSION['app_admin_tok'] = array('token' => $tok, 'exp' => time() + (int) g($v['data'], 'expires_in', 3600), 'email' => $email);
                    break;
                }
            }
        }
    }
    if ($tok === null) { $_SESSION['app_admin_tok'] = array('fail' => time(), 'exp' => 0, 'token' => null, 'email' => $email); }
    return $tok;
}
function app_admin_email() { return isset($_SESSION['app_admin_tok']['email']) ? (string) $_SESSION['app_admin_tok']['email'] : ''; }

/** Call a drotix RPC: as an app admin when possible, otherwise with the service key. */
function app_rpc($fn, $params = array()) {
    if (!app_valid_ident($fn)) { return array('ok' => false, 'data' => null, 'error' => 'Invalid function'); }
    $h = array('Content-Profile' => APP_SCHEMA, 'Accept-Profile' => APP_SCHEMA);
    $tok = strpos($fn, 'admin_') === 0 ? app_admin_token() : null;
    if ($tok) { $h['Authorization'] = 'Bearer ' . $tok; }
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
    $c = isset($_SESSION['app_schema3']) ? $_SESSION['app_schema3'] : null;
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
            foreach (array_keys((array) g($r['data'], 'paths', array())) as $p) { if (strpos($p, '/rpc/') === 0) { $c['rpc'][] = substr($p, 5); } }
        }
        if ($c['ok']) { $_SESSION['app_schema3'] = $c; }
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
function app_list_legal() { return app_rpc('admin_list_legal'); }
/** -> {status, id, version}. $sections = array(array('heading' => ..., 'body' => ...), ...) */
function app_publish_legal($type, $title, $sections, $website, $email, $summary, $requiresAcceptance) {
    return app_rpc('admin_publish_legal', array('p_doc_type' => $type, 'p_title' => $title, 'p_sections' => $sections,
        'p_contact_website' => $website !== '' ? $website : null, 'p_contact_email' => $email !== '' ? $email : null,
        'p_summary' => $summary !== '' ? $summary : null, 'p_requires_acceptance' => (bool) $requiresAcceptance));
}
/** {status, items: [{id, platform, version_name, version_code, min_supported_version_code, update_available, apk_url, apk_sha256, apk_size_bytes, ios_app_store_url, release_notes[], published_at}]} */
function app_list_app_releases($limit = 30) { return app_rpc('admin_list_app_releases', array('p_limit' => (int) $limit)); }
/** -> {status, id} | {status:'error', message} */
function app_publish_app_release($a) {
    return app_rpc('admin_publish_app_release', array(
        'p_version_name' => $a['version_name'], 'p_version_code' => (int) $a['version_code'], 'p_min_supported_version_code' => (int) $a['min_supported'],
        'p_apk_url' => $a['apk_url'] !== '' ? $a['apk_url'] : null, 'p_apk_sha256' => $a['apk_sha256'] !== '' ? $a['apk_sha256'] : null,
        'p_apk_size_bytes' => $a['apk_size'] !== '' ? (int) $a['apk_size'] : null, 'p_release_notes' => $a['notes'],
        'p_platform' => $a['platform'], 'p_ios_app_store_url' => $a['ios_url'] !== '' ? $a['ios_url'] : null, 'p_update_available' => (bool) $a['update_available']));
}
/* ---------- coupons ----------
 * Coupon = {code, coins, max_uses|null, used_count, starts_at|null, expires_at|null, active, note|null, created_at, updated_at,
 *           state: 'live'|'scheduled'|'expired'|'used_up'|'disabled'} */
/** {status, total_coins_given, items: [Coupon]} */
function app_list_coupons($search) { return app_rpc('admin_list_coupons', array('p_search' => $search !== '' ? $search : null)); }
/** Create or edit (same code). -> {status, created: bool, coupon: Coupon} | {status:'error', message} */
function app_save_coupon($code, $coins, $maxUses, $startsAt, $expiresAt, $note, $active) {
    return app_rpc('admin_save_coupon', array('p_code' => strtoupper($code), 'p_coins' => (int) $coins, 'p_max_uses' => $maxUses === null ? null : (int) $maxUses,
        'p_starts_at' => $startsAt, 'p_expires_at' => $expiresAt, 'p_note' => $note !== '' ? $note : null, 'p_active' => (bool) $active));
}
/** -> {status, coupon} */
function app_set_coupon_active($code, $active) { return app_rpc('admin_set_coupon_active', array('p_code' => $code, 'p_active' => (bool) $active)); }
/** -> {status, total, items: [{user_id, username, player_no, coins, redeemed_at}]} */
function app_coupon_redemptions($code, $limit = 100, $offset = 0) { return app_rpc('admin_coupon_redemptions', array('p_code' => $code, 'p_limit' => (int) $limit, 'p_offset' => (int) $offset)); }

/* ---------- coin purchases (money spent) per player, read from the game's purchase table ---------- */
/** Detect the purchase table + its money / coins / currency / status columns. null when none. */
function app_purchase_source() {
    static $src = false;
    if ($src !== false) { return $src; }
    $src = null;
    $moneyCols = array('amount_paid_minor', 'price_minor', 'price_paise', 'amount_minor', 'paid_minor', 'revenue_minor', 'price_amount_micros', 'price_micros', 'amount_micros', 'amount_paid', 'price', 'amount', 'amount_inr', 'paid_amount', 'price_inr');
    foreach (app_tables() as $t => $i) {
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
    return !preg_match('/(refund|fail|cancel|pending|revok|void|chargeback)/i', (string) g($row, $src['status'], ''));
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
    $items = $r['ok'] ? (array) g($r['data'], 'items', array()) : array();
    $ids = array(); foreach ($items as $i) { if (!empty($i['user_id'])) { $ids[$i['user_id']] = $i; } }
    return $c[$limit] = array('ok' => $r['ok'], 'count' => $r['ok'] ? g($r['data'], 'online_count', count($items)) : null, 'items' => $items, 'ids' => $ids, 'error' => $r['error']);
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
               'tournament_status' => 'Tournament status', 'tournament_notify' => 'Notified tournament', 'row_update' => 'Edited data row', 'row_insert' => 'Added data row', 'row_delete' => 'Deleted data row',
               'publish_legal' => 'Published legal document', 'publish_app_release' => 'Published app release',
               'create_coupon' => 'Created coupon', 'edit_coupon' => 'Edited coupon', 'enable_coupon' => 'Enabled coupon', 'disable_coupon' => 'Disabled coupon');
    return isset($m[$a]) ? $m[$a] : ucfirst(str_replace('_', ' ', $a));
}
/** Error text for a failed game function, with the fix when no app admin exists. */
function app_rpc_error($r) {
    $e = (string) $r['error'];
    if (stripos($e, 'forbidden') !== false || (isset($r['status']) && $r['status'] === 401) || !app_admin_token()) {
        $e .= ' (No app admin session: open Players, pick your own account and set is_admin = true.)';
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
            'appeals' => array('/admin/app/appeals/', 'Appeals'))),
        array('Whole app', 'Applies to all players', array(
            'online' => array('/admin/app/online/', 'Online now'), 'cast' => array('/admin/app/broadcast/', 'Broadcast'),
            'legal' => array('/admin/app/legal/', 'Privacy & Terms'), 'rel' => array('/admin/app/releases/', 'App update'),
            'tour' => array('/admin/app/tournaments/', 'Tournaments'), 'coupons' => array('/admin/app/coupons/', 'Coupons'))),
        array('Data', 'Reports & raw data', array(
            'dash' => array('/admin/app/', 'Stats'), 'data' => array('/admin/app/data/', 'All data'), 'audit' => array('/admin/app/audit/', 'Audit log'))),
    );
    $global = array('online' => 'Shows every player who is online right now.', 'cast' => 'Sent to every player of the app, not to one player.',
                    'legal' => 'One Privacy Policy and one Terms of Service for the whole app, shown to every player.',
                    'rel' => 'One app update for everyone: every player on an older version sees the update screen.',
                    'tour' => 'Tournaments are open to all players.', 'coupons' => 'Any new player can use a coupon code once.');
    echo '<link rel="stylesheet" href="/admin/assets/app.css?v=5"><script src="/admin/assets/app.js?v=5" defer></script>';
    echo '<div class="apptop"><div class="navgroups">';
    foreach ($groups as $gr) {
        echo '<div class="navgroup"><div class="ngh">' . e($gr[0]) . ' <span>' . e($gr[1]) . '</span></div><nav class="subnav">';
        foreach ($gr[2] as $k => $t) { echo '<a href="' . e($t[0]) . '"' . ($k === $sub ? ' class="on"' : '') . '>' . e($t[1]) . '</a>'; }
        echo '</nav></div>';
    }
    echo '</div></div>';
    if (isset($global[$sub])) { echo '<div class="scope"><span class="badge b-teal">ALL PLAYERS</span> ' . e($global[$sub]) . '</div>'; }
}
