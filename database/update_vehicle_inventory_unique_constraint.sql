-- ============================================
-- تحديث قيد UNIQUE في جدول vehicle_inventory
-- ============================================
-- هذا السكريبت يحدث القيد UNIQUE ليشمل finished_batch_id
-- للسماح بمنتجات من نفس النوع برقم تشغيلة مختلف
--
-- كيفية الاستخدام:
-- 1. افتح phpMyAdmin
-- 2. اختر قاعدة البيانات (tr)
-- 3. اذهب إلى تبويب SQL
-- 4. انسخ والصق محتوى هذا الملف
-- 5. اضغط على "تنفيذ" (Go)
-- ============================================

-- خطوة 1: حذف القيد القديم (إذا كان موجوداً)
-- قم بإزالة التعليقات من السطر الذي ينطبق على حالتك:

ALTER TABLE `vehicle_inventory` DROP INDEX IF EXISTS `vehicle_product_unique`;
ALTER TABLE `vehicle_inventory` DROP INDEX IF EXISTS `vehicle_product`;

-- ملاحظة: إذا لم يكن IF EXISTS مدعوماً، استخدم:
-- ALTER TABLE `vehicle_inventory` DROP INDEX `vehicle_product_unique`;
-- أو
-- ALTER TABLE `vehicle_inventory` DROP INDEX `vehicle_product`;

-- خطوة 2: إضافة القيد الجديد الذي يشمل finished_batch_id
ALTER TABLE `vehicle_inventory` 
ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`);

-- التحقق من النتيجة
SHOW INDEXES FROM `vehicle_inventory` WHERE Key_name = 'vehicle_product_batch_unique';

SELECT 'تم تحديث القيد بنجاح!' AS status;
