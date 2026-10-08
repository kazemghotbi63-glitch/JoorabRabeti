<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * خواندن تنظیمات کسب‌وکار از DB
 *
 * همه پارامترها از جدول settings خوانده می‌شوند
 * و در صورت نبودن مقدار، مقدار پیش‌فرض استفاده می‌شود.
 *
 * مثال:
 *
 * Setting::get('ship_fee_peyk_tehran', '80000');
 * Setting::getInt('ship_window_days', 3);
 */
final class Setting
{
    private static ?array $cache = null;

    /**
     * بارگذاری تنظیمات از DB
     */
    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {

            foreach (
                db()->query(
                    "SELECT `key`, `value`
                     FROM settings"
                ) as $row
            ) {

                self::$cache[(string)$row['key']] = (string)$row['value'];
            }
        } catch (Throwable $e) {

            /*
             * اگر جدول settings هنوز ساخته نشده باشد،
             * مقادیر پیش‌فرض استفاده خواهند شد.
             */
        }
    }

    /**
     * دریافت مقدار متنی
     */
    public static function get(
        string $key,
        string $default = ''
    ): string {

        self::load();

        return self::$cache[$key] ?? $default;
    }

    /**
     * دریافت مقدار عددی
     */
    public static function getInt(
        string $key,
        int $default
    ): int {

        $value = self::get(
            $key,
            ''
        );

        return (
            $value !== ''
            && is_numeric($value)
        )
            ? (int)$value
            : $default;
    }
}
