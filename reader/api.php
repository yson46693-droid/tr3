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
    define('ACCESS_ALLOWED', true);
    $appRoot = realpath(__DIR__ . '/../v1');
    if (!$appRoot) {
        throw new RuntimeException('تعذر تحديد مسار تطبيق v1 الرئيسي.');
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
$readerLogMaxRows = getReaderAccessLogMaxRows();

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
    $batch = getBatchByNumber($batchNumber);
    if (!$batch) {
        http_response_code(404);
        if ($db) {
            try {
                $db->execute(
                    "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'not_found', ?)",
                    [$sessionId, $ipAddress, $batchNumber, 'رقم التشغيلة غير موجود.']
                );
                enforceReaderAccessLogLimit($db, $readerLogMaxRows);
            } catch (Throwable $logError) {
                error_log('Reader log insert error (not found): ' . $logError->getMessage());
            }
        }
        echo json_encode([
            'success' => false,
            'message' => 'رقم التشغيلة غير موجود أو غير صحيح.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statusLabels = [
        'in_production' => 'قيد الإنتاج',
        'completed' => 'مكتملة',
        'archived' => 'مؤرشفة',
        'cancelled' => 'ملغاة',
    ];

    $materials = [];
    if (!empty($batch['packaging_materials_details']) && is_array($batch['packaging_materials_details'])) {
        foreach ($batch['packaging_materials_details'] as $item) {
            $details = [];
            if (!empty($item['type'])) {
                $details[] = 'النوع: ' . $item['type'];
            }
            if (!empty($item['specifications'])) {
                $details[] = $item['specifications'];
            }
            $materials[] = [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? '—',
                'details' => implode(' — ', array_filter($details)),
            ];
        }
    }

    $workers = [];
    if (!empty($batch['workers_details']) && is_array($batch['workers_details'])) {
        foreach ($batch['workers_details'] as $worker) {
            $workers[] = [
                'id' => $worker['id'] ?? null,
                'username' => $worker['username'] ?? null,
                'full_name' => $worker['full_name'] ?? null,
                'role' => 'عامل إنتاج',
            ];
        }
    }

    $response = [
        'success' => true,
        'batch' => [
            'id' => $batch['id'] ?? null,
            'batch_number' => $batch['batch_number'] ?? $batchNumber,
            'product_name' => $batch['product_name'] ?? null,
            'product_category' => $batch['product_category'] ?? null,
            'production_date' => $batch['production_date'] ?? $batch['production_date_value'] ?? null,
            'quantity' => $batch['quantity'] ?? null,
            'status' => $batch['status'] ?? null,
            'status_label' => $statusLabels[$batch['status'] ?? ''] ?? 'غير معروف',
            'honey_supplier_name' => $batch['honey_supplier_name'] ?? null,
            'packaging_supplier_name' => $batch['packaging_supplier_name'] ?? null,
            'notes' => $batch['notes'] ?? null,
            'created_by_name' => $batch['created_by_name'] ?? null,
            'materials' => $materials,
            'workers' => $workers,
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
    error_log('Reader API error: ' . $e->getMessage());
    if ($db) {
        try {
            $db->execute(
                "INSERT INTO reader_access_log (session_id, ip_address, batch_number, status, message) VALUES (?, ?, ?, 'error', ?)",
                [$sessionId, $ipAddress, $batchNumber ?: null, 'خطأ داخلي في الخادم.']
            );
            enforceReaderAccessLogLimit($db, $readerLogMaxRows);
        } catch (Throwable $logError) {
            error_log('Reader log insert error (exception): ' . $logError->getMessage());
        }
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء معالجة الطلب.'
    ], JSON_UNESCAPED_UNICODE);
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
