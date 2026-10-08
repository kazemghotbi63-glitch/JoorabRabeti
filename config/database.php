<?php
require_once __DIR__ . '/bootstrap.php';

/* ⚙️ تنظیمات اتصال از فایل .env (نمونه: .env.example) — رمز در کد نگه داشته نمی‌شود */
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_NAME', env('DB_NAME', 'sock_b2b'));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (DB_USER === '') {
            error_log('DB_USER is not set — copy .env.example to .env');
            throw new RuntimeException('Database is not configured.');
        }
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}
