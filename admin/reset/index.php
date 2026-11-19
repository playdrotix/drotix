<?php
/* Reset password from the emailed link (/admin/reset/?t=<token>). One-time, expires after 30 minutes. */
require __DIR__ . '/../../includes/admin_accounts.php';
$token = isset($_GET['t']) ? (string) $_GET['t'] : (isset($_POST['t']) ? (string) $_POST['t'] : '');
$row = adm_by_token($token);
$err = '';
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pw = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $err = adm_password_problem($pw, isset($_POST['confirm']) ? (string) $_POST['confirm'] : '');
    if ($err === '') {
        $r = adm_update($row['id'], array('password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'reset_token_hash' => null, 'reset_expires_at' => null, 'password_changed_at' => date('c')));
        if (sb_ok($r)) {
            audit_log('admin_password_reset', $row['username'], client_ip());
            $_SESSION = array(); session_regenerate_id(true);
            flash_set('ok', 'Password changed. Log in with your new password.');
            redirect('/admin/login/');
        }
        $err = 'Could not save: ' . $r['error'];
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>New password - Drotix admin</title><link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body><div class="login"><form class="box" method="post" autocomplete="off">
<div class="shapes" aria-hidden="true"><i></i><i></i><i></i></div>
<h1>New password</h1>
<?php if (!$row) { ?>
<div class="flash err">This reset link is invalid or has expired. Ask for a new one.</div>
<a class="btn" href="/admin/forgot/">Send a new link</a>
<?php } else { ?>
<div class="muted">Account: <b><?php echo e($row['username']); ?></b></div>
<?php if ($err !== '') { echo '<div class="flash err">' . e($err) . '</div>'; } ?>
<?php echo csrf_field(); ?><input type="hidden" name="t" value="<?php echo e($token); ?>">
<label>New password (min <?php echo (int) ADMIN_MIN_PASSWORD; ?>, letters + a number)<input name="password" type="password" required minlength="<?php echo (int) ADMIN_MIN_PASSWORD; ?>" autocomplete="new-password" autofocus></label>
<label>Repeat new password<input name="confirm" type="password" required autocomplete="new-password"></label>
<button class="btn" type="submit">Save new password</button>
<?php } ?>
</form></div></body></html>
