-- =====================================================
--  JoorabRabeti - 022_users_national_code_unique.sql
--  کد ملی تکراری در users مجاز نیست. مقدار خالی به NULL تبدیل می‌شود.
--  اگر از قبل تکراری وجود داشته باشد، ایندکس ساخته نمی‌شود و پیام چاپ می‌شود؛
--  ابتدا تکراری‌ها را با این کوئری پیدا و اصلاح کنید:
--    SELECT national_code, COUNT(*) FROM users WHERE national_code IS NOT NULL GROUP BY national_code HAVING COUNT(*) > 1;
-- =====================================================

UPDATE users SET national_code = NULL WHERE national_code = '';

SET @dups := (SELECT COUNT(*) FROM (
    SELECT national_code FROM users WHERE national_code IS NOT NULL
    GROUP BY national_code HAVING COUNT(*) > 1) d);
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_national_code');
SET @sql := IF(@i > 0, 'SELECT "uq_users_national_code already exists" AS msg',
            IF(@dups > 0, 'SELECT "duplicate national_code values found - fix them, then rerun" AS msg',
               'ALTER TABLE users ADD UNIQUE KEY uq_users_national_code (national_code)'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
