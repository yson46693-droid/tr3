<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start([
    'cookie_lifetime' => 0,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

$sessionTimeout = 15 * 60; // 15 minutes
$now = time();

if (isset($_SESSION['reader_last_activity']) && ($now - $_SESSION['reader_last_activity']) > $sessionTimeout) {
    session_unset();
    session_destroy();
    echo json_encode([
        'success' => false,
        'message' => 'انتهت صلاحية الجلسة. يرجى إعادة تحميل الصفحة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

    $statusLabels = [
        'in_production' => 'قيد الإنتاج',
        'completed' => 'مكتملة',
        'archived' => 'مؤرشفة',
        'cancelled' => 'ملغاة',
    ];

    $packagingDetails = [];
    $packagingAvailable = $batchId > 0 && function_exists('readerTableExists') && readerTableExists($db, 'batch_packaging');
    if (!$packagingAvailable) {
        // Packaging table not available; skip adding notes per requirements
    }
    if ($packagingAvailable) {
        $hasPackagingMaterials = readerTableExists($db, 'packaging_materials');
        $packagingHasSupplierDetails = readerTableExists($db, 'suppliers') && readerTableExists($db, 'packaging_materials') && readerTableExists($db, 'suppliers');
        $hasPackagingSupplierColumn = readerTableExists($db, 'batch_packaging') && batchCreationColumnExists($db, 'batch_packaging', 'supplier_id');

        $selectColumns = [
            'bp.id',
            'bp.quantity_used',
            $hasPackagingMaterials ? 'COALESCE(bp.packaging_name, pm.name) AS name' : 'bp.packaging_name AS name',
            $hasPackagingMaterials ? 'COALESCE(bp.unit, pm.unit) AS unit' : 'bp.unit AS unit'
        ];

        if ($hasPackagingSupplierColumn) {
            $selectColumns[] = 'bp.supplier_id AS supplier_id';
            $selectColumns[] = 's.name AS supplier_name';
        } else {
            $selectColumns[] = 'NULL AS supplier_id';
            $selectColumns[] = 'NULL AS supplier_name';
        }

        $query = "SELECT " . implode(', ', $selectColumns) . " FROM batch_packaging bp";
        if ($hasPackagingMaterials) {
            $query .= " LEFT JOIN packaging_materials pm ON pm.id = bp.packaging_material_id";
        }
        if ($hasPackagingSupplierColumn && readerTableExists($db, 'suppliers')) {
            $query .= " LEFT JOIN suppliers s ON s.id = bp.supplier_id";
        }

        try {
            $packagingDetails = $db->query($query, [$batchId]);
        } catch (Throwable $packagingError) {
            error_log('Reader packaging query error: ' . $packagingError->getMessage());
            $packagingDetails = [];
        }
    }

    $packagingFormatted = array_map(static function (array $row) {
        return [
            'id' => $row['id'] ?? null,
            'name' => $row['name'] ?? null,
            'unit' => $row['unit'] ?? null,
            'quantity_used' => isset($row['quantity_used']) ? (float) $row['quantity_used'] : null,
            'supplier_id' => isset($row['supplier_id']) ? (int) $row['supplier_id'] : null,
            'supplier_name' => $row['supplier_name'] ?? null,
        ];
    }, $packagingDetails);

    $rawMaterials = [];
    $rawMaterialsFormatted = null;
    $rawAvailable = $batchId > 0 && function_exists('readerTableExists') && readerTableExists($db, 'batch_raw_materials');
    if (!$rawAvailable) {
        // Raw materials table not available; no notes returned
    }
    if ($rawAvailable) {
        $hasRawMaterials = readerTableExists($db, 'raw_materials');
        $selectColumns = [
            'brm.id',
            'brm.quantity_used',
        ];
        if ($hasRawMaterials) {
            $selectColumns[] = 'COALESCE(brm.material_name, rm.name) AS name';
            $selectColumns[] = 'COALESCE(brm.unit, rm.unit) AS unit';
        } else {
            $selectColumns[] = 'brm.material_name AS name';
            $selectColumns[] = 'brm.unit AS unit';
        }
        if (readerTableExists($db, 'raw_materials') && readerTableExists($db, 'suppliers') && batchCreationColumnExists($db, 'raw_materials', 'supplier_id')) {
            $selectColumns[] = 's.name AS supplier_name';
            $selectColumns[] = 's.id AS supplier_id';
        } else {
            $selectColumns[] = 'NULL AS supplier_name';
            $selectColumns[] = 'brm.supplier_id AS supplier_id';
        }

        $query = "SELECT " . implode(', ', $selectColumns) . " FROM batch_raw_materials brm";
        if ($hasRawMaterials) {
            $query .= " LEFT JOIN raw_materials rm ON rm.id = brm.raw_material_id";
            if (readerTableExists($db, 'suppliers') && batchCreationColumnExists($db, 'raw_materials', 'supplier_id')) {
                $query .= " LEFT JOIN suppliers s ON s.id = rm.supplier_id";
            }
        }
        $query .= " WHERE brm.batch_id = ?";

        try {
            $rawMaterials = $db->query($query, [$batchId]);
        } catch (Throwable $rawError) {
            error_log('Reader raw materials query error: ' . $rawError->getMessage());
            $rawMaterials = [];
        }

        $rawMaterialsFormatted = array_map(static function (array $row) {
            return [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'unit' => $row['unit'] ?? null,
                'quantity_used' => isset($row['quantity_used']) ? (float) $row['quantity_used'] : null,
                'supplier_id' => isset($row['supplier_id']) ? (int) $row['supplier_id'] : null,
                'supplier_name' => $row['supplier_name'] ?? null,
            ];
        }, $rawMaterials);
    }

    $workersDetails = [];
    $workersAvailable = $batchId > 0 && function_exists('readerTableExists') && readerTableExists($db, 'batch_workers');
    if (!$workersAvailable) {
        // Workers table not available; no notes returned
    }
    if ($workersAvailable) {
        try {
            $joinEmployees = function_exists('readerTableExists') && readerTableExists($db, 'employees');
            $joinUsers = function_exists('readerTableExists') && readerTableExists($db, 'users');

            $selectParts = [
                'bw.id',
                'bw.employee_id',
            ];
            if ($joinEmployees) {
                $selectParts[] = 'e.name AS employee_name';
            }
            if ($joinUsers) {
                $selectParts[] = 'u.full_name';
                $selectParts[] = 'u.username';
                $selectParts[] = 'u.role';
            }

            $query = "SELECT " . implode(', ', $selectParts) . " FROM batch_workers bw";
            if ($joinEmployees) {
                $query .= " LEFT JOIN employees e ON e.id = bw.employee_id";
            }
            if ($joinUsers) {
                $query .= " LEFT JOIN users u ON u.id = bw.employee_id";
            }
            $query .= " WHERE bw.batch_id = ?";

            $workersDetails = $db->query($query, [$batchId]);
        } catch (Throwable $workersError) {
            error_log('Reader workers query error: ' . $workersError->getMessage());
            $workersDetails = [];
        }
    }

    $workersFormatted = array_map(static function (array $row) {
        return [
            'assignment_id' => $row['id'] ?? null,
            'worker_id' => $row['employee_id'] ?? null,
            'full_name' => $row['employee_name'] ?? $row['full_name'] ?? $row['username'] ?? null,
            'role' => $row['role'] ?? 'عامل إنتاج',
        ];
    }, $workersDetails);

    $suppliersFormatted = [];
    if (function_exists('readerTableExists') && readerTableExists($db, 'batch_suppliers')) {
        try {
            $suppliersFormatted = $db->query(
                "SELECT bs.supplier_id, s.name, bs.role
                 FROM batch_suppliers bs
                 LEFT JOIN suppliers s ON s.id = bs.supplier_id
                 WHERE bs.batch_id = ?",
                [$batchId]
            );
        } catch (Throwable $supplierError) {
            error_log('Reader suppliers query error: ' . $supplierError->getMessage());
            $suppliersFormatted = [];
        }
    }

    $suppliersFormatted = array_map(static function (array $row) {
        return [
            'supplier_id' => isset($row['supplier_id']) ? (int) $row['supplier_id'] : null,
            'name' => $row['name'] ?? null,
            'role' => $row['role'] ?? null,
        ];
    }, $suppliersFormatted);

    $response = [
        'success' => true,
        'batch' => [
            'id' => $batch['id'] ?? null,
            'batch_number' => $batch['batch_number'] ?? $batchNumber,
            'product_name' => $batch['product_name'] ?? null,
            'production_date' => $batch['production_date'] ?? null,
            'quantity' => $batch['quantity'] ?? null,
            'quantity_produced' => $batch['quantity_produced'] ?? null,
            'packaging_materials' => $packagingFormatted,
            'raw_materials' => $rawMaterialsFormatted,
            'workers' => $workersFormatted,
            'suppliers' => $suppliersFormatted
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
