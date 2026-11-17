<?php
require __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/bootstrap.php';

$filter = $_GET['filter'] ?? 'all'; // all, unread
$notifications = get_user_notifications($user['id'], $filter === 'unread', 50);
$unreadCount = get_unread_notification_count($user['id']);

// Mark all as read if requested
if (isset($_GET['mark_read'])) {
    mark_notifications_read($user['id']);
    header('Location: /notifications/');
    exit;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifications · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.notif-page{padding:60px 0;min-height:100vh}
.page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:40px;flex-wrap:wrap}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.filter-tabs{display:flex;gap:8px}
.filter-tab{padding:8px 16px;border-radius:99px;border:1px solid var(--border2);background:var(--surface);color:var(--text);font-weight:600;font-size:13px;text-decoration:none;transition:all .2s}
.filter-tab:hover,.filter-tab.active{border-color:var(--mageta);color:var(--mageta)}
.notif-list{display:flex;flex-direction:column;gap:12px}
.notif-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px;display:flex;gap:16px;transition:border-color .2s}
.notif-item.unread{border-color:var(--mageta);background:rgba(237,27,118,.05)}
.notif-item.read{opacity:.8}
.notif-icon{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;flex:none;background:var(--bg);border:2px solid var(--border2)}
.notif-icon.tournament{background:rgba(255,194,77,.15);border-color:var(--gold);color:var(--gold)}
.notif-icon.match{background:rgba(237,27,118,.15);border-color:var(--mageta);color:var(--mageta)}
.notif-icon.room{background:rgba(47,184,180,.15);border-color:var(--teal);color:var(--teal)}
.notif-icon.appeal{background:rgba(237,27,118,.15);border-color:var(--mageta);color:var(--mageta)}
.notif-icon.report{background:rgba(47,184,180,.15);border-color:var(--teal);color:var(--teal)}
.notif-icon.system{background:rgba(23,23,28,.5);border-color:var(--muted);color:var(--muted)}
.notif-content{flex:1;min-width:0}
.notif-title{font-weight:800;font-size:15px;margin:0 0 4px;color:var(--text)}
.notif-body{color:var(--muted);font-size:13px;line-height:1.5;margin:0 0 8px}
.notif-meta{display:flex;align-items:center;gap:12px;font-size:11px;color:var(--muted)}
.notif-time{font-weight:600}
.notif-actions{display:flex;gap:8px;flex-wrap:wrap}
.notif-btn{font-size:12px;padding:6px 12px;height:36px}
.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.empty svg{width:60px;height:60px;margin-bottom:16px;opacity:.5}
@media(max-width:768px){.page-head{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Notifications</span><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="notif-page">
<div class="container">
  <div class="page-head reveal">
    <div>
      <span class="tag">Messages</span>
      <h1>Notifications</h1>
    </div>
    <div class="filter-tabs">
      <a class="filter-tab<?php echo $filter === 'all' ? ' active' : ''; ?>" href="/notifications/?filter=all">All <span style="color:var(--muted);font-weight:400">(<?php echo count($notifications); ?>)</span></a>
      <a class="filter-tab<?php echo $filter === 'unread' ? ' active' : ''; ?>" href="/notifications/?filter=unread">Unread <span style="color:var(--gold);font-weight:400">(<?php echo $unreadCount; ?>)</span></a>
      <?php if ($unreadCount > 0): ?>
      <a class="filter-tab" href="/notifications/?mark_read=1" style="color:var(--teal);border-color:var(--teal)">Mark All Read</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="notif-list reveal">
    <?php if ($notifications): foreach ($notifications as $n): ?>
    <article class="notif-item <?php echo $n['read_at'] ? 'read' : 'unread'; ?>">
      <div class="notif-icon <?php echo e(strpos($n['type'], 'tournament') === 0 ? 'tournament' : (strpos($n['type'], 'match') === 0 ? 'match' : (strpos($n['type'], 'room') === 0 ? 'room' : (strpos($n['type'], 'appeal') === 0 ? 'appeal' : (strpos($n['type'], 'report') === 0 ? 'report' : 'system'))))); ?>">
        <svg width="20" height="20" aria-hidden="true">
          <?php if (strpos($n['type'], 'tournament') === 0): ?><use href="#i-trophy"/>
          <?php elseif (strpos($n['type'], 'match') === 0): ?><use href="#i-target"/>
          <?php elseif (strpos($n['type'], 'room') === 0): ?><use href="#i-gift"/>
          <?php elseif (strpos($n['type'], 'appeal') === 0): ?><use href="#i-shield"/>
          <?php elseif (strpos($n['type'], 'report') === 0): ?><use href="#i-mail"/>
          <?php else: ?><use href="#i-bell"/><?php endif; ?>
        </svg>
      </div>
      <div class="notif-content">
        <h3 class="notif-title"><?php echo e($n['title']); ?></h3>
        <?php if ($n['body']): ?><p class="notif-body"><?php echo e($n['body']); ?></p><?php endif; ?>
        <div class="notif-meta">
          <span class="notif-time"><?php echo date('d M Y, H:i', strtotime($n['created_at'])); ?></span>
          <?php if (!$n['read_at']): ?><span style="color:var(--gold);font-weight:600">● New</span><?php endif; ?>
        </div>
        <?php if ($n['action_url']): ?>
        <div class="notif-actions" style="margin-top:12px">
          <a class="btn btn-primary notif-btn" href="<?php echo e($n['action_url']); ?>">View</a>
          <?php if (!$n['read_at']): ?>
          <a class="btn btn-ghost notif-btn" href="/api/notifications/read/?ids[]=<?php echo e($n['id']); ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>">Mark Read</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; else: ?>
    <div class="empty">
      <svg aria-hidden="true"><use href="#i-mail"/></svg>
      <h3>No notifications</h3>
      <p>When you receive tournament updates, match invites, or admin messages, they'll appear here</p>
    </div>
    <?php endif; ?>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>