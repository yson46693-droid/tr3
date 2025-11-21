<?php
/**
 * API لإدارة بيانات WebAuthn (البصمة)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
// CORS headers
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    try {
        // الحصول على قائمة الاعتماديات للمستخدم
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'غير مصرح به']);
            exit;
        }
        
        $userId = $_SESSION['user_id'];
        $db = db();
        
        if (!$db) {
            throw new Exception('فشل الاتصال بقاعدة البيانات');
        }
        
        $credentials = $db->query(
            "SELECT id, credential_id, device_name, created_at, last_used 
             FROM webauthn_credentials 
             WHERE user_id = ? 
             ORDER BY created_at DESC",
            [$userId]
        );
        
        // التأكد من أن $credentials هو array
        if (!is_array($credentials)) {
            $credentials = [];
        }
        
        echo json_encode([
            'success' => true,
            'credentials' => $credentials
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("WebAuthn Credentials List Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'خطأ في تحميل البصمات: ' . $e->getMessage(),
            'debug' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    // حذف اعتماد محدد
    $userId = $_SESSION['user_id'];
    $credentialId = $_POST['credential_id'] ?? '';
    
    if (empty($credentialId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'معرّف الاعتماد مطلوب']);
        exit;
    }
    
    $db = db();
    
    // التحقق من أن الاعتماد يخص المستخدم الحالي
    $credential = $db->queryOne(
        "SELECT id FROM webauthn_credentials WHERE id = ? AND user_id = ?",
        [$credentialId, $userId]
    );
    
    if (!$credential) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'الاعتماد غير موجود أو غير مسموح']);
        exit;
    }
    
    // حذف الاعتماد
    $db->execute(
        "DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?",
        [$credentialId, $userId]
    );
    
    // التحقق من وجود اعتماديات أخرى للمستخدم
    $remainingCount = $db->queryOne(
        "SELECT COUNT(*) as count FROM webauthn_credentials WHERE user_id = ?",
        [$userId]
    );
    
    // إذا لم يبق أي اعتماد، تحديث حالة المستخدم
    if ($remainingCount['count'] == 0) {
        $db->execute(
            "UPDATE users SET webauthn_enabled = 0, updated_at = NOW() WHERE id = ?",
            [$userId]
        );
    }
    
    // تسجيل في سجل التدقيق
    logAudit($userId, 'delete_webauthn_credential', 'webauthn_credentials', $credentialId, null, null);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم حذف البصمة بنجاح',
        'remaining_count' => $remainingCount['count']
    ]);
    
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'إجراء غير صحيح']);
}

