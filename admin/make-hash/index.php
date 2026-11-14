<?php
/* One-time helper that turns a password into a hash for includes/config.php.
   It only works while 'enable_hash_tool' => true in config.php. Turn it off again afterwards. */
require __DIR__ . '/../../includes/bootstrap.php';
security_headers(true);
if (!cfg('enable_hash_tool', false)) { http_response_code(404); exit('Not found.'); }
$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && strlen($_POST['password']) >= 10) {
    $hash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Make password hash</title><link rel="stylesheet" href="/admin/assets/admin.css"></head>
<body><div class="login"><form class="box" method="post"><h1>Password hash</h1>
<div class="flash err">Turn this tool off after use: set 'enable_hash_tool' => false in config.php.</div>
<label>New admin password (10+ characters)<input name="password" type="text" minlength="10" required autocomplete="off"></label>
<button class="btn" type="submit">Create hash</button>
<?php if ($hash !== '') { echo '<label>Paste this into config.php<textarea readonly rows="3">' . e($hash) . '</textarea></label>'; } ?>
</form></div></body></html>
