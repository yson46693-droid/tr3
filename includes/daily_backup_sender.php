<?php
/**
 * النسخة الاحتياطية اليومية التلقائية وإرسالها إلى Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

const DAILY_BACKUP_JOB_KEY = 'daily_backup_telegram';
const DAILY_BACKUP_STATUS_SETTING_KEY = 'daily_backup_status';

if (!function_exists('dailyBackupFileMatchesDate')) {
    function dailyBackupFileMatchesDate(string $path, string $targetDate): bool
    {
        if (!is_file($path)) {
            return false;
        }

        return date('Y-m-d', (int)filemtime($path)) === $targetDate;
    }
}

if (!function_exists('dailyBackupResolveStoredPath')) {
    /**
     * يحوّل المسار المخزن (نسبي أو مطلق) إلى مسار فعلي يمكن التحقق منه.
     */
    function dailyBackupResolveStoredPath(string $baseDir, ?string $storedPath): ?string
    {
        if ($storedPath === null) {
            return null;
        }

        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return null;
        }

        $normalizedBase = str_replace('\\', '/', rtrim($baseDir, '/\\'));
        $normalizedStored = str_replace('\\', '/', $storedPath);

        // في حال كان المسار مطلقاً (لينكس أو ويندوز)
        if (preg_match('#^[a-zA-Z]:/#', $normalizedStored) || substr($normalizedStored, 0, 1) === '/') {
            $candidate = str_replace('/', DIRECTORY_SEPARATOR, $normalizedStored);
            if (is_file($candidate)) {
                return $candidate;
            }
            if (is_file($storedPath)) {
                return $storedPath;
            }
        }

        // محاولة تجميع المسار النسبي مع المجلد الأساسي
        $candidate = $normalizedBase . '/' . ltrim($normalizedStored, '/');
        $candidate = str_replace('/', DIRECTORY_SEPARATOR, $candidate);
        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }
}

if (!function_exists('dailyBackupGetRelativePath')) {
    /**
     * استخراج مسار نسبي من المسار الكامل إذا كان ضمن المجلد المحدد.
     */
    function dailyBackupGetRelativePath(string $baseDir, string $fullPath): string
    {
        $normalizedBase = str_replace('\\', '/', rtrim($baseDir, '/\\')) . '/';
        $normalizedFull = str_replace('\\', '/', $fullPath);

        if (strpos($normalizedFull, $normalizedBase) === 0) {
            $relative = substr($normalizedFull, strlen($normalizedBase));
            return ltrim($relative, '/');
        }

        return $normalizedFull;
    }
}

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
            $db->execute("\n                CREATE TABLE IF NOT EXISTS `system_daily_jobs` (\n                  `job_key` varchar(120) NOT NULL,\n                  `last_sent_at` datetime DEFAULT NULL,\n                  `last_file_path` varchar(512) DEFAULT NULL,\n                  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                  PRIMARY KEY (`job_key`)\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n            ");

            $lastNotifiedColumn = $db->queryOne("SHOW COLUMNS FROM system_daily_jobs LIKE 'last_notified_at'");
            if (empty($lastNotifiedColumn)) {
                $db->execute("ALTER TABLE system_daily_jobs ADD COLUMN `last_notified_at` datetime DEFAULT NULL AFTER `last_sent_at`");
            }

            $lastNotificationHashColumn = $db->queryOne("SHOW COLUMNS FROM system_daily_jobs LIKE 'last_notification_hash'");
            if (empty($lastNotificationHashColumn)) {
                $db->execute("ALTER TABLE system_daily_jobs ADD COLUMN `last_notification_hash` varchar(128) DEFAULT NULL AFTER `last_notified_at`");
            }
        } catch (Throwable $error) {
            error_log('Daily Backup: unable to ensure job table - ' . $error->getMessage());
            return;
        }

        $tableReady = true;
    }
}

if (!function_exists('dailyBackupResetNotificationState')) {
    function dailyBackupResetNotificationState(): void
    {
        try {
            dailyBackupEnsureJobTable();
            require_once __DIR__ . '/db.php';
            $db = db();
            $result = $db->execute(
                "UPDATE system_daily_jobs SET last_notified_at = NULL, last_notification_hash = NULL WHERE job_key = ?",
                [DAILY_BACKUP_JOB_KEY]
            );
            if (($result['affected_rows'] ?? 0) < 1) {
                $db->execute(
                    "INSERT IGNORE INTO system_daily_jobs (job_key) VALUES (?)",
                    [DAILY_BACKUP_JOB_KEY]
                );
            }
        } catch (Throwable $error) {
            error_log('Daily Backup: failed resetting notification state - ' . $error->getMessage());
        }
    }
}

if (!function_exists('dailyBackupNotifyManagerThrottled')) {
    function dailyBackupNotifyManagerThrottled(string $message, string $type = 'info', ?array &$jobState = null): void
    {
        $hash = sha1($type . '|' . $message);
        $today = date('Y-m-d');

        try {
            dailyBackupEnsureJobTable();
            require_once __DIR__ . '/db.php';
            $db = db();

            if ($jobState === null || !is_array($jobState)) {
                $jobState = $db->queryOne(
                    "SELECT last_notified_at, last_notification_hash FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
                    [DAILY_BACKUP_JOB_KEY]
                ) ?: [];
            }

            $lastNotifiedAt = isset($jobState['last_notified_at']) ? (string) $jobState['last_notified_at'] : '';
            $lastHash = isset($jobState['last_notification_hash']) ? (string) $jobState['last_notification_hash'] : '';

            if (
                $lastHash === $hash &&
                $lastNotifiedAt !== '' &&
                substr($lastNotifiedAt, 0, 10) === $today
            ) {
                return;
            }

            dailyBackupNotifyManager($message, $type);

            $updateResult = $db->execute(
                "UPDATE system_daily_jobs SET last_notified_at = NOW(), last_notification_hash = ? WHERE job_key = ?",
                [$hash, DAILY_BACKUP_JOB_KEY]
            );

            if (($updateResult['affected_rows'] ?? 0) < 1) {
                $db->execute(
                    "INSERT INTO system_daily_jobs (job_key, last_notified_at, last_notification_hash) VALUES (?, NOW(), ?)",
                    [DAILY_BACKUP_JOB_KEY, $hash]
                );
            }

            $jobState['last_notified_at'] = date('Y-m-d H:i:s');
            $jobState['last_notification_hash'] = $hash;
        } catch (Throwable $error) {
            error_log('Daily Backup: failed throttling notification - ' . $error->getMessage());
        }
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

if (!function_exists('dailyBackupRegisterManualDeletion')) {
    /**
     * توثيق حذف النسخة الاحتياطية اليومية يدوياً لتجنب إعادة إرسالها خلال اليوم نفسه.
     *
     * @param array<string,mixed> $backup
     * @param int|null $deletedByUserId
     */
    function dailyBackupRegisterManualDeletion(array $backup, ?int $deletedByUserId = null): void
    {
        $statusData = [
            'date' => date('Y-m-d'),
            'status' => 'manual_deleted',
            'deleted_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($backup['id'])) {
            $statusData['backup_id'] = (int) $backup['id'];
        }

        if (!empty($backup['filename'])) {
            $statusData['filename'] = (string) $backup['filename'];
        }

        if (!empty($backup['file_path'])) {
            $statusData['file_path'] = (string) $backup['file_path'];
        }

        if ($deletedByUserId !== null) {
            $statusData['deleted_by'] = (int) $deletedByUserId;
        }

        dailyBackupSaveStatus($statusData);

        try {
            dailyBackupEnsureJobTable();
            require_once __DIR__ . '/db.php';
            $db = db();
            $db->execute(
                "UPDATE system_daily_jobs SET last_file_path = NULL WHERE job_key = ?",
                [DAILY_BACKUP_JOB_KEY]
            );
        } catch (Throwable $error) {
            error_log('Daily Backup: failed registering manual deletion - ' . $error->getMessage());
        }

        $messageParts = ['تم حذف النسخة الاحتياطية اليومية يدوياً من قبل الإدارة.'];
        if (!empty($backup['filename'])) {
            $messageParts[] = 'الملف:' . ' ' . (string) $backup['filename'];
        }
        if ($deletedByUserId !== null) {
            $messageParts[] = '(المستخدم #' . (int) $deletedByUserId . ')';
        }

        dailyBackupNotifyManager(implode(' ', $messageParts), 'warning');
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

        $backupsBaseDir = rtrim(
            defined('BACKUPS_PATH') ? BACKUPS_PATH : (dirname(__DIR__) . '/backups'),
            '/\\'
        );

        $jobState = null;
        try {
        $jobState = $db->queryOne(
            "SELECT last_sent_at, last_file_path, last_notified_at, last_notification_hash FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
            [DAILY_BACKUP_JOB_KEY]
        );
        } catch (Throwable $stateError) {
            error_log('Daily Backup: failed loading job state - ' . $stateError->getMessage());
        }

        $jobStoredPath = isset($jobState['last_file_path']) ? (string)$jobState['last_file_path'] : '';
        $jobFilePath = dailyBackupResolveStoredPath($backupsBaseDir, $jobStoredPath);
        $jobStoredRelative = $jobFilePath !== null
            ? dailyBackupGetRelativePath($backupsBaseDir, $jobFilePath)
            : $jobStoredPath;
        $jobFileValid = $jobFilePath !== null && dailyBackupFileMatchesDate($jobFilePath, $todayDate);

        if (!empty($jobState['last_sent_at']) && $jobFileValid) {
            $lastSentDate = substr((string) $jobState['last_sent_at'], 0, 10);
            if ($lastSentDate === $todayDate) {
                $statusData['status'] = 'already_sent';
                $statusData['last_sent_at'] = $jobState['last_sent_at'];
                $statusData['file_path'] = $jobStoredRelative ?: null;
                $statusData['note'] = 'Backup already delivered to Telegram today';
                dailyBackupSaveStatus($statusData);
                dailyBackupNotifyManagerThrottled('تم إرسال النسخة الاحتياطية للبيانات إلى شات Telegram مسبقاً اليوم.', 'info', $jobState);
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

            $existingDataHasFile = false;
            $existingStoredPath = isset($existingData['file_path']) ? (string)$existingData['file_path'] : '';

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                ($existingData['status'] ?? null) === 'manual_deleted'
            ) {
                $db->commit();
                $statusData['status'] = 'manual_deleted';
                $statusData['note'] = 'Daily backup delivery skipped due to manual deletion.';
                $statusData['checked_at'] = date('Y-m-d H:i:s');
                dailyBackupSaveStatus($statusData);
                return;
            }

            if (
                $existingStoredPath !== '' &&
                ($existingData['date'] ?? null) === $todayDate
            ) {
                $existingResolvedPath = dailyBackupResolveStoredPath($backupsBaseDir, $existingStoredPath);
                if ($existingResolvedPath !== null && dailyBackupFileMatchesDate($existingResolvedPath, $todayDate)) {
                    $existingDataHasFile = true;
                    $existingData['file_path'] = dailyBackupGetRelativePath($backupsBaseDir, $existingResolvedPath);
                }
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                in_array($existingData['status'] ?? null, ['completed', 'sent'], true) &&
                $existingDataHasFile
            ) {
                $db->commit();
                dailyBackupNotifyManagerThrottled('تم إرسال النسخة الاحتياطية للبيانات إلى شات Telegram مسبقاً اليوم.', 'info', $jobState);
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

        dailyBackupResetNotificationState();

        if (is_array($jobState)) {
            $jobState['last_notified_at'] = null;
            $jobState['last_notification_hash'] = null;
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
                dailyBackupNotifyManagerThrottled('تعذر إنشاء النسخة الاحتياطية اليومية: ' . $errorMessage, 'danger', $jobState);
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
                dailyBackupNotifyManagerThrottled('تعذر العثور على ملف النسخة الاحتياطية بعد إنشائه.', 'danger', $jobState);
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

        $backupRelativePath = $backupFilePath !== null
            ? dailyBackupGetRelativePath($backupsBaseDir, $backupFilePath)
            : null;

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
                'file_path' => $backupRelativePath ?? $backupFilePath,
            ]));
            dailyBackupNotifyManagerThrottled($errorMessage, 'danger', $jobState);
            return;
        }

        $fileLogValue = $backupRelativePath ?? $backupFilePath;
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
            'file_path' => $backupRelativePath ?? $backupFilePath,
            'backup_id' => $backupId,
        ];

        dailyBackupSaveStatus($completedData);
        dailyBackupNotifyManagerThrottled('تم إنشاء النسخة الاحتياطية اليومية وإرسالها إلى شات Telegram بنجاح.', 'success', $jobState);
    }
}


