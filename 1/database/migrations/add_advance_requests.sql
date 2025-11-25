-- جدول طلبات السلفة
CREATE TABLE IF NOT EXISTS `advance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `requested_month` int(2) NOT NULL,
  `requested_year` int(4) NOT NULL,
  `salary_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `salary_id` (`salary_id`),
  KEY `approved_by` (`approved_by`),
  KEY `status` (`status`),
  KEY `requested_month_year` (`requested_month`, `requested_year`),
  CONSTRAINT `advance_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `advance_requests_ibfk_2` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `advance_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

