<?php
require_once __DIR__ . '/../../config/bootstrap.php';
// api/logout.php

require_once ROOT_PATH . '/config/auth.php';

$wasAdmin = is_admin_logged_in();

logout_session();

header(
    'Location: ' .
        ($wasAdmin ? '/admin/login' : '/')
);

exit;
