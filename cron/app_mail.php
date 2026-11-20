<?php
/*
 * DROTIX: emails for things players create INSIDE the app (the app writes straight to Supabase):
 *   - new support ticket  -> player gets "We got your ticket", admin gets an alert
 *   - new player report   -> reporter gets "Report received"
 * Run every minute from the server's crontab (command line only, not reachable from the web):
 *   * * * * * php /var/www/playdrotix/drotix/cron/app_mail.php >> /var/log/drotix-mail.log 2>&1
 * The first run only remembers "now" (no emails for old rows).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/../includes/app_admin_api.php';

$stateFile = __DIR__ . '/../includes/.cron_mail_state.json';
$state = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : array();
$now = gmdate('c');
$sent = array('tickets' => 0, 'reports' => 0, 'errors' => 0);
if (app_config_problem() !== '' || !app_schema()['ok']) { echo date('c') . " config problem: " . app_config_problem() . "\n"; exit(1); }

/* new support tickets */
$TT = app_ticket_table();
if ($TT && array_key_exists('created_at', app_cols($TT))) {
    if (empty($state['tickets'])) { $state['tickets'] = $now; }
    else {
        list($rows, , ) = app_select($TT, array('created_at' => 'gt.' . $state['tickets'], 'order' => 'created_at.asc'), 100, 0, false);
        foreach ($rows as $t) {
            list($ok, $err) = app_mail_ticket($t, 'received', 'open');
            $ok ? $sent['tickets']++ : $sent['errors']++;
            send_notice('New support ticket ' . app_ticket_ref($t), (string) g($t, 'subject', ''), array('kind' => 'support ticket ' . app_ticket_ref($t), 'name' => '-', 'email' => '-',
                'topic' => (string) g($t, 'category', '-'), 'message_excerpt' => (string) g($t, 'subject', ''), 'admin_path' => 'app/tickets/?id=' . g($t, 'id')));
            $state['tickets'] = (string) $t['created_at'];
        }
    }
}

/* new player reports */
$RT = app_has_table('reports') ? 'reports' : null;
if (!$RT) { foreach (app_tables() as $t => $i) { if (preg_match('/report/', $t) && !preg_match('/evidence|attach|log/', $t)) { $RT = $t; break; } } }
if ($RT && array_key_exists('created_at', app_cols($RT))) {
    $repCol = null; $tgtCol = null;
    foreach (app_user_cols($RT) as $c) {
        if (!$repCol && preg_match('/reporter|reported_by|created_by/', $c)) { $repCol = $c; }
        elseif (!$tgtCol && preg_match('/reported|target|accused|offender|against/', $c)) { $tgtCol = $c; }
    }
    if (empty($state['reports'])) { $state['reports'] = $now; }
    elseif ($repCol) {
        list($rows, , ) = app_select($RT, array('created_at' => 'gt.' . $state['reports'], 'order' => 'created_at.asc'), 100, 0, false);
        foreach ($rows as $x) {
            $name = '';
            if ($tgtCol && uuid_ok(g($x, $tgtCol))) { list($pp, , ) = app_select(app_profiles_table(), array(app_profile_idcol() => 'eq.' . $x[$tgtCol], 'select' => 'username'), 1, 0, false); $name = $pp ? (string) g($pp[0], 'username', '') : ''; }
            list($ok, $err) = app_mail_report($x[$repCol], (string) g($x, app_pk($RT) ?: 'id'), 'received', $name, 'Under review', 'Reason you gave: ' . (string) gx($x, array('reason', 'category'), '-'));
            $ok ? $sent['reports']++ : $sent['errors']++;
            $state['reports'] = (string) $x['created_at'];
        }
    }
}

@file_put_contents($stateFile, json_encode($state), LOCK_EX);
if ($sent['tickets'] || $sent['reports'] || $sent['errors']) { echo date('c') . ' ' . json_encode($sent) . "\n"; }
