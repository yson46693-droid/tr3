<?php
/**
 * نقطة بيع خاصة بمندوب المبيعات - بيع من مخزون سيارة المندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/vehicle_inventory.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['sales', 'manager']);

$currentUser = getCurrentUser();
$pageDirection = getDirection();
$db = db();
$error = '';
$success = '';
$validationErrors = [];

// التأكد من وجود الجداول المطلوبة
$customersTableExists = $db->queryOne("SHOW TABLES LIKE 'customers'");
$productsTableExists = $db->queryOne("SHOW TABLES LIKE 'products'");
$vehicleInventoryTableExists = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
$salesTableExists = $db->queryOne("SHOW TABLES LIKE 'sales'");

if (empty($customersTableExists) || empty($productsTableExists) || empty($vehicleInventoryTableExists) || empty($salesTableExists)) {
    $error = 'بعض الجداول المطلوبة غير متوفرة في قاعدة البيانات. يرجى التواصل مع المسؤول.';
}

// الحصول على بيانات السيارة المربوطة بالمندوب
$vehicle = null;
$vehicleWarehouseId = null;

if (!$error) {
    $vehicle = $db->queryOne(
        "SELECT v.*, w.id AS warehouse_id, w.name AS warehouse_name
         FROM vehicles v
         LEFT JOIN warehouses w ON w.vehicle_id = v.id AND w.warehouse_type = 'vehicle'
         WHERE v.driver_id = ?",
        [$currentUser['id']]
    );

    if (!$vehicle) {
        $error = 'لم يتم ربط سيارة بهذا الحساب بعد. يرجى التواصل مع فريق الإدارة.';
    } else {
        if (empty($vehicle['warehouse_id'])) {
            $warehouseResult = createVehicleWarehouse($vehicle['id'], "مخزن سيارة " . ($vehicle['vehicle_number'] ?? ''));
            if ($warehouseResult['success']) {
                $vehicleWarehouseId = $warehouseResult['warehouse_id'];
                // تحديث الكيان ليشمل المعرّف الجديد
                $vehicle['warehouse_id'] = $vehicleWarehouseId;
            } else {
                $error = $warehouseResult['message'] ?? 'تعذر إنشاء مخزن للسيارة.';
            }
        } else {
            $vehicleWarehouseId = (int) $vehicle['warehouse_id'];
        }
    }
}

// تحميل قائمة العملاء المتاحين للمندوب
$customers = [];
if (!$error && !empty($customersTableExists)) {
    $statusColumnExists = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'status'");
    $createdByColumnExists = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'created_by'");

    $customerSql = "SELECT id, name FROM customers WHERE 1=1";
    $customerParams = [];

    if (!empty($statusColumnExists)) {
        $customerSql .= " AND status = 'active'";
    }

    if (!empty($createdByColumnExists) && ($currentUser['role'] ?? '') === 'sales') {
        $customerSql .= " AND (created_by = ? OR created_by IS NULL)";
        $customerParams[] = $currentUser['id'];
    }

    $customerSql .= " ORDER BY name ASC";
    $customers = $db->query($customerSql, $customerParams);
}

// تحميل مخزون السيارة
$vehicleInventory = [];
$inventoryStats = [
    'total_products' => 0,
    'total_quantity' => 0,
    'total_value' => 0,
];

if (!$error && $vehicle) {
    $vehicleInventory = getVehicleInventory($vehicle['id']);

    foreach ($vehicleInventory as $item) {
        $inventoryStats['total_products']++;
        $inventoryStats['total_quantity'] += (float) ($item['quantity'] ?? 0);
        $inventoryStats['total_value'] += (float) ($item['total_value'] ?? 0);
    }
}

// معالجة إنشاء عملية بيع جديدة
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_pos_sale') {
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
        $quantity = isset($_POST['quantity']) ? (float) $_POST['quantity'] : 0;
        $price = isset($_POST['price']) ? round((float) $_POST['price'], 2) : 0;
        $saleDate = $_POST['date'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = date('Y-m-d');
        }

        if ($customerId <= 0) {
            $validationErrors[] = 'يجب اختيار العميل.';
        }

        if ($productId <= 0) {
            $validationErrors[] = 'يجب اختيار المنتج.';
        }

        if ($quantity <= 0) {
            $validationErrors[] = 'يجب إدخال كمية صالحة.';
        }

        if ($price <= 0) {
            $validationErrors[] = 'يجب إدخال سعر صالح.';
        }

        if (empty($validationErrors)) {
            // التحقق من وجود العميل
            $customer = $db->queryOne("SELECT id FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                $validationErrors[] = 'العميل المحدد غير موجود.';
            }
        }

        if (empty($validationErrors)) {
            // إعادة تحميل المخزون للتأكد من آخر تحديث
            $vehicleInventory = getVehicleInventory($vehicle['id']);
            $inventoryByProduct = [];
            foreach ($vehicleInventory as $item) {
                $inventoryByProduct[(int) $item['product_id']] = $item;
            }

            $inventoryItem = $inventoryByProduct[$productId] ?? null;
            if (!$inventoryItem) {
                $validationErrors[] = 'المنتج غير موجود في مخزون السيارة.';
            } else {
                $availableQuantity = (float) ($inventoryItem['quantity'] ?? 0);
                if ($quantity > $availableQuantity) {
                    $validationErrors[] = 'الكمية المطلوبة أكبر من المتوفرة في السيارة.';
                }

                if ($price < 0.01) {
                    $price = round((float) ($inventoryItem['unit_price'] ?? 0), 2);
                    if ($price <= 0) {
                        $validationErrors[] = 'لا يمكن تحديد سعر المنتج.';
                    }
                }
            }
        }

        if (empty($validationErrors)) {
            $total = round($price * $quantity, 2);

            try {
                $conn = $db->getConnection();
                $conn->begin_transaction();

                $db->execute(
                    "INSERT INTO sales (customer_id, product_id, quantity, price, total, date, salesperson_id, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')",
                    [$customerId, $productId, $quantity, $price, $total, $saleDate, $currentUser['id']]
                );

                $saleId = (int) $db->getLastInsertId();

                // تحديث مخزون السيارة
                $newQuantity = max(0, (float) $inventoryItem['quantity'] - $quantity);
                $updateResult = updateVehicleInventory($vehicle['id'], $productId, $newQuantity, $currentUser['id']);

                if (!$updateResult['success']) {
                    throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة.');
                }

                // تسجيل حركة المخزون
                $movementResult = recordInventoryMovement(
                    $productId,
                    $vehicleWarehouseId,
                    'out',
                    $quantity,
                    'sales',
                    $saleId,
                    'بيع من مخزون السيارة',
                    $currentUser['id']
                );

                if (!$movementResult['success']) {
                    throw new Exception($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                }

                logAudit($currentUser['id'], 'create_pos_sale', 'sale', $saleId, null, [
                    'product_id' => $productId,
                    'customer_id' => $customerId,
                    'quantity' => $quantity,
                    'total' => $total
                ]);

                $conn->commit();
                $success = 'تم تسجيل عملية البيع بنجاح.';

                // تحديث البيانات بعد عملية البيع
                $vehicleInventory = getVehicleInventory($vehicle['id']);
                $inventoryStats = [
                    'total_products' => 0,
                    'total_quantity' => 0,
                    'total_value' => 0,
                ];
                foreach ($vehicleInventory as $item) {
                    $inventoryStats['total_products']++;
                    $inventoryStats['total_quantity'] += (float) ($item['quantity'] ?? 0);
                    $inventoryStats['total_value'] += (float) ($item['total_value'] ?? 0);
                }
            } catch (Exception $exception) {
                if (isset($conn)) {
                    $conn->rollback();
                }
                $error = 'حدث خطأ أثناء حفظ عملية البيع: ' . $exception->getMessage();
            }
        } else {
            $error = implode('<br>', array_map('htmlspecialchars', $validationErrors));
        }
    }
}

// آخر عمليات البيع للمندوب
$recentSales = [];
if (!$error) {
    $recentSales = $db->query(
        "SELECT s.*, c.name AS customer_name, p.name AS product_name
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         LEFT JOIN products p ON s.product_id = p.id
         WHERE s.salesperson_id = ?
         ORDER BY s.created_at DESC
         LIMIT 10",
        [$currentUser['id']]
    );
}
?>

<div class="page-header">
    <h2><i class="bi bi-shop me-2"></i>نقطة بيع المندوب</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!$error && !$vehicle): ?>
    <div class="empty-state-card">
        <div class="empty-state-icon"><i class="bi bi-truck"></i></div>
        <div class="empty-state-title">لا توجد سيارة مرتبطة</div>
        <div class="empty-state-description">يرجى التواصل مع الإدارة لربط سيارة بحسابك قبل استخدام نقطة البيع.</div>
    </div>
<?php elseif (!$error): ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-truck me-2"></i>بيانات السيارة</h5>
                </div>
                <div class="card-body">
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">رقم السيارة:</span>
                        <strong><?php echo htmlspecialchars($vehicle['vehicle_number'] ?? '-'); ?></strong>
                    </div>
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">الموديل:</span>
                        <strong><?php echo htmlspecialchars($vehicle['model'] ?? '-'); ?></strong>
                    </div>
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">المخزن المرتبط:</span>
                        <strong><?php echo htmlspecialchars($vehicle['warehouse_name'] ?? 'مخزن السيارة'); ?></strong>
                    </div>
                    <div class="mt-3">
                        <h6 class="fw-bold mb-3">إحصائيات المخزون</h6>
                        <div class="d-flex justify-content-between mb-2">
                            <span>عدد المنتجات:</span>
                            <strong><?php echo number_format($inventoryStats['total_products']); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>إجمالي الكمية:</span>
                            <strong><?php echo number_format($inventoryStats['total_quantity'], 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>قيمة المخزون:</span>
                            <strong><?php echo formatCurrency($inventoryStats['total_value']); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>إنشاء عملية بيع</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($vehicleInventory)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            لا يوجد مخزون متاح في السيارة حالياً.
                        </div>
                    <?php else: ?>
                        <form method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="create_pos_sale">

                            <?php if (empty($customers)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-person-plus me-2"></i>
                                    لا يوجد عملاء نشطون حالياً. قم بإضافة عميل جديد قبل تسجيل عملية بيع.
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="posCustomer" class="form-label">العميل</label>
                                <select class="form-select" id="posCustomer" name="customer_id" <?php echo empty($customers) ? 'disabled' : 'required'; ?>>
                                    <option value="">اختر العميل</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo (int) $customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="posProduct" class="form-label">المنتج</label>
                                <select class="form-select" id="posProduct" name="product_id" required>
                                    <option value="">اختر المنتج</option>
                                    <?php foreach ($vehicleInventory as $item): ?>
                                        <option value="<?php echo (int) $item['product_id']; ?>">
                                            <?php echo htmlspecialchars($item['product_name'] ?? 'منتج غير معروف'); ?>
                                            (<?php echo number_format((float) $item['quantity'], 2); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="posProductHint">اختر المنتج لمعرفة الكمية المتاحة والسعر.</div>
                            </div>

                            <div class="mb-3">
                                <label for="posQuantity" class="form-label">الكمية</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="posQuantity" name="quantity" required>
                                <div class="form-text">الكمية المتاحة: <span id="posAvailableQuantity">0</span></div>
                            </div>

                            <div class="mb-3">
                                <label for="posPrice" class="form-label">سعر الوحدة</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="posPrice" name="price" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">الإجمالي</label>
                                <div class="fw-bold fs-5" id="posTotalValue"><?php echo formatCurrency(0); ?></div>
                            </div>

                            <div class="mb-3">
                                <label for="posDate" class="form-label">تاريخ العملية</label>
                                <input type="date" class="form-control" id="posDate" name="date" value="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <button type="submit" class="btn btn-success w-100" <?php echo empty($customers) ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-circle me-2"></i>تسجيل البيع
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>مخزون السيارة</h5>
                    <span class="badge bg-light text-primary">
                        إجمالي المنتجات: <?php echo number_format($inventoryStats['total_products']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th>التصنيف</th>
                                    <th>الكمية المتاحة</th>
                                    <th>سعر الوحدة</th>
                                    <th>القيمة الإجمالية</th>
                                    <th>آخر تحديث</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($vehicleInventory)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">لا يوجد مخزون متاح حالياً.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($vehicleInventory as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['category'] ?? '-'); ?></td>
                                            <td><strong><?php echo number_format((float) $item['quantity'], 2); ?></strong></td>
                                            <td><?php echo formatCurrency((float) ($item['unit_price'] ?? 0)); ?></td>
                                            <td><?php echo formatCurrency((float) ($item['total_value'] ?? 0)); ?></td>
                                            <td><?php echo !empty($item['last_updated_at']) ? formatDateTime($item['last_updated_at']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>آخر عمليات البيع</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentSales)): ?>
                        <div class="empty-state-card mb-0">
                            <div class="empty-state-icon"><i class="bi bi-receipt"></i></div>
                            <div class="empty-state-title">لا توجد عمليات بيع مسجلة</div>
                            <div class="empty-state-description">ابدأ ببيع منتجات مخزون السيارة ليظهر السجل هنا.</div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-borderless align-middle">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>العميل</th>
                                        <th>المنتج</th>
                                        <th>الكمية</th>
                                        <th>الإجمالي</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentSales as $sale): ?>
                                        <tr>
                                            <td><?php echo formatDate($sale['date']); ?></td>
                                            <td><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($sale['product_name'] ?? '-'); ?></td>
                                            <td><?php echo number_format((float) $sale['quantity'], 2); ?></td>
                                            <td><?php echo formatCurrency((float) $sale['total']); ?></td>
                                            <td>
                                                <span class="badge bg-success">مكتمل</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$error && !empty($vehicleInventory)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var productSelect = document.getElementById('posProduct');
    var quantityInput = document.getElementById('posQuantity');
    var priceInput = document.getElementById('posPrice');
    var totalDisplay = document.getElementById('posTotalValue');
    var availableSpan = document.getElementById('posAvailableQuantity');
    var saleForm = productSelect ? productSelect.closest('form') : null;

    if (!productSelect || !quantityInput || !priceInput || !totalDisplay) {
        return;
    }

    var inventoryData = <?php
        $inventoryPayload = [];
        foreach ($vehicleInventory as $item) {
            $inventoryPayload[(int) $item['product_id']] = [
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) ($item['unit_price'] ?? 0),
            ];
        }
        echo json_encode($inventoryPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    var posLocale = <?php echo json_encode($pageDirection === 'rtl' ? 'ar-EG' : 'en-US'); ?>;
    var currencySymbol = <?php echo json_encode(CURRENCY_SYMBOL); ?>;

    function updateTotals() {
        var selectedProduct = parseInt(productSelect.value, 10);
        var quantity = parseFloat(quantityInput.value) || 0;
        var price = parseFloat(priceInput.value) || 0;

        if (inventoryData[selectedProduct]) {
            var available = inventoryData[selectedProduct].quantity;
            var defaultPrice = inventoryData[selectedProduct].unit_price;

            availableSpan.textContent = available.toFixed(2);

            if (!price || price <= 0) {
                priceInput.value = defaultPrice.toFixed(2);
                price = defaultPrice;
            }

            if (quantity > available) {
                quantityInput.value = available.toFixed(2);
                quantity = available;
            }
        } else {
            availableSpan.textContent = '0.00';
        }

        var total = quantity * price;
        var formattedTotal = (total || 0).toLocaleString(posLocale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        totalDisplay.textContent = formattedTotal + ' ' + currencySymbol;
    }

    productSelect.addEventListener('change', function () {
        var selectedProduct = parseInt(this.value, 10);
        if (inventoryData[selectedProduct]) {
            priceInput.value = inventoryData[selectedProduct].unit_price.toFixed(2);
            quantityInput.value = '';
        } else {
            priceInput.value = '';
            quantityInput.value = '';
        }
        updateTotals();
    });

    quantityInput.addEventListener('input', updateTotals);
    priceInput.addEventListener('input', updateTotals);

    if (saleForm) {
        saleForm.addEventListener('submit', function (event) {
            if (!saleForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            saleForm.classList.add('was-validated');
        });
    }
});
</script>
<?php endif; ?>

