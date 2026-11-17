<?php
require __DIR__ . '/../../includes/auth.php';
$user = require_login();

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../../includes/bootstrap.php';

// Get fresh user data
$fullUser = find_user_by_id($user['id']);
if ($fullUser) {
    $user = array_merge($user, array(
        'coins' => $fullUser['coins'] ?? 1000,
        'rating' => $fullUser['rating'] ?? 1000,
        'total_wins' => $fullUser['total_wins'] ?? 0,
        'total_losses' => $fullUser['total_losses'] ?? 0,
        'total_draws' => $fullUser['total_draws'] ?? 0,
        'avatar_url' => $fullUser['avatar_url'] ?? null,
        'status' => $fullUser['status'] ?? 'active',
    ));
    // Update session
    $_SESSION['user_coins'] = $user['coins'];
    $_SESSION['user_rating'] = $user['rating'];
}

// Get recent tournaments
list($tourneys, , ) = sb_select(tbl('tournaments'), array(
    'status' => 'in.("registration_open","registration_closed","in_progress")',
    'order' => 'starts_at.asc',
    'limit' => 5,
), 5, 0);

// Get user's tournament participations
list($myTourneys, , ) = sb_select(tbl('tourney_participants'), array(
    'user_id' => 'eq.' . $user['id'],
    'order' => 'created_at.desc',
    'limit' => 5,
), 5, 0);

// Get user's private rooms
list($myRooms, , ) = sb_select(tbl('private_rooms'), array(
    'host_id' => 'eq.' . $user['id'],
    'order' => 'created_at.desc',
    'limit' => 5,
), 5, 0);

// Get unread notifications
$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($pageTitle); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.dashboard{padding:60px 0;min-height:100vh}
.dash-head{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:40px;flex-wrap:wrap}
.dash-head h1{font-size:32px}
.user-card{display:flex;align-items:center;gap:20px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px}
.user-avatar{width:80px;height:80px;border-radius:50%;background:var(--bg);border:3px solid var(--magenta);display:grid;place-items:center;overflow:hidden;flex:none}
.user-avatar img{width:100%;height:100%;object-fit:cover}
.user-info h2{font-size:22px;margin:0 0 4px}
.user-meta{display:flex;gap:24px;flex-wrap:wrap;margin-top:8px;color:var(--muted);font-size:14px}
.user-meta b{color:var(--text)}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-top:24px}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px;text-align:center}
.stat .num{font-size:28px;font-weight:800;color:var(--magenta)}
.stat .lbl{color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:1px}
.section{margin-top:48px}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.section-head h2{font-size:20px}
.tbl{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.tbl table{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);font-size:14px}
.tbl th{color:var(--teal);font-size:12px;letter-spacing:2px;text-transform:uppercase;background:var(--surface2)}
.tbl tr:last-child td{border-bottom:none}
.tbl td .status{display:inline-block;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid}
.tbl td .status.active{color:var(--teal);border-color:var(--teal)}
.tbl td .status.pending{color:var(--gold);border-color:var(--gold)}
.tbl td .status.completed{color:var(--muted);border-color:var(--border2)}
.badge.coin{color:var(--gold);font-weight:800}
.empty{padding:40px;text-align:center;color:var(--muted)}
.btn-sm{height:40px;padding:0 16px;font-size:12px}
.notif-bell{position:relative}
.notif-bell .cnt{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;border-radius:99px;background:var(--magenta);color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 5px}
@media(max-width:768px){.user-card{flex-direction:column;text-align:center}.user-meta{justify-content:center}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Logged in as <b><?php echo e($user['username']); ?></b></span><a href="/auth/logout/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Sign Out</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/" aria-current="page">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="dashboard">
<div class="container">
  <div class="dash-head">
    <h1>Dashboard</h1>
    <div class="user-card">
      <div class="user-avatar">
        <?php if ($user['avatar_url']): ?>
          <img src="<?php echo e($user['avatar_url']); ?>" alt="<?php echo e($user['username']); ?>">
        <?php else: ?>
          <svg width="40" height="40" aria-hidden="true"><use href="#i-bot"/></svg>
        <?php endif; ?>
      </div>
      <div class="user-info">
        <h2><?php echo e($user['username']); ?></h2>
        <div class="user-meta">
          <span><b><?php echo e($user['coins']); ?></b> <span class="badge coin">🪙 Coins</span></span>
          <span><b><?php echo e($user['rating']); ?></b> Rating</span>
          <span><b><?php echo e($user['total_wins']); ?></b>W / <b><?php echo e($user['total_losses']); ?></b>L / <b><?php echo e($user['total_draws']); ?></b>D</span>
        </div>
      </div>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat"><div class="num"><?php echo e($user['total_wins']); ?></div><div class="lbl">Wins</div></div>
    <div class="stat"><div class="num"><?php echo e($user['total_losses']); ?></div><div class="lbl">Losses</div></div>
    <div class="stat"><div class="num"><?php echo e($user['total_draws']); ?></div><div class="lbl">Draws</div></div>
    <div class="stat"><div class="num"><?php echo $user['total_wins'] + $user['total_losses'] + $user['total_draws'] > 0 ? round($user['total_wins'] / ($user['total_wins'] + $user['total_losses'] + $user['total_draws']) * 100) : 0; ?>%</div><div class="lbl">Win Rate</div></div>
  </div>

  <section class="section">
    <div class="section-head"><h2>Upcoming Tournaments</h2><a class="btn btn-ghost btn-sm" href="/tournaments/">View All</a></div>
    <div class="tbl">
      <table><thead><tr><th>Tournament</th><th>Status</th><th>Starts</th><th>Entry</th><th>Prize</th><th></th></tr></thead><tbody>
      <?php if ($tourneys): foreach ($tourneys as $t): ?>
        <tr>
          <td><b><?php echo e($t['name']); ?></b></td>
          <td><span class="status <?php echo e($t['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $t['status']))); ?></span></td>
          <td><?php echo $t['starts_at'] ? date('d M Y, H:i', strtotime($t['starts_at'])) : 'TBA'; ?></td>
          <td><?php echo $t['entry_fee'] > 0 ? e($t['entry_fee']) . ' 🪙' : 'Free'; ?></td>
          <td><?php echo $t['prize_pool'] > 0 ? e($t['prize_pool']) . ' 🪙' : '—'; ?></td>
          <td><a class="btn btn-primary btn-sm" href="/tournaments/?slug=<?php echo e($t['slug']); ?>">Details</a></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6" class="empty">No upcoming tournaments</td></tr>
      <?php endif; ?>
      </tbody></table>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><h2>My Tournament Registrations</h2><a class="btn btn-ghost btn-sm" href="/dashboard/tournaments/">View All</a></div>
    <div class="tbl">
      <table><thead><tr><th>Tournament</th><th>Status</th><th>Registered</th><th>Position</th><th></th></tr></thead><tbody>
      <?php if ($myTourneys): foreach ($myTourneys as $mt): ?>
        <tr>
          <td><?php echo e($mt['tournament_id']); ?> <!-- Would need join to get name --></td>
          <td><span class="status <?php echo e($mt['status']); ?>"><?php echo e(ucfirst($mt['status'])); ?></span></td>
          <td><?php echo date('d M Y', strtotime($mt['created_at'])); ?></td>
          <td><?php echo $mt['final_position'] ? '#' . $mt['final_position'] : '—'; ?></td>
          <td><a class="btn btn-ghost btn-sm" href="/tournaments/?id=<?php echo e($mt['tournament_id']); ?>">View</a></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5" class="empty">No tournament registrations yet</td></tr>
      <?php endif; ?>
      </tbody></table>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><h2>My Private Rooms</h2><a class="btn btn-primary btn-sm" href="/rooms/create/">Create Room</a></div>
    <div class="tbl">
      <table><thead><tr><th>Room</th><th>Code</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
      <?php if ($myRooms): foreach ($myRooms as $r): ?>
        <tr>
          <td><b><?php echo e($r['name'] ?? 'Unnamed Room'); ?></b></td>
          <td><code><?php echo e($r['room_code']); ?></code></td>
          <td><span class="status <?php echo e($r['status']); ?>"><?php echo e(ucfirst($r['status'])); ?></span></td>
          <td><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
          <td><a class="btn btn-primary btn-sm" href="/rooms/?code=<?php echo e($r['room_code']); ?>">Enter</a></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5" class="empty">No rooms created yet</td></tr>
      <?php endif; ?>
      </tbody></table>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><h2>Quick Actions</h2></div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <a class="btn btn-primary" href="/rooms/create/"><span>+ Create Private Room</span></a>
      <a class="btn btn-ghost" href="/tournaments/"><span>Browse Tournaments</span></a>
      <a class="btn btn-ghost" href="/appeal/"><span>Submit Appeal</span></a>
      <a class="btn btn-ghost" href="/reports/"><span>My Reports</span></a>
      <a class="btn btn-ghost" href="/notifications/"><span>View Notifications</span></a>
      <a class="btn btn-ghost" href="/settings/"><span>Account Settings</span></a>
    </div>
  </section>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>