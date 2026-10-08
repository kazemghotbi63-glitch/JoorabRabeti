-- =========================================================
-- SMS Pattern Settings
-- Project: sock-b2b
-- Compatible with MariaDB 10.4
-- =========================================================

INSERT INTO settings (`key`, `value`)
VALUES
    ('sms_pattern_password_reset', '2408'),
    ('sms_pattern_order_approved', '2412'),
    ('sms_pattern_order_shipping', '2411')
ON DUPLICATE KEY UPDATE
    `value` = VALUES(`value`);