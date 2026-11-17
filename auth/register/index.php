<?php
require __DIR__ . '/../../includes/auth.php';
require_guest();

$error = '';
$success = '';
$formData = array('email' => '', 'username' => '', 'password' => '', 'confirm' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = strtolower(clean_str($_POST['email'] ?? '', 254));
    $username = clean_str($_POST['username'] ?? '', 40);
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $consent = $_POST['consent'] ?? '';

    $formData = array('email' => $email, 'username' => $username);

    // Rate limit
    list($ok, $msg) = auth_rate_limit('register', client_ip());
    if (!$ok) { $error = $msg; }

    // Validation
    if (!$error) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email address.'; }
        elseif (find_user_by_email($email)) { $error = 'An account with this email already exists.'; }
        elseif (strlen($username) < 3) { $error = 'Username must be at least 3 characters.'; }
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { $error = 'Username can only contain letters, numbers, and underscores.'; }
        elseif (find_user_by_username($username)) { $error = 'This username is already taken.'; }
        elseif (($v = validate_password($password)) !== true) { $error = $v; }
        elseif ($password !== $confirm) { $error = 'Passwords do not match.'; }
        elseif ($consent !== 'yes') { $error = 'Please accept the Terms of Use and Privacy Policy.'; }
    }

    // Create user
    if (!$error) {
        $token = generate_verification_token();
        $expiry = gmdate('c', time() + (int) cfg('auth.verification_token_hours', 24) * 3600);
        $verificationNeeded = (bool) cfg('auth.require_email_verification', true);
        $initialStatus = $verificationNeeded ? 'pending_verification' : 'active';

        $userData = array(
            'email' => $email,
            'username' => $username,
            'password_hash' => hash_password($password),
            'status' => $initialStatus,
            'verification_token' => $token,
            'verify_token_expiry' => $expiry,
            'provider' => 'email',
        );

        $r = sb_insert(tbl('users'), $userData, array(), 'return=representation');
        if (sb_ok($r) && !empty($r['data'])) {
            $user = $r['data'][0];
            if ($verificationNeeded) {
                send_verification_email($user, $token);
                $success = 'Registration successful! Please check your email to verify your account.';
            } else {
                user_login($user);
                header('Location: /dashboard/');
                exit;
            }
        } else {
            $error = 'Registration failed. Please try again or contact support.';
        }
    }
}

// CSRF token for form
csrf_token();

$pageTitle = 'Create Account';
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
.auth-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.auth-form .check{display:flex;gap:10px;align-items:flex-start;font-weight:400;color:var(--muted);font-size:13px;line-height:1.5}
.auth-form .check input{width:18px;height:18px;margin-top:2px;accent-color:var(--magenta);flex:none}
.auth-form .check a{color:var(--pink);text-decoration:underline}
.auth-form .btn{margin-top:6px}
.auth-divider{display:flex;align-items:center;gap:14px;margin:8px 0 4px;color:var(--muted);font-size:13px;font-weight:600}
.auth-divider::before,.auth-divider::after{content:"";flex:1;height:1px;background:var(--border2)}
.btn-fb{display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;height:54px;border-radius:14px;border:1px solid var(--border2);background:var(--surface);color:var(--text);font:700 14px 'Outfit',sans-serif;letter-spacing:1px;text-decoration:none;transition:border-color .2s,transform .2s}
.btn-fb:hover{border-color:#1877F2;transform:translateY(-2px);color:#1877F2}
.btn-fb svg{width:20px;height:20px}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
@media(max-width:480px){.auth-form .row2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-head">
      <span class="tag">Player Registration</span>
      <h1>Create your account</h1>
      <p>Join the arena and start your survival journey</p>
    </div>

    <?php if ($error): ?>
      <div class="flash err"><?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="flash ok"><?php echo e($success); ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form" novalidate>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">

      <label>Email
        <input type="email" name="email" required autocomplete="email" placeholder="you@example.com" value="<?php echo e($formData['email']); ?>">
      </label>

      <label>Username
        <input type="text" name="username" required minlength="3" maxlength="40" autocomplete="username" placeholder="Player456" value="<?php echo e($formData['username']); ?>" pattern="[a-zA-Z0-9_]+">
      </label>

      <div class="row2">
        <label>Password
          <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="••••••••">
        </label>
        <label>Confirm Password
          <input type="password" name="confirm" required autocomplete="new-password" placeholder="••••••••">
        </label>
      </div>

      <label class="check">
        <input type="checkbox" name="consent" value="yes" required>
        <span>I agree to the <a href="/terms-of-use/" target="_blank">Terms of Use</a> and <a href="/privacy-policy/" target="_blank">Privacy Policy</a>.</span>
      </label>

      <button class="btn btn-primary" type="submit"><span>Create Account</span></button>
    </form>

    <div class="auth-divider">or continue with</div>

    <a class="btn-fb" href="/auth/facebook/?return=<?php echo urlencode($returnUrl); ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15 3h-2.5A4.5 4.5 0 008 7.5V10H5v4h3v7h4v-7h3l1-4h-4V7.8c0-.5.3-.8.8-.8H15z"/></svg>
      <span>Continue with Facebook</span>
    </a>

    <p class="auth-foot">Already have an account? <a href="/auth/login/?return=<?php echo urlencode($returnUrl); ?>">Sign in</a></p>
  </div>
</div>
</body></html>