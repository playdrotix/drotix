<?php
/* Mobile App: Players list (/admin/app/users/) and Player page (/admin/app/users/?id=<uuid>&tab=...).
   No extra login: everything is done on the server with the service key. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$PT = app_profiles_table();
$PC = app_cols($PT);
$IDC = app_profile_idcol();
$WT = app_wallet_table();
$TT = app_tx_table();

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$tabs = array('profile' => 'Profile & account', 'safety' => 'Safety', 'wallet' => 'Wallet', 'purchases' => 'Purchases', 'data' => 'All data');
$tab = isset($_GET['tab'], $tabs[$_GET['tab']]) ? (string) $_GET['tab'] : 'profile';

/** Load the profile row of a player. */
function load_profile($PT, $IDC, $uid) {
    list($rows, , $r) = app_select($PT, array($IDC => 'eq.' . $uid), 1, 0, false);
    return array($rows ? $rows[0] : null, $r);
}
function wallet_of($WT, $uid) {
    if (!$WT) { return null; }
    $oc = app_owner_col($WT);
    if (!$oc) { return null; }
    list($rows, , ) = app_select($WT, array($oc => 'eq.' . $uid), 1, 0, false);
    return $rows ? $rows[0] : null;
}

/* ================= actions ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $back = '/admin/app/users/?id=' . rawurlencode($uid) . '&tab=' . rawurlencode(isset($_POST['tab'], $tabs[$_POST['tab']]) ? $_POST['tab'] : 'profile');
    if (!uuid_ok($uid)) { flash_set('err', 'Bad player id.'); redirect('/admin/app/users/'); }
    $fail = function ($r, $what) { flash_set('err', $what . ' failed: ' . $r['error']); };

    if ($act === 'save_profile') {
        list($row, ) = load_profile($PT, $IDC, $uid);
        if (!$row) { flash_set('err', 'Profile not found.'); redirect($back); }
        list($patch, $errs) = app_patch_from_post($PT, $row);
        unset($patch[$IDC]);
        if ($errs) { flash_set('err', 'Invalid value in: ' . implode(', ', $errs) . '.'); redirect($back); }
        if (!$patch) { flash_set('ok', 'Nothing changed.'); redirect($back); }
        $r = app_db('PATCH', $PT, array($IDC => 'eq.' . $uid), $patch, 'return=minimal');
        if ($r['ok']) { app_log('profile_update', $uid, $patch); flash_set('ok', 'Profile saved (' . implode(', ', array_keys($patch)) . ').'); } else { $fail($r, 'Save'); }
    } elseif ($act === 'email') {
        $email = app_post('email', 200);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash_set('err', 'Enter a valid email.'); redirect($back); }
        $r = app_auth_update($uid, array('email' => $email, 'email_confirm' => true));
        if ($r['ok']) {
            if (isset($PC['email'])) { app_db('PATCH', $PT, array($IDC => 'eq.' . $uid), array('email' => $email), 'return=minimal'); }
            app_log('email_change', $uid, $email); flash_set('ok', 'Email changed to ' . $email . ' (confirmed).');
        } else { $fail($r, 'Email change'); }
    } elseif ($act === 'password') {
        $pw = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if (strlen($pw) < 6) { flash_set('err', 'Password must be at least 6 characters.'); redirect($back); }
        $r = app_auth_update($uid, array('password' => $pw));
        if ($r['ok']) { app_log('password_change', $uid); flash_set('ok', 'Password changed. Tell the player the new password.'); } else { $fail($r, 'Password change'); }
    } elseif ($act === 'confirm_email') {
        $r = app_auth_update($uid, array('email_confirm' => true));
        if ($r['ok']) { app_log('email_confirm', $uid); flash_set('ok', 'Email marked as verified.'); } else { $fail($r, 'Confirm'); }
    } elseif ($act === 'suspend' || $act === 'reinstate') {
        $susp = $act === 'suspend';
        $reason = app_post('reason', 500);
        if ($susp && $reason === '') { flash_set('err', 'A reason is required.'); redirect($back); }
        $r = $susp ? app_rpc('admin_suspend_user', array('p_user_id' => $uid, 'p_reason' => $reason, 'p_report_id' => null, 'p_admin_notes' => null))
                   : app_rpc('admin_reinstate_user', array('p_user_id' => $uid, 'p_notes' => null));
        if (!$r['ok']) { $r = app_set_status($uid, $susp, $reason); }
        elseif ($susp && !empty($_POST['auth_ban'])) { app_auth_update($uid, array('ban_duration' => '876000h')); }
        elseif (!$susp) { app_auth_update($uid, array('ban_duration' => 'none')); }
        if ($r['ok']) { app_log($act, $uid, $reason); flash_set('ok', $susp ? 'Player suspended.' : 'Player reinstated.'); } else { $fail($r, $susp ? 'Suspend' : 'Reinstate'); }
    } elseif ($act === 'wallet') {
        $amount = (int) (isset($_POST['amount']) ? $_POST['amount'] : 0);
        $dir = (isset($_POST['direction']) && $_POST['direction'] === 'debit') ? 'debit' : 'credit';
        $note = app_post('note', 500); $ref = app_post('reference', 200);
        if ($amount <= 0 || $note === '') { flash_set('err', 'Amount (> 0) and note are required.'); redirect($back); }
        $done = false; $msg = '';
        if (app_has_rpc('admin_wallet_adjust')) {   // preferred: the game's own function (keeps the ledger + notification)
            $r = app_rpc('admin_wallet_adjust', array('p_user_id' => $uid, 'p_amount' => $amount, 'p_direction' => $dir, 'p_note' => $note, 'p_reference' => $ref !== '' ? $ref : null));
            if ($r['ok'] && !empty($r['data']['duplicate'])) {
                flash_set('err', 'Duplicate: reference ' . $ref . ' was already credited earlier. Nothing changed (balance ' . app_num(g($r['data'], 'balance_minor')) . ').');
                redirect($back);
            }
            if ($r['ok']) { $done = true; $msg = 'New balance: ' . app_num(g($r['data'], 'balance_minor')); }
        }
        if (!$done && $ref !== '' && $TT) {   // direct path: the same reference / order id can never be applied twice
            $tc0 = app_cols($TT); $ors = array();
            if (isset($tc0['ref_id'])) { $ors[] = 'ref_id.eq.' . pg_safe($ref); }
            if (isset($tc0['idempotency_key'])) { $ors[] = 'idempotency_key.eq.admin:' . pg_safe($ref); }
            if ($ors && app_count($TT, array('or' => '(' . implode(',', $ors) . ')'))) {
                flash_set('err', 'Duplicate: reference ' . $ref . ' was already credited earlier. Nothing changed.');
                redirect($back);
            }
        }
        if (!$done) {   // fallback: write the wallet + transaction rows directly
            $w = wallet_of($WT, $uid);
            $oc = $WT ? app_owner_col($WT) : null;
            $bal = (int) g($w, 'balance_minor', 0);
            $new = $dir === 'credit' ? $bal + $amount : $bal - $amount;
            if (!$WT || !$oc) { flash_set('err', 'No wallet table found.'); redirect($back); }
            if ($new < 0) { flash_set('err', 'Not enough coins (balance ' . number_format($bal) . ').'); redirect($back); }
            $r = $w ? app_db('PATCH', $WT, array($oc => 'eq.' . $uid), array('balance_minor' => $new) + (array_key_exists('updated_at', app_cols($WT)) ? array('updated_at' => date('c')) : array()), 'return=minimal')
                    : app_db('POST', $WT, array(), array($oc => $uid, 'balance_minor' => $new), 'return=minimal');
            if (!$r['ok']) { $fail($r, 'Wallet update'); redirect($back); }
            if ($TT && ($toc = app_owner_col($TT))) {
                $tc = app_cols($TT);
                $tx = array($toc => $uid, 'amount_minor' => $amount, 'direction' => $dir);
                foreach (array('reason' => 'admin_' . $dir, 'balance_after' => $new, 'ref_type' => 'admin', 'ref_id' => $ref !== '' ? $ref : null, 'note' => $note, 'idempotency_key' => 'admin:' . ($ref !== '' ? $ref : bin2hex(random_bytes(8)))) as $k => $v) {
                    if (isset($tc[$k])) { $tx[$k] = $v; }
                }
                if (isset($tc['wallet_id']) && $w && isset($w['id'])) { $tx['wallet_id'] = $w['id']; }
                $tr = app_db('POST', $TT, array(), $tx, 'return=minimal');
                if (!$tr['ok']) { $msg = ' (balance changed, but the history row was not saved: ' . $tr['error'] . ')'; }
            }
            $msg = 'New balance: ' . number_format($new) . $msg;
        }
        app_log('wallet_' . $dir, $uid, array('amount' => $amount, 'note' => $note, 'reference' => $ref));
        flash_set('ok', ($dir === 'credit' ? 'Credited ' : 'Debited ') . number_format($amount) . ' coins. ' . $msg);
    } elseif ($act === 'notify') {
        $t = app_post('title', 80); $b = app_post('body', 500);
        if ($t === '' || $b === '') { flash_set('err', 'Title and message are required.'); redirect($back); }
        $n = app_notify($uid, $t, $b);
        if ($n['ok']) { app_log('notify', $uid, $t); flash_set('ok', 'Notification sent' . ($n['via'] === 'game' ? ' (push + in-app).' : ' (in-app).')); } else { flash_set('err', 'Notification failed: ' . $n['error']); }
    } elseif ($act === 'clear_status') {
        $r = app_rpc('admin_clear_status_text', array('p_user_id' => $uid, 'p_reason' => app_post('reason', 300) !== '' ? app_post('reason', 300) : null));
        if (!$r['ok'] && array_key_exists('status_text', $PC)) { $r = app_db('PATCH', $PT, array($IDC => 'eq.' . $uid), array('status_text' => null), 'return=minimal'); }
        if ($r['ok']) { app_log('clear_status_text', $uid, app_post('reason', 300)); flash_set('ok', 'Status line removed.'); } else { flash_set('err', 'Remove status failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'remove_block') {
        $blocker = isset($_POST['blocker']) ? (string) $_POST['blocker'] : ''; $blocked = isset($_POST['blocked']) ? (string) $_POST['blocked'] : '';
        if (!uuid_ok($blocker) || !uuid_ok($blocked)) { flash_set('err', 'Bad players.'); redirect($back); }
        $r = app_rpc('admin_remove_block', array('p_blocker_id' => $blocker, 'p_blocked_id' => $blocked));
        if ($r['ok']) {
            app_log('remove_block', $blocker, array('blocked' => $blocked));
            flash_set('ok', !empty($r['data']['still_restricted']) ? 'Removed, but a report still keeps them apart.' : 'Block removed (' . (int) g($r['data'], 'removed', 0) . ').');
        } else { flash_set('err', 'Remove block failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'cancel_challenge') {
        $cid = isset($_POST['challenge_id']) ? (string) $_POST['challenge_id'] : '';
        $r = uuid_ok($cid) ? app_rpc('admin_cancel_challenge', array('p_challenge_id' => $cid)) : array('ok' => false, 'error' => 'Bad challenge id.');
        if ($r['ok']) { app_log('cancel_challenge', $uid, $cid); flash_set('ok', 'Challenge cancelled.'); } else { flash_set('err', 'Cancel failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'delete_account') {
        if (strtoupper(trim(isset($_POST['confirm_word']) ? (string) $_POST['confirm_word'] : '')) !== 'DELETE') { flash_set('err', 'Type DELETE to confirm.'); redirect($back); }
        $r = app_auth_delete($uid);
        if ($r['ok']) { app_log('account_delete', $uid); flash_set('ok', 'Account deleted.'); redirect('/admin/app/users/'); }
        $fail($r, 'Delete');
    }
    redirect($back);
}

function hid($uid, $tab, $act) { return csrf_field() . '<input type="hidden" name="user_id" value="' . e($uid) . '"><input type="hidden" name="tab" value="' . e($tab) . '"><input type="hidden" name="action" value="' . e($act) . '">'; }

/* ================= list ================= */
if ($id === '') {
    $q = app_get('q', 100);
    $st = app_get('status', 30);
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 25;
    $query = array();
    if (isset($PC['created_at'])) { $query['order'] = 'created_at.desc'; }
    if ($st !== '' && isset($PC['account_status']) && app_valid_ident($st)) { $query['account_status'] = 'eq.' . $st; }
    $emailHits = null;
    if ($q !== '') {
        if (uuid_ok($q)) { $query[$IDC] = 'eq.' . $q; }
        elseif (ctype_digit(ltrim($q, '#')) && isset($PC['player_no'])) { $query['player_no'] = 'eq.' . (int) ltrim($q, '#'); }
        elseif (strpos($q, '@') !== false) {
            $emailHits = app_auth_search_email($q);
            $ids = array(); foreach ($emailHits as $u) { $ids[] = $u['id']; }
            $query[$IDC] = 'in.(' . ($ids ? implode(',', $ids) : '00000000-0000-0000-0000-000000000000') . ')';
        } else {
            $s = pg_safe($q); $ors = array();
            foreach (array('username', 'display_name', 'full_name', 'email', 'referral_code', 'phone_e164') as $c) { if (isset($PC[$c])) { $ors[] = $c . '.ilike.*' . $s . '*'; } }
            if ($ors) { $query['or'] = '(' . implode(',', $ors) . ')'; }
        }
    }
    list($rows, $total, $r) = app_select($PT, $query, $per, ($page - 1) * $per);
    $ids = array(); foreach ($rows as $p) { if (isset($p[$IDC])) { $ids[] = $p[$IDC]; } }
    $auth = app_auth_many($ids);
    $online = app_online(500);   // fetched once; green dot for players in this list only
    $bal = array();
    if ($WT && $ids && ($oc = app_owner_col($WT))) {
        list($ws, , ) = app_select($WT, array($oc => 'in.(' . implode(',', $ids) . ')'), 100, 0, false);
        foreach ($ws as $w) { $bal[$w[$oc]] = g($w, 'balance_minor'); }
    }

    app_head('Mobile App players', 'users');
    echo '<form class="row" method="get"><input name="q" value="' . e($q) . '" placeholder="Username, email, player no or user id" style="min-width:280px">';
    if (isset($PC['account_status'])) {
        echo '<select name="status"><option value="">All statuses</option>';
        foreach (array('active', 'suspended', 'under_review') as $s) { echo '<option value="' . $s . '"' . ($st === $s ? ' selected' : '') . '>' . e(str_replace('_', ' ', $s)) . '</option>'; }
        echo '</select>';
    }
    echo '<button class="btn sm" type="submit">Search</button>' . ($q !== '' || $st !== '' ? '<a class="btn ghost sm" href="/admin/app/users/">Clear</a>' : '');
    if ($total !== null) { echo '<span class="muted">' . number_format($total) . ' players</span>'; }
    if ($online['ok']) { echo '<a class="muted" href="/admin/app/online/"><i class="dot on"></i> ' . app_num($online['count']) . ' online now</a>'; }
    echo '</form>' . app_err_box($r);
    echo '<div class="tbl-wrap"><table><tr><th>Player</th><th>No.</th><th>Email</th><th>Status</th><th>Verified</th><th>Coins</th><th>Joined</th><th>Last sign-in</th></tr>';
    foreach ($rows as $p) {
        $uid = (string) g($p, $IDC, '');
        $a = isset($auth[$uid]) ? $auth[$uid] : null;
        $href = '/admin/app/users/?id=' . rawurlencode($uid);
        $on = isset($online['ids'][$uid]) ? $online['ids'][$uid] : null;
        echo '<tr class="click" data-href="' . e($href) . '"><td><div class="ucell"><span class="avw">' . app_avatar(g($p, 'avatar_url'), g($p, 'player_no')) . ($on ? '<i class="dot on" title="' . (!empty($on['in_match']) ? 'In a match' : 'Online') . '"></i>' : '') . '</span><div><a href="' . e($href) . '"><b>' . e(gx($p, array('username', 'display_name'), '(no name)')) . '</b></a>'
            . (g($p, 'is_admin') ? ' <span class="badge b-pink">admin</span>' : '') . '</div></div></td>';
        echo '<td class="pno">#' . e(app_pno(g($p, 'player_no'))) . '</td><td>' . e(gx($a, array('email'), gx($p, array('email'), '-'))) . '</td><td>' . app_badge(g($p, 'account_status')) . (g($a, 'banned_until') ? ' <span class="badge b-red">login blocked</span>' : '') . '</td>';
        echo '<td>' . (g($a, 'email_confirmed_at') || g($p, 'email_verified_at') ? '<span class="pos">&#10003;</span>' : '<span class="muted">-</span>') . '</td><td>' . app_num(isset($bal[$uid]) ? $bal[$uid] : null) . '</td>';
        echo '<td class="nowrap">' . app_dt(gx($p, array('created_at'), g($a, 'created_at'))) . '</td><td class="nowrap">' . app_dt(g($a, 'last_sign_in_at')) . '</td></tr>';
    }
    if (!$rows) { echo '<tr><td colspan="8" class="muted">' . ($r['ok'] ? 'No players found.' : 'Could not load players.') . '</td></tr>'; }
    echo '</table></div>' . ($total !== null ? pager($page, $total, $per) : app_pager_simple($page, count($rows), $per));
    admin_foot(); exit;
}

/* ================= player page ================= */
if (!uuid_ok($id)) { flash_set('err', 'Bad player id.'); redirect('/admin/app/users/'); }
list($p, $pr) = load_profile($PT, $IDC, $id);
$ar = app_auth_get($id);
$a = $ar['ok'] ? $ar['data'] : null;
$w = wallet_of($WT, $id);
$balance = g($w, 'balance_minor');
$name = (string) gx($p, array('username', 'display_name'), gx($a, array('email'), '(no name)'));
$soc = app_rpc('admin_user_social', array('p_user_id' => $id));
$S = $soc['ok'] ? $soc['data'] : array();
$statusText = (string) gx($S, array('status_text'), g($p, 'status_text', ''));
$pres = g($S, 'presence');
if (!empty($pres['in_match'])) { $presHtml = '<span class="badge b-pink"><i class="dot on"></i> In a match</span>'; }
elseif (!empty($pres['online'])) { $presHtml = '<span class="badge b-teal"><i class="dot on"></i> Online</span>'; }
elseif (g($pres, 'last_seen_at')) { $presHtml = '<span class="badge b-grey">Last seen ' . e(app_ago($pres['last_seen_at'])) . '</span>'; }
else { $presHtml = ''; }

app_head('Player', 'users');
echo '<div class="row"><a class="btn ghost sm" href="/admin/app/users/">&larr; All players</a></div>';
if (!$p && !$a) { echo '<div class="flash err">Player not found. ' . e($pr['error'] . ' ' . $ar['error']) . '</div>'; admin_foot(); exit; }
if (!$p) { echo '<div class="flash err">This account has no game profile yet (only a login account).</div>'; }

/* ---------- header ---------- */
$providers = (array) g($a, 'app_metadata.providers', array());
echo '<div class="panel"><div class="uhead">' . app_avatar(g($p, 'avatar_url'), g($p, 'player_no'), 84);
echo '<div class="who"><h2>' . e($name) . ' <span class="pno">#' . e(app_pno(g($p, 'player_no'))) . '</span> ' . app_badge(g($p, 'account_status')) . (g($p, 'is_admin') ? ' <span class="badge b-pink">app admin</span>' : '') . (g($a, 'banned_until') ? ' <span class="badge b-red">login blocked</span>' : '') . '</h2>';
if (g($p, 'suspension_reason')) { echo '<div class="muted">Suspended ' . app_dt(g($p, 'suspended_at')) . ': ' . e(g($p, 'suspension_reason')) . '</div>'; }
if ($presHtml !== '') { echo '<div style="margin-bottom:6px">' . $presHtml . '</div>'; }
if ($statusText !== '') { echo '<div class="stline">&ldquo;' . e($statusText) . '&rdquo;</div>'; }
echo '<div class="muted" style="font-size:12px">User id: ' . e($id) . '</div></div><div class="meta">';
echo '<div><span>Email:</span> ' . e(g($a, 'email', '-')) . ' ' . (g($a, 'email_confirmed_at') ? '<span class="badge b-teal">verified</span>' : '<span class="badge b-grey">not verified</span>') . '</div>';
echo '<div><span>Phone:</span> ' . e(gx($p, array('phone_e164'), g($a, 'phone', '-'))) . '</div>';
echo '<div><span>Joined:</span> ' . app_dt(gx($p, array('created_at'), g($a, 'created_at'))) . '</div>';
echo '<div><span>Last sign-in:</span> ' . app_dt(g($a, 'last_sign_in_at')) . '</div>';
echo '<div><span>Sign-in with:</span> ' . ($providers ? e(implode(', ', $providers)) : '<span class="muted">-</span>') . '</div>';
echo '<div><span>Country:</span> ' . e(g($p, 'country_iso2', '-')) . '</div>';
echo '<div><span>Coins:</span> <b>' . app_num($balance) . '</b></div>';
echo '<div><span>Login blocked until:</span> ' . (g($a, 'banned_until') ? app_dt(g($a, 'banned_until')) : '<span class="muted">-</span>') . '</div>';
echo '</div></div>';

/* ---------- quick actions ---------- */
$isSusp = g($p, 'account_status') === 'suspended' || g($a, 'banned_until');
echo '<div class="actions">';
if (!$isSusp) {
    echo '<details class="act"><summary class="btn danger sm">Suspend</summary><form class="pop" method="post" data-confirm="Suspend ' . e($name) . '?">' . hid($id, $tab, 'suspend')
        . '<label>Reason<input name="reason" required maxlength="500"></label><label class="chk"><input type="checkbox" name="auth_ban" value="1" checked> Also block login</label><button class="btn danger sm" type="submit">Suspend player</button></form></details>';
} else {
    echo '<form method="post" data-confirm="Reinstate ' . e($name) . ' and unblock login?">' . hid($id, $tab, 'reinstate') . '<button class="btn sm" type="submit">Reinstate</button></form>';
}
if ($statusText !== '') {
    echo '<details class="act"><summary class="btn ghost sm">Remove status</summary><form class="pop" method="post" data-confirm="Remove the status line of ' . e($name) . '?">' . hid($id, $tab, 'clear_status')
        . '<div class="muted">&ldquo;' . e($statusText) . '&rdquo;</div><label>Reason (optional)<input name="reason" maxlength="300"></label><button class="btn sm" type="submit">Remove status</button></form></details>';
}
$pre = isset($_GET['credit_ref']) ? array('ref' => clean_str($_GET['credit_ref'], 200), 'amount' => (int) (isset($_GET['credit_amount']) ? $_GET['credit_amount'] : 0), 'note' => 'Play purchase not credited') : null;
echo '<details class="act" id="adjustCoins"' . ($pre ? ' open' : '') . '><summary class="btn ghost sm">Adjust coins</summary><form class="pop" method="post" data-wallet data-balance="' . e((int) $balance) . '" data-user="' . e($name) . '">' . hid($id, $tab, 'wallet')
    . '<div class="muted">Current balance: <b>' . app_num($balance) . '</b></div>'
    . ($pre ? '<div class="warn" style="margin:0;font-size:13px">Use only after checking the order in Play Console &rarr; Order management. The order id is the reference, so it can never be credited twice.</div>' : '')
    . '<label>Direction<select name="direction"><option value="credit">Credit (add)</option>' . ($pre ? '' : '<option value="debit">Debit (remove)</option>') . '</select></label>'
    . '<label>Amount<input name="amount" type="number" min="1" step="1" required value="' . ($pre && $pre['amount'] > 0 ? e($pre['amount']) : '') . '"></label><label>Note (required)<input name="note" required maxlength="500" value="' . ($pre ? e($pre['note']) : '') . '"></label>'
    . '<label>Reference (' . ($pre ? 'Google order id' : 'optional') . ')<input name="reference" maxlength="200" value="' . ($pre ? e($pre['ref']) : '') . '"' . ($pre ? ($pre['ref'] !== '' ? ' readonly' : ' required placeholder="GPA.1234-5678-..."') : '') . '></label><button class="btn sm" type="submit">Apply</button></form></details>';
echo '<details class="act"><summary class="btn ghost sm">Change email</summary><form class="pop" method="post" data-confirm="Change the login email of ' . e($name) . '?">' . hid($id, $tab, 'email')
    . '<label>New email<input name="email" type="email" required value="' . e(g($a, 'email', '')) . '"></label><button class="btn sm" type="submit">Save email</button></form></details>';
echo '<details class="act"><summary class="btn ghost sm">Change password</summary><form class="pop" method="post" data-confirm="Set a new password for ' . e($name) . '?">' . hid($id, $tab, 'password')
    . '<label>New password (min 6)<input name="password" type="text" required minlength="6" autocomplete="off"></label><button class="btn sm" type="submit">Save password</button></form></details>';
if ($a && !g($a, 'email_confirmed_at')) { echo '<form method="post" data-confirm="Mark the email as verified?">' . hid($id, $tab, 'confirm_email') . '<button class="btn ghost sm" type="submit">Verify email</button></form>'; }
echo '<details class="act"><summary class="btn ghost sm">Send notification</summary><form class="pop" method="post" data-confirm="Send this notification?">' . hid($id, $tab, 'notify')
    . '<label>Title<input name="title" required maxlength="80"></label><label>Message<textarea name="body" required maxlength="500"></textarea></label><button class="btn sm" type="submit">Send</button></form></details>';
echo '<details class="act"><summary class="btn danger sm">Delete account</summary><form class="pop" method="post" data-confirm="Permanently delete this account? This cannot be undone.">' . hid($id, $tab, 'delete_account')
    . '<div class="warn" style="margin:0">Deletes the login account. Game data linked to it may be deleted too.</div><label>Type DELETE<input name="confirm_word" required autocomplete="off"></label><button class="btn danger sm" type="submit">Delete permanently</button></form></details>';
echo '</div></div>';

/* ---------- tabs ---------- */
echo '<nav class="tabs">';
foreach ($tabs as $k => $v) { echo '<a href="/admin/app/users/?id=' . e($id) . '&amp;tab=' . $k . '"' . ($k === $tab ? ' class="on"' : '') . '>' . e($v) . '</a>'; }
echo '</nav>';

if ($tab === 'profile') {
    if ($p) {
        echo '<h2>Game profile <span class="muted" style="font-size:13px">(table drotix.' . e($PT) . ': edit any field)</span></h2>';
        echo '<form method="post" class="panel" data-confirm="Save the changes to this profile?">' . hid($id, 'profile', 'save_profile') . '<div class="fgrid">';
        foreach ($PC as $c => $f) {
            if ($c === $IDC) { continue; }
            echo '<label>' . e($c) . ' <span class="muted" style="font-weight:400">' . e($f) . '</span>' . app_input($c, $f, array_key_exists($c, $p) ? $p[$c] : null) . '</label>';
        }
        echo '</div><button class="btn" type="submit">Save profile</button></form>';
    }
    if ($a) {
        echo '<h2>Login account (Supabase Auth)</h2><div class="panel"><dl class="kv">';
        foreach (array('email', 'phone', 'created_at', 'last_sign_in_at', 'email_confirmed_at', 'banned_until', 'role') as $k) { echo '<dt>' . e($k) . '</dt><dd>' . app_cell($k, g($a, $k)) . '</dd>'; }
        echo '<dt>providers</dt><dd>' . e(implode(', ', $providers)) . '</dd><dt>user metadata</dt><dd>' . app_cell('m', g($a, 'user_metadata')) . '</dd></dl></div>';
    }
}
elseif ($tab === 'purchases') {
    $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
    $pr = app_list_iap_purchases($id, $per, ($page - 1) * $per);
    $items = $pr['ok'] ? (array) g($pr['data'], 'items', array()) : array();
    $titles = array(); $pl = app_list_coin_packages(); foreach ((array) g($pl['data'], 'items', array()) as $k) { $titles[(string) g($k, 'product_id')] = (string) g($k, 'title'); }
    $sm = app_purchase_summary($items);
    echo '<div class="cards sm"><div class="card"><div class="n teal">' . app_num($sm['credited']) . '</div><div class="l">Credited</div></div><div class="card"><div class="n' . ($sm['rejected'] ? ' neg' : '') . '">' . app_num($sm['rejected']) . '</div><div class="l">Rejected</div></div>'
        . '<div class="card"><div class="n gold">' . app_num($sm['coins']) . '</div><div class="l">Coins from purchases</div></div><div class="card"><div class="n">' . e(app_inr($sm['inr'])) . '</div><div class="l">Paid (credited)</div></div></div>';
    echo '<p class="muted">Coins are added by the server only after Google confirms the purchase. If a player paid but got no coins, check the order in <b>Play Console &rarr; Order management</b>, then use <b>Credit missing coins</b> (the order id is used as the reference, so it can never be credited twice).</p>';
    if (!$pr['ok']) { echo '<div class="flash err">Could not load purchases: ' . e(app_rpc_error($pr)) . '</div>'; }
    echo '<div class="tbl-wrap"><table><tr><th>Date</th><th>Product</th><th>Coins</th><th>Price</th><th>Google order id</th><th>Status</th><th>Verified</th><th></th></tr>';
    foreach ($items as $x) {
        $pid = (string) g($x, 'product_id', ''); $oid = (string) g($x, 'order_id', ''); $st = (string) g($x, 'status', '');
        echo '<tr><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td><code class="j">' . e($pid) . '</code>' . (isset($titles[$pid]) ? '<div class="muted" style="font-size:12px">' . e($titles[$pid]) . '</div>' : '') . '</td>';
        echo '<td>' . app_num(g($x, 'coins')) . '</td><td class="nowrap">' . (g($x, 'price_inr') !== null ? e(app_inr($x['price_inr'])) : '-') . '</td>';
        echo '<td class="nowrap">' . ($oid !== '' ? '<code class="j">' . e($oid) . '</code> <button type="button" class="btn ghost sm" data-copy="' . e($oid) . '">Copy</button>' : '<span class="muted">-</span>') . '</td>';
        echo '<td>' . app_purchase_badge($st) . '</td><td>' . (g($x, 'verified') ? '<span class="pos">Verified by Google &#10003;</span>' : '<span class="neg">&#10007;</span>') . '</td><td>';
        if ($oid !== '' && $st !== 'credited') {
            echo '<a class="btn ghost sm" href="/admin/app/users/?id=' . e(rawurlencode($id)) . '&amp;tab=purchases&amp;credit_ref=' . e(rawurlencode($oid)) . '&amp;credit_amount=' . (int) g($x, 'coins', 0) . '#adjustCoins">Credit missing coins</a>';
        }
        echo '</td></tr>';
    }
    if (!$items) { echo '<tr><td colspan="8" class="muted">' . ($pr['ok'] ? 'No purchases.' : 'Could not load.') . '</td></tr>'; }
    echo '</table></div>' . pager($page, $pr['ok'] ? (int) g($pr['data'], 'total', count($items)) : 0, $per);
    echo '<p class="muted">Missing order not in this list? Open <a href="/admin/app/users/?id=' . e(rawurlencode($id)) . '&amp;tab=purchases&amp;credit_ref=&amp;credit_amount=0#adjustCoins">Credit missing coins</a> and type the order id (GPA.…) as the reference.</p>';
    echo '<script src="/admin/assets/app-coupons.js?v=1" defer></script>';   // copy buttons
}
elseif ($tab === 'wallet') {
    echo '<div class="cards sm"><div class="card"><div class="n gold">' . app_num($balance) . '</div><div class="l">Balance (coins)</div>' . ($w && g($w, 'updated_at') ? '<div class="s">Updated ' . app_dt(g($w, 'updated_at')) . '</div>' : '') . '</div></div>';
    if ($TT && ($toc = app_owner_col($TT))) {
        $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 50;
        $q = array($toc => 'eq.' . $id);
        if (array_key_exists('created_at', app_cols($TT))) { $q['order'] = 'created_at.desc'; }
        $reason = app_get('reason', 40);
        if ($reason !== '' && app_valid_ident($reason) && array_key_exists('reason', app_cols($TT))) { $q['reason'] = 'eq.' . $reason; }
        if (isset($_GET['export'])) {
            $all = array(); for ($o = 0; $o < 20000; $o += 1000) { list($ch, , ) = app_select($TT, $q, 1000, $o, false); $all = array_merge($all, $ch); if (count($ch) < 1000) { break; } }
            csv_out('drotix_transactions_' . substr($id, 0, 8) . '.csv', $all);
        }
        list($rows, $total, $r) = app_select($TT, $q, $per, ($page - 1) * $per);
        echo '<form class="row" method="get"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="tab" value="wallet"><input name="reason" value="' . e($reason) . '" placeholder="Filter by reason (e.g. iap_grant)"><button class="btn sm" type="submit">Filter</button><a class="btn ghost sm" href="' . e(qs(array('export' => 1, 'page' => null))) . '">Export CSV</a><span class="muted">' . app_num($total) . ' transactions</span></form>';
        echo app_err_box($r) . app_rows_table($rows, $TT, 'No transactions.') . ($total !== null ? pager($page, $total, $per) : '');
    } else { echo '<p class="muted">No transactions table found.</p>'; }
}
elseif ($tab === 'safety') {
    if (!$soc['ok']) { echo '<div class="flash err">Could not load safety data: ' . e(app_rpc_error($soc)) . '</div>'; }
    $blockTable = function ($rows, $title, $mine) use ($id, $tab) {
        echo '<h2>' . e($title) . ' (' . count($rows) . ')</h2><div class="tbl-wrap"><table><tr><th>Player</th><th>Source</th><th>Since</th><th></th></tr>';
        foreach ($rows as $b) {
            $other = (string) g($b, 'user_id', '');
            $src = (string) g($b, 'source', '');
            echo '<tr><td>' . app_user_link($other, g($b, 'username')) . '</td><td>' . ($src === 'report' ? '<span class="badge b-red">From report</span>' : '<span class="badge b-grey">Manual</span>') . '</td><td class="nowrap">' . app_dt(g($b, 'created_at')) . '</td><td>';
            if (uuid_ok($other)) {
                echo '<form method="post" data-confirm="Remove this block?' . ($src === 'report' ? ' A report may still keep them apart.' : '') . '">' . hid($id, $tab, 'remove_block')
                    . '<input type="hidden" name="blocker" value="' . e($mine ? $id : $other) . '"><input type="hidden" name="blocked" value="' . e($mine ? $other : $id) . '"><button class="btn ghost sm" type="submit">Remove block</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="4" class="muted">None.</td></tr>'; }
        echo '</table></div>';
    };
    $blockTable((array) g($S, 'blocks_made', array()), 'Blocked by this player', true);
    $blockTable((array) g($S, 'blocked_by', array()), 'Blocked this player', false);
    echo '<div class="cards sm" style="margin-top:18px"><div class="card"><div class="n">' . app_num(g($S, 'report_pairs', 0)) . '</div><div class="l">Kept apart by reports</div><div class="s">These players are never matched and cannot challenge each other.</div></div></div>';
    $ch = g($S, 'open_challenge');
    echo '<h2>Open challenge</h2>';
    if ($ch) {
        echo '<div class="panel">Challenging ' . app_user_link(g($ch, 'target_id'), g($ch, 'target_username')) . ' &middot; <b>' . (int) g($ch, 'seconds_left', 0) . ' s</b> left'
            . '<form method="post" style="margin-top:12px" data-confirm="Cancel this challenge?">' . hid($id, $tab, 'cancel_challenge') . '<input type="hidden" name="challenge_id" value="' . e(g($ch, 'challenge_id', '')) . '"><button class="btn danger sm" type="submit">Cancel challenge</button></form></div>';
    } else { echo '<p class="muted">No pending challenge.</p>'; }
    // suspensions history with ticket ids (if the table exists)
    foreach (app_tables() as $t => $info) {
        if (!preg_match('/suspension/', $t)) { continue; }
        $oc = app_owner_col($t); if (!$oc) { continue; }
        $q = array($oc => 'eq.' . $id); if (isset($info['cols']['created_at'])) { $q['order'] = 'created_at.desc'; }
        list($rows, , ) = app_select($t, $q, 50, 0, false);
        echo '<h2>Suspensions (' . count($rows) . ')</h2><div class="tbl-wrap"><table><tr><th>Ticket</th><th>When</th><th>Reason</th><th>Lifted</th></tr>';
        foreach ($rows as $x) { echo '<tr><td class="ref">' . e(app_ref('S', g($x, 'id'))) . '</td><td class="nowrap">' . app_dt(g($x, 'created_at')) . '</td><td>' . app_cell('r', g($x, 'reason')) . '</td><td>' . app_cell('lifted_at', gx($x, array('lifted_at', 'ended_at', 'reinstated_at'))) . '</td></tr>'; }
        if (!$rows) { echo '<tr><td colspan="4" class="muted">None.</td></tr>'; }
        echo '</table></div>';
        break;
    }
}
elseif ($tab === 'data') {
    echo '<p class="muted">Every app table that has rows linked to this player (latest 10 each). Click a table name to see all rows, edit or delete.</p>';
    foreach (app_tables() as $t => $info) {
        if ($t === $PT) { continue; }
        $ucs = app_user_cols($t);
        if (!$ucs) { continue; }
        $ors = array(); foreach ($ucs as $c) { $ors[] = $c . '.eq.' . $id; }
        $q = array('or' => '(' . implode(',', $ors) . ')');
        if (isset($info['cols']['created_at'])) { $q['order'] = 'created_at.desc'; }
        list($rows, $total, $r) = app_select($t, $q, 10, 0);
        if (!$r['ok'] || !$total) { continue; }
        echo '<h2><a href="/admin/app/data/?t=' . e(rawurlencode($t)) . '&amp;user=' . e($id) . '">' . e($t) . '</a> <span class="muted" style="font-size:13px">' . app_num($total) . ' rows</span></h2>' . app_rows_table($rows, $t);
    }
}
admin_foot();
