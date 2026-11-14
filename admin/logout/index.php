<?php
require __DIR__ . '/../../includes/admin_layout.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['admin'])) {
    csrf_verify();
    audit_log('logout', $_SESSION['admin'], client_ip());
}
$_SESSION = array();
if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
redirect('/admin/login/');
