<?php
/**
 * تقرير الكميات المنخفضة اليومي وإرساله إلى Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/pdf_helper.php';
require_once __DIR__ . '/path_helper.php';

if (!function_exists('lowStockReportFileMatchesDate')) {
    /**
     * يتحقق أن ملف التقرير يتبع تاريخاً معيناً بناءً على آخر تعديل.
     */
    function lowStockReportFileMatchesDate(string $path, string $targetDate): bool
    {
        if (!is_file($path)) {
            return false;
        }
        return date('Y-m-d', (int)filemtime($path)) === $targetDate;
    }
}
/**
 * إزالة ملفات التقارير الأقدم والاحتفاظ بآخر تقرير فقط.
 *
 * @param string $reportsDir
 * @param string $currentFilename
 * @return void
 */
function dailyLowStockCleanupOldReports(string $reportsDir, string $currentFilename): void
{
    $pattern = rtrim($reportsDir, '/\\') . '/low-stock-report-*.html';
    $files = glob($pattern) ?: [];

    foreach ($files as $file) {
        if (!is_string($file)) {
            continue;
        }

        if (basename($file) === $currentFilename) {
            continue;
        }

        @unlink($file);
    }
}


/**
 * إنشاء ملف PDF لتقرير الكميات المنخفضة
 *
 * @param array<int, array<string, mixed>> $sections
 * @param array<string, int> $counts
 * @return string|null
 */
function dailyLowStockGeneratePdf(array $sections, array $counts): ?string
{
    $baseReportsPath = defined('REPORTS_PRIVATE_PATH')
        ? REPORTS_PRIVATE_PATH
        : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports/'));
    $reportsDir = rtrim($baseReportsPath, '/\\') . '/low_stock';
    if (!is_dir($reportsDir)) {
        @mkdir($reportsDir, 0755, true);
    }
    if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
        error_log('Low Stock Report: reports directory not writable - ' . $reportsDir);
        return null;
    }

    $filename = sprintf('low-stock-report-%s.html', date('Ymd-His'));
    $filePath = $reportsDir . DIRECTORY_SEPARATOR . $filename;

    $summaryItems = '';
    foreach ($counts as $key => $value) {
        $label = formatLowStockCountLabel($key);
        $liClass = $value > 0 ? '' : ' class="no-data"';
        $valueClass = $value > 0 ? 'value' : 'value zero';
        $summaryItems .= '<li' . $liClass . '><span class="label">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</span><span class="' . $valueClass . '">' . intval($value) . '</span></li>';
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
        .actions { display:flex; flex-direction:column; gap:8px; align-items:flex-start; margin-bottom:20px; }
        .actions button { background:#1d4ed8; color:#fff; border:none; padding:10px 18px; border-radius:10px; font-size:15px; cursor:pointer; transition:opacity 0.2s ease; }
        .actions button:hover { opacity:0.9; }
        .actions .hint { font-size:13px; color:#475569; }
        header { text-align: center; margin-bottom: 24px; }
        header h1 { margin: 0; font-size: 26px; color:#1d4ed8; }
        header .meta { display:flex; justify-content:center; gap:16px; flex-wrap:wrap; margin-top:12px; color:#475569; font-size:14px; }
        header .meta span { background:#e2e8f0; padding:6px 14px; border-radius:999px; }
        .summary { background:#1d4ed8; color:#ffffff; padding:18px 24px; border-radius:14px; margin-bottom:28px; }
        .summary h2 { margin:0 0 12px; font-size:18px; }
        .summary ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
        .summary li { display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.15); padding:12px 16px; border-radius:12px; font-size:15px; }
        .summary li.no-data { background:rgba(255,255,255,0.08); border:1px dashed rgba(255,255,255,0.35); }
        .summary .label { font-weight:600; }
        .summary .value { font-size:17px; font-weight:700; }
        .summary .value.zero { color:#fde68a; font-weight:600; }
        .stock-section { margin-bottom:24px; padding:20px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; }
        .stock-section h3 { margin:0 0 12px; font-size:18px; color:#0f172a; }
        .stock-section ul { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; font-size:14px; color:#1f2937; }
        .stock-section li { position:relative; padding-right:24px; }
        .stock-section li::before { content:"•"; position:absolute; right:0; color:#1d4ed8; font-size:18px; top:0; }
        .stock-section .empty-item { color:#64748b; font-style:italic; }
        @media print {
            .actions { display:none !important; }
            body { background:#ffffff; }
        }
    ';

    $fontHint = '<!-- لإضافة خط عربي من Google Fonts، يمكنك استخدام الرابط التالي (أزل التعليق إذا لزم الأمر):
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
-->';

    $body = '<div class="report-wrapper">'
        . '<div class="actions">'
        . '<button id="printReportButton" type="button">طباعة / حفظ كـ PDF</button>'
        . '<span class="hint">يمكنك أيضاً استخدام الرابط المرسل عبر Telegram لعرض التقرير وطباعته.</span>'
        . '</div>'
        . '<header><h1>تقرير الكميات المنخفضة</h1><div class="meta">'
        . '<span>' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span>التاريخ: ' . date('Y-m-d H:i') . '</span></div></header>';

    if (!empty($summaryItems)) {
        $body .= '<section class="summary"><h2>ملخص الأقسام</h2><ul>' . $summaryItems . '</ul></section>';
    }

    $body .= $sectionsHtml . '</div>';

    $printScript = '<script>(function(){function triggerPrint(){window.print();}'
        . 'document.addEventListener("DOMContentLoaded",function(){var btn=document.getElementById("printReportButton");'
        . 'if(btn){btn.addEventListener("click",function(e){e.preventDefault();triggerPrint();});}'
        . 'var params=new URLSearchParams(window.location.search);'
        . 'if(params.get("print")==="1"){setTimeout(triggerPrint,700);}'
        . '});})();</script>';

    $document = '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>تقرير الكميات المنخفضة</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $fontHint
        . '<style>' . $styles . '</style></head><body>' . $body . $printScript . '</body></html>';

    if (@file_put_contents($filePath, $document) === false) {
        error_log('Low Stock Report: unable to write HTML report to ' . $filePath);
        return null;
    }

    dailyLowStockCleanupOldReports($reportsDir, $filename);

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

        $reportsBaseDir = rtrim(
            defined('REPORTS_PRIVATE_PATH') ? REPORTS_PRIVATE_PATH : (defined('REPORTS_PATH') ? REPORTS_PATH : BASE_PATH . '/reports'),
            '/\\'
        );

        $statusSnapshot = [];
        $existingReportPath = null;
        $existingViewerPath = null;
        $existingAccessToken = null;
        try {
            $rawStatus = $db->queryOne(
                "SELECT value FROM system_settings WHERE `key` = ? LIMIT 1",
                [LOW_STOCK_REPORT_STATUS_SETTING_KEY]
            );
            if ($rawStatus && isset($rawStatus['value'])) {
                $decoded = json_decode((string)$rawStatus['value'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $statusSnapshot = $decoded;
                    if (
                        ($decoded['date'] ?? null) === $todayDate &&
                        !empty($decoded['report_path'])
                    ) {
                        $candidate = $reportsBaseDir . '/' . ltrim((string)$decoded['report_path'], '/\\');
                        if (lowStockReportFileMatchesDate($candidate, $todayDate)) {
                            $existingReportPath = $candidate;
                            $existingViewerPath = (string)($decoded['viewer_path'] ?? '');
                            $existingAccessToken = (string)($decoded['access_token'] ?? '');
                        }
                    }
                }
            }
        } catch (Throwable $statusError) {
            error_log('Low Stock Report: status fetch error - ' . $statusError->getMessage());
        }

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
            $jobRelativePath = (string)($jobState['last_file_path'] ?? '');
            $jobCandidate = $jobRelativePath !== ''
                ? $reportsBaseDir . '/' . ltrim($jobRelativePath, '/\\')
                : null;

            if ($lastSentDate === $todayDate) {
                $hasValidSnapshotFile = $existingReportPath !== null && lowStockReportFileMatchesDate($existingReportPath, $todayDate);
                $hasValidJobFile = $jobCandidate !== null && lowStockReportFileMatchesDate($jobCandidate, $todayDate);

                if (($hasValidSnapshotFile || $hasValidJobFile) && !empty($statusSnapshot)) {
                    $statusSnapshot['status'] = 'already_sent';
                    $statusSnapshot['checked_at'] = date('Y-m-d H:i:s');
                    $statusSnapshot['last_sent_at'] = $jobState['last_sent_at'];
                    if ($hasValidJobFile && !$hasValidSnapshotFile && $jobRelativePath !== '') {
                        $statusSnapshot['report_path'] = $jobRelativePath;
                    }
                    lowStockReportSaveStatus($statusSnapshot);
                    return;
                }
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
            $existingDataHasFile = false;
            if ($existing && isset($existing['value'])) {
                $decoded = json_decode((string)$existing['value'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $existingData = $decoded;
                    if (
                        ($decoded['date'] ?? null) === $todayDate &&
                        !empty($decoded['report_path'])
                    ) {
                        $candidateExisting = $reportsBaseDir . '/' . ltrim((string)$decoded['report_path'], '/\\');
                        if (lowStockReportFileMatchesDate($candidateExisting, $todayDate)) {
                            $existingDataHasFile = true;
                        }
                    }
                }
            }

            if (
                !empty($existingData) &&
                ($existingData['date'] ?? null) === $todayDate &&
                in_array($existingData['status'] ?? null, ['completed', 'completed_no_issues', 'already_sent'], true) &&
                $existingDataHasFile
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
        $counts['honey'] = count($honeyRows);
        $honeyLines = [];
        if (!empty($honeyRows)) {
            foreach ($honeyRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $variety = trim($row['honey_variety'] ?? '') ?: 'أخرى';
                $varietyLabel = formatHoneyVarietyWithCode($variety);
                $quantity = number_format((float)($row['raw_honey_quantity'] ?? 0), 2);
                $honeyLines[] = "- المورد: {$supplier} | النوع: {$varietyLabel} | الكمية: {$quantity} كجم";
            }
        }
        $sections[] = [
            'title' => 'العسل الخام (أقل من 10 كجم)',
            'lines' => $honeyLines,
        ];

        $oliveRows = $safeQuery(
            "SELECT os.id, COALESCE(s.name, 'غير معروف') AS supplier_name, os.quantity
             FROM olive_oil_stock os
             LEFT JOIN suppliers s ON os.supplier_id = s.id
             WHERE os.quantity IS NOT NULL AND os.quantity < 10
             ORDER BY os.quantity ASC"
        );
        $counts['olive_oil'] = count($oliveRows);
        $oliveLines = [];
        if (!empty($oliveRows)) {
            foreach ($oliveRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['quantity'] ?? 0), 2);
                $oliveLines[] = "- المورد: {$supplier} | الكمية: {$quantity} لتر";
            }
        }
        $sections[] = [
            'title' => 'زيت الزيتون (أقل من 10 لتر)',
            'lines' => $oliveLines,
        ];

        $beeswaxRows = $safeQuery(
            "SELECT bs.id, COALESCE(s.name, 'غير معروف') AS supplier_name, bs.weight
             FROM beeswax_stock bs
             LEFT JOIN suppliers s ON bs.supplier_id = s.id
             WHERE bs.weight IS NOT NULL AND bs.weight < 10
             ORDER BY bs.weight ASC"
        );
        $counts['beeswax'] = count($beeswaxRows);
        $beeswaxLines = [];
        if (!empty($beeswaxRows)) {
            foreach ($beeswaxRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['weight'] ?? 0), 2);
                $beeswaxLines[] = "- المورد: {$supplier} | الكمية: {$quantity} كجم";
            }
        }
        $sections[] = [
            'title' => 'شمع العسل (أقل من 10 كجم)',
            'lines' => $beeswaxLines,
        ];

        $derivativeRows = $safeQuery(
            "SELECT ds.id, COALESCE(s.name, 'غير معروف') AS supplier_name, ds.derivative_type, ds.weight
             FROM derivatives_stock ds
             LEFT JOIN suppliers s ON ds.supplier_id = s.id
             WHERE ds.weight IS NOT NULL AND ds.weight < 1
             ORDER BY ds.weight ASC"
        );
        $counts['derivatives'] = count($derivativeRows);
        $derivativeLines = [];
        if (!empty($derivativeRows)) {
            foreach ($derivativeRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $type = trim($row['derivative_type'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['weight'] ?? 0), 3);
                $derivativeLines[] = "- المورد: {$supplier} | المشتق: {$type} | الكمية: {$quantity} كجم";
            }
        }
        $sections[] = [
            'title' => 'المشتقات (أقل من 1 كجم)',
            'lines' => $derivativeLines,
        ];

        $nutsRows = $safeQuery(
            "SELECT ns.id, COALESCE(s.name, 'غير معروف') AS supplier_name, ns.nut_type, ns.quantity
             FROM nuts_stock ns
             LEFT JOIN suppliers s ON ns.supplier_id = s.id
             WHERE ns.quantity IS NOT NULL AND ns.quantity < 10
             ORDER BY ns.quantity ASC"
        );
        $counts['nuts'] = count($nutsRows);
        $nutsLines = [];
        if (!empty($nutsRows)) {
            foreach ($nutsRows as $row) {
                $supplier = trim($row['supplier_name'] ?? '') ?: 'غير محدد';
                $type = trim($row['nut_type'] ?? '') ?: 'غير محدد';
                $quantity = number_format((float)($row['quantity'] ?? 0), 3);
                $nutsLines[] = "- المورد: {$supplier} | النوع: {$type} | الكمية: {$quantity} كجم";
            }
        }
        $sections[] = [
            'title' => 'المكسرات المنفردة (أقل من 10 كجم)',
            'lines' => $nutsLines,
        ];

        $status = 'completed_no_issues';
        $errorMessage = null;
        $reportFilePath = null;
        $relativePath = null;
        $existingReportPath = null;
        $existingReportRelative = null;
        $accessToken = null;
        $viewerPath = null;
        $absoluteReportUrl = null;
        $absolutePrintUrl = null;
        $existingAccessToken = null;
        $existingViewerPath = null;
        if (!empty($existingData) && ($existingData['date'] ?? null) === $todayDate) {
            $storedPath = $existingData['report_path'] ?? null;
            if (!empty($storedPath)) {
                $candidate = $reportsBaseDir . '/' . ltrim($storedPath, '/\\');
                if (lowStockReportFileMatchesDate($candidate, $todayDate)) {
                    $existingReportPath = $candidate;
                    $existingReportRelative = ltrim($storedPath, '/\\');
                }
            }
            if (!empty($existingData['access_token'])) {
                $existingAccessToken = (string) $existingData['access_token'];
            }
            if (!empty($existingData['viewer_path'])) {
                $existingViewerPath = (string) $existingData['viewer_path'];
            }
        }

        if (!empty($sections)) {
            $status = 'completed';
            if ($existingReportPath !== null) {
                $reportFilePath = $existingReportPath;
                $relativePath = $existingReportRelative;
            } else {
                $reportFilePath = dailyLowStockGeneratePdf($sections, $counts);
                if ($reportFilePath !== null) {
                    if (strpos($reportFilePath, $reportsBaseDir) === 0) {
                        $relativePath = ltrim(substr($reportFilePath, strlen($reportsBaseDir)), '/\\');
                    } else {
                        $relativePath = basename($reportFilePath);
                    }
                }
            }

            if ($reportFilePath === null || $relativePath === null) {
                $status = 'failed';
                $errorMessage = 'فشل إنشاء ملف HTML للتقرير.';
            } else {
                $accessToken = $existingAccessToken;
                if (empty($accessToken)) {
                    try {
                        $accessToken = bin2hex(random_bytes(16));
                    } catch (Throwable $tokenError) {
                        $accessToken = sha1($relativePath . microtime(true) . mt_rand());
                        error_log('Low Stock Report: random_bytes failed, using fallback token - ' . $tokenError->getMessage());
                    }
                }

                $viewerPath = $existingViewerPath;
                if (empty($viewerPath) || strpos($viewerPath, 'reports/view.php') !== 0) {
                    $viewerPath = 'reports/view.php?' . http_build_query(
                        [
                            'type' => 'low_stock',
                            'token' => $accessToken,
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
                }

                $reportUrl = getRelativeUrl($viewerPath);
                $printUrl = $reportUrl . (strpos($reportUrl, '?') === false ? '?print=1' : '&print=1');

                $absoluteReportUrl = getAbsoluteUrl($viewerPath);
                $absolutePrintUrl = $absoluteReportUrl . (strpos($absoluteReportUrl, '?') === false ? '?print=1' : '&print=1');

                $message = "⚠️ <b>تقرير الكميات المنخفضة</b>\n";
                $message .= 'التاريخ: ' . date('Y-m-d H:i:s');
                foreach ($counts as $key => $value) {
                    $message .= "\n• " . formatLowStockCountLabel($key) . ': ' . intval($value);
                }
                $message .= "\n\n✅ التقرير محفوظ في النظام ويمكن عرضه أو طباعته من خلال الأزرار التالية.";

                if (!isTelegramConfigured()) {
                    $status = 'failed';
                    $errorMessage = 'إعدادات Telegram غير مكتملة';
                } else {
                    $buttons = [
                        [
                            ['text' => 'عرض التقرير', 'url' => $absoluteReportUrl],
                            ['text' => 'طباعة / حفظ PDF', 'url' => $absolutePrintUrl],
                        ],
                    ];

                    $sendResult = sendTelegramMessageWithButtons($message, $buttons);
                    if (empty($sendResult['success'])) {
                        $status = 'failed';
                        $errorMessage = 'فشل إرسال رابط التقرير إلى Telegram'
                            . (!empty($sendResult['error']) ? ' (' . $sendResult['error'] . ')' : '');
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
                'file_deleted' => false,
            ];
            if (!empty($errorMessage)) {
                $finalData['error'] = $errorMessage;
            }
            if (!empty($relativePath)) {
                $finalData['report_path'] = $relativePath;
            }
            if (!empty($viewerPath)) {
                $finalData['viewer_path'] = $viewerPath;
            }
            if (!empty($accessToken)) {
                $finalData['access_token'] = $accessToken;
            }
            if (isset($reportUrl)) {
                $finalData['report_url'] = $reportUrl;
                $finalData['print_url'] = $printUrl ?? $reportUrl;
            }
            if (!empty($absoluteReportUrl)) {
                $finalData['absolute_report_url'] = $absoluteReportUrl;
                $finalData['absolute_print_url'] = $absolutePrintUrl ?? $absoluteReportUrl;
            }

            if ($status === 'completed') {
                lowStockReportNotifyManager('تم إرسال تقرير المخازن منخفضة الكمية إلى شات Telegram.');
                try {
                    if ($jobState) {
                        $db->execute(
                            "UPDATE system_daily_jobs SET last_sent_at = NOW(), last_file_path = ?, updated_at = NOW() WHERE job_key = ?",
                            [$relativePath, LOW_STOCK_REPORT_JOB_KEY]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path) VALUES (?, NOW(), ?)",
                            [LOW_STOCK_REPORT_JOB_KEY, $relativePath]
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

