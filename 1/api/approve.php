<?php
/**
 * API الموافقة على الطلبات
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
$notes = $_POST['notes'] ?? null;

if ($approvalId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid approval ID']);
    exit;
}

$result = approveRequest($approvalId, $currentUser['id'], $notes);

if ($result['success']) {
    echo json_encode(['success' => true, 'message' => 'تمت الموافقة بنجاح']);
} else {
    http_response_code(400);
    echo json_encode(['error' => $result['message']]);
}

