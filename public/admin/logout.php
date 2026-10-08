<?php
require_once __DIR__ . '/../../config/bootstrap.php';
// admin/logout.php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

$wasAdmin = is_admin_logged_in();

if ($wasAdmin) {
    logout_session();
}

header('Location: /admin/login');
exit;
