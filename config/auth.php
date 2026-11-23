<?php
// auth.php — نسخه نهایی v3

/* HTTPS مستقیم یا پشت پروکسی/CDN (X-Forwarded-Proto) */
function request_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https';
}

if (session_status() === PHP_SESSION_NONE) {
    /* ⭐ Secure cookie فقط روی HTTPS */
    $isHttps = (
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ) || (
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* ═══════════════════════════════════════════════════════════════
   وضعیت ورود
═══════════════════════════════════════════════════════════════ */

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function is_customer_logged_in(): bool
{
    return !empty($_SESSION['customer_id']);
}

function is_logged_in(): bool
{
    return is_admin_logged_in() || is_customer_logged_in();
}


/* ═══════════════════════════════════════════════════════════════
   CSRF
═══════════════════════════════════════════════════════════════ */

/**
 * ساخت یا دریافت توکن CSRF فعلی نشست.
 */
function csrf_token(): string
{
    if (
        empty($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || strlen($_SESSION['csrf_token']) < 32
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * فیلد مخفی CSRF برای فرم‌های POST.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}

/**
 * اعتبارسنجی توکن CSRF ارسال‌شده (فیلد csrf_token یا هدر X-CSRF-Token).
 *
 * در صورت نامعتبر بودن، درخواست با کد 403 متوقف می‌شود (Apache کد 419 را به 500 تبدیل می‌کند)؛
 * برای درخواست‌های API/AJAX پاسخ JSON برمی‌گردد.
 */
function verify_csrf(?string $token = null): void
{
    $token = $token
        ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (
        is_string($token)
        && $token !== ''
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $token)
    ) {
        return;
    }

    $message = 'درخواست نامعتبر یا منقضی شده است. صفحه را دوباره بارگذاری کنید.';

    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    $wantsJson = str_starts_with($path, '/api/')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    http_response_code(403);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));
    }

    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}


/* ═══════════════════════════════════════════════════════════════
   محدودیت تعداد درخواست (Rate limit) — جدول rate_limits
═══════════════════════════════════════════════════════════════ */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * آیا تعداد رویدادهای ثبت‌شده برای این کلید در پنجره زمانی به سقف رسیده؟
 *
 * اگر جدول هنوز ساخته نشده باشد، درخواست مسدود نمی‌شود (fail-open).
 */
function rate_limit_exceeded(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM rate_limits
            WHERE bucket = ?
              AND rl_key = ?
              AND created_at > NOW() - INTERVAL ? SECOND
        ");
        $stmt->execute([$bucket, hash('sha256', $key), $windowSeconds]);

        return (int)$stmt->fetchColumn() >= $max;
    } catch (Throwable $e) {
        error_log('RATE_LIMIT CHECK FAILED: ' . $e->getMessage());

        return true;
    }
}

function rate_limit_hit(string $bucket, string $key): void
{
    try {
        $pdo = db();
        $pdo->prepare("INSERT INTO rate_limits (bucket, rl_key) VALUES (?, ?)")
            ->execute([$bucket, hash('sha256', $key)]);

        /* پاک‌سازی گاه‌به‌گاه رکوردهای قدیمی */
        if (random_int(1, 100) === 1) {
            $pdo->exec("DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY");
        }
    } catch (Throwable $e) {
        error_log('RATE_LIMIT HIT FAILED: ' . $e->getMessage());
    }
}

function rate_limit_clear(string $bucket, string $key): void
{
    try {
        db()->prepare("DELETE FROM rate_limits WHERE bucket = ? AND rl_key = ?")
            ->execute([$bucket, hash('sha256', $key)]);
    } catch (Throwable $e) {
        error_log('RATE_LIMIT CLEAR FAILED: ' . $e->getMessage());
    }
}


/* ═══════════════════════════════════════════════════════════════
   ورود ادمین
═══════════════════════════════════════════════════════════════ */

function attempt_admin_login(string $mobile, string $password): bool
{
    $pdo = db();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: حداکثر ۵ تلاش ناموفق در ۱۵ دقیقه
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM login_attempts
        WHERE ip = ?
          AND success = 0
          AND created_at > NOW() - INTERVAL 15 MINUTE
    ");

    $stmt->execute([$ip]);

    if ((int)$stmt->fetchColumn() >= 5) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE mobile = ?
          AND role = 'admin'
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    /* موبایل ادمین نیست (مثلاً ورود مشتری از /api/login) → تلاش ناموفق ادمین ثبت نشود */
    if (!$user) {
        return false;
    }

    $ok = !empty($user['password_hash'])
        && password_verify($password, $user['password_hash']);

    $pdo->prepare("
        INSERT INTO login_attempts (mobile, ip, success)
        VALUES (?, ?, ?)
    ")->execute([
        $mobile,
        $ip,
        $ok ? 1 : 0
    ]);

    if (!$ok) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['admin_id']   = (int)$user['id'];
    $_SESSION['pw_fp']      = session_password_fingerprint((string)$user['password_hash']);
    $_SESSION['admin_name'] = $user['full_name'] ?? 'مدیر';

    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_name']
    );

    // توکن CSRF قبلی بعد از تغییر هویت نباید ادامه پیدا کند.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $pdo->prepare("
        UPDATE users
        SET last_login_at = NOW()
        WHERE id = ?
    ")->execute([
        $user['id']
    ]);

    $pdo->prepare("
        INSERT INTO audit_logs
            (user_id, action, ip)
        VALUES
            (?, 'admin.login', ?)
    ")->execute([
        $user['id'],
        $ip
    ]);

    return true;
}


/* ═══════════════════════════════════════════════════════════════
   ورود مشتری — API
═══════════════════════════════════════════════════════════════ */

function attempt_customer_login(
    string $mobile,
    string $password
): array {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            full_name,
            password_hash,
            is_active
        FROM users
        WHERE mobile = ?
          AND role = 'customer'
        LIMIT 1
    ");

    $stmt->execute([$mobile]);

    $user = $stmt->fetch();

    if (
        !$user
        || !password_verify(
            $password,
            $user['password_hash'] ?? ''
        )
    ) {
        return [
            'ok' => false,
            'error' => 'شماره موبایل یا رمز عبور نادرست است.'
        ];
    }

    if (!$user['is_active']) {
        return [
            'ok' => false,
            'error' => 'حساب شما غیرفعال است. با پشتیبانی تماس بگیرید.'
        ];
    }

    session_regenerate_id(true);

    $_SESSION['customer_id']   = (int)$user['id'];
    $_SESSION['pw_fp']         = session_password_fingerprint((string)$user['password_hash']);
    $_SESSION['customer_name'] = $user['full_name'];

    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_name']
    );

    // توکن CSRF جدید برای نشست جدید
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $pdo->prepare("
        UPDATE users
        SET last_login_at = NOW()
        WHERE id = ?
    ")->execute([
        $user['id']
    ]);

    return [
        'ok'   => true,
        'name' => $user['full_name']
    ];
}

/* ═══════════════════════════════════════════════════════════════
   ثبت‌نام مشتری + شرکت در انتظار تأیید
═══════════════════════════════════════════════════════════════ */

function register_customer(
    string $fullName,
    string $companyName,
    string $mobile,
    string $password,
    string $nationalCode = '',
    string $customerType = 'individual'
): array {
    $userType = $customerType === 'company' ? 'legal' : 'real';

    $pdo = db();

    $fullName    = trim($fullName);
    $companyName = trim($companyName);
    $mobile      = trim($mobile);

    /* ⭐ تبدیل ارقام فارسی/عربی → انگلیسی برای کد ملی */
    $nationalCode = strtr(trim($nationalCode), [
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

    if ($fullName === '') {
        return [
            'ok' => false,
            'error' => 'نام و نام خانوادگی را وارد کنید.'
        ];
    }

    if (!preg_match('/^09\d{9}$/', $mobile)) {
        return [
            'ok' => false,
            'error' => 'شماره موبایل صحیح نیست.'
        ];
    }

    /* ⭐ اعتبارسنجی کد ملی (الگوریتم چک‌سام) */
    if (
        strlen($nationalCode) !== 10
        || !ctype_digit($nationalCode)
        || preg_match('/^(\d)\1{9}$/', $nationalCode)
    ) {
        return [
            'ok' => false,
            'error' => 'کد ملی ۱۰ رقمی معتبر وارد کنید.'
        ];
    }

    /* چک‌سام کد ملی */
    $check = (int)$nationalCode[9];
    $sum   = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nationalCode[$i] * (10 - $i);
    }
    $r = $sum % 11;
    $validCheck = ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
    if (!$validCheck) {
        return [
            'ok' => false,
            'error' => 'کد ملی معتبر نیست.'
        ];
    }

    if (strlen($password) < 4) {
        return [
            'ok' => false,
            'error' => 'رمز عبور باید حداقل ۴ کاراکتر باشد.'
        ];
    }

    /* ⭐ چک تکراری بودن کد ملی */
    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE national_code = ?
        LIMIT 1
    ");
    $stmt->execute([$nationalCode]);

    if ($stmt->fetch()) {
        return [
            'ok' => false,
            'error' => 'این کد ملی قبلاً ثبت شده است.'
        ];
    }

    /* چک موبایل تکراری */
    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE mobile = ?
        LIMIT 1
    ");
    $stmt->execute([$mobile]);

    if ($stmt->fetch()) {
        return [
            'ok' => false,
            'error' => 'این شماره موبایل قبلاً ثبت شده است.'
        ];
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    try {

        /* ۱. کاربر مشتری — با کد ملی */
        $stmt = $pdo->prepare("
            INSERT INTO users
                (
                    full_name,
                    company_name,
                    mobile,
                    password_hash,
                    national_code,
                    customer_type,
                    user_type,
                    role,
                    is_active
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'customer', 1)
        ");

        $stmt->execute([
            $fullName,
            $companyName !== '' ? $companyName : null,
            $mobile,
            $passwordHash,
            $nationalCode,
            $userType,
            $userType,
        ]);

        $customerId = (int)$pdo->lastInsertId();

        /* ۲. شرکت در وضعیت pending */
        $stmt = $pdo->prepare("
            INSERT INTO companies
                (name, status)
            VALUES
                (?, 'pending')
        ");

        $stmt->execute([
            $companyName !== ''
                ? $companyName
                : 'مشتری بدون نام شرکت'
        ]);

        $companyId = (int)$pdo->lastInsertId();

        /* ۳. ارتباط کاربر و شرکت */
        $pdo->prepare("
            INSERT INTO company_users
                (company_id, user_id, role)
            VALUES
                (?, ?, 'owner')
        ")->execute([
            $companyId,
            $customerId
        ]);

        /* ۴. Audit */
        $pdo->prepare("
            INSERT INTO audit_logs
                (user_id, action, entity, entity_id, ip)
            VALUES
                (?, 'customer.register', 'user', ?, ?)
        ")->execute([
            $customerId,
            $customerId,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        $pdo->commit();
    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[register_customer] ' . $e->getMessage());

        return [
            'ok' => false,
            'error' => 'خطا در ثبت اطلاعات. دوباره تلاش کنید.'
        ];
    }

    return [
        'ok'   => true,
        'id'   => $customerId,
        'name' => $fullName,
    ];
}

/* ═══════════════════════════════════════════════════════════════
   گاردها
═══════════════════════════════════════════════════════════════ */

function require_admin(): void
{
    if (!is_admin_logged_in()) {
        header('Location: /admin/login');
        exit;
    }
}

function require_customer(): void
{
    if (!is_customer_logged_in()) {
        header('Location: /');
        exit;
    }
}


/* ═══════════════════════════════════════════════════════════════
   خرید مهمان‌پسند — helper های مشترک (login / register / checkout)
   ═══════════════════════════════════════════════════════════════ */

/**
 * ادغام سبد مهمان (session) با سبد فعال کاربر.
 * بعد از ورود/ثبت‌نام در هر نقطه‌ای از فرآیند خرید صدا زده می‌شود تا
 * «سبد قبل از ورود ذخیره شود و بعد از ورود ادامه پیدا کند».
 */
function rabeti_merge_guest_cart(PDO $db): void
{
    if (empty($_SESSION['customer_id'])) {
        return;
    }
    if (empty($_SESSION['guest_cart']) || !is_array($_SESSION['guest_cart'])) {
        return;
    }

    $userId = (int)$_SESSION['customer_id'];

    $stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? AND status='active' LIMIT 1");
    $stmt->execute([$userId]);
    $cartId = (int)($stmt->fetchColumn() ?: 0);

    if ($cartId === 0) {
        $db->prepare("INSERT INTO carts (user_id) VALUES (?)")->execute([$userId]);
        $cartId = (int)$db->lastInsertId();
    }

    foreach ($_SESSION['guest_cart'] as $pid => $qty) {
        $pid = (int)$pid;
        $qty = (int)$qty;
        if ($pid < 1 || $qty < 1) {
            continue;
        }

        $db->prepare("
            INSERT INTO cart_items (cart_id, product_id, qty)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ")->execute([$cartId, $pid, $qty]);
    }

    unset($_SESSION['guest_cart']);
}

/**
 * ارتقای آدرس مهمان (session) به آدرس واقعی مشتری در مرحله آخر خرید.
 * اگر مشتری قبلاً آدرس انتخاب کرده باشد، کاری نمی‌کند.
 */
function rabeti_promote_guest_address(PDO $db, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    if ((int)($_SESSION['checkout_address_id'] ?? 0) > 0) {
        return;
    }
    if (empty($_SESSION['checkout_guest_address']) || !is_array($_SESSION['checkout_guest_address'])) {
        return;
    }

    if (!class_exists('AddressService')) {
        require_once ROOT_PATH . '/app/services/AddressService.php';
    }

    $ga = $_SESSION['checkout_guest_address'];

    try {
        $addressId = AddressService::create($userId, [
            'label'           => (string)($ga['label'] ?? '') !== '' ? (string)$ga['label'] : 'آدرس ارسال',
            'province'        => (string)($ga['province'] ?? ''),
            'city'            => (string)($ga['city'] ?? ''),
            'address'         => (string)($ga['address'] ?? ''),
            'postal_code'     => (string)($ga['postal_code'] ?? ''),
            'plate_no'        => (string)($ga['plate_no'] ?? ''),
            'unit_no'         => (string)($ga['unit_no'] ?? ''),
            'receiver_name'   => (string)($ga['receiver_name'] ?? ''),
            'receiver_mobile' => (string)($ga['receiver_mobile'] ?? ''),
            'is_default'      => false,
        ]);

        $_SESSION['checkout_address_id'] = (int)$addressId;
        unset($_SESSION['checkout_guest_address']);
    } catch (Throwable $e) {
        /* آدرس نامعتبر → کاربر در checkout-address دوباره ثبت می‌کند */
        error_log('[rabeti_promote_guest_address] ' . $e->getMessage());
    }
}


/* ═══════════════════════════════════════════════════════════════
   نام کاربر
═══════════════════════════════════════════════════════════════ */

function admin_name(): string
{
    return $_SESSION['admin_name'] ?? 'مدیر';
}

function customer_name(): string
{
    return $_SESSION['customer_name'] ?? 'کاربر';
}


/** توکن مخصوص لینک خروج؛ از CSRF مشتق می‌شود تا خود توکن CSRF در URL نیاید. */
function logout_token(): string
{
    return hash_hmac('sha256', 'logout', csrf_token());
}

function logout_token_valid(): bool
{
    if (isset($_POST['csrf_token'])) {
        return hash_equals(csrf_token(), (string)$_POST['csrf_token']);
    }
    return hash_equals(logout_token(), (string)($_GET['t'] ?? ''));
}

/* ═══════════════════════════════════════════════════════════════
   خروج کامل
═══════════════════════════════════════════════════════════════ */

function logout_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
/* ══════════ فراموشی رمز ══════════ */

/**
 * ساخت کد فراموشی رمز — ارسال با SMS/Email توسط سرویس‌های مربوطه
 */
function create_password_reset(PDO $db, int $userId, string $channel, string $destination): string
{
    /* توکن امن برای لینک، کد ۶ رقمی برای تأیید سریع */
    $token = bin2hex(random_bytes(32));
    $code  = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    /* توکن‌های قبلی استفاده‌نشده باطل */
    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
        ->execute([$userId]);

    $db->prepare("
        INSERT INTO password_resets (user_id, token, channel, destination, code, expires_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    ")->execute([$userId, $token, $channel, $destination, $code]);

    return $code;
}

/**
 * ثبت‌نام حقیقی/حقوقی
 */
function register_user_full(array $d): array
{
    $pdo = db();

    $mobile = strtr(trim($d['mobile'] ?? ''), [
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
    ]);

    $userType = ($d['user_type'] ?? 'real') === 'legal' ? 'legal' : 'real';

    if (mb_strlen(trim($d['full_name'] ?? '')) < 3) {
        return ['ok' => false, 'error' => 'نام و نام خانوادگی را وارد کنید.'];
    }
    if (!preg_match('/^09\d{9}$/', $mobile)) {
        return ['ok' => false, 'error' => 'شماره موبایل صحیح نیست.'];
    }
    if (strlen($d['password'] ?? '') < 4) {
        return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۴ کاراکتر باشد.'];
    }
    if (($d['password'] ?? '') !== ($d['password2'] ?? '')) {
        return ['ok' => false, 'error' => 'تکرار رمز عبور صحیح نیست.'];
    }
    if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'ایمیل معتبر نیست.'];
    }

    /* حقیقی: کد ملی ۱۰ رقم + جنسیت الزامی */
    $nationalCode = strtr(trim($d['national_code'] ?? ''), [
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
    ]);

    if ($userType === 'real') {
        if (!preg_match('/^\d{10}$/', $nationalCode)) {
            return ['ok' => false, 'error' => 'کد ملی باید ۱۰ رقم باشد.'];
        }
        if (!in_array($d['gender'] ?? '', ['male', 'female'], true)) {
            return ['ok' => false, 'error' => 'جنسیت را انتخاب کنید.'];
        }
    }

    /* حقوقی: شناسه اقتصادی + نام شرکت الزامی */
    $economicCode = strtr(trim($d['economic_code'] ?? ''), [
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
    ]);

    if ($userType === 'legal') {
        $companyName = trim($d['company_name'] ?? '');
        if (mb_strlen($companyName) < 2) {
            return ['ok' => false, 'error' => 'نام شرکت را وارد کنید.'];
        }
        if (!preg_match('/^\d{10,12}$/', $economicCode)) {
            return ['ok' => false, 'error' => 'شناسه اقتصادی باید ۱۰ تا ۱۲ رقم باشد.'];
        }
    }

    /* تکراری نبودن موبایل و ایمیل */
    $stmt = $pdo->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
    $stmt->execute([$mobile]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'این شماره موبایل قبلاً ثبت شده است.'];
    }

    if (!empty($d['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim($d['email'])]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'این ایمیل قبلاً ثبت شده است.'];
        }
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users
                (full_name, company_name, mobile, email, password_hash, role,
                 user_type, national_code, gender, economic_code, is_active)
            VALUES (?,?,?,?,?, 'customer', ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            trim($d['full_name']),
            $userType === 'legal' ? $companyName : ($d['company_name'] ?? null),
            $mobile,
            !empty($d['email']) ? trim($d['email']) : null,
            password_hash($d['password'], PASSWORD_DEFAULT),
            $userType,
            $userType === 'real' ? $nationalCode : null,
            $userType === 'real' ? ($d['gender'] ?? null) : null,
            $userType === 'legal' ? $economicCode : null,
        ]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->commit();
        return ['ok' => true, 'id' => $userId, 'name' => trim($d['full_name'])];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


/* ═══════════════════════════════════════════════════════════════
   ابطال نشست بعد از تغییر رمز یا غیرفعال‌شدن حساب
═══════════════════════════════════════════════════════════════ */

function session_password_fingerprint(string $passwordHash): string
{
    return substr(hash('sha256', $passwordHash), 0, 32);
}

function enforce_session_validity(): void
{
    $uid = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
    if ($uid <= 0 || empty($_SESSION['pw_fp']) || !function_exists('db')) {
        return;
    }
    if ((int)($_SESSION['pw_checked_at'] ?? 0) > time() - 60) {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT password_hash, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[session-validity] ' . $e->getMessage());
        return;
    }
    if (
        !$row
        || !(int)$row['is_active']
        || !hash_equals((string)$_SESSION['pw_fp'], session_password_fingerprint((string)$row['password_hash']))
    ) {
        logout_session();
        return;
    }
    $_SESSION['pw_checked_at'] = time();
}

enforce_session_validity();
