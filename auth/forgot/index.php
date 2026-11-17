<?php
require __DIR__ . '/../../includes/auth.php';
require_guest();

$message = '';
$type = 'info';
$formData = array('email' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(clean_str($_POST['email'] ?? '', 254));
    $formData['email'] = $email;

    list($ok, $msg) = auth_rate_limit('forgot', client_ip());
    if (!$ok) { $message = $msg; $type = 'error'; }

    if (!$message && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.'; $type = 'error';
    }

    if (!$message) {
        $user = find_user_by_email($email);
        // Always show success to prevent email enumeration
        if ($user && $user['provider'] === 'email' && $user['password_hash']) {
            $token = generate_reset_token();
            $expiry = gmdate('c', time() + (int) cfg('auth.reset_token_hours', 2) * 3600);
            update_user($user['id'], array('reset_token' => $token, 'reset_token_expiry' => $expiry));
            send_reset_email($user, $token);
        }
        $message = 'If an account with that email exists, you will receive a password reset link shortly.';
        $type = 'success';
    }
}

csrf_token();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password · Drotix</title><meta name="robots" content="noindex,nofollow">
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
.auth-form input:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.auth-form .btn{margin-top:6px}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.flash.info{background:rgba(237,27,118,.1);border:1px solid var(--magenta);color:var(--magenta)}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-head">
      <span class="tag">Reset Password</span>
      <h1>Forgot your password?</h1>
      <p>Enter your email and we'll send you a reset link</p>
    </div>

    <?php if ($message): ?>
      <div class="flash <?php echo $type; ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form" novalidate>
      <?php echo csrf_field(); ?>
      <label>Email
        <input type="email" name="email" required autocomplete="email" placeholder="you@example.com" value="<?php echo e($formData['email']); ?>">
      </label>
      <button class="btn btn-primary" type="submit"><span>Send Reset Link</span></button>
    </form>

    <p class="auth-foot"><a href="/auth/login/">Back to Sign In</a></p>
  </div>
</div>
</body></html>