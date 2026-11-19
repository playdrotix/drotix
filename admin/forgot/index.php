<?php
/* Forgot password: enter the admin email -> a reset link is emailed (valid 30 minutes). */
require __DIR__ . '/../../includes/admin_accounts.php';
if (!empty($_SESSION['admin'])) { redirect('/admin/profile/'); }
$done = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $key = 'forgot:' . client_ip();
    if (rl_count($key, 3600) >= 5) { $err = 'Too many requests. Please try again in an hour.'; }
    else {
        rl_add($key, 3600);
        $email = clean_str(isset($_POST['email']) ? $_POST['email'] : '', 200);
        if (!adm_ready()) { $err = 'Password reset is not set up yet: run database/admin_accounts.sql in Supabase.'; }
        else { adm_send_reset($email); $done = true; }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Forgot password - Drotix admin</title><link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body><div class="login"><form class="box" method="post" autocomplete="off">
<div class="shapes" aria-hidden="true"><i></i><i></i><i></i></div>
<h1>Reset password</h1>
<?php if ($done) { ?>
<div class="flash ok">If this email belongs to an admin account, a reset link is on its way. It is valid for <?php echo (int) ADMIN_RESET_MINUTES; ?> minutes. Check the spam folder too.</div>
<a class="btn ghost" href="/admin/login/">Back to login</a>
<?php } else { ?>
<div class="muted">Enter the email of your admin account. We will email you a link to choose a new password.</div>
<?php if ($err !== '') { echo '<div class="flash err">' . e($err) . '</div>'; } ?>
<?php echo csrf_field(); ?>
<label>Email<input name="email" type="email" required autofocus autocomplete="email"></label>
<button class="btn" type="submit">Send reset link</button>
<a class="muted" href="/admin/login/" style="text-align:center;font-size:13px">Back to login</a>
<?php } ?>
</form></div></body></html>
