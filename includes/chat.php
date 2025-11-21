<?php
/**
 * وظائف المحادثة الجماعية المشابهة لتطبيق Signal
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * التأكد من تهيئة جداول ومتطلبات الدردشة تلقائياً لمرة واحدة
 */
function ensureChatSchema(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $initialized = true;

    try {
        $db = db();
        $connection = getDB();

        $messagesTable = $db->queryOne("SHOW TABLES LIKE 'messages'");
        if (!$messagesTable) {
            if (!$connection->query("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ")) {
                throw new RuntimeException('Failed creating messages table: ' . $connection->error);
            }
        }

        $readByColumn = $db->queryOne("SHOW COLUMNS FROM `messages` LIKE 'read_by_count'");
        if (!$readByColumn) {
            if (!$connection->query("ALTER TABLE `messages` ADD COLUMN `read_by_count` INT NOT NULL DEFAULT 0 AFTER `updated_at`")) {
                throw new RuntimeException('Failed adding read_by_count column: ' . $connection->error);
            }
        }

        $userStatusTable = $db->queryOne("SHOW TABLES LIKE 'user_status'");
        if (!$userStatusTable) {
            if (!$connection->query("
                CREATE TABLE IF NOT EXISTS `user_status` (
                  `user_id` INT NOT NULL,
                  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `is_online` TINYINT(1) NOT NULL DEFAULT 0,
                  PRIMARY KEY (`user_id`),
                  CONSTRAINT `user_status_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ")) {
                throw new RuntimeException('Failed creating user_status table: ' . $connection->error);
            }
        }

        $messageReadsTable = $db->queryOne("SHOW TABLES LIKE 'message_reads'");
        if (!$messageReadsTable) {
            if (!$connection->query("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ")) {
                throw new RuntimeException('Failed creating message_reads table: ' . $connection->error);
            }
        }

        // محاولة إنشاء trigger لتحديث updated_at (اختياري - قد لا يكون المستخدم لديه صلاحيات)
        // إذا فشل، لا بأس لأن الجدول يستخدم ON UPDATE CURRENT_TIMESTAMP بالفعل
        try {
            $connection->query("DROP TRIGGER IF EXISTS `messages_before_update`");
            $triggerResult = $connection->query("
                CREATE TRIGGER `messages_before_update`
                BEFORE UPDATE ON `messages`
                FOR EACH ROW
                BEGIN
                    SET NEW.`updated_at` = CURRENT_TIMESTAMP;
                END
            ");
            if (!$triggerResult) {
                // إذا فشل إنشاء trigger، لا بأس - الجدول يستخدم ON UPDATE CURRENT_TIMESTAMP
                error_log('Note: Could not create trigger messages_before_update (may require TRIGGER permission). Table uses ON UPDATE CURRENT_TIMESTAMP instead.');
            }
        } catch (Throwable $triggerError) {
            // إذا فشل إنشاء trigger، لا بأس - الجدول يستخدم ON UPDATE CURRENT_TIMESTAMP
            error_log('Note: Could not create trigger messages_before_update: ' . $triggerError->getMessage() . ' (Table uses ON UPDATE CURRENT_TIMESTAMP instead)');
        }
    } catch (Throwable $e) {
        error_log('Chat schema initialization failed: ' . $e->getMessage());
    }
}

ensureChatSchema();

/**
 * إرسال رسالة جديدة إلى غرفة الدردشة
 */
function sendChatMessage(int $userId, string $messageText, ?int $replyTo = null): array {
    $db = db();

    $cleanMessage = trim($messageText);

    if ($cleanMessage === '') {
        throw new InvalidArgumentException('Message text is required');
    }

    $db->beginTransaction();

    try {
        $result = $db->execute(
            "INSERT INTO messages (user_id, message_text, reply_to)
             VALUES (?, ?, ?)",
            [
                $userId,
                $cleanMessage,
                $replyTo ?: null,
            ]
        );

        $messageId = (int) $result['insert_id'];

        if ($replyTo) {
            $db->execute(
                "UPDATE messages SET read_by_count = read_by_count WHERE id = ?",
                [$replyTo]
            );
        }

        $db->commit();

        return getChatMessageById($messageId, $userId);
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * جلب رسائل الغرفة
 */
function getChatMessages(?string $since = null, int $limit = 50, ?int $currentUserId = null): array {
    $db = db();

    $params = [];
    $where = '';

    if ($since !== null) {
        $where = 'WHERE m.created_at > ?';
        $params[] = $since;
    }

    $sql = "
        SELECT 
            m.id,
            m.user_id,
            u.full_name AS user_name,
            u.profile_photo,
            u.role,
            m.message_text,
            m.reply_to,
            m.edited,
            m.deleted,
            m.created_at,
            m.updated_at,
            m.read_by_count,
            s.is_online,
            s.last_seen,
            reply.message_text AS reply_text,
            reply.user_id AS reply_user_id,
            replyUser.full_name AS reply_user_name,
            CASE 
                WHEN mr.user_id IS NULL THEN 0
                ELSE 1
            END AS is_read_by_current
        FROM messages m
        INNER JOIN users u ON u.id = m.user_id
        LEFT JOIN messages reply ON reply.id = m.reply_to
        LEFT JOIN users replyUser ON replyUser.id = reply.user_id
        LEFT JOIN user_status s ON s.user_id = u.id
        LEFT JOIN message_reads mr ON mr.message_id = m.id AND mr.user_id = ?
        $where
        ORDER BY m.created_at DESC
        LIMIT ?
    ";

    $params = array_merge([$currentUserId ?? 0], $params, [$limit]);

    $messages = $db->query($sql, $params);

    return array_reverse($messages);
}

/**
 * استرجاع رسالة واحدة
 */
function getChatMessageById(int $messageId, ?int $currentUserId = null): ?array {
    $db = db();

    return $db->queryOne(
        "
        SELECT 
            m.id,
            m.user_id,
            u.full_name AS user_name,
            u.profile_photo,
            u.role,
            m.message_text,
            m.reply_to,
            m.edited,
            m.deleted,
            m.created_at,
            m.updated_at,
            m.read_by_count,
            reply.message_text AS reply_text,
            reply.user_id AS reply_user_id,
            replyUser.full_name AS reply_user_name,
            CASE 
                WHEN mr.user_id IS NULL THEN 0
                ELSE 1
            END AS is_read_by_current
        FROM messages m
        INNER JOIN users u ON u.id = m.user_id
        LEFT JOIN messages reply ON reply.id = m.reply_to
        LEFT JOIN users replyUser ON replyUser.id = reply.user_id
        LEFT JOIN message_reads mr ON mr.message_id = m.id AND mr.user_id = ?
        WHERE m.id = ?
        ",
        [
            $currentUserId ?? 0,
            $messageId,
        ]
    );
}

/**
 * تحديث رسالة
 */
function updateChatMessage(int $messageId, int $userId, string $newText): array {
    $db = db();
    $cleanMessage = trim($newText);

    if ($cleanMessage === '') {
        throw new InvalidArgumentException('Message text is required');
    }

    $message = $db->queryOne(
        "SELECT id, user_id, deleted FROM messages WHERE id = ?",
        [$messageId]
    );

    if (!$message) {
        throw new RuntimeException('Message not found');
    }

    if ((int) $message['user_id'] !== $userId) {
        throw new RuntimeException('Unauthorized to edit this message');
    }

    if ((int) $message['deleted'] === 1) {
        throw new RuntimeException('Cannot edit deleted message');
    }

    $db->execute(
        "UPDATE messages 
         SET message_text = ?, edited = 1 
         WHERE id = ?",
        [$cleanMessage, $messageId]
    );

    return getChatMessageById($messageId, $userId);
}

/**
 * حذف رسالة (حذف منطقي)
 */
function softDeleteChatMessage(int $messageId, int $userId): array {
    $db = db();

    $message = $db->queryOne(
        "SELECT id, user_id, deleted FROM messages WHERE id = ?",
        [$messageId]
    );

    if (!$message) {
        throw new RuntimeException('Message not found');
    }

    if ((int) $message['user_id'] !== $userId) {
        throw new RuntimeException('Unauthorized to delete this message');
    }

    if ((int) $message['deleted'] === 1) {
        return getChatMessageById($messageId, $userId);
    }

    $db->execute(
        "UPDATE messages 
         SET deleted = 1, message_text = '', edited = 1
         WHERE id = ?",
        [$messageId]
    );

    return getChatMessageById($messageId, $userId);
}

/**
 * تسجيل قراءة رسالة
 */
function markMessageAsRead(int $messageId, int $userId): void {
    $db = db();

    $db->execute(
        "INSERT INTO message_reads (message_id, user_id) 
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP",
        [$messageId, $userId]
    );

    $db->execute(
        "UPDATE messages 
         SET read_by_count = (
             SELECT COUNT(*) FROM message_reads WHERE message_id = ?
         )
         WHERE id = ?",
        [$messageId, $messageId]
    );
}

/**
 * تحديث حالة المستخدم
 */
function updateUserPresence(int $userId, bool $isOnline): void {
    $db = db();

    $db->execute(
        "INSERT INTO user_status (user_id, last_seen, is_online)
         VALUES (?, CURRENT_TIMESTAMP, ?)
         ON DUPLICATE KEY UPDATE 
            last_seen = CURRENT_TIMESTAMP,
            is_online = VALUES(is_online)",
        [$userId, $isOnline ? 1 : 0]
    );
}

/**
 * جلب حالة المستخدمين
 */
function getActiveUsers(): array {
    $db = db();

    return $db->query(
        "
        SELECT 
            u.id,
            u.full_name AS name,
            u.username,
            u.role,
            COALESCE(us.is_online, 0) AS is_online,
            COALESCE(us.last_seen, u.updated_at) AS last_seen
        FROM users u
        LEFT JOIN user_status us ON us.user_id = u.id
        WHERE u.status = 'active'
        ORDER BY u.full_name ASC
        "
    );
}

/**
 * آخر رسالة مرئية
 */
function getLastMessageTimestamp(): ?string {
    $db = db();

    $result = $db->queryOne("SELECT MAX(created_at) AS latest FROM messages");

    return $result && isset($result['latest']) ? $result['latest'] : null;
}

