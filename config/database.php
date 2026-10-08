<?php
require_once __DIR__ . '/bootstrap.php';

/* ⚙️ تنظیمات اتصال — روی هاست اشتراکی فقط این مقادیر عوض می‌شود */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'sock_b2b');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP پیش‌فرض رمز ندارد

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
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
