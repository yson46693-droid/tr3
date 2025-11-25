<?php
/**
 * الإرسال اليومي التلقائي لتقرير استهلاك الإنتاج عبر Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

const DAILY_CONSUMPTION_JOB_KEY = 'daily_consumption_report';
const DAILY_CONSUMPTION_STATUS_SETTING_KEY = 'daily_consumption_report_status';

if (!function_exists('consumptionReportFileMatchesDate')) {
    function consumptionReportFileMatchesDate(string $path, string $targetDate): bool
    {
        if (!is_file($path)) {
            return false;
        }
        return date('Y-m-d', (int)filemtime($path)) === $targetDate;
    }
}

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
        error_log(sprintf('[DailyConsumption] Trigger invoked (today=%s, target=%s)', $todayDate, $targetDate));
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

        $reportsBaseDir = rtrim(
            defined('REPORTS_PRIVATE_PATH')
                ? REPORTS_PRIVATE_PATH
                : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports')),
            '/\\'
        );

        $statusSnapshot = [];
        $existingReportPath = null;
        try {
            $rawStatus = $db->queryOne(
                "SELECT value FROM system_settings WHERE `key` = ? LIMIT 1",
                [DAILY_CONSUMPTION_STATUS_SETTING_KEY]
            );
            if ($rawStatus && isset($rawStatus['value'])) {
                $decodedStatus = json_decode((string)$rawStatus['value'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedStatus)) {
                    $statusSnapshot = $decodedStatus;
                    if (
                        ($decodedStatus['date'] ?? null) === $todayDate &&
                        !empty($decodedStatus['report_path'])
                    ) {
                        $candidatePath = $reportsBaseDir . '/' . ltrim((string)$decodedStatus['report_path'], '/\\');
                        if (consumptionReportFileMatchesDate($candidatePath, $todayDate)) {
                            $existingReportPath = $candidatePath;
                        }
                    }
                }
            }
        } catch (Throwable $statusError) {
            error_log('Daily Consumption: failed loading status - ' . $statusError->getMessage());
        }

        $jobState = null;
        try {
            $jobState = $db->queryOne(
                "SELECT last_sent_at, last_file_path FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
                [DAILY_CONSUMPTION_JOB_KEY]
            );
        } catch (Throwable $stateError) {
            error_log('Daily Consumption: failed loading job state - ' . $stateError->getMessage());
        }

        $jobRelativePath = (string)($jobState['last_file_path'] ?? '');
        $jobReportPath = $jobRelativePath !== ''
            ? $reportsBaseDir . '/' . ltrim($jobRelativePath, '/\\')
            : null;
        $jobReportValid = $jobReportPath !== null && consumptionReportFileMatchesDate($jobReportPath, $todayDate);

        if (!empty($jobState['last_sent_at'])) {
            $lastSentDate = substr((string) $jobState['last_sent_at'], 0, 10);
            if ($lastSentDate === $todayDate) {
                $snapshotStatus = $statusSnapshot['status'] ?? null;
                $snapshotHasValidFile = $existingReportPath !== null && consumptionReportFileMatchesDate($existingReportPath, $todayDate);

                if (
                    !empty($statusSnapshot) &&
                    in_array($snapshotStatus, ['completed', 'completed_no_data', 'already_sent'], true) &&
                    ($snapshotHasValidFile || $jobReportValid)
                ) {
                    $statusSnapshot['status'] = 'already_sent';
                    $statusSnapshot['checked_at'] = date('Y-m-d H:i:s');
                    $statusSnapshot['last_sent_at'] = $jobState['last_sent_at'];
                    if ($jobReportValid && !$snapshotHasValidFile && $jobRelativePath !== '') {
                        $statusSnapshot['report_path'] = $jobRelativePath;
                    }
                    dailyConsumptionSaveStatus($statusSnapshot);
                    error_log('[DailyConsumption] Skipped: already sent earlier today.');
                    dailyConsumptionNotifyManager('تم إرسال تقرير الاستهلاك اليومي مسبقاً اليوم.');
                    return;
                }

                if (empty($statusSnapshot) && $jobReportValid) {
                    dailyConsumptionSaveStatus([
                        'date' => $todayDate,
                        'target_date' => $targetDate,
                        'status' => 'already_sent',
                        'checked_at' => date('Y-m-d H:i:s'),
                        'last_sent_at' => $jobState['last_sent_at'],
                        'report_path' => $jobRelativePath,
                    ]);
                    error_log('[DailyConsumption] Skipped: job state indicates already sent today.');
                    return;
                }
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
            $existingDataReportPath = null;
            if ($existing && isset($existing['value'])) {
                $decoded = json_decode((string) $existing['value'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingData = $decoded;
                    if (
                        ($decoded['date'] ?? null) === $todayDate &&
                        !empty($decoded['report_path'])
                    ) {
                        $candidateExisting = $reportsBaseDir . '/' . ltrim((string)$decoded['report_path'], '/\\');
                        if (consumptionReportFileMatchesDate($candidateExisting, $todayDate)) {
                            $existingDataReportPath = $candidateExisting;
                        }
                    }
                }
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                in_array($existingData['status'] ?? null, ['completed', 'completed_no_data', 'already_sent'], true) &&
                $existingDataReportPath !== null
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
            error_log('[DailyConsumption] Status locked and transaction committed (running).');
        } catch (Throwable $transactionError) {
            try {
                $db->rollback();
            } catch (Throwable $ignore) {
            }
            error_log('Daily Consumption: transaction error - ' . $transactionError->getMessage());
            return;
        }

        if (!$inProgress) {
            error_log('[DailyConsumption] Aborted: inProgress flag false after transaction.');
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
                $statusPayload = array_merge($statusData, [
                    'status' => 'completed_no_data',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'message' => $message,
                ]);
                dailyConsumptionSaveStatus($statusPayload);
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
                } catch (Throwable $updateJobNoData) {
                    error_log('Daily Consumption: failed updating job state (no data) - ' . $updateJobNoData->getMessage());
                }
                error_log('[DailyConsumption] No data available for target date - report not sent.');
                dailyConsumptionNotifyManager('لا توجد بيانات استهلاك للفترة السابقة، لم يتم إرسال التقرير الآلي اليوم.', 'info');
                return;
            }

            if (isset($result['status']) && isset($result['preview'])) {
                error_log('[DailyConsumption] aPDF failure details: status=' . $result['status'] . '; preview=' . $result['preview']);
            }

            dailyConsumptionSaveStatus(array_merge($statusData, [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error' => $message ?: 'تعذر إنشاء تقرير الاستهلاك اليومي.',
            ]));
            error_log('[DailyConsumption] Report generation failed: ' . ($message ?: 'unknown reason'));
            dailyConsumptionNotifyManager('تعذر إرسال تقرير الاستهلاك اليومي: ' . ($message ?: 'سبب غير معروف'), 'danger');
            return;
        }

        $relativePath = $result['relative_path'] ?? null;
        try {
            if ($jobState) {
                $db->execute(
                    "UPDATE system_daily_jobs
                     SET last_sent_at = NOW(), last_file_path = ?, updated_at = NOW()
                     WHERE job_key = ?",
                    [$relativePath, DAILY_CONSUMPTION_JOB_KEY]
                );
            } else {
                $db->execute(
                    "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path)
                     VALUES (?, NOW(), ?)",
                    [DAILY_CONSUMPTION_JOB_KEY, $relativePath]
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
            'report_path' => $relativePath,
            'report_url' => $result['report_url'] ?? null,
            'print_url' => $result['print_url'] ?? null,
            'absolute_report_url' => $result['absolute_report_url'] ?? null,
            'absolute_print_url' => $result['absolute_print_url'] ?? null,
        ]);

        error_log('[DailyConsumption] Report generated and sent successfully.');
        dailyConsumptionNotifyManager('تم إنشاء تقرير الاستهلاك اليومي وإرساله إلى Telegram بنجاح.');
        return;
    }
}
