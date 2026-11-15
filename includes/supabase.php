<?php
/* Minimal Supabase (PostgREST) client using cURL. Server-side only. */
require_once __DIR__ . '/bootstrap.php';

function sb_source($src = null) {
    $url = ($src && !empty($src['url'])) ? $src['url'] : cfg('supabase_url', '');
    $key = ($src && !empty($src['key'])) ? $src['key'] : cfg('supabase_key', '');
    return array(rtrim((string) $url, '/'), (string) $key);
}
function sb_configured($src = null) {
    list($u, $k) = sb_source($src);
    return $u !== '' && $k !== '' && strpos($u, 'YOUR-') === false && strpos($k, 'YOUR-') === false;
}
function sb_valid_table($t) { return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', (string) $t); }

/**
 * Raw request. Returns array(status, data, raw, headers, error).
 * $query: associative array of PostgREST filters/params (e.g. array('status' => 'eq.new')).
 */
function sb_request($method, $table, $query = array(), $body = null, $headers = array(), $src = null) {
    $res = array('status' => 0, 'data' => null, 'raw' => '', 'headers' => array(), 'error' => '');
    if (!sb_configured($src)) { $res['error'] = 'Supabase is not configured in includes/config.php'; return $res; }
    if (!sb_valid_table($table)) { $res['error'] = 'Invalid table name'; return $res; }
    list($base, $key) = sb_source($src);
    $url = $base . '/rest/v1/' . $table;
    if (!empty($query)) { $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986); }

    $h = array('apikey: ' . $key, 'Authorization: Bearer ' . $key, 'Accept: application/json');
    if ($body !== null) { $h[] = 'Content-Type: application/json'; }
    foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }

    $respHeaders = array();
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) { $respHeaders[strtolower(trim($p[0]))] = trim($p[1]); }
            return strlen($line);
        },
    ));
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    if ($raw === false) { $res['error'] = curl_error($ch); }
    $res['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res['raw'] = is_string($raw) ? $raw : '';
    $res['headers'] = $respHeaders;
    $data = json_decode($res['raw'], true);
    $res['data'] = (json_last_error() === JSON_ERROR_NONE) ? $data : null;
    if ($res['status'] >= 400 && $res['error'] === '') {
        $m = (is_array($res['data']) && isset($res['data']['message'])) ? $res['data']['message'] : ('HTTP ' . $res['status']);
        $res['error'] = $m . ((is_array($res['data']) && !empty($res['data']['hint'])) ? ' (' . $res['data']['hint'] . ')' : '');
    }
    return $res;
}
function sb_ok($r) { return $r['status'] >= 200 && $r['status'] < 300; }

function sb_insert($table, $row, $query = array(), $prefer = 'return=minimal') {
    return sb_request('POST', $table, $query, $row, array('Prefer' => $prefer));
}
function sb_update($table, $filter, $patch) {
    return sb_request('PATCH', $table, $filter, $patch, array('Prefer' => 'return=minimal'));
}
function sb_delete($table, $filter, $returnRows = false) {
    return sb_request('DELETE', $table, $filter, null, array('Prefer' => $returnRows ? 'return=representation' : 'return=minimal'));
}
/** Select with pagination. Returns array(rows, total, res). total is null if unknown. */
function sb_select($table, $query = array(), $limit = 25, $offset = 0, $src = null) {
    $query['limit'] = (int) $limit; $query['offset'] = (int) $offset;
    if (!isset($query['select'])) { $query['select'] = '*'; }
    $r = sb_request('GET', $table, $query, null, array('Prefer' => 'count=exact'), $src);
    $rows = (sb_ok($r) && is_array($r['data'])) ? $r['data'] : array();
    $total = null;
    if (!empty($r['headers']['content-range']) && preg_match('~/(\d+)$~', $r['headers']['content-range'], $m)) { $total = (int) $m[1]; }
    return array($rows, $total, $r);
}
function sb_count($table, $query = array(), $src = null) {
    $query['select'] = 'id';
    list($rows, $total, $r) = sb_select($table, $query, 1, 0, $src);
    return sb_ok($r) ? (int) $total : null;
}
/** Fetch up to $cap rows (for CSV export). */
function sb_all($table, $query = array(), $cap = 5000, $src = null) {
    $all = array(); $off = 0; $step = 1000;
    while (count($all) < $cap) {
        list($rows, $total, $r) = sb_select($table, $query, $step, $off, $src);
        if (!sb_ok($r) || empty($rows)) { break; }
        $all = array_merge($all, $rows);
        if (count($rows) < $step) { break; }
        $off += $step;
    }
    return array_slice($all, 0, $cap);
}
function audit_log($action, $target = '', $details = '') {
    $who = isset($_SESSION['admin']) ? $_SESSION['admin'] : 'system';
    sb_insert(tbl('audit'), array('admin_user' => $who, 'action' => $action, 'target' => (string) $target, 'details' => (string) $details));
}
/** Strip characters that have a meaning inside PostgREST filter values. */
function pg_safe($s) { return trim(preg_replace('/[,()*%\\\\"\'`;:]/', ' ', (string) $s)); }
