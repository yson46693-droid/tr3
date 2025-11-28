<?php
/**
 * صفحة المرتجعات التالفة - قسم المندوب
 * Damaged Returns Page - Sales Representative Section
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/product_name_helper.php';
require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$basePath = getBasePath();

// التحقق من وجود جدول damaged_returns وإنشاؤه إذا لم يكن موجوداً
try {
    $damagedReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'damaged_returns'");
    if (empty($damagedReturnsTableExists)) {
        // إنشاء جدول المرتجعات التالفة
        $db->execute("
            CREATE TABLE IF NOT EXISTS `damaged_returns` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `return_id` INT(11) NOT NULL,
              `return_item_id` INT(11) NOT NULL,
              `product_id` INT(11) NOT NULL,
              `batch_number_id` INT(11) DEFAULT NULL,
              `quantity` DECIMAL(10,2) NOT NULL,
              `damage_reason` TEXT DEFAULT NULL,
              `invoice_id` INT(11) DEFAULT NULL,
              `invoice_number` VARCHAR(100) DEFAULT NULL,
              `return_date` DATE DEFAULT NULL,
              `return_transaction_number` VARCHAR(100) DEFAULT NULL,
              `approval_status` VARCHAR(50) DEFAULT 'pending',
              `sales_rep_id` INT(11) DEFAULT NULL,
              `sales_rep_name` VARCHAR(255) DEFAULT NULL,
              `product_name` VARCHAR(255) DEFAULT NULL,
              `batch_number` VARCHAR(100) DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_return_id` (`return_id`),
              KEY `idx_return_item_id` (`return_item_id`),
              KEY `idx_product_id` (`product_id`),
              KEY `idx_batch_number_id` (`batch_number_id`),
              KEY `idx_sales_rep_id` (`sales_rep_id`),
              KEY `idx_approval_status` (`approval_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // التحقق من وجود الأعمدة المطلوبة وإضافتها إذا لم تكن موجودة
        $columns = $db->query("SHOW COLUMNS FROM damaged_returns");
        $columnNames = array_column($columns, 'Field');
        
        $requiredColumns = [
            'invoice_id' => "ALTER TABLE damaged_returns ADD COLUMN `invoice_id` INT(11) DEFAULT NULL AFTER `damage_reason`",
            'invoice_number' => "ALTER TABLE damaged_returns ADD COLUMN `invoice_number` VARCHAR(100) DEFAULT NULL AFTER `invoice_id`",
            'return_date' => "ALTER TABLE damaged_returns ADD COLUMN `return_date` DATE DEFAULT NULL AFTER `invoice_number`",
            'return_transaction_number' => "ALTER TABLE damaged_returns ADD COLUMN `return_transaction_number` VARCHAR(100) DEFAULT NULL AFTER `return_date`",
            'approval_status' => "ALTER TABLE damaged_returns ADD COLUMN `approval_status` VARCHAR(50) DEFAULT 'pending' AFTER `return_transaction_number`",
            'sales_rep_id' => "ALTER TABLE damaged_returns ADD COLUMN `sales_rep_id` INT(11) DEFAULT NULL AFTER `approval_status`",
            'sales_rep_name' => "ALTER TABLE damaged_returns ADD COLUMN `sales_rep_name` VARCHAR(255) DEFAULT NULL AFTER `sales_rep_id`",
            'product_name' => "ALTER TABLE damaged_returns ADD COLUMN `product_name` VARCHAR(255) DEFAULT NULL AFTER `sales_rep_name`",
            'batch_number' => "ALTER TABLE damaged_returns ADD COLUMN `batch_number` VARCHAR(100) DEFAULT NULL AFTER `product_name`"
        ];
        
        foreach ($requiredColumns as $columnName => $sql) {
            if (!in_array($columnName, $columnNames)) {
                try {
                    $db->execute($sql);
                    if ($columnName === 'sales_rep_id') {
                        $db->execute("ALTER TABLE damaged_returns ADD INDEX `idx_sales_rep_id` (`sales_rep_id`)");
                    }
                    if ($columnName === 'approval_status') {
                        $db->execute("ALTER TABLE damaged_returns ADD INDEX `idx_approval_status` (`approval_status`)");
                    }
                } catch (Throwable $e) {
                    error_log("Error adding column {$columnName}: " . $e->getMessage());
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log("Error checking/creating damaged_returns table: " . $e->getMessage());
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Filters
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$batchFilter = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';

// Build query
$sql = "SELECT 
        dr.id,
        dr.return_id,
        dr.product_id,
        dr.quantity,
        dr.damage_reason,
        dr.invoice_id,
        dr.invoice_number,
        dr.return_date,
        dr.return_transaction_number,
        dr.approval_status,
        dr.sales_rep_id,
        dr.sales_rep_name,
        dr.product_name,
        dr.batch_number,
        dr.batch_number_id,
        r.return_number,
        r.status as return_status,
        c.name as customer_name,
        COALESCE(
            (SELECT fp2.product_name 
             FROM finished_products fp2 
             WHERE fp2.product_id = dr.product_id 
               AND fp2.product_name IS NOT NULL 
               AND TRIM(fp2.product_name) != ''
               AND fp2.product_name NOT LIKE 'منتج رقم%'
             ORDER BY fp2.id DESC 
             LIMIT 1),
            dr.product_name,
            p.name,
            CONCAT('منتج رقم ', dr.product_id)
        ) as display_product_name,
        COALESCE(b.batch_number, dr.batch_number) as display_batch_number
    FROM damaged_returns dr
    LEFT JOIN returns r ON dr.return_id = r.id
    LEFT JOIN products p ON dr.product_id = p.id
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN batch_numbers b ON dr.batch_number_id = b.id
    WHERE 1=1";

$params = [];

// Filter by role - فقط المرتجعات المعتمدة (approved)
$sql .= " AND dr.approval_status = 'approved'";

if ($currentUser['role'] === 'sales') {
    $sql .= " AND dr.sales_rep_id = ?";
    $params[] = (int)$currentUser['id'];
}

// Apply filters
if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
    $sql .= " AND dr.approval_status = ?";
    $params[] = $statusFilter;
}

if ($customerFilter > 0) {
    $sql .= " AND r.customer_id = ?";
    $params[] = $customerFilter;
}

if ($dateFrom) {
    $sql .= " AND dr.return_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND dr.return_date <= ?";
    $params[] = $dateTo;
}

if ($batchFilter) {
    $sql .= " AND (dr.batch_number LIKE ? OR b.batch_number LIKE ?)";
    $params[] = "%{$batchFilter}%";
    $params[] = "%{$batchFilter}%";
}

$sql .= " ORDER BY dr.created_at DESC
          LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$damagedReturns = $db->query($sql, $params);

// Get total count
$countSql = "SELECT COUNT(*) as total
             FROM damaged_returns dr
             LEFT JOIN returns r ON dr.return_id = r.id
             WHERE 1=1 AND dr.approval_status = 'approved'";

$countParams = [];
if ($currentUser['role'] === 'sales') {
    $countSql .= " AND dr.sales_rep_id = ?";
    $countParams[] = (int)$currentUser['id'];
}

if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
    $countSql .= " AND dr.approval_status = ?";
    $countParams[] = $statusFilter;
}

if ($customerFilter > 0) {
    $countSql .= " AND r.customer_id = ?";
    $countParams[] = $customerFilter;
}

if ($dateFrom) {
    $countSql .= " AND dr.return_date >= ?";
    $countParams[] = $dateFrom;
}

if ($dateTo) {
    $countSql .= " AND dr.return_date <= ?";
    $countParams[] = $dateTo;
}

if ($batchFilter) {
    $countSql .= " AND (dr.batch_number LIKE ? OR EXISTS (SELECT 1 FROM batch_numbers b WHERE b.id = dr.batch_number_id AND b.batch_number LIKE ?))";
    $countParams[] = "%{$batchFilter}%";
    $countParams[] = "%{$batchFilter}%";
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCount = (int)($totalResult['total'] ?? 0);
$totalPages = ceil($totalCount / $perPage);

// Get customers for filter
$customers = [];
if ($currentUser['role'] === 'sales') {
    $customers = $db->query(
        "SELECT id, name FROM customers WHERE created_by = ? AND status = 'active' ORDER BY name",
        [(int)$currentUser['id']]
    );
} else {
    $customers = $db->query(
        "SELECT id, name FROM customers WHERE status = 'active' ORDER BY name LIMIT 100"
    );
}
?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12">
            <h3 class="mb-3">
                <i class="bi bi-exclamation-triangle me-2 text-danger"></i>المرتجعات التالفة
            </h3>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="page" value="my_record">
                        <input type="hidden" name="section" value="damaged_returns">
                        
                        <div class="col-md-2">
                            <label class="form-label">حالة الموافقة</label>
                            <select name="status" class="form-select">
                                <option value="">جميع الحالات</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>قيد المراجعة</option>
                                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>معتمد</option>
                                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">العميل</label>
                            <select name="customer_id" class="form-select">
                                <option value="">جميع العملاء</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" <?php echo $customerFilter === $customer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">من تاريخ</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">إلى تاريخ</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">رقم التشغيلة</label>
                            <input type="text" name="batch_number" class="form-control" value="<?php echo htmlspecialchars($batchFilter); ?>" placeholder="البحث برقم التشغيلة">
                        </div>

                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> بحث
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Damaged Returns Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <?php if (empty($damagedReturns)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد مرتجعات تالفة
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>اسم المنتج</th>
                                        <th>رقم الفاتورة</th>
                                        <th>رقم التشغيلة</th>
                                        <th>الكمية التالفة</th>
                                        <th>تاريخ الإرجاع</th>
                                        <th>رقم العملية</th>
                                        <th>سبب التلف</th>
                                        <th>حالة الموافقة</th>
                                        <th>اسم المندوب</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($damagedReturns as $item): ?>
                                        <?php
                                        $statusClasses = [
                                            'pending' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger'
                                        ];
                                        $statusLabels = [
                                            'pending' => 'قيد المراجعة',
                                            'approved' => 'معتمد',
                                            'rejected' => 'مرفوض'
                                        ];
                                        $statusClass = $statusClasses[$item['approval_status']] ?? 'secondary';
                                        $statusLabel = $statusLabels[$item['approval_status']] ?? $item['approval_status'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['display_product_name'] ?? $item['product_name'] ?? 'منتج رقم ' . $item['product_id']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($item['invoice_number'] ?? ($item['return_transaction_number'] ?? '-')); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($item['display_batch_number'] ?? $item['batch_number'] ?? '-'); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger"><?php echo number_format((float)$item['quantity'], 2); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['return_date'] ?? ($item['return_date'] ?? '-')); ?></td>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($item['return_transaction_number'] ?? ($item['return_number'] ?? '-')); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($item['damage_reason']): ?>
                                                    <span class="text-muted" title="<?php echo htmlspecialchars($item['damage_reason']); ?>">
                                                        <?php echo mb_strlen($item['damage_reason']) > 50 ? mb_substr($item['damage_reason'], 0, 50) . '...' : htmlspecialchars($item['damage_reason']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <?php echo $statusLabel; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['sales_rep_name'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=my_record&section=damaged_returns&p=<?php echo $pageNum - 1; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $customerFilter ? '&customer_id=' . $customerFilter : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $batchFilter ? '&batch_number=' . urlencode($batchFilter) : ''; ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $pageNum - 2);
                                    $endPage = min($totalPages, $pageNum + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++):
                                    ?>
                                        <li class="page-item <?php echo $i === $pageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=my_record&section=damaged_returns&p=<?php echo $i; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $customerFilter ? '&customer_id=' . $customerFilter : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $batchFilter ? '&batch_number=' . urlencode($batchFilter) : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=my_record&section=damaged_returns&p=<?php echo $pageNum + 1; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $customerFilter ? '&customer_id=' . $customerFilter : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?><?php echo $batchFilter ? '&batch_number=' . urlencode($batchFilter) : ''; ?>">
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

