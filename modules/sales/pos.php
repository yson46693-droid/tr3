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
require_once __DIR__ . '/../../includes/customer_history.php';

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

            $token = bin2hex(random_bytes(8));
            $normalizedNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'INV'));
            // تضمين الـ token في اسم الملف للتحقق من الأمان في reports/view.php
            $filename = sprintf('pos-invoice-%s-%s-%s.html', date('Ymd-His'), $normalizedNumber, $token);
            $fullPath = $salesDir . DIRECTORY_SEPARATOR . $filename;

            // حفظ الملف (للاستخدام الفوري)
            if (@file_put_contents($fullPath, $document) === false) {
                return null;
            }

            // حفظ الفاتورة في قاعدة البيانات للوصول إليها لاحقاً
            try {
                require_once __DIR__ . '/../../includes/db.php';
                $db = db();
                
                // التأكد من وجود الجدول
                $tableExists = $db->queryOne("SHOW TABLES LIKE 'telegram_invoices'");
                if ($tableExists) {
                    $invoiceId = isset($invoice['id']) ? (int)$invoice['id'] : null;
                    $invoiceNumber = $invoice['invoice_number'] ?? null;
                    $summaryJson = !empty($meta['summary']) ? json_encode($meta['summary'], JSON_UNESCAPED_UNICODE) : null;
                    
                    $relativePath = 'exports/sales-pos/' . $filename;
                    
                    $db->execute(
                        "INSERT INTO telegram_invoices 
                        (invoice_id, invoice_number, invoice_type, token, html_content, relative_path, filename, summary, created_at)
                        VALUES (?, ?, 'sales_pos_invoice', ?, ?, ?, ?, ?, NOW())",
                        [
                            $invoiceId,
                            $invoiceNumber,
                            $token,
                            $document,
                            $relativePath,
                            $filename,
                            $summaryJson
                        ]
                    );
                }
            } catch (Throwable $dbError) {
                // في حالة فشل حفظ قاعدة البيانات، نستمر في العملية
                error_log('Failed to save invoice to database: ' . $dbError->getMessage());
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

    $customerSql = "SELECT id, name, balance FROM customers WHERE 1=1";
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

    foreach ($vehicleInventory as &$item) {
        $item['quantity'] = cleanFinancialValue($item['quantity'] ?? 0);
        $item['unit_price'] = cleanFinancialValue($item['unit_price'] ?? 0);
        $computedTotal = cleanFinancialValue(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0));
        $item['total_value'] = cleanFinancialValue($item['total_value'] ?? $computedTotal);
        if (abs($item['total_value'] - $computedTotal) > 0.01) {
            $item['total_value'] = $computedTotal;
        }

        $inventoryStats['total_products']++;
        $inventoryStats['total_quantity'] += (float) ($item['quantity'] ?? 0);
        $inventoryStats['total_value'] += (float) ($item['total_value'] ?? 0);
    }
    unset($item);
}

// معالجة إنشاء عملية بيع جديدة
$posInvoiceLinks = null;
$inventoryByProduct = [];
foreach ($vehicleInventory as $item) {
    // استخدام مفتاح مركب من product_id و finished_batch_id للتمييز بين التشغيلات المختلفة
    $productId = (int) ($item['product_id'] ?? 0);
    $batchId = !empty($item['finished_batch_id']) ? (int) $item['finished_batch_id'] : 0;
    $key = $productId . '_' . $batchId;
    $inventoryByProduct[$key] = $item;
    // أيضاً نحتفظ بنسخة بالمفتاح القديم للتوافق مع الكود القديم (إذا لم يكن هناك batch_id)
    if ($batchId === 0) {
        $inventoryByProduct[$productId] = $item;
    }
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
        $dueDateInput = trim($_POST['due_date'] ?? '');
        $dueDate = null;
        if (!empty($dueDateInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateInput)) {
            $dueDate = $dueDateInput;
        }

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
                $batchId = isset($row['finished_batch_id']) ? (int) $row['finished_batch_id'] : 0;
                $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 0;
                $unitPrice = isset($row['unit_price']) ? round((float) $row['unit_price'], 2) : 0;

                // البحث باستخدام المفتاح المركب أولاً، ثم المفتاح القديم للتوافق
                $key = $productId . '_' . $batchId;
                $product = $inventoryByProduct[$key] ?? ($batchId === 0 ? ($inventoryByProduct[$productId] ?? null) : null);

                if ($productId <= 0 || !$product) {
                    $validationErrors[] = 'المنتج المحدد رقم ' . ($index + 1) . ' غير متاح في مخزون السيارة.';
                    continue;
                }

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

                // الحصول على finished_batch_id من المنتج
                $finishedBatchId = !empty($product['finished_batch_id']) ? (int)$product['finished_batch_id'] : null;

                $normalizedCart[] = [
                    'product_id' => $productId,
                    'finished_batch_id' => $finishedBatchId,
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

        $baseDueAmount = round(max(0, $netTotal - $effectivePaidAmount), 2);
        $dueAmount = $baseDueAmount;
        $creditUsed = 0.0;

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
            $newCustomerLatitude = isset($_POST['new_customer_latitude']) && $_POST['new_customer_latitude'] !== '' ? trim($_POST['new_customer_latitude']) : null;
            $newCustomerLongitude = isset($_POST['new_customer_longitude']) && $_POST['new_customer_longitude'] !== '' ? trim($_POST['new_customer_longitude']) : null;

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
                    $dueAmount = $baseDueAmount;
                    $creditUsed = 0.0;
                    $amountAddedToSales = $netTotal; // كامل المبلغ يُضاف إلى خزنة المندوب للعميل الجديد
                    $repIdForCustomer = ($currentUser['role'] ?? '') === 'sales' ? $currentUser['id'] : null;
                    $createdByAdminFlag = $repIdForCustomer ? 0 : 1;

                    // التحقق من عدم تكرار بيانات العميل الجديد مع عملاء المندوب الحاليين
                    if ($repIdForCustomer) {
                        $duplicateCheckConditions = [
                            "(rep_id = ? OR created_by = ?)",
                            "name = ?"
                        ];
                        $duplicateCheckParams = [$repIdForCustomer, $repIdForCustomer, $newCustomerName];
                        
                        // إضافة فحص رقم الهاتف إذا كان موجوداً
                        if (!empty($newCustomerPhone)) {
                            $duplicateCheckConditions[] = "phone = ?";
                            $duplicateCheckParams[] = $newCustomerPhone;
                        }
                        
                        // إضافة فحص العنوان إذا كان موجوداً
                        if (!empty($newCustomerAddress)) {
                            $duplicateCheckConditions[] = "address = ?";
                            $duplicateCheckParams[] = $newCustomerAddress;
                        }
                        
                        $duplicateQuery = "SELECT id, name, phone, address FROM customers WHERE " . implode(" AND ", $duplicateCheckConditions) . " LIMIT 1";
                        $duplicateCustomer = $db->queryOne($duplicateQuery, $duplicateCheckParams);
                        
                        if ($duplicateCustomer) {
                            $duplicateInfo = [];
                            if (!empty($duplicateCustomer['phone'])) {
                                $duplicateInfo[] = "رقم الهاتف: " . $duplicateCustomer['phone'];
                            }
                            if (!empty($duplicateCustomer['address'])) {
                                $duplicateInfo[] = "العنوان: " . $duplicateCustomer['address'];
                            }
                            $duplicateMessage = "يوجد عميل مسجل مسبقاً بنفس البيانات في قائمة عملائك";
                            if (!empty($duplicateInfo)) {
                                $duplicateMessage .= " (" . implode(", ", $duplicateInfo) . ")";
                            }
                            $duplicateMessage .= ". يرجى اختيار العميل الموجود من القائمة أو تعديل البيانات.";
                            throw new InvalidArgumentException($duplicateMessage);
                        }
                    }

                    // التحقق من وجود أعمدة اللوكيشن
                    $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                    $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                    $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                    
                    $customerColumns = ['name', 'phone', 'address', 'balance', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                    $customerValues = [
                        $newCustomerName,
                        $newCustomerPhone !== '' ? $newCustomerPhone : null,
                        $newCustomerAddress !== '' ? $newCustomerAddress : null,
                        $dueAmount,
                        'active',
                        $currentUser['id'],
                        $repIdForCustomer,
                        0,
                        $createdByAdminFlag,
                    ];
                    $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?'];
                    
                    if ($hasLatitudeColumn && $newCustomerLatitude !== null) {
                        $customerColumns[] = 'latitude';
                        $customerValues[] = (float)$newCustomerLatitude;
                        $customerPlaceholders[] = '?';
                    }
                    
                    if ($hasLongitudeColumn && $newCustomerLongitude !== null) {
                        $customerColumns[] = 'longitude';
                        $customerValues[] = (float)$newCustomerLongitude;
                        $customerPlaceholders[] = '?';
                    }
                    
                    if ($hasLocationCapturedAtColumn && $newCustomerLatitude !== null && $newCustomerLongitude !== null) {
                        $customerColumns[] = 'location_captured_at';
                        $customerValues[] = date('Y-m-d H:i:s');
                        $customerPlaceholders[] = '?';
                    }
                    
                    $db->execute(
                        "INSERT INTO customers (" . implode(', ', $customerColumns) . ") 
                         VALUES (" . implode(', ', $customerPlaceholders) . ")",
                        $customerValues
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

                    $currentBalance = (float) ($customer['balance'] ?? 0);
                    $creditUsed = 0.0;
                    $amountAddedToSales = 0.0; // المبلغ الذي يُضاف إلى إجمالي المبيعات في خزنة المندوب
                    
                    // التحقق من وجود سجل مشتريات سابق للعميل (قبل إنشاء الفاتورة الحالية)
                    $hasPreviousPurchases = false;
                    try {
                        $previousInvoiceCount = $db->queryOne(
                            "SELECT COUNT(*) as count FROM invoices WHERE customer_id = ?",
                            [$customerId]
                        );
                        $hasPreviousPurchases = ((int)($previousInvoiceCount['count'] ?? 0)) > 0;
                    } catch (Throwable $e) {
                        error_log('Error checking previous purchases: ' . $e->getMessage());
                        // في حالة الخطأ، نفترض وجود سجل مشتريات سابق كإجراء احتياطي
                        $hasPreviousPurchases = true;
                    }
                    
                    // المنطق الجديد: خصم مبلغ البيع بالكامل من الرصيد الدائن أولاً
                    // ثم إضافة المبلغ الزائد فقط إلى خزنة المندوب
                    if ($currentBalance < 0) {
                        // العميل لديه رصيد دائن
                        $creditAvailable = abs($currentBalance);
                        $invoiceTotal = $netTotal; // إجمالي مبلغ الفاتورة
                        
                        // خصم مبلغ البيع من الرصيد الدائن
                        if ($invoiceTotal <= $creditAvailable) {
                            // مبلغ البيع أقل من أو يساوي الرصيد الدائن
                            // يتم خصم كامل مبلغ البيع من الرصيد الدائن
                            $creditUsed = $invoiceTotal;
                            $amountAddedToSales = 0.0; // لا يُضاف شيء إلى خزنة المندوب
                            $dueAmount = 0.0; // لا يوجد دين متبقي
                            
                            // الرصيد الجديد = الرصيد الدائن الحالي + مبلغ البيع (لأن الرصيد سالب)
                            // مثال: رصيد دائن -100، بيع 80
                            // الرصيد الجديد = -100 + 80 = -20 (رصيد دائن متبقي)
                            $newBalance = round($currentBalance + $creditUsed, 2);
                        } else {
                            // مبلغ البيع أكبر من الرصيد الدائن
                            // يتم خصم الرصيد الدائن بالكامل، والمبلغ الزائد يُضاف كدين
                            $creditUsed = $creditAvailable;
                            $excessAmount = round($invoiceTotal - $creditUsed, 2); // المبلغ الزائد
                            $amountAddedToSales = $excessAmount; // فقط المبلغ الزائد يُضاف إلى خزنة المندوب
                            $dueAmount = $excessAmount; // المبلغ الزائد هو الدين الجديد
                            
                            // الرصيد الجديد = 0 (لا رصيد دائن) + المبلغ الزائد (دين)
                            // مثال: رصيد دائن -100، بيع 150
                            // الرصيد الجديد = 0 + 50 = 50 (رصيد مدين)
                            $newBalance = $dueAmount;
                        }
                    } else {
                        // العميل ليس لديه رصيد دائن
                        // المنطق العادي: حساب المبلغ المتبقي بعد التحصيل الجزئي
                        $remainingAfterPartialPayment = $baseDueAmount;
                        $dueAmount = $remainingAfterPartialPayment;
                        $amountAddedToSales = $netTotal; // كامل المبلغ يُضاف إلى خزنة المندوب
                        
                        // حساب الرصيد الجديد: الرصيد الحالي + الدين الجديد
                        $newBalance = round($currentBalance + $dueAmount, 2);
                    }
                    
                    // تحديث رصيد العميل
                    if (abs($newBalance - $currentBalance) > 0.0001) {
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
                    $currentUser['id'],
                    $dueDate  // تمرير تاريخ الاستحقاق
                );

                if (empty($invoiceResult['success'])) {
                    throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة.');
                }

                $invoiceId = (int) $invoiceResult['invoice_id'];
                $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                // ربط أرقام التشغيلة بعناصر الفاتورة
                $invoiceItems = $db->query(
                    "SELECT id, product_id FROM invoice_items WHERE invoice_id = ? ORDER BY id",
                    [$invoiceId]
                );
                
                // إنشاء خريطة للمطابقة بين invoice_items و normalizedCart
                $invoiceItemsMap = [];
                foreach ($invoiceItems as $invItem) {
                    $productId = (int)$invItem['product_id'];
                    if (!isset($invoiceItemsMap[$productId])) {
                        $invoiceItemsMap[$productId] = [];
                    }
                    $invoiceItemsMap[$productId][] = (int)$invItem['id'];
                }
                
                // ربط أرقام التشغيلة
                foreach ($normalizedCart as $item) {
                    $productId = (int)$item['product_id'];
                    $finishedBatchId = isset($item['finished_batch_id']) && $item['finished_batch_id'] > 0 ? (int)$item['finished_batch_id'] : null;
                    $finishedBatchNumber = isset($item['finished_batch_number']) ? trim($item['finished_batch_number']) : null;
                    
                    if (isset($invoiceItemsMap[$productId]) && !empty($invoiceItemsMap[$productId])) {
                        // استخدام أول invoice_item_id متطابق
                        $invoiceItemId = array_shift($invoiceItemsMap[$productId]);
                        
                        // البحث عن batch_number_id من جدول batch_numbers
                        $batchNumberId = null;
                        if ($finishedBatchId) {
                            // محاولة استخدام finished_batch_id مباشرة كـ batch_number_id
                            $batchCheck = $db->queryOne(
                                "SELECT id FROM batch_numbers WHERE id = ?",
                                [$finishedBatchId]
                            );
                            if ($batchCheck) {
                                $batchNumberId = (int)$batchCheck['id'];
                            }
                        }
                        
                        // إذا لم نجد batch_number_id من finished_batch_id، نبحث باستخدام batch_number string
                        if (!$batchNumberId && $finishedBatchNumber) {
                            $batchCheck = $db->queryOne(
                                "SELECT id FROM batch_numbers WHERE batch_number = ?",
                                [$finishedBatchNumber]
                            );
                            if ($batchCheck) {
                                $batchNumberId = (int)$batchCheck['id'];
                            }
                        }
                        
                        // ربط رقم التشغيلة بعنصر الفاتورة إذا وُجد
                        if ($batchNumberId) {
                            try {
                                $db->execute(
                                    "INSERT INTO sales_batch_numbers (invoice_item_id, batch_number_id, quantity) 
                                     VALUES (?, ?, ?)
                                     ON DUPLICATE KEY UPDATE quantity = quantity + ?",
                                    [$invoiceItemId, $batchNumberId, $item['quantity'], $item['quantity']]
                                );
                            } catch (Throwable $batchError) {
                                error_log('Error linking batch number to invoice item: ' . $batchError->getMessage());
                            }
                        }
                    }
                }

                // تحديد حالة الفاتورة
                // إذا كان الدين = 0 (إما دفعة كاملة نقداً أو استخدام الرصيد الدائن بالكامل)، تكون الفاتورة مدفوعة
                $invoiceStatus = 'sent';
                if ($dueAmount <= 0.0001) {
                    $invoiceStatus = 'paid';
                    // عند استخدام الرصيد الدائن بالكامل، المبلغ المدفوع = إجمالي الفاتورة
                    if ($creditUsed > 0 && $effectivePaidAmount < $netTotal) {
                        $effectivePaidAmount = $netTotal;
                    }
                } elseif ($effectivePaidAmount > 0) {
                    $invoiceStatus = 'partial';
                }

                // تحديد ما إذا كانت المعاملة من رصيد دائن
                $isCreditSale = ($creditUsed > 0.0001);
                $hasRemainingDebt = ($dueAmount > 0.0001);
                
                // تحديث الفاتورة بالمبلغ المدفوع والمبلغ المتبقي
                $invoiceUpdateSql = "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW()";
                $invoiceUpdateParams = [$effectivePaidAmount, $dueAmount, $invoiceStatus];
                
                // إضافة فلاج للفاتورة إذا كان هناك عمود paid_from_credit
                $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
                if ($hasPaidFromCreditColumn) {
                    $invoiceUpdateSql .= ", paid_from_credit = ?";
                    $invoiceUpdateParams[] = $isCreditSale ? 1 : 0;
                }
                
                // إضافة مبلغ credit_used إذا كان هناك عمود credit_used
                $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
                if ($hasCreditUsedColumn) {
                    $invoiceUpdateSql .= ", credit_used = ?";
                    $invoiceUpdateParams[] = $creditUsed;
                } else {
                    // إضافة العمود إذا لم يكن موجوداً
                    try {
                        $db->execute("ALTER TABLE invoices ADD COLUMN credit_used DECIMAL(15,2) DEFAULT 0.00 COMMENT 'المبلغ المخصوم من الرصيد الدائن' AFTER paid_from_credit");
                        $hasCreditUsedColumn = true;
                        $invoiceUpdateSql .= ", credit_used = ?";
                        $invoiceUpdateParams[] = $creditUsed;
                    } catch (Throwable $e) {
                        error_log('Error adding credit_used column: ' . $e->getMessage());
                    }
                }
                
                // إضافة المبلغ الذي يُضاف إلى خزنة المندوب (amount_added_to_sales)
                // يجب تسجيل هذا المبلغ لجميع الفواتير (ليس فقط للفواتير المدفوعة من رصيد دائن)
                // للفواتير العادية: amount_added_to_sales = netTotal
                // للفواتير المدفوعة من رصيد دائن: amount_added_to_sales = المبلغ الزائد فقط
                $hasAmountAddedToSalesColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'amount_added_to_sales'"));
                if ($hasAmountAddedToSalesColumn) {
                    $invoiceUpdateSql .= ", amount_added_to_sales = ?";
                    $invoiceUpdateParams[] = $amountAddedToSales;
                } else {
                    // إضافة العمود إذا لم يكن موجوداً
                    try {
                        $db->execute("ALTER TABLE invoices ADD COLUMN amount_added_to_sales DECIMAL(15,2) DEFAULT NULL COMMENT 'المبلغ المضاف إلى خزنة المندوب (بعد خصم الرصيد الدائن)' AFTER paid_from_credit");
                        $hasAmountAddedToSalesColumn = true;
                        $invoiceUpdateSql .= ", amount_added_to_sales = ?";
                        $invoiceUpdateParams[] = $amountAddedToSales;
                    } catch (Throwable $e) {
                        error_log('Error adding amount_added_to_sales column: ' . $e->getMessage());
                    }
                }
                
                $invoiceUpdateParams[] = $invoiceId;
                $db->execute($invoiceUpdateSql . " WHERE id = ?", $invoiceUpdateParams);
                
                // إضافة الفاتورة إلى سجل مشتريات العميل
                try {
                    customerHistoryEnsureSetup();
                    $db->execute(
                        "INSERT INTO customer_purchase_history
                            (customer_id, invoice_id, invoice_number, invoice_date, invoice_total, paid_amount, invoice_status,
                             return_total, return_count, exchange_total, exchange_count, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, NOW())
                         ON DUPLICATE KEY UPDATE
                            invoice_number = VALUES(invoice_number),
                            invoice_date = VALUES(invoice_date),
                            invoice_total = VALUES(invoice_total),
                            paid_amount = VALUES(paid_amount),
                            invoice_status = VALUES(invoice_status),
                            updated_at = NOW()",
                        [
                            $customerId,
                            $invoiceId,
                            $invoiceNumber,
                            $saleDate,
                            $netTotal,
                            $effectivePaidAmount,
                            $invoiceStatus
                        ]
                    );
                } catch (Throwable $historyError) {
                    error_log('Error adding invoice to customer purchase history: ' . $historyError->getMessage());
                    // لا نوقف العملية إذا فشل تسجيل التاريخ، لكن نسجل الخطأ
                }
                
                // تسجيل التحصيل الجزئي في جدول التحصيلات إذا كان هناك مبلغ محصل فعلياً
                // لا نسجل في خزنة المندوب إذا كانت المعاملة من رصيد دائن
                $shouldRecordCollection = ($effectivePaidAmount > 0.0001 && $paymentType === 'partial' && !$isCreditSale);
                
                if ($shouldRecordCollection) {
                    // التحقق من وجود الأعمدة في جدول collections
                    $hasStatusColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'"));
                    $hasNotesColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'notes'"));
                    $hasCollectionNumberColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'collection_number'"));
                    
                    $collectionNumber = null;
                    if ($hasCollectionNumberColumn) {
                        try {
                            $year = date('Y', strtotime($saleDate));
                            $month = date('m', strtotime($saleDate));
                            $lastCollection = $db->queryOne(
                                "SELECT collection_number FROM collections WHERE collection_number LIKE ? ORDER BY collection_number DESC LIMIT 1",
                                ["COL-{$year}{$month}-%"]
                            );
                            
                            $serial = 1;
                            if (!empty($lastCollection['collection_number'])) {
                                $parts = explode('-', $lastCollection['collection_number']);
                                $serial = intval($parts[2] ?? 0) + 1;
                            }
                            $collectionNumber = sprintf("COL-%s%s-%04d", $year, $month, $serial);
                        } catch (Throwable $e) {
                            error_log('Error generating collection number: ' . $e->getMessage());
                        }
                    }
                    
                    $collectionColumns = ['customer_id', 'amount', 'date', 'payment_method', 'collected_by'];
                    $collectionValues = [$customerId, $effectivePaidAmount, $saleDate, 'cash', $currentUser['id']];
                    $collectionPlaceholders = ['?', '?', '?', '?', '?'];
                    
                    if ($hasCollectionNumberColumn && $collectionNumber !== null) {
                        array_unshift($collectionColumns, 'collection_number');
                        array_unshift($collectionValues, $collectionNumber);
                        array_unshift($collectionPlaceholders, '?');
                    }
                    
                    if ($hasNotesColumn) {
                        $collectionColumns[] = 'notes';
                        $collectionValues[] = 'تحصيل جزئي من نقطة بيع المندوب - فاتورة ' . $invoiceNumber;
                        $collectionPlaceholders[] = '?';
                    }
                    
                    if ($hasStatusColumn) {
                        $collectionColumns[] = 'status';
                        $collectionValues[] = 'pending';
                        $collectionPlaceholders[] = '?';
                    }
                    
                    try {
                        $collectionSql = "INSERT INTO collections (" . implode(', ', $collectionColumns) . ") VALUES (" . implode(', ', $collectionPlaceholders) . ")";
                        $db->execute($collectionSql, $collectionValues);
                        $collectionId = $db->getLastInsertId();
                        
                        logAudit($currentUser['id'], 'pos_partial_collection', 'collection', $collectionId, null, [
                            'invoice_id' => $invoiceId,
                            'invoice_number' => $invoiceNumber,
                            'amount' => $effectivePaidAmount,
                            'collection_number' => $collectionNumber
                        ]);
                    } catch (Throwable $collectionError) {
                        error_log('Error recording partial collection: ' . $collectionError->getMessage());
                        // لا نوقف العملية إذا فشل تسجيل التحصيل، لكن نسجل الخطأ
                    }
                }
                
                // إنشاء سجل في payment_schedules إذا كان هناك تاريخ استحقاق ومبلغ متبقي
                if ($dueDate && $dueAmount > 0.0001) {
                    try {
                        // التحقق من وجود جدول payment_schedules
                        $paymentSchedulesTableExists = $db->queryOne("SHOW TABLES LIKE 'payment_schedules'");
                        if (!empty($paymentSchedulesTableExists)) {
                            // التحقق من وجود عمود invoice_id في payment_schedules
                            $hasInvoiceIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM payment_schedules LIKE 'invoice_id'"));
                            
                            if ($hasInvoiceIdColumn) {
                                // إنشاء سجل في payment_schedules مرتبط بالفاتورة
                                $db->execute(
                                    "INSERT INTO payment_schedules 
                                    (invoice_id, customer_id, sales_rep_id, amount, due_date, installment_number, total_installments, status, created_by) 
                                    VALUES (?, ?, ?, ?, ?, 1, 1, 'pending', ?)",
                                    [
                                        $invoiceId,
                                        $customerId,
                                        $currentUser['id'],
                                        $dueAmount,
                                        $dueDate,
                                        $currentUser['id']
                                    ]
                                );
                                
                                logAudit($currentUser['id'], 'create_payment_schedule_from_pos', 'payment_schedule', $invoiceId, null, [
                                    'invoice_id' => $invoiceId,
                                    'invoice_number' => $invoiceNumber,
                                    'amount' => $dueAmount,
                                    'due_date' => $dueDate
                                ]);
                            } else {
                                // إذا لم يكن هناك عمود invoice_id، نستخدم sale_id (null في هذه الحالة)
                                $db->execute(
                                    "INSERT INTO payment_schedules 
                                    (sale_id, customer_id, sales_rep_id, amount, due_date, installment_number, total_installments, status, created_by) 
                                    VALUES (NULL, ?, ?, ?, ?, 1, 1, 'pending', ?)",
                                    [
                                        $customerId,
                                        $currentUser['id'],
                                        $dueAmount,
                                        $dueDate,
                                        $currentUser['id']
                                    ]
                                );
                            }
                        }
                    } catch (Throwable $scheduleError) {
                        error_log('Error creating payment schedule: ' . $scheduleError->getMessage());
                        // لا نوقف العملية إذا فشل إنشاء الجدول الزمني، لكن نسجل الخطأ
                    }
                }

                $totalSoldValue = 0;

                foreach ($normalizedCart as $item) {
                    $productId = $item['product_id'];
                    $quantity = $item['quantity'];
                    $unitPrice = $item['unit_price'];
                    $lineTotal = $item['line_total'];
                    // الحصول على finished_batch_id من البيانات المرسلة
                    $requestedBatchId = isset($item['finished_batch_id']) && $item['finished_batch_id'] > 0 ? (int)$item['finished_batch_id'] : null;

                    // التحقق من الكمية مرة أخرى باستخدام FOR UPDATE لتجنب race conditions
                    // جلب جميع بيانات المنتج من مخزون السيارة للحفاظ عليها عند التحديث
                    // استخدام finished_batch_id للبحث عن السجل الصحيح إذا كان متوفراً
                    if ($requestedBatchId !== null) {
                        $vehicleInventoryItem = $db->queryOne(
                            "SELECT quantity, finished_batch_id, finished_batch_number, finished_production_date, 
                                    finished_quantity_produced, finished_workers, product_name, product_category, 
                                    product_unit, product_unit_price, product_snapshot, manager_unit_price
                             FROM vehicle_inventory 
                             WHERE vehicle_id = ? AND product_id = ? AND finished_batch_id = ? FOR UPDATE",
                            [$vehicle['id'], $productId, $requestedBatchId]
                        );
                    } else {
                        // إذا لم يكن هناك batch_id، البحث عن سجل بدون batch_id
                        $vehicleInventoryItem = $db->queryOne(
                            "SELECT quantity, finished_batch_id, finished_batch_number, finished_production_date, 
                                    finished_quantity_produced, finished_workers, product_name, product_category, 
                                    product_unit, product_unit_price, product_snapshot, manager_unit_price
                             FROM vehicle_inventory 
                             WHERE vehicle_id = ? AND product_id = ? AND (finished_batch_id IS NULL OR finished_batch_id = 0) FOR UPDATE",
                            [$vehicle['id'], $productId]
                        );
                    }

                    if (!$vehicleInventoryItem) {
                        throw new RuntimeException('المنتج ' . $item['name'] . ' غير موجود في مخزون السيارة.');
                    }

                    $available = (float)($vehicleInventoryItem['quantity'] ?? 0);

                    if ($quantity > $available) {
                        throw new RuntimeException('الكمية المتاحة للمنتج ' . $item['name'] . ' غير كافية. المتاح: ' . $available . '، المطلوب: ' . $quantity);
                    }

                    // استخدام batch_id من البيانات المرسلة أو من vehicle_inventory
                    $batchId = $requestedBatchId ?? (!empty($vehicleInventoryItem['finished_batch_id']) ? (int)$vehicleInventoryItem['finished_batch_id'] : null);

                    // تسجيل حركة المخزون أولاً (قبل تحديث vehicle_inventory)
                    // لأن recordInventoryMovement تتحقق من الكمية من vehicle_inventory
                    $movementResult = recordInventoryMovement(
                        $productId,
                        $vehicleWarehouseId,
                        'out',
                        $quantity,
                        'sales',
                        $invoiceId,
                        'بيع من نقطة بيع المندوب - فاتورة ' . $invoiceNumber,
                        $currentUser['id'],
                        $batchId  // تمرير batchId إذا كان متوفراً
                    );

                    if (empty($movementResult['success'])) {
                        throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                    }

                    // تحديث vehicle_inventory بعد تسجيل الحركة
                    $newQuantity = max(0, $available - $quantity);
                    // تمرير جميع بيانات التشغيلة من السجل الموجود للحفاظ عليها عند التحديث
                    $finishedProductData = [];
                    if ($batchId) {
                        $finishedProductData['finished_batch_id'] = $batchId;
                        $finishedProductData['batch_id'] = $batchId;
                        // الحفاظ على بيانات التشغيلة من السجل الموجود
                        if (!empty($vehicleInventoryItem['finished_batch_number'])) {
                            $finishedProductData['finished_batch_number'] = $vehicleInventoryItem['finished_batch_number'];
                        }
                        if (!empty($vehicleInventoryItem['finished_production_date'])) {
                            $finishedProductData['finished_production_date'] = $vehicleInventoryItem['finished_production_date'];
                        }
                        if (!empty($vehicleInventoryItem['finished_quantity_produced'])) {
                            $finishedProductData['finished_quantity_produced'] = $vehicleInventoryItem['finished_quantity_produced'];
                        }
                        if (!empty($vehicleInventoryItem['finished_workers'])) {
                            $finishedProductData['finished_workers'] = $vehicleInventoryItem['finished_workers'];
                        }
                    }
                    $updateResult = updateVehicleInventory($vehicle['id'], $productId, $newQuantity, $currentUser['id'], null, $finishedProductData);
                    if (empty($updateResult['success'])) {
                        throw new RuntimeException($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة.');
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
                    'invoice_number'    => $invoiceNumber,
                    'items'             => $normalizedCart,
                    'net_total'         => $netTotal,
                    'paid_amount'       => $effectivePaidAmount,
                    'base_due_amount'   => $baseDueAmount,
                    'credit_used'       => $creditUsed,
                    'due_amount'        => $dueAmount,
                    'customer_id'       => $customerId,
                    'is_credit_sale'    => $isCreditSale,
                    'has_previous_purchases' => $hasPreviousPurchases ?? false,
                    'amount_added_to_sales' => $amountAddedToSales,
                    'customer_balance_before' => $currentBalance ?? 0,
                    'customer_balance_after' => $newBalance ?? 0,
                ]);

                // تحديث عمولة المندوب حسب القواعد:
                // - إذا كان للعميل رصيد دائن ولا يمتلك سجل مشتريات سابق: لا نسبة تحصيل
                // - إذا كان للعميل رصيد دائن ولديه سجل مشتريات سابق: نسبة تحصيل عادية
                // - لا نسجل المبلغ في خزنة المندوب إذا كانت المعاملة من رصيد دائن
                if (($currentUser['role'] ?? '') === 'sales') {
                    try {
                        require_once __DIR__ . '/../../includes/salary_calculator.php';
                        
                        // فقط إذا كان للعميل سجل مشتريات سابق، نحسب نسبة التحصيل
                        // أو إذا لم تكن المعاملة من رصيد دائن
                        $shouldCalculateCommission = !$isCreditSale || ($hasPreviousPurchases ?? false);
                        
                        if ($shouldCalculateCommission) {
                            // تحديث عمولة المندوب
                            refreshSalesCommissionForUser(
                                $currentUser['id'],
                                $saleDate,
                                'تحديث تلقائي بعد بيع من نقطة البيع' . ($isCreditSale ? ' (من رصيد دائن)' : '')
                            );
                        }
                    } catch (Throwable $commissionError) {
                        error_log('Error updating sales commission after POS sale: ' . $commissionError->getMessage());
                        // لا نوقف العملية إذا فشل تحديث العمولة
                    }
                }

                $conn->commit();

                $invoiceData = getInvoice($invoiceId);
                $invoiceMeta = [
                    'summary' => [
                        'subtotal' => $subtotal,
                        'prepaid' => $prepaidAmount,
                        'net_total' => $netTotal,
                        'paid' => $effectivePaidAmount,
                        'due_before_credit' => $baseDueAmount,
                        'credit_used' => $creditUsed,
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
                foreach ($vehicleInventory as &$item) {
                    $item['quantity'] = cleanFinancialValue($item['quantity'] ?? 0);
                    $item['unit_price'] = cleanFinancialValue($item['unit_price'] ?? 0);
                    $computedTotal = cleanFinancialValue(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0));
                    $item['total_value'] = cleanFinancialValue($item['total_value'] ?? $computedTotal);
                    if (abs($item['total_value'] - $computedTotal) > 0.01) {
                        $item['total_value'] = $computedTotal;
                    }

                    $inventoryStats['total_products']++;
                    $inventoryStats['total_quantity'] += (float) ($item['quantity'] ?? 0);
                    $inventoryStats['total_value'] += (float) ($item['total_value'] ?? 0);
                }
                unset($item);

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
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($posInvoiceLinks['absolute_report_url'])): ?>
<!-- Modal عرض الفاتورة بعد البيع -->
<div class="modal fade" id="posInvoiceModal" tabindex="-1" aria-labelledby="posInvoiceModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="posInvoiceModalLabel">
                    <i class="bi bi-receipt-cutoff me-2"></i>
                    فاتورة البيع
                </h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light btn-sm" onclick="printInvoice()">
                        <i class="bi bi-printer me-1"></i>طباعة
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="height: calc(100vh - 120px);">
                <iframe id="posInvoiceFrame" src="<?php echo htmlspecialchars($posInvoiceLinks['absolute_report_url']); ?>" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>إغلاق
                </button>
                <button type="button" class="btn btn-primary" onclick="printInvoice()">
                    <i class="bi bi-printer me-1"></i>طباعة الفاتورة
                </button>
            </div>
        </div>
    </div>
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
            gap: 0.75rem;
        }
        .pos-payment-option {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 0.85rem 1rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 14px;
            background: #f8fafc;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .pos-payment-option:hover {
            border-color: rgba(30, 64, 175, 0.4);
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(30, 64, 175, 0.12);
        }
        .pos-payment-option.active {
            border-color: rgba(22, 163, 74, 0.7);
            background: rgba(22, 163, 74, 0.08);
            box-shadow: 0 14px 28px rgba(22, 163, 74, 0.18);
        }
        .pos-payment-option .form-check-input {
            margin: 0;
            width: 1.1rem;
            height: 1.1rem;
            border-width: 2px;
        }
        .pos-payment-option-icon {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(15, 23, 42, 0.08);
            color: #1f2937;
            font-size: 1.25rem;
        }
        .pos-payment-option.active .pos-payment-option-icon {
            background: rgba(22, 163, 74, 0.18);
            color: #15803d;
        }
        .pos-payment-option-details {
            flex: 1;
        }
        .pos-payment-option-title {
            font-weight: 700;
            color: #111827;
            display: block;
        }
        .pos-payment-option-desc {
            display: block;
            margin-top: 0.15rem;
            font-size: 0.85rem;
            color: #64748b;
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
        @media (max-width: 768px) {
            .pos-summary-card {
                padding: 1rem;
            }
            .pos-summary-card .label {
                font-size: 0.8rem;
            }
            .pos-summary-card .value {
                font-size: 1.5rem;
            }
            .pos-panel {
                padding: 1rem;
            }
            .pos-checkout-panel {
                gap: 1rem;
            }
            .pos-cart-table {
                font-size: 0.9rem;
            }
            .pos-cart-table th {
                font-size: 0.85rem;
                padding: 0.6rem 0.5rem;
            }
            .pos-cart-table td {
                padding: 0.65rem 0.5rem;
            }
            .pos-qty-control {
                gap: 0.35rem;
            }
            .pos-qty-control .btn {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
            .pos-qty-control input {
                width: 70px;
                font-size: 0.9rem;
            }
            .pos-payment-option {
                padding: 0.9rem;
            }
            .pos-payment-option-icon {
                width: 38px;
                height: 38px;
                font-size: 1.1rem;
            }
            .pos-payment-option-title {
                font-size: 0.95rem;
            }
            .pos-payment-option-desc {
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 576px) {
            .pos-summary-card {
                padding: 0.9rem;
            }
            .pos-summary-card .label {
                font-size: 0.75rem;
            }
            .pos-summary-card .value {
                font-size: 1.35rem;
            }
            .pos-summary-card .meta {
                font-size: 0.75rem;
            }
            .pos-panel {
                padding: 0.9rem;
            }
            .pos-panel-header h4,
            .pos-panel-header h5 {
                font-size: 1.1rem;
            }
            .pos-panel-header p {
                font-size: 0.85rem;
            }
            .pos-product-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 0.75rem;
            }
            .pos-product-card {
                padding: 0.85rem;
            }
            .pos-product-name {
                font-size: 0.95rem;
            }
            .pos-product-meta {
                font-size: 0.85rem;
            }
            .pos-select-btn {
                font-size: 0.85rem;
                padding: 0.5rem 0.75rem;
            }
            .pos-cart-empty {
                padding: 1.5rem 1rem;
            }
            .pos-cart-empty i {
                font-size: 2rem;
            }
            .pos-cart-empty p {
                font-size: 0.9rem;
            }
            .pos-cart-table {
                width: 100%;
                min-width: 100%;
                font-size: 0.85rem;
            }
            .pos-cart-table thead {
                display: none;
            }
            .pos-cart-table tbody tr {
                display: flex;
                flex-direction: column;
                width: 100%;
                margin-bottom: 1rem;
                border: 1px solid rgba(148, 163, 184, 0.35);
                border-radius: 12px;
                padding: 1rem;
                background: #ffffff;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
                gap: 0.75rem;
            }
            .pos-cart-table tbody tr:last-child {
                margin-bottom: 0;
            }
            .pos-cart-table td {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
                width: 100%;
                padding: 0;
                border: none;
            }
            .pos-cart-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #1f2937;
                font-size: 0.9rem;
                margin-bottom: 0.25rem;
            }
            .pos-cart-table td[data-label="المنتج"] .fw-semibold {
                font-size: 1rem;
                margin-bottom: 0.25rem;
            }
            .pos-cart-table td[data-label="المنتج"] .text-muted {
                font-size: 0.8rem;
            }
            .pos-cart-table td[data-label="إجمالي"],
            .pos-cart-table td[data-label="الإجمالي"] {
                font-size: 1.1rem;
                font-weight: 700;
                color: #059669;
            }
            .pos-cart-table td[data-label="إجراءات"] {
                align-items: flex-end;
                margin-top: 0.25rem;
            }
            .pos-cart-table td .form-control {
                width: 100%;
                font-size: 0.9rem;
                padding: 0.5rem;
            }
            .pos-cart-table td .pos-qty-control {
                width: 100%;
                justify-content: space-between;
                gap: 0.5rem;
            }
            .pos-cart-table td .pos-qty-control input {
                flex: 1;
                min-width: 60px;
                text-align: center;
                font-size: 0.95rem;
                font-weight: 600;
            }
            .pos-cart-table td .btn[data-action="decrease"],
            .pos-cart-table td .btn[data-action="increase"] {
                flex: 0 0 42px;
                width: 42px;
                height: 42px;
                padding: 0;
                font-size: 1.1rem;
                border-radius: 8px;
            }
            .pos-cart-table td .btn[data-action="remove"] {
                font-size: 1.3rem;
                padding: 0.5rem;
                width: auto;
                min-width: 44px;
                height: 44px;
            }
            #posCartTableWrapper {
                overflow-x: visible;
                margin: 0 -0.9rem;
            }
            .pos-selected-product {
                padding: 0.75rem;
            }
            .pos-selected-product h5 {
                font-size: 1rem;
                margin-bottom: 0.75rem;
            }
            .meta-row {
                gap: 0.75rem;
            }
            .meta-block span {
                font-size: 0.8rem;
            }
            .meta-block .fw-semibold {
                font-size: 0.95rem;
            }
            .form-label {
                font-size: 0.875rem;
            }
            .form-control,
            .form-select {
                font-size: 0.9rem;
                padding: 0.5rem 0.75rem;
            }
            .pos-summary-card-neutral {
                padding: 1rem;
            }
            .pos-summary-card-neutral .small {
                font-size: 0.75rem;
            }
            .pos-summary-card-neutral .total {
                font-size: 1.5rem;
            }
            .pos-payment-option {
                padding: 0.75rem;
                gap: 0.75rem;
            }
            .pos-payment-option-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }
            .pos-payment-option-title {
                font-size: 0.9rem;
            }
            .pos-payment-option-desc {
                font-size: 0.75rem;
                margin-top: 0.1rem;
            }
            .pos-inline-note {
                font-size: 0.75rem;
            }
            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 1rem;
            }
            .btn-sm {
                font-size: 0.8rem;
                padding: 0.4rem 0.75rem;
            }
            .row.g-3 {
                --bs-gutter-y: 0.75rem;
                --bs-gutter-x: 0.75rem;
            }
            .col-sm-6 {
                margin-bottom: 0.75rem;
            }
            .pos-vehicle-summary {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 0.75rem;
            }
            .pos-summary-card {
                min-height: auto;
            }
            .d-flex.justify-content-between.gap-2 {
                gap: 0.5rem !important;
            }
            .d-flex.flex-wrap.gap-2 {
                gap: 0.5rem !important;
            }
            .btn.flex-fill {
                min-width: 120px;
            }
            .pos-cart-table tbody tr {
                max-width: 100%;
            }
            textarea.form-control {
                font-size: 0.9rem;
                padding: 0.5rem 0.75rem;
                resize: vertical;
            }
            .pos-search input {
                font-size: 0.9rem;
                padding: 0.5rem 0.75rem 0.5rem 2.5rem;
            }
            .pos-search i {
                font-size: 1rem;
                inset-inline-start: 0.75rem;
            }
            .pos-panel-header h4 {
                font-size: 1.1rem;
                margin-bottom: 0.25rem;
            }
            .pos-panel-header p {
                font-size: 0.85rem;
                margin-bottom: 0;
            }
            .mb-3 h5 {
                font-size: 1.1rem;
            }
            .mb-3 .d-flex.justify-content-between h5 {
                font-size: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .pos-product-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 0.6rem;
            }
            .pos-product-card {
                padding: 0.75rem;
                border-radius: 12px;
            }
            .pos-product-name {
                font-size: 0.9rem;
            }
            .pos-product-meta {
                font-size: 0.8rem;
                gap: 0.3rem;
            }
            .pos-select-btn {
                font-size: 0.8rem;
                padding: 0.45rem 0.65rem;
            }
            .pos-cart-table {
                font-size: 0.8rem;
            }
            .pos-cart-table td::before {
                font-size: 0.85rem;
            }
            .pos-cart-table td[data-label="المنتج"] .fw-semibold {
                font-size: 0.95rem;
            }
            .pos-cart-table td[data-label="إجمالي"],
            .pos-cart-table td[data-label="الإجمالي"] {
                font-size: 1rem;
            }
            .pos-qty-control .btn {
                width: 38px;
                height: 38px;
                font-size: 1rem;
            }
            .pos-qty-control input {
                font-size: 0.9rem;
                min-width: 55px;
            }
            .pos-summary-card-neutral {
                padding: 0.85rem;
            }
            .pos-summary-card-neutral .total {
                font-size: 1.35rem;
            }
            .pos-summary-card-neutral .fw-semibold {
                font-size: 1.15rem;
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

        <section class="pos-content">
                <div class="pos-panel" style="grid-column: span 7;">
                    <div class="pos-panel-header">
                        <div>
                            <h4>مخزون السيارة</h4>
                            <p>اضغط على المنتج لإضافته إلى سلة البيع</p>
                        </div>
                        <div class="pos-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="posInventorySearch" class="form-control" placeholder="بحث سريع عن منتج..."<?php echo empty($vehicleInventory) ? ' disabled' : ''; ?>>
                        </div>
                    </div>
                <div class="pos-product-grid" id="posProductGrid">
                    <?php if (empty($vehicleInventory)): ?>
                        <div class="pos-empty pos-empty-inline">
                            <i class="bi bi-box"></i>
                            <h5 class="mt-3 mb-2">لا يوجد مخزون متاح حالياً</h5>
                            <p class="mb-0">اطلب تزويد السيارة بالمنتجات لبدء البيع من نقطة البيع الميدانية.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($vehicleInventory as $item): ?>
                            <?php
                            $batchId = !empty($item['finished_batch_id']) ? (int) $item['finished_batch_id'] : 0;
                            $batchNumber = !empty($item['finished_batch_number']) ? htmlspecialchars($item['finished_batch_number'], ENT_QUOTES, 'UTF-8') : '';
                            $uniqueId = $batchId > 0 ? ($item['product_id'] . '_' . $batchId) : (string) $item['product_id'];
                            ?>
                            <div class="pos-product-card" data-product-card data-product-id="<?php echo (int) $item['product_id']; ?>" data-unique-id="<?php echo htmlspecialchars($uniqueId, ENT_QUOTES, 'UTF-8'); ?>" data-name="<?php echo htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-category="<?php echo htmlspecialchars($item['category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="pos-product-name">
                                    <?php echo htmlspecialchars($item['product_name'] ?? 'منتج'); ?>
                                    <?php if ($batchNumber): ?>
                                        <small class="text-muted d-block mt-1">تشغيلة: <?php echo $batchNumber; ?></small>
                                    <?php endif; ?>
                                </div>
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
                                        data-product-id="<?php echo (int) $item['product_id']; ?>"
                                        data-unique-id="<?php echo htmlspecialchars($uniqueId, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="bi bi-plus-circle me-2"></i>إضافة إلى السلة
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                                    <div class="btn-group w-100 pos-customer-mode-toggle" role="group" aria-label="Customer mode options">
                                        <input class="btn-check" type="radio" name="customer_mode" id="posCustomerModeExisting" value="existing" autocomplete="off" checked>
                                        <label class="btn btn-outline-primary flex-fill" for="posCustomerModeExisting">
                                            <i class="bi bi-person-check me-1"></i>عميل حالي
                                        </label>
                                        <input class="btn-check" type="radio" name="customer_mode" id="posCustomerModeNew" value="new" autocomplete="off">
                                        <label class="btn btn-outline-secondary flex-fill" for="posCustomerModeNew">
                                            <i class="bi bi-person-plus me-1"></i>عميل جديد
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3" id="posExistingCustomerWrap">
                                <label class="form-label">العملاء المسجلون</label>
                                <select class="form-select" id="posCustomerSelect" name="customer_id" required>
                                    <option value="">اختر العميل</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo (int) $customer['id']; ?>" data-balance="<?php echo htmlspecialchars((string)($customer['balance'] ?? 0)); ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- معلومات العميل المالية - حقل يتحدث تلقائياً -->
                            <div class="mb-3">
                                <label class="form-label">الحالة المالية للعميل</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="posCustomerBalanceText" 
                                       readonly 
                                       value="اختر عميلاً لعرض التفاصيل المالية"
                                       style="background-color: #f8f9fa; cursor: default;">
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
                                    <div class="col-12">
                                        <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                                        <div class="d-flex gap-2">
                                            <input type="text" class="form-control" name="new_customer_latitude" id="posNewCustomerLatitude" placeholder="خط العرض" readonly>
                                            <input type="text" class="form-control" name="new_customer_longitude" id="posNewCustomerLongitude" placeholder="خط الطول" readonly>
                                            <button type="button" class="btn btn-outline-primary" id="posGetLocationBtn" title="الحصول على الموقع الحالي">
                                                <i class="bi bi-geo-alt"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <h5 class="mb-0">سلة البيع</h5>
                                    <button type="button" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1 gap-md-2" id="posClearCartBtn">
                                        <i class="bi bi-trash"></i>
                                        <span class="d-none d-sm-inline">تفريغ السلة</span>
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

                            <div class="row g-2 g-md-3 align-items-start mb-3">
                                <div class="col-12 col-sm-6">
                                    <div class="pos-summary-card-neutral">
                                        <span class="small text-uppercase opacity-75">الإجمالي بعد الخصم</span>
                                        <span class="total" id="posNetTotal">0</span>
                                        <span class="small text-uppercase opacity-75 mt-2">المتبقي على العميل</span>
                                        <span class="fw-semibold" id="posDueAmount">0</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">طريقة الدفع</label>
                            <div class="pos-payment-options">
                                <label class="pos-payment-option active" for="posPaymentFull" data-payment-option>
                                    <input class="form-check-input" type="radio" name="payment_type" id="posPaymentFull" value="full" checked>
                                    <div class="pos-payment-option-icon">
                                        <i class="bi bi-cash-stack"></i>
                                    </div>
                                    <div class="pos-payment-option-details">
                                        <span class="pos-payment-option-title">دفع كامل الآن</span>
                                        <span class="pos-payment-option-desc">تحصيل المبلغ بالكامل فوراً دون أي ديون.</span>
                                    </div>
                                </label>
                                <label class="pos-payment-option" for="posPaymentPartial" data-payment-option>
                                    <input class="form-check-input" type="radio" name="payment_type" id="posPaymentPartial" value="partial">
                                    <div class="pos-payment-option-icon">
                                        <i class="bi bi-cash-coin"></i>
                                    </div>
                                    <div class="pos-payment-option-details">
                                        <span class="pos-payment-option-title">تحصيل جزئي الآن</span>
                                        <span class="pos-payment-option-desc">استلام جزء من المبلغ حالياً وتسجيل المتبقي كدين.</span>
                                    </div>
                                </label>
                                <label class="pos-payment-option" for="posPaymentCredit" data-payment-option>
                                    <input class="form-check-input" type="radio" name="payment_type" id="posPaymentCredit" value="credit">
                                    <div class="pos-payment-option-icon">
                                        <i class="bi bi-receipt"></i>
                                    </div>
                                    <div class="pos-payment-option-details">
                                        <span class="pos-payment-option-title">بيع بالآجل</span>
                                        <span class="pos-payment-option-desc">تمويل كامل للعميل دون تحصيل فوري، مع متابعة الدفعات لاحقاً.</span>
                                    </div>
                                </label>
                                </div>
                                <div class="mt-3 d-none" id="posPartialWrapper">
                                    <label class="form-label">مبلغ التحصيل الجزئي</label>
                                    <input type="number" value="0" class="form-control" id="posPartialAmount" placeholder="0">
                                </div>
                                <div class="mt-3 d-none" id="posDueDateWrapper">
                                    <label class="form-label">تاريخ الاستحقاق <span class="text-muted">(اختياري)</span></label>
                                    <input type="date" class="form-control" name="due_date" id="posDueDate" placeholder="YYYY-MM-DD">
                                    <small class="text-muted">اتركه فارغاً لطباعة "أجل غير مسمى"</small>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between">
                                <button type="button" class="btn btn-outline-secondary btn-sm flex-fill flex-md-none" id="posResetFormBtn">
                                    <i class="bi bi-arrow-repeat me-1 me-md-2"></i><span class="d-none d-sm-inline">إعادة تعيين</span>
                                </button>
                                <button type="submit" class="btn btn-success flex-fill flex-md-auto" id="posSubmitBtn" disabled>
                                    <i class="bi bi-check-circle me-1 me-md-2"></i>إتمام عملية البيع
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
    </div>
<?php endif; ?>

<?php if (!$error && $vehicle): ?>
<script>
(function () {
    const locale = <?php echo json_encode($pageDirection === 'rtl' ? 'ar-EG' : 'en-US'); ?>;
    const currencySymbolRaw = <?php echo json_encode(CURRENCY_SYMBOL); ?>;
    const inventory = <?php
        $inventoryForJs = [];
        foreach ($vehicleInventory as $item) {
            $snapshot = null;
            if (!empty($item['product_snapshot'])) {
                $decodedSnapshot = json_decode($item['product_snapshot'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $snapshot = $decodedSnapshot;
                }
            }

            $batchId = !empty($item['finished_batch_id']) ? (int) $item['finished_batch_id'] : 0;
            $batchNumber = !empty($item['finished_batch_number']) ? $item['finished_batch_number'] : '';
            
            // إنشاء معرف فريد لكل منتج+تشغيلة
            $uniqueId = $batchId > 0 ? ($item['product_id'] . '_' . $batchId) : (string) $item['product_id'];
            
            $inventoryForJs[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'finished_batch_id' => $batchId,
                'finished_batch_number' => $batchNumber,
                'unique_id' => $uniqueId, // معرف فريد للمنتج+التشغيلة
                'name' => $item['product_name'] ?? '',
                'category' => $item['category'] ?? ($item['product_category'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'unit' => $item['unit'] ?? ($item['product_unit'] ?? ''),
                'total_value' => (float) ($item['total_value'] ?? 0),
                'last_updated_at' => $item['last_updated_at'] ?? null,
                'snapshot' => $snapshot,
            ];
        }
        echo json_encode($inventoryForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    inventory.forEach((item) => {
        item.quantity = sanitizeNumber(item.quantity);
        item.unit_price = sanitizeNumber(item.unit_price);
        item.total_value = sanitizeNumber(item.total_value);
    });

    // استخدام unique_id كمفتاح للتمييز بين التشغيلات المختلفة لنفس المنتج
    const inventoryMap = new Map(inventory.map((item) => [item.unique_id || item.product_id, item]));
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
        paymentOptionCards: document.querySelectorAll('[data-payment-option]'),
        paymentRadios: document.querySelectorAll('input[name="payment_type"]'),
        partialWrapper: document.getElementById('posPartialWrapper'),
        partialInput: document.getElementById('posPartialAmount'),
        dueDateWrapper: document.getElementById('posDueDateWrapper'),
        dueDateInput: document.getElementById('posDueDate'),
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
        return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
    }

    function sanitizeCurrencySymbol(value) {
        if (typeof value !== 'string') {
            value = value == null ? '' : String(value);
        }
        const cleaned = value
            .replace(/262145/gi, '')
            .replace(/\s+/g, ' ')
            .trim();
        return cleaned || 'ج.م';
    }

    const currencySymbol = sanitizeCurrencySymbol(currencySymbolRaw);

    function sanitizeNumber(value) {
        if (value === null || value === undefined) {
            return 0;
        }
        if (typeof value === 'string') {
            const stripped = value
                .replace(/262145/gi, '')
                .replace(/[^\d.\-]/g, '');
            value = parseFloat(stripped);
        }
        if (!Number.isFinite(value)) {
            return 0;
        }
        if (Math.abs(value - 262145) < 0.01 || value > 10000000 || value < 0) {
            return 0;
        }
        return roundTwo(value);
    }

    function formatCurrency(value) {
        const sanitized = sanitizeNumber(value);
        return sanitized.toLocaleString(locale, {
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
            finished_batch_id: item.finished_batch_id || null,
            quantity: sanitizeNumber(item.quantity),
            unit_price: sanitizeNumber(item.unit_price),
        }));
        elements.cartData.value = JSON.stringify(payload);
    }

    function refreshPaymentOptionStates() {
        if (!elements.paymentOptionCards) {
            return;
        }
        elements.paymentOptionCards.forEach((card) => {
            const input = card.querySelector('input[type="radio"]');
            const isChecked = Boolean(input && input.checked);
            card.classList.toggle('active', isChecked);
        });
    }

    function sanitizeSummaryDisplays() {
        // لا حاجة لإعادة التنسيق لأن updateSummary() تقوم بذلك بالفعل
        // هذه الدالة محفوظة للتوافق مع الكود القديم
    }

    function updateSummary() {
        const subtotal = cart.reduce((total, item) => {
            const qty = sanitizeNumber(item.quantity);
            const price = sanitizeNumber(item.unit_price);
            return total + (qty * price);
        }, 0);
        // الحصول على المبلغ المدفوع مسبقاً
        let prepaid = sanitizeNumber(elements.prepaidInput ? elements.prepaidInput.value : '0');
        let sanitizedSubtotal = sanitizeNumber(subtotal);

        // التأكد من أن المبلغ المدفوع مسبقاً لا يتجاوز المجموع الفرعي
        if (prepaid < 0) {
            prepaid = 0;
        }
        if (prepaid > sanitizedSubtotal) {
            prepaid = sanitizedSubtotal;
        }
        if (elements.prepaidInput) {
            elements.prepaidInput.value = prepaid.toFixed(2);
        }

        const netTotal = sanitizeNumber(sanitizedSubtotal - prepaid);
        let paidAmount = 0;
        const paymentType = Array.from(elements.paymentRadios).find((radio) => radio.checked)?.value || 'full';

        if (paymentType === 'full') {
            paidAmount = netTotal;
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '0.00';
            if (elements.dueDateWrapper) {
                elements.dueDateWrapper.classList.add('d-none');
            }
        } else if (paymentType === 'partial') {
            elements.partialWrapper.classList.remove('d-none');
            if (elements.dueDateWrapper) {
                elements.dueDateWrapper.classList.remove('d-none');
            }
            let partialValue = sanitizeNumber(elements.partialInput.value);
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
            if (elements.dueDateWrapper) {
                elements.dueDateWrapper.classList.remove('d-none');
            }
            paidAmount = 0;
        }

        // حساب المتبقي بعد خصم الرصيد الدائن إن وجد
        let dueAmount = sanitizeNumber(Math.max(0, netTotal - paidAmount));
        
        // الحصول على رصيد العميل المحدد (إذا كان موجوداً)
        let customerCreditBalance = 0;
        if (elements.customerSelect && elements.customerSelect.value) {
            const selectedOption = elements.customerSelect.options[elements.customerSelect.selectedIndex];
            if (selectedOption) {
                const balance = parseFloat(selectedOption.getAttribute('data-balance') || '0');
                // إذا كان الرصيد سالب (رصيد دائن)، نخصمه من المتبقي
                if (balance < 0) {
                    customerCreditBalance = Math.abs(balance); // القيمة المطلقة للرصيد الدائن
                    // خصم الرصيد الدائن من المتبقي
                    dueAmount = sanitizeNumber(Math.max(0, dueAmount - customerCreditBalance));
                }
            }
        }
        
        // تحديث العناصر في الواجهة بشكل فوري
        if (elements.netTotal) {
            elements.netTotal.textContent = formatCurrency(netTotal);
        }
        if (elements.dueAmount) {
            elements.dueAmount.textContent = formatCurrency(dueAmount);
        }

        if (elements.paidField) {
            elements.paidField.value = paidAmount.toFixed(2);
        }
        if (elements.submitBtn) {
            const hasCartItems = cart.length > 0;
            const hasValidCustomer = (() => {
                const customerMode = Array.from(elements.customerModeRadios).find((radio) => radio.checked)?.value || 'existing';
                if (customerMode === 'existing') {
                    return elements.customerSelect && elements.customerSelect.value && elements.customerSelect.value !== '';
                } else {
                    return elements.newCustomerName && elements.newCustomerName.value && elements.newCustomerName.value.trim() !== '';
                }
            })();
            elements.submitBtn.disabled = !hasCartItems || !hasValidCustomer;
        }
        syncCartData();
        refreshPaymentOptionStates();
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
            const sanitizedQty = sanitizeNumber(item.quantity);
            const sanitizedPrice = sanitizeNumber(item.unit_price);
            const sanitizedAvailable = sanitizeNumber(item.available);
            const uniqueId = item.unique_id || item.product_id;
            const batchInfo = item.finished_batch_id ? ` • تشغيلة: ${escapeHtml(item.finished_batch_number || item.finished_batch_id)}` : '';
            return `
                <tr data-cart-row data-unique-id="${uniqueId}">
                    <td data-label="المنتج">
                        <div class="fw-semibold">${escapeHtml(item.name)}</div>
                        <div class="text-muted small">${escapeHtml(item.category || 'غير مصنف')}${batchInfo} • متاح: ${sanitizedAvailable.toFixed(2)}</div>
                    </td>
                    <td data-label="الكمية">
                        <div class="pos-qty-control">
                            <button type="button" class="btn btn-light border" data-action="decrease" data-unique-id="${uniqueId}" aria-label="تقليل الكمية"><i class="bi bi-dash"></i></button>
                            <input type="number" step="0.01" min="0" max="${sanitizedAvailable.toFixed(2)}" class="form-control" data-cart-qty data-unique-id="${uniqueId}" value="${sanitizedQty.toFixed(2)}" aria-label="الكمية">
                            <button type="button" class="btn btn-light border" data-action="increase" data-unique-id="${uniqueId}" aria-label="زيادة الكمية"><i class="bi bi-plus"></i></button>
                        </div>
                    </td>
                    <td data-label="سعر الوحدة">
                        <input type="number" step="0.01" min="0" class="form-control" data-cart-price data-unique-id="${uniqueId}" value="${sanitizedPrice.toFixed(2)}" aria-label="سعر الوحدة">
                    </td>
                    <td data-label="الإجمالي" class="fw-semibold">${formatCurrency(sanitizedQty * sanitizedPrice)}</td>
                    <td data-label="إجراءات" class="text-end">
                        <button type="button" class="btn btn-link text-danger p-0" data-action="remove" data-unique-id="${uniqueId}" aria-label="حذف المنتج"><i class="bi bi-x-circle"></i></button>
                    </td>
                </tr>`;
        }).join('');

        elements.cartBody.innerHTML = rows;
        syncCartData(); // تحديث البيانات المرسلة للخادم
        updateSummary();
    }

    function addToCart(uniqueId) {
        const product = inventoryMap.get(uniqueId);
        if (!product) {
            return;
        }
        product.quantity = sanitizeNumber(product.quantity);
        product.unit_price = sanitizeNumber(product.unit_price);
        const existing = cart.find((item) => item.unique_id === uniqueId);
        if (existing) {
            const maxQty = sanitizeNumber(product.quantity);
            const newQty = sanitizeNumber(existing.quantity + 1);
            if (newQty > maxQty) {
                existing.quantity = maxQty;
            } else {
                existing.quantity = newQty;
            }
        } else {
            if (product.quantity <= 0) {
                return;
            }
            cart.push({
                product_id: product.product_id,
                finished_batch_id: product.finished_batch_id || null,
                unique_id: product.unique_id || product.product_id,
                name: product.name,
                category: product.category,
                quantity: Math.min(1, sanitizeNumber(product.quantity)),
                available: sanitizeNumber(product.quantity),
                unit_price: sanitizeNumber(product.unit_price),
            });
        }
        renderSelectedProduct(product);
        renderCart();
    }

    function removeFromCart(uniqueId) {
        const index = cart.findIndex((item) => item.unique_id === uniqueId);
        if (index >= 0) {
            cart.splice(index, 1);
            renderCart();
        }
    }

    function adjustQuantity(uniqueId, delta) {
        const item = cart.find((entry) => entry.unique_id === uniqueId);
        const product = inventoryMap.get(uniqueId);
        if (!item || !product) {
            return;
        }
        let newQuantity = sanitizeNumber(item.quantity + delta);
        if (newQuantity <= 0) {
            removeFromCart(uniqueId);
            return;
        }
        const maxQty = sanitizeNumber(product.quantity);
        if (newQuantity > maxQty) {
            newQuantity = maxQty;
        }
        item.quantity = newQuantity;
        renderCart(); // renderCart() تستدعي updateSummary() تلقائياً
    }

    function updateQuantity(uniqueId, value) {
        const item = cart.find((entry) => entry.unique_id === uniqueId);
        const product = inventoryMap.get(uniqueId);
        if (!item || !product) {
            return;
        }
        let qty = sanitizeNumber(value);
        if (qty <= 0) {
            removeFromCart(uniqueId);
            return;
        }
        const maxQty = sanitizeNumber(product.quantity);
        if (qty > maxQty) {
            qty = maxQty;
        }
        item.quantity = qty;
        renderCart(); // renderCart() تستدعي updateSummary() تلقائياً
    }

    function updateUnitPrice(uniqueId, value) {
        const item = cart.find((entry) => entry.unique_id === uniqueId);
        if (!item) {
            return;
        }
        let price = sanitizeNumber(value);
        if (price < 0) {
            price = 0;
        }
        item.unit_price = price;
        renderCart(); // renderCart() تستدعي updateSummary() تلقائياً
    }

    elements.inventoryButtons.forEach((button) => {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const uniqueId = this.dataset.uniqueId || this.dataset.productId;
            addToCart(uniqueId);
        });
    });

    elements.inventoryCards.forEach((card) => {
        card.addEventListener('click', function () {
            const uniqueId = this.dataset.uniqueId || this.dataset.productId;
            elements.inventoryCards.forEach((c) => c.classList.remove('active'));
            this.classList.add('active');
            renderSelectedProduct(inventoryMap.get(uniqueId));
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
            const uniqueId = action.dataset.uniqueId || action.dataset.productId;
            switch (action.dataset.action) {
                case 'increase':
                    adjustQuantity(uniqueId, 1);
                    break;
                case 'decrease':
                    adjustQuantity(uniqueId, -1);
                    break;
                case 'remove':
                    removeFromCart(uniqueId);
                    break;
            }
        });

        elements.cartBody.addEventListener('input', function (event) {
            const qtyInput = event.target.matches('[data-cart-qty]') ? event.target : null;
            const priceInput = event.target.matches('[data-cart-price]') ? event.target : null;
            const uniqueId = event.target.dataset.uniqueId || event.target.dataset.productId;
            if (qtyInput) {
                updateQuantity(uniqueId, qtyInput.value);
                // renderCart() تستدعي updateSummary() تلقائياً
            }
            if (priceInput) {
                updateUnitPrice(uniqueId, priceInput.value);
                // renderCart() تستدعي updateSummary() تلقائياً
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
        elements.prepaidInput.addEventListener('input', function() {
            updateSummary(); // تحديث فوري عند تغيير المبلغ المدفوع مسبقاً
        });
        elements.prepaidInput.addEventListener('change', function() {
            updateSummary(); // تحديث عند تغيير المبلغ المدفوع مسبقاً
        });
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
            updateSummary(); // تحديث حالة الزر عند تغيير وضع العميل
        });
    });

    // دالة تحديث حالة رصيد العميل
    function updateCustomerBalance() {
        const balanceText = document.getElementById('posCustomerBalanceText');
        
        if (!elements.customerSelect || !balanceText) {
            return;
        }
        
        const selectedOption = elements.customerSelect.options[elements.customerSelect.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            // عرض رسالة افتراضية عند عدم اختيار عميل
            balanceText.value = 'اختر عميلاً لعرض التفاصيل المالية';
            balanceText.className = 'form-control';
            balanceText.style.backgroundColor = '#f8f9fa';
            balanceText.style.borderColor = '#dee2e6';
            return;
        }
        
        const balance = parseFloat(selectedOption.getAttribute('data-balance') || '0');
        
        if (balance > 0) {
            balanceText.value = 'ديون العميل: ' + formatCurrency(balance);
            balanceText.className = 'form-control border-warning';
            balanceText.style.backgroundColor = '#fff3cd';
            balanceText.style.borderColor = '#ffc107';
        } else if (balance < 0) {
            balanceText.value = 'رصيد دائن: ' + formatCurrency(Math.abs(balance));
            balanceText.className = 'form-control border-success';
            balanceText.style.backgroundColor = '#d1e7dd';
            balanceText.style.borderColor = '#198754';
        } else {
            balanceText.value = 'الرصيد: 0';
            balanceText.className = 'form-control border-info';
            balanceText.style.backgroundColor = '#cff4fc';
            balanceText.style.borderColor = '#0dcaf0';
        }
    }
    
    // إضافة مستمعين لحقول العميل لتحديث حالة الزر
    if (elements.customerSelect) {
        elements.customerSelect.addEventListener('change', function() {
            updateSummary();
            updateCustomerBalance();
        });
        elements.customerSelect.addEventListener('input', function() {
            updateSummary();
            updateCustomerBalance();
        });
    }
    if (elements.newCustomerName) {
        elements.newCustomerName.addEventListener('input', updateSummary);
        elements.newCustomerName.addEventListener('change', updateSummary);
    }

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

    // دالة الحصول على موقع المستخدم
    const getLocationBtn = document.getElementById('posGetLocationBtn');
    const latitudeInput = document.getElementById('posNewCustomerLatitude');
    const longitudeInput = document.getElementById('posNewCustomerLongitude');
    
    if (getLocationBtn && latitudeInput && longitudeInput) {
        getLocationBtn.addEventListener('click', function() {
            if (!navigator.geolocation) {
                alert('المتصفح لا يدعم الحصول على الموقع');
                return;
            }
            
            getLocationBtn.disabled = true;
            getLocationBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    latitudeInput.value = position.coords.latitude.toFixed(8);
                    longitudeInput.value = position.coords.longitude.toFixed(8);
                    getLocationBtn.disabled = false;
                    getLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                },
                function(error) {
                    alert('فشل الحصول على الموقع: ' + error.message);
                    getLocationBtn.disabled = false;
                    getLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                }
            );
        });
    }
    
    // تهيئة أولية للقيم
    refreshPaymentOptionStates();
    renderCart();
    sanitizeSummaryDisplays();
    
    // عرض معلومات العميل المالية عند تحميل الصفحة
    // استخدام setTimeout لضمان تحميل جميع العناصر
    setTimeout(function() {
        updateCustomerBalance();
    }, 500);
    
    window.posDebugInfo = {
        sanitizeNumber,
        sanitizeSummaryDisplays,
        formatCurrency,
        elements,
        cart,
        inventory,
        currencySymbol
    };
    console.info('[POS] Initialized', {
        netTotal: elements.netTotal?.textContent,
        dueAmount: elements.dueAmount?.textContent,
        inventoryCount: inventory.length
    });
})();
</script>

<?php endif; ?>

<!-- إدارة Modal الفاتورة ومنع Refresh -->
<script>
(function() {
    <?php if (!empty($posInvoiceLinks['absolute_report_url'])): ?>
    const invoiceUrl = <?php echo json_encode($posInvoiceLinks['absolute_report_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const invoicePrintUrl = <?php echo !empty($posInvoiceLinks['absolute_print_url']) ? json_encode($posInvoiceLinks['absolute_print_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'; ?>;
    <?php else: ?>
    const invoiceUrl = null;
    const invoicePrintUrl = null;
    <?php endif; ?>
    
    // الانتظار حتى يتم تحميل DOM بالكامل
    function initInvoiceModal() {
        const invoiceModal = document.getElementById('posInvoiceModal');
        
        if (invoiceModal && invoiceUrl) {
            // الانتظار حتى يتم تحميل Bootstrap
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                // فتح Modal تلقائياً
                try {
                    const modal = new bootstrap.Modal(invoiceModal, {
                        backdrop: 'static',
                        keyboard: false
                    });
                    modal.show();
                    
                    console.log('[POS Invoice] Modal opened successfully');
                    
                    // عند إغلاق Modal، تنظيف URL لمنع refresh
                    invoiceModal.addEventListener('hidden.bs.modal', function() {
                        const currentUrl = new URL(window.location.href);
                        currentUrl.searchParams.delete('success');
                        currentUrl.searchParams.delete('error');
                        window.history.replaceState({}, '', currentUrl.toString());
                    });
                } catch (error) {
                    console.error('[POS Invoice] Error opening modal:', error);
                    // محاولة مرة أخرى بعد قليل
                    setTimeout(initInvoiceModal, 200);
                }
            } else {
                // إذا لم يكن Bootstrap جاهزاً، ننتظر قليلاً ثم نحاول مرة أخرى
                console.log('[POS Invoice] Waiting for Bootstrap...');
                setTimeout(initInvoiceModal, 100);
            }
        }
        
        // دالة الطباعة
        window.printInvoice = function() {
            if (invoicePrintUrl) {
                const printWindow = window.open(invoicePrintUrl, '_blank');
                if (printWindow) {
                    printWindow.onload = function() {
                        setTimeout(function() {
                            printWindow.print();
                        }, 500);
                    };
                }
            } else if (invoiceUrl) {
                const printUrl = invoiceUrl + (invoiceUrl.includes('?') ? '&' : '?') + 'print=1';
                const printWindow = window.open(printUrl, '_blank');
                if (printWindow) {
                    printWindow.onload = function() {
                        setTimeout(function() {
                            printWindow.print();
                        }, 500);
                    };
                }
            }
        };
        
        // منع refresh تلقائي عند وجود فاتورة
        const successAlert = document.getElementById('successAlert');
        const errorAlert = document.getElementById('errorAlert');
        
        // فقط إذا لم تكن هناك فاتورة، نفعل auto-refresh
        if (!invoiceModal && (successAlert || errorAlert)) {
            const alertElement = successAlert || errorAlert;
            if (alertElement && alertElement.dataset.autoRefresh === 'true') {
                setTimeout(function() {
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.delete('success');
                    currentUrl.searchParams.delete('error');
                    window.location.href = currentUrl.toString();
                }, 3000);
            }
        }
    }
    
    // تشغيل الكود عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInvoiceModal);
    } else {
        // DOM محمّل بالفعل
        initInvoiceModal();
    }
})();
</script>

