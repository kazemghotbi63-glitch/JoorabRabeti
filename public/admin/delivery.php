<?php

declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — واحد تحویل  |  v1 | 2026-11-08
 *
 *  نقش این صفحه: مدیریت تحویل سفارش‌های بسته‌بندی‌شده به سه کانال:
 *    ۱) پیک (peyk)        — تحویل به پیک + ثبت نام/موبایل/شرکت پیک
 *    ۲) پست (post)        — تحویل به پست + ثبت کد رهگیری + شرکت پست
 *    ۳) حضوری (in_person) — علامت‌گذاری «آماده تحویل در فروشگاه» + تأیید نهایی
 *
 *  ویژگی‌ها:
 *    - سه تب کانال با شمارش جداگانه
 *    - فیلتر تاریخ شمسی (از/تا)
 *    - فیلتر وضعیت (آماده ارسال / در مسیر / تحویل شده / شکست)
 *    - جستجوی شماره سفارش / موبایل
 *    - نمایش همه تاریخ‌ها به شمسی
 *
 *  گردش کار هر کانال:
 *    peyk:      packed → shipToCourier  → in_transit → confirmDelivery → delivered
 *    post:      packed → shipToPost     → in_transit → confirmDelivery → delivered
 *    in_person: packed → readyForPickup → (انتظار)    → confirmDelivery → delivered
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

/* ═══ POST handlers ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = $_POST['do'] ?? '';

    try {
        /* ۱) تحویل به پیک */
        if ($do === 'ship_to_peyk') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $data = [
                'peyk_name'    => trim((string)($_POST['peyk_name'] ?? '')),
                'peyk_mobile'  => trim((string)($_POST['peyk_mobile'] ?? '')),
                'peyk_company' => trim((string)($_POST['peyk_company'] ?? '')),
            ];
            if ($orderId < 1) throw new InvalidArgumentException('شناسه سفارش نامعتبر');

            $result = ShippingReturnsService::shipToCourier($db, $orderId, $data, $adminId);

            /* SMS (بعد از commit) — رویداد order_shipped_peyk */
            try { OrderSmsService::orderShippedPeyk($orderId); }
            catch (Throwable $e) { error_log('DELIVERY PEYK SMS FAILED: ' . $e->getMessage()); }

            $flash = "سفارش #{$orderId} به پیک تحویل داده شد.";
        }

        /* ۲) تحویل به پست */
        if ($do === 'ship_to_post') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $data = [
                'courier_company' => trim((string)($_POST['courier_company'] ?? '')),
                'tracking_code'    => trim((string)($_POST['tracking_code'] ?? '')),
            ];
            if ($orderId < 1) throw new InvalidArgumentException('شناسه سفارش نامعتبر');

            $result = ShippingReturnsService::shipToPost($db, $orderId, $data, $adminId);

            /* SMS — رویداد order_shipped_post */
            try { OrderSmsService::orderShippedPost($orderId); }
            catch (Throwable $e) { error_log('DELIVERY POST SMS FAILED: ' . $e->getMessage()); }

            $flash = "سفارش #{$orderId} به پست تحویل داده شد.";
        }

        /* ۳) آماده تحویل حضوری */
        if ($do === 'ready_for_pickup') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $data = [
                'store_name' => trim((string)($_POST['store_name'] ?? '')),
                'store_code' => trim((string)($_POST['store_code'] ?? '')),
            ];
            if ($orderId < 1) throw new InvalidArgumentException('شناسه سفارش نامعتبر');

            $result = ShippingReturnsService::readyForPickup($db, $orderId, $data, $adminId);

            /* SMS — رویداد order_ready_pickup */
            try { OrderSmsService::orderReadyPickup($orderId); }
            catch (Throwable $e) { error_log('DELIVERY PICKUP SMS FAILED: ' . $e->getMessage()); }

            $flash = "سفارش #{$orderId} برای تحویل حضوری آماده شد.";
        }

        /* ۴) تأیید تحویل نهایی به مشتری */
        if ($do === 'confirm_delivery') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $deliveredAt = trim((string)($_POST['delivered_at'] ?? ''));
            if ($deliveredAt === '') $deliveredAt = null;
            if ($orderId < 1) throw new InvalidArgumentException('شناسه سفارش نامعتبر');

            $result = ShippingReturnsService::confirmDelivery($db, $orderId, $deliveredAt, $adminId);

            /* SMS — رویداد order_delivered */
            try { OrderSmsService::orderDelivered($orderId); }
            catch (Throwable $e) { error_log('DELIVERY CONFIRMED SMS FAILED: ' . $e->getMessage()); }

            $flash = "تحویل سفارش #{$orderId} به مشتری تأیید شد.";
        }

        /* ۵) شکست ارسال */
        if ($do === 'fail_shipment') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $reason  = trim((string)($_POST['reason'] ?? ''));
            if ($orderId < 1) throw new InvalidArgumentException('شناسه سفارش نامعتبر');
            if (mb_strlen($reason) < 3) throw new InvalidArgumentException('دلیل شکست ارسال الزامی است');

            ShippingReturnsService::failShipment($db, $orderId, $reason, $adminId);
            $flash = "وضعیت سفارش #{$orderId} به «شکست در ارسال» به‌روزرسانی شد.";
        }
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

/* ═══ فیلترها ═══ */
$filterTab    = trim((string)($_GET['tab'] ?? 'all'));   // all | peyk | post | in_person
$filterStatus = trim((string)($_GET['status'] ?? ''));   // ready | in_transit | delivered | failed
$filterQ      = trim((string)($_GET['q'] ?? ''));
$filterFrom   = trim((string)($_GET['from'] ?? ''));     // شمسی
$filterTo     = trim((string)($_GET['to'] ?? ''));      // شمسی

$conditions = [];
$params     = [];

// فقط سفارش‌های بسته‌بندی‌شده یا در جریان ارسال یا تحویل‌شده
$conditions[] = "(o.status IN ('packed','shipped-pik','shipped-post','shipped','delivered')
               OR o.fulfillment_status IN ('packed','completed')
               OR o.shipping_status IN ('ready','in_transit','delivered','failed'))";

if ($filterTab !== 'all' && in_array($filterTab, ['peyk','post','in_person'], true)) {
    $conditions[] = "(s.method = ? OR o.shipping_method = ?)";
    array_push($params, $filterTab, $filterTab);
}

if ($filterStatus !== '') {
    $conditions[] = "o.shipping_status = ?";
    $params[] = $filterStatus;
}

if ($filterQ !== '') {
    $conditions[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ? OR s.ship_mobile LIKE ?)";
    $kw = '%' . $filterQ . '%';
    array_push($params, $kw, $kw, $kw, $kw);
}

if ($filterFrom !== '') {
    $g = jalali_to_gregorian($filterFrom);
    if ($g) {
        $conditions[] = 's.shipped_at >= ?';
        $params[] = $g . ' 00:00:00';
    }
}
if ($filterTo !== '') {
    $g = jalali_to_gregorian($filterTo);
    if ($g) {
        $conditions[] = 's.shipped_at <= ?';
        $params[] = $g . ' 23:59:59';
    }
}

$where = 'WHERE ' . implode(' AND ', $conditions);

/* ═══ لیست سفارش‌ها ═══ */
$stmt = $db->prepare("
    SELECT
        o.id, o.order_number, o.status, o.shipping_status, o.shipping_method,
        o.fulfillment_status, o.total, o.created_at,
        u.full_name AS customer_name, u.mobile AS customer_mobile,
        s.method AS ship_method, s.fee AS ship_fee, s.delivery_date, s.slot_label,
        s.tracking_code, s.carrier, s.shipped_at, s.delivered_at, s.return_reason,
        s.ship_city, s.ship_address, s.ship_mobile, s.ship_name,
        s.peyk_name, s.peyk_mobile, s.peyk_company,
        s.courier_company,
        s.store_name, s.store_code, s.ready_at
    FROM orders o
    INNER JOIN order_shipments s ON s.order_id = o.id
    LEFT JOIN users u ON u.id = o.user_id
    {$where}
    ORDER BY
        CASE o.shipping_status
            WHEN 'ready'      THEN 1
            WHEN 'in_transit' THEN 2
            WHEN 'failed'     THEN 3
            WHEN 'delivered'  THEN 4
            ELSE 9
        END,
        o.id DESC
    LIMIT 200
");
$stmt->execute($params);
$shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══ شمارنده‌های بالا (برای همهٔ کانال‌ها) ═══ */
$counts = [
    'ready'      => 0,
    'in_transit' => 0,
    'delivered'  => 0,
    'failed'     => 0,
];
$rows = $db->query("
    SELECT shipping_status, COUNT(*) AS c
    FROM orders
    WHERE shipping_status IN ('ready','in_transit','delivered','failed')
    GROUP BY shipping_status
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $counts[$r['shipping_status']] = (int)$r['c'];
}

/* شمارش هر کانال */
$tabCounts = [
    'all'       => 0,
    'peyk'      => 0,
    'post'      => 0,
    'in_person' => 0,
];
$rows = $db->query("
    SELECT COALESCE(o.shipping_method, s.method) AS m, COUNT(*) AS c
    FROM orders o
    INNER JOIN order_shipments s ON s.order_id = o.id
    WHERE o.shipping_status IN ('ready','in_transit','delivered','failed')
       OR o.status IN ('packed','shipped-pik','shipped-post','shipped','delivered')
    GROUP BY m
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $m = $r['m'] ?? 'all';
    if (isset($tabCounts[$m])) $tabCounts[$m] = (int)$r['c'];
    $tabCounts['all'] += (int)$r['c'];
}

$methodLabels = ['peyk' => 'پیک', 'post' => 'پست', 'in_person' => 'حضوری'];
$shippingStatusLabels = [
    'not_ready'    => 'آماده نیست',
    'ready'        => 'آماده ارسال',
    'packing'      => 'بسته‌بندی',
    'in_transit'   => 'در مسیر',
    'delivered'    => 'تحویل شده',
    'failed'       => 'شکست',
    'returned'     => 'برگشتی',
];

$activeMenu = 'delivery';
$pageTitle  = 'تحویل — مدیریت ارسال به مشتری';

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
        <span class="admin-kicker">واحد تحویل</span>
        <h1>مدیریت تحویل سفارش‌ها</h1>
        <p>سفارش‌های بسته‌بندی‌شده را به پیک، پست یا فروشگاه تحویل دهید.</p>
    </div>
</div>

<!-- ═══ شمارنده‌های بالا ═══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;">
    <div style="background:#fff7e6;border:1px solid #ffe0a3;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:.7rem;color:#996;">آماده ارسال</div>
        <div style="font-size:1.6rem;font-weight:800;color:#c47b00;"><?= fa_digits((string)$counts['ready']) ?></div>
    </div>
    <div style="background:#e8f0ff;border:1px solid #b3d1ff;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:.7rem;color:#446;">در مسیر</div>
        <div style="font-size:1.6rem;font-weight:800;color:#1a4fa0;"><?= fa_digits((string)$counts['in_transit']) ?></div>
    </div>
    <div style="background:#e8f5ee;border:1px solid #b3e0c2;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:.7rem;color:#475;">تحویل شده</div>
        <div style="font-size:1.6rem;font-weight:800;color:#1a7f4b;"><?= fa_digits((string)$counts['delivered']) ?></div>
    </div>
    <div style="background:#fff0f0;border:1px solid #ffcaca;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:.7rem;color:#954;">شکست</div>
        <div style="font-size:1.6rem;font-weight:800;color:#c62828;"><?= fa_digits((string)$counts['failed']) ?></div>
    </div>
</div>

<!-- ═══ تب‌های کانال ═══ -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
    <a href="/admin/delivery?tab=all"
        class="st-badge <?= $filterTab === 'all' ? 'st-quoted' : '' ?>"
        style="text-decoration:none;padding:8px 18px;">
        همه (<?= fa_digits((string)$tabCounts['all']) ?>)
    </a>
    <a href="/admin/delivery?tab=peyk"
        class="st-badge <?= $filterTab === 'peyk' ? 'st-quoted' : '' ?>"
        style="text-decoration:none;padding:8px 18px;">
        🛵 پیک (<?= fa_digits((string)$tabCounts['peyk']) ?>)
    </a>
    <a href="/admin/delivery?tab=post"
        class="st-badge <?= $filterTab === 'post' ? 'st-quoted' : '' ?>"
        style="text-decoration:none;padding:8px 18px;">
        📦 پست (<?= fa_digits((string)$tabCounts['post']) ?>)
    </a>
    <a href="/admin/delivery?tab=in_person"
        class="st-badge <?= $filterTab === 'in_person' ? 'st-quoted' : '' ?>"
        style="text-decoration:none;padding:8px 18px;">
        🏪 حضوری (<?= fa_digits((string)$tabCounts['in_person']) ?>)
    </a>
</div>

<!-- ═══ فیلترها ═══ -->
<form method="get" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;align-items:flex-end;">
    <input type="hidden" name="tab" value="<?= $h($filterTab) ?>">
    <div>
        <label style="font-size:.72rem;color:#666;display:block;margin-bottom:4px;">وضعیت ارسال:</label>
        <select name="status" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:.78rem;">
            <option value="">همه</option>
            <option value="ready" <?= $filterStatus === 'ready' ? 'selected' : '' ?>>آماده ارسال</option>
            <option value="in_transit" <?= $filterStatus === 'in_transit' ? 'selected' : '' ?>>در مسیر</option>
            <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>تحویل شده</option>
            <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>شکست</option>
        </select>
    </div>
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
    <a href="/admin/delivery?tab=<?= $h($filterTab) ?>"
        style="padding:6px 14px;background:#f0f0f0;border-radius:6px;font-size:.78rem;text-decoration:none;color:#333;">
        پاک کردن
    </a>
</form>

<!-- ═══ جدول ═══ -->
<div class="admin-section-head">
    <div>
        <span class="admin-kicker">سفارش‌ها</span>
        <h2>ارسال‌های جاری (<?= fa_digits((string)count($shipments)) ?>)</h2>
    </div>
</div>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>سفارش</th>
                <th>مشتری</th>
                <th>کانال</th>
                <th>آدرس تحویل</th>
                <th>کد ردیابی / پیک</th>
                <th>وضعیت</th>
                <th>تاریخ ارسال</th>
                <th>تاریخ تحویل</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shipments)): ?>
                <tr><td colspan="9" style="text-align:center;padding:24px;color:#999;">موردی یافت نشد.</td></tr>
            <?php else: ?>
                <?php foreach ($shipments as $s): ?>
                    <?php
                    $method   = $s['ship_method'] ?? $s['shipping_method'] ?? '';
                    $mLabel   = $methodLabels[$method] ?? '—';
                    $shipStat = $s['shipping_status'] ?? 'not_ready';
                    $sLabel   = $shippingStatusLabels[$shipStat] ?? $shipStat;
                    $isReady  = in_array($shipStat, ['ready'], true);
                    $isTransit= $shipStat === 'in_transit';
                    $isInPersonReady = ($method === 'in_person' && $isReady && empty($s['ready_at']));
                    $isInPersonWaiting = ($method === 'in_person' && !empty($s['ready_at']) && $shipStat !== 'delivered');
                    ?>
                    <tr>
                        <td>
                            <a dir="ltr" href="/admin/order-detail?id=<?= (int)$s['id'] ?>" style="color:var(--teal-700);font-weight:700;">
                                <?= $h($s['order_number']) ?>
                            </a>
                        </td>
                        <td>
                            <b><?= $h($s['customer_name'] ?? '') ?></b><br>
                            <span dir="ltr" style="font-size:.7rem;color:#999;"><?= $h($s['customer_mobile'] ?? '') ?></span>
                        </td>
                        <td>
                            <span class="st-badge st-quoted"><?= $h($mLabel) ?></span>
                            <?php if ($s['ship_city']): ?>
                                <div style="font-size:.65rem;color:#999;margin-top:2px;"><?= $h($s['ship_city']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.72rem;max-width:180px;">
                            <?= $h(mb_substr((string)$s['ship_address'], 0, 35)) ?>…
                        </td>
                        <td style="font-size:.72rem;">
                            <?php if ($method === 'peyk' && !empty($s['peyk_name'])): ?>
                                <b><?= $h($s['peyk_name']) ?></b><br>
                                <span dir="ltr" style="color:#999;"><?= $h($s['peyk_mobile']) ?></span>
                                <?php if ($s['peyk_company']): ?>
                                    <div style="font-size:.65rem;color:#999;"><?= $h($s['peyk_company']) ?></div>
                                <?php endif; ?>
                            <?php elseif ($method === 'post' && !empty($s['tracking_code'])): ?>
                                <span dir="ltr" style="font-weight:700;"><?= $h($s['tracking_code']) ?></span>
                                <?php if ($s['courier_company']): ?>
                                    <div style="font-size:.65rem;color:#999;"><?= $h($s['courier_company']) ?></div>
                                <?php endif; ?>
                            <?php elseif ($method === 'in_person' && !empty($s['store_name'])): ?>
                                🏪 <?= $h($s['store_name']) ?>
                                <?php if ($s['store_code']): ?>
                                    <div dir="ltr" style="font-size:.65rem;color:#999;">کد: <?= $h($s['store_code']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#ccc;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="st-badge
                                <?= $shipStat === 'ready' ? 'st-new' : '' ?>
                                <?= $shipStat === 'in_transit' ? 'st-converted' : '' ?>
                                <?= $shipStat === 'delivered' ? 'st-converted' : '' ?>
                                <?= $shipStat === 'failed' ? 'st-rejected' : '' ?>
                            "><?= $h($sLabel) ?></span>
                        </td>
                        <td style="font-size:.72rem;"><?= $s['shipped_at'] ? to_jalali($s['shipped_at']) : '—' ?></td>
                        <td style="font-size:.72rem;"><?= $s['delivered_at'] ? to_jalali($s['delivered_at'], true) : '—' ?></td>
                        <td>
                            <!-- ═══ عملیات بر اساس کانال و وضعیت ═══ -->

                            <?php if ($isReady && $method === 'peyk'): ?>
                                <!-- تحویل به پیک -->
                                <button type="button"
                                    onclick="document.getElementById('peyk-modal-<?= (int)$s['id'] ?>').showModal()"
                                    style="padding:5px 10px;font-size:.72rem;background:#e8f5ee;color:#1a7f4b;border:none;border-radius:4px;cursor:pointer;">
                                    🛵 تحویل به پیک
                                </button>
                                <dialog id="peyk-modal-<?= (int)$s['id'] ?>" style="max-width:480px;border-radius:10px;border:1px solid #ccc;padding:20px;">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="ship_to_peyk">
                                        <input type="hidden" name="order_id" value="<?= (int)$s['id'] ?>">
                                        <h3 style="margin:0 0 12px;">تحویل به پیک: <?= $h($s['order_number']) ?></h3>

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">نام پیک:</label>
                                        <input type="text" name="peyk_name" required
                                            placeholder="نام و نام خانوادگی پیک"
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:10px;">

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">شماره موبایل پیک:</label>
                                        <input type="text" name="peyk_mobile" dir="ltr" required
                                            placeholder="09xxxxxxxxx"
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:10px;">

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">شرکت/سرویس پیک (اختیاری):</label>
                                        <input type="text" name="peyk_company"
                                            placeholder="اسنپ، تپسی، آژانس شخصی، ..."
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:14px;">

                                        <div style="text-align:left;">
                                            <button type="button" onclick="document.getElementById('peyk-modal-<?= (int)$s['id'] ?>').close()"
                                                style="padding:6px 12px;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;">انصراف</button>
                                            <button type="submit"
                                                style="padding:6px 14px;background:var(--teal-700);color:#fff;border:none;border-radius:4px;cursor:pointer;">
                                                ثبت تحویل
                                            </button>
                                        </div>
                                    </form>
                                </dialog>

                            <?php elseif ($isReady && $method === 'post'): ?>
                                <!-- تحویل به پست -->
                                <button type="button"
                                    onclick="document.getElementById('post-modal-<?= (int)$s['id'] ?>').showModal()"
                                    style="padding:5px 10px;font-size:.72rem;background:#e8f0ff;color:#1a4fa0;border:none;border-radius:4px;cursor:pointer;">
                                    📦 تحویل به پست
                                </button>
                                <dialog id="post-modal-<?= (int)$s['id'] ?>" style="max-width:480px;border-radius:10px;border:1px solid #ccc;padding:20px;">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="ship_to_post">
                                        <input type="hidden" name="order_id" value="<?= (int)$s['id'] ?>">
                                        <h3 style="margin:0 0 12px;">تحویل به پست: <?= $h($s['order_number']) ?></h3>

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">شرکت پست:</label>
                                        <select name="courier_company"
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:10px;">
                                            <option value="پست ایران">پست ایران</option>
                                            <option value="تیپاکس">تیپاکس</option>
                                            <option value="چاپار">چاپار</option>
                                            <option value="پست پیشتاز">پست پیشتاز</option>
                                            <option value="سایر">سایر</option>
                                        </select>

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">کد رهگیری:</label>
                                        <input type="text" name="tracking_code" dir="ltr" required
                                            placeholder="کد رهگیری پستی"
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:14px;">

                                        <div style="text-align:left;">
                                            <button type="button" onclick="document.getElementById('post-modal-<?= (int)$s['id'] ?>').close()"
                                                style="padding:6px 12px;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;">انصراف</button>
                                            <button type="submit"
                                                style="padding:6px 14px;background:#1a4fa0;color:#fff;border:none;border-radius:4px;cursor:pointer;">
                                                ثبت تحویل
                                            </button>
                                        </div>
                                    </form>
                                </dialog>

                            <?php elseif ($isInPersonReady): ?>
                                <!-- آماده تحویل حضوری -->
                                <button type="button"
                                    onclick="document.getElementById('pickup-modal-<?= (int)$s['id'] ?>').showModal()"
                                    style="padding:5px 10px;font-size:.72rem;background:#fff0f0;color:#c62828;border:none;border-radius:4px;cursor:pointer;">
                                    🏪 آماده تحویل حضوری
                                </button>
                                <dialog id="pickup-modal-<?= (int)$s['id'] ?>" style="max-width:480px;border-radius:10px;border:1px solid #ccc;padding:20px;">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="ready_for_pickup">
                                        <input type="hidden" name="order_id" value="<?= (int)$s['id'] ?>">
                                        <h3 style="margin:0 0 12px;">آماده تحویل حضوری: <?= $h($s['order_number']) ?></h3>

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">نام فروشگاه مقصد:</label>
                                        <input type="text" name="store_name" required
                                            placeholder="مثلاً: فروشگاه مرکزی تهران"
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:10px;">

                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">کد تحویل (اختیاری):</label>
                                        <input type="text" name="store_code" dir="ltr"
                                            placeholder="کد رهگیری داخلی"
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:14px;">

                                        <div style="text-align:left;">
                                            <button type="button" onclick="document.getElementById('pickup-modal-<?= (int)$s['id'] ?>').close()"
                                                style="padding:6px 12px;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;">انصراف</button>
                                            <button type="submit"
                                                style="padding:6px 14px;background:#c62828;color:#fff;border:none;border-radius:4px;cursor:pointer;">
                                                ثبت آمادگی
                                            </button>
                                        </div>
                                    </form>
                                </dialog>

                            <?php elseif ($isTransit || $isInPersonWaiting): ?>
                                <!-- تأیید تحویل نهایی + شکست -->
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="confirm_delivery">
                                    <input type="hidden" name="order_id" value="<?= (int)$s['id'] ?>">
                                    <button type="submit"
                                        style="padding:5px 10px;font-size:.72rem;background:#e8f5ee;color:#1a7f4b;border:none;border-radius:4px;cursor:pointer;"
                                        onclick="return confirm('تحویل به مشتری تأیید شود؟');">
                                        ✓ تأیید تحویل
                                    </button>
                                </form>

                                <button type="button"
                                    onclick="document.getElementById('fail-modal-<?= (int)$s['id'] ?>').showModal()"
                                    style="padding:5px 10px;font-size:.72rem;background:#fff0f0;color:#c62828;border:none;border-radius:4px;cursor:pointer;">
                                    ✗ شکست
                                </button>
                                <dialog id="fail-modal-<?= (int)$s['id'] ?>" style="max-width:480px;border-radius:10px;border:1px solid #ccc;padding:20px;">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="fail_shipment">
                                        <input type="hidden" name="order_id" value="<?= (int)$s['id'] ?>">
                                        <h3 style="margin:0 0 12px;">شکست ارسال: <?= $h($s['order_number']) ?></h3>
                                        <label style="font-size:.78rem;display:block;margin-bottom:4px;">دلیل (الزامی):</label>
                                        <textarea name="reason" required rows="3"
                                            placeholder="مثلاً: گم‌شدگی محموله، رد توسط مشتری، ..."
                                            style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:14px;"></textarea>
                                        <div style="text-align:left;">
                                            <button type="button" onclick="document.getElementById('fail-modal-<?= (int)$s['id'] ?>').close()"
                                                style="padding:6px 12px;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;">انصراف</button>
                                            <button type="submit" style="padding:6px 14px;background:#c62828;color:#fff;border:none;border-radius:4px;cursor:pointer;">ثبت</button>
                                        </div>
                                    </form>
                                </dialog>

                            <?php else: ?>
                                <a href="/admin/order-detail?id=<?= (int)$s['id'] ?>" style="color:var(--teal-700);font-size:.72rem;">
                                    جزئیات ←
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
