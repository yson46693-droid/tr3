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
        
        // التحقق من وجود جدول production_materials
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'production_materials'");
        
        $productions = [];
        if (!empty($tableCheck)) {
            // إعادة تعريف المتغيرات المطلوبة
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
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i>مخزن أدوات التعبئة</h2>
    <?php if ($currentUser['role'] === 'manager'): ?>
        <?php
        // الحصول على المسار الصحيح
        $basePath = getBasePath();
        $dashboardUrl = rtrim($basePath, '/') . '/dashboard/';
        $dashboardUrl = str_replace('//', '/', $dashboardUrl);
        ?>
        <a href="<?php echo $dashboardUrl; ?>manager.php?page=import_packaging" class="btn btn-outline-primary">
            <i class="bi bi-upload me-2"></i>استيراد أدوات التعبئة
        </a>
    <?php endif; ?>
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
    // TODO: إنشاء modal للتعديل
    // حالياً: إعادة توجيه إلى صفحة التعديل
    const currentUrl = window.location.href;
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('edit', '1');
    urlParams.set('material_id', materialId);
    urlParams.delete('p');
    
    window.location.href = window.location.pathname + '?' + urlParams.toString();
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

