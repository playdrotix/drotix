<?php
require __DIR__ . '/../../includes/auth.php';
user_logout();
header('Location: /');
exit;