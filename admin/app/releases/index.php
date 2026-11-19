<?php
/* Mobile App: app releases ("Please update DROTIX" screen in the app).
   installed code < min_supported  -> forced update;  installed code < version_code -> optional update.
   APK: SHA-256 computed in the browser, uploaded by the browser straight to Storage with a one-time
   signed upload link made here on the server (no key in the browser). */
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
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';

    /* JSON: one-time signed upload link for android/drotix-<code>.apk */
    if ($act === 'sign') {
        header('Content-Type: application/json');
        $code = (int) (isset($_POST['code']) ? $_POST['code'] : 0);
        if ($code <= 0) { echo json_encode(array('error' => 'Enter the version code first.')); exit; }
        list($up, $pub, $err) = app_signed_upload('android/drotix-' . $code . '.apk');
        echo json_encode($err !== '' ? array('error' => $err) : array('upload' => $up, 'public' => $pub));
        exit;
    }

    if ($act === 'publish') {
        $platform = (isset($_POST['platform']) && $_POST['platform'] === 'ios') ? 'ios' : 'android';
        $a = array(
            'platform' => $platform,
            'version_name' => app_post('version_name', 40),
            'version_code' => (int) (isset($_POST['version_code']) ? $_POST['version_code'] : 0),
            'min_supported' => (int) (isset($_POST['min_supported']) ? $_POST['min_supported'] : 0),
            'apk_url' => app_post('apk_url', 1000),
            'apk_sha256' => strtolower(app_post('apk_sha256', 64)),
            'apk_size' => preg_replace('/\D/', '', app_post('apk_size', 20)),
            'ios_url' => app_post('ios_url', 500),
            'update_available' => !empty($_POST['update_available']),
            'notes' => array(),
        );
        foreach (preg_split('/\r\n|\r|\n/', isset($_POST['notes']) ? (string) $_POST['notes'] : '') as $l) { $l = clean_str($l, 300); if ($l !== '') { $a['notes'][] = $l; } }
        $errs = array();
        if ($a['version_name'] === '') { $errs[] = 'version name'; }
        if ($a['version_code'] <= 0) { $errs[] = 'version code'; }
        if ($a['min_supported'] < 0 || $a['min_supported'] > $a['version_code']) { $errs[] = 'minimum supported code (0 … version code)'; }
        if ($platform === 'android') {
            if (!preg_match('~^https://~', $a['apk_url'])) { $errs[] = 'APK upload (no URL yet)'; }
            if (!preg_match('/^[0-9a-f]{64}$/', $a['apk_sha256'])) { $errs[] = 'APK SHA-256 (64 lowercase hex)'; }
            if ($a['apk_size'] === '' || (int) $a['apk_size'] <= 0) { $errs[] = 'APK size'; }
        } elseif ($a['ios_url'] === '') { $errs[] = 'App Store URL'; }
        if ($errs) { $_SESSION['rel_draft'] = $_POST; flash_set('err', 'Not published. Check: ' . implode(', ', $errs) . '.'); redirect('/admin/app/releases/?draft=1'); }
        $r = app_publish_app_release($a);
        if ($r['ok']) {
            unset($_SESSION['rel_draft']);
            app_log('publish_app_release', $a['version_name'], array('code' => $a['version_code'], 'min' => $a['min_supported'], 'platform' => $platform));
            flash_set('ok', 'DROTIX ' . $a['version_name'] . ' (' . $a['version_code'] . ') published. Players see the update screen now' . ($a['min_supported'] > $curMin ? '; below code ' . $a['min_supported'] . ' the update is forced.' : '.'));
            redirect('/admin/app/releases/');
        }
        $_SESSION['rel_draft'] = $_POST;
        flash_set('err', 'Publish failed: ' . app_rpc_error($r));
        redirect('/admin/app/releases/?draft=1');
    }
    redirect('/admin/app/releases/');
}

$d = (isset($_GET['draft']) && !empty($_SESSION['rel_draft'])) ? $_SESSION['rel_draft'] : array();
$dv = function ($k, $def = '') use ($d) { return isset($d[$k]) ? (string) $d[$k] : $def; };
function rel_size($b) { return $b ? number_format($b / 1048576, 1) . ' MB' : '-'; }

app_head('App releases', 'rel');
if (!$lr['ok']) { echo '<div class="flash err">Could not load releases: ' . e(app_rpc_error($lr)) . '</div>'; }

/* ---------- current release ---------- */
if ($cur) {
    echo '<div class="panel"><div class="row" style="margin:0;justify-content:space-between;align-items:flex-start"><div><div class="muted" style="font-size:12px;letter-spacing:2px">CURRENT RELEASE (ANDROID)</div>';
    echo '<b style="font-size:22px">DROTIX ' . e(g($cur, 'version_name', '?')) . '</b> <span class="muted">code ' . e($curCode) . '</span> ' . (g($cur, 'update_available') ? '<span class="badge b-teal">update offered</span>' : '<span class="badge b-grey">not offered</span>');
    echo '<div class="muted">Published ' . app_dt(g($cur, 'published_at')) . ' &middot; forced below code <b>' . e($curMin) . '</b> &middot; ' . e(rel_size((int) g($cur, 'apk_size_bytes', 0))) . '</div></div>';
    if (g($cur, 'apk_url')) { echo '<a class="btn ghost sm" href="' . e($cur['apk_url']) . '" target="_blank" rel="noopener">Download APK</a>'; }
    echo '</div>';
    $notes = (array) g($cur, 'release_notes', array());
    if ($notes) { echo '<ul class="notes">'; foreach ($notes as $n) { echo '<li>' . e($n) . '</li>'; } echo '</ul>'; }
    echo '</div>';
} elseif ($lr['ok']) { echo '<div class="warn">No Android release published yet.</div>'; }

/* ---------- new release ---------- */
echo '<h2>New release</h2><form method="post" class="panel" id="relForm" data-release data-cur-code="' . e($curCode) . '" data-cur-min="' . e($curMin) . '">' . csrf_field() . '<input type="hidden" name="action" value="publish">';
echo '<div class="fgrid">';
echo '<label>Platform<select name="platform" id="relPlatform"><option value="android">Android (APK)</option><option value="ios"' . ($dv('platform') === 'ios' ? ' selected' : '') . '>iOS (App Store)</option></select></label>';
echo '<label>Version name<input name="version_name" required maxlength="40" placeholder="1.1.0" value="' . e($dv('version_name')) . '"></label>';
echo '<label>Version code<input name="version_code" id="relCode" type="number" min="1" step="1" required value="' . e($dv('version_code', $curCode ? (string) ($curCode + 1) : '')) . '"><span class="muted" id="codeWarn" style="font-weight:400"></span></label>';
echo '<label>Minimum supported version code<input name="min_supported" id="relMin" type="number" min="0" step="1" value="' . e($dv('min_supported', (string) $curMin)) . '"><span class="muted" style="font-weight:400">Players below this are forced to update. Now: ' . e($curMin) . '</span></label>';
echo '</div>';

echo '<div class="apkbox" id="apkBox"><label class="fl">APK file<input type="file" id="apkFile" accept=".apk,application/vnd.android.package-archive"></label>';
echo '<div class="muted" id="apkStatus">Enter the version code, then choose the APK. It is hashed in your browser and uploaded to drotix-app-updates/android/drotix-&lt;code&gt;.apk.</div><div class="bar" id="apkBar" hidden><i style="width:0"></i></div>';
echo '<div class="fgrid" style="margin-top:12px"><label>APK URL<input name="apk_url" id="apkUrl" readonly value="' . e($dv('apk_url')) . '"></label><label>SHA-256<input name="apk_sha256" id="apkSha" readonly value="' . e($dv('apk_sha256')) . '"></label><label>Size (bytes)<input name="apk_size" id="apkSize" readonly value="' . e($dv('apk_size')) . '"></label></div></div>';
echo '<div class="fgrid" id="iosBox"><label>App Store URL (iOS)<input name="ios_url" maxlength="500" value="' . e($dv('ios_url')) . '" placeholder="https://apps.apple.com/app/..."></label></div>';
echo '<label class="fl">Release notes (one per line)<textarea name="notes" rows="4" maxlength="3000" placeholder="New: tournaments&#10;Faster matchmaking">' . e($dv('notes')) . '</textarea></label>';
echo '<label class="chk" style="margin:12px 0"><input type="checkbox" name="update_available" value="1"' . (!$d || !empty($d['update_available']) ? ' checked' : '') . '> Offer this update to players</label>';
echo '<button class="btn" type="submit" id="relSubmit">Publish release</button></form>';

/* ---------- history ---------- */
echo '<h2>Release history</h2><div class="tbl-wrap"><table><tr><th>Published</th><th>Platform</th><th>Version</th><th>Code</th><th>Forced below</th><th>Offered</th><th>Size</th><th>SHA-256</th><th>Notes</th><th></th></tr>';
foreach ($items as $i) {
    $n = (array) g($i, 'release_notes', array());
    echo '<tr><td class="nowrap">' . app_dt(g($i, 'published_at')) . '</td><td>' . e(g($i, 'platform', '-')) . '</td><td><b>' . e(g($i, 'version_name', '-')) . '</b></td><td>' . e(g($i, 'version_code', '-')) . '</td>';
    echo '<td>' . e(g($i, 'min_supported_version_code', '-')) . '</td><td>' . (g($i, 'update_available') ? 'yes' : 'no') . '</td><td class="nowrap">' . e(rel_size((int) g($i, 'apk_size_bytes', 0))) . '</td>';
    echo '<td><code class="j">' . e(substr((string) g($i, 'apk_sha256', ''), 0, 12)) . (g($i, 'apk_sha256') ? '…' : '') . '</code></td><td class="chat">' . e(implode(' · ', $n)) . '</td>';
    echo '<td>' . (g($i, 'apk_url') ? '<a href="' . e($i['apk_url']) . '" target="_blank" rel="noopener">APK</a>' : (g($i, 'ios_app_store_url') ? '<a href="' . e($i['ios_app_store_url']) . '" target="_blank" rel="noopener">App Store</a>' : '')) . '</td></tr>';
}
if (!$items) { echo '<tr><td colspan="10" class="muted">' . ($lr['ok'] ? 'No releases yet.' : 'Could not load.') . '</td></tr>'; }
echo '</table></div>';
echo '<script src="/admin/assets/app-release.js?v=1" defer></script>';
admin_foot();
