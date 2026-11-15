<?php
/* GET /api/live/  ->  {ok, online, total, today}   (public numbers for the footer "players alive" panel; cached 10 s) */
require __DIR__ . '/../../includes/supabase.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

$cache = sys_get_temp_dir() . '/drotix_live_' . md5(__DIR__) . '.json';
if (is_file($cache) && time() - (int) @filemtime($cache) < 10) { echo (string) @file_get_contents($cache); exit; }

$online = sb_count(tbl('presence'), array('last_seen' => 'gte.' . gmdate('c', time() - 120)));
$total  = sb_count(tbl('visits'));
$today  = sb_count(tbl('visits'), array('created_at' => 'gte.' . gmdate('c', strtotime('today'))));
if ($online === null || $total === null) {
    http_response_code(503);
    echo json_encode(array('ok' => false));
    exit;
}
$out = json_encode(array('ok' => true, 'online' => max(1, (int) $online), 'total' => (int) $total, 'today' => (int) $today));
@file_put_contents($cache, $out, LOCK_EX);
echo $out;
