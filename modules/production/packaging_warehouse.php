<?php
/**
 * صفحة مخزن أدوات التعبئة
 * Packaging Warehouse Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireAnyRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// الفلترة والبحث
$filters = [
    'search' => $_GET['search'] ?? '',
    'material_id' => isset($_GET['material_id']) ? intval($_GET['material_id']) : 0,
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// التحقق من وجود جدول packaging_materials
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$usePackagingTable = !empty($tableCheck);

// إنشاء جدول تسجيل التلفيات إذا لم يكن موجوداً
$damageLogTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_damage_logs'");
if (empty($damageLogTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `packaging_damage_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `material_id` int(11) NOT NULL,
              `material_name` varchar(255) DEFAULT NULL,
              `source_table` enum('packaging_materials','products') NOT NULL DEFAULT 'packaging_materials',
              `quantity_before` decimal(15,4) DEFAULT 0.0000,
              `damaged_quantity` decimal(15,4) NOT NULL,
              `quantity_after` decimal(15,4) DEFAULT 0.0000,
              `unit` varchar(50) DEFAULT NULL,
              `reason` text DEFAULT NULL,
              `recorded_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `material_id` (`material_id`),
              KEY `recorded_by` (`recorded_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating packaging_damage_logs table: " . $e->getMessage());
    }
}

// معالجة طلبات إضافة الكميات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_packaging_quantity') {
        $materialId = intval($_POST['material_id'] ?? 0);
        $additionalQuantity = isset($_POST['additional_quantity']) ? round(floatval($_POST['additional_quantity']), 4) : 0.0;
        $notes = trim($_POST['notes'] ?? '');

        if ($materialId <= 0) {
            $error = 'معرف أداة التعبئة غير صحيح.';
        } elseif ($additionalQuantity <= 0) {
            $error = 'يرجى إدخال كمية صحيحة أكبر من الصفر.';
        } else {
            try {
                $db->beginTransaction();

                if ($usePackagingTable) {
                    $material = $db->queryOne(
                        "SELECT id, name, quantity, unit FROM packaging_materials WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                } else {
                    $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
                    $selectColumns = $unitColumnCheck ? 'id, name, quantity, unit' : 'id, name, quantity';
                    $material = $db->queryOne(
                        "SELECT {$selectColumns} FROM products WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                    if ($unitColumnCheck && $material && !array_key_exists('unit', $material)) {
                        $material['unit'] = null;
                    }
                }

                if (!$material) {
                    throw new Exception('أداة التعبئة غير موجودة أو غير مفعّلة.');
                }

                $quantityBefore = floatval($material['quantity'] ?? 0);
                $quantityAfter = $quantityBefore + $additionalQuantity;

                if ($usePackagingTable) {
                    $db->execute(
                        "UPDATE packaging_materials 
                         SET quantity = ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                } else {
                    $db->execute(
                        "UPDATE products 
                         SET quantity = ? 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                }

                $auditDetailsAfter = [
                    'quantity_after' => $quantityAfter,
                    'added_quantity' => $additionalQuantity
                ];

                if ($notes !== '') {
                    $auditDetailsAfter['notes'] = mb_substr($notes, 0, 500, 'UTF-8');
                }

                logAudit(
                    $currentUser['id'],
                    'add_packaging_quantity',
                    $usePackagingTable ? 'packaging_materials' : 'products',
                    $materialId,
                    ['quantity_before' => $quantityBefore],
                    $auditDetailsAfter
                );

                $db->commit();

                $unitLabel = $material['unit'] ?? 'وحدة';
                $successMessage = sprintf(
                    'تمت إضافة %.2f %s إلى %s بنجاح.',
                    $additionalQuantity,
                    $unitLabel,
                    $material['name'] ?? ('أداة #' . $materialId)
                );

                $redirectParams = ['page' => 'packaging_warehouse'];
                foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                    if (!empty($_GET[$param])) {
                        $redirectParams[$param] = $_GET[$param];
                    }
                }

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'حدث خطأ أثناء تحديث الكمية: ' . $e->getMessage();
            }

            if (empty($error)) {
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            }
        }
    } elseif ($action === 'record_packaging_damage') {
        $materialId = intval($_POST['material_id'] ?? 0);
        $damagedQuantity = isset($_POST['damaged_quantity']) ? round(floatval($_POST['damaged_quantity']), 4) : 0.0;
        $reason = trim($_POST['reason'] ?? '');

        if ($materialId <= 0) {
            $error = 'معرّف الأداة غير صحيح.';
        } elseif ($damagedQuantity <= 0) {
            $error = 'يرجى إدخال كمية تالفة صحيحة أكبر من الصفر.';
        } elseif ($reason === '') {
            $error = 'يرجى ذكر سبب التلف.';
        } else {
            try {
                $db->beginTransaction();

                if ($usePackagingTable) {
                    $material = $db->queryOne(
                        "SELECT id, name, quantity, unit FROM packaging_materials WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                } else {
                    $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
                    $selectColumns = $unitColumnCheck ? 'id, name, quantity, unit' : 'id, name, quantity';
                    $material = $db->queryOne(
                        "SELECT {$selectColumns} FROM products WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                    if ($unitColumnCheck && $material && !array_key_exists('unit', $material)) {
                        $material['unit'] = null;
                    }
                }

                if (!$material) {
                    throw new Exception('أداة التعبئة غير موجودة أو غير مفعّلة.');
                }

                $quantityBefore = floatval($material['quantity'] ?? 0);
                if ($damagedQuantity > $quantityBefore) {
                    throw new Exception('الكمية التالفة تتجاوز الكمية المتاحة.');
                }

                $quantityAfter = max($quantityBefore - $damagedQuantity, 0);

                if ($usePackagingTable) {
                    $db->execute(
                        "UPDATE packaging_materials 
                         SET quantity = ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                } else {
                    $db->execute(
                        "UPDATE products 
                         SET quantity = ? 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                }

                $db->execute(
                    "INSERT INTO packaging_damage_logs 
                     (material_id, material_name, source_table, quantity_before, damaged_quantity, quantity_after, unit, reason, recorded_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $materialId,
                        $material['name'] ?? null,
                        $usePackagingTable ? 'packaging_materials' : 'products',
                        $quantityBefore,
                        $damagedQuantity,
                        $quantityAfter,
                        $material['unit'] ?? null,
                        mb_substr($reason, 0, 500, 'UTF-8'),
                        $currentUser['id']
                    ]
                );

                logAudit(
                    $currentUser['id'],
                    'record_packaging_damage',
                    $usePackagingTable ? 'packaging_materials' : 'products',
                    $materialId,
                    [
                        'quantity_before' => $quantityBefore,
                        'damaged_quantity' => $damagedQuantity
                    ],
                    [
                        'quantity_after' => $quantityAfter,
                        'reason' => mb_substr($reason, 0, 500, 'UTF-8')
                    ]
                );

                $db->commit();

                $unitLabel = $material['unit'] ?? 'وحدة';
                $successMessage = sprintf(
                    'تم تسجيل %.2f %s تالف من %s.',
                    $damagedQuantity,
                    $unitLabel,
                    $material['name'] ?? ('أداة #' . $materialId)
                );

                $redirectParams = ['page' => 'packaging_warehouse'];
                foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                    if (!empty($_GET[$param])) {
                        $redirectParams[$param] = $_GET[$param];
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'حدث خطأ أثناء تسجيل التلف: ' . $e->getMessage();
            }

            if (empty($error)) {
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            }
        }
    } elseif ($action === 'update_packaging_material') {
        if (($currentUser['role'] ?? '') !== 'manager') {
            $error = 'غير مصرح لك بتعديل أدوات التعبئة.';
        } else {
            $materialId = intval($_POST['material_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $typeValue = trim($_POST['type'] ?? '');
            $unitValue = trim($_POST['unit'] ?? '');
            $materialCode = trim($_POST['material_code'] ?? '');
            $specificationsValue = trim($_POST['specifications'] ?? '');
            $statusValue = $_POST['status'] ?? 'active';

            $allowedStatuses = ['active', 'inactive'];
            if (!in_array($statusValue, $allowedStatuses, true)) {
                $statusValue = 'active';
            }

            if ($materialId <= 0) {
                $error = 'معرف الأداة غير صالح.';
            } elseif ($name === '') {
                $error = 'يرجى إدخال اسم الأداة.';
            } else {
                try {
                    $db->beginTransaction();

                    if ($usePackagingTable) {
                        $original = $db->queryOne(
                            "SELECT * FROM packaging_materials WHERE id = ? FOR UPDATE",
                            [$materialId]
                        );

                        if (!$original) {
                            throw new Exception('لم يتم العثور على أداة التعبئة المطلوبة.');
                        }

                        $db->execute(
                            "UPDATE packaging_materials
                             SET name = ?, type = ?, unit = ?, material_id = ?, specifications = ?, status = ?, updated_at = NOW()
                             WHERE id = ?",
                            [
                                $name,
                                $typeValue !== '' ? $typeValue : null,
                                $unitValue !== '' ? $unitValue : null,
                                $materialCode !== '' ? $materialCode : null,
                                $specificationsValue !== '' ? $specificationsValue : null,
                                $statusValue,
                                $materialId
                            ]
                        );
                    } else {
                        $original = $db->queryOne(
                            "SELECT * FROM products WHERE id = ? FOR UPDATE",
                            [$materialId]
                        );

                        if (!$original) {
                            throw new Exception('المنتج غير موجود أو غير متاح.');
                        }

                        $updateParts = ["name = ?"];
                        $updateParams = [$name];

                        if (array_key_exists('type', $original)) {
                            $updateParts[] = "type = ?";
                            $updateParams[] = $typeValue !== '' ? $typeValue : null;
                        } elseif (array_key_exists('category', $original)) {
                            $updateParts[] = "category = ?";
                            $updateParams[] = $typeValue !== '' ? $typeValue : ($original['category'] ?? null);
                        }

                        if (array_key_exists('unit', $original)) {
                            $updateParts[] = "unit = ?";
                            $updateParams[] = $unitValue !== '' ? $unitValue : null;
                        }

                        if (array_key_exists('specifications', $original)) {
                            $updateParts[] = "specifications = ?";
                            $updateParams[] = $specificationsValue !== '' ? $specificationsValue : null;
                        }

                        if (array_key_exists('status', $original)) {
                            $updateParts[] = "status = ?";
                            $updateParams[] = $statusValue;
                        }

                        if (array_key_exists('updated_at', $original)) {
                            $updateParts[] = "updated_at = NOW()";
                        }

                        if (empty($updateParts)) {
                            throw new Exception('لا توجد حقول متاحة للتعديل على هذا المنتج.');
                        }

                        $updateQuery = "UPDATE products SET " . implode(', ', $updateParts) . " WHERE id = ?";
                        $updateParams[] = $materialId;

                        $db->execute($updateQuery, $updateParams);
                    }

                    logAudit(
                        $currentUser['id'],
                        'update_packaging_material',
                        $usePackagingTable ? 'packaging_materials' : 'products',
                        $materialId,
                        $original ?? null,
                        [
                            'name' => $name,
                            'type' => $typeValue,
                            'unit' => $unitValue,
                            'material_id' => $materialCode,
                            'status' => $statusValue
                        ]
                    );

                    $db->commit();

                    $successMessage = 'تم تحديث بيانات أداة التعبئة بنجاح.';
                    $redirectParams = ['page' => 'packaging_warehouse'];
                    foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                        if (!empty($_GET[$param])) {
                            $redirectParams[$param] = $_GET[$param];
                        }
                    }

                    preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                } catch (Throwable $updateError) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'حدث خطأ أثناء تعديل بيانات الأداة: ' . $updateError->getMessage();
                }
            }
        }
    }
}

// تحميل قائمة أدوات التعبئة بعد معالجة الطلبات
if ($usePackagingTable) {
    $packagingMaterials = $db->query(
        "SELECT id, material_id, name, type, specifications, quantity, unit, status, created_at, updated_at
         FROM packaging_materials 
         WHERE status = 'active'
         ORDER BY name"
    );
} else {
    $typeColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'type'");
    $specificationsColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'specifications'");
    $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
    $hasTypeColumn = !empty($typeColumnCheck);
    $hasSpecificationsColumn = !empty($specificationsColumnCheck);

    $columns = ['id', 'name', 'category', 'quantity'];
    if ($hasTypeColumn) {
        $columns[] = 'type';
    }
    if ($hasSpecificationsColumn) {
        $columns[] = 'specifications';
    }
    if ($unitColumnCheck) {
        $columns[] = 'unit';
    }

    $whereConditions = ["(category LIKE '%تغليف%' OR category LIKE '%packaging%'"];
    if ($hasTypeColumn) {
        $whereConditions[0] .= " OR type LIKE '%تغليف%'";
    }
    $whereConditions[0] .= ") AND status = 'active'";

    $packagingMaterials = $db->query(
        "SELECT " . implode(', ', $columns) . " FROM products 
         WHERE " . implode(' AND ', $whereConditions) . "
         ORDER BY name"
    );
}

// بناء استعلام للحصول على الاستخدامات
$materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
$productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
$hasMaterialIdColumn = !empty($materialIdColumnCheck);
$hasProductIdColumn = !empty($productIdColumnCheck);
$materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);

// التحقق من عمود date في جدول production
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck2 = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$productionDateColumn = !empty($productionDateColumnCheck) ? 'date' : (!empty($productionDateColumnCheck2) ? 'production_date' : 'created_at');

// معالجة AJAX لعرض التفاصيل - يجب أن يكون في بداية الملف قبل أي محتوى HTML
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    // بدء output buffering لمنع أي إخراج غير مرغوب فيه
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    try {
        $materialId = intval($_GET['material_id']);
        
        if ($materialId <= 0) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'معرف المادة غير صحيح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $materialRow = $usePackagingTable
            ? $db->queryOne("SELECT * FROM packaging_materials WHERE id = ?", [$materialId])
            : $db->queryOne("SELECT * FROM products WHERE id = ?", [$materialId]);

        if (!$materialRow) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'أداة التعبئة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $materialData = [
            'id' => intval($materialRow['id'] ?? $materialId),
            'material_id' => $materialRow['material_id'] ?? null,
            'name' => $materialRow['name'] ?? '',
            'type' => $materialRow['type'] ?? null,
            'category' => $materialRow['category'] ?? null,
            'specifications' => $materialRow['specifications'] ?? '',
            'unit' => $materialRow['unit'] ?? '',
            'quantity' => isset($materialRow['quantity']) ? floatval($materialRow['quantity']) : null,
            'status' => $materialRow['status'] ?? 'active',
        ];

        foreach (['supplier_id', 'reorder_point', 'lead_time_days', 'unit_price'] as $optionalKey) {
            if (array_key_exists($optionalKey, $materialRow)) {
                $materialData[$optionalKey] = $materialRow[$optionalKey];
            }
        }

        if (empty($materialData['type']) && !empty($materialData['category'])) {
            $materialData['type'] = $materialData['category'];
        }

        // التحقق من وجود جدول production_materials
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'production_materials'");
        
        $productions = [];
        if (!empty($tableCheck)) {
            $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
            $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
            $hasMaterialIdColumn = !empty($materialIdColumnCheck);
            $hasProductIdColumn = !empty($productIdColumnCheck);
            $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
            
            if ($materialColumn) {
                $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
                $productionDateColumnCheck2 = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
                $productionDateColumn = !empty($productionDateColumnCheck) ? 'date' : (!empty($productionDateColumnCheck2) ? 'production_date' : 'created_at');
                
                try {
                    $productions = $db->query(
                        "SELECT 
                            pm.production_id,
                            pm.quantity_used,
                            p.{$productionDateColumn} as date,
                            pr.name as product_name
                         FROM production_materials pm
                         LEFT JOIN production p ON pm.production_id = p.id
                         LEFT JOIN products pr ON p.product_id = pr.id
                         WHERE pm.{$materialColumn} = ?
                         ORDER BY p.{$productionDateColumn} DESC
                         LIMIT 50",
                        [$materialId]
                    );
                } catch (Exception $queryError) {
                    error_log("Error querying production_materials: " . $queryError->getMessage());
                    $productions = [];
                }
            }
        }
        
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'material' => $materialData,
            'productions' => $productions ?: []
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        ob_clean();
        error_log("Error in AJAX material details: " . $e->getMessage());
        error_log("Error stack trace: " . $e->getTraceAsString());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ في تحميل البيانات: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// الحصول على استخدامات أدوات التعبئة
$usageData = [];
if ($materialColumn) {
    if ($usePackagingTable) {
        // إذا كنا نستخدم packaging_materials، نحتاج لربط material_id من packaging_materials
        // أولاً: إنشاء mapping من packaging_materials.id إلى id
        $pkgMaterialsMap = [];
        foreach ($packagingMaterials as $pkg) {
            $pkgMaterialsMap[$pkg['id']] = $pkg['id'];
        }
        
        // ثم البحث عن الاستخدامات بناءً على packaging_materials.id
        $usageQuery = "
            SELECT 
                pm.{$materialColumn} as material_id,
                SUM(pm.quantity_used) as total_used,
                COUNT(DISTINCT pm.production_id) as production_count,
                MIN(p.{$productionDateColumn}) as first_used,
                MAX(p.{$productionDateColumn}) as last_used
            FROM production_materials pm
            LEFT JOIN production p ON pm.production_id = p.id
            WHERE pm.{$materialColumn} IS NOT NULL
            GROUP BY pm.{$materialColumn}
        ";
        
        $usageResults = $db->query($usageQuery);
        foreach ($usageResults as $usage) {
            $materialId = $usage['material_id'];
            // البحث عن packaging_material_id المقابل
            $pkgMaterial = $db->queryOne(
                "SELECT id FROM packaging_materials WHERE id = ? OR material_id LIKE ?",
                [$materialId, '%' . $materialId . '%']
            );
            if ($pkgMaterial) {
                $usageData[$pkgMaterial['id']] = $usage;
            } else {
                // إذا لم نجد، استخدم material_id مباشرة
                $usageData[$materialId] = $usage;
            }
        }
    } else {
        // استخدام products (الطريقة القديمة)
        $usageQuery = "
            SELECT 
                pm.{$materialColumn} as material_id,
                SUM(pm.quantity_used) as total_used,
                COUNT(DISTINCT pm.production_id) as production_count,
                MIN(p.{$productionDateColumn}) as first_used,
                MAX(p.{$productionDateColumn}) as last_used
            FROM production_materials pm
            LEFT JOIN production p ON pm.production_id = p.id
            WHERE pm.{$materialColumn} IS NOT NULL
            GROUP BY pm.{$materialColumn}
        ";
        
        $usageResults = $db->query($usageQuery);
        foreach ($usageResults as $usage) {
            $usageData[$usage['material_id']] = $usage;
        }
    }
}

// الحصول على استخدامات من batch_numbers (باستخدام PHP لمعالجة JSON)
try {
    $batches = $db->query(
        "SELECT id, packaging_materials, quantity, production_date 
         FROM batch_numbers 
         WHERE packaging_materials IS NOT NULL 
         AND packaging_materials != 'null' 
         AND packaging_materials != ''
         AND packaging_materials != '[]'"
    );
    
    foreach ($batches as $batch) {
        $materials = json_decode($batch['packaging_materials'], true);
        if (is_array($materials) && !empty($materials)) {
            foreach ($materials as $materialId) {
                $materialId = intval($materialId);
                if ($materialId > 0) {
                    // إذا كنا نستخدم packaging_materials، نحتاج لربط material_id
                    if ($usePackagingTable) {
                        // البحث عن packaging_material_id من material_id
                        $pkgMaterial = $db->queryOne(
                            "SELECT id FROM packaging_materials WHERE material_id = ? OR id = ?",
                            [$materialId, $materialId]
                        );
                        if ($pkgMaterial) {
                            $materialId = $pkgMaterial['id'];
                        }
                    }
                    
                    if (isset($usageData[$materialId])) {
                        $usageData[$materialId]['total_used'] += $batch['quantity'];
                        $usageData[$materialId]['production_count'] += 1;
                        if (empty($usageData[$materialId]['first_used']) || $batch['production_date'] < $usageData[$materialId]['first_used']) {
                            $usageData[$materialId]['first_used'] = $batch['production_date'];
                        }
                        if (empty($usageData[$materialId]['last_used']) || $batch['production_date'] > $usageData[$materialId]['last_used']) {
                            $usageData[$materialId]['last_used'] = $batch['production_date'];
                        }
                    } else {
                        $usageData[$materialId] = [
                            'total_used' => $batch['quantity'],
                            'production_count' => 1,
                            'first_used' => $batch['production_date'],
                            'last_used' => $batch['production_date']
                        ];
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    // إذا فشل الاستعلام، تجاهل
    error_log("Batch usage processing error: " . $e->getMessage());
}

// تطبيق الفلاتر
$filteredMaterials = [];
foreach ($packagingMaterials as $material) {
    $materialId = $material['id'];
    
    // فلترة البحث
    if (!empty($filters['search'])) {
        $search = strtolower($filters['search']);
        $name = strtolower($material['name'] ?? '');
        $category = strtolower($material['category'] ?? '');
        $type = strtolower($material['type'] ?? '');
        $specifications = strtolower($material['specifications'] ?? '');
        $materialIdStr = strtolower($material['material_id'] ?? '');
        
        if (strpos($name, $search) === false && 
            strpos($category, $search) === false &&
            strpos($type, $search) === false &&
            strpos($specifications, $search) === false &&
            strpos($materialIdStr, $search) === false) {
            continue;
        }
    }
    
    // فلترة حسب المادة
    if ($filters['material_id'] > 0 && $materialId != $filters['material_id']) {
        continue;
    }
    
    // فلترة حسب التاريخ
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $usage = $usageData[$materialId] ?? null;
        if (!$usage) {
            continue; // إذا لم تُستخدم، تخطيها
        }
        
        if (!empty($filters['date_from']) && $usage['last_used'] < $filters['date_from']) {
            continue;
        }
        if (!empty($filters['date_to']) && $usage['first_used'] > $filters['date_to']) {
            continue;
        }
    }
    
    $material['usage'] = $usageData[$materialId] ?? [
        'total_used' => 0,
        'production_count' => 0,
        'first_used' => null,
        'last_used' => null
    ];
    
    $filteredMaterials[] = $material;
}

// Pagination
$totalMaterials = count($filteredMaterials);
$totalPages = ceil($totalMaterials / $perPage);
$paginatedMaterials = array_slice($filteredMaterials, $offset, $perPage);

// إحصائيات
$stats = [
    'total_materials' => count($packagingMaterials),
    'total_used' => array_sum(array_column($usageData, 'total_used')),
    'materials_with_usage' => count($usageData),
    'total_productions' => array_sum(array_column($usageData, 'production_count'))
];

$packagingReport = [
    'generated_at' => date('Y-m-d H:i'),
    'generated_by' => $currentUser['full_name'] ?? ($currentUser['username'] ?? 'مستخدم'),
    'total_materials' => count($packagingMaterials),
    'total_quantity' => 0,
    'type_breakdown' => [],
    'top_items' => [],
    'zero_quantity' => 0,
    'materials_with_usage' => $stats['materials_with_usage'],
    'total_used' => $stats['total_used'],
    'total_productions' => $stats['total_productions'],
    'last_updated' => null
];

$lastUpdatedTimestamp = null;

foreach ($packagingMaterials as $material) {
    $quantity = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
    $unit = trim((string)($material['unit'] ?? ''));
    if ($unit === '') {
        $unit = 'وحدة';
    }

    $typeLabel = trim((string)($material['type'] ?? ($material['category'] ?? '')));
    if ($typeLabel === '') {
        $typeLabel = 'غير مصنف';
    }

    $packagingReport['total_quantity'] += $quantity;
    if ($quantity <= 0) {
        $packagingReport['zero_quantity']++;
    }

    if (!isset($packagingReport['type_breakdown'][$typeLabel])) {
        $packagingReport['type_breakdown'][$typeLabel] = [
            'count' => 0,
            'total_quantity' => 0,
            'units' => []
        ];
    }

    $packagingReport['type_breakdown'][$typeLabel]['count']++;
    $packagingReport['type_breakdown'][$typeLabel]['total_quantity'] += $quantity;
    if (!isset($packagingReport['type_breakdown'][$typeLabel]['units'][$unit])) {
        $packagingReport['type_breakdown'][$typeLabel]['units'][$unit] = 0;
    }
    $packagingReport['type_breakdown'][$typeLabel]['units'][$unit] += $quantity;

    $materialName = $material['name'] ?? ('أداة #' . ($material['id'] ?? ''));
    $packagingReport['top_items'][] = [
        'name' => $materialName,
        'type' => $typeLabel,
        'quantity' => $quantity,
        'unit' => $unit,
        'code' => $material['material_id'] ?? ($material['id'] ?? null)
    ];

    if (!empty($material['updated_at'])) {
        $timestamp = strtotime((string)$material['updated_at']);
        if ($timestamp !== false && ($lastUpdatedTimestamp === null || $timestamp > $lastUpdatedTimestamp)) {
            $lastUpdatedTimestamp = $timestamp;
        }
    } elseif (!empty($material['created_at'])) {
        $timestamp = strtotime((string)$material['created_at']);
        if ($timestamp !== false && ($lastUpdatedTimestamp === null || $timestamp > $lastUpdatedTimestamp)) {
            $lastUpdatedTimestamp = $timestamp;
        }
    }
}

$packagingReport['types_count'] = count($packagingReport['type_breakdown']);

foreach ($packagingReport['type_breakdown'] as $typeKey => &$breakdownEntry) {
    $count = max(1, (int)$breakdownEntry['count']);
    $breakdownEntry['average_quantity'] = $breakdownEntry['total_quantity'] / $count;
}
unset($breakdownEntry);

ksort($packagingReport['type_breakdown'], SORT_NATURAL | SORT_FLAG_CASE);

usort($packagingReport['top_items'], static function ($a, $b) {
    return $b['quantity'] <=> $a['quantity'];
});
$packagingReport['top_items'] = array_slice($packagingReport['top_items'], 0, 8);

$packagingReport['last_updated'] = $lastUpdatedTimestamp
    ? date('Y-m-d H:i', $lastUpdatedTimestamp)
    : null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h2 class="mb-0"><i class="bi bi-box-seam me-2"></i>مخزن أدوات التعبئة</h2>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary" id="generatePackagingReportBtn">
            <i class="bi bi-file-bar-graph me-1"></i>
            توليد تقرير المخزن
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">إجمالي الأدوات</div>
                        <div class="h4 mb-0"><?php echo $stats['total_materials']; ?></div>
                    </div>
                    <div class="text-primary">
                        <i class="bi bi-box-seam fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">إجمالي المستخدم</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_used'], 0); ?></div>
                    </div>
                    <div class="text-warning">
                        <i class="bi bi-arrow-down-circle fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">أدوات مستخدمة</div>
                        <div class="h4 mb-0"><?php echo $stats['materials_with_usage']; ?></div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-check-circle fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">عمليات الإنتاج</div>
                        <div class="h4 mb-0"><?php echo $stats['total_productions']; ?></div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-gear fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="packaging_warehouse">
            <div class="col-md-4">
                <label class="form-label">البحث</label>
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($filters['search']); ?>" 
                       placeholder="اسم أو فئة...">
            </div>
            <div class="col-md-3">
                <label class="form-label">أداة محددة</label>
                <select class="form-select" name="material_id">
                    <option value="0">جميع الأدوات</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedMaterialId = $filters['material_id'];
                    $materialValid = isValidSelectValue($selectedMaterialId, $packagingMaterials, 'id');
                    foreach ($packagingMaterials as $mat): ?>
                        <option value="<?php echo $mat['id']; ?>" 
                                <?php echo $materialValid && $selectedMaterialId == $mat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from']); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($filters['date_to']); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة أدوات التعبئة -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة أدوات التعبئة (<?php echo $totalMaterials; ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($paginatedMaterials)): ?>
            <div class="text-center text-muted py-4">لا توجد أدوات تعبئة</div>
        <?php else: ?>
            <!-- عرض الجدول على الشاشات الكبيرة -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-striped table-sm" style="font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="width: 40px; padding: 0.5rem 0.25rem;">#</th>
                            <th style="padding: 0.5rem 0.25rem;">اسم الأداة</th>
                            <th style="width: 100px; padding: 0.5rem 0.25rem;">الفئة</th>
                            <th style="width: 120px; padding: 0.5rem 0.25rem;">الكمية المتاحة</th>
                            <th style="width: 100px; padding: 0.5rem 0.25rem;">إجمالي المستخدم</th>
                            <th style="width: 80px; padding: 0.5rem 0.25rem;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginatedMaterials as $index => $material): ?>
                            <tr>
                                <td style="padding: 0.4rem 0.25rem;"><?php echo $offset + $index + 1; ?></td>
                                <td style="padding: 0.4rem 0.25rem; line-height: 1.3;">
                                    <div style="font-weight: 600; font-size: 0.875rem;"><?php echo htmlspecialchars($material['name']); ?></div>
                                    <?php if (!empty($material['specifications'])): ?>
                                        <div style="font-size: 0.75rem; color: #6c757d; margin-top: 2px;"><?php echo htmlspecialchars($material['specifications']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($material['material_id'])): ?>
                                        <div style="font-size: 0.7rem; color: #0dcaf0; margin-top: 2px;"><?php echo htmlspecialchars($material['material_id']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.4rem 0.25rem; font-size: 0.8rem;"><?php echo htmlspecialchars($material['type'] ?? $material['category'] ?? '-'); ?></td>
                                <td style="padding: 0.4rem 0.25rem;">
                                    <div style="font-weight: 600; font-size: 0.875rem;" class="text-<?php echo $material['quantity'] > 0 ? 'success' : 'danger'; ?>">
                                        <?php echo number_format($material['quantity'], 2); ?>
                                    </div>
                                    <?php if (!empty($material['unit'])): ?>
                                        <div style="font-size: 0.7rem; color: #6c757d;"><?php echo htmlspecialchars($material['unit']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.4rem 0.25rem;">
                                    <div style="font-weight: 600; font-size: 0.875rem;" class="text-warning">
                                        <?php echo number_format($material['usage']['total_used'] ?? 0, 2); ?>
                                    </div>
                                </td>
                                <td style="padding: 0.4rem 0.25rem;">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-success btn-sm"
                                                data-id="<?php echo $material['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quantity="<?php echo number_format((float)($material['quantity'] ?? 0), 4, '.', ''); ?>"
                                                onclick="openAddQuantityModal(this)"
                                                title="إضافة كمية"
                                                style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm"
                                                data-id="<?php echo $material['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quantity="<?php echo number_format((float)($material['quantity'] ?? 0), 4, '.', ''); ?>"
                                                onclick="openRecordDamageModal(this)"
                                                title="تسجيل تالف"
                                                style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">
                                            <i class="bi bi-exclamation-octagon"></i>
                                        </button>
                                        <?php if ($currentUser['role'] === 'manager'): ?>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="editMaterial(<?php echo $material['id']; ?>)"
                                                    title="تعديل"
                                                    style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- عرض Cards على الموبايل -->
            <div class="d-md-none">
                <?php foreach ($paginatedMaterials as $index => $material): ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($material['name']); ?></h6>
                                    <?php if (!empty($material['specifications'])): ?>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($material['specifications']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($material['material_id'])): ?>
                                        <small class="text-info d-block"><?php echo htmlspecialchars($material['material_id']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-primary">#<?php echo $offset + $index + 1; ?></span>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">الفئة:</small>
                                    <strong><?php echo htmlspecialchars($material['type'] ?? $material['category'] ?? '-'); ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">الكمية:</small>
                                    <strong class="text-<?php echo $material['quantity'] > 0 ? 'success' : 'danger'; ?>">
                                        <?php echo number_format($material['quantity'], 2); ?> 
                                        <?php echo htmlspecialchars($material['unit'] ?? ''); ?>
                                    </strong>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-12">
                                    <small class="text-muted d-block">المستخدم:</small>
                                    <strong class="text-warning"><?php echo number_format($material['usage']['total_used'] ?? 0, 2); ?></strong>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-flex mt-3">
                                <button class="btn btn-sm btn-success flex-fill"
                                        data-id="<?php echo $material['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantity="<?php echo number_format((float)($material['quantity'] ?? 0), 4, '.', ''); ?>"
                                        onclick="openAddQuantityModal(this)">
                                    <i class="bi bi-plus-circle me-2"></i>إضافة كمية
                                </button>
                                <button class="btn btn-sm btn-danger flex-fill"
                                        data-id="<?php echo $material['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantity="<?php echo number_format((float)($material['quantity'] ?? 0), 4, '.', ''); ?>"
                                        onclick="openRecordDamageModal(this)">
                                    <i class="bi bi-exclamation-octagon me-2"></i>تسجيل تالف
                                </button>
                                <?php if ($currentUser['role'] === 'manager'): ?>
                                    <button class="btn btn-sm btn-warning flex-fill" onclick="editMaterial(<?php echo $material['id']; ?>)">
                                        <i class="bi bi-pencil me-2"></i>تعديل
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=packaging_warehouse&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=packaging_warehouse&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=packaging_warehouse&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=packaging_warehouse&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=packaging_warehouse&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة كمية جديدة -->
<div class="modal fade" id="packagingReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>تقرير احترافي لمخزون أدوات التعبئة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="packagingReportContent" class="packaging-report-content">
                    <div class="report-header d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h3 class="fw-semibold mb-1">تقرير مخزن أدوات التعبئة</h3>
                            <div class="text-muted small">تاريخ التوليد: <?php echo htmlspecialchars($packagingReport['generated_at']); ?></div>
                            <div class="text-muted small">أُعد بواسطة: <?php echo htmlspecialchars($packagingReport['generated_by']); ?></div>
                        </div>
                        <div class="text-lg-end">
                            <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
                                <span class="badge bg-primary-subtle text-primary fw-semibold px-3 py-2">
                                    إجمالي الأدوات: <?php echo number_format($packagingReport['total_materials']); ?>
                                </span>
                                <span class="badge bg-success-subtle text-success fw-semibold px-3 py-2">
                                    إجمالي الكمية: <?php echo number_format($packagingReport['total_quantity'], 2); ?>
                                </span>
                                <span class="badge bg-info-subtle text-info fw-semibold px-3 py-2">
                                    فئات الأدوات: <?php echo number_format($packagingReport['types_count']); ?>
                                </span>
                            </div>
                            <div class="text-muted small mt-2">
                                آخر تحديث للسجلات: <?php echo $packagingReport['last_updated'] ? htmlspecialchars($packagingReport['last_updated']) : 'غير متاح'; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-4 p-3 h-100 bg-light">
                                <div class="text-muted small mb-1">إجمالي الأدوات</div>
                                <div class="fs-5 fw-semibold text-primary"><?php echo number_format($packagingReport['total_materials']); ?></div>
                                <div class="text-muted small mt-1">الأدوات النشطة في المخزن</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-4 p-3 h-100 bg-light">
                                <div class="text-muted small mb-1">إجمالي المخزون الحالي</div>
                                <div class="fs-5 fw-semibold text-success"><?php echo number_format($packagingReport['total_quantity'], 2); ?></div>
                                <div class="text-muted small mt-1">جميع الوحدات المتاحة</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-4 p-3 h-100 bg-light">
                                <div class="text-muted small mb-1">أدوات بدون مخزون</div>
                                <div class="fs-5 fw-semibold text-danger"><?php echo number_format($packagingReport['zero_quantity']); ?></div>
                                <div class="text-muted small mt-1">أدوات تحتاج إعادة توريد</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-4 p-3 h-100 bg-light">
                                <div class="text-muted small mb-1">عمليات الإنتاج المرتبطة</div>
                                <div class="fs-5 fw-semibold text-info"><?php echo number_format($packagingReport['total_productions']); ?></div>
                                <div class="text-muted small mt-1">إجمالي العمليات التي استُخدمت فيها الأدوات</div>
                            </div>
                        </div>
                    </div>

                    <h5 class="fw-semibold mb-3"><i class="bi bi-diagram-3 me-2"></i>التوزيع حسب النوع</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>الفئة</th>
                                    <th>عدد الأصناف</th>
                                    <th>إجمالي الكمية</th>
                                    <th>تفاصيل الوحدات</th>
                                    <th>متوسط الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($packagingReport['type_breakdown'])): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">لا توجد بيانات كافية لعرض التوزيع حسب النوع.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($packagingReport['type_breakdown'] as $typeLabel => $info): ?>
                                        <?php
                                        $unitBreakdown = [];
                                        foreach ($info['units'] as $unitName => $unitQuantity) {
                                            $unitBreakdown[] = number_format($unitQuantity, 2) . ' ' . htmlspecialchars($unitName);
                                        }
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($typeLabel); ?></td>
                                            <td><?php echo number_format($info['count']); ?></td>
                                            <td><?php echo number_format($info['total_quantity'], 2); ?></td>
                                            <td><?php echo $unitBreakdown ? implode(' • ', $unitBreakdown) : '-'; ?></td>
                                            <td><?php echo number_format($info['average_quantity'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-semibold mb-3"><i class="bi bi-bar-chart-steps me-2"></i>أعلى الأدوات من حيث الكمية</h5>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>اسم الأداة</th>
                                    <th>الكود/المعرف</th>
                                    <th>الفئة</th>
                                    <th>الكمية المتاحة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($packagingReport['top_items'])): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">لا توجد بيانات لعرض أفضل الأدوات.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($packagingReport['top_items'] as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['code'] ? htmlspecialchars((string)$item['code']) : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($item['type']); ?></td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary fw-semibold">
                                                    <?php echo number_format($item['quantity'], 2) . ' ' . htmlspecialchars($item['unit']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-info d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle-fill fs-4"></i>
                        <div>
                            <div class="fw-semibold">ملاحظات تحليلية</div>
                            <ul class="mb-0 small ps-3">
                                <li>عدد الأدوات بدون مخزون حالياً: <strong><?php echo number_format($packagingReport['zero_quantity']); ?></strong>. يُنصح بمراجعة إجراءات إعادة التوريد.</li>
                                <li>إجمالي الاستخدام من خلال عمليات الإنتاج: <strong><?php echo number_format($packagingReport['total_used'], 2); ?></strong> وحدة عبر <strong><?php echo number_format($packagingReport['total_productions']); ?></strong> عملية معتمدة.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary" id="printPackagingReportBtn">
                    <i class="bi bi-printer me-1"></i>
                    طباعة التقرير
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addQuantityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="addQuantityForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة كمية لأداة التعبئة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_packaging_quantity">
                    <input type="hidden" name="material_id" id="add_quantity_material_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">أداة التعبئة</label>
                        <div class="form-control-plaintext" id="add_quantity_material_name">-</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية الحالية</label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary" id="add_quantity_existing">0</span>
                            <span id="add_quantity_unit" class="text-muted small"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية المضافة <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   class="form-control"
                                   name="additional_quantity"
                                   id="add_quantity_input"
                                   required
                                   placeholder="0.00">
                            <span class="input-group-text" id="add_quantity_unit_suffix"></span>
                        </div>
                        <small class="text-muted">سيتم جمع الكمية المدخلة مع الموجود حالياً في المخزون.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات (اختياري)</label>
                        <textarea class="form-control"
                                  name="notes"
                                  rows="3"
                                  maxlength="500"
                                  placeholder="مثال: إضافة من شحنة جديدة أو تصحيح جرد."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>حفظ الكمية
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تسجيل تالف -->
<div class="modal fade" id="recordDamageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="recordDamageForm">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-octagon me-2"></i>تسجيل تالف لأداة التعبئة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_packaging_damage">
                    <input type="hidden" name="material_id" id="damage_material_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">أداة التعبئة</label>
                        <div class="form-control-plaintext" id="damage_material_name">-</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية الحالية</label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary" id="damage_existing">0</span>
                            <span id="damage_unit" class="text-muted small"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية التالفة <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   class="form-control"
                                   name="damaged_quantity"
                                   id="damage_quantity_input"
                                   required
                                   placeholder="0.00">
                            <span class="input-group-text" id="damage_unit_suffix"></span>
                        </div>
                        <small class="text-muted">سيتم خصم الكمية التالفة من المخزون الحالي.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">سبب التلف <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="damage_reason_input" rows="3" required placeholder="اذكر سبب التلف"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">تسجيل التالف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل أداة التعبئة -->
<div class="modal fade" id="editMaterialModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editMaterialForm">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات أداة التعبئة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_packaging_material">
                    <input type="hidden" name="material_id" id="edit_material_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الأداة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="edit_material_name" required maxlength="255">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الفئة / النوع</label>
                            <input type="text" class="form-control" name="type" id="edit_material_type" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" id="edit_material_unit" maxlength="50">
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label">الكود الداخلي</label>
                            <input type="text" class="form-control" name="material_code" id="edit_material_code" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status" id="edit_material_status">
                                <option value="active">نشطة</option>
                                <option value="inactive">غير نشطة</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label">المواصفات / الوصف</label>
                        <textarea class="form-control" name="specifications" id="edit_material_specifications" rows="3" maxlength="500"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">الكمية الحالية (للقراءة فقط)</label>
                        <div class="form-control-plaintext fw-semibold" id="edit_material_quantity_display">-</div>
                        <small class="text-muted">لتعديل الكمية يرجى استخدام أزرار "إضافة كمية" أو "تسجيل تالف".</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning text-white">
                        <i class="bi bi-check-circle me-2"></i>تحديث الأداة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal لعرض التفاصيل -->
<div class="modal fade" id="materialDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل استخدام الأداة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="materialDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reportButton = document.getElementById('generatePackagingReportBtn');
    const reportModalElement = document.getElementById('packagingReportModal');
    const printButton = document.getElementById('printPackagingReportBtn');

    function getReportModalInstance() {
        if (!reportModalElement) {
            return null;
        }
        if (typeof bootstrap === 'undefined') {
            console.warn('Bootstrap.js غير محمل بعد، تعذّر تهيئة نافذة التقرير.');
            return null;
        }
        return bootstrap.Modal.getOrCreateInstance(reportModalElement);
    }

    if (reportButton) {
        reportButton.addEventListener('click', () => {
            const modalInstance = getReportModalInstance();
            if (modalInstance) {
                modalInstance.show();
            }
        });
    }

    if (printButton) {
        printButton.addEventListener('click', () => {
            const reportContent = document.getElementById('packagingReportContent');
            if (!reportContent) {
                return;
            }

            const printWindow = window.open('', '_blank', 'width=1024,height=768');
            const stylesheets = Array.from(document.querySelectorAll('link[rel="stylesheet"], style'))
                .map((element) => element.outerHTML)
                .join('\n');

            printWindow.document.write(`<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تقرير مخزن أدوات التعبئة</title>
${stylesheets}
<style>
body { font-family: 'Tajawal', 'Cairo', sans-serif; padding: 32px; background: #f8fafc; color: #0f172a; }
.report-header { border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 24px; }
.summary-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
.summary-card .label { color: #64748b; font-size: 13px; margin-bottom: 6px; }
.summary-card .value { font-size: 20px; font-weight: 600; }
table { width: 100%; border-collapse: collapse; margin-bottom: 28px; background: #fff; border-radius: 16px; overflow: hidden; }
table thead { background: #f1f5f9; }
table th, table td { padding: 14px 16px; border: 1px solid #e2e8f0; text-align: right; }
table th { font-weight: 600; color: #1e293b; background: #f8fafc; }
.badge { display: inline-block; padding: 6px 12px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 600; }
.notes { border-left: 4px solid #38bdf8; background: #ecfeff; padding: 16px 20px; border-radius: 12px; }
@media print {
    body { padding: 0; background: #fff; }
    .badge { background: #bfdbfe; color: #1d4ed8; }
}
</style>
</head>
<body>
<div class="report-header">
    <div>
        <h2 style="margin:0 0 8px; font-weight:700;">تقرير مخزن أدوات التعبئة</h2>
        <div style="color:#64748b; font-size:13px;">تاريخ التوليد: <?php echo htmlspecialchars($packagingReport['generated_at']); ?></div>
        <div style="color:#64748b; font-size:13px;">أُعد بواسطة: <?php echo htmlspecialchars($packagingReport['generated_by']); ?></div>
    </div>
    <div style="text-align:left;">
        <div class="badge">إجمالي الأدوات: <?php echo number_format($packagingReport['total_materials']); ?></div>
        <div class="badge" style="margin-right:8px;">إجمالي الكمية: <?php echo number_format($packagingReport['total_quantity'], 2); ?></div>
        <div style="color:#64748b; font-size:12px; margin-top:8px;">آخر تحديث للسجلات: <?php echo $packagingReport['last_updated'] ? htmlspecialchars($packagingReport['last_updated']) : 'غير متاح'; ?></div>
    </div>
</div>
${reportContent.outerHTML}
</body>
</html>`);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        });
    }
});

function openAddQuantityModal(trigger) {
    const modalElement = document.getElementById('addQuantityModal');
    const form = document.getElementById('addQuantityForm');
    if (!modalElement || !form) {
        return;
    }

    const materialIdInput = document.getElementById('add_quantity_material_id');
    const nameElement = document.getElementById('add_quantity_material_name');
    const existingElement = document.getElementById('add_quantity_existing');
    const unitElement = document.getElementById('add_quantity_unit');
    const unitSuffix = document.getElementById('add_quantity_unit_suffix');
    const quantityInput = document.getElementById('add_quantity_input');

    if (form) {
        form.reset();
    }

    const dataset = trigger?.dataset || {};
    const materialId = dataset.id || '';
    const materialName = dataset.name || '-';
    const unit = dataset.unit || 'وحدة';
    const existingQuantity = parseFloat(dataset.quantity || '0') || 0;

    if (!materialId) {
        console.warn('Material id is missing for add quantity modal trigger.');
        return;
    }

    materialIdInput.value = materialId;
    nameElement.textContent = materialName;
    existingElement.textContent = existingQuantity.toLocaleString('ar-EG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    unitElement.textContent = unit;
    unitSuffix.textContent = unit;
    quantityInput.value = '';

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    setTimeout(() => {
        quantityInput.focus();
        quantityInput.select();
    }, 250);
}

function openRecordDamageModal(trigger) {
    const modalElement = document.getElementById('recordDamageModal');
    const form = document.getElementById('recordDamageForm');
    if (!modalElement || !form) {
        return;
    }

    form.reset();

    const dataset = trigger?.dataset || {};
    const materialId = dataset.id || '';
    const materialName = dataset.name || '-';
    const unit = dataset.unit || 'وحدة';
    const existingQuantity = parseFloat(dataset.quantity || '0') || 0;

    if (!materialId) {
        console.warn('Material id is missing for damage modal trigger.');
        return;
    }

    document.getElementById('damage_material_id').value = materialId;
    document.getElementById('damage_material_name').textContent = materialName;
    document.getElementById('damage_existing').textContent = existingQuantity.toLocaleString('ar-EG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    document.getElementById('damage_unit').textContent = unit;
    document.getElementById('damage_unit_suffix').textContent = unit;
    document.getElementById('damage_quantity_input').value = '';
    document.getElementById('damage_reason_input').value = '';

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    setTimeout(() => {
        document.getElementById('damage_quantity_input').focus();
        document.getElementById('damage_quantity_input').select();
    }, 250);
}

function viewMaterialDetails(materialId) {
    const modal = new bootstrap.Modal(document.getElementById('materialDetailsModal'));
    const content = document.getElementById('materialDetailsContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    modal.show();
    
    // الحصول على المسار الصحيح للـ API
    const currentUrl = window.location.href;
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ajax', '1');
    urlParams.set('material_id', materialId);
    urlParams.delete('p'); // إزالة pagination
    
    const apiUrl = window.location.pathname + '?' + urlParams.toString();
    
    // AJAX call to get material details
    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
        },
        credentials: 'same-origin'
    })
        .then(async response => {
            // التحقق من نوع المحتوى
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text);
                console.error('Response status:', response.status);
                console.error('Response URL:', response.url);
                throw new Error('استجابة غير صحيحة من الخادم: ' + text.substring(0, 200));
            }
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('HTTP error response:', errorText);
                throw new Error('HTTP error! status: ' + response.status + ' - ' + errorText.substring(0, 200));
            }
            
            return response.json();
        })
        .then(data => {
            if (data.success) {
                let html = '<div class="mb-3"><h6><i class="bi bi-box-seam me-2"></i>استخدامات الأداة في عمليات الإنتاج</h6></div>';
                if (data.productions && data.productions.length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>رقم العملية</th><th>المنتج</th><th>الكمية المستخدمة</th><th>التاريخ</th></tr></thead><tbody>';
                    data.productions.forEach(prod => {
                        const date = prod.date ? new Date(prod.date).toLocaleDateString('ar-EG') : '-';
                        html += `<tr><td>#${prod.production_id}</td><td>${prod.product_name || '-'}</td><td>${prod.quantity_used || 0}</td><td>${date}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد استخدامات لهذه الأداة في عمليات الإنتاج</div>';
                }
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + (data.message || 'حدث خطأ') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading material details:', error);
            console.error('Error details:', {
                message: error.message,
                name: error.name,
                stack: error.stack
            });
            content.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>حدث خطأ في تحميل البيانات. يرجى المحاولة مرة أخرى.<br><small>' + (error.message || '') + '</small></div>';
        });
}

function editMaterial(materialId) {
    const currentUrl = window.location.href;
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ajax', '1');
    urlParams.set('material_id', materialId);
    urlParams.delete('p');
    
    const apiUrl = window.location.pathname + '?' + urlParams.toString();

    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
        },
        credentials: 'same-origin'
    })
        .then(async response => {
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error('HTTP error! status: ' + response.status + ' - ' + errorText.substring(0, 200));
            }
            return response.json();
        })
        .then(data => {
            if (!data.success || !data.material) {
                throw new Error(data.message || 'تعذر تحميل بيانات الأداة');
            }
            openEditModalFromData(data.material);
        })
        .catch(error => {
            console.error('Error loading material data for edit:', error);
            alert('حدث خطأ أثناء تحميل بيانات الأداة للتعديل. يرجى المحاولة لاحقاً.\n' + (error.message || ''));
        });
}

function openEditModalFromData(material) {
    const modalElement = document.getElementById('editMaterialModal');
    const form = document.getElementById('editMaterialForm');
    if (!modalElement || !form) {
        console.warn('Edit modal not available.');
        return;
    }

    form.reset();

    const materialIdInput = document.getElementById('edit_material_id');
    const nameInput = document.getElementById('edit_material_name');
    const typeInput = document.getElementById('edit_material_type');
    const unitInput = document.getElementById('edit_material_unit');
    const codeInput = document.getElementById('edit_material_code');
    const specsInput = document.getElementById('edit_material_specifications');
    const statusSelect = document.getElementById('edit_material_status');
    const quantityDisplay = document.getElementById('edit_material_quantity_display');

    materialIdInput.value = material.id ?? '';
    nameInput.value = material.name ?? '';

    const resolvedType = (material.type ?? '') || (material.category ?? '');
    typeInput.value = resolvedType;

    unitInput.value = material.unit ?? '';
    codeInput.value = material.material_id ?? '';
    specsInput.value = material.specifications ?? '';

    if (statusSelect) {
        const availableStatuses = Array.from(statusSelect.options).map(option => option.value);
        const desiredStatus = material.status ?? 'active';
        statusSelect.value = availableStatuses.includes(desiredStatus) ? desiredStatus : 'active';
    }

    if (quantityDisplay) {
        const numericQuantity = parseFloat(material.quantity ?? 0);
        if (Number.isFinite(numericQuantity)) {
            quantityDisplay.textContent = numericQuantity.toLocaleString('ar-EG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        } else {
            quantityDisplay.textContent = '-';
        }
    }

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    setTimeout(() => {
        nameInput.focus();
        nameInput.select();
    }, 200);
}
</script>


<style>
/* تحسين عرض الجدول لجعله أكثر إحكاماً */
.table-sm {
    font-size: 0.875rem !important;
}

.table-sm th,
.table-sm td {
    padding: 0.4rem 0.25rem !important;
    vertical-align: middle !important;
}

.table-sm thead th {
    font-weight: 600;
    font-size: 0.8rem;
    white-space: nowrap;
    background-color: #f8f9fa;
}

.table-sm tbody td {
    font-size: 0.85rem;
}

.table-sm .btn-group .btn {
    padding: 0.2rem 0.4rem !important;
    font-size: 0.75rem !important;
}

.table-sm .badge {
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
}

/* تحسين عرض النصوص الطويلة */
.table-sm td {
    word-wrap: break-word;
    max-width: 200px;
}

.table-sm td:first-child {
    max-width: 40px;
}

.table-sm td:nth-child(2) {
    max-width: 250px;
}

/* تحسين line-height للصفوف */
.table-sm tbody tr {
    height: auto;
    min-height: 45px;
}

/* تحسين المسافة بين العناصر داخل الخلايا */
.table-sm td div {
    margin: 0;
    line-height: 1.3;
}

/* تحسين عرض التواريخ */
.table-sm td:nth-child(7),
.table-sm td:nth-child(8) {
    font-size: 0.8rem;
    white-space: nowrap;
}

/* تحسين عرض الأزرار */
.table-sm .btn-group {
    display: flex;
    gap: 2px;
}

.packaging-report-content .badge {
    font-size: 0.75rem;
}

.packaging-report-content .table {
    font-size: 0.85rem;
}

.packaging-report-content .border.rounded-4 {
    background-color: #f8fafc;
}

.packaging-report-content h5 {
    color: #1f2937;
}

@media (max-width: 991px) {
    .table-sm {
        font-size: 0.8rem !important;
    }
    
    .table-sm th,
    .table-sm td {
        padding: 0.3rem 0.2rem !important;
    }
}
</style>

