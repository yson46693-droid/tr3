<?php
/**
 * API لحذف محاولات تسجيل الدخول القديمة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/audit_log.php';

// CORS headers
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

requireRole('manager');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'cleanup';
    
    $currentUser = getCurrentUser();
    
    if ($action === 'delete_all') {
        // حذف جميع محاولات تسجيل الدخول
        try {
            $db = db();
            
            // الحصول على عدد السجلات قبل الحذف
            $countResult = $db->queryOne("SELECT COUNT(*) as count FROM login_attempts");
            $countBefore = intval($countResult['count'] ?? 0);
            
            if ($countBefore == 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'لا توجد محاولات تسجيل دخول للحذف',
                    'deleted' => 0,
                    'stats' => getLoginAttemptsStats()
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // حذف جميع السجلات
            $result = $db->execute("DELETE FROM login_attempts");
            $deletedCount = intval($result['affected_rows'] ?? 0);
            
            // التحقق من الحذف
            $countAfterResult = $db->queryOne("SELECT COUNT(*) as count FROM login_attempts");
            $countAfter = intval($countAfterResult['count'] ?? 0);
            
            $actualDeleted = $countBefore - $countAfter;
            $finalDeleted = max($actualDeleted, $deletedCount);
            
            if ($finalDeleted > 0) {
                // تسجيل في سجل التدقيق
                logAudit($currentUser['id'], 'delete_all_login_attempts', 'login_attempts', null, null, [
                    'deleted_count' => $finalDeleted
                ]);
                
                // الحصول على الإحصائيات المحدثة
                $stats = getLoginAttemptsStats();
                
                echo json_encode([
                    'success' => true,
                    'message' => "تم حذف {$finalDeleted} محاولة تسجيل دخول بنجاح",
                    'deleted' => $finalDeleted,
                    'stats' => $stats
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'فشل حذف محاولات تسجيل الدخول'
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            error_log("Delete All Login Attempts Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف محاولات تسجيل الدخول: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        // حذف السجلات القديمة (الكود الأصلي)
        $days = isset($_POST['days']) ? intval($_POST['days']) : 1;
        
        if ($days < 1) {
            $days = 1;
        }
        
        // تنفيذ الحذف
        $result = cleanupOldLoginAttempts($days);
        
        if ($result['success']) {
            // تسجيل في سجل التدقيق
            logAudit($currentUser['id'], 'cleanup_login_attempts', 'login_attempts', null, null, [
                'deleted_count' => $result['deleted'],
                'days' => $days
            ]);
            
            // الحصول على الإحصائيات المحدثة
            $stats = getLoginAttemptsStats();
            
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'deleted' => $result['deleted'],
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $result['message']
            ], JSON_UNESCAPED_UNICODE);
        }
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة غير صحيحة'
    ], JSON_UNESCAPED_UNICODE);
}
