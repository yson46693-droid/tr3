-- نظام تتبع مخازن سيارات المندوبين
-- يجب تشغيل هذا الملف بعد schema.sql الأساسي

-- جدول السيارات
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_number` varchar(50) NOT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` year(4) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL COMMENT 'مندوب المبيعات صاحب السيارة',
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vehicle_number` (`vehicle_number`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول مخازن السيارات (warehouse_type = 'vehicle')
-- سيتم استخدام جدول warehouses الموجود مع إضافة type

-- جدول حركات النقل بين المخازن
CREATE TABLE IF NOT EXISTS `warehouse_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(50) NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `transfer_type` enum('to_vehicle','from_vehicle','between_warehouses') DEFAULT 'to_vehicle',
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed','cancelled') DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_number` (`transfer_number`),
  KEY `from_warehouse_id` (`from_warehouse_id`),
  KEY `to_warehouse_id` (`to_warehouse_id`),
  KEY `transfer_date` (`transfer_date`),
  KEY `status` (`status`),
  KEY `requested_by` (`requested_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `warehouse_transfers_ibfk_1` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `warehouse_transfers_ibfk_2` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `warehouse_transfers_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `warehouse_transfers_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول عناصر نقل المخازن
CREATE TABLE IF NOT EXISTS `warehouse_transfer_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `warehouse_transfer_items_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `warehouse_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `warehouse_transfer_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول مخزون السيارات (مخزون كل منتج في كل سيارة)
CREATE TABLE IF NOT EXISTS `vehicle_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL COMMENT 'مخزن السيارة',
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `product_category` varchar(100) DEFAULT NULL,
  `product_unit` varchar(50) DEFAULT NULL,
  `product_unit_price` decimal(15,2) DEFAULT NULL,
  `product_snapshot` longtext DEFAULT NULL,
  `manager_unit_price` decimal(15,2) DEFAULT NULL,
  `finished_batch_id` int(11) DEFAULT NULL,
  `finished_batch_number` varchar(100) DEFAULT NULL,
  `finished_production_date` date DEFAULT NULL,
  `finished_quantity_produced` decimal(12,2) DEFAULT NULL,
  `finished_workers` text DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_updated_by` int(11) DEFAULT NULL,
  `last_updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vehicle_product` (`vehicle_id`, `product_id`),
  KEY `finished_batch_id` (`finished_batch_id`),
  KEY `finished_batch_number` (`finished_batch_number`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `product_id` (`product_id`),
  KEY `last_updated_by` (`last_updated_by`),
  CONSTRAINT `vehicle_inventory_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_inventory_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_inventory_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_inventory_ibfk_4` FOREIGN KEY (`last_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تحديث جدول warehouses لإضافة نوع المخزن
ALTER TABLE `warehouses` 
ADD COLUMN IF NOT EXISTS `warehouse_type` enum('main','vehicle','branch','other') DEFAULT 'main' AFTER `name`,
ADD COLUMN IF NOT EXISTS `vehicle_id` int(11) DEFAULT NULL AFTER `warehouse_type`,
ADD COLUMN IF NOT EXISTS `manager_id` int(11) DEFAULT NULL AFTER `vehicle_id`;

ALTER TABLE `warehouses`
ADD KEY `vehicle_id` (`vehicle_id`),
ADD KEY `manager_id` (`manager_id`),
ADD CONSTRAINT `warehouses_ibfk_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
ADD CONSTRAINT `warehouses_ibfk_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- تحديث جدول inventory_movements لإضافة warehouse_id إذا لم يكن موجوداً
ALTER TABLE `inventory_movements` 
ADD COLUMN IF NOT EXISTS `from_warehouse_id` int(11) DEFAULT NULL AFTER `warehouse_id`,
ADD COLUMN IF NOT EXISTS `to_warehouse_id` int(11) DEFAULT NULL AFTER `from_warehouse_id`,
ADD COLUMN IF NOT EXISTS `transfer_id` int(11) DEFAULT NULL AFTER `to_warehouse_id`;

ALTER TABLE `inventory_movements`
ADD KEY `from_warehouse_id` (`from_warehouse_id`),
ADD KEY `to_warehouse_id` (`to_warehouse_id`),
ADD KEY `transfer_id` (`transfer_id`),
ADD CONSTRAINT `inventory_movements_ibfk_from_wh` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
ADD CONSTRAINT `inventory_movements_ibfk_to_wh` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
ADD CONSTRAINT `inventory_movements_ibfk_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `warehouse_transfers` (`id`) ON DELETE SET NULL;

