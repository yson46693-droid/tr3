<?php
/**
 * الإرسال اليومي التلقائي لتقرير استهلاك الإنتاج عبر Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

const DAILY_CONSUMPTION_JOB_KEY = 'daily_consumption_report';
const DAILY_CONSUMPTION_STATUS_SETTING_KEY = 'daily_consumption_report_status';

if (!function_exists('dailyConsumptionEnsureJobTable')) {
    /**
     * التأكد من وجود جدول system_daily_jobs.
     */
    function dailyConsumptionEnsureJobTable(): void
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
            error_log('Daily Consumption: unable to ensure job table - ' . $error->getMessage());
            return;
        }

        $tableReady = true;
    }
}

if (!function_exists('dailyConsumptionSaveStatus')) {
    /**
     * حفظ حالة التقرير اليومي في system_settings.
     *
     * @param array<string, mixed> $data
     */
    function dailyConsumptionSaveStatus(array $data): void
    {
        try {
            require_once __DIR__ . '/db.php';
            $db = db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->execute(
                "INSERT INTO system_settings (`key`, `value`)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [DAILY_CONSUMPTION_STATUS_SETTING_KEY, $json]
            );
        } catch (Throwable $error) {
            error_log('Daily Consumption: failed saving status - ' . $error->getMessage());
        }
    }
}

if (!function_exists('dailyConsumptionNotifyManager')) {
    /**
     * إرسال إشعار للمدير بشأن حالة التقرير.
     */
    function dailyConsumptionNotifyManager(string $message, string $type = 'info'): void
    {
        try {
            if (!function_exists('createNotificationForRole')) {
                require_once __DIR__ . '/notifications.php';
            }
        } catch (Throwable $includeError) {
            error_log('Daily Consumption: unable to include notifications - ' . $includeError->getMessage());
            return;
        }

        if (function_exists('createNotificationForRole')) {
            try {
                createNotificationForRole(
                    'manager',
                    'تقرير الاستهلاك اليومي',
                    $message,
                    $type
                );
            } catch (Throwable $notifyError) {
                error_log('Daily Consumption: failed sending manager notification - ' . $notifyError->getMessage());
            }
        }
    }
}

if (!function_exists('triggerDailyConsumptionReport')) {
    /**
     * تنفيذ تقرير الاستهلاك اليومي وإرساله مرة واحدة في اليوم.
     */
    function triggerDailyConsumptionReport(): void
    {
        if (PHP_SAPI === 'cli' || defined('SKIP_DAILY_CONSUMPTION_REPORT')) {
            return;
        }

        static $alreadyTriggered = false;
        if ($alreadyTriggered) {
            return;
        }
        $alreadyTriggered = true;

        $todayDate = date('Y-m-d');
        $targetDate = date('Y-m-d', strtotime('-1 day'));
        $statusData = [
            'date' => $todayDate,
            'target_date' => $targetDate,
            'status' => 'pending',
            'checked_at' => date('Y-m-d H:i:s'),
        ];

        try {
            require_once __DIR__ . '/db.php';
        } catch (Throwable $dbIncludeError) {
            error_log('Daily Consumption: failed including db.php - ' . $dbIncludeError->getMessage());
            return;
        }

        $db = db();
        dailyConsumptionEnsureJobTable();

        $jobState = null;
        try {
            $jobState = $db->queryOne(
                "SELECT last_sent_at, last_file_path FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
                [DAILY_CONSUMPTION_JOB_KEY]
            );
        } catch (Throwable $stateError) {
            error_log('Daily Consumption: failed loading job state - ' . $stateError->getMessage());
        }

        if (!empty($jobState['last_sent_at'])) {
            $lastSentDate = substr((string) $jobState['last_sent_at'], 0, 10);
            if ($lastSentDate === $todayDate) {
                $statusData['status'] = 'already_sent';
                $statusData['last_sent_at'] = $jobState['last_sent_at'];
                $statusData['note'] = 'Consumption report already delivered today';
                dailyConsumptionSaveStatus($statusData);
                dailyConsumptionNotifyManager('تم إرسال تقرير الاستهلاك اليومي مسبقاً اليوم.');
                return;
            }
        }

        $inProgress = false;
        try {
            $db->beginTransaction();
            $existing = $db->queryOne(
                "SELECT value FROM system_settings WHERE `key` = ? FOR UPDATE",
                [DAILY_CONSUMPTION_STATUS_SETTING_KEY]
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
                in_array($existingData['status'] ?? null, ['completed', 'completed_no_data'], true)
            ) {
                $db->commit();
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
                [DAILY_CONSUMPTION_STATUS_SETTING_KEY, $statusJson]
            );

            $db->commit();
            $inProgress = true;
        } catch (Throwable $transactionError) {
            try {
                $db->rollback();
            } catch (Throwable $ignore) {
            }
            error_log('Daily Consumption: transaction error - ' . $transactionError->getMessage());
            return;
        }

        if (!$inProgress) {
            return;
        }

        try {
            require_once __DIR__ . '/consumption_reports.php';
        } catch (Throwable $includeError) {
            error_log('Daily Consumption: failed including consumption_reports.php - ' . $includeError->getMessage());
            dailyConsumptionSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'error' => 'Unable to load consumption report module',
            ]));
            return;
        }

        $result = sendConsumptionReport($targetDate, $targetDate, 'التقرير اليومي الآلي');
        $success = (bool)($result['success'] ?? false);
        $message = trim($result['message'] ?? '');

        if (!$success) {
            $normalizedMessage = mb_strtolower($message, 'UTF-8');
            if ($message !== '' && str_contains($normalizedMessage, 'لا توجد بيانات')) {
                dailyConsumptionSaveStatus(array_merge($statusData, [
                    'status' => 'completed_no_data',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'message' => $message,
                ]));
                dailyConsumptionNotifyManager('لا توجد بيانات استهلاك للفترة السابقة، لم يتم إرسال التقرير الآلي اليوم.', 'info');
                return;
            }

            dailyConsumptionSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error' => $message ?: 'تعذر إنشاء تقرير الاستهلاك اليومي.',
            ]));
            dailyConsumptionNotifyManager('تعذر إرسال تقرير الاستهلاك اليومي: ' . ($message ?: 'سبب غير معروف'), 'danger');
            return;
        }

        try {
            if ($jobState) {
                $db->execute(
                    "UPDATE system_daily_jobs
                     SET last_sent_at = NOW(), last_file_path = NULL, updated_at = NOW()
                     WHERE job_key = ?",
                    [DAILY_CONSUMPTION_JOB_KEY]
                );
            } else {
                $db->execute(
                    "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path)
                     VALUES (?, NOW(), NULL)",
                    [DAILY_CONSUMPTION_JOB_KEY]
                );
            }
        } catch (Throwable $logError) {
            error_log('Daily Consumption: failed updating job log - ' . $logError->getMessage());
        }

        dailyConsumptionSaveStatus([
            'date' => $todayDate,
            'target_date' => $targetDate,
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'message' => $message ?: 'تم إرسال التقرير بنجاح.',
        ]);

        dailyConsumptionNotifyManager('تم إنشاء تقرير الاستهلاك اليومي وإرساله إلى Telegram بنجاح.');
        return;
    }
}
