<?php
/* App admin: Supabase sign-in (second step after the website admin login). */
require __DIR__ . '/../../../includes/app_admin_api.php';
admin_require_login();

$next = isset($_GET['next']) ? (string) $_GET['next'] : '/admin/app/';
if (strpos($next, '/admin/app/') !== 0 || strpos($next, '//') !== false) { $next = '/admin/app/'; }
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ((isset($_POST['action']) ? $_POST['action'] : '') === 'signout') {
        app_sign_out();
        flash_set('ok', 'Signed out of App admin.');
        redirect('/admin/app/login/');
    }
    $key = 'applogin:' . client_ip();
    if (rl_count($key, 900) >= 5) {
        $err = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        $email = clean_str(isset($_POST['email']) ? $_POST['email'] : '', 200);
        $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $err = app_sign_in($email, $pass);
        if ($err === '') {
            rl_clear($key);
            audit_log('app_admin_signin', $email);
            redirect($next);
        }
        rl_add($key, 900);
    }
}
if (app_token() !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') { redirect($next); }

admin_head('Sign in to App admin', 'app');
$problem = app_config_problem();
if ($problem !== '') { echo '<div class="flash err">' . e($problem) . '</div>'; }
if ($err !== '') { echo '<div class="flash err">' . e($err) . '</div>'; }
?>
<div class="panel" style="max-width:460px">
<p class="muted" style="margin-top:0">The App section manages the DROTIX mobile game. Sign in with your <b>Supabase account</b> (the same email you use in the app). Only accounts marked as app admin can use it.</p>
<form method="post" autocomplete="off" style="display:grid;gap:12px">
<?php echo csrf_field(); ?>
<label style="display:grid;gap:6px;font-weight:600;font-size:13px;color:var(--m)">Email<input name="email" type="email" required autofocus autocomplete="username"></label>
<label style="display:grid;gap:6px;font-weight:600;font-size:13px;color:var(--m)">Password<input name="password" type="password" required autocomplete="current-password"></label>
<button class="btn" type="submit">Sign in</button>
</form>
</div>
<?php admin_foot();
