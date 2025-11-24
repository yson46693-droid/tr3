-- ============================================
-- تنفيذ هذا السكريبت في phpMyAdmin لتحديث القيد
-- ============================================
-- 
-- الخطوات:
-- 1. افتح phpMyAdmin: http://localhost/phpmyadmin
-- 2. اختر قاعدة البيانات (tr)
-- 3. اضغط على تبويب "SQL" في الأعلى
-- 4. انسخ والصق الأوامر أدناه
-- 5. اضغط "تنفيذ" (Go)
--
-- ============================================

-- حذف القيد القديم
ALTER TABLE `vehicle_inventory` DROP INDEX `vehicle_product_unique`;
ALTER TABLE `vehicle_inventory` DROP INDEX `vehicle_product`;

-- إضافة القيد الجديد
ALTER TABLE `vehicle_inventory` 
ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`);

-- النتيجة: الآن يمكن إضافة منتجات من نفس النوع برقم تشغيلة مختلف!

