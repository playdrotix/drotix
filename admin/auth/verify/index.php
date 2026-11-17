<?php
require __DIR__ . '/../../includes/auth.php';

$token = $_GET['token'] ?? '';
$message = '';
$type = 'info'; // info, success, error

if ($token) {
    list($rows, , $r) = sb_select(tbl('users'), array(
        'verification_token' => 'eq.' . $token,
        'verify_token_expiry' => 'gt.' . gmdate('c'),
        'status' => 'eq.pending_verification',
    ), 1, 0);

    if (sb_ok($r) && !empty($rows)) {
        $user = $rows[0];
        $update = update_user($user['id'], array(
            'status' => 'active',
            'email_verified' => true,
            'verification_token' => null,
            'verify_token_expiry' => null,
        ));
        if ($update) {
            $message = 'Email verified successfully! You can now sign in.';
            $type = 'success';
        } else {
            $message = 'Verification failed. Please try again or contact support.';
            $type = 'error';
        }
    } else {
        $message = 'Invalid or expired verification link. Please register again or request a new link.';
        $type = 'error';
    }
} else {
    $message = 'No verification token provided.';
    $type = 'error';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify Email · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(440px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3);text-align:center}
.auth-icon{width:80px;height:80px;border-radius:50%;display:grid;place-items:center;margin:0 auto 24px;font-size:36px}
.auth-icon.success{background:rgba(47,184,180,.15);border:2px solid var(--teal);color:var(--teal)}
.auth-icon.error{background:rgba(255,122,154,.12);border:2px solid #FF7A9A;color:#FF7A9A}
.auth-icon.info{background:rgba(237,27,118,.15);border:2px solid var(--magenta);color:var(--magenta)}
h1{font-size:24px;margin:0 0 12px}
p{color:var(--muted);margin:0 0 24px;line-height:1.6}
.btn-group{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-icon <?php echo $type; ?>">
      <?php if ($type === 'success'): ?><svg aria-hidden="true"><use href="#i-shield"/></svg>
      <?php elseif ($type === 'error'): ?><svg aria-hidden="true"><use href="#i-trash"/></svg>
      <?php else: ?><svg aria-hidden="true"><use href="#i-clock"/></svg><?php endif; ?>
    </div>
    <h1><?php echo $type === 'success' ? 'Verified!' : ($type === 'error' ? 'Verification Failed' : 'Verifying...'); ?></h1>
    <p><?php echo e($message); ?></p>
    <div class="btn-group">
      <a class="btn btn-primary" href="/auth/login/"><span>Sign In</span></a>
      <a class="btn btn-ghost" href="/"><span>Back to Home</span></a>
    </div>
  </div>
</div>
</body></html>