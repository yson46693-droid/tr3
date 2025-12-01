<?php
/**
 * صفحة مخزن توالف المصنع
 * Factory Waste Warehouse Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/product_name_helper.php';

requireRole(['manager', 'accountant', 'production']);

$currentUser = getCurrentUser();
$db = db();
$basePath = getBasePath();

$userRole = $currentUser['role'];
$canViewFinancials = in_array($userRole, ['manager', 'accountant']);
$isManager = ($userRole === 'manager'); // السماح للمدير فقط بالحذف والتعديل

// إنشاء الجداول إذا لم تكن موجودة
try {
    // جدول المنتجات التالفة
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'factory_waste_products'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `factory_waste_products` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `damaged_return_id` INT(11) DEFAULT NULL,
              `product_id` INT(11) NOT NULL,
              `product_name` VARCHAR(255) NOT NULL,
              `product_code` VARCHAR(100) DEFAULT NULL,
              `batch_number` VARCHAR(100) DEFAULT NULL,
              `batch_number_id` INT(11) DEFAULT NULL,
              `damaged_quantity` DECIMAL(10,2) NOT NULL,
              `waste_value` DECIMAL(10,2) DEFAULT 0.00,
              `source` VARCHAR(100) DEFAULT 'damaged_returns',
              `transaction_number` VARCHAR(100) DEFAULT NULL,
              `added_date` DATE NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_product_id` (`product_id`),
              KEY `idx_batch_number_id` (`batch_number_id`),
              KEY `idx_damaged_return_id` (`damaged_return_id`),
              KEY `idx_added_date` (`added_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // جدول أدوات التعبئة التالفة
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'factory_waste_packaging'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `factory_waste_packaging` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `packaging_damage_log_id` INT(11) DEFAULT NULL,
              `tool_type` VARCHAR(255) NOT NULL,
              `damaged_quantity` DECIMAL(10,2) NOT NULL,
              `unit` VARCHAR(50) DEFAULT NULL,
              `added_date` DATE NOT NULL,
              `recorded_by_user_id` INT(11) DEFAULT NULL,
              `recorded_by_user_name` VARCHAR(255) DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_packaging_damage_log_id` (`packaging_damage_log_id`),
              KEY `idx_recorded_by_user_id` (`recorded_by_user_id`),
              KEY `idx_added_date` (`added_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // جدول الخامات المهدرة
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'factory_waste_raw_materials'");
    if (empty($tableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `factory_waste_raw_materials` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `raw_material_damage_log_id` INT(11) DEFAULT NULL,
              `material_name` VARCHAR(255) NOT NULL,
              `wasted_quantity` DECIMAL(10,2) NOT NULL,
              `unit` VARCHAR(50) DEFAULT NULL,
              `waste_reason` TEXT DEFAULT NULL,
              `added_date` DATE NOT NULL,
              `recorded_by_user_id` INT(11) DEFAULT NULL,
              `recorded_by_user_name` VARCHAR(255) DEFAULT NULL,
              `waste_value` DECIMAL(10,2) DEFAULT 0.00,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_raw_material_damage_log_id` (`raw_material_damage_log_id`),
              KEY `idx_recorded_by_user_id` (`recorded_by_user_id`),
              KEY `idx_added_date` (`added_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Throwable $e) {
    error_log("Error creating factory waste tables: " . $e->getMessage());
}

// تحديد التبويب النشط
$activeTab = $_GET['tab'] ?? 'products';

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Filters
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// حساب إجمالي قيمة المنتجات التالفة (للمدير والمحاسب فقط)
// يشمل factory_waste_products و damaged_returns
$totalDamagedProductsValue = 0;
if ($canViewFinancials) {
    $valueResult = $db->queryOne(
        "SELECT COALESCE(SUM(waste_value), 0) as total FROM factory_waste_products"
    );
    $totalDamagedProductsValue = (float)($valueResult['total'] ?? 0);
    
    // إضافة قيمة المرتجعات التالفة
    $damagedReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'damaged_returns'");
    if (!empty($damagedReturnsTableExists)) {
        try {
            $damagedReturnsValue = $db->queryOne(
                "SELECT COALESCE(SUM(ri.total_price), 0) as total
                 FROM damaged_returns dr
                 INNER JOIN return_items ri ON dr.return_item_id = ri.id"
            );
            $totalDamagedProductsValue += (float)($damagedReturnsValue['total'] ?? 0);
        } catch (Throwable $e) {
            error_log("Error calculating damaged returns value: " . $e->getMessage());
        }
    }
}

// جلب البيانات حسب التبويب
$data = [];
$totalCount = 0;
$totalPages = 0;

if ($activeTab === 'products') {
    // جدول المنتجات التالفة - دمج البيانات من factory_waste_products و damaged_returns
    $sql = "SELECT 
            fwp.id,
            fwp.product_id,
            fwp.product_name,
            fwp.product_code,
            fwp.batch_number,
            fwp.damaged_quantity,
            fwp.waste_value,
            fwp.source,
            fwp.transaction_number,
            fwp.added_date,
            'factory_waste' as data_source
        FROM factory_waste_products fwp
        WHERE 1=1";
    
    $params = [];
    
    if ($dateFrom) {
        $sql .= " AND fwp.added_date >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND fwp.added_date <= ?";
        $params[] = $dateTo;
    }
    
    if ($search) {
        $sql .= " AND (fwp.product_name LIKE ? OR fwp.batch_number LIKE ? OR fwp.product_code LIKE ? OR fwp.transaction_number LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    // إضافة UNION مع damaged_returns
    $sql .= " UNION ALL
        SELECT 
            dr.id,
            dr.product_id,
            COALESCE(p.name, CONCAT('منتج رقم ', dr.product_id)) as product_name,
            NULL as product_code,
            COALESCE(bn.batch_number, '') as batch_number,
            dr.quantity as damaged_quantity,
            COALESCE(ri.total_price, 0) as waste_value,
            'damaged_returns' as source,
            r.return_number as transaction_number,
            DATE(r.return_date) as added_date,
            'damaged_returns' as data_source
        FROM damaged_returns dr
        INNER JOIN returns r ON dr.return_id = r.id
        LEFT JOIN return_items ri ON dr.return_item_id = ri.id
        LEFT JOIN products p ON dr.product_id = p.id
        LEFT JOIN batch_numbers bn ON dr.batch_number_id = bn.id
        WHERE 1=1";
    
    if ($dateFrom) {
        $sql .= " AND DATE(r.return_date) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND DATE(r.return_date) <= ?";
        $params[] = $dateTo;
    }
    
    if ($search) {
        $sql .= " AND (p.name LIKE ? OR bn.batch_number LIKE ? OR r.return_number LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY added_date DESC, id DESC
              LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $data = $db->query($sql, $params);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM (
        SELECT fwp.id
        FROM factory_waste_products fwp
        WHERE 1=1";
    $countParams = [];
    
    if ($dateFrom) {
        $countSql .= " AND fwp.added_date >= ?";
        $countParams[] = $dateFrom;
    }
    
    if ($dateTo) {
        $countSql .= " AND fwp.added_date <= ?";
        $countParams[] = $dateTo;
    }
    
    if ($search) {
        $countSql .= " AND (fwp.product_name LIKE ? OR fwp.batch_number LIKE ? OR fwp.product_code LIKE ? OR fwp.transaction_number LIKE ?)";
        $searchParam = "%{$search}%";
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
    
    $countSql .= " UNION ALL
        SELECT dr.id
        FROM damaged_returns dr
        INNER JOIN returns r ON dr.return_id = r.id
        LEFT JOIN products p ON dr.product_id = p.id
        LEFT JOIN batch_numbers bn ON dr.batch_number_id = bn.id
        WHERE 1=1";
    
    if ($dateFrom) {
        $countSql .= " AND DATE(r.return_date) >= ?";
        $countParams[] = $dateFrom;
    }
    
    if ($dateTo) {
        $countSql .= " AND DATE(r.return_date) <= ?";
        $countParams[] = $dateTo;
    }
    
    if ($search) {
        $countSql .= " AND (p.name LIKE ? OR bn.batch_number LIKE ? OR r.return_number LIKE ?)";
        $searchParam = "%{$search}%";
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
    
    $countSql .= ") as combined_data";
    
    $totalResult = $db->queryOne($countSql, $countParams);
    $totalCount = (int)($totalResult['total'] ?? 0);
    
} elseif ($activeTab === 'packaging') {
    // جدول أدوات التعبئة التالفة
    $sql = "SELECT 
            fwp.id,
            fwp.tool_type,
            fwp.damaged_quantity,
            fwp.unit,
            fwp.added_date,
            fwp.recorded_by_user_name
        FROM factory_waste_packaging fwp
        WHERE 1=1";
    
    $params = [];
    
    if ($dateFrom) {
        $sql .= " AND fwp.added_date >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND fwp.added_date <= ?";
        $params[] = $dateTo;
    }
    
    if ($search) {
        $sql .= " AND (fwp.tool_type LIKE ? OR fwp.recorded_by_user_name LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY fwp.added_date DESC, fwp.created_at DESC
              LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $data = $db->query($sql, $params);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total
                 FROM factory_waste_packaging fwp
                 WHERE 1=1";
    $countParams = [];
    
    if ($dateFrom) {
        $countSql .= " AND fwp.added_date >= ?";
        $countParams[] = $dateFrom;
    }
    
    if ($dateTo) {
        $countSql .= " AND fwp.added_date <= ?";
        $countParams[] = $dateTo;
    }
    
    if ($search) {
        $countSql .= " AND (fwp.tool_type LIKE ? OR fwp.recorded_by_user_name LIKE ?)";
        $searchParam = "%{$search}%";
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
    
    $totalResult = $db->queryOne($countSql, $countParams);
    $totalCount = (int)($totalResult['total'] ?? 0);
    
} elseif ($activeTab === 'raw_materials') {
    // جدول الخامات المهدرة
    $sql = "SELECT 
            fwrm.id,
            fwrm.material_name,
            fwrm.wasted_quantity,
            fwrm.unit,
            fwrm.waste_reason,
            fwrm.added_date,
            fwrm.recorded_by_user_name,
            fwrm.waste_value
        FROM factory_waste_raw_materials fwrm
        WHERE 1=1";
    
    $params = [];
    
    if ($dateFrom) {
        $sql .= " AND fwrm.added_date >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND fwrm.added_date <= ?";
        $params[] = $dateTo;
    }
    
    if ($search) {
        $sql .= " AND (fwrm.material_name LIKE ? OR fwrm.recorded_by_user_name LIKE ? OR fwrm.waste_reason LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY fwrm.added_date DESC, fwrm.created_at DESC
              LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $data = $db->query($sql, $params);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total
                 FROM factory_waste_raw_materials fwrm
                 WHERE 1=1";
    $countParams = [];
    
    if ($dateFrom) {
        $countSql .= " AND fwrm.added_date >= ?";
        $countParams[] = $dateFrom;
    }
    
    if ($dateTo) {
        $countSql .= " AND fwrm.added_date <= ?";
        $countParams[] = $dateTo;
    }
    
    if ($search) {
        $countSql .= " AND (fwrm.material_name LIKE ? OR fwrm.recorded_by_user_name LIKE ? OR fwrm.waste_reason LIKE ?)";
        $searchParam = "%{$search}%";
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
    
    $totalResult = $db->queryOne($countSql, $countParams);
    $totalCount = (int)($totalResult['total'] ?? 0);
}

$totalPages = ceil($totalCount / $perPage);
?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12">
            <h3 class="mb-3">
                <i class="bi bi-trash me-2 text-danger"></i>مخزن توالف المصنع
            </h3>
        </div>
    </div>

    <?php if ($canViewFinancials): ?>
        <!-- إحصائية للمدير والمحاسب -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-0">
                                    <i class="bi bi-calculator me-2"></i>
                                    إجمالي قيمة المنتجات التالفة
                                </h5>
                            </div>
                            <div class="col-md-4 text-end">
                                <h4 class="mb-0">
                                    <?php echo number_format($totalDamagedProductsValue, 2); ?> جنيه
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <style>
    /* تحسين حقول الفلترة على الهواتف */
    @media (max-width: 767.98px) {
        .filter-form .row {
            margin-bottom: 0;
        }
        
        .filter-form .form-label {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
            font-weight: 500;
        }
        
        .filter-form .form-control {
            font-size: 0.85rem;
            padding: 0.4rem 0.5rem;
            height: auto;
            min-height: 38px;
        }
        
        .filter-form .btn {
            font-size: 0.85rem;
            padding: 0.4rem 0.75rem;
            min-height: 38px;
        }
        
        /* تقليل المسافات بين الحقول */
        .filter-form .row.g-2 {
            --bs-gutter-y: 0.5rem;
        }
    }
    </style>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="row g-2 g-md-3 filter-form">
                        <input type="hidden" name="page" value="factory_waste_warehouse">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                        
                        <div class="col-6 col-md-3">
                            <label class="form-label small">من تاريخ</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-label small">إلى تاريخ</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small">بحث</label>
                            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="ابحث...">
                        </div>

                        <div class="col-12 col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search"></i> بحث
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <style>
    /* تحسين التبويبات على الهواتف */
    @media (max-width: 767.98px) {
        .nav-tabs {
            display: flex !important;
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 1rem;
        }
        
        .nav-tabs::-webkit-scrollbar {
            height: 4px;
        }
        
        .nav-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .nav-tabs::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 2px;
        }
        
        .nav-tabs .nav-item {
            flex: 0 0 auto;
            min-width: auto;
            white-space: nowrap;
        }
        
        .nav-tabs .nav-link {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
            border-radius: 0.375rem 0.375rem 0 0;
            margin-right: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .nav-tabs .nav-link i {
            font-size: 0.9rem;
        }
        
        /* تقليل حجم النص على الشاشات الصغيرة جداً */
        @media (max-width: 400px) {
            .nav-tabs .nav-link {
                padding: 0.4rem 0.6rem;
                font-size: 0.75rem;
            }
            
            .nav-tabs .nav-link i {
                font-size: 0.8rem;
            }
        }
    }
    
    /* تحسين التبويبات على الشاشات المتوسطة */
    @media (min-width: 768px) and (max-width: 991.98px) {
        .nav-tabs .nav-link {
            padding: 0.6rem 0.9rem;
            font-size: 0.9rem;
        }
    }
    </style>
    <div class="tabs-wrapper">
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'products' ? 'active' : ''; ?>" 
                   href="?page=factory_waste_warehouse&tab=products<?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                    <i class="bi bi-box-seam me-1"></i>المنتجات التالفة
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'packaging' ? 'active' : ''; ?>" 
                   href="?page=factory_waste_warehouse&tab=packaging<?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                    <i class="bi bi-box me-1"></i>أدوات التعبئة التالفة
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'raw_materials' ? 'active' : ''; ?>" 
                   href="?page=factory_waste_warehouse&tab=raw_materials<?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                    <i class="bi bi-flower1 me-1"></i>الخامات المهدرة
                </a>
            </li>
        </ul>
    </div>

    <!-- Tab Content -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <?php if (empty($data)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد بيانات
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <?php if ($activeTab === 'products'): ?>
                                <!-- جدول المنتجات التالفة -->
                                <table class="table table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>اسم المنتج</th>
                                            <th>الكود</th>
                                            <th>رقم التشغيلة</th>
                                            <th>الكمية التالفة</th>
                                            <th>تاريخ الإضافة</th>
                                            <th>مصدر التوالف</th>
                                            <th>رقم العملية</th>
                                            <?php if ($canViewFinancials): ?>
                                                <th>قيمة التوالف</th>
                                            <?php endif; ?>
                                            <?php if ($isManager): ?>
                                                <th>إجراءات</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data as $item): ?>
                                            <tr>
                                                <td><strong><?php 
                                                    $productName = $item['product_name'] ?? '';
                                                    if (empty($productName) && !empty($item['product_id'])) {
                                                        $productName = 'منتج رقم ' . $item['product_id'];
                                                    }
                                                    echo htmlspecialchars($productName ?: '-');
                                                ?></strong></td>
                                                <td><?php echo htmlspecialchars($item['product_code'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($item['batch_number'] ?? '-'); ?></td>
                                                <td><span class="badge bg-danger"><?php echo number_format((float)$item['damaged_quantity'], 2); ?></span></td>
                                                <td><?php echo htmlspecialchars($item['added_date'] ?? '-'); ?></td>
                                                <td>
                                                    <?php 
                                                    $source = $item['source'] ?? ($item['data_source'] ?? '');
                                                    if ($source === 'damaged_returns' || $item['data_source'] === 'damaged_returns') {
                                                        echo '<span class="badge bg-danger">المرتجعات التالفة</span>';
                                                    } else {
                                                        echo htmlspecialchars($source ?: '-');
                                                    }
                                                    ?>
                                                </td>
                                                <td><strong class="text-primary"><?php echo htmlspecialchars($item['transaction_number'] ?? '-'); ?></strong></td>
                                                <?php if ($canViewFinancials): ?>
                                                    <td><?php echo number_format((float)$item['waste_value'], 2); ?> جنيه</td>
                                                <?php endif; ?>
                                                <?php if ($isManager): ?>
                                                    <td>
                                                        <?php 
                                                        // السماح بالتعديل والحذف فقط للسجلات من factory_waste_products (وليس damaged_returns)
                                                        if (($item['data_source'] ?? '') === 'factory_waste'): 
                                                        ?>
                                                            <div class="btn-group btn-group-sm" role="group">
                                                                <button type="button" class="btn btn-warning btn-sm" 
                                                                        onclick="editProduct(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES); ?>)" 
                                                                        title="تعديل">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-danger btn-sm" 
                                                                        onclick="deleteProduct(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($productName), ENT_QUOTES); ?>')" 
                                                                        title="حذف">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted small">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                            <?php elseif ($activeTab === 'packaging'): ?>
                                <!-- جدول أدوات التعبئة التالفة -->
                                <table class="table table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>نوع الأداة</th>
                                            <th>الكمية التالفة</th>
                                            <th>تاريخ الإضافة</th>
                                            <th>المستخدم</th>
                                            <?php if ($isManager): ?>
                                                <th>إجراءات</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data as $item): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($item['tool_type']); ?></strong></td>
                                                <td>
                                                    <span class="badge bg-danger">
                                                        <?php echo number_format((float)$item['damaged_quantity'], 2); ?>
                                                        <?php echo htmlspecialchars($item['unit'] ?? 'وحدة'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['added_date'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($item['recorded_by_user_name'] ?? '-'); ?></td>
                                                <?php if ($isManager): ?>
                                                    <td>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-warning btn-sm" 
                                                                    onclick="editPackaging(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES); ?>)" 
                                                                    title="تعديل">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm" 
                                                                    onclick="deletePackaging(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['tool_type']), ENT_QUOTES); ?>')" 
                                                                    title="حذف">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                            <?php elseif ($activeTab === 'raw_materials'): ?>
                                <!-- جدول الخامات المهدرة -->
                                <table class="table table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>اسم الخامة</th>
                                            <th>الكمية المهدرة</th>
                                            <th>تاريخ الإضافة</th>
                                            <th>سبب الهدر</th>
                                            <th>المستخدم</th>
                                            <?php if ($canViewFinancials): ?>
                                                <th>قيمة الهدر</th>
                                            <?php endif; ?>
                                            <?php if ($isManager): ?>
                                                <th>إجراءات</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data as $item): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($item['material_name']); ?></strong></td>
                                                <td>
                                                    <span class="badge bg-warning text-dark">
                                                        <?php echo number_format((float)$item['wasted_quantity'], 2); ?>
                                                        <?php echo htmlspecialchars($item['unit'] ?? 'كجم'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['added_date'] ?? '-'); ?></td>
                                                <td>
                                                    <?php if ($item['waste_reason']): ?>
                                                        <span class="text-muted" title="<?php echo htmlspecialchars($item['waste_reason']); ?>">
                                                            <?php echo mb_strlen($item['waste_reason']) > 50 ? mb_substr($item['waste_reason'], 0, 50) . '...' : htmlspecialchars($item['waste_reason']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['recorded_by_user_name'] ?? '-'); ?></td>
                                                <?php if ($canViewFinancials): ?>
                                                    <td><?php echo number_format((float)($item['waste_value'] ?? 0), 2); ?> جنيه</td>
                                                <?php endif; ?>
                                                <?php if ($isManager): ?>
                                                    <td>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-warning btn-sm" 
                                                                    onclick="editRawMaterial(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES); ?>)" 
                                                                    title="تعديل">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm" 
                                                                    onclick="deleteRawMaterial(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['material_name']), ENT_QUOTES); ?>')" 
                                                                    title="حذف">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=factory_waste_warehouse&tab=<?php echo $activeTab; ?>&p=<?php echo $pageNum - 1; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $pageNum - 2);
                                    $endPage = min($totalPages, $pageNum + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++):
                                    ?>
                                        <li class="page-item <?php echo $i === $pageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=factory_waste_warehouse&tab=<?php echo $activeTab; ?>&p=<?php echo $i; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=factory_waste_warehouse&tab=<?php echo $activeTab; ?>&p=<?php echo $pageNum + 1; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isManager): ?>
<!-- Modal تعديل منتج تالف -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل منتج تالف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editProductForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_product_id" name="id">
                    <div class="mb-3">
                        <label class="form-label">الكمية التالفة</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="edit_product_quantity" name="damaged_quantity" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاريخ الإضافة</label>
                        <input type="date" class="form-control" id="edit_product_date" name="added_date" required>
                    </div>
                    <?php if ($canViewFinancials): ?>
                    <div class="mb-3">
                        <label class="form-label">قيمة التوالف</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="edit_product_value" name="waste_value">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal حذف منتج تالف -->
<div class="modal fade" id="deleteProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">حذف منتج تالف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>هل أنت متأكد من حذف هذا المنتج التالف؟</p>
                <p class="text-danger"><strong id="delete_product_name"></strong></p>
                <p class="text-muted small">هذه العملية لا يمكن التراجع عنها.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteProduct">حذف</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تعديل أداة تعبئة تالفة -->
<div class="modal fade" id="editPackagingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل أداة تعبئة تالفة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPackagingForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_packaging_id" name="id">
                    <div class="mb-3">
                        <label class="form-label">الكمية التالفة</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="edit_packaging_quantity" name="damaged_quantity" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاريخ الإضافة</label>
                        <input type="date" class="form-control" id="edit_packaging_date" name="added_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal حذف أداة تعبئة تالفة -->
<div class="modal fade" id="deletePackagingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">حذف أداة تعبئة تالفة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>هل أنت متأكد من حذف هذه الأداة التالفة؟</p>
                <p class="text-danger"><strong id="delete_packaging_name"></strong></p>
                <p class="text-muted small">هذه العملية لا يمكن التراجع عنها.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeletePackaging">حذف</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تعديل خامة مهدرة -->
<div class="modal fade" id="editRawMaterialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل خامة مهدرة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editRawMaterialForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_raw_material_id" name="id">
                    <div class="mb-3">
                        <label class="form-label">الكمية المهدرة</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="edit_raw_material_quantity" name="wasted_quantity" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاريخ الإضافة</label>
                        <input type="date" class="form-control" id="edit_raw_material_date" name="added_date" required>
                    </div>
                    <?php if ($canViewFinancials): ?>
                    <div class="mb-3">
                        <label class="form-label">قيمة الهدر</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="edit_raw_material_value" name="waste_value">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal حذف خامة مهدرة -->
<div class="modal fade" id="deleteRawMaterialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">حذف خامة مهدرة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>هل أنت متأكد من حذف هذه الخامة المهدرة؟</p>
                <p class="text-danger"><strong id="delete_raw_material_name"></strong></p>
                <p class="text-muted small">هذه العملية لا يمكن التراجع عنها.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteRawMaterial">حذف</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentDeleteId = null;
let currentDeleteType = null;

// وظائف تعديل المنتجات التالفة
function editProduct(item) {
    document.getElementById('edit_product_id').value = item.id;
    document.getElementById('edit_product_quantity').value = item.damaged_quantity || '';
    document.getElementById('edit_product_date').value = item.added_date || '';
    <?php if ($canViewFinancials): ?>
    document.getElementById('edit_product_value').value = item.waste_value || '';
    <?php endif; ?>
    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

function deleteProduct(id, name) {
    currentDeleteId = id;
    currentDeleteType = 'product';
    document.getElementById('delete_product_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteProductModal')).show();
}

// وظائف تعديل أدوات التعبئة التالفة
function editPackaging(item) {
    document.getElementById('edit_packaging_id').value = item.id;
    document.getElementById('edit_packaging_quantity').value = item.damaged_quantity || '';
    document.getElementById('edit_packaging_date').value = item.added_date || '';
    new bootstrap.Modal(document.getElementById('editPackagingModal')).show();
}

function deletePackaging(id, name) {
    currentDeleteId = id;
    currentDeleteType = 'packaging';
    document.getElementById('delete_packaging_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deletePackagingModal')).show();
}

// وظائف تعديل الخامات المهدرة
function editRawMaterial(item) {
    document.getElementById('edit_raw_material_id').value = item.id;
    document.getElementById('edit_raw_material_quantity').value = item.wasted_quantity || '';
    document.getElementById('edit_raw_material_date').value = item.added_date || '';
    <?php if ($canViewFinancials): ?>
    document.getElementById('edit_raw_material_value').value = item.waste_value || '';
    <?php endif; ?>
    new bootstrap.Modal(document.getElementById('editRawMaterialModal')).show();
}

function deleteRawMaterial(id, name) {
    currentDeleteId = id;
    currentDeleteType = 'raw_material';
    document.getElementById('delete_raw_material_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteRawMaterialModal')).show();
}

// معالجة النماذج
document.getElementById('editProductForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'edit_product');
    formData.append('tab', '<?php echo $activeTab; ?>');
    
    fetch('<?php echo $basePath; ?>api/factory_waste.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // التحقق من نوع المحتوى قبل محاولة تحويله إلى JSON
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            // إذا لم يكن JSON، إرجاع رسالة خطأ
            throw new Error('استجابة غير صالحة من الخادم');
        }
    })
    .then(data => {
        if (data.success) {
            alert('تم التعديل بنجاح');
            location.reload();
        } else {
            alert('حدث خطأ: ' + (data.message || 'خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء التعديل: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
    });
});

document.getElementById('editPackagingForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'edit_packaging');
    formData.append('tab', '<?php echo $activeTab; ?>');
    
    fetch('<?php echo $basePath; ?>api/factory_waste.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // التحقق من نوع المحتوى قبل محاولة تحويله إلى JSON
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            // إذا لم يكن JSON، إرجاع رسالة خطأ
            throw new Error('استجابة غير صالحة من الخادم');
        }
    })
    .then(data => {
        if (data.success) {
            alert('تم التعديل بنجاح');
            location.reload();
        } else {
            alert('حدث خطأ: ' + (data.message || 'خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء التعديل: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
    });
});

document.getElementById('editRawMaterialForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'edit_raw_material');
    formData.append('tab', '<?php echo $activeTab; ?>');
    
    fetch('<?php echo $basePath; ?>api/factory_waste.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // التحقق من نوع المحتوى قبل محاولة تحويله إلى JSON
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            // إذا لم يكن JSON، إرجاع رسالة خطأ
            throw new Error('استجابة غير صالحة من الخادم');
        }
    })
    .then(data => {
        if (data.success) {
            alert('تم التعديل بنجاح');
            location.reload();
        } else {
            alert('حدث خطأ: ' + (data.message || 'خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء التعديل: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
    });
});

// معالجة الحذف
document.getElementById('confirmDeleteProduct')?.addEventListener('click', function() {
    if (currentDeleteId && currentDeleteType === 'product') {
        deleteItem(currentDeleteId, 'product');
    }
});

document.getElementById('confirmDeletePackaging')?.addEventListener('click', function() {
    if (currentDeleteId && currentDeleteType === 'packaging') {
        deleteItem(currentDeleteId, 'packaging');
    }
});

document.getElementById('confirmDeleteRawMaterial')?.addEventListener('click', function() {
    if (currentDeleteId && currentDeleteType === 'raw_material') {
        deleteItem(currentDeleteId, 'raw_material');
    }
});

function deleteItem(id, type) {
    const formData = new FormData();
    formData.append('action', 'delete_' + type);
    formData.append('id', id);
    formData.append('tab', '<?php echo $activeTab; ?>');
    
    fetch('<?php echo $basePath; ?>api/factory_waste.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // التحقق من نوع المحتوى قبل محاولة تحويله إلى JSON
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            // إذا لم يكن JSON، إرجاع رسالة خطأ
            throw new Error('استجابة غير صالحة من الخادم');
        }
    })
    .then(data => {
        if (data.success) {
            alert('تم الحذف بنجاح');
            location.reload();
        } else {
            alert('حدث خطأ: ' + (data.message || 'خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء الحذف: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
    });
}
</script>
<?php endif; ?>

