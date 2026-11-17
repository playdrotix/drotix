<?php
require __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bootstrap.php';

$tournamentId = $_GET['id'] ?? '';
if (!$tournamentId) { header('Location: /tournaments/'); exit; }

list($tRows, , $r) = sb_select(tbl('tournaments'), array('id' => 'eq.' . $tournamentId), 1, 0);
if (!sb_ok($r) || empty($tRows)) { header('Location: /tournaments/'); exit; }
$t = $tRows[0];

// Get matches
list($matches, , ) = sb_select(tbl('tourney_matches'), array('tournament_id' => 'eq.' . $tournamentId, 'order' => 'round.asc,match_number.asc'), 100, 0);

$user = current_user();
$unreadCount = $user ? get_unread_notification_count($user['id']) : 0;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Watch: <?php echo e($t['name']); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<link rel="stylesheet" href="/assets/css/tournament.css">
<style>
.watch-page{padding:60px 0;min-height:100vh}
.watch-header{margin-bottom:40px}
.watch-header .tag{margin-bottom:12px}
.watch-header h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.live-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid;background:rgba(237,27,118,.15);color:var(--mageta);border-color:var(--mageta);animation:pulse 2s infinite}
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
@media(max-width:768px){.bracket-match{grid-template-columns:1fr;gap:8px}.match-vs{order:2}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span><?php echo e($t['name']); ?> - Live</span><?php if ($user): ?><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a><?php else: ?><a href="/auth/login/?return=/tournaments/watch/?id=<?php echo e($tournamentId); ?>" class="btn btn-primary" style="height:36px;padding:0 16px;font-size:12px">Sign In</a><?php endif; ?></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/" aria-current="page">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><?php if ($user): ?><li><a href="/dashboard/">Dashboard</a></li><?php endif; ?></ul>
<?php if ($user): ?>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<?php else: ?>
<a class="btn btn-primary" href="/auth/login/?return=/tournaments/watch/?id=<?php echo e($tournamentId); ?>"><span>Sign In</span></a>
<?php endif; ?>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="watch-page">
<div class="container">
  <div class="watch-header reveal">
    <span class="tag">Live Tournament</span>
    <h1><?php echo e($t['name']); ?></h1>
    <?php if ($t['status'] === 'in_progress'): ?><span class="live-badge">LIVE</span><?php endif; ?>
    <p class="lead">Watch the bracket unfold in real-time</p>
  </div>

  <div class="bracket reveal">
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
            <?php if ($m['status'] === 'in_progress'): ?>
              <a class="btn btn-primary btn-sm" href="/tournaments/match/?id=<?php echo e($m['id']); ?>" target="_blank">Watch Match</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>