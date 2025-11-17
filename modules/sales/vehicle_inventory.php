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

// معالجة طلبات AJAX قبل أي شيء آخر (قبل requireRole لتجنب إرسال HTML)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_products') {
    // التحقق من تسجيل الدخول فقط (بدون requireRole لتجنب redirect)
    if (!isLoggedIn()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'يجب تسجيل الدخول أولاً'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // التحقق من الصلاحيات
    $currentUser = getCurrentUser();
    $allowedRoles = ['sales', 'accountant', 'production', 'manager'];
    if (!hasAnyRole($allowedRoles)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'غير مصرح لك بالوصول'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // تنظيف أي output buffer موجود
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
    
    try {
        // التأكد من تحميل الدوال المطلوبة
        if (!function_exists('getAvailableProductsFromWarehouse')) {
            require_once __DIR__ . '/../../includes/vehicle_inventory.php';
        }
        
        $products = getAvailableProductsFromWarehouse($warehouseId);
        echo json_encode([
            'success' => true,
            'products' => $products
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        error_log('AJAX load_products error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء تحميل المنتجات: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

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
$defaultFromWarehouseId = null; // مخزن افتراضي للمخزن المصدر في نموذج النقل
if ($currentUser['role'] === 'sales') {
    $userVehicle = $db->queryOne("SELECT id FROM vehicles WHERE driver_id = ?", [$currentUser['id']]);
    if ($userVehicle) {
        $filters['vehicle_id'] = $userVehicle['id'];
        
        // الحصول على مخزن سيارة المندوب لاستخدامه كقيمة افتراضية في نموذج النقل
        $userVehicleWarehouse = $db->queryOne(
            "SELECT w.id, w.name 
             FROM warehouses w 
             WHERE w.vehicle_id = ? AND w.warehouse_type = 'vehicle' AND w.status = 'active' 
             LIMIT 1",
            [$userVehicle['id']]
        );
        
        if ($userVehicleWarehouse) {
            $defaultFromWarehouseId = (int)$userVehicleWarehouse['id'];
        } else {
            // إنشاء مخزن السيارة إذا لم يكن موجوداً
            $result = createVehicleWarehouse($userVehicle['id']);
            if ($result['success']) {
                $defaultFromWarehouseId = (int)$result['warehouse_id'];
            }
        }
    }
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
                    $productId = isset($item['product_id']) && $item['product_id'] !== '' ? intval($item['product_id']) : 0;
                    $batchId = isset($item['batch_id']) && $item['batch_id'] !== '' ? intval($item['batch_id']) : 0;
                    $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0;

                    // يجب أن يكون هناك batch_id على الأقل (للمنتجات من finished_products)
                    // أو product_id (للمنتجات الخارجية)
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
            
            // تسجيل تفاصيل العناصر للمساعدة في التصحيح
            if (empty($items)) {
                error_log('No items found in POST data from sales. POST items: ' . json_encode($_POST['items'] ?? []));
                error_log('POST data keys: ' . json_encode(array_keys($_POST)));
                $error = 'يجب إضافة منتج واحد على الأقل مع تحديد الكمية.';
            } elseif ($fromWarehouseId <= 0 || $toWarehouseId <= 0) {
                $error = 'يجب تحديد المخزن المصدر والمخزن الهدف';
            } else {
                // تسجيل العناصر قبل الإرسال للمساعدة في التصحيح
                error_log('Transfer items from sales: ' . json_encode($items));
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

// تحميل المنتجات من المخزن المحدد (سيتم تحديثها عند اختيار المخزن المصدر)
$finishedProductOptions = [];

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
                                            data-type="<?php echo $warehouse['warehouse_type']; ?>"
                                            <?php echo ($defaultFromWarehouseId && $warehouse['id'] == $defaultFromWarehouseId) ? 'selected' : ''; ?>>
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
                        <div class="alert alert-info d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-info-circle"></i>
                            <div>يرجى اختيار المخزن المصدر أولاً لعرض المنتجات المتاحة.</div>
                        </div>
                        <div id="transferItems">
                            <div class="transfer-item row mb-2">
                                <div class="col-md-5">
                                    <select class="form-select product-select" required>
                                        <option value="">اختر المنتج</option>
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
                const infoAlert = document.querySelector('#transferItems').previousElementSibling;
                if (allFinishedProductOptions.length === 0) {
                    if (infoAlert && infoAlert.classList.contains('alert-info')) {
                        infoAlert.className = 'alert alert-warning d-flex align-items-center gap-2 mb-2';
                        infoAlert.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i><div>لا توجد منتجات متاحة في هذا المخزن حالياً.</div>';
                    }
                } else {
                    if (infoAlert && infoAlert.classList.contains('alert-warning')) {
                        infoAlert.className = 'alert alert-info d-flex align-items-center gap-2 mb-2';
                        infoAlert.innerHTML = '<i class="bi bi-info-circle"></i><div>تم تحميل المنتجات المتاحة من المخزن المحدد.</div>';
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
        const currentProductId = parseInt(currentValue || '0', 10);
        
        // حفظ القيمة المحددة
        select.innerHTML = '<option value="">اختر المنتج</option>';
        
        allFinishedProductOptions.forEach(option => {
            const optionElement = document.createElement('option');
            // استخدام product_id كقيمة للخيار (مثل صفحة عمال الإنتاج)
            const optionValue = parseInt(option.product_id || 0, 10);
            optionElement.value = optionValue;
            optionElement.dataset.productId = option.product_id || 0;
            optionElement.dataset.batchId = option.batch_id || 0;
            optionElement.dataset.batchNumber = option.batch_number || '';
            optionElement.dataset.available = option.quantity_available || 0;
            
            // بناء نص الخيار
            let optionText = option.product_name || 'غير محدد';
            if (option.batch_number) {
                optionText += ` - تشغيلة ${option.batch_number}`;
            }
            optionText += ` (متاح: ${parseFloat(option.quantity_available || 0).toFixed(2)})`;
            optionElement.textContent = optionText;
            
            // استعادة الاختيار السابق إذا كان موجوداً
            if (currentProductId > 0 && option.product_id == currentProductId) {
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
        // استخدام product_id كقيمة للخيار (مثل صفحة عمال الإنتاج)
        const optionValue = parseInt(option.product_id || 0, 10);
        let optionText = option.product_name || 'غير محدد';
        if (option.batch_number) {
            optionText += ` - تشغيلة ${option.batch_number}`;
        }
        optionText += ` (متاح: ${parseFloat(option.quantity_available || 0).toFixed(2)})`;
        optionsHtml += `<option value="${optionValue}" 
                data-product-id="${option.product_id || 0}"
                data-batch-id="${option.batch_id || 0}"
                data-batch-number="${option.batch_number || ''}"
                data-available="${option.quantity_available || 0}">
            ${optionText}
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

// ربط أحداث العناصر - استخدام event delegation مثل صفحة عمال الإنتاج
const transferItemsContainer = document.getElementById('transferItems');
if (transferItemsContainer) {
    // استخدام event delegation لتحديث الحقول المخفية عند تغيير المنتج
    transferItemsContainer.addEventListener('change', function(e) {
        if (e.target.classList.contains('product-select')) {
            const select = e.target;
            const row = select.closest('.transfer-item');
            if (!row) {
                console.warn('Transfer item row not found');
                return;
            }
            
            const selectedOption = select.options[select.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                // لا يوجد خيار محدد - مسح الحقول
                const productIdInput = row.querySelector('.selected-product-id');
                const batchIdInput = row.querySelector('.selected-batch-id');
                const batchNumberInput = row.querySelector('.selected-batch-number');
                const availableHint = row.querySelector('.available-hint');
                const quantityInput = row.querySelector('.quantity');
                
                if (productIdInput) productIdInput.value = '';
                if (batchIdInput) batchIdInput.value = '';
                if (batchNumberInput) batchNumberInput.value = '';
                if (availableHint) availableHint.textContent = '';
                if (quantityInput) quantityInput.removeAttribute('max');
                return;
            }
            
            const available = parseFloat(selectedOption.dataset.available || '0');
            const productId = parseInt(selectedOption.dataset.productId || '0', 10);
            const batchId = parseInt(selectedOption.dataset.batchId || '0', 10);
            const batchNumber = selectedOption.dataset.batchNumber || '';
            
            const productIdInput = row.querySelector('.selected-product-id');
            const batchIdInput = row.querySelector('.selected-batch-id');
            const batchNumberInput = row.querySelector('.selected-batch-number');
            const availableHint = row.querySelector('.available-hint');
            const quantityInput = row.querySelector('.quantity');
            
            // تحديث الحقول المخفية
            if (productIdInput) {
                productIdInput.value = productId > 0 ? productId : '';
            }
            if (batchIdInput) {
                batchIdInput.value = batchId > 0 ? batchId : '';
            }
            if (batchNumberInput) {
                batchNumberInput.value = batchNumber;
            }
            
            // تسجيل للمساعدة في التصحيح
            console.log('Product selected:', {
                productId: productId,
                batchId: batchId,
                batchNumber: batchNumber,
                available: available,
                productIdInputValue: productIdInput?.value,
                batchIdInputValue: batchIdInput?.value
            });
            
            if (availableHint) {
                if (selectedOption && selectedOption.value) {
                    availableHint.textContent = `الكمية المتاحة: ${available.toLocaleString('ar-EG')} وحدة`;
                } else {
                    availableHint.textContent = '';
                }
            }
            
            if (quantityInput) {
                if (available > 0) {
                    quantityInput.setAttribute('max', available);
                    if (parseFloat(quantityInput.value || '0') > available) {
                        quantityInput.value = available;
                    }
                } else {
                    quantityInput.removeAttribute('max');
                }
            }
        }
    });
}

// ربط أحداث العناصر (للتوافق مع الكود القديم)
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
        const selectedIndex = productSelect.selectedIndex;
        const option = selectedIndex > 0 ? productSelect.options[selectedIndex] : null;
        
        if (!option || !option.value || option.value === '') {
            // لا يوجد خيار محدد - مسح الحقول
            if (productIdInput) productIdInput.value = '';
            if (batchIdInput) batchIdInput.value = '';
            if (batchNumberInput) batchNumberInput.value = '';
            if (availableHint) availableHint.textContent = '';
            if (quantityInput) quantityInput.removeAttribute('max');
            return;
        }
        
        const available = parseFloat(option.dataset.available || '0');
        const selectedProductId = parseInt(option.dataset.productId || '0', 10);
        const selectedBatchId = parseInt(option.dataset.batchId || '0', 10);
        const selectedBatchNumber = option.dataset.batchNumber || '';

        // تحديث الحقول المخفية
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
                availableHint.textContent = `الكمية المتاحة: ${available.toLocaleString('ar-EG')} وحدة`;
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
            // تحميل المنتجات من المخزن المحدد (مخزن المندوب إذا كان محدداً)
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
        const productIdInput = row.querySelector('.selected-product-id');
        const batchIdInput = row.querySelector('.selected-batch-id');
        const max = quantityInput ? parseFloat(quantityInput.getAttribute('max') || '0') : 0;
        const min = quantityInput ? parseFloat(quantityInput.getAttribute('min') || '0.01') : 0.01;
        const value = quantityInput ? parseFloat(quantityInput.value || '0') : 0;
        const productId = productIdInput ? parseInt(productIdInput.value || '0', 10) : 0;
        const batchId = batchIdInput ? parseInt(batchIdInput.value || '0', 10) : 0;

        if (!select || !quantityInput || !select.value) {
            e.preventDefault();
            alert('اختر منتجاً وتشغيلته لكل عنصر.');
            return false;
        }

        // التحقق من أن batch_id أو product_id موجود
        if (productId <= 0 && batchId <= 0) {
            e.preventDefault();
            console.error('Validation failed:', {
                selectValue: select.value,
                productId: productId,
                batchId: batchId,
                productIdInputValue: productIdInput?.value,
                batchIdInputValue: batchIdInput?.value,
                selectedOption: select.options[select.selectedIndex]?.dataset
            });
            alert('خطأ: لم يتم تحديد المنتج بشكل صحيح. يرجى إعادة اختيار المنتج.');
            // إعادة تحديث الحقول
            if (select) {
                select.dispatchEvent(new Event('change'));
            }
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

