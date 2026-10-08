<?php
/* [FILE] public/admin/report-shipping.php — گزارش هزینه‌های ارسال */

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

/* ═══ هزینه ارسال به تفکیک روش (از order_shipments) ═══ */
$byMethod = $db->prepare("
    SELECT s.method, s.slot_label,
           COUNT(*) AS cnt,
           SUM(s.fee) AS total_fee
    FROM order_shipments s
    INNER JOIN orders o ON o.id = s.order_id
    WHERE s.created_at BETWEEN ? AND ?
    GROUP BY s.method, s.slot_label
    ORDER BY total_fee DESC
");
$byMethod->execute([$from, $to]);
$methods = $byMethod->fetchAll();

/* ═══ ردیف‌های تفصیلی ═══ */
$stmt = $db->prepare("
    SELECT s.fee, s.method, s.slot_label, s.delivery_date, s.ship_city,
           o.order_number, i.invoice_number, u.full_name
    FROM order_shipments s
    INNER JOIN orders o ON o.id = s.order_id
    INNER JOIN users u ON u.id = o.user_id
    LEFT JOIN invoices i ON i.order_id = o.id
    WHERE s.created_at BETWEEN ? AND ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();

$grandTotal = 0;
foreach ($rows as $r) $grandTotal += (int)$r['fee'];

$methodLabels = ['peyk' => 'پیک', 'post' => 'پست', 'in_person' => 'حضوری'];

$pageTitle  = 'گزارش هزینه‌های ارسال';
$activeMenu = 'reports';

ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <span>گزارش هزینه‌های ارسال</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">گزارش هزینه‌های ارسال</h1>
    <p class="catalog-sub">از <?= $h(fin_jalali($from)) ?> تا <?= $h(fin_jalali($to)) ?></p>
</section>

<?php fin_rangeForm('/admin/report-shipping'); ?>

<!-- ═══ خلاصه به تفکیک روش ═══ -->
<div class="admin-table-wrap" style="margin-bottom:20px;">
    <table class="admin-table">
        <thead>
            <tr>
                <th>روش ارسال</th>
                <th>تعداد</th>
                <th>جمع هزینه</th>
            </tr>
        </thead>
        <tbody>
            <?php $sum = 0;
            foreach ($methods as $m): $sum += (int)$m['total_fee']; ?>
                <tr>
                    <td><b><?= $h($methodLabels[$m['method']] ?? $m['method']) ?></b> — <?= $h($m['slot_label']) ?></td>
                    <td><?= number_format((int)$m['cnt']) ?></td>
                    <td><b><?= $money((int)$m['total_fee']) ?></b></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:800;">
                <td>جمع کل</td>
                <td><?= number_format(count($rows)) ?></td>
                <td><?= $money($grandTotal) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ═══ ردیف‌های تفصیلی ═══ -->
<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>تاریخ ارسال</th>
                <th>روش</th>
                <th>بازه</th>
                <th>شهر</th>
                <th>سفارش</th>
                <th>مشتری</th>
                <th>هزینه</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1;
            foreach ($rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $h(fin_jalali($r['delivery_date'])) ?></td>
                    <td><?= $h($methodLabels[$r['method']] ?? $r['method']) ?></td>
                    <td><?= $h($r['slot_label']) ?></td>
                    <td><?= $h($r['ship_city']) ?></td>
                    <td dir="ltr"><?= $h($r['order_number']) ?></td>
                    <td><?= $h($r['full_name']) ?></td>
                    <td><b><?= $money((int)$r['fee']) ?></b></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:800;">
                <td colspan="7" style="text-align:left;">جمع کل هزینه‌های ارسال</td>
                <td><?= $money($grandTotal) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
