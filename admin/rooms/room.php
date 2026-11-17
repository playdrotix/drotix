<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';

$code = $_GET['code'] ?? '';
$slug = $_GET['slug'] ?? '';

if (!$code && !$slug) { header('Location: /rooms/'); exit; }

$filter = $code ? array('room_code' => 'eq.' . strtoupper($code)) : array('slug' => 'eq.' . $slug);
list($rows, , $r) = sb_select(tbl('private_rooms'), array_merge($filter, array('select' => '*')), 1, 0);
if (!sb_ok($r) || empty($rows)) { header('Location: /rooms/'); exit; }
$room = $rows[0];

// Check expiration
if ($room['expires_at'] && strtotime($room['expires_at']) < time()) {
    header('Location: /rooms/?error=expired');
    exit;
}

$user = current_user();
$isHost = $user && $room['host_id'] === $user['id'];

// Check if user is already a participant
$isParticipant = false;
if ($user) {
    list($parts, , ) = sb_select(tbl('room_participants'), array('room_id' => 'eq.' . $room['id'], 'user_id' => 'eq.' . $user['id']), 1, 0);
    $isParticipant = !empty($parts);
}

// Auto-join if not participant and room has space
if ($user && !$isParticipant && $room['status'] === 'waiting') {
    $currentPlayers = sb_count(tbl('room_participants'), array('room_id' => 'eq.' . $room['id'], 'left_at' => 'is.null'));
    if (($currentPlayers ?? 0) < $room['max_players']) {
        sb_insert(tbl('room_participants'), array('room_id' => $room['id'], 'user_id' => $user['id'], 'role' => 'player'), array('on_conflict' => 'room_id,user_id'), 'resolution=ignore-duplicates,return=minimal');
        $isParticipant = true;
    }
}

// Determine player symbol
$isPlayer1 = false;
$mySymbol = 'X';
$opponentSymbol = 'O';
if ($user) {
    // Check if user is player1 or player2
    if ($room['player1_id'] === $user['id']) { $isPlayer1 = true; }
    elseif ($room['player2_id'] === $user['id']) { $isPlayer1 = false; }
    // If not assigned yet and room is waiting, assign
    elseif ($room['status'] === 'waiting' && !$room['player1_id']) {
        sb_update(tbl('private_rooms'), array('id' => 'eq.' . $room['id']), array('player1_id' => $user['id']));
        $isPlayer1 = true;
        $room['player1_id'] = $user['id'];
    } elseif ($room['status'] === 'waiting' && $room['player1_id'] && !$room['player2_id']) {
        sb_update(tbl('private_rooms'), array('id' => 'eq.' . $room['id']), array('player2_id' => $user['id'], 'status' => 'ready'));
        $isPlayer1 = false;
        $room['player2_id'] = $user['id'];
        $room['status'] = 'ready';
    }
}
$mySymbol = $isPlayer1 ? 'X' : 'O';
$opponentSymbol = $isPlayer1 ? 'O' : 'X';

// Get opponent
$opponentId = $isPlayer1 ? $room['player2_id'] : $room['player1_id'];
$opponent = $opponentId ? find_user_by_id($opponentId) : null;

// Board state
$boardState = $room['board_state'] ?? array_fill(0, $room['board_size'] * $room['board_size'], '');
$moveHistory = $room['move_history'] ?? array();
$currentTurn = $room['current_turn'];
$myTurn = $currentTurn === $user['id'];
$gameOver = in_array($room['status'], array('completed', 'abandoned'));

// Chat messages
list($chatMessages, , ) = sb_select(tbl('room_chat'), array('room_id' => 'eq.' . $room['id'], 'order' => 'created_at.asc'), 100, 0);

$unreadCount = $user ? get_unread_notification_count($user['id']) : 0;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Room: <?php echo e($room['name'] ?? $room['room_code']); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<link rel="stylesheet" href="/assets/css/game.css?v=3">
<style>
.room-page{padding:20px 0;min-height:100vh;background:var(--bg)}
.room-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.room-info{display:flex;align-items:center;gap:16px;flex:1}
.room-badge{padding:6px 12px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid;text-transform:uppercase;letter-spacing:1px}
.room-badge.waiting{color:var(--gold);border-color:var(--gold)}
.room-badge.ready{color:var(--gold);border-color:var(--gold)}
.room-badge.in_progress{color:var(--mageta);border-color:var(--mageta);animation:pulse 2s infinite}
.room-badge.completed{color:var(--teal);border-color:var(--teal)}
.room-badge.abandoned{color:var(--muted);border-color:var(--border2)}
.room-players{display:flex;align-items:center;gap:20px}
.room-player{display:flex;align-items:center;gap:10px}
.room-player .avatar{width:44px;height:44px;border-radius:50%;border:2px solid var(--border2);background:var(--surface);display:grid;place-items:center;overflow:hidden;flex:none}
.room-player .avatar img{width:100%;height:100%;object-fit:cover}
.room-player .name{font-weight:800;font-size:14px}
.room-player .symbol{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:12px;flex:none}
.room-player .symbol.x{background:rgba(237,27,118,.2);color:var(--mageta);border:2px solid var(--mageta)}
.room-player .symbol.o{background:rgba(47,184,180,.2);color:var(--teal);border:2px solid var(--teal)}
.room-player.me .name::after{content:'(You)';font-size:11px;font-weight:400;color:var(--teal);margin-left:6px}
.room-vs{color:var(--muted);font-weight:800;letter-spacing:2px;font-size:14px}
.room-timer{font-size:28px;font-weight:800;color:var(--mageta);font-variant-numeric:tabular-nums;font-family:'Outfit',monospace}
.room-main{display:grid;grid-template-columns:1fr 340px;gap:24px;margin-top:24px}
.room-board-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px}
.room-board{display:grid;gap:8px;margin:0 auto;max-width:480px}
.room-cell{aspect-ratio:1;background:var(--bg);border:2px solid var(--border2);border-radius:12px;display:grid;place-items:center;cursor:pointer;transition:all .15s;font-size:0}
.room-cell:hover:not(.filled){border-color:var(--mageta);background:rgba(237,27,118,.05)}
.room-cell.filled{cursor:default}
.room-cell.winning{animation:winPulse 1s ease-in-out infinite;box-shadow:0 0 20px rgba(237,27,118,.5)}
.room-cell svg{width:60%;height:60%}
.room-cell.x svg{stroke:var(--mageta)}
.room-cell.o svg{stroke:var(--teal)}
@keyframes winPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.room-side{display:flex;flex-direction:column;gap:20px}
.room-chat{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);display:flex;flex-direction:column;height:500px;max-height:500px}
.chat-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.chat-header h3{font-size:14px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin:0}
.chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px}
.chat-msg{display:flex;flex-direction:column;gap:4px;max-width:85%}
.chat-msg.me{align-self:flex-end}
.chat-msg .meta{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted)}
.chat-msg .meta .name{font-weight:800;color:var(--text);font-size:12px}
.chat-msg .meta .time{font-size:10px}
.chat-msg .text{background:var(--bg);border:1px solid var(--border2);border-radius:12px;padding:10px 14px;font-size:13px;line-height:1.5}
.chat-msg.me .text{background:rgba(237,27,118,.15);border-color:var(--mageta);border-radius:12px 12px 4px 12px}
.chat-msg.other .text{border-radius:12px 12px 12px 4px}
.chat-input{padding:16px;border-top:1px solid var(--border);display:flex;gap:8px}
.chat-input input{flex:1;background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:12px 16px;font:400 14px 'Outfit',sans-serif}
.chat-input input:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.chat-input button{background:var(--btn);border:1px solid transparent;color:#fff;border-radius:12px;padding:0 20px;font:700 12px 'Outfit',sans-serif;letter-spacing:1px;text-transform:uppercase;cursor:pointer}
.room-status{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px;text-align:center}
.room-status h3{font-size:18px;margin:0 0 8px}
.room-status.waiting h3{color:var(--muted)}
.room-status.ready h3{color:var(--gold)}
.room-status.in_progress h3{color:var(--mageta)}
.room-result{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;text-align:center;margin-top:20px}
.room-result .icon{width:80px;height:80px;border-radius:50%;display:grid;place-items:center;margin:0 auto 16px;font-size:36px}
.room-result.win .icon{background:rgba(47,184,180,.15);border:3px solid var(--teal);color:var(--teal)}
.room-result.lose .icon{background:rgba(255,122,154,.12);border:3px solid #FF7A9A;color:#FF7A9A}
.room-result.draw .icon{background:rgba(255,194,77,.15);border:3px solid var(--gold);color:var(--gold)}
.room-result h2{font-size:24px;margin:0 0 8px}
.room-result p{color:var(--muted);margin:0 0 20px}
.room-nav{display:flex;gap:12px;justify-content:center;margin-top:24px}
@media(max-width:980px){.room-main{grid-template-columns:1fr}.room-chat{height:400px;order:-1}}
@media(max-width:600px){.room-players{flex-wrap:wrap}.room-vs{width:100%;text-align:center;margin:8px 0}.room-board{max-width:100%}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span><?php echo e($room['name'] ?? 'Room ' . $room['room_code']); ?></span><a href="/rooms/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">All Rooms</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/" aria-current="page">Private Rooms</a></li><?php if ($user): ?><li><a href="/dashboard/">Dashboard</a></li><?php endif; ?></ul>
<?php if ($user): ?>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<?php else: ?>
<a class="btn btn-primary" href="/auth/login/?return=/rooms/?code=<?php echo e($code); ?>"><span>Sign In</span></a>
<?php endif; ?>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="room-page">
<div class="container">
  <div class="room-header">
    <div class="room-info">
      <span class="room-badge <?php echo e($room['status']); ?>"><?php echo e(ucfirst($room['status'])); ?></span>
      <div class="room-players">
        <div class="room-player <?php echo $isPlayer1 ? 'me' : ''; ?>">
          <div class="avatar"><?php if ($user && $user['avatar_url']): ?><img src="<?php echo e($user['avatar_url']); ?>" alt=""><?php else: ?><svg aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
          <div class="name"><?php echo $user ? e($user['username']) : 'Guest'; ?></div>
          <div class="symbol <?php echo $mySymbol === 'X' ? 'x' : 'o'; ?>"><?php echo e($mySymbol); ?></div>
        </div>
        <span class="room-vs">VS</span>
        <div class="room-player <?php echo !$isPlayer1 ? 'me' : ''; ?>">
          <div class="symbol <?php echo $opponentSymbol === 'X' ? 'x' : 'o'; ?>"><?php echo e($opponentSymbol); ?></div>
          <div class="name"><?php echo $opponent ? e($opponent['username']) : 'Waiting for opponent...'; ?></div>
          <div class="avatar"><?php if ($opponent && $opponent['avatar_url']): ?><img src="<?php echo e($opponent['avatar_url']); ?>" alt=""><?php else: ?><svg aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
        </div>
      </div>
    </div>
    <?php if (!$gameOver && in_array($room['status'], array('ready', 'in_progress'))): ?>
    <div class="room-timer" id="roomTimer" data-time="<?php echo (int)$room['turn_time_seconds']; ?>"><?php echo (int)$room['turn_time_seconds']; ?></div>
    <?php endif; ?>
  </div>

  <div class="room-main">
    <div class="room-board-wrap">
      <?php if ($gameOver): ?>
        <?php
        $result = 'draw';
        $resultMsg = 'Match ended in a draw.';
        if ($room['winner_id']) {
            if ($room['winner_id'] === $user['id']) { $result = 'win'; $resultMsg = 'You won!'; }
            else { $result = 'lose'; $resultMsg = 'You lost.'; }
        }
        ?>
        <div class="room-result <?php echo $result; ?>">
          <div class="icon">
            <?php if ($result === 'win'): ?><svg aria-hidden="true"><use href="#i-shield"/></svg>
            <?php elseif ($result === 'lose'): ?><svg aria-hidden="true"><use href="#i-trash"/></svg>
            <?php else: ?><svg aria-hidden="true"><use href="#i-target"/></svg><?php endif; ?>
          </div>
          <h2><?php echo $result === 'win' ? 'Victory!' : ($result === 'lose' ? 'Defeat' : 'Draw'); ?></h2>
          <p><?php echo $resultMsg; ?></p>
          <div class="room-nav">
            <a class="btn btn-primary" href="/rooms/">My Rooms</a>
            <a class="btn btn-ghost" href="/rooms/create/">Create New Room</a>
          </div>
        </div>
      <?php else: ?>
        <div class="room-board" id="roomBoard" style="grid-template-columns:repeat(<?php echo (int)$room['board_size']; ?>,1fr);" data-size="<?php echo (int)$room['board_size']; ?>" data-win="<?php echo (int)$room['win_condition']; ?>" data-my-symbol="<?php echo e($mySymbol); ?>" data-my-turn="<?php echo $myTurn ? 'true' : 'false'; ?>" data-status="<?php echo e($room['status']); ?>"></div>
        <div class="room-status <?php echo e($room['status']); ?>" id="roomStatus" style="margin-top:20px">
          <?php if ($room['status'] === 'waiting'): ?>
            <h3>Waiting for opponent to join...</h3>
            <p style="color:var(--muted);margin-top:8px">Share the room code: <b><?php echo e($room['room_code']); ?></b></p>
          <?php elseif ($room['status'] === 'ready'): ?>
            <h3>Both players joined! Starting match...</h3>
          <?php elseif ($room['status'] === 'in_progress'): ?>
            <h3 id="turnText"><?php echo $myTurn ? 'Your turn' : "Opponent's turn"; ?></h3>
            <div class="timer-bar"><div class="fill" id="timerFill" style="width:100%"></div></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="room-side">
      <div class="room-chat" id="roomChat">
        <div class="chat-header">
          <h3>Room Chat</h3>
          <span style="font-size:11px;color:var(--muted)"><?php echo count($chatMessages); ?> messages</span>
        </div>
        <div class="chat-messages" id="chatMessages">
          <?php if ($chatMessages): foreach ($chatMessages as $msg):
            $isMe = $msg['user_id'] === $user['id'];
            $sender = find_user_by_id($msg['user_id']);
          ?>
          <div class="chat-msg <?php echo $isMe ? 'me' : 'other'; ?>">
            <div class="meta"><span class="name"><?php echo $isMe ? 'You' : e($sender['username'] ?? 'Unknown'); ?></span><span class="time"><?php echo date('H:i', strtotime($msg['created_at'])); ?></span></div>
            <div class="text"><?php echo e($msg['message']); ?></div>
          </div>
          <?php endforeach; else: ?>
          <div style="text-align:center;color:var(--muted);padding:40px;font-size:13px">No messages yet. Say hello!</div>
          <?php endif; ?>
        </div>
        <form class="chat-input" id="chatForm">
          <input type="text" name="message" id="chatInput" placeholder="Type a message..." maxlength="500" autocomplete="off" <?php echo $gameOver ? 'disabled' : ''; ?>>
          <button type="submit" <?php echo $gameOver ? 'disabled' : ''; ?>>Send</button>
        </form>
      </div>

      <div class="room-status" id="roomInfo">
        <h3>Room Info</h3>
        <p style="color:var(--muted);font-size:13px;margin:12px 0">
          Code: <b><?php echo e($room['room_code']); ?></b> •
          Board: <b><?php echo (int)$room['board_size']; ?>×<?php echo (int)$room['board_size']; ?></b>
        </p>
        <p style="color:var(--muted);font-size:13px">
          Win: <b><?php echo (int)$room['win_condition']; ?> in a row</b> •
          Turn time: <b><?php echo (int)$room['turn_time_seconds']; ?>s</b>
          <?php if ($room['is_ranked']): ?> • <b style="color:var(--gold)">Ranked</b><?php endif; ?>
        </p>
        <?php if ($isHost && $room['status'] === 'waiting'): ?>
        <p style="margin-top:16px"><a class="btn btn-ghost btn-sm" href="/rooms/?code=<?php echo e($room['room_code']); ?>" onclick="shareRoom('<?php echo e($room['room_code']); ?>', '<?php echo e($room['slug']); ?>');return false"><svg width="14" height="14" aria-hidden="true"><use href="#i-link"/></svg> Share Link</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script>
// Room game logic (similar to tournament match)
(function() {
  const boardEl = document.getElementById('roomBoard');
  const statusEl = document.getElementById('roomStatus');
  const turnText = document.getElementById('turnText');
  const timerFill = document.getElementById('timerFill');
  const roomTimer = document.getElementById('roomTimer');
  const chatForm = document.getElementById('chatForm');
  const chatInput = document.getElementById('chatInput');
  const chatMessages = document.getElementById('chatMessages');

  if (!boardEl) return;

  const boardSize = parseInt(boardEl.dataset.size);
  const winCondition = parseInt(boardEl.dataset.win);
  const mySymbol = boardEl.dataset.mySymbol;
  const opponentSymbol = mySymbol === 'X' ? 'O' : 'X';
  let myTurn = boardEl.dataset.myTurn === 'true';
  let gameOver = false;
  let boardState = <?php echo json_encode($boardState); ?>;
  let turnTime = <?php echo (int)$room['turn_time_seconds']; ?>;
  let timerInterval = null;
  let timeLeft = turnTime;

  const svgX = '<svg aria-hidden="true"><use href="#x"/></svg>';
  const svgO = '<svg aria-hidden="true"><use href="#o"/></svg>';

  function render() {
    boardEl.innerHTML = '';
    boardEl.style.gridTemplateColumns = 'repeat(' + boardSize + ',1fr)';
    boardState.forEach((v, i) => {
      const cell = document.createElement('button');
      cell.type = 'button';
      cell.className = 'room-cell' + (v ? ' filled' : '');
      cell.dataset.index = i;
      cell.innerHTML = v === 'X' ? svgX : (v === 'O' ? svgO : '');
      cell.disabled = !!v || gameOver || !myTurn;
      cell.onclick = () => makeMove(i);
      boardEl.appendChild(cell);
    });
  }

  function checkWinner(b) {
    const lines = [];
    const n = boardSize, k = winCondition;
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
      [[0,1],[1,0],[1,1],[1,-1]].forEach(d => {
        const er = r + d[0]*(k-1), ec = c + d[1]*(k-1);
        if (er < 0 || er >= n || ec < 0 || ec >= n) return;
        const line = [];
        for (let i = 0; i < k; i++) line.push((r + d[0]*i)*n + c + d[1]*i);
        lines.push(line);
      });
    }
    for (const line of lines) {
      const v = b[line[0]];
      if (v && line.every(idx => b[idx] === v)) return {player: v, line: line};
    }
    return null;
  }

  function makeMove(index) {
    if (gameOver || boardState[index] || !myTurn) return;

    boardState[index] = mySymbol;
    myTurn = false;
    render();
    updateStatus();

    fetch('/api/room/move/', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({room_id: '<?php echo e($room['id']); ?>', position: index, symbol: mySymbol})
    }).catch(() => {});
  }

  function updateStatus() {
    const winner = checkWinner(boardState);
    const empty = boardState.some(v => !v);

    if (winner) {
      gameOver = true;
      clearInterval(timerInterval);
      if (turnText) turnText.textContent = winner.player === mySymbol ? 'You won!' : 'You lost!';
      statusEl.className = 'room-status ' + (winner.player === mySymbol ? 'completed' : 'completed');
      setTimeout(() => location.reload(), 2000);
    } else if (!empty) {
      gameOver = true;
      clearInterval(timerInterval);
      if (turnText) turnText.textContent = 'Draw!';
      statusEl.className = 'room-status completed';
    } else {
      if (turnText) turnText.textContent = myTurn ? 'Your turn' : "Opponent's turn";
      startTimer();
    }
  }

  function startTimer() {
    clearInterval(timerInterval);
    timeLeft = turnTime;
    if (roomTimer) roomTimer.textContent = timeLeft;
    if (timerFill) timerFill.style.width = '100%';

    timerInterval = setInterval(() => {
      timeLeft -= 0.2;
      if (roomTimer) roomTimer.textContent = Math.ceil(timeLeft);
      if (timerFill) timerFill.style.width = Math.max(0, (timeLeft / turnTime) * 100) + '%';
      if (timeLeft <= 0) clearInterval(timerInterval);
    }, 200);
  }

  function pollUpdates() {
    if (gameOver) return;
    fetch('/api/room/match/?id=<?php echo e($room['id']); ?>', {cache: 'no-store'})
      .then(r => r.json())
      .then(data => {
        if (data.ok && data.board_state) {
          const newState = data.board_state;
          const changed = newState.findIndex((v,i) => v !== boardState[i]);
          if (changed >= 0 && newState[changed] === opponentSymbol) {
            boardState = newState;
            myTurn = data.current_turn === '<?php echo e($user['id'] ?? ''); ?>';
            render();
            updateStatus();
          }
          if (data.status && data.status !== '<?php echo e($room['status']); ?>') {
            location.reload();
          }
        }
      })
      .catch(() => {});
  }

  chatForm.addEventListener('submit', e => {
    e.preventDefault();
    const msg = chatInput.value.trim();
    if (!msg) return;
    chatInput.value = '';
    fetch('/api/room/chat/', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({room_id: '<?php echo e($room['id']); ?>', message: msg})
    }).then(() => pollChat());
  });

  function pollChat() {
    fetch('/api/room/chat/?room_id=<?php echo e($room['id']); ?>', {cache: 'no-store'})
      .then(r => r.json())
      .then(data => {
        if (data.ok && data.messages) {
          chatMessages.innerHTML = '';
          data.messages.forEach(msg => {
            const isMe = msg.user_id === '<?php echo e($user['id'] ?? ''); ?>';
            const div = document.createElement('div');
            div.className = 'chat-msg ' + (isMe ? 'me' : 'other');
            div.innerHTML = '<div class="meta"><span class="name">' + (isMe ? 'You' : (msg.username || 'Unknown')) + '</span><span class="time">' + new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) + '</span></div><div class="text">' + msg.message.replace(/</g,'<').replace(/>/g,'>') + '</div>';
            chatMessages.appendChild(div);
          });
          chatMessages.scrollTop = chatMessages.scrollHeight;
        }
      })
      .catch(() => {});
  }

  render();
  if (!gameOver && boardEl.dataset.status === 'in_progress') {
    startTimer();
    setInterval(pollUpdates, 2000);
    setInterval(pollChat, 5000);
  }
})();

function shareRoom(code, slug) {
  const url = window.location.origin + '/rooms/?code=' + code;
  if (navigator.share) {
    navigator.share({title: 'Drotix Room: ' + code, url: url});
  } else {
    navigator.clipboard.writeText(url).then(() => alert('Room link copied!'));
  }
}
</script>
<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>