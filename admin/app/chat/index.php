<?php
/* Mobile App: Chat moderation. The server blocks abusive words in match chat; every blocked message lands here.
   Tabs: Reports (default) / Offenders / Word list. Live updates by polling this server every 8 s. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$tab = isset($_GET['tab']) && in_array($_GET['tab'], array('reports', 'offenders', 'words'), true) ? (string) $_GET['tab'] : 'reports';
$ST = array('new' => 'New', 'reviewed' => 'Reviewed', 'dismissed' => 'Dismissed', 'actioned' => 'Actioned', 'all' => 'All');
$fs = isset($_GET['status'], $ST[$_GET['status']]) ? (string) $_GET['status'] : 'new';
$fUser = isset($_GET['user']) && uuid_ok($_GET['user']) ? (string) $_GET['user'] : '';
if ($fUser !== '' && !isset($_GET['status'])) { $fs = 'all'; }
$sev = isset($_GET['sev']) ? (string) $_GET['sev'] : '';
$today = isset($_GET['today']);
$hl = isset($_GET['event']) ? (string) $_GET['event'] : '';
$base = function ($extra = array()) use ($tab, $fs, $fUser) {
    $q = array_merge(array('tab' => $tab, 'status' => $fs, 'user' => $fUser), $extra);
    foreach ($q as $k => $v) { if ($v === '' || $v === null) { unset($q[$k]); } }
    return '/admin/app/chat/?' . http_build_query($q);
};

/* ---------- live poll (JSON) ---------- */
if (isset($_GET['poll'])) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $l = app_chat_abuse_list(null, null, 1, 0);
    $top = $l['ok'] ? (array) g($l['data'], 'items.0', array()) : array();
    echo json_encode(array('ok' => $l['ok'], 'latest' => (string) g($top, 'id', ''), 'username' => (string) g($top, 'username', ''), 'counts' => $l['ok'] ? g($l['data'], 'counts') : null));
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $note = app_post('note', 500);
    $back = isset($_POST['back']) && strpos((string) $_POST['back'], '/admin/app/chat/') === 0 ? (string) $_POST['back'] : '/admin/app/chat/';
    if (in_array($act, array('reviewed', 'dismissed', 'mute_24h', 'unmute'), true)) {
        $eid = (string) (isset($_POST['event_id']) ? $_POST['event_id'] : '');
        $r = app_chat_abuse_review($eid, $act, $note);
        $label = array('reviewed' => 'Marked OK (strike kept).', 'dismissed' => 'Wrong catch: one strike removed and the chat is un-paused.', 'mute_24h' => 'Chat paused for 24 hours.', 'unmute' => 'Chat pause lifted.')[$act];
        if ($r['ok']) { app_log('chat_review', $eid, $act . ($note !== '' ? ': ' . $note : '')); flash_set('ok', $label); } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'suspend') {
        $uid = (string) (isset($_POST['user_id']) ? $_POST['user_id'] : '');
        $eid = (string) (isset($_POST['event_id']) ? $_POST['event_id'] : '');
        if (!uuid_ok($uid)) { flash_set('err', 'Bad player.'); redirect($back); }
        $r = app_rpc('admin_suspend_user', array('p_user_id' => $uid, 'p_reason' => 'Abusive chat', 'p_report_id' => null, 'p_admin_notes' => $note !== '' ? $note : null));
        if (!$r['ok']) { $r = app_set_status($uid, true, 'Abusive chat'); } else { app_auth_update($uid, array('ban_duration' => '876000h')); }
        if ($r['ok']) {
            if ($eid !== '') { app_chat_abuse_review($eid, 'reviewed', 'Suspended' . ($note !== '' ? ': ' . $note : '')); }
            app_log('suspend', $uid, 'Abusive chat' . ($note !== '' ? ': ' . $note : ''));
            $mm = app_mail_suspended($uid, 'Abusive chat');
            flash_set('ok', 'Player suspended (reason: Abusive chat).' . ($mm[0] ? ' Email sent.' : ''));
        } else { flash_set('err', 'Suspend failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'word_save') {
        $word = trim(app_post('word', 80));
        $kind = (isset($_POST['kind']) && $_POST['kind'] === 'allow') ? 'allow' : 'block';
        $active = !isset($_POST['active']) || $_POST['active'] === '1';
        if ($word === '') { flash_set('err', 'Enter a word.'); redirect($back); }
        $r = app_chat_word_save($word, $kind, $active);
        if ($r['ok']) { app_log('chat_word_save', $word, $kind . ($active ? '' : ' (off)')); flash_set('ok', '"' . $word . '" saved as ' . $kind . ($active ? '' : ' (inactive)') . (g($r['data'], 'norm') ? ' · matches "' . $r['data']['norm'] . '"' : '') . '.'); }
        else { flash_set('err', 'Save failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'word_delete') {
        $wid = (string) (isset($_POST['word_id']) ? $_POST['word_id'] : '');
        $r = app_chat_word_delete($wid);
        if ($r['ok']) { app_log('chat_word_delete', $wid, app_post('word', 80)); flash_set('ok', 'Word deleted.'); } else { flash_set('err', 'Delete failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'test') {
        $_SESSION['chat_test'] = array('text' => app_post('text', 300));
        $r = app_chat_test($_SESSION['chat_test']['text']);
        $_SESSION['chat_test']['r'] = $r['ok'] ? $r['data'] : array('error' => app_rpc_error($r));
        redirect('/admin/app/chat/?tab=words#test');
    }
    redirect($back);
}

/** Message with the matched word highlighted. */
function hl_msg($msg, $matched) {
    $msg = (string) $msg; $m = trim((string) $matched);
    if ($m !== '' && ($pos = mb_stripos($msg, $m)) !== false) {
        return e(mb_substr($msg, 0, $pos)) . '<mark>' . e(mb_substr($msg, $pos, mb_strlen($m))) . '</mark>' . e(mb_substr($msg, $pos + mb_strlen($m)));
    }
    return e($msg) . ($m !== '' ? ' <span class="chip" title="matched">' . e($m) . '</span>' : '');
}

$cnt = app_chat_abuse_list('new', null, 1, 0);
$counts = $cnt['ok'] ? (array) g($cnt['data'], 'counts', array()) : array();
app_head('Chat moderation', 'chat');
echo '<div data-chatpoll="/admin/app/chat/?poll=1" data-latest=""></div>';
echo '<div class="tk-count">';
echo '<a class="tkc' . ($tab === 'reports' && $fs === 'new' && !$sev && !$today ? ' on' : '') . '" href="/admin/app/chat/?status=new"><b id="cNew">' . app_num(g($counts, 'new', 0)) . '</b><span>New</span></a>';
echo '<a class="tkc' . ($sev === 'review' ? ' on' : '') . '" href="/admin/app/chat/?status=all&amp;sev=review"><b id="cRev" class="' . ((int) g($counts, 'review', 0) ? 'neg' : '') . '">' . app_num(g($counts, 'review', 0)) . '</b><span>Needs review</span></a>';
echo '<a class="tkc' . ($today ? ' on' : '') . '" href="/admin/app/chat/?status=all&amp;today=1"><b id="cToday">' . app_num(g($counts, 'today', 0)) . '</b><span>Today</span></a>';
echo '<a class="tkc' . ($tab === 'reports' && $fs === 'all' && !$sev && !$today && !$fUser ? ' on' : '') . '" href="/admin/app/chat/?status=all"><b id="cTotal">' . app_num(g($counts, 'total', 0)) . '</b><span>Total</span></a>';
echo '<span class="row" style="margin:0 0 0 auto;gap:8px"><span class="muted" id="liveDot"><i class="dot on"></i> live</span><button type="button" class="btn ghost sm" id="muteBtn">Sound: on</button></span></div>';

echo '<details class="panel" style="margin-bottom:14px"><summary class="muted" style="cursor:pointer">How the filter works</summary><ul style="margin:10px 0 0;padding-left:20px;line-height:1.8">'
    . '<li>Only abusive words are stopped. Phone numbers, links and UPI ids are allowed.</li>'
    . '<li>A blocked message is not delivered. The player gets a strike and the chat pauses for 5 minutes (strikes 1–2 in 24 h), 30 minutes (3–4) or 24 hours (5+).</li>'
    . '<li>10 strikes in 7 days &rarr; the report gets severity <span class="badge b-red">REVIEW</span>.</li></ul></details>';

echo '<nav class="tabs" style="margin-top:0">';
foreach (array('reports' => 'Reports', 'offenders' => 'Offenders', 'words' => 'Word list') as $k => $v) { echo '<a href="/admin/app/chat/?tab=' . $k . '"' . ($tab === $k ? ' class="on"' : '') . '>' . e($v) . '</a>'; }
echo '</nav>';
$me = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/admin/app/chat/';

/* ================= Reports ================= */
if ($tab === 'reports') {
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
    $r = app_chat_abuse_list($fs === 'all' ? null : $fs, $fUser ?: null, ($sev || $today) ? 500 : $per, ($sev || $today) ? 0 : ($page - 1) * $per);
    $items = $r['ok'] ? (array) g($r['data'], 'items', array()) : array();
    if ($sev === 'review') { $items = array_values(array_filter($items, function ($x) { return g($x, 'severity') === 'review'; })); }
    if ($today) { $mid = strtotime('today'); $items = array_values(array_filter($items, function ($x) use ($mid) { return strtotime((string) g($x, 'created_at', '')) >= $mid; })); }
    echo '<form class="row" method="get"><input type="hidden" name="tab" value="reports">' . ($fUser ? '<input type="hidden" name="user" value="' . e($fUser) . '">' : '') . '<select name="status">';
    foreach ($ST as $k => $v) { echo '<option value="' . $k . '"' . ($fs === $k ? ' selected' : '') . '>' . e($v) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Filter</button>' . ($fUser ? '<span class="muted">Player: ' . app_user_link($fUser, g($items, '0.username', substr($fUser, 0, 8))) . ' &middot; <a href="/admin/app/chat/">show everyone</a></span>' : '') . '</form>';
    if (!$r['ok']) { echo '<div class="flash err">Could not load reports: ' . e(app_rpc_error($r)) . '</div>'; }
    echo '<div class="tbl-wrap"><table id="chatReports"><tr><th>When</th><th>Player</th><th>Blocked message</th><th>Strikes</th><th>Status</th><th>Actions</th></tr>';
    foreach ($items as $x) {
        $eid = (string) g($x, 'id', ''); $uid = (string) g($x, 'user_id', ''); $st = (string) g($x, 'status', 'new');
        $mutedTo = gx($x, array('muted_now_until', 'muted_until'));
        $muted = $mutedTo && strtotime((string) $mutedTo) > time();
        echo '<tr id="ev-' . e($eid) . '"' . ($hl !== '' && $hl === $eid ? ' class="hl"' : (g($x, 'severity') === 'review' ? ' class="flag"' : '')) . '><td class="nowrap"><b>' . e(app_ago(g($x, 'created_at'))) . '</b><div class="muted" style="font-size:12px">' . app_dt(g($x, 'created_at')) . '</div></td>';
        echo '<td><div class="ucell">' . app_avatar(g($x, 'avatar_url'), g($x, 'player_no'), 32) . '<div>' . app_user_link($uid, g($x, 'username', '(no name)')) . ' <span class="pno">#' . e(app_pno(g($x, 'player_no'))) . '</span><div>' . app_badge(g($x, 'account_status')) . '</div></div></div></td>';
        echo '<td class="chat">' . hl_msg(g($x, 'message', ''), g($x, 'matched', '')) . (g($x, 'severity') === 'review' ? ' <span class="badge b-red">REVIEW</span>' : '') . (g($x, 'admin_note') ? '<div class="muted" style="font-size:12px">Note: ' . e($x['admin_note']) . '</div>' : '') . '</td>';
        echo '<td class="nowrap">Strike <b>' . app_num(g($x, 'strikes_24h', 0)) . '</b> today<div class="muted" style="font-size:12px">' . app_num(g($x, 'strikes_7d', 0)) . ' in 7 days &middot; ' . app_num(g($x, 'strikes_total', 0)) . ' total</div>' . ($muted ? '<div class="neg" style="font-size:12px">Paused until ' . app_dt($mutedTo) . '</div>' : '') . '</td>';
        echo '<td>' . app_badge($st === 'new' ? 'pending' : $st) . '</td><td>';
        $h = csrf_field() . '<input type="hidden" name="event_id" value="' . e($eid) . '"><input type="hidden" name="back" value="' . e($me) . '">';
        echo '<details class="act"><summary class="btn ghost sm">Act&hellip;</summary><form class="pop" method="post">' . $h
            . '<label>Note (optional)<input name="note" maxlength="500" placeholder="e.g. repeated abuse"></label><div class="row" style="margin:0;gap:6px">'
            . '<button class="btn sm" name="action" value="reviewed" type="submit" title="OK, keep the strike">OK</button>'
            . '<button class="btn ghost sm" name="action" value="dismissed" type="submit" title="Removes one strike and un-pauses the chat">Wrong catch</button>'
            . '<button class="btn ghost sm" name="action" value="mute_24h" type="submit">Pause 24 h</button>'
            . ($muted ? '<button class="btn ghost sm" name="action" value="unmute" type="submit">Unpause</button>' : '') . '</div></form></details>';
        if (uuid_ok($uid)) {
            echo '<details class="act"><summary class="btn danger sm">Suspend</summary><form class="pop" method="post" data-confirm="Suspend ' . e(g($x, 'username', 'this player')) . ' for abusive chat? They cannot play until reinstated.">' . $h . '<input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="' . e($uid) . '">'
                . '<div class="muted">Reason: <b>Abusive chat</b></div><label>Note<textarea name="note" maxlength="500" placeholder="Admin note"></textarea></label><button class="btn danger sm" type="submit">Suspend player</button></form></details>';
            echo ' <a class="btn ghost sm" href="/admin/app/users/?id=' . e(rawurlencode($uid)) . '">Open player</a>';
        }
        echo '</td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="6" class="muted">' . ($r['ok'] ? 'No reports here.' : 'Could not load.') . '</td></tr>'; }
    echo '</table></div>' . (!$sev && !$today ? app_pager_simple($page, count($items), $per) : '');
}

/* ================= Offenders ================= */
elseif ($tab === 'offenders') {
    $days = (int) (isset($_GET['days']) ? $_GET['days'] : 7); if (!in_array($days, array(7, 30), true)) { $days = 7; }
    $r = app_chat_offenders($days, 50);
    $items = $r['ok'] ? (array) g($r['data'], 'items', array()) : array();
    echo '<div class="row"><span class="muted">Period:</span>';
    foreach (array(7, 30) as $n) { echo '<a class="btn sm' . ($n === $days ? '' : ' ghost') . '" href="/admin/app/chat/?tab=offenders&amp;days=' . $n . '">' . $n . ' days</a>'; }
    echo '</div>';
    if (!$r['ok']) { echo '<div class="flash err">Could not load offenders: ' . e(app_rpc_error($r)) . '</div>'; }
    echo '<div class="tbl-wrap"><table><tr><th>#</th><th>Player</th><th>Strikes (' . $days . ' days)</th><th>Last</th><th>Chat paused until</th><th>Account</th></tr>';
    foreach (array_values($items) as $i => $o) {
        $uid = (string) g($o, 'user_id', ''); $href = '/admin/app/chat/?tab=reports&user=' . rawurlencode($uid) . '&status=all';
        $mu = g($o, 'muted_until');
        echo '<tr class="click" data-href="' . e($href) . '"><td>' . ($i + 1) . '</td><td><a href="' . e($href) . '"><b>' . e(g($o, 'username', '(no name)')) . '</b></a> <span class="pno">#' . e(app_pno(g($o, 'player_no'))) . '</span></td>';
        echo '<td><b' . ((int) g($o, 'strikes', 0) >= 10 ? ' class="neg"' : '') . '>' . app_num(g($o, 'strikes')) . '</b></td><td class="nowrap">' . e(app_ago(g($o, 'last_at'))) . '</td>';
        echo '<td class="nowrap">' . ($mu && strtotime((string) $mu) > time() ? '<span class="neg">' . app_dt($mu) . '</span>' : '<span class="muted">-</span>') . '</td><td>' . app_badge(g($o, 'account_status')) . '</td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="6" class="muted">' . ($r['ok'] ? 'No offenders in this period.' : 'Could not load.') . '</td></tr>'; }
    echo '</table></div>';
}

/* ================= Word list ================= */
else {
    $q = app_get('q', 60);
    $r = app_chat_words($q);
    $items = $r['ok'] ? (array) g($r['data'], 'items', array()) : array();
    echo '<div class="legal-grid"><div>';
    echo '<form method="post" class="panel row" style="margin-bottom:14px">' . csrf_field() . '<input type="hidden" name="action" value="word_save"><input type="hidden" name="back" value="/admin/app/chat/?tab=words">'
        . '<input name="word" required maxlength="80" placeholder="Add a word (Hindi, Hinglish or English)" style="flex:1;min-width:200px"><select name="kind"><option value="block">Block</option><option value="allow">Allow</option></select><button class="btn sm" type="submit">Add</button></form>';
    echo '<form class="row" method="get"><input type="hidden" name="tab" value="words"><input name="q" value="' . e($q) . '" placeholder="Search words"><button class="btn sm" type="submit">Search</button><span class="muted">' . count($items) . ' words</span></form>';
    if (!$r['ok']) { echo '<div class="flash err">Could not load words: ' . e(app_rpc_error($r)) . '</div>'; }
    echo '<div class="tbl-wrap"><table><tr><th>Word</th><th>Cleaned form</th><th>Kind</th><th>Active</th><th></th></tr>';
    foreach ($items as $w) {
        $wid = (string) g($w, 'id', ''); $on = (bool) g($w, 'active', true); $kind = (string) g($w, 'kind', 'block'); $word = (string) g($w, 'word', '');
        $h = csrf_field() . '<input type="hidden" name="back" value="' . e($me) . '">';
        echo '<tr' . ($on ? '' : ' class="muted"') . '><td><b>' . e($word) . '</b></td><td><code class="j">' . e(g($w, 'norm', '')) . '</code></td><td>' . ($kind === 'allow' ? '<span class="badge b-green">Allow</span>' : '<span class="badge b-red">Block</span>') . '</td>';
        echo '<td><form method="post" data-confirm="Turn ' . e($word) . ' ' . ($on ? 'off' : 'on') . '?">' . $h . '<input type="hidden" name="action" value="word_save"><input type="hidden" name="word" value="' . e($word) . '"><input type="hidden" name="kind" value="' . e($kind) . '"><input type="hidden" name="active" value="' . ($on ? '0' : '1') . '"><button type="submit" class="switch' . ($on ? ' on' : '') . '"><i></i></button></form></td>';
        echo '<td><form method="post" data-confirm="Delete the word &quot;' . e($word) . '&quot;?">' . $h . '<input type="hidden" name="action" value="word_delete"><input type="hidden" name="word_id" value="' . e($wid) . '"><input type="hidden" name="word" value="' . e($word) . '"><button class="btn danger sm" type="submit">Delete</button></form></td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="5" class="muted">' . ($r['ok'] ? 'No words.' : 'Could not load.') . '</td></tr>'; }
    echo '</table></div></div><div>';
    $T = isset($_SESSION['chat_test']) ? $_SESSION['chat_test'] : null; unset($_SESSION['chat_test']);
    echo '<div class="panel" id="test"><b>Test a message</b><form method="post" style="display:grid;gap:10px;margin-top:10px">' . csrf_field() . '<input type="hidden" name="action" value="test">'
        . '<textarea name="text" rows="3" maxlength="300" required placeholder="Type a chat message to test, e.g. ch00tiya, f u c k, call me 9876543210">' . e($T ? $T['text'] : '') . '</textarea><button class="btn sm" type="submit">Test</button></form>';
    if ($T) {
        $tr = $T['r'];
        if (isset($tr['error'])) { echo '<div class="flash err" style="margin:10px 0 0">' . e($tr['error']) . '</div>'; }
        elseif (!empty($tr['blocked'])) { echo '<div class="flash err" style="margin:10px 0 0"><b>Blocked</b> by &lsquo;' . e(g($tr, 'matched', '?')) . '&rsquo;<div class="muted" style="font-size:12px">Cleaned: ' . e(g($tr, 'cleaned', '')) . '</div></div>'; }
        else { echo '<div class="flash ok" style="margin:10px 0 0"><b>Allowed</b><div class="muted" style="font-size:12px">Cleaned: ' . e(g($tr, 'cleaned', '')) . '</div></div>'; }
    }
    echo '</div><div class="panel muted" style="font-size:13px;line-height:1.7"><b style="color:var(--t)">How matching works</b><br>'
        . 'Matching ignores case, repeated letters (fuuuck), number tricks (ch00tiya), spaced letters (f u c k) and Hindi nukta / chandrabindu spellings. Hindi in Devanagari and Hinglish in Roman letters are both supported.<br>'
        . '<b style="color:var(--t)">Allow</b> words are never blocked: use them for false matches (a normal word that contains a bad word).</div></div></div>';
}
echo '<div class="toasts" id="toasts"></div><script src="/admin/assets/app-chat.js?v=1" defer></script>';
admin_foot();
