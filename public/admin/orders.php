<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/OrderSmsService.php';
require_once ROOT_PATH . '/app/services/SmsService.php';
require_once ROOT_PATH . '/config/settings.php';

require_admin();

$db = db();

/*
|--------------------------------------------------------------------------
| وضعیت‌های مجاز سفارش
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    'pending_review',
    'processing',
    'packed',
    'shipped-pik',
    'shipped-post',
    'shipped',
    'delivered',
    'cancelled',
];

$statusLabels = [
    'pending_review' => 'در انتظار تائید واحد مالی',
    'processing'     => 'ارسال درخواست به انبار',
    'packed'         => 'بسته بندی و آماده تحویل',
    'shipped-pik'    => 'تحویل پیک جهت ارسال',
    'shipped-post'   => 'تحویل پست جهت ارسال',
    'shipped'        => 'ارسال شد',
    'delivered'      => 'تحویل شد',
    'cancelled'      => 'لغو شده',
];

/*
|--------------------------------------------------------------------------
| تغییر وضعیت سفارش
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = filter_input(
        INPUT_POST,
        'id',
        FILTER_VALIDATE_INT
    );

    $id = $id !== false && $id !== null
        ? (int)$id
        : 0;

    $newStatus = trim(
        (string)($_POST['status'] ?? '')
    );

    /*
     * اگر POST ناقص یا نامعتبر باشد،
     * هیچ عملیات دیتابیسی انجام نمی‌دهیم.
     */
    if (
        $id <= 0
        || !in_array(
            $newStatus,
            $allowedStatuses,
            true
        )
    ) {
        header('Location: /admin/orders');
        exit;
    }

    /*
     * سفارش را با وضعیت فعلی می‌خوانیم.
     */
    $orderStmt = $db->prepare("
        SELECT
            id,
            status
        FROM orders
        WHERE id = ?
        LIMIT 1
    ");

    $orderStmt->execute([$id]);

    $order = $orderStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$order) {
        header('Location: /admin/orders');
        exit;
    }

    $oldStatus = (string)$order['status'];

    /*
     * آیا واقعاً transition اتفاق افتاده؟
     */
    $statusChanged = (
        $oldStatus !== $newStatus
    );

    /*
     * اگر وضعیت تغییری نکرده،
     * دوباره SMS یا Audit ثبت نمی‌کنیم.
     */
    if (!$statusChanged) {
        header('Location: /admin/orders');
        exit;
    }

    /*
     * اطلاعات مربوط به transition
     * قبل از commit نگهداری می‌شوند.
     */

    $shippingSmsStatuses = [
        'packed',
        'shipped-pik',
        'shipped-post',
    ];

    $shouldSendShippingSms = in_array($newStatus, $shippingSmsStatuses, true);
    file_put_contents(
        ROOT_PATH . '/sms-debug.txt',
        date('Y-m-d H:i:s')
            . " | orderId=$id | old=$oldStatus | new=$newStatus"
            . " | shouldSend=" . ($shouldSendShippingSms ? 'YES' : 'NO')
            . PHP_EOL,
        FILE_APPEND
    );
    $db->beginTransaction();

    try {

        /*
         * ----------------------------------------------------------
         * تغییر وضعیت سفارش
         * ----------------------------------------------------------
         */

        $update = $db->prepare("
            UPDATE orders
            SET status = ?
            WHERE id = ?
              AND status = ?
        ");

        $update->execute([
            $newStatus,
            $id,
            $oldStatus,
        ]);

        /*
         * اگر هیچ ردیفی تغییر نکرده،
         * وضعیت سفارش در فاصله بین SELECT و UPDATE
         * تغییر کرده است.
         */
        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                'Order status changed by another request.'
            );
        }

        /*
         * ----------------------------------------------------------
         * آزادسازی موجودی در صورت لغو
         * ----------------------------------------------------------
         */

        if (
            $newStatus === 'cancelled'
            && $oldStatus !== 'cancelled'
        ) {

            $release = $db->prepare("
                UPDATE inventory inv
                JOIN order_items oi
                    ON oi.product_id = inv.product_id
                SET
                    inv.qty_available =
                        inv.qty_available + oi.qty
                WHERE oi.order_id = ?
            ");

            $release->execute([$id]);
        }

        /*
         * ----------------------------------------------------------
         * Audit
         * ----------------------------------------------------------
         */

        $audit = $db->prepare("
            INSERT INTO audit_logs
            (
                user_id,
                action,
                entity,
                entity_id,
                ip
            )
            VALUES
            (
                ?,
                ?,
                'order',
                ?,
                ?
            )
        ");

        $audit->execute([
            (int)($_SESSION['admin_id'] ?? 0),
            'order.status.' . $newStatus,
            $id,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        /*
         * ----------------------------------------------------------
         * COMMIT
         * ----------------------------------------------------------
         */

        $db->commit();
    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'ORDER STATUS UPDATE FAILED: '
                . $e->getMessage()
        );

        header('Location: /admin/orders');
        exit;
    }

    /*
     * --------------------------------------------------------------
     * SMS ارسال سفارش
     * --------------------------------------------------------------
     *
     * SMS بعد از COMMIT ارسال می‌شود.
     *
     * بنابراین خرابی API پیامک باعث rollback سفارش نمی‌شود.
     */

    if ($shouldSendShippingSms) {
        file_put_contents(
            ROOT_PATH . '/sms-debug.txt',
            date('Y-m-d H:i:s')
                . " | CALLING orderShipping($id)"
                . PHP_EOL,
            FILE_APPEND
        );

        try {


            OrderSmsService::orderShipping($id);
        } catch (Throwable $e) {
            file_put_contents(
                ROOT_PATH . '/sms-debug.txt',
                date('Y-m-d H:i:s')
                    . " | ERROR: " . $e->getMessage()
                    . PHP_EOL,
                FILE_APPEND
            );

            error_log(
                'ORDER SHIPPING SMS FAILED: '
                    . $e->getMessage()
            );
        }
    }

    /*
     * بعد از POST همیشه redirect می‌کنیم
     * تا refresh باعث ارسال مجدد POST نشود.
     */
    header('Location: /admin/orders');
    exit;
}

/*
|--------------------------------------------------------------------------
| فیلتر وضعیت
|--------------------------------------------------------------------------
*/

$filter = trim(
    (string)($_GET['status'] ?? '')
);

$where = '';
$params = [];

if (
    $filter !== ''
    && in_array(
        $filter,
        $allowedStatuses,
        true
    )
) {

    $where = " WHERE o.status = ? ";

    $params[] = $filter;
}

/*
|--------------------------------------------------------------------------
| لیست سفارش‌ها
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        o.*,
        u.full_name AS customer_name,
        u.mobile,
        c.name AS company_name,
        (
            SELECT SUM(qty)
            FROM order_items
            WHERE order_id = o.id
        ) AS total_qty
    FROM orders o
    JOIN users u
        ON u.id = o.user_id
    LEFT JOIN companies c
        ON c.id = o.company_id
    $where
    ORDER BY o.id DESC
    LIMIT 100
");

$stmt->execute($params);

$orders = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| شمارنده‌ها
|--------------------------------------------------------------------------
*/

$all = (int)$db
    ->query("
        SELECT COUNT(*)
        FROM orders
    ")
    ->fetchColumn();

$pending = (int)$db
    ->query("
        SELECT COUNT(*)
        FROM orders
        WHERE status = 'pending_review'
    ")
    ->fetchColumn();

/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

$activeMenu = 'orders';
$pageTitle  = 'سفارش‌ها';

ob_start();
?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;">

    <a
        href="/admin/orders"
        class="st-badge <?= $filter === '' ? 'st-quoted' : '' ?>"
        style="text-decoration:none;padding:7px 16px;">
        همه (<?= $all ?>)
    </a>

    <a
        href="/admin/orders?status=pending_review"
        class="st-badge st-new"
        style="text-decoration:none;padding:7px 16px;">
        در انتظار (<?= $pending ?>)
    </a>

    <?php
    foreach (
        [
            'processing',
            'packed',
            'shipped-pik',
            'shipped-post',
            'shipped',
            'delivered',
            'cancelled',
        ] as $s
    ):
    ?>

        <a
            href="/admin/orders?status=<?= urlencode($s) ?>"
            class="st-badge"
            style="text-decoration:none;padding:7px 16px;background:#fff;border:1px solid var(--card-border);color:var(--muted);">
            <?= htmlspecialchars($statusLabels[$s]) ?>
        </a>

    <?php endforeach; ?>

</div>

<?php if ($orders): ?>

    <div class="admin-table-wrap">

        <table class="admin-table">

            <thead>
                <tr>
                    <th>شماره</th>
                    <th>مشتری / شرکت</th>
                    <th>اقلام</th>
                    <th>مبلغ کل</th>
                    <th>ارسال به</th>
                    <th>تاریخ</th>
                    <th style="min-width:190px;">
                        وضعیت ← تغییر
                    </th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($orders as $o): ?>

                    <?php
                    $currentStatus =
                        (string)$o['status'];

                    $statusLabel =
                        $statusLabels[$currentStatus]
                        ?? $currentStatus;

                    $rowStyle =
                        $currentStatus === 'pending_review'
                        ? 'background:#fffbea;'
                        : '';
                    ?>

                    <tr style="<?= $rowStyle ?>">

                        <!-- شماره سفارش -->
                        <td>
                            <a
                                dir="ltr"
                                href="/admin/order-detail?id=<?= (int)$o['id'] ?>"
                                style="color:var(--teal-700);">
                                <b>
                                    <?= htmlspecialchars(
                                        (string)$o['order_number']
                                    ) ?>
                                </b>
                            </a>
                        </td>

                        <!-- مشتری / شرکت -->
                        <td>

                            <b>
                                <?= htmlspecialchars(
                                    (string)($o['customer_name'] ?? '')
                                ) ?>
                            </b>

                            <br>

                            <span
                                style="font-size:.72rem;color:var(--muted);">
                                <?= htmlspecialchars(
                                    (string)(
                                        $o['company_name']
                                        ?? 'بدون شرکت'
                                    )
                                ) ?>
                            </span>

                        </td>

                        <!-- اقلام -->
                        <td>
                            <?= (int)$o['total_qty'] ?>
                            جفت
                        </td>

                        <!-- مبلغ -->
                        <td>
                            <b style="color:var(--teal-800);">
                                <?= number_format(
                                    (float)$o['total']
                                ) ?>
                            </b>
                            ت
                        </td>

                        <!-- ارسال به -->
                        <td>

                            <b>
                                <?= htmlspecialchars(
                                    (string)(
                                        $o['shipping_name']
                                        ?? ''
                                    )
                                ) ?>
                            </b>

                            <br>

                            <span
                                style="color:var(--muted);">
                                <?= htmlspecialchars(
                                    (string)(
                                        $o['shipping_city']
                                        ?? ''
                                    )
                                ) ?>
                            </span>

                        </td>

                        <!-- تاریخ -->
                        <td style="font-size:.75rem;">

                            <?= htmlspecialchars(
                                (string)$o['created_at']
                            ) ?>

                        </td>

                        <!-- وضعیت -->
                        <td style="font-size:.72rem;color:var(--muted);">

                            <span class="st-badge st-new">
                                <?= htmlspecialchars(
                                    $statusLabel
                                ) ?>
                            </span>

                            <br>
                            <br>

                            <form
                                method="post"
                                action="/admin/orders"
                                style="display:flex;gap:6px;">

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$o['id'] ?>">

                                <select
                                    name="status"
                                    style="font-size:.75rem;padding:6px;border:1px solid var(--card-border);border-radius:8px;">

                                    <?php foreach (
                                        $statusLabels
                                        as $key => $lbl
                                    ): ?>

                                        <option
                                            value="<?= htmlspecialchars($key) ?>"
                                            <?= $currentStatus === $key
                                                ? 'selected'
                                                : '' ?>>
                                            <?= htmlspecialchars($lbl) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <button
                                    type="submit"
                                    class="btn btn-orange"
                                    style="padding:6px 14px;font-size:.72rem;">
                                    ثبت
                                </button>

                            </form>

                            <?php
                            if (
                                $currentStatus !== 'cancelled'
                            ):
                            ?>

                                <form
                                    method="post"
                                    action="/admin/orders"
                                    style="margin-top:6px;"
                                    onsubmit="return confirm('سفارش لغو شود؟ موجودی انبار برمی‌گردد.');">

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$o['id'] ?>">

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="cancelled">

                                    <button
                                        type="submit"
                                        class="btn"
                                        style="padding:4px 12px;font-size:.68rem;background:#fff0f0;color:#c62828;">
                                        لغو سفارش
                                    </button>

                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

<?php else: ?>

    <div class="empty-state">

        <h2>
            سفارشی با این فیلتر نیست
        </h2>

    </div>

<?php endif; ?>

<?php

$content = ob_get_clean();

require ROOT_PATH . '/views/layouts/admin.php';
