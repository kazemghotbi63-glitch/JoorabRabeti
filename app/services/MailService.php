<?php

declare(strict_types=1);

final class MailService
{
    /*
    |--------------------------------------------------------------------------
    | MODE = 'log'  → حالت تست: کد در storage/logs/mail.log ذخیره می‌شود
    | MODE = 'mail' → ارسال واقعی با تابع mail() (نیاز به کانفیگ سرور)
    |--------------------------------------------------------------------------
    */

    private const MODE       = 'log';
    private const FROM_EMAIL = 'no-reply@rabeti.ir';
    private const FROM_NAME  = 'رابطی';

    public static function sendResetCode(string $toEmail, string $code): bool
    {
        $subject = 'کد بازیابی رمز عبور — رابطی';
        $body    = self::renderResetBody($code);

        return match (self::MODE) {
            'mail'  => self::viaMail($toEmail, $subject, $body),
            default => self::logToFile($toEmail, $code),
        };
    }

    private static function renderResetBody(string $code): string
    {
        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<body style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;background:#f2f3f5;padding:24px;">
    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;border:1px solid #e5e7eb;">
        <h2 style="color:#183236;margin:0 0 12px;">بازیابی رمز عبور</h2>
        <p style="color:#65777a;font-size:13px;line-height:1.9;">
            کد بازیابی رمز عبور شما در فروشگاه رابطی:
        </p>
        <div style="text-align:center;margin:18px 0;">
            <span style="display:inline-block;font-size:26px;font-weight:800;letter-spacing:8px;color:#005a5e;background:#f3faf9;border:1px solid #dfeceb;border-radius:12px;padding:12px 22px;direction:ltr;">{$code}</span>
        </div>
        <p style="color:#98a4a8;font-size:11px;line-height:1.8;">
            این کد ۱۰ دقیقه اعتبار دارد. اگر شما درخواست نداده‌اید، این ایمیل را نادیده بگیرید.
        </p>
    </div>
</body>
</html>
HTML;
    }

    private static function viaMail(string $to, string $subject, string $html): bool
    {
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::FROM_NAME . ' <' . self::FROM_EMAIL . '>',
        ]);

        return mail($to, $subject, $html, $headers);
    }

    private static function logToFile(string $to, string $code): bool
    {
        $dir = ROOT_PATH . '/storage/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $dir . '/mail.log',
            date('Y-m-d H:i:s') . " | RESET | {$to} | {$code}\n",
            FILE_APPEND
        );

        return true;
    }
}