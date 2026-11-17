<?php
require __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/bootstrap.php';

$reportId = $_GET['id'] ?? '';
$error = '';
$success = '';

// If viewing a specific report
if ($reportId) {
    list($rows, , $r) = sb_select(tbl('user_reports'), array('id' => 'eq.' . $reportId, 'reporter_id' => 'eq.' . $user['id']), 1, 0);
    if (sb_ok($r) && !empty($rows)) {
        include __DIR__ . '/detail.php';
        exit;
    }
    header('Location: /reports/');
    exit;
}

// Create new report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$reportId) {
    csrf_verify();

    $type = clean_str($_POST['type'] ?? '', 20);
    $subject = clean_str($_POST['subject'] ?? '', 200);
    $description = clean_str($_POST['description'] ?? '', 3000);
    $evidence = clean_str($_POST['evidence'] ?? '', 2000);
    $reportedUser = clean_str($_POST['reported_user'] ?? '', 40);

    $validTypes = array('cheating', 'harassment', 'inappropriate_name', 'bug', 'payment', 'other');
    if (!in_array($type, $validTypes)) { $error = 'Invalid report type.'; }
    elseif (strlen($subject) < 5) { $error = 'Subject must be at least 5 characters.'; }
    elseif (strlen($description) < 20) { $error = 'Description must be at least 20 characters.'; }

    if (!$error) {
        $reportedUserId = null;
        if ($reportedUser) {
            $ru = find_user_by_username($reportedUser);
            if ($ru) { $reportedUserId = $ru['id']; }
        }

        $r = sb_insert(tbl('user_reports'), array(
            'reporter_id' => $user['id'],
            'reported_user_id' => $reportedUserId,
            'type' => $type,
            'subject' => $subject,
            'description' => $description,
            'evidence' => $evidence,
            'status' => 'open',
            'priority' => in_array($type, array('cheating', 'harassment')) ? 'high' : 'normal',
        ), array(), 'return=representation');

        if (sb_ok($r) && !empty($r['data'])) {
            // Notify admins
            $success = 'Report submitted successfully! Ticket ID: ' . substr($r['data'][0]['id'], 0, 8);
        } else {
            $error = 'Failed to submit report. Please try again.';
        }
    }
}

// Get user's reports
list($reports, , ) = sb_select(tbl('user_reports'), array('reporter_id' => 'eq.' . $user['id'], 'order' => 'created_at.desc'), 50, 0);

$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Reports · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.reports-page{padding:60px 0;min-height:100vh}
.page-head{margin-bottom:40px}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.report-form{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:32px;margin-bottom:40px}
.report-form .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.report-form .form-field{display:grid;gap:6px}
.report-form label{font-weight:600;font-size:13px;color:var(--muted)}
.report-form input,.report-form select,.report-form textarea{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif}
.report-form input:focus,.report-form select:focus,.report-form textarea:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.report-form textarea{min-height:120px;resize:vertical}
.report-form .btn{margin-top:16px}
.tbl{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.tbl table{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);font-size:14px}
.tbl th{color:var(--teal);font-size:12px;letter-spacing:2px;text-transform:uppercase;background:var(--surface2)}
.tbl tr:last-child td{border-bottom:none}
.tbl td .status{display:inline-block;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid}
.tbl td .status.open{color:var(--mageta);border-color:var(--mageta)}
.tbl td .status.investigating{color:var(--gold);border-color:var(--gold)}
.tbl td .status.resolved{color:var(--teal);border-color:var(--teal)}
.tbl td .status.rejected{color:var(--muted);border-color:var(--border2)}
.tbl td .status.escalated{color:#FF7A9A;border-color:#FF7A9A}
.priority{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;text-transform:uppercase}
.priority.low{color:var(--muted);background:rgba(23,23,28,.5)}
.priority.normal{color:var(--teal);background:rgba(47,184,180,.15)}
.priority.high{color:var(--gold);background:rgba(255,194,77,.15)}
.priority.urgent{color:#FF7A9A;background:rgba(255,122,154,.12)}
.empty{padding:40px;text-align:center;color:var(--muted)}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
@media(max-width:768px){.report-form .form-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Support Reports</span><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="reports-page">
<div class="container">
  <div class="page-head reveal">
    <span class="tag">Support Center</span>
    <h1>My Reports & Tickets</h1>
    <p class="lead">Submit a report or track the status of your existing tickets</p>
  </div>

  <div class="report-form reveal">
    <h2 style="font-size:20px;margin:0 0 8px">Submit New Report</h2>
    <p style="color:var(--muted);margin:0 0 24px">Report cheating, harassment, bugs, or other issues</p>

    <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?php echo csrf_field(); ?>

      <div class="form-row">
        <div class="form-field">
          <label>Report Type *</label>
          <select name="type" required>
            <option value="">Select type</option>
            <option value="cheating">Cheating / Unfair Play</option>
            <option value="harassment">Harassment / Toxic Behavior</option>
            <option value="inappropriate_name">Inappropriate Username</option>
            <option value="bug">Bug / Technical Issue</option>
            <option value="payment">Payment / Coins Issue</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-field">
          <label>Reported User (optional)</label>
          <input type="text" name="reported_user" maxlength="40" placeholder="Username (if applicable)">
        </div>
      </div>

      <div class="form-field" style="margin-top:16px">
        <label>Subject *</label>
        <input type="text" name="subject" required maxlength="200" placeholder="Brief summary of the issue">
      </div>

      <div class="form-field" style="margin-top:16px">
        <label>Description *</label>
        <textarea name="description" required maxlength="3000" placeholder="Describe the issue in detail..."></textarea>
      </div>

      <div class="form-field" style="margin-top:16px">
        <label>Evidence (optional)</label>
        <textarea name="evidence" maxlength="2000" placeholder="Links to screenshots, videos, or other evidence"></textarea>
      </div>

      <button class="btn btn-primary" type="submit"><span>Submit Report</span></button>
    </form>
  </div>

  <section class="section reveal">
    <h2 style="font-size:20px;margin:0 0 20px">Your Reports</h2>
    <div class="tbl">
      <table><thead><tr><th>Ticket ID</th><th>Type</th><th>Subject</th><th>Reported User</th><th>Priority</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
      <?php if ($reports): foreach ($reports as $rep): ?>
      <tr>
        <td><code><?php echo e(substr($rep['id'], 0, 8)); ?></code></td>
        <td><?php echo e(ucfirst(str_replace('_', ' ', $rep['type']))); ?></td>
        <td><a href="/reports/?id=<?php echo e($rep['id']); ?>" style="color:var(--pink);font-weight:600"><?php echo e($rep['subject']); ?></a></td>
        <td><?php echo $rep['reported_user_id'] ? '<code>' . e($rep['reported_user_id']) . '</code>' : '—'; ?></td>
        <td><span class="priority <?php echo e($rep['priority']); ?>"><?php echo e($rep['priority']); ?></span></td>
        <td><span class="status <?php echo e($rep['status']); ?>"><?php echo e(ucfirst($rep['status'])); ?></span></td>
        <td><?php echo date('d M Y', strtotime($rep['created_at'])); ?></td>
        <td><a class="btn btn-ghost btn-sm" href="/reports/?id=<?php echo e($rep['id']); ?>">View</a></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="8" class="empty">No reports submitted yet</td></tr>
      <?php endif; ?>
      </tbody></table>
    </div>
  </section>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>