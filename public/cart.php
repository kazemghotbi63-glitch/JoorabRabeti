<?php
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
require_once ROOT_PATH . '/app/services/PricingService.php';

/* ⭐ خرید مهمان‌پسند: سبد برای مهمان هم باز است (سبد مهمان در session نگه داشته می‌شود و
   در مرحلهٔ آخر — ثبت سفارش — ورود/ثبت‌نام انجام می‌شود؛ بعد از ورود سبد ادغام می‌شود) */
$isCustomer = is_customer_logged_in();
if (!$isCustomer && is_admin_logged_in()) {
    header('Location: /admin');
    exit;
}

$h = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$userId = (int)($_SESSION['customer_id'] ?? 0);
$db = db();
$userGroup = $isCustomer ? current_user_group() : 'regular';

/* ═══════════════════════════════════════════════════════════
 * توابع کمکی فارسی
 * ═══════════════════════════════════════════════════════════ */

$moneyFa = static function ($amount, int $decimals = 0): string {
    $formatted = number_format((float)$amount, $decimals, '.', ',');
    $formatted = strtr($formatted, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
        '.' => '٫',
    ]);
    return $formatted . ' تومان';
};

$numFa = static function ($value, int $decimals = 0): string {
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return strtr($formatted, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
        '.' => '٫',
    ]);
};

/* ═══════════════════════════════════════════════════════════
 * اعتبارسنجی تعداد — بدون وابستگی به $numFa
 * ═══════════════════════════════════════════════════════════ */
function validateCartQty(int $qty, int $moq, int $stock, string $unit = 'جفت'): array
{
    $fa = static function ($v) {
        return strtr((string)$v, [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ]);
    };

    if ($qty < $moq) {
        return [
            'ok'    => false,
            'error' => 'حداقل سفارش این محصول ' . $fa($moq) . ' ' . $unit . ' است.',
        ];
    }

    if ($qty > $stock) {
        return [
            'ok'    => false,
            'error' => 'موجودی این محصول فقط ' . $fa($stock) . ' ' . $unit . ' است.',
        ];
    }

    return ['ok' => true, 'error' => null];
}

/* ═══════════════════════════════════════════════════════════
 * تغییر تعداد / حذف
 * ═══════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $do     = $_POST['do'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    /* ═══ ⭐ حالت مهمان: item_id همان product_id است و سبد در session است ═══ */
    if (!$isCustomer) {
        $guestCart = (isset($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart']))
            ? $_SESSION['guest_cart'] : [];

        $failG = static function (int $code, string $msg) use ($isAjax) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code($code);
                echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: /cart');
            }
            exit;
        };

        if ($itemId <= 0 || !isset($guestCart[$itemId])) {
            $failG(404, 'آیتم سبد پیدا نشد.');
        }

        $stmt = $db->prepare("
            SELECT p.id, p.base_moq, p.is_active,
                (SELECT spec_value FROM product_specs
                 WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
                GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS stock
            FROM products p
            LEFT JOIN inventory inv ON inv.product_id = p.id
            WHERE p.id = ? LIMIT 1
        ");
        $stmt->execute([$itemId]);
        $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pRow) {
            $failG(404, 'محصول یافت نشد.');
        }

        $unitLabelG = unit_label($pRow['unit_code'] ?? 'جفتی');

        /* ─── حذف ─── */
        if ($do === 'remove' || ($do === 'update' && (int)($_POST['qty'] ?? 0) <= 0)) {
            unset($guestCart[$itemId]);
            $_SESSION['guest_cart'] = $guestCart;

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'removed' => true], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: /cart');
            }
            exit;
        }

        /* ─── تغییر تعداد ─── */
        if ($do === 'update') {
            $qty = (int)($_POST['qty'] ?? 0);
            $validation = validateCartQty($qty, max(1, (int)$pRow['base_moq']), max(0, (int)$pRow['stock']), $unitLabelG);

            if (!$validation['ok']) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode([
                        'ok'    => false,
                        'error' => $validation['error'],
                        'min'   => (int)$pRow['base_moq'],
                        'max'   => (int)$pRow['stock'],
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    header('Location: /cart');
                }
                exit;
            }

            if (!(int)$pRow['is_active']) {
                $failG(422, 'این محصول دیگر فعال نیست.');
            }

            $guestCart[$itemId] = $qty;
            $_SESSION['guest_cart'] = $guestCart;

            if ($isAjax) {
                $pricing = PricingService::calculate($db, $itemId, $qty, 'regular');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'               => true,
                    'qty'              => $qty,
                    'count'            => (int)array_sum($guestCart),
                    'unit_price'       => $pricing['unit_price'],
                    'discount_percent' => $pricing['discount_percent'],
                    'discount_source'  => $pricing['discount_source'],
                    'line_total'       => $pricing['line_total'],
                    'customer_message' => $pricing['customer_message'],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: /cart');
            }
            exit;
        }

        $failG(400, 'درخواست نامعتبر است.');
    }

    if ($itemId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'شناسه آیتم نامعتبر است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /cart');
        exit;
    }

    /* ⭐ FIX اصلی: کوئری از cart_items شروع می‌شود (نه products) */
    $stmt = $db->prepare("
        SELECT
            ci.id            AS cart_item_id,
            ci.cart_id,
            ci.qty           AS cart_qty,
            ci.product_id,
            p.id             AS product_id,
            p.base_moq,
            p.base_price,
            p.is_active,
            (SELECT spec_value FROM product_specs
             WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
            GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS stock
        FROM cart_items ci
        INNER JOIN carts c ON c.id = ci.cart_id
        INNER JOIN products p ON p.id = ci.product_id
        LEFT JOIN inventory inv ON inv.product_id = p.id
        WHERE ci.id = ?
          AND c.user_id = ?
          AND c.status = 'active'
          AND (p.is_active = 1 OR ? = 'remove')
        LIMIT 1
    ");
    $stmt->execute([$itemId, $userId, $do]);
    $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cartItem) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'آیتم سبد پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /cart');
        exit;
    }

    $unitLabel = unit_label($cartItem['unit_code'] ?? 'جفتی');

    /* ─── حذف ─── */
    if ($do === 'remove') {
        $db->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?")
            ->execute([$itemId, $cartItem['cart_id']]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'removed' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /cart');
        exit;
    }

    /* ─── تغییر تعداد ─── */
    if ($do === 'update') {
        $qty = (int)($_POST['qty'] ?? 0);

        if ($qty <= 0) {
            $db->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?")
                ->execute([$itemId, $cartItem['cart_id']]);

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'removed' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /cart');
            exit;
        }

        $moq   = max(1, (int)$cartItem['base_moq']);
        $stock = max(0, (int)$cartItem['stock']);

        $validation = validateCartQty($qty, $moq, $stock, $unitLabel);

        if (!$validation['ok']) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(422);
                echo json_encode([
                    'ok'    => false,
                    'error' => $validation['error'],
                    'min'   => $moq,
                    'max'   => $stock,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /cart');
            exit;
        }

        if (!(int)$cartItem['is_active']) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'این محصول دیگر فعال نیست.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /cart');
            exit;
        }

        $db->prepare("UPDATE cart_items SET qty = ? WHERE id = ? AND cart_id = ?")
            ->execute([$qty, $itemId, $cartItem['cart_id']]);

        if ($isAjax) {
            $pricing = PricingService::calculate($db, (int)$cartItem['product_id'], $qty, $userGroup);

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
                'ok'               => true,
                'qty'              => $qty,
                'count'            => $totalCartCount,
                'unit_price'       => $pricing['unit_price'],
                'discount_percent' => $pricing['discount_percent'],
                'discount_source'  => $pricing['discount_source'],
                'line_total'       => $pricing['line_total'],
                'customer_message' => $pricing['customer_message'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: /cart');
        exit;
    }

    header('Location: /cart');
    exit;
}

/* ═══════════════════════════════════════════════════════════
 * اقلام سبد — با unit_code
 * ═══════════════════════════════════════════════════════════ */

if ($isCustomer) {
    $stmt = $db->prepare("
        SELECT
            ci.id,
            ci.qty,
            p.id AS product_id,
            p.code,
            p.name,
            p.slug,
            p.base_moq,
            p.base_price,
            p.is_active,
            (SELECT spec_value FROM product_specs
             WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
            GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS stock,
            (
                SELECT image_path FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_main DESC, pi.sort_order ASC
                LIMIT 1
            ) AS img
        FROM cart_items ci
        JOIN carts c ON c.id = ci.cart_id
        JOIN products p ON p.id = ci.product_id
        LEFT JOIN inventory inv ON inv.product_id = p.id
        WHERE c.user_id = ? AND c.status = 'active'
        ORDER BY ci.added_at DESC
    ");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    /* ⭐ رندر سبد مهمان از session (همان ساختار ردیف‌ها) */
    $guestCart = (isset($_SESSION['guest_cart']) && is_array($_SESSION['guest_cart']))
        ? $_SESSION['guest_cart'] : [];
    $items = [];
    $ids = array_map('intval', array_keys($guestCart));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT
                p.id AS id,
                p.id AS product_id,
                p.code,
                p.name,
                p.slug,
                p.base_moq,
                p.base_price,
                p.is_active,
                (SELECT spec_value FROM product_specs
                 WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
                GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS stock,
                (
                    SELECT image_path FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_main DESC, pi.sort_order ASC
                    LIMIT 1
                ) AS img
            FROM products p
            LEFT JOIN inventory inv ON inv.product_id = p.id
            WHERE p.id IN ($ph)
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = (int)($guestCart[(int)$row['product_id']] ?? 0);
            if ($qty > 0) {
                $row['qty'] = $qty;
                $items[] = $row;
            }
        }
        /* مثل رفتار DB (جدیدترین اول) ترتیب session معکوس می‌شود */
        $items = array_reverse($items);
    }
}

/* ═══════════════════════════════════════════════════════════
 * محاسبه قیمت + شمارش بر اساس واحد
 * ═══════════════════════════════════════════════════════════ */

$totalBase     = 0;
$totalFinal    = 0;
$totalDiscount = 0;
$unitCounts = [];
foreach ($items as &$it) {
    $qty = (int)$it['qty'];

    $pricing = PricingService::calculate($db, (int)$it['product_id'], $qty, $userGroup);

    $it['base_price']       = $pricing['base_price'];
    $it['unit_price']       = $pricing['unit_price'];
    $it['discount_amount']  = $pricing['discount_amount'];
    $it['discount_percent'] = $pricing['discount_percent'];
    $it['discount_source']  = $pricing['discount_source'];
    $it['customer_message'] = $pricing['customer_message'];
    $it['unit_label'] = unit_label($it['unit_code'] ?? 'جفتی');
    $it['next_tier'] = null;
    if ($pricing['discount_source'] !== 'group') {
        $tiers = PricingService::getTiers($db, (int)$it['product_id']);
        foreach ($tiers as $t) {
            if ($t['min_qty'] > $qty) {
                $nextPrice = PricingService::roundPrice(
                    (int)round($pricing['base_price'] * (1 - $t['discount_percent'] / 100))
                );
                $it['next_tier'] = [
                    'min_qty'          => $t['min_qty'],
                    'discount_percent' => $t['discount_percent'],
                    'price'            => $nextPrice,
                ];
                break;
            }
        }
    }

    $it['base_line_total'] = $pricing['base_line_total'];
    $it['line_total']      = $pricing['line_total'];
    $it['line_discount']   = $pricing['discount_amount'];

    if (!isset($unitCounts[$it['unit_label']])) {
        $unitCounts[$it['unit_label']] = 0;
    }
    $unitCounts[$it['unit_label']] += $qty;

    $totalBase     += $pricing['base_line_total'];
    $totalFinal    += $pricing['line_total'];
    $totalDiscount += $pricing['discount_amount'];
}
unset($it);

$totalQtyText = [];
foreach ($unitCounts as $unit => $count) {
    $totalQtyText[] = $numFa($count) . ' ' . $unit;
}
$totalQtyDisplay = $totalQtyText ? implode(' + ', $totalQtyText) : '۰';

/* ═══════════════════════════════════════════════════════════
 * بررسی نهایی سبد
 * ═══════════════════════════════════════════════════════════ */

$hasIssue = false;

foreach ($items as &$it) {
    $qty   = (int)$it['qty'];
    $moq   = max(1, (int)$it['base_moq']);
    $stock = (int)$it['stock'];
    $unit  = $it['unit_label'];

    $it['issue'] = null;

    if (!(int)$it['is_active']) {
        $it['issue'] = 'این محصول دیگر فعال نیست.';
        $hasIssue = true;
    } elseif ($it['unit_price'] <= 0) {
        $it['issue'] = 'قیمت این محصول مشخص نشده است.';
        $hasIssue = true;
    } elseif ($qty < $moq) {
        $it['issue'] = 'حداقل سفارش این محصول ' . $numFa($moq) . ' ' . $unit . ' است.';
        $hasIssue = true;
    } elseif ($stock < $qty) {
        $it['issue'] = 'موجودی این محصول فقط ' . $numFa($stock) . ' ' . $unit . ' است.';
        $hasIssue = true;
    }
}
unset($it);

/* ⭐ FIX: حداقل خرید تجمیعی محصولات جفتی — همان قاعدهٔ PricingService،
   مستقیم روی اقلام رندر (برای مهمان و کاربر یکسان) */
$pairQty = 0;
foreach ($items as $pIt) {
    if ((string)($pIt['unit_code'] ?? 'جفتی') === 'جفتی') {
        $pairQty += (int)$pIt['qty'];
    }
}
$cartMinimumIssue = null;
if (PricingService::violatesPairMinimum($pairQty)) {
    $minPairs = PricingService::CART_MIN_PAIR_QTY;
    $needed   = $minPairs - $pairQty;

    $cartMinimumIssue =
        'حداقل خرید محصولات جفتی ' . $numFa($minPairs) . ' جفت است. '
        . 'در سبد شما فعلاً ' . $numFa($pairQty) . ' جفت وجود دارد. '
        . 'فقط ' . $numFa($needed) . ' جفت دیگر اضافه کنید تا بتوانید سفارش را ثبت کنید.';

    $hasIssue = true;
}

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
    <h1 class="catalog-title">سبد سفارش شما</h1>
    <p class="catalog-sub">
        <?= $moneyFa(count($items)) ?> قلم کالا — قیمت‌گذاری همکاری و پلکانی
    </p>
</section>

<?php if (!empty($_SESSION['reorder_report'])):
    $rr = $_SESSION['reorder_report'];
    unset($_SESSION['reorder_report']);
    if (empty($rr['error'])): ?>
        <div class="reorder-notice" style="padding:14px 18px;background:#f0f9f4;border:1px solid #b7e4c7;border-radius:12px;margin-bottom:18px;font-size:13px;line-height:1.9;">
            <div style="font-weight:700;color:#2e8b57;margin-bottom:6px;">
                ✅ <?= (int)$rr['added'] ?> محصول به سبد اضافه شد
            </div>
            <?php if (!empty($rr['skipped_off'])): ?>
                <div style="color:#c53d3d;">⚠️ <?= (int)$rr['skipped_off'] ?> محصول دیگه در دسترس نیست</div>
            <?php endif; ?>
            <?php if (!empty($rr['skipped_oos'])): ?>
                <div style="color:#c53d3d;">⚠️ <?= (int)$rr['skipped_oos'] ?> محصول ناموجود بود</div>
            <?php endif; ?>
            <?php if (!empty($rr['adjusted'])): ?>
                <div style="color:#a06010;">📊 تعداد <?= (int)$rr['adjusted'] ?> محصول با موجودی/MOQ هماهنگ شد</div>
            <?php endif; ?>
        </div>
<?php endif;
endif; ?>

<?php if ($items): ?>

    <div class="cart-list">

        <?php foreach ($items as $it): ?>
            <?php
            $qty             = (int)$it['qty'];
            $moq             = max(1, (int)$it['base_moq']);
            $stock           = (int)$it['stock'];
            $unit            = $it['unit_label'];
            $unitPrice       = $it['unit_price'];
            $basePrice       = $it['base_price'];
            $lineTotal       = (float)$it['line_total'];
            $lineDiscount    = (float)$it['line_discount'];
            $nextTier        = $it['next_tier'];
            $hasNextTier     = is_array($nextTier) && isset($nextTier['min_qty']);
            $remainingToNext = $hasNextTier ? max(0, (int)$nextTier['min_qty'] - $qty) : 0;
            ?>

            <article class="cart-item" data-cart-item="<?= (int)$it['id'] ?>">

                <a href="/product/<?= $h($it['slug']) ?>" class="cart-item-img">
                    <?php if (!empty($it['img'])): ?>
                        <img src="<?= $h($it['img']) ?>" alt="<?= $h($it['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <span>🧦</span>
                    <?php endif; ?>
                </a>

                <div class="cart-item-info">
                    <h3><a href="/product/<?= $h($it['slug']) ?>"><?= $h($it['name']) ?></a></h3>

                    <span class="card-attr">
                        کد <?= $h($it['code']) ?> · حداقل <?= $numFa($moq) ?> <?= $h($unit) ?>
                    </span>

                    <div class="cart-item-price">
                        <?php if ($unitPrice > 0): ?>
                            <b><?= $moneyFa((float)$unitPrice) ?></b>

                            <?php if ($it['discount_percent'] > 0): ?>
                                <small style="display:block;margin-top:4px;color:#27ae60;">
                                    <?= $numFa((float)$it['discount_percent']) ?>٪
                                    <?= $it['discount_source'] === 'group' ? 'تخفیف سطح' : 'تخفیف پلکانی' ?>
                                    اعمال شد
                                </small>
                            <?php else: ?>
                                <small>قیمت همکاری</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <b class="cart-no-price">قیمت نیاز به استعلام دارد</b>
                        <?php endif; ?>
                    </div>

                    <?php if ($basePrice > 0 && $unitPrice > 0 && $unitPrice < $basePrice): ?>
                        <div style="font-size:.78rem;margin-top:4px;opacity:.75;">
                            قیمت پایه: <span style="text-decoration:line-through;"><?= $moneyFa((float)$basePrice) ?></span>
                        </div>
                    <?php endif; ?>

                    <span class="stock <?= $stock >= $qty ? 'in' : 'out' ?>">
                        <?= $stock >= $qty ? '● موجود در انبار' : '⚠ موجودی انبار فقط ' . $numFa($stock) . ' ' . $h($unit) ?>
                    </span>

                    <?php if ($hasNextTier && $remainingToNext > 0 && $it['discount_source'] !== 'group'): ?>
                        <div class="cart-tier-hint" style="margin-top:8px;font-size:.78rem;line-height:1.8;">
                            فقط <strong><?= $numFa($remainingToNext) ?></strong> <?= $h($unit) ?> دیگر اضافه کنید تا
                            قیمت هر <?= $h($unit) ?> به <strong><?= $moneyFa((float)$nextTier['price']) ?></strong> برسد.
                        </div>
                    <?php elseif ($it['discount_percent'] > 0): ?>
                        <div class="cart-tier-hint" style="margin-top:8px;font-size:.78rem;">
                            🎉 تخفیف <strong><?= $numFa((float)$it['discount_percent']) ?>٪</strong>
                            برای این تعداد فعال است.
                        </div>
                    <?php endif; ?>

                    <?php if ($it['issue']): ?>
                        <div class="cart-item-warn" style="display:block;">⚠️ <?= $h($it['issue']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="cart-item-side">

                    <div class="stepper"
                        data-item="<?= (int)$it['id'] ?>"
                        data-min="<?= $moq ?>"
                        data-max="<?= max($stock, $moq) ?>"
                        data-unit="<?= $h($unit) ?>">

                        <button type="button" class="step plus" aria-label="افزایش تعداد">+</button>
                        <input type="text" inputmode="numeric" class="step-num step-input" value="<?= $qty ?>" aria-label="تعداد">
                        <button type="button" class="step minus" aria-label="کاهش تعداد">−</button>
                    </div>

                    <div class="cart-line-total">
                        <small>جمع</small>
                        <b><?= $moneyFa($lineTotal) ?></b>
                        <?php if ($lineDiscount > 0): ?>
                            <small style="display:block;margin-top:3px;"><?= $moneyFa($lineDiscount) ?> صرفه‌جویی</small>
                        <?php endif; ?>
                    </div>

                    <form method="post" class="cart-remove-form" onsubmit="return confirm('این قلم حذف شود؟');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="remove">
                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                        <button type="submit" class="cart-remove">🗑 حذف</button>
                    </form>

                </div>
            </article>
        <?php endforeach; ?>

        <div class="cart-summary">

            <div class="cart-summary-row">
                <span>تعداد کل</span>
                <b><?= $h($totalQtyDisplay) ?></b>
            </div>

            <?php if ($totalDiscount > 0): ?>
                <div class="cart-summary-row">
                    <span>جمع قیمت پایه</span>
                    <b><?= $moneyFa($totalBase) ?></b>
                </div>
                <div class="cart-summary-row">
                    <span>تخفیف حجمی</span>
                    <b><?= $moneyFa($totalDiscount) ?></b>
                </div>
            <?php endif; ?>

            <div class="cart-summary-row total">
                <span>مبلغ نهایی سفارش</span>
                <b><?= $moneyFa($totalFinal) ?></b>
            </div>

            <?php if ($hasIssue): ?>

                <?php if ($cartMinimumIssue): ?>
                    <div class="form-alert error" style="display:block;line-height:2;">
                        ⚠️ <?= $h($cartMinimumIssue) ?>
                    </div>
                <?php endif; ?>

                <?php
                $hasItemIssue = false;
                foreach ($items as $checkItem) {
                    if (!empty($checkItem['issue'])) {
                        $hasItemIssue = true;
                        break;
                    }
                }
                ?>

                <?php if ($hasItemIssue): ?>
                    <div class="form-alert error" style="display:block;">
                        ⚠️ برخی اقلام سبد نیاز به اصلاح دارند.
                    </div>
                <?php endif; ?>

            <?php else: ?>

                <a href="/preview-invoice?preview=1" target="_blank" class="btn btn-outline cart-preview-btn">👁 مشاهده پیش‌فاکتور</a>
                <a href="/checkout-address" class="btn btn-primary cart-checkout-btn">ادامه و ثبت سفارش ←</a>
            <?php endif; ?>

            <a href="/products" class="cart-continue">ادامه خرید</a>
        </div>
    </div>

<?php else: ?>

    <div class="empty-state">
        <div class="empty-icon">🛒</div>
        <h2>سبد سفارش شما خالی است</h2>
        <a href="/products" class="btn btn-primary">مشاهده محصولات</a>
    </div>

<?php endif; ?>

<script>
    (function() {
        'use strict';

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
            return parseInt(faToEn(input.value), 10) || 0;
        }

        function writeQty(input, val) {
            input.value = enToFa(val);
        }

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
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
                    }
                })
                .then(function(r) {
                    return r.json().catch(function() {
                        return {
                            ok: false,
                            error: 'پاسخ نامعتبر'
                        };
                    });
                })

                .then(function(data) {
                    if (!data.ok) {
                        alert(data.error || 'تغییر تعداد انجام نشد.');
                        // ⭐ FIX: بازگرداندن UI به وضعیت واقعی DB (مثلاً MOQ/موجودی)
                        window.location.reload();
                        return;
                    }
                    if (window.updateCartBadges && data.count !== undefined) {
                        window.updateCartBadges(data.count);
                    }

                    // محاسبه مجدد حداقل خرید و وضعیت اعتبارسنجی سبد
                    window.location.reload();
                    return;
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
// cart.css: فقط قوانین سبد خرید (cart-list/cart-item/cart-summary/...) — استخراج‌شده از app.css
$pageCss = ['/assets/css/cart.css?v=1'];
require ROOT_PATH . '/views/layouts/main.php';
