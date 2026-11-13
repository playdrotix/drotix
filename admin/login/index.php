<?php
require __DIR__ . '/../../includes/admin_layout.php';
if (!empty($_SESSION['admin'])) { redirect('/admin/'); }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $key = 'login:' . client_ip();
    $user = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
    $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $admins = cfg('admins', array());
    if (rl_count($key, 900) >= 5) {
        $err = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        $hash = isset($admins[$user]) ? (string) $admins[$user] : '';
        $dummy = '$2y$10$usesomesillystringforsaltuMf1oQn1g3j6vYQqWmuQw5wq5xJ6a';
        if ($hash === '' || $hash === 'PASTE_PASSWORD_HASH_HERE' || $hash[0] !== '$') {
            password_verify($pass, $dummy);
            $err = ($hash === 'PASTE_PASSWORD_HASH_HERE') ? 'Admin password is not set yet. See README step 5.' : 'Wrong username or password.';
            if ($hash !== 'PASTE_PASSWORD_HASH_HERE') { rl_add($key, 900); }
        } elseif (password_verify($pass, $hash)) {
            rl_clear($key);
            session_regenerate_id(true);
            $_SESSION['admin'] = $user;
            $_SESSION['last'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            audit_log('login', $user, client_ip());
            redirect('/admin/');
        } else {
            rl_add($key, 900);
            $err = 'Wrong username or password.';
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin login - Drotix</title><link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body><div class="login"><form class="box" method="post" autocomplete="off">
<div class="shapes" aria-hidden="true"><i></i><i></i><i></i></div>
<h1>Drotix admin</h1><div class="muted">Tic Tac Toe control room</div>
<?php if ($err !== '') { echo '<div class="flash err">' . e($err) . '</div>'; } ?>
<?php echo csrf_field(); ?>
<label>Username<input name="username" required autofocus autocomplete="username"></label>
<label>Password<input name="password" type="password" required autocomplete="current-password"></label>
<button class="btn" type="submit">Enter</button></form></div></body></html>
