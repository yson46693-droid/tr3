<?php

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/exchanges.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

ensureExchangeSchema();

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'sales_rep_id' => $_GET['sales_rep_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

if ($currentUser['role'] === 'sales') {
    $filters['sales_rep_id'] = $currentUser['id'];
}

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_exchange') {
        $originalSaleId = intval($_POST['original_sale_id'] ?? 0);
        $returnId = !empty($_POST['return_id']) ? intval($_POST['return_id']) : null;
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : $currentUser['id'];
        $exchangeDate = $_POST['exchange_date'] ?? date('Y-m-d');
        $exchangeType = $_POST['exchange_type'] ?? 'same_product';
        $reason = trim($_POST['reason'] ?? '');
        
        // معالجة العناصر المرتجعة
        $returnItems = [];
        if (isset($_POST['return_items']) && is_array($_POST['return_items'])) {
            foreach ($_POST['return_items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $returnItems[] = [
                        'product_id' => intval($item['product_id']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }
        }
        
        // معالجة العناصر الجديدة
        $newItems = [];
        if (isset($_POST['new_items']) && is_array($_POST['new_items'])) {
            foreach ($_POST['new_items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $newItems[] = [
                        'product_id' => intval($item['product_id']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }
        }
        
        if ($originalSaleId <= 0 || $customerId <= 0 || empty($returnItems) || empty($newItems)) {
            $error = 'يجب إدخال جميع البيانات المطلوبة';
        } else {
            $result = createExchange($originalSaleId, $returnId, $customerId, $salesRepId, $exchangeDate,
                                    $exchangeType, $reason, $returnItems, $newItems);
            if ($result['success']) {
                $success = 'تم إنشاء الاستبدال بنجاح: ' . $result['exchange_number'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'approve_exchange') {
        $exchangeId = intval($_POST['exchange_id'] ?? 0);
        
        if ($exchangeId > 0) {
            $result = approveExchange($exchangeId);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    }
}

// التحقق مرة أخرى من وجود جدول exchanges قبل الاستخدام
$tableCheckAgain = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
if (empty($tableCheckAgain)) {
    // إذا لم يكن الجدول موجوداً بعد، عرض رسالة خطأ
    $error = 'جدول الاستبدالات غير موجود. يرجى التحقق من قاعدة البيانات.';
    $exchanges = [];
    $totalReturns = 0;
    $totalPages = 0;
} else {
    // الحصول على البيانات - حساب العدد الإجمالي مع الفلترة
    $countSql = "SELECT COUNT(*) as total FROM exchanges e WHERE 1=1";
    $countParams = [];

    if ($currentUser['role'] === 'sales') {
        $countSql .= " AND e.sales_rep_id = ?";
        $countParams[] = $currentUser['id'];
    }

    if (!empty($filters['customer_id'])) {
        $countSql .= " AND e.customer_id = ?";
        $countParams[] = $filters['customer_id'];
    }

    if (!empty($filters['sales_rep_id'])) {
        $countSql .= " AND e.sales_rep_id = ?";
        $countParams[] = $filters['sales_rep_id'];
    }

    if (!empty($filters['status'])) {
        $countSql .= " AND e.status = ?";
        $countParams[] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $countSql .= " AND DATE(e.exchange_date) >= ?";
        $countParams[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $countSql .= " AND DATE(e.exchange_date) <= ?";
        $countParams[] = $filters['date_to'];
    }

    $totalResult = $db->queryOne($countSql, $countParams);
    $totalExchanges = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalExchanges / $perPage);
    $exchanges = getExchanges($filters, $perPage, $offset);
}

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// التحقق من وجود عمود sale_number في جدول sales
$saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
$hasSaleNumberColumn = !empty($saleNumberColumnCheck);

if ($hasSaleNumberColumn) {
    $sales = $db->query(
        "SELECT s.id, s.sale_number, c.name as customer_name 
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE s.status = 'approved'
         ORDER BY s.created_at DESC LIMIT 50"
    );
} else {
    $sales = $db->query(
        "SELECT s.id, s.id as sale_number, c.name as customer_name 
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE s.status = 'approved'
         ORDER BY s.created_at DESC LIMIT 50"
    );
}
$products = $db->query("SELECT id, name, unit_price FROM products WHERE status = 'active' ORDER BY name");
$returns = $db->query(
    "SELECT r.id, r.return_number, c.name as customer_name 
     FROM returns r
     LEFT JOIN customers c ON r.customer_id = c.id
     WHERE r.status = 'approved'
     ORDER BY r.created_at DESC LIMIT 50"
);

// استبدال محدد للعرض
$selectedExchange = null;
if (isset($_GET['id'])) {
    $exchangeId = intval($_GET['id']);
    
    // التحقق من وجود عمود sale_number
    $saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
    $hasSaleNumberColumn = !empty($saleNumberColumnCheck);
    
    if ($hasSaleNumberColumn) {
        $selectedExchange = $db->queryOne(
            "SELECT e.*, s.sale_number, c.name as customer_name,
                    u.full_name as sales_rep_name, u2.full_name as approved_by_name
             FROM exchanges e
             LEFT JOIN sales s ON e.original_sale_id = s.id
             LEFT JOIN customers c ON e.customer_id = c.id
             LEFT JOIN users u ON e.sales_rep_id = u.id
             LEFT JOIN users u2 ON e.approved_by = u2.id
             WHERE e.id = ?",
            [$exchangeId]
        );
    } else {
        $selectedExchange = $db->queryOne(
            "SELECT e.*, s.id as sale_number, c.name as customer_name,
                    u.full_name as sales_rep_name, u2.full_name as approved_by_name
             FROM exchanges e
             LEFT JOIN sales s ON e.original_sale_id = s.id
             LEFT JOIN customers c ON e.customer_id = c.id
             LEFT JOIN users u ON e.sales_rep_id = u.id
             LEFT JOIN users u2 ON e.approved_by = u2.id
             WHERE e.id = ?",
            [$exchangeId]
        );
    }
    
    if ($selectedExchange) {
        $selectedExchange['return_items'] = $db->query(
            "SELECT eri.*, p.name as product_name
             FROM exchange_return_items eri
             LEFT JOIN products p ON eri.product_id = p.id
             WHERE eri.exchange_id = ?
             ORDER BY eri.id",
            [$exchangeId]
        );
        
        $selectedExchange['new_items'] = $db->query(
            "SELECT eni.*, p.name as product_name
             FROM exchange_new_items eni
             LEFT JOIN products p ON eni.product_id = p.id
             WHERE eni.exchange_id = ?
             ORDER BY eni.id",
            [$exchangeId]
        );
    }
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right me-2"></i>إدارة الاستبدال</h2>
    <?php if (hasRole(['sales', 'accountant'])): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExchangeModal">
        <i class="bi bi-plus-circle me-2"></i>إنشاء استبدال جديد
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

<?php if ($selectedExchange): ?>
    <!-- عرض استبدال محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">استبدال رقم: <?php echo htmlspecialchars($selectedExchange['exchange_number']); ?></h5>
            <a href="?page=exchanges" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">العميل:</th>
                            <td><?php echo htmlspecialchars($selectedExchange['customer_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>رقم البيع الأصلي:</th>
                            <td><?php echo htmlspecialchars($selectedExchange['sale_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ الاستبدال:</th>
                            <td><?php echo formatDate($selectedExchange['exchange_date']); ?></td>
                        </tr>
                        <tr>
                            <th>نوع الاستبدال:</th>
                            <td>
                                <?php 
                                $types = [
                                    'same_product' => 'نفس المنتج',
                                    'different_product' => 'منتج مختلف',
                                    'upgrade' => 'ترقية',
                                    'downgrade' => 'تخفيض'
                                ];
                                echo $types[$selectedExchange['exchange_type']] ?? $selectedExchange['exchange_type'];
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedExchange['status'] === 'completed' ? 'success' : 
                                        ($selectedExchange['status'] === 'rejected' ? 'danger' : 
                                        ($selectedExchange['status'] === 'approved' ? 'info' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'approved' => 'موافق عليه',
                                        'rejected' => 'مرفوض',
                                        'completed' => 'مكتمل'
                                    ];
                                    echo $statuses[$selectedExchange['status']] ?? $selectedExchange['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>المبلغ الأصلي:</th>
                            <td><?php echo formatCurrency($selectedExchange['original_total']); ?></td>
                        </tr>
                        <tr>
                            <th>المبلغ الجديد:</th>
                            <td><?php echo formatCurrency($selectedExchange['new_total']); ?></td>
                        </tr>
                        <tr>
                            <th>الفرق:</th>
                            <td>
                                <strong class="<?php echo $selectedExchange['difference_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatCurrency($selectedExchange['difference_amount']); ?>
                                </strong>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-6">
                    <h6>المنتجات المرتجعة:</h6>
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th>الكمية</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedExchange['return_items'] as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                        <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6>المنتجات الجديدة:</h6>
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th>الكمية</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedExchange['new_items'] as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                        <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php if ($selectedExchange['status'] === 'pending' && hasRole('manager')): ?>
                <div class="mt-3">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="approve_exchange">
                        <input type="hidden" name="exchange_id" value="<?php echo $selectedExchange['id']; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>الموافقة على الاستبدال
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="exchanges">
            <div class="col-md-3">
                <label class="form-label">العميل</label>
                <select class="form-select" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($filters['customer_id']) ? intval($filters['customer_id']) : 0;
                    $customerValid = isValidSelectValue($selectedCustomerId, $customers, 'id');
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo $customerValid && $selectedCustomerId == $customer['id'] ? 'selected' : ''; ?>>
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
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
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

<!-- قائمة الاستبدالات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة الاستبدالات (<?php echo $totalExchanges; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الاستبدال</th>
                        <th>العميل</th>
                        <th>تاريخ الاستبدال</th>
                        <th>المبلغ الأصلي</th>
                        <th>المبلغ الجديد</th>
                        <th>الفرق</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exchanges)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد استبدالات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exchanges as $exchange): ?>
                            <tr>
                                <td>
                                    <a href="?page=exchanges&id=<?php echo $exchange['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($exchange['exchange_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($exchange['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($exchange['exchange_date']); ?></td>
                                <td><?php echo formatCurrency($exchange['original_total']); ?></td>
                                <td><?php echo formatCurrency($exchange['new_total']); ?></td>
                                <td>
                                    <span class="<?php echo $exchange['difference_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatCurrency($exchange['difference_amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $exchange['status'] === 'completed' ? 'success' : 
                                            ($exchange['status'] === 'rejected' ? 'danger' : 
                                            ($exchange['status'] === 'approved' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'completed' => 'مكتمل'
                                        ];
                                        echo $statuses[$exchange['status']] ?? $exchange['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=exchanges&id=<?php echo $exchange['id']; ?>" 
                                       class="btn btn-info btn-sm" title="عرض">
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
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=exchanges&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=exchanges&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=exchanges&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إنشاء استبدال (سيتم إضافته لاحقاً) -->
<?php if (hasRole(['sales', 'accountant'])): ?>
<!-- Modal سيتم إضافته -->
<?php endif; ?>

