-- نظام البيع بالأجل والتحصيلات والمرتجعات والاستبدال
-- يجب تشغيل هذا الملف بعد schema.sql الأساسي

-- جدول الجداول الزمنية للتحصيل
CREATE TABLE IF NOT EXISTS `payment_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `due_date` date NOT NULL,
  `payment_date` date DEFAULT NULL,
  `status` enum('pending','paid','overdue','cancelled') DEFAULT 'pending',
  `installment_number` int(11) DEFAULT 1,
  `total_installments` int(11) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `customer_id` (`customer_id`),
  KEY `sales_rep_id` (`sales_rep_id`),
  KEY `due_date` (`due_date`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `payment_schedules_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_schedules_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_schedules_ibfk_3` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_schedules_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول تذكيرات التحصيل
CREATE TABLE IF NOT EXISTS `payment_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_schedule_id` int(11) NOT NULL,
  `reminder_type` enum('before_due','on_due','after_due','custom') DEFAULT 'before_due',
  `reminder_date` date NOT NULL,
  `days_before_due` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `sent_to` enum('sales_rep','customer','both') DEFAULT 'sales_rep',
  `sent_status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `sent_method` enum('notification','telegram','sms','email') DEFAULT 'notification',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `payment_schedule_id` (`payment_schedule_id`),
  KEY `reminder_date` (`reminder_date`),
  KEY `sent_status` (`sent_status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `payment_reminders_ibfk_1` FOREIGN KEY (`payment_schedule_id`) REFERENCES `payment_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_reminders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول المرتجعات
CREATE TABLE IF NOT EXISTS `returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `return_type` enum('full','partial') DEFAULT 'full',
  `reason` enum('defective','wrong_item','customer_request','other') DEFAULT 'customer_request',
  `reason_description` text DEFAULT NULL,
  `refund_amount` decimal(15,2) DEFAULT 0.00,
  `refund_method` enum('cash','credit','exchange') DEFAULT 'cash',
  `status` enum('pending','approved','rejected','processed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `sale_id` (`sale_id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `customer_id` (`customer_id`),
  KEY `sales_rep_id` (`sales_rep_id`),
  KEY `return_date` (`return_date`),
  KEY `status` (`status`),
  KEY `approved_by` (`approved_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `returns_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `returns_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `returns_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `returns_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `returns_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول عناصر المرتجعات
CREATE TABLE IF NOT EXISTS `return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `sale_item_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `condition` enum('new','used','damaged','defective') DEFAULT 'new',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `return_id` (`return_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `return_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول الاستبدال
CREATE TABLE IF NOT EXISTS `exchanges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_number` varchar(50) NOT NULL,
  `return_id` int(11) DEFAULT NULL,
  `original_sale_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) DEFAULT NULL,
  `exchange_date` date NOT NULL,
  `exchange_type` enum('same_product','different_product','upgrade','downgrade') DEFAULT 'same_product',
  `reason` text DEFAULT NULL,
  `original_total` decimal(15,2) NOT NULL,
  `new_total` decimal(15,2) NOT NULL,
  `difference_amount` decimal(15,2) DEFAULT 0.00,
  `difference_paid` tinyint(1) DEFAULT 0,
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_number` (`exchange_number`),
  KEY `return_id` (`return_id`),
  KEY `original_sale_id` (`original_sale_id`),
  KEY `customer_id` (`customer_id`),
  KEY `sales_rep_id` (`sales_rep_id`),
  KEY `exchange_date` (`exchange_date`),
  KEY `status` (`status`),
  KEY `approved_by` (`approved_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `exchanges_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exchanges_ibfk_2` FOREIGN KEY (`original_sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exchanges_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exchanges_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exchanges_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exchanges_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول عناصر الاستبدال (المنتجات المرتجعة)
CREATE TABLE IF NOT EXISTS `exchange_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `exchange_id` (`exchange_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `exchange_return_items_ibfk_1` FOREIGN KEY (`exchange_id`) REFERENCES `exchanges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exchange_return_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول عناصر الاستبدال (المنتجات الجديدة)
CREATE TABLE IF NOT EXISTS `exchange_new_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `exchange_id` (`exchange_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `exchange_new_items_ibfk_1` FOREIGN KEY (`exchange_id`) REFERENCES `exchanges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exchange_new_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تحديث جدول المبيعات لإضافة حقل نوع الدفع
ALTER TABLE `sales` 
ADD COLUMN IF NOT EXISTS `payment_type` enum('cash','credit','installment') DEFAULT 'cash' AFTER `status`,
ADD COLUMN IF NOT EXISTS `total_amount` decimal(15,2) DEFAULT 0.00 AFTER `total`,
ADD COLUMN IF NOT EXISTS `paid_amount` decimal(15,2) DEFAULT 0.00 AFTER `total_amount`,
ADD COLUMN IF NOT EXISTS `remaining_amount` decimal(15,2) DEFAULT 0.00 AFTER `paid_amount`;

-- تحديث جدول الفواتير لإضافة حالة الدفع
ALTER TABLE `invoices` 
ADD COLUMN IF NOT EXISTS `payment_type` enum('cash','credit','installment') DEFAULT 'cash' AFTER `status`;

