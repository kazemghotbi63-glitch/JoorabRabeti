<?php
require_once __DIR__ . '/../../config/bootstrap.php';
// api/login.php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'ok'    => false,
        'error' => 'متد درخواست نامعتبر است'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$mobile   = trim($_POST['mobile'] ?? '');
$password = $_POST['password'] ?? '';

if (
    !preg_match('/^09\d{9}$/', $mobile) ||
    $password === ''
) {
    http_response_code(422);

    echo json_encode([
        'ok'    => false,
        'error' => 'شماره موبایل یا رمز عبور نامعتبر است'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* ============================================================
   ورود ادمین
============================================================ */

if (attempt_admin_login($mobile, $password)) {
    echo json_encode([
        'ok'       => true,
        'role'     => 'admin',
        'redirect' => '/admin'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* ============================================================
   ورود مشتری
============================================================ */

$stmt = db()->prepare("
    SELECT *
    FROM users
    WHERE mobile = ?
      AND role = 'customer'
      AND is_active = 1
    LIMIT 1
");

$stmt->execute([$mobile]);

$customer = $stmt->fetch();

if (
    $customer &&
    password_verify(
        $password,
        $customer['password_hash']
    )
) {
    session_regenerate_id(true);

    $_SESSION['customer_id'] = (int)$customer['id'];

    $_SESSION['customer_name'] =
        $customer['full_name']
        ?? 'کاربر';

    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_name']
    );

    echo json_encode([
        'ok'   => true,
        'role' => 'customer',
        'name' => $_SESSION['customer_name']
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* ============================================================
   ناموفق
============================================================ */

http_response_code(401);

echo json_encode([
    'ok'    => false,
    'error' => 'شماره موبایل یا رمز عبور نادرست است'
], JSON_UNESCAPED_UNICODE);
