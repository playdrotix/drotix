<?php
// DROTIX private-room invite page: /join/<CODE>, /join?code=<CODE>, /join?room=<CODE>
// Fallback when the Android app does not open the link itself (App Links).
// No database access: it only shows the code and buttons.

function drotix_clean_code($raw) {
    $c = strtoupper((string)$raw);
    $c = preg_replace('/[^A-Z0-9]/', '', $c);
    return substr($c, 0, 12);
}

$path = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/join', PHP_URL_PATH);
$code = '';
if (preg_match('#^/join/([^/]+)#i', $path, $m) && strtolower($m[1]) !== 'index.php') {
    $code = drotix_clean_code(rawurldecode($m[1]));
}
if ($code === '' && isset($_GET['code'])) $code = drotix_clean_code($_GET['code']);
if ($code === '' && isset($_GET['room'])) $code = drotix_clean_code($_GET['room']);

$package   = 'com.drodify.drotix';
$playUrl   = 'https://play.google.com/store/apps/details?id=' . $package;
$joinUrl   = 'https://playdrotix.in/join' . ($code !== '' ? '/' . $code : '');
$intentUrl = 'intent://playdrotix.in/join' . ($code !== '' ? '/' . $code : '')
           . '#Intent;scheme=https;package=' . $package
           . ';S.browser_fallback_url=' . rawurlencode($playUrl) . ';end';
$ogDesc    = $code !== '' ? "Room code $code · Tap to join" : 'Tap to join my private room on DROTIX';
$ogImage   = 'https://playdrotix.in/assets/img/og-image.png';

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Join my private room on DROTIX<?= $code !== '' ? ' · ' . h($code) : '' ?></title>
<meta name="description" content="<?= h($ogDesc) ?>">
<meta name="robots" content="noindex,follow">
<meta name="theme-color" content="#0A0A0C">
<link rel="canonical" href="<?= h($joinUrl) ?>">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:site_name" content="DROTIX">
<meta property="og:title" content="Join my private room on DROTIX">
<meta property="og:description" content="<?= h($ogDesc) ?>">
<meta property="og:image" content="<?= h($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="<?= h($joinUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Join my private room on DROTIX">
<meta name="twitter:description" content="<?= h($ogDesc) ?>">
<meta name="twitter:image" content="<?= h($ogImage) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0A0A0C;--card:#17171C;--border:#2A2A33;--text:#F5F5F7;--muted:#A8A8B5;--magenta:#D4156B;--teal:#2FB8B4}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;-webkit-font-smoothing:antialiased;
  background-image:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%)}
.card{width:100%;max-width:420px;background:var(--card);border:1px solid var(--border);border-radius:22px;padding:32px 24px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.brand{font-weight:800;font-size:28px;letter-spacing:.18em;color:var(--magenta);margin:0 0 18px;text-decoration:none;display:inline-block}
.eyebrow{font-size:13px;font-weight:600;letter-spacing:.2em;color:var(--teal);margin:0}
h1{font-size:22px;font-weight:800;letter-spacing:.04em;margin:6px 0 22px}
.code{font-size:44px;font-weight:800;letter-spacing:.22em;padding:16px 8px 16px calc(8px + .22em);border:2px dashed var(--teal);border-radius:16px;background:#0F0F13;margin:0 0 10px;word-break:break-all}
.code.empty{font-size:18px;letter-spacing:.05em;color:var(--muted);padding:16px}
.copy{background:none;border:0;color:var(--teal);font:600 14px 'Poppins',sans-serif;cursor:pointer;padding:8px 12px;margin-bottom:18px}
.btn{display:block;width:100%;padding:15px 18px;border-radius:14px;font:800 15px 'Poppins',sans-serif;letter-spacing:.06em;text-decoration:none;margin-top:12px;border:0;cursor:pointer}
.btn.primary{background:var(--magenta);color:#fff}
.btn.secondary{background:transparent;color:var(--text);border:2px solid var(--teal)}
.hint{color:var(--muted);font-size:13px;line-height:1.6;margin:20px 0 0}
[hidden]{display:none!important}
</style>
</head>
<body>
<main class="card">
  <a class="brand" href="/">DROTIX</a>
  <p class="eyebrow">YOU'RE INVITED</p>
  <h1>JOIN MY PRIVATE ROOM</h1>

  <?php if ($code !== ''): ?>
  <div class="code" id="code"><?= h($code) ?></div>
  <button class="copy" id="copyBtn" type="button">Copy code</button>
  <?php else: ?>
  <div class="code empty">Ask your friend for the room code</div>
  <?php endif; ?>

  <a class="btn primary" id="openApp" href="<?= h($intentUrl) ?>" hidden>OPEN IN APP</a>
  <a class="btn secondary" href="<?= h($playUrl) ?>" rel="noopener">GET DROTIX ON GOOGLE PLAY</a>

  <p class="hint">Install the app, then open this link again or enter the code in <strong>Join private room</strong>.</p>
</main>
<script>
(function () {
  // "Open in app" only on Android (intent:// URLs do not work on iOS / desktop). No auto-redirect.
  if (/Android/i.test(navigator.userAgent)) document.getElementById('openApp').hidden = false;

  var btn = document.getElementById('copyBtn');
  if (!btn) return;
  var code = <?= json_encode($code) ?>;
  btn.addEventListener('click', function () {
    function done() { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy code'; }, 1800); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(code).then(done, fallback);
    } else { fallback(); }
    function fallback() {
      var t = document.createElement('textarea'); t.value = code; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(t);
    }
  });
})();
</script>
</body>
</html>
