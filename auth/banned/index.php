<?php
require __DIR__ . '/../../includes/auth.php';
// Allow access even if logged in to show the message
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account Banned · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.auth-page{min-height:100vh;display:grid;place-items:center;padding:40px 20px;background:radial-gradient(circle at 100% 0,#3A0A24 0,transparent 55%),radial-gradient(circle at 0 100%,#0F2A29 0,transparent 45%),var(--bg)}
.auth-box{width:min(440px,100%);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px;box-shadow:0 20px 60px -20px rgba(237,27,118,.3);text-align:center}
.auth-icon{width:80px;height:80px;border-radius:50%;display:grid;place-items:center;margin:0 auto 24px;font-size:36px;background:rgba(255,122,154,.12);border:2px solid #FF7A9A;color:#FF7A9A}
h1{font-size:24px;margin:0 0 12px}
p{color:var(--muted);margin:0 0 24px;line-height:1.6}
.btn-group{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-box">
    <div class="auth-icon"><svg aria-hidden="true"><use href="#i-trash"/></svg></div>
    <h1>Account Banned</h1>
    <p>Your account has been permanently banned for violating our Terms of Use. If you believe this is a mistake, you can contact support.</p>
    <div class="btn-group">
      <a class="btn btn-primary" href="/contact/"><span>Contact Support</span></a>
      <a class="btn btn-ghost" href="/auth/logout/"><span>Sign Out</span></a>
    </div>
  </div>
</div>
</body></html>