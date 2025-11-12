<?php
/**
 * واجهة برمجية لإدارة الدردشة الجماعية
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// تعطيل عرض الأخطاء للمستخدم النهائي
error_reporting(0);
ini_set('display_errors', '0');

set_error_handler(static function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/group_chat.php';
} catch (Throwable $bootstrapError) {
    error_log('Group chat API bootstrap failure: ' . $bootstrapError->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization failure']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$currentUser = getCurrentUser();
$currentUserId = isset($currentUser['id']) ? (int) $currentUser['id'] : 0;
$currentUserRole = $currentUser['role'] ?? null;
$canModerate = isGroupChatModeratorRole($currentUserRole);

/**
 * تجهيز الرسالة للإرجاع
 */
$formatMessage = static function (array $message) use ($currentUserId, $canModerate): array {
    $ownerId = isset($message['user_id']) ? (int) $message['user_id'] : 0;
    $message['user_id'] = $ownerId;
    $message['can_edit'] = ($ownerId === $currentUserId) || $canModerate;
    $message['can_delete'] = ($ownerId === $currentUserId) || $canModerate;

    return $message;
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : null;
        $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;

        $messages = getGroupChatMessages([
            'limit' => $limit,
            'since_id' => $sinceId,
            'before_id' => $beforeId,
        ]);

        $payload = array_map($formatMessage, $messages);

        echo json_encode([
            'success' => true,
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        $payload = [];
        if (!empty($contentType) && stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (empty($payload)) {
            $payload = $_POST;
        }

        $action = isset($payload['action']) ? strtolower(trim((string) $payload['action'])) : '';

        if ($action === 'create') {
            $messageText = (string) ($payload['message'] ?? '');
            $parentId = isset($payload['parent_id']) ? (int) $payload['parent_id'] : null;

            if ($messageText === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message text is required']);
                exit;
            }

            $message = createGroupChatMessage($currentUserId, $messageText, $parentId);
            if (!$message) {
                throw new RuntimeException('Unable to create message');
            }
            echo json_encode([
                'success' => true,
                'data' => $formatMessage($message ?? []),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'edit') {
            $messageId = isset($payload['message_id']) ? (int) $payload['message_id'] : 0;
            $messageText = (string) ($payload['message'] ?? '');

            if ($messageId <= 0 || $messageText === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid message payload']);
                exit;
            }

            $message = updateGroupChatMessage($messageId, $currentUserId, $messageText, $canModerate);
            if (!$message) {
                throw new RuntimeException('Unable to update message');
            }
            echo json_encode([
                'success' => true,
                'data' => $formatMessage($message ?? []),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete') {
            $messageId = isset($payload['message_id']) ? (int) $payload['message_id'] : 0;

            if ($messageId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid message id']);
                exit;
            }

            $deleted = deleteGroupChatMessage($messageId, $currentUserId, $canModerate);
            if (!$deleted) {
                throw new RuntimeException('Unable to delete message');
            }
            echo json_encode([
                'success' => true,
                'data' => ['id' => $messageId],
            ]);
            exit;
        }

        if ($action === 'purge') {
            if (!$canModerate) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }

            purgeGroupChatMessages();

            echo json_encode([
                'success' => true,
                'data' => ['purged' => true],
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
} catch (InvalidArgumentException $invalidInput) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $invalidInput->getMessage()]);
} catch (RuntimeException $runtimeProblem) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $runtimeProblem->getMessage()]);
} catch (Throwable $unhandled) {
    error_log('Group chat API error: ' . $unhandled->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

?>
