<?php

declare(strict_types=1);

/**
 * SMS Service
 *
 * مسئول:
 * - ارسال پیامک Pattern از طریق Payamak
 * - خواندن تنظیمات SMS از جدول settings
 * - ثبت نتیجه ارسال در sms_logs
 *
 * این کلاس هیچ منطق تجاری سفارش/فاکتور ندارد.
 */

final class SmsService
{
    private const API_URL = 'https://api-payamak.com/api/v3/rest/sms/pattern-send';

    /**
     * ارسال پیامک Pattern
     *
     * @param string      $mobile      شماره گیرنده
     * @param int         $patternId   شناسه Pattern در Payamak
     * @param array       $variables   متغیرهای Pattern
     * @param int|null    $userId      شناسه کاربر
     * @param int|null    $orderId     شناسه سفارش
     * @param string      $event       نام رویداد
     *
     * @return array
     */
    public static function sendPattern(
        string $mobile,
        int $patternId,
        array $variables,
        ?int $userId = null,
        ?int $orderId = null,
        string $event = 'manual'
    ): array {
        $mobile = self::normalizeMobile($mobile);

        if (!preg_match('/^09\d{9}$/', $mobile)) {
            throw new InvalidArgumentException('شماره موبایل معتبر نیست.');
        }

        if ($patternId <= 0) {
            throw new InvalidArgumentException('Pattern ID معتبر نیست.');
        }

        $apiKey = Setting::get('sms_api_key');
        $from   = Setting::get('asa_from');

        if ($apiKey === '') {
            throw new RuntimeException('کلید API پیامک تنظیم نشده است.');
        }

        /*
         * Payamak برای Pattern انتظار JSON در message دارد.
         *
         * مثال:
         * {
         *     "CODE": "123456"
         * }
         */
        $message = json_encode(
            $variables,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($message === false) {
            throw new RuntimeException('ساخت متغیرهای پیامک ناموفق بود.');
        }

        /*
         * ابتدا Log را با وضعیت pending ثبت می‌کنیم.
         * این کار باعث می‌شود حتی در صورت خطای API نیز سابقه تلاش وجود داشته باشد.
         */
        $logId = self::createLog(
            $userId,
            $orderId,
            $mobile,
            $patternId,
            $event,
            $variables
        );

        $payload = [
            'from'       => $from,
            'recipients' => [$mobile],
            'message'    => $message,
            'pattern_id' => $patternId,
        ];

        $ch = curl_init(self::API_URL);

        if ($ch === false) {
            self::markFailed($logId, 'امکان ایجاد اتصال cURL وجود ندارد.');
            throw new RuntimeException('امکان اتصال به سرویس پیامک وجود ندارد.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $curlError = curl_error($ch);
            curl_close($ch);

            self::markFailed($logId, $curlError);

            throw new RuntimeException(
                'خطا در ارتباط با سرویس پیامک: ' . $curlError
            );
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        /*
         * هرگز API Key را در Log ذخیره یا چاپ نمی‌کنیم.
         */
        $safeResponse = substr((string)$response, 0, 5000);

        $decoded = json_decode($response, true);

        /*
 * خطای HTTP
 */
        if ($httpCode < 200 || $httpCode >= 300) {

            self::markFailed(
                $logId,
                'HTTP ' . $httpCode . ': ' . $safeResponse
            );

            throw new RuntimeException(
                'Payamak HTTP ' . $httpCode . ': ' . $safeResponse
            );
        }

        /*
 * Payamak ممکن است HTTP 200 بدهد،
 * اما داخل response اعلام کند که ارسال ناموفق بوده است.
 *
 * مثال:
 * {
 *   "return": {
 *      "status": 400,
 *      "message": "خطا در ارسال پیام: کد 29"
 *   }
 * }
 */
        $payamakStatus = null;
        $payamakMessage = null;

        if (
            is_array($decoded)
            && isset($decoded['return'])
            && is_array($decoded['return'])
        ) {
            $payamakStatus = isset($decoded['return']['status'])
                ? (int)$decoded['return']['status']
                : null;

            $payamakMessage = isset($decoded['return']['message'])
                ? (string)$decoded['return']['message']
                : null;
        }

        /*
 * اگر Payamak صراحتاً status غیرموفق برگرداند،
 * ارسال را failed ثبت می‌کنیم.
 */
        if ($payamakStatus !== null && $payamakStatus !== 200) {

            $errorMessage = $payamakMessage !== null && $payamakMessage !== ''
                ? $payamakMessage
                : 'Payamak status: ' . $payamakStatus;

            self::markFailed(
                $logId,
                $errorMessage
            );

            throw new RuntimeException($errorMessage);
        }

        /*
 * در غیر این صورت ارسال موفق است.
 */
        $messageId = self::extractMessageId($decoded);

        self::markSent(
            $logId,
            $messageId,
            $safeResponse
        );

        return [
            'success'    => true,
            'log_id'     => $logId,
            'message_id' => $messageId,
            'http_code'  => $httpCode,
            'response'   => $decoded ?? $response,
        ];
    }

    /**
     * نرمال‌سازی شماره موبایل
     */
    private static function normalizeMobile(string $mobile): string
    {
        $mobile = trim($mobile);

        $mobile = strtr($mobile, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
        ]);

        /*
         * پشتیبانی از فرمت +989...
         */
        if (str_starts_with($mobile, '+98')) {
            $mobile = '0' . substr($mobile, 3);
        }

        /*
         * پشتیبانی از 989...
         */
        if (str_starts_with($mobile, '98') && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }

        /*
         * حذف فاصله و کاراکترهای متداول
         */
        $mobile = preg_replace('/[\s\-\(\)]/', '', $mobile) ?? $mobile;

        return $mobile;
    }

    /**
     * ایجاد Log اولیه
     */
    private static function createLog(
        ?int $userId,
        ?int $orderId,
        string $mobile,
        int $patternId,
        string $event,
        array $variables
    ): int {
        $stmt = db()->prepare("
            INSERT INTO sms_logs
                (
                    user_id,
                    order_id,
                    mobile,
                    pattern_id,
                    event,
                    variables,
                    status
                )
            VALUES
                (?, ?, ?, ?, ?, ?, 'pending')
        ");

        $variablesJson = json_encode(
            $variables,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($variablesJson === false) {
            $variablesJson = '{}';
        }

        $stmt->execute([
            $userId,
            $orderId,
            $mobile,
            $patternId,
            $event,
            $variablesJson,
        ]);

        return (int)db()->lastInsertId();
    }

    /**
     * ثبت موفقیت ارسال
     */
    private static function markSent(
        int $logId,
        ?string $messageId,
        string $response
    ): void {
        $stmt = db()->prepare("
            UPDATE sms_logs
            SET
                status = 'sent',
                message_id = ?,
                response = ?,
                sent_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $messageId,
            $response,
            $logId,
        ]);
    }

    /**
     * ثبت خطای ارسال
     */
    private static function markFailed(
        int $logId,
        string $errorMessage
    ): void {
        $stmt = db()->prepare("
            UPDATE sms_logs
            SET
                status = 'failed',
                error_message = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $errorMessage,
            $logId,
        ]);
    }

    /**
     * استخراج Message ID از پاسخ Payamak
     */
    private static function extractMessageId($response): ?string
    {
        if (!is_array($response)) {
            return null;
        }

        $possibleKeys = [
            'messageid',
            'messageId',
            'message_id',
            'id',
        ];

        foreach ($possibleKeys as $key) {
            if (
                array_key_exists($key, $response)
                && $response[$key] !== null
                && $response[$key] !== ''
            ) {
                return (string)$response[$key];
            }
        }

        /*
         * بعضی APIها ممکن است پاسخ را داخل data برگردانند.
         */
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($possibleKeys as $key) {
                if (
                    array_key_exists($key, $response['data'])
                    && $response['data'][$key] !== null
                    && $response['data'][$key] !== ''
                ) {
                    return (string)$response['data'][$key];
                }
            }
        }

        return null;
    }
}
