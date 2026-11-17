<?php
require __DIR__ . '/../../includes/auth.php';
require_guest();

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$formData = array('password' => '', 'confirm' => '');
$validToken = false;

if ($token) {
    list($rows, , $r) = sb_select(tbl('users'), array(
        'reset_token' => 'eq.' . $token,
        'reset_token_expiry' => 'gt.' . gmdate('c'),
    ), 1, 0);

    if (sb_ok($r) && !empty($rows)) {
        $validToken = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    csrf_verify();

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $formData = array('password' => $password, 'confirm' => $confirm);

    list($ok, $msg) = auth_rate_limit('reset', client_ip());
    if (!$ok) { $error = $msg; }

    if (!$error) {
        if (($v = validate_password($password)) !== true) { $error = $v; }
        elseif ($password !== $confirm) { $error = 'Passwords do not match.'; }
    }

    if (!$error) {
        // Find user again to get ID
        list($rows, , $r) = sb_select(tbl('users'), array(
            'reset_token' => 'eq.' . $token,
            'reset_token_expiry' => 'gt.' . gmdate('c'),
        ), 1, 0);

        if (sb_ok($r) && !empty($rows)) {
            $user = $rows[0];
            $update = update_user($user['id'], array(
                'password_hash' => hash_password($password),
                'reset_token' => null,
                'reset_token_expiry' => null,
            ));

            if ($update) {
                auth_clear_rate_limit('reset', client_ip());
                $success = 'Password reset successfully! You can now sign in with your new password.';
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
        } else {
            $error = 'Invalid or expired reset token.';
            $validToken = false;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$validToken) {
    $error = 'Invalid or expired reset token.';
}

csrf_token();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(440px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3)}
.auth-head{text-align:center;margin-bottom:28px}
.auth-head .tag{margin-bottom:12px}
.auth-head h1{font-size:28px;margin:0 0 8px}
.auth-head p{color:var(--muted);margin:0}
.auth-form{display:grid;gap:18px}
.auth-form label{display:grid;gap:6px;font-weight:600;font-size:14px;color:var(--muted)}
.auth-form input{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif}
.auth-form input:focus{outline:none;border-color:var(--magenta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.auth-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.auth-form .btn{margin-top:6px}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
@media(max-width:480px){.auth-form .row2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-head">
      <span class="tag">Reset Password</span>
      <h1><?php echo $validToken ? 'Set New Password' : 'Invalid Link'; ?></h1>
      <p><?php echo $validToken ? 'Enter your new password below' : 'This reset link is invalid or has expired.'; ?></p>
    </div>

    <?php if ($error): ?>
      <div class="flash err"><?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="flash ok"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if ($validToken && !$success): ?>
    <form method="post" class="auth-form" novalidate>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="token" value="<?php echo e($token); ?>">
      <div class="row2">
        <label>New Password
          <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="••••••••">
        </label>
        <label>Confirm Password
          <input type="password" name="confirm" required autocomplete="new-password" placeholder="••••••••">
        </label>
      </div>
      <button class="btn btn-primary" type="submit"><span>Reset Password</span></button>
    </form>
    <?php endif; ?>

    <p class="auth-foot"><a href="/auth/login/">Back to Sign In</a></p>
  </div>
</div>
</body></html>