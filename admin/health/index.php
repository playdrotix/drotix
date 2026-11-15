<?php
/* Setup check: tells you exactly what is missing in config.php or Supabase. */
require __DIR__ . '/../../includes/admin_layout.php';
admin_require_login();

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
admin_foot();
