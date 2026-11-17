<?php
require __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/bootstrap.php';

$error = '';
$success = '';

// Get user's existing appeal
$existingAppeal = null;
list($appeals, , ) = sb_select(tbl('suspension_appeals'), array('user_id' => 'eq.' . $user['id'], 'order' => 'created_at.desc'), 1, 0);
if (!empty($appeals)) { $existingAppeal = $appeals[0]; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingAppeal) {
    csrf_verify();

    $reason = clean_str($_POST['reason'] ?? '', 2000);
    $evidence = clean_str($_POST['evidence'] ?? '', 2000);

    if (strlen($reason) < 20) { $error = 'Please provide a detailed explanation (at least 20 characters).'; }
    elseif ($user['status'] !== 'suspended' && $user['status'] !== 'banned') { $error = 'You can only appeal if your account is suspended or banned.'; }

    if (!$error) {
        $r = sb_insert(tbl('suspension_appeals'), array(
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'reason' => $reason,
            'evidence' => $evidence,
            'status' => 'pending',
        ), array(), 'return=representation');

        if (sb_ok($r) && !empty($r['data'])) {
            // Notify admins
            // create_admin_notification('new_appeal', ...);
            $success = 'Appeal submitted successfully! Our team will review it within 48 hours.';
            $existingAppeal = $r['data'][0];
        } else {
            $error = 'Failed to submit appeal. Please try again.';
        }
    }
}

$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Suspension Appeal · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.appeal-page{padding:60px 0;min-height:100vh}
.page-head{margin-bottom:40px;text-align:center}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.page-head .lead{max-width:60ch;margin:0 auto}
.auth-box{width:min(640px,100%);margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px}
.auth-head{text-align:center;margin-bottom:28px}
.auth-head .tag{margin-bottom:12px}
.auth-head h2{font-size:24px;margin:0 0 8px}
.auth-head p{color:var(--muted);margin:0}
.form-section{margin-bottom:24px}
.form-section h3{font-size:14px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin:0 0 12px}
.form-field{display:grid;gap:6px}
.form-field label{font-weight:600;font-size:13px;color:var(--muted)}
.form-field textarea{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif;min-height:120px;resize:vertical}
.form-field textarea:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.appeal-status{background:var(--bg);border:1px solid var(--border2);border-radius:12px;padding:20px;margin-bottom:24px}
.appeal-status .status-badge{display:inline-block;padding:6px 14px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid;margin-bottom:12px}
.appeal-status .status-badge.pending{color:var(--gold);border-color:var(--gold)}
.appeal-status .status-badge.under_review{color:var(--mageta);border-color:var(--mageta)}
.appeal-status .status-badge.approved{color:var(--teal);border-color:var(--teal)}
.appeal-status .status-badge.rejected{color:#FF7A9A;border-color:#FF7A9A}
.appeal-status .detail{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border2);font-size:14px}
.appeal-status .detail:last-child{border-bottom:none}
.appeal-status .label{color:var(--muted)}
.appeal-status .value{color:var(--text);font-weight:600}
.appeal-status .admin-response{margin-top:16px;padding:16px;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:13px;line-height:1.6}
.btn-block{width:100%}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Suspension Appeal</span><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="appeal-page">
<div class="container">
  <div class="page-head reveal">
    <span class="tag">Account Appeal</span>
    <h1>Suspension Appeal</h1>
    <p class="lead">If you believe your account was suspended or banned in error, submit an appeal for review</p>
  </div>

  <div class="auth-box reveal">
    <?php if ($existingAppeal): ?>
    <div class="auth-head">
      <span class="tag" style="color:var(--gold);border-color:var(--gold)">Existing Appeal</span>
      <h2>You have a pending appeal</h2>
      <p>Our team is reviewing your case</p>
    </div>

    <div class="appeal-status">
      <span class="status-badge <?php echo e($existingAppeal['status']); ?>">
        <?php echo e(ucfirst(str_replace('_', ' ', $existingAppeal['status']))); ?>
      </span>
      <div class="detail"><span class="label">Submitted</span><span class="value"><?php echo date('d M Y, H:i', strtotime($existingAppeal['created_at'])); ?></span></div>
      <div class="detail"><span class="label">Status</span><span class="value"><?php echo e(ucfirst(str_replace('_', ' ', $existingAppeal['status']))); ?></span></div>
      <?php if ($existingAppeal['reviewed_at']): ?>
      <div class="detail"><span class="label">Reviewed</span><span class="value"><?php echo date('d M Y, H:i', strtotime($existingAppeal['reviewed_at'])); ?></span></div>
      <?php endif; ?>
      <?php if ($existingAppeal['admin_response']): ?>
      <div class="admin-response">
        <strong>Admin Response:</strong><br>
        <?php echo e($existingAppeal['admin_response']); ?>
      </div>
      <?php endif; ?>
    </div>

    <p style="color:var(--muted);text-align:center">You can only have one active appeal at a time. Please wait for the review to complete.</p>

    <?php else: ?>

    <div class="auth-head">
      <span class="tag">Submit Appeal</span>
      <h2>Explain your situation</h2>
      <p>Provide details about why you believe the suspension/ban was incorrect</p>
    </div>

    <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>

    <?php if (!$success): ?>
    <form method="post" novalidate>
      <?php echo csrf_field(); ?>

      <div class="form-section">
        <h3>Your Explanation</h3>
        <div class="form-field">
          <label>Why do you believe this suspension/ban was a mistake? *</label>
          <textarea name="reason" required maxlength="2000" placeholder="Provide a detailed explanation..."></textarea>
        </div>
        <p style="color:var(--muted);font-size:12px;margin-top:4px">Minimum 20 characters. Be specific and honest.</p>
      </div>

      <div class="form-section">
        <h3>Evidence (Optional)</h3>
        <div class="form-field">
          <label>Supporting evidence (screenshots, links, context)</label>
          <textarea name="evidence" maxlength="2000" placeholder="Paste links to screenshots, describe context, etc."></textarea>
        </div>
        <p style="color:var(--muted);font-size:12px;margin-top:4px">Links to images (imgur, gyazo, etc.) or detailed descriptions help us review faster.</p>
      </div>

      <button class="btn btn-primary btn-block" type="submit"><span>Submit Appeal</span></button>
    </form>
    <?php endif; ?>

    <p class="auth-foot"><a href="/dashboard/">Back to Dashboard</a></p>
    <?php endif; ?>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>