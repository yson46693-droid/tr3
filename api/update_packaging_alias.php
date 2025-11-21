<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'الطريقة غير مسموح بها.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'يرجى تسجيل الدخول مرة أخرى.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$materialId = isset($_POST['material_id']) ? intval($_POST['material_id']) : 0;
$aliasValue = isset($_POST['alias']) ? trim((string)$_POST['alias']) : '';

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'معرّف الأداة غير صحيح.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();

    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
    if (empty($tableCheck)) {
        echo json_encode([
            'success' => false,
            'message' => 'ميزة الاسم المستعار غير متاحة في الوضع الحالي.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $aliasColumnCheck = $db->queryOne("SHOW COLUMNS FROM packaging_materials LIKE 'alias'");
    if (empty($aliasColumnCheck)) {
        try {
            $db->execute("ALTER TABLE `packaging_materials` ADD COLUMN `alias` VARCHAR(255) DEFAULT NULL AFTER `name`");
        } catch (Throwable $aliasError) {
            error_log('Packaging alias column error: ' . $aliasError->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'تعذّر تجهيز حقل الاسم المستعار.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $original = $db->queryOne(
        "SELECT id, alias FROM packaging_materials WHERE id = ?",
        [$materialId]
    );

    if (!$original) {
        throw new Exception('أداة التعبئة غير موجودة.');
    }

    $db->execute(
        "UPDATE packaging_materials SET alias = ?, updated_at = NOW() WHERE id = ?",
        [$aliasValue !== '' ? $aliasValue : null, $materialId]
    );

    $currentUser = getCurrentUser();
    logAudit(
        $currentUser['id'] ?? null,
        'update_packaging_alias',
        'packaging_materials',
        $materialId,
        ['alias' => $original['alias'] ?? null],
        ['alias' => $aliasValue !== '' ? $aliasValue : null]
    );

    echo json_encode([
        'success' => true,
        'alias' => $aliasValue,
        'message' => 'تم حفظ الاسم المستعار بنجاح.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Packaging alias update API error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذّر حفظ الاسم المستعار: ' . $error->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

