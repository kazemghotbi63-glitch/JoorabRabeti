<?php

declare(strict_types=1);

/**
 * Order SMS Service
 *
 * مسئول:
 * - آماده‌سازی اطلاعات پیامک‌های مرتبط با سفارش
 * - انتخاب Pattern مناسب از settings
 * - جلوگیری از ارسال تکراری پیامک
 *
 * ارسال واقعی SMS توسط SmsService انجام می‌شود.
 */

final class OrderSmsService
{
    /**
     * ارسال پیامک تأیید ثبت سفارش
     *
     * Setting:
     * sms_pattern_order_approved
     *
     * Event:
     * order_approved
     */
    public static function orderApproved(int $orderId): array
    {
        $order = self::getOrder($orderId);

        if (!$order) {
            throw new RuntimeException('سفارش پیدا نشد.');
        }

        $mobile = self::resolveMobile($order);

        if ($mobile === '') {
            throw new RuntimeException(
                'شماره موبایل مشتری برای ارسال پیامک پیدا نشد.'
            );
        }

        $patternId = Setting::getInt(
            'sms_pattern_order_approved',
            0
        );

        if ($patternId <= 0) {
            throw new RuntimeException(
                'Pattern تأیید سفارش تنظیم نشده است.'
            );
        }

        if (
            self::alreadySent(
                $orderId,
                'order_approved',
                $patternId
            )
        ) {
            return [
                'success' => true,
                'skipped' => true,
                'reason'  => 'already_sent',
            ];
        }

        return SmsService::sendPattern(
            $mobile,
            $patternId,
            self::orderVariables($order),
            (int)$order['user_id'],
            $orderId,
            'order_approved'
        );
    }

    /**
     * ارسال پیامک «ارسال شد»
     *
     * Setting:
     * sms_pattern_order_shipping
     *
     * Event:
     * order_shipping
     *
     * این پیام فقط زمانی باید ارسال شود که سفارش
     * واقعاً وارد وضعیت shipped شده باشد.
     */
    public static function orderShipping(int $orderId): array
    {
        $order = self::getOrder($orderId);

        if (!$order) {
            throw new RuntimeException('سفارش پیدا نشد.');
        }

        $mobile = self::resolveMobile($order);

        if ($mobile === '') {
            throw new RuntimeException(
                'شماره موبایل مشتری برای ارسال پیامک پیدا نشد.'
            );
        }

        /*
         * Pattern از Settings خوانده می‌شود.
         * عدد Pattern نباید در کد هاردکد شود.
         */
        $patternId = Setting::getInt(
            'sms_pattern_order_shipping',
            0
        );

        if ($patternId <= 0) {
            throw new RuntimeException(
                'Pattern پیامک ارسال سفارش تنظیم نشده است.'
            );
        }
        /*
 * event مخصوص هر وضعیت — تا هر transition پیامک خودش را داشته باشد
 * و alreadySent جلوی transition بعدی را نگیرد.
 */
        $statusEventMap = [
            'packed'       => 'order_shipping_packed',
            'shipped-pik'  => 'order_shipping_pik',
            'shipped-post' => 'order_shipping_post',
        ];

        $currentStatus = (string)($order['status'] ?? '');
        $event = $statusEventMap[$currentStatus] ?? 'order_shipping';

        if (
            self::alreadySent(
                $orderId,
                $event,
                $patternId
            )
        ) {
            return [
                'success' => true,
                'skipped' => true,
                'reason'  => 'already_sent',
            ];
        }

        return SmsService::sendPattern(
            $mobile,
            $patternId,
            self::orderVariables($order),
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
            LEFT JOIN users u
                ON u.id = o.user_id
            WHERE o.id = ?
            LIMIT 1
        ");

        $stmt->execute([$orderId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * انتخاب شماره موبایل مقصد
     *
     * اولویت:
     * 1) شماره گیرنده سفارش
     * 2) شماره حساب مشتری
     */
    private static function resolveMobile(array $order): string
    {
        $mobile = trim(
            (string)($order['shipping_mobile'] ?? '')
        );

        if ($mobile !== '') {
            return $mobile;
        }

        return trim(
            (string)($order['user_mobile'] ?? '')
        );
    }

    /**
     * متغیرهای مشترک Patternهای سفارش
     */

    private static function orderVariables(array $order): array
    {
        $statusLabels = [
            'packed'       => 'بسته بندی و آماده تحویل',
            'shipped-pik'  => 'تحویل پیک جهت ارسال',
            'shipped-post' => 'تحویل پست جهت ارسال',
        ];

        $status = (string)($order['status'] ?? '');

        return [
            'ORDER'  => (string)$order['order_number'],
            'DATE'   => date('Y/m/d'),
            'TIME'   => date('H:i'),
            'MONEY'  => self::formatMoney(
                (float)$order['total']
            ),
            'PACKED' => $statusLabels[$status] ?? '',
            'LOGO'   => 'از خرید شما متشکریم',
        ];
    }

    /**
     * بررسی ارسال موفق قبلی
     */
    private static function alreadySent(
        int $orderId,
        string $event,
        int $patternId
    ): bool {
        $stmt = db()->prepare("
            SELECT id
            FROM sms_logs
            WHERE order_id = ?
              AND event = ?
              AND pattern_id = ?
              AND status = 'sent'
            LIMIT 1
        ");

        $stmt->execute([
            $orderId,
            $event,
            $patternId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * فرمت مبلغ برای SMS
     */
    private static function formatMoney(float $amount): string
    {
        return number_format(
            $amount,
            0,
            '.',
            ''
        );
    }
}
