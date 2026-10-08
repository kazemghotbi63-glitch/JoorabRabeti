<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

$errors = [];
$saved  = false;

/* ─────────── پردازش فرم (POST) ─────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();


    // مقادیر
    $name    = trim($_POST['name'] ?? '');
    $mobile  = trim($_POST['mobile'] ?? '');
    $brand   = trim($_POST['brand_name'] ?? '');
    $ptype   = trim($_POST['product_type'] ?? '');
    $qty     = (int)($_POST['qty'] ?? 0);
    $desc    = trim($_POST['description'] ?? '');

    // ✂️ اعتبارسنجی سمت سرور — منبع حقیقت همین‌جاست
    if (mb_strlen($name) < 3)          $errors['name'] = 'نام را کامل وارد کنید';
    if (!preg_match('/^09\d{9}$/', $mobile)) $errors['mobile'] = 'شماره موبایل معتبر نیست (مثال: 09121234567)';
    if ($qty < 500)                    $errors['qty'] = 'حداقل سفارش تولید اختصاصی ۵۰۰ جفت است';
    if (mb_strlen($desc) < 10)         $errors['description'] = 'توضیحات را کامل‌تر بنویسید';

    // ذخیره — فقط Prepared Statement
    if (!$errors) {
        $stmt = db()->prepare("
            INSERT INTO custom_order_requests (name, mobile, brand_name, product_type, qty, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $mobile, $brand ?: null, $ptype ?: null, $qty, $desc]);
        $saved = true;
    }
}

$pageTitle = 'سفارش تولید اختصاصی | جوراب رابطی';
ob_start();
?>

<section class="page-head">
    <h1 class="section-title">تولید جوراب با برند شما</h1>
    <p class="section-sub">
        فرم را پر کنید — کارشناس ما حداکثر تا یک روز کاری برای مذاکره و نمونه با شما تماس می‌گیرد
    </p>
</section>

<?php if ($saved): ?>

    <!-- ▂▂▂ پیام موفقیت ▂▂▂ -->
    <div class="empty-state">
        <div class="empty-icon">✅</div>
        <h2>درخواست شما ثبت شد!</h2>
        <p class="section-sub" style="margin-bottom:24px;">
            شماره پیگیری شما: <b>CR-<?= str_pad((string)(db()->query('SELECT LAST_INSERT_ID()')->fetchColumn()), 5, '0', STR_PAD_LEFT) ?></b>
        </p>
        <a href="/" class="btn btn-orange">بازگشت به صفحه اصلی</a>
    </div>

<?php else: ?>

    <!-- ▂▂▂ فرم سفارش ▂▂▂ -->
    <form class="co-form" method="post" action="/custom-order" novalidate>
        <?= csrf_field() ?>

        <?php if ($errors): ?>
            <div class="form-alert">
                ⚠️ لطفاً خطاهای زیر را اصلاح کنید
            </div>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-field">
                <label>نام و نام خانوادگی <i>*</i></label>
                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                    placeholder="مثلاً: کاظم قوطبی">
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= $errors['name'] ?></span><?php endif; ?>
            </div>

            <div class="form-field">
                <label>شماره موبایل <i>*</i></label>
                <input type="tel" name="mobile" dir="ltr" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>"
                    placeholder="09121234567">
                <?php if (isset($errors['mobile'])): ?><span class="field-error"><?= $errors['mobile'] ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-field">
                <label>برند مورد نظر</label>
                <input type="text" name="brand_name" value="<?= htmlspecialchars($_POST['brand_name'] ?? '') ?>"
                    placeholder="نام برند شما (اختیاری)">
            </div>

            <div class="form-field">
                <label>نوع جوراب</label>
                <select name="product_type">
                    <?php
                    $types = ['مردانه', 'زنانه', 'بچگانه', 'ورزشی', 'پشمی', 'سایر'];
                    $current = $_POST['product_type'] ?? '';
                    foreach ($types as $t):
                    ?>
                        <option value="<?= $t ?>" <?= $current === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-field">
            <label>تعداد تقریبی (جفت) <i>*</i></label>
            <input type="number" name="qty" min="500" step="100" dir="ltr"
                value="<?= htmlspecialchars($_POST['qty'] ?? '500') ?>">
            <span class="field-hint">حداقل سفارش تولید اختصاصی: ۵۰۰ جفت</span>
            <?php if (isset($errors['qty'])): ?><span class="field-error"><?= $errors['qty'] ?></span><?php endif; ?>
        </div>

        <div class="form-field">
            <label>توضیحات <i>*</i></label>
            <textarea name="description" rows="5"
                placeholder="جزئیات محصول، جنس نخ، رنگ‌بندی، نوع بسته‌بندی و هر نکته‌ای که مهم است..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            <?php if (isset($errors['description'])): ?><span class="field-error"><?= $errors['description'] ?></span><?php endif; ?>
        </div>

        <!-- آپلود طرح/لوگو — در فاز بک‌اند با اعتبارسنجی امن فعال می‌شود -->
        <div class="form-field form-disabled-field">
            <label>بارگذاری طرح یا لوگو</label>
            <input type="file" disabled>
            <span class="field-hint">🔧 پس از ثبت درخواست، طرح را در واتساپ ارسال می‌کنیم (فعال‌سازی در فاز بعد)</span>
        </div>

        <button type="submit" class="btn btn-orange co-submit">ثبت درخواست تولید ←</button>

        <p class="form-privacy">
            با ثبت درخواست، اطلاعات شما فقط برای تماس کارشناسان ما استفاده می‌شود.
        </p>
    </form>

<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
