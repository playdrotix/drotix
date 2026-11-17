<?php
require __DIR__ . '/../../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../../includes/bootstrap.php';

$tournamentId = $_GET['id'] ?? '';
if (!$tournamentId) { header('Location: /tournaments/'); exit; }

list($rows, , $r) = sb_select(tbl('tournaments'), array('id' => 'eq.' . $tournamentId, 'select' => '*'), 1, 0);
if (!sb_ok($r) || empty($rows)) { header('Location: /tournaments/'); exit; }
$t = $rows[0];

// Check if registration is open
if ($t['status'] !== 'registration_open') {
    header('Location: /tournaments/?slug=' . urlencode($t['slug']));
    exit;
}

// Check if already registered
$existing = null;
list($regs, , ) = sb_select(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $tournamentId, 'user_id' => 'eq.' . $user['id']), 1, 0);
if (!empty($regs)) { $existing = $regs[0]; }

// Check capacity
$currentCount = sb_count(tbl('tourney_participants'), array('tournament_id' => 'eq.' . $tournamentId, 'status' => 'in.("registered","confirmed","checked_in")'));
$isFull = ($currentCount ?? 0) >= $t['max_players'];

// Check entry fee
$canAfford = $user['coins'] >= $t['entry_fee'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing) {
    csrf_verify();

    if ($isFull) { $error = 'Tournament is full.'; }
    elseif (!$canAfford) { $error = 'Insufficient coins. You need ' . $t['entry_fee'] . ' 🪙 to enter.'; }
    elseif ($user['status'] !== 'active') { $error = 'Your account must be active to join tournaments.'; }

    if (!$error) {
        // Deduct entry fee
        $newCoins = $user['coins'] - $t['entry_fee'];
        $updateUser = update_user($user['id'], array('coins' => $newCoins));

        if ($updateUser) {
            $_SESSION['user_coins'] = $newCoins;

            // Create registration
            $r = sb_insert(tbl('tourney_participants'), array(
                'tournament_id' => $tournamentId,
                'user_id' => $user['id'],
                'status' => 'registered',
            ), array(), 'return=representation');

            if (sb_ok($r)) {
                // Create notification
                create_user_notification($user['id'], 'tournament_registered',
                    'Tournament Registration Confirmed',
                    'You have successfully registered for ' . $t['name'] . '.',
                    array('tournament_id' => $tournamentId),
                    '/tournaments/?slug=' . $t['slug']
                );

                // Notify admin
                // (admin notification handled separately)

                $success = 'Registration successful! You are now entered in ' . e($t['name']) . '.';
            } else {
                // Refund coins on failure
                update_user($user['id'], array('coins' => $user['coins']));
                $_SESSION['user_coins'] = $user['coins'];
                $error = 'Registration failed. Please try again.';
            }
        } else {
            $error = 'Failed to process entry fee. Please try again.';
        }
    }
}

$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register for <?php echo e($t['name']); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(520px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3)}
.auth-head{text-align:center;margin-bottom:28px}
.auth-head .tag{margin-bottom:12px}
.auth-head h1{font-size:28px;margin:0 0 8px}
.auth-head p{color:var(--muted);margin:0}
.tourney-summary{background:var(--bg);border:1px solid var(--border2);border-radius:12px;padding:20px;margin-bottom:24px;display:flex;gap:16px;align-items:center}
.tourney-summary img{width:60px;height:60px;border-radius:8px;object-fit:cover;flex:none}
.tourney-summary .info{flex:1}
.tourney-summary .title{font-weight:800;font-size:16px;margin-bottom:8px}
.tourney-summary .meta{display:flex;gap:16px;font-size:12px;color:var(--muted)}
.tourney-summary .meta b{color:var(--text)}
.tourney-summary .fee{font-size:20px;font-weight:800;color:var(--gold);flex:none}
.form-section{margin-bottom:24px}
.form-section h3{font-size:14px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin:0 0 12px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-field{display:grid;gap:6px}
.form-field label{font-weight:600;font-size:13px;color:var(--muted)}
.form-field input,.form-field select{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif}
.form-field input:focus,.form-field select:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;color:var(--muted);font-size:13px;line-height:1.5}
.check input{width:18px;height:18px;margin-top:2px;accent-color:var(--mageta);flex:none}
.check a{color:var(--pink);text-decoration:underline}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.btn-block{width:100%}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
@media(max-width:560px){.form-row{grid-template-columns:1fr}.tourney-summary{flex-direction:column;text-align:center}.tourney-summary .meta{justify-content:center}}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-head">
      <span class="tag">Tournament Registration</span>
      <h1><?php echo e($t['name']); ?></h1>
      <p><?php echo $existing ? 'You are already registered' : 'Confirm your entry'; ?></p>
    </div>

    <div class="tourney-summary">
      <?php if ($t['banner_url']): ?><img src="<?php echo e($t['banner_url']); ?>" alt=""><?php else: ?><div style="width:60px;height:60px;border-radius:8px;background:linear-gradient(135deg,rgba(237,27,118,.2),rgba(47,184,180,.1));display:grid;place-items:center"><svg width="28" height="28" aria-hidden="true"><use href="#i-trophy"/></svg></div><?php endif; ?>
      <div class="info">
        <div class="title"><?php echo e($t['name']); ?></div>
        <div class="meta">
          <span><b><?php echo ($currentCount ?? 0) . '/' . (int)$t['max_players']; ?></b> players</span>
          <span><b><?php echo (int)$t['board_size']; ?>×<?php echo (int)$t['board_size']; ?></b> board</span>
          <span><b><?php echo (int)$t['turn_time_seconds']; ?>s</b> turns</span>
        </div>
      </div>
      <div class="fee"><?php echo $t['entry_fee'] > 0 ? (int)$t['entry_fee'] . ' 🪙' : 'Free'; ?></div>
    </div>

    <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>

    <?php if ($existing): ?>
      <div class="form-section">
        <h3>Registration Status</h3>
        <div class="form-field">
          <label>Status</label>
          <input type="text" value="<?php echo e(ucfirst($existing['status'])); ?>" disabled>
        </div>
        <?php if ($existing['seed']): ?><div class="form-field" style="margin-top:12px"><label>Your Seed</label><input type="text" value="#<?php echo (int)$existing['seed']; ?>" disabled></div><?php endif; ?>
        <p style="color:var(--muted);font-size:13px;margin-top:16px">You are registered for this tournament. Check your dashboard for match schedule.</p>
      </div>
    <?php elseif ($isFull): ?>
      <div class="form-section">
        <h3>Tournament Full</h3>
        <p style="color:var(--muted)">This tournament has reached its maximum capacity of <?php echo (int)$t['max_players']; ?> players.</p>
        <a class="btn btn-ghost btn-block" href="/tournaments/waitlist/?id=<?php echo e($tournamentId); ?>" style="margin-top:12px">Join Waitlist</a>
      </div>
    <?php elseif (!$canAfford): ?>
      <div class="form-section">
        <h3>Insufficient Coins</h3>
        <p style="color:var(--muted)">Entry fee: <b><?php echo (int)$t['entry_fee']; ?> 🪙</b></p>
        <p style="color:var(--muted);margin-top:8px">Your balance: <b><?php echo (int)$user['coins']; ?> 🪙</b></p>
        <p style="color:var(--muted);font-size:13px;margin-top:16px">Earn more coins by playing games, completing daily rewards, or winning matches.</p>
      </div>
    <?php else: ?>
    <form method="post" novalidate>
      <?php echo csrf_field(); ?>

      <div class="form-section">
        <h3>Player Information</h3>
        <div class="form-row">
          <div class="form-field">
            <label>Display Name</label>
            <input type="text" value="<?php echo e($user['username']); ?>" disabled>
          </div>
          <div class="form-field">
            <label>Email</label>
            <input type="email" value="<?php echo e($user['email']); ?>" disabled>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3>Tournament Details</h3>
        <div class="form-row">
          <div class="form-field">
            <label>Board Size</label>
            <input type="text" value="<?php echo (int)$t['board_size']; ?>×<?php echo (int)$t['board_size']; ?>" disabled>
          </div>
          <div class="form-field">
            <label>Win Condition</label>
            <input type="text" value="<?php echo (int)$t['win_condition']; ?> in a row" disabled>
          </div>
        </div>
        <div class="form-row" style="margin-top:12px">
          <div class="form-field">
            <label>Turn Time</label>
            <input type="text" value="<?php echo (int)$t['turn_time_seconds']; ?> seconds" disabled>
          </div>
          <div class="form-field">
            <label>Format</label>
            <input type="text" value="<?php echo e(ucfirst(str_replace('_', ' ', $t['format']))); ?>" disabled>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3>Entry Fee</h3>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--bg);border:1px solid var(--border2);border-radius:12px">
          <span>Tournament Entry</span>
          <span style="font-size:24px;font-weight:800;color:var(--gold)"><?php echo (int)$t['entry_fee']; ?> 🪙</span>
        </div>
        <p style="color:var(--muted);font-size:13px;margin-top:8px">Your balance: <b><?php echo (int)$user['coins']; ?> 🪙</b> → After entry: <b><?php echo (int)$user['coins'] - $t['entry_fee']; ?> 🪙</b></p>
      </div>

      <label class="check">
        <input type="checkbox" name="confirm" value="yes" required>
        <span>I confirm I want to enter <b><?php echo e($t['name']); ?></b> and understand the entry fee of <b><?php echo (int)$t['entry_fee']; ?> 🪙</b> will be deducted from my balance.</span>
      </label>

      <button class="btn btn-primary btn-block" type="submit"><span>Confirm Registration</span></button>
    </form>
    <?php endif; ?>

    <p class="auth-foot"><a href="/tournaments/?slug=<?php echo e($t['slug']); ?>">Back to Tournament</a></p>
  </div>
</div>
</body></html>