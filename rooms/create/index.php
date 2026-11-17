<?php
require __DIR__ . '/../../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../../includes/bootstrap.php';

$error = '';
$success = '';
$room = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name = clean_str($_POST['name'] ?? '', 80);
    $boardSize = (int)($_POST['board_size'] ?? 3);
    $winCondition = (int)($_POST['win_condition'] ?? 3);
    $turnTime = (int)($_POST['turn_time'] ?? 20);
    $isRanked = !empty($_POST['is_ranked']);

    // Validate
    if ($boardSize < 3 || $boardSize > 5) { $error = 'Board size must be 3, 4, or 5.'; }
    elseif ($winCondition < 3 || $winCondition > $boardSize) { $error = 'Win condition must be between 3 and board size.'; }
    elseif ($turnTime < 10 || $turnTime > 60) { $error = 'Turn time must be between 10 and 60 seconds.'; }

    // Check user's room limit
    $currentRooms = sb_count(tbl('private_rooms'), array('host_id' => 'eq.' . $user['id'], 'status' => 'in.("waiting","in_progress")'));
    $maxRooms = (int) cfg('rooms.max_rooms_per_user', 5);
    if (($currentRooms ?? 0) >= $maxRooms) { $error = "You can only have {$maxRooms} active rooms at a time."; }

    if (!$error) {
        // Generate unique room code
        $charset = cfg('rooms.code_charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $codeLen = (int) cfg('rooms.code_length', 6);
        $code = '';
        $slug = '';
        $attempts = 0;
        do {
            $code = '';
            for ($i = 0; $i < $codeLen; $i++) { $code .= $charset[random_int(0, strlen($charset) - 1)]; }
            $slugBase = strtolower($name ?: 'room') . '-' . $code;
            $slug = preg_replace('/[^a-z0-9-]/', '', $slugBase);
            $attempts++;
            if ($attempts > 10) { $slug = substr(bin2hex(random_bytes(8)), 0, 12); break; }
            list($existing, , ) = sb_select(tbl('private_rooms'), array('room_code' => 'eq.' . $code), 1, 0);
        } while (!empty($existing));

        $ttlHours = (int) cfg('rooms.default_ttl_hours', 24);
        $expiresAt = gmdate('c', time() + $ttlHours * 3600);

        $r = sb_insert(tbl('private_rooms'), array(
            'host_id' => $user['id'],
            'room_code' => $code,
            'slug' => $slug,
            'name' => $name ?: null,
            'board_size' => $boardSize,
            'win_condition' => $winCondition,
            'turn_time_seconds' => $turnTime,
            'is_ranked' => $isRanked,
            'max_players' => 2,
            'expires_at' => $expiresAt,
        ), array(), 'return=representation');

        if (sb_ok($r) && !empty($r['data'])) {
            $room = $r['data'][0];
            $success = 'Room created successfully!';
        } else {
            $error = 'Failed to create room. Please try again.';
        }
    }
}

$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Room · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(560px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3)}
.auth-head{text-align:center;margin-bottom:28px}
.auth-head .tag{margin-bottom:12px}
.auth-head h1{font-size:28px;margin:0 0 8px}
.auth-head p{color:var(--muted);margin:0}
.form-section{margin-bottom:24px}
.form-section h3{font-size:14px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin:0 0 12px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-field{display:grid;gap:6px}
.form-field label{font-weight:600;font-size:13px;color:var(--muted)}
.form-field input,.form-field select{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif}
.form-field input:focus,.form-field select:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;color:var(--muted);font-size:13px;line-height:1.5}
.check input{width:18px;height:18px;margin-top:2px;accent-color:var(--mageta);flex:none}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.btn-block{width:100%}
.auth-foot{text-align:center;margin-top:22px;color:var(--muted);font-size:14px}
.auth-foot a{color:var(--pink);font-weight:600}
.success-box{background:var(--bg);border:1px solid var(--border2);border-radius:12px;padding:20px;text-align:center}
.success-box .code{font-family:monospace;font-size:28px;font-weight:800;color:var(--mageta);letter-spacing:4px;margin:12px 0}
.success-box .link{font-size:13px;color:var(--muted);word-break:break-all}
.success-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:20px}
@media(max-width:560px){.form-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <?php if ($room): ?>
    <div class="auth-head">
      <span class="tag" style="color:var(--teal);border-color:var(--teal)">Room Created</span>
      <h1>Your room is ready</h1>
      <p>Share the code or link with your friend</p>
    </div>

    <div class="success-box">
      <div class="code"><?php echo e($room['room_code']); ?></div>
      <div class="link"><?php echo e(cfg('site_url')); ?>/rooms/?code=<?php echo e($room['room_code']); ?></div>
      <div class="success-actions">
        <button class="btn btn-primary" onclick="navigator.clipboard.writeText('<?php echo e(cfg('site_url')); ?>/rooms/?code=<?php echo e($room['room_code']); ?>').then(()=>alert('Link copied!'))"><span>Copy Link</span></button>
        <button class="btn btn-ghost" onclick="navigator.share({title:'Drotix Room: <?php echo e($room['room_code']); ?>',url:'<?php echo e(cfg('site_url')); ?>/rooms/?code=<?php echo e($room['room_code']); ?>'})"><span>Share</span></button>
      </div>
      <p style="margin-top:16px;color:var(--muted);font-size:13px">Room expires in <?php echo (int)cfg('rooms.default_ttl_hours', 24); ?> hours</p>
    </div>

    <p class="auth-foot"><a href="/rooms/">My Rooms</a> · <a href="/rooms/?code=<?php echo e($room['room_code']); ?>">Enter Room</a></p>

    <?php else: ?>

    <div class="auth-head">
      <span class="tag">Create Private Room</span>
      <h1>Set up a match</h1>
      <p>Customize settings and invite a friend to play</p>
    </div>

    <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>

    <form method="post" novalidate>
      <?php echo csrf_field(); ?>

      <div class="form-section">
        <h3>Basic Settings</h3>
        <div class="form-field">
          <label>Room Name (optional)</label>
          <input type="text" name="name" maxlength="80" placeholder="e.g. Friday Night Duel">
        </div>
      </div>

      <div class="form-section">
        <h3>Game Settings</h3>
        <div class="form-row">
          <div class="form-field">
            <label>Board Size</label>
            <select name="board_size">
              <option value="3" selected>3×3 (Classic)</option>
              <option value="4">4×4 (Advanced)</option>
              <option value="5">5×5 (Expert)</option>
            </select>
          </div>
          <div class="form-field">
            <label>Win Condition</label>
            <select name="win_condition" id="winCondition">
              <option value="3" selected>3 in a row</option>
              <option value="4">4 in a row</option>
              <option value="5">5 in a row</option>
            </select>
          </div>
        </div>
        <div class="form-row" style="margin-top:12px">
          <div class="form-field">
            <label>Turn Time (seconds)</label>
            <select name="turn_time">
              <option value="15">15s</option>
              <option value="20" selected>20s</option>
              <option value="30">30s</option>
              <option value="45">45s</option>
              <option value="60">60s</option>
            </select>
          </div>
          <div class="form-field">
            <label>Ranked Match</label>
            <select name="is_ranked">
              <option value="0" selected>No (Friendly)</option>
              <option value="1">Yes (Affects Rating)</option>
            </select>
          </div>
        </div>
      </div>

      <label class="check">
        <input type="checkbox" name="confirm" value="yes" required>
        <span>I understand this room will expire in <?php echo (int)cfg('rooms.default_ttl_hours', 24); ?> hours if not played.</span>
      </label>

      <button class="btn btn-primary btn-block" type="submit"><span>Create Room</span></button>
    </form>

    <p class="auth-foot"><a href="/rooms/">Back to Rooms</a></p>
    <?php endif; ?>
  </div>
</div>
<script>
// Update win condition options based on board size
document.addEventListener('DOMContentLoaded', function() {
  const boardSize = document.querySelector('[name="board_size"]');
  const winCondition = document.getElementById('winCondition');
  if (boardSize && winCondition) {
    boardSize.addEventListener('change', function() {
      const size = parseInt(this.value);
      winCondition.innerHTML = '';
      for (let i = 3; i <= size; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.textContent = i + ' in a row';
        if (i === 3) opt.selected = true;
        winCondition.appendChild(opt);
      }
    });
  }
});
</script>
</body></html>