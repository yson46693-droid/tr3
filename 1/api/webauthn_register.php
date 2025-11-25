<?php
/**
 * API تسجيل WebAuthn - نظام جديد مبسط
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'يجب استخدام طريقة POST']);
    exit;
}

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = db();

try {
    // قراءة البيانات من JSON
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) {
        $input = $_POST;
    }

    $action = $input['action'] ?? 'challenge';

    if ($action === 'challenge') {
        // إنشاء challenge للتسجيل
        $user = getUserById($userId);
        
        if (!$user) {
            throw new Exception('المستخدم غير موجود');
        }

        $challenge = WebAuthn::createRegistrationChallenge($userId, $user['username']);
        
        if (!$challenge) {
            throw new Exception('فشل في إنشاء challenge');
        }

        echo json_encode([
            'success' => true,
            'data' => $challenge
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($action === 'verify') {
        // التحقق من البصمة وحفظها
        $response = $input['response'] ?? null;
        
        if (!$response) {
            throw new Exception('بيانات الاعتماد غير مكتملة');
        }

        // تحويل response من JSON string إلى array إذا لزم الأمر
        if (is_string($response)) {
            $response = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('استجابة غير صحيحة: ' . json_last_error_msg());
            }
        }

        // التحقق من البصمة
        $result = WebAuthn::verifyRegistration(json_encode($response), $userId);
        
        if ($result) {
            // تحديث حالة المستخدم
            $db->execute("UPDATE users SET webauthn_enabled = 1, updated_at = NOW() WHERE id = ?", [$userId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تسجيل البصمة بنجاح'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('فشل التحقق من البصمة. تحقق من سجلات الخادم.');
        }

    } else {
        throw new Exception('إجراء غير صحيح');
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("WebAuthn register error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
