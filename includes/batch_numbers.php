<?php
/**
 * نظام أرقام التشغيلة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/batch_creation.php';
/**
 * توليد رقم تشغيلة بالصيغة الجديدة المعتمدة على القالب والموردين.
 */
function generateBatchNumber(
    $productId,
    $productionDate,
    $honeySupplierId = null,
    $packagingSupplierId = null,
    $workersIds = [],
    array $context = []
) {
    $db = db();

    // التحقق من وجود المنتج
    $product = $db->queryOne("SELECT id FROM products WHERE id = ?", [$productId]);
    if (!$product) {
        return null;
    }

    $templateId = isset($context['template_id']) ? max(0, (int) $context['template_id']) : 0;
    $allSuppliersContext = isset($context['all_suppliers']) && is_array($context['all_suppliers'])
        ? $context['all_suppliers']
        : [];

    // تاريخ التنفيذ (اليوم الحالي) بصيغة YYYYMMDD
    $executionDateRaw = $context['execution_date'] ?? date('Y-m-d');
    $executionTimestamp = strtotime((string) $executionDateRaw);
    $executionDate = $executionTimestamp ? date('Ymd', $executionTimestamp) : date('Ymd');

    // تاريخ الإنتاج المختصر بصيغة YYMMDD
    $productionTimestamp = strtotime((string) $productionDate);
    $productionDateShort = $productionTimestamp ? date('ymd', $productionTimestamp) : date('ymd');

    // تجميع معرفات الموردين
    $supplierIds = [];
    if (!empty($honeySupplierId)) {
        $supplierIds[] = (int) $honeySupplierId;
    }
    if (!empty($packagingSupplierId)) {
        $supplierIds[] = (int) $packagingSupplierId;
    }
    foreach ($allSuppliersContext as $supplierRow) {
        if (!empty($supplierRow['id'])) {
            $supplierIds[] = (int) $supplierRow['id'];
        }
    }
    $supplierIds = array_values(array_unique(array_filter($supplierIds, static function ($value) {
        return (int) $value > 0;
    })));

    // جلب أكواد الموردين
    $supplierCodesMap = [];
    if (!empty($supplierIds)) {
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $supplierRows = $db->query(
            "SELECT id, supplier_code FROM suppliers WHERE id IN ($placeholders)",
            $supplierIds
        );

        foreach ($supplierRows as $row) {
            $rawCode = strtoupper(trim((string) ($row['supplier_code'] ?? '')));
            $normalized = preg_replace('/[^A-Z0-9]/', '', $rawCode);
            if ($normalized === '') {
                $normalized = str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT);
            }
            $supplierCodesMap[(int) $row['id']] = $normalized;
        }
    }
    foreach ($supplierIds as $supplierId) {
        if (!isset($supplierCodesMap[$supplierId])) {
            $supplierCodesMap[$supplierId] = str_pad((string) $supplierId, 3, '0', STR_PAD_LEFT);
        }
    }
    $supplierCodesOrdered = array_map(static function ($supplierId) use ($supplierCodesMap) {
        return $supplierCodesMap[$supplierId];
    }, $supplierIds);
    $supplierSegment = !empty($supplierCodesOrdered) ? implode('_', $supplierCodesOrdered) : '000';

    // إعداد معرفات العمال
    $workersIds = is_array($workersIds) ? $workersIds : [];
    $workerIdsUnique = array_values(array_unique(array_map('intval', $workersIds)));
    if (empty($workerIdsUnique)) {
        $workerIdsUnique = [0];
    }
    $workerCodes = array_map(static function ($workerId) {
        return str_pad((string) $workerId, 3, '0', STR_PAD_LEFT);
    }, $workerIdsUnique);
    $workerSegment = implode('_', $workerCodes);

    $templateSegment = str_pad((string) $templateId, 4, '0', STR_PAD_LEFT);

    $buildBatchNumber = static function (string $randomSegment) use ($templateSegment, $supplierSegment, $executionDate, $workerSegment, $productionDateShort) {
        return sprintf(
            'TPL%s-SUP%s-EX%s-WRK%s-PD%s-%s',
            $templateSegment,
            $supplierSegment,
            $executionDate,
            $workerSegment,
            $productionDateShort,
            $randomSegment
        );
    };

    $attempts = 0;
    $randomSegment = 'R' . str_pad((string) rand(0, 999), 3, '0', STR_PAD_LEFT);
    $batchNumber = $buildBatchNumber($randomSegment);

    while ($db->queryOne("SELECT id FROM batches WHERE batch_number = ?", [$batchNumber])) {
        $attempts++;
        if ($attempts > 200) {
            $randomSegment = 'F' . str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT);
        } else {
            $randomSegment = 'R' . str_pad((string) rand(0, 999), 3, '0', STR_PAD_LEFT);
        }
        $batchNumber = $buildBatchNumber($randomSegment);
    }

    return $batchNumber;
}

/**
 * إنشاء رقم تشغيلة جديد
 */
function createBatchNumber(
    $productId,
    $productionId,
    $productionDate,
    $honeySupplierId = null,
    $packagingMaterials = [],
    $packagingSupplierId = null,
    $workers = [],
    $quantity = 1,
    $expiryDate = null,
    $notes = null,
    $createdBy = null,
    $allSuppliers = [],
    $honeyVariety = null,
    $templateId = null,
    array $rawMaterialsUsage = [],
    array $packagingUsage = []
) {
    $templateId = $templateId !== null ? (int) $templateId : 0;
    $units = (int) $quantity;
    $db = db();

    if ($templateId <= 0) {
        return [
            'success' => false,
            'message' => 'لا يمكن إنشاء التشغيلة بدون قالب منتج مرتبط. يرجى اختيار قالب مناسب أولاً.',
        ];
    }

    if ($units <= 0) {
        $units = 1;
    }

    $creationResult = batchCreationCreate($templateId, $units, $rawMaterialsUsage, $packagingUsage);

    if (empty($creationResult['success'])) {
        return [
            'success' => false,
            'message' => $creationResult['message'] ?? 'تعذر إنشاء التشغيلة باستخدام النظام الجديد',
        ];
    }

    $persisted = false;
    $batchNumberValue = $creationResult['batch_number'] ?? null;

    if ($batchNumberValue) {
        try {
            $tableCheck = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
            if (!empty($tableCheck)) {
                $productIdValue = $creationResult['product_id'] ?? ($productId ?: null);
                $productionDateValue = $creationResult['production_date'] ?? $productionDate;
                $expiryDateValue = $creationResult['expiry_date'] ?? $expiryDate;
                $quantityValue = (int) ($creationResult['quantity'] ?? $units);
                $statusValue = $quantityValue > 0 ? 'completed' : 'in_production';

                $packagingMaterials = array_values(array_filter(
                    array_map('intval', is_array($packagingMaterials) ? $packagingMaterials : []),
                    static function ($value) {
                        return $value > 0;
                    }
                ));
                $workersList = array_values(array_filter(
                    array_map('intval', is_array($workers) ? $workers : []),
                    static function ($value) {
                        return $value > 0;
                    }
                ));

                $packagingJson = !empty($packagingMaterials) ? json_encode($packagingMaterials, JSON_UNESCAPED_UNICODE) : null;
                $workersJson = !empty($workersList) ? json_encode($workersList, JSON_UNESCAPED_UNICODE) : null;
                $allSuppliersJsonOptions = defined('JSON_INVALID_UTF8_SUBSTITUTE')
                    ? JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    : JSON_UNESCAPED_UNICODE;
                $allSuppliersJson = !empty($allSuppliers) ? json_encode($allSuppliers, $allSuppliersJsonOptions) : null;

                $createdByValue = $createdBy;
                if ($createdByValue === null) {
                    if (!function_exists('getCurrentUser')) {
                        $authPath = __DIR__ . '/auth.php';
                        if (file_exists($authPath)) {
                            require_once $authPath;
                        }
                    }
                    if (function_exists('getCurrentUser')) {
                        $currentUser = getCurrentUser();
                        if (!empty($currentUser['id'])) {
                            $createdByValue = (int) $currentUser['id'];
                        }
                    }
                }
                if ($createdByValue === null) {
                    $createdByValue = 0;
                }

                $columns = [
                    'batch_number',
                    'product_id',
                    'production_id',
                    'production_date',
                    'honey_supplier_id',
                    'packaging_materials',
                    'packaging_supplier_id',
                    'workers',
                    'quantity',
                    'status',
                    'expiry_date',
                    'notes',
                    'created_by',
                ];
                $placeholders = array_fill(0, count($columns), '?');
                $values = [
                    $batchNumberValue,
                    $productIdValue ?: null,
                    $productionId ?: null,
                    $productionDateValue ?: null,
                    $honeySupplierId ?: null,
                    $packagingJson,
                    $packagingSupplierId ?: null,
                    $workersJson,
                    $quantityValue,
                    $statusValue,
                    $expiryDateValue ?: null,
                    $notes ?: null,
                    $createdByValue,
                ];

                $hasAllSuppliersColumn = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'all_suppliers'");
                if (!empty($hasAllSuppliersColumn)) {
                    $columns[] = 'all_suppliers';
                    $placeholders[] = '?';
                    $values[] = $allSuppliersJson;
                }

                $hasHoneyVarietyColumn = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'honey_variety'");
                if (!empty($hasHoneyVarietyColumn)) {
                    $columns[] = 'honey_variety';
                    $placeholders[] = '?';
                    $values[] = $honeyVariety ?? null;
                }

                $insertSql = sprintf(
                    "INSERT INTO batch_numbers (%s) VALUES (%s)
                     ON DUPLICATE KEY UPDATE
                        product_id = VALUES(product_id),
                        production_id = VALUES(production_id),
                        production_date = VALUES(production_date),
                        honey_supplier_id = VALUES(honey_supplier_id),
                        packaging_materials = VALUES(packaging_materials),
                        packaging_supplier_id = VALUES(packaging_supplier_id),
                        workers = VALUES(workers),
                        quantity = VALUES(quantity),
                        status = VALUES(status),
                        expiry_date = VALUES(expiry_date),
                        notes = VALUES(notes),
                        created_by = VALUES(created_by)%s%s,
                        updated_at = NOW()",
                    implode(', ', $columns),
                    implode(', ', $placeholders),
                    !empty($hasAllSuppliersColumn) ? ",\n                        all_suppliers = VALUES(all_suppliers)" : '',
                    !empty($hasHoneyVarietyColumn) ? ",\n                        honey_variety = VALUES(honey_variety)" : ''
                );

                $db->execute($insertSql, $values);
                $persisted = true;
            }
        } catch (Throwable $storageException) {
            error_log('Batch numbers persistence error: ' . $storageException->getMessage());
        }
    }

    return [
        'success'        => true,
        'message'        => $creationResult['message'] ?? 'تم إنشاء التشغيله بنجاح',
        'batch_id'       => $creationResult['batch_id'] ?? null,
        'batch_number'   => $batchNumberValue,
        'product_id'     => $creationResult['product_id'] ?? ($productId ?: null),
        'product_name'   => $creationResult['product_name'] ?? null,
        'quantity'       => $creationResult['quantity'] ?? $units,
        'production_date'=> $creationResult['production_date'] ?? $productionDate,
        'expiry_date'    => $creationResult['expiry_date'] ?? $expiryDate,
        'persisted'      => $persisted,
        'stock_deducted' => !empty($creationResult['stock_deducted']),
    ];
}

/**
 * الحصول على رقم تشغيلة
 */
function getBatchNumber($batchId) {
    $db = db();
    
    $batch = $db->queryOne(
        "SELECT 
            b.id,
            b.product_id,
            b.batch_number,
            b.production_date,
            b.expiry_date,
            b.quantity,
            b.created_at,
            COALESCE(fp.product_name, p.name) AS product_name,
            fp.quantity_produced,
            p.category AS product_category
         FROM batches b
         LEFT JOIN finished_products fp ON fp.batch_id = b.id
         LEFT JOIN products p ON b.product_id = p.id
         WHERE b.id = ?",
        [$batchId]
    );
    
    if ($batch) {
        $batchIdValue = (int) $batch['id'];
        $batch['quantity_produced'] = $batch['quantity_produced'] ?? $batch['quantity'];

        $rawMaterialsTableExists = $db->queryOne("SHOW TABLES LIKE 'batch_raw_materials'");
        if (!empty($rawMaterialsTableExists)) {
            $rawMaterialsLookupExists = $db->queryOne("SHOW TABLES LIKE 'raw_materials'");
            if (!empty($rawMaterialsLookupExists)) {
                $batch['raw_materials'] = $db->query(
                    "SELECT 
                        brm.quantity_used,
                        COALESCE(brm.material_name, rm.name) AS name,
                        COALESCE(brm.unit, rm.unit) AS unit
                     FROM batch_raw_materials brm
                     LEFT JOIN raw_materials rm ON brm.raw_material_id = rm.id
                     WHERE brm.batch_id = ?",
                    [$batchIdValue]
                );
            } else {
                $batch['raw_materials'] = $db->query(
                    "SELECT 
                        brm.quantity_used,
                        brm.material_name AS name,
                        brm.unit
                     FROM batch_raw_materials brm
                     WHERE brm.batch_id = ?",
                    [$batchIdValue]
                );
            }
        } else {
            $batch['raw_materials'] = [];
        }

        $batchPackagingTableExists = $db->queryOne("SHOW TABLES LIKE 'batch_packaging'");
        if (!empty($batchPackagingTableExists)) {
            $packagingLookupExists = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
            if (!empty($packagingLookupExists)) {
                $batch['packaging_materials_details'] = $db->query(
                    "SELECT 
                        bp.quantity_used,
                        COALESCE(bp.packaging_name, pm.name) AS name,
                        COALESCE(bp.unit, pm.unit) AS unit
                     FROM batch_packaging bp
                     LEFT JOIN packaging_materials pm ON bp.packaging_material_id = pm.id
                     WHERE bp.batch_id = ?",
                    [$batchIdValue]
                );
            } else {
                $batch['packaging_materials_details'] = $db->query(
                    "SELECT 
                        bp.quantity_used,
                        bp.packaging_name AS name,
                        bp.unit
                     FROM batch_packaging bp
                     WHERE bp.batch_id = ?",
                    [$batchIdValue]
                );
            }
        } else {
            $batch['packaging_materials_details'] = [];
        }

        $batchWorkersTableExists = $db->queryOne("SHOW TABLES LIKE 'batch_workers'");
        if (!empty($batchWorkersTableExists)) {
            $employeesTableExists = $db->queryOne("SHOW TABLES LIKE 'employees'");
            if (!empty($employeesTableExists)) {
                $batch['workers_details'] = $db->query(
                    "SELECT 
                        bw.employee_id AS id,
                        e.name AS full_name
                     FROM batch_workers bw
                     LEFT JOIN employees e ON bw.employee_id = e.id
                     WHERE bw.batch_id = ?",
                    [$batchIdValue]
                );
            } else {
                $usersTableExists = $db->queryOne("SHOW TABLES LIKE 'users'");
                if (!empty($usersTableExists)) {
                    $nameColumn = 'name';
                    foreach (['full_name', 'name', 'username'] as $candidate) {
                        $columnExists = $db->queryOne("SHOW COLUMNS FROM users LIKE '{$candidate}'");
                        if (!empty($columnExists)) {
                            $nameColumn = $candidate;
                            break;
                        }
                    }
                    $batch['workers_details'] = $db->query(
                        "SELECT 
                            bw.employee_id AS id,
                            u.{$nameColumn} AS full_name
                         FROM batch_workers bw
                         LEFT JOIN users u ON bw.employee_id = u.id
                         WHERE bw.batch_id = ?",
                        [$batchIdValue]
                    );
                } else {
                    $availableNameColumn = null;
                    foreach (['employee_name', 'worker_name', 'name', 'full_name'] as $candidate) {
                        $columnExists = $db->queryOne("SHOW COLUMNS FROM batch_workers LIKE '{$candidate}'");
                        if (!empty($columnExists)) {
                            $availableNameColumn = $candidate;
                            break;
                        }
                    }

                    if ($availableNameColumn !== null) {
                        $batch['workers_details'] = $db->query(
                            "SELECT 
                                bw.employee_id AS id,
                                bw.{$availableNameColumn} AS full_name
                             FROM batch_workers bw
                             WHERE bw.batch_id = ?",
                            [$batchIdValue]
                        );
                    } else {
                        $rawWorkers = $db->query(
                            "SELECT bw.employee_id AS id FROM batch_workers bw WHERE bw.batch_id = ?",
                            [$batchIdValue]
                        );
                        $batch['workers_details'] = array_map(static function ($worker) {
                            $workerId = isset($worker['id']) ? (int)$worker['id'] : null;
                            return [
                                'id' => $workerId,
                                'full_name' => $workerId ? ('عامل #' . $workerId) : 'عامل غير معروف'
                            ];
                        }, is_array($rawWorkers) ? $rawWorkers : []);
                    }
                }
            }
        } else {
            $batch['workers_details'] = [];
        }

        // جلب معلومات مورد العسل ومورد التعبئة
        $batchNumbersTableExists = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
        if (!empty($batchNumbersTableExists)) {
            // البحث باستخدام batch_number (النص) وليس batch_id
            $batchNumberValue = $batch['batch_number'] ?? null;
            if ($batchNumberValue) {
                $batchNumberRecord = $db->queryOne(
                    "SELECT honey_supplier_id, packaging_supplier_id 
                     FROM batch_numbers 
                     WHERE batch_number = ? 
                     LIMIT 1",
                    [$batchNumberValue]
                );
            } else {
                $batchNumberRecord = null;
            }
            
            if (!empty($batchNumberRecord)) {
                $honeySupplierId = isset($batchNumberRecord['honey_supplier_id']) ? (int)$batchNumberRecord['honey_supplier_id'] : null;
                $packagingSupplierId = isset($batchNumberRecord['packaging_supplier_id']) ? (int)$batchNumberRecord['packaging_supplier_id'] : null;
                
                // جلب اسم مورد العسل
                if ($honeySupplierId > 0) {
                    $suppliersTableExists = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
                    if (!empty($suppliersTableExists)) {
                        $honeySupplier = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$honeySupplierId]);
                        if (!empty($honeySupplier) && !empty($honeySupplier['name'])) {
                            $batch['honey_supplier_name'] = trim((string)$honeySupplier['name']);
                        }
                    }
                }
                
                // جلب اسم مورد التعبئة
                if ($packagingSupplierId > 0) {
                    $suppliersTableExists = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
                    if (!empty($suppliersTableExists)) {
                        $packagingSupplier = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$packagingSupplierId]);
                        if (!empty($packagingSupplier) && !empty($packagingSupplier['name'])) {
                            $batch['packaging_supplier_name'] = trim((string)$packagingSupplier['name']);
                        }
                    }
                }
            }
        }

        return $batch;
    }
    
    return null;
}

/**
 * الحصول على رقم تشغيلة برقم التشغيلة
 */
function getBatchByNumber($batchNumber) {
    if (empty($batchNumber)) {
        return null;
    }
    
    $db = db();
    
    $batchNumber = trim($batchNumber);
    
    $batch = $db->queryOne("SELECT id FROM batches WHERE batch_number = ?", [$batchNumber]);
    
    if (!$batch) {
        return null;
    }
    
    return getBatchNumber((int) $batch['id']);
}

/**
 * تسجيل فحص باركود
 */
function recordBarcodeScan($batchNumber, $scanType = 'verification', $scanLocation = null, $scannedBy = null) {
    try {
        $db = db();
        
        if ($scannedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $scannedBy = $currentUser['id'] ?? null;
        }
        
        $batch = $db->queryOne("SELECT id FROM batches WHERE batch_number = ?", [$batchNumber]);
        
        if (!$batch) {
            return ['success' => false, 'message' => 'رقم التشغيلة غير موجود'];
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $hasBatchIdColumn = $db->queryOne("SHOW COLUMNS FROM barcode_scans LIKE 'batch_id'");
        $hasBatchNumberIdColumn = $db->queryOne("SHOW COLUMNS FROM barcode_scans LIKE 'batch_number_id'");

        if (!empty($hasBatchIdColumn)) {
            $db->execute(
                "INSERT INTO barcode_scans (batch_id, scanned_by, scan_location, scan_type, ip_address) 
                 VALUES (?, ?, ?, ?, ?)",
                [$batch['id'], $scannedBy, $scanLocation, $scanType, $ipAddress]
            );
        } elseif (!empty($hasBatchNumberIdColumn)) {
            return ['success' => false, 'message' => 'إصدار قاعدة البيانات يحتاج إلى ترقية ليتوافق مع نظام التشغيل الجديد.'];
        } else {
            return ['success' => false, 'message' => 'جدول تسجيل الباركود لا يدعم نظام التشغيل الجديد.'];
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Barcode Scan Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الفحص'];
    }
}

/**
 * الحصول على قائمة أرقام التشغيلة
 */
function getBatchNumbers($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT 
                b.id,
                b.batch_number,
                b.product_id,
                b.production_date,
                b.expiry_date,
                b.quantity,
                b.created_at,
                COALESCE(fp.product_name, p.name) AS product_name,
                fp.quantity_produced
            FROM batches b
            LEFT JOIN finished_products fp ON fp.batch_id = b.id
            LEFT JOIN products p ON b.product_id = p.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND b.product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['batch_number'])) {
        $sql .= " AND b.batch_number LIKE ?";
        $params[] = "%{$filters['batch_number']}%";
    }
    
    if (!empty($filters['production_date'])) {
        $sql .= " AND DATE(b.production_date) = ?";
        $params[] = $filters['production_date'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(b.production_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(b.production_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['expiry_from'])) {
        $sql .= " AND DATE(b.expiry_date) >= ?";
        $params[] = $filters['expiry_from'];
    }
    
    if (!empty($filters['expiry_to'])) {
        $sql .= " AND DATE(b.expiry_date) <= ?";
        $params[] = $filters['expiry_to'];
    }
    
    $sql .= " ORDER BY b.production_date DESC, b.id DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على عدد أرقام التشغيلة
 */
function getBatchNumbersCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM batches WHERE 1=1";
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['batch_number'])) {
        $sql .= " AND batch_number LIKE ?";
        $params[] = "%{$filters['batch_number']}%";
    }
    
    if (!empty($filters['production_date'])) {
        $sql .= " AND DATE(production_date) = ?";
        $params[] = $filters['production_date'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(production_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(production_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['expiry_from'])) {
        $sql .= " AND DATE(expiry_date) >= ?";
        $params[] = $filters['expiry_from'];
    }
    
    if (!empty($filters['expiry_to'])) {
        $sql .= " AND DATE(expiry_date) <= ?";
        $params[] = $filters['expiry_to'];
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

/**
 * تحديث حالة رقم التشغيلة
 */
function updateBatchStatus($batchId, $status, $updatedBy = null) {
    try {
        $db = db();
        
        if ($updatedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $updatedBy = $currentUser['id'] ?? null;
        }
        
        $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM batches LIKE 'status'");
        if (empty($statusColumnCheck)) {
            return ['success' => false, 'message' => 'جدول التشغيلات لا يحتوي على عمود حالة.'];
        }
        
        $oldBatch = $db->queryOne("SELECT status FROM batches WHERE id = ?", [$batchId]);
        if (!$oldBatch) {
            return ['success' => false, 'message' => 'لم يتم العثور على التشغيله المطلوبة.'];
        }
        
        $db->execute(
            "UPDATE batches SET status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $batchId]
        );
        
        logAudit(
            $updatedBy,
            'update_batch_status',
            'batch',
            $batchId,
            ['old_status' => $oldBatch['status']],
            ['new_status' => $status]
        );
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Update Batch Status Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تحديث الحالة'];
    }
}

