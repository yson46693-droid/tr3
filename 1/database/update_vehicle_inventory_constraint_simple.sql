-- Script: update_vehicle_inventory_constraint_simple.sql
-- Purpose: تحديث قيد UNIQUE في جدول vehicle_inventory ليشمل finished_batch_id
-- 
-- تنفيذ الأوامر التالية بالترتيب:

-- 1. حذف القيد القديم (إذا كان موجوداً - قم بحذف التعليقات من السطر الذي ينطبق)
ALTER TABLE `vehicle_inventory` DROP INDEX IF EXISTS `vehicle_product_unique`;
ALTER TABLE `vehicle_inventory` DROP INDEX IF EXISTS `vehicle_product`;

-- 2. إضافة القيد الجديد الذي يشمل finished_batch_id
ALTER TABLE `vehicle_inventory` 
ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`);

-- ملاحظة: إذا ظهر خطأ بأن القيد موجود بالفعل، يمكن تجاهله

