<?php
// auth.php — نسخه نهایی v3

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,       // لوکال http؛ روی HTTPS → true
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
        return false;
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
    string $password
): array {

    $pdo = db();

    $fullName    = trim($fullName);
    $companyName = trim($companyName);
    $mobile      = trim($mobile);

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

    if (strlen($password) < 8) {
        return [
            'ok' => false,
            'error' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.'
        ];
    }

    // موبایل تکراری
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

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $pdo->beginTransaction();

    try {

        // ۱. کاربر مشتری
        $stmt = $pdo->prepare("
            INSERT INTO users
                (
                    full_name,
                    company_name,
                    mobile,
                    password_hash,
                    role,
                    is_active
                )
            VALUES
                (?, ?, ?, ?, 'customer', 1)
        ");

        $stmt->execute([
            $fullName,
            $companyName !== '' ? $companyName : null,
            $mobile,
            $passwordHash
        ]);

        $customerId = (int)$pdo->lastInsertId();

        // ۲. شرکت در وضعیت pending
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

        // ۳. ارتباط کاربر و شرکت با نقش owner
        $pdo->prepare("
            INSERT INTO company_users
                (company_id, user_id, role)
            VALUES
                (?, ?, 'owner')
        ")->execute([
            $companyId,
            $customerId
        ]);

        // ۴. Audit
        $pdo->prepare("
            INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    entity,
                    entity_id,
                    ip
                )
            VALUES
                (
                    ?,
                    'customer.register',
                    'user',
                    ?,
                    ?
                )
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

        // اگر جدول‌های شرکت هنوز آماده نباشند،
        // ثبت کاربر به‌تنهایی انجام می‌شود.
        $stmt = $pdo->prepare("
            INSERT INTO users
                (
                    full_name,
                    mobile,
                    password_hash,
                    role,
                    is_active
                )
            VALUES
                (?, ?, ?, 'customer', 1)
        ");

        try {

            $stmt->execute([
                $fullName,
                $mobile,
                $passwordHash
            ]);

            $customerId = (int)$pdo->lastInsertId();
        } catch (Throwable $e2) {

            return [
                'ok' => false,
                'error' => 'خطا در ثبت اطلاعات. دوباره تلاش کنید.'
            ];
        }
    }

    return [
        'ok'   => true,
        'id'   => $customerId,
        'name' => $fullName
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
    if (strlen($d['password'] ?? '') < 8) {
        return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.'];
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
