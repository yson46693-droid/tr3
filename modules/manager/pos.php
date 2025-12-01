<?php
/**
 * نقطة بيع المدير - بيع من مخزن الشركة الرئيسي
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
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

            $token = bin2hex(random_bytes(8));
            $normalizedNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'INV'));
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
                        VALUES (?, ?, 'manager_pos_invoice', ?, ?, ?, ?, ?, NOW())",
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

// إنشاء جداول طلبات الشحن إذا لم تكن موجودة
function ensureShippingTables(Database $db): void
{
    try {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `shipping_companies` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(150) NOT NULL,
                `contact_person` varchar(100) DEFAULT NULL,
                `phone` varchar(30) DEFAULT NULL,
                `email` varchar(120) DEFAULT NULL,
                `address` text DEFAULT NULL,
                `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
                `status` enum('active','inactive') NOT NULL DEFAULT 'active',
                `notes` text DEFAULT NULL,
                `created_by` int(11) DEFAULT NULL,
                `updated_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`),
                KEY `status` (`status`),
                KEY `created_by` (`created_by`),
                KEY `updated_by` (`updated_by`),
                CONSTRAINT `shipping_companies_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `shipping_companies_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('shipping_companies table creation error: ' . $e->getMessage());
    }

    try {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `shipping_company_orders` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_number` varchar(50) NOT NULL,
                `shipping_company_id` int(11) NOT NULL,
                `customer_id` int(11) NOT NULL,
                `invoice_id` int(11) DEFAULT NULL,
                `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                `status` enum('assigned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'assigned',
                `handed_over_at` timestamp NULL DEFAULT NULL,
                `delivered_at` timestamp NULL DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `created_by` int(11) DEFAULT NULL,
                `updated_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `order_number` (`order_number`),
                KEY `shipping_company_id` (`shipping_company_id`),
                KEY `customer_id` (`customer_id`),
                KEY `invoice_id` (`invoice_id`),
                KEY `status` (`status`),
                CONSTRAINT `shipping_company_orders_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `shipping_company_orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
                CONSTRAINT `shipping_company_orders_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
                CONSTRAINT `shipping_company_orders_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                CONSTRAINT `shipping_company_orders_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('shipping_company_orders table creation error: ' . $e->getMessage());
    }

    try {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `shipping_company_order_items` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_id` int(11) NOT NULL,
                `product_id` int(11) NOT NULL,
                `batch_id` int(11) DEFAULT NULL,
                `quantity` decimal(10,2) NOT NULL,
                `unit_price` decimal(15,2) NOT NULL,
                `total_price` decimal(15,2) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `order_id` (`order_id`),
                KEY `product_id` (`product_id`),
                KEY `batch_id` (`batch_id`),
                CONSTRAINT `shipping_company_order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `shipping_company_orders` (`id`) ON DELETE CASCADE,
                CONSTRAINT `shipping_company_order_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        
        // إضافة عمود batch_id إذا لم يكن موجوداً
        try {
            $batchIdColumn = $db->queryOne("SHOW COLUMNS FROM shipping_company_order_items LIKE 'batch_id'");
            if (empty($batchIdColumn)) {
                $db->execute("ALTER TABLE shipping_company_order_items ADD COLUMN batch_id int(11) DEFAULT NULL AFTER product_id");
                $db->execute("ALTER TABLE shipping_company_order_items ADD KEY batch_id (batch_id)");
            }
        } catch (Throwable $e) {
            error_log('Error adding batch_id column: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('shipping_company_order_items table creation error: ' . $e->getMessage());
    }
}

function generateShippingOrderNumber(Database $db): string
{
    $year = date('Y');
    $month = date('m');
    $prefix = "SHIP-{$year}{$month}-";

    $lastOrder = $db->queryOne(
        "SELECT order_number FROM shipping_company_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1",
        [$prefix . '%']
    );

    if ($lastOrder && isset($lastOrder['order_number'])) {
        $parts = explode('-', $lastOrder['order_number']);
        $serial = (int)($parts[2] ?? 0) + 1;
    } else {
        $serial = 1;
    }

    return sprintf('%s%04d', $prefix, $serial);
}

requireRole('manager');

$currentUser = getCurrentUser();
$pageDirection = getDirection();
$db = db();
$error = '';
$success = '';
$validationErrors = [];

// التأكد من وجود الجداول المطلوبة
ensureShippingTables($db);

// الحصول على المخزن الرئيسي
$mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
if (!$mainWarehouse) {
    $db->execute(
        "INSERT INTO warehouses (name, warehouse_type, status, location, description) VALUES (?, 'main', 'active', ?, ?)",
        ['مخزن الشركة الرئيسي', 'الموقع الرئيسي للشركة', 'المخزن الرئيسي للشركة']
    );
    $mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
}

$mainWarehouseId = $mainWarehouse['id'] ?? null;

// تحميل قائمة العملاء
$customers = [];
try {
    $customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name ASC");
} catch (Throwable $e) {
    error_log('Error fetching customers: ' . $e->getMessage());
}

// تحميل منتجات الشركة (منتجات المصنع + المنتجات الخارجية)
$companyInventory = [];
$inventoryStats = [
    'total_products' => 0,
    'total_quantity' => 0,
    'total_value' => 0,
];

try {
    // جلب منتجات المصنع من finished_products
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    if (!empty($finishedProductsTableExists)) {
        $factoryProducts = $db->query("
            SELECT 
                fp.id AS batch_id,
                fp.batch_number,
                COALESCE(fp.product_id, bn.product_id) AS product_id,
                COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                pr.category as product_category,
                fp.quantity_produced AS quantity,
                COALESCE(fp.unit_price, pr.unit_price, 0) AS unit_price,
                fp.production_date,
                'factory' AS product_type
            FROM finished_products fp
            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
            LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
            WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
            ORDER BY fp.production_date DESC, fp.id DESC
        ");
        
        foreach ($factoryProducts as $fp) {
            $productId = (int)($fp['product_id'] ?? 0);
            $quantity = (float)($fp['quantity'] ?? 0);
            $unitPrice = (float)($fp['unit_price'] ?? 0);
            $totalValue = $quantity * $unitPrice;
            
            $companyInventory[] = [
                'product_id' => $productId,
                'batch_id' => (int)($fp['batch_id'] ?? 0),
                'batch_number' => $fp['batch_number'] ?? '',
                'product_name' => $fp['product_name'] ?? 'غير محدد',
                'category' => $fp['product_category'] ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_value' => $totalValue,
                'product_type' => 'factory',
                'production_date' => $fp['production_date'] ?? null,
            ];
            
            $inventoryStats['total_products']++;
            $inventoryStats['total_quantity'] += $quantity;
            $inventoryStats['total_value'] += $totalValue;
        }
    }
    
    // جلب المنتجات الخارجية
    $externalProducts = $db->query("
        SELECT 
            id AS product_id,
            name AS product_name,
            category,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price,
            (quantity * unit_price) as total_value,
            'external' AS product_type
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
          AND quantity > 0
        ORDER BY name ASC
    ");
    
    foreach ($externalProducts as $ext) {
        $productId = (int)($ext['product_id'] ?? 0);
        $quantity = (float)($ext['quantity'] ?? 0);
        $unitPrice = (float)($ext['unit_price'] ?? 0);
        $totalValue = (float)($ext['total_value'] ?? 0);
        
        $companyInventory[] = [
            'product_id' => $productId,
            'batch_id' => null,
            'batch_number' => null,
            'product_name' => $ext['product_name'] ?? '',
            'category' => $ext['category'] ?? '',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_value' => $totalValue,
            'product_type' => 'external',
            'production_date' => null,
        ];
        
        $inventoryStats['total_products']++;
        $inventoryStats['total_quantity'] += $quantity;
        $inventoryStats['total_value'] += $totalValue;
    }
} catch (Throwable $e) {
    error_log('Error fetching company inventory: ' . $e->getMessage());
}

// معالجة إنشاء عملية بيع جديدة
$posInvoiceLinks = null;
$inventoryByProduct = [];
foreach ($companyInventory as $item) {
    $key = (int)($item['product_id'] ?? 0);
    if (!isset($inventoryByProduct[$key])) {
        $inventoryByProduct[$key] = [
            'quantity' => 0,
            'unit_price' => 0,
            'items' => [],
        ];
    }
    $inventoryByProduct[$key]['quantity'] += (float)($item['quantity'] ?? 0);
    if ((float)($item['unit_price'] ?? 0) > 0) {
        $inventoryByProduct[$key]['unit_price'] = (float)($item['unit_price'] ?? 0);
    }
    $inventoryByProduct[$key]['items'][] = $item;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 0;
                $unitPrice = isset($row['unit_price']) ? round((float) $row['unit_price'], 2) : 0;

                if ($productId <= 0 || !isset($inventoryByProduct[$productId])) {
                    $validationErrors[] = 'المنتج المحدد رقم ' . ($index + 1) . ' غير متاح في مخزن الشركة.';
                    continue;
                }

                $product = $inventoryByProduct[$productId];
                $available = (float) ($product['quantity'] ?? 0);

                if ($quantity <= 0) {
                    $validationErrors[] = 'يجب تحديد كمية صالحة للمنتج رقم ' . ($index + 1) . '.';
                    continue;
                }

                if ($quantity > $available) {
                    $validationErrors[] = 'الكمية المطلوبة للمنتج رقم ' . ($index + 1) . ' تتجاوز الكمية المتاحة.';
                    continue;
                }

                if ($unitPrice <= 0) {
                    $unitPrice = round((float) ($product['unit_price'] ?? 0), 2);
                    if ($unitPrice <= 0) {
                        $validationErrors[] = 'لا يمكن تحديد سعر المنتج رقم ' . ($index + 1) . '.';
                        continue;
                    }
                }

                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $productName = '';
                foreach ($product['items'] as $item) {
                    if (!empty($item['product_name'])) {
                        $productName = $item['product_name'];
                        break;
                    }
                }

                $normalizedCart[] = [
                    'product_id' => $productId,
                    'name' => $productName ?: 'منتج',
                    'category' => $product['items'][0]['category'] ?? null,
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
        } else {
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
                    $dueAmount = $baseDueAmount;
                    $creditUsed = 0.0;
                    $db->execute(
                        "INSERT INTO customers (name, phone, address, balance, status, created_by, rep_id, created_from_pos, created_by_admin) 
                         VALUES (?, ?, ?, ?, 'active', ?, NULL, 1, 0)",
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

                    $currentBalance = (float) ($customer['balance'] ?? 0);
                    if ($currentBalance < 0 && $dueAmount > 0) {
                        $creditUsed = min(abs($currentBalance), $dueAmount);
                        $dueAmount = round($dueAmount - $creditUsed, 2);
                        $effectivePaidAmount += $creditUsed;
                    } else {
                        $creditUsed = 0.0;
                    }

                    $newBalance = round($currentBalance + $creditUsed + $dueAmount, 2);
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
                    $dueDate
                );

                if (empty($invoiceResult['success'])) {
                    throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة.');
                }

                $invoiceId = (int) $invoiceResult['invoice_id'];
                $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                $invoiceStatus = 'sent';
                if ($dueAmount <= 0.0001) {
                    $invoiceStatus = 'paid';
                    if ($creditUsed > 0 && $effectivePaidAmount < $netTotal) {
                        $effectivePaidAmount = $netTotal;
                    }
                } elseif ($effectivePaidAmount > 0) {
                    $invoiceStatus = 'partial';
                }

                // تحديث الفاتورة بالمبلغ المدفوع والمبلغ المتبقي
                $invoiceUpdateSql = "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW()";
                $invoiceUpdateParams = [$effectivePaidAmount, $dueAmount, $invoiceStatus];
                
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
                
                $invoiceUpdateParams[] = $invoiceId;
                $db->execute($invoiceUpdateSql . " WHERE id = ?", $invoiceUpdateParams);

                foreach ($normalizedCart as $item) {
                    $productId = $item['product_id'];
                    $quantity = $item['quantity'];
                    $unitPrice = $item['unit_price'];
                    $lineTotal = $item['line_total'];

                    // تحديث كمية المنتج في products
                    $product = $db->queryOne(
                        "SELECT id, quantity FROM products WHERE id = ? FOR UPDATE",
                        [$productId]
                    );

                    if ($product) {
                        $currentQty = (float)($product['quantity'] ?? 0);
                        $newQty = max(0, $currentQty - $quantity);
                        $db->execute("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?", [$newQty, $productId]);
                    }

                    // تسجيل حركة المخزون
                    $movementResult = recordInventoryMovement(
                        $productId,
                        $mainWarehouseId,
                        'out',
                        $quantity,
                        'sales',
                        $invoiceId,
                        'بيع من نقطة بيع المدير - فاتورة ' . $invoiceNumber,
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
                }

                logAudit($currentUser['id'], 'create_pos_sale_manager', 'invoice', $invoiceId, null, [
                    'invoice_number'    => $invoiceNumber,
                    'items'             => $normalizedCart,
                    'net_total'         => $netTotal,
                    'paid_amount'       => $effectivePaidAmount,
                    'base_due_amount'   => $baseDueAmount,
                    'credit_used'       => $creditUsed,
                    'due_amount'        => $dueAmount,
                    'customer_id'       => $customerId,
                ]);

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
                    $telegramResult = sendReportAndDelete($reportInfo, 'sales_pos_invoice', 'فاتورة نقطة بيع المدير');
                    $reportInfo['telegram_sent'] = !empty($telegramResult['success']);
                    $posInvoiceLinks = $reportInfo;
                }

                $success = 'تم إتمام عملية البيع بنجاح. رقم الفاتورة: ' . htmlspecialchars($invoiceNumber);
                if ($createdCustomerId) {
                    $success .= ' - تم إنشاء العميل الجديد.';
                }

                // إعادة تحميل المخزون
                $companyInventory = [];
                $inventoryStats = [
                    'total_products' => 0,
                    'total_quantity' => 0,
                    'total_value' => 0,
                ];

                try {
                    if (!empty($finishedProductsTableExists)) {
                        $factoryProducts = $db->query("
                            SELECT 
                                fp.id AS batch_id,
                                fp.batch_number,
                                COALESCE(fp.product_id, bn.product_id) AS product_id,
                                COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                                pr.category as product_category,
                                fp.quantity_produced AS quantity,
                                COALESCE(fp.unit_price, pr.unit_price, 0) AS unit_price,
                                fp.production_date,
                                'factory' AS product_type
                            FROM finished_products fp
                            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                            LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                            WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                            ORDER BY fp.production_date DESC, fp.id DESC
                        ");
                        
                        foreach ($factoryProducts as $fp) {
                            $productId = (int)($fp['product_id'] ?? 0);
                            $quantity = (float)($fp['quantity'] ?? 0);
                            $unitPrice = (float)($fp['unit_price'] ?? 0);
                            $totalValue = $quantity * $unitPrice;
                            
                            $companyInventory[] = [
                                'product_id' => $productId,
                                'batch_id' => (int)($fp['batch_id'] ?? 0),
                                'batch_number' => $fp['batch_number'] ?? '',
                                'product_name' => $fp['product_name'] ?? 'غير محدد',
                                'category' => $fp['product_category'] ?? '',
                                'quantity' => $quantity,
                                'unit_price' => $unitPrice,
                                'total_value' => $totalValue,
                                'product_type' => 'factory',
                                'production_date' => $fp['production_date'] ?? null,
                            ];
                            
                            $inventoryStats['total_products']++;
                            $inventoryStats['total_quantity'] += $quantity;
                            $inventoryStats['total_value'] += $totalValue;
                        }
                    }
                    
                    $externalProducts = $db->query("
                        SELECT 
                            id AS product_id,
                            name AS product_name,
                            category,
                            quantity,
                            COALESCE(unit, 'قطعة') as unit,
                            unit_price,
                            (quantity * unit_price) as total_value,
                            'external' AS product_type
                        FROM products
                        WHERE product_type = 'external'
                          AND status = 'active'
                          AND quantity > 0
                        ORDER BY name ASC
                    ");
                    
                    foreach ($externalProducts as $ext) {
                        $productId = (int)($ext['product_id'] ?? 0);
                        $quantity = (float)($ext['quantity'] ?? 0);
                        $unitPrice = (float)($ext['unit_price'] ?? 0);
                        $totalValue = (float)($ext['total_value'] ?? 0);
                        
                        $companyInventory[] = [
                            'product_id' => $productId,
                            'batch_id' => null,
                            'batch_number' => null,
                            'product_name' => $ext['product_name'] ?? '',
                            'category' => $ext['category'] ?? '',
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'total_value' => $totalValue,
                            'product_type' => 'external',
                            'production_date' => null,
                        ];
                        
                        $inventoryStats['total_products']++;
                        $inventoryStats['total_quantity'] += $quantity;
                        $inventoryStats['total_value'] += $totalValue;
                    }
                } catch (Throwable $e) {
                    error_log('Error reloading inventory: ' . $e->getMessage());
                }

                $inventoryByProduct = [];
                foreach ($companyInventory as $item) {
                    $key = (int)($item['product_id'] ?? 0);
                    if (!isset($inventoryByProduct[$key])) {
                        $inventoryByProduct[$key] = [
                            'quantity' => 0,
                            'unit_price' => 0,
                            'items' => [],
                        ];
                    }
                    $inventoryByProduct[$key]['quantity'] += (float)($item['quantity'] ?? 0);
                    if ((float)($item['unit_price'] ?? 0) > 0) {
                        $inventoryByProduct[$key]['unit_price'] = (float)($item['unit_price'] ?? 0);
                    }
                    $inventoryByProduct[$key]['items'][] = $item;
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

    // معالجة طلبات الشحن
    if ($action === 'add_shipping_company') {
        $name = trim($_POST['company_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['company_notes'] ?? '');

        if ($name === '') {
            $error = 'يجب إدخال اسم شركة الشحن.';
        } else {
            try {
                $existingCompany = $db->queryOne("SELECT id FROM shipping_companies WHERE name = ?", [$name]);
                if ($existingCompany) {
                    throw new InvalidArgumentException('اسم شركة الشحن مستخدم بالفعل.');
                }

                $db->execute(
                    "INSERT INTO shipping_companies (name, contact_person, phone, email, address, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name,
                        $contactPerson !== '' ? $contactPerson : null,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $address !== '' ? $address : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                    ]
                );

                $success = 'تم إضافة شركة الشحن بنجاح.';
                
                // إعادة تحميل قائمة شركات الشحن
                $shippingCompanies = [];
                try {
                    $shippingCompanies = $db->query(
                        "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
                    );
                } catch (Throwable $e) {
                    error_log('Error reloading shipping companies: ' . $e->getMessage());
                }
            } catch (InvalidArgumentException $validationError) {
                $error = $validationError->getMessage();
            } catch (Throwable $addError) {
                error_log('shipping_orders: add company error -> ' . $addError->getMessage());
                $error = 'تعذر إضافة شركة الشحن. يرجى المحاولة لاحقاً.';
            }
        }
    }

    if ($action === 'create_shipping_order') {
        $shippingCompanyId = isset($_POST['shipping_company_id']) ? (int)$_POST['shipping_company_id'] : 0;
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $notes = trim($_POST['order_notes'] ?? '');
        $itemsInput = $_POST['items'] ?? [];

        if ($shippingCompanyId <= 0) {
            $error = 'يرجى اختيار شركة الشحن.';
        } elseif ($customerId <= 0) {
            $error = 'يرجى اختيار العميل.';
        } elseif (!is_array($itemsInput) || empty($itemsInput)) {
            $error = 'يرجى إضافة منتجات إلى الطلب.';
        } else {
            $normalizedItems = [];
            $totalAmount = 0.0;
            $productIds = [];
            $batchIds = [];

            foreach ($itemsInput as $itemRow) {
                if (!is_array($itemRow)) {
                    continue;
                }

                $productId = isset($itemRow['product_id']) ? (int)$itemRow['product_id'] : 0;
                $quantity = isset($itemRow['quantity']) ? (float)$itemRow['quantity'] : 0.0;
                $unitPrice = isset($itemRow['unit_price']) ? (float)$itemRow['unit_price'] : 0.0;
                $batchId = isset($itemRow['batch_id']) && !empty($itemRow['batch_id']) ? (int)$itemRow['batch_id'] : null;

                if ($productId <= 0 || $quantity <= 0 || $unitPrice < 0) {
                    continue;
                }

                // تحديد إذا كان منتج مصنع
                $isFactoryProduct = false;
                if (!empty($batchId)) {
                    // إذا كان هناك batch_id، فهو منتج مصنع
                    $isFactoryProduct = true;
                    $batchIds[] = $batchId;
                } elseif ($productId > 1000000) {
                    // دعم الطريقة القديمة (id > 1000000)
                    $batchId = $productId - 1000000;
                    $isFactoryProduct = true;
                    $batchIds[] = $batchId;
                } else {
                    $productIds[] = $productId;
                }

                $lineTotal = round($quantity * $unitPrice, 2);
                $totalAmount += $lineTotal;

                $normalizedItems[] = [
                    'product_id' => $isFactoryProduct ? 0 : $productId, // للمنتجات المصنع نستخدم 0 أو product_id من finished_products
                    'batch_id' => $batchId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'is_factory_product' => $isFactoryProduct,
                ];
            }

            if (empty($normalizedItems)) {
                $error = 'يرجى التأكد من إدخال بيانات صحيحة للمنتجات.';
            } else {
                $totalAmount = round($totalAmount, 2);
                $transactionStarted = false;

                try {
                    $db->beginTransaction();
                    $transactionStarted = true;

                    $shippingCompany = $db->queryOne(
                        "SELECT id, status, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                        [$shippingCompanyId]
                    );

                    if (!$shippingCompany || ($shippingCompany['status'] ?? '') !== 'active') {
                        throw new InvalidArgumentException('شركة الشحن المحددة غير متاحة أو غير نشطة.');
                    }

                    $customer = $db->queryOne(
                        "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                        [$customerId]
                    );

                    if (!$customer) {
                        throw new InvalidArgumentException('تعذر العثور على العميل المحدد.');
                    }

                    $productsMap = [];
                    $factoryProductsMap = [];
                    
                    // جلب المنتجات الخارجية
                    if (!empty($productIds)) {
                        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                        $productsRows = $db->query(
                            "SELECT id, name, quantity, unit_price FROM products WHERE id IN ($placeholders) FOR UPDATE",
                            $productIds
                        );

                        foreach ($productsRows as $row) {
                            $productsMap[(int)$row['id']] = $row;
                        }
                    }
                    
                    // جلب منتجات المصنع
                    if (!empty($batchIds)) {
                        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
                        $factoryProductsRows = $db->query(
                            "SELECT 
                                fp.id as batch_id,
                                COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS name,
                                fp.quantity_produced,
                                COALESCE(fp.product_id, bn.product_id, 0) as product_id
                             FROM finished_products fp
                             LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                             LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                             WHERE fp.id IN ($placeholders) FOR UPDATE",
                            $batchIds
                        );
                        
                        foreach ($factoryProductsRows as $row) {
                            $batchId = (int)($row['batch_id'] ?? 0);
                            $factoryProductsMap[$batchId] = $row;
                        }
                    }

                    // التحقق من توفر الكميات
                    foreach ($normalizedItems as $normalizedItem) {
                        $requestedQuantity = $normalizedItem['quantity'];
                        $isFactoryProduct = $normalizedItem['is_factory_product'] ?? false;
                        
                        if ($isFactoryProduct) {
                            $batchId = $normalizedItem['batch_id'];
                            $factoryProduct = $factoryProductsMap[$batchId] ?? null;
                            
                            if (!$factoryProduct) {
                                throw new InvalidArgumentException('تعذر العثور على منتج مصنع من عناصر الطلب.');
                            }
                            
                            $quantityProduced = (float)($factoryProduct['quantity_produced'] ?? 0);
                            
                            // حساب الكمية المباعة
                            $soldQuantity = $db->queryOne(
                                "SELECT COALESCE(SUM(si.quantity), 0) AS sold_qty
                                 FROM sale_items si
                                 INNER JOIN sales s ON si.sale_id = s.id
                                 WHERE si.batch_id = ? AND s.status IN ('approved', 'completed')",
                                [$batchId]
                            );
                            $soldQty = (float)($soldQuantity['sold_qty'] ?? 0);
                            
                            // حساب الكمية المحجوزة في طلبات النقل
                            $pendingTransfers = $db->queryOne(
                                "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                 FROM warehouse_transfer_items wti
                                 INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                 WHERE wti.batch_id = ? AND wt.status = 'pending'",
                                [$batchId]
                            );
                            $pendingQty = (float)($pendingTransfers['pending_quantity'] ?? 0);
                            
                            // حساب الكمية المحجوزة في طلبات الشحن المعلقة
                            $pendingShipping = $db->queryOne(
                                "SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                                 FROM shipping_company_order_items soi
                                 INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                                 WHERE soi.batch_id = ? AND sco.status IN ('assigned', 'in_transit')",
                                [$batchId]
                            );
                            $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
                            
                            $availableQuantity = max(0, $quantityProduced - $soldQty - $pendingQty - $pendingShippingQty);
                            
                            if ($availableQuantity < $requestedQuantity) {
                                $productName = $factoryProduct['name'] ?? 'غير محدد';
                                throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . $productName . ' غير كافية. المتاح: ' . number_format($availableQuantity, 2));
                            }
                        } else {
                            $productId = $normalizedItem['product_id'];
                            $productRow = $productsMap[$productId] ?? null;
                            
                            if (!$productRow) {
                                throw new InvalidArgumentException('تعذر العثور على منتج من عناصر الطلب.');
                            }

                            $availableQuantity = (float)($productRow['quantity'] ?? 0);
                            if ($availableQuantity < $requestedQuantity) {
                                throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . ($productRow['name'] ?? '') . ' غير كافية.');
                            }
                        }
                    }

                    $invoiceItems = [];
                    foreach ($normalizedItems as $normalizedItem) {
                        $isFactoryProduct = $normalizedItem['is_factory_product'] ?? false;
                        $productName = '';
                        
                        if ($isFactoryProduct) {
                            $batchId = $normalizedItem['batch_id'];
                            $factoryProduct = $factoryProductsMap[$batchId] ?? null;
                            $productName = $factoryProduct['name'] ?? 'غير محدد';
                        } else {
                            $productId = $normalizedItem['product_id'];
                            $productRow = $productsMap[$productId] ?? null;
                            $productName = $productRow['name'] ?? 'غير محدد';
                        }
                        
                        $invoiceItems[] = [
                            'product_id' => $isFactoryProduct ? ($factoryProductsMap[$normalizedItem['batch_id']]['product_id'] ?? 0) : $normalizedItem['product_id'],
                            'description' => $productName,
                            'quantity' => $normalizedItem['quantity'],
                            'unit_price' => $normalizedItem['unit_price'],
                        ];
                    }

                    $invoiceResult = createInvoice(
                        $customerId,
                        null,
                        date('Y-m-d'),
                        $invoiceItems,
                        0,
                        0,
                        $notes,
                        $currentUser['id'] ?? null
                    );

                    if (empty($invoiceResult['success'])) {
                        throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة الخاصة بالطلب.');
                    }

                    $invoiceId = (int)$invoiceResult['invoice_id'];
                    $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                    $db->execute(
                        "UPDATE invoices SET paid_amount = 0, remaining_amount = ?, status = 'sent', updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $invoiceId]
                    );

                    $orderNumber = generateShippingOrderNumber($db);

                    $db->execute(
                        "INSERT INTO shipping_company_orders (order_number, shipping_company_id, customer_id, invoice_id, total_amount, status, handed_over_at, notes, created_by) VALUES (?, ?, ?, ?, ?, 'assigned', NOW(), ?, ?)",
                        [
                            $orderNumber,
                            $shippingCompanyId,
                            $customerId,
                            $invoiceId,
                            $totalAmount,
                            $notes !== '' ? $notes : null,
                            $currentUser['id'] ?? null,
                        ]
                    );

                    $orderId = (int)$db->getLastInsertId();

                    foreach ($normalizedItems as $normalizedItem) {
                        $isFactoryProduct = $normalizedItem['is_factory_product'] ?? false;
                        $batchId = $normalizedItem['batch_id'] ?? null;
                        $productId = $normalizedItem['product_id'];
                        
                        // تحديد product_id الصحيح
                        if ($isFactoryProduct && $batchId) {
                            $factoryProduct = $factoryProductsMap[$batchId] ?? null;
                            $productId = (int)($factoryProduct['product_id'] ?? 0);
                        }
                        
                        // حفظ عنصر الطلب مع batch_id
                        $db->execute(
                            "INSERT INTO shipping_company_order_items (order_id, product_id, batch_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)",
                            [
                                $orderId,
                                $productId > 0 ? $productId : 0,
                                $batchId,
                                $normalizedItem['quantity'],
                                $normalizedItem['unit_price'],
                                $normalizedItem['total_price'],
                            ]
                        );

                        // تسجيل حركة المخزون
                        $movementNote = 'تسليم طلب شحن #' . $orderNumber . ' لشركة الشحن';
                        
                        if ($isFactoryProduct && $batchId) {
                            // لمنتجات المصنع، نستخدم batch_id
                            $movementResult = recordInventoryMovement(
                                $productId > 0 ? $productId : 0,
                                $mainWarehouseId,
                                'out',
                                $normalizedItem['quantity'],
                                'shipping_order',
                                $orderId,
                                $movementNote,
                                $currentUser['id'] ?? null,
                                $batchId
                            );
                        } else {
                            // للمنتجات الخارجية
                            $movementResult = recordInventoryMovement(
                                $productId,
                                $mainWarehouseId,
                                'out',
                                $normalizedItem['quantity'],
                                'shipping_order',
                                $orderId,
                                $movementNote,
                                $currentUser['id'] ?? null
                            );
                        }

                        if (empty($movementResult['success'])) {
                            throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                        }
                    }

                    $db->execute(
                        "UPDATE shipping_companies SET balance = balance + ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $currentUser['id'] ?? null, $shippingCompanyId]
                    );

                    logAudit(
                        $currentUser['id'] ?? null,
                        'create_shipping_order',
                        'shipping_order',
                        $orderId,
                        null,
                        [
                            'order_number' => $orderNumber,
                            'total_amount' => $totalAmount,
                            'shipping_company_id' => $shippingCompanyId,
                            'customer_id' => $customerId,
                        ]
                    );

                    $db->commit();
                    $transactionStarted = false;

                    $success = 'تم تسجيل طلب الشحن وتسليم المنتجات لشركة الشحن بنجاح.';
                    
                    // إعادة تحميل البيانات
                    $shippingCompanies = [];
                    try {
                        $shippingCompanies = $db->query(
                            "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
                        );
                    } catch (Throwable $e) {
                        error_log('Error reloading shipping companies: ' . $e->getMessage());
                    }
                    
                    $shippingOrders = [];
                    try {
                        $shippingOrders = $db->query(
                            "SELECT 
                                sco.*, 
                                sc.name AS shipping_company_name,
                                sc.balance AS company_balance,
                                c.name AS customer_name,
                                c.phone AS customer_phone,
                                i.invoice_number
                            FROM shipping_company_orders sco
                            LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
                            LEFT JOIN customers c ON sco.customer_id = c.id
                            LEFT JOIN invoices i ON sco.invoice_id = i.id
                            ORDER BY sco.created_at DESC
                            LIMIT 50"
                        );
                    } catch (Throwable $e) {
                        error_log('Error reloading shipping orders: ' . $e->getMessage());
                    }
                } catch (InvalidArgumentException $validationError) {
                    if ($transactionStarted) {
                        $db->rollback();
                    }
                    $error = $validationError->getMessage();
                } catch (Throwable $createError) {
                    if ($transactionStarted) {
                        $db->rollback();
                    }
                    error_log('shipping_orders: create order error -> ' . $createError->getMessage());
                    $error = 'تعذر إنشاء طلب الشحن. يرجى المحاولة لاحقاً.';
                }
            }
        }
    }

    if ($action === 'update_shipping_status') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['in_transit'];

        if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'طلب غير صالح لتحديث الحالة.';
        } else {
            try {
                $updateResult = $db->execute(
                    "UPDATE shipping_company_orders SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND status = 'assigned'",
                    [$newStatus, $currentUser['id'] ?? null, $orderId]
                );

                if (($updateResult['affected_rows'] ?? 0) < 1) {
                    throw new RuntimeException('لا يمكن تحديث حالة هذا الطلب في الوقت الحالي.');
                }

                logAudit(
                    $currentUser['id'] ?? null,
                    'update_shipping_order_status',
                    'shipping_order',
                    $orderId,
                    null,
                    ['status' => $newStatus]
                );

                $success = 'تم تحديث حالة طلب الشحن.';
                
                // إعادة تحميل طلبات الشحن
                $shippingOrders = [];
                try {
                    $shippingOrders = $db->query(
                        "SELECT 
                            sco.*, 
                            sc.name AS shipping_company_name,
                            sc.balance AS company_balance,
                            c.name AS customer_name,
                            c.phone AS customer_phone,
                            i.invoice_number
                        FROM shipping_company_orders sco
                        LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
                        LEFT JOIN customers c ON sco.customer_id = c.id
                        LEFT JOIN invoices i ON sco.invoice_id = i.id
                        ORDER BY sco.created_at DESC
                        LIMIT 50"
                    );
                } catch (Throwable $e) {
                    error_log('Error reloading shipping orders: ' . $e->getMessage());
                }
            } catch (Throwable $statusError) {
                error_log('shipping_orders: update status error -> ' . $statusError->getMessage());
                $error = 'تعذر تحديث حالة الطلب.';
            }
        }
    }

    if ($action === 'complete_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

        if ($orderId <= 0) {
            $error = 'طلب غير صالح لإتمام التسليم.';
        } else {
            $transactionStarted = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $order = $db->queryOne(
                    "SELECT id, shipping_company_id, customer_id, total_amount, status, invoice_id FROM shipping_company_orders WHERE id = ? FOR UPDATE",
                    [$orderId]
                );

                if (!$order) {
                    throw new InvalidArgumentException('طلب الشحن المحدد غير موجود.');
                }

                if ($order['status'] === 'delivered') {
                    throw new InvalidArgumentException('تم تسليم هذا الطلب بالفعل.');
                }

                if ($order['status'] === 'cancelled') {
                    throw new InvalidArgumentException('لا يمكن إتمام طلب ملغى.');
                }

                $shippingCompany = $db->queryOne(
                    "SELECT id, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                    [$order['shipping_company_id']]
                );

                if (!$shippingCompany) {
                    throw new InvalidArgumentException('شركة الشحن المرتبطة بالطلب غير موجودة.');
                }

                $customer = $db->queryOne(
                    "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                    [$order['customer_id']]
                );

                if (!$customer) {
                    throw new InvalidArgumentException('تعذر العثور على العميل المرتبط بالطلب.');
                }

                $totalAmount = (float)($order['total_amount'] ?? 0.0);

                $db->execute(
                    "UPDATE shipping_companies SET balance = balance - ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $currentUser['id'] ?? null, $order['shipping_company_id']]
                );

                $db->execute(
                    "UPDATE customers SET balance = balance + ?, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $order['customer_id']]
                );

                $db->execute(
                    "UPDATE shipping_company_orders SET status = 'delivered', delivered_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$currentUser['id'] ?? null, $orderId]
                );

                if (!empty($order['invoice_id'])) {
                    $db->execute(
                        "UPDATE invoices SET status = 'sent', remaining_amount = ?, updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $order['invoice_id']]
                    );
                }

                logAudit(
                    $currentUser['id'] ?? null,
                    'complete_shipping_order',
                    'shipping_order',
                    $orderId,
                    null,
                    [
                        'total_amount' => $totalAmount,
                        'customer_id' => $order['customer_id'],
                        'shipping_company_id' => $order['shipping_company_id'],
                    ]
                );

                $db->commit();
                $transactionStarted = false;

                $success = 'تم تأكيد تسليم الطلب للعميل ونقل الدين بنجاح.';
                
                // إعادة تحميل البيانات
                $shippingCompanies = [];
                try {
                    $shippingCompanies = $db->query(
                        "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
                    );
                } catch (Throwable $e) {
                    error_log('Error reloading shipping companies: ' . $e->getMessage());
                }
                
                $shippingOrders = [];
                try {
                    $shippingOrders = $db->query(
                        "SELECT 
                            sco.*, 
                            sc.name AS shipping_company_name,
                            sc.balance AS company_balance,
                            c.name AS customer_name,
                            c.phone AS customer_phone,
                            i.invoice_number
                        FROM shipping_company_orders sco
                        LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
                        LEFT JOIN customers c ON sco.customer_id = c.id
                        LEFT JOIN invoices i ON sco.invoice_id = i.id
                        ORDER BY sco.created_at DESC
                        LIMIT 50"
                    );
                } catch (Throwable $e) {
                    error_log('Error reloading shipping orders: ' . $e->getMessage());
                }
            } catch (InvalidArgumentException $validationError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $error = $validationError->getMessage();
            } catch (Throwable $completeError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('shipping_orders: complete order error -> ' . $completeError->getMessage());
                $error = 'تعذر إتمام إجراءات الطلب. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

// جلب بيانات طلبات الشحن
$shippingCompanies = [];
try {
    $shippingCompanies = $db->query(
        "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
    );
} catch (Throwable $e) {
    error_log('Error fetching shipping companies: ' . $e->getMessage());
}

$shippingOrders = [];
try {
    $shippingOrders = $db->query(
        "SELECT 
            sco.*, 
            sc.name AS shipping_company_name,
            sc.balance AS company_balance,
            c.name AS customer_name,
            c.phone AS customer_phone,
            i.invoice_number
        FROM shipping_company_orders sco
        LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
        LEFT JOIN customers c ON sco.customer_id = c.id
        LEFT JOIN invoices i ON sco.invoice_id = i.id
        ORDER BY sco.created_at DESC
        LIMIT 50"
    );
} catch (Throwable $e) {
    error_log('Error fetching shipping orders: ' . $e->getMessage());
}

$statusLabels = [
    'assigned' => ['label' => 'تم التسليم لشركة الشحن', 'class' => 'bg-primary'],
    'in_transit' => ['label' => 'جاري الشحن', 'class' => 'bg-warning text-dark'],
    'delivered' => ['label' => 'تم التسليم للعميل', 'class' => 'bg-success'],
    'cancelled' => ['label' => 'ملغي', 'class' => 'bg-secondary'],
];

// آخر عمليات البيع
$recentSales = [];
try {
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
} catch (Throwable $e) {
    error_log('Error fetching recent sales: ' . $e->getMessage());
}

// جلب المنتجات المتاحة لطلبات الشحن (من finished_products و products)
$availableProductsForShipping = [];
try {
    // جلب منتجات المصنع من finished_products
    $factoryProductsForShipping = [];
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    if (!empty($finishedProductsTableExists)) {
        $factoryProductsRaw = $db->query("
            SELECT 
                fp.id as batch_id,
                fp.batch_number,
                COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS name,
                fp.quantity_produced as quantity,
                COALESCE(
                    NULLIF(fp.unit_price, 0),
                    (SELECT pt.unit_price 
                     FROM product_templates pt 
                     WHERE pt.status = 'active' 
                       AND pt.unit_price IS NOT NULL 
                       AND pt.unit_price > 0
                       AND pt.unit_price <= 10000
                       AND (
                           (COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                            AND COALESCE(fp.product_id, bn.product_id) > 0
                            AND pt.product_id IS NOT NULL 
                            AND pt.product_id > 0 
                            AND pt.product_id = COALESCE(fp.product_id, bn.product_id))
                           OR (pt.product_name IS NOT NULL 
                               AND pt.product_name != ''
                               AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                               AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                               AND (LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                    OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                    OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')))
                           )
                     ORDER BY pt.unit_price DESC
                     LIMIT 1),
                    0
                ) AS unit_price,
                COALESCE(pr.unit, 'قطعة') as unit,
                fp.production_date
            FROM finished_products fp
            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
            LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
            WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
            ORDER BY fp.production_date DESC, fp.id DESC
        ");
        
        // حساب الكمية المتاحة بعد خصم المبيعات والتحويلات
        foreach ($factoryProductsRaw as $fp) {
            $batchId = (int)($fp['batch_id'] ?? 0);
            $quantityProduced = (float)($fp['quantity'] ?? 0);
            
            // حساب الكمية المباعة من هذا التشغيلة
            $soldQuantity = $db->queryOne(
                "SELECT COALESCE(SUM(si.quantity), 0) AS sold_qty
                 FROM sale_items si
                 INNER JOIN sales s ON si.sale_id = s.id
                 WHERE si.batch_id = ? AND s.status IN ('approved', 'completed')",
                [$batchId]
            );
            $soldQty = (float)($soldQuantity['sold_qty'] ?? 0);
            
            // حساب الكمية المحجوزة في طلبات النقل المعلقة
            $pendingTransfers = $db->queryOne(
                "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                 FROM warehouse_transfer_items wti
                 INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                 WHERE wti.batch_id = ? AND wt.status = 'pending'",
                [$batchId]
            );
            $pendingQty = (float)($pendingTransfers['pending_quantity'] ?? 0);
            
            // حساب الكمية المحجوزة في طلبات الشحن المعلقة
            $pendingShipping = $db->queryOne(
                "SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                 FROM shipping_company_order_items soi
                 INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                 WHERE soi.batch_id = ? AND sco.status IN ('assigned', 'in_transit')",
                [$batchId]
            );
            $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
            
            // الكمية المتاحة = الكمية المنتجة - المباعة - المحجوزة في النقل - المحجوزة في الشحن
            $availableQty = max(0, $quantityProduced - $soldQty - $pendingQty - $pendingShippingQty);
            
            if ($availableQty > 0) {
                $batchNumber = $fp['batch_number'] ?? '';
                $productName = $fp['name'] ?? 'غير محدد';
                $displayName = $batchNumber ? $productName . ' - تشغيلة ' . $batchNumber : $productName;
                
                $factoryProductsForShipping[] = [
                    'id' => $batchId + 1000000, // استخدام رقم فريد لمنتجات المصنع
                    'batch_id' => $batchId,
                    'batch_number' => $batchNumber,
                    'name' => $productName, // اسم المنتج بدون رقم التشغيلة
                    'quantity' => $availableQty,
                    'unit' => $fp['unit'] ?? 'قطعة',
                    'unit_price' => (float)($fp['unit_price'] ?? 0),
                    'is_factory_product' => true
                ];
            }
        }
    }
    
    // جلب المنتجات الخارجية من products
    $externalProductsForShipping = $db->query(
        "SELECT id, name, quantity, COALESCE(unit, 'قطعة') as unit, unit_price 
         FROM products 
         WHERE status = 'active' 
           AND quantity > 0 
           AND (product_type = 'external' OR product_type IS NULL)
         ORDER BY name ASC"
    );
    
    // دمج المنتجات مع إضافة batch_id = null للمنتجات الخارجية
    foreach ($externalProductsForShipping as $ep) {
        $availableProductsForShipping[] = [
            'id' => (int)($ep['id'] ?? 0),
            'batch_id' => null,
            'name' => $ep['name'] ?? '',
            'quantity' => (float)($ep['quantity'] ?? 0),
            'unit' => $ep['unit'] ?? 'قطعة',
            'unit_price' => (float)($ep['unit_price'] ?? 0),
            'is_factory_product' => false
        ];
    }
    
    // إضافة منتجات المصنع
    $availableProductsForShipping = array_merge($availableProductsForShipping, $factoryProductsForShipping);
    
    // ترتيب حسب الاسم
    usort($availableProductsForShipping, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
} catch (Throwable $e) {
    error_log('Error fetching products for shipping: ' . $e->getMessage());
}
?>

<div class="page-header">
    <h2><i class="bi bi-shop me-2"></i>نقطة بيع المدير</h2>
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

<!-- Tabs للتنقل بين نقطة البيع وطلبات الشحن -->
<ul class="nav nav-tabs mb-4" id="posTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="pos-tab" data-bs-toggle="tab" data-bs-target="#pos-content" type="button" role="tab">
            <i class="bi bi-cart-check me-2"></i>نقطة البيع
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="shipping-tab" data-bs-toggle="tab" data-bs-target="#shipping-content" type="button" role="tab">
            <i class="bi bi-truck me-2"></i>طلبات الشحن
        </button>
    </li>
</ul>

<div class="tab-content" id="posTabContent">
    <!-- محتوى نقطة البيع -->
    <div class="tab-pane fade show active" id="pos-content" role="tabpanel">
        <style>
            .pos-wrapper {
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
            }
            .pos-warehouse-summary {
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
        </style>

        <div class="pos-wrapper">
            <section class="pos-warehouse-summary">
                <div class="pos-summary-card">
                    <span class="label">مخزن الشركة</span>
                    <div class="value"><?php echo htmlspecialchars($mainWarehouse['name'] ?? 'المخزن الرئيسي'); ?></div>
                    <div class="meta">المخزن الرئيسي للشركة</div>
                    <i class="bi bi-building icon"></i>
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
                    <div class="meta">إجمالي الوحدات في المخزن</div>
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
                            <h4>مخزن الشركة الرئيسي</h4>
                            <p>اضغط على المنتج لإضافته إلى سلة البيع</p>
                        </div>
                        <div class="pos-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="posInventorySearch" class="form-control" placeholder="بحث سريع عن منتج..."<?php echo empty($companyInventory) ? ' disabled' : ''; ?>>
                        </div>
                    </div>
                    <div class="pos-product-grid" id="posProductGrid">
                        <?php if (empty($companyInventory)): ?>
                            <div class="pos-empty pos-empty-inline">
                                <i class="bi bi-box"></i>
                                <h5 class="mt-3 mb-2">لا يوجد مخزون متاح حالياً</h5>
                                <p class="mb-0">لا توجد منتجات في مخزن الشركة الرئيسي حالياً.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($companyInventory as $item): ?>
                                <div class="pos-product-card" data-product-card data-product-id="<?php echo (int) $item['product_id']; ?>" data-name="<?php echo htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-category="<?php echo htmlspecialchars($item['category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="pos-product-name"><?php echo htmlspecialchars($item['product_name'] ?? 'منتج'); ?></div>
                                    <?php if (!empty($item['category'])): ?>
                                        <div class="pos-product-meta">
                                            <span class="pos-product-badge"><?php echo htmlspecialchars($item['category']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['batch_number'])): ?>
                                        <div class="pos-product-meta">
                                            <span>رقم التشغيلة</span>
                                            <span class="text-muted small"><?php echo htmlspecialchars($item['batch_number']); ?></span>
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
                                    <button type="button"
                                            class="btn btn-outline-primary pos-select-btn"
                                            data-select-product
                                            data-product-id="<?php echo (int) $item['product_id']; ?>">
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
                                    <label class="form-label">مدفوع مسبقاً (اختياري)</label>
                                    <input type="number" step="0.01" min="0" value="0" class="form-control form-control-sm" id="posPrepaidInput" name="prepaid_amount">
                                    <div class="pos-inline-note">سيتم خصم المبلغ من إجمالي السلة.</div>
                                </div>
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
                                    <input type="number" step="0.01" min="0" value="0" class="form-control" id="posPartialAmount">
                                </div>
                                <div class="mt-3 d-none" id="posDueDateWrapper">
                                    <label class="form-label">تاريخ الاستحقاق <span class="text-muted">(اختياري)</span></label>
                                    <input type="date" class="form-control" name="due_date" id="posDueDate">
                                    <small class="text-muted">اتركه فارغاً لطباعة "أجل غير مسمى"</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ملاحظات إضافية <span class="text-muted">(اختياري)</span></label>
                                <textarea class="form-control form-control-sm" name="notes" rows="3" placeholder="مثال: تعليمات التسليم، شروط خاصة..."></textarea>
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
                                <div class="empty-state-description">ابدأ ببيع منتجات مخزن الشركة ليظهر السجل هنا.</div>
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
    </div>
    <!-- محتوى طلبات الشحن -->
    <div class="tab-pane fade" id="shipping-content" role="tabpanel">
        <?php
        // إحصائيات طلبات الشحن
        $ordersStats = [
            'total_orders' => 0,
            'active_orders' => 0,
            'delivered_orders' => 0,
            'outstanding_amount' => 0.0,
        ];

        try {
            $statsRow = $db->queryOne(
                "SELECT 
                    COUNT(*) AS total_orders,
                    SUM(CASE WHEN status IN ('assigned','in_transit') THEN 1 ELSE 0 END) AS active_orders,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
                    SUM(CASE WHEN status IN ('assigned','in_transit') THEN total_amount ELSE 0 END) AS outstanding_amount
                FROM shipping_company_orders"
            );

            if ($statsRow) {
                $ordersStats['total_orders'] = (int)($statsRow['total_orders'] ?? 0);
                $ordersStats['active_orders'] = (int)($statsRow['active_orders'] ?? 0);
                $ordersStats['delivered_orders'] = (int)($statsRow['delivered_orders'] ?? 0);
                $ordersStats['outstanding_amount'] = (float)($statsRow['outstanding_amount'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('Error fetching shipping stats: ' . $e->getMessage());
        }
        ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon blue"><i class="bi bi-building"></i></div>
                    </div>
                    <div class="stat-card-title">شركات الشحن</div>
                    <div class="stat-card-value"><?php echo number_format(count($shippingCompanies)); ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon orange"><i class="bi bi-truck"></i></div>
                    </div>
                    <div class="stat-card-title">طلبات نشطة</div>
                    <div class="stat-card-value"><?php echo number_format($ordersStats['active_orders']); ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon green"><i class="bi bi-check2-circle"></i></div>
                    </div>
                    <div class="stat-card-title">طلبات مكتملة</div>
                    <div class="stat-card-value"><?php echo number_format($ordersStats['delivered_orders']); ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon purple"><i class="bi bi-cash-stack"></i></div>
                    </div>
                    <div class="stat-card-title">مبالغ قيد التحصيل</div>
                    <div class="stat-card-value"><?php echo formatCurrency($ordersStats['outstanding_amount']); ?></div>
                </div>
            </div>
        </div>

        <?php if (empty($shippingCompanies)): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2">
                <i class="bi bi-info-circle-fill fs-5"></i>
                <div>لم يتم إضافة شركات شحن بعد. يرجى إضافة شركة شحن قبل تسجيل الطلبات.</div>
            </div>
        <?php elseif (empty($availableProductsForShipping)): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2">
                <i class="bi bi-box-seam fs-5"></i>
                <div>لا توجد منتجات متاحة في المخزن الرئيسي حالياً.</div>
            </div>
        <?php elseif (empty($customers)): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2">
                <i class="bi bi-people fs-5"></i>
                <div>لا توجد عملاء نشطون في النظام. قم بإضافة عميل أولاً.</div>
            </div>
        <?php else: ?>
            <!-- نموذج POS لتسجيل طلب الشحن -->
            <div class="shipping-pos-wrapper">
                <form method="POST" id="shippingOrderForm" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="create_shipping_order">
                    
                    <!-- معلومات الطلب الأساسية -->
                    <div class="shipping-pos-header-card mb-4">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-4 col-md-6">
                                <label class="form-label fw-semibold">شركة الشحن <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <select class="form-select form-select-lg" name="shipping_company_id" id="shippingCompanySelect" required>
                                        <option value="">اختر شركة الشحن</option>
                                        <?php foreach ($shippingCompanies as $company): ?>
                                            <?php if (($company['status'] ?? '') === 'active'): ?>
                                                <option value="<?php echo (int)$company['id']; ?>">
                                                    <?php echo htmlspecialchars($company['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#addShippingCompanyModal" title="إضافة شركة شحن">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <small class="text-muted">أو اختر من البطاقات أعلاه</small>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <label class="form-label fw-semibold">العميل <span class="text-danger">*</span></label>
                                <select class="form-select form-select-lg" name="customer_id" required>
                                    <option value="">اختر العميل</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo (int)$customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label fw-semibold">المخزن المصدر</label>
                                <div class="form-control form-control-lg bg-light">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo htmlspecialchars($mainWarehouse['name'] ?? 'المخزن الرئيسي'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- المنتجات والسلة -->
                    <div class="shipping-pos-content">
                        <!-- بطاقات المنتجات -->
                        <div class="shipping-pos-products-panel">
                            <div class="shipping-pos-panel-header">
                                <div>
                                    <h4>المنتجات المتاحة للشحن</h4>
                                    <p>اضغط على المنتج لإضافته إلى السلة</p>
                                </div>
                                <div class="shipping-pos-search">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="shippingProductsSearch" class="form-control" placeholder="بحث سريع عن منتج...">
                                </div>
                            </div>
                            <div class="shipping-pos-product-grid" id="shippingProductGrid">
                                <?php if (empty($availableProductsForShipping)): ?>
                                    <div class="text-center py-5 text-muted w-100" style="grid-column: 1 / -1;">
                                        <i class="bi bi-box-seam fs-1 d-block mb-3 opacity-25"></i>
                                        <div>لا توجد منتجات متاحة للشحن حالياً.</div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($availableProductsForShipping as $product): ?>
                                        <div class="shipping-pos-product-card" 
                                             data-product-id="<?php echo (int)($product['id'] ?? 0); ?>"
                                             data-batch-id="<?php echo !empty($product['batch_id']) ? (int)$product['batch_id'] : ''; ?>"
                                             data-name="<?php echo htmlspecialchars($product['name'] ?? '', ENT_QUOTES); ?>"
                                             data-available="<?php echo (float)($product['quantity'] ?? 0); ?>"
                                             data-unit-price="<?php echo (float)($product['unit_price'] ?? 0); ?>"
                                             data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>">
                                            <div class="shipping-pos-product-name"><?php echo htmlspecialchars($product['name'] ?? 'منتج'); ?></div>
                                            <?php if (!empty($product['batch_number'])): ?>
                                                <div class="shipping-pos-product-meta">
                                                    <span>رقم التشغيلة</span>
                                                    <span class="badge bg-info text-dark"><?php echo htmlspecialchars($product['batch_number']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="shipping-pos-product-meta">
                                                <span>سعر الوحدة</span>
                                                <strong><?php echo formatCurrency((float)($product['unit_price'] ?? 0)); ?></strong>
                                            </div>
                                            <div class="shipping-pos-product-meta">
                                                <span>المتاح</span>
                                                <span class="shipping-pos-product-qty"><?php echo number_format((float)($product['quantity'] ?? 0), 2); ?></span>
                                            </div>
                                            <button type="button" class="btn btn-primary btn-sm w-100 mt-2 add-to-shipping-cart-btn">
                                                <i class="bi bi-plus-circle me-1"></i>إضافة للسلة
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- السلة -->
                        <div class="shipping-pos-cart-panel">
                            <div class="shipping-pos-panel-header">
                                <h4>سلة طلب الشحن</h4>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="clearShippingCartBtn">
                                    <i class="bi bi-trash me-1"></i>تفريغ
                                </button>
                            </div>
                            
                            <div class="shipping-pos-cart-empty" id="shippingCartEmpty">
                                <i class="bi bi-basket3 fs-1 text-muted opacity-50"></i>
                                <p class="mt-3 mb-0 text-muted">لم يتم إضافة أي منتجات. اضغط على بطاقة المنتج لإضافتها.</p>
                            </div>
                            
                            <div class="shipping-pos-cart-content d-none" id="shippingCartContent">
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm align-middle" id="shippingItemsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>المنتج</th>
                                                <th style="width: 100px;">الكمية</th>
                                                <th style="width: 120px;">السعر</th>
                                                <th style="width: 120px;">الإجمالي</th>
                                                <th style="width: 60px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="shippingItemsBody"></tbody>
                                    </table>
                                </div>
                                
                                <div class="shipping-pos-summary-card">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted">عدد المنتجات</span>
                                        <strong id="shippingItemsCount">0</strong>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                        <span class="fw-bold">إجمالي الطلب</span>
                                        <strong class="text-success fs-5" id="shippingOrderTotal"><?php echo formatCurrency(0); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="shipping-pos-cart-footer mt-3">
                                <div class="mb-3">
                                    <label class="form-label">ملاحظات إضافية</label>
                                    <textarea class="form-control" name="order_notes" rows="2" placeholder="أي تفاصيل إضافية لشركة الشحن (اختياري)"></textarea>
                                </div>
                                
                                <div class="alert alert-info mb-3">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    سيتم تسجيل هذا الطلب على شركة الشحن كدين لحين تأكيد التسليم للعميل.
                                </div>
                                
                                <button type="submit" class="btn btn-success btn-lg w-100" id="submitShippingOrderBtn">
                                    <i class="bi bi-send-check me-1"></i>تسجيل الطلب وتسليم المنتجات
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-truck me-2"></i>شركات الشحن</h5>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addShippingCompanyModal">
                    <i class="bi bi-plus-circle me-1"></i>إضافة شركة جديدة
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($shippingCompanies)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-truck fs-1 d-block mb-3 opacity-25"></i>
                        <div>لم يتم إضافة شركات شحن بعد. يرجى إضافة شركة شحن للبدء.</div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($shippingCompanies as $company): ?>
                            <?php 
                                $isActive = ($company['status'] ?? '') === 'active';
                                $balance = (float)($company['balance'] ?? 0);
                                $hasDebt = $balance > 0;
                            ?>
                            <div class="col-lg-4 col-md-6">
                                <div class="shipping-company-card h-100 <?php echo $isActive ? 'active' : 'inactive'; ?>" 
                                     data-company-id="<?php echo (int)$company['id']; ?>"
                                     data-company-name="<?php echo htmlspecialchars($company['name'], ENT_QUOTES); ?>">
                                    <div class="shipping-company-card-header">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="shipping-company-icon">
                                                <i class="bi bi-truck"></i>
                                            </div>
                                            <?php if ($isActive): ?>
                                                <span class="badge bg-success">نشطة</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">غير نشطة</span>
                                            <?php endif; ?>
                                        </div>
                                        <h6 class="shipping-company-name mb-0"><?php echo htmlspecialchars($company['name']); ?></h6>
                                    </div>
                                    <div class="shipping-company-card-body">
                                        <?php if (!empty($company['phone'])): ?>
                                            <div class="shipping-company-info-item">
                                                <i class="bi bi-telephone"></i>
                                                <span><?php echo htmlspecialchars($company['phone']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="shipping-company-info-item <?php echo $hasDebt ? 'text-danger' : 'text-muted'; ?>">
                                            <i class="bi bi-<?php echo $hasDebt ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                                            <span>
                                                <?php if ($hasDebt): ?>
                                                    دين: <?php echo formatCurrency($balance); ?>
                                                <?php else: ?>
                                                    لا توجد ديون
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($isActive): ?>
                                        <div class="shipping-company-card-footer">
                                            <button type="button" class="btn btn-primary btn-sm w-100 select-shipping-company-btn">
                                                <i class="bi bi-check-circle me-1"></i>اختر للشحن
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">أحدث طلبات الشحن</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($shippingOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات شحن مسجلة حالياً.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>شركة الشحن</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>تاريخ التسليم للعميل</th>
                                    <th>الفاتورة</th>
                                    <th style="width: 220px;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shippingOrders as $order): ?>
                                    <?php
                                        $statusInfo = $statusLabels[$order['status']] ?? ['label' => $order['status'], 'class' => 'bg-secondary'];
                                        $deliveredAt = $order['delivered_at'] ?? null;
                                        $invoiceLink = '';
                                        if (!empty($order['invoice_id'])) {
                                            $invoiceUrl = getRelativeUrl('print_invoice.php?id=' . (int)$order['invoice_id']);
                                            $invoiceLink = '<a href="' . htmlspecialchars($invoiceUrl) . '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>عرض الفاتورة</a>';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                            <div class="text-muted small">سجل في <?php echo formatDateTime($order['created_at']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['shipping_company_name'] ?? 'غير معروف'); ?></div>
                                            <div class="text-muted small">دين حالي: <?php echo formatCurrency((float)($order['company_balance'] ?? 0)); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                            <?php if (!empty($order['customer_phone'])): ?>
                                                <div class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency((float)$order['total_amount']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $statusInfo['class']; ?>">
                                                <?php echo htmlspecialchars($statusInfo['label']); ?>
                                            </span>
                                            <?php if (!empty($order['handed_over_at'])): ?>
                                                <div class="text-muted small mt-1">سُلِّم للشركة: <?php echo formatDateTime($order['handed_over_at']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($deliveredAt): ?>
                                                <span class="text-success fw-semibold"><?php echo formatDateTime($deliveredAt); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">لم يتم التسليم بعد</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $invoiceLink ?: '<span class="text-muted">لا توجد فاتورة</span>'; ?></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php if ($order['status'] === 'assigned'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="update_shipping_status">
                                                        <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                        <input type="hidden" name="status" value="in_transit">
                                                        <button type="submit" class="btn btn-outline-warning btn-sm">
                                                            <i class="bi bi-truck me-1"></i>بدء الشحن
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if (in_array($order['status'], ['assigned', 'in_transit'], true)): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('هل ترغب في تأكيد تسليم الطلب للعميل ونقل الدين؟');">
                                                        <input type="hidden" name="action" value="complete_shipping_order">
                                                        <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                        <button type="submit" class="btn btn-success btn-sm">
                                                            <i class="bi bi-check-circle me-1"></i>إجراءات التسليم
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modal إضافة شركة شحن -->
        <div class="modal fade" id="addShippingCompanyModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-truck me-2"></i>إضافة شركة شحن</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_shipping_company">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">اسم الشركة <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="company_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">الشخص المسؤول</label>
                                <input type="text" class="form-control" name="contact_person" placeholder="اسم الشخص المسؤول (اختياري)">
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">رقم الهاتف</label>
                                    <input type="text" class="form-control" name="phone" placeholder="مثال: 01000000000">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">البريد الإلكتروني</label>
                                    <input type="email" class="form-control" name="email" placeholder="example@domain.com">
                                </div>
                            </div>
                            <div class="mb-3 mt-3">
                                <label class="form-label">العنوان</label>
                                <textarea class="form-control" name="address" rows="2" placeholder="عنوان شركة الشحن (اختياري)"></textarea>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">ملاحظات</label>
                                <textarea class="form-control" name="company_notes" rows="2" placeholder="أي معلومات إضافية (اختياري)"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>حفظ
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const products = <?php echo json_encode(array_map(function ($product) {
                return [
                    'id' => (int)($product['id'] ?? 0),
                    'batch_id' => !empty($product['batch_id']) ? (int)$product['batch_id'] : null,
                    'name' => $product['name'] ?? '',
                    'quantity' => (float)($product['quantity'] ?? 0),
                    'unit_price' => (float)($product['unit_price'] ?? 0),
                    'unit' => $product['unit'] ?? ''
                ];
            }, $availableProductsForShipping), JSON_UNESCAPED_UNICODE); ?>;

            const itemsBody = document.getElementById('shippingItemsBody');
            const itemsCountEl = document.getElementById('shippingItemsCount');
            const orderTotalEl = document.getElementById('shippingOrderTotal');
            const submitBtn = document.getElementById('submitShippingOrderBtn');
            const cartEmpty = document.getElementById('shippingCartEmpty');
            const cartContent = document.getElementById('shippingCartContent');
            const clearCartBtn = document.getElementById('clearShippingCartBtn');
            const productsSearch = document.getElementById('shippingProductsSearch');

            if (!itemsBody) {
                return;
            }

            const cart = [];
            let rowIndex = 0;

            const formatCurrency = (value) => {
                return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP', minimumFractionDigits: 2 }).format(value || 0);
            };

            const escapeHtml = (value) => {
                if (typeof value !== 'string') {
                    return '';
                }
                return value.replace(/[&<>"']/g, function (char) {
                    switch (char) {
                        case '&': return '&amp;';
                        case '<': return '&lt;';
                        case '>': return '&gt;';
                        case '"': return '&quot;';
                        case "'": return '&#39;';
                        default: return char;
                    }
                });
            };

            const findProductById = (productId) => {
                return products.find(p => p.id === productId);
            };

            const renderCart = () => {
                if (cart.length === 0) {
                    if (cartEmpty) cartEmpty.classList.remove('d-none');
                    if (cartContent) cartContent.classList.add('d-none');
                    if (submitBtn) submitBtn.disabled = true;
                    return;
                }

                if (cartEmpty) cartEmpty.classList.add('d-none');
                if (cartContent) cartContent.classList.remove('d-none');
                if (submitBtn) submitBtn.disabled = false;

                itemsBody.innerHTML = '';
                let totalItems = 0;
                let totalAmount = 0;

                cart.forEach((item, index) => {
                    const product = findProductById(item.product_id);
                    if (!product) return;

                    const lineTotal = item.quantity * item.unit_price;
                    totalItems += item.quantity;
                    totalAmount += lineTotal;

                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>
                            <div class="fw-semibold">${escapeHtml(product.name)}</div>
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm" 
                                   name="items[${index}][quantity]" 
                                   value="${item.quantity.toFixed(2)}" 
                                   step="0.01" min="0.01" max="${product.quantity}" 
                                   required
                                   data-cart-index="${index}">
                            <input type="hidden" name="items[${index}][product_id]" value="${item.product_id}">
                            ${item.batch_id ? `<input type="hidden" name="items[${index}][batch_id]" value="${item.batch_id}">` : ''}
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm" 
                                   name="items[${index}][unit_price]" 
                                   value="${item.unit_price.toFixed(2)}" 
                                   step="0.01" min="0" required
                                   data-cart-index="${index}">
                        </td>
                        <td class="fw-semibold line-total">${formatCurrency(lineTotal)}</td>
                        <td>
                            <button type="button" class="btn btn-outline-danger btn-sm remove-cart-item" data-cart-index="${index}" title="حذف">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    `;

                    // إضافة أحداث الصف
                    const quantityInput = row.querySelector('input[name$="[quantity]"]');
                    const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
                    const removeBtn = row.querySelector('.remove-cart-item');

                    quantityInput?.addEventListener('input', function() {
                        const idx = parseInt(this.dataset.cartIndex);
                        if (cart[idx]) {
                            cart[idx].quantity = Math.max(0.01, Math.min(parseFloat(this.value) || 0, product.quantity));
                            this.value = cart[idx].quantity.toFixed(2);
                            renderCart();
                        }
                    });

                    unitPriceInput?.addEventListener('input', function() {
                        const idx = parseInt(this.dataset.cartIndex);
                        if (cart[idx]) {
                            cart[idx].unit_price = Math.max(0, parseFloat(this.value) || 0);
                            this.value = cart[idx].unit_price.toFixed(2);
                            renderCart();
                        }
                    });

                    removeBtn?.addEventListener('click', function() {
                        const idx = parseInt(this.dataset.cartIndex);
                        cart.splice(idx, 1);
                        renderCart();
                    });

                    itemsBody.appendChild(row);
                });

                if (itemsCountEl) {
                    itemsCountEl.textContent = totalItems.toLocaleString('ar-EG', { maximumFractionDigits: 2 });
                }
                if (orderTotalEl) {
                    orderTotalEl.textContent = formatCurrency(totalAmount);
                }
            };

            // إضافة منتج من البطاقة
            const productCards = document.querySelectorAll('.shipping-pos-product-card');
            productCards.forEach(card => {
                const addBtn = card.querySelector('.add-to-shipping-cart-btn');
                if (addBtn) {
                    addBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const productId = parseInt(card.dataset.productId);
                        const batchId = card.dataset.batchId ? parseInt(card.dataset.batchId) : null;
                        const product = findProductById(productId);
                        
                        if (!product) return;

                        // التحقق من وجود المنتج في السلة
                        const existingIndex = cart.findIndex(item => item.product_id === productId && item.batch_id === batchId);
                        
                        if (existingIndex >= 0) {
                            // زيادة الكمية
                            const maxQty = parseFloat(card.dataset.available || 0);
                            const currentQty = cart[existingIndex].quantity;
                            if (currentQty < maxQty) {
                                cart[existingIndex].quantity = Math.min(currentQty + 1, maxQty);
                            }
                        } else {
                            // إضافة منتج جديد
                            cart.push({
                                product_id: productId,
                                batch_id: batchId,
                                quantity: 1,
                                unit_price: parseFloat(card.dataset.unitPrice || 0)
                            });
                        }
                        
                        renderCart();
                    });
                }
            });

            // تفريغ السلة
            if (clearCartBtn) {
                clearCartBtn.addEventListener('click', () => {
                    if (confirm('هل أنت متأكد من تفريغ السلة؟')) {
                        cart.length = 0;
                        renderCart();
                    }
                });
            }

            // البحث في المنتجات
            if (productsSearch) {
                productsSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase().trim();
                    productCards.forEach(card => {
                        const name = (card.dataset.name || '').toLowerCase();
                        if (name.includes(searchTerm)) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }

            renderCart();
        })();
        </script>
    </div>
</div>

<?php if (!$error): ?>
<script>
(function () {
    const locale = <?php echo json_encode($pageDirection === 'rtl' ? 'ar-EG' : 'en-US'); ?>;
    const currencySymbolRaw = <?php echo json_encode(CURRENCY_SYMBOL); ?>;
    const inventory = <?php
        $inventoryForJs = [];
        foreach ($companyInventory as $item) {
            $inventoryForJs[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => $item['product_name'] ?? '',
                'category' => $item['category'] ?? '',
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'total_value' => (float) ($item['total_value'] ?? 0),
            ];
        }
        echo json_encode($inventoryForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    inventory.forEach((item) => {
        item.quantity = sanitizeNumber(item.quantity);
        item.unit_price = sanitizeNumber(item.unit_price);
        item.total_value = sanitizeNumber(item.total_value);
    });

    // تجميع المنتجات حسب product_id
    const inventoryMap = new Map();
    inventory.forEach((item) => {
        const productId = item.product_id;
        if (inventoryMap.has(productId)) {
            const existing = inventoryMap.get(productId);
            existing.quantity += item.quantity;
            if (item.unit_price > 0) {
                existing.unit_price = item.unit_price;
            }
        } else {
            inventoryMap.set(productId, {
                product_id: productId,
                name: item.name,
                category: item.category,
                quantity: item.quantity,
                unit_price: item.unit_price,
            });
        }
    });

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
        const cleaned = value.replace(/262145/gi, '').replace(/\s+/g, ' ').trim();
        return cleaned || 'ج.م';
    }

    const currencySymbol = sanitizeCurrencySymbol(currencySymbolRaw);

    function sanitizeNumber(value) {
        if (value === null || value === undefined) {
            return 0;
        }
        if (typeof value === 'string') {
            const stripped = value.replace(/262145/gi, '').replace(/[^\d.\-]/g, '');
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
        if (!product || !elements.selectedPanel) return;
        elements.selectedPanel.classList.add('active');
        elements.selectedName.textContent = product.name || '-';
        elements.selectedCategory.textContent = product.category || 'غير مصنف';
        elements.selectedPrice.textContent = formatCurrency(product.unit_price || 0);
        elements.selectedStock.textContent = (product.quantity ?? 0).toFixed(2);
    }

    function syncCartData() {
        const payload = cart.map((item) => ({
            product_id: item.product_id,
            quantity: sanitizeNumber(item.quantity),
            unit_price: sanitizeNumber(item.unit_price),
        }));
        elements.cartData.value = JSON.stringify(payload);
    }

    function refreshPaymentOptionStates() {
        if (!elements.paymentOptionCards) return;
        elements.paymentOptionCards.forEach((card) => {
            const input = card.querySelector('input[type="radio"]');
            const isChecked = Boolean(input && input.checked);
            card.classList.toggle('active', isChecked);
        });
    }

    function updateSummary() {
        const subtotal = cart.reduce((total, item) => {
            const qty = sanitizeNumber(item.quantity);
            const price = sanitizeNumber(item.unit_price);
            return total + (qty * price);
        }, 0);
        let prepaid = sanitizeNumber(elements.prepaidInput ? elements.prepaidInput.value : '0');
        let sanitizedSubtotal = sanitizeNumber(subtotal);

        if (prepaid < 0) prepaid = 0;
        if (prepaid > sanitizedSubtotal) prepaid = sanitizedSubtotal;
        if (elements.prepaidInput) elements.prepaidInput.value = prepaid.toFixed(2);

        const netTotal = sanitizeNumber(sanitizedSubtotal - prepaid);
        let paidAmount = 0;
        const paymentType = Array.from(elements.paymentRadios).find((radio) => radio.checked)?.value || 'full';

        if (paymentType === 'full') {
            paidAmount = netTotal;
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '0.00';
            if (elements.dueDateWrapper) elements.dueDateWrapper.classList.add('d-none');
        } else if (paymentType === 'partial') {
            elements.partialWrapper.classList.remove('d-none');
            if (elements.dueDateWrapper) elements.dueDateWrapper.classList.remove('d-none');
            let partialValue = sanitizeNumber(elements.partialInput.value);
            if (partialValue < 0) partialValue = 0;
            if (partialValue >= netTotal && netTotal > 0) partialValue = Math.max(0, netTotal - 0.01);
            elements.partialInput.value = partialValue.toFixed(2);
            paidAmount = partialValue;
        } else {
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '0.00';
            if (elements.dueDateWrapper) elements.dueDateWrapper.classList.remove('d-none');
            paidAmount = 0;
        }

        const dueAmount = sanitizeNumber(Math.max(0, netTotal - paidAmount));

        if (elements.netTotal) elements.netTotal.textContent = formatCurrency(netTotal);
        if (elements.dueAmount) elements.dueAmount.textContent = formatCurrency(dueAmount);
        if (elements.paidField) elements.paidField.value = paidAmount.toFixed(2);
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
        if (!elements.cartBody || !elements.cartTableWrapper || !elements.cartEmpty) return;

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
            return `
                <tr data-cart-row data-product-id="${item.product_id}">
                    <td data-label="المنتج">
                        <div class="fw-semibold">${escapeHtml(item.name)}</div>
                        <div class="text-muted small">${escapeHtml(item.category || 'غير مصنف')} • متاح: ${sanitizedAvailable.toFixed(2)}</div>
                    </td>
                    <td data-label="الكمية">
                        <div class="pos-qty-control">
                            <button type="button" class="btn btn-light border" data-action="decrease" data-product-id="${item.product_id}"><i class="bi bi-dash"></i></button>
                            <input type="number" step="0.01" min="0" max="${sanitizedAvailable.toFixed(2)}" class="form-control" data-cart-qty data-product-id="${item.product_id}" value="${sanitizedQty.toFixed(2)}">
                            <button type="button" class="btn btn-light border" data-action="increase" data-product-id="${item.product_id}"><i class="bi bi-plus"></i></button>
                        </div>
                    </td>
                    <td data-label="سعر الوحدة">
                        <input type="number" step="0.01" min="0" class="form-control" data-cart-price data-product-id="${item.product_id}" value="${sanitizedPrice.toFixed(2)}">
                    </td>
                    <td data-label="الإجمالي" class="fw-semibold">${formatCurrency(sanitizedQty * sanitizedPrice)}</td>
                    <td data-label="إجراءات" class="text-end">
                        <button type="button" class="btn btn-link text-danger p-0" data-action="remove" data-product-id="${item.product_id}"><i class="bi bi-x-circle"></i></button>
                    </td>
                </tr>`;
        }).join('');

        elements.cartBody.innerHTML = rows;
        updateSummary();
    }

    function addToCart(productId) {
        const product = inventoryMap.get(productId);
        if (!product) return;
        product.quantity = sanitizeNumber(product.quantity);
        product.unit_price = sanitizeNumber(product.unit_price);
        const existing = cart.find((item) => item.product_id === productId);
        if (existing) {
            const maxQty = sanitizeNumber(product.quantity);
            const newQty = sanitizeNumber(existing.quantity + 1);
            existing.quantity = newQty > maxQty ? maxQty : newQty;
        } else {
            if (product.quantity <= 0) return;
            cart.push({
                product_id: product.product_id,
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
        if (!item || !product) return;
        let newQuantity = sanitizeNumber(item.quantity + delta);
        if (newQuantity <= 0) {
            removeFromCart(productId);
            return;
        }
        const maxQty = sanitizeNumber(product.quantity);
        if (newQuantity > maxQty) newQuantity = maxQty;
        item.quantity = newQuantity;
        renderCart();
    }

    function updateQuantity(productId, value) {
        const item = cart.find((entry) => entry.product_id === productId);
        const product = inventoryMap.get(productId);
        if (!item || !product) return;
        let qty = sanitizeNumber(value);
        if (qty <= 0) {
            removeFromCart(productId);
            return;
        }
        const maxQty = sanitizeNumber(product.quantity);
        if (qty > maxQty) qty = maxQty;
        item.quantity = qty;
        renderCart();
    }

    function updateUnitPrice(productId, value) {
        const item = cart.find((entry) => entry.product_id === productId);
        if (!item) return;
        let price = sanitizeNumber(value);
        if (price < 0) price = 0;
        item.unit_price = price;
        renderCart();
    }

    elements.inventoryButtons.forEach((button) => {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            addToCart(parseInt(this.dataset.productId, 10));
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
                card.style.display = !term || name.includes(term) || category.includes(term) ? '' : 'none';
            });
        });
    }

    if (elements.cartBody) {
        elements.cartBody.addEventListener('click', function (event) {
            const action = event.target.closest('[data-action]');
            if (!action) return;
            const productId = parseInt(action.dataset.productId, 10);
            switch (action.dataset.action) {
                case 'increase': adjustQuantity(productId, 1); break;
                case 'decrease': adjustQuantity(productId, -1); break;
                case 'remove': removeFromCart(productId); break;
            }
        });

        elements.cartBody.addEventListener('input', function (event) {
            const qtyInput = event.target.matches('[data-cart-qty]') ? event.target : null;
            const priceInput = event.target.matches('[data-cart-price]') ? event.target : null;
            const productId = parseInt(event.target.dataset.productId || '0', 10);
            if (qtyInput) updateQuantity(productId, qtyInput.value);
            if (priceInput) updateUnitPrice(productId, priceInput.value);
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
        elements.prepaidInput.addEventListener('change', updateSummary);
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
            updateSummary();
        });
    });

    if (elements.customerSelect) {
        elements.customerSelect.addEventListener('change', updateSummary);
    }
    if (elements.newCustomerName) {
        elements.newCustomerName.addEventListener('input', updateSummary);
    }

    if (elements.form) {
        elements.form.addEventListener('submit', function (event) {
            if (!cart.length) {
                event.preventDefault();
                alert('يرجى إضافة منتجات إلى السلة قبل إتمام العملية.');
                return;
            }
            updateSummary();
            if (!elements.form.checkValidity()) {
                event.preventDefault();
            }
            elements.form.classList.add('was-validated');
        });
    }

    refreshPaymentOptionStates();
    renderCart();
})();
</script>

<!-- إدارة Modal الفاتورة -->
<script>
(function() {
    <?php if (!empty($posInvoiceLinks['absolute_report_url'])): ?>
    const invoiceUrl = <?php echo json_encode($posInvoiceLinks['absolute_report_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const invoicePrintUrl = <?php echo !empty($posInvoiceLinks['absolute_print_url']) ? json_encode($posInvoiceLinks['absolute_print_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'; ?>;
    <?php else: ?>
    const invoiceUrl = null;
    const invoicePrintUrl = null;
    <?php endif; ?>
    
    function initInvoiceModal() {
        const invoiceModal = document.getElementById('posInvoiceModal');
        if (invoiceModal && invoiceUrl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            try {
                const modal = new bootstrap.Modal(invoiceModal, { backdrop: 'static', keyboard: false });
                modal.show();
                invoiceModal.addEventListener('hidden.bs.modal', function() {
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.delete('success');
                    currentUrl.searchParams.delete('error');
                    window.history.replaceState({}, '', currentUrl.toString());
                });
            } catch (error) {
                setTimeout(initInvoiceModal, 200);
            }
        } else if (invoiceModal && invoiceUrl) {
            setTimeout(initInvoiceModal, 100);
        }
        
        window.printInvoice = function() {
            const url = invoicePrintUrl || (invoiceUrl ? invoiceUrl + (invoiceUrl.includes('?') ? '&' : '?') + 'print=1' : null);
            if (url) {
                const printWindow = window.open(url, '_blank');
                if (printWindow) {
                    printWindow.onload = function() {
                        setTimeout(() => printWindow.print(), 500);
                    };
                }
            }
        };
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInvoiceModal);
    } else {
        initInvoiceModal();
    }
})();
</script>
<?php endif; ?>

<!-- JavaScript لاختيار شركة الشحن من البطاقات -->
<script>
(function() {
    // اختيار شركة الشحن من البطاقات
    const selectCompanyButtons = document.querySelectorAll('.select-shipping-company-btn');
    const shippingCompanySelect = document.querySelector('select[name="shipping_company_id"]');
    
    if (selectCompanyButtons && shippingCompanySelect) {
        selectCompanyButtons.forEach(button => {
            button.addEventListener('click', function() {
                const card = this.closest('.shipping-company-card');
                if (card) {
                    const companyId = card.dataset.companyId;
                    const companyName = card.dataset.companyName;
                    
                    // تحديث select
                    if (shippingCompanySelect) {
                        shippingCompanySelect.value = companyId;
                        
                        // إضافة class للبطاقة المختارة
                        document.querySelectorAll('.shipping-company-card').forEach(c => {
                            c.classList.remove('border-primary', 'border-3');
                        });
                        card.classList.add('border-primary', 'border-3');
                        
                        // التمرير إلى نموذج تسجيل الطلب
                        const orderForm = document.getElementById('shippingOrderForm');
                        if (orderForm) {
                            orderForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                }
            });
        });
    }
})();
</script>

<!-- أنماط بطاقات شركات الشحن -->
<style>
.shipping-company-card {
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    padding: 1.5rem;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    height: 100%;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.shipping-company-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(13, 110, 253, 0.15);
    border-color: rgba(13, 110, 253, 0.3);
}

.shipping-company-card.active {
    border-color: #0d6efd;
    background: linear-gradient(135deg, #ffffff 0%, #f0f7ff 100%);
}

.shipping-company-card.inactive {
    opacity: 0.7;
    background: #f8f9fa;
}

.shipping-company-card-header {
    margin-bottom: 1rem;
}

.shipping-company-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.5rem;
    margin-bottom: 0.75rem;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.25);
}

.shipping-company-card.inactive .shipping-company-icon {
    background: linear-gradient(135deg, #6c757d 0%, #adb5bd 100%);
}

.shipping-company-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.shipping-company-card-body {
    flex: 1;
    margin-bottom: 1rem;
}

.shipping-company-info-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    color: #4b5563;
}

.shipping-company-info-item i {
    width: 20px;
    text-align: center;
    color: #6b7280;
}

.shipping-company-info-item.text-danger {
    color: #dc2626;
}

.shipping-company-info-item.text-danger i {
    color: #dc2626;
}

.shipping-company-card-footer {
    margin-top: auto;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

.shipping-company-card.inactive .shipping-company-card-footer {
    display: none;
}

.select-shipping-company-btn {
    font-weight: 600;
    border-radius: 8px;
    padding: 0.5rem 1rem;
    transition: all 0.2s ease;
}

.select-shipping-company-btn:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
}

@media (max-width: 768px) {
    .shipping-company-card {
        padding: 1.25rem;
    }
    
    .shipping-company-icon {
        width: 48px;
        height: 48px;
        font-size: 1.25rem;
    }
    
    .shipping-company-name {
        font-size: 1rem;
    }
}

/* أنماط نموذج POS لطلبات الشحن */
.shipping-pos-wrapper {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.shipping-pos-header-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.shipping-pos-content {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 1.5rem;
}

.shipping-pos-products-panel,
.shipping-pos-cart-panel {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.shipping-pos-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.shipping-pos-panel-header h4 {
    margin: 0;
    font-weight: 700;
    color: #1f2937;
    font-size: 1.25rem;
}

.shipping-pos-panel-header p {
    margin: 0.25rem 0 0 0;
    color: #6b7280;
    font-size: 0.9rem;
}

.shipping-pos-search {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.shipping-pos-search input {
    border-radius: 10px;
    padding-right: 2.5rem;
    height: 2.75rem;
}

.shipping-pos-search i {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

.shipping-pos-product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
    max-height: 600px;
    overflow-y: auto;
    padding: 0.5rem;
}

.shipping-pos-product-card {
    background: #f8f9fa;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 1rem;
    transition: all 0.2s ease;
    cursor: pointer;
}

.shipping-pos-product-card:hover {
    transform: translateY(-2px);
    border-color: #0d6efd;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.15);
    background: #ffffff;
}

.shipping-pos-product-name {
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.75rem;
}

.shipping-pos-product-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
    color: #4b5563;
}

.shipping-pos-product-meta strong {
    color: #0d6efd;
    font-weight: 600;
}

.shipping-pos-product-qty {
    color: #059669;
    font-weight: 600;
}

.shipping-pos-cart-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #9ca3af;
}

.shipping-pos-cart-empty i {
    display: block;
    margin-bottom: 1rem;
}

.shipping-pos-cart-content {
    max-height: 450px;
    overflow-y: auto;
}

.shipping-pos-summary-card {
    background: linear-gradient(135deg, #f0f7ff 0%, #e0f2fe 100%);
    border-radius: 12px;
    padding: 1rem;
    border: 1px solid #bae6fd;
}

.shipping-pos-cart-footer {
    margin-top: auto;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

@media (max-width: 992px) {
    .shipping-pos-content {
        grid-template-columns: 1fr;
    }
    
    .shipping-pos-cart-panel {
        order: -1;
    }
}

@media (max-width: 768px) {
    .shipping-pos-product-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 0.75rem;
    }
    
    .shipping-pos-header-card,
    .shipping-pos-products-panel,
    .shipping-pos-cart-panel {
        padding: 1rem;
    }
}
</style>