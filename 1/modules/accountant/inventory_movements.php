<?php
/**
 * صفحة حركات المخزون - مبسطة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة إضافة حركة جديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_movement') {
    $movementType = $_POST['movement_type'] ?? ''; // 'to_vehicle' أو 'from_vehicle'
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $products = $_POST['products'] ?? []; // مصفوفة من المنتجات
    
    if (empty($movementType) || $vehicleId <= 0) {
        $error = 'يجب تحديد نوع الحركة والسيارة';
    } elseif (empty($products) || !is_array($products)) {
        $error = 'يجب إضافة منتج واحد على الأقل';
    } else {
        // الحصول على مخزن الشركة (النوع main)
        $companyWarehouse = $db->queryOne(
            "SELECT id FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1"
        );
        
        if (!$companyWarehouse) {
            // إنشاء مخزن الشركة إذا لم يكن موجوداً
            $db->execute(
                "INSERT INTO warehouses (name, warehouse_type, location, status) 
                 VALUES (?, 'main', 'مخزن الشركة الرئيسي', 'active')",
                ['مخزن الشركة الرئيسي']
            );
            $companyWarehouseId = $db->getLastInsertId();
        } else {
            $companyWarehouseId = $companyWarehouse['id'];
        }
        
        // الحصول على مخزن السيارة
        $vehicleWarehouse = $db->queryOne(
            "SELECT id FROM warehouses WHERE vehicle_id = ? AND warehouse_type = 'vehicle' AND status = 'active'",
            [$vehicleId]
        );
        
        if (!$vehicleWarehouse) {
            // إنشاء مخزن السيارة إذا لم يكن موجوداً
            $vehicle = $db->queryOne("SELECT vehicle_number FROM vehicles WHERE id = ?", [$vehicleId]);
            if (!$vehicle) {
                $error = 'السيارة غير موجودة';
            } else {
                $db->execute(
                    "INSERT INTO warehouses (name, warehouse_type, vehicle_id, location, status) 
                     VALUES (?, 'vehicle', ?, 'سيارة', 'active')",
                    ['مخزن سيارة ' . $vehicle['vehicle_number'], $vehicleId]
                );
                $vehicleWarehouseId = $db->getLastInsertId();
            }
        } else {
            $vehicleWarehouseId = $vehicleWarehouse['id'];
        }
        
        if (empty($error)) {
            $successCount = 0;
            $errors = [];
            
            foreach ($products as $productData) {
                $productId = intval($productData['product_id'] ?? 0);
                $quantity = floatval($productData['quantity'] ?? 0);
                
                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }
                
                // تحديد المخزن المصدر والهدف
                if ($movementType === 'to_vehicle') {
                    // نقل من الشركة إلى السيارة
                    $fromWarehouseId = $companyWarehouseId;
                    $toWarehouseId = $vehicleWarehouseId;
                } else {
                    // نقل من السيارة إلى الشركة
                    $fromWarehouseId = $vehicleWarehouseId;
                    $toWarehouseId = $companyWarehouseId;
                }
                
                // التحقق من وجود المنتج في المخزن المصدر
                $product = $db->queryOne(
                    "SELECT id, quantity, warehouse_id FROM products WHERE id = ?",
                    [$productId]
                );
                
                if (!$product) {
                    $errors[] = "المنتج #$productId غير موجود";
                    continue;
                }
                
                // التحقق من الكمية المتاحة (إذا كان النقل من مخزن محدد)
                if ($movementType === 'from_vehicle' && $product['warehouse_id'] != $fromWarehouseId) {
                    // التحقق من كمية المنتج في مخزن السيارة
                    $vehicleProduct = $db->queryOne(
                        "SELECT quantity FROM vehicle_inventory WHERE product_id = ? AND vehicle_id = ?",
                        [$productId, $vehicleId]
                    );
                    
                    if (!$vehicleProduct || $vehicleProduct['quantity'] < $quantity) {
                        $errors[] = "الكمية غير كافية في مخزن السيارة للمنتج #$productId";
                        continue;
                    }
                } elseif ($movementType === 'to_vehicle' && $product['quantity'] < $quantity) {
                    $errors[] = "الكمية غير كافية في مخزن الشركة للمنتج #$productId";
                    continue;
                }
                
                // تسجيل الحركة
                $notes = "نقل " . ($movementType === 'to_vehicle' ? 'من الشركة إلى السيارة' : 'من السيارة إلى الشركة');
                
                if ($movementType === 'to_vehicle') {
                    // خفض من مخزن الشركة
                    $result1 = recordInventoryMovement(
                        $productId, 
                        $fromWarehouseId, 
                        'out', 
                        $quantity, 
                        null, 
                        null, 
                        $notes . ' - خروج من مخزن الشركة',
                        $currentUser['id']
                    );
                    
                    // إضافة لمخزن السيارة (إذا كان هناك جدول vehicle_inventory)
                    // أو تحديث products
                    $vehicleInventoryCheck = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
                    if ($vehicleInventoryCheck) {
                        // تسجيل في vehicle_inventory
                        $existing = $db->queryOne(
                            "SELECT id, quantity FROM vehicle_inventory WHERE product_id = ? AND vehicle_id = ?",
                            [$productId, $vehicleId]
                        );
                        
                        if ($existing) {
                            $db->execute(
                                "UPDATE vehicle_inventory SET quantity = quantity + ? WHERE id = ?",
                                [$quantity, $existing['id']]
                            );
                        } else {
                            $db->execute(
                                "INSERT INTO vehicle_inventory (vehicle_id, product_id, quantity) VALUES (?, ?, ?)",
                                [$vehicleId, $productId, $quantity]
                            );
                        }
                        
                        // تسجيل حركة دخول لمخزن السيارة
                        recordInventoryMovement(
                            $productId, 
                            $toWarehouseId, 
                            'in', 
                            $quantity, 
                            null, 
                            null, 
                            $notes . ' - دخول لمخزن السيارة',
                            $currentUser['id']
                        );
                    } else {
                        // إذا لم يكن هناك vehicle_inventory، نحدث فقط في products
                        $newQuantity = $product['quantity'] - $quantity;
                        $db->execute(
                            "UPDATE products SET quantity = ?, warehouse_id = ? WHERE id = ?",
                            [$newQuantity, $toWarehouseId, $productId]
                        );
                        
                        // تسجيل حركة واحدة
                        recordInventoryMovement(
                            $productId, 
                            $toWarehouseId, 
                            'transfer', 
                            $quantity, 
                            null, 
                            null, 
                            $notes,
                            $currentUser['id']
                        );
                    }
                } else {
                    // نقل من السيارة إلى الشركة
                    $vehicleInventoryCheck = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
                    if ($vehicleInventoryCheck) {
                        // خفض من vehicle_inventory
                        $existing = $db->queryOne(
                            "SELECT id, quantity FROM vehicle_inventory WHERE product_id = ? AND vehicle_id = ?",
                            [$productId, $vehicleId]
                        );
                        
                        if ($existing && $existing['quantity'] >= $quantity) {
                            $db->execute(
                                "UPDATE vehicle_inventory SET quantity = quantity - ? WHERE id = ?",
                                [$quantity, $existing['id']]
                            );
                            
                            // تسجيل حركة خروج من مخزن السيارة
                            recordInventoryMovement(
                                $productId, 
                                $fromWarehouseId, 
                                'out', 
                                $quantity, 
                                null, 
                                null, 
                                $notes . ' - خروج من مخزن السيارة',
                                $currentUser['id']
                            );
                            
                            // تسجيل حركة دخول لمخزن الشركة
                            recordInventoryMovement(
                                $productId, 
                                $toWarehouseId, 
                                'in', 
                                $quantity, 
                                null, 
                                null, 
                                $notes . ' - دخول لمخزن الشركة',
                                $currentUser['id']
                            );
                            
                            // تحديث كمية المنتج في products
                            $db->execute(
                                "UPDATE products SET quantity = quantity + ? WHERE id = ?",
                                [$quantity, $productId]
                            );
                        } else {
                            $errors[] = "الكمية غير كافية في مخزن السيارة للمنتج #$productId";
                            continue;
                        }
                    } else {
                        // إذا لم يكن هناك vehicle_inventory
                        if ($product['quantity'] >= $quantity) {
                            $newQuantity = $product['quantity'] - $quantity;
                            $db->execute(
                                "UPDATE products SET quantity = ?, warehouse_id = ? WHERE id = ?",
                                [$newQuantity, $toWarehouseId, $productId]
                            );
                            
                            recordInventoryMovement(
                                $productId, 
                                $toWarehouseId, 
                                'transfer', 
                                $quantity, 
                                null, 
                                null, 
                                $notes,
                                $currentUser['id']
                            );
                        } else {
                            $errors[] = "الكمية غير كافية للمنتج #$productId";
                            continue;
                        }
                    }
                }
                
                $successCount++;
            }
            
            if ($successCount > 0) {
                $success = "تم نقل $successCount منتج بنجاح";
                if (!empty($errors)) {
                    $success .= ". ملاحظات: " . implode(', ', array_slice($errors, 0, 3));
                }
            } else {
                $error = !empty($errors) ? implode(', ', $errors) : 'فشل نقل المنتجات';
            }
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// البحث والفلترة
$filters = [
    'product_id' => $_GET['product_id'] ?? '',
    'warehouse_id' => $_GET['warehouse_id'] ?? '',
    'type' => $_GET['type'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// تنظيف الفلاتر
$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// الحصول على العدد الإجمالي
$totalMovements = getInventoryMovementsCount($filters);
$totalPages = ceil($totalMovements / $perPage);

// الحصول على الحركات
$movements = getInventoryMovements($filters, $perPage, $offset);

// الحصول على المنتجات والسيارات
$products = $db->query("SELECT id, name, quantity, unit FROM products WHERE status = 'active' ORDER BY name");
$vehicles = $db->query(
    "SELECT v.id, v.vehicle_number, v.vehicle_type, u.full_name as driver_name 
     FROM vehicles v 
     LEFT JOIN users u ON v.driver_id = u.id 
     WHERE v.status = 'active' 
     ORDER BY v.vehicle_number"
);

require_once __DIR__ . '/../../includes/path_helper.php';
$currentUrl = getRelativeUrl('dashboard/accountant.php');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrows-move me-2"></i>حركات المخزون</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMovementModal">
        <i class="bi bi-plus-circle me-2"></i>إضافة حركة
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
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="row g-3">
            <input type="hidden" name="page" value="inventory_movements">
            <div class="col-md-3">
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
                <label class="form-label">نوع الحركة</label>
                <select class="form-select" name="type">
                    <option value="">جميع الأنواع</option>
                    <option value="transfer" <?php echo ($filters['type'] ?? '') === 'transfer' ? 'selected' : ''; ?>>نقل</option>
                    <option value="in" <?php echo ($filters['type'] ?? '') === 'in' ? 'selected' : ''; ?>>دخول</option>
                    <option value="out" <?php echo ($filters['type'] ?? '') === 'out' ? 'selected' : ''; ?>>خروج</option>
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

<!-- قائمة الحركات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">سجل الحركات (<?php echo $totalMovements; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>المنتج</th>
                        <th>المستودع</th>
                        <th>النوع</th>
                        <th>الكمية</th>
                        <th>قبل</th>
                        <th>بعد</th>
                        <th>بواسطة</th>
                        <th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">لا توجد حركات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td><?php echo formatDateTime($movement['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($movement['product_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($movement['warehouse_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $movement['type'] === 'in' ? 'success' : 
                                            ($movement['type'] === 'out' ? 'danger' : 
                                            ($movement['type'] === 'adjustment' ? 'warning' : 'info')); 
                                    ?>">
                                        <?php 
                                        $types = ['in' => 'دخول', 'out' => 'خروج', 'adjustment' => 'تعديل', 'transfer' => 'نقل'];
                                        echo $types[$movement['type']] ?? $movement['type'];
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($movement['quantity'], 2); ?></td>
                                <td><?php echo number_format($movement['quantity_before'], 2); ?></td>
                                <td><?php echo number_format($movement['quantity_after'], 2); ?></td>
                                <td><?php echo htmlspecialchars($movement['created_by_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($movement['notes'] ?? '-'); ?></td>
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
                    <a class="page-link" href="?page=inventory_movements&p=<?php echo $page - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=inventory_movements&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=inventory_movements&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=inventory_movements&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=inventory_movements&p=<?php echo $page + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة حركة مبسطة -->
<div class="modal fade" id="addMovementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">إضافة حركة مخزون</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="movementForm">
                <input type="hidden" name="action" value="add_movement">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">نوع الحركة <span class="text-danger">*</span></label>
                            <select class="form-select" name="movement_type" id="movementType" required>
                                <option value="">اختر نوع الحركة</option>
                                <option value="to_vehicle">نقل من مخزن الشركة إلى السيارة</option>
                                <option value="from_vehicle">نقل من مخزن السيارة إلى الشركة</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">السيارة <span class="text-danger">*</span></label>
                            <select class="form-select" name="vehicle_id" id="vehicleId" required>
                                <option value="">اختر السيارة</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>">
                                        <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                        <?php if ($vehicle['driver_name']): ?>
                                            - <?php echo htmlspecialchars($vehicle['driver_name']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">المنتجات</h6>
                        <button type="button" class="btn btn-sm btn-success" onclick="addProductRow()">
                            <i class="bi bi-plus-circle me-1"></i>إضافة منتج
                        </button>
                    </div>
                    
                    <div id="productsContainer">
                        <!-- المنتجات ستضاف هنا ديناميكياً -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ الحركة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let productRowCount = 0;

function addProductRow() {
    productRowCount++;
    const container = document.getElementById('productsContainer');
    const row = document.createElement('div');
    row.className = 'row mb-3 product-row';
    row.id = 'productRow' + productRowCount;
    
    row.innerHTML = `
        <div class="col-md-6">
            <label class="form-label">المنتج <span class="text-danger">*</span></label>
            <select class="form-select product-select" name="products[${productRowCount}][product_id]" required>
                <option value="">اختر المنتج</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo $product['id']; ?>" 
                            data-quantity="<?php echo $product['quantity']; ?>"
                            data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?>">
                        <?php echo htmlspecialchars($product['name']); ?> 
                        (المتاح: <?php echo number_format($product['quantity'], 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">الكمية <span class="text-danger">*</span></label>
            <input type="number" step="0.01" class="form-control product-quantity" 
                   name="products[${productRowCount}][quantity]" 
                   required min="0.01" placeholder="0.00">
        </div>
        <div class="col-md-2">
            <label class="form-label">&nbsp;</label>
            <button type="button" class="btn btn-danger w-100" onclick="removeProductRow(${productRowCount})">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(row);
    
    // إضافة مستمع لتحديث الكمية القصوى
    const select = row.querySelector('.product-select');
    select.addEventListener('change', function() {
        const quantity = parseFloat(this.options[this.selectedIndex].dataset.quantity || 0);
        const quantityInput = row.querySelector('.product-quantity');
        quantityInput.max = quantity;
        quantityInput.setAttribute('data-max', quantity);
    });
}

function removeProductRow(id) {
    const row = document.getElementById('productRow' + id);
    if (row) {
        row.remove();
    }
}

// إضافة منتج واحد عند فتح النموذج
document.getElementById('addMovementModal').addEventListener('show.bs.modal', function() {
    productRowCount = 0;
    document.getElementById('productsContainer').innerHTML = '';
    addProductRow();
});

// التحقق من الكمية قبل الإرسال
document.getElementById('movementForm').addEventListener('submit', function(e) {
    const movementType = document.getElementById('movementType').value;
    const productRows = document.querySelectorAll('.product-row');
    
    if (productRows.length === 0) {
        e.preventDefault();
        alert('يجب إضافة منتج واحد على الأقل');
        return false;
    }
    
    let hasError = false;
    productRows.forEach(row => {
        const select = row.querySelector('.product-select');
        const quantityInput = row.querySelector('.product-quantity');
        const maxQuantity = parseFloat(quantityInput.getAttribute('data-max') || 0);
        const quantity = parseFloat(quantityInput.value || 0);
        
        if (movementType === 'to_vehicle' && quantity > maxQuantity) {
            hasError = true;
            quantityInput.classList.add('is-invalid');
            alert(`الكمية المطلوبة (${quantity}) أكبر من الكمية المتاحة (${maxQuantity})`);
        } else {
            quantityInput.classList.remove('is-invalid');
        }
    });
    
    if (hasError) {
        e.preventDefault();
        return false;
    }
});
</script>
