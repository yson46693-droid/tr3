<?php
/**
 * صفحة المنتجات النهائية - تعرض ما تم إنتاجه من خطوط الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

$currentPageSlug = $_GET['page'] ?? 'inventory';
$currentSection = $_GET['section'] ?? null;
$baseQueryString = '?page=' . urlencode($currentPageSlug);
if ($currentSection !== null && $currentSection !== '') {
    $baseQueryString .= '&section=' . urlencode($currentSection);
}

// التحقق من وجود عمود date أو production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

// التحقق من وجود عمود user_id أو worker_id
$userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
$workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
$hasUserIdColumn = !empty($userIdColumnCheck);
$hasWorkerIdColumn = !empty($workerIdColumnCheck);
$userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

// معالجة طلبات AJAX لتفاصيل المنتج قبل أي إخراج
if (isset($_GET['ajax']) && $_GET['ajax'] === 'product_details') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'معرف المنتج غير صالح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $whereDetails = ["p.product_id = ?", "(p.status = 'completed' OR p.status = 'approved')"];
        $detailsParams = [$productId];

        $detailsSql = "SELECT 
                            p.id,
                            p.quantity,
                            p.$dateColumn as production_date,
                            p.created_at,
                            p.status" .
                        ($userIdColumn
                            ? ", u.full_name as worker_name, u.username as worker_username"
                            : ", 'غير محدد' as worker_name, 'غير محدد' as worker_username"
                        ) .
                        " FROM production p";

        if ($userIdColumn) {
            $detailsSql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
        }

        $detailsSql .= " WHERE " . implode(' AND ', $whereDetails) . "
                         ORDER BY p.$dateColumn ASC";

        $details = $db->query($detailsSql, $detailsParams);

        $statusLabels = [
            'pending' => 'معلق',
            'approved' => 'موافق عليه',
            'completed' => 'مكتمل',
            'rejected' => 'مرفوض'
        ];

        $formattedDetails = array_map(function($detail) use ($statusLabels) {
            return [
                'id' => $detail['id'],
                'quantity' => $detail['quantity'],
                'date' => formatDate($detail['production_date'] ?? $detail['created_at']),
                'worker' => $detail['worker_name'] ?? $detail['worker_username'] ?? 'غير محدد',
                'status' => $detail['status'],
                'status_text' => $statusLabels[$detail['status']] ?? $detail['status']
            ];
        }, $details ?? []);

        echo json_encode([
            'success' => true,
            'details' => $formattedDetails
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('Final products AJAX error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء تحميل تفاصيل المنتج'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// التحقق من وجود جدول production_lines وإنشاءه إذا لم يكن موجوداً
$lineTableCheck = $db->queryOne("SHOW TABLES LIKE 'production_lines'");
if (empty($lineTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `production_lines` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `line_name` varchar(100) NOT NULL,
              `product_id` int(11) NOT NULL,
              `target_quantity` decimal(10,2) DEFAULT 0.00,
              `priority` enum('low','medium','high') DEFAULT 'medium',
              `start_date` date DEFAULT NULL,
              `end_date` date DEFAULT NULL,
              `worker_id` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `notes` text DEFAULT NULL,
              `status` enum('pending','active','completed','paused','cancelled') DEFAULT 'pending',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `product_id` (`product_id`),
              KEY `worker_id` (`worker_id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`),
              CONSTRAINT `production_lines_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
              CONSTRAINT `production_lines_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `production_lines_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating production_lines table: " . $e->getMessage());
    }
}

// التحقق من وجود عمود production_line_id في جدول production
$productionLineIdColumn = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_line_id'");
if (empty($productionLineIdColumn)) {
    try {
        $db->execute("ALTER TABLE `production` ADD COLUMN `production_line_id` int(11) DEFAULT NULL COMMENT 'خط الإنتاج'");
        $db->execute("ALTER TABLE `production` ADD KEY `production_line_id` (`production_line_id`)");
        $db->execute("ALTER TABLE `production` ADD CONSTRAINT `production_ibfk_line` FOREIGN KEY (`production_line_id`) REFERENCES `production_lines` (`id`) ON DELETE SET NULL");
    } catch (Exception $e) {
        error_log("Error adding production_line_id column: " . $e->getMessage());
    }
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = $_GET['search'] ?? '';
$productId = $_GET['product_id'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// بناء استعلام البحث
$whereConditions = ['1=1'];
$params = [];

// التحقق من وجود عمود date أو production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

if ($search) {
    $searchParam = '%' . $search . '%';
    if ($userIdColumn) {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    } else {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
}

if ($productId) {
    $whereConditions[] = "p.product_id = ?";
    $params[] = intval($productId);
}

if ($status) {
    $whereConditions[] = "p.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(p.$dateColumn) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(p.$dateColumn) <= ?";
    $params[] = $dateTo;
}

// فقط المنتجات المكتملة أو الموافق عليها
$whereConditions[] = "(p.status = 'completed' OR p.status = 'approved')";

$whereClause = implode(' AND ', $whereConditions);

// حساب العدد الإجمالي
$countSql = "SELECT COUNT(*) as total 
             FROM production p
             LEFT JOIN products pr ON p.product_id = pr.id";
             
// إضافة JOIN مع users فقط إذا كان العمود موجوداً
if ($userIdColumn) {
    $countSql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
}

$countSql .= " WHERE $whereClause";

$totalResult = $db->queryOne($countSql, $params);
$totalProducts = $totalResult['total'] ?? 0;
$totalPages = ceil($totalProducts / $perPage);

// الحصول على البيانات مع تجميع حسب المنتج وخط الإنتاج
$sql = "SELECT 
            p.id,
            p.product_id,
            pr.name as product_name,
            pr.category as product_category,
            pr.unit_price,
            pr.quantity as available_quantity,
            SUM(p.quantity) as total_produced,
            COUNT(DISTINCT p.id) as production_count,
            MIN(p.$dateColumn) as first_production_date,
            MAX(p.$dateColumn) as last_production_date,
            " . ($userIdColumn ? "GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') as workers," : "'غير محدد' as workers,") . "
            p.status as production_status
        FROM production p
        LEFT JOIN products pr ON p.product_id = pr.id";
        
// إضافة JOIN مع users فقط إذا كان العمود موجوداً
if ($userIdColumn) {
    $sql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
}

$sql .= " WHERE $whereClause
        GROUP BY p.product_id
        ORDER BY MIN(p.$dateColumn) ASC, pr.name ASC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$finalProducts = $db->query($sql, $params);

$totalAvailableSum = 0.0;
$totalProducedSum = 0.0;
$totalProductionCountSum = 0;
$totalEstimatedValue = 0.0;

if (is_array($finalProducts)) {
    foreach ($finalProducts as $product) {
        $availableQuantity = (float)($product['available_quantity'] ?? 0);
        $producedQuantity = (float)($product['total_produced'] ?? 0);
        $productOperations = (int)($product['production_count'] ?? 0);
        $productUnitPrice = isset($product['unit_price']) ? (float)$product['unit_price'] : 0.0;

        $totalAvailableSum += $availableQuantity;
        $totalProducedSum += $producedQuantity;
        $totalProductionCountSum += $productOperations;

        if ($productUnitPrice > 0) {
            $totalEstimatedValue += $availableQuantity * $productUnitPrice;
        }
    }
}

$statusLabels = [
    'pending' => 'معلّق',
    'approved' => 'موافق عليه',
    'completed' => 'مكتمل',
    'rejected' => 'مرفوض'
];

$productDetailsMap = [];
if (!empty($finalProducts)) {
    $productIds = array_column($finalProducts, 'product_id');
    $productIds = array_values(array_filter(array_map('intval', $productIds)));

    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $detailsSql = "SELECT 
                            p.product_id,
                            p.id,
                            p.quantity,
                            p.$dateColumn as production_date,
                            p.status,
                            p.production_line_id,
                            pl.line_name,
                            " . ($userIdColumn ? "u.full_name as worker_name, u.username as worker_username" : "NULL as worker_name, NULL as worker_username") . "
                       FROM production p
                       LEFT JOIN production_lines pl ON p.production_line_id = pl.id";

        if ($userIdColumn) {
            $detailsSql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
        }

        $detailsSql .= " WHERE p.product_id IN ($placeholders)
                         AND (p.status = 'completed' OR p.status = 'approved')
                         ORDER BY p.product_id, p.$dateColumn ASC";

        $detailsRows = $db->query($detailsSql, $productIds);

        foreach ($detailsRows as $detailRow) {
            $workerName = $detailRow['worker_name'] ?? $detailRow['worker_username'] ?? null;
            $productDetailsMap[$detailRow['product_id']][] = [
                'id' => (int)$detailRow['id'],
                'quantity' => (float)($detailRow['quantity'] ?? 0),
                'date' => $detailRow['production_date'] ?? null,
                'status' => $detailRow['status'] ?? 'completed',
                'worker' => $workerName ? trim($workerName) : 'غير محدد',
                'line_name' => $detailRow['line_name'] ?? null,
            ];
        }
    }
}

// الحصول على المنتجات وخطوط الإنتاج للفلترة
$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' ORDER BY name");
// حساب الإحصائيات
$statsSql = "SELECT 
                COUNT(DISTINCT p.product_id) as total_products,
                COUNT(DISTINCT p.id) as total_production_records
             FROM production p
             WHERE (p.status = 'completed' OR p.status = 'approved')";
$stats = $db->queryOne($statsSql);

if ($dateFrom || $dateTo) {
    $statsWhere = ["(p.status = 'completed' OR p.status = 'approved')"];
    if ($dateFrom) {
        $statsWhere[] = "DATE(p.$dateColumn) >= ?";
    }
    if ($dateTo) {
        $statsWhere[] = "DATE(p.$dateColumn) <= ?";
    }
    $statsParams = [];
    if ($dateFrom) $statsParams[] = $dateFrom;
    if ($dateTo) $statsParams[] = $dateTo;
    
    $statsSql = "SELECT 
                    COUNT(DISTINCT p.product_id) as total_products,
                    COUNT(DISTINCT p.id) as total_production_records
                 FROM production p
                 WHERE " . implode(' AND ', $statsWhere);
    $stats = $db->queryOne($statsSql, $statsParams);
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap">
    <h2 class="mb-0"><i class="bi bi-boxes me-2"></i>المنتجات النهائية</h2>
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

<!-- إحصائيات سريعة -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="stat-card-icon blue">
                            <i class="bi bi-box-seam"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">عدد المنتجات</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_products'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="stat-card-icon purple">
                            <i class="bi bi-list-check"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">سجلات الإنتاج</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_production_records'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- الفلاتر -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-2">
            <input type="hidden" name="page" value="<?php echo htmlspecialchars($currentPageSlug); ?>">
            <?php if ($currentSection !== null && $currentSection !== ''): ?>
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($currentSection); ?>">
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">بحث</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="بحث...">
            </div>
            <div class="col-md-2">
                <label class="form-label">المنتج</label>
                <select name="product_id" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>" <?php echo $productId == $product['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($product['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">من تاريخ</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-2 col-lg-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i>بحث
                </button>
            </div>
        </form>
    </div>
</div>

<!-- جدول المنتجات النهائية -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>المنتجات النهائية (<?php echo $totalProducts; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive-lg">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:200px;">المنتج</th>
                        <th style="min-width:200px;">ملخص الإنتاج</th>
                        <th style="min-width:180px;">الفترة الزمنية</th>
                        <th style="min-width:180px;">العمال المشاركون</th>
                        <th style="min-width:280px;">سجلات الإنتاج التفصيلية</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($finalProducts)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                لا توجد منتجات نهائية
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($finalProducts as $product): ?>
                            <?php
                            $productId = (int)($product['product_id'] ?? 0);
                            $availableQty = (float)($product['available_quantity'] ?? 0);
                            $producedQty = (float)($product['total_produced'] ?? 0);
                            $unitPrice = isset($product['unit_price']) ? (float)$product['unit_price'] : 0.0;
                            $productionCount = (int)($product['production_count'] ?? 0);
                            $estimatedValue = $unitPrice > 0 ? $unitPrice * $availableQty : 0.0;
                            $details = $productDetailsMap[$productId] ?? [];
                            $workersRaw = $product['workers'] ?? '';
                            $workersList = array_filter(array_map('trim', explode(',', (string)$workersRaw)));
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($product['product_name'] ?? 'غير محدد'); ?></div>
                                    <?php if (!empty($product['product_category'])): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($product['product_category']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-muted small mt-1">
                                        <i class="bi bi-hash me-1"></i>معرّف المنتج: <?php echo $productId; ?>
                                    </div>
                                    <?php if ($unitPrice > 0): ?>
                                        <div class="text-muted small">
                                            <i class="bi bi-cash-stack me-1"></i>سعر الوحدة: <?php echo formatCurrency($unitPrice); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><strong>المتوفر حالياً: <?php echo number_format($availableQty, 2); ?></strong></div>
                                    <div class="text-muted small">
                                        <i class="bi bi-box-seam me-1"></i>إجمالي الإنتاج: <?php echo number_format($producedQty, 2); ?>
                                    </div>
                                    <div class="text-muted small">
                                        <i class="bi bi-recycle me-1"></i>عدد العمليات: <?php echo number_format($productionCount); ?>
                                    </div>
                                    <?php if ($productionCount > 0): ?>
                                        <div class="text-muted small">
                                            <i class="bi bi-calculator me-1"></i>متوسط إنتاج العملية: <?php echo number_format($producedQty / $productionCount, 2); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($estimatedValue > 0): ?>
                                        <div class="text-success small fw-semibold mt-1">
                                            <i class="bi bi-wallet2 me-1"></i>القيمة التقديرية: <?php echo formatCurrency($estimatedValue); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <i class="bi bi-calendar-week me-1"></i>أول إنتاج:
                                        <strong><?php echo $product['first_production_date'] ? formatDate($product['first_production_date']) : '—'; ?></strong>
                                    </div>
                                    <div class="mt-1">
                                        <i class="bi bi-calendar-check me-1"></i>آخر إنتاج:
                                        <strong><?php echo $product['last_production_date'] ? formatDate($product['last_production_date']) : '—'; ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($workersList) && trim($workersRaw) !== 'غير محدد'): ?>
                                        <ul class="mb-0 ps-3 text-muted small">
                                            <?php foreach ($workersList as $workerName): ?>
                                                <li><i class="bi bi-person-workspace me-1"></i><?php echo htmlspecialchars($workerName); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-muted small">غير محدد</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($details)): ?>
                                        <span class="text-muted small">لا توجد سجلات إنتاج مفصلة</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-column gap-2">
                                            <?php foreach ($details as $detail): ?>
                                                <?php
                                                $status = $detail['status'] ?? 'completed';
                                                $badgeClass = match ($status) {
                                                    'completed' => 'bg-success',
                                                    'approved' => 'bg-primary',
                                                    'pending' => 'bg-warning text-dark',
                                                    'rejected' => 'bg-danger',
                                                    default => 'bg-secondary'
                                                };
                                                $statusText = $statusLabels[$status] ?? $status;
                                                ?>
                                                <div class="border rounded px-3 py-2 bg-light">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="fw-semibold"><?php echo $detail['date'] ? formatDate($detail['date']) : '—'; ?></span>
                                                        <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusText); ?></span>
                                                    </div>
                                                    <div class="text-muted small mt-2">
                                                        <div><i class="bi bi-box-seam me-1"></i>الكمية: <?php echo number_format($detail['quantity'], 2); ?></div>
                                                        <div><i class="bi bi-person me-1"></i>العامل: <?php echo htmlspecialchars($detail['worker']); ?></div>
                                                        <?php if (!empty($detail['line_name'])): ?>
                                                            <div><i class="bi bi-diagram-3 me-1"></i>خط الإنتاج: <?php echo htmlspecialchars($detail['line_name']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($finalProducts)): ?>
                <tfoot class="table-light">
                    <tr>
                        <th>الإجماليات</th>
                        <th>
                            <div class="fw-semibold text-primary"><?php echo number_format($totalAvailableSum, 2); ?></div>
                            <div class="text-muted small">إجمالي الكمية المتاحة</div>
                            <div class="text-muted small">إجمالي الإنتاج: <?php echo number_format($totalProducedSum, 2); ?></div>
                        </th>
                        <th>
                            <?php
                            $overallAverage = $totalProductionCountSum > 0 ? $totalProducedSum / $totalProductionCountSum : 0;
                            ?>
                            <div class="fw-semibold"><?php echo number_format($overallAverage, 2); ?></div>
                            <div class="text-muted small">متوسط إنتاج العملية</div>
                        </th>
                        <th>
                            <?php if ($totalEstimatedValue > 0): ?>
                                <div class="fw-semibold text-success"><?php echo formatCurrency($totalEstimatedValue); ?></div>
                                <div class="text-muted small">القيمة الإجمالية المتاحة</div>
                            <?php else: ?>
                                <span class="text-muted small">غير متاح</span>
                            <?php endif; ?>
                        </th>
                        <th>
                            <div class="text-muted small"><i class="bi bi-flag me-1"></i>إجمالي العمليات: <?php echo number_format($totalProductionCountSum); ?></div>
                        </th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $productId ? '&product_id=' . $productId : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo $baseQueryString; ?>&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $productId ? '&product_id=' . $productId : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $productId ? '&product_id=' . $productId : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $productId ? '&product_id=' . $productId : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $productId ? '&product_id=' . $productId : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

