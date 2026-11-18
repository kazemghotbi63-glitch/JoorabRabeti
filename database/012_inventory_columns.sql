-- =====================================================
--  JoorabRabeti - 012_inventory_columns.sql
--  نسخه اصلاح‌شده برای MariaDB 10.11 (بدون PREPARE)
--
--  مدل مفهومی:
--    available_for_sale = qty_physical - reserved - unsellable
--
--  ستون qty_available قدیمی حفظ می‌شود (trigger آن را sync می‌کند)
-- =====================================================

-- 1. افزودن ستون‌های جدید (idempotent با IF NOT EXISTS)
ALTER TABLE `inventory`
  ADD COLUMN IF NOT EXISTS `qty_physical` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reserved`     INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `unsellable`   INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 2. کپی مقدار قدیمی به qty_physical (یک‌بار)
UPDATE `inventory`
SET `qty_physical` = COALESCE(`qty_available`, 0)
WHERE `qty_physical` = 0;

-- 3. Trigger همگام‌سازی qty_available = qty_physical - reserved - unsellable
--    (BEFORE UPDATE)
DROP TRIGGER IF EXISTS `trg_inventory_sync_avail`;
DELIMITER $$
CREATE TRIGGER `trg_inventory_sync_avail`
BEFORE UPDATE ON `inventory`
FOR EACH ROW
BEGIN
    SET NEW.`qty_available` = GREATEST(0,
        COALESCE(NEW.`qty_physical`, 0) -
        COALESCE(NEW.`reserved`, 0) -
        COALESCE(NEW.`unsellable`, 0)
    );
END$$
DELIMITER ;

-- 4. Trigger همگام‌سازی برای INSERT
DROP TRIGGER IF EXISTS `trg_inventory_sync_avail_ins`;
DELIMITER $$
CREATE TRIGGER `trg_inventory_sync_avail_ins`
BEFORE INSERT ON `inventory`
FOR EACH ROW
BEGIN
    SET NEW.`qty_available` = GREATEST(0,
        COALESCE(NEW.`qty_physical`, 0) -
        COALESCE(NEW.`reserved`, 0) -
        COALESCE(NEW.`unsellable`, 0)
    );
END$$
DELIMITER ;

-- =====================================================
--  End of 012_inventory_columns.sql
-- =====================================================
