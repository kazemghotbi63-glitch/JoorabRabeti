<?php
declare(strict_types=1);

/* ════ RABETI-CUSTOM-ORDER | نسخه: v5 (admin) | 2026-10-30 | جدیدترین نسخه (LATEST) ════ */

/**
 * مدیریت سفارش‌های اختصاصی — پنل ادمین v5
 * URL:  /admin/custom-orders.php (یا مسیر فعلی شما)
 *
 * ═══ تغییرات v5 نسبت به v4 ═══
 *   • ⭐ قفل اتمیک: همه‌ی اکشن‌های POST داخل تراکنش با SELECT ... FOR UPDATE اجرا می‌شوند
 *     (جلوگیری از race در دابل‌کلیک/رفرش)
 *   • ⭐ Idempotency guard در هر اکشن: اگر اثر انگشت (proforma_number / sample_sent_at /
 *     shipped_at / final_invoice_number / *_paid_at ...) قبلاً ثبت شده، دوباره بازنویسی نمی‌شود
 *   • ⭐ set_stage: اول چک $to === $st و بعد پاک‌سازی فایل‌ها (دیگر فایل‌ها را بیهوده پاک نمی‌کند)
 *   • ⭐ set_payment: فقط تا قبل از تایید پرداخت مجاز است
 *   • ⭐ دکمه‌ی «لغو سفارش (توافقی)» جدا از «رد سفارش»
 *   • ⭐ لینک چاپ پیش‌فاکتور / فاکتور نهایی (custom-invoice-print.php)
 *   • ⭐ عنوان دقیق disabled روی دکمه‌ی «مرحله بعد» برای هر مرحله
 *   • ⭐ جدول لیست: total_qty ذخیره‌شده یا محاسبه‌شده (COALESCE) + ظرف اسکرول افقی
 */
const CO_ADMIN_KEY = '';

/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
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

/* فایل مشترک ماژول سفارش اختصاصی — اگر موجود نباشد به‌جای Fatal Error
   (صفحهٔ سفید) یک پیام خطای تمیز و قابل‌فهم نمایش داده می‌شود. */
$__coCommon = dirname(__DIR__) . '/custom-order-common.php';
if (!is_file($__coCommon)) {
    require_once ROOT_PATH . '/config/auth.php';
    require_admin();
    $activeMenu = 'custom';
    $pageTitle  = 'سفارش اختصاصی — خطا';
    ob_start();
    echo '<div class="form-alert error" style="display:block;">'
        . '⚠️ فایل <code>public/custom-order-common.php</code> (کتابخانهٔ مشترک ماژول سفارش اختصاصی) '
        . 'در پروژه پیدا نشد. این فایل در بستهٔ ارسالی موجود نبوده — اگر روی سرور اصلی موجود است، '
        . 'کنار همین فایل قرار دهید؛ در غیر این صورت نسخهٔ کامل ماژول را از توسعه‌دهنده بگیرید.'
        . '</div>';
    $content = ob_get_clean();
    require ROOT_PATH . '/views/layouts/admin.php';
    exit;
}
require_once $__coCommon;
unset($__coCommon);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ───────── گارد ادمین ───────── */
$coAdmin = false;
if (function_exists('is_admin')) {
    try { $coAdmin = (bool)is_admin(); } catch (Throwable $e) { $coAdmin = false; }
} elseif (function_exists('current_user_is_admin')) {
    try { $coAdmin = (bool)current_user_is_admin(); } catch (Throwable $e) { $coAdmin = false; }
}
if (!$coAdmin) {
    $role = strtolower((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
    $coAdmin = in_array($role, ['admin', 'superadmin', '1', 'manager'], true)
        || !empty($_SESSION['admin_id'])
        || !empty($_SESSION['is_admin'])
        || !empty($_SESSION['admin_logged_in']);
}
if (!$coAdmin && CO_ADMIN_KEY !== '' && isset($_GET['key']) && hash_equals(CO_ADMIN_KEY, (string)$_GET['key'])) {
    $coAdmin = true;
    $_SESSION['co_admin_unlocked'] = 1;
}
if (!$coAdmin && !empty($_SESSION['co_admin_unlocked'])) {
    $coAdmin = true;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if (!$coAdmin) {
    http_response_code(403);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">';
    echo '<title>دسترسی مجاز نیست</title>';
    echo '<body style="font-family:Tahoma;padding:40px;line-height:2">';
    echo '<h2>⛔ دسترسی مجاز نیست</h2>';
    echo '<p>این صفحه فقط برای ادمین است. اگر ادمین هستید:</p>';
    echo '<ol><li>وارد حساب ادمین سایت شوید و دوباره برگردید، یا</li>';
    echo '<li>در بالای فایل <code>custom-orders.php</code> مقدار <code>CO_ADMIN_KEY</code> را تنظیم کنید و با <code>?key=...</code> باز کنید.</li></ol>';
    echo '</body></html>';
    exit;
}

/* ───────── داده‌ها ───────── */
$stages = co_stages();
$msg = '';

/* ───────── فیلترها ───────── */
$fStatus = (string)($_GET['status'] ?? '');
$fQ      = trim((string)($_GET['q'] ?? ''));
$viewId  = (int)($_GET['view'] ?? 0);

/* ═══════════════════════════════════════════════════════════════
   دیسپچر اکشن‌های ادمین — داخل تراکنش باز اجرا می‌شود
   ($cur با SELECT ... FOR UPDATE قفل شده است)
═══════════════════════════════════════════════════════════════ */
function co_admin_dispatch(string $action, array $cur, int $oid, string $note): string
{
    $st = (string)$cur['status'];

    /* لیست مراحلی که لغو/رد در آن‌ها مجاز است (تا قبل از تولید انبوه) */
    $canCancel = in_array($st, ['submitted','admin_review','down_payment','sample_production','sample_sent','sample_approval','settlement','agreement','documents'], true);

    switch ($action) {

        /* ── تایید سفارش + صدور پیش‌فاکتور (با گارد idempotency) ── */
        case 'approve_order':
            if (!empty($cur['proforma_number']) || !in_array($st, ['submitted','admin_review'], true)) {
                return 'این سفارش قبلاً تایید شده و پیش‌فاکتور برایش صادر شده است — کلیک/رفرش تکراری نادیده گرفته شد.';
            }
            $total   = (float)str_replace([',', ' '], '', co_en_digits((string)($_POST['total_amount'] ?? '0')));
            $percent = (float)str_replace([',', ' '], '', co_en_digits((string)($_POST['down_payment_percent'] ?? (string)co_default_down_payment_percent())));
            if ($total <= 0) return 'مبلغ کل معتبر وارد کنید.';
            if ($percent <= 0 || $percent > 100) return 'درصد پیش‌پرداخت باید بین ۱ تا ۱۰۰ باشد.';

            $downAmt  = co_compute_down_payment($total, $percent);
            $proforma = co_generate_proforma_number($oid);

            db()->prepare(
                "UPDATE custom_orders SET
                    total_amount = ?, down_payment_percent = ?, down_payment_amount = ?,
                    proforma_number = ?, proforma_issued_at = NOW(),
                    admin_approved_at = NOW(), admin_note = ?
                 WHERE id = ?"
            )->execute([$total, $percent, $downAmt, $proforma, $note !== '' ? $note : null, $oid]);

            co_insert_event($oid, 'admin_review', 'admin',
                'سفارش تایید شد — مبلغ کل: ' . number_format($total) . ' تومان، '
                . 'پیش‌پرداخت ' . $percent . '٪ = ' . number_format($downAmt) . ' تومان'
                . ' — پیش‌فاکتور ' . $proforma
                . ($note !== '' ? ' — ' . $note : ''));

            co_set_stage($oid, 'down_payment', 'admin', null);
            return 'سفارش تایید شد و پیش‌فاکتور صادر گردید. مرحله: پیش‌پرداخت.';

        /* ── ثبت/اصلاح مبلغ پیش‌پرداخت (فقط تا قبل از تایید پرداخت) ── */
        case 'set_payment':
            if (!in_array($st, ['admin_review', 'down_payment'], true)) {
                return 'ثبت مبلغ پیش‌پرداخت فقط در مراحل بررسی/پیش‌پرداخت مجاز است.';
            }
            if (!empty($cur['down_payment_paid_at'])) {
                return 'پیش‌پرداخت این سفارش تایید شده است — برای تغییر مبلغ، نخست مرحله را به قبل برگردانید.';
            }
            $amount = (float)str_replace([',', ' '], '', co_en_digits((string)($_POST['amount'] ?? '0')));
            if ($amount <= 0) return 'مبلغ معتبر وارد کنید.';
            db()->prepare('UPDATE custom_orders SET down_payment_amount = ? WHERE id = ?')->execute([$amount, $oid]);
            co_insert_event($oid, 'down_payment', 'admin', 'مبلغ پیش‌پرداخت ثبت/اصلاح شد: ' . number_format($amount) . ' تومان');
            return 'مبلغ پیش‌پرداخت ثبت شد.';

        /* ── تایید پیش‌پرداخت → تولید نمونه ── */
        case 'confirm_down_payment':
            if ($st !== 'down_payment') return 'تایید پیش‌پرداخت فقط در مرحله «پیش‌پرداخت» مجاز است.';
            if (!empty($cur['down_payment_paid_at'])) return 'پیش‌پرداخت این سفارش قبلاً تایید شده است.';
            if ($cur['down_payment_amount'] === null) return 'مبلغ پیش‌پرداخت هنوز ثبت نشده است.';
            if (empty($cur['down_payment_receipt_path'])) return 'رسید پرداخت مشتری هنوز آپلود نشده است.';

            db()->prepare('UPDATE custom_orders SET down_payment_paid_at = NOW(), payment_method = ? WHERE id = ?')
                ->execute([($_POST['method'] ?? 'manual') === 'gateway' ? 'gateway' : 'manual', $oid]);
            co_set_stage($oid, 'sample_production', 'admin', $note !== '' ? $note : 'پیش‌پرداخت تایید شد — تولید نمونه آغاز شد');
            return 'پیش‌پرداخت تایید شد؛ سفارش به تولید نمونه رفت.';

        /* ── ثبت ارسال نمونه (با گارد idempotency) ── */
        case 'send_sample':
            if ($st !== 'sample_production') return 'ثبت ارسال نمونه فقط در مرحله «تولید نمونه» مجاز است.';
            if (!empty($cur['sample_sent_at'])) return 'ارسال نمونه این سفارش قبلاً ثبت شده است.';
            $carrier = trim((string)($_POST['sample_carrier'] ?? ''));
            $track   = trim((string)($_POST['sample_tracking_code'] ?? ''));
            if ($carrier === '' || $track === '') return 'نام شرکت حمل و کد رهگیری نمونه را وارد کنید.';

            db()->prepare(
                "UPDATE custom_orders SET
                    sample_carrier = ?, sample_tracking_code = ?,
                    sample_sent_at = NOW(), sample_status = 'pending'
                 WHERE id = ?"
            )->execute([$carrier, $track, $oid]);
            co_set_stage($oid, 'sample_sent', 'admin',
                'نمونه ارسال شد — ' . $carrier . ' / کد رهگیری ' . $track
                . ($note !== '' ? ' — ' . $note : ''));
            return 'ارسال نمونه ثبت شد؛ منتظر تایید مشتری.';

        /* ── درخواست اصلاح نمونه (برگشت به تولید نمونه) ── */
        case 'request_revision':
            if (!in_array($st, ['sample_sent', 'sample_approval'], true)) {
                return 'درخواست اصلاح فقط در مراحل ارسال/تایید نمونه مجاز است.';
            }
            db()->prepare("UPDATE custom_orders SET sample_status = 'revision_requested', sample_revision_note = ? WHERE id = ?")
                ->execute([$note !== '' ? $note : 'درخواست اصلاح نمونه توسط ادمین', $oid]);
            co_set_stage($oid, 'sample_production', 'admin', 'برگشت به تولید نمونه برای اصلاح' . ($note !== '' ? ' — ' . $note : ''));
            return 'سفارش برای اصلاح نمونه به تولید برگشت.';

        /* ── صدور دستور تسویه (پس از تایید نمونه توسط مشتری) ── */
        case 'issue_settlement':
            if ($st !== 'sample_approval') return 'صدور دستور تسویه فقط در مرحله «تایید نمونه» مجاز است.';
            if (!empty($cur['settlement_order_at'])) return 'دستور تسویه این سفارش قبلاً صادر شده است.';
            if (($cur['sample_status'] ?? '') !== 'approved') return 'مشتری هنوز نمونه را تایید نکرده است.';

            $total    = (float)($cur['total_amount'] ?? 0);
            $downPaid = (float)($cur['down_payment_amount'] ?? 0);
            $setAmt   = co_compute_settlement($total, $downPaid);
            if ($setAmt <= 0) return 'مبلغ تسویه صفر است — مبلغ کل یا پیش‌پرداخت را بررسی کنید.';

            db()->prepare("UPDATE custom_orders SET settlement_amount = ?, settlement_order_at = NOW() WHERE id = ?")
                ->execute([$setAmt, $oid]);
            co_set_stage($oid, 'settlement', 'admin',
                'دستور تسویه صادر شد — مابه‌التفاوت: ' . number_format($setAmt) . ' تومان'
                . ($note !== '' ? ' — ' . $note : ''));
            return 'دستور تسویه صادر شد؛ منتظر پرداخت مشتری.';

        /* ── تایید تسویه → تولید انبوه ── */
        case 'confirm_settlement':
            if ($st !== 'settlement') return 'تایید تسویه فقط در مرحله «تسویه» مجاز است.';
            if (!empty($cur['settlement_paid_at'])) return 'تسویه این سفارش قبلاً تایید شده است.';
            if ($cur['settlement_amount'] === null) return 'دستور تسویه هنوز صادر نشده (مبلغ تسویه ثبت نیست).';
            if (empty($cur['settlement_receipt_path'])) return 'رسید تسویه مشتری هنوز آپلود نشده است.';

            db()->prepare('UPDATE custom_orders SET settlement_paid_at = NOW() WHERE id = ?')->execute([$oid]);
            co_set_stage($oid, 'production', 'admin', $note !== '' ? $note : 'تسویه تایید شد — تولید انبوه آغاز شد');
            return 'تسویه تایید شد؛ سفارش به تولید انبوه رفت.';

        /* ── ثبت ارسال انبوه (با گارد idempotency) ── */
        case 'ship':
            if ($st !== 'quality_control') return 'ثبت ارسال فقط در مرحله «کنترل کیفیت» مجاز است.';
            if (!empty($cur['shipped_at'])) return 'ارسال این سفارش قبلاً ثبت شده است.';
            $carrier = trim((string)($_POST['shipping_carrier'] ?? ''));
            $track   = trim((string)($_POST['shipping_tracking_code'] ?? ''));
            if ($carrier === '' || $track === '') return 'نام شرکت حمل و کد رهگیری ارسال را وارد کنید.';

            db()->prepare(
                "UPDATE custom_orders SET
                    shipping_carrier = ?, shipping_tracking_code = ?, shipped_at = NOW()
                 WHERE id = ?"
            )->execute([$carrier, $track, $oid]);
            co_set_stage($oid, 'shipped', 'admin',
                'ارسال ثبت شد — ' . $carrier . ' / کد رهگیری ' . $track
                . ($note !== '' ? ' — ' . $note : ''));
            return 'ارسال ثبت شد.';

        /* ── ثبت تحویل + فاکتور نهایی (با گارد idempotency) ── */
        case 'deliver':
            if ($st !== 'shipped') return 'ثبت تحویل فقط در مرحله «ارسال شد» مجاز است.';
            if (!empty($cur['final_invoice_number'])) return 'فاکتور نهایی این سفارش قبلاً صادر شده است.';

            $invNo = trim((string)($_POST['final_invoice_number'] ?? ''));
            if ($invNo === '') $invNo = co_generate_final_invoice_number($oid);
            $invAmt = (float)str_replace([',', ' '], '', co_en_digits((string)($_POST['final_invoice_amount'] ?? (string)($cur['total_amount'] ?? 0))));
            if ($invAmt <= 0) $invAmt = (float)($cur['total_amount'] ?? 0);

            db()->prepare(
                "UPDATE custom_orders SET
                    final_invoice_number = ?, final_invoice_amount = ?,
                    final_invoice_at = NOW(), delivered_at = NOW()
                 WHERE id = ?"
            )->execute([$invNo, $invAmt, $oid]);
            co_set_stage($oid, 'delivered', 'admin',
                'تحویل ثبت شد — فاکتور نهایی ' . $invNo . ' به مبلغ ' . number_format($invAmt) . ' تومان'
                . ($note !== '' ? ' — ' . $note : ''));
            return 'تحویل ثبت شد و فاکتور نهایی صادر گردید.';

        /* ── تایید مدارک (مرحله اختیاری) ── */
        case 'verify_documents':
            if ($st !== 'documents') return 'این سفارش در مرحله بررسی مدارک نیست.';
            if (!empty($cur['documents_verified_at'])) return 'مدارک این سفارش قبلاً تایید شده است.';
            if (empty($cur['id_card_front_path']) || empty($cur['id_card_back_path'])) {
                return 'فایل‌های کارت ملی (رو و پشت) موجود نیست.';
            }
            db()->prepare('UPDATE custom_orders SET documents_verified_at = NOW(), documents_submitted_at = NOW() WHERE id = ?')->execute([$oid]);
            co_set_stage($oid, 'down_payment', 'admin', $note !== '' ? $note : 'مدارک هویتی تایید شد');
            return 'مدارک تایید شد؛ سفارش به مرحله پیش‌پرداخت رفت.';

        /* ── مرحله بعد (فقط production → quality_control) ── */
        case 'advance':
            if ($st === 'production') {
                co_set_stage($oid, 'quality_control', 'admin', $note !== '' ? $note : null);
                return 'سفارش به کنترل کیفیت رفت.';
            }
            /* عنوان دقیق برای هر مرحله — به کاربر توضیح می‌دهد چه دکمه‌ای را باید بزند */
            $why = [
                'submitted'         => 'ابتدا «تایید و صدور پیش‌فاکتور» را انجام دهید',
                'admin_review'      => 'ابتدا «تایید و صدور پیش‌فاکتور» را انجام دهید',
                'down_payment'      => 'ابتدا «تایید پیش‌پرداخت» را انجام دهید',
                'sample_production' => 'ابتدا «ثبت ارسال نمونه» را انجام دهید',
                'sample_sent'       => 'منتظر تایید نمونه توسط مشتری باشید',
                'sample_approval'   => 'ابتدا «صدور دستور تسویه» را انجام دهید',
                'settlement'        => 'ابتدا «تایید تسویه» را انجام دهید',
                'quality_control'   => 'از دکمه «ثبت ارسال انبوه» استفاده کنید',
                'shipped'           => 'از دکمه «ثبت تحویل + فاکتور نهایی» استفاده کنید',
                'delivered'         => 'آخرین مرحله — روال کامل شده است',
            ][$st] ?? 'در این مرحله از دکمه تخصصی همان مرحله استفاده کنید';
            return 'انتقال خودکار ممکن نیست: ' . $why . '.';

        /* ── پرش دستی به مرحله (امن‌شده: اول چک، بعد پاک‌سازی) ── */
        case 'set_stage':
            $to = (string)($_POST['to_stage'] ?? '');
            if (!co_stage_valid($to)) return 'مرحله مقصد نامعتبر است.';
            if ($to === $st) return 'سفارش همین حالا در این مرحله است — عملی انجام نشد.';
            /* پاک‌سازی تخریبی فقط پس از عبور از چک‌های بالا */
            if ($to === 'documents') {
                db()->prepare("UPDATE custom_orders SET id_card_front_path=NULL, id_card_back_path=NULL, documents_verified_at=NULL, documents_submitted_at=NULL WHERE id=?")->execute([$oid]);
            }
            if ($to === 'down_payment') {
                db()->prepare("UPDATE custom_orders SET down_payment_receipt_path=NULL, down_payment_paid_at=NULL WHERE id=?")->execute([$oid]);
            }
            if (co_set_stage($oid, $to, 'admin', $note !== '' ? $note : 'تغییر دستی مرحله توسط ادمین')) {
                return 'مرحله تغییر کرد.';
            }
            return 'تغییر مرحله انجام نشد.';

        case 'reject':
            if (!$canCancel) return 'رد سفارش در این مرحله مجاز نیست.';
            co_set_stage($oid, 'rejected', 'admin', $note !== '' ? $note : 'بدون توضیح');
            return 'سفارش رد شد.';

        /* ── لغو سفارش (توافقی) — جدا از رد ── */
        case 'cancel':
            if (!$canCancel) return 'لغو سفارش در این مرحله مجاز نیست — با مشتری هماهنگ کنید.';
            co_set_stage($oid, 'cancelled', 'admin', $note !== '' ? $note : 'لغو توافقی توسط ادمین');
            return 'سفارش لغو شد.';

        case 'note':
            if ($note === '') return '';
            co_insert_event($oid, $st, 'admin', $note);
            return 'یادداشت ثبت شد.';
    }
    return '';
}

/* ═══════════ اکشن‌های ادمین (POST) — هر اکشن داخل تراکنش FOR UPDATE ═══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postViewId = (int)($_POST['view_id'] ?? $viewId);
    try {
        co_verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        $oid    = (int)($_POST['order_id'] ?? 0);
        $note   = trim((string)($_POST['note'] ?? ''));

        if ($oid > 0 && $action !== '') {
            $db = db();
            $db->beginTransaction();
            try {
                /* ⭐ قفل انحصاری روی ردیف سفارش تا پایان تراکنش */
                $stmt = $db->prepare('SELECT * FROM custom_orders WHERE id = ? FOR UPDATE');
                $stmt->execute([$oid]);
                $cur = $stmt->fetch();
                if ($cur === false) {
                    $db->rollBack();
                    $msg = 'سفارش یافت نشد.';
                } else {
                    $msg = co_admin_dispatch($action, $cur, $oid, $note);
                    $db->commit();
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
        }

        /* PRG */
        $_SESSION['co_flash'] = $msg;
        $qs = http_build_query(array_filter([
            'view'   => $postViewId ?: $oid,
            'status' => $fStatus,
            'q'      => $fQ,
        ], static fn($v) => $v !== '' && $v !== null && $v !== 0));
        header('Location: custom-orders.php?' . $qs);
        exit;
    } catch (Throwable $e) {
        $msg = $e->getMessage() === 'CO_CSRF'
            ? 'نشست منقضی شده — صفحه را رفرش کنید و دوباره تلاش کنید'
            : 'انجام عملیات ممکن نشد — دوباره تلاش کنید';
    }
}

if (!empty($_SESSION['co_flash'])) {
    $msg = (string)$_SESSION['co_flash'];
    unset($_SESSION['co_flash']);
}

/* ───────── لیست ───────── */
$params = [];
$where  = [];
if ($fStatus !== '' && co_stage_valid($fStatus)) {
    $where[] = 'status = ?';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where[] = '(tracking_code LIKE ? OR mobile LIKE ? OR full_name LIKE ?)';
    $like = '%' . $fQ . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
$sql = 'SELECT o.*,
        (SELECT COUNT(*) FROM custom_order_items i WHERE i.custom_order_id = o.id) AS item_count,
        COALESCE(o.total_qty,
            (SELECT COALESCE(SUM(i.qty), 0) FROM custom_order_items i WHERE i.custom_order_id = o.id)
        ) AS total_qty
        FROM custom_orders o';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY o.id DESC LIMIT 200';

$rows = [];
$coDbError = '';
try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $coDbError = co_is_missing_table($e)
        ? 'جدول‌های ماژول ساخته نشده‌اند — فایل custom-order-schema-migration-v5.sql را در phpMyAdmin اجرا کنید'
        : 'خطا در خواندن سفارش‌ها: ' . $e->getMessage();
}

$order = $viewId > 0 ? co_load_order_full($viewId) : null;

/* ───────── رندر — داخل لایوت ادمین ───────── */
$activeMenu = 'custom';
$pageTitle  = 'مدیریت سفارش‌های اختصاصی';
// استایل اختصاصی این ماژول (coa-*) را در <head> لایوت ادمین تزریق می‌کنیم
$pageStyles = '<link rel="stylesheet" href="/assets/css/custom-order-admin.css?v=5">';

ob_start();
?>

<!-- ═══ هدر صفحه ═══ -->
<div class="admin-page-intro">
    <div>
        <span class="admin-kicker">مدیریت فروش</span>
        <h1>مدیریت سفارش‌های اختصاصی</h1>
        <p>لیست و گردش‌کار سفارش‌های اختصاصی (سفارش‌مرکزی / پروژه‌ای).</p>
    </div>
    <a href="/admin/custom-order-settings.php" class="admin-soft-action">⚙️ تنظیمات ماژول</a>
</div>

<?php if ($msg): ?><div class="coa-msg">✅ <?= $h($msg) ?></div><?php endif; ?>
<?php if ($coDbError): ?><div class="coa-msg" style="background:#fee2e2;color:#b91c1c">⚠️ <?= $h($coDbError) ?></div><?php endif; ?>

    <?php if ($order === null): ?>

        <form class="coa-filters" method="get">
            <select name="status">
                <option value="">همه مراحل</option>
                <?php foreach (array_merge(array_keys($stages), array_keys(co_auxiliary_stages()), ['rejected', 'cancelled']) as $k): ?>
                    <option value="<?= $h($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>>
                        <?= $h(co_stage_label($k)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" value="<?= $h($fQ) ?>" placeholder="جست‌وجو: کد پیگیری / موبایل / نام">
            <button type="submit" class="coa-btn">فیلتر</button>
            <a class="coa-btn ghost" href="custom-orders.php">پاک‌کردن</a>
        </form>

        <div class="coa-table-wrap">
        <table class="coa-table">
            <thead>
                <tr>
                    <th>کد پیگیری</th>
                    <th>مشتری</th>
                    <th>موبایل</th>
                    <th>محصول مبدأ</th>
                    <th>ردیف‌ها</th>
                    <th>جمع (جفت)</th>
                    <th>مبلغ کل (تومان)</th>
                    <th>مرحله</th>
                    <th>ثبت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="10" class="coa-empty">سفارشی یافت نشد</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><b dir="ltr"><?= $h($r['tracking_code']) ?></b></td>
                        <td><?= $h($r['full_name']) ?></td>
                        <td dir="ltr"><?= $h($r['mobile']) ?></td>
                        <td><?= $h($r['ref_product_name'] ?: '—') ?></td>
                        <td><?= (int)$r['item_count'] ?></td>
                        <td dir="ltr"><?= number_format((int)$r['total_qty']) ?></td>
                        <td dir="ltr"><?= $r['total_amount'] !== null ? number_format((float)$r['total_amount']) : '—' ?></td>
                        <td><span class="coa-chip s-<?= $h($r['status']) ?>"><?= $h(co_stage_label((string)$r['status'])) ?></span></td>
                        <td><?= $h(co_fa_date($r['created_at'])) ?></td>
                        <td><a class="coa-btn" href="?view=<?= (int)$r['id'] ?>">جزئیات</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php else: ?>

        <?php
        $st         = (string)$order['status'];
        $hasTotal   = $order['total_amount'] !== null;
        $hasAmt     = $order['down_payment_amount'] !== null;
        $hasRcpt    = !empty($order['down_payment_receipt_path']);
        $hasSetAmt  = $order['settlement_amount'] !== null;
        $hasSetRcpt = !empty($order['settlement_receipt_path']);
        $hasFront   = !empty($order['id_card_front_path']);
        $hasBack    = !empty($order['id_card_back_path']);
        $nextSt     = co_next_stage($st);
        $canReject  = in_array($st, ['submitted','admin_review','down_payment','sample_production','sample_sent','sample_approval','settlement','agreement','documents'], true);
        $canVerifyDocs = ($st === 'documents' && $hasFront && $hasBack && empty($order['documents_verified_at']));
        $canApprove    = (in_array($st, ['submitted','admin_review'], true) && empty($order['proforma_number']));
        $canConfirmDP  = ($st === 'down_payment' && $hasAmt && $hasRcpt && empty($order['down_payment_paid_at']));
        $canSendSample = ($st === 'sample_production' && empty($order['sample_sent_at']));
        $canRevise     = in_array($st, ['sample_sent','sample_approval'], true);
        $canIssueSet   = ($st === 'sample_approval' && ($order['sample_status'] ?? '') === 'approved' && empty($order['settlement_order_at']));
        $canConfirmSet = ($st === 'settlement' && $hasSetAmt && $hasSetRcpt && empty($order['settlement_paid_at']));
        $canAdvance    = ($st === 'production');
        $canShip       = ($st === 'quality_control' && empty($order['shipped_at']));
        $canDeliver    = ($st === 'shipped' && empty($order['final_invoice_number']));
        $canSetAmount  = (in_array($st, ['admin_review','down_payment'], true) && empty($order['down_payment_paid_at']));

        /* عنوان دقیق دکمه advance بر اساس مرحله */
        $advanceTitles = [
            'submitted'         => 'ابتدا «تایید و صدور پیش‌فاکتور» را انجام دهید',
            'admin_review'      => 'ابتدا «تایید و صدور پیش‌فاکتور» را انجام دهید',
            'down_payment'      => 'ابتدا «تایید پیش‌پرداخت» را انجام دهید',
            'sample_production' => 'ابتدا «ثبت ارسال نمونه» را انجام دهید',
            'sample_sent'       => 'منتظر تایید نمونه توسط مشتری باشید',
            'sample_approval'   => 'ابتدا «صدور دستور تسویه» را انجام دهید',
            'settlement'        => 'ابتدا «تایید تسویه» را انجام دهید',
            'quality_control'   => 'از دکمه «ثبت ارسال انبوه» استفاده کنید',
            'shipped'           => 'از دکمه «ثبت تحویل + فاکتور نهایی» استفاده کنید',
            'delivered'         => 'آخرین مرحله — روال کامل شده است',
        ];
        $advanceTitle = $advanceTitles[$st] ?? 'در این مرحله از دکمه تخصصی همان مرحله استفاده کنید';
        ?>

        <a class="coa-btn ghost" href="custom-orders.php<?= $fStatus ? '?status=' . urlencode($fStatus) : '' ?>">← بازگشت به لیست</a>

        <section class="coa-detail">
            <h2>
                <?= $h($order['tracking_code']) ?>
                <span class="coa-chip s-<?= $h($order['status']) ?>"><?= $h(co_stage_label($st)) ?></span>
                <?php if (!empty($order['sample_status'])): ?>
                    <span class="coa-chip">نمونه: <?= $h(['pending'=>'در انتظار تایید','approved'=>'تایید شده','revision_requested'=>'درخواست اصلاح','rejected'=>'رد شده'][$order['sample_status']] ?? $order['sample_status']) ?></span>
                <?php endif; ?>
            </h2>

            <?php /* لینک‌های چاپ سند */ ?>
            <?php if (!empty($order['proforma_number']) || !empty($order['final_invoice_number'])): ?>
                <div class="coa-printbar">
                    <?php if (!empty($order['proforma_number'])): ?>
                        <a class="coa-btn ghost" target="_blank" rel="noopener"
                           href="/custom-invoice-print.php?id=<?= (int)$order['id'] ?>&type=quotation">
                           🖨 چاپ پیش‌فاکتور (<?= $h($order['proforma_number']) ?>)
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($order['final_invoice_number'])): ?>
                        <a class="coa-btn ghost" target="_blank" rel="noopener"
                           href="/custom-invoice-print.php?id=<?= (int)$order['id'] ?>&type=final">
                           🖨 چاپ فاکتور نهایی (<?= $h($order['final_invoice_number']) ?>)
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="coa-grid">
                <div class="coa-card">
                    <h3>مشتری و سفارش</h3>
                    <p><b>نام:</b> <?= $h($order['full_name']) ?></p>
                    <p><b>موبایل:</b> <span dir="ltr"><?= $h($order['mobile']) ?></span></p>
                    <p><b>برند:</b> <?= $h($order['brand_name'] ?: '—') ?></p>
                    <p><b>کد ملی:</b> <?= $h($order['national_code'] ?: '—') ?></p>
                    <p><b>ثبت:</b> <?= $h(co_fa_date($order['created_at'])) ?></p>
                    <p><b>تغییر مرحله:</b> <?= $h(co_fa_date($order['status_changed_at'])) ?></p>
                    <?php if (!empty($order['ref_product_name'])): ?>
                        <p><b>محصول مبدأ:</b> <?= $h($order['ref_product_name']) ?> (کد <?= $h($order['ref_product_code']) ?>)</p>
                    <?php endif; ?>
                    <p><b>توضیحات مشتری:</b><br><?= nl2br($h($order['description'] ?: '—')) ?></p>

                    <table class="coa-table small">
                        <tr><th>نوع</th><th>رنگ</th><th>تعداد</th><th>توضیح</th></tr>
                        <?php foreach ($order['items'] as $it): ?>
                            <tr>
                                <td><?= $h($it['product_type']) ?></td>
                                <td><?= $h($it['color'] ?: '—') ?></td>
                                <td dir="ltr"><?= number_format((int)$it['qty']) ?></td>
                                <td><?= $h($it['notes'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div class="coa-card">
                    <h3>🧾 پیش‌فاکتور و مبلغ</h3>
                    <p><b>مبلغ کل:</b> <?= $order['total_amount'] !== null ? number_format((float)$order['total_amount']) . ' تومان' : '— (هنوز ثبت نشده)' ?></p>
                    <p><b>درصد پیش‌پرداخت:</b> <?= $order['down_payment_percent'] !== null ? $h($order['down_payment_percent']) . '٪' : '—' ?></p>
                    <p><b>مبلغ پیش‌پرداخت:</b> <?= $order['down_payment_amount'] !== null ? number_format((float)$order['down_payment_amount']) . ' تومان' : '—' ?></p>
                    <p><b>شماره پیش‌فاکتور:</b> <?= $h($order['proforma_number'] ?: '—') ?></p>
                    <p><b>تاریخ پیش‌فاکتور:</b> <?= $h(co_fa_date($order['proforma_issued_at'] ?? null)) ?></p>
                    <p><b>تایید ادمین:</b> <?= $h(co_fa_date($order['admin_approved_at'] ?? null)) ?></p>
                    <p><b>رسید پیش‌پرداخت:</b> <?= $order['down_payment_receipt_path'] ? '<a href="' . $h(private_file_url($order['down_payment_receipt_path'])) . '" target="_blank">مشاهده ↗</a>' : '—' ?></p>
                    <p><b>پرداخت‌شده در:</b> <?= $h(co_fa_date($order['down_payment_paid_at'] ?? null)) ?> <?= !empty($order['payment_method']) ? '(' . $h($order['payment_method']) . ')' : '' ?></p>
                    <?php if (!empty($order['admin_note'])): ?>
                        <p><b>یادداشت ادمین:</b> <?= nl2br($h($order['admin_note'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($order['proforma_number'])): ?>
                        <p style="margin-top:10px;">
                            <a class="coa-btn ghost" target="_blank" rel="noopener"
                               href="/custom-invoice-print.php?id=<?= (int)$order['id'] ?>&type=quotation">
                               🖨 دانلود / چاپ پیش‌فاکتور
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="coa-card">
                    <h3>🔬 نمونه کار</h3>
                    <p><b>شرکت حمل نمونه:</b> <?= $h($order['sample_carrier'] ?? '—') ?></p>
                    <p><b>کد رهگیری نمونه:</b> <span dir="ltr"><?= $h($order['sample_tracking_code'] ?? '—') ?></span></p>
                    <p><b>ارسال نمونه در:</b> <?= $h(co_fa_date($order['sample_sent_at'] ?? null)) ?></p>
                    <p><b>وضعیت تایید:</b> <?= $h(['pending'=>'در انتظار تایید مشتری','approved'=>'تایید شده','revision_requested'=>'درخواست اصلاح','rejected'=>'رد شده'][$order['sample_status'] ?? ''] ?? '—') ?></p>
                    <p><b>تایید مشتری در:</b> <?= $h(co_fa_date($order['sample_approved_at'] ?? null)) ?></p>
                    <?php if (!empty($order['sample_revision_note'])): ?>
                        <p><b>یادداشت اصلاح:</b> <?= nl2br($h($order['sample_revision_note'])) ?></p>
                    <?php endif; ?>
                    <p><b>طرح/لوگو:</b> <?= !empty($order['logo_path']) ? '<a href="' . $h(private_file_url($order['logo_path'])) . '" target="_blank">مشاهده ↗</a>' : '—' ?></p>
                </div>

                <div class="coa-card">
                    <h3>💳 تسویه</h3>
                    <p><b>مبلغ تسویه:</b> <?= $order['settlement_amount'] !== null ? number_format((float)$order['settlement_amount']) . ' تومان' : '—' ?></p>
                    <p><b>دستور تسویه در:</b> <?= $h(co_fa_date($order['settlement_order_at'] ?? null)) ?></p>
                    <p><b>رسید تسویه:</b> <?= $order['settlement_receipt_path'] ? '<a href="' . $h(private_file_url($order['settlement_receipt_path'])) . '" target="_blank">مشاهده ↗</a>' : '—' ?></p>
                    <p><b>تسویه‌شده در:</b> <?= $h(co_fa_date($order['settlement_paid_at'] ?? null)) ?></p>
                </div>

                <div class="coa-card">
                    <h3>🚚 ارسال و تحویل</h3>
                    <p><b>شرکت حمل:</b> <?= $h($order['shipping_carrier'] ?? '—') ?></p>
                    <p><b>کد رهگیری:</b> <span dir="ltr"><?= $h($order['shipping_tracking_code'] ?? '—') ?></span></p>
                    <p><b>ارسال در:</b> <?= $h(co_fa_date($order['shipped_at'] ?? null)) ?></p>
                    <p><b>تحویل در:</b> <?= $h(co_fa_date($order['delivered_at'] ?? null)) ?></p>
                    <p><b>فاکتور نهایی:</b> <?= $h($order['final_invoice_number'] ?? '—') ?></p>
                    <p><b>مبلغ فاکتور نهایی:</b> <?= $order['final_invoice_amount'] !== null ? number_format((float)$order['final_invoice_amount']) . ' تومان' : '—' ?></p>
                    <p><b>تاریخ فاکتور:</b> <?= $h(co_fa_date($order['final_invoice_at'] ?? null)) ?></p>
                    <?php if (!empty($order['final_invoice_number'])): ?>
                        <p style="margin-top:10px;">
                            <a class="coa-btn ghost" target="_blank" rel="noopener"
                               href="/custom-invoice-print.php?id=<?= (int)$order['id'] ?>&type=final">
                               🖨 دانلود / چاپ فاکتور نهایی
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="coa-card">
                    <h3>📜 توافق‌نامه و مدارک (اختیاری)</h3>
                    <p><b>امضای دیجیتال:</b> <?= $order['agreement_signed_at'] ? $h($order['agreement_signature_name']) . ' — ' . $h(co_fa_date($order['agreement_signed_at'])) : '—' ?></p>
                    <p><b>اسکن قرارداد:</b> <?= $order['agreement_scan_path'] ? '<a href="' . $h(private_file_url($order['agreement_scan_path'])) . '" target="_blank">مشاهده ↗</a>' : '—' ?></p>
                    <p><b>کد ملی (رو):</b> <?= $order['id_card_front_path'] ? '<a href="' . $h(private_file_url($order['id_card_front_path'])) . '" target="_blank">مشاهده ↗</a>' : '—' ?></p>
                    <p><b>کد ملی (پشت):</b> <?= $order['id_card_back_path'] ? '<a href="' . $h(private_file_url($order['id_card_back_path'])) . '" target="_blank">مشاهده ↗</a>' : '—' ?></p>
                    <p><b>تایید مدارک:</b> <?= $h(co_fa_date($order['documents_verified_at'] ?? null)) ?></p>
                </div>
            </div>

            <div class="coa-card">
                <h3>اقدامات</h3>
                <form method="post" class="coa-actions" action="custom-orders.php?view=<?= (int)$order['id'] ?>">
                    <?= co_csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="hidden" name="view_id" value="<?= (int)$order['id'] ?>">

                    <!-- ردیف ۱: دکمه‌های اصلی مرحله -->
                    <div class="coa-act-row">
                        <button class="coa-btn ok" name="action" value="approve_order"
                            <?= $canApprove ? '' : 'disabled title="فقط در مرحله ثبت/بررسی و تا قبل از صدور پیش‌فاکتور"' ?>>
                            ✓ تایید و صدور پیش‌فاکتور
                        </button>
                        <button class="coa-btn warn" name="action" value="confirm_down_payment"
                            <?= $canConfirmDP ? '' : 'disabled title="' . (!empty($order['down_payment_paid_at']) ? 'قبلاً تایید شده' : 'نیاز: ثبت مبلغ + رسید مشتری') . '"' ?>>
                            💳 تایید پیش‌پرداخت
                        </button>
                        <button class="coa-btn ok" name="action" value="send_sample"
                            <?= $canSendSample ? '' : 'disabled title="' . (!empty($order['sample_sent_at']) ? 'ارسال نمونه قبلاً ثبت شده' : 'فقط در مرحله تولید نمونه') . '"' ?>>
                            🔬 ثبت ارسال نمونه
                        </button>
                        <button class="coa-btn ok" name="action" value="issue_settlement"
                            <?= $canIssueSet ? '' : 'disabled title="' . (!empty($order['settlement_order_at']) ? 'دستور تسویه قبلاً صادر شده' : 'نیاز: تایید نمونه توسط مشتری') . '"' ?>>
                            💰 صدور دستور تسویه
                        </button>
                        <button class="coa-btn warn" name="action" value="confirm_settlement"
                            <?= $canConfirmSet ? '' : 'disabled title="' . (!empty($order['settlement_paid_at']) ? 'قبلاً تایید شده' : 'نیاز: صادره شدن دستور تسویه + رسید مشتری') . '"' ?>>
                            ✅ تایید تسویه
                        </button>
                        <button class="coa-btn ok" name="action" value="advance"
                            <?= $canAdvance ? '' : 'disabled title="' . $h($advanceTitle) . '"' ?>>
                            ⏭ مرحله بعد (کنترل کیفیت)
                        </button>
                        <button class="coa-btn ok" name="action" value="ship"
                            <?= $canShip ? '' : 'disabled title="' . (!empty($order['shipped_at']) ? 'ارسال قبلاً ثبت شده' : 'فقط در مرحله کنترل کیفیت') . '"' ?>>
                            🚚 ثبت ارسال انبوه
                        </button>
                        <button class="coa-btn ok" name="action" value="deliver"
                            <?= $canDeliver ? '' : 'disabled title="' . (!empty($order['final_invoice_number']) ? 'فاکتور نهایی قبلاً صادر شده' : 'فقط در مرحله ارسال شد') . '"' ?>>
                            📦 ثبت تحویل + فاکتور نهایی
                        </button>
                        <button class="coa-btn warn" name="action" value="request_revision"
                            <?= $canRevise ? '' : 'disabled title="فقط در مراحل ارسال/تایید نمونه"' ?>>
                            🔄 درخواست اصلاح نمونه
                        </button>
                        <button class="coa-btn warn" name="action" value="verify_documents"
                            <?= $canVerifyDocs ? '' : 'disabled title="فقط در مرحله مدارک با فایل کامل و بدون تایید قبلی"' ?>>
                            🪪 تایید مدارک
                        </button>
                        <button class="coa-btn bad" name="action" value="reject"
                            <?= $canReject ? '' : 'disabled title="در این مرحله مجاز نیست"' ?>>
                            ✕ رد سفارش
                        </button>
                        <button class="coa-btn bad" name="action" value="cancel"
                            <?= $canReject ? '' : 'disabled title="لغو توافقی فقط تا قبل از تولید انبوه"' ?>
                            onclick="return confirm('سفارش «لغو» شود؟ (برخلاف رد، لغو توافقی است — دلیل را در یادداشت بنویسید)');">
                            🚫 لغو سفارش (توافقی)
                        </button>
                    </div>

                    <!-- ردیف ۲: تایید سفارش + پیش‌فاکتور (ورودی‌ها) -->
                    <?php if ($canApprove): ?>
                    <div class="coa-act-row">
                        <input type="text" name="total_amount" placeholder="مبلغ کل کار (تومان)" dir="ltr"
                            value="<?= $order['total_amount'] !== null ? $h($order['total_amount']) : '' ?>">
                        <input type="number" name="down_payment_percent" step="1" min="1" max="100"
                            placeholder="درصد پیش‌پرداخت (پیش‌فرض <?= (int)co_default_down_payment_percent() ?>)"
                            dir="ltr" value="<?= $order['down_payment_percent'] !== null ? $h($order['down_payment_percent']) : (int)co_default_down_payment_percent() ?>">
                        <small style="color:#6b7280">پیش‌پرداخت خودکار محاسبه و پیش‌فاکتور صادر می‌شود.</small>
                    </div>
                    <?php endif; ?>

                    <!-- ردیف ۳: ارسال نمونه -->
                    <?php if ($canSendSample): ?>
                    <div class="coa-act-row">
                        <input type="text" name="sample_carrier" placeholder="شرکت حمل نمونه (مثلاً تیپاکس)" dir="rtl">
                        <input type="text" name="sample_tracking_code" placeholder="کد رهگیری نمونه" dir="ltr">
                        <small style="color:#6b7280">پس از ثبت، سفارش به مرحله تایید مشتری می‌رود.</small>
                    </div>
                    <?php endif; ?>

                    <!-- ردیف ۴: ثبت ارسال انبوه -->
                    <?php if ($canShip): ?>
                    <div class="coa-act-row">
                        <input type="text" name="shipping_carrier" placeholder="شرکت حمل (مثلاً باربری)" dir="rtl">
                        <input type="text" name="shipping_tracking_code" placeholder="کد رهگیری ارسال" dir="ltr">
                        <small style="color:#6b7280">پس از ثبت، سفارش به مرحله ارسال شد می‌رود.</small>
                    </div>
                    <?php endif; ?>

                    <!-- ردیف ۵: ثبت تحویل + فاکتور نهایی -->
                    <?php if ($canDeliver): ?>
                    <div class="coa-act-row">
                        <input type="text" name="final_invoice_number" placeholder="شماره فاکتور نهایی (خالی = خودکار)" dir="ltr">
                        <input type="text" name="final_invoice_amount" placeholder="مبلغ فاکتور نهایی (پیش‌فرض = مبلغ کل)" dir="ltr"
                            value="<?= $order['total_amount'] !== null ? $h($order['total_amount']) : '' ?>">
                        <small style="color:#6b7280">پس از ثبت، روال تکمیل و فاکتور نهایی صادر می‌شود.</small>
                    </div>
                    <?php endif; ?>

                    <!-- ردیف ۶: ثبت مبلغ پیش‌پرداخت (دستی) — فقط تا قبل از تایید پرداخت -->
                    <?php if ($canSetAmount): ?>
                    <div class="coa-act-row">
                        <input type="text" name="amount" placeholder="اصلاح دستی مبلغ پیش‌پرداخت (تومان)" dir="ltr">
                        <select name="method">
                            <option value="manual">پرداخت دستی (کارتی/واریز)</option>
                            <option value="gateway">درگاه پرداخت</option>
                        </select>
                        <button class="coa-btn" name="action" value="set_payment">ثبت مبلغ</button>
                    </div>
                    <?php endif; ?>

                    <!-- ردیف ۷: پرش دستی به مرحله -->
                    <div class="coa-act-row">
                        <select name="to_stage">
                            <?php foreach (array_merge(array_keys($stages), array_keys(co_auxiliary_stages()), ['rejected', 'cancelled']) as $k): ?>
                                <option value="<?= $h($k) ?>" <?= $st === $k ? 'selected' : '' ?>><?= $h(co_stage_label($k)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="coa-btn" name="action" value="set_stage"
                            onclick="return confirm('پرش دستی به مرحله دلخواه؟ اگر مقصد «مدارک» یا «پیش‌پرداخت» باشد فایل‌های همان مرحله پاک می‌شوند.');">
                            پرش به مرحله
                        </button>
                    </div>

                    <!-- ردیف ۸: یادداشت -->
                    <div class="coa-act-row">
                        <input type="text" name="note" placeholder="یادداشت / دلیل (در تایم‌لاین مشتری دیده می‌شود)">
                        <button class="coa-btn" name="action" value="note">ثبت یادداشت</button>
                    </div>
                </form>
            </div>

            <div class="coa-card">
                <h3>تایم‌لاین (<?= count($order['events']) ?> رویداد)</h3>
                <ul class="coa-tl">
                    <?php foreach ($order['events'] as $ev): ?>
                        <li>
                            <b><?= $h(co_stage_label((string)$ev['stage_key'])) ?></b>
                            <span class="coa-chip"><?= $h($ev['actor']) ?></span>
                            <small><?= $h(co_fa_date($ev['created_at'])) ?></small>
                            <?php if ($ev['note']): ?><p><?= $h($ev['note']) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

    <?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
