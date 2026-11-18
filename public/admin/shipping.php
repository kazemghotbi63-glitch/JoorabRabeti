<?php

declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — پنل ارسال (Shipping Dashboard) | v2
 *  - فیلتر پیشرفته + چیپ‌های سریع
 *  - نمایش اطلاعات کامل ارسال (آدرس، شهر، روش، کد رهگیری)
 *  - صفحه‌بندی واقعی
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
require_once ROOT_PATH . '/config/settings.php';
require_once ROOT_PATH . '/app/services/SmsService.php';
require_once ROOT_PATH . '/app/services/OrderSmsService.php';

require_admin();

$db = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ─── تابع تبدیل تاریخ به شمسی ─── */
function to_jalali(?string $dateStr): string
{
    if (!$dateStr) return '—';
    if (class_exists('IntlDateFormatter')) {
        try {
            $formatter = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::SHORT, IntlDateFormatter::SHORT, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd HH:mm');
            return $formatter->format(strtotime($dateStr)) ?: $dateStr;
        } catch (Throwable $e) {
        }
    }
    return $dateStr;
}

/* ═══ پردازش POST (تحویل به حامل) ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $newStatus = trim((string)($_POST['status'] ?? ''));
    $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));
    $returnQuery = $_POST['return_query'] ?? '';

    $allowedShippingStatuses = ['shipped-pik', 'shipped-post', 'shipped-tipax', 'shipped-barbari'];
    $smsShouldSend = false;

    if ($id > 0 && in_array($newStatus, $allowedShippingStatuses, true)) {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE orders SET status = ?, tracking_code = ? WHERE id = ? AND status = 'packed'");
            $stmt->execute([$newStatus, $trackingCode ?: null, $id]);

            if ($stmt->rowCount() === 1) {
                $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip) VALUES (?, ?, 'order', ?, ?)")
                    ->execute([$adminId, 'order.status.' . $newStatus, $id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

                $_SESSION['flash_success'] = 'سفارش با موفقیت به حامل تحویل داده شد.';
                $smsShouldSend = true;
            } else {
                $_SESSION['flash_error'] = 'وضعیت سفارش تغییر کرده یا نامعتبر است.';
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = 'خطا: ' . $e->getMessage();
        }

        /* ⭐ پیامک بعد از commit — خطای SMS تراکنش را خراب نمی‌کند */
        if ($smsShouldSend) {
            try {
                $smsResult = OrderSmsService::orderShipping($id);
                if (empty($smsResult['skipped'])) {
                    $_SESSION['flash_success'] .= ' و پیامک «سفارش ارسال شد» برای مشتری رفت.';
                }
            } catch (Throwable $e) {
                error_log('SHIPPING SMS FAILED: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'وضعیت ثبت شد، اما پیامک ناموفق بود: ' . $e->getMessage();
            }
        }
    }

    header('Location: /admin/shipping' . ($returnQuery ? '?' . $returnQuery : ''));
    exit;
}

/* ═══ دریافت فیلترها ═══ */
$q          = trim((string)($_GET['q'] ?? ''));
$shipMethod = trim((string)($_GET['ship_method'] ?? ''));
$dateFrom   = trim((string)($_GET['from'] ?? ''));
$dateTo     = trim((string)($_GET['to'] ?? ''));
$perPage    = (int)($_GET['per_page'] ?? 24);
$page       = max(1, (int)($_GET['page'] ?? 1));

if (!in_array($perPage, [12, 24, 48, 96])) $perPage = 24;

/* ═══ ساخت کوئری (فقط سفارش‌های packed) ═══ */
/* ═══ ساخت کوئری (فقط سفارش‌های packed) ═══ */
$conditions = ["o.status = 'packed'"];
$params = [];

if ($q !== '') {
    $conditions[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ? OR c.name LIKE ?)";
    $params = array_merge($params, ["%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"]);
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



// شمارش برای صفحه‌بندی
$countStmt = $db->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN companies c ON c.id = o.company_id {$whereSql}");
$countStmt->execute($params);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalOrders / $perPage));
$offset = ($page - 1) * $perPage;

// دریافت سفارش‌ها
$stmt = $db->prepare("
    SELECT o.*, u.full_name AS customer_name, u.mobile, c.name AS company_name,
           (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) AS total_qty
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN companies c ON c.id = o.company_id
    {$whereSql}
    ORDER BY o.id DESC
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
        SELECT oi.order_id, oi.qty, p.code AS product_code, p.name AS product_name, p.color, p.size
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ({$inQuery})
    ");
    $itemStmt->execute($orderIds);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByOrder[$item['order_id']][] = $item;
    }
}

// شمارنده چیپ‌ها (بر اساس روش ارسال)
$chipCounts = ['pik' => 0, 'post' => 0, 'tipax' => 0, 'barbari' => 0, 'other' => 0];
$chipStmt = $db->query("SELECT shipping_method, COUNT(*) as cnt FROM orders WHERE status = 'packed' GROUP BY shipping_method");
foreach ($chipStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $m = strtolower(trim($row['shipping_method'] ?? 'other'));
    if (isset($chipCounts[$m])) $chipCounts[$m] = (int)$row['cnt'];
    else $chipCounts['other'] += (int)$row['cnt'];
}

$activeMenu = 'shipping';
$pageTitle = 'پنل ارسال';
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
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
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

    /* ─── اطلاعات ارسال ─── */
    .shipping-info {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-radius: 10px;
        padding: 12px;
        margin: 10px 0;
    }

    .shipping-info .row {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        padding: 4px 0;
    }

    .shipping-info .row .label {
        color: #64748b;
    }

    .shipping-info .row .value {
        color: #0f172a;
        font-weight: 600;
    }

    /* ─── لیست کالاها ─── */
    .picking-list {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 10px;
        margin: 8px 0;
        min-width: 0;
        box-sizing: border-box;
        overflow: hidden;
    }

    .picking-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 6px 0;
        border-bottom: 1px solid #e2e8f0;
        gap: 8px;
        min-width: 0;
        font-size: 0.78rem;
    }

    .picking-item:last-child {
        border-bottom: none;
    }

    .picking-name {
        font-weight: 600;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .picking-sku {
        font-size: 1rem;
        font-weight: 900;
        color: #64748b;
        font-family: monospace;
        background: #f1f5f9;
        padding: 1px 5px;
        border-radius: 4px;
        display: inline-block;
        margin-top: 2px;
    }

    .picking-qty {
        background: #e0f2fe;
        color: #0284c7;
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.75rem;
        flex-shrink: 0;
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

    .btn-pik {
        background: #8b5cf6;
    }

    .btn-post {
        background: #ef4444;
    }

    .btn-tipax {
        background: #0ea5e9;
    }

    .btn-barbari {
        background: #f59e0b;
    }

    .btn-other {
        background: #64748b;
    }

    /* ─── وضعیت‌ها ─── */
    .status-badge {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-packed {
        background: #fce7f3;
        color: #db2777;
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
</style>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="form-alert error" style="display:block; margin-bottom:16px;">⚠️ <?= $h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="form-alert success" style="display:block; margin-bottom:16px;">✅ <?= $h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div class="admin-page-intro" style="margin-bottom: 16px;">
    <h1 style="margin:0; font-size:1.4rem;">پنل ارسال</h1>
    <p style="margin:4px 0 0; color:#64748b; font-size:0.9rem;">سفارش‌های بسته‌بندی‌شده، آماده تحویل به حامل.</p>
</div>

<!-- ═══ چیپ‌های سریع ═══ -->
<div class="quick-chips">
    <a href="/admin/shipping" class="chip <?= $shipMethod === '' ? 'active' : '' ?>">
        📋 همه
        <span class="cnt"><?= $totalOrders ?></span>
    </a>
    <a href="?ship_method=pik" class="chip <?= $shipMethod === 'pik' ? 'active' : '' ?>">
        🛵 پیک
        <span class="cnt"><?= $chipCounts['pik'] ?></span>
    </a>
    <a href="?ship_method=post" class="chip <?= $shipMethod === 'post' ? 'active' : '' ?>">
        📮 پست
        <span class="cnt"><?= $chipCounts['post'] ?></span>
    </a>
    <a href="?ship_method=tipax" class="chip <?= $shipMethod === 'tipax' ? 'active' : '' ?>">
        📦 تیپاکس
        <span class="cnt"><?= $chipCounts['tipax'] ?></span>
    </a>
    <a href="?ship_method=barbari" class="chip <?= $shipMethod === 'barbari' ? 'active' : '' ?>">
        🚛 باربری
        <span class="cnt"><?= $chipCounts['barbari'] ?></span>
    </a>
</div>

<!-- ═══ فیلترهای پیشرفته ═══ -->
<form method="get" class="filter-panel">
    <input type="hidden" name="ship_method" value="<?= $h($shipMethod) ?>">
    <div class="filter-grid">
        <div class="filter-item">
            <label>جستجو (سفارش، مشتری، شرکت)</label>
            <input type="text" name="q" placeholder="مثلاً ORD-123 یا علی..." value="<?= $h($q) ?>">
        </div>
        <div class="filter-item">
            <label>از تاریخ</label>
            <input type="text" name="from" placeholder="1403/08/17" value="<?= $h($dateFrom) ?>" autocomplete="off" style="direction:ltr; text-align:right;">
        </div>
        <div class="filter-item">
            <label>تا تاریخ</label>
            <input type="text" name="to" placeholder="1403/08/17" value="<?= $h($dateTo) ?>" autocomplete="off" style="direction:ltr; text-align:right;">
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
        <a href="/admin/shipping" class="btn-clear">✖ پاک کردن</a>
        <span style="font-size: 0.78rem; color: #64748b; margin-right: auto;">نمایش <?= count($orders) ?> از <?= $totalOrders ?> سفارش</span>
    </div>
</form>

<!-- ═══ کارت‌های سفارش ═══ -->
<div class="order-grid">
    <?php if (empty($orders)): ?>
        <div style="grid-column: 1/-1; text-align:center; padding:60px; background:#f8fafc; border-radius:16px; border:2px dashed #e2e8f0;">
            <h3 style="color:#64748b;">هیچ سفارشی آماده ارسال نیست.</h3>
        </div>
    <?php else: ?>
        <?php foreach ($orders as $o):
            $method = strtolower(trim($o['shipping_method'] ?? 'other'));
            $methodLabel = ['pik' => 'پیک', 'post' => 'پست', 'tipax' => 'تیپاکس', 'barbari' => 'باربری'][$method] ?? 'سایر';
            $items = $itemsByOrder[$o['id']] ?? [];
            $returnQuery = http_build_query(['q' => $q, 'ship_method' => $shipMethod, 'from' => $dateFrom, 'to' => $dateTo, 'per_page' => $perPage, 'page' => $page]);
        ?>
            <div class="order-card">
                <div class="order-card-header">
                    <div>
                        <a href="/admin/order-detail?id=<?= $o['id'] ?>" style="font-weight:700; color:#0f172a; text-decoration:none; font-size:0.95rem;">
                            #<?= $h($o['order_number']) ?>
                        </a>
                        <div style="font-size:0.68rem; color:#64748b; margin-top:2px;"><?= to_jalali($o['created_at']) ?></div>
                    </div>
                    <span class="status-badge status-packed">📦 آماده ارسال</span>
                </div>

                <div class="order-card-body">
                    <div class="order-meta">
                        <span>مشتری:</span>
                        <strong><?= $h($o['customer_name']) ?> <?= $o['company_name'] ? "({$h($o['company_name'])})" : '' ?></strong>
                    </div>
                    <div class="order-meta">
                        <span>موبایل:</span>
                        <strong dir="ltr"><?= $h($o['mobile']) ?></strong>
                    </div>

                    <!-- ⭐ اطلاعات ارسال (مهم‌ترین بخش برای اپراتور) ⭐ -->
                    <div class="shipping-info">
                        <div class="row">
                            <span class="label">روش ارسال:</span>
                            <span class="value"><?= $methodLabel ?></span>
                        </div>
                        <div class="row">
                            <span class="label">شهر مقصد:</span>
                            <span class="value"><?= $h($o['shipping_city'] ?? '—') ?></span>
                        </div>
                        <div class="row">
                            <span class="label">آدرس:</span>
                            <span class="value" style="max-width:65%; text-align:left; font-size:0.75rem;"><?= $h(mb_substr($o['shipping_address'] ?? '—', 0, 60)) ?><?= mb_strlen($o['shipping_address'] ?? '') > 60 ? '...' : '' ?></span>
                        </div>
                        <div class="row">
                            <span class="label">مبلغ:</span>
                            <span class="value" style="color:#10b981;"><?= number_format((float)$o['total']) ?> تومان</span>
                        </div>
                    </div>

                    <!-- لیست اقلام (برای تایید نهایی) -->
                    <div class="picking-list">
                        <div style="font-size:0.7rem; color:#64748b; margin-bottom:4px; font-weight:600;">📋 اقلام:</div>
                        <?php if (empty($items)): ?>
                            <div style="font-size:0.75rem; color:#94a3b8;">جزئیات ثبت نشده.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($items, 0, 3) as $item): ?>
                                <div class="picking-item">
                                    <div style="flex:1;">
                                        <div class="picking-name"><?= $h($item['product_name']) ?></div>
                                        <span class="picking-sku"><?= $h($item['product_code']) ?></span>
                                        <?php if ($item['color']): ?><span class="picking-sku"><?= $h($item['color']) ?></span><?php endif; ?>
                                        <?php if ($item['size']): ?><span class="picking-sku"><?= $h($item['size']) ?></span><?php endif; ?>
                                    </div>
                                    <div class="picking-qty"><?= (int)$item['qty'] ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($items) > 3): ?>
                                <div style="font-size:0.7rem; color:#64748b; text-align:center; padding-top:4px;">+ <?= count($items) - 3 ?> کالای دیگر</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- فرم تحویل به حامل -->
                    <div style="margin-top:auto; padding-top:12px; border-top:1px dashed #e2e8f0;">
                        <form method="post" style="display:flex; flex-direction:column; gap:8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $o['id'] ?>">
                            <input type="hidden" name="status" value="shipped-<?= $method === 'other' ? 'post' : $method ?>">
                            <input type="hidden" name="return_query" value="<?= $h($returnQuery) ?>">

                            <input type="text" name="tracking_code" placeholder="کد رهگیری (اختیاری)" dir="ltr" style="padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:0.82rem;">

                            <button type="submit" class="btn-action btn-<?= $method === 'other' ? 'post' : $method ?>" onclick="return confirm('تحویل به <?= $methodLabel ?> تایید شود؟');">
                                🚀 تایید تحویل به <?= $methodLabel ?>
                            </button>
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
        $queryParams = ['q' => $q, 'ship_method' => $shipMethod, 'from' => $dateFrom, 'to' => $dateTo, 'per_page' => $perPage];

        if ($page > 1) {
            $queryParams['page'] = $page - 1;
            echo '<a href="?' . http_build_query($queryParams) . '">قبلی ←</a>';
        } else {
            echo '<span class="disabled">قبلی ←</span>';
        }

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

        if ($page < $totalPages) {
            $queryParams['page'] = $page + 1;
            echo '<a href="?' . http_build_query($queryParams) . '">→ بعدی</a>';
        } else {
            echo '<span class="disabled">→ بعدی</span>';
        }
        ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
