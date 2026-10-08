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
$db = db();

/* ============================================================
 * ابزارهای قیمت‌گذاری پلکانی
 * ============================================================ */

/**
 * تمام پله‌های قیمت یک محصول را می‌خواند.
 */
function getProductPriceTiers(PDO $db, int $productId): array
{
    $stmt = $db->prepare("
        SELECT min_qty, price
        FROM product_prices
        WHERE product_id = ?
          AND customer_group = 'wholesale'
        ORDER BY min_qty ASC
    ");

    $stmt->execute([$productId]);

    return $stmt->fetchAll();
}


/**
 * قیمت صحیح بر اساس تعداد خرید.
 *
 * مثال:
 *
 * 1     => 50,000
 * 24    => 47,500
 * 48    => 45,000
 * 100   => 40,000
 *
 * همیشه آخرین پله‌ای انتخاب می‌شود که min_qty آن
 * کمتر یا مساوی تعداد خرید باشد.
 */
function calculateCartPrice(array $tiers, int $qty): array
{
    $basePrice = null;
    $effectivePrice = null;
    $discountPercent = 0;
    $discountAmount = 0;
    $currentTierQty = 1;

    $nextTier = null;

    foreach ($tiers as $tier) {

        $minQty = (int)$tier['min_qty'];
        $tierPrice = (float)$tier['price'];

        if ($minQty === 1) {
            $basePrice = $tierPrice;
        }

        if ($minQty <= $qty) {

            $effectivePrice = $tierPrice;
            $currentTierQty = $minQty;
        } elseif ($nextTier === null) {

            $nextTier = [
                'min_qty' => $minQty,
                'price'   => $tierPrice,
            ];
        }
    }

    /*
     * اگر قیمت پایه وجود نداشت،
     * قیمت اولین پله را به عنوان fallback استفاده می‌کنیم.
     */
    if ($basePrice === null && !empty($tiers)) {
        $basePrice = (float)$tiers[0]['price'];
    }

    /*
     * اگر تعداد کمتر از اولین min_qty بود،
     * قیمت پایه استفاده می‌شود.
     */
    if ($effectivePrice === null) {
        $effectivePrice = $basePrice;
    }

    if ($basePrice !== null && $effectivePrice !== null && $basePrice > 0) {

        $discountAmount = max(
            0,
            $basePrice - $effectivePrice
        );

        $discountPercent = round(
            ($discountAmount / $basePrice) * 100,
            2
        );
    }

    return [
        'base_price'       => $basePrice,
        'unit_price'       => $effectivePrice,
        'discount_amount'  => $discountAmount,
        'discount_percent' => $discountPercent,
        'current_tier_qty' => $currentTierQty,
        'next_tier'        => $nextTier,
    ];
}


/**
 * اعتبارسنجی تعداد یک محصول.
 */
function validateCartQty(
    int $qty,
    int $moq,
    int $stock
): array {

    if ($qty < $moq) {
        return [
            'ok'    => false,
            'error' => 'حداقل سفارش این محصول ' . number_format($moq) . ' جفت است.',
        ];
    }

    if ($qty > $stock) {
        return [
            'ok'    => false,
            'error' => 'موجودی این محصول فقط ' . number_format($stock) . ' جفت است.',
        ];
    }

    return [
        'ok'    => true,
        'error' => null,
    ];
}


/* ============================================================
 * تغییر تعداد / حذف
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $do = $_POST['do'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);

    /*
     * Object-Level Authorization
     *
     * فقط آیتم متعلق به سبد همین کاربر قابل تغییر است.
     */
    $stmt = $db->prepare("
        SELECT
            ci.id,
            ci.product_id,
            ci.qty,
            p.base_moq,
            p.is_active,
            COALESCE(inv.qty_available, 0) AS stock
        FROM cart_items ci
        JOIN carts c
            ON c.id = ci.cart_id
        JOIN products p
            ON p.id = ci.product_id
        LEFT JOIN inventory inv
            ON inv.product_id = p.id
        WHERE ci.id = ?
          AND c.user_id = ?
          AND c.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        $itemId,
        $userId
    ]);

    $cartItem = $stmt->fetch();

    if (!$cartItem) {

        /*
         * اگر درخواست AJAX باشد JSON برگردان.
         */
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(404);

            echo json_encode([
                'ok' => false,
                'error' => 'آیتم سبد پیدا نشد.'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        header('Location: /cart');
        exit;
    }


    /* ========================================================
     * حذف
     * ======================================================== */

    if ($do === 'remove') {

        $db->prepare("
            DELETE FROM cart_items
            WHERE id = ?
        ")->execute([
            $itemId
        ]);

        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {

            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'ok' => true,
                'removed' => true
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        header('Location: /cart');
        exit;
    }


    /* ========================================================
     * تغییر تعداد
     * ======================================================== */

    if ($do === 'update') {

        $qty = (int)($_POST['qty'] ?? 0);

        /*
         * صفر یعنی حذف آیتم.
         */
        if ($qty <= 0) {

            $db->prepare("
                DELETE FROM cart_items
                WHERE id = ?
            ")->execute([
                $itemId
            ]);

            if (
                isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {

                header('Content-Type: application/json; charset=utf-8');

                echo json_encode([
                    'ok' => true,
                    'removed' => true
                ], JSON_UNESCAPED_UNICODE);

                exit;
            }

            header('Location: /cart');
            exit;
        }


        $moq = max(
            1,
            (int)$cartItem['base_moq']
        );

        $stock = max(
            0,
            (int)$cartItem['stock']
        );


        $validation = validateCartQty(
            $qty,
            $moq,
            $stock
        );


        if (!$validation['ok']) {

            if (
                isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {

                header('Content-Type: application/json; charset=utf-8');

                http_response_code(422);

                echo json_encode([
                    'ok' => false,
                    'error' => $validation['error'],
                    'min' => $moq,
                    'max' => $stock
                ], JSON_UNESCAPED_UNICODE);

                exit;
            }

            /*
             * در درخواست عادی، تعداد را تغییر نمی‌دهیم.
             */
            header('Location: /cart');
            exit;
        }


        /*
         * اگر محصول غیرفعال شده باشد،
         * اجازه تغییر/ثبت جدید نمی‌دهیم.
         */
        if (!(int)$cartItem['is_active']) {

            if (
                isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {

                header('Content-Type: application/json; charset=utf-8');

                http_response_code(422);

                echo json_encode([
                    'ok' => false,
                    'error' => 'این محصول دیگر فعال نیست.'
                ], JSON_UNESCAPED_UNICODE);

                exit;
            }

            header('Location: /cart');
            exit;
        }


        $db->prepare("
            UPDATE cart_items
            SET qty = ?
            WHERE id = ?
        ")->execute([
            $qty,
            $itemId
        ]);


        /*
         * پاسخ AJAX
         */
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {

            $tiers = getProductPriceTiers(
                $db,
                (int)$cartItem['product_id']
            );

            $pricing = calculateCartPrice(
                $tiers,
                $qty
            );

            /* ⭐ محاسبه‌ی تعداد کل سبد برای Badge */
            $totalStmt = $db->prepare("
                SELECT COALESCE(SUM(ci.qty), 0)
                FROM cart_items ci
                JOIN carts c ON c.id = ci.cart_id
                WHERE c.user_id = ? AND c.status = 'active'
            ");
            $totalStmt->execute([$userId]);
            $totalCartCount = (int)$totalStmt->fetchColumn();

            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'ok'                => true,
                'qty'               => $qty,
                'count'             => $totalCartCount,
                'unit_price'        => $pricing['unit_price'],
                'discount_percent'  => $pricing['discount_percent'],
                'line_total'        => $pricing['unit_price'] !== null
                    ? $pricing['unit_price'] * $qty
                    : 0
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        header('Location: /cart');
        exit;
    }


    /*
     * درخواست ناشناخته
     */
    header('Location: /cart');
    exit;
}


/* ============================================================
 * اقلام سبد
 *
 * توجه:
 * اینجا دیگر product_prices JOIN مستقیم ندارد.
 * چون هر محصول چند پله قیمت دارد.
 * ============================================================ */

$stmt = $db->prepare("
    SELECT
        ci.id,
        ci.qty,

        p.id AS product_id,
        p.code,
        p.name,
        p.slug,
        p.base_moq,
        p.is_active,

        COALESCE(inv.qty_available, 0) AS stock,

        (
            SELECT image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img

    FROM cart_items ci

    JOIN carts c
        ON c.id = ci.cart_id

    JOIN products p
        ON p.id = ci.product_id

    LEFT JOIN inventory inv
        ON inv.product_id = p.id

    WHERE c.user_id = ?
      AND c.status = 'active'

    ORDER BY ci.added_at DESC
");

$stmt->execute([
    $userId
]);

$items = $stmt->fetchAll();


/* ============================================================
 * محاسبه هوشمند قیمت تمام اقلام
 * ============================================================ */

$totalBase = 0;
$totalFinal = 0;
$totalDiscount = 0;
$totalQty = 0;

foreach ($items as &$it) {

    $qty = (int)$it['qty'];

    $tiers = getProductPriceTiers(
        $db,
        (int)$it['product_id']
    );

    $pricing = calculateCartPrice(
        $tiers,
        $qty
    );

    $it['base_price'] = $pricing['base_price'];
    $it['unit_price'] = $pricing['unit_price'];
    $it['discount_amount'] = $pricing['discount_amount'];
    $it['discount_percent'] = $pricing['discount_percent'];
    $it['current_tier_qty'] = $pricing['current_tier_qty'];
    $it['next_tier'] = $pricing['next_tier'];

    $baseLineTotal = (
        $pricing['base_price'] !== null
        ? $pricing['base_price'] * $qty
        : 0
    );

    $finalLineTotal = (
        $pricing['unit_price'] !== null
        ? $pricing['unit_price'] * $qty
        : 0
    );

    $lineDiscount = max(
        0,
        $baseLineTotal - $finalLineTotal
    );

    $it['base_line_total'] = $baseLineTotal;
    $it['line_total'] = $finalLineTotal;
    $it['line_discount'] = $lineDiscount;

    $totalQty += $qty;
    $totalBase += $baseLineTotal;
    $totalFinal += $finalLineTotal;
    $totalDiscount += $lineDiscount;
}

unset($it);


/* ============================================================
 * بررسی نهایی سبد
 * ============================================================ */

$hasIssue = false;

foreach ($items as &$it) {

    $qty = (int)$it['qty'];
    $moq = max(1, (int)$it['base_moq']);
    $stock = (int)$it['stock'];

    $it['issue'] = null;

    if (!(int)$it['is_active']) {

        $it['issue'] = 'این محصول دیگر فعال نیست.';
        $hasIssue = true;
    } elseif ($it['unit_price'] === null) {

        $it['issue'] = 'قیمت این محصول مشخص نشده است.';
        $hasIssue = true;
    } elseif ($qty < $moq) {

        $it['issue'] =
            'حداقل سفارش این محصول ' .
            number_format($moq) .
            ' جفت است.';

        $hasIssue = true;
    } elseif ($stock < $qty) {

        $it['issue'] =
            'موجودی این محصول فقط ' .
            number_format($stock) .
            ' جفت است.';

        $hasIssue = true;
    }
}

unset($it);


/* ============================================================
 * عنوان صفحه
 * ============================================================ */

$pageTitle = 'سبد سفارش | رابطی';
$pageDescription = 'سبد خرید عمده جوراب — رابطی';

ob_start();
?>

<nav class="breadcrumb">
    <a href="/">خانه</a>
    ›
    <span>سبد سفارش</span>
</nav>


<section class="catalog-head">

    <h1 class="catalog-title">
        سبد سفارش شما
    </h1>

    <p class="catalog-sub">
        <?= number_format(count($items)) ?> قلم کالا
        —
        قیمت‌گذاری همکاری و پلکانی
    </p>

</section>


<?php if ($items): ?>

    <div class="cart-list">


        <?php foreach ($items as $it): ?>

            <?php
            $qty = (int)$it['qty'];
            $moq = max(1, (int)$it['base_moq']);
            $stock = (int)$it['stock'];

            $unitPrice = $it['unit_price'];
            $basePrice = $it['base_price'];

            $lineTotal = (float)$it['line_total'];
            $lineDiscount = (float)$it['line_discount'];

            $nextTier = $it['next_tier'];

            $hasNextTier =
                is_array($nextTier) &&
                isset($nextTier['min_qty']);

            $remainingToNext = $hasNextTier
                ? max(
                    0,
                    (int)$nextTier['min_qty'] - $qty
                )
                : 0;
            ?>


            <article
                class="cart-item"
                data-cart-item="<?= (int)$it['id'] ?>">


                <!-- =================================================
                     تصویر
                     ================================================= -->

                <a
                    href="/product/<?= $h($it['slug']) ?>"
                    class="cart-item-img">

                    <?php if (!empty($it['img'])): ?>

                        <img
                            src="<?= $h($it['img']) ?>"
                            alt="<?= $h($it['name']) ?>"
                            loading="lazy">

                    <?php else: ?>

                        <span>🧦</span>

                    <?php endif; ?>

                </a>


                <!-- =================================================
                     اطلاعات محصول
                     ================================================= -->

                <div class="cart-item-info">

                    <h3>
                        <a
                            href="/product/<?= $h($it['slug']) ?>">
                            <?= $h($it['name']) ?>
                        </a>
                    </h3>


                    <span class="card-attr">

                        کد <?= $h($it['code']) ?>

                        ·

                        حداقل <?= number_format($moq) ?> جفت

                    </span>


                    <!-- =================================================
                         قیمت
                         ================================================= -->

                    <div class="cart-item-price">

                        <?php if ($unitPrice !== null): ?>

                            <b>
                                <?= number_format((float)$unitPrice) ?>
                                تومان
                            </b>

                            <?php if ($it['discount_percent'] > 0): ?>

                                <small
                                    style="
                                        display:block;
                                        margin-top:4px;
                                    ">
                                    <?= number_format((float)$it['discount_percent'], 2) ?>٪
                                    تخفیف پلکانی اعمال شد
                                </small>

                            <?php else: ?>

                                <small>
                                    قیمت همکاری
                                </small>

                            <?php endif; ?>

                        <?php else: ?>

                            <b class="cart-no-price">
                                قیمت نیاز به استعلام دارد
                            </b>

                        <?php endif; ?>

                    </div>


                    <!-- =================================================
                         قیمت پایه
                         ================================================= -->

                    <?php if (
                        $basePrice !== null &&
                        $unitPrice !== null &&
                        $unitPrice < $basePrice
                    ): ?>

                        <div
                            style="
                                font-size:.78rem;
                                margin-top:4px;
                                opacity:.75;
                            ">

                            قیمت پایه:

                            <span style="text-decoration:line-through;">
                                <?= number_format((float)$basePrice) ?>
                                تومان
                            </span>

                        </div>

                    <?php endif; ?>


                    <!-- =================================================
                         موجودی
                         ================================================= -->

                    <span
                        class="stock <?= $stock >= $qty ? 'in' : 'out' ?>">

                        <?= $stock >= $qty

                            ? '● موجود در انبار'

                            : '⚠ موجودی انبار فقط ' .
                            number_format($stock) .
                            ' جفت'

                        ?>

                    </span>


                    <!-- =================================================
                         پیام پله بعدی
                         ================================================= -->

                    <?php if ($hasNextTier && $remainingToNext > 0): ?>

                        <div
                            class="cart-tier-hint"
                            style="
                                margin-top:8px;
                                font-size:.78rem;
                                line-height:1.8;
                            ">

                            فقط

                            <strong>
                                <?= number_format($remainingToNext) ?>
                            </strong>

                            جفت دیگر اضافه کنید تا قیمت هر جفت به

                            <strong>
                                <?= number_format((float)$nextTier['price']) ?>
                                تومان
                            </strong>

                            برسد.

                        </div>

                    <?php elseif ($it['discount_percent'] > 0): ?>

                        <div
                            class="cart-tier-hint"
                            style="
                                margin-top:8px;
                                font-size:.78rem;
                            ">

                            🎉 تخفیف حجمی

                            <strong>
                                <?= number_format((float)$it['discount_percent'], 2) ?>٪
                            </strong>

                            برای این تعداد فعال است.

                        </div>

                    <?php endif; ?>


                    <!-- =================================================
                         خطا
                         ================================================= -->

                    <?php if ($it['issue']): ?>

                        <div
                            class="cart-item-warn"
                            style="display:block;">
                            ⚠️
                            <?= $h($it['issue']) ?>
                        </div>

                    <?php endif; ?>

                </div>


                <!-- =================================================
                     بخش تعداد و جمع
                     ================================================= -->

                <div class="cart-item-side">


                    <div
                        class="stepper"
                        data-item="<?= (int)$it['id'] ?>"
                        data-min="<?= $moq ?>"
                        data-max="<?= max($stock, $moq) ?>">

                        <button
                            type="button"
                            class="step plus"
                            aria-label="افزایش تعداد">
                            +
                        </button>


                        <input
                            type="text"
                            inputmode="numeric"
                            class="step-num step-input"
                            value="<?= $qty ?>"
                            aria-label="تعداد">


                        <button
                            type="button"
                            class="step minus"
                            aria-label="کاهش تعداد">
                            −
                        </button>

                    </div>


                    <!-- =================================================
                         جمع ردیف
                         ================================================= -->

                    <div class="cart-line-total">

                        <small>
                            جمع
                        </small>

                        <b>
                            <?= number_format($lineTotal) ?>
                            تومان
                        </b>

                        <?php if ($lineDiscount > 0): ?>

                            <small
                                style="
                                    display:block;
                                    margin-top:3px;
                                ">
                                <?= number_format($lineDiscount) ?>
                                تومان صرفه‌جویی
                            </small>

                        <?php endif; ?>

                    </div>


                    <!-- =================================================
                         حذف
                         ================================================= -->

                    <form
                        method="post"
                        class="cart-remove-form"
                        onsubmit="return confirm('این قلم حذف شود؟');">

                        <input
                            type="hidden"
                            name="do"
                            value="remove">

                        <input
                            type="hidden"
                            name="item_id"
                            value="<?= (int)$it['id'] ?>">

                        <button
                            type="submit"
                            class="cart-remove">
                            🗑 حذف
                        </button>

                    </form>

                </div>

            </article>

        <?php endforeach; ?>


        <!-- =========================================================
             خلاصه سبد
             ========================================================= -->

        <div class="cart-summary">


            <div class="cart-summary-row">

                <span>
                    تعداد کل
                </span>

                <b>
                    <?= number_format($totalQty) ?>
                    جفت
                </b>

            </div>


            <?php if ($totalDiscount > 0): ?>

                <div class="cart-summary-row">

                    <span>
                        جمع قیمت پایه
                    </span>

                    <b>
                        <?= number_format($totalBase) ?>
                        تومان
                    </b>

                </div>


                <div class="cart-summary-row">

                    <span>
                        تخفیف حجمی
                    </span>

                    <b>
                        <?= number_format($totalDiscount) ?>
                        تومان
                    </b>

                </div>

            <?php endif; ?>


            <div class="cart-summary-row total">

                <span>
                    مبلغ نهایی سفارش
                </span>

                <b>
                    <?= number_format($totalFinal) ?>
                    تومان
                </b>

            </div>


            <?php if ($hasIssue): ?>

                <div
                    class="form-alert error"
                    style="display:block;">
                    ⚠️ برخی اقلام سبد نیاز به اصلاح دارند.
                </div>

            <?php else: ?>

                <a
                    href="/checkout-address"
                    class="btn btn-primary cart-checkout-btn">
                    ادامه و ثبت سفارش ←
                </a>

            <?php endif; ?>


            <a
                href="/products"
                class="cart-continue">
                ادامه خرید
            </a>

        </div>

    </div>


<?php else: ?>


    <div class="empty-state">

        <div class="empty-icon">
            🛒
        </div>

        <h2>
            سبد سفارش شما خالی است
        </h2>

        <a
            href="/products"
            class="btn btn-primary">
            مشاهده محصولات
        </a>

    </div>


<?php endif; ?>

<script>
    (function() {
        'use strict';

        /* ═══════════ Helpers ═══════════ */

        function faToEn(value) {
            return String(value)
                .replace(/[۰-۹]/g, function(d) {
                    return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
                })
                .replace(/[٠-٩]/g, function(d) {
                    return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);
                })
                .replace(/[^\d]/g, '');
        }

        function enToFa(value) {
            return String(value).replace(/\d/g, function(d) {
                return '۰۱۲۳۴۵۶۷۸۹' [d];
            });
        }

        function formatPrice(n) {
            var num = Math.round(Number(n) || 0);
            var s = num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return enToFa(s);
        }

        function readQty(input) {
            var v = faToEn(input.value);
            return parseInt(v, 10) || 0;
        }

        function writeQty(input, val) {
            input.value = enToFa(val);
        }

        /* ═══════════ ذخیره‌ی تعداد (AJAX) ═══════════ */

        var saving = false;

        function saveItem(stepper, qty) {

            if (saving) return;

            var itemId = stepper.dataset.item;
            if (!itemId) return;

            var min = parseInt(stepper.dataset.min || '1', 10) || 1;
            var max = parseInt(stepper.dataset.max || '999999999', 10) || 999999999;

            qty = Math.max(min, Math.min(max, qty));

            var input = stepper.querySelector('.step-input');
            if (input) writeQty(input, qty);

            var fd = new FormData();
            fd.append('do', 'update');
            fd.append('item_id', itemId);
            fd.append('qty', String(qty));

            saving = true;
            stepper.classList.add('is-saving');

            fetch('/cart', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.json().catch(function() {
                        return {
                            ok: false,
                            error: 'پاسخ نامعتبر از سرور'
                        };
                    });
                })

                .then(function(data) {
                    if (!data.ok) {
                        alert(data.error || 'تغییر تعداد انجام نشد.');
                        return;
                    }

                    /* به‌روزرسانی داینامیک ردیف */
                    updateCartItem(itemId, data);

                    /* ⭐ آپدیت Badge هدر و شناور */
                    if (window.updateCartBadges && data.count !== undefined) {
                        window.updateCartBadges(data.count);
                    }
                })

                .catch(function(err) {
                    console.error('CART_UPDATE_ERROR:', err);
                    alert('ارتباط با سرور برقرار نشد.');
                })
                .finally(function() {
                    saving = false;
                    stepper.classList.remove('is-saving');
                });
        }

        /* ═══════════ به‌روزرسانی داینامیک ردیف ═══════════ */

        function updateCartItem(itemId, data) {

            var item = document.querySelector('[data-cart-item="' + itemId + '"]');
            if (!item) return;

            /* قیمت واحد */
            var unitEl = item.querySelector('.cart-item-price b');
            if (unitEl && data.unit_price !== undefined) {
                unitEl.textContent = formatPrice(data.unit_price) + ' تومان';
            }

            /* درصد تخفیف */
            var discEl = item.querySelector('.cart-item-price small');
            if (discEl) {
                var dp = Number(data.discount_percent) || 0;
                if (dp > 0) {
                    discEl.textContent = enToFa(dp.toFixed(2)) + '٪ تخفیف پلکانی اعمال شد';
                } else {
                    discEl.textContent = 'قیمت همکاری';
                }
            }

            /* جمع ردیف */
            var totalEl = item.querySelector('.cart-line-total b');
            if (totalEl && data.line_total !== undefined) {
                totalEl.textContent = formatPrice(data.line_total) + ' تومان';
            }

            /* خلاصه سبد */
            recalcCartSummary();
        }

        /* ═══════════ بازمحاسبه خلاصه ═══════════ */

        function recalcCartSummary() {

            var items = document.querySelectorAll('[data-cart-item]');
            var totalQty = 0;
            var totalFinal = 0;

            items.forEach(function(item) {
                var input = item.querySelector('.step-input');
                var qty = parseInt(faToEn(input ? input.value : '0'), 10) || 0;

                var lineTotalText = (item.querySelector('.cart-line-total b') || {}).textContent || '0';
                var lineTotal = parseInt(faToEn(lineTotalText), 10) || 0;

                totalQty += qty;
                totalFinal += lineTotal;
            });

            var rows = document.querySelectorAll('.cart-summary-row');

            rows.forEach(function(row) {
                var spanEl = row.querySelector('span');
                var b = row.querySelector('b');
                if (!b || !spanEl) return;

                var label = spanEl.textContent.trim();

                if (label.indexOf('تعداد کل') !== -1) {
                    b.textContent = enToFa(totalQty) + ' جفت';
                }

                if (label.indexOf('مبلغ نهایی') !== -1) {
                    b.textContent = formatPrice(totalFinal) + ' تومان';
                }
            });
        }

        /* ═══════════ رهگیری رویدادها ═══════════ */

        document.addEventListener('click', function(event) {

            var t = event.target;
            if (!(t instanceof Element)) return;

            var button = t.closest('.cart-item .step.plus, .cart-item .step.minus');
            if (!button) return;

            var stepper = button.closest('.stepper');
            if (!stepper) return;

            var input = stepper.querySelector('.step-input');
            if (!input) return;

            var min = parseInt(stepper.dataset.min || '1', 10) || 1;
            var max = parseInt(stepper.dataset.max || '999999999', 10) || 999999999;

            var qty = readQty(input);
            if (qty < min) qty = min;

            if (button.classList.contains('plus')) {
                if (qty < max) qty++;
            } else {
                if (qty > min) qty--;
            }

            writeQty(input, qty);
            saveItem(stepper, qty);
        });

        document.addEventListener('change', function(event) {

            var t = event.target;
            if (!(t instanceof Element)) return;

            var input = t.closest('.cart-item .step-input');
            if (!input) return;

            var stepper = input.closest('.stepper');
            if (!stepper) return;

            saveItem(stepper, readQty(input));
        });

        document.addEventListener('keydown', function(event) {

            if (event.key !== 'Enter') return;

            var t = event.target;
            if (!(t instanceof Element)) return;

            var input = t.closest('.cart-item .step-input');
            if (!input) return;

            event.preventDefault();

            var stepper = input.closest('.stepper');
            if (!stepper) return;

            saveItem(stepper, readQty(input));
        });

    })();
</script>


<?php
$content = ob_get_clean();

require ROOT_PATH . '/views/layouts/main.php';
