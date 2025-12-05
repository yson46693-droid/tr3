<?php
/**
 * إدارة طلبات شركات الشحن للمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/customer_history.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

$sessionErrorKey = 'manager_shipping_orders_error';
$sessionSuccessKey = 'manager_shipping_orders_success';
$error = '';
$success = '';

if (!empty($_SESSION[$sessionErrorKey])) {
    $error = $_SESSION[$sessionErrorKey];
    unset($_SESSION[$sessionErrorKey]);
}

if (!empty($_SESSION[$sessionSuccessKey])) {
    $success = $_SESSION[$sessionSuccessKey];
    unset($_SESSION[$sessionSuccessKey]);
}

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
} catch (Throwable $tableError) {
    error_log('shipping_orders: failed ensuring shipping_companies table -> ' . $tableError->getMessage());
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
            CONSTRAINT `shipping_company_orders_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
            CONSTRAINT `shipping_company_orders_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `shipping_company_orders_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $tableError) {
    error_log('shipping_orders: failed ensuring shipping_company_orders table -> ' . $tableError->getMessage());
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
    } catch (Throwable $alterError) {
        // العمود موجود بالفعل أو حدث خطأ
        error_log('shipping_orders: batch_id column check -> ' . $alterError->getMessage());
    }
} catch (Throwable $tableError) {
    error_log('shipping_orders: failed ensuring shipping_company_order_items table -> ' . $tableError->getMessage());
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

$mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
if (!$mainWarehouse) {
    $db->execute(
        "INSERT INTO warehouses (name, warehouse_type, status, location, description) VALUES (?, 'main', 'active', ?, ?)",
        ['المخزن الرئيسي', 'الموقع الرئيسي للشركة', 'تم إنشاء هذا المخزن تلقائياً لطلبات الشحن']
    );
    $mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_shipping_company') {
        $name = trim($_POST['company_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['company_notes'] ?? '');

        if ($name === '') {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال اسم شركة الشحن.';
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

                $_SESSION[$sessionSuccessKey] = 'تم إضافة شركة الشحن بنجاح.';
            } catch (InvalidArgumentException $validationError) {
                $_SESSION[$sessionErrorKey] = $validationError->getMessage();
            } catch (Throwable $addError) {
                error_log('shipping_orders: add company error -> ' . $addError->getMessage());
                $_SESSION[$sessionErrorKey] = 'تعذر إضافة شركة الشحن. يرجى المحاولة لاحقاً.';
            }
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'create_shipping_order') {
        $shippingCompanyId = isset($_POST['shipping_company_id']) ? (int)$_POST['shipping_company_id'] : 0;
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $notes = trim($_POST['order_notes'] ?? '');
        $itemsInput = $_POST['items'] ?? [];

        if ($shippingCompanyId <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار شركة الشحن.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        if ($customerId <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار العميل.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        if (!is_array($itemsInput) || empty($itemsInput)) {
            $_SESSION[$sessionErrorKey] = 'يرجى إضافة منتجات إلى الطلب.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

            $normalizedItems = [];
            $totalAmount = 0.0;
            $productIds = [];

            error_log("shipping_orders: Processing create_shipping_order - itemsInput count: " . count($itemsInput));
            foreach ($itemsInput as $index => $itemRow) {
                error_log("shipping_orders: Processing itemsInput[$index]: " . json_encode($itemRow));
                if (!is_array($itemRow)) {
                    continue;
                }

                $productId = isset($itemRow['product_id']) ? (int)$itemRow['product_id'] : 0;
                // قراءة الكمية بشكل صحيح - التأكد من أنها قيمة رقمية صحيحة
                $rawQuantity = $itemRow['quantity'] ?? 0.0;
                $quantity = (float)$rawQuantity;
                error_log("shipping_orders: RAW quantity from form: " . var_export($rawQuantity, true) . " -> parsed: $quantity for product_id: $productId");
                // التحقق من أن الكمية قيمة صحيحة وموجبة
                if ($quantity <= 0 || $quantity > 100000) {
                    error_log("shipping_orders: Invalid quantity detected: " . var_export($itemRow['quantity'], true) . " for product_id: " . $productId);
                    continue;
                }
                $unitPrice = isset($itemRow['unit_price']) ? (float)$itemRow['unit_price'] : 0.0;
                // إصلاح: استخدام !empty() لضمان أن batch_id يكون null إذا كان فارغ أو 0 أو غير موجود
                $batchId = !empty($itemRow['batch_id']) && (int)$itemRow['batch_id'] > 0 ? (int)$itemRow['batch_id'] : null;
                $productType = isset($itemRow['product_type']) ? trim($itemRow['product_type']) : '';

                if ($productId <= 0 || $unitPrice < 0) {
                    continue;
                }

                // للمنتجات من المصنع، استخدم product_id الأصلي (طرح 1000000)
                $originalProductId = $productId;
                if ($productId > 1000000 && $productType === 'factory') {
                    $originalProductId = $productId - 1000000;
                }

                $productIds[] = $originalProductId;
                $lineTotal = round($quantity * $unitPrice, 2);
                $totalAmount += $lineTotal;

                $normalizedItems[] = [
                    'product_id' => $originalProductId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'batch_id' => $batchId,
                    'product_type' => $productType,
                ];
            }

        if (empty($normalizedItems)) {
            $_SESSION[$sessionErrorKey] = 'يرجى التأكد من إدخال بيانات صحيحة للمنتجات.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

        // تجميع العناصر المكررة لمنع الخصم المتكرر
        // إذا كان نفس المنتج مع نفس batch_id موجود أكثر من مرة، نجمع الكميات
        error_log("shipping_orders: BEFORE grouping - normalizedItems count: " . count($normalizedItems) . ", items: " . json_encode($normalizedItems));
        $groupedItems = [];
        foreach ($normalizedItems as $index => $item) {
            $batchIdForKey = $item['batch_id'] ?? null;
            $key = $item['product_id'] . '_' . ($batchIdForKey ?? 'null') . '_' . $item['product_type'];
            error_log("shipping_orders: Grouping item[$index]: key='$key', product_id={$item['product_id']}, batch_id=" . ($batchIdForKey ?? 'NULL') . ", quantity={$item['quantity']}, product_type={$item['product_type']}");
            
            if (!isset($groupedItems[$key])) {
                $groupedItems[$key] = $item;
                error_log("shipping_orders: NEW grouped item with key '$key', quantity: {$item['quantity']}");
            } else {
                // جمع الكميات للعناصر المكررة
                $oldQuantity = $groupedItems[$key]['quantity'];
                $newQuantity = $item['quantity'];
                $groupedItems[$key]['quantity'] = $oldQuantity + $newQuantity;
                error_log("shipping_orders: MERGED grouped item with key '$key': old_quantity=$oldQuantity, new_quantity=$newQuantity, total_quantity={$groupedItems[$key]['quantity']}");
                // استخدام أعلى سعر وحدة عند التجميع
                if ($item['unit_price'] > $groupedItems[$key]['unit_price']) {
                    $groupedItems[$key]['unit_price'] = $item['unit_price'];
                }
                // إعادة حساب السعر الإجمالي بناءً على الكمية المجمعة والسعر
                $groupedItems[$key]['total_price'] = round($groupedItems[$key]['quantity'] * $groupedItems[$key]['unit_price'], 2);
            }
        }
        
        // إعادة حساب المبلغ الإجمالي بناءً على العناصر المجمعة
        $totalAmount = 0.0;
        foreach ($groupedItems as $key => $item) {
            $totalAmount += $item['total_price'];
            error_log("shipping_orders: Final grouped item '$key': quantity={$item['quantity']}, total_price={$item['total_price']}");
        }
        
        // تحويل المصفوفة المجمعة إلى مصفوفة عادية
        $normalizedItems = array_values($groupedItems);
        error_log("shipping_orders: AFTER grouping - items count: " . count($normalizedItems) . ", normalizedItems: " . json_encode($normalizedItems));
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

            // البحث عن العميل في جدول local_customers (العملاء المحليين)
            $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
            if (empty($localCustomersTableExists)) {
                throw new InvalidArgumentException('جدول العملاء المحليين غير متوفر في النظام.');
            }

            $customer = $db->queryOne(
                "SELECT id, balance, status FROM local_customers WHERE id = ? FOR UPDATE",
                [$customerId]
            );

            if (!$customer) {
                error_log('Shipping order: Customer not found - customer_id: ' . $customerId);
                throw new InvalidArgumentException('تعذر العثور على العميل المحدد. يرجى التحقق من اختيار العميل.');
            }

            if (($customer['status'] ?? '') !== 'active') {
                error_log('Shipping order: Customer is not active - customer_id: ' . $customerId . ', status: ' . ($customer['status'] ?? 'unknown'));
                throw new InvalidArgumentException('العميل المحدد غير نشط. يرجى اختيار عميل نشط.');
            }

            // التحقق من الكميات المتاحة
            foreach ($normalizedItems as $normalizedItem) {
                $productId = $normalizedItem['product_id'];
                $requestedQuantity = $normalizedItem['quantity'];
                $productType = $normalizedItem['product_type'] ?? '';
                $batchId = $normalizedItem['batch_id'] ?? null;

                if ($productType === 'factory' && $batchId) {
                    // للمنتجات من المصنع، التحقق من الكمية المتاحة في finished_products
                    $fp = $db->queryOne("
                        SELECT 
                            fp.id,
                            fp.quantity_produced,
                            fp.batch_number,
                            COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name
                        FROM finished_products fp
                        LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                        LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                        WHERE fp.id = ?
                    ", [$batchId]);

                    if (!$fp) {
                        throw new InvalidArgumentException('تعذر العثور على منتج من عناصر الطلب.');
                    }

                    $quantityProduced = (float)($fp['quantity_produced'] ?? 0);
                    
                    // حساب الكمية المباعة
                    $soldQty = 0;
                    $pendingQty = 0;
                    $pendingShippingQty = 0;
                    
                    if (!empty($fp['batch_number'])) {
                        try {
                            $sold = $db->queryOne("
                                SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                                FROM invoice_items ii
                                INNER JOIN invoices i ON ii.invoice_id = i.id
                                INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                                INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                                WHERE bn.batch_number = ?
                            ", [$fp['batch_number']]);
                            $soldQty = (float)($sold['sold_quantity'] ?? 0);
                            
                            // حساب الكمية المحجوزة في طلبات العملاء المعلقة
                            // ملاحظة: customer_order_items لا يحتوي على batch_number مباشرة
                            // لذلك نستخدم finished_products للربط مع batch_number بناءً على product_id و batch_number
                            // هذا قد يحسب كميات من batches أخرى لنفس المنتج، لكن هذا مقبول كتقدير
                            $pending = $db->queryOne("
                                SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                                FROM customer_order_items oi
                                INNER JOIN customer_orders co ON oi.order_id = co.id
                                INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                                WHERE co.status = 'pending'
                            ", [$fp['batch_number']]);
                            $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                            
                            $pendingShipping = $db->queryOne("
                                SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                                FROM shipping_company_order_items soi
                                INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                                WHERE sco.status = 'in_transit'
                                  AND soi.batch_id = ?
                            ", [$batchId]);
                            $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
                        } catch (Throwable $calcError) {
                            error_log('shipping_orders: error calculating available quantity: ' . $calcError->getMessage());
                        }
                    }
                    
                    $availableQuantity = max(0, $quantityProduced - $soldQty - $pendingQty - $pendingShippingQty);
                    
                    if ($availableQuantity < $requestedQuantity) {
                        throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . ($fp['product_name'] ?? '') . ' غير كافية.');
                    }
                } else {
                    // للمنتجات الخارجية، التحقق من جدول products
                    $productRow = $db->queryOne(
                        "SELECT id, name, quantity FROM products WHERE id = ? FOR UPDATE",
                        [$productId]
                    );

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
                $productId = $normalizedItem['product_id'];
                $productType = $normalizedItem['product_type'] ?? '';
                $batchId = $normalizedItem['batch_id'] ?? null;
                
                $productName = '';
                if ($productType === 'factory' && $batchId) {
                    $fp = $db->queryOne("
                        SELECT COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                               fp.batch_number
                        FROM finished_products fp
                        LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                        LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                        WHERE fp.id = ?
                    ", [$batchId]);
                    $productName = ($fp['product_name'] ?? 'غير محدد') . ($fp['batch_number'] ? ' (' . $fp['batch_number'] . ')' : '');
                } else {
                    $productRow = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                    $productName = $productRow['name'] ?? 'غير محدد';
                }
                
                $invoiceItems[] = [
                    'product_id' => $productId,
                    'description' => $productName,
                    'quantity' => $normalizedItem['quantity'],
                    'unit_price' => $normalizedItem['unit_price'],
                ];
            }

            // التحقق من وجود العميل في جدول customers قبل إنشاء الفاتورة
            // لأن جدول invoices يحتوي على foreign key constraint يشير إلى customers
            $customerInCustomersTable = $db->queryOne(
                "SELECT id FROM customers WHERE id = ?",
                [$customerId]
            );
            
            if (!$customerInCustomersTable) {
                // جلب بيانات العميل من local_customers
                $localCustomerData = $db->queryOne(
                    "SELECT name, phone, address, balance, created_by FROM local_customers WHERE id = ?",
                    [$customerId]
                );
                
                if ($localCustomerData) {
                    // التحقق من وجود عمود rep_id في جدول customers
                    $hasRepIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'rep_id'"));
                    
                    // إنشاء سجل في جدول customers
                    if ($hasRepIdColumn) {
                        $db->execute(
                            "INSERT INTO customers (id, name, phone, address, balance, status, rep_id, created_by, created_at) 
                             VALUES (?, ?, ?, ?, ?, 'active', NULL, ?, NOW())",
                            [
                                $customerId,
                                $localCustomerData['name'] ?? '',
                                $localCustomerData['phone'] ?? null,
                                $localCustomerData['address'] ?? null,
                                $localCustomerData['balance'] ?? 0,
                                $localCustomerData['created_by'] ?? $currentUser['id'] ?? null,
                            ]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO customers (id, name, phone, address, balance, status, created_by, created_at) 
                             VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())",
                            [
                                $customerId,
                                $localCustomerData['name'] ?? '',
                                $localCustomerData['phone'] ?? null,
                                $localCustomerData['address'] ?? null,
                                $localCustomerData['balance'] ?? 0,
                                $localCustomerData['created_by'] ?? $currentUser['id'] ?? null,
                            ]
                        );
                    }
                } else {
                    throw new InvalidArgumentException('تعذر العثور على بيانات العميل.');
                }
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

            // ربط أرقام التشغيلة بعناصر الفاتورة
            $invoiceItemsFromDb = $db->query(
                "SELECT id, product_id FROM invoice_items WHERE invoice_id = ? ORDER BY id",
                [$invoiceId]
            );
            
            // إنشاء خريطة للمطابقة بين invoice_items و normalizedItems
            $invoiceItemsMap = [];
            foreach ($invoiceItemsFromDb as $invItem) {
                $productId = (int)$invItem['product_id'];
                if (!isset($invoiceItemsMap[$productId])) {
                    $invoiceItemsMap[$productId] = [];
                }
                $invoiceItemsMap[$productId][] = (int)$invItem['id'];
            }
            
            // ربط أرقام التشغيلة بعناصر الفاتورة
            foreach ($normalizedItems as $normalizedItem) {
                $productId = (int)$normalizedItem['product_id'];
                $batchId = $normalizedItem['batch_id'] ?? null;
                $productType = $normalizedItem['product_type'] ?? '';
                $quantity = $normalizedItem['quantity'];
                
                if (isset($invoiceItemsMap[$productId]) && !empty($invoiceItemsMap[$productId])) {
                    // استخدام أول invoice_item_id متطابق
                    $invoiceItemId = array_shift($invoiceItemsMap[$productId]);
                    
                    // البحث عن batch_number_id من جدول batch_numbers
                    $batchNumberId = null;
                    if ($productType === 'factory' && $batchId) {
                        // جلب batch_number من finished_products
                        $fp = $db->queryOne("
                            SELECT fp.batch_number, fp.batch_id
                            FROM finished_products fp
                            WHERE fp.id = ?
                            LIMIT 1
                        ", [$batchId]);
                        
                        error_log("shipping_orders: Linking batch - batchId: $batchId, fp data: " . json_encode($fp));
                        
                        if ($fp) {
                            // محاولة جلب batch_number من finished_products.batch_number مباشرة
                            $batchNumber = null;
                            if (!empty($fp['batch_number'])) {
                                $batchNumber = trim($fp['batch_number']);
                                error_log("shipping_orders: Found batch_number from finished_products: $batchNumber");
                            } elseif (!empty($fp['batch_id'])) {
                                // إذا لم يكن batch_number موجوداً، نحاول جلب batch_number من batch_numbers باستخدام batch_id
                                $batchFromTable = $db->queryOne(
                                    "SELECT batch_number FROM batch_numbers WHERE id = ?",
                                    [(int)$fp['batch_id']]
                                );
                                if ($batchFromTable && !empty($batchFromTable['batch_number'])) {
                                    $batchNumber = trim($batchFromTable['batch_number']);
                                    error_log("shipping_orders: Found batch_number from batch_numbers using batch_id: $batchNumber");
                                }
                            }
                            
                            if ($batchNumber) {
                                // البحث عن batch_number_id من جدول batch_numbers
                                $batchCheck = $db->queryOne(
                                    "SELECT id FROM batch_numbers WHERE batch_number = ?",
                                    [$batchNumber]
                                );
                                if ($batchCheck) {
                                    $batchNumberId = (int)$batchCheck['id'];
                                    error_log("shipping_orders: Found batch_number_id: $batchNumberId for batch_number: $batchNumber");
                                } else {
                                    error_log("shipping_orders: WARNING - batch_number '$batchNumber' not found in batch_numbers table");
                                }
                            } else {
                                error_log("shipping_orders: WARNING - batch_number is empty for finished_products.id = $batchId");
                            }
                        } else {
                            error_log("shipping_orders: ERROR - finished_products not found with id = $batchId");
                        }
                    }
                    
                    // ربط رقم التشغيلة بعنصر الفاتورة إذا وُجد
                    if ($batchNumberId) {
                        try {
                            // التحقق من وجود سجل مسبقاً لتجنب التكرار
                            $existingBatchLink = $db->queryOne(
                                "SELECT id, quantity FROM sales_batch_numbers WHERE invoice_item_id = ? AND batch_number_id = ?",
                                [$invoiceItemId, $batchNumberId]
                            );
                            
                            if ($existingBatchLink) {
                                // إذا كان السجل موجوداً، نحدّث الكمية فقط إذا كانت مختلفة
                                if ((float)($existingBatchLink['quantity'] ?? 0) != $quantity) {
                                    $db->execute(
                                        "UPDATE sales_batch_numbers SET quantity = ? WHERE id = ?",
                                        [$quantity, $existingBatchLink['id']]
                                    );
                                    error_log("shipping_orders: Updated existing batch link - batch_number_id $batchNumberId to invoice_item_id $invoiceItemId with quantity $quantity");
                                } else {
                                    error_log("shipping_orders: Batch link already exists with same quantity - batch_number_id $batchNumberId to invoice_item_id $invoiceItemId with quantity $quantity");
                                }
                            } else {
                                // إدراج سجل جديد
                                $db->execute(
                                    "INSERT INTO sales_batch_numbers (invoice_item_id, batch_number_id, quantity) 
                                     VALUES (?, ?, ?)",
                                    [$invoiceItemId, $batchNumberId, $quantity]
                                );
                                error_log("shipping_orders: Successfully linked batch_number_id $batchNumberId to invoice_item_id $invoiceItemId with quantity $quantity");
                            }
                        } catch (Throwable $batchError) {
                            error_log('shipping_orders: Error linking batch number to invoice item: ' . $batchError->getMessage());
                        }
                    } else {
                        error_log("shipping_orders: WARNING - batchNumberId is null for product_id: $productId, batchId: $batchId, productType: $productType");
                    }
                }
            }

            $orderNumber = generateShippingOrderNumber($db);

            $db->execute(
                "INSERT INTO shipping_company_orders (order_number, shipping_company_id, customer_id, invoice_id, total_amount, status, handed_over_at, notes, created_by) VALUES (?, ?, ?, ?, ?, 'in_transit', NOW(), ?, ?)",
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

            // ملاحظة: تم خصم الكميات تلقائياً عند إنشاء الفاتورة عبر createInvoice()
            // لا حاجة لخصم يدوي هنا لتجنب الخصم المتكرر

            // حفظ عناصر الطلب فقط بدون خصم إضافي
            foreach ($normalizedItems as $normalizedItem) {
                $db->execute(
                    "INSERT INTO shipping_company_order_items (order_id, product_id, batch_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $orderId,
                        $normalizedItem['product_id'],
                        $normalizedItem['batch_id'] ?? null,
                        $normalizedItem['quantity'],
                        $normalizedItem['unit_price'],
                        $normalizedItem['total_price'],
                    ]
                );
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

            $_SESSION[$sessionSuccessKey] = 'تم تسجيل طلب الشحن وتسليم المنتجات لشركة الشحن بنجاح.';
        } catch (InvalidArgumentException $validationError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            $_SESSION[$sessionErrorKey] = $validationError->getMessage();
        } catch (Throwable $createError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('shipping_orders: create order error -> ' . $createError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إنشاء طلب الشحن. يرجى المحاولة لاحقاً.';
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'cancel_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

        if ($orderId <= 0) {
            $_SESSION[$sessionErrorKey] = 'طلب غير صالح للإلغاء.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

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

            if ($order['status'] === 'cancelled') {
                throw new InvalidArgumentException('تم إلغاء هذا الطلب بالفعل.');
            }

            if ($order['status'] === 'delivered') {
                throw new InvalidArgumentException('لا يمكن إلغاء طلب تم تسليمه بالفعل.');
            }

            $totalAmount = (float)($order['total_amount'] ?? 0.0);

            // خصم المبلغ من ديون شركة الشحن
            $db->execute(
                "UPDATE shipping_companies SET balance = balance - ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$totalAmount, $currentUser['id'] ?? null, $order['shipping_company_id']]
            );

            // إرجاع المنتجات إلى المخزن الرئيسي
            $orderItems = $db->query(
                "SELECT product_id, batch_id, quantity FROM shipping_company_order_items WHERE order_id = ?",
                [$orderId]
            );

            foreach ($orderItems as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $batchId = isset($item['batch_id']) && $item['batch_id'] > 0 ? (int)$item['batch_id'] : null;
                $quantity = (float)($item['quantity'] ?? 0);

                // إذا كان batch_id موجوداً، فهو منتج من المصنع
                if ($batchId) {
                    // للمنتجات من المصنع، إرجاع الكمية إلى finished_products
                    $db->execute(
                        "UPDATE finished_products SET quantity_produced = quantity_produced + ? WHERE batch_id = ?",
                        [$quantity, $batchId]
                    );
                } else {
                    // للمنتجات الخارجية، إرجاع الكمية إلى products
                    $db->execute(
                        "UPDATE products SET quantity = quantity + ? WHERE id = ?",
                        [$quantity, $productId]
                    );

                    // تسجيل حركة المخزون
                    $movementNote = 'إرجاع منتجات من طلب شحن ملغي #' . ($order['order_number'] ?? $orderId);
                    recordInventoryMovement(
                        $productId,
                        $mainWarehouse['id'] ?? null,
                        'in',
                        $quantity,
                        'shipping_order_cancelled',
                        $orderId,
                        $movementNote,
                        $currentUser['id'] ?? null
                    );
                }
            }

            // تحديث حالة الطلب إلى ملغي
            $db->execute(
                "UPDATE shipping_company_orders SET status = 'cancelled', updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$currentUser['id'] ?? null, $orderId]
            );

            // إلغاء الفاتورة إذا كانت موجودة
            if (!empty($order['invoice_id'])) {
                $db->execute(
                    "UPDATE invoices SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
                    [$order['invoice_id']]
                );
            }

            logAudit(
                $currentUser['id'] ?? null,
                'cancel_shipping_order',
                'shipping_order',
                $orderId,
                null,
                [
                    'total_amount' => $totalAmount,
                    'shipping_company_id' => $order['shipping_company_id'],
                ]
            );

            $db->commit();
            $transactionStarted = false;

            $_SESSION[$sessionSuccessKey] = 'تم إلغاء الطلب وإرجاع المنتجات إلى المخزن الرئيسي بنجاح.';
        } catch (InvalidArgumentException $validationError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            $_SESSION[$sessionErrorKey] = $validationError->getMessage();
        } catch (Throwable $cancelError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('shipping_orders: cancel order error -> ' . $cancelError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إلغاء الطلب. يرجى المحاولة لاحقاً.';
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }

    if ($action === 'complete_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

        if ($orderId <= 0) {
            $_SESSION[$sessionErrorKey] = 'طلب غير صالح لإتمام التسليم.';
            redirectAfterPost('shipping_orders', [], [], 'manager');
            exit;
        }

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

            // البحث عن العميل في جدول local_customers أولاً (العملاء المحليين)
            $customer = null;
            $customerTable = null;
            $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
            
            if (!empty($localCustomersTableExists)) {
                $customer = $db->queryOne(
                    "SELECT id, balance, status FROM local_customers WHERE id = ? FOR UPDATE",
                    [$order['customer_id']]
                );
                if ($customer) {
                    $customerTable = 'local_customers';
                }
            }

            // إذا لم نجد العميل في local_customers، نبحث في customers (للطلبات القديمة)
            if (!$customer) {
                $customersTableExists = $db->queryOne("SHOW TABLES LIKE 'customers'");
                if (!empty($customersTableExists)) {
                    $customer = $db->queryOne(
                        "SELECT id, balance, status FROM customers WHERE id = ? FOR UPDATE",
                        [$order['customer_id']]
                    );
                    if ($customer) {
                        $customerTable = 'customers';
                    }
                }
            }

            if (!$customer || !$customerTable) {
                error_log('Complete shipping order: Customer not found - customer_id: ' . ($order['customer_id'] ?? 'null') . ', order_id: ' . $orderId);
                throw new InvalidArgumentException('تعذر العثور على العميل المرتبط بالطلب. قد يكون العميل قد تم حذفه.');
            }

            $totalAmount = (float)($order['total_amount'] ?? 0.0);

            $db->execute(
                "UPDATE shipping_companies SET balance = balance - ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$totalAmount, $currentUser['id'] ?? null, $order['shipping_company_id']]
            );

            // تحديث رصيد العميل في الجدول المناسب (إضافة المبلغ كدين)
            $currentBalance = (float)($customer['balance'] ?? 0.0);
            $newBalance = round($currentBalance + $totalAmount, 2);
            
            $db->execute(
                "UPDATE {$customerTable} SET balance = ?, updated_at = NOW() WHERE id = ?",
                [$newBalance, $order['customer_id']]
            );

            $db->execute(
                "UPDATE shipping_company_orders SET status = 'delivered', delivered_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$currentUser['id'] ?? null, $orderId]
            );

            // تحديث الفاتورة لتعكس المبلغ المتبقي
            if (!empty($order['invoice_id'])) {
                // تحديث حالة الفاتورة والمبالغ
                $db->execute(
                    "UPDATE invoices SET status = 'sent', remaining_amount = ?, paid_amount = 0, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $order['invoice_id']]
                );
                
                // جلب بيانات الفاتورة للتأكد من تحديث سجل المشتريات
                $invoiceData = $db->queryOne(
                    "SELECT id, invoice_number, date, total_amount, paid_amount, status, customer_id 
                     FROM invoices WHERE id = ?",
                    [$order['invoice_id']]
                );
                
                // إضافة المنتجات إلى سجل مشتريات العميل
                if ($invoiceData) {
                    try {
                        // التأكد من أن جدول customer_purchase_history موجود
                        customerHistoryEnsureSetup();
                        
                        // إضافة أو تحديث سجل الفاتورة في customer_purchase_history
                        $db->execute(
                            "INSERT INTO customer_purchase_history
                                (customer_id, invoice_id, invoice_number, invoice_date, invoice_total, paid_amount, invoice_status,
                                 return_total, return_count, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE
                                invoice_number = VALUES(invoice_number),
                                invoice_date = VALUES(invoice_date),
                                invoice_total = VALUES(invoice_total),
                                paid_amount = VALUES(paid_amount),
                                invoice_status = VALUES(invoice_status),
                                updated_at = NOW()",
                            [
                                $order['customer_id'],
                                $order['invoice_id'],
                                $invoiceData['invoice_number'] ?? '',
                                $invoiceData['date'] ?? date('Y-m-d'),
                                (float)($invoiceData['total_amount'] ?? 0),
                                (float)($invoiceData['paid_amount'] ?? 0),
                                $invoiceData['status'] ?? 'sent',
                            ]
                        );
                        
                        // مزامنة كاملة لسجل المشتريات للتأكد من تحديث جميع البيانات
                        customerHistorySyncForCustomer($order['customer_id']);
                    } catch (Throwable $historyError) {
                        error_log('shipping_orders: failed syncing customer purchase history -> ' . $historyError->getMessage());
                        error_log('shipping_orders: history error trace -> ' . $historyError->getTraceAsString());
                        // لا نوقف العملية إذا فشل تحديث السجل
                    }
                }
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
                    'customer_table' => $customerTable,
                    'old_balance' => $currentBalance,
                    'new_balance' => $newBalance,
                    'shipping_company_id' => $order['shipping_company_id'],
                ]
            );

            $db->commit();
            $transactionStarted = false;

            $_SESSION[$sessionSuccessKey] = 'تم تأكيد تسليم الطلب للعميل ونقل الدين بنجاح. تم إضافة المنتجات إلى سجل مشتريات العميل.';
        } catch (InvalidArgumentException $validationError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            $_SESSION[$sessionErrorKey] = $validationError->getMessage();
        } catch (Throwable $completeError) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log('shipping_orders: complete order error -> ' . $completeError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إتمام إجراءات الطلب. يرجى المحاولة لاحقاً.';
        }

        redirectAfterPost('shipping_orders', [], [], 'manager');
        exit;
    }
}

$shippingCompanies = [];
try {
    $shippingCompanies = $db->query(
        "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
    );
} catch (Throwable $companiesError) {
    error_log('shipping_orders: failed fetching companies -> ' . $companiesError->getMessage());
    $shippingCompanies = [];
}

$activeCustomers = [];
try {
    // جلب العملاء المحليين فقط من جدول local_customers
    $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (!empty($localCustomersTableExists)) {
        $activeCustomers = $db->query(
            "SELECT id, name, phone FROM local_customers WHERE status = 'active' ORDER BY name ASC"
        );
    } else {
        $activeCustomers = [];
    }
} catch (Throwable $customersError) {
    error_log('shipping_orders: failed fetching customers -> ' . $customersError->getMessage());
    $activeCustomers = [];
}

$availableProducts = [];
try {
    // التحقق من وجود جدول finished_products
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    $productsList = [];
    
    // جلب منتجات المصنع من finished_products
    if (!empty($finishedProductsTableExists)) {
        try {
            $factoryProducts = $db->query("
                SELECT 
                    fp.id,
                    COALESCE(
                        NULLIF(TRIM(fp.product_name), ''),
                        pr.name,
                        CONCAT('منتج رقم ', COALESCE(fp.product_id, bn.product_id, fp.id))
                    ) AS name,
                    fp.quantity_produced AS quantity,
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
                               OR (
                                   pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                   )
                               )
                           )
                         ORDER BY pt.unit_price DESC
                         LIMIT 1),
                        0
                    ) AS unit_price,
                    'قطعة' AS unit,
                    fp.batch_number,
                    fp.id AS batch_id,
                    'factory' AS product_type
                FROM finished_products fp
                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                ORDER BY fp.production_date DESC, fp.id DESC
            ");
            
            foreach ($factoryProducts as $fp) {
                $batchNumber = $fp['batch_number'] ?? '';
                $productName = $fp['name'] ?? 'غير محدد';
                $quantity = (float)($fp['quantity'] ?? 0);
                $unitPrice = (float)($fp['unit_price'] ?? 0);
                
                // حساب الكمية المتاحة (طرح المبيعات والطلبات المعلقة)
                $soldQty = 0;
                $pendingQty = 0;
                $pendingShippingQty = 0;
                
                if (!empty($batchNumber)) {
                    try {
                        // حساب الكمية المباعة
                        $sold = $db->queryOne("
                            SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                            FROM invoice_items ii
                            INNER JOIN invoices i ON ii.invoice_id = i.id
                            INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                            INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                            WHERE bn.batch_number = ?
                        ", [$batchNumber]);
                        $soldQty = (float)($sold['sold_quantity'] ?? 0);
                        
                        // حساب الكمية المحجوزة في طلبات العملاء المعلقة
                        // ملاحظة: customer_order_items لا يحتوي على batch_number مباشرة
                        // لذلك نستخدم finished_products للربط مع batch_number بناءً على product_id و batch_number
                        // هذا قد يحسب كميات من batches أخرى لنفس المنتج، لكن هذا مقبول كتقدير
                        $pending = $db->queryOne("
                            SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                            FROM customer_order_items oi
                            INNER JOIN customer_orders co ON oi.order_id = co.id
                            INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                            WHERE co.status = 'pending'
                        ", [$batchNumber]);
                        $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                        
                        // حساب الكمية المحجوزة في طلبات الشحن المعلقة
                        $pendingShipping = $db->queryOne("
                            SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                            FROM shipping_company_order_items soi
                            INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                            WHERE sco.status = 'in_transit'
                              AND soi.batch_id = ?
                        ", [$fp['batch_id'] ?? 0]);
                        $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
                    } catch (Throwable $calcError) {
                        error_log('shipping_orders: error calculating available quantity for batch ' . $batchNumber . ': ' . $calcError->getMessage());
                    }
                }
                
                $availableQuantity = max(0, $quantity - $soldQty - $pendingQty - $pendingShippingQty);
                
                // عرض جميع المنتجات حتى لو كانت الكمية المتاحة صفر (مثل نقطة بيع المدير)
                $productsList[] = [
                    'id' => (int)$fp['id'] + 1000000, // استخدام رقم فريد لمنتجات المصنع
                    'name' => $productName . ($batchNumber ? ' (' . $batchNumber . ')' : ''),
                    'quantity' => $availableQuantity,
                    'total_quantity' => $quantity, // الكمية الإجمالية قبل طرح المبيعات
                    'unit' => $fp['unit'] ?? 'قطعة',
                    'unit_price' => $unitPrice,
                    'batch_number' => $batchNumber,
                    'batch_id' => $fp['batch_id'] ?? null,
                    'product_type' => 'factory',
                    'original_id' => (int)$fp['id']
                ];
            }
        } catch (Throwable $factoryError) {
            error_log('shipping_orders: failed fetching factory products -> ' . $factoryError->getMessage());
        }
    }
    
    // جلب المنتجات الخارجية من products
    try {
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
              AND quantity > 0
            ORDER BY name ASC
        ");
        
        foreach ($externalProducts as $ep) {
            $productsList[] = [
                'id' => (int)$ep['id'],
                'name' => $ep['name'] ?? 'غير محدد',
                'quantity' => (float)($ep['quantity'] ?? 0),
                'unit' => $ep['unit'] ?? 'قطعة',
                'unit_price' => (float)($ep['unit_price'] ?? 0),
                'batch_number' => null,
                'batch_id' => null,
                'product_type' => 'external',
                'original_id' => (int)$ep['id']
            ];
        }
    } catch (Throwable $externalError) {
        error_log('shipping_orders: failed fetching external products -> ' . $externalError->getMessage());
    }
    
    // ترتيب المنتجات حسب الاسم
    usort($productsList, function($a, $b) {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });
    
    $availableProducts = $productsList;
    
} catch (Throwable $productsError) {
    error_log('shipping_orders: failed fetching products -> ' . $productsError->getMessage());
    $availableProducts = [];
}

$orders = [];
try {
    // جلب الطلبات مع البحث عن العملاء في كلا الجدولين (local_customers و customers)
    $orders = $db->query(
        "SELECT 
            sco.*, 
            sc.name AS shipping_company_name,
            sc.balance AS company_balance,
            COALESCE(lc.name, c.name) AS customer_name,
            COALESCE(lc.phone, c.phone) AS customer_phone,
            i.invoice_number
        FROM shipping_company_orders sco
        LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
        LEFT JOIN local_customers lc ON sco.customer_id = lc.id
        LEFT JOIN customers c ON sco.customer_id = c.id AND lc.id IS NULL
        LEFT JOIN invoices i ON sco.invoice_id = i.id
        ORDER BY sco.created_at DESC
        LIMIT 50"
    );
} catch (Throwable $ordersError) {
    error_log('shipping_orders: failed fetching orders -> ' . $ordersError->getMessage());
    $orders = [];
}

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
            SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
            SUM(CASE WHEN status = 'in_transit' THEN total_amount ELSE 0 END) AS outstanding_amount
        FROM shipping_company_orders"
    );

    if ($statsRow) {
        $ordersStats['total_orders'] = (int)($statsRow['total_orders'] ?? 0);
        $ordersStats['active_orders'] = (int)($statsRow['active_orders'] ?? 0);
        $ordersStats['delivered_orders'] = (int)($statsRow['delivered_orders'] ?? 0);
        $ordersStats['outstanding_amount'] = (float)($statsRow['outstanding_amount'] ?? 0);
    }
} catch (Throwable $statsError) {
    error_log('shipping_orders: failed fetching stats -> ' . $statsError->getMessage());
}

$statusLabels = [
    'assigned' => ['label' => 'تم التسليم لشركة الشحن', 'class' => 'bg-primary'],
    'in_transit' => ['label' => 'جاري الشحن', 'class' => 'bg-warning text-dark'],
    'delivered' => ['label' => 'تم التسليم للعميل', 'class' => 'bg-success'],
    'cancelled' => ['label' => 'ملغي', 'class' => 'bg-secondary'],
];

$hasProducts = !empty($availableProducts);
$hasShippingCompanies = !empty($shippingCompanies);
?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

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

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">تسجيل طلب شحن جديد</h5>
            <small class="text-muted">قم بتسليم المنتجات لشركة الشحن وتتبع الدين عليها لحين استلام العميل.</small>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addShippingCompanyModal">
            <i class="bi bi-plus-circle me-1"></i>شركة شحن جديدة
        </button>
    </div>
    <div class="card-body">
        <?php if (!$hasShippingCompanies): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-info-circle-fill fs-5"></i>
                <div>لم يتم إضافة شركات شحن بعد. يرجى إضافة شركة شحن قبل تسجيل الطلبات.</div>
            </div>
        <?php elseif (!$hasProducts): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-box-seam fs-5"></i>
                <div>لا توجد منتجات متاحة في المخزن الرئيسي حالياً.</div>
            </div>
        <?php elseif (empty($activeCustomers)): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-people fs-5"></i>
                <div>لا توجد عملاء نشطون في النظام. قم بإضافة عميل أولاً.</div>
            </div>
        <?php else: ?>
            <form method="POST" id="shippingOrderForm" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create_shipping_order">
                <div class="row g-3 mb-3">
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label">شركة الشحن <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select class="form-select" name="shipping_company_id" required>
                                <option value="">اختر شركة الشحن</option>
                                <?php foreach ($shippingCompanies as $company): ?>
                                    <option value="<?php echo (int)$company['id']; ?>">
                                        <?php echo htmlspecialchars($company['name']); ?>
                                        <?php if (!empty($company['phone'])): ?>
                                            - <?php echo htmlspecialchars($company['phone']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#addShippingCompanyModal" title="إضافة شركة شحن">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" required>
                            <option value="">اختر العميل</option>
                            <?php foreach ($activeCustomers as $customer): ?>
                                <option value="<?php echo (int)$customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                    <?php if (!empty($customer['phone'])): ?>
                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">المخزن المصدر</label>
                        <div class="form-control bg-light">
                            <i class="bi bi-building me-1"></i>
                            <?php echo htmlspecialchars($mainWarehouse['name'] ?? 'المخزن الرئيسي'); ?>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" id="shippingItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 220px;">المنتج</th>
                                <th style="width: 110px;">المتاح</th>
                                <th style="width: 140px;">الكمية <span class="text-danger">*</span></th>
                                <th style="width: 160px;">سعر الوحدة <span class="text-danger">*</span></th>
                                <th style="width: 160px;">الإجمالي</th>
                                <th style="width: 80px;">حذف</th>
                            </tr>
                        </thead>
                        <tbody id="shippingItemsBody"></tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addShippingItemBtn">
                        <i class="bi bi-plus-circle me-1"></i>إضافة منتج
                    </button>
                    <div class="shipping-order-summary card bg-light border-0 px-3 py-2">
                        <div class="d-flex align-items-center gap-3">
                            <div>
                                <div class="small text-muted">إجمالي عدد المنتجات</div>
                                <div class="fw-semibold" id="shippingItemsCount">0</div>
                            </div>
                            <div>
                                <div class="small text-muted">إجمالي الطلب</div>
                                <div class="fw-bold text-success" id="shippingOrderTotal"><?php echo formatCurrency(0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">ملاحظات إضافية</label>
                    <textarea class="form-control" name="order_notes" rows="2" placeholder="أي تفاصيل إضافية لشركة الشحن أو فريق المبيعات (اختياري)"></textarea>
                </div>

                <div class="alert alert-info d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <div>
                        سيتم تسجيل هذا الطلب على شركة الشحن كدين لحين تأكيد التسليم للعميل، ثم يتحول الدين إلى العميل ليتم تحصيله لاحقاً.
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-success btn-lg" id="submitShippingOrderBtn">
                        <i class="bi bi-send-check me-1"></i>تسجيل الطلب وتسليم المنتجات
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h5 class="mb-0">شركات الشحن</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($shippingCompanies)): ?>
            <div class="p-4 text-center text-muted">لم يتم إضافة شركات شحن بعد.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>الاسم</th>
                            <th>الهاتف</th>
                            <th>الحالة</th>
                            <th>ديون الشركة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shippingCompanies as $company): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company['name']); ?></td>
                                <td><?php echo $company['phone'] ? htmlspecialchars($company['phone']) : '<span class="text-muted">غير متوفر</span>'; ?></td>
                                <td>
                                    <?php if (($company['status'] ?? '') === 'active'): ?>
                                        <span class="badge bg-success">نشطة</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">غير نشطة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-<?php echo ($company['balance'] ?? 0) > 0 ? 'danger' : 'muted'; ?>">
                                    <?php echo formatCurrency((float)($company['balance'] ?? 0)); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0">طلبات الشحن</h5>
    </div>
    <div class="card-body p-0">
        <?php
        // تقسيم الطلبات حسب الحالة
        $activeOrders = array_filter($orders, function($order) {
            return in_array($order['status'], ['in_transit'], true);
        });
        $deliveredOrders = array_filter($orders, function($order) {
            return $order['status'] === 'delivered';
        });
        $cancelledOrders = array_filter($orders, function($order) {
            return $order['status'] === 'cancelled';
        });
        ?>
        
        <ul class="nav nav-tabs card-header-tabs border-0" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="active-orders-tab" data-bs-toggle="tab" data-bs-target="#active-orders" type="button" role="tab">
                    جاري الشحن <span class="badge bg-warning text-dark ms-1"><?php echo count($activeOrders); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="delivered-orders-tab" data-bs-toggle="tab" data-bs-target="#delivered-orders" type="button" role="tab">
                    تم التسليم <span class="badge bg-success ms-1"><?php echo count($deliveredOrders); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cancelled-orders-tab" data-bs-toggle="tab" data-bs-target="#cancelled-orders" type="button" role="tab">
                    ملغاة <span class="badge bg-secondary ms-1"><?php echo count($cancelledOrders); ?></span>
                </button>
            </li>
        </ul>
        
        <div class="tab-content">
            <!-- طلبات جارية -->
            <div class="tab-pane fade show active" id="active-orders" role="tabpanel">
                <?php if (empty($activeOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات جارية حالياً.</div>
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
                                    <th>تاريخ التسليم للشركة</th>
                                    <th>الفاتورة</th>
                                    <th style="width: 250px;">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeOrders as $order): ?>
                                    <?php
                                        $statusInfo = $statusLabels[$order['status']] ?? ['label' => $order['status'], 'class' => 'bg-secondary'];
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
                                            <?php if (!empty($order['handed_over_at'])): ?>
                                                <span class="text-info fw-semibold"><?php echo formatDateTime($order['handed_over_at']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $invoiceLink ?: '<span class="text-muted">لا توجد فاتورة</span>'; ?></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('هل ترغب في إلغاء هذا الطلب وإرجاع المنتجات إلى المخزن الرئيسي؟');">
                                                    <input type="hidden" name="action" value="cancel_shipping_order">
                                                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        <i class="bi bi-x-circle me-1"></i>طلب ملغي
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('هل ترغب في تأكيد تسليم الطلب للعميل ونقل الدين من شركة الشحن إلى العميل؟');">
                                                    <input type="hidden" name="action" value="complete_shipping_order">
                                                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="bi bi-check-circle me-1"></i>تم التسليم
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- طلبات مكتملة -->
            <div class="tab-pane fade" id="delivered-orders" role="tabpanel">
                <?php if (empty($deliveredOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات مكتملة.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>شركة الشحن</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>تاريخ التسليم للعميل</th>
                                    <th>الفاتورة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deliveredOrders as $order): ?>
                                    <?php
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
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                            <?php if (!empty($order['customer_phone'])): ?>
                                                <div class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency((float)$order['total_amount']); ?></td>
                                        <td>
                                            <?php if ($deliveredAt): ?>
                                                <span class="text-success fw-semibold"><?php echo formatDateTime($deliveredAt); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $invoiceLink ?: '<span class="text-muted">لا توجد فاتورة</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- طلبات ملغاة -->
            <div class="tab-pane fade" id="cancelled-orders" role="tabpanel">
                <?php if (empty($cancelledOrders)): ?>
                    <div class="p-4 text-center text-muted">لا توجد طلبات ملغاة.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>شركة الشحن</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>تاريخ الإلغاء</th>
                                    <th>الفاتورة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cancelledOrders as $order): ?>
                                    <?php
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
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                            <?php if (!empty($order['customer_phone'])): ?>
                                                <div class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?php echo formatCurrency((float)$order['total_amount']); ?></td>
                                        <td>
                                            <?php if (!empty($order['updated_at'])): ?>
                                                <span class="text-muted"><?php echo formatDateTime($order['updated_at']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $invoiceLink ?: '<span class="text-muted">لا توجد فاتورة</span>'; ?></td>
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
            'name' => $product['name'] ?? '',
            'quantity' => (float)($product['quantity'] ?? 0),
            'unit_price' => (float)($product['unit_price'] ?? 0),
            'unit' => $product['unit'] ?? '',
            'batch_id' => isset($product['batch_id']) ? (int)$product['batch_id'] : null,
            'batch_number' => $product['batch_number'] ?? null,
            'product_type' => $product['product_type'] ?? 'external'
        ];
    }, $availableProducts), JSON_UNESCAPED_UNICODE); ?>;

    const itemsBody = document.getElementById('shippingItemsBody');
    const addItemBtn = document.getElementById('addShippingItemBtn');
    const itemsCountEl = document.getElementById('shippingItemsCount');
    const orderTotalEl = document.getElementById('shippingOrderTotal');
    const submitBtn = document.getElementById('submitShippingOrderBtn');

    if (!itemsBody || !addItemBtn) {
        return;
    }

    if (!Array.isArray(products) || !products.length) {
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        return;
    }

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

    const buildProductOptions = () => {
        return products.map(product => {
            const available = Number(product.quantity || 0).toFixed(2);
            const unitPrice = Number(product.unit_price || 0).toFixed(2);
            const unit = escapeHtml(product.unit || 'وحدة');
            const name = escapeHtml(product.name || '');
            const batchId = product.batch_id || '';
            const productType = product.product_type || 'external';
            return `
                <option value="${product.id}" 
                        data-available="${available}" 
                        data-unit-price="${unitPrice}"
                        data-batch-id="${batchId}"
                        data-product-type="${productType}">
                    ${name} (المتاح: ${available} ${unit})
                </option>
            `;
        }).join('');
    };

    const recalculateTotals = () => {
        const rows = itemsBody.querySelectorAll('tr');
        let totalItems = 0;
        let totalAmount = 0;

        rows.forEach(row => {
            const quantityInput = row.querySelector('input[name$="[quantity]"]');
            const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
            const lineTotalEl = row.querySelector('.line-total');

            const quantity = parseFloat(quantityInput?.value || '0');
            const unitPrice = parseFloat(unitPriceInput?.value || '0');
            const lineTotal = quantity * unitPrice;

            if (quantity > 0) {
                totalItems += quantity;
            }
            totalAmount += lineTotal;

            if (lineTotalEl) {
                lineTotalEl.textContent = formatCurrency(lineTotal);
            }
        });

        if (itemsCountEl) {
            itemsCountEl.textContent = totalItems.toLocaleString('ar-EG', { maximumFractionDigits: 2 });
        }
        if (orderTotalEl) {
            orderTotalEl.textContent = formatCurrency(totalAmount);
        }
    };

    const attachRowEvents = (row) => {
        const productSelect = row.querySelector('select[name$="[product_id]"]');
        const quantityInput = row.querySelector('input[name$="[quantity]"]');
        const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
        const availableBadges = row.querySelectorAll('.available-qty');
        const removeBtn = row.querySelector('.remove-item');

        const updateAvailability = () => {
            const selectedOption = productSelect?.selectedOptions?.[0];
            const available = parseFloat(selectedOption?.dataset?.available || '0');
            const unitPrice = parseFloat(selectedOption?.dataset?.unitPrice || '0');
            const batchId = selectedOption?.dataset?.batchId || '';
            const productType = selectedOption?.dataset?.productType || 'external';

            // تحديث الحقول المخفية
            const batchIdInput = row.querySelector('.batch-id-input');
            const productTypeInput = row.querySelector('.product-type-input');
            if (batchIdInput) {
                batchIdInput.value = batchId;
            }
            if (productTypeInput) {
                productTypeInput.value = productType;
            }

            if (quantityInput) {
                quantityInput.max = available > 0 ? String(available) : '';
                if (available > 0 && parseFloat(quantityInput.value || '0') > available) {
                    quantityInput.value = available;
                }
            }

            if (unitPriceInput && (!unitPriceInput.value || parseFloat(unitPriceInput.value) <= 0)) {
                unitPriceInput.value = unitPrice.toFixed(2);
            }

            if (availableBadges.length) {
                const message = selectedOption && available > 0
                    ? `المتاح: ${available.toLocaleString('ar-EG')} وحدة`
                    : 'لا توجد كمية متاحة';
                availableBadges.forEach((badge) => {
                    badge.textContent = message;
                    badge.classList.toggle('text-danger', !(selectedOption && available > 0));
                });
            }

            recalculateTotals();
        };

        productSelect?.addEventListener('change', updateAvailability);
        quantityInput?.addEventListener('input', recalculateTotals);
        unitPriceInput?.addEventListener('input', recalculateTotals);

        removeBtn?.addEventListener('click', () => {
            if (itemsBody.children.length > 1) {
                row.remove();
                recalculateTotals();
            }
        });

        updateAvailability();
    };

    const addNewRow = () => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <select class="form-select" name="items[${rowIndex}][product_id]" required>
                    <option value="">اختر المنتج</option>
                    ${buildProductOptions()}
                </select>
                <input type="hidden" name="items[${rowIndex}][batch_id]" class="batch-id-input">
                <input type="hidden" name="items[${rowIndex}][product_type]" class="product-type-input">
            </td>
            <td class="text-muted fw-semibold">
                <span class="available-qty d-inline-block">-</span>
            </td>
            <td>
                <input type="number" class="form-control" name="items[${rowIndex}][quantity]" step="1" min="0" value="1" required>
            </td>
            <td>
                <input type="number" class="form-control" name="items[${rowIndex}][unit_price]" step="0.1" min="0" required>
            </td>
            <td class="fw-semibold line-total">${formatCurrency(0)}</td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm remove-item" title="حذف المنتج">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        itemsBody.appendChild(row);
        attachRowEvents(row);
        rowIndex += 1;
        recalculateTotals();
    };

    addItemBtn.addEventListener('click', () => {
        addNewRow();
    });

    addNewRow();

    // إضافة validation للنموذج
    const shippingOrderForm = document.getElementById('shippingOrderForm');
    if (shippingOrderForm) {
        shippingOrderForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // التحقق من شركة الشحن
            const shippingCompanySelect = shippingOrderForm.querySelector('select[name="shipping_company_id"]');
            if (!shippingCompanySelect || !shippingCompanySelect.value || shippingCompanySelect.value === '') {
                alert('يرجى اختيار شركة الشحن');
                shippingCompanySelect?.focus();
                return false;
            }

            // التحقق من العميل
            const customerSelect = shippingOrderForm.querySelector('select[name="customer_id"]');
            if (!customerSelect || !customerSelect.value || customerSelect.value === '') {
                alert('يرجى اختيار العميل');
                customerSelect?.focus();
                return false;
            }

            // التحقق من المنتجات
            const rows = itemsBody.querySelectorAll('tr');
            let hasValidItems = false;
            const validationErrors = [];

            rows.forEach((row, index) => {
                const productSelect = row.querySelector('select[name$="[product_id]"]');
                const quantityInput = row.querySelector('input[name$="[quantity]"]');
                const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');

                const productId = productSelect?.value || '';
                const quantity = parseFloat(quantityInput?.value || '0');
                const unitPrice = parseFloat(unitPriceInput?.value || '0');

                if (productId && productId !== '') {
                    if (quantity <= 0) {
                        validationErrors.push(`المنتج في الصف ${index + 1}: يرجى إدخال كمية صحيحة`);
                        quantityInput?.classList.add('is-invalid');
                    } else {
                        quantityInput?.classList.remove('is-invalid');
                    }

                    if (unitPrice <= 0) {
                        validationErrors.push(`المنتج في الصف ${index + 1}: يرجى إدخال سعر وحدة صحيح`);
                        unitPriceInput?.classList.add('is-invalid');
                    } else {
                        unitPriceInput?.classList.remove('is-invalid');
                    }

                    // التحقق من الكمية المتاحة
                    const selectedOption = productSelect?.selectedOptions?.[0];
                    const available = parseFloat(selectedOption?.dataset?.available || '0');
                    if (quantity > available) {
                        validationErrors.push(`المنتج في الصف ${index + 1}: الكمية المطلوبة (${quantity}) أكبر من المتاح (${available})`);
                        quantityInput?.classList.add('is-invalid');
                    }

                    if (productId && quantity > 0 && unitPrice > 0 && quantity <= available) {
                        hasValidItems = true;
                    }
                }
            });

            if (!hasValidItems) {
                alert('يرجى إضافة منتج واحد على الأقل مع كمية وسعر صحيحين');
                return false;
            }

            if (validationErrors.length > 0) {
                alert('يرجى تصحيح الأخطاء التالية:\n' + validationErrors.join('\n'));
                return false;
            }

            // إذا تم التحقق من كل شيء، أرسل النموذج
            this.submit();
        });
    }
})();
</script>
