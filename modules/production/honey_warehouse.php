<?php
/**
 * صفحة مخزن العسل
 * Honey Warehouse Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/honey_varieties.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole('production'); // فقط عامل الإنتاج يمكنه التعديل

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';
$honeyVarietiesCatalog = getHoneyVarietiesCatalog();
$validHoneyVarieties = array_keys($honeyVarietiesCatalog);
$defaultHoneyVariety = 'سدر';

// إنشاء جدول مخزن العسل إذا لم يكن موجوداً
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `honey_stock` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `honey_variety` enum('سدر','جبلي','حبة البركة','موالح','نوارة برسيم','أخرى') DEFAULT 'أخرى' COMMENT 'نوع العسل',
              `raw_honey_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
              `filtered_honey_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id_idx` (`supplier_id`),
              KEY `honey_variety` (`honey_variety`),
              KEY `supplier_variety` (`supplier_id`, `honey_variety`),
              CONSTRAINT `honey_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating honey_stock table: " . $e->getMessage());
    }
} else {
    // إضافة عمود honey_variety إذا لم يكن موجوداً
    $varietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM honey_stock LIKE 'honey_variety'");
    if (empty($varietyColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `honey_stock` 
                ADD COLUMN `honey_variety` enum('سدر','جبلي','حبة البركة','موالح','نوارة برسيم','أخرى') DEFAULT 'أخرى' COMMENT 'نوع العسل' 
                AFTER `supplier_id`
            ");
            $db->execute("ALTER TABLE `honey_stock` ADD KEY `honey_variety` (`honey_variety`)");
            $db->execute("ALTER TABLE `honey_stock` ADD KEY `supplier_variety` (`supplier_id`, `honey_variety`)");
            // إزالة UNIQUE KEY على supplier_id لأن نفس المورد يمكن أن يكون له أنواع مختلفة
            try {
                $db->execute("ALTER TABLE `honey_stock` DROP INDEX `supplier_id`");
            } catch (Exception $e) {
                // قد لا يكون موجوداً
            }
        } catch (Exception $e) {
            error_log("Error adding honey_variety column: " . $e->getMessage());
        }
    }
}

// إنشاء جدول سجل عمليات التصفية
$filtrationTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_filtration'");
if (empty($filtrationTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `honey_filtration` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `raw_honey_quantity` decimal(10,2) NOT NULL,
              `filtered_honey_quantity` decimal(10,2) NOT NULL,
              `filtration_loss` decimal(10,2) NOT NULL COMMENT 'الكمية المفقودة (0.5%)',
              `filtration_date` date NOT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id` (`supplier_id`),
              KEY `filtration_date` (`filtration_date`),
              KEY `created_by` (`created_by`),
              CONSTRAINT `honey_filtration_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
              CONSTRAINT `honey_filtration_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating honey_filtration table: " . $e->getMessage());
    }
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_honey') {
        $supplierId = intval($_POST['supplier_id'] ?? 0);
        $honeyVariety = $_POST['honey_variety'] ?? 'أخرى';
        $quantity = floatval($_POST['quantity'] ?? 0);
        $honeyType = $_POST['honey_type'] ?? 'raw'; // raw or filtered
        
        // التحقق من صحة نوع العسل
        if (!in_array($honeyVariety, $validHoneyVarieties, true)) {
            $honeyVariety = 'أخرى';
        }
        $honeyVarietyCode = getHoneyVarietyCode($honeyVariety);
        
        if ($supplierId <= 0) {
            $error = 'يجب اختيار المورد';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة أكبر من الصفر';
        } else {
            // التحقق من وجود سجل للمورد ونوع العسل
            $existingStock = $db->queryOne(
                "SELECT * FROM honey_stock WHERE supplier_id = ? AND honey_variety = ?",
                [$supplierId, $honeyVariety]
            );
            
            if ($existingStock) {
                // تحديث الكمية
                if ($honeyType === 'raw') {
                    $db->execute(
                        "UPDATE honey_stock SET raw_honey_quantity = raw_honey_quantity + ?, updated_at = NOW() WHERE supplier_id = ? AND honey_variety = ?",
                        [$quantity, $supplierId, $honeyVariety]
                    );
                } else {
                    $db->execute(
                        "UPDATE honey_stock SET filtered_honey_quantity = filtered_honey_quantity + ?, updated_at = NOW() WHERE supplier_id = ? AND honey_variety = ?",
                        [$quantity, $supplierId, $honeyVariety]
                    );
                }
            } else {
                // إنشاء سجل جديد
                if ($honeyType === 'raw') {
                    $db->execute(
                        "INSERT INTO honey_stock (supplier_id, honey_variety, raw_honey_quantity, filtered_honey_quantity) VALUES (?, ?, ?, 0)",
                        [$supplierId, $honeyVariety, $quantity]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO honey_stock (supplier_id, honey_variety, raw_honey_quantity, filtered_honey_quantity) VALUES (?, ?, 0, ?)",
                        [$supplierId, $honeyVariety, $quantity]
                    );
                }
            }
            
            logAudit(
                $currentUser['id'],
                'add_honey',
                'honey_stock',
                $supplierId,
                null,
                [
                    'supplier_id' => $supplierId,
                    'honey_variety' => $honeyVariety,
                    'honey_variety_code' => $honeyVarietyCode,
                    'quantity' => $quantity,
                    'type' => $honeyType,
                ]
            );
            
            $success = 'تم إضافة العسل بنجاح';
        }
    } elseif ($action === 'filter_honey') {
        $stockId = intval($_POST['stock_id'] ?? 0);
        $rawQuantity = floatval($_POST['raw_quantity'] ?? 0);
        $filtrationDate = $_POST['filtration_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($stockId <= 0) {
            $error = 'معرف السجل غير صحيح';
        } elseif ($rawQuantity <= 0) {
            $error = 'يجب إدخال كمية العسل الخام أكبر من الصفر';
        } else {
            // التحقق من وجود كمية كافية من العسل الخام
            $stock = $db->queryOne(
                "SELECT * FROM honey_stock WHERE id = ?",
                [$stockId]
            );
            
            if (!$stock) {
                $error = 'لا يوجد سجل مخزون';
            } elseif ($stock['raw_honey_quantity'] < $rawQuantity) {
                $error = sprintf(
                    'لا يمكن تصفية الكمية المطلوبة!<br>' .
                    '<strong>الكمية المتاحة من العسل الخام:</strong> %s كجم<br>' .
                    '<strong>الكمية المطلوبة للتصفية:</strong> %s كجم<br>' .
                    '<strong>الفرق:</strong> %s كجم<br><br>' .
                    '<small class="text-muted">يرجى إضافة المزيد من العسل الخام أولاً أو تقليل الكمية المطلوبة للتصفية.</small>',
                    number_format($stock['raw_honey_quantity'], 2),
                    number_format($rawQuantity, 2),
                    number_format($rawQuantity - $stock['raw_honey_quantity'], 2)
                );
            } else {
                // حساب كمية العسل بعد التصفية (خصم 0.5%)
                $filtrationLoss = $rawQuantity * 0.005; // 0.5%
                $filteredQuantity = $rawQuantity - $filtrationLoss;
                
                // تحديث المخزون
                $db->execute(
                    "UPDATE honey_stock 
                     SET raw_honey_quantity = raw_honey_quantity - ?, 
                         filtered_honey_quantity = filtered_honey_quantity + ?,
                         updated_at = NOW() 
                     WHERE id = ?",
                    [$rawQuantity, $filteredQuantity, $stockId]
                );
                
                // تسجيل عملية التصفية
                $db->execute(
                    "INSERT INTO honey_filtration (supplier_id, raw_honey_quantity, filtered_honey_quantity, filtration_loss, filtration_date, notes, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$stock['supplier_id'], $rawQuantity, $filteredQuantity, $filtrationLoss, $filtrationDate, $notes ?: null, $currentUser['id']]
                );
                
                $stockVariety = $stock['honey_variety'] ?? 'أخرى';
                $stockVarietyCode = getHoneyVarietyCode($stockVariety);

                logAudit($currentUser['id'], 'filter_honey', 'honey_filtration', $stockId, null, [
                    'supplier_id' => $stock['supplier_id'],
                    'honey_variety' => $stockVariety,
                    'honey_variety_code' => $stockVarietyCode,
                    'raw_quantity' => $rawQuantity,
                    'filtered_quantity' => $filteredQuantity,
                    'loss' => $filtrationLoss
                ]);
                
                $success = 'تمت عملية التصفية بنجاح. الكمية بعد التصفية: ' . number_format($filteredQuantity, 2) . ' كجم (الخسارة: ' . number_format($filtrationLoss, 2) . ' كجم)';
            }
        }
    } elseif ($action === 'update_stock') {
        $stockId = intval($_POST['stock_id'] ?? 0);
        $rawQuantity = floatval($_POST['raw_quantity'] ?? 0);
        $filteredQuantity = floatval($_POST['filtered_quantity'] ?? 0);
        
        if ($stockId <= 0) {
            $error = 'معرف السجل غير صحيح';
        } else {
            $oldStock = $db->queryOne("SELECT * FROM honey_stock WHERE id = ?", [$stockId]);
            
            $db->execute(
                "UPDATE honey_stock SET raw_honey_quantity = ?, filtered_honey_quantity = ?, updated_at = NOW() WHERE id = ?",
                [$rawQuantity, $filteredQuantity, $stockId]
            );
            
            logAudit($currentUser['id'], 'update_honey_stock', 'honey_stock', $stockId, 
                     ['raw' => $oldStock['raw_honey_quantity'], 'filtered' => $oldStock['filtered_honey_quantity']],
                     ['raw' => $rawQuantity, 'filtered' => $filteredQuantity]);
            
            $success = 'تم تحديث المخزون بنجاح';
        }
    }
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// الفلترة والبحث
$filters = [
    'search' => $_GET['search'] ?? '',
    'supplier_id' => isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0
];

// الحصول على جميع الموردين
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name");

// الحصول على مخزون العسل
$whereConditions = [];
$params = [];

if (!empty($filters['search'])) {
    $whereConditions[] = "s.name LIKE ?";
    $params[] = "%{$filters['search']}%";
}

if ($filters['supplier_id'] > 0) {
    $whereConditions[] = "hs.supplier_id = ?";
    $params[] = $filters['supplier_id'];
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// الحصول على العدد الإجمالي
$countQuery = "SELECT COUNT(*) as total 
               FROM honey_stock hs 
               LEFT JOIN suppliers s ON hs.supplier_id = s.id 
               $whereClause";
$totalCount = $db->queryOne($countQuery, $params)['total'] ?? 0;
$totalPages = ceil($totalCount / $perPage);

// الحصول على بيانات المخزون
$query = "SELECT hs.*, s.name as supplier_name, s.phone as supplier_phone
          FROM honey_stock hs
          LEFT JOIN suppliers s ON hs.supplier_id = s.id
          $whereClause
          ORDER BY s.name ASC
          LIMIT ? OFFSET ?";
$queryParams = array_merge($params, [$perPage, $offset]);
$honeyStock = $db->query($query, $queryParams);

// إحصائيات
$stats = [
    'total_suppliers' => $db->queryOne("SELECT COUNT(*) as total FROM honey_stock")['total'] ?? 0,
    'total_raw_honey' => $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
    'total_filtered_honey' => $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
    'total_honey' => 0
];

$stats['total_honey'] = $stats['total_raw_honey'] + $stats['total_filtered_honey'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-droplet me-2"></i>مخزن العسل</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHoneyModal">
        <i class="bi bi-plus-circle me-2"></i>إضافة عسل
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
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
                        <div class="text-muted small">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $stats['total_suppliers']; ?></div>
                    </div>
                    <div class="text-primary">
                        <i class="bi bi-people fs-1"></i>
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
                        <div class="text-muted small">إجمالي العسل الخام</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_raw_honey'], 2); ?> كجم</div>
                    </div>
                    <div class="text-warning">
                        <i class="bi bi-droplet-half fs-1"></i>
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
                        <div class="text-muted small">إجمالي العسل المصفى</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_filtered_honey'], 2); ?> كجم</div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-droplet fs-1"></i>
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
                        <div class="text-muted small">إجمالي العسل</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_honey'], 2); ?> كجم</div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-droplet-fill fs-1"></i>
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
            <input type="hidden" name="page" value="honey_warehouse">
            <div class="col-md-6">
                <label class="form-label">البحث</label>
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($filters['search']); ?>" 
                       placeholder="اسم المورد...">
            </div>
            <div class="col-md-4">
                <label class="form-label">المورد</label>
                <select class="form-select" name="supplier_id">
                    <option value="0">جميع الموردين</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedSupplierId = $filters['supplier_id'];
                    $supplierValid = isValidSelectValue($selectedSupplierId, $suppliers, 'id');
                    foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['id']; ?>" 
                                <?php echo $supplierValid && $selectedSupplierId == $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة مخزون العسل -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">مخزون العسل (<?php echo $totalCount; ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($honeyStock)): ?>
            <div class="text-center text-muted py-4">لا يوجد مخزون عسل</div>
        <?php else: ?>
            <!-- عرض الجدول على الشاشات الكبيرة -->
            <div class="table-responsive dashboard-table-wrapper d-none d-md-block">
                <table class="table dashboard-table align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th class="text-center" style="width: 50px;">#</th>
                            <th style="min-width: 160px;"><i class="bi bi-person me-1"></i>المورد</th>
                            <th class="text-center" style="min-width: 140px;"><i class="bi bi-tag me-1"></i>نوع العسل</th>
                            <th class="text-center" style="min-width: 160px;"><i class="bi bi-droplet me-1"></i>العسل المصفى (كجم)</th>
                            <th class="text-center" style="min-width: 170px;"><i class="bi bi-clock-history me-1"></i>آخر تحديث</th>
                            <th class="text-center" style="min-width: 130px;"><i class="bi bi-gear me-1"></i>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($honeyStock as $index => $stock): ?>
                            <?php
                                $varietyRaw = $stock['honey_variety'] ?? 'أخرى';
                                $varietyDisplay = formatHoneyVarietyWithCode($varietyRaw);
                                $varietyDisplayEscaped = htmlspecialchars($varietyDisplay, ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($stock['supplier_name'] ?? '-'); ?></strong>
                                    <?php if (!empty($stock['supplier_phone'])): ?>
                                        <br><small class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info">
                                        <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($varietyDisplay); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <strong class="text-success">
                                        <i class="bi bi-droplet me-1"></i><?php echo number_format($stock['filtered_honey_quantity'], 2); ?> <small class="text-muted">كجم</small>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <small><i class="bi bi-clock-history me-1"></i><?php echo formatDateTime($stock['updated_at'] ?? $stock['created_at']); ?></small>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-warning" 
                                                onclick="showFilterModal(<?php echo $stock['id']; ?>, <?php echo $stock['supplier_id']; ?>, '<?php echo htmlspecialchars($stock['supplier_name'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $varietyDisplayEscaped; ?>', <?php echo $stock['raw_honey_quantity']; ?>)"
                                                title="تصفية العسل"
                                                <?php echo $stock['raw_honey_quantity'] <= 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-funnel"></i>
                                        </button>
                                        <button class="btn btn-info" 
                                                onclick="viewFiltrationHistory(<?php echo $stock['supplier_id']; ?>)"
                                                title="سجل التصفية">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                        <button class="btn btn-secondary" 
                                                onclick="editStock(<?php echo $stock['id']; ?>, <?php echo $stock['supplier_id']; ?>, '<?php echo htmlspecialchars($stock['supplier_name']); ?>', <?php echo $stock['raw_honey_quantity']; ?>, <?php echo $stock['filtered_honey_quantity']; ?>)"
                                                title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- عرض Cards على الموبايل -->
            <div class="d-md-none">
                <?php foreach ($honeyStock as $index => $stock): ?>
                    <?php
                        $varietyRaw = $stock['honey_variety'] ?? 'أخرى';
                        $varietyDisplay = formatHoneyVarietyWithCode($varietyRaw);
                        $varietyDisplayEscaped = htmlspecialchars($varietyDisplay, ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($stock['supplier_name'] ?? '-'); ?></h6>
                                    <?php if (!empty($stock['supplier_phone'])): ?>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                    <?php endif; ?>
                                    <span class="badge bg-info mt-1">
                                        <?php echo htmlspecialchars($varietyDisplay); ?>
                                    </span>
                                </div>
                                <span class="badge bg-primary">#<?php echo $offset + $index + 1; ?></span>
                            </div>
                            
                            <div class="mb-2">
                                <small class="text-muted d-block">العسل المصفى:</small>
                                <strong class="text-success"><?php echo number_format($stock['filtered_honey_quantity'], 2); ?> كجم</strong>
                            </div>
                            
                            <div class="mb-2">
                                <small class="text-muted d-block">آخر تحديث:</small>
                                <strong><?php echo formatDateTime($stock['updated_at'] ?? $stock['created_at']); ?></strong>
                            </div>
                            
                            <div class="d-grid gap-2 d-flex mt-3">
                                <button class="btn btn-sm btn-warning flex-fill" 
                                        onclick="showFilterModal(<?php echo $stock['id']; ?>, <?php echo $stock['supplier_id']; ?>, '<?php echo htmlspecialchars($stock['supplier_name'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $varietyDisplayEscaped; ?>', <?php echo $stock['raw_honey_quantity']; ?>)"
                                        <?php echo $stock['raw_honey_quantity'] <= 0 ? 'disabled' : ''; ?>>
                                    <i class="bi bi-funnel"></i> تصفية
                                </button>
                                <button class="btn btn-sm btn-info flex-fill" 
                                        onclick="viewFiltrationHistory(<?php echo $stock['supplier_id']; ?>)">
                                    <i class="bi bi-clock-history"></i> سجل
                                </button>
                                <button class="btn btn-sm btn-secondary flex-fill" 
                                        onclick="editStock(<?php echo $stock['id']; ?>, <?php echo $stock['supplier_id']; ?>, '<?php echo htmlspecialchars($stock['supplier_name']); ?>', <?php echo $stock['raw_honey_quantity']; ?>, <?php echo $stock['filtered_honey_quantity']; ?>)">
                                    <i class="bi bi-pencil"></i> تعديل
                                </button>
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
                    <a class="page-link" href="?page=honey_warehouse&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=honey_warehouse&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=honey_warehouse&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=honey_warehouse&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=honey_warehouse&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal لإضافة عسل -->
<div class="modal fade" id="addHoneyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عسل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_honey">
                    <div class="mb-3">
                        <label class="form-label">المورد <span class="text-danger">*</span></label>
                        <select class="form-select" name="supplier_id" required>
                            <option value="">اختر المورد</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صنف العسل <span class="text-danger">*</span></label>
                        <select class="form-select honey-variety-select" name="honey_variety" required>
                            <?php foreach ($honeyVarietiesCatalog as $honeyVariety => $meta): ?>
                                <option value="<?php echo htmlspecialchars($honeyVariety); ?>" data-code="<?php echo htmlspecialchars($meta['code']); ?>" <?php echo $honeyVariety === $defaultHoneyVariety ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(sprintf('%s - %s', $meta['label'], $meta['code'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع العسل</label>
                        <select class="form-select" name="honey_type" required>
                            <option value="raw">عسل خام</option>
                            <option value="filtered">عسل مصفى</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية (كجم)</label>
                        <input type="number" class="form-control" name="quantity" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal لتصفية العسل -->
<div class="modal fade" id="filterHoneyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تصفية العسل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="filter_honey">
                    <input type="hidden" name="stock_id" id="filter_stock_id">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>ملاحظة:</strong> سيتم خصم 0.5% من كمية العسل الخام كخسارة في عملية التصفية.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المورد</label>
                        <input type="text" class="form-control" id="filter_supplier_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صنف العسل</label>
                        <input type="text" class="form-control" id="filter_honey_variety" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية المتاحة (كجم)</label>
                        <input type="text" class="form-control" id="filter_available_quantity" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كمية العسل الخام المراد تصفيته (كجم)</label>
                        <input type="number" class="form-control" name="raw_quantity" id="filter_raw_quantity" step="0.01" min="0.01" required>
                        <small class="text-muted">الكمية بعد التصفية: <span id="filter_result_quantity">0.00</span> كجم</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاريخ التصفية</label>
                        <input type="date" class="form-control" name="filtration_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">تصفية</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal لتعديل المخزون -->
<div class="modal fade" id="editStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل المخزون</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="stock_id" id="edit_stock_id">
                    <div class="mb-3">
                        <label class="form-label">المورد</label>
                        <input type="text" class="form-control" id="edit_supplier_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كمية العسل الخام (كجم)</label>
                        <input type="number" class="form-control" name="raw_quantity" id="edit_raw_quantity" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">كمية العسل المصفى (كجم)</label>
                        <input type="number" class="form-control" name="filtered_quantity" id="edit_filtered_quantity" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal لعرض سجل التصفية -->
<div class="modal fade" id="filtrationHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">سجل عمليات التصفية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="filtrationHistoryContent">
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
function showFilterModal(stockId, supplierId, supplierName, honeyVariety, availableQuantity) {
    document.getElementById('filter_stock_id').value = stockId;
    document.getElementById('filter_supplier_name').value = supplierName;
    document.getElementById('filter_honey_variety').value = honeyVariety;
    document.getElementById('filter_available_quantity').value = parseFloat(availableQuantity).toFixed(2);
    document.getElementById('filter_raw_quantity').value = '';
    document.getElementById('filter_raw_quantity').max = availableQuantity;
    document.getElementById('filter_result_quantity').textContent = '0.00';
    
    const modal = new bootstrap.Modal(document.getElementById('filterHoneyModal'));
    modal.show();
}

document.getElementById('filter_raw_quantity')?.addEventListener('input', function() {
    const rawQuantity = parseFloat(this.value) || 0;
    const filteredQuantity = rawQuantity * 0.995; // خصم 0.5%
    document.getElementById('filter_result_quantity').textContent = filteredQuantity.toFixed(2);
});

function editStock(stockId, supplierId, supplierName, rawQuantity, filteredQuantity) {
    document.getElementById('edit_stock_id').value = stockId;
    document.getElementById('edit_supplier_name').value = supplierName;
    document.getElementById('edit_raw_quantity').value = parseFloat(rawQuantity).toFixed(2);
    document.getElementById('edit_filtered_quantity').value = parseFloat(filteredQuantity).toFixed(2);
    
    const modal = new bootstrap.Modal(document.getElementById('editStockModal'));
    modal.show();
}

function viewFiltrationHistory(supplierId) {
    const modal = new bootstrap.Modal(document.getElementById('filtrationHistoryModal'));
    const content = document.getElementById('filtrationHistoryContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    modal.show();
    
    // حساب المسار بشكل صحيح
    const currentUrl = new URL(window.location.href);
    const basePath = currentUrl.pathname;
    const apiUrl = basePath + '?page=honey_warehouse&ajax=1&supplier_id=' + supplierId;
    
    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        cache: 'no-cache'
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.text();
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    let html = '<div class="table-responsive dashboard-table-wrapper"><table class="table dashboard-table dashboard-table--compact align-middle"><thead class="table-light"><tr><th>التاريخ</th><th>الكمية الخام</th><th>الكمية المصفاة</th><th>الخسارة</th><th>ملاحظات</th></tr></thead><tbody>';
                    if (data.history && data.history.length > 0) {
                        data.history.forEach(item => {
                            html += `<tr>
                                <td>${item.filtration_date || '-'}</td>
                                <td><strong class="text-warning">${parseFloat(item.raw_honey_quantity || 0).toFixed(2)} كجم</strong></td>
                                <td><strong class="text-success">${parseFloat(item.filtered_honey_quantity || 0).toFixed(2)} كجم</strong></td>
                                <td><strong class="text-danger">${parseFloat(item.filtration_loss || 0).toFixed(2)} كجم</strong></td>
                                <td>${item.notes || '-'}</td>
                            </tr>`;
                        });
                    } else {
                        html += '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-inbox me-2"></i>لا توجد عمليات تصفية مسجلة</td></tr>';
                    }
                    html += '</tbody></table></div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + (data.message || 'حدث خطأ في تحميل البيانات') + '</div>';
                }
            } catch (e) {
                console.error('JSON Parse Error:', e);
                console.error('Response Text:', text);
                throw new Error('خطأ في قراءة البيانات من الخادم');
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            content.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>حدث خطأ في تحميل البيانات<br><small class="text-muted">' + error.message + '</small><br><small>يرجى التحقق من الاتصال بالإنترنت والمحاولة مرة أخرى.</small></div>';
        });
}
</script>

<?php
// معالجة AJAX لعرض سجل التصفية
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['supplier_id'])) {
    // تنظيف أي output قبل إرسال JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $supplierId = intval($_GET['supplier_id']);
        
        if ($supplierId <= 0) {
            throw new Exception('معرف المورد غير صحيح');
        }
        
        // التحقق من وجود الجدول
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_filtration'");
        if (empty($tableCheck)) {
            echo json_encode([
                'success' => true,
                'history' => [],
                'message' => 'لا يوجد سجل عمليات تصفية'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        
        $history = $db->query(
            "SELECT hf.*, u.full_name as created_by_name
             FROM honey_filtration hf
             LEFT JOIN users u ON hf.created_by = u.id
             WHERE hf.supplier_id = ?
             ORDER BY hf.filtration_date DESC, hf.created_at DESC
             LIMIT 50",
            [$supplierId]
        );
        
        echo json_encode([
            'success' => true,
            'history' => $history ?: []
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
    } catch (Exception $e) {
        error_log("Filtration History Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage(),
            'history' => []
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}
?>

