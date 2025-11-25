<?php
declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/batch_creation.php';

header('Content-Type: application/json; charset=utf-8');

function respondWithJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondWithJson([
        'status'  => 'error',
        'message' => 'طريقة الطلب غير مدعومة',
    ], 405);
}

$templateId = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
$units      = isset($_POST['units']) ? (int) $_POST['units'] : 0;

if ($templateId <= 0) {
    respondWithJson([
        'status'  => 'error',
        'message' => 'معرف القالب غير صالح',
    ], 422);
}

if ($units <= 0) {
    respondWithJson([
        'status'  => 'error',
        'message' => 'عدد الوحدات غير صالح',
    ], 422);
}

$result = batchCreationCreate($templateId, $units);

if (!is_array($result) || empty($result['success'])) {
    respondWithJson([
        'status'  => 'error',
        'message' => $result['message'] ?? 'حدث خطأ أثناء إنشاء التشغيله',
    ], 422);
}

respondWithJson([
    'status'          => 'success',
    'message'         => $result['message'] ?? 'تم إنشاء التشغيله بنجاح',
    'batch_number'    => $result['batch_number'] ?? null,
    'batch_id'        => $result['batch_id'] ?? null,
    'product_id'      => $result['product_id'] ?? null,
    'product_name'    => $result['product_name'] ?? null,
    'quantity'        => $result['quantity'] ?? $units,
    'production_date' => $result['production_date'] ?? null,
    'expiry_date'     => $result['expiry_date'] ?? null,
    'workers'         => $result['workers'] ?? [],
]);
