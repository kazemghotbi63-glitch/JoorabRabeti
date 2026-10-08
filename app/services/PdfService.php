<?php

declare(strict_types=1);

require_once __DIR__ . '/PdfStyle.php';

/* [FILE] app/services/PdfService.php — Invoice PDF generator (v3) */

final class PdfService
{
    private static bool $loaded = false;

    /* ─────────────────────────────────────────────
       تابع ۱ — لود TCPDF
       ───────────────────────────────────────────── */

    private static function loadTcpdf(): void
    {
        if (self::$loaded) {
            return;
        }

        $autoload = ROOT_PATH . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            throw new RuntimeException('Composer autoload پیدا نشد.');
        }

        require_once $autoload;

        if (!class_exists('TCPDF')) {
            throw new RuntimeException('TCPDF در Composer نصب نشده است.');
        }

        self::$loaded = true;
    }

    /* ─────────────────────────────────────────────
       تابع ۲ — میلادی به جلالی
       ───────────────────────────────────────────── */

    private static function toJalali(?string $mysqlDate): string
    {
        if (!$mysqlDate) return '—';

        $ts = strtotime($mysqlDate);
        if ($ts === false) return '—';

        $gy = (int)date('Y', $ts);
        $gm = (int)date('n', $ts);
        $gd = (int)date('j', $ts);

        $g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? $gy + 1 : $gy;

        $d = (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            - 80
            + $gd
            + $g[$gm - 1];

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

        return $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT)
            . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);
    }

    /* ─────────────────────────────────────────────
       تابع ۳ — تولید PDF
       ───────────────────────────────────────────── */

    public static function output(array $invoice): void
    {
        self::loadTcpdf();

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        $pdf->SetCreator('RABETI');
        $pdf->SetAuthor('رابطی');
        $pdf->SetTitle('فاکتور ' . (string)($invoice['invoice_number'] ?? ''));
        $pdf->SetSubject('فاکتور فروش');

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL(true);

        $pdf->SetMargins(9, 8, 9);
        $pdf->SetAutoPageBreak(true, 8);

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->AddPage();

        $pdf->writeHTML(
            self::buildHtml($invoice),
            true,
            false,
            true,
            false,
            ''
        );

        $invoiceNumber = (string)($invoice['invoice_number'] ?? 'invoice');
        $safe = preg_replace('/[^A-Za-z0-9\-_]/', '', $invoiceNumber);

        if ($safe === '') {
            $safe = 'invoice';
        }

        $pdf->Output('RABETI-invoice-' . $safe . '.pdf', 'D');
    }

    /* ─────────────────────────────────────────────
       تابع ۴ — HTML فاکتور
       ───────────────────────────────────────────── */

    private static function buildHtml(array $invoice): string
    {
        $e = static fn($v): string =>
        htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $faDigits = static fn(string $v): string => strtr($v, [
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
        ]);

        $number = static fn($v): string =>
        $faDigits(number_format((int)$v, 0, '.', ','));

        $money = static fn($v): string =>
        $number($v) . ' تومان';
        /*
         * ═══ داده‌های فاکتور ═══
         */

        $invoiceNumber = (string)($invoice['invoice_number'] ?? '—');
        $orderNumber   = (string)($invoice['order_number'] ?? '—');

        $customerName   = (string)($invoice['shipping_name'] ?? $invoice['customer_name'] ?? '—');
        $customerMobile = (string)($invoice['shipping_mobile'] ?? $invoice['customer_mobile'] ?? '—');

        $province = trim((string)($invoice['shipping_province'] ?? ''));
        $city     = trim((string)($invoice['shipping_city'] ?? ''));
        $address  = trim((string)($invoice['shipping_address'] ?? ''));
        $postal   = trim((string)($invoice['shipping_postal'] ?? ''));

        /*
         * ═══ تاریخ شمسی ═══
         */

        $issuedAt   = $invoice['invoice_issued_at'] ?? $invoice['order_created_at'] ?? null;
        $issuedDate = '—';
        $issuedTime = '—';

        if ($issuedAt) {
            $ts = strtotime((string)$issuedAt);
            if ($ts !== false) {
                $issuedDate = $faDigits(self::toJalali(date('Y-m-d H:i:s', $ts)));
                $issuedTime = $faDigits(date('H:i', $ts));
            }
        }

        $orderStatus = (string)($invoice['status'] ?? $invoice['order_status'] ?? '—');

        /*
         * ═══ مبالغ — مستقیم از ردیف فاکتور ═══
         */

        $totals   = $invoice['totals'] ?? [];
        $items    = $invoice['items'] ?? [];

        $subtotal = (int)($totals['subtotal'] ?? 0);
        $discount = (int)($totals['discount'] ?? 0);
        $shipping = (int)($totals['shipping'] ?? 0);

        $total = (int)($invoice['total'] ?? $invoice['invoice_total'] ?? 0);
        $paid  = (int)($invoice['paid_total'] ?? $invoice['invoice_paid_total'] ?? 0);

        $pendingAmount = (int)($invoice['pending_amount'] ?? 0);

        /* ⭐ بستانکاری — بدون max(0) */
        $remaining = $total - $paid;

        /*
         * ═══ نتیجه مالی — COMPUTED ═══
         */

        if ($remaining > 0) {
            $recType = 'بدهکار';
            $recAmount = $remaining;
        } elseif ($remaining < 0) {
            $recType = 'بستانکار';
            $recAmount = abs($remaining);
        } else {
            $recType = 'تسویه';
            $recAmount = 0;
        }

        /*
         * ═══ اسناد بازگشت وجه ═══
         */

        $refunds = [];
        $totalRefunded = 0;

        try {

            $refundsStmt = db()->prepare("
    SELECT amount, method, document_no, reference_no, completed_at
    FROM financial_transactions
    WHERE invoice_id = ?
      AND type = 'creditor_settlement'
      AND status = 'completed'
    ORDER BY completed_at ASC
");

            $refundsStmt->execute([(int)($invoice['id'] ?? 0)]);

            $refunds = $refundsStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($refunds as $r) {
                $totalRefunded += (int)$r['amount'];
            }
        } catch (Throwable $e) {
            $refunds = [];
        }

        /*
         * ═══ لوگو ═══
         */

        $logoPath = PUBLIC_PATH . '/Image/logo3.jpg';

        $logoHtml = '';

        if (is_file($logoPath)) {
            $logoHtml = '<img src="'
                . $e(str_replace('\\', '/', $logoPath))
                . '" style="max-width:22mm; max-height:22mm; width:auto; height:auto;">';
        }

        /*
         * ═══ آدرس کامل ═══
         */

        $addressParts = array_filter(
            [$province, $city, $address],
            static fn($v) => trim((string)$v) !== ''
        );

        $fullAddress = implode('، ', $addressParts);

        if ($postal !== '') {
            $fullAddress .= ($fullAddress !== '' ? ' — ' : '') . 'کد پستی: ' . $postal;
        }

        if ($fullAddress === '') {
            $fullAddress = '—';
        }

        /*
         * ═══ اقلام ═══
         */

        $itemsHtml = '';

        foreach ($items as $index => $item) {

            $productName = (string)($item['product_name'] ?? 'محصول');
            $productCode = trim((string)($item['product_code'] ?? ''));
            $qty         = (int)($item['qty'] ?? 0);
            $baseUnitPrice = (int)($item['base_unit_price'] ?? $item['unit_price'] ?? 0);
            $unitPrice   = (int)($item['unit_price'] ?? 0);
            $discountPercent = (float)($item['discount_percent'] ?? 0);
            $lineTotal   = (int)($item['line_total'] ?? ($unitPrice * $qty));

            $discountText = '—';

            if ($discountPercent > 0) {
                $discountText = $faDigits(
                    rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.')
                ) . '٪';
            }

            $codeHtml = '';

            if ($productCode !== '') {
                $codeHtml = '<div class="product-code">کد: <span dir="ltr">'
                    . $e($productCode)
                    . '</span></div>';
            }

            $itemsHtml .= '
                <tr>
                    <td class="center" width="7%">' . $number($index + 1) . '</td>
                    <td class="product" width="29%">
                        <div class="product-name">' . $e($productName) . '</div>
                        ' . $codeHtml . '
                    </td>
                    <td class="center" width="10%">' . $number($qty) . '</td>
                    <td class="money" width="16%">' . $money($baseUnitPrice) . '</td>
                    <td class="center" width="11%">' . $discountText . '</td>
                    <td class="money" width="27%">' . $money($lineTotal) . '</td>
                </tr>';
        }

        if ($itemsHtml === '') {
            $itemsHtml = '<tr><td colspan="6" class="empty">موردی برای نمایش وجود ندارد.</td></tr>';
        }

        /*
         * ═══ یادداشت ═══
         */

        $note = trim((string)($invoice['customer_note'] ?? ''));

        $noteHtml = '
            <div class="bottom-box">
                <div class="box-title">یادداشت سفارش</div>
                <div class="note ' . ($note === '' ? 'muted' : '') . '">'
            . ($note !== '' ? nl2br($e($note)) : '—') .
            '</div>
            </div>';

        /*
         * ═══ اسناد بازگشت وجه — HTML ═══
         */

        $refundsHtml = '';

        if ($refunds) {

            $refundRows = '';

            foreach ($refunds as $r) {

                $refundRows .= '
                    <tr class="refund-row">
                        <td class="center">' . $faDigits(self::toJalali($r['completed_at'] ?? null)) . '</td>
                        <td class="center">' . $e($r['method'] ?? '—') . '</td>
                        <td class="center" dir="ltr">' . $e($r['document_no'] ?? $r['reference_no'] ?? '—') . '</td>
                        <td class="center b">' . $money((int)$r['amount']) . '</td>
                    </tr>';
            }

            $refundsHtml = '
                <div class="section-title">اسناد بازگشت وجه</div>

                <table class="items">
                    <thead>
                        <tr>
                            <th width="25%">تاریخ</th>
                            <th width="25%">روش</th>
                            <th width="25%">شماره سند / پیگیری</th>
                            <th width="25%">مبلغ</th>
                        </tr>
                    </thead>
                    <tbody>' . $refundRows . '</tbody>
                </table>';
        }

        /*
         * ═══ نتیجه مالی — HTML ═══
         */

        $reconHtml = '
            <table class="meta">
                <tr>
                    <td class="meta-label">نتیجه مالی (محاسبه‌شده سیستمی)</td>
                    <td class="meta-value">' . $e($recType) . '</td>
                    <td class="meta-label">مبلغ</td>
                    <td class="meta-value">' . ($recAmount > 0 ? $money($recAmount) : '—') . '</td>
                </tr>
            </table>';

        /*
         * ═══ CSS از فایل جدا ═══
         */

        $css = PdfStyle::get();

        /*
         * ═══ HTML نهایی ═══
         */

        return '
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<style>' . $css . '</style>
</head>
<body>

<div class="header">
    <table class="header-table">
        <tr>
            <td class="logo-cell">' . $logoHtml . '</td>
            <td class="title-cell">
                <div class="brand">رابطی</div>
                <div class="subtitle">تولید مستقیم · فروش عمده</div>
                <div class="invoice-title">فاکتور فروش</div>
            </td>
        </tr>
    </table>
</div>

<table class="meta">
    <tr>
        <td class="meta-label">شماره فاکتور</td>
        <td class="meta-value"><span class="ltr">' . $e($invoiceNumber) . '</span></td>
        <td class="meta-label">تاریخ</td>
        <td class="meta-value">' . $issuedDate . '</td>
    </tr>
    <tr>
        <td class="meta-label">شماره سفارش</td>
        <td class="meta-value"><span class="ltr">' . $e($orderNumber) . '</span></td>
        <td class="meta-label">ساعت</td>
        <td class="meta-value">' . $issuedTime . '</td>
    </tr>
    <tr>
        <td class="meta-label">وضعیت سفارش</td>
        <td class="meta-value">' . $e($orderStatus) . '</td>
        <td class="meta-label">نوع فروش</td>
        <td class="meta-value">عمده</td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td class="party">
            <div class="party-box">
                <div class="party-title">فروشنده</div>
                <div class="party-row"><span class="party-label">نام:</span> رابطی</div>
                <div class="party-row"><span class="party-label">نوع فعالیت:</span> تولید و فروش عمده جوراب</div>
            </div>
        </td>
        <td class="party-gap"></td>
        <td class="party">
            <div class="party-box">
                <div class="party-title">مشتری</div>
                <div class="party-row"><span class="party-label">نام:</span> ' . $e($customerName) . '</div>
                <div class="party-row"><span class="party-label">موبایل:</span> ' . $e($customerMobile) . '</div>
                <div class="party-row"><span class="party-label">آدرس:</span> ' . $e($fullAddress) . '</div>
            </div>
        </td>
    </tr>
</table>

<div class="section-title">جزئیات اقلام</div>

<table class="items">
    <thead>
        <tr>
            <th width="7%">ردیف</th>
            <th width="29%">محصول</th>
            <th width="10%">تعداد</th>
            <th width="16%">قیمت پایه</th>
            <th width="11%">تخفیف</th>
            <th width="27%">مبلغ نهایی</th>
        </tr>
    </thead>
    <tbody>' . $itemsHtml . '</tbody>
</table>

' . $reconHtml . '

' . $refundsHtml . '

<table class="bottom">
    <tr>
        <td class="bottom-left">' . $noteHtml . '</td>
        <td class="bottom-right">
            <div class="bottom-box">
                <div class="box-title">خلاصه مالی</div>
                <table class="totals">

                    <tr>
                        <td class="total-label">جمع اقلام</td>
                        <td class="total-value">' . $money($subtotal) . '</td>
                    </tr>

                    ' . ($discount > 0 ? '
                    <tr>
                        <td class="total-label">تخفیف</td>
                        <td class="total-value">' . $money($discount) . '</td>
                    </tr>' : '') . '

                    ' . ($shipping > 0 ? '
                    <tr>
                        <td class="total-label">هزینه ارسال</td>
                        <td class="total-value">' . $money($shipping) . '</td>
                    </tr>' : '') . '

                    <tr class="grand-total">
                        <td class="total-label">مبلغ کل</td>
                        <td class="total-value">' . $money($total) . '</td>
                    </tr>

                    <tr>
                        <td class="total-label">پرداخت‌شده (تأییدشده)</td>
                        <td class="total-value">' . $money($paid) . '</td>
                    </tr>

                    ' . ($pendingAmount > 0 ? '
                    <tr>
                        <td class="total-label">در انتظار تأیید مالی</td>
                        <td class="total-value">' . $money($pendingAmount) . '</td>
                    </tr>' : '') . '

                    ' . ($totalRefunded > 0 ? '
                    <tr class="refund-row">
                        <td class="total-label">بازگشت وجه به مشتری</td>
                        <td class="total-value">- ' . $money($totalRefunded) . '</td>
                    </tr>' : '') . '

                    <tr class="remaining grand-total">
                        <td class="total-label">مانده قطعی</td>
                        <td class="total-value">' . $money(max(0, $remaining)) . '</td>
                    </tr>

                </table>
            </div>
        </td>
    </tr>
</table>

<div class="footer">
    این فاکتور به‌صورت الکترونیکی توسط رابطی صادر شده است.
</div>

</body>
</html>';
    }
}
