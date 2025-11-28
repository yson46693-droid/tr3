-- ============================================================
-- جدول المعاملات المحاسبية (تحصيل من مندوب، مصروفات، وغيرها)
-- ============================================================
-- تاريخ الإنشاء: 2025-11-28
-- الوصف: جدول شامل لتسجيل جميع المعاملات المحاسبية
-- ============================================================

CREATE TABLE IF NOT EXISTS `accountant_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','other') NOT NULL COMMENT 'نوع المعاملة',
  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ',
  `sales_rep_id` int(11) DEFAULT NULL COMMENT 'معرف المندوب (للتحصيل)',
  `description` text NOT NULL COMMENT 'الوصف',
  `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
  `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
  `status` enum('pending','approved','rejected') DEFAULT 'approved' COMMENT 'الحالة',
  `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق',
  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
  `created_by` int(11) NOT NULL COMMENT 'من أنشأ السجل',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  KEY `transaction_type` (`transaction_type`),
  KEY `sales_rep_id` (`sales_rep_id`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `created_at` (`created_at`),
  KEY `reference_number` (`reference_number`),
  CONSTRAINT `accountant_transactions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `accountant_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accountant_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المعاملات المحاسبية';

