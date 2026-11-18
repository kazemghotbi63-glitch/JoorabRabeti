<?php

declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — پنل انبار (Warehouse Dashboard) | v4
 *  - فیلترهای پیشرفته + چیپ‌های سریع
 *  - نمایش کالاها با SKU و مشخصات داخل کارت
 *  - صفحه‌بندی واقعی با حفظ فیلترها
 *  - بج وضعیت پرداخت
 * ═══════════════════════════════════════════════════════════════════ */

$__bp = [__DIR__ . '/../../sock-b2b/config/bootstrap.php', __DIR__ . '/../../config/bootstrap.php'];
foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__bp, $__b);
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/OrderSmsService.php';

require_admin();

$db = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ═══ پردازش POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $newStatus = trim((string)($_POST['status'] ?? ''));

    // ذخیره فیلترهای فعلی برای بازگشت
    $returnQuery = $_POST['return_query'] ?? '';

    if ($id > 0 && in_array($newStatus, ['processing', 'packed', 'cancelled'], true)) {
        try {
            if ($newStatus === 'processing') {
                $invStmt = $db->prepare("SELECT total, paid_total FROM invoices WHERE order_id = ? LIMIT 1");
                $invStmt->execute([$id]);
                $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
                if ($inv && (int)$inv['total'] > 0 && (int)$inv['paid_total'] < (int)$inv['total']) {
                    throw new RuntimeException('در انتظار تائید واحد مالی');
                }
            }

            $db->beginTransaction();
            $db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$newStatus, $id]);

            if ($newStatus === 'cancelled') {
                $db->prepare("UPDATE inventory inv JOIN order_items oi ON oi.product_id = inv.product_id SET inv.reserved = GREATEST(0, inv.reserved - oi.qty_reserved) WHERE oi.order_id = ?")->execute([$id]);
            }

            $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip) VALUES (?, ?, 'order', ?, ?)")
                ->execute([$adminId, 'order.status.' . $newStatus, $id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $db->commit();

            $_SESSION['flash_success'] = 'وضعیت سفارش با موفقیت به‌روزرسانی شد.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = 'خطا: ' . $e->getMessage();
        }
    }
    // بازگشت با حفظ فیلترها
    header('Location: /admin/orders' . ($returnQuery ? '?' . $returnQuery : ''));
    exit;
}

/* ═══ دریافت فیلترها ═══ */
$q          = trim((string)($_GET['q'] ?? ''));
$status     = trim((string)($_GET['status'] ?? ''));
$shipMethod = trim((string)($_GET['ship_method'] ?? ''));
$dateFrom   = trim((string)($_GET['from'] ?? ''));
$dateTo     = trim((string)($_GET['to'] ?? ''));
$sort       = trim((string)($_GET['sort'] ?? 'newest'));
$perPage    = (int)($_GET['per_page'] ?? 24);
$page       = max(1, (int)($_GET['page'] ?? 1));

if (!in_array($perPage, [12, 24, 48, 96])) $perPage = 24;
if (!in_array($sort, ['newest', 'oldest', 'amount_desc', 'amount_asc', 'qty_desc'])) $sort = 'newest';

/* ═══ ساخت کوئری ═══ */
$conditions = [];
$params = [];

// فقط سفارش‌های نیازمند اقدام انبار (اگر فیلتر خاصی انتخاب نشده باشد)
if ($status === '') {
    $conditions[] = "o.status IN ('pending_review', 'processing')";
} else {
    $conditions[] = "o.status = ?";
    $params[] = $status;
}

if ($q !== '') {
    $conditions[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ? OR c.name LIKE ? OR i.invoice_number LIKE ?)";
    $params = array_merge($params, ["%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"]);
}
if ($shipMethod !== '') {
    $conditions[] = "o.shipping_method = ?";
    $params[] = $shipMethod;
}
if ($dateFrom !== '') {
    $gregorianFrom = jalali_to_gregorian($dateFrom);
    if ($gregorianFrom) {
        $conditions[] = "o.created_at >= ?";
        $params[] = $gregorianFrom . ' 00:00:00';
    }
}
if ($dateTo !== '') {
    $gregorianTo = jalali_to_gregorian($dateTo);
    if ($gregorianTo) {
        $conditions[] = "o.created_at <= ?";
        $params[] = $gregorianTo . ' 23:59:59';
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $conditions);

// مرتب‌سازی
$orderBy = "o.id DESC";
if ($sort === 'oldest') $orderBy = "o.id ASC";
elseif ($sort === 'amount_desc') $orderBy = "o.total DESC";
elseif ($sort === 'amount_asc') $orderBy = "o.total ASC";

// کوئری شمارش
$countStmt = $db->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN companies c ON c.id = o.company_id LEFT JOIN invoices i ON i.order_id = o.id {$whereSql}");
$countStmt->execute($params);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalOrders / $perPage));
$offset = ($page - 1) * $perPage;

// کوئری دریافت داده‌ها
$stmt = $db->prepare("
    SELECT o.*, u.full_name AS customer_name, u.mobile, c.name AS company_name,
           (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) AS total_qty,
           i.total AS invoice_total, i.paid_total AS invoice_paid_total, i.invoice_number
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN companies c ON c.id = o.company_id
    LEFT JOIN invoices i ON i.order_id = o.id
    {$whereSql}
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت اقلام برای کارت‌ها
$orderIds = array_column($orders, 'id');
$itemsByOrder = [];
if (!empty($orderIds)) {
    $inQuery = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = $db->prepare("
        SELECT oi.order_id, oi.qty, 
               p.id AS product_id, 
               p.code AS product_code,
               p.name AS product_name,
               p.color,
               p.size,
               p.material,
               p.brand,
               COALESCE(p.unit, 'عدد') AS order_unit,
               COALESCE(
                   (SELECT spec_value FROM product_specs 
                    WHERE product_id = p.id AND spec_key = 'تعداد در بسته' LIMIT 1),
                   '1'
               ) AS pack_count
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ({$inQuery})
    ");
    $itemStmt->execute($orderIds);  // ⬅️ این خط فراموش شده بود
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {  // ⬅️ این حلقه هم فراموش شده بود
        $itemsByOrder[$item['order_id']][] = $item;
    }
}


/* ─── تابع تبدیل شمسی به میلادی ─── */
function jalali_to_gregorian(string $jalaliDate): ?string
{
    if (empty($jalaliDate)) return null;

    $parts = preg_split('/[\/\-]/', $jalaliDate);
    if (count($parts) < 3) return null;

    $jy = (int)$parts[0];
    $jm = (int)$parts[1];
    $jd = (int)$parts[2];

    // اگر تاریخ میلادی وارد شده (سال بزرگ‌تر از 1700)
    if ($jy > 1700) {
        return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
    }

    $jy -= 979;
    $jm -= 1;
    $jd -= 1;

    $j_day_no = 365 * $jy + (int)($jy / 33) * 8 + (int)((($jy % 33) + 3) / 4);
    for ($i = 0; $i < $jm; ++$i) {
        $j_day_no += ($i < 6) ? 31 : 30;
    }
    $j_day_no += $jd;

    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400 * (int)($g_day_no / 146097);
    $g_day_no %= 146097;

    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * (int)($g_day_no / 36524);
        $g_day_no %= 36524;
        if ($g_day_no >= 365) {
            $g_day_no++;
        } else {
            $leap = false;
        }
    }

    $gy += 4 * (int)($g_day_no / 1461);
    $g_day_no %= 1461;
    if ($g_day_no >= 366) {
        $leap = false;
        $g_day_no--;
        $gy += (int)($g_day_no / 365);
        $g_day_no %= 365;
    }

    for ($i = 0; $g_day_no >= ($i < 6 ? 31 : 30); ++$i) {
        $g_day_no -= ($i < 6 ? 31 : 30);
    }
    $gm = $i + 1;
    $gd = $g_day_no + 1;

    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

function to_jalali(?string $dateStr): string
{
    if (!$dateStr) return '—';

    // اگر افزونه intl فعال است (بهترین روش)
    if (class_exists('IntlDateFormatter')) {
        try {
            $formatter = new IntlDateFormatter(
                'fa_IR@calendar=persian',
                IntlDateFormatter::SHORT,
                IntlDateFormatter::SHORT,
                'Asia/Tehran',
                IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd HH:mm'
            );
            $timestamp = strtotime($dateStr);
            return $formatter->format($timestamp) ?: $dateStr;
        } catch (Throwable $e) {
            // در صورت خطا، به روش دستی برمی‌گردیم
        }
    }

    // روش دستی جایگزین (اگر intl نبود)
    $timestamp = strtotime($dateStr);
    $g_y = (int)date('Y', $timestamp);
    $g_m = (int)date('n', $timestamp);
    $g_d = (int)date('j', $timestamp);

    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;

    $g_day_no = 365 * $gy + (int)(($gy + 3) / 4) - (int)(($gy + 99) / 100) + (int)(($gy + 399) / 400);
    for ($i = 0; $i < $gm; ++$i) {
        $g_day_no += $g_days_in_month[$i];
    }
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
        $g_day_no++;
    }
    $g_day_no += $gd;

    $j_day_no = $g_day_no - 79;
    $j_np = (int)($j_day_no / 12053);
    $j_day_no %= 12053;
    $jy = 979 + 33 * $j_np + 4 * (int)($j_day_no / 1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += (int)(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
        $j_day_no -= $j_days_in_month[$i];
    }
    $jm = $i + 1;
    $jd = $j_day_no + 1;

    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $timestamp));
}
// برای چیپ‌های سریع، تعداد هر وضعیت را بگیریم
$chipCounts = [];
$chipStmt = $db->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
foreach ($chipStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $chipCounts[$row['status']] = $row['cnt'];
}

$activeMenu = 'orders';
$pageTitle = 'مدیریت سفارش‌ها (انبار)';
ob_start();
?>

<style>
    /* ─── چیپ‌های سریع ─── */
    .quick-chips {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .chip {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid #e2e8f0;
        color: #64748b;
        background: #fff;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .chip:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }

    .chip.active {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }

    .chip .cnt {
        background: rgba(0, 0, 0, 0.1);
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.7rem;
    }

    .chip.active .cnt {
        background: rgba(255, 255, 255, 0.2);
    }

    /* ─── فیلتر پیشرفته ─── */
    .filter-panel {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        width: 100%;
        box-sizing: border-box;
        display: block;
    }

    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
        width: 100%;
        box-sizing: border-box;
    }

    .filter-item {
        flex: 1 1 180px;
        min-width: 150px;
        box-sizing: border-box;
    }

    .filter-item label {
        font-size: 0.72rem;
        color: #64748b;
        display: block;
        margin-bottom: 4px;
    }

    .filter-item input,
    .filter-item select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.85rem;
        font-family: inherit;
        box-sizing: border-box;
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    .btn-filter {
        padding: 10px 20px;
        background: #0f172a;
        color: #fff;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.85rem;
    }

    .btn-clear {
        padding: 10px 16px;
        background: #f1f5f9;
        color: #64748b;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
        font-size: 0.85rem;
        display: inline-block;
    }

    /* ─── کارت‌ها ─── */
    .order-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 20px;
        width: 100%;
        box-sizing: border-box;
    }

    .order-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        border: 1px solid #eef2f6;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        min-width: 0;
        /* ⭐ این خط کلیدی است: جلوگیری از سرریز شدن */
        width: 100%;
        box-sizing: border-box;
    }

    .order-card-header {
        padding: 14px 18px;
        background: #f8fafc;
        border-bottom: 1px solid #eef2f6;
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-width: 0;
    }

    .order-card-body {
        padding: 18px;
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
        /* ⭐ جلوگیری از سرریز شدن محتوا */
        box-sizing: border-box;
    }

    .order-meta {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-size: 0.82rem;
        color: #64748b;
        min-width: 0;
        gap: 8px;
    }

    .order-meta strong {
        color: #1e293b;
        text-align: left;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 60%;
    }

    /* ─── لیست کالاها ─── */
    .picking-list {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 12px;
        margin: 10px 0;
        min-width: 0;
        box-sizing: border-box;
        overflow: hidden;
    }

    .picking-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 8px 0;
        border-bottom: 1px solid #e2e8f0;
        gap: 8px;
        min-width: 0;
    }

    .picking-item:last-child {
        border-bottom: none;
    }

    .picking-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.82rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .picking-sku {
        font-size: 1.2rem;
        font-weight: bold;
        color: #64748b;
        font-family: monospace;
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 4px;
        display: inline-block;
        margin-top: 2px;
    }

    .picking-attr {
        font-size: 0.65rem;
        background: #e0f2fe;
        color: #0284c7;
        padding: 1px 6px;
        border-radius: 4px;
        display: inline-block;
        margin-left: 4px;
    }

    .picking-qty {
        background: #e0f2fe;
        color: #0284c7;
        padding: 4px 10px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.8rem;
        flex-shrink: 0;
    }

    .more-items {
        font-size: 0.72rem;
        color: #0f172a;
        font-weight: 600;
        padding: 6px 0;
        text-align: center;
        border-top: 1px dashed #e2e8f0;
        margin-top: 4px;
    }

    /* ─── دکمه‌ها ─── */
    .btn-action {
        width: 100%;
        padding: 12px;
        border-radius: 10px;
        font-size: 0.88rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        color: white;
        margin-top: 8px;
        font-family: inherit;
    }

    .btn-finance {
        background: #10b981;
    }

    .btn-warehouse {
        background: #3b82f6;
    }

    .btn-cancel {
        background: #f1f5f9;
        color: #64748b;
        width: auto;
        padding: 8px 14px;
        font-size: 0.72rem;
    }

    /* ─── وضعیت‌ها و بج‌ها ─── */
    .status-badge {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-pending_review {
        background: #fef3c7;
        color: #d97706;
    }

    .status-processing {
        background: #e0f2fe;
        color: #0284c7;
    }

    .status-packed {
        background: #fce7f3;
        color: #db2777;
    }

    .pay-badge {
        font-size: 0.68rem;
        padding: 3px 8px;
        border-radius: 6px;
        font-weight: 600;
    }

    .pay-paid {
        background: #dcfce7;
        color: #16a34a;
    }

    .pay-debt {
        background: #fee2e2;
        color: #ef4444;
    }

    /* ─── صفحه‌بندی ─── */
    .pagination {
        display: flex;
        gap: 6px;
        justify-content: center;
        margin-top: 30px;
        flex-wrap: wrap;
    }

    .pagination a,
    .pagination span {
        padding: 8px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        text-decoration: none;
        color: #64748b;
        font-size: 0.82rem;
    }

    .pagination .active {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }

    .pagination .disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    @media (max-width: 600px) {
        .order-grid {
            grid-template-columns: 1fr;
        }

        .filter-grid {
            grid-template-columns: 1fr;
        }
    }

    .show-more-btn {
        width: 100%;
        padding: 8px;
        margin-top: 6px;
        background: #f1f5f9;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        color: #3b82f6;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        transition: background 0.2s;
    }

    .show-more-btn:hover {
        background: #e2e8f0;
    }
</style>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="form-alert error" style="display:block; margin-bottom:16px;">⚠️ <?= $h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="form-alert success" style="display:block; margin-bottom:16px;">✅ <?= $h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<!-- ═══ چیپ‌های سریع ═══ -->
<div class="quick-chips">
    <a href="/admin/orders" class="chip <?= $status === '' ? 'active' : '' ?>">
        🏭 نیاز به انبار
        <span class="cnt"><?= ($chipCounts['pending_review'] ?? 0) + ($chipCounts['processing'] ?? 0) ?></span>
    </a>
    <a href="?status=pending_review" class="chip <?= $status === 'pending_review' ? 'active' : '' ?>">
        ⏳ در انتظار مالی
        <span class="cnt"><?= $chipCounts['pending_review'] ?? 0 ?></span>
    </a>
    <a href="?status=processing" class="chip <?= $status === 'processing' ? 'active' : '' ?>">
        📦 در حال آماده‌سازی
        <span class="cnt"><?= $chipCounts['processing'] ?? 0 ?></span>
    </a>
    <a href="?status=packed" class="chip <?= $status === 'packed' ? 'active' : '' ?>">
        📮 بسته‌بندی‌شده
        <span class="cnt"><?= $chipCounts['packed'] ?? 0 ?></span>
    </a>
    <a href="?status=delivered" class="chip <?= $status === 'delivered' ? 'active' : '' ?>">
        ✅ تحویل‌شده
        <span class="cnt"><?= $chipCounts['delivered'] ?? 0 ?></span>
    </a>
    <a href="?status=cancelled" class="chip <?= $status === 'cancelled' ? 'active' : '' ?>">
        ❌ لغو‌شده
        <span class="cnt"><?= $chipCounts['cancelled'] ?? 0 ?></span>
    </a>
</div>

<!-- ═══ فیلترهای پیشرفته ═══ -->
<form method="get" class="filter-panel">
    <!-- حفظ وضعیت چیپ در فرم -->
    <input type="hidden" name="status" value="<?= $h($status) ?>">

    <div class="filter-grid">
        <div class="filter-item">
            <label>جستجو (سفارش، فاکتور، مشتری، شرکت)</label>
            <input type="text" name="q" placeholder="مثلاً ORD-123 یا علی..." value="<?= $h($q) ?>">
        </div>
        <div class="filter-item">
            <label>روش ارسال</label>
            <select name="ship_method">
                <option value="">همه</option>
                <option value="pik" <?= $shipMethod === 'pik' ? 'selected' : '' ?>>🛵 پیک</option>
                <option value="post" <?= $shipMethod === 'post' ? 'selected' : '' ?>>📮 پست</option>
                <option value="tipax" <?= $shipMethod === 'tipax' ? 'selected' : '' ?>>📦 تیپاکس</option>
                <option value="barbari" <?= $shipMethod === 'barbari' ? 'selected' : '' ?>>🚛 باربری</option>
            </select>
        </div>
        <div class="filter-item">
            <label>از تاریخ (شمسی)</label>
            <input type="text" name="from" id="filter-from" placeholder="1403/08/17" value="<?= $h($dateFrom) ?>" autocomplete="off" style="direction:ltr; text-align:right;">
        </div>
        <div class="filter-item">
            <label>تا تاریخ (شمسی)</label>
            <input type="text" name="to" id="filter-to" placeholder="1403/08/17" value="<?= $h($dateTo) ?>" autocomplete="off" style="direction:ltr; text-align:right;">
        </div>
        <div class="filter-item">
            <label>مرتب‌سازی</label>
            <select name="sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>جدیدترین</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>قدیمی‌ترین</option>
                <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>بیشترین مبلغ</option>
                <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>کمترین مبلغ</option>
            </select>
        </div>
        <div class="filter-item">
            <label>تعداد در صفحه</label>
            <select name="per_page">
                <option value="12" <?= $perPage === 12 ? 'selected' : '' ?>>۱۲</option>
                <option value="24" <?= $perPage === 24 ? 'selected' : '' ?>>۲۴</option>
                <option value="48" <?= $perPage === 48 ? 'selected' : '' ?>>۴۸</option>
                <option value="96" <?= $perPage === 96 ? 'selected' : '' ?>>۹۶</option>
            </select>
        </div>
    </div>

    <div class="filter-actions">
        <button type="submit" class="btn-filter">🔍 اعمال فیلتر</button>
        <a href="/admin/orders" class="btn-clear">✖ پاک کردن</a>
        <span style="font-size: 0.78rem; color: #64748b; margin-right: auto;">نمایش <?= count($orders) ?> از <?= $totalOrders ?> سفارش</span>
    </div>
</form>

<!-- ═══ کارت‌های سفارش ═══ -->
<div class="order-grid">
    <?php if (empty($orders)): ?>
        <div style="grid-column: 1/-1; text-align:center; padding:60px; background:#f8fafc; border-radius:16px; border:2px dashed #e2e8f0;">
            <h3 style="color:#64748b;">هیچ سفارشی با این فیلتر یافت نشد.</h3>
        </div>
    <?php else: ?>
        <?php foreach ($orders as $o):
            $st = $o['status'];
            $canAdvance = true;
            if ($st === 'pending_review' && (int)($o['invoice_total'] ?? 0) > 0 && (int)($o['invoice_paid_total'] ?? 0) < (int)($o['invoice_total'] ?? 0)) {
                $canAdvance = false;
            }
            $items = $itemsByOrder[$o['id']] ?? [];
            $isPaid = ((int)($o['invoice_paid_total'] ?? 0) >= (int)($o['invoice_total'] ?? $o['total'] ?? 0));
            // ذخیره فیلترها برای بازگشت بعد از POST
            $returnQuery = http_build_query(['q' => $q, 'status' => $status, 'ship_method' => $shipMethod, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sort, 'per_page' => $perPage, 'page' => $page]);
        ?>
            <div class="order-card">
                <div class="order-card-header">
                    <div>
                        <a href="/admin/order-detail?id=<?= $o['id'] ?>" style="font-weight:700; color:#0f172a; text-decoration:none; font-size:0.95rem;">
                            #<?= $h($o['order_number']) ?>
                        </a>
                        <?php if (!empty($o['invoice_number'])): ?>
                            <div style="font-size:0.68rem; color:#64748b; margin-top:2px;">فاکتور: <?= $h($o['invoice_number']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:left;">
                        <span class="status-badge status-<?= $st ?>">
                            <?= $st === 'pending_review' ? 'در انتظار مالی' : ($st === 'processing' ? 'آماده‌سازی' : ($st === 'packed' ? 'بسته‌بندی‌شده' : $st)) ?>
                        </span>
                    </div>
                </div>

                <div class="order-card-body">
                    <div class="order-meta">
                        <span>مشتری:</span>
                        <strong><?= $h($o['customer_name']) ?> <?= $o['company_name'] ? "({$h($o['company_name'])})" : '' ?></strong>
                    </div>

                    <div class="order-meta">
                        <span>روش ارسال:</span>
                        <strong><?= ['pik' => 'پیک', 'post' => 'پست', 'tipax' => 'تیپاکس', 'barbari' => 'باربری'][$o['shipping_method'] ?? ''] ?? '—' ?></strong>
                    </div>

                    <!-- ⭐ لیست کالاها (با قابلیت نمایش همه) ⭐ -->
                    <div class="picking-list">
                        <div style="font-size:0.72rem; color:#64748b; margin-bottom:6px; font-weight:600;">📦 اقلام جهت آماده‌سازی:</div>

                        <?php if (empty($items)): ?>
                            <div style="font-size:0.78rem; color:#94a3b8;">جزئیات اقلام ثبت نشده است.</div>
                        <?php else: ?>
                            <?php
                            // نمایش ۳ قلم اول به صورت پیش‌فرض
                            $visibleItems = array_slice($items, 0, 0);
                            $hiddenItems = array_slice($items, 0);
                            $hiddenCount = count($hiddenItems);
                            ?>

                            <!-- اقلام قابل مشاهده (همیشه باز) -->
                            <div id="visible-items-<?= $o['id'] ?>">
                                <?php foreach ($visibleItems as $item): ?>
                                    <div class="picking-item">
                                        <div style="flex:1;">
                                            <div class="picking-name"><?= $h($item['product_name']) ?></div>
                                            <div>
                                                <span class="picking-sku"><?= $h($item['product_code']) ?></span>
                                                <?php if ($item['color']): ?><span class="picking-attr"><?= $h($item['color']) ?></span><?php endif; ?>
                                                <?php if ($item['size']): ?><span class="picking-attr"><?= $h($item['size']) ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="picking-qty">
                                            <?= (int)$item['qty'] ?> <?= $h($item['order_unit']) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- اقلام مخفی (در ابتدا بسته) -->
                            <?php if ($hiddenCount > 0): ?>
                                <div id="hidden-items-<?= $o['id'] ?>" style="display: none;">
                                    <?php foreach ($hiddenItems as $item): ?>
                                        <div class="picking-item">
                                            <div style="flex:1;">
                                                <div class="picking-name"><?= $h($item['product_name']) ?></div>
                                                <div>
                                                    <span class="picking-sku"><?= $h($item['product_code']) ?></span>
                                                    <?php if ($item['color']): ?><span class="picking-attr"><?= $h($item['color']) ?></span><?php endif; ?>
                                                    <?php if ($item['size']): ?><span class="picking-attr"><?= $h($item['size']) ?></span><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="picking-qty">
                                                <?= (int)$item['qty'] ?> <?= $h($item['order_unit']) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- دکمه نمایش بیشتر / کمتر -->
                                <button type="button"
                                    class="show-more-btn"
                                    onclick="toggleItems(<?= $o['id'] ?>, this)"
                                    data-more-text="+ نمایش <?= $hiddenCount ?> محصول جهت ارسال"
                                    data-less-text="− بستن لیست">
                                    + نمایش <?= $hiddenCount ?> محصول جهت ارسال
                                </button>
                            <?php endif; ?>

                            <!-- لینک مشاهده جزئیات کامل (همیشه نمایش داده شود) -->
                            <div style="text-align:center; margin-top:6px;">
                                <a href="/admin/order-detail?id=<?= $o['id'] ?>"
                                    style="font-size:0.72rem; color:#3b82f6; text-decoration:none; font-weight:600;">
                                    مشاهده جزئیات کامل ←
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- دکمه‌های عملیات -->
                    <div style="margin-top:auto; padding-top:12px; border-top:1px dashed #e2e8f0;">
                        <form method="post" style="display:flex; gap:8px; align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $o['id'] ?>">
                            <input type="hidden" name="return_query" value="<?= $h($returnQuery) ?>">

                            <?php if ($st === 'pending_review'): ?>
                                <button type="submit" name="status" value="processing" class="btn-action btn-finance" <?= $canAdvance ? '' : 'disabled style="opacity:0.5;cursor:not-allowed;"' ?>>
                                    ✅ تایید مالی (ارسال به انبار)
                                </button>
                            <?php elseif ($st === 'processing'): ?>
                                <button type="submit" name="status" value="packed" class="btn-action btn-warehouse">
                                    📦 بسته‌بندی شد (تحویل به ارسال)
                                </button>
                            <?php endif; ?>

                            <?php if (!in_array($st, ['cancelled', 'delivered'])): ?>
                                <button type="submit" name="status" value="cancelled" class="btn-action btn-cancel" onclick="return confirm('سفارش لغو شود؟');">
                                    لغو
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ═══ صفحه‌بندی ═══ -->
<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $queryParams = ['q' => $q, 'status' => $status, 'ship_method' => $shipMethod, 'from' => $dateFrom, 'to' => $dateTo, 'sort' => $sort, 'per_page' => $perPage];

        // لینک قبلی
        if ($page > 1) {
            $queryParams['page'] = $page - 1;
            echo '<a href="?' . http_build_query($queryParams) . '">قبلی ←</a>';
        } else {
            echo '<span class="disabled">قبلی ←</span>';
        }

        // شماره صفحات (هوشمند: حداکثر ۷ عدد)
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);
        if ($start > 1) echo '<span>...</span>';
        for ($i = $start; $i <= $end; $i++) {
            $queryParams['page'] = $i;
            if ($i == $page) {
                echo '<span class="active">' . $i . '</span>';
            } else {
                echo '<a href="?' . http_build_query($queryParams) . '">' . $i . '</a>';
            }
        }
        if ($end < $totalPages) echo '<span>...</span>';

        // لینک بعدی
        if ($page < $totalPages) {
            $queryParams['page'] = $page + 1;
            echo '<a href="?' . http_build_query($queryParams) . '">→ بعدی</a>';
        } else {
            echo '<span class="disabled">→ بعدی</span>';
        }
        ?>
    </div>
<?php endif; ?>
<script>
    function toggleItems(orderId, btn) {
        var hidden = document.getElementById('hidden-items-' + orderId);
        if (!hidden) return;

        if (hidden.style.display === 'none') {
            hidden.style.display = 'block';
            btn.textContent = btn.dataset.lessText;
        } else {
            hidden.style.display = 'none';
            btn.textContent = btn.dataset.moreText;
        }
    }
</script>
<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
