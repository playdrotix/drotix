<?php
/* Mobile App: Support tickets (inbox + conversation). Players raise tickets in the app; admins reply here.
   Live updates: the page polls this server every 8 s (no Supabase key in the browser). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$CATS = array('payment_failed' => 'Payment failed', 'payment_pending' => 'Payment pending', 'coins_missing' => 'Coins not added', 'account' => 'Account', 'bug' => 'Bug', 'other' => 'Other');
$STAT = array('open' => 'Open', 'in_progress' => 'In progress', 'waiting_user' => 'Waiting for player', 'resolved' => 'Resolved', 'closed' => 'Closed');
$PAY = array('payment_failed', 'payment_pending', 'coins_missing');
$QUICK = array(
    "We're checking your payment with Google Play. We'll update you here within 24 hours.",
    "We found your payment and added the coins to your wallet. Please check and confirm.",
    "Google Play shows this payment as pending/refunded. Pending payments complete or refund automatically within 3 days.",
    "Please send the Google Play order id (GPA.…) from your receipt email.",
);
function tk_status_badge($st) {
    $c = array('open' => 'b-pink', 'in_progress' => 'b-blue', 'waiting_user' => 'b-gold', 'resolved' => 'b-green', 'closed' => 'b-grey');
    $l = array('open' => 'Open', 'in_progress' => 'In progress', 'waiting_user' => 'Waiting for player', 'resolved' => 'Resolved', 'closed' => 'Closed');
    return '<span class="badge ' . (isset($c[$st]) ? $c[$st] : 'b-grey') . '">' . e(isset($l[$st]) ? $l[$st] : $st) . '</span>';
}

$fStatus = isset($_GET['status']) ? (string) $_GET['status'] : 'active';
if ($fStatus !== '' && $fStatus !== 'active' && !isset($STAT[$fStatus])) { $fStatus = 'active'; }
$fCat = isset($_GET['cat'], $CATS[$_GET['cat']]) ? (string) $_GET['cat'] : '';
$fQ = app_get('q', 80);
$id = isset($_GET['id']) && uuid_ok($_GET['id']) ? (string) $_GET['id'] : '';
$keep = array('status' => $fStatus, 'cat' => $fCat, 'q' => $fQ);
$self = function ($extra = array()) use ($keep) { $q = array_merge($keep, $extra); foreach ($q as $k => $v) { if ($v === '' || $v === null) { unset($q[$k]); } } return '/admin/app/tickets/' . ($q ? '?' . http_build_query($q) : ''); };

/* ---------- live poll (JSON): signature of the list + open conversation ---------- */
if (isset($_GET['poll'])) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $l = app_list_tickets($fStatus ?: null, $fCat, $fQ, 30, 0);
    $sig = json_encode(g($l['data'], 'counts')); foreach ((array) g($l['data'], 'items', array()) as $t) { $sig .= g($t, 'id') . gx($t, array('last_message_at', 'updated_at')) . g($t, 'status') . app_ticket_count_unread($t); }
    if ($id !== '') { $tm = app_ticket_msg_table(); if ($tm) { list($ms, $n, ) = app_select($tm, array('ticket_id' => 'eq.' . $id, 'select' => 'id', 'order' => 'created_at.desc'), 1, 0, true); $sig .= '|' . $n . (isset($ms[0]['id']) ? $ms[0]['id'] : ''); } }
    echo json_encode(array('sig' => md5($sig))); exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $tid = isset($_POST['ticket_id']) && uuid_ok($_POST['ticket_id']) ? (string) $_POST['ticket_id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $back = $self(array('id' => $tid));
    if ($tid === '') { flash_set('err', 'Bad ticket.'); redirect($self()); }
    if ($act === 'reply') {
        $msg = trim((string) (isset($_POST['message']) ? $_POST['message'] : ''));
        $st = isset($_POST['set_status'], $STAT[$_POST['set_status']]) ? (string) $_POST['set_status'] : 'waiting_user';
        if ($msg === '') { flash_set('err', 'Write a reply first.'); redirect($back); }
        $r = app_reply_ticket($tid, mb_substr($msg, 0, 4000), $st);
        if ($r['ok']) { app_log('reply_ticket', $tid, $st); $mm = app_mail_ticket(app_ticket_row($tid), 'reply', $st, $msg); flash_set('ok', 'Reply sent (notification' . ($mm[0] ? ' + email' : '') . '). Status: ' . $STAT[$st] . '.'); } else { flash_set('err', 'Reply failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'status') {
        $st = isset($_POST['set_status'], $STAT[$_POST['set_status']]) ? (string) $_POST['set_status'] : '';
        $pr = isset($_POST['priority']) && in_array($_POST['priority'], array('normal', 'high'), true) ? (string) $_POST['priority'] : null;
        if ($st === '') { flash_set('err', 'Choose a status.'); redirect($back); }
        $r = app_set_ticket_status($tid, $st, $pr);
        if ($r['ok'] && in_array($st, array('in_progress', 'resolved', 'closed'), true)) { app_mail_ticket(app_ticket_row($tid), 'status', $st); }
        if ($r['ok']) { app_log('ticket_status', $tid, $st . ($pr ? ' / ' . $pr : '')); flash_set('ok', 'Ticket: ' . $STAT[$st] . ($pr ? ', priority ' . $pr : '') . '.'); } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'credit') {
        $uid = isset($_POST['user_id']) && uuid_ok($_POST['user_id']) ? (string) $_POST['user_id'] : '';
        $amount = (int) (isset($_POST['amount']) ? $_POST['amount'] : 0);
        $ref = app_post('reference', 200); $note = app_post('note', 300);
        if ($uid === '' || $amount <= 0 || $ref === '' || $note === '') { flash_set('err', 'Amount, order id and note are required.'); redirect($back); }
        list($ok, $m, $dup) = app_wallet_adjust_any($uid, $amount, 'credit', $note, $ref);
        flash_set($ok ? 'ok' : 'err', $m . ($ok ? ' Now send the reply below and set the ticket to Resolved.' : ''));
        redirect($self(array('id' => $tid, 'credited' => $ok ? '1' : null)));
    }
    redirect($back);
}

/* ---------- data ---------- */
$lr = app_list_tickets($fStatus ?: null, $fCat, $fQ, 100, 0);
$items = $lr['ok'] ? (array) g($lr['data'], 'items', array()) : array();
$counts = $lr['ok'] ? (array) g($lr['data'], 'counts', array()) : array();
$T = null;
if ($id !== '') { $tr = app_get_ticket($id); $T = $tr['ok'] ? $tr['data'] : null; }

app_head('Support tickets', 'tickets');
echo '<div data-tkpoll="' . e($self(array('id' => $id, 'poll' => '1'))) . '"></div>';
echo '<div class="tk-count">';
foreach ($STAT as $k => $v) { echo '<a class="tkc' . ($fStatus === $k ? ' on' : '') . '" href="' . e($self(array('status' => $k, 'id' => null))) . '"><b>' . app_num(g($counts, $k, 0)) . '</b><span>' . e($v) . '</span></a>'; }
echo '<a class="tkc' . ($fStatus === 'active' ? ' on' : '') . '" href="' . e($self(array('status' => 'active', 'id' => null))) . '"><b>' . app_num((int) g($counts, 'open', 0) + (int) g($counts, 'in_progress', 0) + (int) g($counts, 'waiting_user', 0)) . '</b><span>All active</span></a>';
echo (g($counts, 'unread', 0) ? '<span class="badge b-red" style="align-self:center">' . app_num($counts['unread']) . ' unread</span>' : '') . '</div>';
echo '<form class="row" method="get"><select name="status"><option value="active"' . ($fStatus === 'active' ? ' selected' : '') . '>Active (open, in progress, waiting)</option><option value=""' . ($fStatus === '' ? ' selected' : '') . '>All</option>';
foreach ($STAT as $k => $v) { echo '<option value="' . $k . '"' . ($fStatus === $k ? ' selected' : '') . '>' . e($v) . '</option>'; }
echo '</select><select name="cat"><option value="">All categories</option>'; foreach ($CATS as $k => $v) { echo '<option value="' . $k . '"' . ($fCat === $k ? ' selected' : '') . '>' . e($v) . '</option>'; }
echo '</select><input name="q" value="' . e($fQ) . '" placeholder="T-00012, username, order id, subject"><button class="btn sm" type="submit">Filter</button><span class="muted" id="tkLive"><i class="dot on"></i> live</span></form>';
if (!$lr['ok']) { echo '<div class="flash err">Could not load tickets: ' . e(app_rpc_error($lr)) . '</div>'; }

echo '<div class="tk-grid' . ($id !== '' ? ' has-open' : '') . '"><div class="tk-list">';
foreach ($items as $t) {
    $tid = (string) g($t, 'id'); $un = app_ticket_count_unread($t);
    echo '<a class="tk-row' . ($tid === $id ? ' on' : '') . ($un ? ' unread' : '') . '" href="' . e($self(array('id' => $tid))) . '">';
    echo '<div class="tk-top">' . ($un ? '<i class="dot on" title="Unread"></i>' : '') . '<b class="code">' . e(app_ticket_ref($t)) . '</b>' . (g($t, 'priority') === 'high' ? '<span class="badge b-red">HIGH</span>' : '') . '<span class="chip">' . e(isset($CATS[g($t, 'category')]) ? $CATS[$t['category']] : g($t, 'category', '')) . '</span><span class="muted tk-time">' . e(app_ago(gx($t, array('last_message_at', 'updated_at', 'created_at')))) . '</span></div>';
    echo '<div class="tk-sub">' . e(g($t, 'subject', '(no subject)')) . '</div><div class="muted tk-who">' . e(g($t, 'username', '?')) . ' <span class="pno">#' . e(app_pno(g($t, 'player_no'))) . '</span> &middot; ' . tk_status_badge(g($t, 'status')) . '</div>';
    if (g($t, 'last_message')) { echo '<div class="muted tk-prev">' . e(mb_substr((string) $t['last_message'], 0, 90)) . '</div>'; }
    echo '</a>';
}
if (!$items) { echo '<p class="muted" style="padding:16px">' . ($lr['ok'] ? 'No tickets here.' : 'Could not load.') . '</p>'; }
echo '</div><div class="tk-conv">';

if ($id === '') { echo '<div class="panel muted" style="text-align:center;padding:60px 20px">Choose a ticket on the left.</div>'; }
elseif (!$T) { echo '<div class="flash err">Could not open this ticket: ' . e(app_rpc_error($tr)) . '</div>'; }
else {
    $t = (array) g($T, 'ticket', array()); $u = (array) g($T, 'user', array()); $ref = app_ticket_ref($t); $st = (string) g($t, 'status', 'open');
    $uid = (string) gx($u, array('id'), g($t, 'user_id', ''));
    echo '<div class="panel tk-head"><div class="row" style="margin:0;justify-content:space-between;align-items:flex-start"><div><a class="muted tk-back" href="' . e($self()) . '">&larr; Tickets</a><h2 style="margin:4px 0"><span class="code">' . e($ref) . '</span> ' . e(g($t, 'subject', '')) . '</h2>';
    echo '<div class="muted"><span class="chip">' . e(isset($CATS[g($t, 'category')]) ? $CATS[$t['category']] : g($t, 'category', '')) . '</span> Created ' . app_dt(g($t, 'created_at')) . (g($t, 'order_id') ? ' &middot; Order <code class="j">' . e($t['order_id']) . '</code>' : '') . '</div></div>';
    echo '<div class="ucell">' . app_avatar(g($u, 'avatar_url'), g($u, 'player_no'), 44) . '<div>' . app_user_link($uid, g($u, 'username', 'player')) . ' <span class="pno">#' . e(app_pno(g($u, 'player_no'))) . '</span><div class="muted" style="font-size:13px">' . app_badge(g($u, 'account_status')) . ' &middot; ' . app_num(g($u, 'balance')) . ' coins</div></div></div></div>';
    $h = csrf_field() . '<input type="hidden" name="ticket_id" value="' . e($id) . '">';
    echo '<form method="post" class="row" style="margin:12px 0 0" data-tkstatus>' . $h . '<input type="hidden" name="action" value="status"><label class="chk">Status <select name="set_status">';
    foreach ($STAT as $k => $v) { echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . e($v) . '</option>'; }
    echo '</select></label><label class="chk"><input type="checkbox" name="priority" value="high"' . (g($t, 'priority') === 'high' ? ' checked' : '') . ' data-pri> High priority</label><input type="hidden" name="priority" value="normal" disabled data-prinormal><button class="btn ghost sm" type="submit">Update</button></form></div>';

    // payments box
    if (in_array(g($t, 'category'), $PAY, true)) {
        $pur = (array) g($T, 'purchases', array()); $oid = (string) g($t, 'order_id', '');
        echo '<div class="panel"><b>Payments of this player</b><div class="tbl-wrap" style="margin-top:8px"><table><tr><th>Date</th><th>Product</th><th>Coins</th><th>Price</th><th>Order id</th><th>Status</th><th></th></tr>';
        foreach ($pur as $p) {
            $po = (string) g($p, 'order_id', ''); $match = $oid !== '' && $po === $oid;
            echo '<tr' . ($match ? ' class="win"' : '') . '><td class="nowrap">' . app_dt(g($p, 'created_at')) . '</td><td><code class="j">' . e(g($p, 'product_id', '')) . '</code></td><td>' . app_num(g($p, 'coins')) . '</td><td class="nowrap">' . (g($p, 'price_inr') !== null ? e(app_inr($p['price_inr'])) : '-') . '</td>';
            echo '<td><code class="j">' . e($po) . '</code>' . ($match ? ' <span class="badge b-gold">this ticket</span>' : '') . '</td><td>' . app_purchase_badge(g($p, 'status')) . ' ' . (g($p, 'verified') ? '<span class="pos">&#10003;</span>' : '<span class="neg">&#10007;</span>') . '</td><td>';
            if ($po !== '' && g($p, 'status') !== 'credited') { echo '<a class="btn ghost sm" href="' . e($self(array('id' => $id, 'cref' => $po, 'camt' => (int) g($p, 'coins', 0)))) . '#creditBox">Credit coins</a>'; }
            echo '</td></tr>';
        }
        if (!$pur) { echo '<tr><td colspan="7" class="muted">No purchases from this player.</td></tr>'; }
        echo '</table></div>';
        $cref = isset($_GET['cref']) ? clean_str($_GET['cref'], 200) : $oid; $camt = isset($_GET['camt']) ? (int) $_GET['camt'] : 0;
        if ($camt <= 0) { foreach ($pur as $p) { if ((string) g($p, 'order_id') === $cref) { $camt = (int) g($p, 'coins', 0); } } }
        echo '<details class="act" id="creditBox" style="margin-top:10px"' . (isset($_GET['cref']) ? ' open' : '') . '><summary class="btn sm">Credit coins</summary><form class="pop" method="post" data-wallet data-balance="' . e((int) g($u, 'balance', 0)) . '" data-user="' . e(g($u, 'username', 'player')) . '">' . $h . '<input type="hidden" name="action" value="credit"><input type="hidden" name="user_id" value="' . e($uid) . '"><input type="hidden" name="direction" value="credit">'
            . '<div class="warn" style="margin:0;font-size:13px">Use only after checking the order in Play Console &rarr; Order management. The order id is the reference: it can never be credited twice.</div>'
            . '<label>Coins<input name="amount" type="number" min="1" step="1" required value="' . ($camt > 0 ? e($camt) : '') . '"></label><label>Google order id (reference)<input name="reference" required maxlength="200" value="' . e($cref) . '" placeholder="GPA.1234-5678-..."></label>'
            . '<label>Note<input name="note" required maxlength="300" value="Support ticket ' . e($ref) . '"></label><button class="btn sm" type="submit">Credit coins</button></form></details></div>';
    }

    // conversation
    echo '<div class="tk-msgs" id="tkMsgs">';
    foreach ((array) g($T, 'messages', array()) as $m) {
        $snd = (string) g($m, 'sender', 'user');
        if ($snd === 'system') { echo '<div class="tk-sys">' . e(g($m, 'body', '')) . ' <span>' . app_dt(g($m, 'created_at')) . '</span></div>'; continue; }
        echo '<div class="tk-msg ' . ($snd === 'admin' ? 'me' : 'them') . '"><div class="tk-bub">' . nl2br(e(g($m, 'body', '')));
        if (g($m, 'attachment_path')) { $su = app_signed_url(APP_SUPPORT_BUCKET, $m['attachment_path'], 600); echo $su !== '' ? '<a href="' . e($su) . '" target="_blank" rel="noopener"><img class="tk-img" src="' . e($su) . '" alt="screenshot"></a>' : '<div class="muted">(screenshot unavailable)</div>'; }
        echo '</div><div class="tk-meta">' . ($snd === 'admin' ? 'Support' : e(g($u, 'username', 'Player'))) . ' &middot; ' . app_dt(g($m, 'created_at')) . '</div></div>';
    }
    if (!g($T, 'messages')) { echo '<p class="muted">No messages.</p>'; }
    echo '</div>';

    // reply
    $credited = isset($_GET['credited']);
    echo '<form method="post" class="panel tk-reply" id="tkReply">' . $h . '<input type="hidden" name="action" value="reply"><div class="tk-quick">';
    foreach ($QUICK as $q) { echo '<button type="button" class="chip" data-insert="' . e($q) . '">' . e(mb_substr($q, 0, 42)) . '…</button>'; }
    echo '</div><textarea name="message" id="tkText" rows="4" maxlength="4000" required placeholder="Write a reply to the player…">' . ($credited ? e($QUICK[1]) : '') . '</textarea>';
    echo '<div class="row" style="margin:10px 0 0"><label class="chk">Send and set status <select name="set_status" id="tkSet">';
    foreach (array('waiting_user' => 'Waiting for player', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed') as $k => $v) { echo '<option value="' . $k . '"' . ($credited && $k === 'resolved' ? ' selected' : '') . '>' . e($v) . '</option>'; }
    echo '</select></label><button class="btn" type="submit">Send reply</button><span class="muted">The player gets a push + in-app notification.</span></div></form>';
}
echo '</div></div>';
echo '<script src="/admin/assets/app-tickets.js?v=1" defer></script>';
admin_foot();
