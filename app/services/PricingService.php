<?php

declare(strict_types=1);

/**
 * Central pricing service
 *
 * مسئول محاسبه قیمت نهایی محصول بر اساس:
 * - قیمت پایه
 * - تعداد سفارش
 * - تخفیف‌های پلکانی product_prices
 *
 * قانون:
 * بالاترین min_qty که <= qty باشد، اعمال می‌شود.
 */
final class PricingService
{
    /**
     * دریافت tierهای قیمت عمده محصول.
     */
    public static function getWholesaleTiers(
        PDO $db,
        int $productId
    ): array {
        $stmt = $db->prepare("
            SELECT
                min_qty,
                price
            FROM product_prices
            WHERE product_id = ?
              AND customer_group = 'wholesale'
            ORDER BY min_qty ASC
        ");

        $stmt->execute([
            $productId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * محاسبه قیمت برای یک تعداد مشخص.
     *
     * خروجی:
     * [
     *   base_unit_price,
     *   unit_price,
     *   discount_percent,
     *   discount_per_unit,
     *   discount_amount,
     *   qty,
     *   line_total,
     *   base_line_total
     * ]
     */
    public static function calculate(
        int $basePrice,
        array $tiers,
        int $qty
    ): array {
        $qty = max(0, $qty);
        $basePrice = max(0, $basePrice);

        $unitPrice = $basePrice;
        $discountPercent = 0.0;

        /*
         * بالاترین tier قابل اعمال را پیدا می‌کنیم.
         */
        foreach ($tiers as $tier) {

            $minQty = (int)($tier['min_qty'] ?? 0);
            $price  = (int)($tier['price'] ?? 0);

            if (
                $minQty > 0
                && $minQty <= $qty
                && $price > 0
            ) {
                $unitPrice = $price;

                /*
                 * درصد تخفیف واقعی نسبت به قیمت پایه.
                 */
                if ($basePrice > 0 && $price < $basePrice) {

                    $discountPercent =
                        (($basePrice - $price) / $basePrice) * 100;
                }
            }
        }

        $discountPerUnit =
            max(0, $basePrice - $unitPrice);

        $baseLineTotal =
            $basePrice * $qty;

        $lineTotal =
            $unitPrice * $qty;

        $discountAmount =
            max(
                0,
                $baseLineTotal - $lineTotal
            );

        return [
            'base_unit_price'   => $basePrice,
            'unit_price'        => $unitPrice,
            'discount_percent'  => round($discountPercent, 2),
            'discount_per_unit' => $discountPerUnit,
            'discount_amount'   => $discountAmount,
            'qty'               => $qty,
            'base_line_total'   => $baseLineTotal,
            'line_total'        => $lineTotal,
        ];
    }

    /**
     * محاسبه قیمت بر اساس product_id و تعداد.
     */
    public static function calculateForProduct(
        PDO $db,
        int $productId,
        int $basePrice,
        int $qty
    ): array {
        $tiers =
            self::getWholesaleTiers(
                $db,
                $productId
            );

        return self::calculate(
            $basePrice,
            $tiers,
            $qty
        );
    }
}
