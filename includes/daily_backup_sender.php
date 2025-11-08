<?php
/**
 * النسخة الاحتياطية اليومية التلقائية وإرسالها إلى Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

const DAILY_BACKUP_JOB_KEY = 'daily_backup_telegram';
const DAILY_BACKUP_STATUS_SETTING_KEY = 'daily_backup_status';

if (!function_exists('dailyBackupEnsureJobTable')) {
    /**
     * التأكد من وجود جدول تتبع المهام اليومية.
     */
    function dailyBackupEnsureJobTable(): void
    {
        static $tableReady = false;

        if ($tableReady) {
            return;
        }

        try {
            require_once __DIR__ . '/db.php';
            $db = db();
            $db->execute("
                CREATE TABLE IF NOT EXISTS `system_daily_jobs` (
                  `job_key` varchar(120) NOT NULL,
                  `last_sent_at` datetime DEFAULT NULL,
                  `last_file_path` varchar(512) DEFAULT NULL,
                  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`job_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $error) {
            error_log('Daily Backup: unable to ensure job table - ' . $error->getMessage());
            return;
        }

        $tableReady = true;
    }
}

if (!function_exists('dailyBackupSaveStatus')) {
    /**
     * حفظ حالة النسخة الاحتياطية اليومية في system_settings.
     *
     * @param array<string, mixed> $data
     */
    function dailyBackupSaveStatus(array $data): void
    {
        try {
            require_once __DIR__ . '/db.php';
            $db = db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->execute(
                "INSERT INTO system_settings (`key`, `value`)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [DAILY_BACKUP_STATUS_SETTING_KEY, $json]
            );
        } catch (Throwable $error) {
            error_log('Daily Backup: failed saving status - ' . $error->getMessage());
        }
    }
}

if (!function_exists('dailyBackupNotifyManager')) {
    /**
     * إرسال إشعار للمدير عند الحاجة.
     */
    function dailyBackupNotifyManager(string $message, string $type = 'info'): void
    {
        try {
            if (!function_exists('createNotificationForRole')) {
                require_once __DIR__ . '/notifications.php';
            }
        } catch (Throwable $includeError) {
            error_log('Daily Backup: unable to include notifications - ' . $includeError->getMessage());
            return;
        }

        if (function_exists('createNotificationForRole')) {
            try {
                createNotificationForRole(
                    'manager',
                    'النسخة الاحتياطية اليومية',
                    $message,
                    $type
                );
            } catch (Throwable $notifyError) {
                error_log('Daily Backup: failed sending manager notification - ' . $notifyError->getMessage());
            }
        }
    }
}

if (!function_exists('triggerDailyBackupDelivery')) {
    /**
     * تنفيذ النسخة الاحتياطية اليومية وإرسالها إلى Telegram مرة واحدة في اليوم.
     */
    function triggerDailyBackupDelivery(): void
    {
        if (PHP_SAPI === 'cli' || defined('SKIP_DAILY_BACKUP')) {
            return;
        }

        static $alreadyTriggered = false;
        if ($alreadyTriggered) {
            return;
        }
        $alreadyTriggered = true;

        $todayDate = date('Y-m-d');
        $statusData = [
            'date' => $todayDate,
            'status' => 'pending',
            'checked_at' => date('Y-m-d H:i:s'),
        ];

        try {
            require_once __DIR__ . '/db.php';
        } catch (Throwable $dbIncludeError) {
            error_log('Daily Backup: failed including db.php - ' . $dbIncludeError->getMessage());
            return;
        }

        $db = db();
        dailyBackupEnsureJobTable();

        $jobState = null;
        try {
            $jobState = $db->queryOne(
                "SELECT last_sent_at, last_file_path FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
                [DAILY_BACKUP_JOB_KEY]
            );
        } catch (Throwable $stateError) {
            error_log('Daily Backup: failed loading job state - ' . $stateError->getMessage());
        }

        if (!empty($jobState['last_sent_at'])) {
            $lastSentDate = substr((string) $jobState['last_sent_at'], 0, 10);
            if ($lastSentDate === $todayDate) {
                $statusData['status'] = 'already_sent';
                $statusData['last_sent_at'] = $jobState['last_sent_at'];
                $statusData['file_path'] = $jobState['last_file_path'] ?? null;
                $statusData['note'] = 'Backup already delivered to Telegram today';
                dailyBackupSaveStatus($statusData);
                dailyBackupNotifyManager('تم إرسال النسخة الاحتياطية للبيانات إلى شات Telegram مسبقاً اليوم.');
                return;
            }
        }

        $inProgress = false;
        try {
            $db->beginTransaction();
            $existing = $db->queryOne(
                "SELECT value FROM system_settings WHERE `key` = ? FOR UPDATE",
                [DAILY_BACKUP_STATUS_SETTING_KEY]
            );

            $existingData = [];
            if ($existing && isset($existing['value'])) {
                $decoded = json_decode((string) $existing['value'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingData = $decoded;
                }
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                in_array($existingData['status'] ?? null, ['completed', 'sent'], true)
            ) {
                $db->commit();
                dailyBackupNotifyManager('تم إرسال النسخة الاحتياطية للبيانات إلى شات Telegram مسبقاً اليوم.');
                return;
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                ($existingData['status'] ?? null) === 'running'
            ) {
                $startedAt = isset($existingData['started_at']) ? strtotime((string) $existingData['started_at']) : 0;
                if ($startedAt && (time() - $startedAt) < 600) {
                    $db->commit();
                    return;
                }
            }

            $statusData['status'] = 'running';
            $statusData['started_at'] = date('Y-m-d H:i:s');
            $statusJson = json_encode($statusData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $db->execute(
                "INSERT INTO system_settings (`key`, `value`)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [DAILY_BACKUP_STATUS_SETTING_KEY, $statusJson]
            );

            $db->commit();
            $inProgress = true;
        } catch (Throwable $transactionError) {
            try {
                $db->rollback();
            } catch (Throwable $ignore) {
            }
            error_log('Daily Backup: transaction error - ' . $transactionError->getMessage());
            return;
        }

        if (!$inProgress) {
            return;
        }

        try {
            require_once __DIR__ . '/backup.php';
        } catch (Throwable $backupIncludeError) {
            error_log('Daily Backup: failed including backup.php - ' . $backupIncludeError->getMessage());
            dailyBackupSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'error' => 'Unable to load backup module',
            ]));
            return;
        }

        try {
            require_once __DIR__ . '/simple_telegram.php';
        } catch (Throwable $telegramIncludeError) {
            error_log('Daily Backup: failed including simple_telegram.php - ' . $telegramIncludeError->getMessage());
            dailyBackupSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'error' => 'Unable to load Telegram module',
            ]));
            return;
        }

        if (!function_exists('isTelegramConfigured') || !isTelegramConfigured()) {
            dailyBackupSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'error' => 'Telegram integration is not configured',
            ]));
            return;
        }

        $backupRecord = null;
        $backupFilePath = null;
        $backupFilename = null;
        $backupId = null;

        try {
            $backupRecord = $db->queryOne(
                "SELECT id, filename, file_path, file_size, created_at
                 FROM backups
                 WHERE backup_type = 'daily'
                   AND status IN ('completed', 'success')
                   AND DATE(created_at) = ?
                 ORDER BY created_at DESC
                 LIMIT 1",
                [$todayDate]
            );
        } catch (Throwable $lookupError) {
            error_log('Daily Backup: lookup error - ' . $lookupError->getMessage());
        }

        if ($backupRecord && !empty($backupRecord['file_path']) && file_exists($backupRecord['file_path'])) {
            $backupFilePath = $backupRecord['file_path'];
            $backupFilename = $backupRecord['filename'] ?? basename($backupFilePath);
            $backupId = $backupRecord['id'] ?? null;
        } else {
            $creationResult = createDatabaseBackup('daily');

            if (!is_array($creationResult) || empty($creationResult['success'])) {
                $errorMessage = $creationResult['message'] ?? 'Failed to create backup file';
                dailyBackupSaveStatus(array_merge($statusData, [
                    'status' => 'failed',
                    'error' => $errorMessage,
                ]));
                dailyBackupNotifyManager('تعذر إنشاء النسخة الاحتياطية اليومية: ' . $errorMessage, 'danger');
                return;
            }

            $backupFilePath = $creationResult['file_path'] ?? null;
            $backupFilename = $creationResult['filename'] ?? null;

            if ($backupFilePath === null || !file_exists($backupFilePath)) {
                $errorMessage = 'Backup file missing after creation';
                dailyBackupSaveStatus(array_merge($statusData, [
                    'status' => 'failed',
                    'error' => $errorMessage,
                ]));
                dailyBackupNotifyManager('تعذر العثور على ملف النسخة الاحتياطية بعد إنشائه.', 'danger');
                return;
            }

            try {
                $newBackupRecord = $db->queryOne(
                    "SELECT id FROM backups WHERE filename = ? ORDER BY id DESC LIMIT 1",
                    [$backupFilename]
                );
                if ($newBackupRecord && isset($newBackupRecord['id'])) {
                    $backupId = (int) $newBackupRecord['id'];
                }
            } catch (Throwable $idLookupError) {
                error_log('Daily Backup: failed retrieving backup id - ' . $idLookupError->getMessage());
            }
        }

        $captionLines = [
            '🗃️ النسخة الاحتياطية اليومية للبيانات',
            'التاريخ: ' . date('Y-m-d H:i:s'),
        ];
        if ($backupFilename) {
            $captionLines[] = 'الملف: ' . $backupFilename;
        }
        if ($backupRecord && isset($backupRecord['file_size'])) {
            $captionLines[] = 'الحجم: ' . formatFileSize((int) $backupRecord['file_size']);
        }
        $caption = implode("\n", $captionLines);

        $sendResult = sendTelegramFile($backupFilePath, $caption);

        if ($sendResult === false) {
            $errorMessage = 'فشل إرسال النسخة الاحتياطية إلى Telegram';
            dailyBackupSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'error' => $errorMessage,
                'file_path' => $backupFilePath,
            ]));
            dailyBackupNotifyManager($errorMessage, 'danger');
            return;
        }

        $fileLogValue = $backupFilePath;
        if (strlen($fileLogValue) > 510) {
            $fileLogValue = substr($fileLogValue, -510);
        }

        try {
            if ($jobState) {
                $db->execute(
                    "UPDATE system_daily_jobs
                     SET last_sent_at = NOW(), last_file_path = ?, updated_at = NOW()
                     WHERE job_key = ?",
                    [$fileLogValue, DAILY_BACKUP_JOB_KEY]
                );
            } else {
                $db->execute(
                    "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path)
                     VALUES (?, NOW(), ?)",
                    [DAILY_BACKUP_JOB_KEY, $fileLogValue]
                );
            }
        } catch (Throwable $logError) {
            error_log('Daily Backup: failed updating job log - ' . $logError->getMessage());
        }

        $completedData = [
            'date' => $todayDate,
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'file_path' => $backupFilePath,
            'backup_id' => $backupId,
        ];

        dailyBackupSaveStatus($completedData);
        dailyBackupNotifyManager('تم إنشاء النسخة الاحتياطية اليومية وإرسالها إلى شات Telegram بنجاح.');
    }
}


