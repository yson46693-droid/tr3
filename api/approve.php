<?php
/**
 * API الموافقة على الطلبات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/approval_system.php';

// تنظيف أي output buffers موجودة
while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    requireRole('manager');
    $currentUser = getCurrentUser();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $approvalId = intval($_POST['id'] ?? 0);
    $notes = $_POST['notes'] ?? null;

    if ($approvalId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'معرّف الطلب غير صحيح'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = approveRequest($approvalId, $currentUser['id'], $notes);

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'تمت الموافقة بنجاح'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $result['message'] ?? 'حدث خطأ في الموافقة'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('Error in approve.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'حدث خطأ في الخادم: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Fatal error in approve.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'حدث خطأ فادح في الخادم'], JSON_UNESCAPED_UNICODE);
}

