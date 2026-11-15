<?php
/*
 * GET  /api/notify/?vid=...   -> {ok, unread, items:[{id,created_at,kind,title,body,read}]}
 * POST /api/notify/           -> body {vid, action:"read"} marks that visitor's notifications as read
 * The vid is the random id kept in the visitor's own browser, so a visitor only sees messages sent to them.
 */
require __DIR__ . '/../../includes/supabase.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function nout($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }

$ip = client_ip();
$rlKey = 'notify:' . $ip;
if (rl_count($rlKey, 600) >= 150) { nout(429, array('ok' => false)); }
rl_add($rlKey, 600);

$method = $_SERVER['REQUEST_METHOD'];
$in = array();
if ($method === 'POST') {
    $allowed = cfg('allowed_origins', array());
    if (is_array($allowed) && count($allowed) > 0) {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        if ($origin === '' || !in_array(rtrim($origin, '/'), $allowed, true)) { nout(403, array('ok' => false)); }
    }
    $j = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($j)) { $in = $j; }
} elseif ($method === 'GET') {
    $in = $_GET;
} else {
    nout(405, array('ok' => false));
}
$vid = preg_replace('/[^A-Za-z0-9_-]/', '', clean_str(isset($in['vid']) ? $in['vid'] : '', 64));
if (strlen($vid) < 8) { nout(200, array('ok' => true, 'unread' => 0, 'items' => array())); }

if ($method === 'POST') {
    sb_update(tbl('notify'), array('visitor_id' => 'eq.' . $vid, 'read_at' => 'is.null'), array('read_at' => gmdate('c')));
    nout(200, array('ok' => true));
}

list($rows, , $r) = sb_select(tbl('notify'), array('visitor_id' => 'eq.' . $vid, 'order' => 'created_at.desc', 'select' => 'id,created_at,kind,title,body,read_at'), 20, 0);
if (!sb_ok($r)) { nout(502, array('ok' => false)); }
$items = array(); $unread = 0;
foreach ($rows as $n) {
    $read = !empty($n['read_at']);
    if (!$read) { $unread++; }
    $items[] = array('id' => $n['id'], 'created_at' => $n['created_at'], 'kind' => $n['kind'],
        'title' => $n['title'], 'body' => isset($n['body']) ? $n['body'] : '', 'read' => $read);
}
nout(200, array('ok' => true, 'unread' => $unread, 'items' => $items));
