<?php
/* My profile (logged-in web admin): name, email (used for password reset), change password. */
require __DIR__ . '/../../includes/admin_accounts.php';
admin_require_login();
$me = (string) $_SESSION['admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $row = adm_get($me);
    if (!$row) { flash_set('err', 'Your account is not in the admin_users table yet. Run database/admin_accounts.sql in Supabase, then log out and log in once.'); redirect('/admin/profile/'); }
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($act === 'profile') {
        $name = clean_str(isset($_POST['display_name']) ? $_POST['display_name'] : '', 80);
        $email = strtolower(clean_str(isset($_POST['email']) ? $_POST['email'] : '', 200));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash_set('err', 'Enter a valid email.'); redirect('/admin/profile/'); }
        if ($email !== '' && ($o = adm_find($email)) && $o['id'] !== $row['id']) { flash_set('err', 'Another admin already uses this email.'); redirect('/admin/profile/'); }
        $r = adm_update($row['id'], array('display_name' => $name !== '' ? $name : $me, 'email' => $email !== '' ? $email : null));
        if (sb_ok($r)) { audit_log('admin_profile_update', $me, $email); flash_set('ok', 'Profile saved.'); } else { flash_set('err', 'Could not save: ' . $r['error']); }
    } elseif ($act === 'password') {
        $cur = isset($_POST['current']) ? (string) $_POST['current'] : '';
        $pw = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $key = 'pwchange:' . $me;
        if (rl_count($key, 900) >= 5) { flash_set('err', 'Too many wrong attempts. Wait 15 minutes.'); redirect('/admin/profile/'); }
        if (!password_verify($cur, (string) $row['password_hash'])) { rl_add($key, 900); flash_set('err', 'The current password is wrong.'); redirect('/admin/profile/'); }
        $p = adm_password_problem($pw, isset($_POST['confirm']) ? (string) $_POST['confirm'] : '');
        if ($p === '' && password_verify($pw, (string) $row['password_hash'])) { $p = 'The new password must be different from the current one.'; }
        if ($p !== '') { flash_set('err', $p); redirect('/admin/profile/'); }
        $r = adm_update($row['id'], array('password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'password_changed_at' => date('c'), 'reset_token_hash' => null, 'reset_expires_at' => null));
        if (sb_ok($r)) {
            rl_clear($key); session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32));
            audit_log('admin_password_change', $me, client_ip());
            flash_set('ok', 'Password changed. Use the new password next time you log in.');
        } else { flash_set('err', 'Could not save: ' . $r['error']); }
    }
    redirect('/admin/profile/');
}

$row = adm_get($me);
admin_head('My profile', 'me');
if (!adm_ready()) {
    echo '<div class="flash err"><b>Setup needed.</b> Run <code>database/admin_accounts.sql</code> in Supabase &rarr; SQL Editor, then log out and log in once. After that you can change your password and use "Forgot password".</div>';
    admin_foot(); exit;
}
if (!$row) { echo '<div class="flash err">Your account is not in the admin_users table yet. Log out and log in once; it is copied automatically.</div>'; admin_foot(); exit; }

echo '<div class="panel" style="max-width:620px"><dl class="kv"><dt>Username</dt><dd><b>' . e($row['username']) . '</b></dd><dt>Last login</dt><dd>' . fmt_dt($row['last_login_at']) . '</dd><dt>Password changed</dt><dd>' . fmt_dt(isset($row['password_changed_at']) ? $row['password_changed_at'] : null) . '</dd></dl></div>';

$lbl = 'style="display:grid;gap:6px;font-weight:600;font-size:13px;color:var(--m)"';
echo '<h2>Profile</h2><form method="post" class="panel" style="max-width:620px;display:grid;gap:12px">' . csrf_field() . '<input type="hidden" name="action" value="profile">';
echo '<label ' . $lbl . '>Display name<input name="display_name" maxlength="80" value="' . e(isset($row['display_name']) ? $row['display_name'] : '') . '"></label>';
echo '<label ' . $lbl . '>Email (for "Forgot password")<input name="email" type="email" maxlength="200" value="' . e(isset($row['email']) ? $row['email'] : '') . '" placeholder="you@playdrotix.in"></label>';
if (empty($row['email'])) { echo '<div class="muted" style="color:var(--gd)">Add an email, otherwise you cannot reset a forgotten password.</div>'; }
echo '<div><button class="btn" type="submit">Save profile</button></div></form>';

echo '<h2>Change password</h2><form method="post" class="panel" style="max-width:620px;display:grid;gap:12px" data-confirm="Change your admin password?">' . csrf_field() . '<input type="hidden" name="action" value="password">';
echo '<label ' . $lbl . '>Current password<input name="current" type="password" required autocomplete="current-password"></label>';
echo '<label ' . $lbl . '>New password (min ' . (int) ADMIN_MIN_PASSWORD . ', letters + a number)<input name="password" type="password" required minlength="' . (int) ADMIN_MIN_PASSWORD . '" autocomplete="new-password"></label>';
echo '<label ' . $lbl . '>Repeat new password<input name="confirm" type="password" required autocomplete="new-password"></label>';
echo '<div><button class="btn" type="submit">Change password</button></div></form>';
admin_foot();
