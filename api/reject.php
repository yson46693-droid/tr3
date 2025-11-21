<?php
/**
 * API رفض الطلبات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/approval_system.php';

header('Content-Type: application/json');
requireRole('manager');

$currentUser = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$approvalId = intval($_POST['id'] ?? 0);
$rejectionReason = $_POST['reason'] ?? '';

if ($approvalId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid approval ID']);
    exit;
}

if (empty($rejectionReason)) {
    http_response_code(400);
    echo json_encode(['error' => 'يجب إدخال سبب الرفض']);
    exit;
}

$result = rejectRequest($approvalId, $currentUser['id'], $rejectionReason);

if ($result['success']) {
    echo json_encode(['success' => true, 'message' => 'تم رفض الطلب بنجاح']);
} else {
    http_response_code(400);
    echo json_encode(['error' => $result['message']]);
}

