<?php
/* [FILE] public/preview-invoice.php — پیش‌فاکتور از سبد */

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

if (!is_customer_logged_in()) {
    header('Location: ' . (is_admin_logged_in() ? '/admin' : '/'));
    exit;
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$userId = (int)($_SESSION['customer_id'] ?? 0);
$db = db();



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
        '.' => '٫',  // اعشار فارسی (اختیاری)
    ]);
    return $formatted . ' تومان';
};

// ⭐ تابع جدید — فقط عدد فارسی، بدون "تومان"
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

/* گرفتن آیتم‌های سبد */
$stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$userId]);
$cartId = (int)($stmt->fetchColumn() ?: 0);

if (!$cartId) {
    header('Location: /cart');
    exit;
}
/* کنترل حداقل مجموع خرید محصولات جفتی */
/* ⭐ FIX: کنترل حداقل مجموع خرید محصولات جفتی — از منبع مشترک */
$pairQty = PricingService::getPairQtyByCart($db, $cartId);

if (PricingService::violatesPairMinimum($pairQty)) {
    header('Location: /cart');
    exit;
}

/* ═══ آیتم‌های سبد + عکس + قیمت ═══ */
$stmt = $db->prepare("
    SELECT
        ci.id AS item_id,
        ci.qty,
        p.id AS product_id,
        p.code,
        p.name,
        p.slug,
        p.base_moq,
        p.base_price,
        GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS stock,
        (
            SELECT image_path FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS image
    FROM cart_items ci
    INNER JOIN products p ON p.id = ci.product_id
    LEFT JOIN inventory inv ON inv.product_id = p.id
    WHERE ci.cart_id = ?
    ORDER BY ci.id ASC
");
$stmt->execute([$cartId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* اطلاعات مشتری */
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

/* ═══ محاسبه با PricingService ═══ */
$userGroup = current_user_group();
$subtotal = 0;
$totalQty = 0;
$totalDiscount = 0;
$hasPrice = true;

foreach ($items as &$it) {
    $qty = (int)$it['qty'];

    $pricing = PricingService::calculate(
        $db,
        (int)$it['product_id'],
        $qty,
        $userGroup
    );

    $it['unit_price']       = $pricing['unit_price'];
    $it['base_unit_price']  = $pricing['base_price'];
    $it['discount_percent'] = $pricing['discount_percent'];
    $it['discount_amount']  = $pricing['discount_amount'];
    $it['line_total']       = $pricing['line_total'];
    $it['base_line_total']  = $pricing['base_line_total'];
    $it['discount_source']  = $pricing['discount_source'];
    $it['price']            = $pricing['unit_price'];

    if ($pricing['unit_price'] <= 0) {
        $hasPrice = false;
    }

    $subtotal       += $pricing['base_line_total'];
    $totalDiscount  += $pricing['discount_amount'];
    $totalQty       += $qty;
}
unset($it);

$finalSubtotal = max(0, $subtotal - $totalDiscount);

$pageTitle = 'پیش‌فاکتور | رابطی';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($pageTitle) ?></title>
    <script src="/assets/js/jalali.js"></script>
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background: #f2f3f5;
            padding: 20px;
            direction: rtl;
            margin: 0;
        }

        .invoice-page {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 14px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .08);
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 120px;
            font-weight: 900;
            color: rgba(42, 95, 120, 0.05);
            pointer-events: none;
            z-index: 0;
            white-space: nowrap;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .brand {
            font-size: 24px;
            font-weight: 900;
            color: #2a5f78;
        }

        .brand small {
            display: block;
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
            font-weight: 400;
        }

        .meta {
            text-align: left;
            font-size: 13px;
        }

        .meta .type {
            font-size: 18px;
            font-weight: 800;
            color: #a35c3c;
            margin-bottom: 6px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .info-box {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 13px;
            line-height: 1.9;
        }

        .info-box b {
            color: #1f2937;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            background: #f8fafc;
            padding: 12px 8px;
            text-align: right;
            font-weight: 700;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }

        td {
            padding: 12px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        td.center {
            text-align: center;
        }

        td.ltr {
            direction: ltr;
            text-align: left;
        }

        .item-thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .item-thumb-empty {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: #f9fafb;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }

        .totals {
            margin-top: 20px;
            max-width: 400px;
            margin-right: auto;
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px 20px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }

        .total-row.discount {
            color: #059669;
        }

        .total-row.final {
            border-top: 2px solid #1f2937;
            margin-top: 8px;
            padding-top: 14px;
            font-size: 18px;
            font-weight: 900;
            color: #2a5f78;
        }

        .actions {
            margin-top: 24px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-primary {
            background: #2a5f78;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            color: #2a5f78;
            border: 1.5px solid #2a5f78;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .note {
            margin-top: 20px;
            padding: 12px 16px;
            background: #fff4e5;
            border-radius: 10px;
            font-size: 12px;
            color: #a06010;
            line-height: 1.8;
        }

        .discount-badge {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 4px;
            background: #d1fae5;
            color: #065f46;
            margin-right: 6px;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .invoice-page {
                box-shadow: none;
            }

            .actions {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="watermark">رابطی</div>

    <div class="invoice-page">

        <div class="header">
            <div class="brand">
                رابطی
                <small>RABETI — کارگاه تولیدی جوراب</small>
            </div>
            <div class="meta">
                <div class="type">پیش‌فاکتور</div>
                <div>تاریخ: <span data-iso="<?= date('Y-m-d') ?>" data-style="long">...</span></div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-box">
                <b>مشتری:</b><br>
                <?= $h($customer['full_name'] ?? '-') ?><br>
                موبایل: <span dir="ltr"><?= $h($customer['mobile'] ?? '-') ?></span>
            </div>
            <div class="info-box">
                <b>تعداد کل اقلام:</b> <?= $numFa($totalQty) ?> عدد<br>
                <b>تعداد ردیف:</b> <?= $numFa(count($items)) ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th style="width: 80px;">تصویر</th>
                    <th>محصول</th>
                    <th style="width: 100px;">کد</th>
                    <th style="width: 80px;">تعداد</th>
                    <th style="width: 120px;">قیمت واحد</th>
                    <th style="width: 140px;">جمع</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <?php
                    $qty = (int)$it['qty'];
                    $price = $it['unit_price'];
                    $lineTotal = $it['line_total'];
                    $hasDiscount = $it['discount_amount'] > 0;
                    ?>
                    <tr>
                        <td class="center"><?= $i + 1 ?></td>
                        <td class="center">
                            <?php if (!empty($it['image'])): ?>
                                <img src="<?= $h($it['image']) ?>"
                                    alt="<?= $h($it['name']) ?>"
                                    class="item-thumb">
                            <?php else: ?>
                                <div class="item-thumb-empty">🧦</div>
                            <?php endif; ?>
                        </td>
                        <td><?= $h($it['name']) ?></td>
                        <td class="ltr"><?= $h($it['code']) ?></td>
                        <td class="center"><?= $moneyFa($qty) ?></td>
                        <td class="ltr">
                            <?= $price > 0 ? $moneyFa($price)  : 'استعلام' ?>
                            <?php if ($hasDiscount): ?>
                                <br>
                                <span class="discount-badge">
                                    <?= $numFa($it['discount_percent'], 0) ?>٪
                                    <?= $it['discount_source'] === 'group' ? 'سطح' : 'پله‌ای' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="ltr">
                            <?= $lineTotal > 0 ? $moneyFa($lineTotal) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($hasPrice): ?>
            <div class="totals">
                <div class="total-row">
                    <span>جمع کل (قبل از تخفیف):</span>
                    <b><?= $moneyFa($subtotal) ?> </b>
                </div>
                <?php if ($totalDiscount > 0): ?>
                    <div class="total-row discount">
                        <span>تخفیف:</span>
                        <b>- <?= $moneyFa($totalDiscount) ?> </b>
                    </div>
                <?php endif; ?>
                <div class="total-row final">
                    <span>مبلغ قابل پرداخت:</span>
                    <span><?= $moneyFa($finalSubtotal) ?> </span>
                </div>
            </div>
        <?php endif; ?>

        <div class="note">
            این پیش‌فاکتور است و اعتبار آن تا <b><span data-iso="<?= date('Y-m-d', strtotime('+3 days')) ?>" data-style="long">...</span></b> می‌باشد. برای ثبت نهایی سفارش، روی دکمهٔ «ادامه فرآیند خرید» کلیک کنید.
        </div>

        <div class="actions">
            <a href="/checkout-address" class="btn btn-primary">تأیید و ادامه خرید ←</a>
            <button type="button" class="btn btn-outline" onclick="window.print()">🖨 چاپ</button>
            <a href="/cart" class="btn btn-outline">بازگشت به سبد</a>
        </div>

    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.BPM && window.BPM.jalali) {
                var J = window.BPM.jalali;
                document.querySelectorAll('[data-iso]').forEach(function(el) {
                    var iso = el.getAttribute('data-iso');
                    var style = el.getAttribute('data-style') || 'long';
                    if (iso) el.textContent = J.format(iso, style);
                });
            }
        });
    </script>

</body>

</html>