-- نظام رقم التشغيلة مع الباركود
-- يجب تشغيل هذا الملف بعد schema.sql الأساسي

-- جدول أرقام التشغيلة
CREATE TABLE IF NOT EXISTS `batch_numbers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_number` varchar(100) NOT NULL,
  `product_id` int(11) NOT NULL,
  `production_id` int(11) DEFAULT NULL,
  `production_date` date NOT NULL,
  `honey_supplier_id` int(11) DEFAULT NULL,
  `packaging_materials` text DEFAULT NULL COMMENT 'JSON array of packaging material IDs',
  `packaging_supplier_id` int(11) DEFAULT NULL,
  `workers` text DEFAULT NULL COMMENT 'JSON array of worker IDs',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `status` enum('in_production','completed','in_stock','sold','expired') DEFAULT 'in_production',
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_number` (`batch_number`),
  KEY `product_id` (`product_id`),
  KEY `production_id` (`production_id`),
  KEY `production_date` (`production_date`),
  KEY `honey_supplier_id` (`honey_supplier_id`),
  KEY `packaging_supplier_id` (`packaging_supplier_id`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `batch_numbers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `batch_numbers_ibfk_2` FOREIGN KEY (`production_id`) REFERENCES `production` (`id`) ON DELETE SET NULL,
  CONSTRAINT `batch_numbers_ibfk_3` FOREIGN KEY (`honey_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `batch_numbers_ibfk_4` FOREIGN KEY (`packaging_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `batch_numbers_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول ربط المنتجات المباعة بأرقام التشغيلة
CREATE TABLE IF NOT EXISTS `sales_batch_numbers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `batch_number_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `batch_number_id` (`batch_number_id`),
  CONSTRAINT `sales_batch_numbers_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_batch_numbers_ibfk_2` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول سجل فحص الباركود
CREATE TABLE IF NOT EXISTS `barcode_scans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_number_id` int(11) NOT NULL,
  `scanned_by` int(11) DEFAULT NULL,
  `scan_location` varchar(255) DEFAULT NULL,
  `scan_type` enum('production','inventory','sale','verification') DEFAULT 'verification',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `batch_number_id` (`batch_number_id`),
  KEY `scanned_by` (`scanned_by`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `barcode_scans_ibfk_1` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `barcode_scans_ibfk_2` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

