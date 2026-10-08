USE sock_b2b;

SELECT
    `key`,
    `value`,
    updated_at
FROM settings
WHERE `key` IN (
    'sms_pattern_password_reset',
    'sms_pattern_order_approved',
    'sms_pattern_order_shipping'
)
ORDER BY `key`;
