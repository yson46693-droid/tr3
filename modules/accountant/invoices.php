<?php
/**
 * صفحة إدارة الفواتير للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['accountant', 'sales', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'invoice_number' => $_GET['invoice_number'] ?? ''
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_invoice') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : null;
        $date = $_POST['date'] ?? date('Y-m-d');
        $taxRate = 0; // تم إلغاء الضريبة
        $discountAmount = floatval($_POST['discount_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        // معالجة العناصر
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $items[] = [
                        'product_id' => intval($item['product_id']),
                        'description' => trim($item['description'] ?? ''),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }
        }
        
        if ($customerId <= 0 || empty($items)) {
            $error = 'يجب إدخال العميل وعناصر الفاتورة';
        } else {
            $result = createInvoice($customerId, $salesRepId, $date, $items, $taxRate, $discountAmount, $notes);
            if ($result['success']) {
                $success = 'تم إنشاء الفاتورة بنجاح: ' . $result['invoice_number'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'update_status') {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($invoiceId > 0 && !empty($status)) {
            $result = updateInvoiceStatus($invoiceId, $status);
            if ($result['success']) {
                $success = 'تم تحديث حالة الفاتورة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'record_payment') {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($invoiceId > 0 && $amount > 0) {
            $result = recordInvoicePayment($invoiceId, $amount, $notes);
            if ($result['success']) {
                $success = 'تم تسجيل الدفعة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'delete_invoice') {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        
        if ($invoiceId > 0) {
            $result = deleteInvoice($invoiceId);
            if ($result['success']) {
                $success = 'تم حذف الفاتورة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    }
}

// الحصول على البيانات
$totalInvoices = getInvoicesCount($filters);
$totalPages = ceil($totalInvoices / $perPage);
$invoices = getInvoices($filters, $perPage, $offset);

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// التحقق من وجود عمود unit قبل الاستعلام
$unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
$hasUnitColumn = !empty($unitColumnCheck);

if ($hasUnitColumn) {
    $products = $db->query("SELECT id, name, unit_price, unit FROM products WHERE status = 'active' ORDER BY name");
} else {
    // إضافة العمود إذا لم يكن موجوداً
    try {
        $db->execute("ALTER TABLE products ADD COLUMN unit VARCHAR(20) DEFAULT 'piece' AFTER quantity");
        $hasUnitColumn = true;
        // تحديث البيانات الموجودة
        $db->execute("UPDATE products SET unit = 'piece' WHERE unit IS NULL OR unit = ''");
    } catch (Exception $e) {
        error_log("Error adding unit column: " . $e->getMessage());
    }
    // استعلام بدون unit
    $products = $db->query("SELECT id, name, unit_price FROM products WHERE status = 'active' ORDER BY name");
    // إضافة unit افتراضي للمنتجات
    foreach ($products as &$product) {
        $product['unit'] = 'piece';
    }
}

$salesReps = $db->query("SELECT id, username, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY username");

// فاتورة محددة للعرض
$selectedInvoice = null;
if (isset($_GET['id'])) {
    $selectedInvoice = getInvoice(intval($_GET['id']));
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-receipt me-2"></i>إدارة الفواتير</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
        <i class="bi bi-plus-circle me-2"></i>إنشاء فاتورة
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($selectedInvoice && !isset($_GET['print'])): ?>
    <!-- عرض فاتورة محددة -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">فاتورة رقم: <?php echo htmlspecialchars($selectedInvoice['invoice_number']); ?></h5>
            <div>
                <a href="<?php echo getRelativeUrl('print_invoice.php?id=' . $selectedInvoice['id'] . '&print=1'); ?>" 
                   class="btn btn-light btn-sm" target="_blank">
                    <i class="bi bi-printer me-2"></i>طباعة
                </a>
                <a href="?page=invoices" class="btn btn-light btn-sm">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php 
            $selectedInvoice = $selectedInvoice; // متغير للاستخدام في invoice_print.php
            include __DIR__ . '/invoice_print.php'; 
            ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="invoices">
            <div class="col-md-3">
                <label class="form-label">رقم الفاتورة</label>
                <input type="text" class="form-control" name="invoice_number" 
                       value="<?php echo htmlspecialchars($filters['invoice_number'] ?? ''); ?>" 
                       placeholder="INV-...">
            </div>
            <div class="col-md-2">
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
                    <option value="draft" <?php echo ($filters['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>مسودة</option>
                    <option value="sent" <?php echo ($filters['status'] ?? '') === 'sent' ? 'selected' : ''; ?>>مرسلة</option>
                    <option value="paid" <?php echo ($filters['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>مدفوعة</option>
                    <option value="cancelled" <?php echo ($filters['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                    <option value="overdue" <?php echo ($filters['status'] ?? '') === 'overdue' ? 'selected' : ''; ?>>متأخرة</option>
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

<!-- قائمة الفواتير -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة الفواتير (<?php echo $totalInvoices; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>العميل</th>
                        <th>التاريخ</th>
                        <th>المبلغ الإجمالي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد فواتير</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                            <?php
                            $remaining = $invoice['total_amount'] - $invoice['paid_amount'];
                            ?>
                            <tr>
                                <td>
                                    <a href="?page=invoices&id=<?php echo $invoice['id']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($invoice['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($invoice['date']); ?></td>
                                <td><?php echo formatCurrency($invoice['total_amount']); ?></td>
                                <td><?php echo formatCurrency($invoice['paid_amount']); ?></td>
                                <td>
                                    <span class="<?php echo $remaining > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo formatCurrency($remaining); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $invoice['status'] === 'paid' ? 'success' : 
                                            ($invoice['status'] === 'partial' ? 'warning' :
                                            ($invoice['status'] === 'sent' ? 'info' : 
                                            ($invoice['status'] === 'cancelled' ? 'danger' : 
                                            ($invoice['status'] === 'overdue' ? 'warning' : 'secondary')))); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'draft' => 'مسودة',
                                            'sent' => 'مرسلة',
                                            'partial' => 'مدفوع جزئياً',
                                            'paid' => 'مدفوعة',
                                            'cancelled' => 'ملغاة',
                                            'overdue' => 'متأخرة'
                                        ];
                                        echo $statuses[$invoice['status']] ?? $invoice['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="?page=invoices&id=<?php echo $invoice['id']; ?>" 
                                           class="btn btn-info" title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?php echo getRelativeUrl('print_invoice.php?id=' . $invoice['id'] . '&print=1'); ?>" 
                                           class="btn btn-secondary" target="_blank" title="طباعة">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                        <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'cancelled'): ?>
                                        <button class="btn btn-warning" 
                                                onclick="showStatusModal(<?php echo $invoice['id']; ?>, '<?php echo $invoice['status']; ?>')"
                                                title="تغيير الحالة">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-success" 
                                                onclick="showPaymentModal(<?php echo $invoice['id']; ?>, <?php echo $remaining; ?>)"
                                                title="تسجيل دفعة">
                                            <i class="bi bi-cash"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($invoice['status'] === 'draft' && hasRole('manager')): ?>
                                        <button class="btn btn-danger" 
                                                onclick="deleteInvoiceConfirm(<?php echo $invoice['id']; ?>)"
                                                title="حذف">
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
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=invoices&p=<?php echo $page - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=invoices&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=invoices&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=invoices&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=invoices&p=<?php echo $page + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إنشاء فاتورة -->
<div class="modal fade" id="addInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء فاتورة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="invoiceForm">
                <input type="hidden" name="action" value="create_invoice">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">العميل <span class="text-danger">*</span></label>
                            <select class="form-select" name="customer_id" id="invoiceCustomer" required>
                                <option value="">اختر العميل</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">مندوب المبيعات</label>
                            <select class="form-select" name="sales_rep_id">
                                <option value="">اختر مندوب</option>
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>">
                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">خصم (ج.م)</label>
                            <input type="number" step="0.01" class="form-control" name="discount_amount" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الفاتورة</label>
                        <div id="invoiceItems">
                            <div class="invoice-item row mb-2">
                                <div class="col-md-4">
                                    <select class="form-select product-select" name="items[0][product_id]" required>
                                        <option value="">اختر المنتج</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>" 
                                                    data-price="<?php echo $product['unit_price']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?> 
                                                (<?php echo formatCurrency($product['unit_price']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control" name="items[0][description]" 
                                           placeholder="الوصف (اختياري)">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control unit-price" 
                                           name="items[0][unit_price]" placeholder="السعر" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <div class="d-flex">
                                        <input type="text" class="form-control item-total" readonly 
                                               placeholder="الإجمالي">
                                        <button type="button" class="btn btn-danger ms-2 remove-item">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>المجموع الفرعي:</strong>
                                        <span id="subtotal">0.00 ج.م</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>الخصم:</strong>
                                        <span id="discountDisplay">0.00 ج.م</span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <h5>الإجمالي:</h5>
                                        <h5 id="totalAmount">0.00 ج.م</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء فاتورة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تغيير الحالة -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغيير حالة الفاتورة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="invoice_id" id="statusInvoiceId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status" id="statusSelect" required>
                            <option value="draft">مسودة</option>
                            <option value="sent">مرسلة</option>
                            <option value="paid">مدفوعة</option>
                            <option value="cancelled">ملغاة</option>
                        </select>
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

<!-- Modal تسجيل دفعة -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تسجيل دفعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المبلغ المتبقي</label>
                        <input type="text" class="form-control" id="remainingAmount" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ المدفوع <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="amount" 
                               id="paymentAmount" required min="0.01">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">تسجيل الدفعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;

// إضافة عنصر جديد
document.getElementById('addItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('invoiceItems');
    const newItem = document.createElement('div');
    newItem.className = 'invoice-item row mb-2';
    newItem.innerHTML = `
        <div class="col-md-4">
            <select class="form-select product-select" name="items[${itemIndex}][product_id]" required>
                <option value="">اختر المنتج</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo $product['id']; ?>" 
                            data-price="<?php echo $product['unit_price']; ?>">
                        <?php echo htmlspecialchars($product['name']); ?> 
                        (<?php echo formatCurrency($product['unit_price']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control" name="items[${itemIndex}][description]" 
                   placeholder="الوصف (اختياري)">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" class="form-control quantity" 
                   name="items[${itemIndex}][quantity]" placeholder="الكمية" required min="0.01">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" class="form-control unit-price" 
                   name="items[${itemIndex}][unit_price]" placeholder="السعر" required min="0.01">
        </div>
        <div class="col-md-2">
            <div class="d-flex">
                <input type="text" class="form-control item-total" readonly placeholder="الإجمالي">
                <button type="button" class="btn btn-danger ms-2 remove-item">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    itemIndex++;
    attachItemEvents(newItem);
});

// حذف عنصر
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        e.target.closest('.invoice-item').remove();
        calculateTotal();
    }
});

// ربط أحداث العناصر
function attachItemEvents(item) {
    const productSelect = item.querySelector('.product-select');
    const quantityInput = item.querySelector('.quantity');
    const unitPriceInput = item.querySelector('.unit-price');
    const itemTotal = item.querySelector('.item-total');
    
    // تحديث السعر عند اختيار المنتج
    productSelect?.addEventListener('change', function() {
        const price = this.options[this.selectedIndex].dataset.price;
        if (price) {
            unitPriceInput.value = price;
            calculateItemTotal(item);
            calculateTotal();
        }
    });
    
    // حساب إجمالي العنصر
    [quantityInput, unitPriceInput].forEach(input => {
        input?.addEventListener('input', function() {
            calculateItemTotal(item);
            calculateTotal();
        });
    });
}

// حساب إجمالي العنصر
function calculateItemTotal(item) {
    const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    item.querySelector('.item-total').value = total.toFixed(2);
}

// حساب الإجمالي الكامل
function calculateTotal() {
    const form = document.getElementById('invoiceForm');
    if (!form) return;
    
    let subtotal = 0;
    document.querySelectorAll('.item-total').forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    
    const discountAmount = parseFloat(form.querySelector('[name="discount_amount"]')?.value) || 0;
    
    const total = subtotal - discountAmount;
    
    const subtotalEl = document.getElementById('subtotal');
    const discountDisplayEl = document.getElementById('discountDisplay');
    const totalAmountEl = document.getElementById('totalAmount');
    
    if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2) + ' ج.م';
    if (discountDisplayEl) discountDisplayEl.textContent = discountAmount.toFixed(2) + ' ج.م';
    if (totalAmountEl) totalAmountEl.textContent = total.toFixed(2) + ' ج.م';
}

// ربط الأحداث للعناصر الموجودة
document.querySelectorAll('.invoice-item').forEach(item => {
    attachItemEvents(item);
});

// ربط أحداث الخصم
document.getElementById('invoiceForm')?.querySelectorAll('[name="discount_amount"]').forEach(input => {
    input.addEventListener('input', calculateTotal);
});

// عرض Modal تغيير الحالة
function showStatusModal(invoiceId, currentStatus) {
    document.getElementById('statusInvoiceId').value = invoiceId;
    document.getElementById('statusSelect').value = currentStatus;
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

// عرض Modal تسجيل دفعة
function showPaymentModal(invoiceId, remaining) {
    document.getElementById('paymentInvoiceId').value = invoiceId;
    document.getElementById('remainingAmount').value = remaining.toFixed(2) + ' ج.م';
    document.getElementById('paymentAmount').value = remaining.toFixed(2);
    document.getElementById('paymentAmount').max = remaining;
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// تأكيد حذف فاتورة
function deleteInvoiceConfirm(invoiceId) {
    if (confirm('هل أنت متأكد من حذف هذه الفاتورة؟\n\nهذه العملية لا يمكن التراجع عنها.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_invoice">
            <input type="hidden" name="invoice_id" value="${invoiceId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>

