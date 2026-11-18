<?php
/* Auto-detect bootstrap (local + host) */
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
require_once ROOT_PATH . '/app/services/OrderService.php';

require_admin();

$db       = db();
$adminId  = (int)$_SESSION['admin_id'];
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$id       = (int)($_GET['id'] ?? 0);
$flash    = '';
$flashErr = '';

/* ─── POST handlers ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = $_POST['do'] ?? '';

    try {
        /* ۱) تغییر وضعیت کلی سفارش (status) */
        if ($do === 'change_status' && $id > 0) {
            $newStatus = (string)($_POST['new_status'] ?? '');
            $allowed = ['pending_review', 'processing', 'packed', 'shipped-pik', 'shipped-post', 'shipped', 'delivered', 'cancelled'];
            if (!in_array($newStatus, $allowed, true)) {
                throw new InvalidArgumentException('وضعیت نامعتبر است');
            }

            $db->beginTransaction();

            // سفارش فعلی
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('سفارش یافت نشد');

            $oldStatus = $order['status'];
            if ($oldStatus === $newStatus) {
                $flash = 'وضعیت سفارش از قبل "' . $newStatus . '" است.';
            } else {
                OrderService::assertStatusTransition($oldStatus, $newStatus);

                $cancelReason = trim((string)($_POST['cancellation_reason'] ?? ''));
                $cancelledAt  = $newStatus === 'cancelled' ? date('Y-m-d H:i:s') : null;

                $db->prepare("
                    UPDATE orders
                    SET status = ?,
                        cancelled_at = COALESCE(?, cancelled_at),
                        cancellation_reason = COALESCE(?, cancellation_reason),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$newStatus, $cancelledAt, $cancelReason !== '' ? $cancelReason : null, $id]);

                // همگام‌سازی فیلدهای جدید با status
                $paymentStatus = match ($newStatus) {
                    'cancelled'                                  => 'void',
                    'delivered', 'shipped', 'shipped-pik', 'shipped-post' => 'paid',
                    default                                      => $order['payment_status'] ?? 'unpaid',
                };
                $fulfillmentStatus = match ($newStatus) {
                    'cancelled' => 'cancelled',
                    'delivered', 'shipped', 'shipped-pik', 'shipped-post' => 'completed',
                    'packed'    => 'packed',
                    'processing' => 'processing',
                    default     => $order['fulfillment_status'] ?? 'pending',
                };
                $shippingStatus = match ($newStatus) {
                    'cancelled'    => 'not_applicable',
                    'delivered'    => 'delivered',
                    'shipped', 'shipped-pik', 'shipped-post' => 'in_transit',
                    'packed'       => 'ready',
                    default       => $order['shipping_status'] ?? 'not_ready',
                };
                $shippingMethod = $newStatus === 'shipped-pik' ? 'peyk'
                    : ($newStatus === 'shipped-post' ? 'post' : null);

                $db->prepare("
                    UPDATE orders
                    SET payment_status = ?, fulfillment_status = ?, shipping_status = ?,
                        shipping_method = COALESCE(?, shipping_method)
                    WHERE id = ?
                ")->execute([$paymentStatus, $fulfillmentStatus, $shippingStatus, $shippingMethod, $id]);

                // لغو → آزادسازی رزرو همهٔ اقلام
                if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
                    $itemsToRelease = $db->prepare("
                        SELECT id, product_id, qty,
                               GREATEST(qty_reserved - COALESCE(qty_delivered, 0), 0) AS qty_open
                        FROM order_items
                        WHERE order_id = ? AND qty_reserved > COALESCE(qty_delivered, 0)
                    ");
                    $itemsToRelease->execute([$id]);
                    foreach ($itemsToRelease as $it) {
                        OrderService::releaseStock(
                            $db,
                            (int)$it['product_id'],
                            (int)$it['qty_open'],
                            $id,
                            (int)$it['id'],
                            $adminId
                        );
                        $db->prepare("
                            UPDATE order_items
                            SET qty_cancelled = qty_cancelled + ?, qty_reserved = qty_reserved - ?
                            WHERE id = ?
                        ")->execute([(int)$it['qty_open'], (int)$it['qty_open'], (int)$it['id']]);
                    }
                }
                // بازگشتی از cancelled → pending_review → رزرو دوباره (در صورت موجودی کافی)
                if ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
                    $itemsToReserve = $db->prepare("
                        SELECT oi.id, oi.product_id, oi.qty_cancelled AS qty, p.name AS product_name
                        FROM order_items oi
                        LEFT JOIN products p ON p.id = oi.product_id
                        WHERE oi.order_id = ? AND oi.qty_cancelled > 0
                    ");
                    $itemsToReserve->execute([$id]);
                    foreach ($itemsToReserve as $it) {
                        try {
                            OrderService::reserveStock(
                                $db,
                                (int)$it['product_id'],
                                (int)$it['qty'],
                                $id,
                                (int)$it['id'],
                                $adminId
                            );
                            $db->prepare("
                                UPDATE order_items
                                SET qty_reserved = qty_reserved + ?, qty_cancelled = 0
                                WHERE id = ?
                            ")->execute([(int)$it['qty'], (int)$it['id']]);
                        } catch (RuntimeException $e) {
                            $flashErr .= "ریزرو دوبارهٔ «{$it['product_name']}» شکست خورد: " . $e->getMessage() . " ";
                        }
                    }
                }

                // ثبت audit
                $db->prepare("
                    INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                    VALUES (?, 'order.status.change', 'order', ?, ?)
                ")->execute([$adminId, $id, $ip]);

                $flash = 'وضعیت سفارش از «' . $oldStatus . '» به «' . $newStatus . '» تغییر کرد.';
            }

            $db->commit();
        }

        /* ۲) افزودن یادداشت داخلی به سفارش (customer_note فقط خواندنی است؛ یادداشت در audit_logs) */
        if ($do === 'add_note' && $id > 0) {
            $note = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($note) < 3) {
                throw new InvalidArgumentException('یادداشت باید حداقل ۳ کاراکتر داشته باشد');
            }
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                VALUES (?, CONCAT('order.note: ', ?), 'order', ?, ?)
            ")->execute([$adminId, mb_substr($note, 0, 200), $id, $ip]);
            $flash = 'یادداشت اضافه شد.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $flashErr = $e->getMessage();
    }
}

/* ─── Load order + items + invoice + payment_submissions ─── */
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

// اقلام سفارش با اطلاعات موجودی فعلی هر محصول
$items = $db->prepare("
    SELECT oi.*,
           p.is_active,
           (SELECT spec_value FROM product_specs 
            WHERE product_id = oi.product_id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
           COALESCE(inv.qty_physical, 0) AS stock_physical,
           COALESCE(inv.reserved, 0)     AS stock_reserved,
           COALESCE(inv.unsellable, 0)    AS stock_unsellable
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    LEFT JOIN inventory inv ON inv.product_id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$items->execute([$id]);
$items = $items->fetchAll();

// فاکتور
$invoice = null;
try {
    $stmt = $db->prepare("SELECT * FROM invoices WHERE order_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
} catch (Throwable $e) {
}

// پرداخت‌های ثبت‌شده برای این سفارش
$payments = [];
if ($invoice) {
    try {
        $stmt = $db->prepare("
            SELECT ps.*,
                   (SELECT verified_amount FROM payment_verifications pv
                    WHERE pv.payment_submission_id = ps.id
                    ORDER BY pv.verified_at DESC LIMIT 1) AS verified_amount,
                   (SELECT status FROM payment_verifications pv
                    WHERE pv.payment_submission_id = ps.id
                    ORDER BY pv.verified_at DESC LIMIT 1) AS verification_status
            FROM payment_submissions ps
            WHERE ps.invoice_id = ?
            ORDER BY ps.created_at ASC
        ");
        $stmt->execute([$invoice['id']]);
        $payments = $stmt->fetchAll();
    } catch (Throwable $e) {
    }
}

// رویدادهای audit برای این سفارش
$audit = [];
try {
    $stmt = $db->prepare("
        SELECT al.*, u.full_name AS admin_name
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.entity = 'order' AND al.entity_id = ?
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$id]);
    $audit = $stmt->fetchAll();
} catch (Throwable $e) {
}

/* ─── options for status dropdown ─── */
$allStatuses = ['pending_review', 'processing', 'packed', 'shipped-pik', 'shipped-post', 'shipped', 'delivered', 'cancelled'];
$statusLabels = [
    'pending_review' => 'در انتظار بررسی',
    'processing'     => 'در حال تأمین',
    'packed'         => 'بسته‌بندی‌شده',
    'shipped-pik'    => 'ارسال با پیک',
    'shipped-post'   => 'ارسال با پست',
    'shipped'        => 'ارسال‌شده',
    'delivered'      => 'تحویل داده‌شده',
    'cancelled'      => 'لغو شده',
];

$activeMenu = 'orders';
$pageTitle  = 'سفارش ' . $order['order_number'];

ob_start();
?>

<a href="/admin/orders" style="color:var(--teal-700);font-size:.82rem;">→ بازگشت به سفارش‌ها</a>

<?php if ($flash): ?>
    <div class="alert alert-success" style="background:#e8f5ee;color:#1a7f4b;padding:12px 16px;border-radius:8px;margin:14px 0;">
        ✓ <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-error" style="background:#fff0f0;color:#c62828;padding:12px 16px;border-radius:8px;margin:14px 0;">
        ✗ <?= htmlspecialchars($flashErr) ?>
    </div>
<?php endif; ?>

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
        <div class="num" style="font-size:1rem;"><?= $statusLabels[$order['status']] ?? $order['status'] ?></div>
        <div class="lbl">وضعیت کلی</div>
    </div>
    <div class="stat-card">
        <div class="num" style="font-size:.82rem;">
            <span class="st-badge st-new"><?= htmlspecialchars($order['payment_status'] ?? 'unpaid') ?></span>
        </div>
        <div class="lbl">پرداخت</div>
    </div>
    <div class="stat-card">
        <div class="num" style="font-size:.82rem;">
            <span class="st-badge st-quoted"><?= htmlspecialchars($order['fulfillment_status'] ?? 'pending') ?></span>
        </div>
        <div class="lbl">تأمین</div>
    </div>
    <div class="stat-card">
        <div class="num" style="font-size:.82rem;">
            <span class="st-badge st-converted"><?= htmlspecialchars($order['shipping_status'] ?? 'not_ready') ?></span>
        </div>
        <div class="lbl">ارسال</div>
    </div>
</div>

<!-- کارت تغییر وضعیت سفارش -->
<div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:16px;padding:20px;margin-bottom:20px;">
    <h3 style="font-size:.95rem;margin:0 0 12px;">تغییر وضعیت سفارش</h3>

    <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="change_status">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div>
            <label style="font-size:.72rem;color:#666;display:block;margin-bottom:4px;">وضعیت جدید:</label>
            <select name="new_status" required
                style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;min-width:200px;">
                <?php foreach ($allStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>>
                        <?= $statusLabels[$s] ?? $s ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1;min-width:200px;">
            <label style="font-size:.72rem;color:#666;display:block;margin-bottom:4px;">دلیل لغو (در صورت لغو):</label>
            <input type="text" name="cancellation_reason" maxlength="500"
                placeholder="اگر لغو می‌کنید، دلیل را ذکر کنید"
                style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;width:100%;font-size:.82rem;">
        </div>

        <button type="submit"
            style="padding:8px 18px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;font-weight:700;"
            onclick="return confirm('وضعیت سفارش تغییر کند؟');">
            اعمال
        </button>
    </form>

    <p style="font-size:.7rem;color:#999;margin-top:8px;">
        گراف انتقال مجاز طبق قواعد کسب‌وکار اعمال می‌شود. لغو سفارش به‌صورت خودکار رزرو موجودی را آزاد می‌کند.
    </p>
</div>

<div class="admin-table-wrap" style="margin-bottom:20px;">
    <table class="admin-table">
        <thead>
            <tr>
                <th>کد</th>
                <th>محصول</th>
                <th>تعداد سفارش</th>
                <th>رزرو شده</th>
                <th>نیازمند تولید</th>
                <th>ارسال‌شده</th>
                <th>قیمت واحد (تثبیت‌شده)</th>
                <th>جمع</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><b><?= htmlspecialchars($it['product_code']) ?></b></td>
                    <td>
                        <?= htmlspecialchars($it['product_name']) ?>
                        <?php if (!empty($it['product_id']) && empty($it['is_active'])): ?>
                            <span class="st-badge st-rejected" style="margin-right:6px;font-size:.65rem;">غیرفعال</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((int)$it['qty']) ?> <?= htmlspecialchars(unit_label($it['unit_code'] ?? 'جفتی')) ?></td>
                    <td>
                        <?php
                        $reserved = (int)($it['qty_reserved'] ?? 0);
                        $toProduce = (int)($it['qty_to_produce'] ?? 0);
                        $delivered = (int)($it['qty_delivered'] ?? 0);
                        $cancelled = (int)($it['qty_cancelled'] ?? 0);
                        ?>
                        <?= $reserved ?> <?= htmlspecialchars(unit_label($it['unit_code'] ?? 'جفتی')) ?> <?php if ($toProduce > 0): ?>
                            <br><span style="color:#a06010;font-size:.7rem;">تأمین از تولید: <?= $toProduce ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($toProduce > 0): ?>
                            <span style="color:#c62828;font-weight:700;"><?= $toProduce ?> <?= htmlspecialchars(unit_label($it['unit_code'] ?? 'جفتی')) ?></span>
                        <?php else: ?>
                            <span style="color:#999;">۰</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $delivered ?> <?= htmlspecialchars(unit_label($it['unit_code'] ?? 'جفتی')) ?>
                         <?php if ($cancelled > 0): ?>
                            <br><span style="color:#c62828;font-size:.7rem;">لغو: <?= $cancelled ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((int)$it['unit_price']) ?></td>
                    <td><b><?= number_format((int)$it['line_total']) ?></b> ت</td>
                </tr>

                <?php
                // نشانی خطری کمبود موجودی: اگر qty_reserved < qty - qty_cancelled → موجودی کافی نبود
                $expectedReserved = (int)$it['qty'] - $cancelled;
                if ($reserved < $expectedReserved):
                ?>
                    <tr style="background:#fff4e0;">
                        <td colspan="8" style="font-size:.75rem;color:#a06010;padding:8px 12px;">
                            ⚠️ این قلم به‌طور کامل رزرو نشده است. موجودی فعلی انبار:
                            فیزیکی=<?= (int)$it['stock_physical'] ?> · رزروشده=<?= (int)$it['stock_reserved'] ?> · غیرقابل فروش=<?= (int)$it['stock_unsellable'] ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- کارت اطلاعات مشتری و ارسال -->
<div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:16px;padding:20px;margin-bottom:20px;">
    <h3 style="font-size:.95rem;margin:0 0 12px;">اطلاعات مشتری و ارسال</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:.82rem;">
        <div><b>مشتری:</b> <?= htmlspecialchars($order['full_name']) ?></div>
        <div><b>موبایل:</b> <span dir="ltr"><?= htmlspecialchars($order['mobile']) ?></span></div>
        <?php if ($order['company_name']): ?>
            <div><b>شرکت:</b> <?= htmlspecialchars($order['company_name']) ?></div>
        <?php endif; ?>
        <div><b>گیرنده:</b> <?= htmlspecialchars($order['shipping_name']) ?></div>
        <div><b>موبایل گیرنده:</b> <span dir="ltr"><?= htmlspecialchars($order['shipping_mobile']) ?></span></div>
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

<!-- کارت فاکتور و پرداخت‌ها -->
<?php if ($invoice): ?>
    <div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:16px;padding:20px;margin-bottom:20px;">
        <h3 style="font-size:.95rem;margin:0 0 12px;">فاکتور و پرداخت‌ها</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;font-size:.82rem;margin-bottom:16px;">
            <div><b>شماره فاکتور:</b> <span dir="ltr"><?= htmlspecialchars($invoice['invoice_number']) ?></span></div>
            <div><b>مبلغ فاکتور:</b> <?= number_format((int)$invoice['total']) ?> ت</div>
            <div><b>پرداخت‌شده:</b> <?= number_format((int)$invoice['paid_total']) ?> ت</div>
            <div><b>مانده:</b> <?= number_format((int)$invoice['total'] - (int)$invoice['paid_total']) ?> ت</div>
            <div><b>وضعیت فاکتور:</b>
                <span class="st-badge <?= $invoice['status'] === 'paid' ? 'st-converted' : ($invoice['status'] === 'unpaid' ? 'st-rejected' : 'st-quoted') ?>">
                    <?= htmlspecialchars($invoice['status']) ?>
                </span>
            </div>
            <div>
                <a href="/admin/payments?invoice_id=<?= (int)$invoice['id'] ?>"
                    style="color:var(--teal-700);font-size:.82rem;">
                    → مدیریت پرداخت‌ها
                </a>
            </div>
            <div>
                <a href="/invoice-pdf.php?order=<?= (int)$order['id'] ?>" target="_blank"
                    style="color:var(--teal-700);font-size:.82rem;">
                    📄 دانلود فاکتور PDF
                </a>
            </div>
        </div>

        <?php if ($payments): ?>
            <table class="admin-table" style="font-size:.78rem;">
                <thead>
                    <tr>
                        <th>روش</th>
                        <th>مبلغ ادعا</th>
                        <th>مبلغ تأییدشده</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>شماره مرجع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['method']) ?></td>
                            <td><?= number_format((int)$p['claimed_amount']) ?></td>
                            <td>
                                <?php if ($p['verified_amount'] !== null): ?>
                                    <?= number_format((int)$p['verified_amount']) ?>
                                <?php else: ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="st-badge <?= $p['status'] === 'verified' ? 'st-converted' : ($p['status'] === 'rejected' ? 'st-rejected' : 'st-new') ?>">
                                    <?= htmlspecialchars($p['status']) ?>
                                </span>
                            </td>
                            <td class="td-muted td-nowrap"><?= jdate_fa($p['created_at'], true) ?></td>
                            <td dir="ltr"><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="font-size:.82rem;color:#999;">هنوز پرداختی برای این سفارش ثبت نشده است.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- کارت افزودن یادداشت داخلی -->
<div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:16px;padding:20px;margin-bottom:20px;">
    <h3 style="font-size:.95rem;margin:0 0 12px;">افزودن یادداشت داخلی</h3>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="add_note">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <textarea name="note" rows="2" required
            placeholder="یادداشت (فقط برای ادمین‌ها در تاریخچه رویدادها ذخیره می‌شود)"
            style="flex:1;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;"></textarea>
        <button type="submit"
            style="padding:8px 18px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;">
            افزودن
        </button>
    </form>
</div>

<!-- کارت تاریخچه رویدادها -->
<div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:16px;padding:20px;">
    <h3 style="font-size:.95rem;margin:0 0 12px;">تاریخچه رویدادها</h3>
    <?php if ($audit): ?>
        <table class="admin-table" style="font-size:.78rem;">
            <thead>
                <tr>
                    <th>زمان</th>
                    <th>کاربر</th>
                    <th>عملیات</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit as $a): ?>
                    <tr>
                        <td class="td-muted td-nowrap"><?= jdate_fa($a['created_at'], true) ?></td>
                        <td><?= htmlspecialchars($a['admin_name'] ?? 'سیستم') ?></td>
                        <td dir="ltr" style="text-align:right;"><?= htmlspecialchars($a['action']) ?></td>
                        <td dir="ltr" style="text-align:right;"><?= htmlspecialchars($a['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="font-size:.82rem;color:#999;">هیچ رویدادی برای این سفارش ثبت نشده است.</p>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
