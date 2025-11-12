<?php
/**
 * وظائف المساعدة للدردشة الجماعية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!function_exists('ensureGroupChatTables')) {
    /**
     * التأكد من وجود جداول الدردشة وإنشائها إذا لزم الأمر
     */
    function ensureGroupChatTables(): bool
    {
        static $ensured = false;

        if ($ensured) {
            return true;
        }

        try {
            $db = db();

            $tableCheck = $db->queryOne("SHOW TABLES LIKE 'group_chat_messages'");
            if (empty($tableCheck)) {
                $db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `group_chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_group_chat_user` (`user_id`),
  KEY `idx_group_chat_parent` (`parent_id`),
  KEY `idx_group_chat_created` (`created_at`),
  CONSTRAINT `fk_group_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_chat_parent` FOREIGN KEY (`parent_id`) REFERENCES `group_chat_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
                );
            } else {
                // تأكد من الأعمدة الأساسية في حال كانت الجداول قديمة
                $columns = $db->query("SHOW COLUMNS FROM group_chat_messages");
                $columnNames = array_map(static function ($column) {
                    return $column['Field'] ?? '';
                }, $columns ?: []);

                $alterStatements = [];
                if (!in_array('updated_at', $columnNames, true)) {
                    $alterStatements[] = "ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`";
                }
                if (!in_array('deleted_at', $columnNames, true)) {
                    $alterStatements[] = "ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `updated_at`";
                }
                if (!in_array('parent_id', $columnNames, true)) {
                    $alterStatements[] = "ADD COLUMN `parent_id` int(11) DEFAULT NULL AFTER `message`";
                }

                if (!empty($alterStatements)) {
                    foreach ($alterStatements as $alter) {
                        try {
                            $db->execute("ALTER TABLE `group_chat_messages` {$alter}");
                        } catch (Throwable $alterError) {
                            error_log('Group chat table alter failed: ' . $alterError->getMessage());
                        }
                    }
                }

                // التأكد من وجود الفهارس القياسية
                try {
                    $indexCheck = $db->query(
                        "SHOW INDEX FROM group_chat_messages WHERE Key_name = 'idx_group_chat_created'"
                    );
                    if (empty($indexCheck)) {
                        $db->execute("ALTER TABLE `group_chat_messages` ADD INDEX `idx_group_chat_created` (`created_at`)");
                    }
                } catch (Throwable $indexError) {
                    error_log('Group chat index ensure failed: ' . $indexError->getMessage());
                }
            }

            $ensured = true;
            return true;
        } catch (Throwable $e) {
            error_log('Group chat table initialization failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sanitizeGroupChatMessage')) {
    /**
     * تنقية نص الرسالة قبل التخزين
     */
    function sanitizeGroupChatMessage(string $message): string
    {
        $clean = trim($message);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $clean);
        $clean = strip_tags($clean);

        if (function_exists('mb_strlen')) {
            if (mb_strlen($clean, 'UTF-8') > 2000) {
                $clean = mb_substr($clean, 0, 2000, 'UTF-8');
            }
        } else {
            if (strlen($clean) > 2000) {
                $clean = substr($clean, 0, 2000);
            }
        }

        return trim($clean);
    }
}

if (!function_exists('isGroupChatModeratorRole')) {
    /**
     * تحديد ما إذا كان الدور يملك صلاحيات الإشراف على الدردشة
     */
    function isGroupChatModeratorRole(?string $role): bool
    {
        if ($role === null) {
            return false;
        }

        $normalized = strtolower($role);
        return in_array($normalized, ['manager', 'admin'], true);
    }
}

if (!function_exists('normalizeGroupChatRow')) {
    /**
     * تحويل صف قاعدة البيانات إلى بنية جاهزة للإرسال
     */
    function normalizeGroupChatRow(array $row): array
    {
        $messageId = isset($row['id']) ? (int) $row['id'] : 0;
        $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        if ($parentId === 0) {
            $parentId = null;
        }

        $result = [
            'id' => $messageId,
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'message' => (string) ($row['message'] ?? ''),
            'parent_id' => $parentId,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'deleted_at' => $row['deleted_at'] ?? null,
            'author' => [
                'id' => isset($row['user_id']) ? (int) $row['user_id'] : 0,
                'full_name' => $row['full_name'] ?? null,
                'username' => $row['username'] ?? null,
                'role' => $row['role'] ?? null,
                'profile_photo' => $row['profile_photo'] ?? null,
            ],
            'parent' => null,
        ];

        if ($parentId !== null) {
            $result['parent'] = [
                'id' => $parentId,
                'message' => $row['parent_message'] ?? null,
                'deleted_at' => $row['parent_deleted_at'] ?? null,
                'created_at' => $row['parent_created_at'] ?? null,
                'author' => [
                    'id' => isset($row['parent_user_id']) ? (int) $row['parent_user_id'] : null,
                    'full_name' => $row['parent_full_name'] ?? null,
                    'username' => $row['parent_username'] ?? null,
                    'role' => $row['parent_role'] ?? null,
                ],
            ];
        }

        return $result;
    }
}

if (!function_exists('cleanupExpiredGroupChatMessages')) {
    /**
     * حذف رسائل الدردشة الأقدم من فترة الاحتفاظ المحددة.
     */
    function cleanupExpiredGroupChatMessages(): void
    {
        static $cleanupDone = false;

        if ($cleanupDone) {
            return;
        }

        if (!ensureGroupChatTables()) {
            return;
        }

        $retentionDays = defined('GROUP_CHAT_RETENTION_DAYS')
            ? (int) GROUP_CHAT_RETENTION_DAYS
            : 30;

        if ($retentionDays < 1) {
            $retentionDays = 30;
        }

        $db = db();

        try {
            $db->execute(
                "DELETE FROM group_chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$retentionDays]
            );
        } catch (Throwable $cleanupError) {
            error_log('Group chat cleanup failed: ' . $cleanupError->getMessage());
        }

        $cleanupDone = true;
    }
}

if (!function_exists('getGroupChatMessageById')) {
    /**
     * الحصول على رسالة واحدة
     */
    function getGroupChatMessageById(int $messageId): ?array
    {
        if ($messageId <= 0) {
            return null;
        }

        if (!ensureGroupChatTables()) {
            return null;
        }

        $db = db();

        try {
            $row = $db->queryOne(
                "SELECT gcm.*, u.full_name, u.username, u.role, u.profile_photo,
                        parent.message AS parent_message,
                        parent.deleted_at AS parent_deleted_at,
                        parent.created_at AS parent_created_at,
                        parent.user_id AS parent_user_id,
                        parentUser.full_name AS parent_full_name,
                        parentUser.username AS parent_username,
                        parentUser.role AS parent_role
                 FROM group_chat_messages gcm
                 INNER JOIN users u ON u.id = gcm.user_id
                 LEFT JOIN group_chat_messages parent ON parent.id = gcm.parent_id
                 LEFT JOIN users parentUser ON parentUser.id = parent.user_id
                 WHERE gcm.id = ?",
                [$messageId]
            );

            return $row ? normalizeGroupChatRow($row) : null;
        } catch (Throwable $e) {
            error_log('Failed fetching chat message: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getGroupChatMessages')) {
    /**
     * الحصول على مجموعة من رسائل الدردشة مع خيارات التصفية
     *
     * @param array $options ['limit' => int, 'since_id' => int, 'before_id' => int]
     */
    function getGroupChatMessages(array $options = []): array
    {
        if (!ensureGroupChatTables()) {
            return [];
        }

        cleanupExpiredGroupChatMessages();

        $limit = isset($options['limit']) ? (int) $options['limit'] : 100;
        $limit = max(1, min(200, $limit));
        $sinceId = isset($options['since_id']) ? (int) $options['since_id'] : null;
        $beforeId = isset($options['before_id']) ? (int) $options['before_id'] : null;

        $db = db();
        $params = [];

        $conditions = [];
        if ($sinceId !== null && $sinceId > 0) {
            $conditions[] = 'gcm.id > ?';
            $params[] = $sinceId;
        }
        if ($beforeId !== null && $beforeId > 0) {
            $conditions[] = 'gcm.id < ?';
            $params[] = $beforeId;
        }

        $whereClause = '';
        if (!empty($conditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
        }

        try {
            $rows = $db->query(
                "SELECT gcm.*, u.full_name, u.username, u.role, u.profile_photo,
                        parent.message AS parent_message,
                        parent.deleted_at AS parent_deleted_at,
                        parent.created_at AS parent_created_at,
                        parent.user_id AS parent_user_id,
                        parentUser.full_name AS parent_full_name,
                        parentUser.username AS parent_username,
                        parentUser.role AS parent_role
                 FROM group_chat_messages gcm
                 INNER JOIN users u ON u.id = gcm.user_id
                 LEFT JOIN group_chat_messages parent ON parent.id = gcm.parent_id
                 LEFT JOIN users parentUser ON parentUser.id = parent.user_id
                 {$whereClause}
                 ORDER BY gcm.created_at ASC, gcm.id ASC
                 LIMIT ?",
                array_merge($params, [$limit])
            );

            if (empty($rows)) {
                return [];
            }

            return array_map('normalizeGroupChatRow', $rows);
        } catch (Throwable $e) {
            error_log('Failed fetching chat messages: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('createGroupChatMessage')) {
    /**
     * إنشاء رسالة جديدة
     */
    function createGroupChatMessage(int $userId, string $message, ?int $parentId = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        if (!ensureGroupChatTables()) {
            return null;
        }

        $cleanMessage = sanitizeGroupChatMessage($message);
        if ($cleanMessage === '') {
            throw new InvalidArgumentException('Message content is empty.');
        }

        $db = db();

        try {
            $db->beginTransaction();

            $resolvedParentId = null;
            if ($parentId !== null && $parentId > 0) {
                $parent = $db->queryOne(
                    "SELECT id FROM group_chat_messages WHERE id = ?",
                    [$parentId]
                );

                if (!$parent) {
                    throw new InvalidArgumentException('Parent message not found.');
                }

                $resolvedParentId = (int) $parent['id'];
            }

            $result = $db->execute(
                "INSERT INTO group_chat_messages (user_id, message, parent_id) VALUES (?, ?, ?)",
                [$userId, $cleanMessage, $resolvedParentId]
            );

            $messageId = (int) ($result['insert_id'] ?? 0);
            $db->commit();

            return getGroupChatMessageById($messageId);
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Failed creating chat message: ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('updateGroupChatMessage')) {
    /**
     * تحديث رسالة موجودة
     */
    function updateGroupChatMessage(int $messageId, int $userId, string $message, bool $canModerate = false): ?array
    {
        if ($messageId <= 0 || $userId <= 0) {
            return null;
        }

        if (!ensureGroupChatTables()) {
            return null;
        }

        $cleanMessage = sanitizeGroupChatMessage($message);
        if ($cleanMessage === '') {
            throw new InvalidArgumentException('Message content is empty.');
        }

        $db = db();

        try {
            $existing = $db->queryOne(
                "SELECT id, user_id, deleted_at FROM group_chat_messages WHERE id = ?",
                [$messageId]
            );

            if (!$existing) {
                throw new InvalidArgumentException('Message not found.');
            }

            if (!empty($existing['deleted_at'])) {
                throw new RuntimeException('Cannot edit a deleted message.');
            }

            if ((int) $existing['user_id'] !== $userId && !$canModerate) {
                throw new RuntimeException('Unauthorized to edit this message.');
            }

            $db->execute(
                "UPDATE group_chat_messages SET message = ?, updated_at = NOW() WHERE id = ?",
                [$cleanMessage, $messageId]
            );

            return getGroupChatMessageById($messageId);
        } catch (Throwable $e) {
            error_log('Failed updating chat message: ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('deleteGroupChatMessage')) {
    /**
     * حذف (إخفاء) رسالة موجودة
     */
    function deleteGroupChatMessage(int $messageId, int $userId, bool $canModerate = false): bool
    {
        if ($messageId <= 0 || $userId <= 0) {
            return false;
        }

        if (!ensureGroupChatTables()) {
            return false;
        }

        $db = db();

        try {
            $existing = $db->queryOne(
                "SELECT id, user_id, deleted_at FROM group_chat_messages WHERE id = ?",
                [$messageId]
            );

            if (!$existing) {
                throw new InvalidArgumentException('Message not found.');
            }

            if (!empty($existing['deleted_at'])) {
                return true;
            }

            if ((int) $existing['user_id'] !== $userId && !$canModerate) {
                throw new RuntimeException('Unauthorized to delete this message.');
            }

            $db->execute(
                "UPDATE group_chat_messages SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$messageId]
            );

            return true;
        } catch (Throwable $e) {
            error_log('Failed deleting chat message: ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('purgeGroupChatMessages')) {
    /**
     * حذف جميع رسائل الدردشة نهائياً.
     */
    function purgeGroupChatMessages(): void
    {
        if (!ensureGroupChatTables()) {
            return;
        }

        $db = db();

        try {
            $db->execute('DELETE FROM group_chat_messages');
        } catch (Throwable $e) {
            error_log('Failed purging chat messages: ' . $e->getMessage());
            throw $e;
        }
    }
}

?>
