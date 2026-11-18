<?php

declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — گزارش ارسال و مرجوعی (Reporting)
 *  این فایل فقط برای مشاهده تاریخچه ارسال‌ها و مدیریت مرجوعی‌هاست.
 *  عملیات اصلی (ثبت ارسال) در admin/shipping.php انجام می‌شود.
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

require_admin();

$db = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ─── پردازش POST (فقط برای مرجوعی‌ها) ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = $_POST['do'] ?? '';

    try {
        // دریافت کالای برگشتی
        if ($do === 'return_received') {
            $refundId = (int)($_POST['refund_id'] ?? 0);
            $qty = (int)($_POST['qty'] ?? 0);
            $sellable = ($_POST['sellable'] ?? '') === '1';

            if ($refundId < 1 || $qty < 1) throw new InvalidArgumentException('اطلاعات نامعتبر');

            $db->prepare("UPDATE refund_requests SET return_status = ?, return_qty = ?, return_received_at = NOW() WHERE id = ?")
                ->execute([$sellable ? 'received_sellable' : 'received_unsellable', $qty, $refundId]);

            $_SESSION['flash_success'] = 'کالای برگشتی ثبت شد.';
        }

        // رد درخواست بازگشت
        if ($do === 'reject_return') {
            $refundId = (int)($_POST['refund_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($refundId < 1 || mb_strlen($reason) < 3) throw new InvalidArgumentException('دلیل الزامی است');

            $db->prepare("UPDATE refund_requests SET return_status = 'rejected', admin_note = ? WHERE id = ?")
                ->execute([$reason, $refundId]);

            $_SESSION['flash_success'] = 'درخواست بازگشت رد شد.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: /admin/shipments');
    exit;
}

/* ─── فیلترها ─── */
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));

$conditions = ["o.status IN ('shipped-pik', 'shipped-post', 'shipped-tipax', 'shipped-barbari', 'delivered', 'shipped')"];
$params = [];

if ($filterStatus !== '') {
    $conditions[] = "o.status = ?";
    $params[] = $filterStatus;
}
if ($filterFrom !== '') {
    $conditions[] = "o.created_at >= ?";
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $conditions[] = "o.created_at <= ?";
    $params[] = $filterTo . ' 23:59:59';
}

$where = 'WHERE ' . implode(' AND ', $conditions);

/* ─── لیست ارسال‌ها (گزارش) ─── */
$shipments = $db->prepare("
    SELECT o.id, o.order_number, o.status, o.shipping_method, o.total, o.created_at,
           u.full_name AS customer_name, u.mobile AS customer_mobile
    FROM orders o
    JOIN users u ON u.id = o.user_id
    {$where}
    ORDER BY o.id DESC
    LIMIT 200
");
$shipments->execute($params);
$shipments = $shipments->fetchAll(PDO::FETCH_ASSOC);

/* ─── لیست درخواست‌های بازگشت (اگر جدول وجود دارد) ─── */
$returns = [];
try {
    $stmt = $db->prepare("
        SELECT r.id, r.invoice_id, r.user_id, r.amount, r.status AS refund_status,
               r.admin_note, r.created_at, r.return_status, r.return_received_at, r.return_qty,
               u.full_name AS customer_name, u.mobile AS customer_mobile,
               i.invoice_number, o.order_number, o.id AS order_id
        FROM refund_requests r
        JOIN users u ON u.id = r.user_id
        JOIN invoices i ON i.id = r.invoice_id
        LEFT JOIN orders o ON o.id = i.order_id
        ORDER BY r.id DESC LIMIT 100
    ");
    $stmt->execute();
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // اگر جدول refund_requests وجود نداشت، خطا نده
    $returns = [];
}

$activeMenu = 'shipments';
$pageTitle = 'گزارش ارسال و مرجوعی';
ob_start();
?>

<style>
    .report-filters {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 24px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
    }

    .report-filters label {
        font-size: 0.75rem;
        color: #64748b;
        display: block;
        margin-bottom: 4px;
    }

    .report-filters select,
    .report-filters input {
        padding: 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.85rem;
        font-family: inherit;
    }

    .report-filters button {
        padding: 8px 20px;
        background: #0f172a;
        color: #fff;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-family: inherit;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    }

    .report-table th {
        background: #f8fafc;
        padding: 14px 16px;
        text-align: right;
        font-size: 0.8rem;
        color: #64748b;
        border-bottom: 1px solid #e2e8f0;
    }

    .report-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.85rem;
    }

    .report-table tr:hover {
        background: #f8fafc;
    }

    .badge-status {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .badge-shipped {
        background: #dcfce7;
        color: #16a34a;
    }

    .badge-delivered {
        background: #dbeafe;
        color: #2563eb;
    }

    .badge-pending {
        background: #fef3c7;
        color: #d97706;
    }

    .badge-rejected {
        background: #fee2e2;
        color: #ef4444;
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

<div class="admin-page-intro" style="margin-bottom: 20px;">
    <h1 style="margin:0; font-size:1.4rem;">گزارش ارسال و مرجوعی</h1>
    <p style="margin:4px 0 0; color:#64748b; font-size:0.9rem;">تاریخچه ارسال‌ها و مدیریت درخواست‌های بازگشت کالا.</p>
</div>

<!-- فیلترها -->
<form method="get" class="report-filters">
    <div>
        <label>وضعیت:</label>
        <select name="status">
            <option value="">همه</option>
            <option value="shipped-pik" <?= $filterStatus === 'shipped-pik' ? 'selected' : '' ?>>تحویل پیک</option>
            <option value="shipped-post" <?= $filterStatus === 'shipped-post' ? 'selected' : '' ?>>تحویل پست</option>
            <option value="shipped-tipax" <?= $filterStatus === 'shipped-tipax' ? 'selected' : '' ?>>تحویل تیپاکس</option>
            <option value="shipped-barbari" <?= $filterStatus === 'shipped-barbari' ? 'selected' : '' ?>>تحویل باربری</option>
            <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>تحویل شده</option>
        </select>
    </div>
    <div>
        <label>از تاریخ:</label>
        <input type="date" name="from" value="<?= $h($filterFrom) ?>">
    </div>
    <div>
        <label>تا تاریخ:</label>
        <input type="date" name="to" value="<?= $h($filterTo) ?>">
    </div>
    <div style="display:flex; align-items:flex-end;">
        <button type="submit">فیلتر</button>
    </div>
</form>

<!-- جدول گزارش ارسال‌ها -->
<h2 style="font-size:1.1rem; margin:24px 0 12px;">تاریخچه ارسال‌ها (<?= count($shipments) ?>)</h2>
<div style="overflow-x:auto;">
    <table class="report-table">
        <thead>
            <tr>
                <th>سفارش</th>
                <th>مشتری</th>
                <th>روش ارسال</th>
                <th>مبلغ</th>
                <th>وضعیت</th>
                <th>تاریخ ثبت</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shipments)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding:40px; color:#94a3b8;">هیچ ارسالی یافت نشد.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($shipments as $s):
                    $statusLabel = [
                        'shipped-pik' => 'تحویل پیک',
                        'shipped-post' => 'تحویل پست',
                        'shipped-tipax' => 'تحویل تیپاکس',
                        'shipped-barbari' => 'تحویل باربری',
                        'delivered' => 'تحویل شده',
                        'shipped' => 'ارسال شده'
                    ][$s['status']] ?? $s['status'];
                    $badgeClass = in_array($s['status'], ['delivered']) ? 'badge-delivered' : 'badge-shipped';
                ?>
                    <tr>
                        <td><a href="/admin/order-detail?id=<?= (int)$s['id'] ?>" style="color:#0f172a; font-weight:600;"><?= $h($s['order_number']) ?></a></td>
                        <td><?= $h($s['customer_name']) ?><br><span dir="ltr" style="font-size:0.7rem; color:#94a3b8;"><?= $h($s['customer_mobile']) ?></span></td>
                        <td style="text-transform:uppercase;"><?= $h($s['shipping_method'] ?? '—') ?></td>
                        <td><?= number_format((float)$s['total']) ?> ت</td>
                        <td><span class="badge-status <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
                        <td style="font-size:0.75rem; color:#64748b;"><?= $h($s['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- جدول مرجوعی‌ها -->
<?php if (!empty($returns)): ?>
    <h2 style="font-size:1.1rem; margin:32px 0 12px;">درخواست‌های بازگشت کالا (<?= count($returns) ?>)</h2>
    <div style="overflow-x:auto;">
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>مشتری</th>
                    <th>سفارش</th>
                    <th>مبلغ</th>
                    <th>وضعیت بازگشت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($returns as $r):
                    $returnLabel = [
                        'not_requested' => 'درخواست نشده',
                        'requested' => 'درخواست شده',
                        'in_transit' => 'در راه',
                        'received_sellable' => 'دریافت (سالم)',
                        'received_unsellable' => 'دریافت (معیوب)',
                        'rejected' => 'رد شده'
                    ][$r['return_status'] ?? 'not_requested'] ?? '—';
                ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td><?= $h($r['customer_name']) ?></td>
                        <td><?= $h($r['order_number'] ?? '—') ?></td>
                        <td><?= number_format((int)$r['amount']) ?> ت</td>
                        <td><span class="badge-status badge-pending"><?= $returnLabel ?></span></td>
                        <td>
                            <?php if (($r['return_status'] ?? '') === 'requested' || ($r['return_status'] ?? '') === 'in_transit'): ?>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="return_received">
                                    <input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>">
                                    <input type="number" name="qty" placeholder="تعداد" min="1" required style="width:60px; padding:4px; border:1px solid #cbd5e1; border-radius:4px;">
                                    <select name="sellable" style="padding:4px; border:1px solid #cbd5e1; border-radius:4px;">
                                        <option value="1">سالم</option>
                                        <option value="0">معیوب</option>
                                    </select>
                                    <button type="submit" style="padding:4px 10px; background:#10b981; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:0.75rem;">دریافت</button>
                                </form>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:0.75rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
