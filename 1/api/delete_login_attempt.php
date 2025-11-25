<?php
/**
 * API لحذف محاولة تسجيل دخول واحدة
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
    $attemptId = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if ($attemptId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'معرّف المحاولة غير صحيح'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $db = db();
        $currentUser = getCurrentUser();
        
        // التحقق من وجود المحاولة
        $attempt = $db->queryOne(
            "SELECT id FROM login_attempts WHERE id = ?",
            [$attemptId]
        );
        
        if (!$attempt) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'المحاولة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف المحاولة
        $result = $db->execute(
            "DELETE FROM login_attempts WHERE id = ?",
            [$attemptId]
        );
        
        $deletedCount = intval($result['affected_rows'] ?? 0);
        
        if ($deletedCount > 0) {
            // تسجيل في سجل التدقيق
            logAudit($currentUser['id'], 'delete_login_attempt', 'login_attempts', $attemptId, null, null);
            
            // الحصول على الإحصائيات المحدثة
            $stats = getLoginAttemptsStats();
            
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف المحاولة بنجاح',
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'فشل حذف المحاولة'
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } catch (Exception $e) {
        error_log("Delete Login Attempt Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء حذف المحاولة: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة غير صحيحة'
    ], JSON_UNESCAPED_UNICODE);
}

