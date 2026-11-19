<?php
/* App admin: send a notification to ALL active players (push + in-app). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $t = app_post('title', 80); $b = app_post('body', 500);
    if ($t === '' || $b === '') { flash_set('err', 'Title and message are required.'); redirect('/admin/app/broadcast/'); }
    if (!isset($_POST['confirm_word']) || strtoupper(trim((string) $_POST['confirm_word'])) !== 'SEND') { flash_set('err', 'Type SEND to confirm.'); redirect('/admin/app/broadcast/'); }
    $r = app_send_notification(null, $t, $b);
    $n = $r['ok'] ? gx($r['data'], array('sent', 'recipients', 'count')) : null;
    app_flash_result($r, 'Broadcast sent' . ($n !== null ? ' to ' . app_num($n) . ' players' : '') . '.');
    redirect('/admin/app/broadcast/');
}

app_head('Broadcast', 'cast');
echo '<div class="warn"><b>Everyone gets this.</b> The notification goes to <b>all active players</b> as a push notification and in the app inbox. It cannot be recalled.</div>';
echo '<form method="post" class="panel" style="max-width:560px;display:grid;gap:12px" data-confirm1="Send this notification to ALL active players?" data-confirm2="Really send? This cannot be undone.">' . csrf_field();
echo '<label style="display:grid;gap:6px;font-weight:600;font-size:13px;color:var(--m)">Title<input name="title" required maxlength="80" placeholder="New tournament this Sunday!"></label>';
echo '<label style="display:grid;gap:6px;font-weight:600;font-size:13px;color:var(--m)">Message<textarea name="body" required maxlength="500"></textarea></label>';
echo '<label style="display:grid;gap:6px;font-weight:600;font-size:13px;color:var(--m)">Type SEND to confirm<input name="confirm_word" required autocomplete="off" pattern="[Ss][Ee][Nn][Dd]"></label>';
echo '<button class="btn" type="submit">Send to everyone</button></form>';
admin_foot();
