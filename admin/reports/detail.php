<?php
require __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/bootstrap.php';

$reportId = $_GET['id'] ?? '';
if (!$reportId) { header('Location: /reports/'); exit; }

list($rows, , $r) = sb_select(tbl('user_reports'), array('id' => 'eq.' . $reportId, 'reporter_id' => 'eq.' . $user['id']), 1, 0);
if (!sb_ok($r) || empty($rows)) { header('Location: /reports/'); exit; }
$report = $rows[0];

// Get messages
list($messages, , ) = sb_select(tbl('report_messages'), array('report_id' => 'eq.' . $reportId, 'order' => 'created_at.asc'), 100, 0);

// Mark admin messages as read by user
if ($messages) {
    $unreadAdminIds = array();
    foreach ($messages as $msg) {
        if ($msg['is_admin'] && !$msg['read_by_user']) { $unreadAdminIds[] = $msg['id']; }
    }
    if ($unreadAdminIds) {
        sb_update(tbl('report_messages'), array('id' => 'in.(' . implode(',', $unreadAdminIds) . ')'), array('read_by_user' => true));
    }
}

// Send reply
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $message = clean_str($_POST['message'] ?? '', 2000);
    if (strlen($message) < 2) { $error = 'Message too short.'; }
    else {
        $r = sb_insert(tbl('report_messages'), array(
            'report_id' => $reportId,
            'user_id' => $user['id'],
            'message' => $message,
            'is_admin' => false,
        ));
        if (sb_ok($r)) {
            // Notify assigned admin
            $success = 'Reply sent. Admin will be notified.';
            // Refresh messages
            list($messages, , ) = sb_select(tbl('report_messages'), array('report_id' => 'eq.' . $reportId, 'order' => 'created_at.asc'), 100, 0);
        } else {
            $error = 'Failed to send reply.';
        }
    }
}

$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Report #<?php echo e(substr($report['id'], 0, 8)); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.report-detail{padding:60px 0;min-height:100vh}
.page-head{margin-bottom:40px}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.report-header{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;margin-bottom:24px}
.report-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:16px}
.report-meta-item{padding:16px;background:var(--bg);border:1px solid var(--border2);border-radius:12px}
.report-meta-label{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin-bottom:4px}
.report-meta-value{font-weight:800;color:var(--text)}
.report-status{display:inline-block;padding:6px 14px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid}
.report-status.open{color:var(--mageta);border-color:var(--mageta)}
.report-status.investigating{color:var(--gold);border-color:var(--gold)}
.report-status.resolved{color:var(--teal);border-color:var(--teal)}
.report-status.rejected{color:var(--muted);border-color:var(--border2)}
.report-status.escalated{color:#FF7A9A;border-color:#FF7A9A}
.priority{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;text-transform:uppercase}
.priority.low{color:var(--muted);background:rgba(23,23,28,.5)}
.priority.normal{color:var(--teal);background:rgba(47,184,180,.15)}
.priority.high{color:var(--gold);background:rgba(255,194,77,.15)}
.priority.urgent{color:#FF7A9A;background:rgba(255,122,154,.12)}
.messages{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.msg{padding:20px 24px;border-bottom:1px solid var(--border2);display:flex;gap:16px}
.msg:last-child{border-bottom:none}
.msg.admin{background:rgba(237,27,118,.05)}
.msg-avatar{width:40px;height:40px;border-radius:50%;border:2px solid var(--border2);background:var(--bg);display:grid;place-items:center;flex:none;overflow:hidden}
.msg-avatar img{width:100%;height:100%;object-fit:cover}
.msg-avatar svg{width:20px;height:20px;color:var(--text)}
.msg.admin .msg-avatar{border-color:var(--mageta)}
.msg-content{flex:1}
.msg-header{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.msg-sender{font-weight:800;font-size:14px}
.msg-sender.admin{color:var(--mageta)}
.msg-time{font-size:11px;color:var(--muted)}
.msg-text{color:var(--text);line-height:1.6;white-space:pre-wrap}
.reply-form{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;margin-top:24px}
.reply-form textarea{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif;min-height:100px;resize:vertical;margin-bottom:16px}
.reply-form textarea:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Report #<?php echo e(substr($report['id'], 0, 8)); ?></span><a href="/reports/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">All Reports</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="report-detail">
<div class="container">
  <div class="page-head reveal">
    <span class="tag">Support Ticket</span>
    <h1>Report #<?php echo e(substr($report['id'], 0, 8)); ?></h1>
    <p class="lead"><?php echo e($report['subject']); ?></p>
  </div>

  <div class="report-header reveal">
    <div class="report-meta">
      <div class="report-meta-item"><div class="report-meta-label">Type</div><div class="report-meta-value"><?php echo e(ucfirst(str_replace('_', ' ', $report['type']))); ?></div></div>
      <div class="report-meta-item"><div class="report-meta-label">Priority</div><div class="report-meta-value"><span class="priority <?php echo e($report['priority']); ?>"><?php echo e($report['priority']); ?></span></div></div>
      <div class="report-meta-item"><div class="report-meta-label">Status</div><div class="report-meta-value"><span class="report-status <?php echo e($report['status']); ?>"><?php echo e(ucfirst($report['status'])); ?></span></div></div>
      <div class="report-meta-item"><div class="report-meta-label">Created</div><div class="report-meta-value"><?php echo date('d M Y, H:i', strtotime($report['created_at'])); ?></div></div>
      <?php if ($report['reported_user_id']): ?>
      <div class="report-meta-item"><div class="report-meta-label">Reported User</div><div class="report-meta-value"><code><?php echo e($report['reported_user_id']); ?></code></div></div>
      <?php endif; ?>
      <?php if ($report['resolved_at']): ?>
      <div class="report-meta-item"><div class="report-meta-label">Resolved</div><div class="report-meta-value"><?php echo date('d M Y, H:i', strtotime($report['resolved_at'])); ?></div></div>
      <?php endif; ?>
    </div>

    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border2)">
      <strong style="color:var(--muted);font-size:13px">Description:</strong>
      <p style="margin:8px 0 0;color:var(--text);white-space:pre-wrap"><?php echo e($report['description']); ?></p>
      <?php if ($report['evidence']): ?>
      <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border2)">
        <strong style="color:var(--muted);font-size:13px">Evidence:</strong>
        <p style="margin:8px 0 0;color:var(--text);word-break:break-all"><?php echo e($report['evidence']); ?></p>
      </div>
      <?php endif; ?>
      <?php if ($report['resolution']): ?>
      <div style="margin-top:16px;padding:16px;background:rgba(47,184,180,.1);border:1px solid var(--teal);border-radius:12px">
        <strong style="color:var(--teal)">Resolution:</strong>
        <p style="margin:8px 0 0;color:var(--text)"><?php echo e($report['resolution']); ?></p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <section class="section reveal">
    <h2 style="font-size:20px;margin:0 0 20px">Conversation</h2>
    <div class="messages">
      <?php if ($messages): foreach ($messages as $msg):
        $sender = find_user_by_id($msg['user_id']);
        $isAdmin = $msg['is_admin'];
      ?>
      <div class="msg <?php echo $isAdmin ? 'admin' : ''; ?>">
        <div class="msg-avatar">
          <?php if ($sender && $sender['avatar_url']): ?><img src="<?php echo e($sender['avatar_url']); ?>" alt=""><?php else: ?><svg aria-hidden="true"><use href="<?php echo $isAdmin ? '#i-shield' : '#i-bot'; ?>"/></svg><?php endif; ?>
        </div>
        <div class="msg-content">
          <div class="msg-header">
            <span class="msg-sender <?php echo $isAdmin ? 'admin' : ''; ?>">
              <?php echo $isAdmin ? 'Support Team' : 'You'; ?>
              <?php if ($isAdmin && $sender): ?>(<?php echo e($sender['username']); ?>)<?php endif; ?>
            </span>
            <span class="msg-time"><?php echo date('d M Y, H:i', strtotime($msg['created_at'])); ?></span>
            <?php if ($isAdmin && !$msg['read_by_user']): ?><span style="font-size:10px;padding:2px 6px;background:var(--gold);color:var(--bg);border-radius:4px">NEW</span><?php endif; ?>
          </div>
          <div class="msg-text"><?php echo e($msg['message']); ?></div>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div class="msg" style="justify-content:center;color:var(--muted);padding:40px">No messages yet. Be the first to reply.</div>
      <?php endif; ?>
    </div>

    <?php if (in_array($report['status'], array('open', 'investigating'))): ?>
    <div class="reply-form">
      <h3 style="font-size:16px;margin:0 0 16px">Reply to Support</h3>
      <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
      <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>
      <form method="post" novalidate>
        <?php echo csrf_field(); ?>
        <textarea name="message" required maxlength="2000" placeholder="Type your reply..."></textarea>
        <button class="btn btn-primary" type="submit"><span>Send Reply</span></button>
      </form>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:20px;color:var(--muted)">
      This ticket is <b><?php echo e(ucfirst($report['status'])); ?></b>. You cannot reply further.
    </div>
    <?php endif; ?>
  </section>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>