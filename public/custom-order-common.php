<?php
/* ════ RABETI-CUSTOM-ORDER | نسخه: v5 | 2026-10-30 | جدیدترین نسخه (LATEST) ════ */

/**
 * custom-order-common.php — توابع مشترک ماژول سفارش اختصاصی (v5)
 * محل نصب: کنار custom-order.php در ریشه سایت (public)
 * نیازمندی: قبل از include آن، bootstrap و config/database.php لود شده باشد.
 *
 * ═══ تغییرات v5 نسبت به v4 (مهم — بازطراحی کامل روال) ═══
 *   • ⭐ روال جدید ۱۱ مرحله‌ای مطابق فرایند واقعی کارگاه:
 *       ثبت درخواست → بررسی و پیش‌فاکتور (ادمین) → پیش‌پرداخت ۳۰٪ →
 *       تولید نمونه → ارسال نمونه → تایید نمونه (مشتری) →
 *       تسویه مابه‌التفاوت → تولید انبوه → کنترل کیفیت → ارسال → تحویل + فاکتور نهایی
 *   • ⭐ توافق‌نامه و کد ملی به مرحله‌ی «اختیاری» تبدیل شد (ادمین در صورت نیاز با
 *       «پرش به مرحله» فعال می‌کند؛ در زنجیره‌ی پیش‌فرض نیست)
 *   • ⭐ توابع کمکی: co_compute_down_payment / co_compute_settlement /
 *       co_generate_proforma_number / co_generate_final_invoice_number / co_setting
 *   • ⭐ حذف مراحل legacy: expert_contact / shipping_choice / final_payment از زنجیره‌ی اصلی
 *       (مرحله‌های قدیمی هنوز معتبرند تا سفارش‌های قبلی نمایش داده شوند)
 *
 * تغییرات v3/v4 (حفظ شده): CSRF اختصاصی، تشخیص customer_id سایت،
 *   co_claim_order، co_find_order_for_user، co_user_profile، co_unclaimed_orders_by_mobile
 */

declare(strict_types=1);

if (!function_exists('co_stages')) {

    /* ───────── CSRF اختصاصی ماژول (همیشه کار می‌کند) ───────── */

    /** توکن CSRF ماژول را برمی‌گرداند (در سشن نگه داشته می‌شود) */
    function co_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['co_csrf_token']) || !is_string($_SESSION['co_csrf_token'])) {
            $_SESSION['co_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['co_csrf_token'];
    }

    /** فیلد hidden فرم — جایگزین csrf_field() */
    function co_csrf_field(): string
    {
        return '<input type="hidden" name="co_csrf_token" value="' . co_csrf_token() . '">';
    }

    /** بررسی توکن — ناموفق: RuntimeException با کد CO_CSRF */
    function co_verify_csrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $sent = (string)($_POST['co_csrf_token'] ?? '');
        $kept = co_csrf_token();
        if ($sent === '' || !hash_equals($kept, $sent)) {
            throw new RuntimeException('CO_CSRF');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       تعریف روال جدید — ۱۱ مرحله‌ی استاندارد (v5)
       مطابق فرایند واقعی: ثبت → تایید و پیش‌فاکتور → پیش‌پرداخت ۳۰٪ →
       نمونه (تولید/ارسال/تایید) → تسویه → تولید انبوه → کنترل کیفیت →
       ارسال → تحویل + فاکتور نهایی
    ═══════════════════════════════════════════════════════════════ */
    function co_stages(): array
    {
        return [
            'submitted'         => ['ثبت درخواست',          'درخواست شما در سیستم ثبت شد',                                                              'customer'],
            'admin_review'      => ['بررسی و پیش‌فاکتور',    'ادمین بررسی می‌کند، مبلغ کل و پیش‌فاکتور را صادر و درصد پیش‌پرداخت را اعلام می‌کند',          'admin'],
            'down_payment'      => ['پیش‌پرداخت (۳۰٪)',      'پس از تایید، سهم پیش‌پرداخت را واریز و رسید را بارگذاری کنید تا تولید نمونه آغاز شود',     'customer'],
            'sample_production' => ['تولید نمونه',           'پس از تایید پیش‌پرداخت، نمونه‌ی کار تولید می‌شود',                                          'admin'],
            'sample_sent'       => ['ارسال نمونه',           'نمونه برای شما ارسال شد — منتظر تایید شما',                                               'admin'],
            'sample_approval'   => ['تایید نمونه',           'نمونه را بررسی و تایید کنید یا درخواست اصلاح بدهید',                                       'customer'],
            'settlement'        => ['تسویه مابه‌التفاوت',     'پس از تایید نمونه، مابقی مبلغ را تسویه کنید تا تولید انبوه آغاز شود',                     'customer'],
            'production'        => ['تولید انبوه',           'سفارش وارد خط تولید شد',                                                                  'admin'],
            'quality_control'   => ['کنترل کیفیت',           'محصولات از نظر کیفیت کنترل می‌شوند',                                                       'admin'],
            'shipped'           => ['ارسال شد',              'سفارش ارسال شد — موفق و مؤید باشید',                                                       'admin'],
            'delivered'         => ['تحویل و فاکتور نهایی',  'سفارش تحویل شد و فاکتور نهایی صادر گردید',                                                 'admin'],
        ];
    }

    /** مرحله‌های کمکی (اختیاری — در زنجیره‌ی پیش‌فرض نیستند) */
    function co_auxiliary_stages(): array
    {
        return [
            'agreement' => ['امضای توافق‌نامه (اختیاری)', 'در صورت نیاز، ادمین فعال می‌کند', 'customer'],
            'documents' => ['بررسی مدارک (اختیاری)',     'در صورت نیاز، ادمین فعال می‌کند', 'customer'],
        ];
    }

    /** مرحله‌ی بعدی در جریان عادی (فقط روی زنجیره‌ی اصلی) */
    function co_next_stage(string $stage): ?string
    {
        $keys = array_keys(co_stages());
        $i = array_search($stage, $keys, true);
        if ($i === false || $i === count($keys) - 1) {
            return null;
        }
        return $keys[$i + 1];
    }

    /** آیا مرحله معتبر است؟ (اصلی + کمکی + پایانی) */
    function co_stage_valid(string $stage): bool
    {
        return array_key_exists($stage, co_stages())
            || array_key_exists($stage, co_auxiliary_stages())
            || in_array($stage, ['rejected', 'cancelled'], true);
    }

    /** برچسب نمایشی مرحله (اصلی/کمکی/پایانی/legacy) */
    function co_stage_label(string $stage): string
    {
        $main = co_stages();
        if (isset($main[$stage])) return $main[$stage][0];
        $aux = co_auxiliary_stages();
        if (isset($aux[$stage])) return $aux[$stage][0];
        if ($stage === 'rejected')  return 'رد شده';
        if ($stage === 'cancelled') return 'لغو شده';
        // legacy
        $legacy = [
            'expert_contact'  => 'تماس کارشناس (قدیمی)',
            'shipping_choice' => 'انتخاب ارسال (قدیمی)',
            'final_payment'   => 'پرداخت نهایی (قدیمی)',
        ];
        return $legacy[$stage] ?? $stage;
    }

    /* ───────── اعداد فارسی → انگلیسی ───────── */
    function co_en_digits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /* ───────── تشخیص پیام خطای «جدول ساخته نشده» ───────── */
    function co_is_missing_table(Throwable $e): bool
    {
        $m = $e->getMessage();
        return stripos($m, '42S02') !== false
            || stripos($m, "doesn't exist") !== false
            || stripos($m, 'no such table') !== false;
    }

    /* ───────── اعتبارسنجی کد ملی ایران (چک‌دیجیت ۱۰ رقم) ───────── */
    function co_valid_national_code(string $code): bool
    {
        $code = co_en_digits(trim($code));
        if (!preg_match('/^\d{10}$/', $code)) return false;
        if (preg_match('/^(\d)\1{9}$/', $code)) return false;
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$code[$i] * (10 - $i);
        }
        $rem  = $sum % 11;
        $ctrl = (int)$code[9];
        return ($rem < 2) ? ($ctrl === $rem) : ($ctrl === 11 - $rem);
    }

    /* ───────── تولید کد پیگیری یکتا: CR-00001-X7QF ───────── */
    function co_tracking_code(int $id): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $rand = '';
        for ($i = 0; $i < 8; $i++) {
            $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'CR-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT) . '-' . $rand;
    }

    /* ───────── ثبت رویداد تایم‌لاین ───────── */
    function co_insert_event(int $orderId, string $stageKey, string $actor, ?string $note = null): void
    {
        $stmt = db()->prepare(
            'INSERT INTO custom_order_events (custom_order_id, stage_key, actor, note)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $stageKey, $actor, $note !== null ? mb_substr($note, 0, 500) : null]);
    }

    /* ───────── تغییر مرحله + رویداد (یک تراکنش منطقی) ───────── */
    function co_set_stage(int $orderId, string $newStage, string $actor = 'system', ?string $note = null): bool
    {
        $db = db();
        $stmt = $db->prepare("SELECT status FROM custom_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $currentStage = $stmt->fetchColumn();
        if ($currentStage === $newStage) {
            return false; // جلوگیری از ثبت تکراری
        }
        $db->prepare("UPDATE custom_orders SET status = ?, status_changed_at = NOW() WHERE id = ?")
            ->execute([$newStage, $orderId]);
        co_insert_event($orderId, $newStage, $actor, $note);
        return true;
    }

    /* ───────── آپلود امن تصویر (فقط jpg/png/webp تا 4MB) ───────── */
    function co_handle_upload(array $file, string $subDir, string $prefix): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'path' => null, 'error' => null];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => null, 'error' => 'خطا در دریافت فایل (کد ' . (int)$file['error'] . ')'];
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > 4 * 1024 * 1024) {
            return ['ok' => false, 'path' => null, 'error' => 'حجم فایل باید کمتر از ۴ مگابایت باشد'];
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'path' => null, 'error' => 'فایل ارسالی تصویر معتبر نیست'];
        }
        $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowedMime[$info['mime']])) {
            return ['ok' => false, 'path' => null, 'error' => 'فقط JPG یا PNG یا WebP پذیرفته می‌شود'];
        }
        $dir = __DIR__ . '/uploads/custom-orders/' . preg_replace('/[^A-Za-z0-9\-]/', '', $subDir);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'path' => null, 'error' => 'ساخت پوشه آپلود ممکن نشد'];
        }

        /* ⭐ v5.1: قفل اجرای اسکریپت در پوشه آپلود (.htaccess یک‌بار برای کل پوشه ساخته می‌شود)
           جلوگیری از اجرای PHP در فایل‌های آپلودی حتی اگر پسوند جعلی باشند.
           توجه: php_flag عمداً گذاشته نشده تا زیر PHP-FPM خطای ۵۰۰ ندهد؛
           به‌جای آن RemoveHandler/RemoveType + FilesMatch استفاده شده است. */
        $htaccess = __DIR__ . '/uploads/custom-orders/.htaccess';
        if (!is_file($htaccess)) {
            $rules = "# RABETI custom-orders uploads — no script execution\n"
                . "Options -Indexes\n"
                . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .phar .pl .py .cgi\n"
                . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps .phar .pl .py .cgi\n"
                . "<FilesMatch \"\\.(?i:php|phtml|php3|php4|php5|php7|phps|phar|pl|py|cgi|sh|asp|aspx|jsp)$\">\n"
                . "  Require all denied\n"
                . "</FilesMatch>\n";
            @file_put_contents($htaccess, $rules);
        }

        $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $allowedMime[$info['mime']];
        $dest = $dir . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'path' => null, 'error' => 'ذخیره فایل ممکن نشد — دوباره تلاش کنید'];
        }
        return ['ok' => true, 'path' => 'uploads/custom-orders/' . basename($dir) . '/' . $name, 'error' => null];
    }

    /* ═══════════════════════════════════════════════════════════════
       توابع کمکی مالی v5 — پیش‌فاکتور، پیش‌پرداخت، تسویه
    ═══════════════════════════════════════════════════════════════ */

    /** خواندن یک تنظیم از جدول custom_order_settings (با کش ساکن) */
    function co_setting(string $key, $default = null)
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $stmt = db()->prepare("SELECT setting_value, setting_type FROM custom_order_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) { $cache[$key] = $default; return $default; }
            $val = $row['setting_value'];
            $val = match ($row['setting_type']) {
                'int'     => (int)$val,
                'decimal' => (float)$val,
                'bool'    => (bool)(int)$val,
                'json'    => json_decode((string)$val, true) ?: [],
                default   => $val,
            };
            $cache[$key] = $val;
            return $val;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /** درصد پیش‌فرض پیش‌پرداخت (از تنظیمات، پیش‌فرض ۳۰) */
    function co_default_down_payment_percent(): float
    {
        return (float)co_setting('default_down_payment_percent', 30.0);
    }

    /** محاسبه‌ی مبلغ پیش‌پرداخت از مبلغ کل و درصد (رُند به تومان — بدون اعشار) */
    function co_compute_down_payment(float $total, float $percent): float
    {
        if ($total <= 0 || $percent <= 0) return 0.0;
        return (float)round($total * $percent / 100.0, 0);
    }

    /** محاسبه‌ی مبلغ تسویه (مابقی = کل - پیش‌پرداخت پرداخت‌شده) — رُند به تومان */
    function co_compute_settlement(float $total, float $downPaid): float
    {
        $s = $total - $downPaid;
        return $s > 0 ? (float)round($s, 0) : 0.0;
    }

    /** شماره پیش‌فاکتور یکتا: PF-CO-YYMMDD-NNNNN */
    function co_generate_proforma_number(int $orderId): string
    {
        $prefix = (string)co_setting('proforma_prefix', 'PF-CO-');
        return $prefix . date('ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
    }

    /** شماره فاکتور نهایی یکتا: INV-CO-YYMMDD-NNNNN */
    function co_generate_final_invoice_number(int $orderId): string
    {
        $prefix = (string)co_setting('final_invoice_prefix', 'INV-CO-');
        return $prefix . date('ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
    }

    /* ───────── یافتن سفارش با کد پیگیری + موبایل (پیگیری مهمان) ───────── */
    function co_find_order(string $code, string $mobile): ?array
    {
        $code   = strtoupper(trim($code));
        $mobile = co_en_digits(trim($mobile));
        if ($code === '' || !preg_match('/^09\d{9}$/', $mobile)) return null;
        $stmt = db()->prepare('SELECT * FROM custom_orders WHERE tracking_code = ? AND mobile = ? LIMIT 1');
        $stmt->execute([$code, $mobile]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** یافتن سفارش برای کاربر لاگین (کد + user_id، بعد مسیر مهمان) */
    function co_find_order_for_user(string $code, string $mobile, ?int $uid): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') return null;
        if ($uid !== null && $uid > 0) {
            try {
                $stmt = db()->prepare('SELECT * FROM custom_orders WHERE tracking_code = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$code, $uid]);
                $row = $stmt->fetch();
                if ($row !== false) return $row;
            } catch (Throwable $e) {}
        }
        return co_find_order($code, $mobile);
    }

    /** اتصال سفارش مهمان به حساب کاربری (ضد race — با شرط اتمیک و rowCount) */
    function co_claim_order(int $orderId, int $uid): bool
    {
        if ($uid <= 0) return false;
        try {
            $stmt = db()->prepare('SELECT user_id, status FROM custom_orders WHERE id = ? LIMIT 1');
            $stmt->execute([$orderId]);
            $row = $stmt->fetch();
            if ($row === false) return false;
            if ($row['user_id'] !== null && (int)$row['user_id'] === $uid) return true; // قبلاً متصل شده
            if ($row['user_id'] !== null) return false; // متعلق به دیگری است

            // ⭐ v5.1: UPDATE اتمیک با شرط user_id IS NULL — در race، فقط اولین درخواست می‌برد
            $upd = db()->prepare('UPDATE custom_orders SET user_id = ? WHERE id = ? AND user_id IS NULL');
            $upd->execute([$uid, $orderId]);
            if ($upd->rowCount() === 0) {
                // کسی دیگر زودتر claim کرده — وضعیت فعلی را دوباره بخوان
                $chk = db()->prepare('SELECT user_id FROM custom_orders WHERE id = ? LIMIT 1');
                $chk->execute([$orderId]);
                $nowOwner = $chk->fetchColumn();
                return ($nowOwner !== false && $nowOwner !== null && (int)$nowOwner === $uid);
            }

            $stageKey = co_stage_valid((string)$row['status']) ? (string)$row['status'] : 'submitted';
            co_insert_event($orderId, $stageKey, 'customer', 'این سفارش به حساب کاربری شما متصل شد');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ───────── محدودیت تعداد جست‌وجوی پیگیری (ضد حدس‌زدن) ───────── */
    function co_rate_limit_ok(int $max = 10, int $windowSec = 600): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $now = time();
        $_SESSION['co_lookups'] = array_values(array_filter(
            (array)($_SESSION['co_lookups'] ?? []),
            static fn($t) => is_int($t) && ($now - $t) < $windowSec
        ));
        if (count($_SESSION['co_lookups']) >= $max) return false;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (function_exists('rate_limit_exceeded') && rate_limit_exceeded('co_lookup', $ip, $max * 3, $windowSec)) {
            return false;
        }
        $_SESSION['co_lookups'][] = $now;
        if (function_exists('rate_limit_hit')) rate_limit_hit('co_lookup', $ip);
        return true;
    }

    /* ───────── اتصال کاربر لاگین‌شده (سازگار با customer_id سایت) ───────── */
    function co_current_user_id(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) return null;
        if (function_exists('is_customer_logged_in')) {
            try {
                if (is_customer_logged_in()) {
                    $v = $_SESSION['customer_id'] ?? ($_SESSION['user_id'] ?? null);
                    if (!empty($v) && is_numeric($v)) return (int)$v;
                }
            } catch (Throwable $e) {}
        }
        foreach (['customer_id', 'user_id', 'uid', 'member_id', 'account_id'] as $k) {
            if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
                return (int)$_SESSION[$k];
            }
        }
        return null;
    }

    /** پروفایل کاربر (نام/موبایل) برای پیش‌پرکردن فرم */
    function co_user_profile(?int $uid): array
    {
        $name   = '';
        $mobile = '';
        if (session_status() !== PHP_SESSION_NONE) {
            $name   = (string)($_SESSION['customer_name']   ?? $_SESSION['user_name']   ?? '');
            $mobile = (string)($_SESSION['customer_mobile'] ?? $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? '');
        }
        if ($uid !== null && $uid > 0) {
            try {
                $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$uid]);
                $u = $stmt->fetch();
                if ($u !== false) {
                    $name   = trim((string)($u['name'] ?? $u['full_name'] ?? $u['customer_name'] ?? $name));
                    $mobile = trim((string)($u['mobile'] ?? $u['phone'] ?? $u['mobile_number'] ?? $mobile));
                }
            } catch (Throwable $e) {}
        }
        return ['name' => $name, 'mobile' => co_en_digits($mobile)];
    }

    /** سفارش‌های مهمانِ بدون مالک با همین موبایل (پیشنهاد اتصال) */
    function co_unclaimed_orders_by_mobile(string $mobile, int $limit = 5): array
    {
        $mobile = co_en_digits(trim($mobile));
        if (!preg_match('/^09\d{9}$/', $mobile)) return [];
        try {
            $stmt = db()->prepare(
                'SELECT id, tracking_code, status, created_at FROM custom_orders
                 WHERE user_id IS NULL AND mobile = ?
                 ORDER BY id DESC LIMIT ' . (int)$limit
            );
            $stmt->execute([$mobile]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /* ───────── خواندن سفارش + آیتم‌ها + رویدادها ───────── */
    function co_load_order_full(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM custom_orders WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if ($order === false) return null;

        $stmt = db()->prepare(
            'SELECT product_type, color, qty, notes FROM custom_order_items
             WHERE custom_order_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        $stmt = db()->prepare(
            'SELECT stage_key, actor, note, created_at FROM custom_order_events
             WHERE custom_order_id = ? ORDER BY created_at, id'
        );
        $stmt->execute([$id]);
        $order['events'] = $stmt->fetchAll();

        return $order;
    }

    /* ───────── تاریخ فارسی سبک ───────── */
    function co_fa_date(?string $mysqlDatetime): string
    {
        if (!$mysqlDatetime) return '—';
        try {
            $ts = strtotime($mysqlDatetime);
            if ($ts === false) return '—';
            if (function_exists('jdate')) return jdate('Y/m/d H:i', $ts);
            return date('Y/m/d H:i', $ts);
        } catch (Throwable $e) {
            return '—';
        }
    }

    /* ───────── قالب‌بندی مبلغ تومانی فارسی ───────── */
    function co_fa_money($amount): string
    {
        $n = (float)($amount ?? 0);
        if ($n <= 0) return '—';
        return number_format($n) . ' تومان';
    }
}
