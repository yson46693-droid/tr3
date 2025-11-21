-- تحسينات الأمان والتصاريح
-- يجب تشغيل هذا الملف بعد schema.sql الأساسي

-- جدول تسجيل محاولات تسجيل الدخول
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `failure_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ip_address` (`ip_address`),
  KEY `username` (`username`),
  KEY `created_at` (`created_at`),
  KEY `success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول IP المحظورة
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `blocked_until` timestamp NULL DEFAULT NULL,
  `blocked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `blocked_until` (`blocked_until`),
  KEY `blocked_by` (`blocked_by`),
  CONSTRAINT `blocked_ips_ibfk_1` FOREIGN KEY (`blocked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول الصلاحيات
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول صلاحيات الأدوار
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` enum('manager','accountant','sales','production') NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permission` (`role`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول الصلاحيات المخصصة للمستخدمين
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted` tinyint(1) DEFAULT 1,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_permission` (`user_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  KEY `granted_by` (`granted_by`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول إعدادات الإشعارات حسب الدور
CREATE TABLE IF NOT EXISTS `notification_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `notification_type` varchar(50) NOT NULL,
  `telegram_enabled` tinyint(1) DEFAULT 0,
  `email_enabled` tinyint(1) DEFAULT 1,
  `browser_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `role` (`role`),
  KEY `notification_type` (`notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إدراج الصلاحيات الأساسية
INSERT INTO `permissions` (`name`, `description`, `category`) VALUES
('view_dashboard', 'عرض لوحة التحكم', 'dashboard'),
('view_financial', 'عرض البيانات المالية', 'financial'),
('create_financial', 'إنشاء معاملات مالية', 'financial'),
('approve_financial', 'الموافقة على المعاملات المالية', 'financial'),
('view_sales', 'عرض المبيعات', 'sales'),
('create_sales', 'إنشاء مبيعات', 'sales'),
('approve_sales', 'الموافقة على المبيعات', 'sales'),
('view_production', 'عرض الإنتاج', 'production'),
('create_production', 'تسجيل الإنتاج', 'production'),
('approve_production', 'الموافقة على الإنتاج', 'production'),
('view_inventory', 'عرض المخزون', 'inventory'),
('manage_inventory', 'إدارة المخزون', 'inventory'),
('view_reports', 'عرض التقارير', 'reports'),
('generate_reports', 'إنشاء التقارير', 'reports'),
('view_users', 'عرض المستخدمين', 'users'),
('manage_users', 'إدارة المستخدمين', 'users'),
('view_audit', 'عرض سجل التدقيق', 'audit'),
('manage_settings', 'إدارة إعدادات النظام', 'settings')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- إعطاء صلاحيات كاملة للمدير
INSERT INTO `role_permissions` (`role`, `permission_id`)
SELECT 'manager', `id` FROM `permissions`
ON DUPLICATE KEY UPDATE `role` = `role`;

-- إعطاء صلاحيات للمحاسب
INSERT INTO `role_permissions` (`role`, `permission_id`)
SELECT 'accountant', `id` FROM `permissions`
WHERE `category` IN ('dashboard', 'financial', 'inventory', 'reports', 'users')
ON DUPLICATE KEY UPDATE `role` = `role`;

-- إعطاء صلاحيات لمندوب المبيعات
INSERT INTO `role_permissions` (`role`, `permission_id`)
SELECT 'sales', `id` FROM `permissions`
WHERE `category` IN ('dashboard', 'sales', 'reports')
ON DUPLICATE KEY UPDATE `role` = `role`;

-- إعطاء صلاحيات لعامل الإنتاج
INSERT INTO `role_permissions` (`role`, `permission_id`)
SELECT 'production', `id` FROM `permissions`
WHERE `category` IN ('dashboard', 'production', 'inventory')
ON DUPLICATE KEY UPDATE `role` = `role`;

