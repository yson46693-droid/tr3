-- إضافة جدول تسجيلات الحضور والانصراف المتعددة
-- يسمح بتسجيلات متعددة في اليوم الواحد

CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `check_in_time` datetime NOT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `delay_minutes` int(11) DEFAULT 0,
  `work_hours` decimal(5,2) DEFAULT 0.00,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `date` (`date`),
  KEY `user_date` (`user_id`, `date`),
  KEY `check_in_time` (`check_in_time`),
  CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إضافة عمود photo_path إلى جدول attendance القديم (إن وجد)
-- ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `photo_path` VARCHAR(255) DEFAULT NULL AFTER `notes`;

