<?php

declare(strict_types=1);

/* [FILE] app/services/NotifyService.php — ارسال SMS (پیامک v3) و Email */

final class NotifyService
{
    /* ═══════════ نقطه ورود واحد ═══════════ */

    /**
     * ارسال اعلان — SMS و Email
     * خطاها را پرتاب نمی‌کند به بیرون اصلی؛ فقط error_log
     * تا عملیات اصلی (سفارش/تأیید) خراب نشود
     */
    public static function send(
        int $userId,
        ?string $mobile,
        ?string $email,
        string $title,
        string $body,
        ?string $link = null
    ): void {

        /* ثبت در notifications — همیشه، برای سابقه */
        try {
            db()->prepare("INSERT INTO notifications (user_id, type, title, body, link)
                           VALUES (?, 'notify', ?, ?, ?)")
                ->execute([$userId, $title, $body, $link]);
        } catch (Throwable $e) {
            /* سابقه جلوی ارسال را نمی‌گیرد */
        }

        /* SMS */
        if ($mobile) {
            try {
                self::sendSms($mobile, $body);
            } catch (Throwable $e) {
                error_log('SMS FAILED: ' . $e->getMessage());
            }
        }

        /* Email */
        if ($email) {
            try {
                self::sendEmail($email, $title, $body);
            } catch (Throwable $e) {
                error_log('EMAIL FAILED: ' . $e->getMessage());
            }
        }
    }

    /* ═══════════ SMS — پیامک v3 (pattern-send) ═══════════ */

    /**
     * پترن ۲۴۰۶ در پنل پیامک:
     * «با سلام رمز عبور شما CODE میباشد. رمز عبور: CODE ...»
     * پارامتر CODE با مقدار body جایگزین می‌شود
     */
    private static function sendSms(string $mobile, string $body): void
    {
        $apiKey    = Setting::get('sms_api_key');
        $patternId = Setting::get('asa_pattern_id');
        $from      = Setting::get('asa_from');

        if ($apiKey === '' || $patternId === '') {
            throw new RuntimeException('SMS تنظیم نشده است.');
        }

        $payload = [
            'from'       => $from,
            'recipients' => [$mobile],
            'message'    => json_encode(['CODE' => $body], JSON_UNESCAPED_UNICODE),
            'pattern_id' => (int)$patternId,
        ];

        $ch = curl_init('https://api-payamak.com/api/v3/rest/sms/pattern-send');

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        /* دیباگ موقت */
        error_log('PAYAMAK SMS: mobile=' . $mobile . ' | response=' . substr((string)$response, 0, 300));

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Payamak HTTP $httpCode: $response");
        }
    }

    /* ═══════════ Email — SMTP با PHPMailer ═══════════ */

    private static function sendEmail(string $to, string $subject, string $body): void
    {
        $host     = Setting::get('smtp_host');
        $username = Setting::get('smtp_user');
        $password = Setting::get('smtp_pass');
        $port     = Setting::get('smtp_port', '587');
        $fromName = Setting::get('smtp_from_name', 'رابطی');

        if ($host === '' || $username === '') {
            throw new RuntimeException('SMTP تنظیم نشده است.');
        }

        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);

        $mailer->isSMTP();
        $mailer->Host       = $host;
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $username;
        $mailer->Password   = $password;
        $mailer->Port       = (int)$port;
        $mailer->SMTPSecure = $port === '465'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mailer->CharSet    = 'UTF-8';
        $mailer->setFrom($username, $fromName);
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);

        $mailer->Body = '
            <div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;
                 background:#f5efe4;padding:24px;">
                <div style="background:#fff;border-radius:12px;
                     padding:24px;max-width:520px;margin:0 auto;">
                    <h2 style="color:#2a5f78;margin:0 0 12px;">' .
            htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '
                    </h2>
                    <p style="color:#23303a;font-size:14px;line-height:2;">' .
            nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '
                    </p>
                    <hr style="border:none;border-top:1px solid #eee;margin:16px 0;">
                    <small style="color:#999;">تولیدی جوراب رابطی — خرید عمده، مستقیم از تولیدکننده</small>
                </div>
            </div>';

        $mailer->AltBody = strip_tags($body);

        $mailer->send();
    }
}
