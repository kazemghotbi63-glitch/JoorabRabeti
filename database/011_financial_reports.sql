-- تخفیف در سطح سفارش (برای گزارش تخفیف‌ها)
ALTER TABLE orders ADD COLUMN discount_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER shipping_fee;