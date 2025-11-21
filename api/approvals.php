<?php
/**
 * API: الحصول على عدد الموافقات المعلقة
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/approval_system.php';
} catch (Throwable $bootstrapError) {
    error_log('approvals API bootstrap error: ' . $bootstrapError->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Initialization error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من أن المستخدم مدير فقط
$currentUser = getCurrentUser();
if (($currentUser['role'] ?? '') !== 'manager') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $count = getPendingApprovalsCount();
    
    echo json_encode([
        'success' => true,
        'count' => (int)$count
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Approvals count API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
}

