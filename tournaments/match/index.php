<?php
require __DIR__ . '/../../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../../includes/bootstrap.php';

$matchId = $_GET['id'] ?? '';
if (!$matchId) { header('Location: /dashboard/'); exit; }

list($rows, , $r) = sb_select(tbl('tourney_matches'), array('id' => 'eq.' . $matchId, 'select' => '*'), 1, 0);
if (!sb_ok($r) || empty($rows)) { header('Location: /dashboard/'); exit; }
$match = $rows[0];

// Verify user is a participant
if ($match['player1_id'] !== $user['id'] && $match['player2_id'] !== $user['id']) {
    header('Location: /dashboard/');
    exit;
}

// Get opponent
$opponentId = $match['player1_id'] === $user['id'] ? $match['player2_id'] : $match['player1_id'];
$opponent = $opponentId ? find_user_by_id($opponentId) : null;

// Get tournament info
$tournament = find_user_by_id($match['tournament_id']) ?: null; // This should be tournament table
list($tRows, , ) = sb_select(tbl('tournaments'), array('id' => 'eq.' . $match['tournament_id']), 1, 0);
$tourney = $tRows ? $tRows[0] : null;

$unreadCount = get_unread_notification_count($user['id']);

// Determine player symbol (X or O)
$isPlayer1 = $match['player1_id'] === $user['id'];
$mySymbol = $isPlayer1 ? 'X' : 'O';
$opponentSymbol = $isPlayer1 ? 'O' : 'X';

// Initialize board state from match data
$boardState = $match['board_state'] ?? array_fill(0, $match['board_size'] * $match['board_size'], '');
$moveHistory = $match['move_history'] ?? array();
$currentTurn = $match['current_turn'];
$myTurn = $currentTurn === $user['id'];
$gameOver = in_array($match['status'], array('completed', 'walkover', 'cancelled'));

// Get chat messages
list($chatMessages, , ) = sb_select(tbl('tourney_chat'), array('match_id' => 'eq.' . $matchId, 'order' => 'created_at.asc'), 100, 0);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Match: <?php echo e($tourney ? $tourney['name'] : 'Tournament Match'); ?> · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<link rel="stylesheet" href="/assets/css/game.css?v=3">
<style>
.match-page{padding:20px 0;min-height:100vh;background:var(--bg)}
.match-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.match-info{display:flex;align-items:center;gap:16px;flex:1}
.match-badge{padding:6px 12px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid;text-transform:uppercase;letter-spacing:1px}
.match-badge.pending{color:var(--muted);border-color:var(--border2)}
.match-badge.ready{color:var(--gold);border-color:var(--gold)}
.match-badge.in_progress{color:var(--mageta);border-color:var(--mageta);animation:pulse 2s infinite}
.match-badge.completed{color:var(--teal);border-color:var(--teal)}
.match-badge.walkover{color:#FF7A9A;border-color:#FF7A9A}
.match-players{display:flex;align-items:center;gap:20px}
.match-player{display:flex;align-items:center;gap:10px}
.match-player .avatar{width:44px;height:44px;border-radius:50%;border:2px solid var(--border2);background:var(--surface);display:grid;place-items:center;overflow:hidden;flex:none}
.match-player .avatar img{width:100%;height:100%;object-fit:cover}
.match-player .avatar svg{width:22px;height:22px;color:var(--text)}
.match-player .name{font-weight:800;font-size:14px}
.match-player .symbol{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:12px;flex:none}
.match-player .symbol.x{background:rgba(237,27,118,.2);color:var(--mageta);border:2px solid var(--mageta)}
.match-player .symbol.o{background:rgba(47,184,180,.2);color:var(--teal);border:2px solid var(--teal)}
.match-player.me .name::after{content:'(You)';font-size:11px;font-weight:400;color:var(--teal);margin-left:6px}
.match-vs{color:var(--muted);font-weight:800;letter-spacing:2px;font-size:14px}
.match-timer{font-size:28px;font-weight:800;color:var(--mageta);font-variant-numeric:tabular-nums;font-family:'Outfit',monospace}
.match-main{display:grid;grid-template-columns:1fr 340px;gap:24px;margin-top:24px}
.match-board-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px}
.match-board{display:grid;gap:8px;margin:0 auto;max-width:480px}
.match-cell{aspect-ratio:1;background:var(--bg);border:2px solid var(--border2);border-radius:12px;display:grid;place-items:center;cursor:pointer;transition:all .15s;font-size:0}
.match-cell:hover:not(.filled){border-color:var(--mageta);background:rgba(237,27,118,.05)}
.match-cell.filled{cursor:default}
.match-cell.winning{animation:winPulse 1s ease-in-out infinite;box-shadow:0 0 20px rgba(237,27,118,.5)}
.match-cell svg{width:60%;height:60%}
.match-cell.x svg{stroke:var(--mageta)}
.match-cell.o svg{stroke:var(--teal)}
@keyframes winPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.match-side{display:flex;flex-direction:column;gap:20px}
.match-chat{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);display:flex;flex-direction:column;height:500px;max-height:500px}
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
.match-status{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px;text-align:center}
.match-status h3{font-size:18px;margin:0 0 8px}
.match-status.pending h3{color:var(--muted)}
.match-status.ready h3{color:var(--gold)}
.match-status.in_progress h3{color:var(--mageta)}
.match-status.completed h3{color:var(--teal)}
.match-status .timer-bar{height:6px;background:var(--border2);border-radius:3px;overflow:hidden;margin-top:16px}
.match-status .timer-bar .fill{height:100%;background:linear-gradient(90deg,var(--mageta),var(--teal));border-radius:3px;transition:width .2s linear}
.match-result{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;text-align:center;margin-top:20px}
.match-result .icon{width:80px;height:80px;border-radius:50%;display:grid;place-items:center;margin:0 auto 16px;font-size:36px}
.match-result.win .icon{background:rgba(47,184,180,.15);border:3px solid var(--teal);color:var(--teal)}
.match-result.lose .icon{background:rgba(255,122,154,.12);border:3px solid #FF7A9A;color:#FF7A9A}
.match-result.draw .icon{background:rgba(255,194,77,.15);border:3px solid var(--gold);color:var(--gold)}
.match-result h2{font-size:24px;margin:0 0 8px}
.match-result p{color:var(--muted);margin:0 0 20px}
.match-nav{display:flex;gap:12px;justify-content:center;margin-top:24px}
@media(max-width:980px){.match-main{grid-template-columns:1fr}.match-chat{height:400px;order:-1}}
@media(max-width:600px){.match-players{flex-wrap:wrap}.match-vs{width:100%;text-align:center;margin:8px 0}.match-board{max-width:100%}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span><?php echo e($tourney ? $tourney['name'] : 'Tournament Match'); ?></span><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="match-page">
<div class="container">
  <div class="match-header">
    <div class="match-info">
      <span class="match-badge <?php echo e($match['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $match['status']))); ?></span>
      <div class="match-players">
        <div class="match-player <?php echo $isPlayer1 ? 'me' : ''; ?>">
          <div class="avatar"><?php if ($user['avatar_url']): ?><img src="<?php echo e($user['avatar_url']); ?>" alt=""><?php else: ?><svg aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
          <div class="name"><?php echo e($user['username']); ?></div>
          <div class="symbol <?php echo $mySymbol === 'X' ? 'x' : 'o'; ?>"><?php echo e($mySymbol); ?></div>
        </div>
        <span class="match-vs">VS</span>
        <div class="match-player <?php echo !$isPlayer1 ? 'me' : ''; ?>">
          <div class="symbol <?php echo $opponentSymbol === 'X' ? 'x' : 'o'; ?>"><?php echo e($opponentSymbol); ?></div>
          <div class="name"><?php echo $opponent ? e($opponent['username']) : 'Waiting...'; ?></div>
          <div class="avatar"><?php if ($opponent && $opponent['avatar_url']): ?><img src="<?php echo e($opponent['avatar_url']); ?>" alt=""><?php else: ?><svg aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?></div>
        </div>
      </div>
    </div>
    <?php if (!$gameOver && in_array($match['status'], array('ready', 'in_progress'))): ?>
    <div class="match-timer" id="matchTimer" data-time="<?php echo (int)$match['turn_time_seconds']; ?>"><?php echo (int)$match['turn_time_seconds']; ?></div>
    <?php endif; ?>
  </div>

  <div class="match-main">
    <div class="match-board-wrap">
      <?php if ($gameOver): ?>
        <?php
        $result = 'draw';
        $resultMsg = 'Match ended in a draw.';
        if ($match['winner_id']) {
            if ($match['winner_id'] === $user['id']) { $result = 'win'; $resultMsg = 'You won the match!'; }
            else { $result = 'lose'; $resultMsg = 'You lost the match.'; }
        }
        ?>
        <div class="match-result <?php echo $result; ?>">
          <div class="icon">
            <?php if ($result === 'win'): ?><svg aria-hidden="true"><use href="#i-shield"/></svg>
            <?php elseif ($result === 'lose'): ?><svg aria-hidden="true"><use href="#i-trash"/></svg>
            <?php else: ?><svg aria-hidden="true"><use href="#i-target"/></svg><?php endif; ?>
          </div>
          <h2><?php echo $result === 'win' ? 'Victory!' : ($result === 'lose' ? 'Eliminated' : 'Draw'); ?></h2>
          <p><?php echo $resultMsg; ?></p>
          <div class="match-nav">
            <a class="btn btn-primary" href="/tournaments/?slug=<?php echo e($tourney['slug'] ?? ''); ?>">View Bracket</a>
            <a class="btn btn-ghost" href="/dashboard/">Dashboard</a>
          </div>
        </div>
      <?php else: ?>
        <div class="match-board" id="matchBoard" style="grid-template-columns:repeat(<?php echo (int)$match['board_size']; ?>,1fr);" data-size="<?php echo (int)$match['board_size']; ?>" data-win="<?php echo (int)$match['win_condition']; ?>" data-my-symbol="<?php echo e($mySymbol); ?>" data-my-turn="<?php echo $myTurn ? 'true' : 'false'; ?>" data-status="<?php echo e($match['status']); ?>"></div>
        <div class="match-status <?php echo e($match['status']); ?>" id="matchStatus" style="margin-top:20px">
          <?php if ($match['status'] === 'pending'): ?>
            <h3>Waiting for tournament to start...</h3>
          <?php elseif ($match['status'] === 'ready'): ?>
            <h3>Match ready! Waiting for both players to join.</h3>
            <div class="timer-bar"><div class="fill" style="width:100%"></div></div>
          <?php elseif ($match['status'] === 'in_progress'): ?>
            <h3 id="turnText"><?php echo $myTurn ? 'Your turn' : "Opponent's turn"; ?></h3>
            <div class="timer-bar"><div class="fill" id="timerFill" style="width:100%"></div></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="match-side">
      <div class="match-chat" id="matchChat">
        <div class="chat-header">
          <h3>Match Chat</h3>
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
          <div style="text-align:center;color:var(--muted);padding:40px;font-size:13px">No messages yet. Say hello to your opponent!</div>
          <?php endif; ?>
        </div>
        <form class="chat-input" id="chatForm">
          <input type="text" name="message" id="chatInput" placeholder="Type a message..." maxlength="500" autocomplete="off" <?php echo $gameOver ? 'disabled' : ''; ?>>
          <button type="submit" <?php echo $gameOver ? 'disabled' : ''; ?>>Send</button>
        </form>
      </div>

      <div class="match-status" id="matchInfo">
        <h3>Match Info</h3>
        <p style="color:var(--muted);font-size:13px;margin:12px 0">
          Round: <b><?php echo (int)$match['round']; ?></b> •
          Match: <b>#<?php echo (int)$match['match_number']; ?></b> •
          Board: <b><?php echo (int)$match['board_size']; ?>×<?php echo (int)$match['board_size']; ?></b>
        </p>
        <p style="color:var(--muted);font-size:13px">
          Win: <b><?php echo (int)$match['win_condition']; ?> in a row</b> •
          Turn time: <b><?php echo (int)$match['turn_time_seconds']; ?>s</b>
        </p>
      </div>
    </div>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script>
// Match game logic
(function() {
  const boardEl = document.getElementById('matchBoard');
  const statusEl = document.getElementById('matchStatus');
  const turnText = document.getElementById('turnText');
  const timerFill = document.getElementById('timerFill');
  const matchTimer = document.getElementById('matchTimer');
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
  let turnTime = <?php echo (int)$match['turn_time_seconds']; ?>;
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
      cell.className = 'match-cell' + (v ? ' filled' : '');
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

    // Send move to server
    fetch('/api/tournament/move/', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({match_id: '<?php echo e($matchId); ?>', position: index, symbol: mySymbol})
    }).catch(() => {});
  }

  function updateStatus() {
    const winner = checkWinner(boardState);
    const empty = boardState.some(v => !v);

    if (winner) {
      gameOver = true;
      clearInterval(timerInterval);
      if (turnText) turnText.textContent = winner.player === mySymbol ? 'You won!' : 'You lost!';
      statusEl.className = 'match-status ' + (winner.player === mySymbol ? 'completed' : 'completed');
      setTimeout(() => location.reload(), 2000);
    } else if (!empty) {
      gameOver = true;
      clearInterval(timerInterval);
      if (turnText) turnText.textContent = 'Draw!';
      statusEl.className = 'match-status completed';
    } else {
      if (turnText) turnText.textContent = myTurn ? 'Your turn' : "Opponent's turn";
      startTimer();
    }
  }

  function startTimer() {
    clearInterval(timerInterval);
    timeLeft = turnTime;
    if (matchTimer) matchTimer.textContent = timeLeft;
    if (timerFill) timerFill.style.width = '100%';

    timerInterval = setInterval(() => {
      timeLeft -= 0.2;
      if (matchTimer) matchTimer.textContent = Math.ceil(timeLeft);
      if (timerFill) timerFill.style.width = Math.max(0, (timeLeft / turnTime) * 100) + '%';
      if (timeLeft <= 0) {
        clearInterval(timerInterval);
        // Auto-move for opponent if it's their turn and they're AFK
        if (!myTurn) {
          const emptyCells = boardState.map((v,i) => v ? -1 : i).filter(i => i >= 0);
          if (emptyCells.length) {
            // Server will handle auto-move
          }
        }
      }
    }, 200);
  }

  // Poll for opponent moves
  function pollUpdates() {
    if (gameOver) return;
    fetch('/api/tournament/match/?id=<?php echo e($matchId); ?>', {cache: 'no-store'})
      .then(r => r.json())
      .then(data => {
        if (data.ok && data.board_state) {
          const newState = data.board_state;
          const changed = newState.findIndex((v,i) => v !== boardState[i]);
          if (changed >= 0 && newState[changed] === opponentSymbol) {
            boardState = newState;
            myTurn = data.current_turn === '<?php echo e($user['id']); ?>';
            render();
            updateStatus();
          }
          if (data.status && data.status !== '<?php echo e($match['status']); ?>') {
            location.reload();
          }
        }
      })
      .catch(() => {});
  }

  // Chat
  chatForm.addEventListener('submit', e => {
    e.preventDefault();
    const msg = chatInput.value.trim();
    if (!msg) return;
    chatInput.value = '';
    fetch('/api/tournament/chat/', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({match_id: '<?php echo e($matchId); ?>', message: msg})
    }).then(() => pollChat());
  });

  function pollChat() {
    fetch('/api/tournament/chat/?match_id=<?php echo e($matchId); ?>', {cache: 'no-store'})
      .then(r => r.json())
      .then(data => {
        if (data.ok && data.messages) {
          chatMessages.innerHTML = '';
          data.messages.forEach(msg => {
            const isMe = msg.user_id === '<?php echo e($user['id']); ?>';
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
</script>
<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>