<?php
/* Mobile App: send a notification to ALL active players. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $t = app_post('title', 80); $b = app_post('body', 500);
    if ($t === '' || $b === '') { flash_set('err', 'Title and message are required.'); redirect('/admin/app/broadcast/'); }
    if (strtoupper(trim(isset($_POST['confirm_word']) ? (string) $_POST['confirm_word'] : '')) !== 'SEND') { flash_set('err', 'Type SEND to confirm.'); redirect('/admin/app/broadcast/'); }
    @set_time_limit(300);
    $n = app_notify(null, $t, $b);
    if ($n['ok']) {
        app_log('broadcast', 'all', array('title' => $t, 'sent' => $n['count'], 'via' => $n['via']));
        flash_set('ok', 'Broadcast sent' . ($n['count'] !== null ? ' to ' . number_format((int) $n['count']) . ' players' : '') . ($n['via'] === 'game' ? ' (push + in-app).' : ' (in-app inbox).'));
    } else { flash_set('err', 'Broadcast failed: ' . $n['error'] . ($n['count'] ? ' (sent to ' . $n['count'] . ' before the error)' : '')); }
    redirect('/admin/app/broadcast/');
}

app_head('Broadcast', 'cast');
$nt = app_notif_table();
echo '<div class="warn"><b>Everyone gets this.</b> The message goes to <b>every player of the app</b> (push + in-app inbox). It is not for one player: to message one player, open Players &rarr; that player &rarr; Send notification. It cannot be recalled.</div>';
echo '<form method="post" class="panel" style="max-width:560px;display:grid;gap:12px" data-confirm1="Send this message to EVERY player of the app?" data-confirm2="Really send? This cannot be undone.">' . csrf_field();
echo '<label class="fl">Title<input name="title" required maxlength="80" placeholder="New tournament this Sunday!"></label>';
echo '<label class="fl">Message<textarea name="body" required maxlength="500"></textarea></label>';
echo '<label class="fl">Type SEND to confirm<input name="confirm_word" required autocomplete="off"></label>';
echo '<button class="btn" type="submit">Send to everyone</button></form>';

if ($nt) {
    $q = array(); if (array_key_exists('created_at', app_cols($nt))) { $q['order'] = 'created_at.desc'; }
    list($rows, , ) = app_select($nt, $q, 15, 0, false);
    echo '<h2>Latest notifications</h2>' . app_rows_table($rows, $nt, 'None yet.');
}
admin_foot();
