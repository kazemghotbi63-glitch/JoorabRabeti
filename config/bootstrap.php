<?php
/* نقطه شروع همه فایل‌ها — مسیرها + زمان
 *
 * ⭐ PATCH: خط require jalali.php در انتها اضافه شده.
 *    این فایل را جایگزین config/bootstrap.php اصلی کنید.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

if (!defined('PUBLIC_PATH')) {
    /* ⭐ تشخیص خودکار: روی هاست کنار ROOT_PATH، روی لوکال داخل ROOT_PATH */
    $hostPublic = dirname(ROOT_PATH) . '/public_html';

    define(
        'PUBLIC_PATH',
        is_dir($hostPublic) ? $hostPublic : ROOT_PATH . '/public'
    );
}

/* بارگذاری ROOT_PATH/.env — متغیرهای محیطی واقعی سرور اولویت دارند */
if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return $_ENV[$key] ?? $default;
    }
}

if (is_readable(ROOT_PATH . '/.env') && !defined('ENV_LOADED')) {
    define('ENV_LOADED', true);
    foreach (file(ROOT_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}

/* منبع واحد زمان — ۱۶:۰۰ باید همیشه یک معنا داشته باشد */
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Tehran');
}
date_default_timezone_set(APP_TIMEZONE);

/* آدرس اصلی سایت — canonical، sitemap و JSON-LD */
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://joorabrabeti.ir');
}

/* توابع کمکی مشترک (current_user_group, inventory_state, log_stock_movement, record_audit, ...) */
require_once ROOT_PATH . '/config/helpers.php';

/* ⭐ PATCH: توابع تاریخ شمسی (to_jalali, jalali_to_gregorian, fa_digits, en_digits) */
require_once ROOT_PATH . '/app/services/jalali.php';
