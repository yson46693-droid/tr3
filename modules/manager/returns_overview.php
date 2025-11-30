<?php
/**
 * صفحة نظرة عامة على المرتجعات - حساب المدير
 * Manager Returns Overview Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['manager']);

$currentUser = getCurrentUser();
$db = db();
$basePath = getBasePath();

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Filters
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$salesRepFilter = isset($_GET['sales_rep_id']) ? (int)$_GET['sales_rep_id'] : 0;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$batchFilter = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';

// Build query
$sql = "SELECT 
        r.id,
        r.return_number,
        r.return_date,
        r.refund_amount,
        r.status,
        COALESCE(SUM(ri.quantity), 0) as return_quantity,
        r.reason,
        c.id as customer_id,
        c.name as customer_name,
        u.id as sales_rep_id,
        u.full_name as sales_rep_name,
        i.invoice_number,
        GROUP_CONCAT(DISTINCT ri.batch_number ORDER BY ri.batch_number SEPARATOR ', ') as batch_numbers
    FROM returns r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN users u ON r.sales_rep_id = u.id
    LEFT JOIN invoices i ON r.invoice_id = i.id
    LEFT JOIN return_items ri ON r.id = ri.return_id
    WHERE 1=1";

$params = [];

// Apply filters
if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected', 'processed'])) {
    $sql .= " AND r.status = ?";
    $params[] = $statusFilter;
}

if ($salesRepFilter > 0) {
    $sql .= " AND r.sales_rep_id = ?";
    $params[] = $salesRepFilter;
}

if ($customerFilter > 0) {
    $sql .= " AND r.customer_id = ?";
    $params[] = $customerFilter;
}

if ($dateFrom) {
    $sql .= " AND r.return_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND r.return_date <= ?";
    $params[] = $dateTo;
}

if ($batchFilter) {
    $sql .= " AND ri.batch_number LIKE ?";
    $params[] = "%{$batchFilter}%";
}

$sql .= " GROUP BY r.id
          ORDER BY r.created_at DESC
          LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$returns = $db->query($sql, $params);

// Get total count
$countSql = "SELECT COUNT(DISTINCT r.id) as total
             FROM returns r
             LEFT JOIN customers c ON r.customer_id = c.id
             LEFT JOIN return_items ri ON r.id = ri.return_id
             WHERE 1=1";

$countParams = [];

if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected', 'processed'])) {
    $countSql .= " AND r.status = ?";
    $countParams[] = $statusFilter;
}

if ($salesRepFilter > 0) {
    $countSql .= " AND r.sales_rep_id = ?";
    $countParams[] = $salesRepFilter;
}

if ($customerFilter > 0) {
    $countSql .= " AND r.customer_id = ?";
    $countParams[] = $customerFilter;
}

if ($dateFrom) {
    $countSql .= " AND r.return_date >= ?";
    $countParams[] = $dateFrom;
}

if ($dateTo) {
    $countSql .= " AND r.return_date <= ?";
    $countParams[] = $dateTo;
}

if ($batchFilter) {
    $countSql .= " AND ri.batch_number LIKE ?";
    $countParams[] = "%{$batchFilter}%";
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCount = (int)($totalResult['total'] ?? 0);
$totalPages = ceil($totalCount / $perPage);

// Get sales reps for filter
$salesReps = $db->query(
    "SELECT id, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY full_name"
);

// Get customers for filter
$customers = $db->query(
    "SELECT id, name FROM customers WHERE status = 'active' ORDER BY name LIMIT 100"
);

// Statistics
$stats = [
    'pending' => (int)$db->queryOne(
        "SELECT COUNT(*) as total FROM returns WHERE status = 'pending'"
    )['total'] ?? 0,
    'approved_today' => (int)$db->queryOne(
        "SELECT COUNT(*) as total FROM returns WHERE status = 'approved' AND DATE(approved_at) = CURDATE()"
    )['total'] ?? 0,
    'total_pending_amount' => (float)$db->queryOne(
        "SELECT COALESCE(SUM(refund_amount), 0) as total FROM returns WHERE status = 'pending'"
    )['total'] ?? 0,
    'total_approved_today' => (float)$db->queryOne(
        "SELECT COALESCE(SUM(refund_amount), 0) as total FROM returns WHERE status = 'approved' AND DATE(approved_at) = CURDATE()"
    )['total'] ?? 0,
];

?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-3">
                <i class="bi bi-arrow-return-left me-2"></i>نظرة عامة على المرتجعات
            </h2>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">طلبات معلقة</h6>
                            <h3 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2.5rem;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">معتمدة اليوم</h6>
                            <h3 class="mb-0 text-primary"><?php echo $stats['approved_today']; ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2.5rem;">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">مبلغ معلق</h6>
                            <h3 class="mb-0 text-danger"><?php echo number_format($stats['total_pending_amount'], 2); ?> ج.م</h3>
                        </div>
                        <div class="text-danger" style="font-size: 2.5rem;">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">معتمد اليوم</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['total_approved_today'], 2); ?> ج.م</h3>
                        </div>
                        <div class="text-success" style="font-size: 2.5rem;">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="page" value="returns">
                        
                        <div class="col-md-2">
                            <label class="form-label">الحالة</label>
                            <select name="status" class="form-select">
                                <option value="">جميع الحالات</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>قيد المراجعة</option>
                                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>مقبول</option>
                                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                                <option value="processed" <?php echo $statusFilter === 'processed' ? 'selected' : ''; ?>>مكتمل</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">المندوب</label>
                            <select name="sales_rep_id" class="form-select">
                                <option value="">جميع المندوبين</option>
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo $salesRepFilter === $rep['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
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

                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> بحث
                            </button>
                            <a href="?page=returns" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> إعادة تعيين
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Returns Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <?php if (empty($returns)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>لا توجد مرتجعات
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>رقم المرتجع</th>
                                        <th>العميل</th>
                                        <th>المندوب</th>
                                        <th>رقم الفاتورة</th>
                                        <th>رقم التشغيلة</th>
                                        <th>الكمية</th>
                                        <th>المبلغ</th>
                                        <th>التاريخ</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($returns as $return): ?>
                                        <?php
                                        $statusClasses = [
                                            'pending' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            'processed' => 'info'
                                        ];
                                        $statusLabels = [
                                            'pending' => 'قيد المراجعة',
                                            'approved' => 'مقبول',
                                            'rejected' => 'مرفوض',
                                            'processed' => 'مكتمل'
                                        ];
                                        $statusClass = $statusClasses[$return['status']] ?? 'secondary';
                                        $statusLabel = $statusLabels[$return['status']] ?? $return['status'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary"><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($return['customer_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($return['sales_rep_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($return['invoice_number'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($return['batch_numbers'] ?? '-'); ?></td>
                                            <td><?php echo number_format((float)$return['return_quantity'], 2); ?></td>
                                            <td>
                                                <strong><?php echo number_format((float)$return['refund_amount'], 2); ?> ج.م</strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($return['return_date']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <?php echo $statusLabel; ?>
                                                </span>
                                            </td>
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
                                        <a class="page-link" href="?page=returns&p=<?php echo $pageNum - 1; ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    
                                    <?php
                                    $startPage = max(1, $pageNum - 2);
                                    $endPage = min($totalPages, $pageNum + 2);
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++):
                                    ?>
                                        <li class="page-item <?php echo $i === $pageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=returns&p=<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=returns&p=<?php echo $pageNum + 1; ?>">
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

<!-- Return Details Modal -->
<div class="modal fade" id="returnDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل المرتجع</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="returnDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const basePath = '<?php echo $basePath; ?>';

function viewReturnDetails(returnId) {
    const modal = new bootstrap.Modal(document.getElementById('returnDetailsModal'));
    const content = document.getElementById('returnDetailsContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    modal.show();
    
    fetch(basePath + '/api/new_returns_api.php?action=details&id=' + returnId, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.return) {
            const ret = data.return;
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>رقم المرتجع:</strong> ${ret.return_number || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>التاريخ:</strong> ${ret.return_date || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>العميل:</strong> ${ret.customer_name || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>الحالة:</strong> <span class="badge bg-${ret.status === 'approved' ? 'success' : ret.status === 'rejected' ? 'danger' : 'warning'}">${ret.status || '-'}</span>
                    </div>
                </div>
                <hr>
                <h6>المنتجات:</h6>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>رقم التشغيلة</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            if (ret.items && ret.items.length > 0) {
                ret.items.forEach(item => {
                    html += `
                        <tr>
                            <td>${item.product_name || '-'}</td>
                            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
                            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
                            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
                            <td>${item.batch_number || '-'}</td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="5" class="text-center">لا توجد منتجات</td></tr>';
            }
            
            html += `
                    </tbody>
                </table>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <strong>المبلغ الإجمالي:</strong> <span class="text-primary fs-5">${parseFloat(ret.refund_amount || 0).toFixed(2)} ج.م</span>
                    </div>
                </div>
            `;
            
            if (ret.notes) {
                html += `<hr><strong>ملاحظات:</strong><p>${ret.notes}</p>`;
            }
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div class="alert alert-warning">لا يمكن تحميل تفاصيل المرتجع</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
    });
}

function approveReturn(returnId) {
    if (!confirm('هل أنت متأكد من الموافقة على طلب المرتجع؟')) {
        return;
    }
    
    fetch(basePath + '/api/returns.php?action=approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            notes: ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تمت الموافقة بنجاح!\n' + (data.financial_note || ''));
            location.reload();
        } else {
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم');
    });
}

function rejectReturn(returnId) {
    const notes = prompt('يرجى إدخال سبب الرفض (اختياري):');
    if (notes === null) {
        return;
    }
    
    fetch(basePath + '/api/approve_return.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            action: 'reject',
            notes: notes || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم رفض الطلب بنجاح');
            location.reload();
        } else {
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم');
    });
}
</script>

