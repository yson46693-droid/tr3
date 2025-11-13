<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start([
    'cookie_lifetime' => 315360000, // 10 years
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

$now = time();
$_SESSION['reader_last_activity'] = $now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$batchNumber = trim((string)($input['batch_number'] ?? ''));

if ($batchNumber === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'يرجى إدخال رقم التشغيلة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = null;

try {
    if (!defined('ACCESS_ALLOWED')) {
        define('ACCESS_ALLOWED', true);
    }

    $rootCandidates = [
        realpath(__DIR__ . '/../v1'),
        realpath(__DIR__ . '/..'),
        realpath(__DIR__ . '/../../v2'),
        realpath(__DIR__ . '/../../')
    ];

    $appRoot = null;
    foreach ($rootCandidates as $candidate) {
        if ($candidate && is_dir($candidate . '/includes')) {
            $appRoot = $candidate;
            break;
        }
    }

    if (!$appRoot) {
        throw new RuntimeException('تعذر تحديد مجلد التطبيق الرئيسي الذي يحتوي على مجلد includes.');
    }

    require_once $appRoot . '/includes/config.php';
    require_once $appRoot . '/includes/db.php';
    require_once $appRoot . '/includes/batch_numbers.php';
} catch (Throwable $e) {
    error_log('Reader bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر تهيئة الاتصال بقاعدة البيانات.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();

    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'reader_access_log'");
    if (empty($tableCheck)) {
        $db->execute("CREATE TABLE IF NOT EXISTS reader_access_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NULL,
            ip_address VARCHAR(45) NULL,
            batch_number VARCHAR(255) NULL,
            status ENUM('success','not_found','error','rate_limited','invalid') NOT NULL DEFAULT 'success',
            message VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_ip (ip_address),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {
    error_log('Reader log table error: ' . $e->getMessage());
}

$sessionId = $_SESSION['reader_session_id'] ?? null;
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$readerLogMaxRows = function_exists('getReaderAccessLogMaxRows') ? getReaderAccessLogMaxRows() : 50;

if ($db) {
    try {
        $perIpLimit = 120; // requests per hour per IP
        $perSessionLimit = 80; // requests per hour per temporary session

        if ($ipAddress) {
            $ipCount = $db->queryOne(
                "SELECT COUNT(*) AS total FROM reader_access_log WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$ipAddress]
            );
            if (($ipCount['total'] ?? 0) >= $perIpLimit) {
                $db->execute(
                    "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'rate_limited', ?)",
                    [$sessionId, $ipAddress, $batchNumber ?: null, 'تجاوز الحد المسموح للطلبات لكل ساعة.']
                );
                enforceReaderAccessLogLimit($db, $readerLogMaxRows);
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'message' => 'تم تجاوز الحد المسموح به للطلبات من هذا العنوان. يرجى المحاولة لاحقًا.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        if ($sessionId) {
            $sessionCount = $db->queryOne(
                "SELECT COUNT(*) AS total FROM reader_access_log WHERE session_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$sessionId]
            );
            if (($sessionCount['total'] ?? 0) >= $perSessionLimit) {
                $db->execute(
                    "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'rate_limited', ?)",
                    [$sessionId, $ipAddress, $batchNumber ?: null, 'تجاوز الحد المسموح للطلبات للجلسة.']
                );
                enforceReaderAccessLogLimit($db, $readerLogMaxRows);
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'message' => 'تم تجاوز الحد المسموح به للطلبات لهذه الجلسة. يرجى المحاولة لاحقًا.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } catch (Throwable $e) {
        error_log('Reader rate limit error: ' . $e->getMessage());
    }
}

try {
    $batch = null;
    $batchQueryError = null;

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
    } catch (Throwable $primaryQueryError) {
        $batchQueryError = $primaryQueryError;
        error_log('Reader primary batch query error: ' . $primaryQueryError->getMessage());
    }

    if (!$batch) {
        try {
            $batch = $db->queryOne(
                "SELECT * FROM batches WHERE batch_number = ? LIMIT 1",
                [$batchNumber]
            );
        } catch (Throwable $fallbackQueryError) {
            error_log('Reader fallback batch query error: ' . $fallbackQueryError->getMessage());
        }
    }

    if (!$batch) {
        $notFoundMessage = 'رقم التشغيلة غير موجود أو غير صحيح.';
        if ($batchQueryError) {
            $notFoundMessage .= ' (تفاصيل تقنية: Fallback query failed أو نتائج فارغة.)';
        }
        http_response_code(404);
        if ($db) {
            try {
                $db->execute(
                    "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'not_found', ?)",
                    [$sessionId, $ipAddress, $batchNumber, $notFoundMessage]
                );
                enforceReaderAccessLogLimit($db, $readerLogMaxRows);
            } catch (Throwable $logError) {
                error_log('Reader log insert error (not found): ' . $logError->getMessage());
            }
        }
        echo json_encode([
            'success' => false,
            'message' => $notFoundMessage
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $batchId = isset($batch['id']) ? (int) $batch['id'] : 0;

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
            error_log('Reader batch_numbers query error: ' . $batchNumberError->getMessage());
            $batchNumberRecord = null;
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
        $quantityValue = (int) $quantityValue;
    }

    $productName = $batch['product_name'] ?? ($batchNumberRecord['product_name_lookup'] ?? null);
    $productCategory = $batch['product_category'] ?? ($batchNumberRecord['product_category_lookup'] ?? null);
    $notes = $batchNumberRecord['notes'] ?? null;

    $createdByName = null;
    if ($batchNumberRecord) {
        foreach (['created_by_full_name', 'created_by_username'] as $createdKey) {
            if (!empty($batchNumberRecord[$createdKey])) {
                $candidate = trim((string) $batchNumberRecord[$createdKey]);
                if ($candidate !== '') {
                    $createdByName = $candidate;
                    break;
                }
            }
        }
        if ($createdByName === null && !empty($batchNumberRecord['created_by'])) {
            $createdByName = 'المستخدم #' . (int) $batchNumberRecord['created_by'];
        }
    }

    $honeySupplierId = isset($batchNumberRecord['honey_supplier_id']) ? (int) $batchNumberRecord['honey_supplier_id'] : null;
    $packagingSupplierId = isset($batchNumberRecord['packaging_supplier_id']) ? (int) $batchNumberRecord['packaging_supplier_id'] : null;

    $suppliersRows = [];
    $suppliersFormatted = [];
    $supplierNameMap = [];
    if ($batchId > 0 && readerTableExists($db, 'batch_suppliers')) {
        $suppliersHaveStoredNames = readerColumnExists($db, 'batch_suppliers', 'supplier_name');
        $selectSupplierColumns = ['bs.id', 'bs.supplier_id', 'bs.role'];
        if ($suppliersHaveStoredNames) {
            $selectSupplierColumns[] = 'bs.supplier_name';
        }
        try {
            $suppliersRows = $db->query(
                "SELECT " . implode(', ', $selectSupplierColumns) . " FROM batch_suppliers bs WHERE bs.batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $supplierError) {
            error_log('Reader suppliers query error: ' . $supplierError->getMessage());
            $suppliersRows = [];
        }
    }

    foreach ($suppliersRows as $row) {
        $supplierIdValue = isset($row['supplier_id']) ? (int) $row['supplier_id'] : null;
        if ($supplierIdValue !== null && $supplierIdValue > 0) {
            $storedNameValue = '';
            if (array_key_exists('supplier_name', $row)) {
                $storedNameValue = trim((string) $row['supplier_name']);
            }
            $supplierNameMap[$supplierIdValue] = $storedNameValue !== '' ? $storedNameValue : ('مورد #' . $supplierIdValue);
        }
    }

    $suppliersFormatted = array_map(static function (array $row) {
        $supplierId = isset($row['supplier_id']) ? (int) $row['supplier_id'] : null;
        $storedName = array_key_exists('supplier_name', $row) ? trim((string) $row['supplier_name']) : '';
        $finalName = $storedName !== '' ? $storedName : ($supplierId ? 'مورد #' . $supplierId : 'مورد غير معروف');
        $rolesRaw = isset($row['role']) ? (string) $row['role'] : '';
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
            if (isset($entry['supplier_id']) && (int) $entry['supplier_id'] === $supplierId && !empty($entry['name'])) {
                return $entry['name'];
            }
        }
        return 'مورد #' . $supplierId;
    };

    $honeySupplierName = $lookupSupplierName($honeySupplierId);
    $packagingSupplierName = $lookupSupplierName($packagingSupplierId);

    $packagingRows = [];
    if ($batchId > 0 && readerTableExists($db, 'batch_packaging')) {
        try {
            $packagingRows = $db->query(
                "SELECT id, packaging_material_id, packaging_name, unit, supplier_id, quantity_used
                 FROM batch_packaging
                 WHERE batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $packagingError) {
            error_log('Reader packaging query error: ' . $packagingError->getMessage());
            $packagingRows = [];
        }
    }

    $packagingFormatted = array_map(static function (array $row) use ($supplierNameMap) {
        $supplierId = isset($row['supplier_id']) ? (int) $row['supplier_id'] : null;
        $quantity = isset($row['quantity_used']) ? (float) $row['quantity_used'] : null;
        return [
            'id' => $row['id'] ?? null,
            'packaging_material_id' => isset($row['packaging_material_id']) ? (int) $row['packaging_material_id'] : null,
            'name' => $row['packaging_name'] ?? null,
            'unit' => $row['unit'] ?? null,
            'quantity_used' => $quantity,
            'supplier_id' => $supplierId,
            'supplier_name' => ($supplierId && isset($supplierNameMap[$supplierId]))
                ? $supplierNameMap[$supplierId]
                : ($supplierId ? 'مورد #' . $supplierId : null),
        ];
    }, $packagingRows);

    $formatQuantity = static function ($value) {
        if ($value === null || $value === '') {
            return null;
        }
        $numeric = (float) $value;
        if (abs($numeric - round($numeric)) < 0.0001) {
            return number_format($numeric, 0, '.', '');
        }
        return rtrim(rtrim(number_format($numeric, 3, '.', ''), '0'), '.');
    };

    $materialsList = array_map(static function (array $item) use ($formatQuantity) {
        $details = [];
        if (isset($item['quantity_used']) && $item['quantity_used'] !== null) {
            $quantityLabel = $formatQuantity($item['quantity_used']);
            if ($quantityLabel !== null) {
                $unit = $item['unit'] ?? '';
                $details[] = trim($quantityLabel . ($unit ? ' ' . $unit : ''));
            }
        }
        if (!empty($item['supplier_name'])) {
            $details[] = 'المورد: ' . $item['supplier_name'];
        }
        return [
            'name' => $item['name'] ?? 'مادة تعبئة',
            'details' => implode(' • ', array_filter($details)),
        ];
    }, $packagingFormatted);

    $rawRows = [];
    if ($batchId > 0 && readerTableExists($db, 'batch_raw_materials')) {
        try {
            $rawRows = $db->query(
                "SELECT id, raw_material_id, material_name, unit, supplier_id, quantity_used
                 FROM batch_raw_materials
                 WHERE batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $rawError) {
            error_log('Reader raw materials query error: ' . $rawError->getMessage());
            $rawRows = [];
        }
    }

    $rawMaterialsFormatted = array_map(static function (array $row) use ($supplierNameMap) {
        $supplierId = isset($row['supplier_id']) ? (int) $row['supplier_id'] : null;
        $quantity = isset($row['quantity_used']) ? (float) $row['quantity_used'] : null;
        return [
            'id' => $row['id'] ?? null,
            'raw_material_id' => isset($row['raw_material_id']) ? (int) $row['raw_material_id'] : null,
            'name' => $row['material_name'] ?? null,
            'unit' => $row['unit'] ?? null,
            'quantity_used' => $quantity,
            'supplier_id' => $supplierId,
            'supplier_name' => ($supplierId && isset($supplierNameMap[$supplierId]))
                ? $supplierNameMap[$supplierId]
                : ($supplierId ? 'مورد #' . $supplierId : null),
        ];
    }, $rawRows);

    $rawMaterialsDisplay = array_map(static function (array $item) use ($formatQuantity) {
        $details = [];
        if (isset($item['quantity_used']) && $item['quantity_used'] !== null) {
            $quantityLabel = $formatQuantity($item['quantity_used']);
            if ($quantityLabel !== null) {
                $unit = $item['unit'] ?? '';
                $details[] = trim($quantityLabel . ($unit ? ' ' . $unit : ''));
            }
        }
        if (!empty($item['supplier_name'])) {
            $details[] = 'المورد: ' . $item['supplier_name'];
        }
        return [
            'name' => $item['name'] ?? 'مادة خام',
            'details' => implode(' • ', array_filter($details)),
        ];
    }, $rawMaterialsFormatted);

    $workersFormatted = [];
    if ($batchId > 0 && readerTableExists($db, 'batch_workers')) {
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
            error_log('Reader workers query error: ' . $workersError->getMessage());
            $workersRows = [];
        }

        $workersFormatted = array_map(static function (array $row) use ($workersHaveStoredNames) {
            $name = '';
            if ($workersHaveStoredNames && isset($row['worker_name'])) {
                $name = trim((string) $row['worker_name']);
            }
            if ($name === '' && isset($row['employee_id']) && (int) $row['employee_id'] > 0) {
                $name = 'الموظف #' . (int) $row['employee_id'];
            }
            if ($name === '') {
                $name = 'عامل إنتاج';
            }
            return [
                'assignment_id' => $row['id'] ?? null,
                'worker_id' => isset($row['employee_id']) ? (int) $row['employee_id'] : null,
                'full_name' => $name,
                'role' => 'عامل إنتاج',
            ];
        }, $workersRows ?? []);
    }

    $response = [
        'success' => true,
        'batch' => [
            'id' => $batch['id'] ?? null,
            'batch_number' => $batch['batch_number'] ?? $batchNumber,
            'product_name' => $productName,
            'product_category' => $productCategory,
            'production_date' => $productionDate,
            'quantity' => $quantityValue ?? ($batch['quantity'] ?? null),
            'quantity_produced' => $batch['quantity_produced'] ?? ($quantityValue ?? null),
            'status' => $statusKey,
            'status_label' => $statusLabel,
            'honey_supplier_name' => $honeySupplierName,
            'packaging_supplier_name' => $packagingSupplierName,
            'created_by_name' => $createdByName,
            'notes' => $notes,
            'materials' => $materialsList,
            'raw_materials' => $rawMaterialsDisplay,
            'workers' => $workersFormatted,
            'suppliers' => $suppliersFormatted,
            'packaging_materials' => $packagingFormatted,
            'raw_materials_source' => $rawMaterialsFormatted,
        ]
    ];

    if ($db) {
        try {
            $db->execute(
                "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'success', ?)",
                [$sessionId, $ipAddress, $batch['batch_number'] ?? $batchNumber, 'نجاح الاستعلام.']
            );
            enforceReaderAccessLogLimit($db, $readerLogMaxRows);
        } catch (Throwable $logError) {
            error_log('Reader log insert error (success): ' . $logError->getMessage());
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
    error_log('Reader API error: ' . $errorMessage);
    if ($db) {
        try {
            $db->execute(
                "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'error', ?)",
                [$sessionId, $ipAddress, $batchNumber ?: null, 'خطأ داخلي في الخادم: ' . mb_substr($errorMessage, 0, 200)]
            );
            enforceReaderAccessLogLimit($db, $readerLogMaxRows);
        } catch (Throwable $logError) {
            error_log('Reader log insert error (exception): ' . $logError->getMessage());
        }
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء معالجة الطلب.',
        'debug' => $errorMessage
    ], JSON_UNESCAPED_UNICODE);
}

if (!function_exists('readerTableExists')) {
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
            $result = $dbInstance->queryOne("SHOW TABLES LIKE ?", [$table]);
            $cache[$table] = !empty($result);
        } catch (Throwable $e) {
            error_log('Reader table exists check error for ' . $table . ': ' . $e->getMessage());
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

if (!function_exists('readerColumnExists')) {
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
        } catch (Throwable $e) {
            error_log('Reader column exists check error for ' . $table . '.' . $column . ': ' . $e->getMessage());
            $cache[$cacheKey] = false;
        }

        return $cache[$cacheKey];
    }
}

if (!function_exists('getReaderAccessLogMaxRows')) {
    function getReaderAccessLogMaxRows(): int {
        if (defined('READER_ACCESS_LOG_MAX_ROWS')) {
            $value = (int) READER_ACCESS_LOG_MAX_ROWS;
            if ($value > 0) {
                return $value;
            }
        }
        return 50;
    }
}

if (!function_exists('enforceReaderAccessLogLimit')) {
    function enforceReaderAccessLogLimit($dbInstance = null, int $maxRows = 50) {
        $maxRows = (int) $maxRows;
        if ($maxRows < 1) {
            $maxRows = 50;
        }

        try {
            if ($dbInstance === null) {
                $dbInstance = db();
            }

            if (!$dbInstance) {
                return false;
            }

            $totalRow = $dbInstance->queryOne("SELECT COUNT(*) AS total FROM reader_access_log");
            $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

            if ($total <= $maxRows) {
                return true;
            }

            $toDelete = $total - $maxRows;
            $batchSize = 50;

            while ($toDelete > 0) {
                $currentBatch = min($batchSize, $toDelete);

                $oldest = $dbInstance->query(
                    "SELECT id FROM reader_access_log ORDER BY created_at ASC, id ASC LIMIT ?",
                    [$currentBatch]
                );

                if (empty($oldest)) {
                    break;
                }

                $ids = array_map('intval', array_column($oldest, 'id'));
                $ids = array_filter($ids, static function ($id) {
                    return $id > 0;
                });

                if (empty($ids)) {
                    break;
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $dbInstance->execute(
                    "DELETE FROM reader_access_log WHERE id IN ($placeholders)",
                    $ids
                );

                $deleted = count($ids);
                $toDelete -= $deleted;

                if ($deleted < $currentBatch) {
                    break;
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log('Reader access log enforce limit error: ' . $e->getMessage());
            return false;
        }
    }
}
