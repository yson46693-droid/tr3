-- إضافة أعلام تخص العملاء للشركة والمندوبين
-- يجب تشغيل هذا الملف بعد schema.sql وأي ترقية سابقة لجدول customers

ALTER TABLE `customers`
ADD COLUMN IF NOT EXISTS `rep_id` INT NULL AFTER `id`,
ADD COLUMN IF NOT EXISTS `created_from_pos` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rep_id`,
ADD COLUMN IF NOT EXISTS `created_by_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_from_pos`,
ADD KEY IF NOT EXISTS `rep_id` (`rep_id`);

UPDATE customers c
INNER JOIN users u ON c.rep_id IS NULL AND c.created_by = u.id AND u.role = 'sales'
SET c.rep_id = u.id;

SET @fk_name := 'customers_ibfk_rep';
SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = @fk_name
);
SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `customers` ADD CONSTRAINT `customers_ibfk_rep` FOREIGN KEY (`rep_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;',
    'SELECT 1'
);
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

