<?php
/**
 * API: إرسال رسالة جديدة إلى الدردشة الجماعية
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
    error_log('chat/send_message bootstrap error: ' . $e->getMessage());
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
    $currentUser = getCurrentUser();
    $userId = (int) $currentUser['id'];

    $payload = json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $messageText = isset($payload['message']) ? (string) $payload['message'] : '';
    $replyTo = isset($payload['reply_to']) ? (int) $payload['reply_to'] : null;

    if ($replyTo !== null && $replyTo <= 0) {
        $replyTo = null;
    }

    if ($replyTo !== null) {
        $original = db()->queryOne(
            "SELECT id FROM messages WHERE id = ?",
            [$replyTo]
        );
        if (!$original) {
            $replyTo = null;
        }
    }

    $message = sendChatMessage($userId, $messageText, $replyTo);
    markMessageAsRead((int) $message['id'], $userId);

    echo json_encode([
        'success' => true,
        'data' => $message,
    ]);
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (Throwable $e) {
    error_log('chat/send_message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

