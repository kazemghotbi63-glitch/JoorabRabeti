<?php

declare(strict_types=1);

/* ─── بارگذاری وابستگی‌ها ─── */
if (!class_exists('SmsService', false)) {
    require_once ROOT_PATH . '/app/services/SmsService.php';
}

final class OrderSmsService
{
    /**
     * ارسال پیامک تأیید ثبت سفارش
     */
    public static function orderApproved(int $orderId, array $fin = []): array
    {
        $order = self::getOrder($orderId);

        if (!$order) {
            throw new RuntimeException('سفارش پیدا نشد.');
        }

        $mobile = self::resolveMobile($order);

        if ($mobile === '') {
            throw new RuntimeException('شماره موبایل مشتری برای ارسال پیامک پیدا نشد.');
        }

        $patternId = Setting::getInt('sms_pattern_order_approved', 0);

        if ($patternId <= 0) {
            throw new RuntimeException('Pattern تأیید سفارش تنظیم نشده است.');
        }

        /* ⭐ event یهتا با timestamp — هر تأیید پیامک خودش رو داره */
        $uniqueEvent = 'order_approved_' . date('YmdHis');

        if (self::alreadySent($orderId, $uniqueEvent, $patternId)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'already_sent'];
        }

        return SmsService::sendPattern(
            $mobile,
            $patternId,
            self::orderApprovedVariables($order, $fin),
            (int)$order['user_id'],
            $orderId,
            $uniqueEvent
        );
    }

    /**
     * ارسال پیامک «ارسال شد»
     */
    public static function orderShipping(int $orderId): array
    {
        $order = self::getOrder($orderId);

        if (!$order) {
            throw new RuntimeException('سفارش پیدا نشد.');
        }

        $mobile = self::resolveMobile($order);

        if ($mobile === '') {
            throw new RuntimeException('شماره موبایل مشتری برای ارسال پیامک پیدا نشد.');
        }

        $patternId = Setting::getInt('sms_pattern_order_shipping', 0);

        if ($patternId <= 0) {
            throw new RuntimeException('Pattern پیامک ارسال سفارش تنظیم نشده است.');
        }
        $statusEventMap = [
            'packed'            => 'order_shipping_packed',
            'shipped-pik'       => 'order_shipping_pik',
            'shipped-post'      => 'order_shipping_post',
            'shipped-tipax'     => 'order_shipping_tipax',
            'shipped-barbari'   => 'order_shipping_barbari',
            'shipped'           => 'order_shipping_general',
        ];
        $currentStatus = (string)($order['status'] ?? '');
        $event = $statusEventMap[$currentStatus] ?? 'order_shipping';

        if (self::alreadySent($orderId, $event, $patternId)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'already_sent'];
        }

        return SmsService::sendPattern(
            $mobile,
            $patternId,
            self::orderShippingVariables($order),
            (int)$order['user_id'],
            $orderId,
            $event
        );
    }

    /**
     * اطلاعات سفارش
     */
    private static function getOrder(int $orderId): ?array
    {
        $stmt = db()->prepare("
            SELECT
                o.id,
                o.order_number,
                o.user_id,
                o.total,
                o.status,
                o.shipping_mobile,
                u.mobile AS user_mobile
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = ?
            LIMIT 1
        ");

        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * انتخاب شماره موبایل مقصد
     */
    private static function resolveMobile(array $order): string
    {
        /* ⭐ اولویت به شماره کاربر (صاحب حساب) */
        $mobile = trim((string)($order['user_mobile'] ?? ''));
        if ($mobile !== '') {
            return $mobile;
        }
        /* اگه شماره کاربر نبود، از شماره گیرنده استفاده کن */
        return trim((string)($order['shipping_mobile'] ?? ''));
    }

    /**
     * ⭐ متغیرهای Pattern تأیید پرداخت (2685)
     *    ORDER, DATE, PAID, REMAINING, STATUS
     */
    private static function orderApprovedVariables(array $order, array $fin = []): array
    {
        $paid      = (float)($fin['paid'] ?? 0);
        $remaining = (float)($fin['remaining'] ?? 0);

        if ($remaining > 0) {
            $statusText = 'بدهکار';
        } elseif ($remaining < 0) {
            $statusText = 'بستانکار';
        } else {
            $statusText = 'تسویه شده';
        }

        return [
            'ORDER'     => (string)$order['order_number'],
            'DATE'      => self::jalaliDate(),
            'PAID'      => self::formatMoney($paid),
            'REMAINING' => self::formatMoney(abs($remaining)),
            'STATUS'    => $statusText,
        ];
    }

    /**
     * ⭐ متغیرهای Pattern ارسال سفارش (2691)
     *    ORDER, DATE, TIME
     */
    private static function orderShippingVariables(array $order): array
    {
        return [
            'ORDER' => (string)$order['order_number'],
            'DATE'  => self::jalaliDate(),
            'TIME'  => self::timeFa(),
        ];
    }

    /**
     * تبدیل تاریخ میلادی به شمسی
     */
    private static function jalaliDate(): string
    {
        $gy = (int)date('Y');
        $gm = (int)date('n');
        $gd = (int)date('j');

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

        $formatted = $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);

        return strtr($formatted, [
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
    }

    /**
     * ⭐ ساعت فعلی به فارسی
     */
    private static function timeFa(): string
    {
        $time = date('H:i');
        return strtr($time, [
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
    }

    /**
     * فرمت مبلغ — فارسی + کاما + تومان
     */
    private static function formatMoney(float $amount): string
    {
        $formatted = number_format($amount, 0, '.', ',');
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
    }

    /**
     * بررسی ارسال موفق قبلی
     */
    private static function alreadySent(int $orderId, string $event, int $patternId): bool
    {
        $stmt = db()->prepare("
            SELECT id FROM sms_logs
            WHERE order_id = ? AND event = ? AND pattern_id = ? AND status = 'sent'
            LIMIT 1
        ");
        $stmt->execute([$orderId, $event, $patternId]);
        return (bool)$stmt->fetchColumn();
    }
}
