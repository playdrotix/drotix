<?php
/* Mobile App: Home banners (posters on the Home screen of EVERY player). */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$routes = array('/events' => 'Events', '/shop' => 'Shop', '/daily' => 'Daily reward', '/challenges' => 'Challenges', '/friends' => 'Friends', '/ranks' => 'Ranks', '/missions' => 'Missions', '/wallet' => 'Wallet');
$audiences = array('all' => 'All players', 'signed_in' => 'Signed-in players', 'guests' => 'Guests only');
function bstate($st) {
    $c = array('live' => 'b-green', 'scheduled' => 'b-blue', 'ended' => 'b-grey', 'disabled' => 'b-red');
    return '<span class="badge ' . (isset($c[$st]) ? $c[$st] : 'b-grey') . '">' . e(ucfirst((string) $st)) . '</span>';
}
function bpreview($b) {
    $img = (string) g($b, 'image_url', '');
    $h = '<div class="bn"' . ($img !== '' ? ' style="background-image:url(\'' . e(str_replace("'", '%27', $img)) . '\')"' : '') . '>';
    if (g($b, 'title') || g($b, 'subtitle')) { $h .= '<div class="bn-txt">' . (g($b, 'title') ? '<b>' . e($b['title']) . '</b>' : '') . (g($b, 'subtitle') ? '<span>' . e($b['subtitle']) . '</span>' : '') . '</div>'; }
    return $h . ($img === '' ? '<div class="bn-empty">No image</div>' : '') . '</div>';
}
function local_in($iso) { $t = strtotime((string) $iso); return $t ? date('Y-m-d\TH:i', $t) : ''; }
function banner_from_row($b) {
    return array('id' => (string) g($b, 'id', ''), 'image_url' => (string) g($b, 'image_url', ''), 'title' => (string) g($b, 'title', ''), 'subtitle' => (string) g($b, 'subtitle', ''),
                 'action_type' => (string) g($b, 'action_type', 'none'), 'action_value' => (string) g($b, 'action_value', ''), 'audience' => (string) g($b, 'audience', 'all'),
                 'starts_at' => g($b, 'starts_at'), 'ends_at' => g($b, 'ends_at'), 'active' => (bool) g($b, 'active', true), 'sort_order' => (int) g($b, 'sort_order', 0));
}

$lr = app_list_banners();
$items = $lr['ok'] ? (array) g($lr['data'], 'items', array()) : array();
$byId = array(); foreach ($items as $b) { $byId[(string) g($b, 'id')] = $b; }

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($act === 'sign') {   // JSON: one-time upload link for home/<uuid>.<ext>
        header('Content-Type: application/json');
        $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        $type = isset($_POST['type']) ? (string) $_POST['type'] : ''; $size = (int) (isset($_POST['size']) ? $_POST['size'] : 0);
        if (!isset($types[$type])) { echo json_encode(array('error' => 'Use a JPG, PNG or WebP image.')); exit; }
        if ($size <= 0 || $size > 3 * 1048576) { echo json_encode(array('error' => 'The image must be 3 MB or smaller.')); exit; }
        $b = bin2hex(random_bytes(16));
        $uuid = substr($b, 0, 8) . '-' . substr($b, 8, 4) . '-4' . substr($b, 13, 3) . '-a' . substr($b, 17, 3) . '-' . substr($b, 20, 12);
        list($up, $pub, $err) = app_signed_upload_to(APP_BANNER_BUCKET, 'home/' . $uuid . '.' . $types[$type], false);
        echo json_encode($err !== '' ? array('error' => $err) : array('upload' => $up, 'public' => $pub)); exit;
    }
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    if ($act === 'save') {
        $b = array('id' => uuid_ok($id) ? $id : '', 'image_url' => app_post('image_url', 1000), 'title' => app_post('title', 80), 'subtitle' => app_post('subtitle', 160),
                   'action_type' => in_array(isset($_POST['action_type']) ? $_POST['action_type'] : '', array('none', 'route', 'url'), true) ? $_POST['action_type'] : 'none',
                   'action_value' => '', 'audience' => isset($audiences[isset($_POST['audience']) ? $_POST['audience'] : '']) ? $_POST['audience'] : 'all',
                   'starts_at' => app_local_to_iso(isset($_POST['starts_at']) ? $_POST['starts_at'] : ''), 'ends_at' => app_local_to_iso(isset($_POST['ends_at']) ? $_POST['ends_at'] : ''),
                   'active' => !empty($_POST['active']), 'sort_order' => (int) (isset($_POST['sort_order']) ? $_POST['sort_order'] : 0));
        if ($b['action_type'] === 'route') { $b['action_value'] = isset($routes[isset($_POST['route']) ? $_POST['route'] : '']) ? $_POST['route'] : ''; }
        if ($b['action_type'] === 'url') { $b['action_value'] = app_post('url', 1000); }
        $err = '';
        if (!preg_match('~^https://\S+$~i', $b['image_url'])) { $err = 'Upload an image or paste an https:// image URL.'; }
        elseif ($b['action_type'] === 'route' && $b['action_value'] === '') { $err = 'Choose the app screen to open.'; }
        elseif ($b['action_type'] === 'url' && !preg_match('~^https://\S+$~i', $b['action_value'])) { $err = 'The web link must start with https://'; }
        elseif ($b['starts_at'] && $b['ends_at'] && strtotime($b['starts_at']) >= strtotime($b['ends_at'])) { $err = 'The start must be before the end.'; }
        $r = $err === '' ? app_save_banner($b) : null;
        if ($r && $r['ok']) {
            unset($_SESSION['banner_draft']);
            app_log($b['id'] ? 'edit_banner' : 'create_banner', $b['id'] ?: (string) g($r['data'], 'banner.id', ''), $b['title']);
            flash_set('ok', ($b['id'] ? 'Banner saved.' : 'Banner created.') . ' Open apps update within a second.');
            redirect('/admin/app/banners/');
        }
        $_SESSION['banner_draft'] = $_POST + array('error' => $err !== '' ? $err : app_rpc_error($r));
        redirect('/admin/app/banners/?' . ($b['id'] ? 'edit=' . rawurlencode($b['id']) : 'new=1') . '&draft=1#bannerForm');
    }
    if (!isset($byId[$id])) { flash_set('err', 'Banner not found.'); redirect('/admin/app/banners/'); }
    if ($act === 'toggle') {
        $b = banner_from_row($byId[$id]); $b['active'] = !$b['active'];
        $r = app_save_banner($b);
        if ($r['ok']) { app_log('edit_banner', $id, $b['active'] ? 'on' : 'off'); flash_set('ok', 'Banner ' . ($b['active'] ? 'turned on.' : 'turned off.')); } else { flash_set('err', 'Failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'delete') {
        $r = app_delete_banner($id);
        if ($r['ok']) { app_log('delete_banner', $id, (string) g($byId[$id], 'title', '')); flash_set('ok', 'Banner deleted.'); } else { flash_set('err', 'Delete failed: ' . app_rpc_error($r)); }
    } elseif ($act === 'move') {
        $dir = (isset($_POST['dir']) && $_POST['dir'] === 'up') ? -1 : 1;
        $ids = array_keys($byId); $pos = array_search($id, $ids, true); $to = $pos + $dir;
        if ($pos !== false && $to >= 0 && $to < count($ids)) {
            $tmp = $ids[$pos]; $ids[$pos] = $ids[$to]; $ids[$to] = $tmp;
            $fail = '';
            foreach ($ids as $i => $bid) {
                $want = ($i + 1) * 10;
                if ((int) g($byId[$bid], 'sort_order', 0) === $want) { continue; }
                $b = banner_from_row($byId[$bid]); $b['sort_order'] = $want;
                $r = app_save_banner($b); if (!$r['ok']) { $fail = app_rpc_error($r); break; }
            }
            if ($fail === '') { app_log('edit_banner', $id, 'order'); flash_set('ok', 'Order changed.'); } else { flash_set('err', 'Could not change the order: ' . $fail); }
        }
    }
    redirect('/admin/app/banners/');
}

$live = app_home_banners();
$liveItems = $live['ok'] ? (array) gx($live['data'], array('items', 'banners'), (isset($live['data'][0]) ? $live['data'] : array())) : array_values(array_filter($items, function ($b) { return g($b, 'state') === 'live' && in_array(g($b, 'audience', 'all'), array('all', 'signed_in'), true); }));

app_head('Home banners', 'banners');
if (!$lr['ok']) { echo '<div class="flash err">Could not load banners: ' . e(app_rpc_error($lr)) . '</div>'; }

/* ---------- form ---------- */
$E = isset($_GET['edit']) && isset($byId[(string) $_GET['edit']]) ? banner_from_row($byId[(string) $_GET['edit']]) : null;
$D = isset($_GET['draft']) && !empty($_SESSION['banner_draft']) ? $_SESSION['banner_draft'] : null;
$f = $E ?: array('id' => '', 'image_url' => '', 'title' => '', 'subtitle' => '', 'action_type' => 'none', 'action_value' => '', 'audience' => 'all', 'starts_at' => null, 'ends_at' => null, 'active' => true, 'sort_order' => (count($items) + 1) * 10);
$fRoute = $f['action_type'] === 'route' ? $f['action_value'] : ''; $fUrl = $f['action_type'] === 'url' ? $f['action_value'] : '';
$fStart = local_in($f['starts_at']); $fEnd = local_in($f['ends_at']);
if ($D) {
    foreach (array('id', 'image_url', 'title', 'subtitle', 'action_type', 'audience', 'sort_order') as $k) { $f[$k] = isset($D[$k]) ? (string) $D[$k] : ''; }
    $f['active'] = !empty($D['active']); $fRoute = isset($D['route']) ? (string) $D['route'] : ''; $fUrl = isset($D['url']) ? (string) $D['url'] : '';
    $fStart = isset($D['starts_at']) ? (string) $D['starts_at'] : ''; $fEnd = isset($D['ends_at']) ? (string) $D['ends_at'] : '';
}
$editing = $f['id'] !== '';
echo '<details class="panel" id="bannerForm"' . ($editing || isset($_GET['new']) ? ' open' : '') . '><summary class="btn">' . ($editing ? 'Edit banner' : '+ Create banner') . '</summary>';
echo '<div class="legal-grid" style="margin-top:16px"><form method="post" id="bnForm" data-confirm="' . ($editing ? 'Save this banner? Every player sees the change.' : 'Create this banner? It shows on the Home screen of every player (when live).') . '">' . csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . e($f['id']) . '">';
echo '<div class="apkbox"><label class="fl">Image (JPG, PNG or WebP, max 3 MB; best 1600×700 px, 16:7)<input type="file" id="bnFile" accept="image/jpeg,image/png,image/webp"></label><div class="muted" id="bnStatus"></div><div class="bar" id="bnBar" hidden><i style="width:0"></i></div>';
echo '<label class="fl" style="margin-top:10px">…or image URL (https)<input name="image_url" id="bnUrl" required value="' . e($f['image_url']) . '" placeholder="https://..."></label></div>';
echo '<div class="fgrid"><label>Title (≤ 40 suggested)<input name="title" id="bnTitle" maxlength="80" value="' . e($f['title']) . '"></label>';
echo '<label>Subtitle (≤ 80 suggested)<input name="subtitle" id="bnSub" maxlength="160" value="' . e($f['subtitle']) . '"></label>';
echo '<label>On tap<select name="action_type" id="bnAct"><option value="none">Nothing</option><option value="route"' . ($f['action_type'] === 'route' ? ' selected' : '') . '>Open an app screen</option><option value="url"' . ($f['action_type'] === 'url' ? ' selected' : '') . '>Open a web link</option></select></label>';
echo '<label id="bnRouteL">App screen<select name="route"><option value="">Choose…</option>';
foreach ($routes as $k => $v) { echo '<option value="' . e($k) . '"' . ($fRoute === $k ? ' selected' : '') . '>' . e($v . ' (' . $k . ')') . '</option>'; }
echo '</select></label><label id="bnUrlL">Web link (https)<input name="url" value="' . e($fUrl) . '" placeholder="https://playdrotix.in/..."></label>';
echo '<label>Who sees it<select name="audience">'; foreach ($audiences as $k => $v) { echo '<option value="' . $k . '"' . ($f['audience'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; } echo '</select></label>';
echo '<label>Starts (optional)<input name="starts_at" type="datetime-local" value="' . e($fStart) . '"></label><label>Ends (optional)<input name="ends_at" type="datetime-local" value="' . e($fEnd) . '"></label>';
echo '<label>Sort order (small = first)<input name="sort_order" type="number" step="1" value="' . e($f['sort_order']) . '"></label></div>';
echo '<label class="chk" style="margin-bottom:12px"><input type="checkbox" name="active" value="1"' . ($f['active'] ? ' checked' : '') . '> Active</label>';
if ($D && !empty($D['error'])) { echo '<div class="flash err" style="margin:0 0 12px">' . e($D['error']) . '</div>'; }
echo '<div class="row" style="margin:0"><button class="btn" type="submit">' . ($editing ? 'Save banner' : 'Create banner') . '</button>' . ($editing ? '<a class="btn ghost" href="/admin/app/banners/">Cancel</a>' : '') . '</div></form>';
echo '<div><div class="muted" style="margin-bottom:8px">Live preview (16:7, as in the app)</div><div id="bnPreview">' . bpreview($f) . '</div></div></div></details>';

/* ---------- cards ---------- */
echo '<h2>All banners (' . count($items) . ')</h2><div class="bcards">';
$n = count($items);
foreach (array_values($items) as $i => $b) {
    $id = (string) g($b, 'id', ''); $on = (bool) g($b, 'active');
    $h = csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '">';
    $act = g($b, 'action_type', 'none') === 'route' ? 'Opens ' . (isset($routes[$b['action_value']]) ? $routes[$b['action_value']] : $b['action_value']) : (g($b, 'action_type') === 'url' ? 'Opens ' . $b['action_value'] : 'No action');
    echo '<div class="bcard">' . bpreview($b) . '<div class="bmeta2"><div class="row" style="margin:0;justify-content:space-between">' . bstate(g($b, 'state')) . '<span class="muted">' . e(isset($audiences[g($b, 'audience', 'all')]) ? $audiences[g($b, 'audience', 'all')] : g($b, 'audience')) . ' &middot; #' . e(g($b, 'sort_order', 0)) . '</span></div>';
    echo '<div class="muted" style="font-size:13px">' . (g($b, 'starts_at') ? app_dt($b['starts_at']) : '&mdash;') . ' &rarr; ' . (g($b, 'ends_at') ? app_dt($b['ends_at']) : '&mdash;') . '</div><div style="font-size:13px;word-break:break-all">' . e($act) . '</div>';
    echo '<div class="row" style="margin:6px 0 0;gap:6px">';
    echo '<form method="post" data-confirm="Turn this banner ' . ($on ? 'OFF for every player?' : 'ON for every player?') . '">' . $h . '<input type="hidden" name="action" value="toggle"><button type="submit" class="switch' . ($on ? ' on' : '') . '" title="On/Off"><i></i></button></form>';
    if ($i > 0) { echo '<form method="post">' . $h . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="up"><button class="btn ghost sm" type="submit" title="Move up">&uarr;</button></form>'; }
    if ($i < $n - 1) { echo '<form method="post">' . $h . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="down"><button class="btn ghost sm" type="submit" title="Move down">&darr;</button></form>'; }
    echo '<a class="btn ghost sm" href="/admin/app/banners/?edit=' . e(rawurlencode($id)) . '#bannerForm">Edit</a>';
    echo '<form method="post" data-confirm="Delete this banner for good? It disappears from every player\'s Home screen.">' . $h . '<input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit">Delete</button></form></div></div></div>';
}
if (!$items) { echo '<p class="muted">' . ($lr['ok'] ? 'No banners yet. Create the first one above.' : 'Could not load banners.') . '</p>'; }
echo '</div>';

/* ---------- as players see it ---------- */
echo '<h2>As players see it now (' . count($liveItems) . ' live)</h2><div class="phone">';
foreach ($liveItems as $b) { echo bpreview($b); }
if (!$liveItems) { echo '<p class="muted" style="padding:20px">No live banner: the Home screen shows no posters.</p>'; }
echo '</div>';
echo '<script src="/admin/assets/app-banners.js?v=1" defer></script>';
admin_foot();
