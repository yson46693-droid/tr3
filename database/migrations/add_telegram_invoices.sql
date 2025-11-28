-- جدول لتخزين الفواتير المرسلة إلى Telegram
-- هذا الجدول يخزن جميع الفواتير المرسلة إلى Telegram للوصول إليها لاحقاً
-- يمكن تشغيل هذا الملف يدوياً أو سيتم إنشاء الجدول تلقائياً عند الحاجة
CREATE TABLE IF NOT EXISTS `telegram_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) DEFAULT NULL COMMENT 'معرف الفاتورة من جدول invoices',
  `invoice_number` varchar(100) DEFAULT NULL COMMENT 'رقم الفاتورة',
  `invoice_type` varchar(50) DEFAULT 'sales_pos_invoice' COMMENT 'نوع الفاتورة (sales_pos_invoice, manager_pos_invoice, etc.)',
  `token` varchar(32) NOT NULL COMMENT 'معرف أمني للوصول للفاتورة',
  `html_content` longtext NOT NULL COMMENT 'محتوى HTML للفاتورة',
  `relative_path` varchar(255) DEFAULT NULL COMMENT 'مسار الملف النسبي (إن وجد)',
  `filename` varchar(255) DEFAULT NULL COMMENT 'اسم الملف',
  `summary` text DEFAULT NULL COMMENT 'ملخص الفاتورة (JSON)',
  `telegram_sent` tinyint(1) DEFAULT 0 COMMENT 'تم الإرسال إلى Telegram',
  `sent_at` datetime DEFAULT NULL COMMENT 'تاريخ الإرسال إلى Telegram',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `invoice_id` (`invoice_id`),
  KEY `invoice_number` (`invoice_number`),
  KEY `invoice_type` (`invoice_type`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `telegram_invoices_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تخزين الفواتير المرسلة إلى Telegram';

