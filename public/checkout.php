<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
$db     = db();
$errors = [];

/* ─── ثبت نهایی سفارش (POST) ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $shippingName    = trim($_POST['shipping_name'] ?? '');
    $shippingMobile  = trim($_POST['shipping_mobile'] ?? '');
    $shippingMobile  = strtr($shippingMobile, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $province        = trim($_POST['province'] ?? '');
    $city            = trim($_POST['city'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $postal          = trim($_POST['postal'] ?? '');
    $note            = trim($_POST['note'] ?? '');

    if (mb_strlen($shippingName) < 3)                              $errors[] = 'نام گیرنده را کامل وارد کنید';
    if (!preg_match('/^09\d{9}$/', $shippingMobile))               $errors[] = 'موبایل گیرنده معتبر نیست';
    if ($province === '' || $city === '')                          $errors[] = 'استان و شهر را وارد کنید';
    if (mb_strlen($address) < 10)                                  $errors[] = 'آدرس کامل پستی را وارد کنید';
    if ($postal !== '' && !preg_match('/^\d{10}$/', $postal))      $errors[] = 'کد پستی باید ۱۰ رقم باشد';

    if (!$errors) {
        try {
            $db->beginTransaction();

            /* ۱) قفل ردیف‌های سبد و موجودی — FOR UPDATE */
            $stmt = $db->prepare("
                SELECT ci.id, ci.qty,
                       p.id AS product_id, p.code, p.name, p.base_moq, p.is_active,
                       pr.price,
                       COALESCE(inv.qty_available, 0) AS stock
                FROM cart_items ci
                JOIN carts c ON c.id = ci.cart_id
                JOIN products p ON p.id = ci.product_id
                LEFT JOIN product_prices pr
                    ON pr.product_id = p.id AND pr.customer_group='wholesale' AND pr.min_qty=1
                LEFT JOIN inventory inv ON inv.product_id = p.id
                WHERE c.user_id = ? AND c.status = 'active'
                FOR UPDATE
            ");
            $stmt->execute([$userId]);
            $items = $stmt->fetchAll();

            if (!$items) throw new RuntimeException('سبد شما خالی است');

            /* ۲) اعتبارسنجی نهایی سرور: فعال بودن، MOQ، موجودی، قیمت */
            $subtotal = 0;
            foreach ($items as $it) {
                if (!$it['is_active'])          throw new RuntimeException('محصول «' . $it['name'] . '» غیرفعال شده است');
                if ($it['price'] === null)      throw new RuntimeException('قیمت «' . $it['name'] . '» نیاز به استعلام دارد — از پشتیبانی پیگیری کنید');
                if ((int)$it['qty'] < (int)$it['base_moq'])
                    throw new RuntimeException('تعداد «' . $it['name'] . '» کمتر از حداقل سفارش (' . (int)$it['base_moq'] . ' جفت) است');
                if ((int)$it['stock'] < (int)$it['qty'])
                    throw new RuntimeException('موجودی «' . $it['name'] . '» کافی نیست — فقط ' . number_format((int)$it['stock']) . ' جفت موجود است');
                $subtotal += (int)$it['price'] * (int)$it['qty'];
            }

            /* ۳) ساخت سفارش */
            $companyId = null;
            $stmt = $db->prepare("SELECT company_id FROM company_users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $companyId = $stmt->fetchColumn() ?: null;

            $orderNumber = 'ORD-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $db->prepare("
                INSERT INTO orders
                    (order_number, user_id, company_id, subtotal, shipping_cost, total,
                     shipping_name, shipping_mobile, shipping_province, shipping_city,
                     shipping_address, shipping_postal, customer_note)
                VALUES (?,?,?,?,0,?,?,?,?,?,?,?,?)
            ")->execute([
                $orderNumber,
                $userId,
                $companyId,
                $subtotal,
                $subtotal,
                $shippingName,
                $shippingMobile,
                $province,
                $city,
                $address,
                $postal ?: null,
                $note ?: null
            ]);
            $orderId = (int)$db->lastInsertId();

            /* ۴) اقلام + snapshot قیمت + کسر موجودی */
            $insItem = $db->prepare("
                INSERT INTO order_items
                    (order_id, product_id, product_code, product_name, qty, unit_price, line_total)
                VALUES (?,?,?,?,?,?,?)
            ");
            $cutStock = $db->prepare("
                UPDATE inventory
                SET qty_available = qty_available - ?
                WHERE product_id = ? AND qty_available >= ?
            ");

            foreach ($items as $it) {
                $line = (int)$it['price'] * (int)$it['qty'];
                $insItem->execute([
                    $orderId,
                    $it['product_id'],
                    $it['code'],
                    $it['name'],
                    (int)$it['qty'],
                    (int)$it['price'],
                    $line
                ]);
                $cutStock->execute([(int)$it['qty'], $it['product_id'], (int)$it['qty']]);
            }

            /* ۵) سبد به سفارش تبدیل شد */
            $db->prepare("UPDATE carts SET status='converted' WHERE user_id=? AND status='active'")
                ->execute([$userId]);
            $db->prepare("DELETE ci FROM cart_items ci
                          JOIN carts c ON c.id = ci.cart_id
                          WHERE c.user_id = ?")->execute([$userId]);

            $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                VALUES (?, 'order.create', 'order', ?, ?)
            ")->execute([$userId, $orderId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

            $db->commit();
            header('Location: /payment?order=' . urlencode($orderNumber));
            exit;
        } catch (RuntimeException $e) {
            $db->rollBack();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = 'خطای سرور در ثبت سفارش — دوباره تلاش کنید';
        }
    }
}

/* ─── نمایش فرم با اقلام سبد ─── */
$stmt = $db->prepare("
    SELECT ci.id, ci.qty, p.code, p.name, pr.price
    FROM cart_items ci
    JOIN carts c ON c.id = ci.cart_id
    JOIN products p ON p.id = ci.product_id
    LEFT JOIN product_prices pr ON pr.product_id = p.id AND pr.customer_group='wholesale' AND pr.min_qty=1
    WHERE c.user_id = ? AND c.status='active'
");
$stmt->execute([$userId]);
$items = $stmt->fetchAll();

if (!$items && !$errors) {
    header('Location: /cart');
    exit;
}

$total = 0;
foreach ($items as $it) $total += ($it['price'] ?? 0) * (int)$it['qty'];

$pageTitle = 'ثبت سفارش | رابطی';
ob_start();
?>

<nav class="breadcrumb">
    <a href="/">خانه</a> › <a href="/cart">سبد سفارش</a> › <span>ثبت سفارش</span>
</nav>

<section class="catalog-head">
    <h1 class="catalog-title">ثبت سفارش</h1>
    <p class="catalog-sub">اطلاعات ارسال را تکمیل کنید — سفارش پس از بررسی کارشناس تأیید نهایی می‌شود</p>
</section>

<?php if ($errors): ?>
    <div class="form-alert error" style="display:block;">
        <?php foreach ($errors as $er): ?>⚠️ <?= $h($er) ?><br><?php endforeach; ?>
    <a href="/cart" style="text-decoration:underline;">بازگشت به سبد برای اصلاح</a>
    </div>
<?php endif; ?>

<?php if ($items): ?>

    <div class="checkout-grid">

        <!-- خلاصه سفارش -->
        <div class="checkout-summary">
            <b>خلاصه سفارش</b>
            <?php foreach ($items as $it): ?>
                <div class="cs-row">
                    <span><?= $h($it['name']) ?> <small>× <?= (int)$it['qty'] ?></small></span>
                    <b><?= $it['price'] !== null ? number_format((int)$it['price'] * (int)$it['qty']) : '—' ?></b>
                </div>
            <?php endforeach; ?>
            <div class="cs-row cs-total">
                <span>جمع کل</span>
                <b><?= number_format((float)$total) ?> تومان</b>
            </div>
            <a href="/cart" class="cs-edit">ویرایش سبد ←</a>
        </div>

        <!-- فرم ارسال -->
        <form method="post" class="form-card checkout-form">

            <div class="form-row">
                <div class="form-field">
                    <label>نام گیرنده <i>*</i></label>
                    <input type="text" name="shipping_name" value="<?= $h($_POST['shipping_name'] ?? '') ?>" required>
                </div>
                <div class="form-field">
                    <label>موبایل گیرنده <i>*</i></label>
                    <input type="tel" name="shipping_mobile" dir="ltr" value="<?= $h($_POST['shipping_mobile'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>استان <i>*</i></label>
                    <input type="text" name="province" value="<?= $h($_POST['province'] ?? '') ?>" required>
                </div>
                <div class="form-field">
                    <label>شهر <i>*</i></label>
                    <input type="text" name="city" value="<?= $h($_POST['city'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-field">
                <label>آدرس کامل پستی <i>*</i></label>
                <textarea name="address" rows="3" required><?= $h($_POST['address'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>کد پستی</label>
                    <input type="text" name="postal" dir="ltr" value="<?= $h($_POST['postal'] ?? '') ?>" inputmode="numeric" maxlength="10">
                </div>
               </div>

            <div class="form-field">
                <label>توضیحات سفارش</label>
                <textarea name="note" rows="2" placeholder="هر نکته‌ای برای کارشناس فروش..."><?= $h($_POST['note'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary co-submit">
                ثبت نهایی سفارش ←
            </button>
            <p class="form-hint" style="text-align:center;">
                با ثبت سفارش، موجودی انبار برای شما رزرو می‌شود و کارشناس فروش برای هماهنگی ارسال و پرداخت تماس می‌گیرد.
            </p>

        </form>

    </div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
