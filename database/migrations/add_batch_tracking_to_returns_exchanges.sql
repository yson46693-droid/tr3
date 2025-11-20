-- Migration: Add batch number tracking to returns and exchanges
-- This migration adds batch_number fields to return_items and exchange item tables

-- Add batch_number tracking to return_items table
ALTER TABLE `return_items`
ADD COLUMN IF NOT EXISTS `invoice_item_id` int(11) DEFAULT NULL AFTER `sale_item_id`,
ADD COLUMN IF NOT EXISTS `batch_number_id` int(11) DEFAULT NULL AFTER `invoice_item_id`,
ADD COLUMN IF NOT EXISTS `batch_number` varchar(100) DEFAULT NULL AFTER `batch_number_id`,
ADD INDEX IF NOT EXISTS `idx_batch_number_id` (`batch_number_id`),
ADD INDEX IF NOT EXISTS `idx_invoice_item_id` (`invoice_item_id`);

-- Add foreign key for batch_number_id if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND CONSTRAINT_NAME = 'return_items_ibfk_batch'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `return_items` ADD CONSTRAINT `return_items_ibfk_batch` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for invoice_item_id if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'return_items'
    AND CONSTRAINT_NAME = 'return_items_ibfk_invoice_item'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `return_items` ADD CONSTRAINT `return_items_ibfk_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add batch_number tracking to exchange_return_items table
ALTER TABLE `exchange_return_items`
ADD COLUMN IF NOT EXISTS `invoice_item_id` int(11) DEFAULT NULL AFTER `exchange_id`,
ADD COLUMN IF NOT EXISTS `batch_number_id` int(11) DEFAULT NULL AFTER `invoice_item_id`,
ADD COLUMN IF NOT EXISTS `batch_number` varchar(100) DEFAULT NULL AFTER `batch_number_id`,
ADD INDEX IF NOT EXISTS `idx_batch_number_id` (`batch_number_id`),
ADD INDEX IF NOT EXISTS `idx_invoice_item_id` (`invoice_item_id`);

-- Add foreign key for batch_number_id if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'exchange_return_items'
    AND CONSTRAINT_NAME = 'exchange_return_items_ibfk_batch'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `exchange_return_items` ADD CONSTRAINT `exchange_return_items_ibfk_batch` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for invoice_item_id if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'exchange_return_items'
    AND CONSTRAINT_NAME = 'exchange_return_items_ibfk_invoice_item'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `exchange_return_items` ADD CONSTRAINT `exchange_return_items_ibfk_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add batch_number tracking to exchange_new_items table
ALTER TABLE `exchange_new_items`
ADD COLUMN IF NOT EXISTS `batch_number_id` int(11) DEFAULT NULL AFTER `product_id`,
ADD COLUMN IF NOT EXISTS `batch_number` varchar(100) DEFAULT NULL AFTER `batch_number_id`,
ADD INDEX IF NOT EXISTS `idx_batch_number_id` (`batch_number_id`);

-- Add foreign key for batch_number_id if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'exchange_new_items'
    AND CONSTRAINT_NAME = 'exchange_new_items_ibfk_batch'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `exchange_new_items` ADD CONSTRAINT `exchange_new_items_ibfk_batch` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update exchanges table to link to invoices instead of just sales
ALTER TABLE `exchanges`
ADD COLUMN IF NOT EXISTS `invoice_id` int(11) DEFAULT NULL AFTER `original_sale_id`,
ADD INDEX IF NOT EXISTS `idx_invoice_id` (`invoice_id`);

-- Add foreign key for invoice_id if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'exchanges'
    AND CONSTRAINT_NAME = 'exchanges_ibfk_invoice'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `exchanges` ADD CONSTRAINT `exchanges_ibfk_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

