<?php
/**
 * إرسال رسائل وملفات إلى Telegram Bot
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/simple_telegram.php';

/**
 * إرسال تقرير إلى Telegram
 */
function sendReportToTelegram($filePath, $reportType, $reportName) {
    if (!isTelegramConfigured()) {
        return ['success' => false, 'message' => 'Telegram Bot غير مُعد'];
    }
    
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'الملف غير موجود'];
    }
    
    $caption = "📊 تقرير: {$reportName}\nنوع التقرير: {$reportType}\nالتاريخ: " . date('Y-m-d H:i:s');
    
    $result = sendTelegramFile($filePath, $caption);
    
    if ($result) {
        // حذف الملف بعد الإرسال
        if (REPORTS_AUTO_DELETE && file_exists($filePath)) {
            unlink($filePath);
        }
        
        return ['success' => true, 'message' => 'تم إرسال التقرير بنجاح'];
    } else {
        return ['success' => false, 'message' => 'فشل إرسال التقرير'];
    }
}

/**
 * إرسال رسالة نصية إلى Telegram
 */
function sendMessageToTelegram($message) {
    if (!isTelegramConfigured()) {
        return false;
    }
    
    return sendTelegramMessage($message);
}

