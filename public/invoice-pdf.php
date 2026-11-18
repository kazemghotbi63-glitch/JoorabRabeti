<?php

declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
 *  RABETI — PDF فاکتور  |  v2 | 2026-10-23
 *
 *  تغییرات:
 *    • خریدار از users (نام، موبایل، کد ملی، شرکت، کد اقتصادی)
 *    • نام + کد ملی در یک خط (نصف نصف)
 *    • گیرنده یک خط زیر دو ستون
 *    • تخفیف + هزینه ارسال نمایش
 *    • جمع پرداخت‌ها با تفکیک (تأییدشده / در انتظار / ردشده)
 *    • مانده = total - sum(verified)
 * ═══════════════════════════════════════════════════════════════ */

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

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/InvoiceService.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$db = db();

$userId  = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
$isAdmin = !empty($_SESSION['admin_id']);

$invoiceNumber = trim((string)($_GET['inv'] ?? $_GET['invoice'] ?? ''));
$invoiceId     = (int)($_GET['id'] ?? 0);

if ($invoiceNumber === '' && $invoiceId <= 0) {
    http_response_code(404);
    exit('فاکتور پیدا نشد.');
}

$invoice = null;

try {
    if ($invoiceId > 0) {
        $invoice = InvoiceService::find($db, $invoiceId);
    } else {
        $stmt = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
        $stmt->execute([$invoiceNumber]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invoice = InvoiceService::find($db, (int)$row['id']);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[invoice-pdf] ' . $e->getMessage());
    exit('خطا در ساخت فاکتور. لطفاً بعداً دوباره تلاش کنید.');
}

if (!$invoice) {
    http_response_code(404);
    exit('فاکتور پیدا نشد.');
}

$invoiceUserId = (int)($invoice['user_id'] ?? 0);
if (!$isAdmin && $invoiceUserId !== $userId) {
    http_response_code(403);
    exit('دسترسی غیرمجاز.');
}

$invId = (int)($invoice['invoice_id'] ?? $invoice['id'] ?? 0);

/* ═══ پرداخت‌ها ═══ */
$paymentStmt = $db->prepare("
    SELECT
        ps.id, ps.method, ps.claimed_amount, ps.reference_no,
        ps.transaction_date, ps.status, ps.created_at,
        v.verified_amount, v.status AS verification_status, v.verified_at
    FROM payment_submissions ps
    LEFT JOIN payment_verifications v ON v.payment_submission_id = ps.id
    WHERE ps.invoice_id = ?
    ORDER BY ps.created_at ASC, ps.id ASC
");
$paymentStmt->execute([$invId]);
$payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══ بازگشت وجه ═══ */
$stmt = $db->prepare("
    SELECT ft.amount, ft.method, ft.document_no, ft.reference_no, ft.completed_at
    FROM financial_transactions ft
    WHERE ft.invoice_id = ? AND ft.type = 'creditor_settlement' AND ft.status = 'completed'
    ORDER BY ft.completed_at ASC
");
$stmt->execute([$invId]);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRefunded = 0;
foreach ($refunds as $r) $totalRefunded += (int)$r['amount'];

/* ═══ جمع‌های پرداخت ═══ */
$sumVerified = 0;
$sumPending  = 0;
$sumRejected = 0;

foreach ($payments as &$p) {
    $p['_claimed']  = max(0, (int)($p['claimed_amount'] ?? 0));
    $p['_verified'] = $p['verified_amount'] !== null ? max(0, (int)$p['verified_amount']) : null;

    $st = (string)($p['status'] ?? '');
    if ($st === 'verified' || $st === 'adjusted' || $st === 'confirmed') {
        $sumVerified += $p['_verified'] ?? 0;
    } elseif ($st === 'pending') {
        $sumPending += $p['_claimed'];
    } elseif ($st === 'rejected') {
        $sumRejected += $p['_claimed'];
    }
}
unset($p);

/* ═══ اقلام ═══ */
$items  = $invoice['items'] ?? [];
$totals = $invoice['totals'] ?? [];

$invoiceNo   = (string)($invoice['invoice_number'] ?? $invoiceNumber ?? '—');
$orderNo     = (string)($invoice['order_number'] ?? '—');
$invoiceDate = (string)($invoice['order_created_at'] ?? $invoice['issued_at'] ?? '');

/* ═══ خریدار (از users) ═══ */
$buyerName     = (string)($invoice['buyer_full_name'] ?? '—');
$buyerMobile   = (string)($invoice['buyer_mobile'] ?? '—');
$buyerNational = (string)($invoice['buyer_national_code'] ?? '');
$buyerCompany  = (string)($invoice['buyer_company'] ?? '');
$buyerEconomic = (string)($invoice['buyer_economic_code'] ?? '');
$buyerType     = (string)($invoice['buyer_type'] ?? 'real');

/* ═══ گیرنده (از orders) ═══ */
$receiverName     = (string)($invoice['shipping_name'] ?? '');
$receiverMobile   = (string)($invoice['shipping_mobile'] ?? '');
$receiverProvince = (string)($invoice['shipping_province'] ?? '');
$receiverCity     = (string)($invoice['shipping_city'] ?? '');
$receiverAddress  = (string)($invoice['shipping_address'] ?? '');
$receiverPostal   = (string)($invoice['shipping_postal'] ?? '');

$customerNote = (string)($invoice['customer_note'] ?? $invoice['note'] ?? '');

/* ═══ مبالغ ═══ */
$subtotal  = (int)($totals['subtotal']  ?? 0);
$discount  = (int)($totals['discount']  ?? 0);
$shipping  = (int)($totals['shipping']  ?? 0);
$tax       = (int)($totals['tax']       ?? 0);
$total     = (int)($totals['total']     ?? 0);

$effectivePaid = $sumVerified;
$remaining     = $total - $effectivePaid;

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

$itemsCount = 0;
foreach ($items as $it) $itemsCount += (int)($it['qty'] ?? 0);

$siteUrl  = 'https://joorabrabeti.ir';
$logoPath = PUBLIC_PATH . '/Image/logo3.jpg';

/* ═══ Helperها ═══ */
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$faNum = static fn($n): string => strtr((string)$n, [
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

$ltr = static fn(string $s): string =>
'<span dir="ltr" style="unicode-bidi:embed;">' . $s . '</span>';

$moneyFa = static function ($a) use ($faNum, $ltr): string {
    return $ltr($faNum(number_format((int)$a, 0, '.', '٬'))) . ' تومان';
};

$dateFa = static function ($value) use ($faNum, $ltr): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    if ($ts === false) return $ltr((string)$value);
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
    return $ltr($faNum($jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT)));
};

$methodLabel = static fn(string $m): string => match ($m) {
    'sheba'        => 'انتقال با شبا',
    'card_to_card' => 'کارت به کارت',
    'cash_receipt' => 'فیش نقدی',
    'online'       => 'درگاه پرداخت آنلاین',
    default        => $m,
};

$statusLabel = static fn(string $s): string => match ($s) {
    'pending'   => 'در انتظار تأیید',
    'verified'  => 'تأیید شده',
    'adjusted'  => 'اصلاح شده',
    'rejected'  => 'رد شده',
    'confirmed' => 'تأیید شده',
    default     => $s,
};

/* ═══ تولید PDF ═══ */
try {
    $tcpdfPath = ROOT_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdfPath)) throw new RuntimeException('TCPDF not found');
    require_once $tcpdfPath;

    /* ─── Logo ─── */
    $logoHtml = is_file($logoPath)
        ? '<img src="' . $h($logoPath) . '" style="width:16mm;height:16mm;">'
        : '';

    /* ─── اقلام ─── */
    $itemsHtml = '';
    foreach ($items as $it) {
        $name  = (string)($it['product_name'] ?? 'محصول');
        $code  = trim((string)($it['product_code'] ?? ''));
        $qty   = (int)($it['qty'] ?? 0);
        $base  = (int)($it['base_unit_price'] ?? $it['unit_price'] ?? 0);
        $line  = (int)($it['line_total'] ?? 0);
        $img   = (string)($it['image'] ?? '');
        $unit = htmlspecialchars(unit_label($it['unit_code'] ?? 'جفتی'));
        $imgAbs = $img !== '' ? PUBLIC_PATH . '/' . ltrim($img, '/') : '';
        $imgTag = ($imgAbs && is_file($imgAbs))
            ? '<img src="' . $h($imgAbs) . '" style="width:14mm;height:14mm;">'
            : '<div style="width:14mm;height:14mm;border:0.2mm solid #ddd;text-align:center;line-height:14mm;color:#999;">—</div>';

        $codeHtml = $code !== '' ? '<div style="font-size:6.5pt;color:#888;">کد: ' . $h($code) . '</div>' : '';

        $itemsHtml .= '
        <tr>
            <td style="text-align:center;padding:1.5mm;border-bottom:0.2mm solid #e5e7eb;">' . $imgTag . '</td>
            <td style="padding:1.5mm;border-bottom:0.2mm solid #e5e7eb;"><div style="font-weight:bold;font-size:8pt;">' . $h($name) . '</div>' . $codeHtml . '</td>
            <td style="text-align:center;padding:1.5mm;border-bottom:0.2mm solid #e5e7eb;font-size:8pt;">' . $ltr($faNum(number_format($qty))) . ' ' . $unit . '</td>
            <td style="text-align:left;padding:1.5mm;border-bottom:0.2mm solid #e5e7eb;font-size:8pt;">' . $moneyFa($base) . '</td>
            <td style="text-align:left;padding:1.5mm;border-bottom:0.2mm solid #e5e7eb;font-size:8pt;"><strong>' . $moneyFa($line) . '</strong></td>
        </tr>';
    }
    if ($itemsHtml === '') {
        $itemsHtml = '<tr><td colspan="5" style="text-align:center;padding:4mm;color:#999;">موردی نیست</td></tr>';
    }

    /* ─── پرداخت‌ها ─── */
    $paymentsHtml = '';
    if ($payments) {
        $rows = '';
        foreach ($payments as $p) {
            $displayAmount = $p['_verified'] !== null ? $p['_verified'] : $p['_claimed'];
            $pendingNote   = ($p['_verified'] === null) ? ' <span style="color:#92400e;font-size:6.5pt;">(ادعا)</span>' : '';
            $rows .= '
            <tr>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $h($methodLabel((string)($p['method'] ?? ''))) . '</td>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $moneyFa($displayAmount) . $pendingNote . '</td>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $h($statusLabel((string)($p['status'] ?? ''))) . '</td>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $dateFa($p['created_at'] ?? null) . '</td>
            </tr>';
        }
        $paymentsHtml = '
        <div style="font-weight:bold;font-size:9pt;color:#2a5f78;border-bottom:0.3mm solid #e5e7eb;padding-bottom:1mm;margin-bottom:2mm;margin-top:3mm;">تاریخچه پرداخت</div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:3mm;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">روش</th>
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">مبلغ</th>
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">وضعیت</th>
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">تاریخ</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>';
    }

    /* ─── بازگشت وجه ─── */
    $refundsHtml = '';
    if ($refunds) {
        $rows = '';
        foreach ($refunds as $r) {
            $rows .= '
            <tr>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $dateFa($r['completed_at'] ?? null) . '</td>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $h($methodLabel((string)($r['method'] ?? ''))) . '</td>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $h($r['document_no'] ?? $r['reference_no'] ?? '—') . '</td>
                <td style="padding:1.5mm;font-size:7.5pt;border-bottom:0.2mm solid #e5e7eb;">' . $moneyFa((int)$r['amount']) . '</td>
            </tr>';
        }
        $refundsHtml = '
        <div style="font-weight:bold;font-size:9pt;color:#2a5f78;border-bottom:0.3mm solid #e5e7eb;padding-bottom:1mm;margin-bottom:2mm;margin-top:3mm;">بازگشت وجه</div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:3mm;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">تاریخ</th>
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">روش</th>
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">سند</th>
                <th style="padding:1.5mm;font-size:7.5pt;text-align:right;border-bottom:0.2mm solid #e5e7eb;">مبلغ</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>';
    }

    /* ─── خلاصه مالی ─── */
    $totalsHtml = '
    <table style="width:100%;border-collapse:collapse;">
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#374151;">جمع اقلام:</td>
            <td style="padding:1.8mm;font-size:8pt;text-align:left;">' . $moneyFa($subtotal) . '</td>
        </tr>
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#374151;">تخفیف:</td>
            <td style="padding:1.8mm;font-size:8pt;text-align:left;' . ($discount > 0 ? 'color:#059669;' : '') . '">' .
        ($discount > 0 ? '- ' . $moneyFa($discount) : '۰ تومان') . '
            </td>
        </tr>
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#374151;">هزینه ارسال:</td>
            <td style="padding:1.8mm;font-size:8pt;text-align:left;">' .
        ($shipping > 0 ? $moneyFa($shipping) : '۰ تومان') . '
            </td>
        </tr>';

    if ($tax > 0) {
        $totalsHtml .= '
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#374151;">مالیات:</td>
            <td style="padding:1.8mm;font-size:8pt;text-align:left;">' . $moneyFa($tax) . '</td>
        </tr>';
    }

    $totalsHtml .= '
        <tr style="border-top:0.5mm solid #2a5f78;border-bottom:0.5mm solid #2a5f78;background:#f8fafc;">
            <td style="padding:2mm;font-size:10pt;font-weight:bold;color:#2a5f78;">مبلغ نهایی:</td>
            <td style="padding:2mm;font-size:10pt;font-weight:bold;color:#2a5f78;text-align:left;">' . $moneyFa($total) . '</td>
        </tr>
        <tr>
            <td colspan="2" style="padding:2mm 1.8mm 1mm;font-size:9pt;font-weight:bold;color:#2a5f78;border-bottom:0.2mm dashed #d1d5db;">جمع پرداخت‌ها</td>
        </tr>
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#059669;"> تأییدشده:</td>
            <td style="padding:1.8mm;font-size:8pt;color:#059669;text-align:left;">' . $moneyFa($sumVerified) . '</td>
        </tr>';

    if ($sumPending > 0) {
        $totalsHtml .= '
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#d97706;">در انتظار تأیید:</td>
            <td style="padding:1.8mm;font-size:8pt;color:#d97706;text-align:left;">' . $moneyFa($sumPending) . '</td>
        </tr>';
    }

    if ($sumRejected > 0) {
        $totalsHtml .= '
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#dc2626;"> ردشده:</td>
            <td style="padding:1.8mm;font-size:8pt;color:#dc2626;text-align:left;">' . $moneyFa($sumRejected) . '</td>
        </tr>';
    }

    if ($totalRefunded > 0) {
        $totalsHtml .= '
        <tr>
            <td style="padding:1.8mm;font-size:8pt;color:#dc2626;">بازگشت وجه:</td>
            <td style="padding:1.8mm;font-size:8pt;color:#dc2626;text-align:left;">- ' . $moneyFa($totalRefunded) . '</td>
        </tr>';
    }

    $remainingColor = $remaining > 0 ? '#dc2626' : ($remaining < 0 ? '#1d4ed8' : '#059669');
    $remainingLabel = $remaining > 0 ? 'مانده بدهی:' : ($remaining < 0 ? 'بستانکاری:' : 'مانده:');

    $totalsHtml .= '
        <tr style="border-top:0.5mm solid #2a5f78;">
            <td style="padding:2mm;font-size:9pt;font-weight:bold;color:' . $remainingColor . ';">' . $remainingLabel . '</td>
            <td style="padding:2mm;font-size:9pt;font-weight:bold;color:' . $remainingColor . ';text-align:left;">' . $moneyFa(abs($remaining)) . '</td>
        </tr>
    </table>';

    /* ─── نتیجه مالی ─── */
    $reconHtml = '
    <div style="border:0.2mm solid #e5e7eb;padding:2mm;background:#f9fafb;margin-bottom:2mm;">
        <div style="font-weight:bold;font-size:8pt;color:#6b7280;margin-bottom:1mm;">نتیجه مالی</div>
        <div style="font-size:8pt;">وضعیت: <strong>' . $h($recType) . '</strong>' .
        ($recAmount > 0 ? ' — مبلغ: <strong>' . $moneyFa($recAmount) . '</strong>' : '') . '</div>
    </div>';

    /* ─── توضیحات مشتری ─── */
    $noteHtml = '';
    if ($customerNote !== '') {
        $noteHtml = '<div style="border:0.2mm solid #e5e7eb;padding:2mm;background:#f9fafb;margin-bottom:2mm;">
            <div style="font-weight:bold;font-size:8pt;color:#6b7280;margin-bottom:1mm;">توضیحات مشتری</div>
            <div style="font-size:8pt;">' . nl2br($h($customerNote)) . '</div>
        </div>';
    }

    /* ─── گیرنده — یک خط ─── */
    $receiverParts = [];
    if ($receiverName !== '')   $receiverParts[] = $receiverName;
    if ($receiverMobile !== '') $receiverParts[] = $receiverMobile;
    if ($receiverProvince !== '' || $receiverCity !== '') {
        $receiverParts[] = trim($receiverProvince . ($receiverCity !== '' ? '، ' . $receiverCity : ''));
    }
    if ($receiverAddress !== '') $receiverParts[] = $receiverAddress;
    if ($receiverPostal !== '')  $receiverParts[] = 'کد پستی: ' . $receiverPostal;

    $receiverHtml = '';
    if ($receiverParts) {
        $receiverHtml = '
        <div style="background:#f0f9ff;border:0.2mm dashed #7dd3fc;padding:2mm 3mm;margin-bottom:3mm;font-size:8pt;color:#075985;">
            <strong style="color:#0369a1;">گیرنده:</strong> ' . $h(implode(' — ', $receiverParts)) . '
        </div>';
    }

    /* ═══════════════════════════════════════════════════════════
       HTML نهایی
    ═══════════════════════════════════════════════════════════ */
    $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>
        * { font-family: dejavusans; }
        body { direction: rtl; font-size: 8pt; color:#1f2937; line-height:1.5; }
        table { border-collapse: collapse; width:100%; }
        .title-brand { font-size:14pt; font-weight:bold; color:#2a5f78; }
        .title-sub { font-size:8pt; color:#6b7280; }
        .title-site { font-size:8pt; color:#2a5f78; }
        .meta-label { font-size:7.5pt; color:#6b7280; background:#f9fafb; padding:1.8mm; border:0.2mm solid #e5e7eb; }
        .meta-value { font-size:8pt; font-weight:bold; padding:1.8mm; border:0.2mm solid #e5e7eb; }
        .party-title { font-size:9pt; font-weight:bold; color:#2a5f78; border-bottom:0.3mm dashed #d1d5db; padding-bottom:1mm; margin-bottom:1.5mm; }
        .party-card { border:0.2mm solid #e5e7eb; background:#f9fafb; padding:2.5mm; vertical-align:top; }
        .section-title { font-size:9pt; font-weight:bold; color:#2a5f78; border-bottom:0.3mm solid #e5e7eb; padding-bottom:1mm; margin-bottom:2mm; }
        .items-head th { background:#f8fafc; padding:1.8mm 1.2mm; font-size:7.5pt; text-align:right; color:#374151; border-bottom:0.3mm solid #e5e7eb; }
        .footer { border-top:0.2mm solid #e5e7eb; padding-top:2mm; margin-top:4mm; font-size:7pt; color:#6b7280; text-align:center; }
        .party-line { font-size:8pt; margin-bottom:0.5mm; }
        .party-half { padding:0; vertical-align:top; }
    </style></head><body>';

    /* ─── هدر ─── */
    $html .= '<table style="border-bottom:0.6mm solid #2a5f78;padding-bottom:2mm;margin-bottom:3mm;"><tr>';
    $html .= '<td style="width:60%;vertical-align:middle;"><table><tr>';
    if ($logoHtml) $html .= '<td style="width:20mm;">' . $logoHtml . '</td>';
    $html .= '<td>';
    $html .= '<div class="title-brand">رابطی</div>';
    $html .= '<div class="title-sub">کارگاه تولیدی جوراب رابطی</div>';
    $html .= '<div class="title-site">' . $ltr($h($siteUrl)) . '</div>';
    $html .= '</td></tr></table></td>';
    $html .= '<td style="width:40%;vertical-align:middle;text-align:left;">';
    $html .= '<div style="font-size:8pt;color:#6b7280;">فاکتور فروش</div>';
    $html .= '<div style="font-size:11pt;font-weight:bold;color:#2a5f78;">' . $ltr($h($invoiceNo)) . '</div>';
    $html .= '<div style="font-size:8pt;color:#6b7280;">تاریخ: ' . $dateFa($invoiceDate) . '</div>';
    $html .= '</td></tr></table>';

    /* ─── Meta ─── */
    $html .= '<table style="margin-bottom:3mm;"><tr>';
    $html .= '<td class="meta-label">شماره فاکتور</td><td class="meta-value">' . $ltr($h($invoiceNo)) . '</td>';
    $html .= '<td class="meta-label">شماره سفارش</td><td class="meta-value">' . $ltr($h($orderNo)) . '</td>';
    $html .= '<td class="meta-label">تاریخ صدور</td><td class="meta-value">' . $dateFa($invoiceDate) . '</td>';
    $html .= '</tr></table>';

    /* ═══ خریدار | فروشنده ═══ */
    $html .= '<table style="margin-bottom:2mm;"><tr>';

    /* ─── خریدار ─── */
    $html .= '<td class="party-card party-half" style="width:49%;">';
    $html .= '<div class="party-title">خریدار <span style="font-size:6.5pt;color:#fff;background:#2a5f78;padding:0.5mm 1.5mm;border-radius:1mm;">' .
        ($buyerType === 'legal' ? 'حقوقی' : 'حقیقی') . '</span></div>';

    /* نام + کد ملی */
    $html .= '<table style="width:100%;"><tr>';
    $html .= '<td style="width:50%;padding:0;vertical-align:top;font-size:8pt;">';
    $html .= '<span style="color:#6b7280;">نام:</span> <strong>' . $h($buyerName) . '</strong>';
    $html .= '</td>';
    $html .= '<td style="width:50%;padding:0;vertical-align:top;font-size:8pt;text-align:left;">';
    $html .= '<span style="color:#6b7280;">کد ملی:</span> <strong>' . ($buyerNational !== '' ? $ltr($h($buyerNational)) : '—') . '</strong>';
    $html .= '</td>';
    $html .= '</tr></table>';

    /* موبایل */
    if ($buyerMobile !== '') {
        $html .= '<div class="party-line"><span style="color:#6b7280;">موبایل:</span> ' . $ltr($h($buyerMobile)) . '</div>';
    }

    /* شرکت */
    if ($buyerCompany !== '') {
        $html .= '<div class="party-line"><span style="color:#6b7280;">شرکت:</span> ' . $h($buyerCompany) . '</div>';
    }

    /* کد اقتصادی */
    if ($buyerEconomic !== '') {
        $html .= '<div class="party-line"><span style="color:#6b7280;">کد اقتصادی:</span> ' . $ltr($h($buyerEconomic)) . '</div>';
    }

    $html .= '</td>';

    /* ─── جداکننده ─── */
    $html .= '<td style="width:2%;"></td>';

    /* ─── فروشنده ─── */
    $html .= '<td class="party-card party-half" style="width:49%;">';
    $html .= '<div class="party-title">فروشنده <span style="font-size:6.5pt;color:#fff;background:#6b7280;padding:0.5mm 1.5mm;border-radius:1mm;">رابطی</span></div>';
    $html .= '<div class="party-line"><span style="color:#6b7280;">نام:</span> <strong>کارگاه تولید جوراب رابطی</strong></div>';
    $html .= '<div class="party-line"><span style="color:#6b7280;">آدرس:</span> بازار بزرگ تهران — سرای لطفی‌پور، پلاک ۲۲</div>';
    $html .= '<div class="party-line"><span style="color:#6b7280;">وب‌سایت:</span> ' . $ltr($h($siteUrl)) . '</div>';
    $html .= '</td>';

    $html .= '</tr></table>';

    /* ═══ گیرنده — یک خط ═══ */
    $html .= $receiverHtml;

    /* ═══ اقلام ═══ */
    $html .= '<div class="section-title">اقلام سفارش</div>';
    $html .= '<table><thead class="items-head"><tr>';
    $html .= '<th style="width:18mm;">تصویر</th>';
    $html .= '<th>محصول</th>';
    $html .= '<th style="width:15mm;">تعداد</th>';
    $html .= '<th style="width:25mm;">قیمت واحد</th>';
    $html .= '<th style="width:28mm;">مبلغ</th>';
    $html .= '</tr></thead><tbody>' . $itemsHtml . '</tbody></table>';

    /* ═══ پرداخت‌ها و بازگشت وجه ═══ */
    $html .= $paymentsHtml;
    $html .= $refundsHtml;

    /* ═══ نتیجه + خلاصه ═══ */
    $html .= '<table style="margin-top:3mm;"><tr>';
    $html .= '<td style="width:58%;vertical-align:top;padding-left:3mm;">' . $noteHtml . $reconHtml . '</td>';
    $html .= '<td style="width:42%;vertical-align:top;">' . $totalsHtml . '</td>';
    $html .= '</tr></table>';

    /* ═══ Footer ═══ */
    $unitLabel = htmlspecialchars(unit_label($items[0]['unit_code'] ?? 'جفتی'));
    $html .= '<div class="footer"| تولیدی جوراب رابطی | ' . $ltr($h($siteUrl)) . '</div>';

    $html .= '</body></html>';

    /* ═══ TCPDF ═══ */
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('رابطی');
    $pdf->SetAuthor('رابطی');
    $pdf->SetTitle('فاکتور ' . $invoiceNo);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setRTL(true);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    $safe = preg_replace('/[^A-Za-z0-9\-_]/', '', $invoiceNo);
    $pdf->Output('RABETI-invoice-' . ($safe ?: 'invoice') . '.pdf', 'D');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
?>
    <!doctype html>
    <html lang="fa" dir="rtl">

    <head>
        <meta charset="utf-8">
        <title>خطا در PDF</title>
    </head>

    <body style="font-family:Tahoma;padding:30px;line-height:2;">
        <h2>تولید PDF انجام نشد</h2>
        <p>خطا: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/invoice-print?inv=<?= rawurlencode($invoiceNumber) ?>">بازگشت به فاکتور</a>
    </body>

    </html>
<?php
}
