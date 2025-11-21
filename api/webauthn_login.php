<?php
/**
 * API تسجيل الدخول WebAuthn
 */

// منع أي output قبل headers
ob_start();

define('ACCESS_ALLOWED', true);

// بدء الجلسة قبل تحميل الملفات
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/webauthn.php';
    require_once __DIR__ . '/../includes/auth.php';
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'خطأ في تحميل النظام: ' . $e->getMessage()]);
    exit;
}

// تنظيف output buffer
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'يجب استخدام طريقة POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create_challenge') {
        $username = $_POST['username'] ?? '';
        
        if (empty($username)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'اسم المستخدم مطلوب']);
            exit;
        }
        
        $challenge = WebAuthn::createLoginChallenge($username);
        
        if ($challenge) {
            echo json_encode(['success' => true, 'challenge' => $challenge], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'لا توجد بصمات مسجلة لهذا المستخدم'], JSON_UNESCAPED_UNICODE);
        }
        
    } elseif ($action === 'verify') {
        $response = $_POST['response'] ?? '';
        
        if (empty($response)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'الاستجابة مطلوبة'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // تحويل JSON string إلى array
        if (is_string($response)) {
            $response = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'استجابة غير صحيحة: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // إرسال response object كامل إلى verifyLogin
        $userId = WebAuthn::verifyLogin(json_encode($response));
        
        if ($userId) {
            // تسجيل الدخول
            $user = getUserById($userId);
            
            if ($user && $user['status'] === 'active') {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;
                
                // تسجيل سجل التدقيق
                require_once __DIR__ . '/../includes/audit_log.php';
                logAudit($userId, 'login', 'user', $userId, null, ['method' => 'webauthn']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'تم تسجيل الدخول بنجاح',
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role']
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'الحساب غير مفعّل'], JSON_UNESCAPED_UNICODE);
            }
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'فشل التحقق من البصمة'], JSON_UNESCAPED_UNICODE);
        }
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'إجراء غير صحيح'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log("WebAuthn Login API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'حدث خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

