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
        $filePath = exportPDF($data, $title, $filters);
        
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
                    [$userId, $type, $filePath]
                );
            } catch (Exception $dbError) {
                error_log("Failed to save report to database: " . $dbError->getMessage());
            }
        }
        
        return $filePath;
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
function sendReportAndDelete($filePath, $reportType, $reportName) {
    require_once __DIR__ . '/../telegram_bot/send_message.php';
    
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

