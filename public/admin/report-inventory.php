<?php
/* [FILE] public/admin/report-inventory.php — گزارش موجودی و فروش محصولات */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once __DIR__ . '/financial-helpers.php';

require_admin();

$db = db();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v): string => number_format((int)$v) . ' تومان';

fin_applyPreset();
$finRange = fin_dateRange();
[$from, $to] = $finRange;

/* فیلتر دسته */
$catId = (int)($_GET['cat'] ?? 0);

$categories = $db->query("SELECT id, name FROM categories WHERE is_active=1 ORDER BY sort_order")->fetchAll();

/* ═══ کوئری اصلی: هر محصول = موجودی فعلی + فروش در بازه ═══ */
$sql = "
    SELECT p.id, p.code, p.name,
           c.name AS cat_name,
           COALESCE(inv.qty_available, 0) AS stock,
           COALESCE((
               SELECT SUM(oi.qty)
               FROM order_items oi
               INNER JOIN orders o ON o.id = oi.order_id
               WHERE oi.product_id = p.id
                 AND o.status NOT IN ('cancelled')
                 AND o.created_at BETWEEN ? AND ?
           ), 0) AS sold_qty,
           COALESCE((
               SELECT SUM(oi.line_total)
               FROM order_items oi
               INNER JOIN orders o ON o.id = oi.order_id
               WHERE oi.product_id = p.id
                 AND o.status NOT IN ('cancelled')
                 AND o.created_at BETWEEN ? AND ?
           ), 0) AS sold_amount
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory inv ON inv.product_id = p.id
    WHERE p.is_active = 1
";
$params = [$from, $to, $from, $to];

if ($catId > 0) {
    $sql .= " AND p.category_id = ? ";
    $params[] = $catId;
}

/* جستجوی نام/کد */
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ?) ";
    array_push($params, '%' . $q . '%', '%' . $q . '%');
}

$sql .= " ORDER BY sold_qty DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalStock = 0;
$totalSold = 0;
$totalAmount = 0;
foreach ($rows as $r) {
    $totalStock  += (int)$r['stock'];
    $totalSold   += (int)$r['sold_qty'];
    $totalAmount += (int)$r['sold_amount'];
}

$pageTitle  = 'گزارش موجودی و فروش';
$activeMenu = 'reports';

ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <span>گزارش موجودی و فروش</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">موجودی و فروش محصولات</h1>
    <p class="catalog-sub">بازه: <?= $h(fin_jalali($from)) ?> تا <?= $h(fin_jalali($to)) ?></p>
</section>

<?php fin_rangeForm('/admin/report-inventory', ['cat' => $catId, 'q' => $q]); ?>

<!-- ═══ فیلتر دسته + جستجو ═══ -->
<div class="admin-card" style="padding:14px;margin-bottom:16px;">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="from" value="<?= $h($from) ?>">
        <input type="hidden" name="to" value="<?= $h($to) ?>">

        <div style="flex:1;min-width:200px;">
            <label style="display:block;font-size:11px;font-weight:700;margin-bottom:4px;">دسته‌بندی</label>
            <select name="cat" style="width:100%;padding:8px 10px;border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;">
                <option value="0">همه دسته‌ها</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= $h($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1;min-width:200px;">
            <label style="display:block;font-size:11px;font-weight:700;margin-bottom:4px;">جستجوی نام یا کد</label>
            <input type="text" name="q" value="<?= $h($q) ?>"
                style="width:100%;padding:8px 10px;border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;">
        </div>

        <button type="submit" class="btn btn-orange" style="padding:9px 20px;font-size:.78rem;">اعمال</button>
    </form>
</div>

<!-- ═══ آمار کلی ═══ -->
<div class="stat-cards" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <div class="stat-card stat-card-accent">
        <div class="stat-card-top"><span class="stat-card-label">موجودی کل انبار</span><span class="stat-card-icon">📦</span></div>
        <div class="num"><?= number_format($totalStock) ?></div>
        <div class="stat-card-note">جفت</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-card-label">فروش بازه (جفت)</span><span class="stat-card-icon">🧦</span></div>
        <div class="num"><?= number_format($totalSold) ?></div>
        <div class="stat-card-note">از <?= count($rows) ?> محصول</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-card-label">مبلغ فروش بازه</span><span class="stat-card-icon">💰</span></div>
        <div class="num"><?= $money($totalAmount) ?></div>
        <div class="stat-card-note">بدون احتساب برگشتی</div>
    </div>
</div>

<!-- ═══ جدول ═══ -->
<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>کد محصول</th>
                <th>دسته محصول</th>
                <th>نام محصول</th>
                <th>تعداد مانده</th>
                <th>تعداد فروش رفته</th>
                <th>مبلغ فروش بازه</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1;
            foreach ($rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td dir="ltr"><b><?= $h($r['code']) ?></b></td>
                    <td><?= $h($r['cat_name']) ?></td>
                    <td><?= $h($r['name']) ?></td>
                    <td>
                        <b style="color:<?= (int)$r['stock'] > 0 ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;">
                            <?= number_format((int)$r['stock']) ?>
                        </b>
                        جفت
                    </td>
                    <td><?= number_format((int)$r['sold_qty']) ?> جفت</td>
                    <td><?= $money((int)$r['sold_amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:800;">
                <td colspan="4" style="text-align:left;">جمع کل</td>
                <td><?= number_format($totalStock) ?> جفت</td>
                <td><?= number_format($totalSold) ?> جفت</td>
                <td><?= $money($totalAmount) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
