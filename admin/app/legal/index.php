<?php
/* Mobile App: Legal documents (Privacy Policy / Terms of Service).
   Versions are never edited: every publish creates version N+1 (admin_publish_legal).
   If "Players must agree again" is on, every signed-in player must tap "I agree" in the app. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$types = array('privacy' => 'Privacy Policy', 'terms' => 'Terms of Service');
$doc = isset($_GET['doc'], $types[$_GET['doc']]) ? (string) $_GET['doc'] : 'privacy';

/* ---------- publish ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $type = isset($_POST['doc_type'], $types[$_POST['doc_type']]) ? (string) $_POST['doc_type'] : '';
    $back = '/admin/app/legal/?doc=' . rawurlencode($type !== '' ? $type : 'privacy');
    $title = app_post('title', 200);
    $website = app_post('contact_website', 300);
    $email = app_post('contact_email', 200);
    $summary = app_post('summary', 2000);
    $req = !empty($_POST['requires_acceptance']);
    $heads = isset($_POST['sec_heading']) && is_array($_POST['sec_heading']) ? $_POST['sec_heading'] : array();
    $bodies = isset($_POST['sec_body']) && is_array($_POST['sec_body']) ? $_POST['sec_body'] : array();
    $sections = array(); $bad = 0;
    foreach (array_values($heads) as $i => $h) {
        $h = clean_str($h, 300);
        $b = isset($bodies[$i]) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $bodies[$i])) : '';
        if ($h === '' && $b === '') { continue; }
        if ($h === '' || $b === '') { $bad++; continue; }
        $sections[] = array('heading' => $h, 'body' => mb_substr($b, 0, 20000));
    }
    $errs = array();
    if ($type === '') { $errs[] = 'document type'; }
    if ($title === '') { $errs[] = 'title'; }
    if (!$sections) { $errs[] = 'at least one section'; }
    if ($bad) { $errs[] = $bad . ' section(s) with an empty heading or body'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errs[] = 'contact email'; }
    if ($website !== '' && !preg_match('~^https?://~i', $website)) { $errs[] = 'contact website (must start with https://)'; }
    if ($errs) {
        $_SESSION['legal_draft'] = $_POST;
        flash_set('err', 'Not published. Check: ' . implode(', ', $errs) . '.');
        redirect($back . '&draft=1');
    }
    $r = app_publish_legal($type, $title, $sections, $website, $email, $summary, $req);
    if ($r['ok']) {
        unset($_SESSION['legal_draft']);
        app_log('publish_legal', $type, array('version' => g($r['data'], 'version'), 'requires_acceptance' => $req));
        flash_set('ok', $types[$type] . ' version ' . g($r['data'], 'version', '?') . ' published.' . ($req ? ' All players will be asked to agree.' : ''));
    } else {
        $_SESSION['legal_draft'] = $_POST;
        flash_set('err', 'Publish failed: ' . app_rpc_error($r));
        $back .= '&draft=1';
    }
    redirect($back);
}

/* ---------- data ---------- */
$lr = app_list_legal();
$all = $lr['ok'] ? (array) g($lr['data'], 'versions', array()) : array();
$playersTotal = $lr['ok'] ? (int) g($lr['data'], 'players_total', 0) : 0;
$versions = array_values(array_filter($all, function ($v) use ($doc) { return g($v, 'doc_type') === $doc; }));
usort($versions, function ($a, $b) { return (int) g($b, 'version', 0) - (int) g($a, 'version', 0); });
$latest = $versions ? $versions[0] : null;
$byId = array(); foreach ($versions as $v) { $byId[(string) g($v, 'id')] = $v; }

/** Server-side preview, same markup as the live preview (assets/app-legal.js). */
function legal_preview($d) {
    $h = '<div class="lp"><div class="lp-title">' . e(mb_strtoupper((string) g($d, 'title', ''))) . '</div>';
    $h .= '<div class="lp-date">Last updated ' . e(g($d, 'published_at') ? date('d M Y', strtotime($d['published_at'])) : date('d M Y')) . '</div>';
    if (g($d, 'summary')) { $h .= '<div class="lp-sum"><b>What changed</b><br>' . e($d['summary']) . '</div>'; }
    foreach ((array) g($d, 'sections', array()) as $s) { $h .= '<div class="lp-h">' . e(g($s, 'heading', '')) . '</div><div class="lp-b">' . e(g($s, 'body', '')) . '</div>'; }
    if (g($d, 'contact_website') || g($d, 'contact_email')) {
        $h .= '<div class="lp-contact"><div class="lp-ct">CONTACT US</div>';
        if (g($d, 'contact_website')) { $h .= '<div><a href="' . e($d['contact_website']) . '" target="_blank" rel="noopener">' . e($d['contact_website']) . '</a></div>'; }
        if (g($d, 'contact_email')) { $h .= '<div><a href="mailto:' . e($d['contact_email']) . '">' . e($d['contact_email']) . '</a></div>'; }
        $h .= '</div>';
    }
    return $h . '</div>';
}

app_head('Legal', 'legal');
echo '<nav class="tabs" style="margin-top:0">';
foreach ($types as $k => $v) { echo '<a href="/admin/app/legal/?doc=' . $k . '"' . ($k === $doc ? ' class="on"' : '') . '>' . e($v) . '</a>'; }
echo '</nav>';
if (!$lr['ok']) { echo '<div class="flash err">Could not load legal documents: ' . e(app_rpc_error($lr)) . '</div>'; }

/* ---------- read-only view of one version ---------- */
if (isset($_GET['view']) && isset($byId[(string) $_GET['view']])) {
    $v = $byId[(string) $_GET['view']];
    echo '<div class="row"><a class="btn ghost sm" href="/admin/app/legal/?doc=' . e($doc) . '">&larr; Back</a><a class="btn sm" href="/admin/app/legal/?doc=' . e($doc) . '&amp;copy=' . e(rawurlencode((string) g($v, 'id'))) . '">Copy into editor</a>';
    echo '<span class="muted">Version ' . e(g($v, 'version', '?')) . ' &middot; read-only &middot; ' . app_num(g($v, 'accepted_count')) . ' players accepted</span></div>';
    echo '<div class="lp-wrap" style="max-width:520px">' . legal_preview($v) . '</div>';
    admin_foot(); exit;
}

/* ---------- current version card ---------- */
if ($latest) {
    $acc = (int) g($latest, 'accepted_count', 0);
    $pct = $playersTotal > 0 ? min(100, round($acc * 100 / $playersTotal)) : 0;
    echo '<div class="panel"><div class="row" style="margin:0;justify-content:space-between"><div><b style="font-size:18px">' . e($types[$doc]) . ' &middot; version ' . e(g($latest, 'version', '?')) . '</b>'
        . '<div class="muted">Published ' . app_dt(g($latest, 'published_at')) . ' &middot; ' . (g($latest, 'requires_acceptance') ? 'players must agree' : 'no acceptance needed') . '</div></div>'
        . '<div style="min-width:240px"><div class="muted" style="font-size:13px">Accepted: <b>' . number_format($acc) . '</b> / ' . number_format($playersTotal) . ' players (' . $pct . '%)</div><div class="bar"><i style="width:' . $pct . '%"></i></div></div></div></div>';
} elseif ($lr['ok']) {
    echo '<div class="warn">No ' . e($types[$doc]) . ' published yet. Write the first version below.</div>';
}

/* ---------- editor (prefill: draft after an error, ?copy=, or latest) ---------- */
$src = $latest;
if (isset($_GET['copy']) && isset($byId[(string) $_GET['copy']])) { $src = $byId[(string) $_GET['copy']]; echo '<div class="flash ok">Copied version ' . e(g($src, 'version')) . ' into the editor. Publishing creates a new version.</div>'; }
$pre = array('title' => (string) g($src, 'title', $types[$doc]), 'contact_website' => (string) g($src, 'contact_website', 'https://playdrotix.in'), 'contact_email' => (string) g($src, 'contact_email', 'support@playdrotix.in'),
             'summary' => '', 'requires_acceptance' => true, 'sections' => (array) g($src, 'sections', array()));
if (isset($_GET['draft']) && !empty($_SESSION['legal_draft']) && (isset($_SESSION['legal_draft']['doc_type']) ? $_SESSION['legal_draft']['doc_type'] : '') === $doc) {
    $d = $_SESSION['legal_draft']; $secs = array();
    foreach ((array) (isset($d['sec_heading']) ? $d['sec_heading'] : array()) as $i => $hh) { $secs[] = array('heading' => (string) $hh, 'body' => isset($d['sec_body'][$i]) ? (string) $d['sec_body'][$i] : ''); }
    $pre = array('title' => (string) $d['title'], 'contact_website' => (string) $d['contact_website'], 'contact_email' => (string) $d['contact_email'], 'summary' => (string) $d['summary'], 'requires_acceptance' => !empty($d['requires_acceptance']), 'sections' => $secs);
}
if (!$pre['sections']) { $pre['sections'] = array(array('heading' => '', 'body' => '')); }

echo '<h2>New version</h2><div class="legal-grid">';
echo '<form method="post" class="panel" id="legalForm" data-legal data-doc="' . e($types[$doc]) . '">' . csrf_field() . '<input type="hidden" name="doc_type" value="' . e($doc) . '">';
echo '<div class="fgrid"><label>Title<input name="title" required maxlength="200" value="' . e($pre['title']) . '"></label>';
echo '<label>Contact website<input name="contact_website" maxlength="300" value="' . e($pre['contact_website']) . '" placeholder="https://playdrotix.in"></label>';
echo '<label>Contact email<input name="contact_email" type="email" maxlength="200" value="' . e($pre['contact_email']) . '"></label></div>';
echo '<div class="secs" id="secs">';
foreach ($pre['sections'] as $s) {
    echo '<div class="sec"><div class="sec-bar"><b class="sec-n"></b><span><button type="button" class="btn ghost sm" data-mv="-1" title="Move up">&uarr;</button><button type="button" class="btn ghost sm" data-mv="1" title="Move down">&darr;</button><button type="button" class="btn danger sm" data-del title="Delete">&times;</button></span></div>'
        . '<input name="sec_heading[]" maxlength="300" placeholder="Section heading" value="' . e(g($s, 'heading', '')) . '"><textarea name="sec_body[]" rows="5" placeholder="Section text">' . e(g($s, 'body', '')) . '</textarea></div>';
}
echo '</div><button type="button" class="btn ghost sm" id="addSec">+ Add section</button>';
echo '<label class="fl" style="margin-top:14px">What changed (shown to players)<textarea name="summary" rows="3" maxlength="2000" placeholder="e.g. We now explain how report screenshots are stored.">' . e($pre['summary']) . '</textarea></label>';
echo '<label class="chk" style="margin:12px 0"><input type="checkbox" name="requires_acceptance" value="1"' . ($pre['requires_acceptance'] ? ' checked' : '') . '> Players must agree again (every signed-in player gets an "I agree" screen)</label>';
echo '<div class="muted" id="legalErr" style="color:var(--rd)"></div><button class="btn" type="submit">Publish as version ' . e(((int) g($latest, 'version', 0)) + 1) . '</button></form>';
echo '<div><div class="muted" style="margin-bottom:8px">Live preview (as in the app)</div><div class="lp-wrap" id="legalPreview"></div></div></div>';

/* ---------- history ---------- */
echo '<h2>Version history</h2><div class="tbl-wrap"><table><tr><th>Version</th><th>Published</th><th>Title</th><th>Players must agree</th><th>Accepted</th><th></th></tr>';
foreach ($versions as $v) {
    $vid = (string) g($v, 'id', '');
    $href = '/admin/app/legal/?doc=' . rawurlencode($doc) . '&view=' . rawurlencode($vid);
    echo '<tr class="click" data-href="' . e($href) . '"><td><b>v' . e(g($v, 'version', '?')) . '</b>' . ($latest && $vid === (string) g($latest, 'id') ? ' <span class="badge b-teal">current</span>' : '') . '</td><td class="nowrap">' . app_dt(g($v, 'published_at')) . '</td><td>' . e(g($v, 'title', '')) . '</td>';
    echo '<td>' . (g($v, 'requires_acceptance') ? 'yes' : 'no') . '</td><td>' . app_num(g($v, 'accepted_count')) . '</td><td><a class="btn ghost sm" href="' . e($href) . '">View</a> <a class="btn ghost sm" href="/admin/app/legal/?doc=' . e($doc) . '&amp;copy=' . e(rawurlencode($vid)) . '">Copy into editor</a></td></tr>';
}
if (!$versions) { echo '<tr><td colspan="6" class="muted">' . ($lr['ok'] ? 'No versions yet.' : 'Could not load.') . '</td></tr>'; }
echo '</table></div>';
echo '<script src="/admin/assets/app-legal.js?v=1" defer></script>';
admin_foot();
