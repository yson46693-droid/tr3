-- Migration: حذف عمود البريد الإلكتروني من جدول العملاء
-- التاريخ: 2024

-- التحقق من وجود العمود قبل حذفه
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customers' 
    AND COLUMN_NAME = 'email'
);

-- حذف العمود إذا كان موجوداً
SET @sql = IF(@column_exists > 0, 
    'ALTER TABLE `customers` DROP COLUMN `email`', 
    'SELECT "Column email does not exist in customers table" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

