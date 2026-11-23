<?php
/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — API ثبت‌نام مشتری  |  v5 | 2026-10-23
 *  • تفکیک حقیقی / حقوقی
 *  • کد ملی اجباری با چک‌سام
 *  • تبدیل ارقام فارسی/عربی
 *  • CSRF fallback (اگر auth.php تابع را تعریف نکرد)
 * ═══════════════════════════════════════════════════════════════════ */
/* ═══ Auto-detect bootstrap (اصلاح‌شده برای هاست) ═══ */
$__bp = [
    __DIR__ . '/../../config/bootstrap.php',       // public/config/bootstrap.php (رایج‌ترین)
    __DIR__ . '/../config/bootstrap.php',          // api/config/bootstrap.php
    dirname(__DIR__, 2) . '/config/bootstrap.php', // ریشه پروژه/config/bootstrap.php
    dirname(__DIR__, 3) . '/config/bootstrap.php', // یک پله بالاتر
    __DIR__ . '/../../sock-b2b/config/bootstrap.php', // حالت لوکال شما
];

$__found = false;
foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        $__found = true;
        break;
    }
}
unset($__bp, $__b);

if (!$__found) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'error' => 'فایل bootstrap.php پیدا نشد. لطفاً مسیر پوشه config را بررسی کنید.'
        /* ⭐ FIX امنیتی: debug_paths (مسیرهای سرور) از پاسخ ناشناس حذف شد */
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ═══ CSRF fallback ═══ */
if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $token
            ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (
            !is_string($token) || $token === ''
            || !is_string($sessionToken) || $sessionToken === ''
            || !hash_equals($sessionToken, $token)
        ) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'توکن امنیتی نامعتبر است. صفحه را رفرش کنید.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/* ═══ Helper: تبدیل ارقام فارسی/عربی به انگلیسی ═══ */
$toEnDigits = static function (string $s): string {
    return strtr($s, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
    ]);
};

/* ═══ Helper: اعتبارسنجی کد ملی (چک‌سام ۱۰ رقمی) ═══ */
$isValidNationalCode = static function (string $code): bool {
    if (strlen($code) !== 10 || !ctype_digit($code)) return false;
    if (preg_match('/^(\d)\1{9}$/', $code)) return false;
    $check = (int)$code[9];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += (int)$code[$i] * (10 - $i);
    $r = $sum % 11;
    return ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'متد درخواست نامعتبر است.'], 405);
}

verify_csrf();

try {
    $customerType = ($_POST['customer_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
    $fullName     = trim((string)($_POST['full_name']     ?? ''));
    $companyName  = trim((string)($_POST['company_name']  ?? ''));
    $mobile       = $toEnDigits(trim((string)($_POST['mobile'] ?? '')));
    $nationalCode = $toEnDigits(trim((string)($_POST['national_code'] ?? '')));
    $password     = (string)($_POST['password']  ?? '');
    $password2    = (string)($_POST['password2'] ?? '');

    if ($fullName === '') {
        json_response(['ok' => false, 'error' => 'نام و نام خانوادگی را وارد کنید.'], 422);
    }
    if ($customerType === 'company' && $companyName === '') {
        json_response(['ok' => false, 'error' => 'نام شرکت / مغازه را وارد کنید.'], 422);
    }
    if (!preg_match('/^09\d{9}$/', $mobile)) {
        json_response(['ok' => false, 'error' => 'شماره موبایل صحیح نیست.'], 422);
    }
    if (!$isValidNationalCode($nationalCode)) {
        json_response(['ok' => false, 'error' => 'کد ملی ۱۰ رقمی معتبر وارد کنید.'], 422);
    }
    if (strlen($password) < 4) {
        json_response(['ok' => false, 'error' => 'رمز عبور باید حداقل ٤ کاراکتر باشد.'], 422);
    }
    if ($password !== $password2) {
        json_response(['ok' => false, 'error' => 'تکرار رمز عبور صحیح نیست.'], 422);
    }

    $result = register_customer(
        $fullName,
        $customerType === 'company' ? $companyName : '',
        $mobile,
        $password,
        $nationalCode,
        $customerType
    );

    if (!$result['ok']) {
        json_response($result, 409);
    }

    /* ⭐ خرید مهمان‌پسند: ورود خودکار بعد از ثبت‌نام — تا کاربر
       در ادامهٔ همان سبد و با تخفیف سطح خودش به مرحلهٔ آخر برسد */
    $autoLogin = attempt_customer_login($mobile, $password);
    if ($autoLogin['ok']) {
        rabeti_merge_guest_cart(db());
        rabeti_promote_guest_address(db(), (int)$_SESSION['customer_id']);
    }

    json_response([
        'ok'       => true,
        'logged_in' => $autoLogin['ok'] ? true : false,
        'role'     => 'customer',
        'name'     => $result['name'] ?? $fullName,
        'id'       => $result['id']   ?? null,
        'redirect' => '/'
    ], 200);
} catch (PDOException $e) {
    error_log('REGISTER PDO ERROR: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'خطای پایگاه داده هنگام ثبت‌نام.'], 500);
} catch (Throwable $e) {
    error_log('REGISTER ERROR: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'خطای سرور هنگام ثبت‌نام.'], 500);
}
