<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

$db  = db();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT o.*, u.full_name, u.mobile, c.name AS company_name
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN companies c ON c.id = o.company_id
    WHERE o.id = ? LIMIT 1
");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /admin/orders');
    exit;
}

$stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$activeMenu = 'orders';
$pageTitle  = 'سفارش ' . $order['order_number'];
ob_start();
?>

<a href="/admin/orders" style="color:var(--teal-700);font-size:.82rem;">→ بازگشت به سفارش‌ها</a>

<div class="stat-cards" style="margin-top:14px;">
    <div class="stat-card">
        <div class="num" dir="ltr"><?= htmlspecialchars($order['order_number']) ?></div>
        <div class="lbl">شماره سفارش</div>
    </div>
    <div class="stat-card accent">
        <div class="num"><?= number_format((float)$order['total']) ?></div>
        <div class="lbl">مبلغ کل (تومان)</div>
    </div>
    <div class="stat-card">
        <div class="num"><?= htmlspecialchars($order['status']) ?></div>
        <div class="lbl">وضعیت</div>
    </div>
</div>

<div class="admin-table-wrap" style="margin-bottom:20px;">
    <table class="admin-table">
        <thead>
            <tr>
                <th>کد</th>
                <th>محصول</th>
                <th>تعداد</th>
                <th>قیمت واحد</th>
                <th>جمع</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><b><?= htmlspecialchars($it['product_code']) ?></b></td>
                    <td><?= htmlspecialchars($it['product_name']) ?></td>
                    <td><?= number_format((int)$it['qty']) ?> جفت</td>
                    <td><?= number_format((int)$it['unit_price']) ?></td>
                    <td><b><?= number_format((int)$it['line_total']) ?></b> ت</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:16px;padding:20px;margin-bottom:20px;">
    <h3 style="font-size:.95rem;margin:0 0 12px;">اطلاعات ارسال</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:.82rem;">
        <div><b>گیرنده:</b> <?= htmlspecialchars($order['shipping_name']) ?></div>
        <div><b>موبایل:</b> <span dir="ltr"><?= htmlspecialchars($order['shipping_mobile']) ?></span></div>
        <div><b>استان/شهر:</b> <?= htmlspecialchars($order['shipping_province']) ?> / <?= htmlspecialchars($order['shipping_city']) ?></div>
        <div style="grid-column:1/-1;"><b>آدرس:</b> <?= htmlspecialchars($order['shipping_address']) ?></div>
        <?php if ($order['shipping_postal']): ?>
            <div><b>کد پستی:</b> <span dir="ltr"><?= htmlspecialchars($order['shipping_postal']) ?></span></div>
        <?php endif; ?>
        <?php if ($order['customer_note']): ?>
            <div style="grid-column:1/-1;"><b>توضیحات مشتری:</b> <?= htmlspecialchars($order['customer_note']) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
