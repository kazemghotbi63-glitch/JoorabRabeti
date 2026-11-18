<?php
/* [FILE] public/checkout-shipping.php — v3 (Tier/Factor/Free + Weight) */

/* ─── Auto-detect bootstrap ─── */
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
require_once ROOT_PATH . '/app/services/AddressService.php';
require_once ROOT_PATH . '/app/services/ShippingService.php';
require_once ROOT_PATH . '/app/services/PricingService.php';
require_once ROOT_PATH . '/app/services/Time.php';

/* ═══ Helper های نمایش ═══ */
$jalaliFa = static function (?string $gregorianDate): string {
    if (!$gregorianDate) return '—';
    $ts = strtotime($gregorianDate);
    if ($ts === false) return '—';

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    $g  = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? $gy + 1 : $gy;
    $d = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) - 80 + $gd + $g[$gm - 1];
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

    $days = [7 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه'];
    $dayName = $days[(int)date('N', $ts)];

    return $dayName . ' ' . strtr(
        sprintf('%04d/%02d/%02d', $jy, $jm, $jd),
        ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']
    );
};

$slotFa = static function (string $range): string {
    if (!str_contains($range, '-')) return $range;
    [$s, $e] = array_map('trim', explode('-', $range, 2));
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

/* ═══ احراز هویت ═══ */
if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /');
    exit;
}

$db = db();

/* ═══ ۱) آدرس ═══ */
$addressId = (int)($_SESSION['checkout_address_id'] ?? 0);
$address   = AddressService::get($userId, $addressId);
if (!$address) {
    header('Location: /checkout-address');
    exit;
}

/* ═══ ۲) کوئری سبد ═══ */
$userGroup = current_user_group();
$stmt = $db->prepare("
    SELECT
        ci.product_id,
        ci.qty,
        p.base_price AS base_price,
        (SELECT spec_value FROM product_specs
         WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code
    FROM cart_items ci
    JOIN carts c     ON c.id = ci.cart_id
    JOIN products p  ON p.id = ci.product_id
    WHERE c.user_id = ? AND c.status = 'active'
    ORDER BY ci.id ASC
");
$stmt->execute([$userId]);
$cartItems = $stmt->fetchAll();

/* ═══ ۳) محاسبه مبالغ سبد ═══ */
$subtotal = 0;
$discountTotal = 0;
$finalSubtotal = 0;
$totalQty = 0;
foreach ($cartItems as $item) {
    $qty = (int)$item['qty'];
    if ($qty <= 0) continue;
    $pricing = PricingService::calculate($db, (int)$item['product_id'], $qty, $userGroup);
    if ($pricing['base_price'] <= 0) continue;
    $subtotal      += (int)$pricing['base_line_total'];
    $discountTotal += (int)$pricing['discount_amount'];
    $finalSubtotal += (int)$pricing['line_total'];
    $totalQty      += $qty;
}

/* ═══ ۴) شمارش واحدها ═══ */
$unitCounts = [];
$unitCountsNorm = [];
foreach ($cartItems as $item) {
    $qty = (int)$item['qty'];
    if ($qty <= 0) continue;
    $raw = (string)($item['unit_code'] ?? 'جفتی');
    $unit = unit_label($raw);
    $unitCounts[$unit] = ($unitCounts[$unit] ?? 0) + $qty;

    $key = str_contains($raw, 'جین') ? 'جین'
        : (str_contains($raw, 'بسته') ? 'بسته' : 'جفتی');
    $unitCountsNorm[$key] = ($unitCountsNorm[$key] ?? 0) + $qty;
}
$qtyParts = [];
foreach ($unitCounts as $u => $c) $qtyParts[] = number_format($c) . ' ' . $u;
$qtyDisplay = $qtyParts ? implode(' + ', $qtyParts) : '۰ جفت';

$cart = [
    'subtotal'    => $subtotal,
    'discount'    => $discountTotal,
    'final'       => $finalSubtotal,
    'total_qty'   => $totalQty,
    'qty_display' => $qtyDisplay,
];

/* ═══ ۵) وزن + گزینه‌های ارسال ═══ */
$weightInfo   = ShippingService::calculateCartWeight($db, $cartItems);
$pairQtyTotal = (int)$weightInfo['pairs'];
$weightGrams  = (int)$weightInfo['grams'];

$options = ShippingService::getOptions(
    $db,
    (string)$address['province'],
    (string)($address['city'] ?? ''),
    $pairQtyTotal,
    $weightGrams,
    (int)$cart['final'],
    $unitCountsNorm
);

$error = '';

/* ═══ ۶) هندل POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $method = trim((string)($_POST['method'] ?? ''));
    $slotId = trim((string)($_POST['slot']   ?? ''));

    try {
        if ($method === '' || !isset($options['methods'][$method])) {
            throw new RuntimeException('روش ارسال انتخاب‌شده معتبر نیست.');
        }

        $selectedMethod = $options['methods'][$method];
        $requiresSlot   = !empty($selectedMethod['days']);

        if ($requiresSlot) {
            if ($slotId === '') {
                throw new RuntimeException('لطفاً تاریخ و زمان ارسال را انتخاب کنید.');
            }
            $validSlot = false;
            foreach ($selectedMethod['days'] as $day) {
                foreach ($day['slots'] as $slot) {
                    if ($slot['id'] === $slotId && !empty($slot['available'])) {
                        $validSlot = true;
                        break 2;
                    }
                }
            }
            if (!$validSlot) {
                throw new RuntimeException('بازه ارسال انتخاب‌شده دیگر موجود نیست. لطفاً گزینه دیگری انتخاب کنید.');
            }
        } else {
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

/* ═══ ۷) رندر ═══ */
$activeMenu = '';
$pageTitle  = 'روش ارسال | رابطی';
ob_start();
?>

<nav class="breadcrumb">
    <a href="/">خانه</a> ›
    <a href="/cart">سبد</a> ›
    <a href="/checkout-address">آدرس</a> ›
    <span>ارسال</span>
</nav>

<section class="catalog-head">
    <h1 class="catalog-title">روش و زمان ارسال</h1>
    <p class="catalog-sub">
        سفارش به: <?= $h($address['province']) ?>، <?= $h($address['city']) ?>
    </p>
</section>

<?php if (!empty($options['warning'])): ?>
    <div style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;
                padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:13px;">
        ⚠️ <?= $h($options['warning']) ?>
    </div>
<?php endif; ?>

<div style="font-size:12px;color:#64748b;margin-bottom:14px;">
    📦 وزن: <b><?= number_format($weightGrams / 1000, 1) ?> کیلوگرم</b>
    — <?= number_format($pairQtyTotal) ?> جفت
    <!-- — منطقه: <b><?= $h(match ($options['zone']) {
                            'near' => 'نزدیک',
                            'far' => 'دور',
                            'very_far' => 'خیلی دور',
                            default => $options['zone']
                        }) ?></b> -->
</div>

<?php if ($error): ?>
    <div class="form-alert error" style="display:block;">⚠️ <?= $h($error) ?></div>
<?php endif; ?>

<form method="post" class="co-form shipping-form" id="shippingForm">
    <?= csrf_field() ?>

    <?php foreach ($options['methods'] as $mKey => $m): ?>
        <?php $hasDays = !empty($m['days']); ?>
        <?php $isFree = ((int)$m['fee'] === 0 && (($m['calc']['calc_mode'] ?? '') === 'free')); ?>

        <div class="ship-method-block">
            <label class="pay-method">
                <input
                    type="radio"
                    name="method"
                    value="<?= $h($mKey) ?>"
                    data-fee="<?= (int)$m['fee'] ?>"
                    data-has-days="<?= $hasDays ? '1' : '0' ?>">
                required>

                <span class="pay-method-body">
                    <b>
                        <?= $h($m['label']) ?> —
                        <?php if ($isFree): ?>
                            <span style="color:#166534">رایگان</span>
                        <?php else: ?>
                            <?= number_format((float)$m['fee']) ?> تومان
                        <?php endif; ?>
                        <?php if ($isFree): ?>
                            <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;
                                         font-size:10px;font-weight:800;margin-right:6px;">🎁</span>
                        <?php endif; ?>
                    </b>
                    <?php if ($hasDays): ?>
                        <small>
                            <?= $mKey === 'post'
                                ? 'هزینه ثابت بر اساس مبلغ سفارش شما'
                                : 'پنجره ' . count($m['days']) . ' روز آینده' ?>
                        </small>
                    <?php else: ?>
                        <small><?= !empty($m['estimate']) ? $h($m['estimate']) : 'بدون نیاز به انتخاب تاریخ و ساعت' ?></small>
                    <?php endif; ?>
                </span>
            </label>

            <?php if ($hasDays): ?>
                <div class="ship-days" data-method="<?= $h($mKey) ?>" style="display:none;">
                    <?php foreach ($m['days'] as $day): ?>
                        <div class="ship-day">
                            <div class="ship-day-label"><?= $h($day['label']) ?></div>
                            <div class="ship-slots">
                                <?php foreach ($day['slots'] as $slot): ?>
                                    <label class="ship-slot <?= !$slot['available'] ? 'is-full' : '' ?>">
                                        <input
                                            type="radio"
                                            name="slot"
                                            value="<?= $h($slot['id']) ?>"
                                            <?= !$slot['available'] ? 'disabled' : '' ?>>
                                        <span><?= $h($slotFa($slot['label'])) ?></span>
                                        <?php if (!$slot['available']): ?>
                                            <em>تکمیل</em>
                                        <?php elseif ((int)$slot['left'] <= 2): ?>
                                            <em class="almost"><?= number_format((int)$slot['left']) ?> ظرفیت باقی‌مانده</em>
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

    <div class="cart-summary" style="margin-top:20px;">
        <div class="cart-summary-row">
            <span>جمع کالاها (<?= $h($cart['qty_display'] ?? number_format((int)$cart['total_qty']) . ' جفت') ?>)</span>
            <b><?= number_format((float)$cart['subtotal']) ?> تومان</b>
        </div>

        <?php if ((int)$cart['discount'] > 0): ?>
            <div class="cart-summary-row">
                <span>تخفیف خرید عمده</span>
                <b><?= number_format((float)$cart['discount']) ?> تومان</b>
            </div>
        <?php endif; ?>

        <div class="cart-summary-row">
            <span>مبلغ کالا پس از تخفیف</span>
            <b><?= number_format((float)$cart['final']) ?> تومان</b>
        </div>

        <div class="cart-summary-row">
            <span>هزینه ارسال</span>
            <b id="shipFeeView">— انتخاب کنید</b>
        </div>

        <div class="cart-summary-row total">
            <span>مبلغ نهایی</span>
            <b id="totalView"><?= number_format((float)$cart['final']) ?> تومان</b>
        </div>

        <button type="submit" class="btn btn-primary cart-checkout-btn">
            ادامه و مشاهده خلاصه ←
        </button>
    </div>
</form>

<script>
    (function() {
        const form = document.getElementById('shippingForm');
        if (!form) return;

        const subtotal = <?= (int)$cart['final'] ?>;
        const feeView = document.getElementById('shipFeeView');
        const totalView = document.getElementById('totalView');
        const methodInputs = form.querySelectorAll('input[name="method"]');
        const slotInputs = form.querySelectorAll('input[name="slot"]');

        function updateShippingUI(selected) {
            document.querySelectorAll('.ship-days').forEach(box => box.style.display = 'none');

            if (!selected) {
                feeView.textContent = '— انتخاب کنید';
                totalView.textContent = subtotal.toLocaleString('fa-IR') + ' تومان';
                return;
            }

            const hasDays = selected.dataset.hasDays === '1';
            const fee = Number(selected.dataset.fee || 0);

            if (hasDays) {
                const block = document.querySelector('.ship-days[data-method="' + CSS.escape(selected.value) + '"]');
                if (block) block.style.display = 'block';
            }

            feeView.textContent = fee === 0 ? 'رایگان' : fee.toLocaleString('fa-IR') + ' تومان';
            totalView.textContent = (subtotal + fee).toLocaleString('fa-IR') + ' تومان';

            // مهم: required را از همه slot ها بردارید (اعتبارسنجی دستی می‌کنیم)
            slotInputs.forEach(slot => {
                slot.required = false;
            });
        }

        methodInputs.forEach(radio => {
            radio.addEventListener('change', function() {
                slotInputs.forEach(s => {
                    s.checked = false;
                });
                updateShippingUI(radio);
            });
        });

        form.addEventListener('submit', function(event) {
            const selected = form.querySelector('input[name="method"]:checked');
            if (!selected) {
                event.preventDefault();
                alert('لطفاً روش ارسال را انتخاب کنید.');
                return;
            }
            const hasDays = selected.dataset.hasDays === '1';
            if (hasDays) {
                const selectedSlot = form.querySelector('input[name="slot"]:checked');
                if (!selectedSlot) {
                    event.preventDefault();
                    alert('لطفاً تاریخ و زمان ارسال را انتخاب کنید.');
                }
            }
        });

        const initialSelected = form.querySelector('input[name="method"]:checked');
        if (initialSelected) {
            updateShippingUI(initialSelected);
        }
    })();
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
