<?php
/* Mobile App: app update (whole app). Updates come ONLY from Google Play: "UPDATE NOW" in the app opens Google Play.
   installed code < min_supported -> forced update;  installed code < version_code -> optional update. No APK here. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$lr = app_list_app_releases(30);
$items = $lr['ok'] ? (array) g($lr['data'], 'items', array()) : array();
usort($items, function ($a, $b) { return strcmp((string) g($b, 'published_at', ''), (string) g($a, 'published_at', '')); });
$cur = null; foreach ($items as $i) { if (g($i, 'platform', 'android') === 'android') { $cur = $i; break; } }
$curCode = (int) g($cur, 'version_code', 0);
$curMin = (int) g($cur, 'min_supported_version_code', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $platform = (isset($_POST['platform']) && $_POST['platform'] === 'ios') ? 'ios' : 'android';
    $a = array('platform' => $platform, 'version_name' => app_post('version_name', 40), 'version_code' => (int) (isset($_POST['version_code']) ? $_POST['version_code'] : 0),
               'min_supported' => (int) (isset($_POST['min_supported']) ? $_POST['min_supported'] : 0),
               'apk_url' => '', 'apk_sha256' => '', 'apk_size' => '',   // updates come from Google Play: no APK
               'ios_url' => app_post('ios_url', 500), 'update_available' => !empty($_POST['update_available']), 'notes' => array());
    foreach (preg_split('/\r\n|\r|\n/', isset($_POST['notes']) ? (string) $_POST['notes'] : '') as $l) { $l = clean_str($l, 300); if ($l !== '') { $a['notes'][] = $l; } }
    $errs = array();
    if ($a['version_name'] === '') { $errs[] = 'version name'; }
    if ($a['version_code'] <= 0) { $errs[] = 'version code'; }
    if ($a['min_supported'] < 0 || $a['min_supported'] > $a['version_code']) { $errs[] = 'minimum supported code (0 … version code)'; }
    if ($a['ios_url'] !== '' && !preg_match('~^https://~i', $a['ios_url'])) { $errs[] = 'App Store URL (https://)'; }
    if ($errs) { $_SESSION['rel_draft'] = $_POST; flash_set('err', 'Not published. Check: ' . implode(', ', $errs) . '.'); redirect('/admin/app/releases/?draft=1'); }
    $r = app_publish_app_release($a);
    if ($r['ok']) {
        unset($_SESSION['rel_draft']);
        app_log('publish_app_release', $a['version_name'], array('code' => $a['version_code'], 'min' => $a['min_supported'], 'platform' => $platform));
        flash_set('ok', 'DROTIX ' . $a['version_name'] . ' (code ' . $a['version_code'] . ') published. Players on older versions are sent to Google Play' . ($a['min_supported'] > $curMin ? '; below code ' . $a['min_supported'] . ' the update is forced.' : '.'));
        redirect('/admin/app/releases/');
    }
    $_SESSION['rel_draft'] = $_POST;
    flash_set('err', 'Publish failed: ' . app_rpc_error($r));
    redirect('/admin/app/releases/?draft=1');
}

$d = (isset($_GET['draft']) && !empty($_SESSION['rel_draft'])) ? $_SESSION['rel_draft'] : array();
$dv = function ($k, $def = '') use ($d) { return isset($d[$k]) ? (string) $d[$k] : $def; };

app_head('App update', 'rel');
echo '<div class="warn"><b>Publish here only AFTER the new version is live in Google Play</b> (Production or your test track). Players are sent to Google Play to update.</div>';
if (!$lr['ok']) { echo '<div class="flash err">Could not load releases: ' . e(app_rpc_error($lr)) . '</div>'; }

if ($cur) {
    $notes = (array) g($cur, 'release_notes', array());
    echo '<div class="panel"><div class="muted" style="font-size:12px;letter-spacing:2px">CURRENT RELEASE (ANDROID)</div>';
    echo '<b style="font-size:22px">DROTIX ' . e(g($cur, 'version_name', '?')) . '</b> <span class="muted">code ' . e($curCode) . '</span> ' . (g($cur, 'update_available') ? '<span class="badge b-teal">update offered</span>' : '<span class="badge b-grey">not offered</span>');
    echo '<div class="muted">Published ' . app_dt(g($cur, 'published_at')) . ' &middot; forced below code <b>' . e($curMin) . '</b></div>';
    if ($notes) { echo '<ul class="notes">'; foreach ($notes as $n) { echo '<li>' . e($n) . '</li>'; } echo '</ul>'; }
    echo '</div>';
} elseif ($lr['ok']) { echo '<div class="warn">No Android release published yet.</div>'; }

echo '<h2>New release</h2><form method="post" class="panel" id="relForm" data-release data-cur-code="' . e($curCode) . '" data-cur-min="' . e($curMin) . '">' . csrf_field() . '<div class="fgrid">';
echo '<label>Platform<select name="platform" id="relPlatform"><option value="android">Android (Google Play)</option><option value="ios"' . ($dv('platform') === 'ios' ? ' selected' : '') . '>iOS (App Store)</option></select></label>';
echo '<label>Version name<input name="version_name" required maxlength="40" placeholder="1.1.0" value="' . e($dv('version_name')) . '"></label>';
echo '<label>Version code<input name="version_code" id="relCode" type="number" min="1" step="1" required value="' . e($dv('version_code', $curCode ? (string) ($curCode + 1) : '')) . '"><span class="muted" style="font-weight:400">Must equal the versionCode of the build in Play Console.</span><span class="muted" id="codeWarn" style="font-weight:400"></span></label>';
echo '<label>Minimum supported version code<input name="min_supported" id="relMin" type="number" min="0" step="1" value="' . e($dv('min_supported', (string) $curMin)) . '"><span class="muted" style="font-weight:400">Players below this are forced to update. Now: ' . e($curMin) . '</span></label>';
echo '<label id="iosBox">App Store URL (iOS, optional)<input name="ios_url" maxlength="500" value="' . e($dv('ios_url')) . '" placeholder="https://apps.apple.com/app/..."></label>';
echo '</div><label class="fl">Release notes (one per line)<textarea name="notes" rows="4" maxlength="3000" placeholder="New: tournaments&#10;Faster matchmaking">' . e($dv('notes')) . '</textarea></label>';
echo '<label class="chk" style="margin:12px 0"><input type="checkbox" name="update_available" value="1"' . (!$d || !empty($d['update_available']) ? ' checked' : '') . '> Update available (show the update screen)</label>';
echo '<button class="btn" type="submit">Publish release</button></form>';

echo '<h2>Release history</h2><div class="tbl-wrap"><table><tr><th>Published</th><th>Platform</th><th>Version</th><th>Code</th><th>Forced below</th><th>Offered</th><th>Notes</th></tr>';
foreach ($items as $i) {
    $n = (array) g($i, 'release_notes', array());
    echo '<tr><td class="nowrap">' . app_dt(g($i, 'published_at')) . '</td><td>' . e(g($i, 'platform', '-')) . '</td><td><b>' . e(g($i, 'version_name', '-')) . '</b></td><td>' . e(g($i, 'version_code', '-')) . '</td>';
    echo '<td>' . e(g($i, 'min_supported_version_code', '-')) . '</td><td>' . (g($i, 'update_available') ? 'yes' : 'no') . '</td><td title="' . e(implode(' · ', $n)) . '">' . count($n) . ' note' . (count($n) === 1 ? '' : 's') . '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="7" class="muted">' . ($lr['ok'] ? 'No releases yet.' : 'Could not load.') . '</td></tr>'; }
echo '</table></div>';
echo '<script src="/admin/assets/app-release.js?v=2" defer></script>';
admin_foot();
