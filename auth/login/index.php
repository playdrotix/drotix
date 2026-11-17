<?php
require __DIR__ . '/../../includes/auth.php';
require_guest();

$error = '';
$formData = array('email' => '', 'remember' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = strtolower(clean_str($_POST['email'] ?? '', 254));
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    $formData = array('email' => $email, 'remember' => $remember ? 'checked' : '');

    // Rate limit
    list($ok, $msg) = auth_rate_limit('login', client_ip());
    if (!$ok) { $error = $msg; }

    // Validate
    if (!$error) {
        $user = find_user_by_email($email);
        if (!$user) { $error = 'No account found with this email.'; }
        elseif (!$user['password_hash'] && $user['provider'] === 'facebook') { $error = 'This account uses Facebook login. Please sign in with Facebook.'; }
        elseif (!verify_password($password, $user['password_hash'])) {
            $error = 'Invalid password.';
        }
        elseif ($user['status'] === 'banned') { $error = 'This account has been banned.'; }
        elseif ($user['status'] === 'suspended' && !empty($user['suspended_until']) && $user['suspended_until'] > time()) {
            $error = 'This account is temporarily suspended.';
        }
        elseif ($user['status'] === 'pending_verification') { $error = 'Please verify your email first. Check your inbox for the verification link.'; }
    }

    // Login
    if (!$error && $user) {
        auth_clear_rate_limit('login', client_ip());
        user_login($user, $remember);

        $return = isset($_POST['return']) ? $_POST['return'] : '/dashboard/';
        // Validate return URL is local
        if (strpos($return, '://') !== false || $return[0] !== '/') { $return = '/dashboard/'; }
        header('Location: ' . $return);
        exit;
    }
}

csrf_token();
$pageTitle = 'Sign In';
$returnUrl = isset($_GET['return']) ? $_GET['return'] : '/dashboard/';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($pageTitle); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
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
.auth-form .row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:4px}
.auth-form .remember{display:flex;align-items:center;gap:8px;font-weight:400;color:var(--muted);font-size:13px;cursor:pointer}
.auth-form .remember input{width:18px;height:18px;accent-color:var(--magenta)}
.auth-form .forgot{color:var(--pink);font-weight:600;text-decoration:none;font-size:13px}
.auth-form .forgot:hover{text-decoration:underline}
.auth-form .btn{margin-top:6px}
.auth-divider{display:flex;align-items:center;gap:14px;margin:8px 0 4px;color:var(--muted);font-size:13px;font-weight:600}
.auth-divider::before,.auth-divider::after{content:"";flex:1;height:1px;background:var(--border2)}
.btn-fb{display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;height:54px;border-radius:14px;border:1px solid var(--border2);background:var(--surface);color:var(--text);font:700 14px 'Outfit',sans-serif;letter-spacing:1px;text-decoration:none;transition:border-color .2s,transform .2s}
.btn-fb:hover{border-color:#1877F2;transform:translateY(-2px);color:#1877F2}
.btn-fb svg{width:20px;height:20px}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-head">
      <span class="tag">Player Login</span>
      <h1>Welcome back</h1>
      <p>Enter your credentials to enter the arena</p>
    </div>

    <?php if ($error): ?>
      <div class="flash err"><?php echo e($error); ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form" novalidate>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">

      <label>Email
        <input type="email" name="email" required autocomplete="email" placeholder="you@example.com" value="<?php echo e($formData['email']); ?>">
      </label>

      <label>Password
        <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
      </label>

      <div class="row">
        <label class="remember"><input type="checkbox" name="remember" value="1" <?php echo $formData['remember']; ?>> Remember me</label>
        <a class="forgot" href="/auth/forgot/">Forgot password?</a>
      </div>

      <button class="btn btn-primary" type="submit"><span>Sign In</span></button>
    </form>

    <div class="auth-divider">or continue with</div>

    <a class="btn-fb" href="/auth/facebook/?return=<?php echo urlencode($returnUrl); ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15 3h-2.5A4.5 4.5 0 008 7.5V10H5v4h3v7h4v-7h3l1-4h-4V7.8c0-.5.3-.8.8-.8H15z"/></svg>
      <span>Continue with Facebook</span>
    </a>

    <p class="auth-foot">Don't have an account? <a href="/auth/register/?return=<?php echo urlencode($returnUrl); ?>">Create one</a></p>
  </div>
</div>
</body></html>