<?php
/**
 * API: جلب رسائل الدردشة الجماعية
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
    error_log('chat/get_messages bootstrap error: ' . $e->getMessage());
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

    updateUserPresence($userId, true);

    $since = isset($_GET['since']) ? (string) $_GET['since'] : null;
    $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;

    $limit = max(1, min($limit, 200));

    if ($afterId !== null && $afterId > 0) {
        $since = null;
    }

    $messages = getChatMessages($since, $limit, $userId);

    if ($afterId !== null && $afterId > 0) {
        $messages = array_values(array_filter($messages, function ($message) use ($afterId) {
            return (int) $message['id'] > $afterId;
        }));
    }

    $markReadIds = [];
    foreach ($messages as $message) {
        if ((int) $message['user_id'] !== $userId && (int) $message['deleted'] === 0) {
            $markReadIds[] = (int) $message['id'];
        }
    }

    if (!empty($markReadIds)) {
        foreach ($markReadIds as $mid) {
            markMessageAsRead($mid, $userId);
        }
    }

    $latestTimestamp = getLastMessageTimestamp();

    echo json_encode([
        'success' => true,
        'data' => [
            'messages' => $messages,
            'latest_timestamp' => $latestTimestamp,
            'users' => getActiveUsers(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('chat/get_messages error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

