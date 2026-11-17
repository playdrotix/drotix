<?php
require __DIR__ . '/../../includes/auth.php';

$user = current_user();
$suspendedUntil = '';
$reason = '';

if ($user) {
    // Get full user data for suspension details
    $fullUser = find_user_by_id($user['id']);
    if ($fullUser) {
        $reason = $fullUser['suspension_reason'] ?? '';
        if (!empty($fullUser['suspended_until'])) {
            $ts = strtotime($fullUser['suspended_until']);
            $suspendedUntil = $ts ? date('d M Y, H:i', $ts) : '';
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account Suspended · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(480px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3);text-align:center}
.auth-icon{width:80px;height:80px;border-radius:50%;display:grid;place-items:center;margin:0 auto 24px;font-size:36px;background:rgba(255,194,77,.15);border:2px solid var(--gold);color:var(--gold)}
h1{font-size:24px;margin:0 0 12px}
p{color:var(--muted);margin:0 0 16px;line-height:1.6}
.suspension-info{background:var(--bg);border:1px solid var(--border2);border-radius:12px;padding:20px;margin:20px 0;text-align:left}
.suspension-info h3{margin:0 0 12px;font-size:14px;color:var(--gold);text-transform:uppercase;letter-spacing:2px}
.suspension-info .row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border2);font-size:14px}
.suspension-info .row:last-child{border-bottom:none}
.suspension-info .label{color:var(--muted)}
.suspension-info .value{color:var(--text);font-weight:600}
.btn-group{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-icon"><svg aria-hidden="true"><use href="#i-clock"/></svg></div>
    <h1>Account Temporarily Suspended</h1>
    <p>Your account has been suspended. You'll be able to access it again after the suspension period ends.</p>

    <div class="suspension-info">
      <h3>Suspension Details</h3>
      <?php if ($reason): ?>
      <div class="row"><span class="label">Reason</span><span class="value"><?php echo e($reason); ?></span></div>
      <?php endif; ?>
      <?php if ($suspendedUntil): ?>
      <div class="row"><span class="label">Expires</span><span class="value"><?php echo e($suspendedUntil); ?></span></div>
      <?php else: ?>
      <div class="row"><span class="label">Duration</span><span class="value">Permanent (contact support)</span></div>
      <?php endif; ?>
    </div>

    <p style="font-size:13px">If you believe this is a mistake, you can <a href="/appeal/" style="color:var(--pink);font-weight:600">submit an appeal</a>.</p>

    <div class="btn-group">
      <a class="btn btn-ghost" href="/auth/logout/"><span>Sign Out</span></a>
      <a class="btn btn-primary" href="/contact/"><span>Contact Support</span></a>
    </div>
  </div>
</div>
</body></html>