<?php
/* Mobile App: tournaments. List, create, start now (bracket), and full history of one tournament:
   who joined, every match (who played whom, when, who won), who went out in which round and when, champion. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

/* ---------- tables (discovered) ---------- */
$TT = app_has_table('tournaments') ? 'tournaments' : app_table_like('/^tournaments?$/');
$PART = null; $MATCH = null;
foreach (app_tables() as $t => $info) {
    if ($t === $TT || !isset($info['cols']['tournament_id'])) { continue; }
    if (!$PART && preg_match('/(participant|player|entr|registr|member|signup)/', $t)) { $PART = $t; }
    if (!$MATCH && preg_match('/(match|game|round|bracket|pairing)/', $t)) { $MATCH = $t; }
}
function col_of($t, $cands) { $c = app_cols($t); foreach ($cands as $x) { if (isset($c[$x])) { return $x; } } return null; }
function names_for($ids) {
    $ids = array_values(array_unique(array_filter($ids, 'uuid_ok')));
    if (!$ids) { return array(); }
    $pt = app_profiles_table(); $idc = app_profile_idcol(); $out = array();
    foreach (array_chunk($ids, 150) as $ch) {
        list($rows, , ) = app_select($pt, array($idc => 'in.(' . implode(',', $ch) . ')'), 200, 0, false);
        foreach ($rows as $r) { $out[$r[$idc]] = array('name' => gx($r, array('username', 'display_name'), substr($r[$idc], 0, 8)), 'no' => g($r, 'player_no'), 'av' => g($r, 'avatar_url')); }
    }
    return $out;
}
function who($names, $id) {
    if (!$id) { return '<span class="muted">-</span>'; }
    $n = isset($names[$id]) ? $names[$id] : array('name' => substr($id, 0, 8), 'no' => null);
    return app_user_link($id, $n['name']) . ($n['no'] !== null ? ' <span class="pno">#' . e(app_pno($n['no'])) . '</span>' : '');
}
function dur($a, $b) {
    $x = strtotime((string) $a); $y = strtotime((string) $b);
    if (!$x || !$y || $y < $x) { return '-'; }
    $s = $y - $x;
    return $s >= 3600 ? floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm' : ($s >= 60 ? floor($s / 60) . 'm ' . ($s % 60) . 's' : $s . 's');
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($act === 'create') {
        $name = app_post('name', 80);
        $fee = (int) (isset($_POST['entry_fee']) ? $_POST['entry_fee'] : 0);
        $max = (int) (isset($_POST['max_participants']) ? $_POST['max_participants'] : 0);
        $closes = strtotime((string) (isset($_POST['closes_at']) ? $_POST['closes_at'] : ''));
        $starts = strtotime((string) (isset($_POST['starts_at']) ? $_POST['starts_at'] : ''));
        $move = max(5, (int) (isset($_POST['move_timeout']) ? $_POST['move_timeout'] : 20));
        $join = max(10, (int) (isset($_POST['join_timeout']) ? $_POST['join_timeout'] : 60));
        $prize = (int) (isset($_POST['prize']) ? $_POST['prize'] : 0);
        $errs = array();
        if ($name === '') { $errs[] = 'name'; }
        if ($fee < 0) { $errs[] = 'entry fee'; }
        if ($max < 2 || $max > 256) { $errs[] = 'max players (2-256)'; }
        if (!$closes || !$starts) { $errs[] = 'dates'; } elseif ($closes > $starts) { $errs[] = 'registration must close before the start'; }
        if ($errs) { flash_set('err', 'Check: ' . implode(', ', $errs) . '.'); redirect('/admin/app/tournaments/?new=1'); }
        $r = app_rpc('admin_create_tournament', array('p_name' => $name, 'p_entry_fee' => $fee, 'p_max_participants' => $max,
            'p_registration_closes_at' => date('c', $closes), 'p_starts_at' => date('c', $starts), 'p_move_timeout_sec' => $move, 'p_join_timeout_sec' => $join));
        $newId = $r['ok'] ? gx($r['data'], array('tournament_id', 'id', 'tournament.id')) : null;
        if (!$r['ok'] && $TT) {   // fallback: insert the row directly
            $c = app_cols($TT); $row = array();
            $map = array('name' => $name, 'title' => $name, 'entry_fee_minor' => $fee, 'entry_fee' => $fee, 'max_participants' => $max, 'bracket_size' => null,
                         'registration_closes_at' => date('c', $closes), 'starts_at' => date('c', $starts), 'move_timeout_sec' => $move, 'join_timeout_sec' => $join);
            foreach ($map as $k => $v) { if (isset($c[$k]) && $v !== null) { $row[$k] = $v; } }
            if (isset($c['status'])) { $row['status'] = app_pick_value($TT, 'status', array('registration', 'open', 'scheduled', 'upcoming', 'pending')); }
            $row = app_fill_required($TT, $row);
            $r = app_db('POST', $TT, array(), $row, 'return=representation');
            $newId = $r['ok'] ? g($r['data'], '0.id') : null;
        }
        if ($r['ok'] && $prize > 0 && $newId && $TT) {
            $pc = col_of($TT, array('prize_pool_minor', 'prize_minor', 'prize_pool', 'prize'));
            if ($pc) { app_db('PATCH', $TT, array('id' => 'eq.' . $newId), array($pc => $prize), 'return=minimal'); }
        }
        if ($r['ok']) { app_log('tournament_create', (string) $newId, $name); flash_set('ok', 'Tournament "' . $name . '" created.'); redirect($newId ? '/admin/app/tournaments/?id=' . rawurlencode($newId) : '/admin/app/tournaments/'); }
        flash_set('err', 'Create failed: ' . $r['error']); redirect('/admin/app/tournaments/?new=1');
    }
    $tid = isset($_POST['tournament_id']) ? (string) $_POST['tournament_id'] : '';
    if (!uuid_ok($tid)) { flash_set('err', 'Bad tournament id.'); redirect('/admin/app/tournaments/'); }
    if ($act === 'start') {
        $r = app_rpc('admin_generate_bracket', array('p_tournament_id' => $tid));
        if ($r['ok']) { app_log('tournament_start', $tid); flash_set('ok', 'Bracket generated: the tournament has started.'); }
        else { flash_set('err', 'Start failed: ' . $r['error'] . (app_admin_token() ? '' : ' (no app admin account found: make one player an admin with is_admin = true)')); }
    } elseif ($act === 'status' && $TT) {
        $st = app_post('status', 40);
        $r = app_db('PATCH', $TT, array('id' => 'eq.' . $tid), array('status' => $st), 'return=minimal');
        if ($r['ok']) { app_log('tournament_status', $tid, $st); flash_set('ok', 'Status set to ' . $st . '.'); } else { flash_set('err', $r['error']); }
    } elseif ($act === 'notify' && $PART) {
        $t = app_post('title', 80); $b = app_post('body', 500);
        $uc = app_owner_col($PART);
        list($ps, , ) = app_select($PART, array('tournament_id' => 'eq.' . $tid, 'select' => $uc), 1000, 0, false);
        $ok = 0; $err = '';
        foreach ($ps as $p) { $n = app_notify($p[$uc], $t, $b); if ($n['ok']) { $ok++; } else { $err = $n['error']; } }
        app_log('tournament_notify', $tid, $t);
        flash_set($err === '' ? 'ok' : 'err', 'Notified ' . $ok . ' of ' . count($ps) . ' players.' . ($err ? ' Error: ' . $err : ''));
    }
    redirect('/admin/app/tournaments/?id=' . rawurlencode($tid));
}

if (!$TT) { app_head('Tournaments', 'tour'); echo '<div class="flash err">No tournaments table found in the drotix schema.</div>'; admin_foot(); exit; }
$TC = app_cols($TT);
$statusOpts = app_enum($TT, 'status');
$id = isset($_GET['id']) ? (string) $_GET['id'] : '';

/* ================= one tournament ================= */
if ($id !== '' && uuid_ok($id)) {
    list($rows, , $r) = app_select($TT, array('id' => 'eq.' . $id), 1, 0, false);
    app_head('Tournament', 'tour');
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/tournaments/">&larr; All tournaments</a><a class="btn ghost sm" href="/admin/app/data/?t=' . e(rawurlencode($TT)) . '&amp;edit=' . e($id) . '">Edit all fields</a></div>' . app_err_box($r);
    if (!$rows) { echo '<div class="flash err">Tournament not found.</div>'; admin_foot(); exit; }
    $T = $rows[0];
    $st = (string) g($T, 'status', '');

    // participants + matches
    $parts = array(); $matches = array();
    $puc = $PART ? app_owner_col($PART) : null;
    if ($PART) { list($parts, , ) = app_select($PART, array('tournament_id' => 'eq.' . $id) + (array_key_exists('seed', app_cols($PART)) ? array('order' => 'seed.asc') : array()), 1000, 0, false); }
    $mRound = $MATCH ? col_of($MATCH, array('round', 'round_no', 'round_number', 'round_index')) : null;
    $mSlot = $MATCH ? col_of($MATCH, array('match_no', 'match_number', 'slot', 'position', 'bracket_position', 'match_index')) : null;
    $mWin = $MATCH ? col_of($MATCH, array('winner_id', 'winner_user_id', 'winner_player_id', 'winner')) : null;
    $mStart = $MATCH ? col_of($MATCH, array('started_at', 'start_at', 'scheduled_at', 'created_at')) : null;
    $mEnd = $MATCH ? col_of($MATCH, array('finished_at', 'ended_at', 'completed_at', 'decided_at')) : null;
    $mPlayers = array();
    if ($MATCH) {
        foreach (app_user_cols($MATCH) as $c) { if ($c !== $mWin && !preg_match('/(winner|loser|created_by|admin)/', $c)) { $mPlayers[] = $c; } }
        $q = array('tournament_id' => 'eq.' . $id);
        $ord = array(); if ($mRound) { $ord[] = $mRound . '.asc'; } if ($mSlot) { $ord[] = $mSlot . '.asc'; }
        if ($ord) { $q['order'] = implode(',', $ord); }
        list($matches, , ) = app_select($MATCH, $q, 1000, 0, false);
    }
    $ids = array();
    foreach ($parts as $p) { if ($puc && !empty($p[$puc])) { $ids[] = $p[$puc]; } }
    foreach ($matches as $m) { foreach (array_merge($mPlayers, $mWin ? array($mWin) : array()) as $c) { if (!empty($m[$c])) { $ids[] = $m[$c]; } } }
    $champ = gx($T, array('winner_id', 'winner_user_id', 'champion_id'));
    if ($champ) { $ids[] = $champ; }
    $names = names_for($ids);

    // who went out where/when (from matches)
    $out = array(); $wins = array();
    foreach ($matches as $m) {
        $w = $mWin ? g($m, $mWin) : null;
        if (!$w) { continue; }
        $wins[$w] = (isset($wins[$w]) ? $wins[$w] : 0) + 1;
        foreach ($mPlayers as $c) { $pl = g($m, $c); if ($pl && $pl !== $w) { $out[$pl] = array('round' => $mRound ? g($m, $mRound) : null, 'at' => $mEnd ? g($m, $mEnd) : null, 'by' => $w); } }
    }
    if (!$champ) { foreach ($parts as $p) { if ((int) g($p, 'final_place', 0) === 1 && $puc) { $champ = $p[$puc]; } } }

    // header
    echo '<div class="panel"><div class="uhead"><div class="who"><h2>' . e(gx($T, array('name', 'title'), 'Tournament')) . ' ' . app_badge($st) . '</h2><div class="muted" style="font-size:12px">' . e($id) . '</div></div><div class="meta">';
    foreach (array('entry_fee_minor' => 'Entry fee', 'entry_fee' => 'Entry fee', 'prize_pool_minor' => 'Prize pool', 'max_participants' => 'Max players', 'bracket_size' => 'Bracket size',
                   'registration_closes_at' => 'Registration closes', 'starts_at' => 'Starts', 'started_at' => 'Started', 'finished_at' => 'Finished', 'ended_at' => 'Ended',
                   'move_timeout_sec' => 'Move timeout (s)', 'join_timeout_sec' => 'Join timeout (s)', 'created_at' => 'Created') as $k => $label) {
        if (array_key_exists($k, $T)) { echo '<div><span>' . e($label) . ':</span> ' . app_cell($k, $T[$k], isset($TC[$k]) ? $TC[$k] : '') . '</div>'; }
    }
    echo '<div><span>Players joined:</span> <b>' . count($parts) . '</b></div><div><span>Matches:</span> <b>' . count($matches) . '</b></div>';
    echo '</div></div>';
    if ($champ) { echo '<div class="champ">&#127942; Champion: ' . who($names, $champ) . '</div>'; }
    echo '<div class="actions">';
    echo '<form method="post" data-confirm="START NOW: closes registration, seeds the players and creates the bracket (byes for empty seats). This cannot be undone. Continue?">' . csrf_field() . '<input type="hidden" name="action" value="start"><input type="hidden" name="tournament_id" value="' . e($id) . '"><button class="btn danger sm" type="submit">Start now (generate bracket)</button></form>';
    if (isset($TC['status'])) {
        echo '<details class="act"><summary class="btn ghost sm">Change status</summary><form class="pop" method="post" data-confirm="Change the tournament status?">' . csrf_field() . '<input type="hidden" name="action" value="status"><input type="hidden" name="tournament_id" value="' . e($id) . '"><label>Status';
        if ($statusOpts) { echo '<select name="status">'; foreach ($statusOpts as $o) { echo '<option' . ($o === $st ? ' selected' : '') . '>' . e($o) . '</option>'; } echo '</select>'; } else { echo '<input name="status" value="' . e($st) . '" required>'; }
        echo '</label><button class="btn sm" type="submit">Save</button></form></details>';
    }
    if ($PART) {
        echo '<details class="act"><summary class="btn ghost sm">Notify all players</summary><form class="pop" method="post" data-confirm="Send this to every player of this tournament?">' . csrf_field() . '<input type="hidden" name="action" value="notify"><input type="hidden" name="tournament_id" value="' . e($id) . '">'
            . '<label>Title<input name="title" required maxlength="80"></label><label>Message<textarea name="body" required maxlength="500"></textarea></label><button class="btn sm" type="submit">Send</button></form></details>';
    }
    echo '</div></div>';

    // participants
    echo '<h2>Players (' . count($parts) . ')</h2>';
    if (!$PART) { echo '<p class="muted">No participants table found.</p>'; }
    else {
        echo '<div class="tbl-wrap"><table><tr><th>Seed</th><th>Player</th><th>Status</th><th>Joined</th><th>Matches won</th><th>Out in round</th><th>Out at</th><th>Knocked out by</th><th>Place</th><th>Prize</th><th>Paid</th></tr>';
        foreach ($parts as $p) {
            $u = $puc ? g($p, $puc) : null; $o = $u && isset($out[$u]) ? $out[$u] : null;
            $isCh = $u && $u === $champ;
            echo '<tr' . ($isCh ? ' class="win"' : '') . '><td>' . app_num(g($p, 'seed')) . '</td><td>' . ($isCh ? '&#127942; ' : '') . who($names, $u) . '</td><td>' . app_badge(gx($p, array('status', 'my_status'))) . '</td>';
            echo '<td class="nowrap">' . app_dt(gx($p, array('joined_at', 'created_at'))) . '</td><td>' . app_num(gx($p, array('rounds_won'), $u && isset($wins[$u]) ? $wins[$u] : 0)) . '</td>';
            echo '<td>' . app_num(gx($p, array('eliminated_round'), $o ? $o['round'] : null)) . '</td><td class="nowrap">' . ($o ? app_dt($o['at']) : '<span class="muted">-</span>') . '</td><td>' . ($o ? who($names, $o['by']) : '<span class="muted">-</span>') . '</td>';
            echo '<td>' . app_num(g($p, 'final_place')) . '</td><td class="pos">' . app_num(gx($p, array('prize_minor', 'prize'))) . '</td><td>' . app_num(gx($p, array('paid_minor', 'entry_paid_minor'))) . '</td></tr>';
        }
        if (!$parts) { echo '<tr><td colspan="11" class="muted">Nobody has joined yet.</td></tr>'; }
        echo '</table></div>';
    }

    // bracket / match history
    echo '<h2>Matches &amp; bracket (' . count($matches) . ')</h2>';
    if (!$MATCH) { echo '<p class="muted">No tournament matches table found.</p>'; }
    elseif (!$matches) { echo '<p class="muted">No matches yet. Press "Start now" to generate the bracket.</p>'; }
    else {
        $byRound = array();
        foreach ($matches as $m) { $byRound[$mRound ? (string) g($m, $mRound, '?') : '1'][] = $m; }
        echo '<div class="bracket">';
        foreach ($byRound as $rn => $ms) {
            echo '<div class="bround"><div class="bhead">Round ' . e($rn) . '</div>';
            foreach ($ms as $m) {
                $w = $mWin ? g($m, $mWin) : null;
                echo '<div class="bmatch">';
                foreach ($mPlayers as $i => $c) {
                    $pl = g($m, $c);
                    echo '<div class="bp' . ($w && $pl === $w ? ' bw' : ($w && $pl ? ' bl' : '')) . '">' . ($pl ? who($names, $pl) : '<span class="muted">bye / waiting</span>') . ($w && $pl === $w ? ' &#10003;' : '') . '</div>';
                }
                echo '<div class="bmeta">' . app_badge(g($m, 'status')) . ' ' . ($mStart ? app_dt(g($m, $mStart)) : '') . ($mStart && $mEnd && g($m, $mEnd) ? ' &middot; ' . e(dur(g($m, $mStart), g($m, $mEnd))) : '') . (isset($m['id']) ? ' &middot; <a href="/admin/app/data/?t=' . e(rawurlencode($MATCH)) . '&amp;edit=' . e(rawurlencode($m['id'])) . '">details</a>' : '') . '</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<details style="margin-top:16px"><summary class="muted">All match rows (raw)</summary>' . app_rows_table($matches, $MATCH) . '</details>';
    }
    admin_foot(); exit;
}

/* ================= list + create ================= */
$q = array();
if (isset($TC['created_at'])) { $q['order'] = 'created_at.desc'; } elseif (isset($TC['starts_at'])) { $q['order'] = 'starts_at.desc'; }
$fs = app_get('status', 40);
if ($fs !== '' && isset($TC['status'])) { $q['status'] = 'eq.' . $fs; }
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)); $per = 25;
list($rows, $total, $r) = app_select($TT, $q, $per, ($page - 1) * $per);
$counts = array(); $champs = array();
if ($PART && $rows) {
    $tids = array(); foreach ($rows as $t) { $tids[] = $t['id']; }
    list($ps, , ) = app_select($PART, array('tournament_id' => 'in.(' . implode(',', $tids) . ')', 'select' => 'tournament_id'), 10000, 0, false);
    foreach ($ps as $p) { $counts[$p['tournament_id']] = (isset($counts[$p['tournament_id']]) ? $counts[$p['tournament_id']] : 0) + 1; }
}
$wids = array(); foreach ($rows as $t) { $w = gx($t, array('winner_id', 'winner_user_id', 'champion_id')); if ($w) { $wids[] = $w; } }
$names = names_for($wids);

app_head('Tournaments', 'tour');
echo '<details class="panel"' . (isset($_GET['new']) ? ' open' : '') . '><summary class="btn">+ Create tournament</summary>';
echo '<form method="post" style="margin-top:16px" data-confirm="Create this tournament? Players will see it in the app.">' . csrf_field() . '<input type="hidden" name="action" value="create"><div class="fgrid">';
echo '<label>Name<input name="name" required maxlength="80" placeholder="Sunday Cup"></label>';
echo '<label>Entry fee (coins, 0 = free)<input name="entry_fee" type="number" min="0" step="1" value="0" required></label>';
echo '<label>Max players (2–256)<input name="max_participants" type="number" min="2" max="256" value="16" required></label>';
echo '<label>Registration closes<input name="closes_at" type="datetime-local" value="' . e(date('Y-m-d\TH:i', strtotime('tomorrow 18:00'))) . '" required></label>';
echo '<label>Starts at<input name="starts_at" type="datetime-local" value="' . e(date('Y-m-d\TH:i', strtotime('tomorrow 18:30'))) . '" required></label>';
echo '<label>Move timeout (sec)<input name="move_timeout" type="number" min="5" max="300" value="20"></label>';
echo '<label>Join timeout (sec)<input name="join_timeout" type="number" min="10" max="900" value="60"></label>';
echo '<label>Prize pool (coins, optional)<input name="prize" type="number" min="0" step="1" value="0"></label>';
echo '</div><p class="muted" style="margin-top:0">Times are in ' . e(date_default_timezone_get()) . ' (' . e(date('P')) . ').</p><button class="btn" type="submit">Create tournament</button></form></details>';

echo '<form class="row" method="get">';
if (isset($TC['status'])) {
    echo '<select name="status"><option value="">All statuses</option>';
    foreach ($statusOpts ?: array('registration', 'open', 'live', 'in_progress', 'finished', 'completed', 'cancelled') as $o) { echo '<option' . ($fs === $o ? ' selected' : '') . '>' . e($o) . '</option>'; }
    echo '</select><button class="btn sm" type="submit">Filter</button>';
}
echo '<span class="muted">' . app_num($total) . ' tournaments</span></form>' . app_err_box($r);
echo '<div class="tbl-wrap"><table><tr><th>Name</th><th>Status</th><th>Players</th><th>Entry fee</th><th>Registration closes</th><th>Starts</th><th>Champion</th></tr>';
foreach ($rows as $t) {
    $href = '/admin/app/tournaments/?id=' . rawurlencode($t['id']);
    $w = gx($t, array('winner_id', 'winner_user_id', 'champion_id'));
    echo '<tr class="click" data-href="' . e($href) . '"><td><a href="' . e($href) . '"><b>' . e(gx($t, array('name', 'title'), '-')) . '</b></a></td><td>' . app_badge(g($t, 'status')) . '</td>';
    echo '<td>' . app_num(isset($counts[$t['id']]) ? $counts[$t['id']] : 0) . (gx($t, array('max_participants', 'bracket_size')) ? ' / ' . app_num(gx($t, array('max_participants', 'bracket_size'))) : '') . '</td>';
    echo '<td>' . app_num(gx($t, array('entry_fee_minor', 'entry_fee'))) . '</td><td class="nowrap">' . app_dt(g($t, 'registration_closes_at')) . '</td><td class="nowrap">' . app_dt(g($t, 'starts_at')) . '</td>';
    echo '<td>' . ($w ? '&#127942; ' . who($names, $w) : app_cell('w', g($t, 'winner_username'))) . '</td></tr>';
}
if (!$rows) { echo '<tr><td colspan="7" class="muted">' . ($r['ok'] ? 'No tournaments yet. Create the first one above.' : 'Could not load tournaments.') . '</td></tr>'; }
echo '</table></div>' . ($total !== null ? pager($page, $total, $per) : '');
admin_foot();
