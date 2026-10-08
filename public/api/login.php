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

verify_csrf();

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
   Rate limit — ۵ تلاش ناموفق برای هر موبایل / ۳۰ برای هر IP در ۱۵ دقیقه
============================================================ */

const LOGIN_WINDOW_SECONDS = 900;

$ip = client_ip();

if (
    rate_limit_exceeded('login_mobile', $mobile, 5, LOGIN_WINDOW_SECONDS) ||
    rate_limit_exceeded('login_ip', $ip, 30, LOGIN_WINDOW_SECONDS)
) {
    http_response_code(429);
    header('Retry-After: ' . LOGIN_WINDOW_SECONDS);

    echo json_encode([
        'ok'    => false,
        'error' => 'به دلیل تلاش‌های ناموفق زیاد، ورود موقتاً مسدود شد. ۱۵ دقیقه دیگر دوباره تلاش کنید.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* ============================================================
   ورود ادمین
============================================================ */

if (attempt_admin_login($mobile, $password)) {
    rate_limit_clear('login_mobile', $mobile);

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

    rate_limit_clear('login_mobile', $mobile);

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

rate_limit_hit('login_mobile', $mobile);
rate_limit_hit('login_ip', $ip);

http_response_code(401);

echo json_encode([
    'ok'    => false,
    'error' => 'شماره موبایل یا رمز عبور نادرست است'
], JSON_UNESCAPED_UNICODE);
