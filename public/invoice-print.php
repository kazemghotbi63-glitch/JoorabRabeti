<?php
/* [FILE] public/invoice-print.php — Invoice printable view */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once ROOT_PATH . '/app/services/InvoiceService.php';

$db = db();

$h = static fn($v): string =>
htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$moneyFa = static function ($amount): string {
    return number_format((int)$amount) . ' تومان';
};

/* ═══ تاریخ شمسی — فقط لایه نمایش ═══ */
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

/* ══════════════════════════════════════════════
   تاریخچه پرداخت — از جداول جدید
   ══════════════════════════════════════════════ */

$paymentStmt = $db->prepare("
    SELECT
        ps.id,
        ps.method,
        ps.claimed_amount,
        ps.reference_no,
        ps.receipt_path,
        ps.transaction_date,
        ps.status,
        ps.created_at,

        v.verified_amount,
        v.status AS verification_status,
        v.note AS verification_note,
        v.verified_at

    FROM payment_submissions ps
    LEFT JOIN payment_verifications v
        ON v.payment_submission_id = ps.id
    WHERE ps.invoice_id = ?
    ORDER BY ps.created_at ASC, ps.id ASC
");
$paymentStmt->execute([(int)$invoice['id']]);
$payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══ اسناد بازگشت وجه (تسویه بستانکاری) ═══ */
$refunds = [];
$stmt = $db->prepare("
    SELECT ft.amount, ft.method, ft.document_no, ft.reference_no, ft.completed_at
    FROM financial_transactions ft
    WHERE ft.invoice_id = ? AND ft.type = 'creditor_settlement' AND ft.status = 'completed'
    ORDER BY ft.completed_at ASC
");
$stmt->execute([(int)$invoice['id']]);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRefunded = 0;
foreach ($refunds as $r) $totalRefunded += (int)$r['amount'];

/* ══════════════════════════════════════════════
   محاسبات — بستانکار هم لحاظ می‌شود
   ══════════════════════════════════════════════ */

$pendingAmount     = 0;
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

$invoiceNo  = (string)($invoice['invoice_number'] ?? $invoiceNumber ?? '—');
$orderNo    = (string)($invoice['order_number'] ?? '—');
$invoiceDate = (string)($invoice['issued_at'] ?? '');

$invoiceStatus = (string)($invoice['status'] ?? 'unpaid');

$customerName     = (string)($invoice['customer_name'] ?? $invoice['shipping_name'] ?? '—');
$customerMobile   = (string)($invoice['customer_mobile'] ?? $invoice['shipping_mobile'] ?? '—');
$customerProvince = (string)($invoice['shipping_province'] ?? '');
$customerCity     = (string)($invoice['shipping_city'] ?? '');
$customerAddress  = (string)($invoice['shipping_address'] ?? '');
$customerPostal   = (string)($invoice['shipping_postal'] ?? '');
$customerNote     = (string)($invoice['customer_note'] ?? $invoice['note'] ?? '');

$subtotal = (int)($totals['subtotal'] ?? $invoice['subtotal'] ?? 0);
$shipping = (int)($totals['shipping'] ?? $invoice['shipping_fee'] ?? 0);
$tax      = (int)($totals['tax'] ?? 0);

/* ═══ فیکس صفرها: مبلغ نهایی و پرداخت‌شده مستقیم از جدول invoices ═══ */
$stmt = $db->prepare("SELECT total, paid_total FROM invoices WHERE id = ? LIMIT 1");
$stmt->execute([(int)$invoice['id']]);
$invRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$total = (int)($invRow['total'] ?? 0);
$paid  = (int)($invRow['paid_total'] ?? 0);

/* اگر total در DB صفر بود، از جمع اقلام بساز */
if ($total <= 0) {
    $sum = 0;
    foreach ($items as $item) {
        $sum += (int)($item['line_total'] ?? (($item['unit_price'] ?? 0) * ($item['qty'] ?? 0)));
    }
    $total = $sum + $shipping + $tax;
}

/* ═══ نتیجه مالی — COMPUTED (بستانکاری ممکن است) ═══ */
$remaining = $total - $paid;          /* منفی یعنی بستانکار */

if ($remaining > 0) {
    $recType   = 'بدهکار';
    $recAmount = $remaining;
} elseif ($remaining < 0) {
    $recType   = 'بستانکار';
    $recAmount = abs($remaining);
} else {
    $recType   = 'تسویه';
    $recAmount = 0;
}

/* ═══ قواعد CTA ═══ */
$canPay = !$hasPendingPayment
    && $remaining > 0
    && $recType !== 'بستانکار'
    && $invoiceStatus !== 'void';

$logoPath = '/Image/logo3.jpg';

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
    <link rel="stylesheet" href="/assets/css/app.css?v=22">
</head>

<body class="invoice-page">

    <div class="invoice-actions">
        <div class="actions-right">
            <button type="button" class="action-btn action-print" onclick="window.print()">🖨 چاپ</button>
            <a href="/invoice-pdf.php?inv=<?= rawurlencode($invoiceNo) ?>" class="action-btn action-pdf">📄 دریافت PDF</a>
        </div>
        <div class="actions-left">
            <a href="/account-orders" class="action-btn action-back">← بازگشت</a>
        </div>
    </div>

    <main class="invoice-sheet">

        <header class="invoice-header">
            <div class="invoice-header-top">
                <div class="brand-block">
                    <img src="<?= $h($logoPath) ?>" alt="رابطی" class="brand-logo">
                    <div class="brand-text">
                        <div class="brand-name">رابطی</div>
                        <div class="brand-subtitle">تولید مستقیم و فروش عمده جوراب</div>
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

            <section class="parties">
                <div class="party-card">
                    <div class="party-head">
                        <div class="party-title">خریدار</div>
                        <div class="party-badge">مشتری</div>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">نام</span>
                        <strong class="party-row-value"><?= $h($customerName) ?></strong>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">موبایل</span>
                        <strong class="party-row-value ltr"><?= $h($customerMobile) ?></strong>
                    </div>
                    <?php if ($customerProvince !== '' || $customerCity !== ''): ?>
                        <div class="party-row">
                            <span class="party-row-label">شهر</span>
                            <strong class="party-row-value"><?= $h(trim($customerProvince . '، ' . $customerCity, '، ')) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($customerAddress !== ''): ?>
                        <div class="party-row">
                            <span class="party-row-label">آدرس</span>
                            <strong class="party-row-value party-address"><?= $h($customerAddress) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($customerPostal !== ''): ?>
                        <div class="party-row">
                            <span class="party-row-label">کد پستی</span>
                            <strong class="party-row-value ltr"><?= $h($customerPostal) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="party-card seller-card">
                    <div class="party-head">
                        <div class="party-title">فروشنده</div>
                        <div class="party-badge seller-badge">رابطی</div>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">نام</span>
                        <strong class="party-row-value">کارگاه تولید جوراب رابطی</strong>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">نوع فروش</span>
                        <strong class="party-row-value">عمده و مستقیم از تولیدکننده</strong>
                    </div>
                    <div class="party-row">
                        <span class="party-row-label">وضعیت</span>
                        <strong class="party-row-value">فاکتور رسمی سفارش</strong>
                    </div>
                </div>
            </section>

            <section class="products-section">
                <div class="section-title">اقلام سفارش</div>

                <?php if (!empty($items)): ?>
                    <div class="table-wrap">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th class="col-index">#</th>
                                    <th>محصول</th>
                                    <th>تعداد</th>
                                    <th>قیمت پایه</th>
                                    <th>تخفیف</th>
                                    <th>قیمت واحد</th>
                                    <th>مبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $index => $item):
                                    $productName = (string)($item['product_name'] ?? $item['name'] ?? 'محصول');
                                    $productCode = (string)($item['product_code'] ?? $item['code'] ?? '');
                                    $qty = (int)($item['qty'] ?? 0);
                                    $baseUnitPrice = (int)($item['base_unit_price'] ?? $item['base_price'] ?? $item['price'] ?? 0);
                                    $discountPercent = (float)($item['discount_percent'] ?? 0);
                                    $unitPrice = (int)($item['unit_price'] ?? $baseUnitPrice);
                                    $lineTotal = (int)($item['line_total'] ?? ($unitPrice * $qty));
                                ?>
                                    <tr>
                                        <td class="col-index"><?= $index + 1 ?></td>
                                        <td>
                                            <div class="product-name"><?= $h($productName) ?></div>
                                            <?php if ($productCode !== ''): ?>
                                                <div class="product-code">کد: <?= $h($productCode) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="qty-value"><?= number_format($qty) ?></span> جفت</td>
                                        <td class="price"><?= $moneyFa($baseUnitPrice) ?></td>
                                        <td class="discount-cell">
                                            <?= $discountPercent > 0
                                                ? rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '٪'
                                                : '—' ?>
                                        </td>
                                        <td class="price final-price"><?= $moneyFa($unitPrice) ?></td>
                                        <td class="price line-total"><?= $moneyFa($lineTotal) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile -->
                    <div class="mobile-items">
                        <?php foreach ($items as $index => $item):
                            $productName = (string)($item['product_name'] ?? $item['name'] ?? 'محصول');
                            $productCode = (string)($item['product_code'] ?? $item['code'] ?? '');
                            $qty = (int)($item['qty'] ?? 0);
                            $baseUnitPrice = (int)($item['base_unit_price'] ?? $item['base_price'] ?? $item['price'] ?? 0);
                            $discountPercent = (float)($item['discount_percent'] ?? 0);
                            $unitPrice = (int)($item['unit_price'] ?? $baseUnitPrice);
                            $lineTotal = (int)($item['line_total'] ?? ($unitPrice * $qty));
                        ?>
                            <article class="mobile-item">
                                <div class="mobile-item-head">
                                    <div>
                                        <div class="mobile-item-index"><?= $index + 1 ?></div>
                                        <div class="mobile-item-name"><?= $h($productName) ?></div>
                                        <?php if ($productCode !== ''): ?>
                                            <div class="product-code">کد: <?= $h($productCode) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <strong class="mobile-item-total"><?= $moneyFa($lineTotal) ?></strong>
                                </div>
                                <div class="mobile-item-grid">
                                    <div class="mobile-detail">
                                        <span class="mobile-detail-label">تعداد</span>
                                        <strong class="mobile-detail-value"><?= number_format($qty) ?> جفت</strong>
                                    </div>
                                    <div class="mobile-detail">
                                        <span class="mobile-detail-label">قیمت پایه</span>
                                        <strong class="mobile-detail-value"><?= $moneyFa($baseUnitPrice) ?></strong>
                                    </div>
                                    <div class="mobile-detail">
                                        <span class="mobile-detail-label">تخفیف</span>
                                        <strong class="mobile-detail-value discount-cell">
                                            <?= $discountPercent > 0 ? rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '٪' : '—' ?>
                                        </strong>
                                    </div>
                                    <div class="mobile-detail">
                                        <span class="mobile-detail-label">قیمت واحد نهایی</span>
                                        <strong class="mobile-detail-value final-price"><?= $moneyFa($unitPrice) ?></strong>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <div class="empty-items">موردی برای نمایش در این فاکتور وجود ندارد.</div>
                <?php endif; ?>
            </section>

            <section class="payment-section">
                <div class="section-title">تاریخچه پرداخت</div>

                <?php if (!empty($payments)): ?>
                    <div class="payment-history">
                        <?php foreach ($payments as $payment): ?>
                            <?php
                            $paymentStatus = (string)($payment['status'] ?? '');
                            $paymentMethod = (string)($payment['method'] ?? '');
                            $verified = $payment['_verified'];
                            $claimed  = $payment['_claimed'];
                            ?>
                            <article class="payment-item payment-item-<?= $h($paymentStatus) ?>">
                                <div class="payment-item-main">
                                    <div class="payment-item-title">
                                        <span class="payment-method"><?= $h($methodLabel($paymentMethod)) ?></span>
                                        <span class="payment-status payment-status-<?= $h($paymentStatus) ?>">
                                            <?= $h($statusLabel($paymentStatus)) ?>
                                        </span>
                                    </div>
                                    <div class="payment-item-amount">
                                        <?= $moneyFa($claimed) ?>
                                    </div>
                                </div>

                                <div class="payment-item-meta">
                                    <div>
                                        <span class="payment-meta-label">ثبت پرداخت</span>
                                        <span><?= $h($dateTimeFa($payment['created_at'] ?? null)) ?></span>
                                    </div>

                                    <?php if ($paymentStatus !== 'pending' && $payment['verified_at']): ?>
                                        <div>
                                            <span class="payment-meta-label">تأیید مالی</span>
                                            <span><?= $h($dateTimeFa($payment['verified_at'])) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (trim((string)($payment['reference_no'] ?? '')) !== ''): ?>
                                        <div>
                                            <span class="payment-meta-label">شماره پیگیری</span>
                                            <span class="ltr"><?= $h($payment['reference_no']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($verified !== null && $verified !== $claimed): ?>
                                        <div>
                                            <span class="payment-meta-label">مبلغ واقعی تأییدشده</span>
                                            <span><?= $moneyFa($verified) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (trim((string)($payment['verification_note'] ?? '')) !== ''): ?>
                                    <div class="payment-admin-note">
                                        <strong>توضیح مالی:</strong>
                                        <?= $h($payment['verification_note']) ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="payment-empty">هنوز پرداختی برای این فاکتور ثبت نشده است.</div>
                <?php endif; ?>

                <?php if ($hasPendingPayment): ?>
                    <div class="payment-alert payment-alert-pending">
                        <span class="payment-alert-icon">⏳</span>
                        <div>
                            <strong>پرداخت شما در انتظار تأیید مالی است.</strong>
                            <p>
                                مبلغ <?= $moneyFa($pendingAmount) ?> ثبت شده اما هنوز توسط واحد مالی تأیید نشده است.
                                مانده قطعی پس از بررسی مشخص می‌شود.
                            </p>
                        </div>
                    </div>
                <?php elseif ($remaining <= 0): ?>
                    <div class="payment-alert payment-alert-success">
                        <span class="payment-alert-icon">✓</span>
                        <div>
                            <strong>این فاکتور به‌طور کامل پرداخت شده است.</strong>
                            <?php if ($remaining < 0): ?>
                                <p>مبلغ بستانکاری شما: <?= $moneyFa(abs($remaining)) ?> — در سفارش بعدی قابل اعمال است.</p>
                            <?php else: ?>
                                <p>مانده قابل پرداخت این فاکتور صفر است.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
            <?php if (!empty($refunds)): ?>

                <section class="payment-section">
                    <div class="section-title">اسناد بازگشت وجه</div>

                    <div class="payment-history">
                        <?php foreach ($refunds as $r): ?>
                            <article class="payment-item payment-item-refund">
                                <div class="payment-item-main">
                                    <div class="payment-item-title">
                                        <span class="payment-method">
                                            بازگشت وجه به مشتری
                                        </span>
                                        <span class="payment-status payment-status-refunded">
                                            تسویه بستانکاری
                                        </span>
                                    </div>
                                    <div class="payment-item-amount">
                                        <?= $moneyFa($r['amount']) ?>
                                    </div>
                                </div>
                                <div class="payment-item-meta">
                                    <div>
                                        <span class="payment-meta-label">
                                            تاریخ بازگشت
                                        </span>
                                        <span>
                                            <?= $h($dateTimeFa($r['completed_at'])) ?>
                                        </span>
                                    </div>
                                    <?php if (trim((string)($r['document_no'] ?? '')) !== ''): ?>
                                        <div>
                                            <span class="payment-meta-label">
                                                شماره سند
                                            </span>
                                            <span class="ltr">
                                                <?= $h($r['document_no']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (trim((string)($r['reference_no'] ?? '')) !== ''): ?>
                                        <div>
                                            <span class="payment-meta-label">
                                                شماره پیگیری
                                            </span>
                                            <span class="ltr">
                                                <?= $h($r['reference_no']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

            <?php endif; ?>

            <section class="invoice-bottom">
                <div class="bottom-left">
                    <?php if ($customerNote !== ''): ?>
                        <div class="note-box">
                            <div class="note-box-title">توضیحات مشتری</div>
                            <p><?= nl2br($h($customerNote)) ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- نتیجه مالی — COMPUTED -->
                    <div class="reconciliation-box">
                        <div class="reconciliation-title">نتیجه مالی (محاسبه‌شده سیستمی)</div>

                        <div class="reconciliation-result <?= $recType === 'بستانکار' ? 'creditor' : ($recType === 'بدهکار' ? 'debtor' : 'settled') ?>">
                            <span>وضعیت</span>
                            <strong><?= $h($recType) ?></strong>
                        </div>

                        <?php if ($recAmount > 0): ?>
                            <div class="reconciliation-amount">
                                <span>مبلغ</span>
                                <strong><?= $moneyFa($recAmount) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($itemsCount > 0): ?>
                        <div class="items-summary">
                            <span>تعداد کل اقلام</span>
                            <strong><?= number_format($itemsCount) ?> جفت</strong>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="totals-box">
                    <div class="totals-head">خلاصه مالی</div>

                    <div class="total-row">
                        <span class="total-label">جمع قیمت پایه</span>
                        <span class="total-value"><?= $moneyFa($subtotal) ?></span>
                    </div>

                    <?php if ($shipping > 0): ?>
                        <div class="total-row">
                            <span class="total-label">هزینه ارسال</span>
                            <span class="total-value"><?= $moneyFa($shipping) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($tax > 0): ?>
                        <div class="total-row">
                            <span class="total-label">مالیات</span>
                            <span class="total-value"><?= $moneyFa($tax) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="total-row grand-total">
                        <span class="total-label">مبلغ نهایی فاکتور</span>
                        <strong class="total-value"><?= $moneyFa($total) ?></strong>
                    </div>

                    <div class="total-row paid-row">
                        <span class="total-label">پرداخت تأییدشده</span>
                        <strong class="total-value"><?= $moneyFa($paid) ?></strong>
                    </div>
                    <?php if ($totalRefunded > 0): ?>
                        <div class="total-row refund-row">
                            <span class="total-label">بازگشت وجه به مشتری</span>
                            <span class="total-value">- <?= $moneyFa($totalRefunded) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasPendingPayment): ?>
                        <div class="total-row pending-row">
                            <span class="total-label">در انتظار تأیید مالی</span>
                            <span class="total-value"><?= $moneyFa($pendingAmount) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($remaining < 0): ?>
                        <div class="total-row">
                            <span class="total-label">بستانکاری (مازاد پرداخت)</span>
                            <span class="total-value"><?= $moneyFa(abs($remaining)) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="total-row grand-total">
                        <span class="total-label">مانده قطعی</span>
                        <strong class="total-value"><?= $moneyFa(max(0, $remaining)) ?></strong>
                    </div>
                </div>
            </section>

        </div>

    </main>

</body>

</html>