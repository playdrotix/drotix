<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
if (!$slug) { header('Location: /tournaments/'); exit; }

list($rows, , $r) = sb_select(tbl('tournaments'), array('slug' => 'eq.' . $slug, 'select' => '*'), 1, 0);
if (!sb_ok($r) || empty($rows)) { header('Location: /tournaments/'); exit; }
$t = $rows[0];

$user = current_user();
$myReg = null;
if ($user) {
    list($regs, , ) = sb_select(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $t['id'], 'user_id' => 'eq.' . $user['id']), 1, 0);
    if (!empty($regs)) { $myReg = $regs[0]; }
}

// Get participants count
$participantsCount = sb_count(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $t['id'], 'status' => 'in.("registered","confirmed","checked_in")'));

// Get bracket/matches if in progress or completed
$matches = array();
if (in_array($t['status'], array('in_progress', 'completed'))) {
    list($matches, , ) = sb_select(tbl('tourney_matches'), array('tournament_id' => 'eq.' . $t['id'], 'order' => 'round.asc,match_number.asc'), 100, 0);
}

$unreadCount = $user ? get_unread_notification_count($user['id']) : 0;
$pageTitle = $t['name'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($pageTitle); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.tourney-detail{padding:60px 0;min-height:100vh}
.tourney-header{position:relative;margin-bottom:40px}
.tourney-banner{width:100%;height:300px;object-fit:cover;border-radius:var(--r);background:var(--bg)}
.tourney-badge{position:absolute;top:20px;left:20px;padding:8px 16px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid;text-transform:uppercase;letter-spacing:1px}
.tourney-badge.registration_open{background:rgba(47,184,180,.15);color:var(--teal);border-color:var(--teal)}
.tourney-badge.registration_closed{background:rgba(255,194,77,.15);color:var(--gold);border-color:var(--gold)}
.tourney-badge.in_progress{background:rgba(237,27,118,.15);color:var(--magenta);border-color:var(--mageta);animation:pulse 2s infinite}
.tourney-badge.completed{background:rgba(23,23,28,.8);color:var(--muted);border-color:var(--border2)}
.tourney-info{margin-top:-60px;padding:0 20px 40px;position:relative;z-index:2}
.tourney-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:16px}
.tourney-title-row h1{font-size:clamp(32px,5vw,56px);margin:0}
.tourney-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.meta-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px}
.meta-label{font-size:12px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin-bottom:6px}
.meta-value{font-size:20px;font-weight:800;color:var(--text)}
.meta-value small{font-size:13px;font-weight:400;color:var(--muted)}
.tourney-desc{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;color:var(--muted);line-height:1.7}
.tourney-actions{display:flex;gap:16px;flex-wrap:wrap;margin-top:24px}
.section{margin-top:48px}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.section-head h2{font-size:20px}
.bracket{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.bracket-round{padding:20px;border-bottom:1px solid var(--border2)}
.bracket-round:last-child{border-bottom:none}
.bracket-round h3{font-size:14px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin:0 0 16px}
.bracket-matches{display:flex;flex-direction:column;gap:12px}
.bracket-match{display:grid;grid-template-columns:1fr auto 1fr;gap:16px;align-items:center;padding:16px;background:var(--bg);border-radius:12px;border:1px solid var(--border2)}
.bracket-match.completed{border-color:var(--teal)}
.bracket-match.in_progress{border-color:var(--mageta);animation:pulse 2s infinite}
.match-player{display:flex;align-items:center;gap:12px}
.match-player.winner{opacity:1}
.match-player.loser{opacity:.5}
.match-player .avatar{width:40px;height:40px;border-radius:50%;background:var(--surface);border:2px solid var(--border2);display:grid;place-items:center;overflow:hidden;flex:none}
.match-player .avatar img{width:100%;height:100%;object-fit:cover}
.match-player .info{display:flex;flex-direction:column}
.match-player .name{font-weight:800;font-size:14px}
.match-player .seed{font-size:11px;color:var(--gold);text-transform:uppercase;letter-spacing:1px}
.match-vs{color:var(--muted);font-weight:800;font-size:14px;letter-spacing:2px}
.match-score{font-weight:800;color:var(--mageta);font-size:18px}
.match-status{padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid;text-transform:uppercase}
.match-status.pending{color:var(--muted);border-color:var(--border2)}
.match-status.ready{color:var(--gold);border-color:var(--gold)}
.match-status.in_progress{color:var(--mageta);border-color:var(--mageta)}
.match-status.completed{color:var(--teal);border-color:var(--teal)}
.match-status.walkover{color:#FF7A9A;border-color:#FF7A9A}
.participants-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.participant{display:flex;align-items:center;gap:12px;padding:12px;background:var(--surface);border:1px solid var(--border);border-radius:12px}
.participant .avatar{width:40px;height:40px;border-radius:50%;background:var(--bg);border:2px solid var(--border2);display:grid;place-items:center;overflow:hidden;flex:none}
.participant .avatar img{width:100%;height:100%;object-fit:cover}
.participant .info{flex:1}
.participant .name{font-weight:800;font-size:14px}
.participant .meta{font-size:12px;color:var(--muted)}
@media(max-width:768px){.bracket-match{grid-template-columns:1fr;gap:8px}.match-vs{order:2}.tourney-info{margin-top:-40px}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span><?php echo e($t['name']); ?></span><?php if ($user): ?><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a><?php else: ?><a href="/auth/login/?return=/tournaments/?slug=<?php echo e($slug); ?>" class="btn btn-primary" style="height:36px;padding:0 16px;font-size:12px">Sign In</a><?php endif; ?></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/" aria-current="page">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><?php if ($user): ?><li><a href="/dashboard/">Dashboard</a></li><?php endif; ?></ul>
<?php if ($user): ?>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<?php else: ?>
<a class="btn btn-primary" href="/auth/login/?return=/tournaments/?slug=<?php echo e($slug); ?>"><span>Sign In</span></a>
<?php endif; ?>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="tourney-detail">
<div class="container">
  <div class="tourney-header">
    <?php if ($t['banner_url']): ?>
      <img class="tourney-banner" src="<?php echo e($t['banner_url']); ?>" alt="<?php echo e($t['name']); ?> banner">
    <?php else: ?>
      <div class="tourney-banner" style="display:grid;place-items:center;background:linear-gradient(135deg,rgba(237,27,118,.2),rgba(47,184,180,.1))"><svg width="80" height="80" aria-hidden="true"><use href="#i-trophy"/></svg></div>
    <?php endif; ?>
    <span class="tourney-badge <?php echo e($t['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $t['status']))); ?></span>
  </div>

  <div class="tourney-info">
    <div class="tourney-title-row">
      <h1><?php echo e($t['name']); ?></h1>
      <div class="tourney-actions">
        <?php if ($user): ?>
          <?php if ($myReg): ?>
            <a class="btn btn-ghost" href="/tournaments/match/?id=<?php echo e($myReg['tournament_id']); ?>&user=<?php echo e($user['id']); ?>">My Matches</a>
            <?php if ($t['status'] === 'registration_open' && $myReg['status'] === 'registered'): ?>
              <form method="post" action="/tournaments/withdraw/" style="display:inline"><input type="hidden" name="tournament_id" value="<?php echo e($t['id']); ?>"><input type="hidden" name="user_id" value="<?php echo e($user['id']); ?>"><?php echo csrf_field(); ?><button class="btn btn-ghost" type="submit" style="background:#B3123E;border-color:#B3123E;color:#fff" onclick="return confirm('Withdraw from tournament?')">Withdraw</button></form>
            <?php endif; ?>
          <?php elseif ($t['status'] === 'registration_open' && ($participantsCount ?? 0) < $t['max_players']): ?>
            <a class="btn btn-primary" href="/tournaments/register/?id=<?php echo e($t['id']); ?>">Register Now</a>
          <?php elseif ($t['status'] === 'registration_open'): ?>
            <a class="btn btn-ghost" href="/tournaments/waitlist/?id=<?php echo e($t['id']); ?>">Join Waitlist</a>
          <?php elseif ($t['status'] === 'in_progress'): ?>
            <a class="btn btn-primary" href="/tournaments/watch/?id=<?php echo e($t['id']); ?>">Watch Live</a>
          <?php endif; ?>
        <?php else: ?>
          <a class="btn btn-primary" href="/auth/login/?return=/tournaments/?slug=<?php echo e($slug); ?>">Sign In to Register</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="/tournaments/">All Tournaments</a>
      </div>
    </div>

    <div class="tourney-meta-grid">
      <div class="meta-item"><div class="meta-label">Format</div><div class="meta-value"><?php echo e(ucfirst(str_replace('_', ' ', $t['format']))); ?></div></div>
      <div class="meta-item"><div class="meta-label">Players</div><div class="meta-value"><?php echo ($participantsCount ?? 0) . ' / ' . (int)$t['max_players']; ?></div></div>
      <div class="meta-item"><div class="meta-label">Board Size</div><div class="meta-value"><?php echo (int)$t['board_size']; ?>×<?php echo (int)$t['board_size']; ?></div></div>
      <div class="meta-item"><div class="meta-label">Win Condition</div><div class="meta-value"><?php echo (int)$t['win_condition']; ?> in a row</div></div>
      <div class="meta-item"><div class="meta-label">Turn Time</div><div class="meta-value"><?php echo (int)$t['turn_time_seconds']; ?> seconds</div></div>
      <div class="meta-item"><div class="meta-label">Entry Fee</div><div class="meta-value"><?php echo $t['entry_fee'] > 0 ? (int)$t['entry_fee'] . ' 🪙' : 'Free'; ?></div></div>
      <div class="meta-item"><div class="meta-label">Prize Pool</div><div class="meta-value"><?php echo $t['prize_pool'] > 0 ? (int)$t['prize_pool'] . ' 🪙' : '—'; ?></div></div>
      <div class="meta-item"><div class="meta-label">Registration</div><div class="meta-value"><?php echo $t['registration_starts_at'] ? date('d M Y, H:i', strtotime($t['registration_starts_at'])) : 'Open'; ?> <small>to</small> <?php echo $t['registration_ends_at'] ? date('d M Y, H:i', strtotime($t['registration_ends_at'])) : 'TBA'; ?></div></div>
      <div class="meta-item"><div class="meta-label">Starts</div><div class="meta-value"><?php echo $t['starts_at'] ? date('d M Y, H:i', strtotime($t['starts_at'])) : 'TBA'; ?></div></div>
    </div>

    <?php if ($t['description']): ?>
    <div class="tourney-desc"><?php echo e($t['description']); ?></div>
    <?php endif; ?>
  </div>

  <?php if ($matches): ?>
  <section class="section">
    <div class="section-head"><h2>Tournament Bracket</h2></div>
    <div class="bracket">
      <?php
      $rounds = array();
      foreach ($matches as $m) { $rounds[$m['round']][] = $m; }
      ksort($rounds);
      foreach ($rounds as $roundNum => $roundMatches): ?>
      <div class="bracket-round">
        <h3>Round <?php echo (int)$roundNum; ?> (<?php echo count($roundMatches); ?> matches)</h3>
        <div class="bracket-matches">
          <?php foreach ($roundMatches as $m): ?>
          <div class="bracket-match <?php echo e($m['status']); ?>">
            <div class="match-player <?php echo ($m['winner_id'] && $m['winner_id'] === $m['player1_id']) ? 'winner' : (($m['status'] === 'completed' && $m['winner_id'] && $m['winner_id'] !== $m['player1_id']) ? 'loser' : ''); ?>">
              <div class="avatar"><?php if ($m['player1_id']): ?><img src="<?php echo e($m['player1']['avatar_url'] ?? ''); ?>" alt=""><?php else: ?><svg width="20" height="20" aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
              <div class="info">
                <span class="name"><?php echo $m['player1_id'] ? e($m['player1']['username'] ?? 'Player') : 'TBD'; ?></span>
                <?php if ($m['player1_id'] && isset($m['player1']['seed'])): ?><span class="seed">Seed #<?php echo (int)$m['player1']['seed']; ?></span><?php endif; ?>
              </div>
            </div>
            <div class="match-vs">
              <?php if ($m['status'] === 'completed' && $m['result']): ?>
                <span class="match-score"><?php echo (int)($m['result']['player1_score'] ?? 0); ?> - <?php echo (int)($m['result']['player2_score'] ?? 0); ?></span>
              <?php else: ?>
                VS
              <?php endif; ?>
            </div>
            <div class="match-player <?php echo ($m['winner_id'] && $m['winner_id'] === $m['player2_id']) ? 'winner' : (($m['status'] === 'completed' && $m['winner_id'] && $m['winner_id'] !== $m['player2_id']) ? 'loser' : ''); ?>" style="justify-content:flex-end;text-align:right">
              <div class="info" style="align-items:flex-end">
                <span class="name"><?php echo $m['player2_id'] ? e($m['player2']['username'] ?? 'Player') : 'TBD'; ?></span>
                <?php if ($m['player2_id'] && isset($m['player2']['seed'])): ?><span class="seed">Seed #<?php echo (int)$m['player2']['seed']; ?></span><?php endif; ?>
              </div>
              <div class="avatar"><?php if ($m['player2_id']): ?><img src="<?php echo e($m['player2']['avatar_url'] ?? ''); ?>" alt=""><?php else: ?><svg width="20" height="20" aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px solid var(--border2)">
              <span class="match-status <?php echo e($m['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $m['status']))); ?></span>
              <?php if ($m['status'] === 'ready' && $user && ($user['id'] === $m['player1_id'] || $user['id'] === $m['player2_id'])): ?>
                <a class="btn btn-primary btn-sm" href="/tournaments/match/?id=<?php echo e($m['id']); ?>">Enter Match</a>
              <?php elseif ($m['status'] === 'in_progress' && $user && ($user['id'] === $m['player1_id'] || $user['id'] === $m['player2_id'])): ?>
                <a class="btn btn-primary btn-sm" href="/tournaments/match/?id=<?php echo e($m['id']); ?>">Continue Match</a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="section-head"><h2>Participants (<?php echo ($participantsCount ?? 0); ?>/<?php echo (int)$t['max_players']; ?>)</h2></div>
    <div class="participants-list">
      <?php
      list($parts, , ) = sb_select(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $t['id'], 'status' => 'in.("registered","confirmed","checked_in")', 'order' => 'seed.asc,created_at.asc'), 100, 0);
      if ($parts): foreach ($parts as $p):
        $pUser = find_user_by_id($p['user_id']);
      ?>
      <div class="participant">
        <div class="avatar"><?php if ($pUser && $pUser['avatar_url']): ?><img src="<?php echo e($pUser['avatar_url']); ?>" alt=""><?php else: ?><svg width="20" height="20" aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
        <div class="info">
          <span class="name"><?php echo $pUser ? e($pUser['username']) : 'Unknown'; ?></span>
          <span class="meta"><?php echo $p['seed'] ? 'Seed #' . (int)$p['seed'] : ''; ?> <?php echo e(ucfirst($p['status'])); ?></span>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div style="grid-column:1/-1;text-align:center;color:var(--muted);padding:40px">No participants yet</div>
      <?php endif; ?>
    </div>
  </section>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>