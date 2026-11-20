<?php

 * DROTIX mailer: sends the HTML templates in includes/emails/ through SMTP (mail.playdrotix.in).
 * No external library. Port 587 + STARTTLS (default) or 465 + SSL. Falls back to PHP mail() if SMTP is not set.
 *
 * Settings: ONLY in includes/config.php on the server (never commit the password):
 *   'smtp' => array(
 *       'host' => 'mail.playdrotix.in', 'port' => 587, 'secure' => 'tls',      // or 465 + 'ssl'
 *       'username' => 'noreply@playdrotix.in', 'password' => 'Pravin@7844',
 *       'from_email' => 'noreply@playdrotix.in', 'from_name' => 'Playdrotix',
 *       'reply_to' => 'support@playdrotix.in',
 *   ),
 
require_once __DIR__ . '/bootstrap.php';

/** Fill a template from includes/emails/<name>.html. Values are HTML-escaped; keys ending in _html are inserted as is. */
function mail_render($template, $vars) {
    $file = __DIR__ . '/emails/' . basename($template) . '.html';
    if (!is_file($file)) { return ''; }
    $html = (string) file_get_contents($file);
    foreach ($vars as $k => $v) {
        $val = substr($k, -5) === '_html' ? (string) $v : htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace('{{' . preg_replace('/_html$/', '', $k) . '}}', $val, $html);
    }
    return preg_replace('/\{\{[a-z_]+\}\}/', '', $html);   // unused placeholders -> empty
}
/** Plain-text copy of an HTML email (for clients that do not show HTML, and better spam scores). */
function mail_html_to_text($html) {
    $h = preg_replace('~<(style|title|head)[^>]*>.*?</\1>~si', '', $html);
    $h = preg_replace('~<div style="display:none[^>]*>.*?</div>~si', '', $h);
    $h = preg_replace_callback('~<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>~si', function ($m) { $t = trim(strip_tags($m[2])); return ($t !== '' && $t !== $m[1] ? $t . ' ' : '') . '(' . html_entity_decode($m[1]) . ')'; }, $h);
    $h = preg_replace('~<(br|/p|/tr|/h1|/div)[^>]*>~i', "\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t));
}

/** Send a template. Returns array(ok, error). */
function mail_send_template($to, $template, $subject, $vars, $replyTo = '') {
    $html = mail_render($template, $vars);
    if ($html === '') { return array(false, 'Template not found: ' . $template); }
    return mail_send($to, $subject, $html, mail_html_to_text($html), $replyTo);
}

/** Send one email (HTML + text). SMTP when configured, else PHP mail(). Returns array(ok, error). */
function mail_send($to, $subject, $html, $text, $replyTo = '') {
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return array(false, 'Bad recipient'); }
    $s = cfg('smtp', array());
    $fromEmail = !empty($s['from_email']) ? $s['from_email'] : (!empty($s['username']) ? $s['username'] : (string) cfg('mail_from', 'no-reply@playdrotix.in'));
    $fromName = !empty($s['from_name']) ? $s['from_name'] : 'DROTIX';
    if ($replyTo === '' && !empty($s['reply_to'])) { $replyTo = $s['reply_to']; }
    $msg = mail_build($to, $subject, $html, $text, $fromEmail, $fromName, $replyTo);
    if (!empty($s['host']) && !empty($s['password']) && strpos((string) $s['password'], 'PASTE_') !== 0) {
        $err = smtp_send($s, $fromEmail, $to, $msg['headers'] . "\r\n\r\n" . $msg['body']);
        if ($err === '') { return array(true, ''); }
        error_log('[drotix] SMTP failed: ' . $err);
        return array(false, 'SMTP: ' . $err);
    }
    $ok = @mail($to, $msg['subject'], $msg['body'], $msg['mail_headers']);
    return array($ok, $ok ? '' : 'mail() failed (SMTP is not set up in config.php)');
}

function mail_hdr($v) { return trim(preg_replace('/[\r\n]+/', ' ', (string) $v)); }
function mail_enc($v) { return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v; }

/** Build a multipart/alternative message. */
function mail_build($to, $subject, $html, $text, $fromEmail, $fromName, $replyTo) {
    $b = 'b_' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($fromEmail, '@'), 1) ?: 'playdrotix.in';
    $common = array(
        'From: ' . mail_enc(mail_hdr($fromName)) . ' <' . mail_hdr($fromEmail) . '>',
        'Reply-To: ' . mail_hdr($replyTo !== '' ? $replyTo : $fromEmail),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
        'X-Mailer: DROTIX',
    );
    $body = "This is a multi-part message in MIME format.\r\n\r\n"
        . '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
        . '--' . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
        . '--' . $b . "--\r\n";
    $subj = mail_enc(mail_hdr($subject));
    return array(
        'headers' => implode("\r\n", array_merge(array('To: <' . mail_hdr($to) . '>', 'Subject: ' . $subj), $common)),
        'mail_headers' => implode("\r\n", $common),
        'subject' => $subj,
        'body' => $body,
    );
}

/** Minimal SMTP client. Returns '' on success or the error. */
function smtp_send($s, $from, $to, $data) {
    $host = (string) $s['host']; $port = (int) (isset($s['port']) ? $s['port'] : 587);
    $secure = strtolower((string) (isset($s['secure']) ? $s['secure'] : ($port === 465 ? 'ssl' : 'tls')));
    $ctx = stream_context_create(array('ssl' => array('peer_name' => $host, 'verify_peer' => empty($s['no_verify']), 'verify_peer_name' => empty($s['no_verify']), 'allow_self_signed' => !empty($s['no_verify']))));
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return 'connect to ' . $host . ':' . $port . ' failed: ' . $errstr; }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) { $out = ''; while (($l = fgets($fp, 515)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] === ' ') { break; } } return $out; };
    $cmd = function ($c, $want) use ($fp, $read) { if ($c !== null) { fwrite($fp, $c . "\r\n"); } $r = $read(); return in_array(substr($r, 0, 3), (array) $want, true) ? '' : trim(($c !== null && stripos($c, 'AUTH') === false ? $c : 'step') . ' -> ' . $r); };
    $ehlo = 'EHLO ' . (isset($_SERVER['SERVER_NAME']) ? preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME']) : 'playdrotix.in');
    $steps = array();
    if (($e = $cmd(null, '220')) !== '') { fclose($fp); return 'greeting: ' . $e; }
    if (($e = $cmd($ehlo, '250')) !== '') { fclose($fp); return $e; }
    if ($secure === 'tls') {
        if (($e = $cmd('STARTTLS', '220')) !== '') { fclose($fp); return $e; }
        $m = STREAM_CRYPTO_METHOD_TLS_CLIENT; if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) { $m |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT; } if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) { $m |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; }
        if (!@stream_socket_enable_crypto($fp, true, $m)) { fclose($fp); return 'TLS handshake failed (certificate of ' . $host . '?)'; }
        if (($e = $cmd($ehlo, '250')) !== '') { fclose($fp); return $e; }
    }
    if (($e = $cmd('AUTH LOGIN', '334')) !== '') { fclose($fp); return 'AUTH not accepted: ' . $e; }
    if (($e = $cmd(base64_encode((string) $s['username']), '334')) !== '') { fclose($fp); return 'username rejected'; }
    if (($e = $cmd(base64_encode((string) $s['password']), '235')) !== '') { fclose($fp); return 'login failed (wrong username or password)'; }
    if (($e = $cmd('MAIL FROM:<' . $from . '>', '250')) !== '') { fclose($fp); return $e; }
    if (($e = $cmd('RCPT TO:<' . $to . '>', array('250', '251'))) !== '') { fclose($fp); return $e; }
    if (($e = $cmd('DATA', '354')) !== '') { fclose($fp); return $e; }
    $data = preg_replace('/^\./m', '..', str_replace(array("\r\n", "\r"), array("\n", "\n"), $data));
    $data = str_replace("\n", "\r\n", $data);
    if (($e = $cmd($data . "\r\n.", '250')) !== '') { fclose($fp); return 'message refused: ' . $e; }
    $cmd('QUIT', '221');
    fclose($fp);
    return '';
}
