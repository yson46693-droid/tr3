-- ============================================
-- تحديث قيد UNIQUE في جدول vehicle_inventory
-- ============================================
-- هذا السكريبت يحدث القيد UNIQUE ليشمل finished_batch_id
-- للسماح بمنتجات من نفس النوع برقم تشغيلة مختلف
--
-- يمكن تشغيله من:
-- 1. phpMyAdmin (SQL tab)
-- 2. MySQL command line: mysql -u root -p tr < database/final_update.sql
-- 3. MySQL Workbench
-- ============================================

-- التحقق من وجود الجدول
SELECT 'Checking table...' AS status;
SHOW TABLES LIKE 'vehicle_inventory';

-- حذف القيد القديم (إذا كان موجوداً)
ALTER TABLE `vehicle_inventory` DROP INDEX IF EXISTS `vehicle_product_unique`;
ALTER TABLE `vehicle_inventory` DROP INDEX IF EXISTS `vehicle_product`;

-- إضافة القيد الجديد
ALTER TABLE `vehicle_inventory` 
ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`);

-- التحقق من القيد الجديد
SELECT 'Verifying constraint...' AS status;
SHOW INDEXES FROM `vehicle_inventory` WHERE Key_name = 'vehicle_product_batch_unique';

SELECT 'تم التحديث بنجاح!' AS status;

