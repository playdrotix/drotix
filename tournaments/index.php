<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

$status = $_GET['status'] ?? '';
$slug = $_GET['slug'] ?? '';

$pageTitle = 'Tournaments';

// If slug provided, show tournament details
if ($slug) {
    list($rows, , $r) = sb_select(tbl('tournaments'), array('slug' => 'eq.' . $slug, 'select' => '*'), 1, 0);
    if (sb_ok($r) && !empty($rows)) {
        include __DIR__ . '/detail.php';
        exit;
    }
    // Fall through to list if not found
}

// Get tournaments
$query = array('order' => 'starts_at.asc');
if (in_array($status, array('registration_open', 'registration_closed', 'in_progress', 'completed', 'cancelled'), true)) {
    $query['status'] = 'eq.' . $status;
}
list($tourneys, , ) = sb_select(tbl('tournaments'), $query, 50, 0);

// Get user's registrations if logged in
$user = current_user();
$myRegistrations = array();
if ($user) {
    list($regs, , ) = sb_select(tbl('tourney_participants'), array('user_id' => 'eq.' . $user['id'], 'select' => 'tournament_id,status'), 50, 0);
    foreach ($regs as $reg) { $myRegistrations[$reg['tournament_id']] = $reg['status']; }
}

$unreadCount = $user ? get_unread_notification_count($user['id']) : 0;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($pageTitle); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.tournaments-page{padding:60px 0;min-height:100vh}
.page-head{margin-bottom:40px}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.filters{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
.filter-btn{padding:10px 20px;border-radius:99px;border:1px solid var(--border2);background:var(--surface);color:var(--text);font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:1px;cursor:pointer;transition:all .2s;text-decoration:none}
.filter-btn:hover,.filter-btn.active{border-color:var(--magenta);color:var(--magenta);transform:translateY(-2px)}
.tourney-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:24px}
.tourney-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:transform .25s,border-color .25s;display:flex;flex-direction:column}
.tourney-card:hover{transform:translateY(-4px);border-color:var(--magenta)}
.tourney-banner{width:100%;height:160px;object-fit:cover;background:var(--bg)}
.tourney-body{padding:24px;display:flex;flex-direction:column;flex:1}
.tourney-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;font-size:13px;color:var(--muted)}
.tourney-meta span{display:flex;align-items:center;gap:6px}
.tourney-meta b{color:var(--text)}
.tourney-title{font-size:20px;font-weight:800;letter-spacing:.5px;margin:0 0 8px}
.tourney-desc{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:20px;flex:1}
.tourney-status{display:inline-block;padding:6px 12px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid;margin-bottom:16px}
.tourney-status.registration_open{color:var(--teal);border-color:var(--teal)}
.tourney-status.registration_closed{color:var(--gold);border-color:var(--gold)}
.tourney-status.in_progress{color:var(--magenta);border-color:var(--magenta);animation:pulse 2s infinite}
.tourney-status.completed{color:var(--muted);border-color:var(--border2)}
.tourney-status.cancelled{color:#FF7A9A;border-color:#FF7A9A}
.tourney-footer{display:flex;gap:12px;align-items:center;padding-top:16px;border-top:1px solid var(--border2)}
.tourney-entry{font-weight:800;color:var(--gold)}
.tourney-prize{font-weight:800;color:var(--teal);margin-left:auto}
.tourney-btn{flex:1;text-align:center}
.empty-state{text-align:center;padding:80px 20px;color:var(--muted)}
.empty-state svg{width:80px;height:80px;margin-bottom:20px;opacity:.5}
@media(max-width:768px){.tourney-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Tournaments & Competitions</span><?php if ($user): ?><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a><?php else: ?><a href="/auth/login/?return=/tournaments/" class="btn btn-primary" style="height:36px;padding:0 16px;font-size:12px">Sign In to Join</a><?php endif; ?></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/" aria-current="page">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><?php if ($user): ?><li><a href="/dashboard/">Dashboard</a></li><?php endif; ?></ul>
<?php if ($user): ?>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<?php else: ?>
<a class="btn btn-primary" href="/auth/login/?return=/tournaments/"><span>Sign In</span></a>
<?php endif; ?>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="tournaments-page">
<div class="container">
  <div class="page-head reveal">
    <span class="tag">The Arena</span>
    <h1>Tournaments</h1>
    <p class="lead">Compete in structured brackets, climb the ranks, and win coin prizes</p>
    <div class="filters">
      <a class="filter-btn<?php echo $status === '' ? ' active' : ''; ?>" href="/tournaments/">All</a>
      <a class="filter-btn<?php echo $status === 'registration_open' ? ' active' : ''; ?>" href="/tournaments/?status=registration_open">Open for Registration</a>
      <a class="filter-btn<?php echo $status === 'registration_closed' ? ' active' : ''; ?>" href="/tournaments/?status=registration_closed">Registration Closed</a>
      <a class="filter-btn<?php echo $status === 'in_progress' ? ' active' : ''; ?>" href="/tournaments/?status=in_progress">In Progress</a>
      <a class="filter-btn<?php echo $status === 'completed' ? ' active' : ''; ?>" href="/tournaments/?status=completed">Completed</a>
    </div>
  </div>

  <div class="tourney-grid">
    <?php if ($tourneys): foreach ($tourneys as $t): ?>
    <article class="tourney-card reveal">
      <?php if ($t['banner_url']): ?>
        <img class="tourney-banner" src="<?php echo e($t['banner_url']); ?>" alt="<?php echo e($t['name']); ?> banner">
      <?php else: ?>
        <div class="tourney-banner" style="display:grid;place-items:center;background:linear-gradient(135deg,rgba(237,27,118,.2),rgba(47,184,180,.1))"><svg width="60" height="60" aria-hidden="true"><use href="#i-trophy"/></svg></div>
      <?php endif; ?>
      <div class="tourney-body">
        <div class="tourney-meta">
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-users"/></svg> <b><?php echo (int)$t['max_players']; ?></b> players</span>
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-target"/></svg> <b><?php echo (int)$t['board_size']; ?>×<?php echo (int)$t['board_size']; ?></b> board</span>
          <span><svg width="14" height="14" aria-hidden="true"><use href="#i-clock"/></svg> <b><?php echo (int)$t['turn_time_seconds']; ?>s</b> turns</span>
        </div>
        <h3 class="tourney-title"><?php echo e($t['name']); ?></h3>
        <?php if ($t['description']): ?>
        <p class="tourney-desc"><?php echo e($t['description']); ?></p>
        <?php endif; ?>
        <span class="tourney-status <?php echo e($t['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $t['status']))); ?></span>
        <div class="tourney-footer">
          <span class="tourney-entry"><?php echo $t['entry_fee'] > 0 ? e($t['entry_fee']) . ' 🪙 entry' : 'Free entry'; ?></span>
          <span class="tourney-prize"><?php echo $t['prize_pool'] > 0 ? e($t['prize_pool']) . ' 🪙 prize' : '—'; ?></span>
        </div>
        <a class="btn btn-primary tourney-btn" href="/tournaments/?slug=<?php echo e($t['slug']); ?>">
          <?php
            if ($t['status'] === 'registration_open') echo 'Register';
            elseif ($t['status'] === 'in_progress') echo 'Watch Live';
            elseif ($t['status'] === 'completed') echo 'View Results';
            else echo 'View Details';
          ?>
        </a>
      </div>
    </article>
    <?php endforeach; else: ?>
    <div class="empty-state reveal">
      <svg aria-hidden="true"><use href="#i-trophy"/></svg>
      <h3>No tournaments found</h3>
      <p>Check back soon for new competitions!</p>
    </div>
    <?php endif; ?>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>