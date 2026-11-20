<?php
/*
 * POST /api/submit/   (called by the website forms)
 * types: contact | waitlist | delete
 * Saves to Supabase through includes/supabase.php. Returns JSON {ok, message}.
 */
require __DIR__ . '/../../includes/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers(false);

function out($code, $ok, $message) {
    http_response_code($code);
    echo json_encode(array('ok' => $ok, 'message' => $message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(405, false, 'Method not allowed.'); }

/* only accept forms from our own site */
$allowed = cfg('allowed_origins', array());
if (is_array($allowed) && count($allowed) > 0) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $p = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($p['scheme']) && !empty($p['host'])) {
            $origin = $p['scheme'] . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '');
        }
    }
    if ($origin === '' || !in_array(rtrim($origin, '/'), $allowed, true)) { out(403, false, 'Request not allowed.'); }
}

/* read JSON or normal form data */
$in = array();
$ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (stripos($ct, 'application/json') !== false) {
    $j = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($j)) { $in = $j; }
} else {
    $in = $_POST;
}
$type = isset($in['type']) ? clean_str($in['type'], 20) : '';
if (!in_array($type, array('contact', 'waitlist', 'delete', 'tournament', 'visit', 'ping'), true)) { out(400, false, 'Unknown form.'); }

/* anonymous browser id (random, made by assets/js/track.js) */
$vidIn = preg_replace('/[^A-Za-z0-9_-]/', '', clean_str(isset($in['vid']) ? $in['vid'] : '', 64));
if (strlen($vidIn) < 8) { $vidIn = null; }
function presence_touch($vid, $path) {
    if (!$vid) { return; }
    sb_insert(tbl('presence'), array('visitor_id' => $vid, 'last_seen' => gmdate('c'), 'path' => $path),
        array('on_conflict' => 'visitor_id'), 'resolution=merge-duplicates,return=minimal');
}

/* ---- heartbeat: keeps the visitor counted as "online" for the next 2 minutes ---- */
if ($type === 'ping') {
    $rlKey = 'ping:' . client_ip();
    if (rl_count($rlKey, 600) >= 200) { out(200, true, 'ok'); }
    rl_add($rlKey, 600);
    presence_touch($vidIn, clean_str(isset($in['path']) ? $in['path'] : '', 200));
    out(200, true, 'ok');
}


/* ---- site visit (no email, no spam timer; its own, higher rate limit) ---- */
if ($type === 'visit') {
    $ip = client_ip();
    $rlKey = 'visit:' . $ip;
    if (rl_count($rlKey, 600) >= 200) { out(200, true, 'ok'); }
    rl_add($rlKey, 600);
    $vid = preg_replace('/[^A-Za-z0-9_-]/', '', clean_str(isset($in['vid']) ? $in['vid'] : '', 64));
    if (strlen($vid) < 8) { out(200, true, 'ok'); }
    $ua = clean_str(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 250);
    if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless/i', $ua)) { out(200, true, 'ok'); }
    $dev = clean_str(isset($in['device']) ? $in['device'] : '', 12);
    if (!in_array($dev, array('mobile', 'tablet', 'desktop'), true)) { $dev = ''; }
    sb_insert(tbl('visits'), array(
        'visitor_id' => $vid,
        'session_id' => preg_replace('/[^A-Za-z0-9_-]/', '', clean_str(isset($in['sid']) ? $in['sid'] : '', 64)),
        'is_new'     => !empty($in['is_new']),
        'path'       => clean_str(isset($in['path']) ? $in['path'] : '', 200),
        'referrer'   => clean_str(isset($in['ref']) ? $in['ref'] : '', 250),
        'device'     => $dev,
        'language'   => clean_str(isset($in['lang']) ? $in['lang'] : '', 20),
        'screen'     => clean_str(isset($in['screen']) ? $in['screen'] : '', 20),
        'ip'         => $ip, 'user_agent' => $ua,
    ));
    presence_touch($vid, clean_str(isset($in['path']) ? $in['path'] : '', 200));
    out(200, true, 'ok');
}

/* spam traps: hidden field + sent-too-fast. Pretend success so bots learn nothing. */
if (!empty($in['website'])) { out(200, true, 'Thank you!'); }
if (isset($in['elapsed']) && is_numeric($in['elapsed']) && (int) $in['elapsed'] < 1500) { out(200, true, 'Thank you!'); }

/* rate limit per visitor and form type: 5 per 10 minutes */
$ip = client_ip();
$rlKey = 'submit:' . $type . ':' . $ip;
if (rl_count($rlKey, 600) >= 5) { out(429, false, 'Too many attempts. Please wait a few minutes and try again.'); }
rl_add($rlKey, 600);

$email = strtolower(clean_str(isset($in['email']) ? $in['email'] : '', 254));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { out(422, false, 'Please enter a valid email address.'); }
$ua = clean_str(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 250);
$support = (string) cfg('support_email', '');
$failMsg = 'We could not save this right now. Please email us at ' . $support . '.';

if ($type === 'contact') {
    $name = clean_str(isset($in['name']) ? $in['name'] : '', 80);
    $topic = clean_str(isset($in['topic']) ? $in['topic'] : '', 80);
    $message = clean_str(isset($in['message']) ? $in['message'] : '', 4000);
    $consent = isset($in['consent']) ? (string) $in['consent'] : '';
    if (strlen($name) < 2) { out(422, false, 'Please enter your name.'); }
    if (strlen($message) < 10) { out(422, false, 'Please write a little more in your message.'); }
    if ($consent !== 'yes') { out(422, false, 'Please tick the consent box.'); }
    $r = sb_insert(tbl('contact'), array(
        'name' => $name, 'email' => $email, 'topic' => $topic, 'message' => $message,
        'status' => 'new', 'ip' => $ip, 'user_agent' => $ua, 'visitor_id' => $vidIn,
    ));
    if (!sb_ok($r)) { error_log('[drotix] contact insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
    send_notice('New contact message: ' . $topic, "From: $name <$email>\nTopic: $topic\n\n$message\n",
        array('kind' => 'contact message', 'name' => $name, 'email' => $email, 'topic' => $topic !== '' ? $topic : '-', 'message_excerpt' => mb_substr($message, 0, 800), 'admin_path' => 'messages/'));
    require_once __DIR__ . '/../../includes/mailer.php';
    mail_send_template($email, 'contact_received', 'We got your message - DROTIX', array('name' => $name, 'message_excerpt' => mb_substr($message, 0, 400)));
    out(200, true, 'Thank you! We got your message. Our reply will pop up here on the website (bell icon) and may also reach your email.');
}


if ($type === 'tournament') {
    $name = clean_str(isset($in['name']) ? $in['name'] : '', 80);
    $phone = preg_replace('/[^0-9+]/', '', clean_str(isset($in['phone']) ? $in['phone'] : '', 20));
    $username = clean_str(isset($in['username']) ? $in['username'] : '', 40);
    $city = clean_str(isset($in['city']) ? $in['city'] : '', 80);
    $exp = clean_str(isset($in['experience']) ? $in['experience'] : '', 20);
    if (!in_array($exp, array('beginner', 'intermediate', 'pro'), true)) { $exp = 'beginner'; }
    $consent = isset($in['consent']) ? (string) $in['consent'] : '';
    if (strlen($name) < 2) { out(422, false, 'Please enter your full name.'); }
    if (strlen(preg_replace('/\D/', '', $phone)) < 10) { out(422, false, 'Please enter a valid phone / WhatsApp number.'); }
    if (strlen($username) < 2) { out(422, false, 'Please choose a player name.'); }
    if ($consent !== 'yes') { out(422, false, 'Please tick the consent box.'); }
    $r = sb_insert(tbl('tournament'), array(
        'tournament' => 'Drotix Cup Season 1', 'name' => $name, 'email' => $email, 'phone' => $phone,
        'username' => $username, 'city' => $city, 'experience' => $exp,
        'status' => 'new', 'ip' => $ip, 'user_agent' => $ua, 'visitor_id' => $vidIn,
    ), array('on_conflict' => 'tournament,email'), 'resolution=ignore-duplicates,return=minimal');
    if (!sb_ok($r)) { error_log('[drotix] tournament insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
    send_notice('New tournament registration: ' . $username, "Name: $name\nPlayer: $username\nEmail: $email\nPhone: $phone\nCity: $city\nLevel: $exp\n",
        array('kind' => 'tournament registration', 'name' => $name, 'email' => $email, 'topic' => 'Drotix Cup Season 1', 'message_excerpt' => "Player: $username | Phone: $phone | City: $city | Level: $exp", 'admin_path' => 'tournament/'));
    require_once __DIR__ . '/../../includes/mailer.php';
    mail_send_template($email, 'tournament_status', 'Drotix Cup: registration received', array('name' => $name, 'username' => $username, 'status' => 'Registered', 'tournament' => 'Drotix Cup Season 1',
        'starts_at' => 'Date coming soon', 'message' => 'You are registered! We will contact you on WhatsApp / email with the match details.'));
    out(200, true, 'You are registered for Drotix Cup! We will contact you on WhatsApp / email with the match details.');
}

if ($type === 'waitlist') {
    $source = clean_str(isset($in['source']) ? $in['source'] : 'website', 40);
    // return=representation: the row comes back only when it is NEW (a repeat signup returns nothing -> no second email)
    $r = sb_insert(tbl('waitlist'),
        array('email' => $email, 'source' => $source, 'ip' => $ip),
        array('on_conflict' => 'email'),
        'resolution=ignore-duplicates,return=representation');
    if (!sb_ok($r)) { error_log('[drotix] waitlist insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
    $isNew = is_array($r['data']) && count($r['data']) > 0;
    if ($isNew) {
        require_once __DIR__ . '/../../includes/mailer.php';
        $pos = sb_count(tbl('waitlist'));
        $vars = array('name' => 'player', 'email' => $email, 'position' => $pos !== null ? number_format($pos) : '-');
        if ($source === 'footer') {   // footer "Join" (every page): players list / news
            mail_send_template($email, 'club_welcome', 'Welcome to DROTIX, player #' . $vars['position'], $vars);
        } else {                      // home "Notify me": launch alert
            mail_send_template($email, 'waitlist_welcome', 'Launch alert set: DROTIX is coming', $vars);
        }
    }
    out(200, true, $source === 'footer' ? 'You are in! Check your inbox for a welcome email.' : 'You are on the list! We will email you when the game launches.');
}

/* type === 'delete' */
$username = clean_str(isset($in['username']) ? $in['username'] : '', 60);
$notes = clean_str(isset($in['notes']) ? $in['notes'] : '', 1000);
$confirm = isset($in['confirm']) ? (string) $in['confirm'] : '';
if ($username === '') { out(422, false, 'Please enter your username.'); }
if ($confirm !== 'yes') { out(422, false, 'Please confirm that you want to delete your account.'); }
$days = max(1, (int) cfg('deletion_days', 2));
$due = gmdate('c', time() + $days * 86400);
$r = sb_insert(tbl('deletions'), array(
    'username' => $username, 'email' => $email, 'notes' => $notes,
    'status' => 'pending', 'due_at' => $due, 'ip' => $ip, 'user_agent' => $ua,
), array(), 'return=representation');
$reqId = (sb_ok($r) && isset($r['data'][0]['id'])) ? 'D-' . strtoupper(substr((string) $r['data'][0]['id'], 0, 8)) : '-';
if (!sb_ok($r)) { error_log('[drotix] deletion insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
send_notice('Account deletion request: ' . $username, "Username: $username\nEmail: $email\nDue by: $due\nNotes: $notes\n",
    array('kind' => 'account deletion request', 'name' => $username, 'email' => $email, 'topic' => 'Delete account (' . $reqId . ')', 'message_excerpt' => $notes !== '' ? $notes : 'No notes.', 'admin_path' => 'deletions/'));
require_once __DIR__ . '/../../includes/mailer.php';
mail_send_template($email, 'deletion_received', 'We received your account deletion request', array('username' => $username, 'email' => $email, 'request_id' => $reqId, 'days' => (string) $days, 'due_date' => date('d M Y', strtotime($due))));
out(200, true, 'Request received. We will verify it is you and delete your account within ' . $days . ' days.');
