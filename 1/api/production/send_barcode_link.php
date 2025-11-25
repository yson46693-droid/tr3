<?php
/**
 * API: إرسال رابط طباعة الباركود عبر تليجرام بعد اختيار عدد الملصقات
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_error_handler(static function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/path_helper.php';
    require_once __DIR__ . '/../../includes/simple_telegram.php';
    require_once __DIR__ . '/../../includes/audit_log.php';
    require_once __DIR__ . '/../../includes/batch_numbers.php';
} catch (Throwable $bootstrapError) {
    error_log('production/send_barcode_link bootstrap error: ' . $bootstrapError->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization error'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requireRole(['production', 'manager', 'accountant']);
} catch (Throwable $roleError) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isTelegramConfigured()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'تم تعطيل تكامل تليجرام حالياً'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$batchNumber = isset($payload['batch_number']) ? trim((string) $payload['batch_number']) : '';
$labels = isset($payload['labels']) ? (int) $payload['labels'] : 0;
$contextToken = isset($payload['context_token']) ? (string) $payload['context_token'] : '';

if ($batchNumber === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'رقم التشغيلة مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($labels <= 0) {
    $labels = 1;
} elseif ($labels > 1000) {
    $labels = 1000; // تحديد حد منطقي لعدد الملصقات المرسلة عبر الرابط
}

if ($contextToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'رمز التحقق مفقود'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionToken = $_SESSION['created_batch_context_token'] ?? null;

if (!$sessionToken) {
    http_response_code(410);
    echo json_encode(['success' => false, 'error' => 'انتهت صلاحية جلسة الطباعة. يرجى إعادة إنشاء التشغيلة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals((string) $sessionToken, (string) $contextToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'طلب غير مصرح به'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionMeta = $_SESSION['created_batch_metadata'] ?? null;

if (!is_array($sessionMeta) || ($sessionMeta['batch_number'] ?? '') !== $batchNumber) {
    $sessionMeta = null;
}

$batchDetails = null;

if (!$sessionMeta) {
    try {
        $batchDetails = getBatchByNumber($batchNumber);
        if (!$batchDetails) {
            // إذا فشل جلب البيانات من getBatchByNumber، حاول جلبها مباشرة من قاعدة البيانات
            try {
                $batchRow = $db->queryOne("SELECT id FROM batches WHERE batch_number = ? LIMIT 1", [$batchNumber]);
                if ($batchRow) {
                    $batchDetails = getBatchNumber((int)$batchRow['id']);
                }
            } catch (Throwable $dbError) {
                error_log('send_barcode_link direct batch lookup error: ' . $dbError->getMessage());
            }
        }
    } catch (Throwable $batchError) {
        error_log('send_barcode_link getBatchByNumber error: ' . $batchError->getMessage());
        $batchDetails = null;
    }
    
    // إذا لم نتمكن من جلب البيانات، استخدم البيانات الأساسية فقط
    if (!$batchDetails) {
        // على الأقل تأكد من وجود رقم التشغيلة
        $batchDetails = [
            'batch_number' => $batchNumber,
            'product_name' => '',
            'quantity' => null,
            'production_date' => null,
        ];
    }
}

$currentUser = getCurrentUser();
$db = db();

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$formatQuantity = static function ($value) {
    if ($value === null) {
        return null;
    }
    $numeric = (float) $value;
    if (abs($numeric - round($numeric)) < 0.0001) {
        return (string) round($numeric);
    }
    return number_format($numeric, 2, '.', '');
};

$productName = $sessionMeta['product_name'] ?? ($batchDetails['product_name'] ?? '');
$productionDate = $sessionMeta['production_date'] ?? ($batchDetails['production_date'] ?? null);
$quantityValue = $sessionMeta['quantity'] ?? ($batchDetails['quantity'] ?? null);
$unitLabel = $sessionMeta['quantity_unit_label'] ?? null;

if (!$unitLabel && isset($batchDetails['unit'])) {
    $unitLabel = $batchDetails['unit'];
}

// إذا لم يكن هناك productName، حاول جلبها من قاعدة البيانات مباشرة
if (empty($productName) && $batchDetails) {
    try {
        $batchId = $batchDetails['id'] ?? null;
        if ($batchId) {
            $productRow = $db->queryOne(
                "SELECT COALESCE(fp.product_name, p.name) AS product_name
                 FROM batches b
                 LEFT JOIN finished_products fp ON fp.batch_id = b.id
                 LEFT JOIN products p ON b.product_id = p.id
                 WHERE b.id = ? LIMIT 1",
                [$batchId]
            );
            if (!empty($productRow['product_name'])) {
                $productName = trim((string)$productRow['product_name']);
            }
        }
    } catch (Throwable $productError) {
        error_log('send_barcode_link product name lookup error: ' . $productError->getMessage());
    }
}

$createdByName = $sessionMeta['created_by'] ?? '';
if ($createdByName === '' && isset($batchDetails['created_by_name'])) {
    $createdByName = $batchDetails['created_by_name'];
}

$workers = [];
if (!empty($sessionMeta['workers']) && is_array($sessionMeta['workers'])) {
    $workers = $sessionMeta['workers'];
} elseif (!empty($batchDetails['workers_details']) && is_array($batchDetails['workers_details'])) {
    foreach ($batchDetails['workers_details'] as $workerRow) {
        if (!empty($workerRow['full_name'])) {
            $workers[] = $workerRow['full_name'];
        }
    }
}
$workers = array_values(array_unique(array_filter(array_map('trim', $workers))));

$honeySupplierName = $sessionMeta['honey_supplier_name'] ?? null;
$packagingSupplierName = $sessionMeta['packaging_supplier_name'] ?? null;
$extraSuppliers = $sessionMeta['extra_suppliers'] ?? [];

// جلب أسماء الموردين إن لم تكن متوفرة في الجلسة
if (!$honeySupplierName) {
    $honeySupplierId = $sessionMeta['honey_supplier_id'] ?? null;
    if (!$honeySupplierId && $batchDetails) {
        // محاولة جلب من batch_numbers
        try {
            $batchNumberValue = $batchDetails['batch_number'] ?? $batchNumber;
            if ($batchNumberValue) {
                $batchNumbersTableExists = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
                if (!empty($batchNumbersTableExists)) {
                    $batchNumberRow = $db->queryOne(
                        "SELECT honey_supplier_id FROM batch_numbers WHERE batch_number = ? LIMIT 1",
                        [$batchNumberValue]
                    );
                    if (!empty($batchNumberRow['honey_supplier_id'])) {
                        $honeySupplierId = (int)$batchNumberRow['honey_supplier_id'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('send_barcode_link honey supplier id lookup error: ' . $e->getMessage());
        }
    }
    
    if ($honeySupplierId && $honeySupplierId > 0) {
        try {
            $row = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$honeySupplierId]);
            if (!empty($row['name'])) {
                $honeySupplierName = trim((string)$row['name']);
            }
        } catch (Throwable $supplierError) {
            error_log('send_barcode_link honey supplier lookup failed: ' . $supplierError->getMessage());
        }
    }
}

if (!$packagingSupplierName) {
    $packagingSupplierId = $sessionMeta['packaging_supplier_id'] ?? null;
    if (!$packagingSupplierId && $batchDetails) {
        // محاولة جلب من batch_numbers
        try {
            $batchNumberValue = $batchDetails['batch_number'] ?? $batchNumber;
            if ($batchNumberValue) {
                $batchNumbersTableExists = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
                if (!empty($batchNumbersTableExists)) {
                    $batchNumberRow = $db->queryOne(
                        "SELECT packaging_supplier_id FROM batch_numbers WHERE batch_number = ? LIMIT 1",
                        [$batchNumberValue]
                    );
                    if (!empty($batchNumberRow['packaging_supplier_id'])) {
                        $packagingSupplierId = (int)$batchNumberRow['packaging_supplier_id'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('send_barcode_link packaging supplier id lookup error: ' . $e->getMessage());
        }
    }
    
    if ($packagingSupplierId && $packagingSupplierId > 0) {
        try {
            $row = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$packagingSupplierId]);
            if (!empty($row['name'])) {
                $packagingSupplierName = trim((string)$row['name']);
            }
        } catch (Throwable $supplierError) {
            error_log('send_barcode_link packaging supplier lookup failed: ' . $supplierError->getMessage());
        }
    }
}

if ((empty($extraSuppliers) || !is_array($extraSuppliers)) && !empty($sessionMeta['extra_suppliers_ids'])) {
    $extraSuppliers = [];
    $extraIds = array_values(array_filter(array_map('intval', $sessionMeta['extra_suppliers_ids'])));
    if (!empty($extraIds)) {
        $placeholders = implode(',', array_fill(0, count($extraIds), '?'));
        try {
            $supplierRows = $db->query(
                "SELECT name, type FROM suppliers WHERE id IN ($placeholders)",
                $extraIds
            );
            foreach ($supplierRows as $supplierRow) {
                $label = trim((string) ($supplierRow['name'] ?? ''));
                if ($label === '' && !empty($supplierRow['type'])) {
                    $label = 'مورد (' . $supplierRow['type'] . ')';
                }
                if ($label !== '') {
                    $extraSuppliers[] = $label;
                }
            }
        } catch (Throwable $supplierError) {
            error_log('send_barcode_link extra suppliers lookup failed: ' . $supplierError->getMessage());
        }
    }
}

$rawMaterialsSummary = [];
if (!empty($sessionMeta['raw_materials']) && is_array($sessionMeta['raw_materials'])) {
    $rawMaterialsSummary = $sessionMeta['raw_materials'];
}

$packagingSummary = [];
if (!empty($sessionMeta['packaging_materials']) && is_array($sessionMeta['packaging_materials'])) {
    $packagingSummary = $sessionMeta['packaging_materials'];
}

// استخدام بيانات إضافية مرسلة من الواجهة كنسخة احتياطية فقط
$clientMetadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];

if (empty($rawMaterialsSummary) && !empty($clientMetadata['raw_materials']) && is_array($clientMetadata['raw_materials'])) {
    $rawMaterialsSummary = $clientMetadata['raw_materials'];
}

if (empty($packagingSummary) && !empty($clientMetadata['packaging_materials']) && is_array($clientMetadata['packaging_materials'])) {
    $packagingSummary = $clientMetadata['packaging_materials'];
}

if (!$productName && isset($clientMetadata['product_name'])) {
    $productName = $clientMetadata['product_name'];
}

if (!$productionDate && isset($clientMetadata['production_date'])) {
    $productionDate = $clientMetadata['production_date'];
}

if ($quantityValue === null && isset($clientMetadata['quantity'])) {
    $quantityValue = $clientMetadata['quantity'];
}

if (!$unitLabel && isset($clientMetadata['quantity_unit_label'])) {
    $unitLabel = $clientMetadata['quantity_unit_label'];
}

if ($createdByName === '' && isset($clientMetadata['created_by'])) {
    $createdByName = $clientMetadata['created_by'];
}

$notes = $sessionMeta['notes'] ?? ($clientMetadata['notes'] ?? null);

$printUrl = getAbsoluteUrl('print_barcode.php?batch=' . urlencode($batchNumber) . '&quantity=' . $labels . '&print=1');
$detailsUrl = getAbsoluteUrl('view_batch.php?batch=' . urlencode($batchNumber));

$lines = [];
$lines[] = '🏷️ <b>طلب طباعة باركود جديد</b>';
$lines[] = '';
$lines[] = '🔖 رقم التشغيلة: <code>' . $escape($batchNumber) . '</code>';

if ($productName !== '') {
    $lines[] = '📦 المنتج: ' . $escape($productName);
}

$formattedQuantity = $formatQuantity($quantityValue);
if ($formattedQuantity !== null) {
    $quantityLine = '📊 كمية الإنتاج: ' . $escape($formattedQuantity);
    if ($unitLabel) {
        $quantityLine .= ' ' . $escape($unitLabel);
    }
    $lines[] = $quantityLine;
}

$lines[] = '🏷️ عدد الملصقات المطلوبة: ' . $escape($labels);

if ($productionDate) {
    $lines[] = '🗓️ تاريخ الإنتاج: ' . $escape($productionDate);
}

if ($createdByName !== '') {
    $lines[] = '👤 أنشأها: ' . $escape($createdByName);
}

if (!empty($workers)) {
    $lines[] = '👷‍♀️ طاقم الإنتاج: ' . $escape(implode('، ', $workers));
}

$buildMaterialsSection = static function ($items, $title, $escape, $formatQuantity) {
    if (empty($items) || !is_array($items)) {
        return [];
    }

    $rows = [];
    $displayItems = array_slice($items, 0, 6);
    foreach ($displayItems as $material) {
        $name = isset($material['name']) ? trim((string) $material['name']) : '';
        if ($name === '') {
            $name = 'مادة بدون اسم';
        }
        $line = '• ' . $escape($name);
        if (isset($material['quantity']) && $material['quantity'] !== null && $material['quantity'] !== '') {
            $qtyFormatted = $formatQuantity($material['quantity']);
            if ($qtyFormatted !== null) {
                $line .= ' — ' . $escape($qtyFormatted);
                if (!empty($material['unit'])) {
                    $line .= ' ' . $escape($material['unit']);
                }
            }
        }
        $rows[] = $line;
    }

    $remaining = count($items) - count($displayItems);
    if ($remaining > 0) {
        $rows[] = '• ... (' . $escape($remaining) . ' عناصر إضافية)';
    }

    if (empty($rows)) {
        return [];
    }

    array_unshift($rows, $title);
    return $rows;
};


if ($notes) {
    $lines[] = '📝 ملاحظات التشغيلة: ' . $escape($notes);
}

$lines[] = '';

$message = implode("\n", array_filter($lines, static function ($line) {
    return $line !== null;
}));

// إضافة أزرار واضحة وكبيرة في أسفل الرسالة
$buttons = [
    [
        [
            'text' => '🖨️ طباعة الباركود',
            'url' => $printUrl
        ]
    ],
    []
];

$telegramResult = sendTelegramMessageWithButtons($message, $buttons);

if (!$telegramResult || !($telegramResult['success'] ?? false)) {
    $errorMsg = $telegramResult['error'] ?? 'تعذر إرسال الرسالة إلى تليجرام';
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

// تحديث بيانات الجلسة للمراجعة المستقبلية
$_SESSION['created_batch_metadata']['last_telegram_sent_at'] = time();
$_SESSION['created_batch_metadata']['last_telegram_labels'] = $labels;
$_SESSION['created_batch_metadata']['last_print_url'] = $printUrl;

$telegramMessageId = null;
$telegramResponse = $telegramResult['response'] ?? null;
if ($telegramResponse && isset($telegramResponse['result']['message_id'])) {
    $telegramMessageId = (int) $telegramResponse['result']['message_id'];
    $_SESSION['created_batch_metadata']['last_telegram_message_id'] = $telegramMessageId;
}

try {
    logAudit(
        $currentUser['id'] ?? null,
        'send_barcode_print_link',
        'batch',
        $sessionMeta['batch_id'] ?? ($batchDetails['id'] ?? null),
        null,
        [
            'batch_number' => $batchNumber,
            'labels' => $labels,
            'print_url' => $printUrl,
            'telegram_message_id' => $telegramMessageId,
        ]
    );
} catch (Throwable $auditError) {
    error_log('send_barcode_link audit log error: ' . $auditError->getMessage());
}

echo json_encode([
    'success' => true,
    'labels' => $labels,
    'batch_number' => $batchNumber,
    'print_url' => $printUrl,
    'telegram_message_id' => $telegramMessageId,
], JSON_UNESCAPED_UNICODE);

