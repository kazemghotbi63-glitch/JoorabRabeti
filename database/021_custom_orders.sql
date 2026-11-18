-- =====================================================
--  JoorabRabeti - 021_custom_orders.sql
--  جداول ماژول سفارش اختصاصی (قبلاً بدون migration بودند)
--  ستون‌ها از روی کد public/custom-order*.php و admin/custom-orders.php
--  اگر روی سرور جدول‌ها از قبل هستند، IF NOT EXISTS آن‌ها را دست نمی‌زند.
-- =====================================================

CREATE TABLE IF NOT EXISTS custom_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracking_code VARCHAR(32) NOT NULL DEFAULT '',
    user_id INT UNSIGNED NULL,
    ref_product_id INT UNSIGNED NULL,
    ref_product_name VARCHAR(200) NULL,
    ref_product_code VARCHAR(50) NULL,
    full_name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    national_code VARCHAR(15) NULL,
    brand_name VARCHAR(150) NULL,
    description TEXT NULL,
    logo_path VARCHAR(255) NULL,
    total_qty INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount BIGINT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'submitted',
    status_changed_at DATETIME NULL,
    admin_note TEXT NULL,
    admin_approved_at DATETIME NULL,
    proforma_number VARCHAR(40) NULL,
    proforma_issued_at DATETIME NULL,
    down_payment_percent DECIMAL(5,2) NULL,
    down_payment_amount BIGINT UNSIGNED NULL,
    down_payment_receipt_path VARCHAR(255) NULL,
    down_payment_paid_at DATETIME NULL,
    payment_method VARCHAR(40) NULL,
    sample_status VARCHAR(40) NULL,
    sample_carrier VARCHAR(100) NULL,
    sample_tracking_code VARCHAR(100) NULL,
    sample_sent_at DATETIME NULL,
    sample_approved_at DATETIME NULL,
    sample_revision_note TEXT NULL,
    settlement_amount BIGINT UNSIGNED NULL,
    settlement_order_at DATETIME NULL,
    settlement_receipt_path VARCHAR(255) NULL,
    settlement_paid_at DATETIME NULL,
    agreement_signature_name VARCHAR(150) NULL,
    agreement_signed_at DATETIME NULL,
    agreement_ip VARCHAR(45) NULL,
    agreement_ua VARCHAR(255) NULL,
    agreement_scan_path VARCHAR(255) NULL,
    id_card_front_path VARCHAR(255) NULL,
    id_card_back_path VARCHAR(255) NULL,
    documents_submitted_at DATETIME NULL,
    documents_verified_at DATETIME NULL,
    final_invoice_number VARCHAR(40) NULL,
    final_invoice_amount BIGINT UNSIGNED NULL,
    final_invoice_at DATETIME NULL,
    shipping_carrier VARCHAR(100) NULL,
    shipping_tracking_code VARCHAR(100) NULL,
    shipped_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_co_tracking (tracking_code),
    KEY idx_co_user (user_id),
    KEY idx_co_mobile (mobile),
    KEY idx_co_status (status),
    CONSTRAINT fk_co_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS custom_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    custom_order_id INT UNSIGNED NOT NULL,
    product_type VARCHAR(100) NOT NULL,
    color VARCHAR(100) NULL,
    qty INT UNSIGNED NOT NULL,
    notes VARCHAR(500) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_coi_order (custom_order_id),
    CONSTRAINT fk_coi_order FOREIGN KEY (custom_order_id) REFERENCES custom_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS custom_order_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    custom_order_id INT UNSIGNED NOT NULL,
    stage_key VARCHAR(40) NOT NULL,
    actor VARCHAR(20) NOT NULL DEFAULT 'system',
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_coe_order (custom_order_id, created_at),
    CONSTRAINT fk_coe_order FOREIGN KEY (custom_order_id) REFERENCES custom_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS custom_order_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    setting_type ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
    setting_group VARCHAR(50) NOT NULL DEFAULT 'general',
    setting_label VARCHAR(150) NULL,
    setting_hint VARCHAR(255) NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_cos_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO custom_order_settings (setting_key, setting_value, setting_type, setting_group, setting_label) VALUES
('default_down_payment_percent', '30', 'decimal', 'payment', 'درصد پیش‌پرداخت پیش‌فرض'),
('proforma_prefix', 'PF-CO-', 'string', 'invoice', 'پیشوند پیش‌فاکتور'),
('proforma_validity_days', '7', 'int', 'invoice', 'اعتبار پیش‌فاکتور (روز)'),
('final_invoice_prefix', 'INV-CO-', 'string', 'invoice', 'پیشوند فاکتور نهایی'),
('invoice_shop_name', 'جوراب رابطی', 'string', 'invoice', 'نام فروشگاه روی فاکتور'),
('invoice_logo_path', '/Image/logo3.jpg', 'string', 'invoice', 'مسیر لوگوی فاکتور');
