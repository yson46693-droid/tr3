<?php
/**
 * صفحة إدارة مخازن سيارات المندوبين
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/vehicle_inventory.php';
require_once __DIR__ . '/../../includes/audit_log.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'production', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';
$canManageVehicles = ($currentUser['role'] ?? '') === 'manager';
$salesReps = [];

$currentPageSlug = $_GET['page'] ?? 'vehicle_inventory';
$currentSection = $_GET['section'] ?? null;
$baseQueryString = '?page=' . urlencode($currentPageSlug);
if ($currentSection !== null && $currentSection !== '') {
    $baseQueryString .= '&section=' . urlencode($currentSection);
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'vehicle_id' => $_GET['vehicle_id'] ?? '',
    'product_id' => $_GET['product_id'] ?? '',
    'product_name' => $_GET['product_name'] ?? ''
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// إذا كان المستخدم مندوب مبيعات، عرض فقط سيارته
if ($currentUser['role'] === 'sales') {
    $userVehicle = $db->queryOne("SELECT id FROM vehicles WHERE driver_id = ?", [$currentUser['id']]);
    if ($userVehicle) {
        $filters['vehicle_id'] = $userVehicle['id'];
    }
}

// معالجة طلبات AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_products') {
    // تنظيف أي output buffer موجود
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
    
    try {
        // التأكد من تحميل الدوال المطلوبة
        if (!function_exists('getFinishedProductBatchOptions')) {
            require_once __DIR__ . '/../../includes/vehicle_inventory.php';
        }
        
        $products = getFinishedProductBatchOptions(true, $warehouseId);
        echo json_encode([
            'success' => true,
            'products' => $products
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_transfer') {
        $fromWarehouseId = intval($_POST['from_warehouse_id'] ?? 0);
        $toWarehouseId = intval($_POST['to_warehouse_id'] ?? 0);
        $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // معالجة العناصر
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $productId = !empty($item['product_id']) ? intval($item['product_id']) : 0;
                $batchId = !empty($item['batch_id']) ? intval($item['batch_id']) : 0;
                $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0;

                if (($productId > 0 || $batchId > 0) && $quantity > 0) {
                    $items[] = [
                        'product_id' => $productId > 0 ? $productId : null,
                        'batch_id' => $batchId > 0 ? $batchId : null,
                        'batch_number' => !empty($item['batch_number']) ? trim($item['batch_number']) : null,
                        'quantity' => $quantity,
                        'notes' => trim($item['notes'] ?? '')
                    ];
                }
            }
        }
        
        if ($fromWarehouseId <= 0 || $toWarehouseId <= 0 || empty($items)) {
            $error = 'يجب إدخال جميع البيانات المطلوبة';
        } else {
            $result = createWarehouseTransfer($fromWarehouseId, $toWarehouseId, $transferDate, $items, $reason, $notes);
            if ($result['success']) {
                $success = 'تم إنشاء طلب النقل بنجاح: ' . $result['transfer_number'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'create_vehicle') {
        if (!$canManageVehicles) {
            $error = 'غير مصرح لك بإضافة سيارات جديدة.';
        } else {
            $vehicleNumber = trim($_POST['vehicle_number'] ?? '');
            $vehicleType = trim($_POST['vehicle_type'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $year = !empty($_POST['year']) ? intval($_POST['year']) : null;
            $driverId = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
            $statusValue = $_POST['status'] ?? 'active';
            $notes = trim($_POST['notes'] ?? '');

            if ($vehicleNumber === '') {
                $error = 'يجب إدخال رقم السيارة';
            } else {
                $existingVehicle = $db->queryOne("SELECT id FROM vehicles WHERE vehicle_number = ?", [$vehicleNumber]);
                if ($existingVehicle) {
                    $error = 'رقم السيارة موجود بالفعل';
                } else {
                    $db->execute(
                        "INSERT INTO vehicles (vehicle_number, vehicle_type, model, year, driver_id, status, notes, created_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $vehicleNumber,
                            $vehicleType,
                            $model,
                            $year,
                            $driverId,
                            $statusValue,
                            $notes,
                            $currentUser['id']
                        ]
                    );

                    $vehicleId = $db->getLastInsertId();
                    createVehicleWarehouse($vehicleId);

                    logAudit($currentUser['id'], 'create_vehicle', 'vehicle', $vehicleId, null, [
                        'vehicle_number' => $vehicleNumber
                    ]);

                    $success = 'تم إنشاء السيارة بنجاح';
                }
            }
        }
    }
}

// الحصول على السيارات
$vehicles = getVehicles(['status' => 'active']);

if ($canManageVehicles) {
    $salesReps = $db->query("SELECT id, username, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY username");
}

// الحصول على المخازن
$warehouses = $db->query("SELECT id, name, warehouse_type FROM warehouses WHERE status = 'active' ORDER BY name");
$mainWarehouses = $db->query("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' ORDER BY name");
$vehicleWarehouses = $db->query("SELECT id, name, vehicle_id FROM warehouses WHERE warehouse_type = 'vehicle' AND status = 'active' ORDER BY name");

// الحصول على المنتجات
$products = $db->query("SELECT id, name, quantity, unit_price FROM products WHERE status = 'active' ORDER BY name");

// مخزن سيارة محدد
$selectedVehicle = null;
$vehicleInventory = [];
$selectedWarehouseId = null;

if (isset($_GET['vehicle_id']) || !empty($filters['vehicle_id'])) {
    $vehicleId = intval($_GET['vehicle_id'] ?? $filters['vehicle_id']);
    $selectedVehicle = $db->queryOne(
        "SELECT v.*, u.full_name as driver_name, u.username as driver_username,
                w.id as warehouse_id, w.name as warehouse_name
         FROM vehicles v
         LEFT JOIN users u ON v.driver_id = u.id
         LEFT JOIN warehouses w ON w.vehicle_id = v.id AND w.warehouse_type = 'vehicle'
         WHERE v.id = ?",
        [$vehicleId]
    );
    
    if ($selectedVehicle) {
        // إنشاء مخزن السيارة إذا لم يكن موجوداً
        if (!$selectedVehicle['warehouse_id']) {
            $result = createVehicleWarehouse($vehicleId);
            if ($result['success']) {
                $selectedVehicle['warehouse_id'] = $result['warehouse_id'];
            }
        }
        
        // تحديد المخزن المحدد لتحميل المنتجات منه
        if (!empty($selectedVehicle['warehouse_id'])) {
            $selectedWarehouseId = (int)$selectedVehicle['warehouse_id'];
        }
        
        // الحصول على مخزون السيارة
        $vehicleInventory = getVehicleInventory($vehicleId, $filters);
    }
}

// تحميل المنتجات من المخزن المحدد (أو المخزن الرئيسي إذا لم يكن هناك مخزن محدد)
$finishedProductOptions = getFinishedProductBatchOptions(true, $selectedWarehouseId);

// إحصائيات المخزون
$inventoryStats = [
    'total_products' => count($vehicleInventory),
    'total_quantity' => 0,
    'total_value' => 0
];

foreach ($vehicleInventory as $item) {
    $inventoryStats['total_quantity'] += floatval($item['quantity'] ?? 0);
    $inventoryStats['total_value'] += floatval($item['total_value'] ?? 0);
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-truck me-2"></i>مخازن سيارات المندوبين</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canManageVehicles): ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
            <i class="bi bi-plus-circle me-2"></i>إضافة سيارة جديدة
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTransferModal">
            <i class="bi bi-arrow-left-right me-2"></i>طلب نقل منتجات
        </button>
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

<?php if ($selectedVehicle): ?>
    <!-- عرض مخزن سيارة محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-truck me-2"></i>
                مخزن سيارة: <?php echo htmlspecialchars($selectedVehicle['vehicle_number']); ?>
            </h5>
            <a href="<?php echo $baseQueryString; ?>" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-12 col-md-6 mb-3 mb-md-0">
                    <table class="table table-no-hover dashboard-table-details">
                        <tr>
                            <th width="40%">رقم السيارة:</th>
                            <td><?php echo htmlspecialchars($selectedVehicle['vehicle_number']); ?></td>
                        </tr>
                        <tr>
                            <th>الموديل:</th>
                            <td><?php echo htmlspecialchars($selectedVehicle['model'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>المندوب:</th>
                            <td><?php echo htmlspecialchars($selectedVehicle['driver_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedVehicle['status'] === 'active' ? 'success' : 
                                        ($selectedVehicle['status'] === 'maintenance' ? 'warning' : 'secondary'); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'active' => 'نشطة',
                                        'inactive' => 'غير نشطة',
                                        'maintenance' => 'صيانة'
                                    ];
                                    echo $statuses[$selectedVehicle['status']] ?? $selectedVehicle['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card bg-light h-100">
                        <div class="card-body">
                            <h6 class="mb-3">إحصائيات المخزون</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>عدد المنتجات:</span>
                                <strong><?php echo $inventoryStats['total_products']; ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>إجمالي الكمية:</span>
                                <strong><?php echo number_format($inventoryStats['total_quantity'], 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>القيمة الإجمالية:</span>
                                <strong class="text-success"><?php echo formatCurrency($inventoryStats['total_value']); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- البحث في مخزون السيارة -->
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 g-md-3">
                        <input type="hidden" name="page" value="<?php echo htmlspecialchars($currentPageSlug); ?>">
                        <?php if ($currentSection !== null && $currentSection !== ''): ?>
                            <input type="hidden" name="section" value="<?php echo htmlspecialchars($currentSection); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="vehicle_id" value="<?php echo $selectedVehicle['id']; ?>">
                        <div class="col-12 col-md-4">
                            <label class="form-label">اسم المنتج</label>
                            <input type="text" class="form-control form-control-sm" name="product_name" 
                                   value="<?php echo htmlspecialchars($filters['product_name'] ?? ''); ?>" 
                                   placeholder="بحث...">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label d-none d-md-block">&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search me-1 me-md-2"></i><span class="d-none d-sm-inline">بحث</span>
                            </button>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label d-none d-md-block">&nbsp;</label>
                            <a href="<?php echo $baseQueryString; ?>&vehicle_id=<?php echo $selectedVehicle['id']; ?>" class="btn btn-secondary btn-sm w-100">
                                <i class="bi bi-arrow-clockwise me-1 me-md-2"></i><span class="d-none d-sm-inline">إعادة تعيين</span>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- قائمة مخزون السيارة -->
            <div class="table-responsive dashboard-table-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="table table-no-hover dashboard-table align-middle" style="min-width: 700px; font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th style="min-width: 150px; white-space: nowrap;">المنتج</th>
                            <th style="min-width: 120px; white-space: nowrap;">رقم التشغيلة</th>
                            <th style="min-width: 110px; white-space: nowrap;">تاريخ الإنتاج</th>
                            <th style="min-width: 80px; white-space: nowrap; text-align: center;">الكمية</th>
                            <th style="min-width: 100px; white-space: nowrap; text-align: center;">سعر الوحدة</th>
                            <th style="min-width: 120px; white-space: nowrap; text-align: center;">القيمة الإجمالية</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehicleInventory)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">لا توجد منتجات في مخزن هذه السيارة</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vehicleInventory as $item): ?>
                                <tr>
                                    <td style="min-width: 150px;">
                                        <strong style="word-wrap: break-word; word-break: break-word;"><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></strong>
                                    </td>
                                    <td style="min-width: 120px; white-space: nowrap;"><?php echo htmlspecialchars($item['finished_batch_number'] ?? '—'); ?></td>
                                    <td style="min-width: 110px; white-space: nowrap;"><?php echo !empty($item['finished_production_date']) ? htmlspecialchars(formatDate($item['finished_production_date'])) : '—'; ?></td>
                                    <td style="min-width: 80px; white-space: nowrap; text-align: center;"><strong><?php echo number_format($item['quantity'], 2); ?></strong></td>
                                    <td style="min-width: 100px; white-space: nowrap; text-align: center;">
                                        <?php
                                        // حساب سعر الوحدة بناءً على total_value / quantity إذا كان total_value متوفراً
                                        // أو استخدام unit_price المحسوب
                                        $unitPrice = 0;
                                        if (!empty($item['total_value']) && !empty($item['quantity']) && floatval($item['quantity']) > 0) {
                                            // إذا كان total_value صحيحاً، احسب unit_price منه
                                            $unitPrice = floatval($item['total_value']) / floatval($item['quantity']);
                                        } elseif (!empty($item['unit_price']) && floatval($item['unit_price']) > 0) {
                                            // استخدام unit_price المحسوب من الاستعلام
                                            $unitPrice = floatval($item['unit_price']);
                                        } elseif (!empty($item['fp_unit_price']) && floatval($item['fp_unit_price']) > 0) {
                                            // استخدام unit_price من finished_products
                                            $unitPrice = floatval($item['fp_unit_price']);
                                        } elseif (!empty($item['fp_unit_price']) && !empty($item['fp_total_price']) && !empty($item['fp_quantity_produced']) && floatval($item['fp_quantity_produced']) > 0) {
                                            // حساب من finished_products إذا كان متوفراً
                                            $unitPrice = floatval($item['fp_total_price']) / floatval($item['fp_quantity_produced']);
                                        }
                                        echo formatCurrency($unitPrice);
                                        ?>
                                    </td>
                                    <td style="min-width: 120px; white-space: nowrap; text-align: center;"><strong><?php echo formatCurrency($item['total_value'] ?? 0); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <style>
                @media (max-width: 768px) {
                    .dashboard-table-wrapper {
                        -webkit-overflow-scrolling: touch;
                        overflow-x: auto;
                        overflow-y: hidden;
                        border: 1px solid #dee2e6;
                        border-radius: 0.25rem;
                        margin: 0 -0.75rem;
                        padding: 0.5rem 0.25rem;
                    }
                    
                    .dashboard-table {
                        font-size: 0.85rem;
                        margin-bottom: 0;
                        width: 100%;
                    }
                    
                    .dashboard-table th,
                    .dashboard-table td {
                        padding: 0.5rem 0.4rem;
                        vertical-align: middle;
                    }
                    
                    .dashboard-table th {
                        font-size: 0.8rem;
                        font-weight: 600;
                        background-color: #f8f9fa;
                        position: sticky;
                        top: 0;
                        z-index: 10;
                    }
                    
                    .dashboard-table td strong {
                        font-size: 0.9rem;
                    }
                    
                    .card-body {
                        padding: 1rem;
                    }
                    
                    .dashboard-table-details {
                        font-size: 0.9rem;
                    }
                    
                    .dashboard-table-details th {
                        width: auto;
                        min-width: 100px;
                        font-size: 0.85rem;
                    }
                    
                    .dashboard-table-details td {
                        font-size: 0.9rem;
                    }
                    
                    .card-header h5 {
                        font-size: 1rem;
                    }
                    
                    .card-header .btn {
                        padding: 0.25rem 0.5rem;
                        font-size: 0.875rem;
                    }
                    
                    .card.bg-light .card-body h6 {
                        font-size: 0.95rem;
                        margin-bottom: 0.75rem;
                    }
                    
                    .card.bg-light .card-body span,
                    .card.bg-light .card-body strong {
                        font-size: 0.9rem;
                    }
                }
                
                @media (max-width: 576px) {
                    .dashboard-table-wrapper {
                        margin: 0 -0.5rem;
                        padding: 0.25rem 0.15rem;
                    }
                    
                    .dashboard-table {
                        font-size: 0.75rem;
                        min-width: 650px;
                    }
                    
                    .dashboard-table th,
                    .dashboard-table td {
                        padding: 0.4rem 0.3rem;
                    }
                    
                    .dashboard-table th {
                        font-size: 0.75rem;
                    }
                    
                    .dashboard-table td strong {
                        font-size: 0.85rem;
                    }
                    
                    .card-body {
                        padding: 0.75rem;
                    }
                    
                    .dashboard-table-details {
                        font-size: 0.85rem;
                    }
                    
                    .dashboard-table-details th {
                        font-size: 0.8rem;
                        min-width: 90px;
                    }
                    
                    .dashboard-table-details td {
                        font-size: 0.85rem;
                    }
                    
                    .card.bg-light .card-body {
                        padding: 0.75rem;
                    }
                    
                    .card.bg-light .card-body h6 {
                        font-size: 0.9rem;
                        margin-bottom: 0.5rem;
                    }
                    
                    .card.bg-light .card-body span,
                    .card.bg-light .card-body strong {
                        font-size: 0.85rem;
                    }
                    
                    .row.mb-3 {
                        margin-bottom: 1rem !important;
                    }
                    
                    .col-md-6 {
                        margin-bottom: 1rem;
                    }
                    
                    .form-label {
                        font-size: 0.875rem;
                    }
                    
                    .form-control-sm {
                        font-size: 0.875rem;
                        padding: 0.375rem 0.5rem;
                    }
                    
                    .btn-sm {
                        font-size: 0.8rem;
                        padding: 0.375rem 0.75rem;
                    }
                }
            </style>
        </div>
    </div>
<?php endif; ?>

<?php if (($currentUser['role'] ?? '') !== 'sales'): ?>
<!-- قائمة السيارات -->
<div class="card shadow-sm">
    <div class="card-body">
        <div class="row">
            <?php if (empty($vehicles)): ?>
                <div class="col-12">
                    <p class="text-center text-muted">لا توجد سيارات مسجلة</p>
                </div>
            <?php else: ?>
                <?php foreach ($vehicles as $vehicle): ?>
                    <div class="col-md-4 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-truck me-2"></i>
                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                </h6>
                                <p class="card-text">
                                    <small class="text-muted">
                                        <i class="bi bi-person me-1"></i>
                                        <?php echo htmlspecialchars($vehicle['driver_name'] ?? 'لا يوجد مندوب'); ?>
                                    </small>
                                </p>
                                <?php if ($vehicle['model']): ?>
                                <p class="card-text">
                                    <small class="text-muted">
                                        <i class="bi bi-car-front me-1"></i>
                                        <?php echo htmlspecialchars($vehicle['model']); ?>
                                    </small>
                                </p>
                                <?php endif; ?>
                                <a href="<?php echo $baseQueryString; ?>&vehicle_id=<?php echo $vehicle['id']; ?>" 
                                   class="btn btn-primary btn-sm w-100">
                                    <i class="bi bi-box-seam me-2"></i>عرض المخزون
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageVehicles): ?>
<!-- Modal إضافة سيارة جديدة -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة سيارة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_vehicle">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">رقم السيارة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع السيارة</label>
                        <input type="text" class="form-control" name="vehicle_type" placeholder="مثال: شاحنة">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الموديل</label>
                        <input type="text" class="form-control" name="model" placeholder="مثال: تويوتا هايلكس">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">السنة</label>
                        <input type="number" class="form-control" name="year" 
                               min="2000" max="<?php echo date('Y'); ?>" 
                               placeholder="<?php echo date('Y'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المندوب (السائق)</label>
                        <select class="form-select" name="driver_id">
                            <option value="">لا يوجد</option>
                            <?php foreach ($salesReps as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>">
                                    <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status">
                            <option value="active">نشطة</option>
                            <option value="inactive">غير نشطة</option>
                            <option value="maintenance">صيانة</option>
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

<!-- Modal إنشاء طلب نقل -->
<div class="modal fade" id="createTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">طلب نقل منتجات بين المخازن</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transferForm">
                <input type="hidden" name="action" value="create_transfer">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">من المخزن <span class="text-danger">*</span></label>
                            <select class="form-select" name="from_warehouse_id" id="fromWarehouse" required>
                                <option value="">اختر المخزن المصدر</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?php echo $warehouse['id']; ?>" 
                                            data-type="<?php echo $warehouse['warehouse_type']; ?>">
                                        <?php echo htmlspecialchars($warehouse['name']); ?> 
                                        (<?php echo $warehouse['warehouse_type'] === 'main' ? 'رئيسي' : 'سيارة'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">إلى المخزن <span class="text-danger">*</span></label>
                            <select class="form-select" name="to_warehouse_id" id="toWarehouse" required>
                                <option value="">اختر المخزن الوجهة</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?php echo $warehouse['id']; ?>" 
                                            data-type="<?php echo $warehouse['warehouse_type']; ?>">
                                        <?php echo htmlspecialchars($warehouse['name']); ?> 
                                        (<?php echo $warehouse['warehouse_type'] === 'main' ? 'رئيسي' : 'سيارة'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">تاريخ النقل <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transfer_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">السبب</label>
                            <input type="text" class="form-control" name="reason" 
                                   placeholder="مثال: تعبئة سيارة المندوب">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر النقل</label>
                        <?php if (empty($finishedProductOptions)): ?>
                            <div class="alert alert-warning d-flex align-items-center gap-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>لا توجد تشغيلات جاهزة للنقل من المخزن الرئيسي حالياً.</div>
                            </div>
                        <?php endif; ?>
                        <div id="transferItems">
                            <div class="transfer-item row mb-2">
                                <div class="col-md-5">
                                    <select class="form-select product-select" required>
                                        <option value="">اختر المنتج</option>
                                        <?php foreach ($finishedProductOptions as $option): ?>
                                            <option value="<?php echo intval($option['product_id'] ?? 0); ?>" 
                                                    data-product-id="<?php echo intval($option['product_id'] ?? 0); ?>"
                                                    data-batch-id="<?php echo intval($option['batch_id']); ?>"
                                                    data-batch-number="<?php echo htmlspecialchars($option['batch_number']); ?>"
                                                    data-available="<?php echo number_format((float)$option['quantity_available'], 2, '.', ''); ?>">
                                                <?php echo htmlspecialchars($option['product_name']); ?>
                                                - تشغيلة <?php echo htmlspecialchars($option['batch_number'] ?: 'بدون'); ?>
                                                (متاح: <?php echo number_format((float)$option['quantity_available'], 2); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" step="0.01" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required min="0.01">
                                </div>
                                <div class="col-md-3">
                                    <input type="text" class="form-control" 
                                           name="items[0][notes]" placeholder="ملاحظات">
                                </div>
                                <div class="col-12">
                                    <small class="text-muted available-hint d-block"></small>
                                    <input type="hidden" name="items[0][product_id]" class="selected-product-id">
                                    <input type="hidden" name="items[0][batch_id]" class="selected-batch-id">
                                    <input type="hidden" name="items[0][batch_number]" class="selected-batch-number">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger remove-item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn" <?php echo empty($finishedProductOptions) ? 'disabled' : ''; ?>>
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        سيتم إرسال طلب النقل للمدير للموافقة عليه قبل التنفيذ
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" <?php echo empty($finishedProductOptions) ? 'disabled' : ''; ?>>إنشاء الطلب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;
let allFinishedProductOptions = <?php echo json_encode($finishedProductOptions); ?>;

// تحميل المنتجات عند تغيير المخزن المصدر
document.getElementById('fromWarehouse')?.addEventListener('change', function() {
    const fromWarehouseId = this.value;
    if (!fromWarehouseId) {
        allFinishedProductOptions = [];
        updateProductSelects();
        return;
    }
    
    // إظهار مؤشر التحميل
    const addItemBtn = document.getElementById('addItemBtn');
    const submitBtn = document.querySelector('#transferForm button[type="submit"]');
    const originalAddBtnText = addItemBtn?.innerHTML;
    const originalSubmitBtnText = submitBtn?.innerHTML;
    
    if (addItemBtn) {
        addItemBtn.disabled = true;
        addItemBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>جاري التحميل...';
    }
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>جاري التحميل...';
    }
    
    // تحميل المنتجات من المخزن المحدد عبر AJAX
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax', 'load_products');
    currentUrl.searchParams.set('warehouse_id', fromWarehouseId);
    
    fetch(currentUrl.toString())
        .then(response => {
            // التحقق من نوع المحتوى
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error('Expected JSON but got: ' + text.substring(0, 100));
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.products) {
                allFinishedProductOptions = data.products;
                updateProductSelects();
                
                // تحديث حالة الأزرار
                if (addItemBtn) {
                    addItemBtn.disabled = allFinishedProductOptions.length === 0;
                    addItemBtn.innerHTML = originalAddBtnText || '<i class="bi bi-plus-circle me-2"></i>إضافة عنصر';
                }
                if (submitBtn) {
                    submitBtn.disabled = allFinishedProductOptions.length === 0;
                    submitBtn.innerHTML = originalSubmitBtnText || 'إنشاء الطلب';
                }
                
                // إظهار رسالة إذا لم توجد منتجات
                if (allFinishedProductOptions.length === 0) {
                    const alertDiv = document.querySelector('#transferItems .alert-warning');
                    if (!alertDiv) {
                        const itemsDiv = document.getElementById('transferItems');
                        if (itemsDiv) {
                            const warning = document.createElement('div');
                            warning.className = 'alert alert-warning d-flex align-items-center gap-2';
                            warning.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i><div>لا توجد منتجات متاحة في هذا المخزن حالياً.</div>';
                            itemsDiv.insertBefore(warning, itemsDiv.firstChild);
                        }
                    }
                } else {
                    const alertDiv = document.querySelector('#transferItems .alert-warning');
                    if (alertDiv) {
                        alertDiv.remove();
                    }
                }
            } else {
                console.error('Error loading products:', data.message || 'Unknown error');
                alert('حدث خطأ أثناء تحميل المنتجات: ' + (data.message || 'خطأ غير معروف'));
            }
        })
        .catch(error => {
            console.error('Error loading products:', error);
            let errorMessage = 'حدث خطأ أثناء تحميل المنتجات. يرجى المحاولة مرة أخرى.';
            if (error.message && error.message.includes('Expected JSON')) {
                errorMessage = 'حدث خطأ في استجابة الخادم. يرجى التأكد من الاتصال بالإنترنت والمحاولة مرة أخرى.';
            }
            alert(errorMessage);
            if (addItemBtn) {
                addItemBtn.disabled = false;
                addItemBtn.innerHTML = originalAddBtnText || '<i class="bi bi-plus-circle me-2"></i>إضافة عنصر';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalSubmitBtnText || 'إنشاء الطلب';
            }
        });
});

// تحديث جميع قوائم المنتجات
function updateProductSelects() {
    const selects = document.querySelectorAll('.product-select');
    selects.forEach(select => {
        const currentValue = select.value;
        const currentBatchId = select.querySelector(`option[value="${currentValue}"]`)?.dataset.batchId;
        
        // حفظ القيمة المحددة
        select.innerHTML = '<option value="">اختر المنتج</option>';
        
        allFinishedProductOptions.forEach(option => {
            const optionElement = document.createElement('option');
            optionElement.value = option.product_id || 0;
            optionElement.dataset.productId = option.product_id || 0;
            optionElement.dataset.batchId = option.batch_id;
            optionElement.dataset.batchNumber = option.batch_number || '';
            optionElement.dataset.available = option.quantity_available || 0;
            optionElement.textContent = `${option.product_name} - تشغيلة ${option.batch_number || 'بدون'} (متاح: ${parseFloat(option.quantity_available || 0).toFixed(2)})`;
            
            // استعادة الاختيار السابق إذا كان موجوداً
            if (currentBatchId && option.batch_id == currentBatchId) {
                optionElement.selected = true;
            }
            
            select.appendChild(optionElement);
        });
        
        // إعادة تفعيل الأحداث
        const item = select.closest('.transfer-item');
        if (item) {
            attachItemEvents(item);
        }
    });
}

// إضافة عنصر جديد
document.getElementById('addItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('transferItems');
    const newItem = document.createElement('div');
    newItem.className = 'transfer-item row mb-2';
    let optionsHtml = '<option value="">اختر المنتج</option>';
    allFinishedProductOptions.forEach(option => {
        optionsHtml += `<option value="${option.product_id || 0}" 
                data-product-id="${option.product_id || 0}"
                data-batch-id="${option.batch_id}"
                data-batch-number="${option.batch_number || ''}"
                data-available="${option.quantity_available || 0}">
            ${option.product_name} - تشغيلة ${option.batch_number || 'بدون'} (متاح: ${parseFloat(option.quantity_available || 0).toFixed(2)})
        </option>`;
    });
    
    newItem.innerHTML = `
        <div class="col-md-5">
            <select class="form-select product-select" required>
                ${optionsHtml}
            </select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control quantity" 
                   name="items[${itemIndex}][quantity]" placeholder="الكمية" required min="0.01">
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control" 
                   name="items[${itemIndex}][notes]" placeholder="ملاحظات">
        </div>
        <div class="col-12">
            <small class="text-muted available-hint d-block"></small>
            <input type="hidden" name="items[${itemIndex}][product_id]" class="selected-product-id">
            <input type="hidden" name="items[${itemIndex}][batch_id]" class="selected-batch-id">
            <input type="hidden" name="items[${itemIndex}][batch_number]" class="selected-batch-number">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger remove-item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    itemIndex++;
    attachItemEvents(newItem);
});

// حذف عنصر
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        e.target.closest('.transfer-item').remove();
    }
});

// ربط أحداث العناصر
function attachItemEvents(item) {
    const productSelect = item.querySelector('.product-select');
    const quantityInput = item.querySelector('.quantity');
    const productIdInput = item.querySelector('.selected-product-id');
    const batchIdInput = item.querySelector('.selected-batch-id');
    const batchNumberInput = item.querySelector('.selected-batch-number');
    const availableHint = item.querySelector('.available-hint');

    if (!productSelect || !quantityInput) {
        return;
    }

    const updateAvailability = () => {
        const option = productSelect.options[productSelect.selectedIndex];
        const available = option ? parseFloat(option.dataset.available || '0') : 0;
        const selectedProductId = option ? parseInt(option.dataset.productId || '0', 10) : 0;
        const selectedBatchId = option ? parseInt(option.dataset.batchId || '0', 10) : 0;
        const selectedBatchNumber = option ? option.dataset.batchNumber || '' : '';

        if (productIdInput) {
            productIdInput.value = selectedProductId > 0 ? selectedProductId : '';
        }
        if (batchIdInput) {
            batchIdInput.value = selectedBatchId > 0 ? selectedBatchId : '';
        }
        if (batchNumberInput) {
            batchNumberInput.value = selectedBatchNumber;
        }

        if (availableHint) {
            if (option && option.value) {
                availableHint.textContent = `الكمية المتاحة لهذه التشغيلة: ${available.toLocaleString('ar-EG')} وحدة`;
            } else {
                availableHint.textContent = '';
            }
        }

        if (available > 0) {
            quantityInput.setAttribute('max', available);
            if (parseFloat(quantityInput.value || '0') > available) {
                quantityInput.value = available;
            }
        } else {
            quantityInput.removeAttribute('max');
        }
    };

    productSelect.addEventListener('change', updateAvailability);
    updateAvailability();
}

// ربط الأحداث للعناصر الموجودة
document.querySelectorAll('.transfer-item').forEach(item => {
    attachItemEvents(item);
});

// تحميل المنتجات تلقائياً عند فتح النموذج إذا كان هناك مخزن محدد
const createTransferModal = document.getElementById('createTransferModal');
if (createTransferModal) {
    createTransferModal.addEventListener('show.bs.modal', function() {
        const fromWarehouseSelect = document.getElementById('fromWarehouse');
        if (fromWarehouseSelect && fromWarehouseSelect.value) {
            // تحميل المنتجات من المخزن المحدد
            fromWarehouseSelect.dispatchEvent(new Event('change'));
        } else if (fromWarehouseSelect && allFinishedProductOptions.length === 0) {
            // إذا لم يكن هناك مخزن محدد ولم تكن هناك منتجات، تحميل من المخزن الرئيسي
            const mainWarehouseOption = fromWarehouseSelect.querySelector('option[data-type="main"]');
            if (mainWarehouseOption) {
                fromWarehouseSelect.value = mainWarehouseOption.value;
                fromWarehouseSelect.dispatchEvent(new Event('change'));
            }
        }
    });
}

// التحقق من عدم اختيار نفس المخزن
document.getElementById('transferForm')?.addEventListener('submit', function(e) {
    const fromWarehouse = document.getElementById('fromWarehouse').value;
    const toWarehouse = document.getElementById('toWarehouse').value;
    
    if (fromWarehouse === toWarehouse) {
        e.preventDefault();
        alert('لا يمكن النقل من وإلى نفس المخزن');
        return false;
    }

    const rows = document.querySelectorAll('#transferItems .transfer-item');
    if (!rows.length) {
        e.preventDefault();
        alert('أضف منتجاً واحداً على الأقل.');
        return false;
    }

    for (const row of rows) {
        const select = row.querySelector('.product-select');
        const quantityInput = row.querySelector('.quantity');
        const max = quantityInput ? parseFloat(quantityInput.getAttribute('max') || '0') : 0;
        const min = quantityInput ? parseFloat(quantityInput.getAttribute('min') || '0.01') : 0.01;
        const value = quantityInput ? parseFloat(quantityInput.value || '0') : 0;

        if (!select || !quantityInput || !select.value) {
            e.preventDefault();
            alert('اختر منتجاً وتشغيلته لكل عنصر.');
            return false;
        }

        if (value < min) {
            e.preventDefault();
            alert('أدخل كمية صحيحة لكل منتج.');
            return false;
        }

        if (max > 0 && value > max) {
            e.preventDefault();
            alert('الكمية المطلوبة تتجاوز المتاحة لإحدى التشغيلات.');
            return false;
        }
    }
});
</script>

