<?php
/**
 * Cron Job: حذف محاولات تسجيل الدخول القديمة يومياً
 * 
 * يمكن إعداد هذا الملف كـ cron job ليعمل يومياً:
 * 0 0 * * * /usr/bin/php /path/to/cleanup_login_attempts.php
 * 
 * أو يمكن استدعاؤه من URL:
 * https://your-domain.com/cron/cleanup_login_attempts.php?secret=YOUR_SECRET_KEY
 */

// حماية الملف - يتطلب secret key أو أن يكون في CLI
$secretKey = 'CHANGE_THIS_SECRET_KEY_TO_SOMETHING_RANDOM';

// التحقق من طريقة التنفيذ
$isCLI = (php_sapi_name() === 'cli');
$hasSecret = isset($_GET['secret']) && $_GET['secret'] === $secretKey;

if (!$isCLI && !$hasSecret) {
    http_response_code(403);
    die('Access denied');
}

// تعريف المسار
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';

// عدد الأيام - حذف السجلات الأقدم من يوم واحد (يمكن تعديله)
$days = isset($_GET['days']) ? intval($_GET['days']) : 1;

// تسجيل بدء العملية
$logMessage = "[" . date('Y-m-d H:i:s') . "] Starting cleanup of login attempts older than {$days} day(s)\n";

// تنفيذ الحذف
$result = cleanupOldLoginAttempts($days);

// تسجيل النتيجة
if ($result['success']) {
    $logMessage .= "[" . date('Y-m-d H:i:s') . "] Success: {$result['message']}\n";
    $logMessage .= "[" . date('Y-m-d H:i:s') . "] Deleted {$result['deleted']} records\n";
    
    // الحصول على الإحصائيات
    $stats = getLoginAttemptsStats();
    $logMessage .= "[" . date('Y-m-d H:i:s') . "] Current stats - Total: {$stats['total']}, Today: {$stats['today']}, Old: {$stats['old_records']}\n";
    
    if ($isCLI) {
        echo $logMessage;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'deleted' => $result['deleted'],
            'stats' => $stats
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} else {
    $logMessage .= "[" . date('Y-m-d H:i:s') . "] Error: {$result['message']}\n";
    
    if ($isCLI) {
        echo $logMessage;
        exit(1);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

// حفظ log في ملف (اختياري)
$logFile = __DIR__ . '/logs/cleanup_login_attempts.log';
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}
@file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
