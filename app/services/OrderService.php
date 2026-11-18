<?php

declare(strict_types=1);

/**
 * OrderService — مرکز منطق سفارش
 *
 * ═══════════════════════════════════════════════════════════════════
 * ۱) گراف انتقال وضعیت سفارش (status, payment_status, fulfillment_status, shipping_status)
 * ۲) اعتبارسنجی انتقال‌ها
 * ۳) کمک‌تابع‌های تخصیص/آزادسازی رزرو موجودی
 * ۴) snapshot قیمت هنگام ثبت
 * ۵) ارتقای خودکار سطح مشتری/شرکت بر اساس خرید تجمعی
 * ═══════════════════════════════════════════════════════════════════
 *
 * این کلاس stateless است؛ فقط منطق و کوئری‌ها در آن نگه‌داری می‌شوند.
 * هر متد یک PDO قبول می‌کند (به‌جای db() مستقیم) تا قابل تست باشد.
 */

final class OrderService
{
    /* ═══════════════════════════════════════════════════════════════
       گراف‌های انتقال وضعیت
       ═══════════════════════════════════════════════════════════════ */

    /**
     * گراف انتقال وضعیت سفارش (status — برای backward compatibility).
     */
    public const STATUS_GRAPH = [
        'pending_review' => ['processing', 'cancelled'],
        'processing'     => ['packed', 'cancelled', 'pending_review'],
        'packed'         => ['shipped-pik', 'shipped-post', 'shipped', 'processing', 'cancelled'],
        'shipped-pik'    => ['delivered', 'cancelled'],
        'shipped-post'   => ['delivered', 'cancelled'],
        'shipped'        => ['delivered', 'cancelled'],
        'delivered'      => ['cancelled'],
        'cancelled'      => ['pending_review'],
    ];

    public const PAYMENT_GRAPH = [
        'unpaid'               => ['pending_verification', 'partial', 'paid', 'void'],
        'pending_verification' => ['unpaid', 'partial', 'paid', 'rejected'],
        'partial'              => ['paid', 'unpaid', 'void'],
        'paid'                 => ['overpaid', 'refunded_partially', 'refunded_fully', 'void'],
        'overpaid'             => ['paid', 'refunded_partially'],
        'refunded_partially'   => ['refunded_fully'],
        'refunded_fully'       => [],
        'void'                 => ['unpaid'],
        'rejected'             => ['unpaid', 'pending_verification'],
    ];

    public const FULFILLMENT_GRAPH = [
        'pending'          => ['processing', 'cancelled'],
        'processing'        => ['packed', 'partial_supply', 'needs_production', 'cancelled', 'pending'],
        'partial_supply'   => ['processing', 'packed', 'cancelled'],
        'needs_production' => ['processing', 'cancelled'],
        'packed'           => ['completed', 'processing', 'cancelled'],
        'completed'        => [],
        'cancelled'        => ['pending'],
    ];

    public const SHIPPING_GRAPH = [
        'not_ready'      => ['ready', 'not_applicable'],
        'ready'          => ['packing', 'not_ready'],
        'packing'        => ['in_transit', 'ready'],
        'in_transit'     => ['delivered', 'failed', 'returned'],
        'delivered'      => [],
        'failed'         => ['ready', 'in_transit'],
        'returned'       => ['ready', 'not_applicable'],
        'not_applicable' => ['not_ready'],
    ];

    /* ═══════════════════════════════════════════════════════════════
       اعتبارسنجی انتقال
       ═══════════════════════════════════════════════════════════════ */

    public static function canTransition(array $graph, string $from, string $to): bool
    {
        if ($from === $to) return true;
        $allowed = $graph[$from] ?? null;
        return $allowed !== null && in_array($to, $allowed, true);
    }

    public static function assertStatusTransition(string $from, string $to): void
    {
        if (!self::canTransition(self::STATUS_GRAPH, $from, $to)) {
            throw new InvalidArgumentException(
                "انتقال وضعیت سفارش از «{$from}» به «{$to}» مجاز نیست."
            );
        }
    }

    public static function assertPaymentTransition(string $from, string $to): void
    {
        if (!self::canTransition(self::PAYMENT_GRAPH, $from, $to)) {
            throw new InvalidArgumentException(
                "انتقال وضعیت پرداخت از «{$from}» به «{$to}» مجاز نیست."
            );
        }
    }

    public static function assertFulfillmentTransition(string $from, string $to): void
    {
        if (!self::canTransition(self::FULFILLMENT_GRAPH, $from, $to)) {
            throw new InvalidArgumentException(
                "انتقال وضعیت تأمین از «{$from}» به «{$to}» مجاز نیست."
            );
        }
    }

    public static function assertShippingTransition(string $from, string $to): void
    {
        if (!self::canTransition(self::SHIPPING_GRAPH, $from, $to)) {
            throw new InvalidArgumentException(
                "انتقال وضعیت ارسال از «{$from}» به «{$to}» مجاز نیست."
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       رزرو و آزادسازی موجودی
       ═══════════════════════════════════════════════════════════════ */

    /** اگر تراکنش بیرونی باز است، به‌جای تراکنش جدید از SAVEPOINT استفاده می‌شود. */
    private static function txBegin(PDO $db): bool
    {
        if ($db->inTransaction()) {
            $db->exec('SAVEPOINT order_service_stock');
            return false;
        }
        $db->beginTransaction();
        return true;
    }

    private static function txCommit(PDO $db, bool $own): void
    {
        if ($own) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT order_service_stock');
        }
    }

    private static function txRollback(PDO $db, bool $own): void
    {
        if ($own) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } elseif ($db->inTransaction()) {
            $db->exec('ROLLBACK TO SAVEPOINT order_service_stock');
        }
    }

    /**
     * رزرو موجودی برای یک قلم سفارش.
     */
    public static function reserveStock(
        PDO $db,
        int $productId,
        int $qty,
        int $orderId,
        ?int $orderItemId = null,
        ?int $adminId = null
    ): bool {
        if ($qty < 0) {
            throw new InvalidArgumentException("qty نمی‌تواند منفی باشد");
        }
        if ($qty === 0) return true;

        $own = self::txBegin($db);
        try {
            $stmt = $db->prepare("
                SELECT qty_physical, reserved, unsellable
                FROM inventory
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();

            if (!$row) {
                self::txRollback($db, $own);
                throw new RuntimeException("رکورد inventory برای محصول {$productId} وجود ندارد");
            }

            $phys = (int)$row['qty_physical'];
            $res  = (int)$row['reserved'];
            $uns  = (int)$row['unsellable'];

            $availForSale = max(0, $phys - $res - $uns);
            if ($availForSale < $qty) {
                self::txRollback($db, $own);
                throw new RuntimeException(
                    "موجودی قابل فروش محصول {$productId} کافی نیست (موجود={$availForSale}, درخواست={$qty})"
                );
            }

            $db->prepare("UPDATE inventory SET reserved = reserved + ? WHERE product_id = ?")
                ->execute([$qty, $productId]);

            $db->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, reference_type, reference_id,
                 order_id, order_item_id, reason, created_by, ip)
                VALUES (?, 'reserve', ?, 'order', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $productId,
                $qty,
                $orderId,
                $orderId,
                $orderItemId,
                null,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);

            self::txCommit($db, $own);
            return true;
        } catch (Throwable $e) {
            self::txRollback($db, $own);
            throw $e;
        }
    }

    /**
     * آزادسازی رزرو.
     */
    public static function releaseStock(
        PDO $db,
        int $productId,
        int $qty,
        int $orderId,
        ?int $orderItemId = null,
        ?int $adminId = null
    ): bool {
        if ($qty <= 0) return true;

        $own = self::txBegin($db);
        try {
            $stmt = $db->prepare("
                SELECT reserved FROM inventory WHERE product_id = ? FOR UPDATE
            ");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();
            if (!$row) {
                self::txRollback($db, $own);
                return false;
            }
            $current    = (int)$row['reserved'];
            $releaseQty = min($qty, $current);

            $db->prepare("UPDATE inventory SET reserved = reserved - ? WHERE product_id = ?")
                ->execute([$releaseQty, $productId]);

            $db->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, reference_type, reference_id,
                 order_id, order_item_id, reason, created_by, ip)
                VALUES (?, 'release', ?, 'order', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $productId,
                $releaseQty,
                $orderId,
                $orderId,
                $orderItemId,
                null,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);

            self::txCommit($db, $own);
            return true;
        } catch (Throwable $e) {
            self::txRollback($db, $own);
            throw $e;
        }
    }

    /**
     * خروج واقعی کالا از انبار.
     */
    public static function shipStock(
        PDO $db,
        int $productId,
        int $qty,
        int $orderId,
        ?int $orderItemId = null,
        ?int $adminId = null
    ): bool {
        if ($qty <= 0) return true;

        $own = self::txBegin($db);
        try {
            $stmt = $db->prepare("
                SELECT qty_physical, reserved FROM inventory WHERE product_id = ? FOR UPDATE
            ");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();
            if (!$row) {
                self::txRollback($db, $own);
                throw new RuntimeException("inventory برای محصول {$productId} یافت نشد");
            }

            $phys = (int)$row['qty_physical'];
            $res  = (int)$row['reserved'];

            if ($res < $qty) {
                self::txRollback($db, $own);
                throw new RuntimeException(
                    "مقدار رزرو ({$res}) کمتر از مقدار درخواست ارسال ({$qty}) است"
                );
            }
            if ($phys < $qty) {
                self::txRollback($db, $own);
                throw new RuntimeException("موجودی فیزیکی کافی نیست");
            }

            $db->prepare("
                UPDATE inventory
                SET qty_physical = qty_physical - ?, reserved = reserved - ?
                WHERE product_id = ?
            ")->execute([$qty, $qty, $productId]);

            $db->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, reference_type, reference_id,
                 order_id, order_item_id, reason, created_by, ip)
                VALUES (?, 'ship', ?, 'order', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $productId,
                $qty,
                $orderId,
                $orderId,
                $orderItemId,
                null,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);

            self::txCommit($db, $own);
            return true;
        } catch (Throwable $e) {
            self::txRollback($db, $own);
            throw $e;
        }
    }

    /**
     * ورود کالا به انبار (رسید).
     */
    public static function stockIn(
        PDO $db,
        int $productId,
        int $qty,
        string $reason = '',
        ?int $adminId = null
    ): bool {
        if ($qty <= 0) return false;

        $own = self::txBegin($db);
        try {
            $db->prepare("
                INSERT INTO inventory (product_id, qty_physical, reserved, unsellable)
                VALUES (?, ?, 0, 0)
                ON DUPLICATE KEY UPDATE qty_physical = qty_physical + VALUES(qty_physical)
            ")->execute([$productId, $qty]);

            $db->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, reference_type, reference_id,
                 order_id, order_item_id, reason, created_by, ip)
                VALUES (?, 'stock_in', ?, NULL, NULL, NULL, NULL, ?, ?, ?)
            ")->execute([
                $productId,
                $qty,
                $reason !== '' ? $reason : 'manual stock-in',
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);

            self::txCommit($db, $own);
            return true;
        } catch (Throwable $e) {
            self::txRollback($db, $own);
            throw $e;
        }
    }

    /**
     * اصلاح موجودی فیزیکی.
     */
    public static function adjustPhysical(
        PDO $db,
        int $productId,
        int $newPhysical,
        string $reason,
        ?int $adminId = null
    ): bool {
        if ($newPhysical < 0) {
            throw new InvalidArgumentException("موجودی فیزیکی نمی‌تواند منفی باشد");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("دلیل اصلاح موجودی الزامی است");
        }

        $own = self::txBegin($db);
        try {
            $stmt = $db->prepare("SELECT qty_physical FROM inventory WHERE product_id = ? FOR UPDATE");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();
            $oldPhysical = $row ? (int)$row['qty_physical'] : 0;
            $delta = $newPhysical - $oldPhysical;

            if ($delta === 0) {
                self::txRollback($db, $own);
                return true;
            }

            if ($row) {
                $db->prepare("UPDATE inventory SET qty_physical = ? WHERE product_id = ?")
                    ->execute([$newPhysical, $productId]);
            } else {
                $db->prepare("INSERT INTO inventory (product_id, qty_physical) VALUES (?, ?)")
                    ->execute([$productId, $newPhysical]);
            }

            $db->prepare("
                INSERT INTO stock_movements
                (product_id, movement_type, quantity, reference_type, reference_id,
                 order_id, order_item_id, reason, created_by, ip)
                VALUES (?, 'adjust_physical', ?, NULL, NULL, NULL, NULL, ?, ?, ?)
            ")->execute([
                $productId,
                $delta,
                $reason,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);

            self::txCommit($db, $own);
            return true;
        } catch (Throwable $e) {
            self::txRollback($db, $own);
            throw $e;
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       ارتقای خودکار سطح مشتری/شرکت
       ═══════════════════════════════════════════════════════════════ */

    /**
     * آستانه‌های ارتقا را از settings می‌خواند.
     *
     * @return array{bronze:int, silver:int, gold:int}
     */
    private static function getUpgradeThresholds(PDO $db): array
    {
        $thresholds = [
            'bronze' => 100,
            'silver' => 500,
            'gold'   => 1000,
        ];

        try {
            $stmt = $db->query("
                SELECT `key`, `value`
                FROM settings
                WHERE `key` IN (
                    'customer_group.bronze_min_jin',
                    'customer_group.silver_min_jin',
                    'customer_group.gold_min_jin'
                )
            ");

            $map = [
                'customer_group.bronze_min_jin' => 'bronze',
                'customer_group.silver_min_jin' => 'silver',
                'customer_group.gold_min_jin'   => 'gold',
            ];

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($map[$row['key']])) {
                    $thresholds[$map[$row['key']]] = (int)$row['value'];
                }
            }
        } catch (Throwable $e) {
            // پیش‌فرض‌ها کافی هستند
        }

        return $thresholds;
    }

    /**
     * تعیین سطح بر اساس خرید تجمعی + اولویت‌بندی با سطح فعلی.
     */
    private static function resolveNewGroup(
        int $totalQty,
        string $currentGroup,
        array $thresholds
    ): string {
        $newGroup = 'regular';
        if ($totalQty >= $thresholds['gold'])        $newGroup = 'gold';
        elseif ($totalQty >= $thresholds['silver'])  $newGroup = 'silver';
        elseif ($totalQty >= $thresholds['bronze'])  $newGroup = 'bronze';

        // سطح فعلی اگر بالاتر بود، حفظ شود
        $rank = ['regular' => 0, 'bronze' => 1, 'silver' => 2, 'gold' => 3];
        if (($rank[$currentGroup] ?? 0) > ($rank[$newGroup] ?? 0)) {
            $newGroup = $currentGroup;
        }

        return $newGroup;
    }

    /**
     * ارتقای خودکار سطح مشتری (حقیقی) بر اساس خرید تجمعی.
     *
     * @return array{old:string, new:string, total:int, upgraded:bool}
     */
    public static function maybeUpgradeCustomerGroup(PDO $db, int $userId): array
    {
        if ($userId < 1) {
            return ['old' => 'regular', 'new' => 'regular', 'total' => 0, 'upgraded' => false];
        }

        /* ۱. جمع خرید تجمعی */
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(oi.qty), 0) AS total_qty
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.user_id = ?
              AND o.status NOT IN ('cancelled')
        ");
        $stmt->execute([$userId]);
        $totalQty = (int)$stmt->fetchColumn();

        /* ۲. آستانه‌ها */
        $thresholds = self::getUpgradeThresholds($db);

        /* ۳. سطح فعلی */
        $stmt = $db->prepare("SELECT customer_group FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $currentGroup = (string)($stmt->fetchColumn() ?: 'regular');

        /* ۴. سطح جدید */
        $newGroup = self::resolveNewGroup($totalQty, $currentGroup, $thresholds);
        $upgraded = ($currentGroup !== $newGroup);

        /* ۵. آپدیت */
        try {
            $db->prepare("
                UPDATE users
                SET customer_group = ?,
                    total_purchased_jin = ?,
                    group_upgraded_at = IF(? <> customer_group, NOW(), group_upgraded_at)
                WHERE id = ?
            ")->execute([$newGroup, $totalQty, $newGroup, $userId]);
        } catch (Throwable $e) {
            error_log('maybeUpgradeCustomerGroup failed: ' . $e->getMessage());
        }

        return [
            'old'      => $currentGroup,
            'new'      => $newGroup,
            'total'    => $totalQty,
            'upgraded' => $upgraded,
        ];
    }

    /**
     * ارتقای خودکار سطح شرکت (حقوقی) بر اساس خرید تجمعی.
     *
     * @return array{old:string, new:string, total:int, upgraded:bool}
     */
    public static function maybeUpgradeCompanyGroup(PDO $db, int $companyId): array
    {
        if ($companyId < 1) {
            return ['old' => 'regular', 'new' => 'regular', 'total' => 0, 'upgraded' => false];
        }

        /* ۱. جمع خرید تجمعی */
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(oi.qty), 0) AS total_qty
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.company_id = ?
              AND o.status NOT IN ('cancelled')
        ");
        $stmt->execute([$companyId]);
        $totalQty = (int)$stmt->fetchColumn();

        /* ۲. آستانه‌ها */
        $thresholds = self::getUpgradeThresholds($db);

        /* ۳. سطح فعلی */
        $stmt = $db->prepare("SELECT customer_group FROM companies WHERE id = ? LIMIT 1");
        $stmt->execute([$companyId]);
        $currentGroup = (string)($stmt->fetchColumn() ?: 'regular');

        /* ۴. سطح جدید */
        $newGroup = self::resolveNewGroup($totalQty, $currentGroup, $thresholds);
        $upgraded = ($currentGroup !== $newGroup);

        /* ۵. آپدیت */
        try {
            $db->prepare("
                UPDATE companies
                SET customer_group = ?,
                    total_purchased_jin = ?,
                    group_upgraded_at = IF(? <> customer_group, NOW(), group_upgraded_at)
                WHERE id = ?
            ")->execute([$newGroup, $totalQty, $newGroup, $companyId]);
        } catch (Throwable $e) {
            error_log('maybeUpgradeCompanyGroup failed: ' . $e->getMessage());
        }

        return [
            'old'      => $currentGroup,
            'new'      => $newGroup,
            'total'    => $totalQty,
            'upgraded' => $upgraded,
        ];
    }
}
