<?php

declare(strict_types=1);

/* [FILE] public/invoice-pdf.php — Invoice PDF download */

require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/InvoiceService.php';
require_once ROOT_PATH . '/app/services/PdfService.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$db = db();

$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
$isAdmin = !empty($_SESSION['admin_id']);

$invoiceNumber = trim((string)($_GET['inv'] ?? $_GET['invoice'] ?? ''));

if ($invoiceNumber === '') {
    http_response_code(404);
    exit('فاکتور پیدا نشد.');
}

$stmt = $db->prepare("SELECT id, user_id FROM invoices WHERE invoice_number = ? LIMIT 1");
$stmt->execute([$invoiceNumber]);
$invoiceRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoiceRow) {
    http_response_code(404);
    exit('فاکتور پیدا نشد.');
}

if (!$isAdmin && (int)$invoiceRow['user_id'] !== $userId) {
    http_response_code(403);
    exit('دسترسی غیرمجاز.');
}

$invoice = InvoiceService::find($db, (int)$invoiceRow['id']);

if (!$invoice) {
    http_response_code(404);
    exit('فاکتور پیدا نشد.');
}

try {
    PdfService::output($invoice);
} catch (Throwable $e) {
    http_response_code(500);
?>
    <!doctype html>
    <html lang="fa" dir="rtl">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>خطا در تولید PDF</title>
    </head>

    <body style="font-family:Tahoma;padding:30px;line-height:2;">
        <h2>تولید PDF انجام نشد</h2>
        <p>خطا: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/invoice-print?inv=<?= rawurlencode($invoiceNumber) ?>">بازگشت به فاکتور</a>
    </body>

    </html>
<?php
}
