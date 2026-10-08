<?php
/* [FILE] public/admin/report-discounts.php — گزارش تخفیف‌ها */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once __DIR__ . '/financial-helpers.php';

require_admin();

$db = db();

fin_applyPreset();
$finRange = fin_dateRange();
[$from, $to] = $finRange;

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v): string => number_format((int)$v) . ' تومان';

/* ═══ تخفیف در سطح آیتم + سطح سفارش ═══ */
$stmt = $db->prepare("
    SELECT i.invoice_number, i.issued_at,
           u.full_name, u.company_name,
           o.order_number,
           (SELECT COALESCE(SUM(
                CASE WHEN oi.base_unit_price > oi.unit_price
                     THEN (oi.base_unit_price - oi.unit_price) * oi.qty
                     ELSE 0 END
           ), 0)
            FROM order_items oi WHERE oi.order_id = o.id) AS item_discount,
           o.discount_amount AS order_discount
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN users u ON u.id = i.user_id
    WHERE i.issued_at BETWEEN ? AND ?
    ORDER BY i.issued_at DESC
");
$stmt->execute([$from, $to]);
$discounts = $stmt->fetchAll();

/* فیلتر فقط تخفیف‌دارها */
$onlyDiscounted = isset($_GET['only']) && $_GET['only'] === '1';
if ($onlyDiscounted) {
    $discounts = array_filter($discounts, fn($d) => ((int)$d['item_discount'] + (int)$d['order_discount']) > 0);
}

$totalItemDisc = 0;
$totalOrderDisc = 0;
foreach ($discounts as $d) {
    $totalItemDisc  += (int)$d['item_discount'];
    $totalOrderDisc += (int)$d['order_discount'];
}

$pageTitle  = 'گزارش تخفیف‌ها';
$activeMenu = 'reports';

ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <span>گزارش تخفیف‌ها</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">گزارش تخفیف‌ها</h1>
    <p class="catalog-sub">از <?= $h(fin_jalali($from)) ?> تا <?= $h(fin_jalali($to)) ?></p>
</section>

<?php fin_rangeForm('/admin/report-discounts'); ?>

<div class="stat-cards" style="grid-template-columns:repeat(2,minmax(0,1fr));">
    <div class="stat-card stat-card-accent">
        <div class="stat-card-top"><span class="stat-card-label">تخفیف روی آیتم‌ها</span><span class="stat-card-icon">%</span></div>
        <div class="num"><?= $money($totalItemDisc) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-card-label">تخفیف روی کل سفارش</span><span class="stat-card-icon">%</span></div>
        <div class="num"><?= $money($totalOrderDisc) ?></div>
    </div>
</div>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>تاریخ</th>
                <th>شماره فاکتور</th>
                <th>سفارش</th>
                <th>نام خریدار</th>
                <th>تخفیف آیتم‌ها</th>
                <th>تخفیف سفارش</th>
                <th>جمع تخفیف</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1;
            foreach ($discounts as $d):
                $itemDisc = (int)$d['item_discount'];
                $orderDisc = (int)$d['order_discount'];
                $total = $itemDisc + $orderDisc;
            ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $h(fin_jalali($d['issued_at'])) ?></td>
                    <td dir="ltr"><?= $h($d['invoice_number']) ?></td>
                    <td dir="ltr"><?= $h($d['order_number']) ?></td>
                    <td><?= $h($d['full_name']) ?><?= $d['company_name'] ? ' — ' . $h($d['company_name']) : '' ?></td>
                    <td><?= $itemDisc > 0 ? $money($itemDisc) : '—' ?></td>
                    <td><?= $orderDisc > 0 ? $money($orderDisc) : '—' ?></td>
                    <td style="font-weight:800;"><?= $total > 0 ? $money($total) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:800;">
                <td colspan="5" style="text-align:left;">جمع کل تخفیف‌ها</td>
                <td><?= $money($totalItemDisc) ?></td>
                <td><?= $money($totalOrderDisc) ?></td>
                <td><?= $money($totalItemDisc + $totalOrderDisc) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
