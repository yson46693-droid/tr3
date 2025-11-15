<?php
/**
 * API: جلب تفاصيل تشغيلة الإنتاج للعرض داخل النظام
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$closeSession = static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة.'
    ], JSON_UNESCAPED_UNICODE);
    $closeSession();
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$readerPublicParam = $payload['reader_public'] ?? ($payload['reader_mode'] ?? ($_GET['reader_public'] ?? null));

$isReaderRequest = false;
if (is_bool($readerPublicParam)) {
    $isReaderRequest = $readerPublicParam;
} elseif (is_numeric($readerPublicParam)) {
    $isReaderRequest = ((int) $readerPublicParam) === 1;
} elseif (is_string($readerPublicParam)) {
    $normalizedFlag = strtolower(trim($readerPublicParam));
    $isReaderRequest = in_array($normalizedFlag, ['1', 'true', 'yes', 'on', 'reader', 'public'], true);
}

if (!$isReaderRequest) {
    $sourceHint = $payload['source'] ?? ($_GET['source'] ?? null);
    if (is_string($sourceHint) && strtolower(trim($sourceHint)) === 'reader') {
        $isReaderRequest = true;
    }
}

$batchNumber = trim((string)($payload['batch_number'] ?? ''));

if ($batchNumber === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'رقم التشغيلة مطلوب.'
    ], JSON_UNESCAPED_UNICODE);
    $closeSession();
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
    $closeSession();
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
            $escapedTable = $dbInstance->escape($table);
            $result = $dbInstance->queryOne("SHOW TABLES LIKE '{$escapedTable}'", []);
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
            $escapedTable = $dbInstance->escape($table);
            $escapedColumn = $dbInstance->escape($column);
            $result = $dbInstance->queryOne("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'", []);
            $cache[$cacheKey] = !empty($result);
        } catch (Throwable $columnError) {
            error_log('get_batch_details column check error for ' . $table . '.' . $column . ': ' . $columnError->getMessage());
            $cache[$cacheKey] = false;
        }

        return $cache[$cacheKey];
    }
}

if (!function_exists('getReaderAccessLogMaxRows')) {
    function getReaderAccessLogMaxRows(): int
    {
        $default = 50;
        if (defined('READER_ACCESS_LOG_MAX_ROWS')) {
            $value = (int) READER_ACCESS_LOG_MAX_ROWS;
            if ($value > 0) {
                return $value;
            }
        }
        $envValue = getenv('READER_ACCESS_LOG_MAX_ROWS');
        if ($envValue !== false) {
            $envInt = (int) $envValue;
            if ($envInt > 0) {
                return $envInt;
            }
        }
        return $default;
    }
}

if (!function_exists('enforceReaderAccessLogLimit')) {
    function enforceReaderAccessLogLimit($dbInstance = null, int $maxRows = 50): bool
    {
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

                $toDelete -= $currentBatch;
            }

            return true;
        } catch (Throwable $enforceError) {
            error_log('get_batch_details reader log limit error: ' . $enforceError->getMessage());
            return false;
        }
    }
}

$resolveEnvBool = static function ($value, bool $default = false): bool {
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null) {
        return $default;
    }
    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    return $default;
};

$allowPublicReader = defined('ALLOW_PUBLIC_READER') ? (bool) ALLOW_PUBLIC_READER : false;
if (!$allowPublicReader) {
    $envAllowPublic = getenv('ALLOW_PUBLIC_READER');
    if ($envAllowPublic !== false) {
        $allowPublicReader = $resolveEnvBool($envAllowPublic, false);
    }
}
if ($isReaderRequest && !$allowPublicReader) {
    $allowPublicReader = true;
}

if (!$allowPublicReader && !isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'غير مصرح بالوصول.'
    ], JSON_UNESCAPED_UNICODE);
    $closeSession();
    exit;
}

$db = db();

$readerLogEnabled = false;
$readerSessionId = null;
$readerIpAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$readerLogMaxRows = getReaderAccessLogMaxRows();
$logReaderEvent = null;

if ($isReaderRequest && $db) {
    $readerLogEnabled = true;

    if (!isset($_SESSION['reader_session_id']) || !is_string($_SESSION['reader_session_id']) || $_SESSION['reader_session_id'] === '') {
        try {
            $_SESSION['reader_session_id'] = bin2hex(random_bytes(16));
        } catch (Throwable $randomError) {
            $_SESSION['reader_session_id'] = sha1(uniqid('reader', true));
        }
    }

    $readerSessionId = $_SESSION['reader_session_id'] ?? null;

    try {
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
    } catch (Throwable $logTableError) {
        error_log('get_batch_details reader log table error: ' . $logTableError->getMessage());
        $readerLogEnabled = false;
    }

    $logReaderEvent = static function (string $status, ?string $message, ?string $loggedBatchNumber = null) use ($db, &$readerLogEnabled, $readerSessionId, $readerIpAddress, &$readerLogMaxRows) {
        if (!$readerLogEnabled || !$db) {
            return;
        }

        try {
            $db->execute(
                "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, ?, ?)",
                [
                    $readerSessionId,
                    $readerIpAddress,
                    $loggedBatchNumber ?: null,
                    $status,
                    $message ?: null,
                ]
            );
            enforceReaderAccessLogLimit($db, $readerLogMaxRows);
        } catch (Throwable $logInsertError) {
            $readerLogEnabled = false;
            error_log('get_batch_details reader log insert error: ' . $logInsertError->getMessage());
        }
    };

    if ($readerLogEnabled) {
        try {
            $perIpLimit = 120;
            $perSessionLimit = 80;

            if ($readerIpAddress) {
                $ipCount = $db->queryOne(
                    "SELECT COUNT(*) AS total FROM reader_access_log WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                    [$readerIpAddress]
                );
                if (($ipCount['total'] ?? 0) >= $perIpLimit) {
                    $logReaderEvent && $logReaderEvent('rate_limited', 'تجاوز الحد المسموح للطلبات لكل ساعة.', $batchNumber ?: null);
                    http_response_code(429);
                    echo json_encode([
                        'success' => false,
                        'message' => 'تم تجاوز الحد المسموح به للطلبات من هذا العنوان. يرجى المحاولة لاحقًا.',
                    ], JSON_UNESCAPED_UNICODE);
                    $closeSession();
                    exit;
                }
            }

            if ($readerSessionId) {
                $sessionCount = $db->queryOne(
                    "SELECT COUNT(*) AS total FROM reader_access_log WHERE session_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                    [$readerSessionId]
                );
                if (($sessionCount['total'] ?? 0) >= $perSessionLimit) {
                    $logReaderEvent && $logReaderEvent('rate_limited', 'تجاوز الحد المسموح للطلبات للجلسة.', $batchNumber ?: null);
                    http_response_code(429);
                    echo json_encode([
                        'success' => false,
                        'message' => 'تم تجاوز الحد المسموح به للطلبات لهذه الجلسة. يرجى المحاولة لاحقًا.',
                    ], JSON_UNESCAPED_UNICODE);
                    $closeSession();
                    exit;
                }
            }
        } catch (Throwable $rateLimitError) {
            error_log('get_batch_details reader rate limit error: ' . $rateLimitError->getMessage());
        }
    }
}

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
        $notFoundMessage = 'رقم التشغيلة غير موجود.';
        if ($logReaderEvent) {
            $logReaderEvent('not_found', $notFoundMessage, $batchNumber ?: null);
        }
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => $notFoundMessage
        ], JSON_UNESCAPED_UNICODE);
        $closeSession();
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
                $supplierId = isset($entry['id']) ? (int) $entry['id'] : null;
                $supplierName = $entry['name'] ?? null;
                
                // إذا لم يكن هناك اسم مورد، ابحث في جدول suppliers
                if (empty($supplierName) && $supplierId > 0 && $db) {
                    try {
                        $suppliersTableExists = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
                        if (!empty($suppliersTableExists)) {
                            $supplierRow = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$supplierId]);
                            if (!empty($supplierRow) && !empty($supplierRow['name'])) {
                                $supplierName = trim((string)$supplierRow['name']);
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('get_batch_details supplier name lookup error: ' . $e->getMessage());
                    }
                }
                
                // لا تستخدم material كاسم المورد - فقط استخدم name من suppliers
                $suppliersRows[] = [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplierName,
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

    $lookupSupplierName = static function (?int $supplierId) use ($db, $supplierNameMap, $suppliersFormatted) {
        if ($supplierId === null || $supplierId <= 0) {
            return null;
        }
        
        // التحقق من supplierNameMap أولاً
        if (isset($supplierNameMap[$supplierId])) {
            $candidateName = $supplierNameMap[$supplierId];
            // إذا كان الاسم يبدو كأنه نوع عسل (يحتوي على كلمات مثل "عسل" أو "سدر" أو "زهور" إلخ)
            // أو إذا كان قصيراً جداً (أقل من 3 أحرف)، ابحث في قاعدة البيانات
            $honeyTypeKeywords = ['عسل', 'سدر', 'زهور', 'برسيم', 'كينا', 'حبة', 'بركة', 'شوكة', 'قحطان', 'شوكة'];
            $isLikelyHoneyType = false;
            foreach ($honeyTypeKeywords as $keyword) {
                if (stripos($candidateName, $keyword) !== false) {
                    $isLikelyHoneyType = true;
                    break;
                }
            }
            
            if (!$isLikelyHoneyType && strlen($candidateName) >= 3) {
                return $candidateName;
            }
        }
        
        // البحث في suppliersFormatted
        foreach ($suppliersFormatted as $entry) {
            if (isset($entry['supplier_id']) && (int)$entry['supplier_id'] === $supplierId && !empty($entry['name'])) {
                $candidateName = $entry['name'];
                // نفس التحقق من نوع العسل
                $honeyTypeKeywords = ['عسل', 'سدر', 'زهور', 'برسيم', 'كينا', 'حبة', 'بركة', 'شوكة', 'قحطان', 'شوكة'];
                $isLikelyHoneyType = false;
                foreach ($honeyTypeKeywords as $keyword) {
                    if (stripos($candidateName, $keyword) !== false) {
                        $isLikelyHoneyType = true;
                        break;
                    }
                }
                
                if (!$isLikelyHoneyType && strlen($candidateName) >= 3) {
                    return $candidateName;
                }
            }
        }
        
        // إذا لم يتم العثور على اسم صحيح، ابحث في جدول suppliers
        if ($db) {
            try {
                $suppliersTableExists = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
                if (!empty($suppliersTableExists)) {
                    $supplierRow = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$supplierId]);
                    if (!empty($supplierRow) && !empty($supplierRow['name'])) {
                        return trim((string)$supplierRow['name']);
                    }
                }
            } catch (Throwable $e) {
                error_log('get_batch_details supplier lookup error: ' . $e->getMessage());
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

    if ($logReaderEvent) {
        $logReaderEvent('success', 'نجاح الاستعلام.', $batchNumberValue);
    }

    $closeSession();
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
    $rawErrorMessage = (string) $apiError->getMessage();
    error_log('get_batch_details error: ' . $rawErrorMessage);
    $errorSnippet = function_exists('mb_substr')
        ? mb_substr($rawErrorMessage, 0, 200)
        : substr($rawErrorMessage, 0, 200);
    if ($logReaderEvent) {
        $logReaderEvent(
            'error',
            'خطأ داخلي في الخادم: ' . $errorSnippet,
            $batchNumber ?: null
        );
    }
    http_response_code(500);
    $closeSession();
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء معالجة الطلب.'
    ], JSON_UNESCAPED_UNICODE);
}


