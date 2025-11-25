<?php
/**
 * API لقراءة الباركود
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/batch_numbers.php';
require_once __DIR__ . '/../includes/simple_barcode.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batchNumber = $_POST['batch_number'] ?? '';
    
    if (empty($batchNumber)) {
        echo json_encode(['success' => false, 'message' => 'رقم التشغيلة مطلوب']);
        exit;
    }
    
    $batch = getBatchByNumber($batchNumber);
    
    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'رقم التشغيلة غير موجود']);
        exit;
    }
    
    // تسجيل الفحص
    $scanType = $_POST['scan_type'] ?? 'verification';
    $scanLocation = $_POST['scan_location'] ?? null;
    recordBarcodeScan($batchNumber, $scanType, $scanLocation);
    
    echo json_encode([
        'success' => true,
        'batch' => [
            'id' => $batch['id'],
            'batch_number' => $batch['batch_number'],
            'product_name' => $batch['product_name'],
            'production_date' => formatDate($batch['production_date']),
            'honey_supplier_name' => $batch['honey_supplier_name'],
            'packaging_supplier_name' => $batch['packaging_supplier_name'],
            'quantity' => $batch['quantity'],
            'status' => $batch['status'],
            'expiry_date' => $batch['expiry_date'] ? formatDate($batch['expiry_date']) : null
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صحيحة']);
}

