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
require_once __DIR__ . '/../../includes/table_styles.php';

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
if (is_array($finalProducts)) {
    foreach ($finalProducts as $product) {
        $availableQuantity = (float)($product['available_quantity'] ?? 0);
        $producedQuantity = (float)($product['total_produced'] ?? 0);
        $productOperations = (int)($product['production_count'] ?? 0);
        $productUnitPrice = isset($product['unit_price']) ? (float)$product['unit_price'] : 0.0;

        $totalAvailableSum += $availableQuantity;
        $totalProducedSum += $producedQuantity;
        $totalProductionCountSum += $productOperations;
    }
}

$statusLabels = [
    'pending' => 'معلّق',
    'approved' => 'موافق عليه',
    'completed' => 'مكتمل',
    'rejected' => 'مرفوض'
];

$finishedProductsRows = [];
$finishedProductsCount = 0;
$finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
if (!empty($finishedProductsTableExists)) {
    try {
        $finishedProductsRows = $db->query("
            SELECT 
                fp.id,
                fp.batch_id,
                fp.batch_number,
                fp.product_id,
                COALESCE(pr.name, fp.product_name) AS product_name,
                fp.production_date,
                fp.quantity_produced,
                GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
            FROM finished_products fp
            LEFT JOIN products pr ON fp.product_id = pr.id
            LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
            LEFT JOIN users u ON bw.employee_id = u.id
            GROUP BY fp.id
            ORDER BY fp.production_date DESC, fp.id DESC
            LIMIT 150
        ");
        $finishedProductsCount = is_array($finishedProductsRows) ? count($finishedProductsRows) : 0;
    } catch (Exception $finishedProductsError) {
        error_log('Finished products query error: ' . $finishedProductsError->getMessage());
    }
}

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

<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h2 class="mb-0"><i class="bi bi-boxes me-2"></i>جدول المنتجات</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php
        $transferUrl = getDashboardUrl('sales') . '?page=vehicle_inventory';
        ?>
        <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars($transferUrl); ?>">
            <i class="bi bi-arrow-left-right me-1"></i>
            نقل منتجات بين المخازن
        </a>
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

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-archive-fill me-2"></i>جدول المنتجات</h5>
        <span class="badge bg-light text-dark">
            <?php echo number_format($finishedProductsCount); ?> عنصر
        </span>
    </div>
    <div class="card-body">
        <?php if (!empty($finishedProductsRows)): ?>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>رقم التشغيله</th>
                            <th>اسم المنتج</th>
                            <th>تاريخ الإنتاج</th>
                            <th>الكمية المنتجة</th>
                            <th>العمال المشاركون</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($finishedProductsRows as $finishedRow): ?>
                            <?php
                                $batchNumber = $finishedRow['batch_number'] ?? '';
                                $workersList = array_filter(array_map('trim', explode(',', (string)($finishedRow['workers'] ?? ''))));
                                $workersDisplay = !empty($workersList)
                                    ? implode('، ', array_map('htmlspecialchars', $workersList))
                                    : 'غير محدد';
                                $viewUrl = $batchNumber
                                    ? getRelativeUrl('production.php?page=batch_numbers&batch_number=' . urlencode($batchNumber))
                                    : null;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batchNumber ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($finishedRow['product_name'] ?? 'غير محدد'); ?></td>
                                <td><?php echo !empty($finishedRow['production_date']) ? htmlspecialchars(formatDate($finishedRow['production_date'])) : '—'; ?></td>
                                <td><?php echo number_format((float)($finishedRow['quantity_produced'] ?? 0), 2); ?></td>
                                <td><?php echo $workersDisplay; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if ($viewUrl): ?>
                                            <a class="btn btn-primary" href="<?php echo htmlspecialchars($viewUrl); ?>">
                                                <i class="bi bi-eye"></i> عرض تفاصيل التشغيلة
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($batchNumber): ?>
                                        <button type="button"
                                                class="btn btn-outline-secondary js-copy-batch"
                                                data-batch="<?php echo htmlspecialchars($batchNumber); ?>">
                                            <i class="bi bi-clipboard"></i>
                                            <span class="d-none d-sm-inline">نسخ الرقم</span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$viewUrl && !$batchNumber): ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                لا توجد بيانات متاحة حالياً.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const copyButton = event.target.closest('.js-copy-batch');
    if (!copyButton) {
        return;
    }

    const batchNumber = copyButton.dataset.batch;
    if (!batchNumber) {
        return;
    }

    const originalHtml = copyButton.innerHTML;
    const originalClasses = copyButton.className;

    function showCopiedFeedback(success) {
        copyButton.className = success ? 'btn btn-success btn-sm' : 'btn btn-warning btn-sm';
        copyButton.innerHTML = success
            ? '<i class="bi bi-check-circle"></i> تم النسخ'
            : '<i class="bi bi-exclamation-triangle"></i> تعذر النسخ';

        setTimeout(() => {
            copyButton.className = originalClasses;
            copyButton.innerHTML = originalHtml;
        }, 2000);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(batchNumber)
            .then(() => showCopiedFeedback(true))
            .catch(() => showCopiedFeedback(false));
    } else {
        const tempInput = document.createElement('input');
        tempInput.style.position = 'fixed';
        tempInput.style.opacity = '0';
        tempInput.value = batchNumber;
        document.body.appendChild(tempInput);
        tempInput.select();
        try {
            const successful = document.execCommand('copy');
            showCopiedFeedback(successful);
        } catch (err) {
            showCopiedFeedback(false);
        }
        document.body.removeChild(tempInput);
    }
});
</script>

