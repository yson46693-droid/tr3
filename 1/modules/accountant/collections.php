<?php
/**
 * صفحة إدارة التحصيلات للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['accountant', 'manager']);

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

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_collection') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($customerId <= 0) {
            $error = 'يجب اختيار العميل';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
        } else {
            // التحقق من وجود عمود status
            $columnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
            $hasStatus = !empty($columnCheck);
            
            if ($hasStatus) {
                $status = 'pending';
                $result = $db->execute(
                    "INSERT INTO collections (customer_id, amount, date, payment_method, reference_number, collected_by, status, notes) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$customerId, $amount, $date, $paymentMethod, $referenceNumber ?: null, $currentUser['id'], $status, $notes ?: null]
                );
            } else {
                // إذا لم يكن status موجوداً
                $result = $db->execute(
                    "INSERT INTO collections (customer_id, amount, date, payment_method, reference_number, collected_by, notes) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$customerId, $amount, $date, $paymentMethod, $referenceNumber ?: null, $currentUser['id'], $notes ?: null]
                );
            }
            
            logAudit($currentUser['id'], 'add_collection', 'collection', $result['insert_id'], null, [
                'customer_id' => $customerId,
                'amount' => $amount
            ]);
            
            // إرسال إشعار للمدير للموافقة
            if ($hasStatus) {
                notifyManagers('تحصيل جديد', "تم إضافة تحصيل جديد بقيمة " . formatCurrency($amount), 'info');
            }
            
            $success = 'تم إضافة التحصيل بنجاح';
        }
    } elseif ($action === 'update_collection') {
        $collectionId = intval($_POST['collection_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($collectionId <= 0) {
            $error = 'معرّف التحصيل غير صحيح';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
        } else {
            $oldCollection = $db->queryOne("SELECT * FROM collections WHERE id = ?", [$collectionId]);
            
            // التحقق من وجود عمود notes
            $notesColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'notes'");
            $hasNotes = !empty($notesColumnCheck);
            
            if ($hasNotes) {
                $db->execute(
                    "UPDATE collections SET amount = ?, date = ?, payment_method = ?, reference_number = ?, notes = ?, updated_at = NOW() WHERE id = ?",
                    [$amount, $date, $paymentMethod, $referenceNumber ?: null, $notes ?: null, $collectionId]
                );
            } else {
                $db->execute(
                    "UPDATE collections SET amount = ?, date = ?, payment_method = ?, reference_number = ?, updated_at = NOW() WHERE id = ?",
                    [$amount, $date, $paymentMethod, $referenceNumber ?: null, $collectionId]
                );
            }
            
            logAudit($currentUser['id'], 'update_collection', 'collection', $collectionId, 
                     json_encode($oldCollection), ['amount' => $amount]);
            
            $success = 'تم تحديث التحصيل بنجاح';
        }
    } elseif ($action === 'delete_collection') {
        $collectionId = intval($_POST['collection_id'] ?? 0);
        
        if ($collectionId <= 0) {
            $error = 'معرّف التحصيل غير صحيح';
        } else {
            $collection = $db->queryOne("SELECT * FROM collections WHERE id = ?", [$collectionId]);
            
            if ($collection) {
                $db->execute("DELETE FROM collections WHERE id = ?", [$collectionId]);
                
                logAudit($currentUser['id'], 'delete_collection', 'collection', $collectionId, 
                         json_encode($collection), null);
                
                $success = 'تم حذف التحصيل بنجاح';
            } else {
                $error = 'التحصيل غير موجود';
            }
        }
    } elseif ($action === 'approve_collection') {
        $collectionId = intval($_POST['collection_id'] ?? 0);
        
        if ($collectionId <= 0) {
            $error = 'معرّف التحصيل غير صحيح';
        } else {
            $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
            $approvedByColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'approved_by'");
            
            if (!empty($statusColumnCheck)) {
                if (!empty($approvedByColumnCheck)) {
                    $db->execute(
                        "UPDATE collections SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                        [$currentUser['id'], $collectionId]
                    );
                } else {
                    $db->execute(
                        "UPDATE collections SET status = 'approved', approved_at = NOW() WHERE id = ?",
                        [$collectionId]
                    );
                }
                
                logAudit($currentUser['id'], 'approve_collection', 'collection', $collectionId, null, null);
                
                $success = 'تم الموافقة على التحصيل بنجاح';
            } else {
                $error = 'نظام الموافقات غير متاح';
            }
        }
    } elseif ($action === 'reject_collection') {
        $collectionId = intval($_POST['collection_id'] ?? 0);
        
        if ($collectionId <= 0) {
            $error = 'معرّف التحصيل غير صحيح';
        } else {
            $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
            $approvedByColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'approved_by'");
            
            if (!empty($statusColumnCheck)) {
                if (!empty($approvedByColumnCheck)) {
                    $db->execute(
                        "UPDATE collections SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?",
                        [$currentUser['id'], $collectionId]
                    );
                } else {
                    $db->execute(
                        "UPDATE collections SET status = 'rejected', approved_at = NOW() WHERE id = ?",
                        [$collectionId]
                    );
                }
                
                logAudit($currentUser['id'], 'reject_collection', 'collection', $collectionId, null, null);
                
                $success = 'تم رفض التحصيل';
            } else {
                $error = 'نظام الموافقات غير متاح';
            }
        }
    }
}

// التحقق من وجود الأعمدة قبل بناء الاستعلام
$approvedByColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'approved_by'");
$hasApprovedByColumn = !empty($approvedByColumnCheck);

// بناء استعلام البحث
$sql = "SELECT c.*, 
        cust.name as customer_name, 
        cust.phone as customer_phone,
        u.full_name as collected_by_name";
        
if ($hasApprovedByColumn) {
    $sql .= ",
        u2.full_name as approved_by_name";
}

$sql .= " FROM collections c
        LEFT JOIN customers cust ON c.customer_id = cust.id
        LEFT JOIN users u ON c.collected_by = u.id";
        
if ($hasApprovedByColumn) {
    $sql .= "
        LEFT JOIN users u2 ON c.approved_by = u2.id";
}

$sql .= " WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM collections WHERE 1=1";
$params = [];
$countParams = [];

if (!empty($filters['customer_id'])) {
    $sql .= " AND c.customer_id = ?";
    $countSql .= " AND customer_id = ?";
    $params[] = $filters['customer_id'];
    $countParams[] = $filters['customer_id'];
}

if (!empty($filters['status'])) {
    $columnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    if (!empty($columnCheck)) {
        $sql .= " AND c.status = ?";
        $countSql .= " AND status = ?";
        $params[] = $filters['status'];
        $countParams[] = $filters['status'];
    }
}

if (!empty($filters['date_from'])) {
    $sql .= " AND DATE(c.date) >= ?";
    $countSql .= " AND DATE(date) >= ?";
    $params[] = $filters['date_from'];
    $countParams[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $sql .= " AND DATE(c.date) <= ?";
    $countSql .= " AND DATE(date) <= ?";
    $params[] = $filters['date_to'];
    $countParams[] = $filters['date_to'];
}

if (!empty($filters['payment_method'])) {
    $sql .= " AND c.payment_method = ?";
    $countSql .= " AND payment_method = ?";
    $params[] = $filters['payment_method'];
    $countParams[] = $filters['payment_method'];
}

if (!empty($filters['collected_by'])) {
    $sql .= " AND c.collected_by = ?";
    $countSql .= " AND collected_by = ?";
    $params[] = $filters['collected_by'];
    $countParams[] = $filters['collected_by'];
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCollections = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCollections / $perPage);

$sql .= " ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$collections = $db->query($sql, $params);

// الحصول على البيانات المطلوبة
$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
$users = $db->query("SELECT id, username, full_name FROM users WHERE status = 'active' ORDER BY username");

// التحقق من وجود عمود status
$statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
$hasStatusColumn = !empty($statusColumnCheck);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-coin me-2"></i>إدارة التحصيلات</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCollectionModal">
        <i class="bi bi-plus-circle me-2"></i>إضافة تحصيل جديد
    </button>
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

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
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
                    <option value="pending" <?php echo ($filters['status'] ?? '') == 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($filters['status'] ?? '') == 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($filters['status'] ?? '') == 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="col-md-2">
                <label class="form-label">طريقة الدفع</label>
                <select class="form-select" name="payment_method">
                    <option value="">جميع الطرق</option>
                    <option value="cash" <?php echo ($filters['payment_method'] ?? '') == 'cash' ? 'selected' : ''; ?>>نقدي</option>
                    <option value="bank" <?php echo ($filters['payment_method'] ?? '') == 'bank' ? 'selected' : ''; ?>>بنكي</option>
                    <option value="cheque" <?php echo ($filters['payment_method'] ?? '') == 'cheque' ? 'selected' : ''; ?>>شيك</option>
                    <option value="other" <?php echo ($filters['payment_method'] ?? '') == 'other' ? 'selected' : ''; ?>>أخرى</option>
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
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>العميل</th>
                        <th>المبلغ</th>
                        <th>طريقة الدفع</th>
                        <th>رقم المرجع</th>
                        <th>المحصل</th>
                        <?php if ($hasStatusColumn): ?>
                        <th>الحالة</th>
                        <?php endif; ?>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($collections)): ?>
                        <tr>
                            <td colspan="<?php echo $hasStatusColumn ? '9' : '8'; ?>" class="text-center text-muted">لا توجد تحصيلات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($collections as $index => $collection): ?>
                            <tr>
                                <td data-label="#"><?php echo ($pageNum - 1) * $perPage + $index + 1; ?></td>
                                <td data-label="التاريخ"><?php echo formatDate($collection['date']); ?></td>
                                <td data-label="العميل">
                                    <strong><?php echo htmlspecialchars($collection['customer_name'] ?? 'غير محدد'); ?></strong>
                                    <?php if (!empty($collection['customer_phone'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($collection['customer_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="المبلغ"><strong class="text-success"><?php echo formatCurrency($collection['amount']); ?></strong></td>
                                <td data-label="طريقة الدفع">
                                    <?php
                                    $paymentMethods = [
                                        'cash' => 'نقدي',
                                        'bank' => 'بنكي',
                                        'cheque' => 'شيك',
                                        'other' => 'أخرى'
                                    ];
                                    echo $paymentMethods[$collection['payment_method']] ?? $collection['payment_method'];
                                    ?>
                                </td>
                                <td data-label="رقم المرجع"><?php echo htmlspecialchars($collection['reference_number'] ?? '-'); ?></td>
                                <td data-label="المحصل"><?php echo htmlspecialchars($collection['collected_by_name'] ?? 'غير محدد'); ?></td>
                                <?php if ($hasStatusColumn): ?>
                                <td data-label="الحالة">
                                    <?php
                                    $status = $collection['status'] ?? 'pending';
                                    $statusBadges = [
                                        'pending' => '<span class="badge bg-warning">معلق</span>',
                                        'approved' => '<span class="badge bg-success">موافق عليه</span>',
                                        'rejected' => '<span class="badge bg-danger">مرفوض</span>'
                                    ];
                                    echo $statusBadges[$status] ?? '<span class="badge bg-secondary">' . $status . '</span>';
                                    ?>
                                </td>
                                <?php endif; ?>
                                <td data-label="الإجراءات">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-info" onclick="viewCollection(<?php echo $collection['id']; ?>)" title="عرض">
                                            <i class="bi bi-eye"></i>
                                            <span class="d-none d-md-inline">عرض</span>
                                        </button>
                                        <?php if (hasRole('accountant') || hasRole('manager')): ?>
                                            <button class="btn btn-warning" onclick="editCollection(<?php echo $collection['id']; ?>)" title="تعديل">
                                                <i class="bi bi-pencil"></i>
                                                <span class="d-none d-md-inline">تعديل</span>
                                            </button>
                                            <?php if ($hasStatusColumn && $status === 'pending' && hasRole('manager')): ?>
                                                <button class="btn btn-success" onclick="approveCollection(<?php echo $collection['id']; ?>)" title="موافقة">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button class="btn btn-danger" onclick="rejectCollection(<?php echo $collection['id']; ?>)" title="رفض">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-danger" onclick="deleteCollection(<?php echo $collection['id']; ?>)" title="حذف">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
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
                    <a class="page-link" href="?page=collections&p=<?php echo $pageNum - 1; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=collections&p=1<?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=collections&p=<?php echo $i; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=collections&p=<?php echo $totalPages; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=collections&p=<?php echo $pageNum + 1; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة تحصيل -->
<div class="modal fade" id="addCollectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة تحصيل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addCollectionForm">
                <input type="hidden" name="action" value="add_collection">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
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
                        
                        <div class="col-md-6">
                            <label class="form-label">المبلغ <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount" required min="0.01">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">طريقة الدفع</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">نقدي</option>
                                <option value="bank">بنكي</option>
                                <option value="cheque">شيك</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">رقم المرجع</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="رقم المرجع أو رقم الشيك">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="ملاحظات إضافية"></textarea>
                        </div>
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

<!-- Modal تعديل تحصيل -->
<div class="modal fade" id="editCollectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل التحصيل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editCollectionForm">
                <input type="hidden" name="action" value="update_collection">
                <input type="hidden" name="collection_id" id="edit_collection_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">المبلغ <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="edit_amount" required min="0.01">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" id="edit_date" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">طريقة الدفع</label>
                            <select class="form-select" name="payment_method" id="edit_payment_method">
                                <option value="cash">نقدي</option>
                                <option value="bank">بنكي</option>
                                <option value="cheque">شيك</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">رقم المرجع</label>
                            <input type="text" class="form-control" name="reference_number" id="edit_reference_number">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// عرض تفاصيل التحصيل
function viewCollection(id) {
    // TODO: إضافة modal لعرض التفاصيل
    alert('عرض تفاصيل التحصيل #' + id);
}

// تعديل التحصيل
function editCollection(id) {
    fetch('api/get_collection.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const collection = data.collection;
                document.getElementById('edit_collection_id').value = collection.id;
                document.getElementById('edit_amount').value = collection.amount;
                document.getElementById('edit_date').value = collection.date;
                document.getElementById('edit_payment_method').value = collection.payment_method;
                document.getElementById('edit_reference_number').value = collection.reference_number || '';
                document.getElementById('edit_notes').value = collection.notes || '';
                
                const modal = new bootstrap.Modal(document.getElementById('editCollectionModal'));
                modal.show();
            } else {
                alert('خطأ: ' + (data.message || 'فشل تحميل البيانات'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ أثناء تحميل البيانات');
        });
}

// الموافقة على التحصيل
function approveCollection(id) {
    if (!confirm('هل أنت متأكد من الموافقة على هذا التحصيل؟')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="approve_collection">
        <input type="hidden" name="collection_id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// رفض التحصيل
function rejectCollection(id) {
    if (!confirm('هل أنت متأكد من رفض هذا التحصيل؟')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="reject_collection">
        <input type="hidden" name="collection_id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// حذف التحصيل
function deleteCollection(id) {
    if (!confirm('هل أنت متأكد من حذف هذا التحصيل؟ هذا الإجراء لا يمكن التراجع عنه.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_collection">
        <input type="hidden" name="collection_id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

