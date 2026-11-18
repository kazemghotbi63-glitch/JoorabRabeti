<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$__bp = [
    __DIR__ . '/../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../config/bootstrap.php',
];
foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__bp, $__b);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once ROOT_PATH . '/app/services/InvoiceService.php';
$db = db();

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$moneyFa = static function ($amount): string {
    $formatted = number_format((int)$amount);
    $formatted = strtr($formatted, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
    ]);
    return $formatted . ' تومان';
};

/* ═══ تاریخ شمسی ═══ */
$dateTimeFa = static function ($value): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    if ($ts === false) return (string)$value;

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    $g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? $gy + 1 : $gy;
    $d = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $g[$gm - 1];
    $jy += 33 * intdiv($d, 12053);
    $d %= 12053;
    $jy += 4 * intdiv($d, 1461);
    $d %= 1461;
    if ($d > 365) {
        $jy += intdiv($d - 1, 365);
        $d = ($d - 1) % 365;
    }
    $jm = ($d < 186) ? 1 + intdiv($d, 31) : 7 + intdiv($d - 186, 30);
    $jd = 1 + (($d < 186) ? $d % 31 : ($d - 186) % 30);

    $out = $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);
    return $out . ' - ' . date('H:i', $ts);
};

$dateOnlyFa = static function ($value) use ($dateTimeFa): string {
    $full = $dateTimeFa($value);
    return $full === '—' ? '—' : explode(' - ', $full)[0];
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'pending'   => 'در انتظار تأیید مالی',
        'verified'  => 'تأیید شده',
        'adjusted'  => 'اصلاح شده',
        'rejected'  => 'رد شده',
        'confirmed' => 'تأیید شده',
        default     => 'نامشخص',
    };
};

$methodLabel = static function (string $method): string {
    return match ($method) {
        'sheba'         => 'انتقال با شبا',
        'card_to_card'  => 'کارت به کارت',
        'cash_receipt'  => 'فیش نقدی',
        'online'        => 'درگاه پرداخت آنلاین',
        default         => 'پرداخت',
    };
};

/* ═══ احراز هویت ═══ */
if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /');
    exit;
}

/* ═══ یافتن فاکتور ═══ */
$invoiceId     = (int)($_GET['id'] ?? 0);
$invoiceNumber = trim((string)($_GET['inv'] ?? ''));

$invoice = null;

try {
    if ($invoiceId > 0) {
        $invoice = InvoiceService::find($db, $invoiceId);
    } elseif ($invoiceNumber !== '') {
        $stmt = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
        $stmt->execute([$invoiceNumber]);
        if ($found = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invoice = InvoiceService::find($db, (int)$found['id']);
        }
    }
} catch (Throwable $e) {
    $invoice = null;
}

if (!$invoice) {
    http_response_code(404);
?>
    <!doctype html>
    <html lang="fa" dir="rtl">

    <head>
        <meta charset="utf-8">
        <title>فاکتور پیدا نشد | رابطی</title>
    </head>

    <body class="invoice-page invoice-error-page" style="font-family:Tahoma;padding:40px;text-align:center;">
        <h1>فاکتور پیدا نشد</h1>
        <p>فاکتور موردنظر وجود ندارد.</p>
        <a href="/account-orders">بازگشت به سفارش‌ها</a>
    </body>

    </html>
<?php
    exit;
}

/* ═══ دسترسی ═══ */
$isAdmin = !empty($_SESSION['admin_id']);
$invoiceUserId = (int)($invoice['user_id'] ?? $invoice['customer_id'] ?? $invoice['order_user_id'] ?? 0);

if (!$isAdmin && $invoiceUserId !== $userId) {
    http_response_code(403);
    exit('دسترسی غیرمجاز.');
}

/* ═══ شناسه فاکتور (سازگار با id و invoice_id) ═══ */
$invoiceId = (int)($invoice['invoice_id'] ?? $invoice['id'] ?? 0);

/* ═══ تاریخچه پرداخت ═══ */
$paymentStmt = $db->prepare("  
    SELECT  
        ps.id, ps.method, ps.claimed_amount, ps.reference_no,  
        ps.receipt_path, ps.transaction_date, ps.status, ps.created_at,  
        v.verified_amount, v.status AS verification_status,  
        v.note AS verification_note, v.verified_at  
    FROM payment_submissions ps  
    LEFT JOIN payment_verifications v ON v.payment_submission_id = ps.id  
    WHERE ps.invoice_id = ?  
    ORDER BY ps.created_at ASC, ps.id ASC  
");
$paymentStmt->execute([$invoiceId]);
$payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══ اسناد بازگشت وجه ═══ */
$refunds = [];
$stmt = $db->prepare("  
    SELECT ft.amount, ft.method, ft.document_no, ft.reference_no, ft.completed_at  
    FROM financial_transactions ft  
    WHERE ft.invoice_id = ? AND ft.type = 'creditor_settlement' AND ft.status = 'completed'  
    ORDER BY ft.completed_at ASC  
");
$stmt->execute([$invoiceId]);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRefunded = 0;
foreach ($refunds as $r) $totalRefunded += (int)$r['amount'];

/* ═══ محاسبات ═══ */
$pendingAmount = 0;
$hasPendingPayment = false;

foreach ($payments as &$payment) {
    $claimed = max(0, (int)($payment['claimed_amount'] ?? 0));
    $payment['_claimed']  = $claimed;
    $payment['_verified'] = $payment['verified_amount'] !== null
        ? max(0, (int)$payment['verified_amount'])
        : null;

    if ((string)$payment['status'] === 'pending') {
        $pendingAmount += $claimed;
        $hasPendingPayment = true;
    }
}
unset($payment);

/* ═══ داده‌های نمایش ═══ */
$items  = $invoice['items'] ?? [];
$totals = $invoice['totals'] ?? [];

$invoiceNo   = (string)($invoice['invoice_number'] ?? $invoiceNumber ?? '—');
$orderNo     = (string)($invoice['order_number'] ?? '—');
$invoiceDate = (string)($invoice['order_created_at'] ?? $invoice['issued_at'] ?? '');

$invoiceStatus = (string)($invoice['status'] ?? 'unpaid');

/* ─ خریدار (از users) — صاحب فاکتور */
$buyerName        = (string)($invoice['buyer_full_name'] ?? '—');
$buyerMobile      = (string)($invoice['buyer_mobile'] ?? '—');
$buyerNational    = (string)($invoice['buyer_national_code'] ?? '');
$buyerEconomic    = (string)($invoice['buyer_economic_code'] ?? '');
$buyerCompany     = (string)($invoice['buyer_company'] ?? '');
$buyerType        = (string)($invoice['buyer_type'] ?? 'real');  // real | legal  

/* ─ گیرنده (از orders) — فقط برای ارسال */
$receiverName     = (string)($invoice['shipping_name'] ?? '');
$receiverMobile   = (string)($invoice['shipping_mobile'] ?? '');
$receiverProvince = (string)($invoice['shipping_province'] ?? '');
$receiverCity     = (string)($invoice['shipping_city'] ?? '');
$receiverAddress  = (string)($invoice['shipping_address'] ?? '');
$receiverPostal   = (string)($invoice['shipping_postal'] ?? '');
$customerNote     = (string)($invoice['customer_note'] ?? $invoice['note'] ?? '');

$subtotal = (int)($totals['subtotal'] ?? 0);
$discount = (int)($totals['discount'] ?? 0);
$shipping = (int)($totals['shipping'] ?? 0);
$tax      = (int)($totals['tax'] ?? 0);
$total    = (int)($totals['total'] ?? 0);
$paid     = (int)($totals['paid'] ?? 0);
$remaining = $total - $paid;

$recType = 'تسویه';
$recAmount = 0;
if ($remaining > 0) {
    $recType = 'بدهکار';
    $recAmount = $remaining;
} elseif ($remaining < 0) {
    $recType = 'بستانکار';
    $recAmount = abs($remaining);
}

$canPay = !$hasPendingPayment
    && $remaining > 0
    && $recType !== 'بستانکار'
    && $invoiceStatus !== 'void';

$logoPath = '/Image/logo3.jpg';
$siteUrl  = 'https://joorabrabeti.ir';

$itemsCount = 0;
foreach ($items as $item) $itemsCount += (int)($item['qty'] ?? 0);

header('X-Robots-Tag: noindex, nofollow, noarchive');
?>
<!doctype html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>فاکتور <?= $h($invoiceNo) ?> | رابطی</title>

    <style>
        :root {
            --ink: #1a1a1a;
            --muted: #6b7280;
            --line: #e5e7eb;
            --soft: #f9fafb;
            --accent: #2a5f78;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
        }

        body.invoice-page {
            font-family: Tahoma, "Vazirmatn", sans-serif;
            background: #f2f3f5;
            color: var(--ink);
            font-size: 13px;
            margin: 0;
            padding: 24px 12px;
        }

        .ltr {
            direction: ltr;
            unicode-bidi: plaintext;
        }

        /* ─── Toolbar ─── */
        .invoice-actions {
            width: min(820px, 100%);
            margin: 0 auto 14px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .actions-right,
        .actions-left {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12px;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }

        .action-btn:hover {
            border-color: var(--ink);
        }

        .action-print-a4 {
            background: var(--ink);
            border-color: var(--ink);
            color: #fff;
        }

        /* ─── Sheet ─── */
        .invoice-sheet {
            width: min(820px, 100%);
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }

        .invoice-content {
            padding: 0 28px 24px;
        }

        /* ─── Header ─── */
        .invoice-header {
            padding: 24px 28px 0;
        }

        .invoice-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--ink);
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }

        .brand-name {
            font-size: 20px;
            font-weight: 800;
        }

        .brand-subtitle {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        .brand-site-url {
            font-size: 10px;
            color: var(--muted);
            direction: ltr;
            text-align: right;
        }

        .invoice-title {
            text-align: left;
        }

        .invoice-title-label {
            font-size: 11px;
            color: var(--muted);
        }

        .invoice-title-number {
            font-size: 16px;
            font-weight: 800;
            direction: ltr;
        }

        .invoice-meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 14px 0;
        }

        .meta-card {
            background: var(--soft);
            border-radius: 8px;
            padding: 8px 12px;
        }

        .meta-label {
            display: block;
            font-size: 10px;
            color: var(--muted);
            margin-bottom: 2px;
        }

        .meta-value {
            font-size: 12px;
        }

        /* ─── Parties ─── */
        .parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .party-card {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
        }

        .party-head {
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--line);
        }

        .party-title {
            font-size: 12px;
            font-weight: 800;
            color: var(--accent);
        }

        .party-row,
        .party-row-split {
            display: flex;
            gap: 10px;
            padding: 3px 0;
            font-size: 12px;
        }

        .party-row-split {
            justify-content: space-between;
        }

        .party-row-half {
            display: flex;
            gap: 6px;
            flex: 1;
        }

        .party-row-label {
            color: var(--muted);
            font-size: 11px;
            min-width: 52px;
        }

        .party-row-value {
            font-weight: 600;
        }

        .receiver-line {
            background: var(--soft);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 11px;
            margin-bottom: 16px;
            line-height: 1.9;
        }

        /* ─── Sections ─── */
        .section-title {
            font-size: 12px;
            font-weight: 800;
            color: var(--accent);
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--line);
        }

        .products-section,
        .payment-section {
            margin-bottom: 18px;
        }

        /* ─── Items table ─── */
        .products-table {
            width: 100%;
            border-collapse: collapse;
        }

        .products-table th {
            background: var(--soft);
            font-size: 10px;
            color: var(--muted);
            font-weight: 700;
            padding: 7px 8px;
            border-bottom: 1px solid var(--line);
            text-align: right;
        }

        .products-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 12px;
            vertical-align: middle;
        }

        .products-table .center {
            text-align: center;
        }

        .item-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--line);
        }

        .item-thumb-empty {
            width: 44px;
            height: 44px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--soft);
            border-radius: 6px;
            font-size: 18px;
        }

        .product-name {
            font-weight: 700;
        }

        .product-code {
            font-size: 10px;
            color: var(--muted);
            direction: ltr;
            text-align: right;
        }

        /* ─── Payments ─── */
        .payment-item {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .payment-item-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .payment-method {
            font-weight: 700;
            margin-left: 8px;
        }

        .payment-item-amount {
            font-weight: 800;
        }

        .payment-status {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
        }

        .payment-status-verified,
        .payment-status-confirmed,
        .payment-status-adjusted {
            background: #eaf7ef;
            color: var(--success);
        }

        .payment-status-pending {
            background: #fff7df;
            color: var(--warning);
        }

        .payment-status-rejected {
            background: #fff0ee;
            color: var(--danger);
        }

        .payment-item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 8px;
            padding-top: 7px;
            border-top: 1px dashed var(--line);
            font-size: 10px;
            color: var(--muted);
        }

        .payment-meta-label {
            font-weight: 700;
            margin-left: 3px;
        }

        /* ─── Bottom ─── */
        .invoice-bottom {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 20px;
            align-items: start;
        }

        .note-box {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .note-box-title {
            font-weight: 800;
            color: var(--ink);
            margin-bottom: 4px;
        }

        .reconciliation-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
        }

        .reconciliation-title {
            font-size: 11px;
            font-weight: 800;
            color: var(--accent);
            margin-bottom: 6px;
        }

        .reconciliation-result,
        .reconciliation-amount {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 2px 0;
        }

        .items-summary {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--muted);
        }

        .totals-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px 16px;
            background: var(--soft);
        }

        .totals-head {
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 4px 0;
            font-size: 12px;
        }

        .total-row.grand-total {
            border-top: 2px solid var(--ink);
            margin-top: 6px;
            padding-top: 8px;
            font-size: 14px;
            font-weight: 900;
        }

        .refund-row {
            color: var(--danger);
        }

        /* ─── Footer ─── */
        .invoice-footer {
            padding: 12px 28px;
            border-top: 1px solid var(--line);
            font-size: 10px;
            color: var(--muted);
            text-align: center;
        }

        .site-url {
            direction: ltr;
            unicode-bidi: plaintext;
        }

        /* ─── Mobile ─── */
        @media (max-width: 640px) {
            body.invoice-page {
                padding: 10px 6px;
            }

            .invoice-actions {
                flex-wrap: wrap;
            }

            .actions-right {
                width: 100%;
            }

            .actions-right .action-btn {
                flex: 1;
                text-align: center;
            }

            .invoice-header,
            .invoice-content {
                padding-left: 14px;
                padding-right: 14px;
            }

            .invoice-meta {
                grid-template-columns: 1fr;
            }

            .parties,
            .invoice-bottom {
                grid-template-columns: 1fr;
            }
        }

        /* ════════════════════════════════════════════  
   چاپ — A4 لیزری + حرارتی ۸۰mm  
   ════════════════════════════════════════════ */

        /* کاغذ A4 — پیش‌فرض */
        @page {
            size: A4;
            margin: 10mm;
        }

        /* کاغذ حرارتی — فقط وقتی invoice-sheet داخل body.thermal-print باشد */
        @page thermal {
            size: 80mm auto;
            margin: 2mm;
        }

        @media print {

            html,
            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body.invoice-page {
                font-size: 11px;
            }

            .invoice-actions {
                display: none !important;
            }

            .invoice-sheet {
                width: 100%;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .item-thumb {
                width: 34px;
                height: 34px;
            }

            .party-card,
            .payment-item,
            .totals-box,
            .reconciliation-box,
            tr {
                break-inside: avoid;
            }

            .products-table thead {
                display: table-header-group;
            }

            /* ─── حالت حرارتی ۸۰mm ─── */
            body.thermal-print {
                width: auto;
                font-size: 10px;
            }

            body.thermal-print .invoice-sheet {
                page: thermal;
                width: 76mm;
            }

            body.thermal-print .invoice-header,
            body.thermal-print .invoice-content,
            body.thermal-print .invoice-footer {
                padding-left: 2mm;
                padding-right: 2mm;
            }

            /* هدر وسط‌چین */
            body.thermal-print .invoice-header-top {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 6px;
                border-bottom: 1px dashed #999;
            }

            body.thermal-print .invoice-title {
                text-align: center;
            }

            body.thermal-print .brand-logo {
                width: 36px;
                height: 36px;
            }

            body.thermal-print .brand-name {
                font-size: 14px;
            }

            /* متا — خطی */
            body.thermal-print .invoice-meta {
                grid-template-columns: 1fr;
                gap: 4px;
                padding: 8px 0;
            }

            body.thermal-print .meta-card {
                background: none;
                padding: 2px 0;
                display: flex;
                justify-content: space-between;
            }

            body.thermal-print .meta-label {
                margin: 0;
            }

            /* طرفین — تک‌ستونه با جداکننده خط‌چین */
            body.thermal-print .parties {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            body.thermal-print .party-card {
                border: 0;
                border-top: 1px dashed #999;
                border-radius: 0;
                padding: 6px 0;
            }

            body.thermal-print .party-head {
                border-bottom: 0;
                margin-bottom: 4px;
                padding-bottom: 0;
            }

            body.thermal-print .receiver-line {
                background: none;
                padding: 4px 0;
                border-top: 1px dashed #999;
                border-radius: 0;
            }

            /* جدول اقلام — بدون ستون تصویر */
            body.thermal-print .products-table th:nth-child(1),
            body.thermal-print .products-table td:nth-child(1) {
                display: none;
            }

            body.thermal-print .products-table th,
            body.thermal-print .products-table td {
                padding: 3px 2px;
                font-size: 10px;
            }

            body.thermal-print .products-table th {
                background: none;
                border-bottom: 1px solid #000;
            }

            /* پرداخت‌ها */
            body.thermal-print .payment-item {
                border: 0;
                border-top: 1px dashed #999;
                border-radius: 0;
                padding: 5px 0;
            }

            body.thermal-print .payment-status {
                background: none;
                padding: 0;
            }

            body.thermal-print .payment-item-meta {
                border-top: 0;
                margin-top: 4px;
                padding-top: 0;
            }

            /* بخش پایانی — تک‌ستونه */
            body.thermal-print .invoice-bottom {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            body.thermal-print .totals-box {
                background: none;
                border: 0;
                border-top: 1px dashed #999;
                border-radius: 0;
                padding: 6px 0;
            }

            body.thermal-print .reconciliation-box {
                border: 0;
                border-top: 1px dashed #999;
                border-radius: 0;
                padding: 6px 0;
            }

            body.thermal-print .total-row.grand-total {
                border-top: 1px solid #000;
            }

            /* سیاه‌وسفید خالص برای پرینتر حرارتی */
            body.thermal-print * {
                color: #000 !important;
                background: none !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body class="invoice-page">

    <div class="invoice-actions">
        <div class="actions-right">
            <!-- دکمه چاپ A4 (لیزری) -->
            <button type="button" class="action-btn action-print-a4" onclick="window.print()">
                🖨 چاپ A4 (لیزری)
            </button>
            <!-- دکمه چاپ حرارتی 80mm -->
            <button type="button" class="action-btn action-print-thermal" onclick="printThermal()">
                🧾 چاپ حرارتی (80mm)
            </button>
            <a href="/invoice-pdf.php?inv=<?= rawurlencode($invoiceNo) ?>" class="action-btn action-pdf">📄 دریافت PDF</a>
        </div>
        <div class="actions-left">
            <a href="/account-orders" class="action-btn action-back">← بازگشت</a>
        </div>
    </div>

    <main class="invoice-sheet">

        <!-- Header -->
        <header class="invoice-header">
            <div class="invoice-header-top">
                <div class="brand-block">
                    <img src="<?= $h($logoPath) ?>" alt="رابطی" class="brand-logo">
                    <div class="brand-text">
                        <div class="brand-name">رابطی</div>
                        <div class="brand-subtitle">کارگاه تولیدی جوراب رابطی</div>
                        <div class="brand-site-url"><?= $h($siteUrl) ?></div>
                    </div>
                </div>
                <div class="invoice-title">
                    <div class="invoice-title-label">فاکتور فروش</div>
                    <div class="invoice-title-number"><?= $h($invoiceNo) ?></div>
                </div>
            </div>

            <div class="invoice-meta">
                <div class="meta-card">
                    <span class="meta-label">شماره فاکتور</span>
                    <strong class="meta-value ltr"><?= $h($invoiceNo) ?></strong>
                </div>
                <div class="meta-card">
                    <span class="meta-label">شماره سفارش</span>
                    <strong class="meta-value ltr"><?= $h($orderNo) ?></strong>
                </div>
                <div class="meta-card">
                    <span class="meta-label">تاریخ صدور</span>
                    <strong class="meta-value"><?= $h($dateOnlyFa($invoiceDate)) ?></strong>
                </div>
            </div>
        </header>
        <div class="invoice-content">

            <!-- Parties -->
            <section class="parties">
                <!-- ─── خریدار ─── -->
                <div class="party-card">
                    <div class="party-head">
                        <div class="party-title">خریدار</div>
                    </div>

                    <!-- خط ۱: نام (نصف چپ) + کد ملی (نصف راست) -->
                    <div class="party-row-split">
                        <div class="party-row-half">
                            <span class="party-row-label">نام</span>
                            <strong class="party-row-value"><?= $h($buyerName) ?></strong>
                        </div>
                        <div class="party-row-half">
                            <span class="party-row-label">کد ملی</span>
                            <strong class="party-row-value ltr"><?= $buyerNational !== '' ? $h($buyerNational) : '—' ?></strong>
                        </div>
                    </div>

                    <!-- خط ۲: موبایل -->
                    <div class="party-row">
                        <span class="party-row-label">موبایل</span>
                        <strong class="party-row-value ltr"><?= $h($buyerMobile) ?></strong>
                    </div>

                    <!-- خط ۳: شرکت (اگر هست) -->
                    <?php if ($buyerCompany !== ''): ?>
                        <div class="party-row">
                            <span class="party-row-label">شرکت</span>
                            <strong class="party-row-value"><?= $h($buyerCompany) ?></strong>
                        </div>
                    <?php endif; ?>

                    <!-- خط ۴: کد اقتصادی (اگر هست) -->
                    <?php if ($buyerEconomic !== ''): ?>
                        <div class="party-row">
                            <span class="party-row-label">کد اقتصادی</span>
                            <strong class="party-row-value ltr"><?= $h($buyerEconomic) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ─── فروشنده ─── -->
                <div class="party-card seller-card">
                    <div class="party-head">
                        <div class="party-title">فروشنده</div>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">نام</span>
                        <strong class="party-row-value">کارگاه تولید جوراب رابطی</strong>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">آدرس</span>
                        <strong class="party-row-value">بازار بزرگ تهران — عباس‌آباد، سرای لطفی‌پور، پلاک ۲۲</strong>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">وب‌سایت</span>
                        <strong class="party-row-value ltr"><?= $h($siteUrl) ?></strong>
                    </div>
                </div>
            </section>

            <!-- ─── گیرنده — یک خط زیر دو ستون ─── -->
            <?php if ($receiverName !== '' || $receiverAddress !== ''): ?>
                <?php
                $receiverParts = [];
                if ($receiverName !== '')    $receiverParts[] = $receiverName;
                if ($receiverMobile !== '')  $receiverParts[] = $receiverMobile;
                if ($receiverProvince !== '' || $receiverCity !== '') {
                    $receiverParts[] = trim($receiverProvince . ($receiverCity !== '' ? '، ' . $receiverCity : ''));
                }
                if ($receiverAddress !== '') $receiverParts[] = $receiverAddress;
                if ($receiverPostal !== '')  $receiverParts[] = 'کد پستی: ' . $receiverPostal;
                ?>
                <div class="receiver-line">
                    <strong>📦 گیرنده:</strong>
                    <?= $h(implode(' — ', $receiverParts)) ?>
                </div>
            <?php endif; ?>

            <!-- Items -->
            <section class="products-section">
                <div class="section-title">اقلام سفارش</div>

                <?php if (!empty($items)): ?>
                    <table class="products-table">
                        <thead>
                            <tr>
                                <th style="width: 90px;">تصویر</th>
                                <th>محصول</th>
                                <th style="width: 70px;">تعداد</th>
                                <th style="width: 110px;">قیمت واحد</th>
                                <th style="width: 120px;">مبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $index => $item):
                                $productName = (string)($item['product_name'] ?? $item['name'] ?? 'محصول');
                                $productCode = (string)($item['product_code'] ?? $item['code'] ?? '');
                                $qty         = (int)($item['qty'] ?? 0);
                                $unitPrice   = (int)($item['unit_price'] ?? $item['base_unit_price'] ?? 0);
                                $lineTotal   = (int)($item['line_total'] ?? ($unitPrice * $qty));
                                $itemImage   = (string)($item['image'] ?? '');
                            ?>
                                <tr>
                                    <td class="center" data-label="تصویر">
                                        <?php if ($itemImage !== ''): ?>
                                            <img src="<?= $h($itemImage) ?>" alt="<?= $h($productName) ?>" class="item-thumb">
                                        <?php else: ?>
                                            <div class="item-thumb-empty">🧦</div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="محصول">
                                        <div class="product-name"><?= $h($productName) ?></div>
                                        <?php if ($productCode !== ''): ?>
                                            <div class="product-code">کد: <?= $h($productCode) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center"><?= number_format($qty) ?> <?= htmlspecialchars(unit_label($item['unit_code'] ?? 'جفتی')) ?></td>
                                    <td class="ltr"><?= $moneyFa($unitPrice) ?></td>
                                    <td class="ltr"><strong><?= $moneyFa($lineTotal) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color:#9ca3af;text-align:center;padding:20px;">موردی برای نمایش وجود ندارد.</p>
                <?php endif; ?>
            </section>

            <!-- Payments -->
            <section class="payment-section">
                <div class="section-title">تاریخچه پرداخت</div>

                <?php if (!empty($payments)): ?>
                    <div class="payment-history">

                        <?php foreach ($payments as $payment): ?>
                            <?php
                            $paymentStatus   = (string)($payment['status'] ?? '');
                            $paymentMethod   = (string)($payment['method'] ?? '');
                            $claimed         = (int)$payment['_claimed'];
                            $verified        = $payment['_verified'];
                            $hasMismatch     = ($verified !== null && $verified !== $claimed);
                            $displayAmount   = $verified ?? $claimed;
                            ?>
                            <article class="payment-item">
                                <div class="payment-item-main">
                                    <div>
                                        <span class="payment-method"><?= $h($methodLabel($paymentMethod)) ?></span>
                                        <span class="payment-status payment-status-<?= $h($paymentStatus) ?>">
                                            <?= $h($statusLabel($paymentStatus)) ?>
                                        </span>
                                    </div>
                                    <div class="payment-item-amount">
                                        <?= $moneyFa($displayAmount) ?>
                                        <?php if ($verified === null): ?>
                                            <small style="display:block;font-size:10px;color:#92400e;font-weight:400;">
                                                (در انتظار تأیید)
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($hasMismatch): ?>
                                    <div style="margin-top:8px;padding:6px 10px;background:#fef3c7;border-radius:6px;font-size:11px;color:#92400e;">
                                        ⚠️ اظهارات مشتری: <strong><?= $moneyFa($claimed) ?></strong>
                                        — مغایرت: <strong><?= $moneyFa(abs($claimed - $verified)) ?></strong>
                                        (تأیید مالی: <?= $moneyFa($verified) ?>)
                                    </div>
                                <?php endif; ?>

                                <div class="payment-item-meta">
                                    <div>
                                        <span class="payment-meta-label">ثبت:</span>
                                        <?= $h($dateTimeFa($payment['created_at'] ?? null)) ?>
                                    </div>
                                    <?php if (trim((string)($payment['reference_no'] ?? '')) !== ''): ?>
                                        <div>
                                            <span class="payment-meta-label">پیگیری:</span>
                                            <span class="ltr"><?= $h($payment['reference_no']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($verified !== null && !empty($payment['verified_at'])): ?>
                                        <div>
                                            <span class="payment-meta-label">تأیید مالی:</span>
                                            <?= $h($dateTimeFa($payment['verified_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="payment-item" style="text-align:center;color:#9ca3af;">
                        هنوز پرداختی برای این فاکتور ثبت نشده است.
                    </div>
                <?php endif; ?>
            </section>

            <!-- Bottom: Reconciliation + Totals -->
            <section class="invoice-bottom">
                <div class="bottom-left">
                    <?php if ($customerNote !== ''): ?>
                        <div class="note-box">
                            <div class="note-box-title">توضیحات مشتری</div>
                            <p><?= nl2br($h($customerNote)) ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="reconciliation-box">
                        <div class="reconciliation-title">نتیجه مالی</div>
                        <div class="reconciliation-result">
                            <span>وضعیت:</span>
                            <strong><?= $h($recType) ?></strong>
                        </div>
                        <?php if ($recAmount > 0): ?>
                            <div class="reconciliation-amount">
                                <span>مبلغ:</span>
                                <strong><?= $moneyFa($recAmount) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($itemsCount > 0): ?>
                        <div class="items-summary">

                        </div>
                    <?php endif; ?>
                </div>

                <div class="totals-box">
                    <div class="totals-head">خلاصه مالی</div>

                    <!-- ─── اقلام و تخفیف ─── -->
                    <div class="total-row">
                        <span>جمع اقلام:</span>
                        <strong><?= $moneyFa($subtotal) ?></strong>
                    </div>

                    <div class="total-row">
                        <span>تخفیف:</span>
                        <strong style="<?= $discount > 0 ? 'color:#059669;' : '' ?>">
                            <?= $discount > 0 ? '- ' . $moneyFa($discount) : '۰ تومان' ?>
                        </strong>
                    </div>

                    <div class="total-row">
                        <span>هزینه ارسال:</span>
                        <strong><?= $shipping > 0 ? $moneyFa($shipping) : '۰ تومان' ?></strong>
                    </div>

                    <?php if ($tax > 0): ?>
                        <div class="total-row">
                            <span>مالیات:</span>
                            <strong><?= $moneyFa($tax) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="total-row grand-total">
                        <span>مبلغ نهایی:</span>
                        <span><?= $moneyFa($total) ?></span>
                    </div>

                    <!-- ─── جمع پرداخت‌ها با تفکیک ─── -->
                    <?php
                    $sumVerified = 0;
                    $sumPending  = 0;
                    $sumRejected = 0;

                    foreach ($payments as $p) {
                        $st = (string)($p['status'] ?? '');
                        if ($st === 'verified' || $st === 'adjusted' || $st === 'confirmed') {
                            $sumVerified += (int)($p['_verified'] ?? 0);
                        } elseif ($st === 'pending') {
                            $sumPending += (int)($p['_claimed'] ?? 0);
                        } elseif ($st === 'rejected') {
                            $sumRejected += (int)($p['_claimed'] ?? 0);
                        }
                    }
                    ?>

                    <div style="margin-top:12px;padding-top:10px;border-top:1px dashed #d1d5db;">
                        <div style="font-size:12px;font-weight:800;color:#2a5f78;margin-bottom:8px;">
                            جمع پرداخت‌ها
                        </div>

                        <div class="total-row" style="color:#059669;">
                            <span>✓ تأییدشده:</span>
                            <strong><?= $moneyFa($sumVerified) ?></strong>
                        </div>

                        <?php if ($sumPending > 0): ?>
                            <div class="total-row" style="color:#d97706;">
                                <span>⏳ در انتظار تأیید:</span>
                                <strong><?= $moneyFa($sumPending) ?></strong>
                            </div>
                        <?php endif; ?>

                        <?php if ($sumRejected > 0): ?>
                            <div class="total-row" style="color:#dc2626;">
                                <span>✗ ردشده:</span>
                                <strong><?= $moneyFa($sumRejected) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($totalRefunded > 0): ?>
                        <div class="total-row refund-row" style="margin-top:8px;">
                            <span>بازگشت وجه:</span>
                            <strong>- <?= $moneyFa($totalRefunded) ?></strong>
                        </div>
                    <?php endif; ?>

                    <!-- ─── مانده (بر اساس verified) ─── -->
                    <?php
                    $effectivePaid = $sumVerified;
                    $remainingCalc = $total - $effectivePaid;

                    if ($remainingCalc > 0) {
                        $recTypeCalc   = 'بدهکار';
                        $recAmountCalc = $remainingCalc;
                        $recColor      = '#dc2626';
                    } elseif ($remainingCalc < 0) {
                        $recTypeCalc   = 'بستانکار';
                        $recAmountCalc = abs($remainingCalc);
                        $recColor      = '#1d4ed8';
                    } else {
                        $recTypeCalc   = 'تسویه';
                        $recAmountCalc = 0;
                        $recColor      = '#059669';
                    }
                    ?>

                    <div class="total-row" style="  
        border-top:2px solid #2a5f78;  
        margin-top:10px;  
        padding-top:10px;  
        font-weight:900;  
        font-size:14px;  
        color:<?= $recColor ?>;  
    ">
                        <span><?= $recTypeCalc === 'بستانکار' ? 'بستانکاری:' : ($recTypeCalc === 'بدهکار' ? 'مانده بدهی:' : 'مانده:') ?></span>
                        <strong><?= $moneyFa($recAmountCalc) ?></strong>
                    </div>
                </div>
            </section>

        </div>

        <!-- Footer -->
        <div class="invoice-footer">
            تولیدی جوراب رابطی |
            <span class="site-url"><?= $h($siteUrl) ?></span>
        </div>

    </main>
    <script>
        function printThermal() {
            // اضافه کردن کلاس thermal-print به body  
            document.body.classList.add('thermal-print');
            // باز کردن پنجره چاپ  
            window.print();
            // حذف کلاس بعد از بستن پنجره چاپ  
            window.onafterprint = function() {
                document.body.classList.remove('thermal-print');
            };
        }
    </script>
</body>

</html>