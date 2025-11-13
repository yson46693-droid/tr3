<?php
/**
 * API: جلب تفاصيل تشغيلة الإنتاج للعرض داخل النظام
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$batchNumber = trim((string)($payload['batch_number'] ?? ''));

if ($batchNumber === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'رقم التشغيلة مطلوب.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/batch_numbers.php';
    require_once __DIR__ . '/../../includes/simple_telegram.php';
} catch (Throwable $bootstrapError) {
    error_log('get_batch_details bootstrap error: ' . $bootstrapError->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر تهيئة الاتصال بقاعدة البيانات.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('readerTableExists')) {
    /**
     * التحقق من وجود جدول
     */
    function readerTableExists($dbInstance, string $table): bool
    {
        static $cache = [];
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $result = $dbInstance->queryOne('SHOW TABLES LIKE ?', [$table]);
            $cache[$table] = !empty($result);
        } catch (Throwable $tableError) {
            error_log('get_batch_details table check error for ' . $table . ': ' . $tableError->getMessage());
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

if (!function_exists('readerColumnExists')) {
    /**
     * التحقق من وجود عمود داخل جدول
     */
    function readerColumnExists($dbInstance, string $table, string $column): bool
    {
        static $cache = [];
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $cacheKey = strtolower($table . ':' . $column);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $result = $dbInstance->queryOne("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
            $cache[$cacheKey] = !empty($result);
        } catch (Throwable $columnError) {
            error_log('get_batch_details column check error for ' . $table . '.' . $column . ': ' . $columnError->getMessage());
            $cache[$cacheKey] = false;
        }

        return $cache[$cacheKey];
    }
}

$allowPublicReader = defined('ALLOW_PUBLIC_READER') && ALLOW_PUBLIC_READER;

if (!$allowPublicReader && !isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'غير مصرح بالوصول.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();

try {
    $batch = $db->queryOne(
        "SELECT 
            b.*,
            COALESCE(fp.product_name, p.name) AS product_name,
            p.category AS product_category,
            fp.quantity_produced
         FROM batches b
         LEFT JOIN finished_products fp ON fp.batch_id = b.id
         LEFT JOIN products p ON p.id = b.product_id
         WHERE b.batch_number = ?
         LIMIT 1",
        [$batchNumber]
    );

    if (!$batch) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'رقم التشغيلة غير موجود.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $batchId = isset($batch['id']) ? (int)$batch['id'] : 0;

    $batchNumberRecord = null;
    if (readerTableExists($db, 'batch_numbers')) {
        try {
            $batchNumberRecord = $db->queryOne(
                "SELECT 
                    bn.*,
                    p.name AS product_name_lookup,
                    p.category AS product_category_lookup,
                    u.full_name AS created_by_full_name,
                    u.username AS created_by_username
                 FROM batch_numbers bn
                 LEFT JOIN products p ON p.id = bn.product_id
                 LEFT JOIN users u ON u.id = bn.created_by
                 WHERE bn.batch_number = ?
                 LIMIT 1",
                [$batchNumber]
            );
        } catch (Throwable $batchNumberError) {
            error_log('get_batch_details batch_numbers query error: ' . $batchNumberError->getMessage());
        }
    }

    $statusLabels = [
        'in_production' => 'قيد الإنتاج',
        'completed' => 'مكتملة',
        'in_stock' => 'في المخزون',
        'sold' => 'مباعة',
        'expired' => 'منتهية',
        'archived' => 'مؤرشفة',
        'cancelled' => 'ملغاة',
    ];

    $statusKey = $batchNumberRecord['status'] ?? ($batch['status'] ?? null);
    $statusLabel = $statusKey !== null ? ($statusLabels[$statusKey] ?? $statusKey) : null;

    $productionDate = $batchNumberRecord['production_date'] ?? ($batch['production_date'] ?? null);
    $quantityValue = $batchNumberRecord['quantity'] ?? ($batch['quantity'] ?? null);
    if ($quantityValue !== null && is_numeric($quantityValue)) {
        $quantityValue = (int)$quantityValue;
    }

    $productName = $batch['product_name'] ?? ($batchNumberRecord['product_name_lookup'] ?? null);
    $productCategory = $batch['product_category'] ?? ($batchNumberRecord['product_category_lookup'] ?? null);
    $notes = $batchNumberRecord['notes'] ?? null;

    $createdByName = null;
    if ($batchNumberRecord) {
        foreach (['created_by_full_name', 'created_by_username'] as $createdKey) {
            if (!empty($batchNumberRecord[$createdKey])) {
                $candidate = trim((string)$batchNumberRecord[$createdKey]);
                if ($candidate !== '') {
                    $createdByName = $candidate;
                    break;
                }
            }
        }
        if ($createdByName === null && !empty($batchNumberRecord['created_by'])) {
            $createdByName = 'المستخدم #' . (int)$batchNumberRecord['created_by'];
        }
    }

    $honeySupplierId = isset($batchNumberRecord['honey_supplier_id']) ? (int)$batchNumberRecord['honey_supplier_id'] : null;
    $packagingSupplierId = isset($batchNumberRecord['packaging_supplier_id']) ? (int)$batchNumberRecord['packaging_supplier_id'] : null;

    $suppliersRows = [];
    $supplierNameMap = [];
    if ($batchId > 0) {
        $suppliersHaveStoredNames = readerColumnExists($db, 'batch_suppliers', 'supplier_name');
        $selectSupplierColumns = ['bs.id', 'bs.supplier_id', 'bs.role'];
        if ($suppliersHaveStoredNames) {
            $selectSupplierColumns[] = 'bs.supplier_name';
        }
        try {
            $suppliersRows = $db->query(
                "SELECT " . implode(', ', $selectSupplierColumns) . "
                 FROM batch_suppliers bs
                 WHERE bs.batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $supplierError) {
            error_log('get_batch_details suppliers query error: ' . $supplierError->getMessage());
            $suppliersRows = [];
        }
    }

    if (empty($suppliersRows) && !empty($batchNumberRecord['all_suppliers'])) {
        $decodedSuppliers = json_decode((string) $batchNumberRecord['all_suppliers'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSuppliers)) {
            foreach ($decodedSuppliers as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $suppliersRows[] = [
                    'supplier_id' => isset($entry['id']) ? (int) $entry['id'] : null,
                    'supplier_name' => $entry['name'] ?? ($entry['material'] ?? null),
                    'role' => $entry['context'] ?? ($entry['role'] ?? null),
                ];
            }
        }
    }

    foreach ($suppliersRows as $row) {
        $supplierIdValue = isset($row['supplier_id']) ? (int)$row['supplier_id'] : null;
        if ($supplierIdValue !== null && $supplierIdValue > 0) {
            $storedNameValue = '';
            if (array_key_exists('supplier_name', $row)) {
                $storedNameValue = trim((string)$row['supplier_name']);
            }
            $supplierNameMap[$supplierIdValue] = $storedNameValue !== '' ? $storedNameValue : ('مورد #' . $supplierIdValue);
        }
    }

    $suppliersFormatted = array_map(static function (array $row) {
        $supplierId = isset($row['supplier_id']) ? (int)$row['supplier_id'] : null;
        $storedName = array_key_exists('supplier_name', $row) ? trim((string)$row['supplier_name']) : '';
        $finalName = $storedName !== '' ? $storedName : ($supplierId ? 'مورد #' . $supplierId : 'مورد غير معروف');
        $rolesRaw = isset($row['role']) ? (string)$row['role'] : '';
        $rolesList = array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));
        return [
            'supplier_id' => $supplierId,
            'name' => $finalName,
            'role' => $rolesRaw !== '' ? $rolesRaw : null,
            'roles' => $rolesList,
        ];
    }, $suppliersRows);

    $lookupSupplierName = static function (?int $supplierId) use ($supplierNameMap, $suppliersFormatted) {
        if ($supplierId === null || $supplierId <= 0) {
            return null;
        }
        if (isset($supplierNameMap[$supplierId])) {
            return $supplierNameMap[$supplierId];
        }
        foreach ($suppliersFormatted as $entry) {
            if (isset($entry['supplier_id']) && (int)$entry['supplier_id'] === $supplierId && !empty($entry['name'])) {
                return $entry['name'];
            }
        }
        return 'مورد #' . $supplierId;
    };

    $honeySupplierName = $lookupSupplierName($honeySupplierId);
    $packagingSupplierName = $lookupSupplierName($packagingSupplierId);

    $packagingRows = [];
    if ($batchId > 0) {
        try {
            $packagingRows = $db->query(
                "SELECT id, packaging_material_id, packaging_name, unit, supplier_id, quantity_used
                 FROM batch_packaging
                 WHERE batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $packagingError) {
            error_log('get_batch_details packaging query error: ' . $packagingError->getMessage());
            $packagingRows = [];
        }
    }

    $packagingFormatted = array_map(static function (array $row) use ($supplierNameMap) {
        $supplierId = isset($row['supplier_id']) ? (int)$row['supplier_id'] : null;
        $quantity = isset($row['quantity_used']) ? (float)$row['quantity_used'] : null;
        return [
            'id' => $row['id'] ?? null,
            'packaging_material_id' => isset($row['packaging_material_id']) ? (int)$row['packaging_material_id'] : null,
            'name' => $row['packaging_name'] ?? null,
            'unit' => $row['unit'] ?? null,
            'quantity_used' => $quantity,
            'supplier_id' => $supplierId,
            'supplier_name' => ($supplierId && isset($supplierNameMap[$supplierId]))
                ? $supplierNameMap[$supplierId]
                : ($supplierId ? 'مورد #' . $supplierId : null),
        ];
    }, $packagingRows);

    $rawRows = [];
    if ($batchId > 0) {
        try {
            $rawRows = $db->query(
                "SELECT id, raw_material_id, material_name, unit, supplier_id, quantity_used
                 FROM batch_raw_materials
                 WHERE batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $rawError) {
            error_log('get_batch_details raw materials query error: ' . $rawError->getMessage());
            $rawRows = [];
        }
    }

    $rawMaterialsFormatted = array_map(static function (array $row) use ($supplierNameMap) {
        $supplierId = isset($row['supplier_id']) ? (int)$row['supplier_id'] : null;
        $quantity = isset($row['quantity_used']) ? (float)$row['quantity_used'] : null;
        return [
            'id' => $row['id'] ?? null,
            'raw_material_id' => isset($row['raw_material_id']) ? (int)$row['raw_material_id'] : null,
            'name' => $row['material_name'] ?? null,
            'unit' => $row['unit'] ?? null,
            'quantity_used' => $quantity,
            'supplier_id' => $supplierId,
            'supplier_name' => ($supplierId && isset($supplierNameMap[$supplierId]))
                ? $supplierNameMap[$supplierId]
                : ($supplierId ? 'مورد #' . $supplierId : null),
        ];
    }, $rawRows);

    $workersFormatted = [];
    if ($batchId > 0) {
        $workersHaveStoredNames = readerColumnExists($db, 'batch_workers', 'worker_name');
        $selectWorkerColumns = ['id', 'employee_id'];
        if ($workersHaveStoredNames) {
            $selectWorkerColumns[] = 'worker_name';
        }
        try {
            $workersRows = $db->query(
                "SELECT " . implode(', ', $selectWorkerColumns) . " FROM batch_workers WHERE batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $workersError) {
            error_log('get_batch_details workers query error: ' . $workersError->getMessage());
            $workersRows = [];
        }

        $workersFormatted = array_map(static function (array $row) use ($workersHaveStoredNames) {
            $name = '';
            if ($workersHaveStoredNames && isset($row['worker_name'])) {
                $name = trim((string)$row['worker_name']);
            }
            if ($name === '' && isset($row['employee_id']) && (int)$row['employee_id'] > 0) {
                $name = 'الموظف #' . (int)$row['employee_id'];
            }
            if ($name === '') {
                $name = 'عامل إنتاج';
            }
            return [
                'assignment_id' => $row['id'] ?? null,
                'worker_id' => isset($row['employee_id']) ? (int)$row['employee_id'] : null,
                'full_name' => $name,
                'role' => 'عامل إنتاج',
            ];
        }, $workersRows ?? []);
    }

    $batchNumberValue = $batch['batch_number'] ?? $batchNumber;
    $quantityResolved = $quantityValue ?? ($batch['quantity'] ?? null);
    if ($quantityResolved !== null && is_numeric($quantityResolved)) {
        $quantityResolved = (int) $quantityResolved;
    }

    $unitLabel = $batch['unit'] ?? ($batchNumberRecord['unit'] ?? 'قطعة');

    $workersNames = array_values(array_filter(array_map(static function ($worker) {
        return isset($worker['full_name']) ? trim((string) $worker['full_name']) : '';
    }, $workersFormatted)));

    $workerIds = array_values(array_filter(array_map(static function ($worker) {
        return isset($worker['worker_id']) ? (int) $worker['worker_id'] : 0;
    }, $workersFormatted), static function ($value) {
        return $value > 0;
    }));

    $extraSuppliersNames = [];
    $extraSupplierIds = [];
    foreach ($suppliersFormatted as $supplierEntry) {
        $roles = $supplierEntry['roles'] ?? [];
        $roles = is_array($roles) ? $roles : [];
        $hasExtraRole = false;
        foreach ($roles as $roleValue) {
            if (stripos((string) $roleValue, 'extra') !== false) {
                $hasExtraRole = true;
                break;
            }
        }
        if ($hasExtraRole) {
            if (!empty($supplierEntry['name'])) {
                $extraSuppliersNames[] = $supplierEntry['name'];
            }
            if (!empty($supplierEntry['supplier_id'])) {
                $extraSupplierIds[] = (int) $supplierEntry['supplier_id'];
            }
        }
    }
    $extraSuppliersNames = array_values(array_unique(array_filter($extraSuppliersNames)));
    $extraSupplierIds = array_values(array_unique(array_filter($extraSupplierIds)));

    $metadata = [
        'batch_number' => $batchNumberValue,
        'batch_id' => $batch['id'] ?? null,
        'production_id' => $batch['production_id'] ?? null,
        'product_id' => $batch['product_id'] ?? ($batchNumberRecord['product_id'] ?? null),
        'product_name' => $productName,
        'production_date' => $productionDate,
        'quantity' => $quantityResolved,
        'unit' => $unitLabel,
        'quantity_unit_label' => $unitLabel,
        'created_by' => $createdByName,
        'created_by_id' => $batchNumberRecord['created_by'] ?? null,
        'workers' => $workersNames,
        'workers_ids' => $workerIds,
        'honey_supplier_name' => $honeySupplierName,
        'honey_supplier_id' => $honeySupplierId,
        'packaging_supplier_name' => $packagingSupplierName,
        'packaging_supplier_id' => $packagingSupplierId,
        'extra_suppliers' => $extraSuppliersNames,
        'extra_suppliers_ids' => $extraSupplierIds,
        'notes' => $notes,
        'raw_materials' => $rawMaterialsFormatted,
        'packaging_materials' => $packagingFormatted,
        'template_id' => $batchNumberRecord['template_id'] ?? null,
        'timestamp' => date('c'),
    ];

    $contextToken = null;
    if (function_exists('random_bytes')) {
        try {
            $contextToken = bin2hex(random_bytes(16));
        } catch (Throwable $tokenError) {
            $contextToken = null;
        }
    }
    if (!$contextToken && function_exists('openssl_random_pseudo_bytes')) {
        $opensslBytes = @openssl_random_pseudo_bytes(16);
        if ($opensslBytes !== false) {
            $contextToken = bin2hex($opensslBytes);
        }
    }
    if (!$contextToken) {
        $contextToken = sha1(uniqid((string) mt_rand(), true));
    }

    $metadata['context_token'] = $contextToken;

    $_SESSION['created_batch_context_token'] = $contextToken;
    $_SESSION['created_batch_metadata'] = $metadata;
    $_SESSION['created_batch_numbers'] = [$batchNumberValue];
    $_SESSION['created_batch_product_name'] = $productName ?? '';
    $_SESSION['created_batch_quantity'] = $quantityResolved ?? 0;

    $telegramEnabled = isTelegramConfigured();

    echo json_encode([
        'success' => true,
        'batch' => [
            'id' => $batch['id'] ?? null,
            'batch_number' => $batchNumberValue,
            'product_name' => $productName,
            'product_category' => $productCategory,
            'production_date' => $productionDate,
            'quantity' => $quantityResolved,
            'quantity_produced' => $batch['quantity_produced'] ?? $quantityResolved,
            'status' => $statusKey,
            'status_label' => $statusLabel,
            'honey_supplier_name' => $honeySupplierName,
            'packaging_supplier_name' => $packagingSupplierName,
            'created_by_name' => $createdByName,
            'notes' => $notes,
            'materials' => array_map(static function (array $item) {
                $details = [];
                if (isset($item['quantity_used']) && $item['quantity_used'] !== null) {
                    $qty = (float) $item['quantity_used'];
                    $details[] = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
                }
                if (!empty($item['supplier_name'])) {
                    $details[] = 'المورد: ' . $item['supplier_name'];
                }
                return [
                    'name' => $item['name'] ?? 'مادة تعبئة',
                    'details' => implode(' • ', array_filter($details)),
                ];
            }, $packagingFormatted),
            'raw_materials' => array_map(static function (array $item) {
                $details = [];
                if (isset($item['quantity_used']) && $item['quantity_used'] !== null) {
                    $qty = (float) $item['quantity_used'];
                    $unit = $item['unit'] ?? '';
                    $quantityLabel = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
                    $details[] = trim($quantityLabel . ($unit ? ' ' . $unit : ''));
                }
                if (!empty($item['supplier_name'])) {
                    $details[] = 'المورد: ' . $item['supplier_name'];
                }
                return [
                    'name' => $item['name'] ?? 'مادة خام',
                    'unit' => $item['unit'] ?? null,
                    'quantity_used' => $item['quantity_used'] ?? null,
                    'supplier_name' => $item['supplier_name'] ?? null,
                    'details' => implode(' • ', array_filter($details)),
                ];
            }, $rawMaterialsFormatted),
            'workers' => $workersFormatted,
            'suppliers' => $suppliersFormatted,
            'packaging_materials' => $packagingFormatted,
            'raw_materials_source' => $rawMaterialsFormatted,
            'telegram_enabled' => $telegramEnabled,
            'context_token' => $contextToken,
        ],
        'metadata' => $metadata,
        'context_token' => $contextToken,
        'telegram_enabled' => $telegramEnabled,
        'quantity' => $quantityResolved,
        'product_name' => $productName,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $apiError) {
    error_log('get_batch_details error: ' . $apiError->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء معالجة الطلب.'
    ], JSON_UNESCAPED_UNICODE);
}


