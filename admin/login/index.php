<?php
require __DIR__ . '/../../includes/admin_accounts.php';
if (!empty($_SESSION['admin'])) { redirect('/admin/'); }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $key = 'login:' . client_ip();
    $user = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
    $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if (rl_count($key, 900) >= 5) {
        $err = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        list($who, $e) = adm_check_login($user, $pass);
        if ($who !== null) {
            rl_clear($key);
            session_regenerate_id(true);
            $_SESSION['admin'] = $who;
            $_SESSION['last'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            audit_log('login', $who, client_ip());
            redirect('/admin/');
        }
        if (strpos($e, 'not set yet') === false) { rl_add($key, 900); }
        $err = $e;
    }
}
$f = flash_get();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin login - Drotix</title><link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body><div class="login"><form class="box" method="post" autocomplete="off">
<div class="shapes" aria-hidden="true"><i></i><i></i><i></i></div>
<h1>Drotix admin</h1><div class="muted">Tic Tac Toe control room</div>
<?php if ($f) { echo '<div class="flash ' . e($f[0]) . '">' . e($f[1]) . '</div>'; } ?>
<?php if ($err !== '') { echo '<div class="flash err">' . e($err) . '</div>'; } ?>
<?php echo csrf_field(); ?>
<label>Username or email<input name="username" required autofocus autocomplete="username"></label>
<label>Password<input name="password" type="password" required autocomplete="current-password"></label>
<button class="btn" type="submit">Enter</button>
<a class="muted" href="/admin/forgot/" style="text-align:center;font-size:13px">Forgot password?</a></form></div></body></html>
