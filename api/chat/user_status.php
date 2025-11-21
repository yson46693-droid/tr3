<?php
/**
 * API: تحديث حالة المستخدمين في الدردشة الجماعية
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    error_log('chat/user_status bootstrap error: ' . $e->getMessage());
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);

        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $online = isset($payload['is_online']) ? (bool) $payload['is_online'] : true;

        updateUserPresence($userId, $online);

        echo json_encode([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'is_online' => $online,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => getActiveUsers(),
    ]);
} catch (Throwable $e) {
    error_log('chat/user_status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

