<?php
/**
 * API لإلغاء حظر IP
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

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
    $ipAddress = $_POST['ip_address'] ?? '';
    
    if (empty($ipAddress)) {
        echo json_encode(['success' => false, 'message' => 'عنوان IP مطلوب']);
        exit;
    }
    
    if (unblockIP($ipAddress)) {
        echo json_encode(['success' => true, 'message' => 'تم إلغاء حظر IP بنجاح']);
    } else {
        echo json_encode(['success' => false, 'message' => 'حدث خطأ في إلغاء الحظر']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صحيحة']);
}

