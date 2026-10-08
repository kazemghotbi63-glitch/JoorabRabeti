<?php
/* [FILE] public/api/forgot-password.php — درخواست و تأیید فراموشی رمز */

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/settings.php';
require_once ROOT_PATH . '/app/services/SmsService.php';

header('Content-Type: application/json; charset=utf-8');

$db = db();

function jsonOut(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'متد نامعتبر'], 405);
}

verify_csrf();

$step = trim((string)($_POST['step'] ?? ''));
$ip   = client_ip();

function tooManyRequests(string $error, int $retryAfter): never
{
    header('Retry-After: ' . $retryAfter);
    jsonOut(['ok' => false, 'error' => $error], 429);
}

/* ═══ مرحله ۱: ارسال کد ═══ */
if ($step === 'request') {

    $channel = trim((string)($_POST['channel'] ?? ''));
    $target  = trim((string)($_POST['target'] ?? ''));

    if (!in_array($channel, ['sms', 'email'], true)) {
        jsonOut(['ok' => false, 'error' => 'روش بازیابی نامعتبر است.'], 422);
    }

    if ($channel === 'sms') {
        $target = strtr($target, [
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
        if (!preg_match('/^09\d{9}$/', $target)) {
            jsonOut(['ok' => false, 'error' => 'شماره موبایل صحیح نیست.'], 422);
        }
        $stmt = $db->prepare("SELECT id, full_name FROM users WHERE mobile = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$target]);
    } else {
        $target = strtolower(trim($target));
        if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['ok' => false, 'error' => 'ایمیل معتبر نیست.'], 422);
        }
        $stmt = $db->prepare("SELECT id, full_name FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$target]);
    }

    /* Rate limit — قبل از جست‌وجوی کاربر تا وجود/عدم وجود حساب لو نرود */
    $rlKey = $channel . ':' . $target;

    if (rate_limit_exceeded('reset_req_target', $rlKey, 1, 60)) {
        tooManyRequests('برای ارسال مجدد کد، یک دقیقه صبر کنید.', 60);
    }
    if (
        rate_limit_exceeded('reset_req_target', $rlKey, 3, 900) ||
        rate_limit_exceeded('reset_req_ip', $ip, 10, 3600)
    ) {
        tooManyRequests('درخواست‌های بازیابی بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 900);
    }

    rate_limit_hit('reset_req_target', $rlKey);
    rate_limit_hit('reset_req_ip', $ip);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    /* امنیت: حتی اگر کاربر نبود، پیام موفق برمی‌گردد */
    if (!$user) {
        jsonOut([
            'ok'  => true,
            'msg' => 'اگر این ' . ($channel === 'sms' ? 'شماره' : 'ایمیل') . ' در سیستم ثبت باشد، کد ارسال می‌شود.',
        ]);
    }

    /* ساخت کد */
    $code = create_password_reset($db, (int)$user['id'], $channel, $target);

    /* ═══ ارسال واقعی با NotifyService (ASA پیامک) ═══ */
    try {
        if ($channel === 'sms') {
            $patternId = Setting::getInt('sms_pattern_password_reset', 0);

            if ($patternId <= 0) {
                throw new RuntimeException('Pattern بازیابی رمز تنظیم نشده است.');
            }

            SmsService::sendPattern(
                $target,
                $patternId,
                [
                    'CODE' => $code
                ],
                (int)$user['id'],
                null,
                'password_reset'
            );
        }
    } catch (Throwable $e) {
        error_log('RESET NOTIFY FAILED: ' . $e->getMessage());
    }
    jsonOut([
        'ok'  => true,
        'msg' => 'اگر این ' . ($channel === 'sms' ? 'شماره' : 'ایمیل') . ' در سیستم ثبت باشد، کد ارسال می‌شود.',
    ]);
}

/* ═══ مرحله ۲: تأیید کد و رمز جدید ═══ */
if ($step === 'reset') {

    $channel = trim((string)($_POST['channel'] ?? ''));
    $target  = trim((string)($_POST['target'] ?? ''));
    $code    = trim((string)($_POST['code'] ?? ''));

    if ($channel === 'sms') {
        $target = strtr($target, [
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
    } else {
        $target = strtolower($target);
    }

    /* Rate limit — ۵ کد اشتباه برای هر مقصد / ۲۰ برای هر IP در ۱۵ دقیقه */
    $rlKey = $channel . ':' . $target;

    if (
        rate_limit_exceeded('reset_verify_target', $rlKey, 5, 900) ||
        rate_limit_exceeded('reset_verify_ip', $ip, 20, 900)
    ) {
        tooManyRequests('تلاش‌های ناموفق زیاد. ۱۵ دقیقه دیگر دوباره تلاش کنید.', 900);
    }

    $newPass  = (string)($_POST['new_password'] ?? '');
    $newPass2 = (string)($_POST['new_password2'] ?? '');

    if (strlen($newPass) < 8) {
        jsonOut(['ok' => false, 'error' => 'رمز جدید باید حداقل ۸ کاراکتر باشد.'], 422);
    }
    if ($newPass !== $newPass2) {
        jsonOut(['ok' => false, 'error' => 'تکرار رمز صحیح نیست.'], 422);
    }

    if ($channel === 'sms') {
        $stmt = $db->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    }
    $stmt->execute([$target]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $reset = false;

    if ($user) {
        $userId = (int)$user['id'];

        $stmt = $db->prepare("
            SELECT id FROM password_resets
            WHERE user_id = ? AND channel = ? AND destination = ?
              AND code = ? AND used_at IS NULL AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$userId, $channel, $target, $code]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* کاربر ناموجود و کد اشتباه پاسخ یکسان دارند */
    if (!$reset) {
        rate_limit_hit('reset_verify_target', $rlKey);
        rate_limit_hit('reset_verify_ip', $ip);
        jsonOut(['ok' => false, 'error' => 'کد نامعتبر یا منقضی شده است.'], 422);
    }

    rate_limit_clear('reset_verify_target', $rlKey);

    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);

    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
        ->execute([(int)$reset['id']]);

    $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                  VALUES (?, 'password.reset', 'user', ?, ?)")
        ->execute([$userId, $userId, $_SERVER['REMOTE_ADDR'] ?? null]);

    jsonOut(['ok' => true, 'msg' => 'رمز عبور با موفقیت تغییر کرد.']);
}

jsonOut(['ok' => false, 'error' => 'درخواست نامعتبر'], 400);
