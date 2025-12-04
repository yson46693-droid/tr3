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

// معالجة إضافة عميل جديد من صفحة طلبات الشحن
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer_from_shipping') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0.0;

    if (empty($name)) {
        $error = 'يجب إدخال اسم العميل.';
    } else {
        try {
            // التحقق من وجود جدول local_customers
            $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
            
            if (empty($localCustomersTableExists)) {
                throw new RuntimeException('جدول العملاء المحليين غير موجود في قاعدة البيانات.');
            }
            
            // التحقق من عدم وجود عميل محلي بنفس الاسم
            $existingLocalCustomer = $db->queryOne(
                "SELECT id, name FROM local_customers WHERE name = ? AND status = 'active' LIMIT 1",
                [$name]
            );
            
            if ($existingLocalCustomer) {
                $error = 'يوجد عميل محلي مسجل مسبقاً بنفس الاسم.';
            } else {
                $result = $db->execute(
                    "INSERT INTO local_customers (name, phone, address, balance, status, created_by) VALUES (?, ?, ?, ?, 'active', ?)",
                    [
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $balance,
                        $currentUser['id'],
                    ]
                );

                $customerId = (int)($result['insert_id'] ?? 0);
                if ($customerId <= 0) {
                    throw new RuntimeException('فشل إضافة العميل: لم يتم الحصول على معرف العميل.');
                }
                
                logAudit($currentUser['id'], 'manager_add_local_customer', 'local_customer', $customerId, null, [
                    'name' => $name,
                    'from_shipping_page' => true,
                ]);

                $success = 'تمت إضافة العميل المحلي بنجاح. يمكنك الآن اختياره من القائمة.';
            }
        } catch (InvalidArgumentException $invalidError) {
            $error = $invalidError->getMessage();
        } catch (Throwable $addError) {
            error_log('Manager add customer from shipping error: ' . $addError->getMessage());
            // التحقق من نوع الخطأ - إذا كان duplicate key error، عرض رسالة مناسبة
            $errorMessage = $addError->getMessage();
            if (stripos($errorMessage, 'duplicate') !== false || stripos($errorMessage, '1062') !== false) {
                $error = 'يوجد عميل مسجل مسبقاً بنفس الاسم.';
            } else {
                $error = 'تعذر إضافة العميل: ' . htmlspecialchars($errorMessage);
            }
        }
    }
}

// تحميل قائمة العملاء المحليين فقط
if (empty($customers)) {
    $customers = [];
    try {
        // جلب العملاء المحليين فقط من جدول local_customers
        $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
        if (!empty($localCustomersTableExists)) {
            $customers = $db->query("SELECT id, name, COALESCE(balance, 0) as balance FROM local_customers WHERE status = 'active' ORDER BY name ASC");
        } else {
            $customers = [];
        }
    } catch (Throwable $e) {
        error_log('Error fetching local customers: ' . $e->getMessage());
        $customers = [];
    }
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

                // تحديد batch_id و product_type من أول عنصر
                $firstItem = $product['items'][0] ?? null;
                $productType = $firstItem['product_type'] ?? 'external';
                
                // للمنتجات المصنعة: توزيع الكمية على عدة باتشات إذا لزم الأمر
                if ($productType === 'factory' && $quantity > 0) {
                    $remainingQty = $quantity;
                    $batchItems = [];
                    
                    // ترتيب الباتشات حسب الكمية (الأكبر أولاً)
                    $sortedItems = $product['items'];
                    usort($sortedItems, function($a, $b) {
                        $qtyA = (float)($a['quantity'] ?? 0);
                        $qtyB = (float)($b['quantity'] ?? 0);
                        return $qtyB <=> $qtyA; // ترتيب تنازلي
                    });
                    
                    foreach ($sortedItems as $item) {
                        if ($remainingQty <= 0) break;
                        
                        $itemQty = (float)($item['quantity'] ?? 0);
                        if ($itemQty <= 0) continue;
                        
                        $batchId = $item['batch_id'] ?? null;
                        if (!$batchId) continue;
                        
                        $qtyToTake = min($remainingQty, $itemQty);
                        $batchItems[] = [
                            'batch_id' => $batchId,
                            'quantity' => $qtyToTake,
                            'unit_price' => $unitPrice,
                            'line_total' => round($qtyToTake * $unitPrice, 2),
                        ];
                        
                        $remainingQty -= $qtyToTake;
                    }
                    
                    if ($remainingQty > 0.001) {
                        $validationErrors[] = 'الكمية المطلوبة للمنتج رقم ' . ($index + 1) . ' (' . number_format($quantity, 2) . ') تتجاوز الكمية المتاحة (' . number_format($available, 2) . ').';
                        continue;
                    }
                    
                    // إضافة عناصر منفصلة لكل باتش
                    foreach ($batchItems as $batchItem) {
                        $itemBatchId = $batchItem['batch_id'] ?? null;
                        error_log("Manager POS: Adding batch item to cart - product_id: $productId, batch_id: " . ($itemBatchId ?? 'NULL') . ", quantity: " . $batchItem['quantity']);
                        $normalizedCart[] = [
                            'product_id' => $productId,
                            'batch_id' => $itemBatchId,
                            'product_type' => $productType,
                            'name' => $productName ?: 'منتج',
                            'category' => $product['items'][0]['category'] ?? null,
                            'quantity' => $batchItem['quantity'],
                            'available' => $available,
                            'unit_price' => $batchItem['unit_price'],
                            'line_total' => $batchItem['line_total'],
                        ];
                    }
                } else {
                    // للمنتجات الخارجية: استخدام batch_id من أول عنصر
                    $batchId = $firstItem['batch_id'] ?? null;
                    
                    $normalizedCart[] = [
                        'product_id' => $productId,
                        'batch_id' => $batchId,
                        'product_type' => $productType,
                        'name' => $productName ?: 'منتج',
                        'category' => $product['items'][0]['category'] ?? null,
                        'quantity' => $quantity,
                        'available' => $available,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ];
                }
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
                // البحث عن العميل في جدول local_customers (العملاء المحليين)
                $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                if (!empty($localCustomersTableExists)) {
                    $customer = $db->queryOne("SELECT id, name, balance, created_by FROM local_customers WHERE id = ?", [$customerId]);
                } else {
                    $customer = null;
                }
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

                // التأكد من وجود جدول local_customers
                $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                if (empty($localCustomersTableExists)) {
                    throw new RuntimeException('جدول العملاء المحليين غير موجود. يرجى التأكد من إعداد النظام.');
                }

                if ($customerMode === 'new') {
                    $dueAmount = $baseDueAmount;
                    $creditUsed = 0.0;
                    // إنشاء العميل في جدول local_customers
                    $db->execute(
                        "INSERT INTO local_customers (name, phone, address, balance, status, created_by) 
                         VALUES (?, ?, ?, ?, 'active', ?)",
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
                    // جلب العميل من local_customers
                    $customer = $db->queryOne(
                        "SELECT id, name, phone, address, balance FROM local_customers WHERE id = ? FOR UPDATE",
                        [$customerId]
                    );

                    if (!$customer) {
                        throw new RuntimeException('تعذر تحميل بيانات العميل المحلي أثناء المعالجة.');
                    }

                    $currentBalance = (float) ($customer['balance'] ?? 0);
                    
                    if ($currentBalance < 0 && $dueAmount > 0) {
                        $creditUsed = min(abs($currentBalance), $dueAmount);
                        $dueAmount = round($dueAmount - $creditUsed, 2);
                        // لا نضيف creditUsed إلى effectivePaidAmount لأنه ليس مبلغ نقدي
                        // effectivePaidAmount يبقى كما هو (المبلغ النقدي الفعلي فقط)
                    } else {
                        $creditUsed = 0.0;
                    }

                    $newBalance = round($currentBalance + $creditUsed + $dueAmount, 2);
                    if (abs($newBalance - $currentBalance) > 0.0001) {
                        $db->execute("UPDATE local_customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                        $customer['balance'] = $newBalance;
                    }
                }

                // إنشاء عميل مؤقت في جدول customers للربط مع invoices (إذا لم يكن موجوداً)
                // لأن invoices table له foreign key على customers
                $tempCustomerId = null;
                $localCustomerId = $customerId; // حفظ معرف العميل المحلي
                $customerName = $customer['name'] ?? ($customerMode === 'new' ? $newCustomerName : '');
                
                // البحث عن عميل في customers بنفس الاسم أو إنشاء واحد جديد
                $existingCustomerInCustomers = $db->queryOne(
                    "SELECT id FROM customers WHERE name = ? AND created_by_admin = 1 LIMIT 1",
                    [$customerName]
                );
                
                if ($existingCustomerInCustomers) {
                    $tempCustomerId = (int)$existingCustomerInCustomers['id'];
                } else {
                    // إنشاء عميل مؤقت في customers للربط
                    $customerPhone = $customer['phone'] ?? ($customerMode === 'new' ? ($newCustomerPhone !== '' ? $newCustomerPhone : null) : null);
                    $customerAddress = $customer['address'] ?? ($customerMode === 'new' ? ($newCustomerAddress !== '' ? $newCustomerAddress : null) : null);
                    
                    $db->execute(
                        "INSERT INTO customers (name, phone, address, balance, status, created_by, rep_id, created_from_pos, created_by_admin) 
                         VALUES (?, ?, ?, 0, 'active', ?, NULL, 1, 1)",
                        [
                            $customerName,
                            $customerPhone,
                            $customerAddress,
                            $currentUser['id'],
                        ]
                    );
                    $tempCustomerId = (int) $db->getLastInsertId();
                }

                $invoiceItems = [];
                foreach ($normalizedCart as $item) {
                    $batchId = $item['batch_id'] ?? null;
                    $batchNumber = null;
                    
                    // جلب batch_number من batch_id إذا كان موجوداً
                    // batch_id في normalizedCart هو fp.id (finished_products.id)
                    if ($batchId) {
                        $batchInfo = $db->queryOne(
                            "SELECT COALESCE(fp.batch_number, bn.batch_number) as batch_number
                             FROM finished_products fp
                             LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                             WHERE fp.id = ?
                             LIMIT 1",
                            [$batchId]
                        );
                        if ($batchInfo && !empty($batchInfo['batch_number'])) {
                            $batchNumber = $batchInfo['batch_number'];
                        }
                    }
                    
                    $invoiceItems[] = [
                        'product_id' => $item['product_id'],
                        'description' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'batch_id' => $batchId,
                        'batch_number' => $batchNumber,
                    ];
                }

                // استخدام tempCustomerId لإنشاء الفاتورة (لأن invoices table مرتبط بـ customers)
                $invoiceResult = createInvoice(
                    $tempCustomerId,
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
                // حساب المبلغ الإجمالي المدفوع (نقدي + رصيد دائن) للفاتورة
                $totalPaidAmount = $effectivePaidAmount + $creditUsed;
                
                if ($dueAmount <= 0.0001) {
                    $invoiceStatus = 'paid';
                    // إذا تم الدفع بالكامل (نقدي + رصيد دائن)، نستخدم المبلغ الإجمالي للفاتورة
                    $totalPaidAmount = $netTotal;
                } elseif ($totalPaidAmount > 0) {
                    $invoiceStatus = 'partial';
                }

                // تحديث الفاتورة بالمبلغ المدفوع والمبلغ المتبقي
                // نستخدم totalPaidAmount (نقدي + رصيد دائن) للفاتورة
                $invoiceUpdateSql = "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW()";
                $invoiceUpdateParams = [$totalPaidAmount, $dueAmount, $invoiceStatus];
                
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

                // تسجيل الإيراد في خزنة الشركة (accountant_transactions)
                // مهم: نستخدم فقط المبلغ النقدي الفعلي (effectivePaidAmount) وليس creditUsed
                // لأن creditUsed هو رصيد دائن للعميل وليس مبلغ نقدي تم استلامه
                // منع التسجيل المزدوج: فحص إذا كان تم تسجيل معاملة بنفس reference_number
                try {
                    // التأكد من وجود جدول accountant_transactions
                    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                    if (!empty($accountantTableExists)) {
                        $customerName = $customer['name'] ?? 'عميل محلي';
                        
                        // نستخدم فقط المبلغ النقدي الفعلي (بدون creditUsed)
                        $cashAmountToRecord = $effectivePaidAmount;
                        
                        // الحالة 1: البيع الكامل - تسجيل المبلغ النقدي فقط كإيراد معتمد
                        if ($invoiceStatus === 'paid' && $cashAmountToRecord > 0) {
                            $referenceNumber = 'POS-FULL-' . $invoiceId;
                            
                            // فحص إذا كان تم تسجيل معاملة بنفس reference_number مسبقاً
                            $existingTransaction = $db->queryOne(
                                "SELECT id FROM accountant_transactions WHERE reference_number = ? LIMIT 1",
                                [$referenceNumber]
                            );
                            
                            if (empty($existingTransaction)) {
                                $description = 'بيع كامل من نقطة بيع المدير - فاتورة ' . $invoiceNumber . ' - عميل: ' . $customerName;
                                if ($creditUsed > 0) {
                                    $description .= ' (منها ' . number_format($creditUsed, 2) . ' ج.م من الرصيد الدائن)';
                                }
                                
                                $db->execute(
                                    "INSERT INTO accountant_transactions 
                                    (transaction_type, amount, description, reference_number, payment_method, status, approved_by, created_by, approved_at)
                                    VALUES (?, ?, ?, ?, 'cash', 'approved', ?, ?, NOW())",
                                    [
                                        'income',
                                        $cashAmountToRecord,
                                        $description,
                                        $referenceNumber,
                                        $currentUser['id'],
                                        $currentUser['id']
                                    ]
                                );
                            }
                        }
                        // الحالة 2: البيع الجزئي - تسجيل المبلغ النقدي المحصل فقط كإيراد معتمد
                        elseif ($invoiceStatus === 'partial' && $cashAmountToRecord > 0) {
                            $referenceNumber = 'POS-PARTIAL-' . $invoiceId;
                            
                            // فحص إذا كان تم تسجيل معاملة بنفس reference_number مسبقاً
                            $existingTransaction = $db->queryOne(
                                "SELECT id FROM accountant_transactions WHERE reference_number = ? LIMIT 1",
                                [$referenceNumber]
                            );
                            
                            if (empty($existingTransaction)) {
                                $description = 'تحصيل جزئي من نقطة بيع المدير - فاتورة ' . $invoiceNumber . ' - عميل: ' . $customerName;
                                if ($creditUsed > 0) {
                                    $description .= ' (منها ' . number_format($creditUsed, 2) . ' ج.م من الرصيد الدائن)';
                                }
                                
                                $db->execute(
                                    "INSERT INTO accountant_transactions 
                                    (transaction_type, amount, description, reference_number, payment_method, status, approved_by, created_by, approved_at)
                                    VALUES (?, ?, ?, ?, 'cash', 'approved', ?, ?, NOW())",
                                    [
                                        'income',
                                        $cashAmountToRecord,
                                        $description,
                                        $referenceNumber,
                                        $currentUser['id'],
                                        $currentUser['id']
                                    ]
                                );
                            }
                        }
                    }
                } catch (Throwable $incomeError) {
                    // لا نوقف العملية إذا فشل تسجيل الإيراد، فقط نسجل الخطأ
                    error_log('Error recording income in accountant_transactions: ' . $incomeError->getMessage());
                }

                foreach ($normalizedCart as $item) {
                    $productId = $item['product_id'];
                    $quantity = $item['quantity'];
                    $unitPrice = $item['unit_price'];
                    $lineTotal = $item['line_total'];
                    // تحويل batch_id إلى int بشكل صريح - إذا كان 0 أو null، نتركه null
                    $batchIdRaw = $item['batch_id'] ?? null;
                    $batchId = ($batchIdRaw !== null && $batchIdRaw !== '' && (int)$batchIdRaw > 0) ? (int)$batchIdRaw : null;
                    $productType = $item['product_type'] ?? 'external';

                    error_log("Manager POS: Processing sale item - product_id: $productId, batch_id_raw: " . ($batchIdRaw ?? 'NULL') . ", batch_id: " . ($batchId ?? 'NULL') . ", quantity: $quantity, product_type: $productType");

                    // التحقق من الكمية مباشرة من finished_products قبل البيع (مثل نقطة بيع المندوب)
                    // هذا مهم جداً لضمان أن الكمية متاحة فعلياً
                    if ($productType === 'factory' && $batchId) {
                        // للمنتجات المصنعة: التحقق من finished_products مباشرة
                        $finishedProduct = $db->queryOne(
                            "SELECT id, quantity_produced FROM finished_products WHERE id = ? FOR UPDATE",
                            [$batchId]
                        );
                        
                        if (!$finishedProduct) {
                            throw new RuntimeException('التشغيلة المحددة غير موجودة في قاعدة البيانات.');
                        }
                        
                        $availableQuantity = (float)($finishedProduct['quantity_produced'] ?? 0);
                        
                        if ($quantity > $availableQuantity) {
                            throw new RuntimeException('الكمية المتاحة للمنتج ' . $item['name'] . ' غير كافية. المتاح: ' . number_format($availableQuantity, 2) . '، المطلوب: ' . number_format($quantity, 2));
                        }
                        
                        error_log("Manager POS: Verified quantity from finished_products - batch_id: $batchId, available: $availableQuantity, requested: $quantity");
                    } elseif ($productType === 'external') {
                        // للمنتجات الخارجية: التحقق من products مباشرة
                        $product = $db->queryOne(
                            "SELECT id, quantity FROM products WHERE id = ? AND product_type = 'external' FOR UPDATE",
                            [$productId]
                        );
                        
                        if (!$product) {
                            throw new RuntimeException('المنتج الخارجي المحدد غير موجود في قاعدة البيانات.');
                        }
                        
                        $availableQuantity = (float)($product['quantity'] ?? 0);
                        
                        if ($quantity > $availableQuantity) {
                            throw new RuntimeException('الكمية المتاحة للمنتج ' . $item['name'] . ' غير كافية. المتاح: ' . number_format($availableQuantity, 2) . '، المطلوب: ' . number_format($quantity, 2));
                        }
                        
                        error_log("Manager POS: Verified quantity from products - product_id: $productId, available: $availableQuantity, requested: $quantity");
                    }

                    // تسجيل حركة المخزون (سوف يقوم recordInventoryMovement بالتحقق من الكمية وتحديثها)
                    // تم توزيع الكمية على الباتشات مسبقاً في normalizedCart، لذا كل عنصر يحتوي على كمية متاحة في الباتش المحدد
                    error_log("Manager POS: Calling recordInventoryMovement - product_id: $productId, batch_id: " . ($batchId ?? 'NULL') . ", quantity: $quantity, product_type: $productType");
                    $movementResult = recordInventoryMovement(
                        $productId,
                        $mainWarehouseId,
                        'out',
                        $quantity,
                        'sales',
                        $invoiceId,
                        'بيع من نقطة بيع المدير - فاتورة ' . $invoiceNumber,
                        $currentUser['id'],
                        $batchId // batchId - يجب أن يكون في المكان التاسع (آخر معامل)
                    );

                    if (empty($movementResult['success'])) {
                        throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                    }

                    // استخدام tempCustomerId (من جدول customers) لأن جدول sales له foreign key على customers
                    $db->execute(
                        "INSERT INTO sales (customer_id, product_id, quantity, price, total, date, salesperson_id, status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')",
                        [$tempCustomerId, $productId, $quantity, $unitPrice, $lineTotal, $saleDate, $currentUser['id']]
                    );
                }

                // إنشاء فاتورة محلية للعميل المحلي في local_invoices
                try {
                    // التأكد من وجود جدول local_invoices وإنشاؤه إذا لم يكن موجوداً
                    $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
                    if (empty($localInvoicesTableExists)) {
                        // إنشاء جدول local_invoices
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `local_invoices` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `invoice_number` varchar(50) NOT NULL,
                              `customer_id` int(11) NOT NULL,
                              `date` date NOT NULL,
                              `due_date` date DEFAULT NULL,
                              `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `tax_rate` decimal(5,2) DEFAULT 0.00,
                              `tax_amount` decimal(15,2) DEFAULT 0.00,
                              `discount_amount` decimal(15,2) DEFAULT 0.00,
                              `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `paid_amount` decimal(15,2) DEFAULT 0.00,
                              `remaining_amount` decimal(15,2) DEFAULT 0.00,
                              `status` enum('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft',
                              `notes` text DEFAULT NULL,
                              `created_by` int(11) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `invoice_number` (`invoice_number`),
                              KEY `customer_id` (`customer_id`),
                              KEY `date` (`date`),
                              KEY `status` (`status`),
                              KEY `created_by` (`created_by`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    } else {
                        // التأكد من وجود جميع الأعمدة المطلوبة وإضافتها إذا لم تكن موجودة
                        $requiredColumns = [
                            'due_date' => "ALTER TABLE local_invoices ADD COLUMN `due_date` date DEFAULT NULL AFTER `date`",
                            'subtotal' => "ALTER TABLE local_invoices ADD COLUMN `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `due_date`",
                            'tax_rate' => "ALTER TABLE local_invoices ADD COLUMN `tax_rate` decimal(5,2) DEFAULT 0.00 AFTER `subtotal`",
                            'tax_amount' => "ALTER TABLE local_invoices ADD COLUMN `tax_amount` decimal(15,2) DEFAULT 0.00 AFTER `tax_rate`",
                            'discount_amount' => "ALTER TABLE local_invoices ADD COLUMN `discount_amount` decimal(15,2) DEFAULT 0.00 AFTER `tax_amount`",
                            'total_amount' => "ALTER TABLE local_invoices ADD COLUMN `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`",
                            'paid_amount' => "ALTER TABLE local_invoices ADD COLUMN `paid_amount` decimal(15,2) DEFAULT 0.00 AFTER `total_amount`",
                            'remaining_amount' => "ALTER TABLE local_invoices ADD COLUMN `remaining_amount` decimal(15,2) DEFAULT 0.00 AFTER `paid_amount`",
                            'status' => "ALTER TABLE local_invoices ADD COLUMN `status` enum('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft' AFTER `remaining_amount`",
                            'notes' => "ALTER TABLE local_invoices ADD COLUMN `notes` text DEFAULT NULL AFTER `status`",
                            'created_by' => "ALTER TABLE local_invoices ADD COLUMN `created_by` int(11) NOT NULL AFTER `notes`",
                            'created_at' => "ALTER TABLE local_invoices ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`",
                            'updated_at' => "ALTER TABLE local_invoices ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
                        ];
                        
                        foreach ($requiredColumns as $columnName => $alterSql) {
                            $columnExists = !empty($db->queryOne("SHOW COLUMNS FROM local_invoices LIKE '$columnName'"));
                            if (!$columnExists) {
                                try {
                                    $db->execute($alterSql);
                                    error_log("Added column $columnName to local_invoices table");
                                } catch (Throwable $alterError) {
                                    error_log("Error adding column $columnName to local_invoices: " . $alterError->getMessage());
                                }
                            }
                        }
                    }
                    
                    // التأكد من وجود جدول local_invoice_items وإنشاؤه إذا لم يكن موجوداً
                    $localInvoiceItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoice_items'");
                    if (empty($localInvoiceItemsTableExists)) {
                        // إنشاء جدول local_invoice_items
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `local_invoice_items` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `invoice_id` int(11) NOT NULL,
                              `product_id` int(11) NOT NULL,
                              `description` varchar(255) DEFAULT NULL,
                              `quantity` decimal(10,2) NOT NULL,
                              `unit_price` decimal(15,2) NOT NULL,
                              `total_price` decimal(15,2) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `invoice_id` (`invoice_id`),
                              KEY `product_id` (`product_id`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    
                    // إنشاء الفاتورة المحلية
                    $localInvoiceNumber = 'LOC-' . $invoiceNumber;
                    
                    // فحص إذا كانت الفاتورة المحلية موجودة مسبقاً
                    $existingLocalInvoice = $db->queryOne(
                        "SELECT id FROM local_invoices WHERE invoice_number = ? LIMIT 1",
                        [$localInvoiceNumber]
                    );
                    
                        if (empty($existingLocalInvoice)) {
                            // إنشاء الفاتورة المحلية
                            // بعد التأكد من وجود جميع الأعمدة، نستخدم نفس استعلام INSERT
                            $hasDueDateColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_invoices LIKE 'due_date'"));
                            
                            if ($hasDueDateColumn) {
                                $db->execute(
                                    "INSERT INTO local_invoices 
                                    (invoice_number, customer_id, date, due_date, subtotal, tax_rate, tax_amount, 
                                     discount_amount, total_amount, paid_amount, remaining_amount, status, notes, created_by)
                                    VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)",
                                    [
                                        $localInvoiceNumber,
                                        $localCustomerId,
                                        $saleDate,
                                        $dueDate,
                                        $subtotal,
                                        $prepaidAmount,
                                        $netTotal,
                                        $effectivePaidAmount,
                                        $dueAmount,
                                        $invoiceStatus,
                                        $notes,
                                        $currentUser['id']
                                    ]
                                );
                            } else {
                                $db->execute(
                                    "INSERT INTO local_invoices 
                                    (invoice_number, customer_id, date, subtotal, tax_rate, tax_amount, 
                                     discount_amount, total_amount, paid_amount, remaining_amount, status, notes, created_by)
                                    VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)",
                                    [
                                        $localInvoiceNumber,
                                        $localCustomerId,
                                        $saleDate,
                                        $subtotal,
                                        $prepaidAmount,
                                        $netTotal,
                                        $effectivePaidAmount,
                                        $dueAmount,
                                        $invoiceStatus,
                                        $notes,
                                        $currentUser['id']
                                    ]
                                );
                            }
                        
                        $localInvoiceId = (int)$db->getLastInsertId();
                        
                        error_log("Local invoice created successfully: ID=$localInvoiceId, Number=$localInvoiceNumber, Customer=$localCustomerId");
                        
                        // إضافة عناصر الفاتورة المحلية
                        if (!empty($invoiceItems)) {
                            // التحقق من وجود أعمدة batch_number و batch_id في local_invoice_items
                            $hasBatchNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_number'"));
                            $hasBatchId = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_id'"));
                            
                            foreach ($invoiceItems as $item) {
                                $itemTotal = $item['quantity'] * $item['unit_price'];
                                
                                // بناء استعلام INSERT ديناميكياً بناءً على وجود الأعمدة
                                $columns = ['invoice_id', 'product_id', 'description', 'quantity', 'unit_price', 'total_price'];
                                $values = [
                                    $localInvoiceId,
                                    $item['product_id'],
                                    $item['description'] ?? null,
                                    $item['quantity'],
                                    $item['unit_price'],
                                    $itemTotal
                                ];
                                
                                if ($hasBatchNumber) {
                                    $columns[] = 'batch_number';
                                    $values[] = $item['batch_number'] ?? null;
                                }
                                
                                if ($hasBatchId) {
                                    $columns[] = 'batch_id';
                                    $values[] = isset($item['batch_id']) && $item['batch_id'] ? (int)$item['batch_id'] : null;
                                }
                                
                                $placeholders = str_repeat('?,', count($values) - 1) . '?';
                                $sql = "INSERT INTO local_invoice_items (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                                
                                $db->execute($sql, $values);
                            }
                            error_log("Local invoice items added successfully: " . count($invoiceItems) . " items for invoice ID=$localInvoiceId");
                        }
                    } else {
                        error_log("Local invoice already exists: Number=$localInvoiceNumber");
                    }
                } catch (Throwable $localInvoiceError) {
                    // لا نوقف العملية إذا فشل إنشاء الفاتورة المحلية، فقط نسجل الخطأ
                    error_log('Error creating local invoice: ' . $localInvoiceError->getMessage());
                    error_log('Stack trace: ' . $localInvoiceError->getTraceAsString());
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

        // تسجيل القيم المستلمة للمساعدة في التشخيص
        if ($customerId <= 0) {
            error_log('Shipping order creation: Invalid customer_id received - ' . ($_POST['customer_id'] ?? 'not set') . ', user_id: ' . ($currentUser['id'] ?? 'unknown'));
        }

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

                    // البحث عن العميل في جدول local_customers أولاً
                    $localCustomer = null;
                    $actualCustomerId = null;
                    $customer = null;
                    
                    $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                    if (!empty($localCustomersTableExists)) {
                        $localCustomer = $db->queryOne(
                            "SELECT id, name, phone, address, balance, status FROM local_customers WHERE id = ? FOR UPDATE",
                            [$customerId]
                        );
                        
                        if ($localCustomer) {
                            // البحث عن عميل في جدول customers بنفس الاسم
                            $existingCustomer = $db->queryOne(
                                "SELECT id, balance, status FROM customers WHERE name = ? AND status = 'active' FOR UPDATE",
                                [$localCustomer['name']]
                            );
                            
                            if ($existingCustomer) {
                                $actualCustomerId = (int)$existingCustomer['id'];
                                $customer = $existingCustomer;
                            } else {
                                // إنشاء عميل في جدول customers بنفس بيانات العميل المحلي
                                $insertResult = $db->execute(
                                    "INSERT INTO customers (name, phone, address, balance, status, created_by, created_by_admin) VALUES (?, ?, ?, ?, 'active', ?, 1)",
                                    [
                                        $localCustomer['name'],
                                        $localCustomer['phone'] ?? null,
                                        $localCustomer['address'] ?? null,
                                        (float)($localCustomer['balance'] ?? 0),
                                        $currentUser['id'] ?? null,
                                    ]
                                );
                                $actualCustomerId = (int)($insertResult['insert_id'] ?? 0);
                                
                                if ($actualCustomerId <= 0) {
                                    throw new RuntimeException('فشل إنشاء العميل في جدول customers.');
                                }
                                
                                $customer = [
                                    'id' => $actualCustomerId,
                                    'balance' => (float)($localCustomer['balance'] ?? 0),
                                    'status' => 'active'
                                ];
                            }
                        }
                    }
                    
                    // إذا لم نجد العميل في local_customers، نبحث في customers
                    if (!$customer) {
                        $customer = $db->queryOne(
                            "SELECT id, balance, status FROM customers WHERE id = ? FOR UPDATE",
                            [$customerId]
                        );
                        $actualCustomerId = $customerId;
                    }

                    if (!$customer) {
                        error_log('Shipping order: Customer not found - customer_id: ' . $customerId);
                        throw new InvalidArgumentException('تعذر العثور على العميل المحدد. يرجى التحقق من اختيار العميل.');
                    }

                    if (($customer['status'] ?? '') !== 'active') {
                        error_log('Shipping order: Customer is not active - customer_id: ' . ($actualCustomerId ?? $customerId) . ', status: ' . ($customer['status'] ?? 'unknown'));
                        throw new InvalidArgumentException('العميل المحدد غير نشط. يرجى اختيار عميل نشط.');
                    }
                    
                    // استخدام actualCustomerId بدلاً من customerId
                    $customerId = $actualCustomerId ?? $customerId;

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
                    "SELECT id, balance, status FROM customers WHERE id = ? FOR UPDATE",
                    [$order['customer_id']]
                );

                if (!$customer) {
                    error_log('Complete shipping order: Customer not found - customer_id: ' . ($order['customer_id'] ?? 'null') . ', order_id: ' . $orderId);
                    throw new InvalidArgumentException('تعذر العثور على العميل المرتبط بالطلب. قد يكون العميل قد تم حذفه.');
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
// جلب المنتجات بنفس منطق صفحة company_products بالضبط - بدون أي شروط أو قيود
$availableProductsForShipping = [];
try {
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    // جلب منتجات المصنع - نفس الاستعلام المستخدم في company_products
    if (!empty($finishedProductsTableExists)) {
        $factoryProducts = $db->query("
            SELECT 
                base.id,
                base.batch_id,
                base.batch_number,
                base.product_id,
                base.product_name,
                base.product_category,
                base.production_date,
                base.quantity_produced,
                base.unit_price,
                base.total_price
            FROM (
                SELECT 
                    fp.id,
                    fp.batch_id,
                    fp.batch_number,
                    COALESCE(fp.product_id, bn.product_id) AS product_id,
                    COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                    pr.category as product_category,
                    fp.production_date,
                    fp.quantity_produced,
                    COALESCE(
                        NULLIF(fp.unit_price, 0),
                        (SELECT pt.unit_price 
                         FROM product_templates pt 
                         WHERE pt.status = 'active' 
                           AND pt.unit_price IS NOT NULL 
                           AND pt.unit_price > 0
                           AND pt.unit_price <= 10000
                           AND (
                               -- مطابقة product_id أولاً (الأكثر دقة)
                               (
                                   COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                                   AND COALESCE(fp.product_id, bn.product_id) > 0
                                   AND pt.product_id IS NOT NULL 
                                   AND pt.product_id > 0 
                                   AND pt.product_id = COALESCE(fp.product_id, bn.product_id)
                               )
                               -- مطابقة product_name (مطابقة دقيقة أو جزئية)
                               OR (
                                   pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                               -- إذا لم يكن هناك product_id في القالب، نبحث فقط بالاسم
                               OR (
                                   (pt.product_id IS NULL OR pt.product_id = 0)
                                   AND pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                           )
                         ORDER BY pt.unit_price DESC
                         LIMIT 1),
                        0
                    ) AS unit_price,
                    fp.total_price
                FROM finished_products fp
                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                GROUP BY fp.id
            ) AS base
            ORDER BY base.production_date DESC, base.id DESC
        ");
        
        // تحويل المنتجات إلى نفس التنسيق المستخدم في طلبات الشحن - بدون أي حساب للكمية المتاحة
        foreach ($factoryProducts as $fp) {
            $batchId = (int)($fp['id'] ?? 0);
            $batchNumber = $fp['batch_number'] ?? '';
            $productName = $fp['product_name'] ?? 'غير محدد';
            $quantityProduced = (float)($fp['quantity_produced'] ?? 0);
            
            $availableProductsForShipping[] = [
                'id' => $batchId + 1000000, // استخدام رقم فريد لمنتجات المصنع
                'batch_id' => $batchId,
                'batch_number' => $batchNumber,
                'name' => $productName,
                'quantity' => $quantityProduced, // الكمية الإجمالية بدون أي طرح
                'unit' => 'قطعة',
                'unit_price' => (float)($fp['unit_price'] ?? 0),
                'is_factory_product' => true
            ];
        }
    }
    
    // جلب المنتجات الخارجية - نفس الاستعلام المستخدم في company_products
    $externalProducts = $db->query("
        SELECT 
            id,
            name,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
        ORDER BY name ASC
    ");
    
    // إضافة المنتجات الخارجية
    foreach ($externalProducts as $ep) {
        $availableProductsForShipping[] = [
            'id' => (int)($ep['id'] ?? 0),
            'batch_id' => null,
            'batch_number' => null,
            'name' => $ep['name'] ?? '',
            'quantity' => (float)($ep['quantity'] ?? 0),
            'unit' => $ep['unit'] ?? 'قطعة',
            'unit_price' => (float)($ep['unit_price'] ?? 0),
            'is_factory_product' => false
        ];
    }
    
    // ترتيب حسب الاسم
    usort($availableProductsForShipping, function($a, $b) {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });
    
} catch (Throwable $e) {
    error_log('Error fetching products for shipping: ' . $e->getMessage());
    $availableProductsForShipping = [];
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
                                <label class="form-label">العملاء المحليين</label>
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
                                    <input type="number" class="form-control text-muted" id="posPartialAmount" placeholder="0" step="1">
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

    function updateSummary() {
        const subtotal = cart.reduce((total, item) => {
            const qty = sanitizeNumber(item.quantity);
            const price = sanitizeNumber(item.unit_price);
            return total + (qty * price);
        }, 0);
        // الحصول على المبلغ المدفوع مسبقاً
        let prepaid = sanitizeNumber('0');
        let sanitizedSubtotal = sanitizeNumber(subtotal);

        // التأكد من أن المبلغ المدفوع مسبقاً لا يتجاوز المجموع الفرعي
        if (prepaid < 0) {
            prepaid = 0;
        }
        if (prepaid > sanitizedSubtotal) {
            prepaid = sanitizedSubtotal;
        }

        const netTotal = sanitizeNumber(sanitizedSubtotal - prepaid);
        let paidAmount = 0;
        const paymentType = Array.from(elements.paymentRadios).find((radio) => radio.checked)?.value || 'full';

        if (paymentType === 'full') {
            paidAmount = netTotal;
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '';
            if (elements.dueDateWrapper) elements.dueDateWrapper.classList.add('d-none');
        } else if (paymentType === 'partial') {
            elements.partialWrapper.classList.remove('d-none');
            if (elements.dueDateWrapper) elements.dueDateWrapper.classList.remove('d-none');
            let partialValue = sanitizeNumber(elements.partialInput.value);
            const inputValue = elements.partialInput.value.trim();
            const isInputFocused = document.activeElement === elements.partialInput;
            
            if (isNaN(partialValue) || partialValue <= 0) {
                // لا نمسح الحقل أثناء الكتابة، فقط نتركه كما هو
                if (!isInputFocused && (inputValue === '' || inputValue === '0' || inputValue === '0.' || inputValue === '0.00')) {
                    elements.partialInput.value = '';
                }
                paidAmount = 0;
            } else {
                if (partialValue >= netTotal && netTotal > 0) {
                    partialValue = Math.max(0, netTotal - 0);
                }
                // لا نطبق toFixed أثناء الكتابة، فقط عند blur أو change
                if (!isInputFocused) {
                    elements.partialInput.value = partialValue.toFixed(2);
                }
                paidAmount = partialValue;
            }
        } else {
            elements.partialWrapper.classList.add('d-none');
            elements.partialInput.value = '';
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

    if (elements.partialInput) {
        elements.partialInput.addEventListener('input', updateSummary);
        
        // معالجة النقر على الأسهم (step arrows) - استخدام mouseup للكشف
        elements.partialInput.addEventListener('mouseup', function() {
            setTimeout(function() {
                const value = sanitizeNumber(elements.partialInput.value);
                if (value === 0 && elements.partialInput.value === '0') {
                    elements.partialInput.value = '';
                    updateSummary();
                } else if (!isNaN(value) && value > 0) {
                    elements.partialInput.value = value.toFixed(2);
                    updateSummary();
                }
            }, 10);
        });
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
            updateCustomerBalance();
        });
    });

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

    // الحصول على الموقع الجغرافي
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
    
    // عرض معلومات العميل المالية عند تحميل الصفحة
    // استخدام setTimeout لضمان تحميل جميع العناصر
    setTimeout(function() {
        updateCustomerBalance();
    }, 500);
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

<!-- إعادة تحميل الصفحة تلقائياً بعد رسالة الخطأ -->
<script>
(function() {
    const errorAlert = document.getElementById('errorAlert');
    
    if (errorAlert && errorAlert.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية رسالة الخطأ
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