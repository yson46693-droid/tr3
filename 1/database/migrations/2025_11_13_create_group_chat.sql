-- مجموعة دردشة جماعية مشابهة لـ Signal
-- يعتمد على جدول users الموجود مسبقاً

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `message_text` LONGTEXT NOT NULL,
  `reply_to` INT DEFAULT NULL,
  `edited` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `read_by_count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `messages_user_id_idx` (`user_id`),
  KEY `messages_reply_to_idx` (`reply_to`),
  CONSTRAINT `messages_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_reply_fk` FOREIGN KEY (`reply_to`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_status` (
  `user_id` INT NOT NULL,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_online` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_status_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_reads` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `message_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_reads_unique` (`message_id`, `user_id`),
  KEY `message_reads_user_idx` (`user_id`),
  CONSTRAINT `message_reads_message_fk` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_reads_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `messages_created_at_idx` ON `messages` (`created_at`);

-- Trigger لضمان تحديث حقل updated_at عند تعديل الرسالة
DROP TRIGGER IF EXISTS `messages_before_update`;
DELIMITER $$
CREATE TRIGGER `messages_before_update`
BEFORE UPDATE ON `messages`
FOR EACH ROW
BEGIN
  SET NEW.`updated_at` = CURRENT_TIMESTAMP;
END$$
DELIMITER ;

