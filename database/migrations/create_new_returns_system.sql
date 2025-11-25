-- Migration: إنشاء نظام المرتجعات الجديد
-- تاريخ الإنشاء: 2025-01-20
-- الوصف: تحديث جداول المرتجعات وإضافة جدول المرتجعات التالفة

-- تحديث جدول returns ليدعم النظام الجديد
-- ملاحظة: نتحقق من وجود الأعمدة قبل إضافتها لتجنب الأخطاء

-- إضافة عمود batch_number_id إذا لم يكن موجوداً
SET @batch_number_id_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'returns' 
    AND COLUMN_NAME = 'batch_number_id'
);

SET @sql = IF(@batch_number_id_exists = 0,
    'ALTER TABLE `returns` ADD COLUMN `batch_number_id` INT(11) DEFAULT NULL AFTER `invoice_id`',
    'SELECT "Column batch_number_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة عمود condition_type إذا لم يكن موجوداً
SET @condition_type_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'returns' 
    AND COLUMN_NAME = 'condition_type'
);

SET @sql = IF(@condition_type_exists = 0,
    'ALTER TABLE `returns` ADD COLUMN `condition_type` ENUM("normal","damaged","expired") DEFAULT "normal" AFTER `batch_number_id`',
    'SELECT "Column condition_type already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة عمود return_quantity إذا لم يكن موجوداً
SET @return_quantity_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'returns' 
    AND COLUMN_NAME = 'return_quantity'
);

SET @sql = IF(@return_quantity_exists = 0,
    'ALTER TABLE `returns` ADD COLUMN `return_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `condition_type`',
    'SELECT "Column return_quantity already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة فهرس لـ batch_number_id إذا لم يكن موجوداً
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'returns' 
    AND INDEX_NAME = 'idx_batch_number_id'
);

SET @sql = IF(@index_exists = 0,
    'ALTER TABLE `returns` ADD INDEX `idx_batch_number_id` (`batch_number_id`)',
    'SELECT "Index idx_batch_number_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إنشاء جدول المرتجعات التالفة
CREATE TABLE IF NOT EXISTS `damaged_returns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `return_id` INT(11) NOT NULL,
  `return_item_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `batch_number_id` INT(11) DEFAULT NULL,
  `quantity` DECIMAL(10,2) NOT NULL,
  `damage_reason` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_id` (`return_id`),
  KEY `idx_return_item_id` (`return_item_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_batch_number_id` (`batch_number_id`),
  CONSTRAINT `damaged_returns_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `damaged_returns_ibfk_2` FOREIGN KEY (`return_item_id`) REFERENCES `return_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `damaged_returns_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `damaged_returns_ibfk_4` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تحديث جدول return_items لضمان وجود الأعمدة المطلوبة
-- التحقق من وجود invoice_item_id
SET @invoice_item_id_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'return_items' 
    AND COLUMN_NAME = 'invoice_item_id'
);

SET @sql = IF(@invoice_item_id_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `invoice_item_id` INT(11) DEFAULT NULL AFTER `return_id`',
    'SELECT "Column invoice_item_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- التحقق من وجود batch_number_id في return_items
SET @return_items_batch_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'return_items' 
    AND COLUMN_NAME = 'batch_number_id'
);

SET @sql = IF(@return_items_batch_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `batch_number_id` INT(11) DEFAULT NULL AFTER `invoice_item_id`',
    'SELECT "Column batch_number_id already exists in return_items" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- التحقق من وجود batch_number في return_items (نصي للعرض)
SET @return_items_batch_number_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'return_items' 
    AND COLUMN_NAME = 'batch_number'
);

SET @sql = IF(@return_items_batch_number_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `batch_number` VARCHAR(100) DEFAULT NULL AFTER `batch_number_id`',
    'SELECT "Column batch_number already exists in return_items" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة عمود is_damaged في return_items لتسهيل التعرف على المنتجات التالفة
SET @is_damaged_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'return_items' 
    AND COLUMN_NAME = 'is_damaged'
);

SET @sql = IF(@is_damaged_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `is_damaged` TINYINT(1) NOT NULL DEFAULT 0 AFTER `condition`',
    'SELECT "Column is_damaged already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

