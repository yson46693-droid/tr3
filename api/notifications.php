<?php
/**
 * API الإشعارات
 */

define('ACCESS_ALLOWED', true);

// تعطيل عرض الأخطاء لمنع إخراج HTML
error_reporting(0);
ini_set('display_errors', 0);

// ضمان إرجاع JSON دائماً
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// معالجة الأخطاء بشكل صحيح
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/notifications.php';
} catch (Exception $e) {
    error_log("Notifications API initialization error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Initialization error']);
    exit;
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// التحقق من تسجيل الدخول قبل إرسال أي headers
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $currentUser = getCurrentUser();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list' || $action === 'get_unread') {
            $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === 'true';
            if ($action === 'get_unread') {
                $unreadOnly = true;
            }
            $limit = intval($_GET['limit'] ?? 50);
            
            $notifications = getUserNotifications($currentUser['id'], $unreadOnly, $limit);
            
            // إذا كان action هو get_unread، استخدم نفس format لـ sidebar.js
            if ($action === 'get_unread') {
                echo json_encode([
                    'success' => true,
                    'notifications' => $notifications ? $notifications : []
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $notifications ? $notifications : []
                ]);
            }
            
        } elseif ($action === 'count') {
            $count = getUnreadNotificationCount($currentUser['id']);
            
            echo json_encode([
                'success' => true,
                'count' => intval($count)
            ]);
            
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'mark_read') {
            $notificationId = intval($_POST['id'] ?? 0);
            
            if ($notificationId > 0) {
                markNotificationAsRead($notificationId, $currentUser['id']);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            
        } elseif ($action === 'mark_all_read') {
            markAllNotificationsAsRead($currentUser['id']);
            
            echo json_encode(['success' => true]);
            
        } elseif ($action === 'delete') {
            $notificationId = intval($_POST['id'] ?? 0);
            
            if ($notificationId > 0) {
                deleteNotification($notificationId, $currentUser['id']);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Notifications API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}

