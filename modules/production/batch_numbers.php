<?php
/**
 * صفحة إدارة أرقام التشغيلة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/batch_numbers.php';
require_once __DIR__ . '/../../includes/simple_barcode.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'product_id' => $_GET['product_id'] ?? '',
    'batch_number' => $_GET['batch_number'] ?? '',
    'production_date' => $_GET['production_date'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_batch') {
        $productId = intval($_POST['product_id'] ?? 0);
        $productionId = !empty($_POST['production_id']) ? intval($_POST['production_id']) : null;
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $honeySupplierId = !empty($_POST['honey_supplier_id']) ? intval($_POST['honey_supplier_id']) : null;
        $packagingSupplierId = !empty($_POST['packaging_supplier_id']) ? intval($_POST['packaging_supplier_id']) : null;
        $quantity = intval($_POST['quantity'] ?? 1);
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        
        // مواد التعبئة
        $packagingMaterials = [];
        if (isset($_POST['packaging_materials']) && is_array($_POST['packaging_materials'])) {
            $packagingMaterials = array_map('intval', $_POST['packaging_materials']);
        }
        
        // العمال الحاضرين
        $workers = [];
        if (isset($_POST['workers']) && is_array($_POST['workers'])) {
            $workers = array_map('intval', $_POST['workers']);
        }
        
        if ($productId <= 0 || $quantity <= 0) {
            $error = 'يجب إدخال المنتج والكمية';
        } else {
            $result = createBatchNumber($productId, $productionId, $productionDate, $honeySupplierId, 
                                      $packagingMaterials, $packagingSupplierId, $workers, $quantity, 
                                      $expiryDate, $notes);
            if ($result['success']) {
                $success = 'تم إنشاء رقم التشغيلة بنجاح: ' . $result['batch_number'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'update_status') {
        $batchId = intval($_POST['batch_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($batchId > 0 && !empty($status)) {
            $result = updateBatchStatus($batchId, $status);
            if ($result['success']) {
                $success = 'تم تحديث حالة رقم التشغيلة بنجاح';
            } else {
                $error = $result['message'];
            }
        }
    }
}

// الحصول على البيانات
$totalBatches = getBatchNumbersCount($filters);
$totalPages = ceil($totalBatches / $perPage);
$batches = getBatchNumbers($filters, $perPage, $offset);

$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' ORDER BY name");
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name");

// التحقق من وجود الأعمدة قبل استخدامها
$typeColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'type'");
$specificationsColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'specifications'");
$hasTypeColumn = !empty($typeColumnCheck);
$hasSpecificationsColumn = !empty($specificationsColumnCheck);

// بناء استعلام مواد التعبئة بشكل ديناميكي
$columns = ['id', 'name'];
$whereConditions = ["(category LIKE '%تغليف%' OR category LIKE '%packaging%'"];

if ($hasTypeColumn) {
    $columns[] = 'type';
    $whereConditions[0] .= " OR type LIKE '%تغليف%'";
}

if ($hasSpecificationsColumn) {
    $columns[] = 'specifications';
}

$whereConditions[0] .= ") AND status = 'active'";

$packagingMaterials = $db->query(
    "SELECT " . implode(', ', $columns) . " FROM products 
     WHERE " . implode(' AND ', $whereConditions) . "
     ORDER BY name"
);
$workers = $db->query("SELECT id, username, full_name FROM users WHERE role = 'production' AND status = 'active' ORDER BY username");

// التحقق من عمود date في جدول production
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck2 = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$productionDateColumn = !empty($productionDateColumnCheck) ? 'date' : (!empty($productionDateColumnCheck2) ? 'production_date' : 'created_at');

$productions = $db->query(
    "SELECT id, $productionDateColumn as date, product_id FROM production 
     WHERE status = 'approved' 
     ORDER BY $productionDateColumn DESC LIMIT 50"
);

// عرض رقم تشغيلة محدد
$selectedBatch = null;
if (isset($_GET['id'])) {
    $selectedBatch = getBatchNumber(intval($_GET['id']));
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-upc-scan me-2"></i>إدارة أرقام التشغيلة</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBatchModal">
        <i class="bi bi-plus-circle me-2"></i>إنشاء رقم تشغيلة
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

<?php if ($selectedBatch): ?>
    <!-- عرض رقم تشغيلة محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">رقم التشغيلة: <?php echo htmlspecialchars($selectedBatch['batch_number']); ?></h5>
            <div>
                <a href="print_barcode.php?batch=<?php echo urlencode($selectedBatch['batch_number']); ?>&quantity=1" 
                   class="btn btn-light btn-sm" target="_blank">
                    <i class="bi bi-printer me-2"></i>طباعة باركود
                </a>
                <a href="?page=batch_numbers" class="btn btn-light btn-sm">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle table-bordered">
                        <tr>
                            <th width="40%">المنتج:</th>
                            <td><?php echo htmlspecialchars($selectedBatch['product_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ الإنتاج:</th>
                            <td><?php echo formatDate($selectedBatch['production_date']); ?></td>
                        </tr>
                        <tr>
                            <th>مورد العسل:</th>
                            <td><?php echo htmlspecialchars($selectedBatch['honey_supplier_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>مورد أدوات التعبئة:</th>
                            <td><?php echo htmlspecialchars($selectedBatch['packaging_supplier_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>الكمية:</th>
                            <td><?php echo $selectedBatch['quantity']; ?> قطعة</td>
                        </tr>
                        <tr>
                            <th>الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedBatch['status'] === 'sold' ? 'success' : 
                                        ($selectedBatch['status'] === 'in_stock' ? 'info' : 
                                        ($selectedBatch['status'] === 'expired' ? 'danger' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'in_production' => 'قيد الإنتاج',
                                        'completed' => 'مكتمل',
                                        'in_stock' => 'في المخزون',
                                        'sold' => 'مباع',
                                        'expired' => 'منتهي الصلاحية'
                                    ];
                                    echo $statuses[$selectedBatch['status']] ?? $selectedBatch['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6>مواد التعبئة المستخدمة:</h6>
                    <?php if (!empty($selectedBatch['packaging_materials_details'])): ?>
                        <ul>
                            <?php foreach ($selectedBatch['packaging_materials_details'] as $material): ?>
                                <li><?php echo htmlspecialchars($material['name'] ?? '-'); ?> 
                                    <?php if (!empty($material['specifications'])): ?>
                                        (<?php echo htmlspecialchars($material['specifications']); ?>)
                                    <?php elseif (!empty($material['type'])): ?>
                                        (<?php echo htmlspecialchars($material['type']); ?>)
                                    <?php elseif (!empty($material['category'])): ?>
                                        (<?php echo htmlspecialchars($material['category']); ?>)
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">لا توجد مواد تعبئة</p>
                    <?php endif; ?>
                    
                    <h6 class="mt-3">العمال الحاضرين:</h6>
                    <?php if (!empty($selectedBatch['workers_details'])): ?>
                        <ul>
                            <?php foreach ($selectedBatch['workers_details'] as $worker): ?>
                                <li><?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">لا يوجد عمال مسجلين</p>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <h6>الباركود:</h6>
                        <div class="border p-3 text-center">
                            <?php echo generateBarcode($selectedBatch['batch_number'], 'barcode'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="batch_numbers">
            <div class="col-md-3">
                <label class="form-label">رقم التشغيلة</label>
                <input type="text" class="form-control" name="batch_number" 
                       value="<?php echo htmlspecialchars($filters['batch_number'] ?? ''); ?>" 
                       placeholder="بحث...">
            </div>
            <div class="col-md-2">
                <label class="form-label">المنتج</label>
                <select class="form-select" name="product_id">
                    <option value="">جميع المنتجات</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedProductId = isset($filters['product_id']) ? intval($filters['product_id']) : 0;
                    $productValid = isValidSelectValue($selectedProductId, $products, 'id');
                    foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>" 
                                <?php echo $productValid && $selectedProductId == $product['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($product['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">تاريخ الإنتاج</label>
                <input type="date" class="form-control" name="production_date" 
                       value="<?php echo htmlspecialchars($filters['production_date'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="in_production" <?php echo ($filters['status'] ?? '') === 'in_production' ? 'selected' : ''; ?>>قيد الإنتاج</option>
                    <option value="completed" <?php echo ($filters['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                    <option value="in_stock" <?php echo ($filters['status'] ?? '') === 'in_stock' ? 'selected' : ''; ?>>في المخزون</option>
                    <option value="sold" <?php echo ($filters['status'] ?? '') === 'sold' ? 'selected' : ''; ?>>مباع</option>
                    <option value="expired" <?php echo ($filters['status'] ?? '') === 'expired' ? 'selected' : ''; ?>>منتهي الصلاحية</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
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

<!-- قائمة أرقام التشغيلة -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة أرقام التشغيلة (<?php echo $totalBatches; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم التشغيلة</th>
                        <th>المنتج</th>
                        <th>تاريخ الإنتاج</th>
                        <th>مورد العسل</th>
                        <th>الكمية</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">لا توجد أرقام تشغيلة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td>
                                    <a href="?page=batch_numbers&id=<?php echo $batch['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($batch['batch_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($batch['product_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($batch['production_date']); ?></td>
                                <td><?php echo htmlspecialchars($batch['honey_supplier_name'] ?? '-'); ?></td>
                                <td><?php echo $batch['quantity']; ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $batch['status'] === 'sold' ? 'success' : 
                                            ($batch['status'] === 'in_stock' ? 'info' : 
                                            ($batch['status'] === 'expired' ? 'danger' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'in_production' => 'قيد الإنتاج',
                                            'completed' => 'مكتمل',
                                            'in_stock' => 'في المخزون',
                                            'sold' => 'مباع',
                                            'expired' => 'منتهي'
                                        ];
                                        echo $statuses[$batch['status']] ?? $batch['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="?page=batch_numbers&id=<?php echo $batch['id']; ?>" 
                                           class="btn btn-info" title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="print_barcode.php?batch=<?php echo urlencode($batch['batch_number']); ?>&quantity=1" 
                                           class="btn btn-secondary" target="_blank" title="طباعة باركود">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                        <button class="btn btn-warning" 
                                                onclick="showBatchPrintModal('<?php echo htmlspecialchars($batch['batch_number']); ?>', <?php echo $batch['quantity']; ?>)"
                                                title="طباعة متعددة">
                                            <i class="bi bi-printer-fill"></i>
                                        </button>
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
                    <a class="page-link" href="?page=batch_numbers&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=batch_numbers&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=batch_numbers&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=batch_numbers&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=batch_numbers&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إنشاء رقم تشغيلة -->
<div class="modal fade" id="addBatchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء رقم تشغيلة جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="batchForm">
                <input type="hidden" name="action" value="create_batch">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">المنتج <span class="text-danger">*</span></label>
                            <select class="form-select" name="product_id" id="batchProduct" required>
                                <option value="">اختر المنتج</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">عملية الإنتاج</label>
                            <select class="form-select" name="production_id">
                                <option value="">اختر عملية إنتاج</option>
                                <?php foreach ($productions as $production): ?>
                                    <option value="<?php echo $production['id']; ?>">
                                        #<?php echo $production['id']; ?> - <?php echo formatDate($production['date']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">تاريخ الإنتاج <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="production_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الكمية <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity" value="1" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تاريخ انتهاء الصلاحية</label>
                            <input type="date" class="form-control" name="expiry_date">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">مورد العسل</label>
                            <select class="form-select" name="honey_supplier_id">
                                <option value="">اختر المورد</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>">
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">مورد أدوات التعبئة</label>
                            <select class="form-select" name="packaging_supplier_id">
                                <option value="">اختر المورد</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>">
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">أدوات التعبئة المستخدمة</label>
                        <select class="form-select" name="packaging_materials[]" multiple size="5">
                            <?php foreach ($packagingMaterials as $material): ?>
                                <option value="<?php echo $material['id']; ?>">
                                    <?php echo htmlspecialchars($material['name']); ?> 
                                    (<?php echo htmlspecialchars($material['specifications'] ?? ''); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للاختيار المتعدد</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">العمال الحاضرين خلال يوم الإنتاج</label>
                        <select class="form-select" name="workers[]" multiple size="5">
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo $worker['id']; ?>">
                                    <?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl للاختيار المتعدد</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal طباعة متعددة -->
<div class="modal fade" id="batchPrintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">طباعة باركود</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="GET" action="print_barcode.php" target="_blank">
                <input type="hidden" name="batch" id="printBatchNumber">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">نوع الطباعة</label>
                        <select class="form-select" name="format" id="printFormat">
                            <option value="single">فردية (1 باركود)</option>
                            <option value="multiple">متعددة (2 باركود في الصفحة)</option>
                            <option value="custom">مخصصة</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="quantity" id="printQuantity" 
                               value="1" min="1" required>
                        <small class="text-muted">عدد الباركودات المراد طباعتها</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">طباعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showBatchPrintModal(batchNumber, quantity) {
    document.getElementById('printBatchNumber').value = batchNumber;
    document.getElementById('printQuantity').value = quantity;
    const modal = new bootstrap.Modal(document.getElementById('batchPrintModal'));
    
    // تحميل JsBarcode إذا لم يكن محملاً
    if (typeof JsBarcode === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js';
        document.head.appendChild(script);
    }
    modal.show();
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

