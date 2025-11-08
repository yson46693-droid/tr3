<?php
/**
 * تقرير الكميات المنخفضة اليومي وإرساله إلى Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/pdf_helper.php';

/**
 * إنشاء ملف PDF لتقرير الكميات المنخفضة
 *
 * @param array<int, array<string, mixed>> $sections
 * @param array<string, int> $counts
 * @return string|null
 */
function dailyLowStockGeneratePdf(array $sections, array $counts): ?string
{
    $baseReportsPath = defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports/');
    $reportsDir = rtrim($baseReportsPath, '/\\') . '/low_stock';
    if (!is_dir($reportsDir)) {
        @mkdir($reportsDir, 0755, true);
    }
    if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
        error_log('Low Stock Report: reports directory not writable - ' . $reportsDir);
        return null;
    }

    $filename = sprintf('low-stock-report-%s.pdf', date('Ymd-His'));
    $filePath = $reportsDir . DIRECTORY_SEPARATOR . $filename;

    $summaryItems = '';
    foreach ($counts as $key => $value) {
        if ($value <= 0) {
            continue;
        }
        $summaryItems .= '<li><span class="label">' . htmlspecialchars(formatLowStockCountLabel($key), ENT_QUOTES, 'UTF-8')
            . '</span><span class="value">' . intval($value) . '</span></li>';
    }

    // لتغيير محتوى التقرير أو ترتيب الأقسام، عدل طريقة بناء المتغير $sectionsHtml أدناه.
    $sectionsHtml = '';
    foreach ($sections as $section) {
        $title = htmlspecialchars((string)($section['title'] ?? 'قسم غير محدد'), ENT_QUOTES, 'UTF-8');
        $details = $section['lines'] ?? [];
        $itemsList = '';

        if (empty($details)) {
            $itemsList = '<li class="empty-item">لا توجد عناصر منخفضة في هذا القسم.</li>';
        } else {
            foreach ($details as $detail) {
                $itemsList .= '<li>' . htmlspecialchars(ltrim((string)$detail), ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }

        $sectionsHtml .= '<section class="stock-section"><h3>' . $title . '</h3><ul>' . $itemsList . '</ul></section>';
    }

    $styles = '
        @page { margin: 18mm 15mm; }
        body { font-family: "Amiri", "Cairo", "Segoe UI", Tahoma, sans-serif; direction: rtl; text-align: right; margin: 0; background:#f8fafc; color:#0f172a; }
        /* لتغيير الخط العربي، استبدل أسماء الخطوط في السطر أعلاه أو فعّل رابط Google Fonts داخل الوسم <head>. */
        .report-wrapper { padding: 32px; background:#ffffff; border-radius: 16px; box-shadow: 0 12px 40px rgba(15,23,42,0.08); }
        header { text-align: center; margin-bottom: 24px; }
        header h1 { margin: 0; font-size: 26px; color:#1d4ed8; }
        header .meta { display:flex; justify-content:center; gap:16px; flex-wrap:wrap; margin-top:12px; color:#475569; font-size:14px; }
        header .meta span { background:#e2e8f0; padding:6px 14px; border-radius:999px; }
        .summary { background:#1d4ed8; color:#ffffff; padding:18px 24px; border-radius:14px; margin-bottom:28px; }
        .summary h2 { margin:0 0 12px; font-size:18px; }
        .summary ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
        .summary li { display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.15); padding:12px 16px; border-radius:12px; font-size:15px; }
        .summary .label { font-weight:600; }
        .summary .value { font-size:17px; font-weight:700; }
        .stock-section { margin-bottom:24px; padding:20px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; }
        .stock-section h3 { margin:0 0 12px; font-size:18px; color:#0f172a; }
        .stock-section ul { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; font-size:14px; color:#1f2937; }
        .stock-section li { position:relative; padding-right:24px; }
        .stock-section li::before { content:"•"; position:absolute; right:0; color:#1d4ed8; font-size:18px; top:0; }
        .stock-section .empty-item { color:#64748b; font-style:italic; }
    ';

    $fontHint = '<!-- لإضافة خط عربي من Google Fonts، يمكنك استخدام الرابط التالي (أزل التعليق إذا لزم الأمر):
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
-->';

    $body = '<div class="report-wrapper">'
        . '<header><h1>تقرير الكميات المنخفضة</h1><div class="meta">'
        . '<span>' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span>التاريخ: ' . date('Y-m-d H:i') . '</span></div></header>';

    if (!empty($summaryItems)) {
        $body .= '<section class="summary"><h2>ملخص الأقسام</h2><ul>' . $summaryItems . '</ul></section>';
    }

    $body .= $sectionsHtml . '</div>';

    $document = '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>تقرير الكميات المنخفضة</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $fontHint
        . '<style>' . $styles . '</style></head><body>' . $body . '</body></html>';

    try {
        apdfSavePdfToPath($document, $filePath, [
            'landscape' => false,
            'preferCSSPageSize' => true,
        ]);
    } catch (Throwable $e) {
        error_log('Low Stock Report: aPDF.io error - ' . $e->getMessage());
        return null;
    }

    return $filePath;
}

function formatLowStockCountLabel(string $key): string
{
    $labels = [
        'honey' => 'العسل الخام',
        'olive_oil' => 'زيت الزيتون',
        'beeswax' => 'شمع العسل',
        'derivatives' => 'المشتقات',
        'nuts' => 'المكسرات',
    ];

    return $labels[$key] ?? $key;
}
if (!defined('LOW_STOCK_REPORT_JOB_KEY')) {
    define('LOW_STOCK_REPORT_JOB_KEY', 'low_stock_report');
}
if (!defined('LOW_STOCK_REPORT_STATUS_SETTING_KEY')) {
    define('LOW_STOCK_REPORT_STATUS_SETTING_KEY', 'low_stock_report_status');
}

if (!function_exists('lowStockReportEnsureJobTable')) {
    /**
     * ضمان وجود جدول تتبع الوظائف اليومية.
     */
    function lowStockReportEnsureJobTable(): void
    {
        static $tableReady = false;

        if ($tableReady) {
            return;
        }

        try {
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
        } catch (Throwable $tableError) {
            error_log('Low Stock Report: failed ensuring job table - ' . $tableError->getMessage());
            return;
        }

        $tableReady = true;
    }
}

if (!function_exists('lowStockReportNotifyManager')) {
    /**
     * إرسال إشعار للمدير عند الحاجة.
     */
    function lowStockReportNotifyManager(string $message, string $type = 'info'): void
    {
        try {
            if (!function_exists('createNotificationForRole')) {
                require_once __DIR__ . '/notifications.php';
            }
        } catch (Throwable $includeError) {
            error_log('Low Stock Report: unable to include notifications - ' . $includeError->getMessage());
            return;
        }

        if (function_exists('createNotificationForRole')) {
            try {
                createNotificationForRole(
                    'manager',
                    'تقرير المخازن اليومي',
                    $message,
                    $type
                );
            } catch (Throwable $notifyError) {
                error_log('Low Stock Report: notification error - ' . $notifyError->getMessage());
            }
        }
    }
}

if (!function_exists('lowStockReportSaveStatus')) {
    /**
     * حفظ حالة التقرير في system_settings.
     *
     * @param array<string, mixed> $data
     */
    function lowStockReportSaveStatus(array $data): void
    {
        try {
            require_once __DIR__ . '/db.php';
            $db = db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->execute(
                "INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [LOW_STOCK_REPORT_STATUS_SETTING_KEY, $json]
            );
        } catch (Throwable $saveError) {
            error_log('Low Stock Report: status save error - ' . $saveError->getMessage());
        }
    }
}

if (!function_exists('triggerDailyLowStockReport')) {
    /**
     * تنفيذ فحص الكميات المنخفضة مرة واحدة يوميًا.
     */
    function triggerDailyLowStockReport(): void
    {
        // لا يتم التنفيذ في سطر الأوامر أو في حالة تعطيله صراحةً
        if (PHP_SAPI === 'cli' || defined('SKIP_LOW_STOCK_REPORT')) {
            return;
        }

        static $alreadyTriggered = false;
        if ($alreadyTriggered) {
            return;
        }
        $alreadyTriggered = true;

        $settingKey = LOW_STOCK_REPORT_STATUS_SETTING_KEY;
        $todayDate = date('Y-m-d');
        $statusData = [
            'date' => $todayDate,
            'status' => 'pending',
            'started_at' => date('Y-m-d H:i:s'),
        ];

        try {
            require_once __DIR__ . '/db.php';
        } catch (Throwable $e) {
            error_log('Low Stock Report: failed to include db.php - ' . $e->getMessage());
            return;
        }

        $db = db();

        lowStockReportEnsureJobTable();

        $jobState = null;
        try {
            $jobState = $db->queryOne(
                "SELECT last_sent_at, last_file_path FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
                [LOW_STOCK_REPORT_JOB_KEY]
            );
        } catch (Throwable $stateError) {
            error_log('Low Stock Report: job state error - ' . $stateError->getMessage());
        }

        if (!empty($jobState['last_sent_at'])) {
            $lastSentDate = substr((string)$jobState['last_sent_at'], 0, 10);
            if ($lastSentDate === $todayDate) {
                lowStockReportNotifyManager('تم إرسال تقرير المخازن إلى شات Telegram خلال هذا اليوم بالفعل.');
                lowStockReportSaveStatus([
                    'date' => $todayDate,
                    'status' => 'already_sent',
                    'checked_at' => date('Y-m-d H:i:s'),
                    'last_sent_at' => $jobState['last_sent_at'],
                    'file_path' => $jobState['last_file_path'] ?? null,
                ]);
                return;
            }
        }

        // منع التكرار خلال نفس اليوم باستخدام قفل بسيط
        try {
            $db->beginTransaction();
            $existing = $db->queryOne(
                "SELECT value FROM system_settings WHERE `key` = ? FOR UPDATE",
                [$settingKey]
            );

            $existingData = [];
            if ($existing && isset($existing['value'])) {
                $decoded = json_decode((string)$existing['value'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingData = $decoded;
                }
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                in_array($existingData['status'] ?? null, ['completed', 'completed_no_issues'], true)
            ) {
                $db->commit();
                return;
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                ($existingData['status'] ?? null) === 'running'
            ) {
                $startedAt = isset($existingData['started_at']) ? strtotime($existingData['started_at']) : 0;
                if ($startedAt && (time() - $startedAt) < 600) {
                    // تقرير قيد التنفيذ خلال آخر 10 دقائق
                    $db->commit();
                    return;
                }
            }

            $statusData['status'] = 'running';
            $statusDataJson = json_encode($statusData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $db->execute(
                "INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$settingKey, $statusDataJson]
            );
            $db->commit();
        } catch (Throwable $transactionError) {
            try {
                $db->rollback();
            } catch (Throwable $ignore) {
            }
            error_log('Low Stock Report: transaction error - ' . $transactionError->getMessage());
            return;
        }

        // تجميع البيانات
        require_once __DIR__ . '/honey_varieties.php';
        require_once __DIR__ . '/simple_telegram.php';

        $safeQuery = function (string $sql, array $params = []) use ($db): array {
            try {
                return $db->query($sql, $params);
            } catch (Throwable $queryError) {
                error_log('Low Stock Report: query failed - ' . $queryError->getMessage());
                return [];
            }
        };

        $sections = [];
        $counts = [
            'honey' => 0,
            'olive_oil' => 0,
            'beeswax' => 0,
            'derivatives' => 0,
            'nuts' => 0,
        ];

        $honeyRows = $safeQuery(
            "SELECT hs.id, COALESCE(s.name, 'غير معروف') AS supplier_name, hs.honey_variety, hs.raw_honey_quantity
             FROM honey_stock hs
             LEFT JOIN suppliers s ON hs.supplier_id = s.id
             WHERE hs.raw_honey_quantity IS NOT NULL AND hs.raw_honey_quantity < 10
             ORDER BY hs.raw_honey_quantity ASC"
        );
        if (!empty($honeyRows)) {
            $counts['honey'] = count($honeyRows);
            $lines = [];
            foreach ($honeyRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $variety = trim($row['honey_variety'] ?? '') ?: 'أخرى';
                $varietyLabel = formatHoneyVarietyWithCode($variety);
                $quantity = number_format((float)($row['raw_honey_quantity'] ?? 0), 2);
                $lines[] = "- المورد: {$supplier} | النوع: {$varietyLabel} | الكمية: {$quantity} كجم";
            }
            $sections[] = [
                'title' => 'العسل الخام (أقل من 10 كجم)',
                'lines' => $lines,
            ];
        }

        $oliveRows = $safeQuery(
            "SELECT os.id, COALESCE(s.name, 'غير معروف') AS supplier_name, os.quantity
             FROM olive_oil_stock os
             LEFT JOIN suppliers s ON os.supplier_id = s.id
             WHERE os.quantity IS NOT NULL AND os.quantity < 10
             ORDER BY os.quantity ASC"
        );
        if (!empty($oliveRows)) {
            $counts['olive_oil'] = count($oliveRows);
            $lines = [];
            foreach ($oliveRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['quantity'] ?? 0), 2);
                $lines[] = "- المورد: {$supplier} | الكمية: {$quantity} لتر";
            }
            $sections[] = [
                'title' => 'زيت الزيتون (أقل من 10 لتر)',
                'lines' => $lines,
            ];
        }

        $beeswaxRows = $safeQuery(
            "SELECT bs.id, COALESCE(s.name, 'غير معروف') AS supplier_name, bs.weight
             FROM beeswax_stock bs
             LEFT JOIN suppliers s ON bs.supplier_id = s.id
             WHERE bs.weight IS NOT NULL AND bs.weight < 10
             ORDER BY bs.weight ASC"
        );
        if (!empty($beeswaxRows)) {
            $counts['beeswax'] = count($beeswaxRows);
            $lines = [];
            foreach ($beeswaxRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['weight'] ?? 0), 2);
                $lines[] = "- المورد: {$supplier} | الكمية: {$quantity} كجم";
            }
            $sections[] = [
                'title' => 'شمع العسل (أقل من 10 كجم)',
                'lines' => $lines,
            ];
        }

        $derivativeRows = $safeQuery(
            "SELECT ds.id, COALESCE(s.name, 'غير معروف') AS supplier_name, ds.derivative_type, ds.weight
             FROM derivatives_stock ds
             LEFT JOIN suppliers s ON ds.supplier_id = s.id
             WHERE ds.weight IS NOT NULL AND ds.weight < 1
             ORDER BY ds.weight ASC"
        );
        if (!empty($derivativeRows)) {
            $counts['derivatives'] = count($derivativeRows);
            $lines = [];
            foreach ($derivativeRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $type = trim($row['derivative_type'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['weight'] ?? 0), 3);
                $lines[] = "- المورد: {$supplier} | المشتق: {$type} | الكمية: {$quantity} كجم";
            }
            $sections[] = [
                'title' => 'المشتقات (أقل من 1 كجم)',
                'lines' => $lines,
            ];
        }

        $nutsRows = $safeQuery(
            "SELECT ns.id, COALESCE(s.name, 'غير معروف') AS supplier_name, ns.nut_type, ns.quantity
             FROM nuts_stock ns
             LEFT JOIN suppliers s ON ns.supplier_id = s.id
             WHERE ns.quantity IS NOT NULL AND ns.quantity < 10
             ORDER BY ns.quantity ASC"
        );
        if (!empty($nutsRows)) {
            $counts['nuts'] = count($nutsRows);
            $lines = [];
            foreach ($nutsRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $type = trim($row['nut_type'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['quantity'] ?? 0), 3);
                $lines[] = "- المورد: {$supplier} | النوع: {$type} | الكمية: {$quantity} كجم";
            }
            $sections[] = [
                'title' => 'المكسرات المنفردة (أقل من 10 كجم)',
                'lines' => $lines,
            ];
        }

        $status = 'completed_no_issues';
        $errorMessage = null;
        $reportFilePath = null;

        if (!empty($sections)) {
            $status = 'completed';
            $reportFilePath = dailyLowStockGeneratePdf($sections, $counts);

            if ($reportFilePath === null) {
                $status = 'failed';
                $errorMessage = 'فشل إنشاء ملف PDF للتقرير.';
            } else {
                if (!isTelegramConfigured()) {
                    $status = 'failed';
                    $errorMessage = 'إعدادات Telegram غير مكتملة';
                } else {
                    $caption = "⚠️ تقرير الكميات المنخفضة\nالتاريخ: " . date('Y-m-d H:i:s');
                    $sendResult = sendTelegramFile($reportFilePath, $caption);
                    if ($sendResult === false) {
                        $status = 'failed';
                        $errorMessage = 'فشل إرسال التقرير إلى Telegram';
                    } else {
                        if ($reportFilePath && file_exists($reportFilePath)) {
                            @unlink($reportFilePath);
                        }
                        $reportFilePath = null;
                    }
                }
            }
        }

        // تحديث حالة التنفيذ في system_settings
        try {
            $finalData = [
                'date' => $todayDate,
                'status' => $status,
                'completed_at' => date('Y-m-d H:i:s'),
                'counts' => $counts,
                'file_deleted' => ($status === 'completed'),
            ];
            if (!empty($errorMessage)) {
                $finalData['error'] = $errorMessage;
            }

            if ($status === 'completed') {
                lowStockReportNotifyManager('تم إرسال تقرير المخازن منخفضة الكمية إلى شات Telegram.');
                try {
                    if ($jobState) {
                        $db->execute(
                            "UPDATE system_daily_jobs SET last_sent_at = NOW(), last_file_path = NULL, updated_at = NOW() WHERE job_key = ?",
                            [LOW_STOCK_REPORT_JOB_KEY]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path) VALUES (?, NOW(), NULL)",
                            [LOW_STOCK_REPORT_JOB_KEY]
                        );
                    }
                } catch (Throwable $jobUpdateError) {
                    error_log('Low Stock Report: job state update failed - ' . $jobUpdateError->getMessage());
                }
            }

            lowStockReportSaveStatus($finalData);
        } catch (Throwable $updateError) {
            error_log('Low Stock Report: status update failed - ' . $updateError->getMessage());
        }
    }
}

