<?php
/**
 * نظام التقارير - مبسط
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/simple_export.php';
require_once __DIR__ . '/telegram_config.php';

/**
 * توليد تقرير PDF (HTML للطباعة)
 */
function generatePDFReport($type, $data, $title, $filters = []) {
    try {
        $reportInfo = exportPDF($data, $title, $filters);
        
        // حفظ في قاعدة البيانات
        $db = db();
        require_once __DIR__ . '/auth.php';
        $currentUser = getCurrentUser();
        $userId = $currentUser['id'] ?? null;
        
        if ($userId) {
            try {
                $db->execute(
                    "INSERT INTO reports (user_id, type, file_path, file_type, telegram_sent) 
                     VALUES (?, ?, ?, 'pdf', 0)",
                    [
                        $userId,
                        $type,
                        $reportInfo['relative_path'] ?? ($reportInfo['file_path'] ?? '')
                    ]
                );
            } catch (Exception $dbError) {
                error_log("Failed to save report to database: " . $dbError->getMessage());
            }
        }
        
        return $reportInfo;
    } catch (Exception $e) {
        error_log("PDF Report Generation Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * توليد تقرير Excel (CSV)
 */
function generateExcelReport($type, $data, $title, $filters = []) {
    try {
        $filePath = exportCSV($data, $title, $filters);
        
        // حفظ في قاعدة البيانات
        $db = db();
        require_once __DIR__ . '/auth.php';
        $currentUser = getCurrentUser();
        $userId = $currentUser['id'] ?? null;
        
        if ($userId) {
            try {
                $db->execute(
                    "INSERT INTO reports (user_id, type, file_path, file_type, telegram_sent) 
                     VALUES (?, ?, ?, 'excel', 0)",
                    [$userId, $type, $filePath]
                );
            } catch (Exception $dbError) {
                error_log("Failed to save report to database: " . $dbError->getMessage());
            }
        }
        
        return $filePath;
    } catch (Exception $e) {
        error_log("Excel Report Generation Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إرسال تقرير إلى Telegram وحذفه
 */
function sendReportAndDelete($report, $reportType, $reportName) {
    require_once __DIR__ . '/simple_telegram.php';
    require_once __DIR__ . '/../telegram_bot/send_message.php';

    // في حال كان التقرير مصفوفة تحتوي على روابط HTML (النظام الجديد)
    if (is_array($report) && isset($report['absolute_report_url'])) {
        if (!isTelegramConfigured()) {
            return ['success' => false, 'message' => 'Telegram Bot غير مُعد'];
        }

        $title = trim($reportName) !== '' ? $reportName : $reportType;
        $generatedAt = $report['generated_at'] ?? date('Y-m-d H:i:s');
        $totalRows = intval($report['total_rows'] ?? 0);

        $message = "📊 <b>{$title}</b>\n";
        $message .= 'التاريخ: ' . $generatedAt . "\n";
        $message .= 'عدد السجلات: ' . $totalRows . "\n";

        if (!empty($report['summary']) && is_array($report['summary'])) {
            $summaryLines = [];
            if (isset($report['summary']['packaging'])) {
                $summaryLines[] = 'استهلاك التعبئة: ' . number_format((float)$report['summary']['packaging'], 3) . ' كجم';
            }
            if (isset($report['summary']['raw'])) {
                $summaryLines[] = 'استهلاك المواد الخام: ' . number_format((float)$report['summary']['raw'], 3) . ' كجم';
            }
            if (isset($report['summary']['net'])) {
                $summaryLines[] = 'الصافي الكلي: ' . number_format((float)$report['summary']['net'], 3) . ' كجم';
            }
            if (!empty($summaryLines)) {
                $message .= implode("\n", array_map(fn($line) => '• ' . $line, $summaryLines)) . "\n";
            }
        }

        $message .= "\n✅ التقرير جاهز للعرض والطباعة من الروابط التالية.";

        $buttons = [
            [
                ['text' => 'عرض التقرير', 'url' => $report['absolute_report_url']],
                ['text' => 'طباعة / حفظ PDF', 'url' => $report['absolute_print_url'] ?? $report['absolute_report_url']],
            ],
        ];

        $sendResult = sendTelegramMessageWithButtons($message, $buttons);
        $success = !empty($sendResult['success']);

        if ($success) {
            try {
                $db = db();
                if (!empty($report['relative_path'])) {
                    $db->execute(
                        "UPDATE reports SET telegram_sent = 1 WHERE file_path = ?",
                        [$report['relative_path']]
                    );
                }
            } catch (Throwable $updateError) {
                error_log('Reports table update failed: ' . $updateError->getMessage());
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'تم إرسال رابط التقرير إلى Telegram.' : ('فشل إرسال التقرير إلى Telegram' . (!empty($sendResult['error']) ? ' (' . $sendResult['error'] . ')' : '')),
            'relative_path' => $report['relative_path'] ?? '',
            'report_url' => $report['report_url'] ?? '',
            'absolute_report_url' => $report['absolute_report_url'] ?? '',
            'print_url' => $report['print_url'] ?? '',
            'absolute_print_url' => $report['absolute_print_url'] ?? '',
            'response' => $sendResult,
        ];
    }

    // توافق مع النظام القديم (إرسال ملف فعلي)
    $filePath = (string)$report;
    $result = sendReportToTelegram($filePath, $reportType, $reportName);

    if ($result['success'] && REPORTS_AUTO_DELETE) {
        // تحديث قاعدة البيانات
        try {
            $db = db();
            $db->execute(
                "UPDATE reports SET telegram_sent = 1 WHERE file_path = ?",
                [$filePath]
            );
        } catch (Throwable $updateError) {
            error_log('Reports table update failed: ' . $updateError->getMessage());
        }

        // حذف الملف
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    return $result;
}

