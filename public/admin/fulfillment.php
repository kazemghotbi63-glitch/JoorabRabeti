<?php

declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — واحد آماده‌سازی (انبار)  |  v1 | 2026-11-08
 *
 *  نقش این صفحه: واحد انبار سفارش‌های پرداخت‌شده را بسته‌بندی می‌کند
 *  و به وضعیت «packed» (آماده تحویل) می‌برد.
 *
 *  گردش کار:
 *    processing (پرداخت تأیید شده) → packed (آماده تحویل)
 *
 *  این صفحه فقط transition بالا را انجام می‌دهد. تغییر به shipped/
 *  delivered بر عهدهٔ واحد تحویل (/admin/delivery) است.
 * ═══════════════════════════════════════════════════════════════════ */

/* ─── Bootstrap ─── */
$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
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
require_once ROOT_PATH . '/app/services/ShippingReturnsService.php';
require_once ROOT_PATH . '/app/services/OrderSmsService.php';
require_once ROOT_PATH . '/app/services/SmsService.php';
require_once ROOT_PATH . '/config/settings.php';

require_admin();

$db      = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$flash    = '';
$flashErr = '';

/* ─── Helper ─── */
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ═══ POST: بسته‌بندی سفارش ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = $_POST['do'] ?? '';

    try {
        if ($do === 'pack_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId < 1) {
                throw new InvalidArgumentException('شناسه سفارش نامعتبر');
            }

            $result = ShippingReturnsService::markPacked($db, $orderId, $adminId);

            /* SMS (بعد از commit) — رویداد order_shipping_packed */
            if (!empty($result['event'])) {
                try {
                    OrderSmsService::orderShipping($orderId);
                } catch (Throwable $e) {
                    error_log('FULFILLMENT PACK SMS FAILED: ' . $e->getMessage());
                }
            }

            $flash = "سفارش #{$orderId} بسته‌بندی و آماده تحویل شد.";
        }
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

/* ═══ فیلترها ═══ */
$filterTab = trim((string)($_GET['tab'] ?? 'queue'));   // queue | packed
$filterQ   = trim((string)($_GET['q'] ?? ''));
$filterFrom = trim((string)($_GET['from'] ?? ''));   // شمسی
$filterTo   = trim((string)($_GET['to'] ?? ''));     // شمسی

$conditions = [];
$params     = [];

if ($filterTab === 'queue') {
    // سفارش‌های در انتظار بسته‌بندی (processing و پرداخت‌شده)
    $conditions[] = "(o.status = 'processing'
                     OR (o.fulfillment_status = 'processing' AND o.payment_status = 'paid'))";
} elseif ($filterTab === 'packed') {
    // بسته‌بندی‌شده‌ها (آماده تحویل به واحد تحویل)
    $conditions[] = "(o.status = 'packed' OR o.fulfillment_status = 'packed')";
}

if ($filterQ !== '') {
    $conditions[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ?)";
    $kw = '%' . $filterQ . '%';
    array_push($params, $kw, $kw, $kw);
}

if ($filterFrom !== '') {
    $g = jalali_to_gregorian($filterFrom);
    if ($g) {
        $conditions[] = 'o.created_at >= ?';
        $params[] = $g . ' 00:00:00';
    }
}
if ($filterTo !== '') {
    $g = jalali_to_gregorian($filterTo);
    if ($g) {
        $conditions[] = 'o.created_at <= ?';
        $params[] = $g . ' 23:59:59';
    }
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/* ═══ لیست سفارش‌ها ═══ */
$stmt = $db->prepare("
    SELECT
        o.id, o.order_number, o.status, o.payment_status,
        o.fulfillment_status, o.shipping_status, o.shipping_method,
        o.total, o.created_at,
        u.full_name AS customer_name, u.mobile,
        s.method AS ship_method, s.ship_city, s.ship_address,
        s.delivery_date, s.slot_label,
        (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) AS total_qty,
        i.total AS invoice_total, i.paid_total AS invoice_paid_total, i.status AS invoice_status
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN order_shipments s ON s.order_id = o.id
    LEFT JOIN invoices i ON i.order_id = o.id
    {$where}
    ORDER BY
        CASE o.status
            WHEN 'processing' THEN 1
            WHEN 'packed'     THEN 2
            ELSE 9
        END,
        o.id DESC
    LIMIT 200
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══ شمارنده‌های بالا ═══ */
$countQueue  = (int)$db->query("
    SELECT COUNT(*) FROM orders
    WHERE status = 'processing'
       OR (fulfillment_status = 'processing' AND payment_status = 'paid')
")->fetchColumn();

$countPacked = (int)$db->query("
    SELECT COUNT(*) FROM orders
    WHERE status = 'packed' OR fulfillment_status = 'packed'
")->fetchColumn();

$activeMenu = 'fulfillment';
$pageTitle  = 'آماده‌سازی — واحد انبار';

ob_start();
?>

<?php if ($flash): ?>
    <div class="alert alert-success" style="background:#e8f5ee;color:#1a7f4b;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        ✓ <?= $h($flash) ?>
    </div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-error" style="background:#fff0f0;color:#c62828;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        ✗ <?= $h($flashErr) ?>
    </div>
<?php endif; ?>

<div class="admin-page-intro">
    <div>
        <span class="admin-kicker">واحد انبار</span>
        <h1>آماده‌سازی سفارش‌ها</h1>
        <p>سفارش‌های پرداخت‌شده را بسته‌بندی کنید و به واحد تحویل بفرستید.</p>
    </div>
</div>

<!-- ═══ شمارنده‌های بالا ═══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
    <div style="background:#fff;border:1px solid var(--card-border);border-radius:10px;padding:14px;">
        <div style="font-size:.7rem;color:var(--muted);">در انتظار بسته‌بندی</div>
        <div style="font-size:1.8rem;font-weight:800;color:#c62828;"><?= fa_digits((string)$countQueue) ?></div>
    </div>
    <div style="background:#fff;border:1px solid var(--card-border);border-radius:10px;padding:14px;">
        <div style="font-size:.7rem;color:var(--muted);">بسته‌بندی شده (آماده تحویل)</div>
        <div style="font-size:1.8rem;font-weight:800;color:#1a7f4b;"><?= fa_digits((string)$countPacked) ?></div>
    </div>
</div>

<!-- ═══ تب‌ها ═══ -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
    <a href="/admin/fulfillment?tab=queue"
        class="st-badge <?= $filterTab === 'queue' ? 'st-new' : '' ?>"
        style="text-decoration:none;padding:8px 18px;">
        در صف بسته‌بندی (<?= fa_digits((string)$countQueue) ?>)
    </a>
    <a href="/admin/fulfillment?tab=packed"
        class="st-badge <?= $filterTab === 'packed' ? 'st-converted' : '' ?>"
        style="text-decoration:none;padding:8px 18px;">
        بسته‌بندی شده (<?= fa_digits((string)$countPacked) ?>)
    </a>
</div>

<!-- ═══ فیلترها ═══ -->
<form method="get" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;align-items:flex-end;">
    <input type="hidden" name="tab" value="<?= $h($filterTab) ?>">
    <div>
        <label style="font-size:.72rem;color:#666;display:block;margin-bottom:4px;">جستجو:</label>
        <input type="text" name="q" value="<?= $h($filterQ) ?>" placeholder="شماره سفارش / نام / موبایل"
            style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:.78rem;width:220px;">
    </div>
    <div>
        <label style="font-size:.72rem;color:#666;display:block;margin-bottom:4px;">از تاریخ (شمسی):</label>
        <input type="text" name="from" value="<?= $h($filterFrom) ?>" placeholder="<?= jalali_today() ?>"
            style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:.78rem;width:130px;">
    </div>
    <div>
        <label style="font-size:.72rem;color:#666;display:block;margin-bottom:4px;">تا تاریخ (شمسی):</label>
        <input type="text" name="to" value="<?= $h($filterTo) ?>" placeholder="<?= jalali_today() ?>"
            style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:.78rem;width:130px;">
    </div>
    <button type="submit" style="padding:6px 14px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;">
        فیلتر
    </button>
    <a href="/admin/fulfillment?tab=<?= $h($filterTab) ?>"
        style="padding:6px 14px;background:#f0f0f0;border-radius:6px;font-size:.78rem;text-decoration:none;color:#333;">
        پاک کردن
    </a>
</form>

<!-- ═══ جدول ═══ -->
<div class="admin-section-head">
    <div>
        <span class="admin-kicker">سفارش‌ها</span>
        <h2><?= $filterTab === 'packed' ? 'بسته‌بندی‌شده' : 'در انتظار بسته‌بندی' ?> (<?= fa_digits((string)count($orders)) ?>)</h2>
    </div>
</div>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>سفارش</th>
                <th>مشتری</th>
                <th>اقلام</th>
                <th>مبلغ</th>
                <th>روش ارسال</th>
                <th>آدرس تحویل</th>
                <th>تاریخ</th>
                <th>وضعیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="9" style="text-align:center;padding:24px;color:#999;">موردی یافت نشد.</td></tr>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                    <?php
                    $methodLabels = ['peyk' => 'پیک', 'post' => 'پست', 'in_person' => 'حضوری'];
                    $shipMethod = $o['ship_method'] ?? $o['shipping_method'] ?? '';
                    $methodLabel = $methodLabels[$shipMethod] ?? '—';
                    $isPacked = in_array($o['status'], ['packed'], true) || $o['fulfillment_status'] === 'packed';
                    ?>
                    <tr>
                        <td>
                            <a dir="ltr" href="/admin/order-detail?id=<?= (int)$o['id'] ?>" style="color:var(--teal-700);font-weight:700;">
                                <?= $h($o['order_number']) ?>
                            </a>
                        </td>
                        <td>
                            <b><?= $h($o['customer_name'] ?? '') ?></b><br>
                            <span dir="ltr" style="font-size:.7rem;color:#999;"><?= $h($o['mobile'] ?? '') ?></span>
                        </td>
                        <td><?= fa_digits(number_format((int)$o['total_qty'])) ?> واحد</td>
                        <td>
                            <b><?= fa_digits(number_format((float)$o['total'])) ?></b>
                            <span style="font-size:.7rem;color:#999;">ت</span>
                        </td>
                        <td>
                            <span class="st-badge st-quoted"><?= $h($methodLabel) ?></span>
                        </td>
                        <td style="font-size:.72rem;max-width:200px;">
                            <?= $h($o['ship_city'] ?? '') ?> —
                            <?= $h(mb_substr((string)$o['ship_address'], 0, 40)) ?>…
                        </td>
                        <td style="font-size:.72rem;">
                            <?= to_jalali($o['created_at']) ?>
                        </td>
                        <td>
                            <?php if ($isPacked): ?>
                                <span class="st-badge st-converted">بسته‌بندی شده</span>
                            <?php else: ?>
                                <span class="st-badge st-new">در صف</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isPacked): ?>
                                <form method="post" style="display:inline;"
                                    onsubmit="return confirm('سفارش بسته‌بندی شد؟ به واحد تحویل ارجاع داده می‌شود.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="pack_order">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                    <button type="submit"
                                        style="padding:5px 12px;font-size:.72rem;background:#e8f5ee;color:#1a7f4b;border:none;border-radius:4px;cursor:pointer;">
                                        📦 بسته‌بندی
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="/admin/delivery" style="color:var(--teal-700);font-size:.72rem;">
                                    به واحد تحویل ←
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
