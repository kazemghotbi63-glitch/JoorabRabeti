<?php
declare(strict_types=1);

/*
 * سرو امن فایل‌های خصوصی (فیش واریز، کارت ملی، رسیدها و فایل‌های سفارش اختصاصی).
 * دسترسی مستقیم به uploads/receipts و uploads/custom-orders در .htaccess بسته است.
 */

foreach ([__DIR__ . '/../sock-b2b/config/bootstrap.php', __DIR__ . '/../config/bootstrap.php'] as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__b);

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

function private_file_deny(int $code): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($code === 403 ? 'دسترسی غیرمجاز.' : 'فایل پیدا نشد.');
}

$rel = ltrim(str_replace('\\', '/', (string)($_GET['p'] ?? '')), '/');

if (
    str_contains($rel, '..')
    || !preg_match('#^uploads/(receipts|custom-orders)/([A-Za-z0-9._\-/]+)$#', $rel, $m)
) {
    private_file_deny(404);
}

$area = $m[1];
$root = realpath(PUBLIC_PATH . '/uploads/' . $area);
$abs  = realpath(PUBLIC_PATH . '/' . $rel);

if ($root === false || $abs === false || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
    private_file_deny(404);
}

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$allowed    = is_admin_logged_in();

if (!$allowed) {
    if ($area === 'receipts') {
        $stmt = db()->prepare('SELECT user_id FROM payment_submissions WHERE receipt_path IN (?, ?) LIMIT 1');
        $stmt->execute(['/' . $rel, $rel]);
        $owner   = $stmt->fetchColumn();
        $allowed = $customerId > 0 && $owner !== false && (int)$owner === $customerId;
    } else {
        $trackingCode = explode('/', $m[2])[0];
        $stmt = db()->prepare('SELECT id, user_id FROM custom_orders WHERE tracking_code = ? LIMIT 1');
        $stmt->execute([$trackingCode]);
        $co = $stmt->fetch();
        if ($co) {
            $granted = array_map('intval', (array)($_SESSION['co_access'] ?? []));
            $ownerId = $co['user_id'] !== null ? (int)$co['user_id'] : null;
            /* co_access فقط بعد از تأیید کد پیگیری + موبایل در همین نشست ثبت می‌شود
               و هنگام ورود/خروج پاک می‌شود؛ همان سیاست صفحهٔ پیگیری. */
            $allowed = ($customerId > 0 && $ownerId === $customerId)
                || in_array((int)$co['id'], $granted, true);
        }
    }
}

if (!$allowed) {
    private_file_deny(403);
}

$mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true)) {
    private_file_deny(404);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($abs));
header('Content-Disposition: inline; filename="' . basename($abs) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
