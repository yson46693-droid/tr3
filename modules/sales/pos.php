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
        <?php if (!empty($posInvoiceLinks['absolute_report_url'])): ?>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <a href="<?php echo htmlspecialchars($posInvoiceLinks['absolute_report_url']); ?>" target="_blank" class="btn btn-light btn-sm">
                    <i class="bi bi-eye me-1"></i>عرض الفاتورة
                </a>
                <a href="<?php echo htmlspecialchars($posInvoiceLinks['absolute_print_url'] ?? $posInvoiceLinks['absolute_report_url']); ?>" target="_blank" class="btn btn-primary btn-sm">
                    <i class="bi bi-printer me-1"></i>طباعة الفاتورة
                </a>
            </div>
        <?php endif; ?>
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
    <style>
        .pos-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .pos-vehicle-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }
        .pos-summary-card {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            padding: 1.5rem;
            color: #ffffff;
            background: linear-gradient(135deg, rgba(30,58,95,0.95), rgba(44,82,130,0.88));
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.15);
        }
        .pos-summary-card .label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            opacity: 0.8;
        }
        .pos-summary-card .value {
            font-size: 1.45rem;
            font-weight: 700;
            margin-top: 0.35rem;
        }
        .pos-summary-card .meta {
            margin-top: 0.35rem;
            font-size: 0.9rem;
            opacity: 0.85;
        }
        .pos-summary-card .icon {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            font-size: 2.4rem;
            opacity: 0.12;
        }
        .pos-content {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
        }
        .pos-panel {
            background: #fff;
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: 0 16px 35px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(15, 23, 42, 0.05);
        }
        .pos-panel-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.15rem;
        }
        .pos-panel-header h4,
        .pos-panel-header h5 {
            margin: 0;
            font-weight: 700;
            color: #1f2937;
        }
        .pos-panel-header p {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
        }
        .pos-search {
            position: relative;
            flex: 1;
            min-width: 220px;
        }
        .pos-search input {
            border-radius: 12px;
            padding-inline-start: 2.75rem;
            height: 3rem;
        }
        .pos-search i {
            position: absolute;
            inset-inline-start: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 1.1rem;
        }
        .pos-product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.25rem;
        }
        .pos-product-card {
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            padding: 1.15rem;
            background: #f8fafc;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: relative;
        }
        .pos-product-card:hover {
            transform: translateY(-4px);
            border-color: rgba(30, 58, 95, 0.35);
            box-shadow: 0 18px 40px rgba(30, 58, 95, 0.15);
        }
        .pos-product-card.active {
            border-color: rgba(30, 58, 95, 0.65);
            background: #ffffff;
            box-shadow: 0 20px 45px rgba(30, 58, 95, 0.18);
        }
        .pos-product-name {
            font-size: 1.08rem;
            font-weight: 700;
            color: #1f2937;
        }
        .pos-product-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.4rem;
            font-size: 0.85rem;
            color: #475569;
        }
        .pos-product-badge {
            background: rgba(30, 58, 95, 0.08);
            color: #1e3a5f;
            border-radius: 999px;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
        }
        .pos-product-qty {
            font-weight: 700;
            color: #059669;
        }
        .pos-select-btn {
            margin-top: auto;
            border-radius: 12px;
            font-weight: 600;
        }
        .pos-checkout-panel {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .pos-selected-product {
            display: none;
            border-radius: 18px;
            padding: 1.25rem;
            color: #fff;
            background: linear-gradient(135deg, rgba(6,78,59,0.95), rgba(16,185,129,0.9));
            box-shadow: 0 18px 40px rgba(6, 78, 59, 0.25);
        }
        .pos-selected-product.active {
            display: block;
        }
        .pos-selected-product h5 {
            margin-bottom: 0.75rem;
        }
        .pos-selected-product .meta-row {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .pos-selected-product .meta-block span {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: uppercase;
        }
        .pos-form .form-control,
        .pos-form .form-select {
            border-radius: 12px;
        }
        .pos-cart-table thead {
            background: #f1f5f9;
            font-size: 0.9rem;
        }
        .pos-cart-table td {
            vertical-align: middle;
        }
        .pos-qty-control {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .pos-qty-control .btn {
            border-radius: 999px;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pos-qty-control input {
            width: 80px;
            text-align: center;
        }
        .pos-summary-card-neutral {
            background: #0f172a;
            color: #fff;
            border-radius: 16px;
            padding: 1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .pos-summary-card-neutral .total {
            font-size: 1.45rem;
            font-weight: 700;
        }
        .pos-payment-options {
            display: grid;
            gap: 0.6rem;
        }
        .pos-payment-options .form-check {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .pos-history-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 320px;
            overflow-y: auto;
        }
        .pos-sale-card {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            padding: 0.95rem 1.15rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }
        .pos-sale-card .meta {
            font-size: 0.85rem;
            color: #64748b;
        }
        .pos-empty {
            border-radius: 18px;
            background: #f8fafc;
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: #475569;
        }
        .pos-empty-inline {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 200px;
        }
        .pos-empty i {
            font-size: 2.6rem;
            color: #94a3b8;
        }
        .pos-cart-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: #6b7280;
        }
        .pos-inline-note {
            font-size: 0.82rem;
            color: #64748b;
        }
        @media (max-width: 992px) {
            .pos-content {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 576px) {
            .pos-summary-card {
                padding: 1.15rem;
            }
            .pos-panel {
                padding: 1.25rem;
            }
            .pos-product-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
        }
    </style>

    <div class="pos-wrapper">
        <section class="pos-vehicle-summary">
            <div class="pos-summary-card">
                <span class="label">معلومات السيارة</span>
                <div class="value"><?php echo htmlspecialchars($vehicle['vehicle_number'] ?? '-'); ?></div>
                <div class="meta">الموديل: <?php echo htmlspecialchars($vehicle['model'] ?? 'غير محدد'); ?></div>
                <i class="bi bi-truck icon"></i>
            </div>
            <div class="pos-summary-card">
                <span class="label">عدد المنتجات</span>
                <div class="value"><?php echo number_format($inventoryStats['total_products']); ?></div>
                <div class="meta">أصناف جاهزة للبيع</div>
                <i class="bi bi-box-seam icon"></i>
            </div>
            <div class="pos-summary-card">
                <span class="label">إجمالي الكمية</span>
                <div class="value"><?php echo number_format($inventoryStats['total_quantity'], 2); ?></div>
                <div class="meta">إجمالي الوحدات في السيارة</div>
                <i class="bi bi-stack icon"></i>
            </div>
            <div class="pos-summary-card">
                <span class="label">قيمة المخزون</span>
                <div class="value"><?php echo formatCurrency($inventoryStats['total_value']); ?></div>
                <div class="meta">التقييم الإجمالي الحالي</div>
                <i class="bi bi-cash-stack icon"></i>
            </div>
        </section>

        <?php if (empty($vehicleInventory)): ?>
            <div class="pos-empty">
                <i class="bi bi-box"></i>
                <h5 class="mt-3 mb-2">لا يوجد مخزون متاح حالياً</h5>
                <p class="mb-0">اطلب تزويد السيارة بالمنتجات لبدء البيع من نقطة البيع الميدانية.</p>
            </div>
        <?php else: ?>
            <section class="pos-content">
                <div class="pos-panel" style="grid-column: span 7;">
                    <div class="pos-panel-header">
                        <div>
                            <h4>مخزون السيارة</h4>
                            <p>اضغط على المنتج لإضافته إلى سلة البيع</p>
                        </div>
                        <div class="pos-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="posInventorySearch" class="form-control" placeholder="بحث سريع عن منتج...">
                        </div>
                    </div>
                    <div class="pos-product-grid" id="posProductGrid">
                        <?php foreach ($vehicleInventory as $item): ?>
                            <div class="pos-product-card" data-product-card data-product-id="<?php echo (int) $item['product_id']; ?>" data-name="<?php echo htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-category="<?php echo htmlspecialchars($item['category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="pos-product-name"><?php echo htmlspecialchars($item['product_name'] ?? 'منتج'); ?></div>
                                <?php if (!empty($item['category'])): ?>
                                    <div class="pos-product-meta">
                                        <span class="pos-product-badge"><?php echo htmlspecialchars($item['category']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="pos-product-meta">
                                    <span>سعر الوحدة</span>
                                    <strong><?php echo formatCurrency((float) ($item['unit_price'] ?? 0)); ?></strong>
                                </div>
                                <div class="pos-product-meta">
                                    <span>الكمية المتاحة</span>
                                    <span class="pos-product-qty"><?php echo number_format((float) ($item['quantity'] ?? 0), 2); ?></span>
                                </div>
                                <div class="pos-product-meta">
                                    <span>آخر تحديث</span>
                                    <span><?php echo !empty($item['last_updated_at']) ? formatDateTime($item['last_updated_at']) : '-'; ?></span>
                                </div>
                                <button type="button"
                                        class="btn btn-outline-primary pos-select-btn"
                                        data-select-product
                                        data-product-id="<?php echo (int) $item['product_id']; ?>">
                                    <i class="bi bi-plus-circle me-2"></i>إضافة إلى السلة
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pos-panel pos-checkout-panel" style="grid-column: span 5;">
                    <div class="pos-selected-product" id="posSelectedProduct">
                        <h5 class="mb-3">تفاصيل المنتج المختار</h5>
                        <div class="meta-row">
                            <div class="meta-block">
                                <span>المنتج</span>
                                <div class="fw-semibold" id="posSelectedProductName">-</div>
                            </div>
                            <div class="meta-block">
                                <span>التصنيف</span>
                                <div class="fw-semibold" id="posSelectedProductCategory">-</div>
                            </div>
                        </div>
                        <div class="meta-row mt-3">
                            <div class="meta-block">
                                <span>السعر</span>
                                <div class="fw-semibold" id="posSelectedProductPrice">-</div>
                            </div>
                            <div class="meta-block">
                                <span>المتوفر</span>
                                <div class="fw-semibold" id="posSelectedProductStock">-</div>
                            </div>
                        </div>
                    </div>

                    <div class="pos-panel">
                        <form method="post" id="posSaleForm" class="pos-form needs-validation" novalidate>
                            <input type="hidden" name="action" value="create_pos_sale">
                            <input type="hidden" name="cart_data" id="posCartData">
                            <input type="hidden" name="paid_amount" id="posPaidField" value="0">

                            <div class="row g-3 mb-3 align-items-end">
                                <div class="col-sm-6">
                                    <label class="form-label">تاريخ العملية</label>
                                    <input type="date" class="form-control" name="sale_date" id="posSaleDate" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">اختيار العميل</label>
                                    <div class="d-flex gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="customer_mode" id="posCustomerModeExisting" value="existing" checked>
                                            <label class="form-check-label" for="posCustomerModeExisting">عميل حالي</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="customer_mode" id="posCustomerModeNew" value="new">
                                            <label class="form-check-label" for="posCustomerModeNew">عميل جديد</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3" id="posExistingCustomerWrap">
                                <label class="form-label">العملاء المسجلون</label>
                                <select class="form-select" id="posCustomerSelect" name="customer_id" required>
                                    <option value="">اختر العميل</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo (int) $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3 d-none" id="posNewCustomerWrap">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">اسم العميل</label>
                                        <input type="text" class="form-control" name="new_customer_name" id="posNewCustomerName" placeholder="اسم العميل الجديد">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label">رقم الهاتف <span class="text-muted">(اختياري)</span></label>
                                        <input type="text" class="form-control" name="new_customer_phone" placeholder="مثال: 01012345678">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label">العنوان <span class="text-muted">(اختياري)</span></label>
                                        <input type="text" class="form-control" name="new_customer_address" placeholder="عنوان العميل">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="mb-0">سلة البيع</h5>
                                    <button type="button" class="btn btn-link text-danger p-0" id="posClearCartBtn">
                                        <i class="bi bi-trash me-1"></i>تفريغ السلة
                                    </button>
                                </div>
                                <div class="pos-cart-empty" id="posCartEmpty">
                                    <i class="bi bi-basket3"></i>
                                    <p class="mt-2 mb-0">لم يتم اختيار أي منتجات بعد. اضغط على البطاقة لإضافتها.</p>
                                </div>
                                <div class="table-responsive d-none" id="posCartTableWrapper">
                                    <table class="table pos-cart-table align-middle">
                                        <thead>
                                            <tr>
                                                <th>المنتج</th>
                                                <th width="180">الكمية</th>
                                                <th width="160">سعر الوحدة</th>
                                                <th width="160">الإجمالي</th>
                                                <th width="70"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="posCartBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="row g-3 align-items-start mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label">مدفوع مسبقاً (اختياري)</label>
                                    <input type="number" step="0.01" min="0" value="0" class="form-control" id="posPrepaidInput" name="prepaid_amount">
                                    <div class="pos-inline-note">سيتم خصم المبلغ من إجمالي السلة.</div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pos-summary-card-neutral">
                                        <span class="small text-uppercase opacity-75">الإجمالي بعد الخصم</span>
                                        <span class="total" id="posNetTotal">0</span>
                                        <span class="small text-uppercase opacity-75">المتبقي على العميل</span>
                                        <span class="fw-semibold" id="posDueAmount">0</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">طريقة الدفع</label>
                                <div class="pos-payment-options">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_type" id="posPaymentFull" value="full" checked>
                                        <label class="form-check-label" for="posPaymentFull">دفع كامل الآن</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_type" id="posPaymentPartial" value="partial">
                                        <label class="form-check-label" for="posPaymentPartial">تحصيل جزئي الآن</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_type" id="posPaymentCredit" value="credit">
                                        <label class="form-check-label" for="posPaymentCredit">بيع بالآجل (دون تحصيل فوري)</label>
                                    </div>
                                </div>
                                <div class="mt-3 d-none" id="posPartialWrapper">
                                    <label class="form-label">مبلغ التحصيل الجزئي</label>
                                    <input type="number" step="0.01" min="0" value="0" class="form-control" id="posPartialAmount">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ملاحظات إضافية <span class="text-muted">(اختياري)</span></label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="مثال: تعليمات التسليم، شروط خاصة..."></textarea>
                            </div>

                            <div class="d-flex flex-wrap gap-2 justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" id="posResetFormBtn">
                                    <i class="bi bi-arrow-repeat me-1"></i>إعادة تعيين
                                </button>
                                <button type="submit" class="btn btn-success" id="posSubmitBtn" disabled>
                                    <i class="bi bi-check-circle me-2"></i>إتمام عملية البيع
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="pos-panel">
                        <div class="pos-panel-header">
                            <div>
                                <h5>آخر عمليات البيع</h5>
                                <p>أحدث عملياتك خلال الفترة الأخيرة</p>
                            </div>
                        </div>
                        <?php if (empty($recentSales)): ?>
                            <div class="pos-empty mb-0">
                                <div class="empty-state-icon mb-2"><i class="bi bi-receipt"></i></div>
                                <div class="empty-state-title">لا توجد عمليات بيع مسجلة</div>
                                <div class="empty-state-description">ابدأ ببيع منتجات مخزون السيارة ليظهر السجل هنا.</div>
                            </div>
                        <?php else: ?>
                            <div class="pos-history-list">
                                <?php foreach ($recentSales as $sale): ?>
                                    <div class="pos-sale-card">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($sale['product_name'] ?? '-'); ?></div>
                                            <div class="meta">
                                                <?php echo formatDate($sale['date']); ?> • <?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold mb-1"><?php echo formatCurrency((float) ($sale['total'] ?? 0)); ?></div>
                                            <span class="badge bg-success">مكتمل</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$error && !empty($vehicleInventory)): ?>
<script>
(function () {
    const locale = <?php echo json_encode($pageDirection === 'rtl' ? 'ar-EG' : 'en-US'); ?>;
    const currencySymbol = <?php echo json_encode(CURRENCY_SYMBOL); ?>;
    const inventory = <?php
        $inventoryForJs = [];
        foreach ($vehicleInventory as $item) {
            $inventoryForJs[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => $item['product_name'] ?? '',
                'category' => $item['category'] ?? '',
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'unit' => $item['unit'] ?? '',
                'total_value' => (float) ($item['total_value'] ?? 0),
                'last_updated_at' => $item['last_updated_at'] ?? null,
            ];
        }
        echo json_encode($inventoryForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    const inventoryMap = new Map(inventory.map((item) => [item.product_id, item]));
    const cart = [];

    const elements = {
        form: document.getElementById('posSaleForm'),
        cartData: document.getElementById('posCartData'),
        paidField: document.getElementById('posPaidField'),
        cartBody: document.getElementById('posCartBody'),
        cartEmpty: document.getElementById('posCartEmpty'),
        cartTableWrapper: document.getElementById('posCartTableWrapper'),
        clearCart: document.getElementById('posClearCartBtn'),
        resetForm: document.getElementById('posResetFormBtn'),
        netTotal: document.getElementById('posNetTotal'),
        dueAmount: document.getElementById('posDueAmount'),
        prepaidInput: document.getElementById('posPrepaidInput'),
        paymentRadios: document.querySelectorAll('input[name="payment_type"]'),
        partialWrapper: document.getElementById('posPartialWrapper'),
        partialInput: document.getElementById('posPartialAmount'),
        submitBtn: document.getElementById('posSubmitBtn'),
        customerModeRadios: document.querySelectorAll('input[name="customer_mode"]'),
        existingCustomerWrap: document.getElementById('posExistingCustomerWrap'),
        customerSelect: document.getElementById('posCustomerSelect'),
        newCustomerWrap: document.getElementById('posNewCustomerWrap'),
        newCustomerName: document.getElementById('posNewCustomerName'),
        inventoryCards: document.querySelectorAll('[data-product-card]'),
        inventoryButtons: document.querySelectorAll('[data-select-product]'),
        inventorySearch: document.getElementById('posInventorySearch'),
        selectedPanel: document.getElementById('posSelectedProduct'),
        selectedName: document.getElementById('posSelectedProductName'),
        selectedCategory: document.getElementById('posSelectedProductCategory'),
        selectedPrice: document.getElementById('posSelectedProductPrice'),
        selectedStock: document.getElementById('posSelectedProductStock'),
    };

    function roundTwo(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }

    function formatCurrency(value) {
        return (value || 0).toLocaleString(locale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + ' ' + currencySymbol;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>\"']/g, function (char) {
            const escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return escapeMap[char] || char;
        });
    }

    function renderSelectedProduct(product) {
        if (!product || !elements.selectedPanel) {
            return;
        }
        elements.selectedPanel.classList.add('active');
        elements.selectedName.textContent = product.name || '-';
        elements.selectedCategory.textContent = product.category || 'غير مصنف';
        elements.selectedPrice.textContent = formatCurrency(product.unit_price || 0);
        elements.selectedStock.textContent = (product.quantity ?? 0).toFixed(2);
    }

    function syncCartData() {
        const payload = cart.map((item) => ({
            product_id: item.product_id,
            quantity: roundTwo(item.quantity),
            unit_price: roundTwo(item.unit_price),
        }));
        elements.cartData.value = JSON.stringify(payload);
    }

    function updateSummary() {
        const subtotal = cart.reduce((total, item) => total + (item.quantity * item.unit_price), 0);
        let prepaid = parseFloat(elements.prepaidInput.value) || 0;
        let sanitizedSubtotal = roundTwo(subtotal);

        if (prepaid < 0) {
            prepaid = 0;
        }
        if (prepaid > sanitizedSubtotal) {
            prepaid = sanitizedSubtotal;
        }
        elements.prepaidInput.value = prepaid.toFixed(2);

        const netTotal = roundTwo(sanitizedSubtotal - prepaid);
        let paidAmount = 0;
        const paymentType = Array.from(elements.paymentRadios).find((radio) => radio.checked)?.value || 'full';

        if (paymentType === 'full') {
            paidAmount = netTotal;
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '0.00';
        } else if (paymentType === 'partial') {
            elements.partialWrapper.classList.remove('d-none');
            let partialValue = parseFloat(elements.partialInput.value) || 0;
            if (partialValue < 0) {
                partialValue = 0;
            }
            if (partialValue >= netTotal && netTotal > 0) {
                partialValue = Math.max(0, netTotal - 0.01);
            }
            elements.partialInput.value = partialValue.toFixed(2);
            paidAmount = partialValue;
        } else {
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '0.00';
            paidAmount = 0;
        }

        const dueAmount = roundTwo(Math.max(0, netTotal - paidAmount));

        if (elements.netTotal) {
            elements.netTotal.textContent = formatCurrency(netTotal);
        }
        if (elements.dueAmount) {
            elements.dueAmount.textContent = formatCurrency(dueAmount);
        }

        elements.paidField.value = paidAmount.toFixed(2);
        elements.submitBtn.disabled = cart.length === 0;
        syncCartData();
    }

    function renderCart() {
        if (!elements.cartBody || !elements.cartTableWrapper || !elements.cartEmpty) {
            return;
        }

        if (!cart.length) {
            elements.cartBody.innerHTML = '';
            elements.cartTableWrapper.classList.add('d-none');
            elements.cartEmpty.classList.remove('d-none');
            updateSummary();
            return;
        }

        elements.cartTableWrapper.classList.remove('d-none');
        elements.cartEmpty.classList.add('d-none');

        const rows = cart.map((item) => {
            return `
                <tr data-cart-row data-product-id="${item.product_id}">
                    <td>
                        <div class="fw-semibold">${escapeHtml(item.name)}</div>
                        <div class="text-muted small">التصنيف: ${escapeHtml(item.category || 'غير مصنف')} • متاح: ${item.available.toFixed(2)}</div>
                    </td>
                    <td>
                        <div class="pos-qty-control">
                            <button type="button" class="btn btn-light border" data-action="decrease" data-product-id="${item.product_id}"><i class="bi bi-dash"></i></button>
                            <input type="number" step="0.01" min="0" class="form-control" data-cart-qty data-product-id="${item.product_id}" value="${item.quantity.toFixed(2)}">
                            <button type="button" class="btn btn-light border" data-action="increase" data-product-id="${item.product_id}"><i class="bi bi-plus"></i></button>
                        </div>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" class="form-control" data-cart-price data-product-id="${item.product_id}" value="${item.unit_price.toFixed(2)}">
                    </td>
                    <td class="fw-semibold">${formatCurrency(item.quantity * item.unit_price)}</td>
                    <td class="text-end">
                        <button type="button" class="btn btn-link text-danger" data-action="remove" data-product-id="${item.product_id}"><i class="bi bi-x-circle"></i></button>
                    </td>
                </tr>`;
        }).join('');

        elements.cartBody.innerHTML = rows;
        updateSummary();
    }

    function addToCart(productId) {
        const product = inventoryMap.get(productId);
        if (!product) {
            return;
        }
        const existing = cart.find((item) => item.product_id === productId);
        if (existing) {
            if (existing.quantity + 1 > product.quantity) {
                existing.quantity = product.quantity;
            } else {
                existing.quantity = roundTwo(existing.quantity + 1);
            }
        } else {
            if (product.quantity <= 0) {
                return;
            }
            cart.push({
                product_id: product.product_id,
                name: product.name,
                category: product.category,
                quantity: Math.min(1, product.quantity),
                available: product.quantity,
                unit_price: product.unit_price > 0 ? product.unit_price : 0,
            });
        }
        renderSelectedProduct(product);
        renderCart();
    }

    function removeFromCart(productId) {
        const index = cart.findIndex((item) => item.product_id === productId);
        if (index >= 0) {
            cart.splice(index, 1);
            renderCart();
        }
    }

    function adjustQuantity(productId, delta) {
        const item = cart.find((entry) => entry.product_id === productId);
        const product = inventoryMap.get(productId);
        if (!item || !product) {
            return;
        }
        let newQuantity = roundTwo(item.quantity + delta);
        if (newQuantity <= 0) {
            removeFromCart(productId);
            return;
        }
        if (newQuantity > product.quantity) {
            newQuantity = product.quantity;
        }
        item.quantity = newQuantity;
        renderCart();
    }

    function updateQuantity(productId, value) {
        const item = cart.find((entry) => entry.product_id === productId);
        const product = inventoryMap.get(productId);
        if (!item || !product) {
            return;
        }
        let qty = parseFloat(value) || 0;
        if (qty <= 0) {
            removeFromCart(productId);
            return;
        }
        if (qty > product.quantity) {
            qty = product.quantity;
        }
        item.quantity = roundTwo(qty);
        renderCart();
    }

    function updateUnitPrice(productId, value) {
        const item = cart.find((entry) => entry.product_id === productId);
        if (!item) {
            return;
        }
        let price = parseFloat(value) || 0;
        if (price < 0) {
            price = 0;
        }
        item.unit_price = roundTwo(price);
        renderCart();
    }

    elements.inventoryButtons.forEach((button) => {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const productId = parseInt(this.dataset.productId, 10);
            addToCart(productId);
        });
    });

    elements.inventoryCards.forEach((card) => {
        card.addEventListener('click', function () {
            const productId = parseInt(this.dataset.productId, 10);
            elements.inventoryCards.forEach((c) => c.classList.remove('active'));
            this.classList.add('active');
            renderSelectedProduct(inventoryMap.get(productId));
        });
    });

    if (elements.inventorySearch) {
        elements.inventorySearch.addEventListener('input', function () {
            const term = (this.value || '').toLowerCase().trim();
            elements.inventoryCards.forEach((card) => {
                const name = (card.dataset.name || '').toLowerCase();
                const category = (card.dataset.category || '').toLowerCase();
                const matches = !term || name.includes(term) || category.includes(term);
                card.style.display = matches ? '' : 'none';
            });
        });
    }

    if (elements.cartBody) {
        elements.cartBody.addEventListener('click', function (event) {
            const action = event.target.closest('[data-action]');
            if (!action) {
                return;
            }
            const productId = parseInt(action.dataset.productId, 10);
            switch (action.dataset.action) {
                case 'increase':
                    adjustQuantity(productId, 1);
                    break;
                case 'decrease':
                    adjustQuantity(productId, -1);
                    break;
                case 'remove':
                    removeFromCart(productId);
                    break;
            }
        });

        elements.cartBody.addEventListener('input', function (event) {
            const qtyInput = event.target.matches('[data-cart-qty]') ? event.target : null;
            const priceInput = event.target.matches('[data-cart-price]') ? event.target : null;
            const productId = parseInt(event.target.dataset.productId || '0', 10);
            if (qtyInput) {
                updateQuantity(productId, qtyInput.value);
            }
            if (priceInput) {
                updateUnitPrice(productId, priceInput.value);
            }
        });
    }

    if (elements.clearCart) {
        elements.clearCart.addEventListener('click', function () {
            cart.length = 0;
            renderCart();
        });
    }

    if (elements.resetForm) {
        elements.resetForm.addEventListener('click', function () {
            cart.length = 0;
            renderCart();
            elements.form?.reset();
            elements.partialWrapper?.classList.add('d-none');
            elements.submitBtn.disabled = true;
            elements.selectedPanel?.classList.remove('active');
        });
    }

    if (elements.prepaidInput) {
        elements.prepaidInput.addEventListener('input', updateSummary);
    }

    if (elements.partialInput) {
        elements.partialInput.addEventListener('input', updateSummary);
    }

    elements.paymentRadios.forEach((radio) => {
        radio.addEventListener('change', updateSummary);
    });

    elements.customerModeRadios.forEach((radio) => {
        radio.addEventListener('change', function () {
            const mode = this.value;
            if (mode === 'existing') {
                elements.existingCustomerWrap?.classList.remove('d-none');
                elements.customerSelect?.setAttribute('required', 'required');
                elements.newCustomerWrap?.classList.add('d-none');
                elements.newCustomerName?.removeAttribute('required');
            } else {
                elements.existingCustomerWrap?.classList.add('d-none');
                elements.customerSelect?.removeAttribute('required');
                elements.newCustomerWrap?.classList.remove('d-none');
                elements.newCustomerName?.setAttribute('required', 'required');
            }
        });
    });

    if (elements.form) {
        elements.form.addEventListener('submit', function (event) {
            if (!cart.length) {
                event.preventDefault();
                event.stopPropagation();
                alert('يرجى إضافة منتجات إلى السلة قبل إتمام العملية.');
                return;
            }
            updateSummary();
            if (!elements.form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            elements.form.classList.add('was-validated');
        });
    }

    // تهيئة أولية للقيم
    renderCart();
})();
</script>
<?php endif; ?>

