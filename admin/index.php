<?php
require __DIR__ . '/../includes/admin_layout.php';
admin_require_login();

$nowIso = gmdate('c');
$open = 'in.(pending,verifying)';
$cNew = sb_count(tbl('contact'), array('status' => 'eq.new'));
$cDel = sb_count(tbl('deletions'), array('status' => $open));
$cOver = sb_count(tbl('deletions'), array('status' => $open, 'due_at' => 'lt.' . $nowIso));
$cWait = sb_count(tbl('waitlist'));
list($lastMsg, , $r1) = sb_select(tbl('contact'), array('order' => 'created_at.desc'), 5, 0);
list($lastDel, , $r2) = sb_select(tbl('deletions'), array('order' => 'created_at.desc'), 5, 0);

admin_head('Dashboard', 'dash');
$n = function ($v) { return $v === null ? '?' : (string) $v; };

if (!sb_configured()) {
    echo '<div class="flash err">Supabase is not configured yet. Open includes/config.php and set supabase_url and supabase_key.</div>';
} elseif (!sb_ok($r1) || !sb_ok($r2)) {
    echo '<div class="flash err">Could not read the tables. Did you run database/schema.sql in Supabase? Details: ' . e($r1['error'] . ' ' . $r2['error']) . '</div>';
}
echo '<div class="cards">';
echo '<div class="card' . ($cNew ? ' hot' : '') . '"><div class="n">' . e($n($cNew)) . '</div><div class="l">New messages</div></div>';
echo '<div class="card' . ($cDel ? ' hot' : '') . '"><div class="n">' . e($n($cDel)) . '</div><div class="l">Open deletion requests</div></div>';
echo '<div class="card' . ($cOver ? ' hot' : '') . '"><div class="n">' . e($n($cOver)) . '</div><div class="l">Overdue deletions (past ' . (int) cfg('deletion_days', 2) . ' days)</div></div>';
echo '<div class="card"><div class="n">' . e($n($cWait)) . '</div><div class="l">Waitlist signups</div></div></div>';

echo '<h2>Latest messages</h2><div class="tbl-wrap"><table><tr><th>When</th><th>From</th><th>Topic</th><th>Status</th></tr>';
foreach ($lastMsg as $m) {
    echo '<tr><td>' . fmt_dt($m['created_at']) . '</td><td><a href="/admin/messages/?id=' . e($m['id']) . '">' . e($m['name']) . '</a><div class="muted">' . e($m['email']) . '</div></td><td>' . e(isset($m['topic']) ? $m['topic'] : '') . '</td><td>' . badge($m['status']) . '</td></tr>';
}
if (!$lastMsg) { echo '<tr><td colspan="4" class="muted">No messages yet.</td></tr>'; }
echo '</table></div>';

echo '<h2>Latest deletion requests</h2><div class="tbl-wrap"><table><tr><th>When</th><th>Player</th><th>Status</th><th>Deadline</th></tr>';
foreach ($lastDel as $d) {
    echo '<tr><td>' . fmt_dt($d['created_at']) . '</td><td><a href="/admin/deletions/?id=' . e($d['id']) . '">' . e($d['username']) . '</a><div class="muted">' . e($d['email']) . '</div></td><td>' . badge($d['status']) . '</td><td>' . due_label($d['due_at'], $d['status']) . '</td></tr>';
}
if (!$lastDel) { echo '<tr><td colspan="4" class="muted">No requests yet.</td></tr>'; }
echo '</table></div>';
admin_foot();
