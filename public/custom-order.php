<?php
/* ════ RABETI-CUSTOM-ORDER | نسخه: v4 | 2026-10-30 | جدیدترین نسخه (LATEST) ════ */

/**
 * سفارش تولید اختصاصی — v4
 * تغییرات v4 نسبت به v3:
 *   • ⭐ به‌روزرسانی متن مراحل مطابق روال جدید ۱۱ مرحله‌ای
 *     (ثبت → تایید و پیش‌فاکتور → پیش‌پرداخت ۳۰٪ → نمونه → تایید نمونه →
 *      تسویه → تولید انبوه → کنترل کیفیت → ارسال → تحویل + فاکتور نهایی)
 *   • توافق‌نامه و کد ملی به مرحله‌ی «اختیاری» تبدیل شد (در صورت نیاز ادمین فعال می‌کند)
 *   • منطق ثبت درخواست کاملاً دست‌نخورده باقی مانده (طبق درخواست: «تا اینجا مشکلی نیست»)
 *
 * حفظ شده از v3:
 *   • ثبت سفارش فقط برای کاربران عضو (مهمان کارت ورود/ثبت‌نام می‌بیند)
 *   • پیش‌پرکردن نام/موبایل از پروفایل، نوار حساب کاربری، لینک پیگیری بدون کد
 *   • چند-آیتمی، ارجاع از صفحه محصول، آپلود طرح/لوگو (درگ‌اند‌دراپ)
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

$h     = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$types = ['مردانه', 'زنانه', 'بچگانه', 'ورزشی', 'پشمی', 'سایر'];

$errors    = [];
$saved     = false;
$trackCode = '';
$trackUrl  = '';

/* ── کاربر جاری (ثبت سفارش فقط برای اعضا) ── */
$uid = co_current_user_id();
$profile = co_user_profile($uid);

/* ── ارجاع محصول (اختیاری): /custom-order?ref=ID ── */
$refProduct = null;
$refId = (int)($_GET['ref'] ?? 0);
if ($refId > 0) {
    try {
        $stmt = db()->prepare(
            'SELECT p.id, p.name, p.code,
                    (SELECT pi.image_path FROM product_images pi
                     WHERE pi.product_id = p.id
                     ORDER BY pi.is_main DESC, pi.sort_order ASC LIMIT 1) AS img
             FROM products p
             WHERE p.id = ? AND p.is_active = 1 LIMIT 1'
        );
        $stmt->execute([$refId]);
        $refProduct = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $refProduct = null;
    }
}

/* ── مقادیر قدیمی (بعد از خطا) — پیش‌پر از پروفایل اگر لاگین است ── */
$old = [
    'name'        => trim($_POST['name'] ?? ''),
    'mobile'      => trim($_POST['mobile'] ?? ''),
    'brand_name'  => trim($_POST['brand_name'] ?? ''),
    'description' => trim($_POST['description'] ?? ''),
];
if ($uid !== null && $old['name'] === '' && $old['mobile'] === '') {
    if ($old['name'] === '' && $profile['name'] !== '') {
        $old['name'] = $profile['name'];
    }
    if ($old['mobile'] === '' && $profile['mobile'] !== '') {
        $old['mobile'] = $profile['mobile'];
    }
}
$oldItems = [];
if (isset($_POST['items']) && is_array($_POST['items'])) {
    foreach (array_slice($_POST['items'], 0, 8) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $oldItems[] = [
            'product_type' => trim((string)($it['product_type'] ?? '')),
            'color'        => trim((string)($it['color'] ?? '')),
            'qty'          => trim((string)($it['qty'] ?? '')),
            'notes'        => trim((string)($it['notes'] ?? '')),
        ];
    }
}
if (!$oldItems) {
    $oldItems[] = ['product_type' => 'مردانه', 'color' => '', 'qty' => '100', 'notes' => ''];
}

/* ═══════════ پردازش (POST) — کاملاً محافظت‌شده ═══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($uid === null) {
        $errors['auth'] = 'ثبت سفارش اختصاصی فقط برای کاربران عضو است — ابتدا وارد حساب خود شوید یا ثبت‌نام کنید';
    }

    if (!$errors) {
        try {
            co_verify_csrf();
        } catch (Throwable $e) {
            $errors['general'] = 'نشست شما منقضی شده است — لطفاً صفحه را با Ctrl+F5 رفرش کنید و یک‌بار دیگر ارسال کنید';
        }
    }

    $items = [];
    $totalQty = 0;
    if (!$errors) {
        $posted = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
        $posted = array_slice($posted, 0, 8);
        foreach ($posted as $i => $it) {
            if (!is_array($it)) {
                continue;
            }
            $type  = trim((string)($it['product_type'] ?? ''));
            $color = trim((string)($it['color'] ?? ''));
            $qty   = (int)co_en_digits((string)($it['qty'] ?? '0'));
            $note  = trim((string)($it['notes'] ?? ''));
            if ($type === '' && $color === '' && $qty === 0) {
                continue;
            }
            if (!in_array($type, $types, true)) {
                $errors["item_$i"] = 'نوع جوراب ردیف ' . ($i + 1) . ' معتبر نیست';
            }
            if ($qty < 100 || $qty > 100000) {
                $errors["item_$i"] = 'تعداد ردیف ' . ($i + 1) . ': هر آیتم باید بین ۱۰۰ تا ۱۰۰٬۰۰۰ جفت باشد';
            }
            $totalQty += max(0, $qty);
            $items[] = ['product_type' => $type, 'color' => $color, 'qty' => $qty, 'notes' => $note];
        }
        if (!$items) {
            $errors['items'] = 'حداقل یک ردیف سفارش را کامل کنید';
        } elseif (count($errors) === 0 && $totalQty < 500) {
            $errors['items'] = 'مجموع تعداد کل سفارش باید حداقل ۵۰۰ جفت باشد (الان: ' . number_format($totalQty) . ')';
        }

        if (mb_strlen($old['name']) < 3)                              $errors['name'] = 'نام را کامل وارد کنید';
        if (!preg_match('/^09\d{9}$/', co_en_digits($old['mobile']))) $errors['mobile'] = 'شماره موبایل معتبر نیست (مثال: 09121234567)';
        if (mb_strlen($old['description']) < 10)                      $errors['description'] = 'توضیحات را کامل‌تر بنویسید';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            $stmt = db()->prepare("
                INSERT INTO custom_orders
                    (tracking_code, user_id, ref_product_id, ref_product_name, ref_product_code,
                     full_name, mobile, brand_name, description, status, status_changed_at)
                VALUES ('', ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())
            ");
            $stmt->execute([
                $uid,
                $refProduct !== null ? (int)$refProduct['id'] : null,
                $refProduct !== null ? $refProduct['name'] : null,
                $refProduct !== null ? $refProduct['code'] : null,
                $old['name'],
                co_en_digits($old['mobile']),
                $old['brand_name'] ?: null,
                $old['description'],
            ]);
            $orderId = (int)db()->lastInsertId();

            $code = co_tracking_code($orderId);
            db()->prepare('UPDATE custom_orders SET tracking_code = ? WHERE id = ?')
                ->execute([$code, $orderId]);

            $logo = co_handle_upload($_FILES['design_logo'] ?? [], $code, 'logo');
            if (!$logo['ok']) {
                $errors['design_logo'] = $logo['error'];
                throw new RuntimeException('CO_LOGO');
            }
            if ($logo['path'] !== null) {
                db()->prepare('UPDATE custom_orders SET logo_path = ? WHERE id = ?')
                    ->execute([$logo['path'], $orderId]);
            }

            $ins = db()->prepare(
                'INSERT INTO custom_order_items (custom_order_id, product_type, color, qty, notes, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $k => $it) {
                $ins->execute([
                    $orderId,
                    $it['product_type'],
                    $it['color'] ?: null,
                    $it['qty'],
                    $it['notes'] ?: null,
                    $k,
                ]);
            }

            co_insert_event(
                $orderId,
                'submitted',
                'customer',
                ($refProduct !== null
                    ? 'درخواست سفارشی‌سازی محصول: ' . $refProduct['name'] . ' (کد ' . $refProduct['code'] . ')'
                    : 'درخواست تولید اختصاصی')
                    . ' — ' . count($items) . ' ردیف، جمع ' . number_format($totalQty) . ' جفت'
                    . ($logo['path'] !== null ? ' — طرح/لوگو پیوست شد' : '')
                    . ' — ثبت با حساب کاربری'
            );

            /* ⭐ v5.1: ذخیره‌ی جمع کل جفت‌ها در ستون total_qty (اگر migration اجرا شده باشد) */
            try {
                db()->prepare('UPDATE custom_orders SET total_qty = ? WHERE id = ?')
                    ->execute([$totalQty, $orderId]);
            } catch (Throwable $e) {
                /* ستون total_qty هنوز اضافه نشده — مشکلی نیست، پنل ادمین آن را محاسبه می‌کند */
            }

            db()->commit();

            $saved     = true;
            $trackCode = $code;
            $trackUrl  = 'custom-order-track.php?code=' . rawurlencode($code)
                . '&mobile=' . rawurlencode(co_en_digits($old['mobile']));
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($e->getMessage() === 'CO_LOGO') {
                /* خطای آپلود قبلاً در $errors ثبت شده */
            } elseif (co_is_missing_table($e)) {
                $errors['general'] = 'جدول‌های ماژول هنوز ساخته نشده‌اند — فایل custom-order-schema-migration-v5.sql را یک‌بار در phpMyAdmin اجرا کنید';
                $errors['debug']   = $e->getMessage();
            } else {
                $errors['general'] = 'ثبت درخواست ممکن نشد — لطفاً دوباره تلاش کنید';
                $errors['debug']   = $e->getMessage();
            }
        }
    }

    if ($errors && $items) {
        $oldItems = array_map(static fn($it) => [
            'product_type' => $it['product_type'],
            'color'        => $it['color'],
            'qty'          => (string)$it['qty'],
            'notes'        => $it['notes'],
        ], $items);
    }
}

$pageTitle = 'سفارش تولید اختصاصی | جوراب رابطی';
ob_start();
?>

<!-- custom-order.css از وسط body به <head> منتقل شد (از طریق $pageCss قبل از require main.php) -->

<section class="page-head">
    <h1 class="section-title">تولید جوراب با برند شما</h1>
    <p class="section-sub">
        هر ردیف یک مدل جدا است (مردانه، زنانه، رنگ و…) — پس از ثبت، ادمین بررسی و پیش‌فاکتور صادر می‌کند،
        پس از پرداخت ۳۰٪ نمونه تولید می‌شود و پس از تایید نمونه و تسویه، تولید انبوه آغاز می‌گردد
    </p>
</section>

<?php if ($saved): ?>

    <div class="empty-state">
        <div class="empty-icon">✅</div>
        <h2>درخواست شما ثبت شد!</h2>
        <p class="section-sub" style="margin-bottom:8px;">
            شماره پیگیری شما: <b class="coi-track"><?= $h($trackCode) ?></b>
        </p>
        <p class="section-sub" style="margin-bottom:24px;">
            پیشرفت مراحل را هر لحظه در صفحه‌ی پیگیری ببینید — چون با حساب خودتان ثبت کرده‌اید،
            این سفارش در «پیگیری سفارش اختصاصی» بدون وارد کردن کد هم فهرست می‌شود
        </p>
        <a href="<?= $h($trackUrl) ?>" class="btn btn-orange">پیگیری مراحل سفارش ←</a>
        <a href="/" class="btn btn-ghost" style="margin-right:8px;">بازگشت به صفحه اصلی</a>
    </div>

<?php elseif ($uid === null): ?>

    <?php if (isset($errors['auth']) || isset($errors['general'])): ?>
        <div class="form-alert">⚠️ <?= $h($errors['auth'] ?? $errors['general']) ?></div>
    <?php endif; ?>

    <div class="empty-state co-auth-card">
        <div class="empty-icon">🔐</div>
        <h2>ثبت سفارش اختصاصی فقط برای کاربران عضو</h2>
        <p class="section-sub" style="max-width:560px;">
            برای اینکه بتوانید مراحل سفارش (پیش‌فاکتور، پیش‌پرداخت، تایید نمونه و تسویه) را آنلاین پیگیری کنید،
            سفارش باید با حساب کاربری ثبت شود. ورود یا ثبت‌نام کمتر از یک دقیقه طول می‌کشد.
        </p>
        <div class="co-auth-benefits">
            <span>✔ پیگیری آنلاین همه‌ی مراحل بدون وارد کردن کد</span>
            <span>✔ مشاهده پیش‌فاکتور و آپلود رسید پیش‌پرداخت و تسویه</span>
            <span>✔ تایید یا درخواست اصلاح نمونه از پنل پیگیری</span>
            <span>✔ اتصال سفارش‌های قبلی (حتی مهمان) به حساب شما</span>
        </div>
        <button type="button" class="btn btn-orange" data-auth-open="login">ورود / ثبت‌نام ←</button>
        <p class="field-hint" style="margin-top:12px;">
            قبلاً به‌عنوان مهمان سفارش داده‌اید؟ پس از ورود، صفحه‌ی
            <a href="/custom-order-track"><b>پیگیری سفارش اختصاصی</b></a>
            را باز کنید و کد پیگیری + موبایل را وارد کنید تا سفارش به حساب شما متصل شود.
        </p>
    </div>

<?php else: ?>

    <?php if (isset($errors['general'])): ?>
        <div class="form-alert">⚠️ <?= $h($errors['general']) ?></div>
        <?php if (!empty($errors['debug'])): ?>
            <details class="co-debug">
                <summary>جزئیات فنی خطا (اگر پشتیبانی خواست، همین را بفرستید)</summary>
                <code dir="ltr"><?= $h($errors['debug']) ?></code>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <div class="coi-userbar">
        <span class="coi-userbar-ico" aria-hidden="true">👤</span>
        ثبت با حساب کاربری شما<b><?= $profile['name'] !== '' ? ' — ' . $h($profile['name']) : '' ?></b>
        <a href="/custom-order-track">پیگیری سفارش‌های من (بدون نیاز به کد) ←</a>
    </div>

    <form class="co-form" id="coForm" method="post" action="" enctype="multipart/form-data" novalidate>
        <?= co_csrf_field() ?>

        <?php if ($errors && !isset($errors['general'])): ?>
            <div class="form-alert">⚠️ لطفاً خطاهای زیر را اصلاح کنید</div>
        <?php endif; ?>

        <!-- ▂▂ ارجاع از صفحه محصول ▂▂ -->
        <?php if ($refProduct !== null): ?>
            <div class="coi-ref" id="coiRefBox">
                <input type="hidden" name="ref_product_id" value="<?= (int)$refProduct['id'] ?>">
                <div class="coi-ref-img">
                    <?php if (!empty($refProduct['img'])): ?>
                        <img src="<?= $h($refProduct['img']) ?>" alt="<?= $h($refProduct['name']) ?>">
                    <?php else: ?>
                        <span>🧦</span>
                    <?php endif; ?>
                </div>
                <div class="coi-ref-info">
                    <b>سفارش اختصاصی بر اساس این محصول</b>
                    <span><?= $h($refProduct['name']) ?> — کد <?= $h($refProduct['code']) ?></span>
                    <small>خواسته‌ی شما: همین محصول با برند و جزئیات شما تولید شود (قابل حذف است)</small>
                </div>
                <button type="button" class="coi-ref-remove" id="coiRefRemove" aria-label="حذف ارجاع محصول">×</button>
            </div>
        <?php endif; ?>

        <!-- ▂▂ آیتم‌های سفارش (چند-آیتمی) ▂▂ -->
        <div class="coi-section-title">آیتم‌های سفارش <i>*</i></div>
        <?php if (isset($errors['items'])): ?>
            <span class="field-error"><?= $h($errors['items']) ?></span>
        <?php endif; ?>

        <div id="coiRows">
            <?php foreach ($oldItems as $i => $it): ?>
                <div class="coi-row">
                    <div class="coi-cell coi-cell-type">
                        <label>نوع جوراب</label>
                        <select name="items[<?= $i ?>][product_type]">
                            <?php foreach ($types as $t): ?>
                                <option value="<?= $h($t) ?>" <?= ($it['product_type'] ?? '') === $t ? 'selected' : '' ?>><?= $h($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="coi-cell">
                        <label>رنگ / طرح</label>
                        <input type="text" name="items[<?= $i ?>][color]" value="<?= $h($it['color'] ?? '') ?>" placeholder="مثلاً سرمه‌ای، راه‌راه…">
                    </div>
                    <div class="coi-cell coi-cell-qty">
                        <label>تعداد (جفت)</label>
                        <input type="number" name="items[<?= $i ?>][qty]" min="100" step="50" dir="ltr" value="<?= $h($it['qty'] ?? '100') ?>">
                    </div>
                    <div class="coi-cell">
                        <label>توضیح ردیف</label>
                        <input type="text" name="items[<?= $i ?>][notes]" value="<?= $h($it['notes'] ?? '') ?>" placeholder="اختیاری">
                    </div>
                    <button type="button" class="coi-del" aria-label="حذف ردیف" title="حذف ردیف">×</button>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="coi-row-actions">
            <button type="button" class="btn btn-ghost" id="coiAddRow">+ افزودن ردیف دیگر (رنگ/جنسیت جدید)</button>
            <span class="coi-total" id="coiTotal">جمع کل: <b>۰</b> جفت</span>
        </div>
        <span class="field-hint">حداقل هر ردیف ۱۰۰ جفت — حداقل مجموع سفارش ۵۰۰ جفت — حداکثر ۸ ردیف</span>

        <!-- ▂▂ اطلاعات تماس ▂▂ -->
        <div class="form-row" style="margin-top:20px;">
            <div class="form-field">
                <label>نام و نام خانوادگی <i>*</i></label>
                <input type="text" name="name" value="<?= $h($old['name']) ?>">
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= $h($errors['name']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label>شماره موبایل <i>*</i></label>
                <input type="tel" name="mobile" dir="ltr" maxlength="11" inputmode="numeric" value="<?= $h($old['mobile']) ?>" placeholder="09121234567">
                <?php if (isset($errors['mobile'])): ?><span class="field-error"><?= $h($errors['mobile']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-field">
            <label>برند مورد نظر</label>
            <input type="text" name="brand_name" value="<?= $h($old['brand_name']) ?>" placeholder="نام برند شما (اختیاری)">
        </div>

        <div class="form-field">
            <label>توضیحات کلی <i>*</i></label>
            <textarea name="description" rows="5"
                placeholder="جنس نخ، بسته‌بندی، لیبل و برچسب برند، زمان‌بندی دلخواه و هر نکته‌ی مهم…"><?= $h($old['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?><span class="field-error"><?= $h($errors['description']) ?></span><?php endif; ?>
        </div>

        <!-- ▂▂ آپلود طرح / لوگو (اختیاری — درگ‌اند‌دراپ) ▂▂ -->
        <div class="form-field">
            <label>بارگذاری طرح یا لوگو <span class="co-optional">(اختیاری)</span></label>
            <div class="co-drop">
                <input type="file" name="design_logo" id="coFileLogo" class="co-file-input"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <label class="co-dropzone" for="coFileLogo">
                    <span class="co-drop-ico" aria-hidden="true">🧦</span>
                    <b>فایل طرح / لوگو را اینجا رها کنید</b>
                    <span class="co-drop-or">یا کلیک کنید و از کامپیوتر انتخاب کنید</span>
                    <small>JPG / PNG / WebP — حداکثر ۴ مگابایت</small>
                </label>
                <div class="co-file-chip" hidden>
                    <span class="co-chip-ico" aria-hidden="true">📄</span>
                    <span class="co-chip-name" dir="ltr"></span>
                    <span class="co-chip-size"></span>
                    <button type="button" class="co-chip-clear" aria-label="حذف فایل انتخابی" title="حذف فایل">×</button>
                </div>
                <div class="co-file-error" hidden></div>
            </div>
            <span class="field-hint">اگر الان آماده نیست، بعداً از طریق کارشناس هم می‌توانید ارسال کنید</span>
            <?php if (isset($errors['design_logo'])): ?><span class="field-error"><?= $h($errors['design_logo']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn btn-orange co-submit">ثبت درخواست تولید ←</button>

        <p class="form-privacy">
            با ثبت درخواست، روال کار در ۱۱ مرحله پیش می‌رود: ادمین بررسی و پیش‌فاکتور صادر می‌کند →
            شما ۳۰٪ پیش‌پرداخت می‌دهید → نمونه تولید و ارسال می‌شود → شما نمونه را تایید یا درخواست اصلاح می‌دهید →
            تسویه‌ی مابه‌التفاوت → تولید انبوه → کنترل کیفیت → ارسال → تحویل و فاکتور نهایی.
            توافق‌نامه و کد ملی فقط در صورت نیاز ادمین (مرحله‌ی اختیاری) از شما گرفته می‌شود.
        </p>
    </form>

<?php endif; ?>

<script src="/assets/js/custom-order.js?v=5" defer></script>

<?php
$content = ob_get_clean();
// custom-order.css: قوانین فرم سفارش سفارشی — فقط صفحه custom-order
$pageCss = ['/assets/css/custom-order.css?v=1005'];
require ROOT_PATH . '/views/layouts/main.php';
