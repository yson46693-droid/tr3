-- Migration: Add invoice_item_id column to return_items table
-- This migration adds the invoice_item_id column if it doesn't exist

-- Check if column exists and add it if not
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND COLUMN_NAME = 'invoice_item_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `invoice_item_id` int(11) DEFAULT NULL AFTER `sale_item_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if column was added
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND COLUMN_NAME = 'invoice_item_id'
);

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND INDEX_NAME = 'idx_invoice_item_id'
);

SET @sql = IF(@col_exists > 0 AND @idx_exists = 0,
    'ALTER TABLE `return_items` ADD INDEX `idx_invoice_item_id` (`invoice_item_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key if column exists and FK doesn't exist
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND COLUMN_NAME = 'invoice_item_id'
);

SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND CONSTRAINT_NAME = 'return_items_ibfk_invoice_item'
);

SET @sql = IF(@col_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE `return_items` ADD CONSTRAINT `return_items_ibfk_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

