<?php
/**
 * API لإدارة جلسات PWA Splash Screen
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// السماح بجميع الطرق
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

try {
    $db = db();
    $connection = getDB();
    
    // تنظيف الجلسات المنتهية الصلاحية
    $connection->query("DELETE FROM `pwa_splash_sessions` WHERE `expires_at` < NOW()");
    
    switch ($action) {
        case 'check':
            // التحقق من وجود جلسة نشطة
            $sessionToken = $_GET['token'] ?? '';
            
            if (empty($sessionToken)) {
                echo json_encode(['exists' => false]);
                exit;
            }
            
            $stmt = $connection->prepare("
                SELECT id, user_id, expires_at 
                FROM `pwa_splash_sessions` 
                WHERE `session_token` = ? AND `expires_at` > NOW()
            ");
            $stmt->bind_param('s', $sessionToken);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                echo json_encode(['exists' => true]);
            } else {
                echo json_encode(['exists' => false]);
            }
            $stmt->close();
            break;
            
        case 'create':
            // إنشاء جلسة جديدة
            $userId = isLoggedIn() ? ($_SESSION['user_id'] ?? null) : null;
            $sessionToken = bin2hex(random_bytes(32));
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            // الجلسة تنتهي بعد 3 ثواني
            $expiresAt = date('Y-m-d H:i:s', time() + 3);
            
            $stmt = $connection->prepare("
                INSERT INTO `pwa_splash_sessions` 
                (`user_id`, `session_token`, `ip_address`, `user_agent`, `expires_at`) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('issss', $userId, $sessionToken, $ipAddress, $userAgent, $expiresAt);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'token' => $sessionToken,
                    'expires_at' => $expiresAt
                ]);
            } else {
                throw new Exception('Failed to create session');
            }
            $stmt->close();
            break;
            
        case 'delete':
            // حذف جلسة
            $sessionToken = $_POST['token'] ?? $_GET['token'] ?? '';
            
            if (empty($sessionToken)) {
                echo json_encode(['success' => false, 'error' => 'Token required']);
                exit;
            }
            
            $stmt = $connection->prepare("DELETE FROM `pwa_splash_sessions` WHERE `session_token` = ?");
            $stmt->bind_param('s', $sessionToken);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode(['success' => true]);
            break;
            
        case 'cleanup':
            // تنظيف الجلسات المنتهية الصلاحية
            $connection->query("DELETE FROM `pwa_splash_sessions` WHERE `expires_at` < NOW()");
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

