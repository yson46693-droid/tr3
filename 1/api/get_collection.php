<?php
/**
 * API: الحصول على بيانات تحصيل
 */

header('Content-Type: application/json; charset=utf-8');

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

$collectionId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($collectionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرّف التحصيل غير صحيح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();
    
    $collection = $db->queryOne(
        "SELECT * FROM collections WHERE id = ?",
        [$collectionId]
    );
    
    if (!$collection) {
        echo json_encode(['success' => false, 'message' => 'التحصيل غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'collection' => $collection
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Collection Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

