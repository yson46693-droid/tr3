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
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/simple_telegram.php';

if (!function_exists('renderSalesInvoiceHtml')) {
    function renderSalesInvoiceHtml(array $invoice, array $meta = []): string
    {
        ob_start();
        $selectedInvoice = $invoice;
        $invoiceData = $invoice;
        $invoiceMeta = $meta;
        include __DIR__ . '/../accountant/invoice_print.php';
        return (string) ob_get_clean();
    }
}

if (!function_exists('storeSalesInvoiceDocument')) {
    function storeSalesInvoiceDocument(array $invoice, array $meta = []): ?array
    {
        try {
            if (!function_exists('ensurePrivateDirectory')) {
                return null;
            }

            $basePath = defined('REPORTS_PRIVATE_PATH')
                ? REPORTS_PRIVATE_PATH
                : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__, 2) . '/reports'));

            $basePath = rtrim((string) $basePath, '/\\');
            if ($basePath === '') {
                return null;
            }

            ensurePrivateDirectory($basePath);

            $exportsDir = $basePath . DIRECTORY_SEPARATOR . 'exports';
            $salesDir = $exportsDir . DIRECTORY_SEPARATOR . 'sales-pos';

            ensurePrivateDirectory($exportsDir);
            ensurePrivateDirectory($salesDir);

            if (!is_dir($salesDir) || !is_writable($salesDir)) {
                error_log('POS invoice directory not writable: ' . $salesDir);
                return null;
            }

            $document = renderSalesInvoiceHtml($invoice, $meta);
            if ($document === '') {
                return null;
            }

            $pattern = $salesDir . DIRECTORY_SEPARATOR . 'pos-invoice-*';
            foreach (glob($pattern) ?: [] as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            $token = bin2hex(random_bytes(8));
            $normalizedNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'INV'));
            $filename = sprintf('pos-invoice-%s-%s.html', date('Ymd-His'), $normalizedNumber);
            $fullPath = $salesDir . DIRECTORY_SEPARATOR . $filename;

            if (@file_put_contents($fullPath, $document) === false) {
                return null;
            }

            $relativePath = 'exports/sales-pos/' . $filename;
            $viewerPath = '/reports/view.php?type=export&file=' . rawurlencode($relativePath) . '&token=' . $token;
            $printPath = $viewerPath . '&print=1';

            $absoluteViewer = function_exists('getAbsoluteUrl')
                ? getAbsoluteUrl(ltrim($viewerPath, '/'))
                : $viewerPath;
            $absolutePrint = function_exists('getAbsoluteUrl')
                ? getAbsoluteUrl(ltrim($printPath, '/'))
                : $printPath;

            return [
                'relative_path' => $relativePath,
                'viewer_path' => $viewerPath,
                'print_path' => $printPath,
                'absolute_report_url' => $absoluteViewer,
                'absolute_print_url' => $absolutePrint,
                'generated_at' => date('Y-m-d H:i:s'),
                'summary' => $meta['summary'] ?? [],
                'token' => $token,
            ];
        } catch (Throwable $error) {
            error_log('POS invoice storage failed: ' . $error->getMessage());
            return null;
        }
    }
}

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
$posInvoiceLinks = null;
$inventoryByProduct = [];
foreach ($vehicleInventory as $item) {
    $inventoryByProduct[(int) ($item['product_id'] ?? 0)] = $item;
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_pos_sale') {
        $cartPayload = $_POST['cart_data'] ?? '';
        $cartItems = json_decode($cartPayload, true);
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        $customerMode = $_POST['customer_mode'] ?? 'existing';
        $paymentType = $_POST['payment_type'] ?? 'full';
        $prepaidAmount = cleanFinancialValue($_POST['prepaid_amount'] ?? 0);
        $paidAmountInput = cleanFinancialValue($_POST['paid_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = date('Y-m-d');
        }

        if (!in_array($paymentType, ['full', 'partial', 'credit'], true)) {
            $paymentType = 'full';
        }

        if (!is_array($cartItems) || empty($cartItems)) {
            $validationErrors[] = 'يجب اختيار منتج واحد على الأقل من المخزون.';
        }

        $normalizedCart = [];
        $subtotal = 0;

        if (empty($validationErrors)) {
            foreach ($cartItems as $index => $row) {
                $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
                $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 0;
                $unitPrice = isset($row['unit_price']) ? round((float) $row['unit_price'], 2) : 0;

                if ($productId <= 0 || !isset($inventoryByProduct[$productId])) {
                    $validationErrors[] = 'المنتج المحدد رقم ' . ($index + 1) . ' غير متاح في مخزون السيارة.';
                    continue;
                }

                $product = $inventoryByProduct[$productId];
                $available = (float) ($product['quantity'] ?? 0);

                if ($quantity <= 0) {
                    $validationErrors[] = 'يجب تحديد كمية صالحة للمنتج ' . htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8') . '.';
                    continue;
                }

                if ($quantity > $available) {
                    $validationErrors[] = 'الكمية المطلوبة للمنتج ' . htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8') . ' تتجاوز الكمية المتاحة.';
                    continue;
                }

                if ($unitPrice <= 0) {
                    $unitPrice = round((float) ($product['unit_price'] ?? 0), 2);
                    if ($unitPrice <= 0) {
                        $validationErrors[] = 'لا يمكن تحديد سعر المنتج ' . htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8') . '.';
                        continue;
                    }
                }

                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $normalizedCart[] = [
                    'product_id' => $productId,
                    'name' => $product['product_name'] ?? 'منتج',
                    'category' => $product['category'] ?? null,
                    'quantity' => $quantity,
                    'available' => $available,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }
        }

        if ($subtotal <= 0 && empty($validationErrors)) {
            $validationErrors[] = 'لا يمكن إتمام عملية بيع بمجموع صفري.';
        }

        $prepaidAmount = max(0, min($prepaidAmount, $subtotal));
        $netTotal = round($subtotal - $prepaidAmount, 2);

        $effectivePaidAmount = 0.0;
        if ($paymentType === 'full') {
            $effectivePaidAmount = $netTotal;
        } elseif ($paymentType === 'partial') {
            if ($paidAmountInput <= 0) {
                $validationErrors[] = 'يجب إدخال مبلغ التحصيل الجزئي.';
            } elseif ($paidAmountInput >= $netTotal) {
                $validationErrors[] = 'مبلغ التحصيل الجزئي يجب أن يكون أقل من الإجمالي بعد الخصم.';
            } else {
                $effectivePaidAmount = $paidAmountInput;
            }
        } else { // credit
            $effectivePaidAmount = 0.0;
        }

        $dueAmount = round(max(0, $netTotal - $effectivePaidAmount), 2);

        $customerId = 0;
        $createdCustomerId = null;
        $customer = null;

        if ($customerMode === 'existing') {
            $customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
            if ($customerId <= 0) {
                $validationErrors[] = 'يجب اختيار عميل من القائمة.';
            } else {
                $customer = $db->queryOne("SELECT id, name, balance, created_by FROM customers WHERE id = ?", [$customerId]);
                if (!$customer) {
                    $validationErrors[] = 'العميل المحدد غير موجود.';
                } elseif (($currentUser['role'] ?? '') === 'sales' && isset($customer['created_by']) && (int) $customer['created_by'] !== (int) $currentUser['id']) {
                    $validationErrors[] = 'غير مصرح لك بإتمام البيع لهذا العميل.';
                }
            }
        } else {
            $newCustomerName = trim($_POST['new_customer_name'] ?? '');
            $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
            $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');

            if ($newCustomerName === '') {
                $validationErrors[] = 'يجب إدخال اسم العميل الجديد.';
            }
        }

        if (empty($validationErrors) && empty($normalizedCart)) {
            $validationErrors[] = 'قائمة المنتجات فارغة.';
        }

        if (empty($validationErrors)) {
            try {
                $conn = $db->getConnection();
                $conn->begin_transaction();

                if ($customerMode === 'new') {
                    $db->execute(
                        "INSERT INTO customers (name, phone, address, balance, status, created_by) VALUES (?, ?, ?, ?, 'active', ?)",
                        [
                            $newCustomerName,
                            $newCustomerPhone !== '' ? $newCustomerPhone : null,
                            $newCustomerAddress !== '' ? $newCustomerAddress : null,
                            $dueAmount,
                            $currentUser['id'],
                        ]
                    );
                    $customerId = (int) $db->getLastInsertId();
                    $createdCustomerId = $customerId;
                    $customer = [
                        'id' => $customerId,
                        'name' => $newCustomerName,
                        'balance' => $dueAmount,
                        'created_by' => $currentUser['id'],
                    ];
                } else {
                    $customer = $db->queryOne(
                        "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                        [$customerId]
                    );

                    if (!$customer) {
                        throw new RuntimeException('تعذر تحميل بيانات العميل أثناء المعالجة.');
                    }

                    if ($dueAmount > 0) {
                        $newBalance = round(((float) ($customer['balance'] ?? 0)) + $dueAmount, 2);
                        $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                        $customer['balance'] = $newBalance;
                    }
                }

                $invoiceItems = [];
                foreach ($normalizedCart as $item) {
                    $invoiceItems[] = [
                        'product_id' => $item['product_id'],
                        'description' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ];
                }

                $invoiceResult = createInvoice(
                    $customerId,
                    $currentUser['id'],
                    $saleDate,
                    $invoiceItems,
                    0,
                    $prepaidAmount,
                    $notes,
                    $currentUser['id']
                );

                if (empty($invoiceResult['success'])) {
                    throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة.');
                }

                $invoiceId = (int) $invoiceResult['invoice_id'];
                $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                $invoiceStatus = 'sent';
                if ($dueAmount <= 0.0001) {
                    $invoiceStatus = 'paid';
                } elseif ($effectivePaidAmount > 0) {
                    $invoiceStatus = 'partial';
                }

                $db->execute(
                    "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$effectivePaidAmount, $dueAmount, $invoiceStatus, $invoiceId]
                );

                $totalSoldValue = 0;

                foreach ($normalizedCart as $item) {
                    $productId = $item['product_id'];
                    $quantity = $item['quantity'];
                    $unitPrice = $item['unit_price'];
                    $lineTotal = $item['line_total'];

                    $product = $inventoryByProduct[$productId] ?? null;
                    $available = $product ? (float) ($product['quantity'] ?? 0) : 0;

                    if ($product === null || $quantity > $available) {
                        throw new RuntimeException('الكمية المتاحة للمنتج ' . $item['name'] . ' تغيرت أثناء المعالجة.');
                    }

                    $newQuantity = max(0, $available - $quantity);
                    $updateResult = updateVehicleInventory($vehicle['id'], $productId, $newQuantity, $currentUser['id']);
                    if (empty($updateResult['success'])) {
                        throw new RuntimeException($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة.');
                    }

                    $movementResult = recordInventoryMovement(
                        $productId,
                        $vehicleWarehouseId,
                        'out',
                        $quantity,
                        'sales',
                        $invoiceId,
                        'بيع من نقطة بيع المندوب - فاتورة ' . $invoiceNumber,
                        $currentUser['id']
                    );

                    if (empty($movementResult['success'])) {
                        throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                    }

                    $db->execute(
                        "INSERT INTO sales (customer_id, product_id, quantity, price, total, date, salesperson_id, status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')",
                        [$customerId, $productId, $quantity, $unitPrice, $lineTotal, $saleDate, $currentUser['id']]
                    );

                    $inventoryByProduct[$productId]['quantity'] = $newQuantity;
                    $inventoryByProduct[$productId]['total_value'] = ($newQuantity * $unitPrice);
                    $totalSoldValue += $lineTotal;
                }

                logAudit($currentUser['id'], 'create_pos_sale_multi', 'invoice', $invoiceId, null, [
                    'invoice_number' => $invoiceNumber,
                    'items' => $normalizedCart,
                    'net_total' => $netTotal,
                    'paid_amount' => $effectivePaidAmount,
                    'due_amount' => $dueAmount,
                    'customer_id' => $customerId,
                ]);

                $conn->commit();

                $invoiceData = getInvoice($invoiceId);
                $invoiceMeta = [
                    'summary' => [
                        'subtotal' => $subtotal,
                        'prepaid' => $prepaidAmount,
                        'net_total' => $netTotal,
                        'paid' => $effectivePaidAmount,
                        'due' => $dueAmount,
                    ],
                ];
                $reportInfo = $invoiceData ? storeSalesInvoiceDocument($invoiceData, $invoiceMeta) : null;

                if ($reportInfo) {
                    $telegramResult = sendReportAndDelete($reportInfo, 'sales_pos_invoice', 'فاتورة نقطة بيع المندوب');
                    $reportInfo['telegram_sent'] = !empty($telegramResult['success']);
                    $posInvoiceLinks = $reportInfo;
                }

                $success = 'تم إتمام عملية البيع بنجاح. رقم الفاتورة: ' . htmlspecialchars($invoiceNumber);
                if ($createdCustomerId) {
                    $success .= ' - تم إنشاء العميل الجديد.';
                }

                // إعادة تحميل المخزون والإحصائيات
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

                // تحديث الخريطة بعد البيع
                $inventoryByProduct = [];
                foreach ($vehicleInventory as $item) {
                    $inventoryByProduct[(int) ($item['product_id'] ?? 0)] = $item;
                }

            } catch (Throwable $exception) {
                if (isset($conn) && $conn->errno === 0) {
                    $conn->rollback();
                } elseif (isset($conn) && $conn->errno !== 0) {
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

