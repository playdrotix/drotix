<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

$code = $_GET['code'] ?? '';
$slug = $_GET['slug'] ?? '';

$user = current_user();
$unreadCount = $user ? get_unread_notification_count($user['id']) : 0;

// If code or slug provided, join room
if ($code || $slug) {
    $filter = $code ? array('room_code' => 'eq.' . strtoupper($code)) : array('slug' => 'eq.' . $slug);
    list($rows, , $r) = sb_select(tbl('private_rooms'), array_merge($filter, array('select' => '*')), 1, 0);
    if (sb_ok($r) && !empty($rows)) {
        $room = $rows[0];
        // Check if room is expired
        if ($room['expires_at'] && strtotime($room['expires_at']) < time()) {
            // Room expired
        } else {
            include __DIR__ . '/room.php';
            exit;
        }
    }
    // Fall through to list if not found/expired
}

// Get user's rooms
$myRooms = array();
$joinedRooms = array();
if ($user) {
    list($myRooms, , ) = sb_select(tbl('private_rooms'), array('host_id' => 'eq.' . $user['id'], 'order' => 'created_at.desc'), 20, 0);
    list($parts, , ) = sb_select(tbl('room_participants'), array('user_id' => 'eq.' . $user['id'], 'left_at' => 'is.null', 'order' => 'joined_at.desc'), 20, 0);
    foreach ($parts as $p) {
        list($rRows, , ) = sb_select(tbl('private_rooms'), array('id' => 'eq.' . $p['room_id']), 1, 0);
        if (!empty($rRows)) { $joinedRooms[] = $rRows[0]; }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Private Rooms · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.rooms-page{padding:60px 0;min-height:100vh}
.page-head{margin-bottom:40px}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.page-head .lead{max-width:60ch}
.join-form{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;margin-bottom:40px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.join-form input{flex:1;min-width:200px;background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif}
.join-form input:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.join-form .btn{height:54px}
.section{margin-top:48px}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.section-head h2{font-size:20px}
.room-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px}
.room-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;display:flex;flex-direction:column;transition:transform .25s,border-color .25s}
.room-card:hover{transform:translateY(-4px);border-color:var(--mageta)}
.room-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
.room-name{font-weight:800;font-size:16px;flex:1}
.room-code{font-family:monospace;background:var(--bg);border:1px solid var(--border2);border-radius:8px;padding:4px 10px;font-size:13px;font-weight:800;color:var(--mageta);flex:none}
.room-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;font-size:13px;color:var(--muted)}
.room-meta span{display:flex;align-items:center;gap:6px}
.room-meta b{color:var(--text)}
.room-status{display:inline-block;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid;margin-bottom:16px}
.room-status.waiting{color:var(--gold);border-color:var(--gold)}
.room-status.in_progress{color:var(--mageta);border-color:var(--mageta);animation:pulse 2s infinite}
.room-status.completed{color:var(--teal);border-color:var(--teal)}
.room-status.abandoned{color:var(--muted);border-color:var(--border2)}
.room-footer{display:flex;gap:12px;margin-top:auto}
.room-footer .btn{flex:1}
.share-btn{background:var(--surface);border:1px solid var(--border2);color:var(--text)}
.share-btn:hover{border-color:var(--teal);color:var(--teal)}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-state svg{width:60px;height:60px;margin-bottom:16px;opacity:.5}
@media(max-width:768px){.room-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Private Rooms</span><?php if ($user): ?><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a><?php else: ?><a href="/auth/login/?return=/rooms/" class="btn btn-primary" style="height:36px;padding:0 16px;font-size:12px">Sign In</a><?php endif; ?></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/" aria-current="page">Private Rooms</a></li><?php if ($user): ?><li><a href="/dashboard/">Dashboard</a></li><?php endif; ?></ul>
<?php if ($user): ?>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<?php else: ?>
<a class="btn btn-primary" href="/auth/login/?return=/rooms/"><span>Sign In</span></a>
<?php endif; ?>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="rooms-page">
<div class="container">
  <div class="page-head reveal">
    <span class="tag">Private Matches</span>
    <h1>Private Rooms</h1>
    <p class="lead">Create a private room to play with friends, or join a room using a code or link</p>
  </div>

  <form class="join-form reveal" method="get" action="/rooms/">
    <input type="text" name="code" placeholder="Enter room code (e.g. ABC123)" style="text-transform:uppercase" maxlength="6" pattern="[A-Z0-9]{6}">
    <button class="btn btn-primary" type="submit"><span>Join Room</span></button>
  </form>

  <?php if ($user): ?>
  <section class="section reveal">
    <div class="section-head"><h2>Create New Room</h2><a class="btn btn-primary" href="/rooms/create/"><span>+ Create Room</span></a></div>
    <div class="room-grid">
      <article class="room-card" style="border:2px dashed var(--border2);background:transparent;display:flex;align-items:center;justify-content:center;min-height:200px">
        <div style="text-align:center">
          <svg width="48" height="48" aria-hidden="true" style="color:var(--muted)"><use href="#i-gift"/></svg>
          <h3 style="margin:16px 0 8px;color:var(--text)">Create a Room</h3>
          <p style="color:var(--muted);font-size:14px">Set up a private match with custom settings</p>
        </div>
      </article>
      <?php if ($myRooms): foreach ($myRooms as $room): ?>
      <article class="room-card">
        <div class="room-header">
          <span class="room-name"><?php echo e($room['name'] ?? 'Unnamed Room'); ?></span>
          <span class="room-code"><?php echo e($room['room_code']); ?></span>
        </div>
        <div class="room-meta">
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-target"/></svg> <b><?php echo (int)$room['board_size']; ?>×<?php echo (int)$room['board_size']; ?></b></span>
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-clock"/></svg> <b><?php echo (int)$room['turn_time_seconds']; ?>s</b></span>
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-users"/></svg> <b><?php echo (int)$room['max_players']; ?></b> players</span>
        </div>
        <span class="room-status <?php echo e($room['status']); ?>"><?php echo e(ucfirst($room['status'])); ?></span>
        <div class="room-footer">
          <a class="btn btn-primary" href="/rooms/?code=<?php echo e($room['room_code']); ?>">Enter</a>
          <button class="btn share-btn" onclick="shareRoom('<?php echo e($room['room_code']); ?>', '<?php echo e($room['slug']); ?>')"><svg width="16" height="16" aria-hidden="true"><use href="#i-link"/></svg> Share</button>
        </div>
      </article>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <?php if ($joinedRooms): ?>
  <section class="section reveal">
    <div class="section-head"><h2>Rooms You've Joined</h2></div>
    <div class="room-grid">
      <?php foreach ($joinedRooms as $room): ?>
      <article class="room-card">
        <div class="room-header">
          <span class="room-name"><?php echo e($room['name'] ?? 'Unnamed Room'); ?></span>
          <span class="room-code"><?php echo e($room['room_code']); ?></span>
        </div>
        <div class="room-meta">
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-target"/></svg> <b><?php echo (int)$room['board_size']; ?>×<?php echo (int)$room['board_size']; ?></b></span>
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-clock"/></svg> <b><?php echo (int)$room['turn_time_seconds']; ?>s</b></span>
        </div>
        <span class="room-status <?php echo e($room['status']); ?>"><?php echo e(ucfirst($room['status'])); ?></span>
        <div class="room-footer">
          <a class="btn btn-primary" href="/rooms/?code=<?php echo e($room['room_code']); ?>">Enter</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php else: ?>
  <div class="empty-state reveal">
    <svg aria-hidden="true"><use href="#i-gift"/></svg>
    <h3>Sign in to create rooms</h3>
    <p>Create private matches, invite friends, and track your room history</p>
    <a class="btn btn-primary" href="/auth/login/?return=/rooms/create/" style="margin-top:16px">Sign In</a>
  </div>
  <?php endif; ?>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script>
function shareRoom(code, slug) {
  const url = window.location.origin + '/rooms/?code=' + code;
  if (navigator.share) {
    navigator.share({title: 'Drotix Private Room: ' + code, text: 'Join my private Tic Tac Toe room!', url: url});
  } else {
    navigator.clipboard.writeText(url).then(() => {
      alert('Room link copied to clipboard!');
    });
  }
}
</script>
<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>