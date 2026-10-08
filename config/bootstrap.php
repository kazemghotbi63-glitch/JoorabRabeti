<?php
/* نقطه شروع همه فایل‌ها — مسیرها + زمان */

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

/* منبع واحد زمان — ۱۶:۰۰ باید همیشه یک معنا داشته باشد */
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Tehran');
}
date_default_timezone_set(APP_TIMEZONE);

/* آدرس اصلی سایت — canonical، sitemap و JSON-LD */
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://jorabrabeti.ir');
}
