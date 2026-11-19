<?php
/* Mobile App: open a report screenshot from the private bucket via a 5-minute signed URL. */
require __DIR__ . '/../../../includes/app_admin_api.php';
app_require();

$url = app_evidence_url(isset($_GET['path']) ? (string) $_GET['path'] : '');
if ($url === '') {
    app_head('Screenshot', 'data');
    echo '<div class="flash err">Could not open this screenshot (file missing).</div>';
    admin_foot(); exit;
}
header('Location: ' . $url, true, 302);
exit;
