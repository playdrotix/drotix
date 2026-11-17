<?php
require __DIR__ . '/../includes/auth.php';
$user = require_login();
require_once __DIR__ . '/../includes/bootstrap.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $username = clean_str($_POST['username'] ?? '', 40);
        $email = strtolower(clean_str($_POST['email'] ?? '', 254));

        if (strlen($username) < 3) { $error = 'Username must be at least 3 characters.'; }
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { $error = 'Username can only contain letters, numbers, and underscores.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid email address.'; }
        elseif ($username !== $user['username'] && find_user_by_username($username)) { $error = 'Username already taken.'; }
        elseif ($email !== $user['email'] && find_user_by_email($email)) { $error = 'Email already in use.'; }

        if (!$error) {
            $r = update_user($user['id'], array('username' => $username, 'email' => $email));
            if ($r) {
                $_SESSION['user_username'] = $username;
                $_SESSION['user_email'] = $email;
                $success = 'Profile updated successfully!';
            } else { $error = 'Failed to update profile.'; }
        }
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$user['password_hash']) { $error = 'Cannot change password for Facebook accounts.'; }
        elseif (!verify_password($current, $user['password_hash'])) { $error = 'Current password is incorrect.'; }
        elseif (($v = validate_password($new)) !== true) { $error = $v; }
        elseif ($new !== $confirm) { $error = 'New passwords do not match.'; }

        if (!$error) {
            $r = update_user($user['id'], array('password_hash' => hash_password($new)));
            if ($r) { $success = 'Password changed successfully!'; }
            else { $error = 'Failed to change password.'; }
        }
    } elseif ($action === 'avatar') {
        $avatarUrl = clean_str($_POST['avatar_url'] ?? '', 500);
        if ($avatarUrl && !filter_var($avatarUrl, FILTER_VALIDATE_URL)) { $error = 'Invalid avatar URL.'; }
        else {
            $r = update_user($user['id'], array('avatar_url' => $avatarUrl ?: null));
            if ($r) { $_SESSION['user_avatar'] = $avatarUrl ?: null; $success = 'Avatar updated!'; }
            else { $error = 'Failed to update avatar.'; }
        }
    } elseif ($action === 'delete') {
        $confirm = $_POST['confirm_delete'] ?? '';
        if ($confirm !== 'DELETE') { $error = 'Type DELETE to confirm.'; }
        else {
            // Soft delete - mark as banned
            $r = update_user($user['id'], array('status' => 'banned', 'suspended_at' => gmdate('c')));
            if ($r) { user_logout(); header('Location: /'); exit; }
            else { $error = 'Failed to delete account.'; }
        }
    }
}

// Refresh user data
$fullUser = find_user_by_id($user['id']);
if ($fullUser) { $user = array_merge($user, $fullUser); }

$unreadCount = get_unread_notification_count($user['id']);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings · Drotix</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=ecd29af5">
<style>
.settings-page{padding:60px 0;min-height:100vh}
.page-head{margin-bottom:40px}
.page-head .tag{margin-bottom:12px}
.page-head h1{font-size:clamp(36px,6vw,64px);margin:14px 0 18px}
.settings-grid{display:grid;grid-template-columns:280px 1fr;gap:40px}
.settings-nav{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:20px;height:fit-content;position:sticky;top:100px}
.settings-nav ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.settings-nav a{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:12px;color:var(--muted);text-decoration:none;font-weight:600;transition:all .2s}
.settings-nav a:hover,.settings-nav a.active{color:var(--text);background:rgba(237,27,118,.1);border:1px solid var(--mageta)}
.settings-nav a svg{width:20px;height:20px;flex:none}
.settings-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:32px}
.settings-panel h2{font-size:20px;margin:0 0 8px}
.settings-panel > p{color:var(--muted);margin:0 0 24px}
.form-section{margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border2)}
.form-section:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
.form-section h3{font-size:14px;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin:0 0 16px}
.form-field{display:grid;gap:6px;margin-bottom:16px}
.form-field label{font-weight:600;font-size:13px;color:var(--muted)}
.form-field input{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:12px;padding:14px 16px;font:400 16px 'Outfit',sans-serif}
.form-field input:focus{outline:none;border-color:var(--mageta);box-shadow:0 0 0 3px rgba(237,27,118,.2)}
.btn-block{width:100%}
.danger-zone{background:rgba(255,122,154,.05);border:1px solid #FF7A9A;border-radius:12px;padding:24px;margin-top:32px}
.danger-zone h3{color:#FF7A9A}
.danger-zone .btn{background:#B3123E;border-color:#B3123E}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600}
.flash.ok{background:rgba(47,184,180,.15);border:1px solid var(--teal);color:var(--teal)}
.flash.err{background:rgba(255,122,154,.12);border:1px solid #FF7A9A;color:#FF7A9A}
.avatar-preview{width:100px;height:100px;border-radius:50%;border:3px solid var(--border2);background:var(--bg);display:grid;place-items:center;overflow:hidden;margin-bottom:16px}
.avatar-preview img{width:100%;height:100%;object-fit:cover}
@media(max-width:980px){.settings-grid{grid-template-columns:1fr}.settings-nav{position:static;display:none}}
</style>
</head>
<body>
<div class="topbar"><div class="container"><span class="dot" aria-hidden="true"></span><span>Settings</span><a href="/dashboard/" class="btn btn-ghost" style="height:36px;padding:0 16px;font-size:12px">Dashboard</a></div></div>

<header class="site-header" id="top"><div class="container nav"><a class="brand" href="/" aria-label="Drotix home"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><span>DROTIX<small>Tournament Gaming</small></span></a>
<ul><li><a href="/">Home</a></li><li><a href="/tournaments/">Tournaments</a></li><li><a href="/rooms/">Private Rooms</a></li><li><a href="/dashboard/" aria-current="page">Dashboard</a></li></ul>
<a class="btn btn-primary notif-bell" href="/notifications/" aria-label="Notifications"><svg width="20" height="20" aria-hidden="true"><use href="#i-mail"/></svg><?php if ($unreadCount > 0): ?><span class="cnt"><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span><?php endif; ?></a>
<button class="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button></div></header>

<main class="settings-page">
<div class="container">
  <div class="page-head reveal">
    <span class="tag">Account Settings</span>
    <h1>Settings</h1>
    <p class="lead">Manage your profile, password, and account preferences</p>
  </div>

  <div class="settings-grid">
    <nav class="settings-nav reveal">
      <ul>
        <li><a href="#profile" class="active" onclick="showTab('profile')"><svg aria-hidden="true"><use href="#i-edit"/></svg> Profile</a></li>
        <li><a href="#password" onclick="showTab('password')"><svg aria-hidden="true"><use href="#i-shield"/></svg> Password</a></li>
        <li><a href="#avatar" onclick="showTab('avatar')"><svg aria-hidden="true"><use href="#i-bot"/></svg> Avatar</a></li>
        <li><a href="#danger" onclick="showTab('danger')"><svg aria-hidden="true"><use href="#i-trash"/></svg> Danger Zone</a></li>
      </ul>
    </nav>

    <div class="settings-panel">
      <?php if ($error): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
      <?php if ($success): ?><div class="flash ok"><?php echo e($success); ?></div><?php endif; ?>

      <div id="tab-profile" class="tab-content active">
        <h2>Profile</h2>
        <p>Update your display name and email address</p>
        <form method="post" novalidate>
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="profile">
          <div class="form-field"><label>Username</label><input type="text" name="username" required minlength="3" maxlength="40" pattern="[a-zA-Z0-9_]+" value="<?php echo e($user['username']); ?>"></div>
          <div class="form-field"><label>Email</label><input type="email" name="email" required value="<?php echo e($user['email']); ?>"></div>
          <button class="btn btn-primary btn-block" type="submit">Save Changes</button>
        </form>
      </div>

      <div id="tab-password" class="tab-content" style="display:none">
        <h2>Password</h2>
        <p>Change your account password</p>
        <?php if ($user['provider'] === 'facebook'): ?>
        <div class="flash info">You signed up with Facebook. Password cannot be changed here.</div>
        <?php else: ?>
        <form method="post" novalidate>
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="password">
          <div class="form-field"><label>Current Password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="form-field"><label>New Password</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
          <div class="form-field"><label>Confirm New Password</label><input type="password" name="confirm_password" required autocomplete="new-password"></div>
          <button class="btn btn-primary btn-block" type="submit">Change Password</button>
        </form>
        <?php endif; ?>
      </div>

      <div id="tab-avatar" class="tab-content" style="display:none">
        <h2>Avatar</h2>
        <p>Set a custom avatar image URL (must be a direct link to an image)</p>
        <div class="avatar-preview" id="avatarPreview">
          <?php if ($user['avatar_url']): ?><img src="<?php echo e($user['avatar_url']); ?>" alt=""><?php else: ?><svg width="48" height="48" aria-hidden="true"><use href="#i-bot"/></svg><?php endif; ?>
        </div>
        <form method="post" novalidate>
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="avatar">
          <div class="form-field"><label>Avatar URL</label><input type="url" name="avatar_url" placeholder="https://example.com/avatar.png" value="<?php echo e($user['avatar_url'] ?? ''); ?>"></div>
          <div class="row"><button class="btn btn-primary" type="submit">Update Avatar</button><button class="btn btn-ghost" type="button" onclick="document.querySelector('[name=avatar_url]').value='';document.getElementById('avatarPreview').innerHTML='<svg width=\"48\" height=\"48\"><use href=\"#i-bot\"/></svg>'">Remove</button></div>
        </form>
      </div>

      <div id="tab-danger" class="tab-content" style="display:none">
        <h2>Danger Zone</h2>
        <div class="danger-zone">
          <h3>Delete Account</h3>
          <p style="color:var(--muted);margin:0 0 16px">Permanently delete your account and all data. This action cannot be undone.</p>
          <form method="post" novalidate onsubmit="return confirm('Are you absolutely sure? This will permanently delete your account!')">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <div class="form-field"><label>Type "DELETE" to confirm</label><input type="text" name="confirm_delete" required placeholder="DELETE"></div>
            <button class="btn btn-block" type="submit">Delete My Account</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</main>

<footer class="site-footer"><div class="f-marquee" aria-hidden="true"><div><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-circle"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-tri"/></svg><span>Calculate every move</span><svg class="msep" aria-hidden="true"><use href="#s-sq"/></svg></div></div>
<div class="container f-main"><div class="f-brand"><a class="brand" href="/"><img src="/assets/img/logo.svg" alt="" width="48" height="48"><span>DROTIX<small>Tournament Gaming</small></span></a><p>Drotix Tournament Gaming. The deadliest little game. Calculate every move.</p></div></div></footer>

<script>
function showTab(id) {
  document.querySelectorAll('.tab-content').forEach(function(el) { el.style.display = 'none'; });
  document.querySelectorAll('.settings-nav a').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('tab-' + id).style.display = 'block';
  document.querySelector('.settings-nav a[href="#' + id + '"]').classList.add('active');
}

document.getElementById('avatarPreview').addEventListener('click', function() {
  document.querySelector('[name="avatar_url"]').focus();
});

document.querySelector('[name="avatar_url"]').addEventListener('input', function() {
  var preview = document.getElementById('avatarPreview');
  var url = this.value.trim();
  if (url) { preview.innerHTML = '<img src="' + url + '" alt="">'; }
  else { preview.innerHTML = '<svg width="48" height="48"><use href="#i-bot"/></svg>'; }
});
</script>
<script src="/assets/js/main.js?v=9b2e1d4f" defer></script>
</body></html>