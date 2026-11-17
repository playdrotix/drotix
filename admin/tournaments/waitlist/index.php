<?php
require __DIR__ . '/../../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../../includes/bootstrap.php';

$tournamentId = $_GET['id'] ?? '';
if (!$tournamentId) { header('Location: /tournaments/'); exit; }

list($tRows, , $r) = sb_select(tbl('tournaments'), array('id' => 'eq.' . $tournamentId), 1, 0);
if (!sb_ok($r) || empty($tRows)) { header('Location: /tournaments/'); exit; }
$t = $tRows[0];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Check if already registered or waitlisted
    list($existing, , ) = sb_select(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $tournamentId, 'user_id' => 'eq.' . $user['id']), 1, 0);
    if ($existing) { $error = 'You are already registered or waitlisted for this tournament.'; }

    if (!$error) {
        $r = sb_insert(tbl('tourney_participants'), array(
            'tournament_id' => $tournamentId,
            'user_id' => $user['id'],
            'status' => 'waitlisted',
        ), array('on_conflict' => 'tournament_id,user_id'), 'resolution=ignore-duplicates,return=representation');

        if (sb_ok($r)) {
            create_user_notification($user['id'], 'tournament_registered',
                'Added to Waitlist',
                'You have been added to the waitlist for ' . $t['name'] . '. We\'ll notify you if a spot opens up.',
                array('tournament_id' => $tournamentId),
                '/tournaments/?slug=' . $t['slug']
            );
            $success = 'You have been added to the waitlist!';
        } else {
            $error = 'Failed to join waitlist.';
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Waitlist for <?php echo e($t['name']); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(480px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3)}
.auth-head{text-align:center;margin-bottom:28px}
.auth-head .tag{margin-bottom:12px}
.auth-head h1{font-size:28px;margin:0 0 8px}
.auth-head p{color:var(--muted);margin:0}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.waitlist-info{background:var(--bg);border:1px solid var(--border2);border-radius:12px;padding:20px;margin-bottom:24px;text-align:center}
.waitlist-info .icon{width:60px;height:60px;border-radius:50%;background:rgba(255,194,77,.15);border:3px solid var(--gold);color:var(--gold);display:grid;place-items:center;margin:0 auto 16px;font-size:24px}
.waitlist-info h3{margin:0 0 8px;font-size:18px}
.waitlist-info p{color:var(--muted);margin:0}
.btn-block{width:100%}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-head">
      <span class="tag" style="color:var(--gold);border-color:var(--gold)">Waitlist</span>
      <h1><?php echo e($t['name']); ?></h1>
      <p>Tournament is full. Join the waitlist for a chance to play.</p>
    </div>

    <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>

    <div class="waitlist-info">
      <div class="icon"><svg aria-hidden="true"><use href="#i-clock"/></svg></div>
      <h3>Join Waitlist</h3>
      <p>If a registered player withdraws, you'll be notified immediately and can claim their spot.</p>
    </div>

    <?php if (!$success): ?>
    <form method="post" novalidate>
      <?php echo csrf_field(); ?>
      <button class="btn btn-primary btn-block" type="submit"><span>Join Waitlist</span></button>
    </form>
    <?php endif; ?>

    <p class="auth-foot"><a href="/tournaments/?slug=<?php echo e($t['slug']); ?>">Back to Tournament</a></p>
  </div>
</div>
</body></html>