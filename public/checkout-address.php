<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/AddressService.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
$db = db();

/* سبد باید پر باشد */
$stmt = $db->prepare("SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id
                      WHERE c.user_id=? AND c.status='active'");
$stmt->execute([$userId]);
if (!$stmt->fetchColumn()) {
    header('Location: /cart');
    exit;
}

$error = '';

/* ─── انتخاب آدرس ذخیره‌شده ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'pick') {
    $addressId = (int)($_POST['address_id'] ?? 0);
    $addr = AddressService::get($userId, $addressId);
    if ($addr) {
        $_SESSION['checkout_address_id'] = $addressId;
        header('Location: /checkout-shipping');
        exit;
    }
    $error = 'آدرس انتخابی یافت نشد';
}

/* ─── افزودن آدرس جدید ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add') {
    try {
        $addressId = AddressService::create($userId, [
            'label'         => $_POST['label'] ?? '',
            'province'      => $_POST['province'] ?? '',
            'city'          => $_POST['city'] ?? '',
            'address'       => $_POST['address'] ?? '',
            'postal_code'   => trim($_POST['postal_code'] ?? ''),
            'plate_no'      => trim($_POST['plate_no'] ?? ''),
            'unit_no'       => trim($_POST['unit_no'] ?? ''),
            'receiver_name' => trim($_POST['receiver_name'] ?? ''),
            'receiver_mobile' => strtr(
                trim($_POST['receiver_mobile'] ?? ''),
                ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']
            ),
            'is_default'    => isset($_POST['is_default']),
        ]);
        $_SESSION['checkout_address_id'] = $addressId;
        header('Location: /checkout-shipping');
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$addresses = AddressService::list($userId);
$selectedId = (int)($_SESSION['checkout_address_id'] ?? 0);

$pageTitle = 'آدرس ارسال | رابطی';
ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <a href="/cart">سبد</a> › <span>آدرس ارسال</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">آدرس ارسال سفارش</h1>
    <p class="catalog-sub">سفارش به کدام آدرس ارسال شود؟</p>
</section>

<?php if ($error): ?>
    <div class="form-alert error" style="display:block;">⚠️ <?= $h($error) ?></div>
<?php endif; ?>

<?php if ($addresses): ?>
    <div class="addr-list">

        <?php foreach ($addresses as $a): ?>
            <div class="addr-card <?= $a['is_default'] ? 'is-default' : '' ?>">
                <div class="addr-card-body">
                    <b>🏪 <?= $h($a['label']) ?> <?= $a['is_default'] ? '<span class="st-badge st-converted">پیش‌فرض</span>' : '' ?></b>
                    <p>
                        <?= $h($a['province']) ?>، <?= $h($a['city']) ?> —
                        <?= $h($a['address']) ?>
                        <?= $a['plate_no'] ? '، پلاک ' . $h($a['plate_no']) : '' ?>
                        <?= $a['unit_no'] ? '، واحد ' . $h($a['unit_no']) : '' ?>
                    </p>
                    <?php if ($a['postal_code']): ?>
                        <small>کدپستی: <span dir="ltr"><?= $h($a['postal_code']) ?></span></small>
                    <?php endif; ?>
                </div>
                <form method="post">
                    <input type="hidden" name="do" value="pick">
                    <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-primary">همین آدرس ←</button>
                </form>
            </div>
        <?php endforeach; ?>

    </div>
<?php endif; ?>

<!-- ▂▂▂ افزودن آدرس جدید ▂▂▂ -->
<div class="addr-new-wrap">
    <details <?= $addresses ? '' : 'open' ?>>
        <summary><?= $addresses ? '+ افزودن آدرس جدید' : 'ثبت آدرس ارسال' ?></summary>

        <form method="post" class="co-form">
            <input type="hidden" name="do" value="add">

            <div class="form-row">
                <div class="form-field">
                    <label>عنوان آدرس</label>
                    <input type="text" name="label" placeholder="مثلاً: فروشگاه / انبار">
                </div>
                <div class="form-field">
                    <label>استان <i>*</i></label>
                    <input type="text" name="province" required placeholder="تهران">
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>شهر <i>*</i></label>
                    <input type="text" name="city" required>
                </div>
                <div class="form-field">
                    <label>کد پستی</label>
                    <input type="text" name="postal_code" dir="ltr" inputmode="numeric" maxlength="10">
                </div>
            </div>

            <div class="form-field">
                <label>آدرس کامل <i>*</i></label>
                <textarea name="address" rows="2" required placeholder="خیابان، کوچه، ..."></textarea>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>پلاک</label>
                    <input type="text" name="plate_no" dir="ltr">
                </div>
                <div class="form-field">
                    <label>واحد</label>
                    <input type="text" name="unit_no" dir="ltr">
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>نام گیرنده (اختیاری)</label>
                    <input type="text" name="receiver_name" placeholder="خالی = خود شما">
                </div>
                <div class="form-field">
                    <label>موبایل گیرنده (اختیاری)</label>
                    <input type="tel" name="receiver_mobile" dir="ltr">
                </div>
            </div>

            <label class="form-check">
                <input type="checkbox" name="is_default" value="1" checked>
                این آدرس پیش‌فرض سفارش‌های بعدی باشد
            </label>

            <button type="submit" class="btn btn-primary co-submit">ذخیره و ادامه ←</button>
        </form>
    </details>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
