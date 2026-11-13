<?php
/* Admin panel shared layout + helpers. Included by every page in /admin/. */
require_once __DIR__ . '/supabase.php';
security_headers(true);
admin_session_start();

function admin_require_login() {
    $idle = max(5, (int) cfg('session_idle_minutes', 30)) * 60;
    if (empty($_SESSION['admin']) || (isset($_SESSION['last']) && time() - $_SESSION['last'] > $idle)) {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        header('Location: /admin/login/');
        exit;
    }
    $_SESSION['last'] = time();
}
function redirect($url) { header('Location: ' . $url); exit; }
function fmt_dt($iso) {
    if (!$iso) { return '-'; }
    $t = strtotime($iso);
    return $t ? date('d M Y, H:i', $t) : e($iso);
}
function badge($status) {
    $map = array('new' => 'b-pink', 'pending' => 'b-pink', 'read' => 'b-grey', 'verifying' => 'b-gold',
                 'replied' => 'b-teal', 'completed' => 'b-teal', 'archived' => 'b-grey', 'rejected' => 'b-grey');
    $c = isset($map[$status]) ? $map[$status] : 'b-grey';
    return '<span class="badge ' . $c . '">' . e($status) . '</span>';
}
function due_label($iso, $status) {
    if (in_array($status, array('completed', 'rejected'), true)) { return '<span class="muted">-</span>'; }
    $t = strtotime($iso);
    if (!$t) { return '-'; }
    $diff = $t - time();
    if ($diff < 0) {
        $h = (int) floor(-$diff / 3600);
        return '<span class="badge b-red">overdue ' . ($h >= 48 ? floor($h / 24) . 'd' : $h . 'h') . '</span>';
    }
    $h = (int) floor($diff / 3600);
    return '<span class="badge b-gold">due in ' . ($h >= 48 ? floor($h / 24) . 'd' : $h . 'h') . '</span>';
}
function qs($extra = array()) {
    $q = array_merge($_GET, $extra);
    foreach ($q as $k => $v) { if ($v === null || $v === '') { unset($q[$k]); } }
    return '?' . http_build_query($q);
}
function pager($page, $total, $per) {
    if ($total === null || $total <= $per) { return ''; }
    $pages = (int) ceil($total / $per);
    $h = '<div class="pager">';
    if ($page > 1) { $h .= '<a href="' . e(qs(array('page' => $page - 1))) . '">&larr; Prev</a>'; }
    $h .= '<span>Page ' . (int) $page . ' of ' . $pages . ' &middot; ' . (int) $total . ' rows</span>';
    if ($page < $pages) { $h .= '<a href="' . e(qs(array('page' => $page + 1))) . '">Next &rarr;</a>'; }
    return $h . '</div>';
}
/** Download rows as CSV (cells that start with = + - @ are neutralised to block spreadsheet formula injection). */
function csv_out($filename, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    $cols = array();
    foreach ($rows as $r) { foreach (array_keys($r) as $k) { if (!in_array($k, $cols, true)) { $cols[] = $k; } } }
    fputcsv($f, $cols);
    foreach ($rows as $r) {
        $line = array();
        foreach ($cols as $c) {
            $v = isset($r[$c]) ? $r[$c] : '';
            if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
            $v = (string) $v;
            if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) { $v = "'" . $v; }
            $line[] = $v;
        }
        fputcsv($f, $line);
    }
    fclose($f);
    exit;
}
function admin_head($title, $active) {
    $nav = array(
        'dash' => array('/admin/', 'Dashboard'),
        'msg' => array('/admin/messages/', 'Messages'),
        'del' => array('/admin/deletions/', 'Deletion requests'),
        'wait' => array('/admin/waitlist/', 'Waitlist'),
        'exp' => array('/admin/explorer/', 'Data Explorer'),
    );
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' - Drotix Admin</title>';
    echo '<link rel="stylesheet" href="/adminhttps://playdrotix.in/assets/admin.css"><script src="/adminhttps://playdrotix.in/assets/admin.js" defer></script></head><body><div class="shell"><aside class="side">';
    echo '<div class="logo"><span class="sh"></span><b>DROTIX</b><small>Admin</small></div><nav>';
    foreach ($nav as $k => $v) {
        echo '<a href="' . $v[0] . '"' . ($k === $active ? ' class="on"' : '') . '>' . e($v[1]) . '</a>';
    }
    echo '</nav><form method="post" action="/admin/logout/" class="lo">' . csrf_field() . '<button class="btn ghost" type="submit">Log out (' . e($_SESSION['admin']) . ')</button></form></aside><main class="main">';
    echo '<h1>' . e($title) . '</h1>';
    $f = flash_get();
    if ($f) { echo '<div class="flash ' . e($f[0]) . '">' . e($f[1]) . '</div>'; }
}
function admin_foot() { echo '</main></div></body></html>'; }
function uuid_ok($s) { return (bool) preg_match('/^[0-9a-fA-F-]{36}$/', (string) $s); }
