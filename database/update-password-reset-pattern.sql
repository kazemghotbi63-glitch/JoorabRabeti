USE sock_b2b;

UPDATE settings
SET value = '2411'
WHERE `key` = 'sms_pattern_password_reset';

SELECT
    `key`,
    `value`,
    updated_at
FROM settings
WHERE `key` = 'sms_pattern_password_reset';
