<?php
/**
 * صفحة إدارة التحصيلات للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/path_helper.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'payment_method' => $_GET['payment_method'] ?? '',
    'collected_by' => $_GET['collected_by'] ?? ''
];

// إذا كان المستخدم مندوب مبيعات، عرض فقط تحصيلاته
if ($currentUser['role'] === 'sales') {
    $filters['collected_by'] = $currentUser['id'];
}

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// التحقق من وجود عمود status في جدول collections
$statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
$hasStatusColumn = !empty($statusColumnCheck);

// التحقق من وجود عمود approved_by
$approvedByColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'approved_by'");
$hasApprovedByColumn = !empty($approvedByColumnCheck);

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_collection') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($customerId <= 0 || $amount <= 0) {
            $error = 'يجب إدخال العميل والمبلغ';
        } else {
            // توليد رقم تحصيل
            $year = date('Y');
            $month = date('m');
            $lastCollection = $db->queryOne(
                "SELECT collection_number FROM collections WHERE collection_number LIKE ? ORDER BY collection_number DESC LIMIT 1",
                ["COL-{$year}{$month}-%"]
            );
            
            $serial = 1;
            if ($lastCollection) {
                $parts = explode('-', $lastCollection['collection_number']);
                $serial = intval($parts[2] ?? 0) + 1;
            }
            $collectionNumber = sprintf("COL-%s%s-%04d", $year, $month, $serial);
            
            // بناء الاستعلام بشكل ديناميكي
            $columns = ['collection_number', 'customer_id', 'amount', 'date', 'payment_method', 'collected_by', 'notes'];
            $values = [$collectionNumber, $customerId, $amount, $date, $paymentMethod, $currentUser['id'], $notes];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
            
            if ($hasStatusColumn) {
                $columns[] = 'status';
                $values[] = 'pending';
                $placeholders[] = '?';
            }
            
            $sql = "INSERT INTO collections (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $db->execute($sql, $values);
            
            $collectionId = $db->getLastInsertId();
            
            logAudit($currentUser['id'], 'add_collection', 'collection', $collectionId, null, [
                'collection_number' => $collectionNumber,
                'amount' => $amount
            ]);
            
            $success = 'تم إضافة التحصيل بنجاح: ' . $collectionNumber;
        }
    }
}

// بناء استعلام SQL
$sql = "SELECT c.*, cust.name as customer_name, cust.phone as customer_phone, u.full_name as collected_by_name";
if ($hasApprovedByColumn) {
    $sql .= ", u2.full_name as approved_by_name";
}
$sql .= " FROM collections c 
         LEFT JOIN customers cust ON c.customer_id = cust.id 
         LEFT JOIN users u ON c.collected_by = u.id";
if ($hasApprovedByColumn) {
    $sql .= " LEFT JOIN users u2 ON c.approved_by = u2.id";
}
$sql .= " WHERE 1=1";

$params = [];

// إذا كان المستخدم مندوب مبيعات، عرض فقط تحصيلاته
if ($currentUser['role'] === 'sales') {
    $sql .= " AND c.collected_by = ?";
    $params[] = $currentUser['id'];
}

if (!empty($filters['customer_id'])) {
    $sql .= " AND c.customer_id = ?";
    $params[] = $filters['customer_id'];
}

if (!empty($filters['status']) && $hasStatusColumn) {
    $sql .= " AND c.status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['date_from'])) {
    $sql .= " AND DATE(c.date) >= ?";
    $params[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $sql .= " AND DATE(c.date) <= ?";
    $params[] = $filters['date_to'];
}

if (!empty($filters['payment_method'])) {
    $sql .= " AND c.payment_method = ?";
    $params[] = $filters['payment_method'];
}

if (!empty($filters['collected_by']) && $currentUser['role'] !== 'sales') {
    $sql .= " AND c.collected_by = ?";
    $params[] = $filters['collected_by'];
}

// حساب العدد الإجمالي
$countSql = "SELECT COUNT(*) as total FROM collections c WHERE 1=1";
$countParams = [];

if ($currentUser['role'] === 'sales') {
    $countSql .= " AND c.collected_by = ?";
    $countParams[] = $currentUser['id'];
}

if (!empty($filters['customer_id'])) {
    $countSql .= " AND c.customer_id = ?";
    $countParams[] = $filters['customer_id'];
}

if (!empty($filters['status']) && $hasStatusColumn) {
    $countSql .= " AND c.status = ?";
    $countParams[] = $filters['status'];
}

if (!empty($filters['date_from'])) {
    $countSql .= " AND DATE(c.date) >= ?";
    $countParams[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $countSql .= " AND DATE(c.date) <= ?";
    $countParams[] = $filters['date_to'];
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCollections = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCollections / $perPage);

$sql .= " ORDER BY c.date DESC, c.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$collections = $db->query($sql, $params);

// الحصول على العملاء
$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-coin me-2"></i>التحصيلات</h2>
    <?php if ($currentUser['role'] === 'sales'): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCollectionModal">
            <i class="bi bi-plus-circle me-2"></i>إضافة تحصيل جديد
        </button>
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
            
            <?php if ($hasStatusColumn): ?>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">طريقة الدفع</label>
                <select class="form-select" name="payment_method">
                    <option value="">جميع الطرق</option>
                    <option value="cash" <?php echo ($filters['payment_method'] ?? '') === 'cash' ? 'selected' : ''; ?>>نقدي</option>
                    <option value="bank_transfer" <?php echo ($filters['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>تحويل بنكي</option>
                    <option value="check" <?php echo ($filters['payment_method'] ?? '') === 'check' ? 'selected' : ''; ?>>شيك</option>
                </select>
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

<!-- قائمة التحصيلات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة التحصيلات (<?php echo $totalCollections; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم التحصيل</th>
                        <th>العميل</th>
                        <th>المبلغ</th>
                        <th>التاريخ</th>
                        <th>طريقة الدفع</th>
                        <?php if ($hasStatusColumn): ?>
                        <th>الحالة</th>
                        <?php endif; ?>
                        <th>المحصل</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($collections)): ?>
                        <tr>
                            <td colspan="<?php echo $hasStatusColumn ? '8' : '7'; ?>" class="text-center text-muted">لا توجد تحصيلات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($collections as $collection): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($collection['collection_number'] ?? 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($collection['customer_name'] ?? '-'); ?></td>
                                <td><strong><?php echo formatCurrency($collection['amount']); ?></strong></td>
                                <td><?php echo formatDate($collection['date']); ?></td>
                                <td>
                                    <?php 
                                    $methods = [
                                        'cash' => 'نقدي',
                                        'bank_transfer' => 'تحويل بنكي',
                                        'check' => 'شيك'
                                    ];
                                    echo $methods[$collection['payment_method']] ?? $collection['payment_method'];
                                    ?>
                                </td>
                                <?php if ($hasStatusColumn): ?>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $collection['status'] === 'approved' ? 'success' : 
                                            ($collection['status'] === 'rejected' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض'
                                        ];
                                        echo $statuses[$collection['status']] ?? $collection['status'];
                                        ?>
                                    </span>
                                </td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($collection['collected_by_name'] ?? '-'); ?></td>
                                <td>
                                    <a href="?page=sales_collections&id=<?php echo $collection['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
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

<!-- Modal إضافة تحصيل -->
<?php if ($currentUser['role'] === 'sales'): ?>
<div class="modal fade" id="addCollectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة تحصيل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_collection">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" required>
                            <option value="">اختر العميل</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="amount" required min="0.01">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">طريقة الدفع</label>
                        <select class="form-select" name="payment_method">
                            <option value="cash">نقدي</option>
                            <option value="bank_transfer">تحويل بنكي</option>
                            <option value="check">شيك</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
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
<?php endif; ?>

