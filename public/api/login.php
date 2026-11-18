<?php
/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
];
foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__bp, $__b);
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
    (!preg_match('/^09\d{9}$/', $mobile) && !preg_match('/^\d{10}$/', $mobile)) ||
    $password === ''
) {
    http_response_code(422);

    echo json_encode([
        'ok'    => false,
        'error' => 'نام کاربری یا رمز عبور نامعتبر است'
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
    WHERE (mobile = ? OR national_code = ?)
      AND role = 'customer'
      AND is_active = 1
    LIMIT 1
");
$stmt->execute([$mobile, $mobile]);

$customer = $stmt->fetch();

if (
    $customer &&
    password_verify(
        $password,
        $customer['password_hash']
    )
) {
    session_regenerate_id(true);
    unset($_SESSION['co_access']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    rate_limit_clear('login_mobile', $mobile);

    $_SESSION['customer_id'] = (int)$customer['id'];
    // ═══ ادغام سبد مهمان با سبد کاربر ═══
    if (!empty($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart'])) {
        $db = db();
        $customerId = (int)$customer['id'];

        // گرفتن یا ساخت سبد فعال کاربر
        $stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? AND status='active' LIMIT 1");
        $stmt->execute([$customerId]);
        $cartId = (int)($stmt->fetchColumn() ?: 0);

        if ($cartId === 0) {
            $db->prepare("INSERT INTO carts (user_id) VALUES (?)")->execute([$customerId]);
            $cartId = (int)$db->lastInsertId();
        }

        // انتقال اقلام مهمان به سبد کاربر
        foreach ($_SESSION['guest_cart'] as $pid => $qty) {
            $pid = (int)$pid;
            $qty = (int)$qty;
            if ($pid < 1 || $qty < 1) continue;

            $db->prepare("
            INSERT INTO cart_items (cart_id, product_id, qty)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ")->execute([$cartId, $pid, $qty]);
        }

        // پاک کردن سبد مهمان
        unset($_SESSION['guest_cart']);
    }
    $_SESSION['pw_fp'] = session_password_fingerprint((string)$customer['password_hash']);

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
