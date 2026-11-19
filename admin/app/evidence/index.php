<?php
/* App admin: open a report screenshot from the private bucket via a 5-minute signed URL. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$path = isset($_GET['path']) ? (string) $_GET['path'] : '';
$url = app_evidence_url($path);
if ($url === '') {
    app_head('Screenshot', 'reports');
    echo '<div class="flash err">Could not open this screenshot (missing file or no permission on the bucket).</div>';
    admin_foot(); exit;
}
header('Location: ' . $url, true, 302);
exit;
