<?php
/* ════ RABETI-CUSTOM-ORDER | نسخه: v5.1-accordion | 2026-10-30 | LATEST ════ */

/**
 * پیگیری سفارش اختصاصی — v5.1-accordion (صفحه مشتری)
 *
 * ═══ تغییرات v5.1 نسبت به v5 ═══
 *   • ⭐ نمایش آکاردئونی سفارش‌ها: هر سفارش بلوک کامل خودش را دارد
 *   • ⭐ همه سفارش‌های حساب یک‌جا — با کلیک باز/بسته می‌شوند
 *   • ⭐ اگر ?code= باشد، همان سفارش از پیش باز است
 *   • ⭐ بلوک‌های اکشن به فایل custom-order-track-actions.php منتقل شد
 *   • منطق POST/GET/CSRF/claim/PRG دقیقاً مثل v5
 */

declare(strict_types=1);

/* Auto-detect bootstrap (local + host) */
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
require_once __DIR__ . '/custom-order-common.php';

$h         = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$stages    = co_stages();
$stageKeys = array_keys($stages);

$errors  = [];
$notice  = '';
$order   = null;

$uid = co_current_user_id();

$code   = strtoupper(co_en_digits(trim((string)($_REQUEST['code'] ?? ''))));
$mobile = co_en_digits(trim((string)($_REQUEST['mobile'] ?? '')));

/* برچسب وضعیت + اقدام بعدی برای کارت‌های فهرست */
$coStatusInfo = static function (string $st): array {
    if ($st === 'rejected')  return ['رد شده', 'bad'];
    if ($st === 'cancelled') return ['لغو شده', 'bad'];
    $map = [
        'submitted'         => 'در انتظار تایید ادمین و صدور پیش‌فاکتور',
        'admin_review'      => 'نوبت اقدام ادمین (بررسی و پیش‌فاکتور)',
        'down_payment'      => 'اقدام شما: واریز پیش‌پرداخت و ارسال رسید 💳',
        'sample_production' => 'در حال تولید نمونه 🔬',
        'sample_sent'       => 'اقدام شما: تایید یا درخواست اصلاح نمونه ✅',
        'sample_approval'   => 'نمونه تایید شد — منتظر صدور دستور تسویه',
        'settlement'        => 'اقدام شما: تسویه مابه‌التفاوت و ارسال رسید 💳',
        'production'        => 'در حال تولید انبوه 🏭',
        'quality_control'   => 'کنترل کیفیت 🔍',
        'shipped'           => 'ارسال شد 🚚',
        'delivered'         => 'تحویل شد + فاکتور نهایی ✅',
        // legacy
        'expert_contact'    => 'تماس کارشناس (قدیمی)',
        'agreement'         => 'اقدام شما: امضای توافق‌نامه ✍️',
        'documents'         => 'اقدام شما: بارگذاری تصویر کد ملی 🪪',
        'shipping_choice'   => 'انتخاب ارسال (قدیمی)',
        'final_payment'     => 'پرداخت نهایی (قدیمی)',
    ];
    $todo = in_array($st, ['down_payment', 'sample_sent', 'settlement', 'agreement', 'documents'], true);
    return [$map[$st] ?? $st, $todo ? 'todo' : ''];
};

/* سفارش‌های حساب کاربر لاگین */
$myOrders = [];
if ($uid) {
    try {
        $stmt = db()->prepare('SELECT id, tracking_code, status, created_at FROM custom_orders WHERE user_id = ? ORDER BY id DESC LIMIT 30');
        $stmt->execute([$uid]);
        $myOrders = $stmt->fetchAll();
    } catch (Throwable $e) {
        $myOrders = [];
    }
}

/* ═══════════ اکشن‌های مشتری (POST) ═══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        co_verify_csrf();
    } catch (Throwable $e) {
        $errors['global'] = 'نشست شما منقضی شده است — صفحه را با Ctrl+F5 رفرش کنید و دوباره تلاش کنید';
    }

    if (!$errors) {
        try {
            $action = (string)($_POST['action'] ?? '');
            $code   = strtoupper(co_en_digits(trim((string)($_POST['code'] ?? ''))));
            $mobile = co_en_digits(trim((string)($_POST['mobile'] ?? '')));

            /* اتصال سفارش مهمان به حساب */
            if ($action === 'claim') {
                if (!$uid) {
                    $errors['global'] = 'برای اتصال سفارش، ابتدا وارد حساب کاربری شوید';
                } else {
                    $order = co_find_order($code, $mobile);
                    if ($order === null) {
                        $errors['global'] = 'سفارشی با این کد پیگیری و شماره موبایل پیدا نشد';
                    } elseif (!co_claim_order((int)$order['id'], $uid)) {
                        $errors['global'] = 'اتصال این سفارش ممکن نشد (احتمالاً به حساب دیگری متصل است)';
                    } else {
                        $notice = 'سفارش به حساب شما متصل شد — از این پس در فهرست پیگیری حساب شما دیده می‌شود';
                        $order  = co_load_order_full((int)$order['id']);
                        $stmt = db()->prepare('SELECT id, tracking_code, status, created_at FROM custom_orders WHERE user_id = ? ORDER BY id DESC LIMIT 30');
                        $stmt->execute([$uid]);
                        $myOrders = $stmt->fetchAll();
                    }
                }
            } else {

                $order = co_find_order_for_user($code, $mobile, $uid);
                if ($order === null) {
                    $errors['global'] = 'سفارشی با این کد پیگیری پیدا نشد';
                } elseif ($action === 'cancel') {
                    if (in_array($order['status'], ['submitted', 'admin_review', 'down_payment', 'sample_production', 'sample_sent', 'sample_approval', 'settlement', 'agreement', 'documents'], true)) {
                        co_set_stage((int)$order['id'], 'cancelled', 'customer', 'لغو توسط مشتری از صفحه پیگیری');
                        header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=cancelled');
                        exit;
                    } else {
                        $errors['global'] = 'از این مرحله به بعد، لغو سفارش آنلاین ممکن نیست — لطفاً با کارشناس تماس بگیرید';
                    }

                    /* ── امضای توافق‌نامه (اختیاری) ── */
                } elseif ($action === 'sign') {
                    if ($order['status'] !== 'agreement') {
                        $errors['global'] = 'این سفارش در مرحله امضای توافق‌نامه نیست';
                    } else {
                        $sigName = trim((string)($_POST['signature_name'] ?? ''));
                        $natCode = co_en_digits(trim((string)($_POST['national_code'] ?? '')));
                        $agree   = isset($_POST['agree']);
                        if (mb_strlen($sigName) < 3)             $errors['signature_name'] = 'نام امضاکننده را کامل وارد کنید';
                        if (!co_valid_national_code($natCode))   $errors['national_code']  = 'کد ملی ۱۰ رقمی معتبر نیست';
                        if (!$agree)                             $errors['agree']          = 'تأیید بندهای توافق‌نامه الزامی است';
                        $scan = co_handle_upload($_FILES['agreement_scan'] ?? [], $order['tracking_code'], 'agreement-scan');
                        if (!$scan['ok']) $errors['agreement_scan'] = $scan['error'];
                        if ($scan['path'] !== null) {
                            db()->prepare('UPDATE custom_orders SET agreement_scan_path = ? WHERE id = ?')->execute([$scan['path'], (int)$order['id']]);
                        }
                        if (!$errors) {
                            db()->prepare('UPDATE custom_orders SET agreement_signed_at = NOW(), agreement_ip = ?, agreement_ua = ?, agreement_signature_name = ?, national_code = ?, status = ?, status_changed_at = NOW() WHERE id = ?')
                                ->execute([
                                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                                    $sigName,
                                    $natCode,
                                    'documents',
                                    (int)$order['id'],
                                ]);
                            co_insert_event((int)$order['id'], 'documents', 'customer', 'توافق‌نامه امضا شد' . ($scan['path'] ? ' + اسکن' : ''));
                            header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=sign');
                            exit;
                        }
                    }

                    /* ── آپلود کد ملی (اختیاری) ── */
                } elseif ($action === 'docs') {
                    if ($order['status'] !== 'documents') {
                        $errors['global'] = 'این سفارش در مرحله بارگذاری مدارک نیست';
                    } else {
                        $needFront = empty($order['id_card_front_path']);
                        $needBack  = empty($order['id_card_back_path']);
                        $front = ['ok' => true, 'path' => $order['id_card_front_path'], 'error' => null];
                        $back  = ['ok' => true, 'path' => $order['id_card_back_path'],  'error' => null];
                        if (isset($_FILES['id_front']) && (int)($_FILES['id_front']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                            $front = co_handle_upload($_FILES['id_front'], $order['tracking_code'], 'id-front');
                        }
                        if (isset($_FILES['id_back']) && (int)($_FILES['id_back']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                            $back = co_handle_upload($_FILES['id_back'], $order['tracking_code'], 'id-back');
                        }
                        if (!$front['ok']) $errors['id_front'] = $front['error'];
                        if (!$back['ok'])  $errors['id_back']  = $back['error'];
                        if ($needFront && $front['path'] === null) $errors['id_front'] = $errors['id_front'] ?? 'تصویر روی کارت ملی الزامی است';
                        if ($needBack  && $back['path']  === null) $errors['id_back']  = $errors['id_back']  ?? 'تصویر پشت کارت ملی الزامی است';
                        if ($front['path'] !== null) db()->prepare('UPDATE custom_orders SET id_card_front_path = ? WHERE id = ?')->execute([$front['path'], (int)$order['id']]);
                        if ($back['path']  !== null) db()->prepare('UPDATE custom_orders SET id_card_back_path = ? WHERE id = ?')->execute([$back['path'],  (int)$order['id']]);
                        if (!$errors) {
                            db()->prepare("UPDATE custom_orders SET documents_submitted_at = NOW() WHERE id = ?")->execute([(int)$order['id']]);
                            co_insert_event((int)$order['id'], 'documents', 'customer', 'تصاویر کد ملی (پشت و رو) آپلود شد — در انتظار بررسی');
                            header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=docs');
                            exit;
                        }
                    }

                    /* ── آپلود رسید پیش‌پرداخت ── */
                } elseif ($action === 'receipt') {
                    if ($order['status'] !== 'down_payment') {
                        $errors['global'] = 'این سفارش در مرحله پیش‌پرداخت نیست';
                    } else {
                        $rcpt = co_handle_upload($_FILES['payment_receipt'] ?? [], $order['tracking_code'], 'receipt');
                        if (!$rcpt['ok']) {
                            $errors['payment_receipt'] = $rcpt['error'];
                        } elseif ($rcpt['path'] === null) {
                            $errors['payment_receipt'] = 'تصویر رسید را انتخاب کنید';
                        } else {
                            db()->prepare('UPDATE custom_orders SET down_payment_receipt_path = ? WHERE id = ?')->execute([$rcpt['path'], (int)$order['id']]);
                            co_insert_event((int)$order['id'], 'down_payment', 'customer', 'رسید پیش‌پرداخت آپلود شد — در انتظار تایید');
                            header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=receipt');
                            exit;
                        }
                    }

                    /* ── تایید نمونه ── */
                } elseif ($action === 'approve_sample') {
                    if ($order['status'] !== 'sample_sent') {
                        $errors['global'] = 'تایید نمونه فقط در مرحله «ارسال نمونه» ممکن است';
                    } else {
                        $upd = db()->prepare("UPDATE custom_orders SET sample_status = 'approved', sample_approved_at = NOW(), status = 'sample_approval', status_changed_at = NOW() WHERE id = ? AND status = 'sample_sent' AND (sample_status IS NULL OR sample_status <> 'approved')");
                        $upd->execute([(int)$order['id']]);
                        if ($upd->rowCount() === 0) {
                            $errors['global'] = 'وضعیت نمونه تغییر کرده است — صفحه را رفرش کنید';
                        } else {
                            co_insert_event((int)$order['id'], 'sample_approval', 'customer', 'نمونه توسط مشتری تایید شد — منتظر صدور دستور تسویه');
                            header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=sample_approved');
                            exit;
                        }
                    }

                    /* ── درخواست اصلاح نمونه ── */
                } elseif ($action === 'revise_sample') {
                    if (!in_array($order['status'], ['sample_sent', 'sample_approval'], true)) {
                        $errors['global'] = 'درخواست اصلاح در این مرحله ممکن نیست';
                    } else {
                        $revNote = trim((string)($_POST['revision_note'] ?? ''));
                        if (mb_strlen($revNote) < 5) {
                            $errors['revision_note'] = 'لطفاً اصلاحات مورد نظر را به‌طور کامل توضیح دهید (حداقل ۵ نویسه)';
                        } else {
                            $upd = db()->prepare("UPDATE custom_orders SET sample_status = 'revision_requested', sample_revision_note = ?, status = 'sample_production', status_changed_at = NOW() WHERE id = ? AND status IN ('sample_sent','sample_approval')");
                            $upd->execute([$revNote, (int)$order['id']]);
                            if ($upd->rowCount() === 0) {
                                $errors['global'] = 'وضعیت نمونه تغییر کرده است — صفحه را رفرش کنید';
                            } else {
                                co_insert_event((int)$order['id'], 'sample_production', 'customer', 'درخواست اصلاح نمونه: ' . $revNote);
                                header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=sample_revised');
                                exit;
                            }
                        }
                    }

                    /* ── آپلود رسید تسویه ── */
                } elseif ($action === 'settlement_receipt') {
                    if ($order['status'] !== 'settlement') {
                        $errors['global'] = 'این سفارش در مرحله تسویه نیست';
                    } else {
                        $rcpt = co_handle_upload($_FILES['settlement_receipt'] ?? [], $order['tracking_code'], 'settlement');
                        if (!$rcpt['ok']) {
                            $errors['settlement_receipt'] = $rcpt['error'];
                        } elseif ($rcpt['path'] === null) {
                            $errors['settlement_receipt'] = 'تصویر رسید را انتخاب کنید';
                        } else {
                            db()->prepare('UPDATE custom_orders SET settlement_receipt_path = ? WHERE id = ?')->execute([$rcpt['path'], (int)$order['id']]);
                            co_insert_event((int)$order['id'], 'settlement', 'customer', 'رسید تسویه آپلود شد — در انتظار تایید');
                            header('Location: ?code=' . urlencode((string)$order['tracking_code']) . '&ok=settlement_receipt');
                            exit;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if (co_is_missing_table($e)) {
                $errors['global'] = 'جدول‌های ماژول ساخته نشده‌اند — فایل custom-order-schema-migration-v5.sql را در phpMyAdmin اجرا کنید';
                $errors['debug']  = $e->getMessage();
            } else {
                $errors['global'] = 'انجام عملیات ممکن نشد — دوباره تلاش کنید';
                $errors['debug']  = $e->getMessage();
            }
        }
    }
}

/* ── نمایش با GET ── */
if ($order === null && $code !== '' && isset($_GET['code'])) {
    $resolved = null;
    if ($uid) {
        $resolved = co_find_order_for_user($code, '', $uid);
        if ($resolved !== null && $resolved['user_id'] !== null && (int)$resolved['user_id'] === $uid) {
            $order = $resolved;
        } else {
            $resolved = null;
        }
    }
    if ($order === null) {
        if ($mobile === '') {
            if (!isset($errors['global'])) {
                $errors['global'] = $uid
                    ? 'این کد در حساب شما ثبت نشده — اگر سفارش مهمان است، شماره موبایل سفارش را هم وارد کنید'
                    : 'شماره موبایل سفارش را هم وارد کنید';
            }
        } elseif (!co_rate_limit_ok()) {
            $errors['global'] = 'تعداد درخواست‌های پیگیری زیاد است — ۱۰ دقیقه بعد دوباره تلاش کنید';
        } else {
            $resolved = co_find_order($code, $mobile);
            if ($resolved === null) {
                if (!isset($errors['global'])) $errors['global'] = 'سفارشی با این کد پیگیری و شماره موبایل پیدا نشد';
            } else {
                $order = $resolved;
                if ($uid && $order['user_id'] === null) {
                    if (co_claim_order((int)$order['id'], $uid)) $notice = 'این سفارش به حساب شما متصل شد';
                }
            }
        }
    }
}
if ($order !== null) {
    $order = co_load_order_full((int)$order['id']);
}
if ($order !== null) {
    $_SESSION['co_access'] = array_slice(array_values(array_unique(array_merge(
        array_map('intval', (array)($_SESSION['co_access'] ?? [])),
        [(int)$order['id']]
    ))), -20);
}

/* پیام‌های ok از PRG */
if (!empty($_GET['ok'])) {
    $okMap = [
        'sign'                => 'توافق‌نامه ثبت شد — مرحله بعد: بارگذاری تصویر کد ملی',
        'docs'                => 'مدارک شما ثبت شد؛ پس از بررسی به مرحله پیش‌پرداخت می‌روید.',
        'receipt'             => 'رسید پیش‌پرداخت ثبت شد؛ پس از تایید، تولید نمونه آغاز می‌شود.',
        'cancelled'           => 'سفارش شما لغو شد.',
        'sample_approved'     => 'نمونه تایید شد — منتظر صدور دستور تسویه توسط ادمین.',
        'sample_revised'      => 'درخواست اصلاح نمونه ثبت شد — سفارش به تولید نمونه برگشت.',
        'settlement_receipt'  => 'رسید تسویه ثبت شد؛ پس از تایید، تولید انبوه آغاز می‌شود.',
    ];
    if (isset($okMap[$_GET['ok']])) $notice = $okMap[$_GET['ok']];
}

/* پیشنهاد اتصال سفارش‌های مهمان هم‌موبایل */
$claimMobile = '';
if ($uid) $claimMobile = co_user_profile($uid)['mobile'];
$claimCandidates = [];
if ($uid && !$myOrders && $order === null && $claimMobile !== '') {
    $claimCandidates = co_unclaimed_orders_by_mobile($claimMobile);
}

$pageTitle = 'پیگیری سفارش اختصاصی | جوراب رابطی';
ob_start();
?>

<link rel="stylesheet" href="/assets/css/custom-order.css?v=6">

<section class="page-head">
    <h1 class="section-title">پیگیری سفارش اختصاصی</h1>
    <p class="section-sub">
        <?php if ($uid && $myOrders): ?>
            سفارش‌های حساب شما این‌جا فهرست می‌شود — روی هر سفارش کلیک کنید تا جزئیات و اقدامات باز شود
        <?php elseif ($uid): ?>
            سفارشی در حساب شما نیست — اگر سفارش مهمان دارید، کد پیگیری + موبایل را وارد کنید
        <?php else: ?>
            کد پیگیری و شماره موبایلی که هنگام ثبت درخواست وارد کردید را بنویسید
        <?php endif; ?>
    </p>
</section>

<?php if (!empty($errors['global'])): ?>
    <div class="form-alert">⚠️ <?= $h($errors['global']) ?></div>
<?php endif; ?>
<?php if ($notice): ?><div class="form-alert ok">✅ <?= $h($notice) ?></div><?php endif; ?>
<?php if (isset($errors['debug'])): ?>
    <details class="co-debug">
        <summary>جزئیات فنی خطا (اگر پشتیبانی خواست، همین را بفرستید)</summary>
        <code dir="ltr"><?= $h($errors['debug']) ?></code>
    </details>
<?php endif; ?>

<?php /* ═══ v5.1: آکاردئون سفارش‌های حساب ═══ */ ?>
<?php if ($uid && $myOrders): ?>
    <div class="coi-myorders">
        <b class="coi-myorders-title">سفارش‌های حساب شما (<?= count($myOrders) ?> مورد):</b>

        <?php foreach ($myOrders as $mo): ?>
            <?php
            $isThisOrder = ($order !== null && (int)$order['id'] === (int)$mo['id']);
            $fullMo      = $isThisOrder ? $order : null;
            [$moLabel, $moTone] = $coStatusInfo((string)$mo['status']);
            if ($moLabel === (string)$mo['status'] && isset($stages[$mo['status']])) {
                $moLabel = $stages[$mo['status']][0];
            }
            ?>

            <details class="coi-order-accordion" <?= $isThisOrder ? 'open' : '' ?> id="coi-order-<?= (int)$mo['id'] ?>">
                <summary class="coi-order-card">
                    <span class="coi-order-code" dir="ltr"><?= $h($mo['tracking_code']) ?></span>
                    <span class="coi-order-status <?= $moTone ?>"><?= $h($moLabel) ?></span>
                    <span class="coi-order-date">ثبت: <?= $h(co_fa_date($mo['created_at'])) ?></span>
                    <span class="coi-order-go"><?= $isThisOrder ? 'بستن ▲' : 'مشاهده جزئیات ▼' ?></span>
                </summary>

                <div class="coi-order-body">
                    <?php if ($fullMo !== null): ?>
                        <?php require __DIR__ . '/custom-order-track-actions.php'; ?>
                    <?php else: ?>
                        <p class="field-hint">برای مشاهده جزئیات و اقدامات این سفارش، روی لینک زیر بزنید.</p>
                        <a href="?code=<?= $h($mo['tracking_code']) ?>" class="btn btn-ghost">
                            باز کردن سفارش <?= $h($mo['tracking_code']) ?> ←
                        </a>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>

        <small style="display:block;margin-top:12px;color:#6b7280">
            روی هر سفارش کلیک کنید تا جزئیات کامل و اقدامات همان مرحله باز شود — موبایل لازم نیست
        </small>
    </div>
<?php endif; ?>

<?php /* ═══ پیشنهاد اتصال سفارش‌های مهمان هم‌موبایل ═══ */ ?>
<?php if ($uid && $claimCandidates && !$myOrders): ?>
    <div class="coi-claim-box">
        <b>🔗 سفارش(هایی) با شماره موبایل حساب شما پیدا شد که هنوز به حساب متصل نیست:</b>
        <?php foreach ($claimCandidates as $cc): ?>
            <?php [$ccLabel, $ccTone] = $coStatusInfo((string)$cc['status']); ?>
            <form method="post" action="" class="coi-claim-row">
                <?= co_csrf_field() ?>
                <input type="hidden" name="action" value="claim">
                <input type="hidden" name="code" value="<?= $h($cc['tracking_code']) ?>">
                <input type="hidden" name="mobile" value="<?= $h($claimMobile) ?>">
                <span class="coi-order-code" dir="ltr"><?= $h($cc['tracking_code']) ?></span>
                <span class="coi-order-status <?= $ccTone ?>"><?= $h($ccLabel) ?></span>
                <span class="coi-order-date">ثبت: <?= $h(co_fa_date($cc['created_at'])) ?></span>
                <button type="submit" class="btn btn-ghost">اتصال به حساب من</button>
            </form>
        <?php endforeach; ?>
        <small>با اتصال، این سفارش‌ها همیشه در همین صفحه و بدون وارد کردن کد در دسترس شما هستند</small>
    </div>
<?php endif; ?>

<?php /* ═══ فرم جستجو — برای مهمان یا وقتی سفارشی باز نیست ═══ */ ?>
<?php if (!$uid || !$myOrders || $order === null): ?>
    <?php if ($uid && !$myOrders): ?>
        <div class="coi-hint-box">
            <b>سفارشی در حساب شما ثبت نشده است.</b>
            اگر قبلاً به‌عنوان مهمان سفارش داده‌اید، کد پیگیری (مثل CR-00001-X7QF) و شماره موبایل سفارش را
            در فرم زیر وارد کنید — سفارش به حساب شما متصل می‌شود.
        </div>
    <?php endif; ?>

    <form class="co-form" method="get" action="" novalidate>
        <div class="form-row">
            <div class="form-field">
                <label>کد پیگیری <i>*</i></label>
                <input type="text" name="code" dir="ltr" value="<?= $h($code) ?>" placeholder="CR-00001-X7QF">
            </div>
            <div class="form-field">
                <label>شماره موبایل <?= $uid ? '<span class="co-optional">(فقط برای سفارش مهمان)</span>' : '<i>*</i>' ?></label>
                <input type="tel" name="mobile" dir="ltr" value="<?= $h($mobile) ?>" placeholder="09121234567">
            </div>
        </div>
        <button type="submit" class="btn btn-orange co-submit">نمایش وضعیت ←</button>
    </form>
<?php endif; ?>

<?php /* ═══ نمایش تکی برای مهمان — وقتی سفارش پیدا شد ═══ */ ?>
<?php if ($order !== null && !$uid): ?>
    <div class="coi-single-order">
        <?php require __DIR__ . '/custom-order-track-actions.php'; ?>
    </div>
<?php endif; ?>

<script src="/assets/js/custom-order.js?v=6" defer></script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
