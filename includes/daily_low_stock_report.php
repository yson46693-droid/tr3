<?php
/**
 * تقرير الكميات المنخفضة اليومي وإرساله إلى Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
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
        $savedFilePath = null;
        $reportFileName = null;

        if (!empty($sections)) {
            $status = 'completed';
            $reportLines = [];
            $reportLines[] = 'تقرير الكميات المنخفضة';
            $reportLines[] = 'التاريخ: ' . date('d/m/Y H:i');
            $reportLines[] = str_repeat('-', 50);

            foreach ($sections as $section) {
                $reportLines[] = $section['title'];
                foreach ($section['lines'] as $line) {
                    $reportLines[] = $line;
                }
                $reportLines[] = '';
            }

            $reportContent = implode(PHP_EOL, $reportLines);

            $reportsDirectory = REPORTS_PATH ?? (dirname(__DIR__) . '/reports/');
            if (!is_dir($reportsDirectory)) {
                @mkdir($reportsDirectory, 0775, true);
            }

            $reportFileName = 'low_stock_report_' . date('Ymd_His') . '.txt';
            $savedFilePath = rtrim($reportsDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $reportFileName;

            try {
                $written = file_put_contents($savedFilePath, $reportContent, LOCK_EX);
                if ($written === false) {
                    throw new RuntimeException('فشل إنشاء ملف التقرير');
                }
            } catch (Throwable $fileError) {
                $status = 'failed';
                $errorMessage = 'فشل إنشاء ملف التقرير: ' . $fileError->getMessage();
                $savedFilePath = null;
            }

            if ($savedFilePath !== null) {
                if (!isTelegramConfigured()) {
                    $status = 'failed';
                    $errorMessage = 'إعدادات Telegram غير مكتملة';
                } else {
                    $caption = "⚠️ تقرير الكميات المنخفضة\nالتاريخ: " . date('Y-m-d H:i:s');
                    $sendResult = sendTelegramFile($savedFilePath, $caption);
                    if ($sendResult === false) {
                        $status = 'failed';
                        $errorMessage = 'فشل إرسال التقرير إلى Telegram';
                    } else {
                        if (REPORTS_AUTO_DELETE && file_exists($savedFilePath)) {
                            @unlink($savedFilePath);
                            $savedFilePath = null;
                        }
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
                'file' => $reportFileName,
                'counts' => $counts,
            ];
            if (!empty($errorMessage)) {
                $finalData['error'] = $errorMessage;
            }
            if ($savedFilePath !== null) {
                $finalData['file_path'] = $savedFilePath;
            }

            $finalDataJson = json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->execute(
                "UPDATE system_settings SET value = ? WHERE `key` = ?",
                [$finalDataJson, $settingKey]
            );
        } catch (Throwable $updateError) {
            error_log('Low Stock Report: status update failed - ' . $updateError->getMessage());
        }
    }
}


