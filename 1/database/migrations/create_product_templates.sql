-- جدول قوالب المنتجات
CREATE TABLE IF NOT EXISTS `product_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(255) NOT NULL COMMENT 'اسم القالب',
  `product_id` int(11) NOT NULL COMMENT 'المنتج المرتبط',
  `product_name` varchar(255) DEFAULT NULL COMMENT 'اسم المنتج (نسخة احتياطية)',
  `description` text DEFAULT NULL COMMENT 'وصف القالب',
  `target_quantity` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT 'الكمية المستهدفة (عدد الوحدات)',
  `unit` varchar(50) DEFAULT 'قطعة' COMMENT 'وحدة القياس',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL COMMENT 'منشئ القالب',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `created_by` (`created_by`),
  KEY `status` (`status`),
  CONSTRAINT `product_templates_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_templates_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول المواد الخام لكل قالب
CREATE TABLE IF NOT EXISTS `product_template_materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL COMMENT 'القالب',
  `material_type` enum('honey','other','ingredient') NOT NULL DEFAULT 'other' COMMENT 'نوع المادة',
  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة',
  `material_id` int(11) DEFAULT NULL COMMENT 'معرف المادة (supplier_id للعسل أو product_id للمواد الأخرى)',
  `quantity_per_unit` decimal(10,3) NOT NULL COMMENT 'الكمية لكل وحدة منتج',
  `unit` varchar(50) DEFAULT 'كجم' COMMENT 'وحدة القياس',
  `is_required` tinyint(1) DEFAULT 1 COMMENT 'هل المادة مطلوبة',
  `notes` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0 COMMENT 'ترتيب العرض',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `material_type` (`material_type`),
  CONSTRAINT `product_template_materials_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول أدوات التعبئة لكل قالب
CREATE TABLE IF NOT EXISTS `product_template_packaging` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL COMMENT 'القالب',
  `packaging_material_id` int(11) DEFAULT NULL COMMENT 'معرف أداة التعبئة (من جدول packaging_materials أو products)',
  `packaging_name` varchar(255) NOT NULL COMMENT 'اسم أداة التعبئة',
  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.00 COMMENT 'الكمية لكل وحدة منتج',
  `unit` varchar(50) DEFAULT 'قطعة' COMMENT 'وحدة القياس',
  `is_required` tinyint(1) DEFAULT 1 COMMENT 'هل أداة التعبئة مطلوبة',
  `notes` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0 COMMENT 'ترتيب العرض',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `packaging_material_id` (`packaging_material_id`),
  CONSTRAINT `product_template_packaging_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ربط خطوط الإنتاج بالقوالب
ALTER TABLE `production_lines` 
ADD COLUMN IF NOT EXISTS `template_id` int(11) DEFAULT NULL COMMENT 'قالب المنتج',
ADD KEY IF NOT EXISTS `template_id` (`template_id`),
ADD CONSTRAINT IF NOT EXISTS `production_lines_ibfk_template` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE SET NULL;

