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
if (!in_array($type, array('contact', 'waitlist', 'delete'), true)) { out(400, false, 'Unknown form.'); }

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
        'status' => 'new', 'ip' => $ip, 'user_agent' => $ua,
    ));
    if (!sb_ok($r)) { error_log('[drotix] contact insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
    send_notice('New contact message: ' . $topic, "From: $name <$email>\nTopic: $topic\n\n$message\n");
    out(200, true, 'Thank you! We got your message and will reply soon.');
}

if ($type === 'waitlist') {
    $source = clean_str(isset($in['source']) ? $in['source'] : 'website', 40);
    $r = sb_insert(tbl('waitlist'),
        array('email' => $email, 'source' => $source, 'ip' => $ip),
        array('on_conflict' => 'email'),
        'resolution=ignore-duplicates,return=minimal');
    if (!sb_ok($r)) { error_log('[drotix] waitlist insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
    out(200, true, 'You are on the list! We will email you when the game launches.');
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
));
if (!sb_ok($r)) { error_log('[drotix] deletion insert failed: ' . $r['status'] . ' ' . $r['error']); out(502, false, $failMsg); }
send_notice('Account deletion request: ' . $username, "Username: $username\nEmail: $email\nDue by: $due\nNotes: $notes\n");
out(200, true, 'Request received. We will verify it is you and delete your account within ' . $days . ' days.');
