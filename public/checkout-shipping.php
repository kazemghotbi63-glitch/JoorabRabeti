<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/AddressService.php';
require_once ROOT_PATH . '/app/services/ShippingService.php';
require_once ROOT_PATH . '/app/services/PricingService.php';
require_once ROOT_PATH . '/app/services/Time.php';
/* ═══ نمایش شمسی و بازه فارسی ═══ */

/* میلادی → جلالی */
$jalaliFa = static function (?string $gregorianDate): string {
    if (!$gregorianDate) return '—';
    $ts = strtotime($gregorianDate);
    if ($ts === false) return '—';

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    $g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? $gy + 1 : $gy;
    $d = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $g[$gm - 1];
    $jy += 33 * intdiv($d, 12053);
    $d %= 12053;
    $jy += 4 * intdiv($d, 1461);
    $d %= 1461;
    if ($d > 365) {
        $jy += intdiv($d - 1, 365);
        $d = ($d - 1) % 365;
    }
    $jm = ($d < 186) ? 1 + intdiv($d, 31) : 7 + intdiv($d - 186, 30);
    $jd = 1 + (($d < 186) ? $d % 31 : ($d - 186) % 30);

    /* روز هفته فارسی */
    $days = [7 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه'];
    $dayName = $days[(int)date('N', $ts)];

    return $dayName . ' ' . strtr(
        $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT),
        ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']
    );
};

/* بازه: 16:00-19:00 → «از ۴ عصر تا ۷ عصر» */
$slotFa = static function (string $range): string {
    if (!str_contains($range, '-')) return $range;
    [$s, $e] = array_map('trim', explode('-', $range, 2));

    /* اگر قبلاً فارسی است، دست نزن */
    if (!preg_match('/^\d{1,2}:\d{2}$/', $s)) return $range;

    $sh = (int)explode(':', $s)[0];
    $eh = (int)explode(':', $e)[0];

    $fa = static fn(int $n): string => strtr(
        (string)($n > 12 ? $n - 12 : $n),
        ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']
    );
    $tod = static fn(int $hh): string => ($hh >= 5 && $hh < 12) ? 'صبح' : (($hh >= 12 && $hh < 17) ? 'ظهر' : (($hh >= 17 && $hh < 21) ? 'عصر' : 'شب'));

    return 'از ' . $fa($sh) . ' ' . $tod($sh) . ' تا ' . $fa($eh) . ' ' . $tod($eh);
};





if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$h = static fn($v) => htmlspecialchars(
    (string)$v,
    ENT_QUOTES,
    'UTF-8'
);

$userId = (int)(
    $_SESSION['admin_id']
    ?? $_SESSION['customer_id']
    ?? 0
);

if ($userId <= 0) {
    header('Location: /');
    exit;
}

$db = db();

/*
|--------------------------------------------------------------------------
| آدرس انتخاب‌شده
|--------------------------------------------------------------------------
*/

$addressId = (int)(
    $_SESSION['checkout_address_id'] ?? 0
);

$address = AddressService::get(
    $userId,
    $addressId
);

if (!$address) {
    header('Location: /checkout-address');
    exit;
}

/*
|--------------------------------------------------------------------------
| گزینه‌های ارسال
|--------------------------------------------------------------------------
*/

$options = ShippingService::getOptions(
    (string)$address['province']
);

$error = '';

/*
|--------------------------------------------------------------------------
| ذخیره انتخاب ارسال
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();


    $method = trim(
        (string)($_POST['method'] ?? '')
    );

    $slotId = trim(
        (string)($_POST['slot'] ?? '')
    );

    try {

        if (
            $method === ''
            || !isset($options['methods'][$method])
        ) {
            throw new RuntimeException(
                'روش ارسال انتخاب‌شده معتبر نیست.'
            );
        }

        $selectedMethod =
            $options['methods'][$method];

        /*
         * روش‌هایی که تاریخ/Slot دارند:
         * پیک تهران و پست تهران
         */
        $requiresSlot =
            !empty($selectedMethod['days']);

        if ($requiresSlot) {

            if ($slotId === '') {
                throw new RuntimeException(
                    'لطفاً تاریخ و زمان ارسال را انتخاب کنید.'
                );
            }

            /*
             * کنترل اینکه Slot واقعاً متعلق به همین روش
             * و یکی از گزینه‌های فعلی باشد.
             */
            $validSlot = false;

            foreach (
                $selectedMethod['days']
                as $day
            ) {

                foreach (
                    $day['slots']
                    as $slot
                ) {

                    if (
                        $slot['id'] === $slotId
                        && !empty($slot['available'])
                    ) {
                        $validSlot = true;
                        break 2;
                    }
                }
            }

            if (!$validSlot) {
                throw new RuntimeException(
                    'بازه ارسال انتخاب‌شده دیگر موجود نیست. لطفاً گزینه دیگری انتخاب کنید.'
                );
            }
        } else {

            /*
             * پست شهرستان و تحویل حضوری
             * اصلاً Slot ندارند.
             */
            $slotId = '';
        }

        $_SESSION['checkout_shipping'] = [
            'method' => $method,
            'slot'   => $slotId,
            'fee'    => (int)$selectedMethod['fee'],
        ];

        header('Location: /checkout-review');
        exit;
    } catch (RuntimeException $e) {

        $error = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| خلاصه سبد + قیمت‌گذاری پلکانی
|--------------------------------------------------------------------------
|
| قیمت پایه از min_qty=1 گرفته می‌شود.
| سپس PricingService بر اساس تعداد واقعی هر محصول،
| tier صحیح را انتخاب می‌کند.
|
*/

$stmt = $db->prepare("
    SELECT
        ci.product_id,
        ci.qty,
        pr.price AS base_price
    FROM cart_items ci

    JOIN carts c
        ON c.id = ci.cart_id

    LEFT JOIN product_prices pr
        ON pr.product_id = ci.product_id
       AND pr.customer_group = 'wholesale'
       AND pr.min_qty = 1

    WHERE c.user_id = ?
      AND c.status = 'active'

    ORDER BY ci.id ASC
");

$stmt->execute([
    $userId
]);

$cartItems = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| محاسبه نهایی سبد
|--------------------------------------------------------------------------
*/

$subtotal = 0;
$discountTotal = 0;
$finalSubtotal = 0;
$totalQty = 0;

foreach ($cartItems as $item) {

    $productId = (int)$item['product_id'];
    $qty = (int)$item['qty'];
    $basePrice = (int)($item['base_price'] ?? 0);

    if ($qty <= 0) {
        continue;
    }

    /*
     * اگر قیمت پایه وجود نداشته باشد،
     * فعلاً این قلم را از محاسبه حذف می‌کنیم.
     * کنترل نهایی دوباره در checkout-review انجام می‌شود.
     */
    if ($basePrice <= 0) {
        continue;
    }

    $pricing = PricingService::calculateForProduct(
        $db,
        $productId,
        $basePrice,
        $qty
    );

    $subtotal += (int)$pricing['base_line_total'];

    $discountTotal += (int)$pricing['discount_amount'];

    $finalSubtotal += (int)$pricing['line_total'];

    $totalQty += $qty;
}

/*
|--------------------------------------------------------------------------
| سازگاری با ساختار قبلی $cart
|--------------------------------------------------------------------------
*/

$cart = [
    'subtotal' => $subtotal,
    'discount'  => $discountTotal,
    'final'     => $finalSubtotal,
    'total_qty' => $totalQty,
];

/*
|--------------------------------------------------------------------------
| عنوان صفحه
|--------------------------------------------------------------------------
*/

$activeMenu = '';
$pageTitle = 'روش ارسال | رابطی';

ob_start();

?>

<nav class="breadcrumb">
    <a href="/">خانه</a> ›
    <a href="/cart">سبد</a> ›
    <a href="/checkout-address">آدرس</a> ›
    <span>ارسال</span>
</nav>

<section class="catalog-head">

    <h1 class="catalog-title">
        روش و زمان ارسال
    </h1>

    <p class="catalog-sub">
        سفارش به:
        <?= $h($address['province']) ?>،
        <?= $h($address['city']) ?>
    </p>

</section>

<?php if ($error): ?>

    <div
        class="form-alert error"
        style="display:block;">
        ⚠️ <?= $h($error) ?>
    </div>

<?php endif; ?>

<form
    method="post"
    class="co-form shipping-form"
    id="shippingForm">
    <?= csrf_field() ?>

    <?php foreach ($options['methods'] as $mKey => $m): ?>

        <?php
        $hasDays = !empty($m['days']);
        ?>

        <div class="ship-method-block">

            <label class="pay-method">

                <input
                    type="radio"
                    name="method"
                    value="<?= $h($mKey) ?>"
                    data-fee="<?= (int)$m['fee'] ?>"
                    data-has-days="<?= $hasDays ? '1' : '0' ?>"
                    required>

                <span class="pay-method-body">

                    <b>
                        <?= $h($m['label']) ?>
                        —
                        <?= number_format((float)$m['fee']) ?>
                        تومان
                    </b>

                    <?php if ($hasDays): ?>

                        <small>
                            پنجره
                            <?= count($m['days']) ?>
                            روز آینده
                        </small>

                    <?php else: ?>

                        <small>
                            <?= !empty($m['estimate'])
                                ? $h($m['estimate'])
                                : 'بدون نیاز به انتخاب تاریخ و ساعت'
                            ?>
                        </small>

                    <?php endif; ?>

                </span>

            </label>

            <?php if ($hasDays): ?>

                <div
                    class="ship-days"
                    data-method="<?= $h($mKey) ?>"
                    style="display:none;">

                    <?php foreach ($m['days'] as $day): ?>

                        <div class="ship-day">

                            <div class="ship-day-label">
                                <?= $h($day['label']) ?>
                            </div>

                            <div class="ship-slots">

                                <?php foreach ($day['slots'] as $slot): ?>

                                    <label
                                        class="ship-slot <?= !$slot['available'] ? 'is-full' : '' ?>">

                                        <input
                                            type="radio"
                                            name="slot"
                                            value="<?= $h($slot['id']) ?>"
                                            <?= !$slot['available'] ? 'disabled' : '' ?>>


                                        <span><?= $h($slotFa($slot['label'])) ?></span>

                                        <?php if (!$slot['available']): ?>

                                            <em>
                                                تکمیل
                                            </em>

                                        <?php elseif ((int)$slot['left'] <= 2): ?>

                                            <em class="almost">
                                                <?= number_format((int)$slot['left']) ?>
                                                ظرفیت باقی‌مانده
                                            </em>

                                        <?php endif; ?>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>


    <div
        class="cart-summary"
        style="margin-top:20px;">

        <!-- جمع قیمت پایه -->

        <div class="cart-summary-row">

            <span>
                جمع کالاها
                (<?= number_format((int)$cart['total_qty']) ?> جفت)
            </span>

            <b>
                <?= number_format((float)$cart['subtotal']) ?>
                تومان
            </b>

        </div>


        <!-- تخفیف حجمی -->

        <?php if ((int)$cart['discount'] > 0): ?>

            <div class="cart-summary-row">

                <span>
                    تخفیف خرید عمده
                </span>

                <b>
                    <?= number_format((float)$cart['discount']) ?>
                    تومان
                </b>

            </div>

        <?php endif; ?>


        <!-- مبلغ کالا بعد از تخفیف -->

        <div class="cart-summary-row">

            <span>
                مبلغ کالا پس از تخفیف
            </span>

            <b>
                <?= number_format((float)$cart['final']) ?>
                تومان
            </b>

        </div>


        <!-- هزینه ارسال -->

        <div class="cart-summary-row">

            <span>
                هزینه ارسال
            </span>

            <b id="shipFeeView">
                — انتخاب کنید
            </b>

        </div>


        <!-- مبلغ نهایی -->

        <div class="cart-summary-row total">

            <span>
                مبلغ نهایی
            </span>

            <b id="totalView">
                <?= number_format((float)$cart['final']) ?>
                تومان
            </b>

        </div>


        <button
            type="submit"
            class="btn btn-primary cart-checkout-btn">
            ادامه و مشاهده خلاصه ←
        </button>

    </div>

</form>


<script>
    (function() {

        const form =
            document.getElementById(
                'shippingForm'
            );

        if (!form) {
            return;
        }

        /*
         * مبلغ واقعی کالا پس از تخفیف
         */
        const subtotal =
            <?= (int)$cart['final'] ?>;

        const feeView =
            document.getElementById(
                'shipFeeView'
            );

        const totalView =
            document.getElementById(
                'totalView'
            );

        const methodInputs =
            form.querySelectorAll(
                'input[name="method"]'
            );

        const slotInputs =
            form.querySelectorAll(
                'input[name="slot"]'
            );


        function updateShippingUI(selected) {

            document
                .querySelectorAll('.ship-days')
                .forEach(function(box) {

                    box.style.display =
                        'none';

                });


            /*
             * هیچ روش ارسال انتخاب نشده
             */
            if (!selected) {

                feeView.textContent =
                    '— انتخاب کنید';

                totalView.textContent =
                    subtotal.toLocaleString(
                        'fa-IR'
                    ) +
                    ' تومان';

                return;
            }


            const hasDays =
                selected.dataset.hasDays === '1';


            const fee =
                Number(
                    selected.dataset.fee || 0
                );


            /*
             * فقط روش‌هایی که Slot دارند
             * پنجره روز را نمایش دهند.
             */
            if (hasDays) {

                const block =
                    document.querySelector(
                        '.ship-days[data-method="' +
                        CSS.escape(
                            selected.value
                        ) +
                        '"]'
                    );

                if (block) {
                    block.style.display =
                        'block';
                }
            }


            feeView.textContent =
                fee.toLocaleString(
                    'fa-IR'
                ) +
                ' تومان';


            totalView.textContent =
                (
                    subtotal + fee
                ).toLocaleString(
                    'fa-IR'
                ) +
                ' تومان';


            /*
             * Slot برای روش دارای روز الزامی است.
             * برای روش بدون روز کاملاً آزاد است.
             */
            slotInputs.forEach(
                function(slot) {

                    slot.required =
                        hasDays;

                }
            );
        }


        methodInputs.forEach(
            function(radio) {

                radio.addEventListener(
                    'change',
                    function() {

                        /*
                         * با تغییر روش،
                         * Slot قبلی را پاک می‌کنیم.
                         */
                        slotInputs.forEach(
                            function(slot) {

                                slot.checked =
                                    false;

                            }
                        );

                        updateShippingUI(
                            radio
                        );
                    }
                );

            }
        );


        /*
         * کنترل نهایی قبل از ارسال فرم
         */
        form.addEventListener(
            'submit',
            function(event) {

                const selected =
                    form.querySelector(
                        'input[name="method"]:checked'
                    );

                if (!selected) {

                    event.preventDefault();

                    alert(
                        'لطفاً روش ارسال را انتخاب کنید.'
                    );

                    return;
                }


                const hasDays =
                    selected.dataset.hasDays === '1';


                if (hasDays) {

                    const selectedSlot =
                        form.querySelector(
                            'input[name="slot"]:checked'
                        );

                    if (!selectedSlot) {

                        event.preventDefault();

                        alert(
                            'لطفاً تاریخ و زمان ارسال را انتخاب کنید.'
                        );

                        return;
                    }
                }

            }
        );

    })();
</script>

<?php

$content = ob_get_clean();

require ROOT_PATH . '/views/layouts/main.php';
