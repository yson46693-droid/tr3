<?php
/**
 * صفحة إدارة المبيعات للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// التحقق من وجود جدول sales
$salesTableCheck = $db->queryOne("SHOW TABLES LIKE 'sales'");
if (empty($salesTableCheck)) {
    $error = 'جدول المبيعات غير موجود. يرجى التحقق من قاعدة البيانات.';
    $sales = [];
    $totalSales = 0;
    $totalPages = 0;
} else {
    // Pagination
    $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $perPage = 20;
    $offset = ($pageNum - 1) * $perPage;

    // البحث والفلترة
    $filters = [
        'customer_id' => $_GET['customer_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? ''
    ];

    // إذا كان المستخدم مندوب مبيعات، عرض فقط مبيعاته
    if ($currentUser['role'] === 'sales') {
        $filters['salesperson_id'] = $currentUser['id'];
    }

    $filters = array_filter($filters, function($value) {
        return $value !== '';
    });

    // بناء استعلام SQL
    $sql = "SELECT s.*, c.name as customer_name, p.name as product_name,
                   u.full_name as salesperson_name
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN products p ON s.product_id = p.id
            LEFT JOIN users u ON s.salesperson_id = u.id
            WHERE 1=1";

    $countSql = "SELECT COUNT(*) as total FROM sales WHERE 1=1";
    $params = [];
    $countParams = [];

    // إذا كان المستخدم مندوب مبيعات، فلتر حسب salesperson_id
    if ($currentUser['role'] === 'sales') {
        $sql .= " AND s.salesperson_id = ?";
        $countSql .= " AND salesperson_id = ?";
        $params[] = $currentUser['id'];
        $countParams[] = $currentUser['id'];
    }

    if (!empty($filters['customer_id'])) {
        $sql .= " AND s.customer_id = ?";
        $countSql .= " AND customer_id = ?";
        $params[] = $filters['customer_id'];
        $countParams[] = $filters['customer_id'];
    }

    if (!empty($filters['status'])) {
        $sql .= " AND s.status = ?";
        $countSql .= " AND status = ?";
        $params[] = $filters['status'];
        $countParams[] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(s.date) >= ?";
        $countSql .= " AND DATE(date) >= ?";
        $params[] = $filters['date_from'];
        $countParams[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(s.date) <= ?";
        $countSql .= " AND DATE(date) <= ?";
        $params[] = $filters['date_to'];
        $countParams[] = $filters['date_to'];
    }

    $totalResult = $db->queryOne($countSql, $countParams);
    $totalSales = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalSales / $perPage);

    $sql .= " ORDER BY s.date DESC, s.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    $sales = $db->query($sql, $params);
}

// الحصول على العملاء
$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cart-check me-2"></i>المبيعات</h2>
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

<!-- الفلاتر -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="page" value="sales_collections">
            <div class="col-md-3">
                <label class="form-label">العميل</label>
                <select class="form-select" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($filters['customer_id']) ? intval($filters['customer_id']) : 0;
                    $customerValid = isValidSelectValue($selectedCustomerId, $customers, 'id');
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $customerValid && $selectedCustomerId == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                    <option value="completed" <?php echo ($filters['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-2"></i>بحث
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة المبيعات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة المبيعات (<?php echo $totalSales ?? 0; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>العميل</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>السعر</th>
                        <th>الإجمالي</th>
                        <th>الحالة</th>
                        <?php if ($currentUser['role'] !== 'sales'): ?>
                        <th>مندوب المبيعات</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="<?php echo $currentUser['role'] !== 'sales' ? '8' : '7'; ?>" class="text-center text-muted">لا توجد مبيعات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td><?php echo formatDate($sale['date']); ?></td>
                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($sale['product_name'] ?? '-'); ?></td>
                                <td><?php echo number_format($sale['quantity'], 2); ?></td>
                                <td><?php echo formatCurrency($sale['price']); ?></td>
                                <td><strong><?php echo formatCurrency($sale['total']); ?></strong></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $sale['status'] === 'approved' ? 'success' : 
                                            ($sale['status'] === 'pending' ? 'warning' : 
                                            ($sale['status'] === 'rejected' ? 'danger' : 'info')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'completed' => 'مكتمل'
                                        ];
                                        echo $statuses[$sale['status']] ?? $sale['status'];
                                        ?>
                                    </span>
                                </td>
                                <?php if ($currentUser['role'] !== 'sales'): ?>
                                <td><?php echo htmlspecialchars($sale['salesperson_name'] ?? '-'); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if (isset($totalPages) && $totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=sales_collections&p=<?php echo $pageNum - 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=sales_collections&p=1">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=sales_collections&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=sales_collections&p=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=sales_collections&p=<?php echo $pageNum + 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

