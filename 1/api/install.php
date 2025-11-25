<?php
/**
 * API تهيئة قاعدة البيانات (يمكن استدعاؤها يدوياً)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/install.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من وجود مفتاح أمان (اختياري)
    $secretKey = $_POST['secret_key'] ?? '';
    
    // يمكن إضافة مفتاح أمان للتحكم في التهيئة
    // if ($secretKey !== 'YOUR_SECRET_KEY') {
    //     http_response_code(403);
    //     echo json_encode(['error' => 'Unauthorized']);
    //     exit;
    // }
    
    $result = initializeDatabase();
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
    }
} else {
    // GET request - عرض حالة التهيئة
    $needsInstall = needsInstallation();
    
    echo json_encode([
        'needs_installation' => $needsInstall,
        'database_exists' => checkDatabaseExists(),
        'tables_exist' => checkTablesExist(),
        'initial_data_exists' => checkInitialData()
    ]);
}

