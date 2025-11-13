<?php
/**
 * API: تعديل رسالة دردشة
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/chat.php';
} catch (Throwable $e) {
    error_log('chat/update_message bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $messageId = isset($payload['message_id']) ? (int) $payload['message_id'] : 0;
    $newText = isset($payload['message']) ? (string) $payload['message'] : '';

    if ($messageId <= 0) {
        throw new InvalidArgumentException('Invalid message id');
    }

    $currentUser = getCurrentUser();
    $userId = (int) $currentUser['id'];

    $updatedMessage = updateChatMessage($messageId, $userId, $newText);

    echo json_encode([
        'success' => true,
        'data' => $updatedMessage,
    ]);
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (RuntimeException $runtime) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $runtime->getMessage()]);
} catch (Throwable $e) {
    error_log('chat/update_message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

