<?php

declare(strict_types=1);

/**
 * Central Pricing Service — v2 Final- PricingService.php
 *
 * ══════════════════════════════════════════════════════════════════
 * قانون قیمت‌گذاری (اولویت‌بندی، نه انباشت):
 * ══════════════════════════════════════════════════════════════════
 *
 *   ۱. اگر گروه ∈ {bronze, silver, gold}:
 *        قیمت واحد = base_price × (۱ - تخفیف گروه)
 *        (تخفیف پله‌ای نادیده گرفته می‌شود)
 *
 *   ۲. اگر گروه ∈ {regular, null}:
 *        قیمت واحد = base_price × (۱ - تخفیف پله‌ای)
 *        (تخفیف گروه = ۰)
 *
 *   ۳. هیچ‌کدام: قیمت واحد = base_price
 *
 * ══════════════════════════════════════════════════════════════════
 * قاعده گرد کردن (طبق تصمیم کسب‌وکار):
 * ══════════════════════════════════════════════════════════════════
 *
 *   همه قیمت‌های نهایی به مضرب ۱۰۰۰ تومان گرد می‌شوند
 *   (۳ رقم آخر همیشه صفر).
 *
 *   مثال: ۴۴۲,۳۵۰ → ۴۴۲,۰۰۰
 *         ۴۴۲,۷۵۰ → ۴۴۳,۰۰۰
 *
 * ══════════════════════════════════════════════════════════════════
 * وابستگی دیتابیس:
 * ══════════════════════════════════════════════════════════════════
 *
 *   - products.base_price              (قیمت پایه محصول)
 *   - customer_groups                  (۴ گروه: regular, bronze, silver, gold)
 *   - product_tier_discounts           (پله‌های درصدی)
 *   - product_prices                   (legacy fallback برای base_price)
 */
final class PricingService
{
    /** کش گروه‌ها در طول یک درخواست */
    private static ?array $groupsCache = null;

    /** مضرب گرد کردن قیمت (تومان) */
    private const ROUND_FACTOR = 1000;

    /** گروه‌هایی که تخفیف سطح می‌گیرند (پله‌ای برایشان اعمال نمی‌شود) */
    private const TIER_GROUP_CODES = ['bronze', 'silver', 'gold'];

    /* ═══════════════════════════════════════════════════════════════
       گروه‌ها
    ═══════════════════════════════════════════════════════════════ */

    public static function getGroups(PDO $db): array
    {
        if (self::$groupsCache !== null) {
            return self::$groupsCache;
        }

        $stmt = $db->query("
            SELECT code, name, discount_percent, min_purchase_jin, sort_order
            FROM customer_groups
            WHERE is_active = 1
            ORDER BY sort_order, id
        ");

        self::$groupsCache = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::$groupsCache[$row['code']] = [
                'code'             => $row['code'],
                'name'             => $row['name'],
                'discount_percent' => (float)$row['discount_percent'],
                'min_purchase_jin' => (int)$row['min_purchase_jin'],
                'sort_order'       => (int)$row['sort_order'],
            ];
        }

        return self::$groupsCache;
    }

    public static function getGroup(PDO $db, ?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        return self::getGroups($db)[$code] ?? null;
    }

    /* ═══════════════════════════════════════════════════════════════
       قیمت پایه
    ═══════════════════════════════════════════════════════════════ */

    public static function getBasePrice(PDO $db, int $productId): int
    {
        $stmt = $db->prepare("SELECT base_price FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $basePrice = (int)$stmt->fetchColumn();

        if ($basePrice > 0) {
            return $basePrice;
        }

        // Fallback legacy: قیمت wholesale min_qty=1
        $stmt = $db->prepare("
            SELECT price FROM product_prices
            WHERE product_id = ? AND customer_group = 'wholesale' AND min_qty = 1
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        $legacy = (int)($stmt->fetchColumn() ?: 0);

        if ($legacy > 0) {
            return $legacy;
        }

        // Fallback دوم: هر گروهی، min_qty=1
        $stmt = $db->prepare("
            SELECT price FROM product_prices
            WHERE product_id = ? AND min_qty = 1 AND price > 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$productId]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    /* ═══════════════════════════════════════════════════════════════
       پله‌ها
    ═══════════════════════════════════════════════════════════════ */

    /**
     * @return array<int, array{min_qty:int, discount_percent:float}>
     */
    public static function getTiers(PDO $db, int $productId): array
    {
        $stmt = $db->prepare("
            SELECT min_qty, discount_percent
            FROM product_tier_discounts
            WHERE product_id = ? AND is_active = 1
            ORDER BY min_qty ASC
        ");
        $stmt->execute([$productId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn($r) => [
            'min_qty'          => (int)$r['min_qty'],
            'discount_percent' => (float)$r['discount_percent'],
        ], $rows);
    }

    /* ═══════════════════════════════════════════════════════════════
       گرد کردن
    ═══════════════════════════════════════════════════════════════ */

    /**
     * گرد کردن قیمت به نزدیک‌ترین مضرب ۱۰۰۰ تومان.
     * مثال: 442,350 → 442,000 | 442,750 → 443,000
     */
    public static function roundPrice(int $price): int
    {
        if ($price <= 0) {
            return 0;
        }
        return (int)(round($price / self::ROUND_FACTOR) * self::ROUND_FACTOR);
    }

    /* ═══════════════════════════════════════════════════════════════
       محاسبه نهایی
    ═══════════════════════════════════════════════════════════════ */

    /**
     * محاسبه قیمت نهایی محصول برای یک تعداد و گروه.
     *
     * @return array{
     *   base_price:int,
     *   unit_price:int,
     *   qty:int,
     *   line_total:int,
     *   base_line_total:int,
     *   discount_source:'none'|'group'|'tier',
     *   discount_percent:float,
     *   discount_amount:int,
     *   group:?array,
     *   tier_applied:?array{min_qty:int, discount_percent:float},
     *   customer_message:string
     * }
     */
    public static function calculate(
        PDO $db,
        int $productId,
        int $qty,
        ?string $groupCode = 'regular'
    ): array {
        $qty = max(0, $qty);

        $basePrice = self::getBasePrice($db, $productId);
        $group     = self::getGroup($db, $groupCode);

        $isTierGroup = $group !== null
            && in_array($group['code'], self::TIER_GROUP_CODES, true);

        $unitPrice        = $basePrice;
        $discountPercent  = 0.0;
        $source           = 'none';
        $tierApplied      = null;
        $customerMessage  = 'قیمت عادی';

        /* ─── ۱) تخفیف سطح مشتری ─── */
        if ($isTierGroup && $group['discount_percent'] > 0) {
            $discountPercent = (float)$group['discount_percent'];
            $rawPrice        = (int)round($basePrice * (1 - $discountPercent / 100));
            $unitPrice       = self::roundPrice($rawPrice);
            $source          = 'group';

            $customerMessage = sprintf(
                'تخفیف سطح %s %s٪',
                $group['name'],
                self::formatPercent($discountPercent)
            );
        }
        /* ─── ۲) تخفیف پله‌ای (فقط regular) ─── */ else {
            $tiers = self::getTiers($db, $productId);

            foreach ($tiers as $tier) {
                $minQty = $tier['min_qty'];
                if ($minQty > 0 && $minQty <= $qty) {
                    $tierApplied     = $tier;
                    $discountPercent = $tier['discount_percent'];
                }
            }

            if ($tierApplied !== null && $discountPercent > 0) {
                $rawPrice  = (int)round($basePrice * (1 - $discountPercent / 100));
                $unitPrice = self::roundPrice($rawPrice);
                $source    = 'tier';

                $customerMessage = sprintf(
                    'تخفیف خرید %s عدد %s٪',
                    number_format($tierApplied['min_qty']),
                    self::formatPercent($discountPercent)
                );
            }
        }

        /* ─── جمع‌ها ─── */
        $lineTotal      = $unitPrice * $qty;
        $baseLineTotal  = $basePrice * $qty;
        $discountAmount = max(0, $baseLineTotal - $lineTotal);

        /* درصد واقعی تخفیف (برای نمایش) */
        $effectiveDiscount = $basePrice > 0
            ? round((($basePrice - $unitPrice) / $basePrice) * 100, 2)
            : 0.0;

        return [
            'base_price'       => $basePrice,
            'unit_price'       => $unitPrice,
            'qty'              => $qty,
            'line_total'       => $lineTotal,
            'base_line_total'  => $baseLineTotal,
            'discount_source'  => $source,
            'discount_percent' => $effectiveDiscount,
            'discount_amount'  => $discountAmount,
            'group'            => $group,
            'tier_applied'     => $tierApplied,
            'customer_message' => $customerMessage,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════
       کمکی‌ها
    ═══════════════════════════════════════════════════════════════ */
    /* ⭐ FIX: قاعده مشترک حداقل خرید محصولات جفتی */

    /** حداقل مجموع خرید محصولات جفتی (قاعده تجاری) */
    public const CART_MIN_PAIR_QTY = 12;

    /**
     * مجموع تعداد محصولات با واحد «جفتی» در یک سبد مشخص
     */
    public static function getPairQtyByCart(PDO $db, int $cartId): int
    {
        if ($cartId <= 0) {
            return 0;
        }

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN COALESCE((
                        SELECT ps.spec_value
                        FROM product_specs ps
                        WHERE ps.product_id = ci.product_id
                          AND ps.spec_key = 'واحد سفارش'
                        LIMIT 1
                    ), 'جفتی') = 'جفتی'
                    THEN ci.qty
                    ELSE 0
                END
            ), 0)
            FROM cart_items ci
            WHERE ci.cart_id = ?
        ");
        $stmt->execute([$cartId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * مجموع تعداد محصولات «جفتی» در سبد فعال یک کاربر
     */
    public static function getPairQtyByUser(PDO $db, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $db->prepare("
            SELECT id FROM carts
            WHERE user_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $cartId = (int)($stmt->fetchColumn() ?: 0);

        return $cartId > 0 ? self::getPairQtyByCart($db, $cartId) : 0;
    }

    /**
     * آیا سبد قاعده حداقل ۱۲ جفت را نقض می‌کند؟
     * (اگر هیچ محصول جفتی در سبد نباشد، محدودیت اعمال نمی‌شود)
     */
    public static function violatesPairMinimum(int $pairQty): bool
    {
        return $pairQty > 0 && $pairQty < self::CART_MIN_PAIR_QTY;
    }
    
    private static function formatPercent(float $p): string
    {
        $rounded = round($p, 2);
        if ($rounded == (int)$rounded) {
            return number_format((int)$rounded);
        }
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    /**
     * پاک کردن کش (در تست‌ها یا وقتی گروه‌ها تغییر می‌کنند).
     */
    public static function clearCache(): void
    {
        self::$groupsCache = null;
    }
}
