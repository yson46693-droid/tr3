-- ============================================================
-- ملف إصلاح الأعمدة (للاستخدام في حالة وجود أخطاء)
-- ============================================================

-- إضافة عمود warehouse_id إلى جدول products
-- (يمكنك تشغيل هذا مباشرة إذا كان العمود غير موجود)
ALTER TABLE `products` 
ADD COLUMN `warehouse_id` int(11) DEFAULT NULL AFTER `category`;

-- إضافة مفتاح للعمود
ALTER TABLE `products` 
ADD KEY `warehouse_id` (`warehouse_id`);

-- إضافة Foreign Key (إذا لم يكن موجوداً)
-- ملاحظة: تأكد من وجود جدول warehouses أولاً
ALTER TABLE `products` 
ADD CONSTRAINT `products_ibfk_warehouse` 
FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL;

-- ============================================================

-- إضافة عمود customer_order_item_id إلى جدول production
ALTER TABLE `production` 
ADD COLUMN `customer_order_item_id` int(11) DEFAULT NULL AFTER `product_id`;

-- إضافة مفتاح للعمود
ALTER TABLE `production` 
ADD KEY `customer_order_item_id` (`customer_order_item_id`);

-- إضافة Foreign Key (إذا لم يكن موجوداً)
-- ملاحظة: تأكد من وجود جدول customer_order_items أولاً
ALTER TABLE `production` 
ADD CONSTRAINT `production_ibfk_order_item` 
FOREIGN KEY (`customer_order_item_id`) REFERENCES `customer_order_items` (`id`) ON DELETE SET NULL;

-- ============================================================
-- نهاية الملف
-- ============================================================

