<?php

declare(strict_types=1);

/* [FILE] public/checkout-review.php — v3 (Tier/Factor/Free + snapshot calc) */

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
require_once ROOT_PATH . '/app/services/OrderService.php';

/* ═══ Helper: شمسی + بازه فارسی ═══ */
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

/* ═══ احراز هویت — ⭐ خرید مهمان‌پسند: ورود/ثبت‌نام فقط در همین مرحلهٔ آخر ═══ */
$isCustomer = is_customer_logged_in();
if (!$isCustomer && is_admin_logged_in()) {
    header('Location: /admin');
    exit;
}
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$userId = $isCustomer ? (int)($_SESSION['customer_id'] ?? 0) : 0;

$db = db();

/* ═══ ۱) سشن ═══ */
$addressId = (int)($_SESSION['checkout_address_id'] ?? 0);
$shipping  = $_SESSION['checkout_shipping'] ?? null;
if ($isCustomer) {
    $address = $addressId > 0 ? AddressService::get($userId, $addressId) : null;
} else {
    $address = (isset($_SESSION['checkout_guest_address'])
                && is_array($_SESSION['checkout_guest_address']))
        ? $_SESSION['checkout_guest_address'] : null;
}

if (!$address || !is_array($shipping)) {
    header('Location: /checkout-address');
    exit;
}

$shippingMethod = trim((string)($shipping['method'] ?? ''));
$shippingSlot   = trim((string)($shipping['slot']   ?? ''));

if ($shippingMethod === '') {
    header('Location: /checkout-shipping');
    exit;
}

/* ═══ ۲) سبد (کاربر: DB — مهمان: session) ═══ */
if ($isCustomer) {
    $stmtItems = $db->prepare("
        SELECT
            ci.qty,
            p.id AS product_id,
            p.code,
            p.name,
            p.slug,
            p.base_price,
            p.base_moq,
            p.is_active,
            (SELECT spec_value FROM product_specs
             WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
            (SELECT image_path FROM product_images pi
             WHERE pi.product_id = p.id
             ORDER BY pi.is_main DESC, pi.sort_order ASC
             LIMIT 1) AS image
        FROM cart_items ci
        JOIN carts c    ON c.id = ci.cart_id
        JOIN products p ON p.id = ci.product_id
        WHERE c.user_id = ? AND c.status = 'active'
        ORDER BY ci.id ASC
    ");
    $stmtItems->execute([$userId]);
    $cartItems = $stmtItems->fetchAll();
} else {
    $guestCart = (isset($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart']))
        ? $_SESSION['guest_cart'] : [];
    $cartItems = [];
    $stmtItems = $db->prepare("
        SELECT
            p.id AS product_id,
            p.code,
            p.name,
            p.slug,
            p.base_price,
            p.base_moq,
            p.is_active,
            (SELECT spec_value FROM product_specs
             WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
            (SELECT image_path FROM product_images pi
             WHERE pi.product_id = p.id
             ORDER BY pi.is_main DESC, pi.sort_order ASC
             LIMIT 1) AS image
        FROM products p
        WHERE p.id = ? LIMIT 1
    ");
    foreach ($guestCart as $gPid => $gQty) {
        if ((int)$gQty <= 0) continue;
        $stmtItems->execute([(int)$gPid]);
        $row = $stmtItems->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['qty'] = (int)$gQty;
            $cartItems[] = $row;
        }
    }
}

if (!$cartItems) {
    header('Location: /cart');
    exit;
}

/* ─── نرمال‌سازی unitCounts برای شرط رایگان ─── */
$unitCountsNorm = [];
foreach ($cartItems as $item) {
    $qty = (int)$item['qty'];
    if ($qty <= 0) continue;
    $raw = (string)($item['unit_code'] ?? 'جفتی');
    $key = str_contains($raw, 'جین') ? 'جین'
        : (str_contains($raw, 'بسته') ? 'بسته' : 'جفتی');
    $unitCountsNorm[$key] = ($unitCountsNorm[$key] ?? 0) + $qty;
}

/* ═══ ۳) محاسبه سریع مبلغ برای getOptions (نمایش) ═══ */
$__quickSubtotal = 0;
$__quickDiscount = 0;
foreach ($cartItems as $__it) {
    $__qty = (int)$__it['qty'];
    if ($__qty <= 0) continue;
    $__p = PricingService::calculate($db, (int)$__it['product_id'], $__qty, $isCustomer ? current_user_group() : 'regular');
    if ($__p['base_price'] <= 0) continue;
    $__quickSubtotal += (int)$__p['base_line_total'];
    $__quickDiscount += (int)$__p['discount_amount'];
}
$__quickFinal = max(0, $__quickSubtotal - $__quickDiscount);

/* ═══ ۴) وزن + گزینه‌های ارسال ═══ */
$weightInfo   = ShippingService::calculateCartWeight($db, $cartItems);
$pairQtyTotal = (int)$weightInfo['pairs'];
$weightGrams  = (int)$weightInfo['grams'];

$options = ShippingService::getOptions(
    $db,
    (string)$address['province'],
    (string)($address['city'] ?? ''),
    $pairQtyTotal,
    $weightGrams,
    $__quickFinal,
    $unitCountsNorm
);

if (!isset($options['methods'][$shippingMethod])) {
    unset($_SESSION['checkout_shipping']);
    header('Location: /checkout-shipping');
    exit;
}

$selectedShippingMethod = $options['methods'][$shippingMethod];
$shippingRequiresSlot   = !empty($selectedShippingMethod['days']);

if (!$shippingRequiresSlot) $shippingSlot = '';

/* ═══ ۵) برچسب‌ها ═══ */
$shippingMethodLabels = [
    'peyk'      => 'پیک موتوری',
    'post'      => 'پست پیشتاز',
    'tipax'     => 'تیپاکس',
    'barbari'   => 'باربری',
    'in_person' => 'تحویل حضوری از کارگاه',
];

$shippingMethodLabel = $shippingMethodLabels[$shippingMethod]
    ?? trim((string)($shipping['method_label'] ?? $selectedShippingMethod['label'] ?? $shippingMethod));

$error = '';

/* ═══ ۶) POST: ثبت سفارش ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $receiverName   = trim((string)($_POST['receiver_name']   ?? ''));
    $receiverMobile = trim((string)($_POST['receiver_mobile'] ?? ''));
    $receiverMobile = strtr($receiverMobile, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ]);

    if ($receiverName !== '' && mb_strlen($receiverName) < 3) {
        $error = 'نام گیرنده را کامل وارد کنید.';
    }
    if (!$error && $receiverMobile !== '' && !preg_match('/^09\d{9}$/', $receiverMobile)) {
        $error = 'موبایل گیرنده معتبر نیست.';
    }

    /* ═══ ⭐ مرحلهٔ آخر: مهمان باید همین‌جا وارد شود یا ثبت‌نام کند ═══
       (سبد مهمان و آدرس انتخابی در session محفوظ است؛ با ورود، به‌صورت
       خودکار با سبد حساب ادغام و تخفیف سطح عضویت اعمال می‌شود) */
    if (!$error && !$isCustomer) {
        $error = 'برای ثبت نهایی، ورود یا ثبت‌نام لازم است — سبد و آدرس شما ذخیره شده است.';
    }

    if (!$error) {
        $transactionStarted = false;
        try {
            $db->beginTransaction();
            $transactionStarted = true;

            $userGroup = current_user_group();

            /* قفل سبد */
            $stmt = $db->prepare("
                SELECT
                    ci.qty,
                    p.id AS product_id,
                    p.code,
                    p.name,
                    p.base_moq,
                    p.is_active,
                    p.base_price,
                    (SELECT spec_value FROM product_specs
                     WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
                    COALESCE(inv.qty_physical, 0) AS qty_physical,
                    COALESCE(inv.reserved, 0) AS qty_reserved,
                    COALESCE(inv.unsellable, 0) AS qty_unsellable,
                    GREATEST(0, COALESCE(inv.qty_physical,0) - COALESCE(inv.reserved,0) - COALESCE(inv.unsellable,0)) AS available_for_sale
                FROM cart_items ci
                JOIN carts c    ON c.id = ci.cart_id
                JOIN products p ON p.id = ci.product_id
                LEFT JOIN inventory inv ON inv.product_id = p.id
                WHERE c.user_id = ? AND c.status = 'active'
                FOR UPDATE
            ");
            $stmt->execute([$userId]);
            $items = $stmt->fetchAll();

            if (!$items) throw new RuntimeException('سبد شما خالی است.');

            /* unitCountsNorm بر اساس items قفل‌شده */
            $unitCountsNorm = [];
            foreach ($items as $__it) {
                $__q = (int)$__it['qty'];
                if ($__q <= 0) continue;
                $__raw = (string)($__it['unit_code'] ?? 'جفتی');
                $__k = str_contains($__raw, 'جین') ? 'جین'
                    : (str_contains($__raw, 'بسته') ? 'بسته' : 'جفتی');
                $unitCountsNorm[$__k] = ($unitCountsNorm[$__k] ?? 0) + $__q;
            }

            $subtotal = 0;
            $discountTotal = 0;
            foreach ($items as &$it) {
                $qty = (int)$it['qty'];
                if ($qty <= 0) continue;

                if (!(int)$it['is_active']) {
                    throw new RuntimeException('محصول «' . $it['name'] . '» دیگر فعال نیست.');
                }
                $moq = max(1, (int)$it['base_moq']);
                if ($qty < $moq) {
                    throw new RuntimeException('حداقل سفارش «' . $it['name'] . '» ' . number_format($moq) . ' عدد است.');
                }

                $pricing = PricingService::calculate($db, (int)$it['product_id'], $qty, $userGroup);
                if ($pricing['base_price'] <= 0) {
                    throw new RuntimeException('قیمت «' . $it['name'] . '» مشخص نشده است.');
                }

                $it['base_unit_price']        = $pricing['base_price'];
                $it['unit_price']             = $pricing['unit_price'];
                $it['discount_percent']       = $pricing['discount_percent'];
                $it['discount_amount']        = $pricing['discount_amount'];
                $it['base_line_total']        = $pricing['base_line_total'];
                $it['line_total']             = $pricing['line_total'];
                $it['discount_source']        = $pricing['discount_source'];
                $it['group_discount_percent'] = $pricing['discount_source'] === 'group' ? $pricing['discount_percent'] : 0;
                $it['tier_discount_percent']  = $pricing['discount_source'] === 'tier'  ? $pricing['discount_percent'] : 0;

                $qtyAvailable = (int)$it['available_for_sale'];
                $qtyReserved  = min($qty, max(0, $qtyAvailable));
                $qtyToProduce = max(0, $qty - $qtyReserved);

                $it['qty_reserved']   = $qtyReserved;
                $it['qty_to_produce'] = $qtyToProduce;

                $subtotal      += $pricing['base_line_total'];
                $discountTotal += $pricing['discount_amount'];
            }
            unset($it);

            $finalSubtotal = max(0, $subtotal - $discountTotal);

            /* آدرس دوباره */
            $addr = AddressService::get($userId, $addressId);
            if (!$addr) throw new RuntimeException('آدرس ارسال معتبر نیست.');

            /* گزینه‌های تازه */
            $freshOptions = ShippingService::getOptions(
                $db,
                (string)$addr['province'],
                (string)($addr['city'] ?? ''),
                $pairQtyTotal,
                $weightGrams,
                (int)$finalSubtotal,
                $unitCountsNorm
            );

            if (!isset($freshOptions['methods'][$shippingMethod])) {
                throw new RuntimeException('روش ارسال انتخاب‌شده دیگر قابل استفاده نیست. لطفاً دوباره انتخاب کنید.');
            }

            $freshMethod       = $freshOptions['methods'][$shippingMethod];
            $freshRequiresSlot = !empty($freshMethod['days']);

            /* ─── اعتبارسنجی Slot + ساخت $ship ─── */
            $ship = null;

            if ($freshRequiresSlot) {
                if ($shippingSlot === '') {
                    throw new RuntimeException('تاریخ و زمان ارسال انتخاب نشده است.');
                }

                $validSlot = false;
                foreach ($freshMethod['days'] as $day) {
                    foreach ($day['slots'] as $slot) {
                        if ($slot['id'] === $shippingSlot && !empty($slot['available'])) {
                            $validSlot = true;
                            break 2;
                        }
                    }
                }
                if (!$validSlot) {
                    throw new RuntimeException('بازه ارسال انتخاب‌شده دیگر موجود نیست. لطفاً گزینه دیگری انتخاب کنید.');
                }

                [$slotDate, $slotLabel] = array_pad(explode('|', $shippingSlot, 2), 2, '');

                $ship = [
                    'method' => $shippingMethod,
                    'date'   => $slotDate,
                    'label'  => $slotLabel,
                    'fee'    => (int)$freshMethod['fee'],
                ];
            } else {
                $noSlotLabel = trim((string)($freshMethod['estimate'] ?? ''));
                if ($noSlotLabel === '') {
                    $noSlotLabel = trim((string)($freshMethod['label'] ?? 'بدون نیاز به انتخاب تاریخ و ساعت'));
                }

                $ship = [
                    'method' => $shippingMethod,
                    'date'   => date('Y-m-d'),
                    'label'  => $noSlotLabel,
                    'fee'    => (int)$freshMethod['fee'],
                ];
            }

            $deliveryDate = trim((string)($ship['date'] ?? ''));
            if ($deliveryDate === '') $deliveryDate = date('Y-m-d');

            /* شرکت */
            $stmt = $db->prepare("SELECT company_id FROM company_users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $companyId = $stmt->fetchColumn();
            $companyId = $companyId !== false ? (int)$companyId : null;

            /* گیرنده نهایی */
            $finalReceiverName = $receiverName !== ''
                ? $receiverName
                : (!empty($addr['receiver_name']) ? $addr['receiver_name'] : $addr['label']);

            $finalReceiverMobile = $receiverMobile !== ''
                ? $receiverMobile
                : (!empty($addr['receiver_mobile']) ? $addr['receiver_mobile'] : (string)($_SESSION['customer_mobile'] ?? ''));

            /* شماره سفارش */
            $orderNumber = generate_unique_number($db, 'ORD', 'orders', 'order_number');

            $shippingFee   = (int)($ship['fee'] ?? 0);
            $total         = $finalSubtotal + $shippingFee;

            /* ثبت سفارش */
            $insOrder = $db->prepare("
                INSERT INTO orders
                (order_number, user_id, company_id, status, shipping_method,
                 subtotal, discount_total, shipping_fee, total,
                 shipping_name, shipping_mobile, shipping_province,
                 shipping_city, shipping_address, shipping_postal)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $insOrder->execute([
                $orderNumber,
                $userId,
                $companyId,
                'pending_review',
                $shippingMethod,
                $subtotal,
                $discountTotal,
                $shippingFee,
                $total,
                $finalReceiverName,
                $finalReceiverMobile,
                $addr['province'],
                $addr['city'],
                $addr['address'],
                $addr['postal_code'],
            ]);

            $orderId = (int)$db->lastInsertId();

            /* اقلام */
            $insItem = $db->prepare("
                INSERT INTO order_items
                (order_id, product_id, product_code, product_name, qty,
                 qty_reserved, qty_to_produce, base_unit_price,
                 group_discount_percent, tier_discount_percent,
                 discount_percent, discount_source, discount_amount,
                 unit_price, line_total)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($items as $it) {
                $insItem->execute([
                    $orderId,
                    (int)$it['product_id'],
                    $it['code'],
                    $it['name'],
                    (int)$it['qty'],
                    (int)$it['qty_reserved'],
                    (int)$it['qty_to_produce'],
                    (int)$it['base_unit_price'],
                    (float)$it['group_discount_percent'],
                    (float)$it['tier_discount_percent'],
                    (float)$it['discount_percent'],
                    (string)$it['discount_source'],
                    (int)$it['discount_amount'],
                    (int)$it['unit_price'],
                    (int)$it['line_total'],
                ]);
            }

            /* رزرو موجودی */
            $reserveStock = $db->prepare("UPDATE inventory SET reserved = reserved + ? WHERE product_id = ?");
            $logMovement  = $db->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, reference_type, reference_id,
                 order_id, order_item_id, reason, created_by, ip)
                VALUES (?, 'reserve', ?, 'order', ?, ?, ?, ?, ?, ?)
            ");
            $adminUser = $userId;
            foreach ($items as $it) {
                $qtyReserved = (int)$it['qty_reserved'];
                $productId   = (int)$it['product_id'];
                if ($qtyReserved > 0) {
                    $reserveStock->execute([$qtyReserved, $productId]);
                    if ($reserveStock->rowCount() !== 1) {
                        throw new RuntimeException('رزرو موجودی برای «' . $it['name'] . '» شکست خورد.');
                    }
                    $logMovement->execute([
                        $productId,
                        $qtyReserved,
                        null,
                        $orderId,
                        null,
                        'رزرو برای سفارش جدید',
                        $adminUser,
                        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    ]);
                }
            }

            /* Snapshot ارسال + calc_* */
            $shipmentLabel = trim((string)($ship['label'] ?? ''));
            if ($shipmentLabel === '') {
                $shipmentLabel = trim((string)($freshMethod['estimate'] ?? $freshMethod['label'] ?? 'روش ارسال'));
            }

            $calc = $freshMethod['calc'] ?? [];

            $db->prepare("
                INSERT INTO order_shipments
                (order_id, method, fee, delivery_date, slot_label, slot_key,
                 ship_name, ship_mobile, ship_province, ship_city, ship_address, ship_postal,
                 calc_mode, calc_base_amount, calc_tier_min, calc_tier_max, calc_free_rule_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $orderId,
                $ship['method'],
                $shippingFee,
                $deliveryDate,
                $shipmentLabel,
                $shippingSlot,
                $finalReceiverName,
                $finalReceiverMobile,
                $addr['province'],
                $addr['city'],
                $addr['address'],
                $addr['postal_code'],
                $calc['calc_mode']         ?? null,
                $calc['calc_base_amount']  ?? null,
                $calc['calc_tier_min']     ?? null,
                $calc['calc_tier_max']     ?? null,
                $calc['calc_free_rule_id'] ?? null,
            ]);

            /* تبدیل سبد */
            $db->prepare("UPDATE carts SET status = 'converted' WHERE user_id = ? AND status = 'active'")->execute([$userId]);
            $db->prepare("DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = ?")->execute([$userId]);

            /* فاکتور */
            $invNum = generate_unique_number($db, 'INV', 'invoices', 'invoice_number');
            $db->prepare("INSERT INTO invoices (invoice_number, order_id, user_id, total) VALUES (?,?,?,?)")
                ->execute([$invNum, $orderId, $userId, $total]);

            /* Audit */
            $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip) VALUES (?, 'order.create', 'order', ?, ?)")
                ->execute([$userId, $orderId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

            $db->commit();
            $transactionStarted = false;

            /* ارتقای سطح */
            try {
                if ($companyId !== null && $companyId > 0) {
                    OrderService::maybeUpgradeCompanyGroup($db, $companyId);
                }
                OrderService::maybeUpgradeCustomerGroup($db, $userId);
            } catch (Throwable $e) {
                error_log('AUTO UPGRADE: ' . $e->getMessage());
            }

            unset($_SESSION['checkout_address_id'], $_SESSION['checkout_shipping']);

            header('Location: /payment?order=' . urlencode($orderNumber));
            exit;
        } catch (RuntimeException $e) {
            if ($transactionStarted && $db->inTransaction()) $db->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($transactionStarted && $db->inTransaction()) $db->rollBack();
            error_log('CHECKOUT COMMIT ERROR: ' . $e->getMessage());
            $error = 'خطایی در ثبت سفارش رخ داد. لطفاً دوباره تلاش کنید.';
        }
    }
}

/* ═══ ۷) اقلام برای نمایش ═══ */
$userGroup = $isCustomer ? current_user_group() : 'regular';
if ($isCustomer) {
    $stmt = $db->prepare("
        SELECT
            ci.qty,
            p.id AS product_id,
            p.code,
            p.name,
            p.slug,
            p.base_price,
            (SELECT image_path FROM product_images pi
             WHERE pi.product_id = p.id
             ORDER BY pi.is_main DESC, pi.sort_order ASC
             LIMIT 1) AS image
        FROM cart_items ci
        JOIN carts c    ON c.id = ci.cart_id
        JOIN products p ON p.id = ci.product_id
        WHERE c.user_id = ? AND c.status = 'active'
    ");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();
} else {
    $guestCart = (isset($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart']))
        ? $_SESSION['guest_cart'] : [];
    $items = [];
    $stmt = $db->prepare("
        SELECT
            p.id AS product_id,
            p.code,
            p.name,
            p.slug,
            p.base_price,
            (SELECT image_path FROM product_images pi
             WHERE pi.product_id = p.id
             ORDER BY pi.is_main DESC, pi.sort_order ASC
             LIMIT 1) AS image
        FROM products p
        WHERE p.id = ? LIMIT 1
    ");
    foreach ($guestCart as $gPid => $gQty) {
        if ((int)$gQty <= 0) continue;
        $stmt->execute([(int)$gPid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['qty'] = (int)$gQty;
            $items[] = $row;
        }
    }
}

if (!$items && !$error) {
    header('Location: /cart');
    exit;
}

$subtotal = 0;
$discountTotal = 0;
foreach ($items as &$it) {
    $qty = (int)$it['qty'];
    if ($qty <= 0) continue;
    $pricing = PricingService::calculate($db, (int)$it['product_id'], $qty, $userGroup);
    if ($pricing['base_price'] <= 0) continue;

    $it['base_unit_price']  = $pricing['base_price'];
    $it['unit_price']       = $pricing['unit_price'];
    $it['discount_percent'] = $pricing['discount_percent'];
    $it['discount_amount']  = $pricing['discount_amount'];
    $it['base_line_total']  = $pricing['base_line_total'];
    $it['line_total']       = $pricing['line_total'];
    $it['discount_source']  = $pricing['discount_source'];

    $subtotal      += $pricing['base_line_total'];
    $discountTotal += $pricing['discount_amount'];
}
unset($it);

$finalSubtotal = max(0, $subtotal - $discountTotal);

$shippingFeeDisplay = (int)($shipping['fee'] ?? $selectedShippingMethod['fee'] ?? 0);
$total = $finalSubtotal + $shippingFeeDisplay;

/* ═══ ۸) تاریخ / بازه ═══ */
$shipDate = '';
$shipTimeLabel = '';

if ($shippingSlot !== '') {
    [$slotDay, $slotRange] = array_pad(explode('|', $shippingSlot, 2), 2, '');
    $shipDate = $slotDay;
    $shipTimeLabel = $slotRange;
}

if ($shippingSlot === '') {
    $shipTimeLabel = trim((string)($shipping['estimate'] ?? $selectedShippingMethod['estimate'] ?? ''));
    if ($shipTimeLabel === '') $shipTimeLabel = 'بدون نیاز به انتخاب تاریخ و ساعت';
}

$deliveryDateLabel = $shipDate !== '' ? $jalaliFa($shipDate) : '—';
$shipTimeLabelFa   = $slotFa($shipTimeLabel);

/* ═══ ۹) رندر ═══ */
$pageTitle  = 'خلاصه سفارش | رابطی';
$activeMenu = '';
ob_start();
?>

<meta name="robots" content="noindex,nofollow,noarchive">

<nav class="breadcrumb">
    <a href="/">خانه</a> ›
    <a href="/cart">سبد</a> ›
    <a href="/checkout-address">آدرس</a> ›
    <a href="/checkout-shipping">ارسال</a> ›
    <span>خلاصه</span>
</nav>
<script>try{sessionStorage.setItem('rabetiCheckout','1');}catch(e){}</script>

<section class="catalog-head">
    <h1 class="catalog-title">خلاصه سفارش</h1>
    <p class="catalog-sub">یک بار دیگر مرور کنید و تأیید نهایی را انجام دهید.</p>
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
    — منطقه: <b><?= $h(match ($options['zone']) {
                    'near' => 'نزدیک',
                    'far' => 'دور',
                    'very_far' => 'خیلی دور',
                    default => $options['zone']
                }) ?></b>
</div>

<?php if ($error): ?>
    <div class="form-alert error" style="display:block;">⚠️ <?= $h($error) ?></div>
<?php endif; ?>

<div class="review-grid">
    <div class="review-col">

        <div class="pd-specs-box">
            <div class="pd-price-title">اقلام سفارش</div>
            <?php foreach ($items as $it): ?>
                <div class="cs-row">
                    <div class="cs-row-main">
                        <?php if (!empty($it['image'])): ?>
                            <img src="<?= $h($it['image']) ?>" alt="<?= $h($it['name']) ?>" class="cs-thumb" loading="lazy">
                        <?php else: ?>
                            <div class="cs-thumb-empty">🧦</div>
                        <?php endif; ?>
                        <div class="cs-row-name">
                            <span>
                                <?= $h($it['name']) ?>
                                <small>× <?= number_format((int)$it['qty']) ?></small>
                            </span>
                            <?php if (!empty($it['discount_percent']) && $it['discount_percent'] > 0): ?>
                                <small style="color:#27ae60;display:block;">
                                    <?= $it['discount_source'] === 'group' ? 'تخفیف سطح' : 'تخفیف پلکانی' ?>
                                    <?= number_format((float)$it['discount_percent'], 2) ?>٪
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <b><?= number_format((int)$it['line_total']) ?></b>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pd-specs-box">
            <div class="pd-price-title">آدرس ارسال</div>
            <p class="review-text">
                📍 <?= $h($address['label']) ?><br>
                <?= $h($address['province']) ?>، <?= $h($address['city']) ?><br>
                <?= $h($address['address']) ?>
                <?php if (!empty($address['plate_no'])): ?>, پلاک <?= $h($address['plate_no']) ?><?php endif; ?>
            </p>
            <a href="/checkout-address" class="cs-edit">تغییر آدرس ←</a>
        </div>

        <div class="pd-specs-box">
            <div class="pd-price-title">ارسال</div>
            <p class="review-text">
                🚚 <?= $h($shippingMethodLabel) ?>
                <?php if ($shippingFeeDisplay === 0): ?>
                    <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;
                                 font-size:10px;font-weight:800;margin-right:6px;">🎁 رایگان</span>
                <?php endif; ?>
                <?php if ($deliveryDateLabel !== '—'): ?>
                    <br>📅 <?= $h($deliveryDateLabel) ?>
                <?php endif; ?>
                <br>⏰ <?= $h($shipTimeLabelFa) ?>
            </p>
            <a href="/checkout-shipping" class="cs-edit">تغییر ارسال ←</a>
        </div>

    </div>

    <div class="review-col">
        <div class="cart-summary" style="position:static;">

            <div class="cart-summary-row">
                <span>جمع کالاها</span>
                <b><?= number_format($subtotal) ?> تومان</b>
            </div>

            <?php if ($discountTotal > 0): ?>
                <div class="cart-summary-row">
                    <span>تخفیف</span>
                    <b><?= number_format($discountTotal) ?> تومان</b>
                </div>
            <?php endif; ?>

            <?php if (!$isCustomer): ?>
                <div class="cart-summary-row" style="color:#166534;">
                    <span>تخفیف سطح عضویت</span>
                    <b>بعد از ورود/ثبت‌نام اعمال می‌شود</b>
                </div>
            <?php endif; ?>

            <div class="cart-summary-row">
                <span>هزینه ارسال</span>
                <b>
                    <?php if ($shippingFeeDisplay === 0): ?>
                        <span style="color:#166534">رایگان 🎁</span>
                    <?php else: ?>
                        <?= number_format($shippingFeeDisplay) ?> تومان
                    <?php endif; ?>
                </b>
            </div>

            <div class="cart-summary-row total">
                <span>مبلغ نهایی</span>
                <b><?= number_format($total) ?> تومان</b>
            </div>

            <?php if ($isCustomer): ?>
            <div class="form-field" style="margin-top:14px;">
                <label>گیرنده (اختیاری — خالی = خودتان)</label>
                <input type="text" name="receiver_name" form="commitForm" value="<?= $h($_POST['receiver_name'] ?? '') ?>">
            </div>

            <div class="form-field">
                <label>موبایل گیرنده (اختیاری)</label>
                <input type="tel" name="receiver_mobile" dir="ltr" form="commitForm" value="<?= $h($_POST['receiver_mobile'] ?? '') ?>">
            </div>

            <form method="post" id="commitForm">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary cart-checkout-btn">
                    تأیید نهایی و پرداخت ←
                </button>
            </form>
            <?php else: ?>

            <div class="pd-specs-box" style="margin-top:14px;border:1px dashed var(--line-strong,#c9a961);">
                <div class="pd-price-title">مرحلهٔ آخر — ورود یا ثبت‌نام</div>
                <p class="review-text">
                    سبد و آدرس شما محفوظ است. با ورود یا ثبت‌نام در همین مرحله،
                    سبد مهمان به‌صورت خودکار با سبد حساب شما ادغام می‌شود و
                    تخفیف سطح عضویت روی سفارش اعمال می‌گردد.
                </p>
                <button type="button" class="btn btn-primary cart-checkout-btn" data-auth-open="login">
                    ورود و ثبت نهایی سفارش ←
                </button>
                <button type="button" class="btn btn-outline" data-auth-open="register" style="margin-top:10px;width:100%;">
                    ثبت‌نام سریع
                </button>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
// checkout.css: قوانین مشترک مراحل تسویه (checkout-) — فقط صفحات checkout
$pageCss = ['/assets/css/checkout.css?v=1'];
require ROOT_PATH . '/views/layouts/main.php';
