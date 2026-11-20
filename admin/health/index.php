<?php
/* Setup check: tells you exactly what is missing in config.php or Supabase. */
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();
require_once __DIR__ . '/../../includes/mailer.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_mail') {
    csrf_verify();
    $to = clean_str(isset($_POST['to']) ? $_POST['to'] : '', 200);
    list($ok, $err) = mail_send_template($to, 'contact_reply', 'DROTIX test email', array('name' => 'Admin', 'topic' => 'Test email', 'reply' => "This is a test email from the DROTIX admin panel.\nIf you can read this, SMTP works.", 'message_excerpt' => 'Setup check'));
    audit_log('test_email', $to, $ok ? 'sent' : $err);
    flash_set($ok ? 'ok' : 'err', $ok ? 'Test email sent to ' . $to . '. Check the inbox (and the spam folder).' : 'Test email failed: ' . $err);
    redirect('/admin/health/');
}

function row($ok, $title, $detail = '') {
    echo '<tr><td style="width:90px">' . ($ok === true ? '<span class="badge b-teal">OK</span>' : ($ok === null ? '<span class="badge b-gold">CHECK</span>' : '<span class="badge b-red">FIX</span>')) . '</td><td><b>' . e($title) . '</b>' . ($detail !== '' ? '<div class="muted">' . $detail . '</div>' : '') . '</td></tr>';
}
admin_head('Setup check', 'chk');
echo '<div class="tbl-wrap"><table>';

/* 1. key */
list($u, $k) = sb_source();
$kind = sb_key_kind($k);
row(sb_configured(), 'Supabase URL and key are filled in in includes/config.php');
row($kind[1], 'Key type: ' . $kind[0], e($kind[2]) . ($kind[1] ? '' : '<br>Supabase dashboard &rarr; Project Settings &rarr; API Keys &rarr; copy the <b>Secret key</b> (sb_secret_...) into <code>supabase_key</code>. Never put it in HTML/JS and never share it.'));

/* 2. tables + columns */
$checks = array(
    array('contact',    'contact_messages',         'id,visitor_id', 'v3 block: column visitor_id'),
    array('tournament', 'tournament_registrations', 'id,visitor_id', 'v2 + v3 blocks'),
    array('notify',     'site_notifications',       'id,visitor_id,title,body,read_at', 'v3 block'),
    array('presence',   'site_presence',            'id,visitor_id,last_seen', 'v3 block'),
    array('visits',     'site_visits',              'id,visitor_id,is_new', 'v2 block'),
    array('waitlist',   'waitlist_signups',         'id', 'first schema'),
    array('audit',      'admin_audit_log',          'id', 'first schema'),
);
foreach ($checks as $c) {
    $name = tbl($c[0]);
    $r = sb_request('GET', $name, array('select' => $c[2], 'limit' => 1));
    $ok = sb_ok($r);
    row($ok, 'Table ' . $name . ' (' . $c[2] . ')', $ok ? '' : e($r['error']) . '<br>Run the <b>' . e($c[3]) . '</b> of database/schema.sql in Supabase &rarr; SQL Editor. Also check <code>tables</code> in config.php has the line for \'' . e($c[0]) . '\'.');
}

/* 3. write test (insert a notification, then delete it) */
$tn = tbl('notify');
$w = sb_request('POST', $tn, array(), array('visitor_id' => 'healthcheck0000', 'kind' => 'info', 'title' => 'health check'), array('Prefer' => 'return=representation'));
if (sb_ok($w) && is_array($w['data']) && !empty($w['data'][0]['id'])) {
    sb_delete($tn, array('id' => 'eq.' . $w['data'][0]['id']));
    row(true, 'Can write notifications (insert + delete test)');
} else {
    row(false, 'Cannot write notifications', e($w['error']) . '<br>Usually the wrong key (publishable instead of secret) or the table is missing.');
}

/* 4. do real messages carry a visitor id? */
$tot = sb_count(tbl('contact'));
$withId = sb_count(tbl('contact'), array('visitor_id' => 'not.is.null'));
if ($tot === null || $withId === null) {
    row(null, 'Messages with a visitor ID', 'Could not read contact_messages / visitor_id column.');
} elseif ($tot === 0) {
    row(null, 'No messages yet', 'Send a test message from the live Contact page, then reload this page.');
} else {
    row($withId > 0 ? true : false, 'Messages with a visitor ID: ' . $withId . ' of ' . $tot,
        $withId > 0 ? 'Replies to these messages pop up on the website. Older messages (without an ID) can only be answered by email.'
                    : 'None yet. Upload the v3/v4 files (main.js, api/submit), clear the cache (Cloudflare too) and send a NEW message from the live site.');
}
$cfgOrigins = cfg('allowed_origins', array());
row(null, 'allowed_origins: ' . (is_array($cfgOrigins) && $cfgOrigins ? e(implode(', ', $cfgOrigins)) : 'not set'), 'The site address you open in the browser must be in this list (with https://), otherwise forms and notifications are refused.');
echo '</table></div>';

/* email (SMTP) */
$smtp = cfg('smtp', array());
$smtpOn = !empty($smtp['host']) && !empty($smtp['password']) && strpos((string) $smtp['password'], 'PASTE_') !== 0;
echo '<h2>Email (SMTP)</h2><div class="tbl-wrap"><table>';
row($smtpOn ? true : false, $smtpOn ? 'SMTP: ' . e($smtp['host']) . ':' . e(isset($smtp['port']) ? $smtp['port'] : 587) . ' as ' . e(isset($smtp['username']) ? $smtp['username'] : '') : 'SMTP is not set up',
    $smtpOn ? 'Emails are sent from ' . e(isset($smtp['from_name']) ? $smtp['from_name'] : 'DROTIX') . ' &lt;' . e(isset($smtp['from_email']) ? $smtp['from_email'] : $smtp['username']) . '&gt;.' : 'Add the <code>smtp</code> block to includes/config.php on the server. Until then PHP mail() is used, which often does not arrive.');
row(null, 'Templates', e(implode(', ', array_map(function ($f) { return basename($f, '.html'); }, array_filter(glob(__DIR__ . '/../../includes/emails/*.html'), function ($f) { return basename($f) !== 'preview.html'; })))));
echo '</table></div>';
echo '<form method="post" class="panel row" style="margin-top:14px">' . csrf_field() . '<input type="hidden" name="action" value="test_mail"><input name="to" type="email" required placeholder="Send a test email to…" style="flex:1;min-width:240px" value="' . e((string) cfg('notify_admin_email', '')) . '"><button class="btn" type="submit">Send test email</button></form>';
admin_foot();
