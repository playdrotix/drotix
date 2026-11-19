<?php
/* Mobile App: Admin check. Shows why the game's admin functions answer "Forbidden: admin only"
   and lets you make an account app admin (drotix profiles.is_admin = true) in one click. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$PT = app_profiles_table(); $IDC = app_profile_idcol();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($act === 'make' || $act === 'remove') {
        $uid = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
        if ($act === 'make' && !uuid_ok($uid)) {
            $email = strtolower(app_post('email', 200));
            foreach (app_auth_search_email($email, 20) as $u) { if (strtolower((string) g($u, 'email')) === $email) { $uid = (string) $u['id']; break; } }
            if (!uuid_ok($uid)) { flash_set('err', 'No app account with the email ' . $email . '. Sign up in the app with it first.'); redirect('/admin/app/check/'); }
        }
        list($rows, , ) = app_select($PT, array($IDC => 'eq.' . $uid), 1, 0, false);
        if (!$rows) { flash_set('err', 'This account has no game profile yet: open the app once with it, then try again.'); redirect('/admin/app/check/'); }
        $r = app_db('PATCH', $PT, array($IDC => 'eq.' . $uid), array('is_admin' => $act === 'make'), 'return=minimal');
        if ($r['ok']) {
            app_log($act === 'make' ? 'set_admin' : 'set_admin', $uid, $act === 'make' ? 'is_admin=true' : 'is_admin=false');
            unset($_SESSION['app_admin_tok']);
            flash_set('ok', $act === 'make' ? 'Done: this account is now an app admin. The checks below run again.' : 'Admin rights removed.');
        } else { flash_set('err', 'Could not save: ' . $r['error']); }
    }
    redirect('/admin/app/check/');
}

$tok = app_admin_token(true);   // fresh attempt, records every step
$steps = isset($_SESSION['app_admin_steps']) ? $_SESSION['app_admin_steps'] : array();
$test = $tok ? app_rpc('admin_online_now', array('p_limit' => 1)) : null;

list($admins, , $ar) = app_select($PT, array('is_admin' => 'eq.true'), 50, 0, false);
$emails = app_auth_many(array_map(function ($p) use ($IDC) { return $p[$IDC]; }, $admins));

app_head('Admin check', 'check');
echo '<p class="muted" style="margin-top:-8px">The game\'s admin functions (Online now, Broadcast, Privacy &amp; Terms, App update, Tournaments, Coupons, report / appeal decisions) only answer an <b>app admin</b>. The server acts as one of your app admin accounts automatically. No password or login is needed.</p>';

echo '<h2>Checks</h2><div class="tbl-wrap"><table>';
foreach ($steps as $st) { echo '<tr><td style="width:70px">' . ($st[0] ? '<span class="badge b-teal">OK</span>' : '<span class="badge b-red">FIX</span>') . '</td><td>' . e($st[1]) . '</td></tr>'; }
if ($test) {
    echo '<tr><td>' . ($test['ok'] ? '<span class="badge b-teal">OK</span>' : '<span class="badge b-red">FIX</span>') . '</td><td>Test call to the game (admin_online_now): ' . ($test['ok'] ? 'works. Everything is ready.' : e($test['error'])) . '</td></tr>';
}
echo '</table></div>';

if ($tok && $test && !$test['ok']) {
    echo '<div class="warn" style="margin-top:14px"><b>The session works, but the game still refuses it.</b><br>1) Check that the account below really has <code>is_admin = true</code>.<br>'
        . '2) Add the <b>Publishable key</b> to <code>includes/config.php</code> as <code>\'supabase_anon_key\' => \'sb_publishable_...\',</code> (Supabase &rarr; Project Settings &rarr; API Keys), then reload this page.</div>';
}
if (!$tok && !$admins) {
    echo '<div class="warn" style="margin-top:14px"><b>Fix:</b> make your own app account an admin with the form below (use the email you sign in with in the DROTIX app).</div>';
}

echo '<h2>Make an account app admin</h2><form method="post" class="panel row" style="max-width:640px" data-confirm="Make this account an app admin? It gets full admin rights in the game.">' . csrf_field()
    . '<input type="hidden" name="action" value="make"><input name="email" type="email" required placeholder="Email used in the DROTIX app" style="flex:1;min-width:240px"><button class="btn" type="submit">Make app admin</button></form>';

echo '<h2>App admins (' . count($admins) . ')</h2>' . app_err_box($ar) . '<div class="tbl-wrap"><table><tr><th>Player</th><th>Email</th><th>Login</th><th></th></tr>';
foreach ($admins as $p) {
    $u = $p[$IDC]; $a = isset($emails[$u]) ? $emails[$u] : null;
    echo '<tr><td>' . app_user_link($u, gx($p, array('username'), substr($u, 0, 8))) . '</td><td>' . e(g($a, 'email', '-')) . '</td><td>' . ($a ? (g($a, 'last_sign_in_at') ? 'last ' . app_dt($a['last_sign_in_at']) : 'never signed in') : '<span class="badge b-red">no login account</span>') . '</td>';
    echo '<td><form method="post" data-confirm="Remove app admin rights from this account?">' . csrf_field() . '<input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="' . e($u) . '"><button class="btn ghost sm" type="submit">Remove admin</button></form></td></tr>';
}
if (!$admins) { echo '<tr><td colspan="4" class="muted">No app admin yet.</td></tr>'; }
echo '</table></div>';
admin_foot();
