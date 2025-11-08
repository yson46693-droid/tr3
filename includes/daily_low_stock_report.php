<?php
/**
 * تقرير الكميات المنخفضة اليومي وإرساله إلى Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
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

    $lines = [];
    $lines[] = 'تقرير الكميات المنخفضة';
    $lines[] = 'التاريخ: ' . date('Y-m-d H:i');
    $lines[] = str_repeat('=', 70);
    $lines[] = 'ملخص الأقسام:';
    foreach ($counts as $key => $value) {
        $lines[] = sprintf('• %s: %d عنصر منخفض', formatLowStockCountLabel($key), (int)$value);
    }
    $lines[] = str_repeat('-', 70);

    foreach ($sections as $section) {
        $title = $section['title'] ?? 'قسم غير محدد';
        $lines[] = $title;
        $sectionLines = $section['lines'] ?? [];
        if (empty($sectionLines)) {
            $lines[] = '  لا توجد عناصر منخفضة في هذا القسم.';
        } else {
            foreach ($sectionLines as $detail) {
                $lines[] = '  ' . ltrim($detail);
            }
        }
        $lines[] = '';
    }

    $pdfContent = dailyLowStockBuildPdf($lines);
    if ($pdfContent === null) {
        return null;
    }

    $bytes = @file_put_contents($filePath, $pdfContent);
    if ($bytes === false || $bytes === 0) {
        error_log('Low Stock Report: unable to write PDF - ' . $filePath);
        return null;
    }

    return $filePath;
}

/**
 * بناء محتوى PDF بسيط يدعم العربية
 *
 * @param array<int, string> $lines
 * @return string|null
 */
function dailyLowStockBuildPdf(array $lines): ?string
{
    if (empty($lines)) {
        return null;
    }

    $pageWidth = 595.0;
    $pageHeight = 842.0;
    $marginX = 40.0;
    $startY = $pageHeight - 60.0;
    $lineSpacing = 22.0;

    $contentParts = [];
    $yPos = $startY;

    foreach ($lines as $index => $line) {
        if ($yPos < 60.0) {
            break;
        }

        $fontSize = $index === 0 ? 20 : ($index <= 2 ? 14 : 12);
        $prepared = dailyLowStockNormalizePdfText($line);
        $hex = strtoupper(bin2hex(dailyLowStockUtf16beText($prepared, true)));

        $contentParts[] = "BT\n/F1 {$fontSize} Tf\n1 0 0 1 " . ($pageWidth - $marginX) . " {$yPos} Tm\n<{$hex}> Tj\nET\n";
        $yPos -= $lineSpacing;
    }

    $contentStream = implode('', $contentParts);
    $contentLength = strlen($contentStream);
    $toUnicode = dailyLowStockBuildToUnicodeCMap();

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents 7 0 R /Resources << /Font << /F1 4 0 R >> >> >> endobj\n";
    $objects[] = "4 0 obj << /Type /Font /Subtype /Type0 /BaseFont /TimesNewRomanPSMT /Encoding /Identity-H /DescendantFonts [5 0 R] /ToUnicode 6 0 R >> endobj\n";
    $objects[] = "5 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /TimesNewRomanPSMT /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 8 0 R /CIDToGIDMap /Identity /DW 1000 >> endobj\n";
    $objects[] = "6 0 obj << /Length " . strlen($toUnicode) . " >> stream\n{$toUnicode}endstream\nendobj\n";
    $objects[] = "7 0 obj << /Length {$contentLength} >> stream\n{$contentStream}endstream\nendobj\n";
    $objects[] = "8 0 obj << /Type /FontDescriptor /FontName /TimesNewRomanPSMT /Flags 32 /ItalicAngle 0 /Ascent 1024 /Descent -300 /CapHeight 800 /StemV 80 /FontBBox [-1024 -1024 3072 3072] >> endobj\n";

    $pdf = "%PDF-1.7\n";
    $offsets = [];
    $currentOffset = strlen($pdf);

    foreach ($objects as $object) {
        $offsets[] = $currentOffset;
        $pdf .= $object;
        $currentOffset += strlen($object);
    }

    $xrefPosition = $currentOffset;
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPosition}\n%%EOF";

    return $pdf;
}

function dailyLowStockNormalizePdfText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function dailyLowStockUtf16beText(string $text, bool $withBom = false): string
{
    $result = $withBom ? "\xFE\xFF" : '';
    $directional = "\u{202B}" . $text . "\u{202C}";
    $result .= mb_convert_encoding($directional, 'UTF-16BE', 'UTF-8');
    return $result;
}

function dailyLowStockBuildToUnicodeCMap(): string
{
    return <<<CMAP
/CIDInit /ProcSet findresource begin
12 dict begin
begincmap
/CIDSystemInfo
<< /Registry (Adobe)
   /Ordering (Identity)
   /Supplement 0
>> def
/CMapName /Adobe-Identity-UCS def
/CMapType 2 def
1 begincodespacerange
<0000> <FFFF>
endcodespacerange
1 beginbfrange
<0000> <FFFF> <0000>
endbfrange
endcmap
CMapName currentdict /CMap defineresource pop
end
end
CMAP;
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

const LOW_STOCK_REPORT_JOB_KEY = 'low_stock_report';

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
    function lowStockReportNotifyManager(string $message): void
    {
        if (function_exists('createNotificationForRole')) {
            try {
                createNotificationForRole(
                    'manager',
                    'تقرير المخازن اليومي',
                    $message,
                    'info'
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
    function lowStockReportSaveStatus($db, string $settingKey, array $data): void
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->execute(
                "INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$settingKey, $json]
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

        $settingKey = 'low_stock_report_status';
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
                lowStockReportSaveStatus($db, $settingKey, [
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

            lowStockReportSaveStatus($db, $settingKey, $finalData);
        } catch (Throwable $updateError) {
            error_log('Low Stock Report: status update failed - ' . $updateError->getMessage());
        }
    }
}
