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

requireRole('manager');

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
            CONSTRAINT `shipping_company_orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
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
            `quantity` decimal(10,2) NOT NULL,
            `unit_price` decimal(15,2) NOT NULL,
            `total_price` decimal(15,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `order_id` (`order_id`),
            KEY `product_id` (`product_id`),
            CONSTRAINT `shipping_company_order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `shipping_company_orders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `shipping_company_order_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

$shippingPosUrl = getRelativeUrl('manager.php?page=shipping_orders');

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
    $redirectUrl = $shippingPosUrl;

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

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'create_shipping_order') {
        $shippingCompanyId = isset($_POST['shipping_company_id']) ? (int)$_POST['shipping_company_id'] : 0;
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $notes = trim($_POST['order_notes'] ?? '');
        $itemsInput = $_POST['items'] ?? [];

        if ($shippingCompanyId <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار شركة الشحن.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        if ($customerId <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار العميل.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        if (!is_array($itemsInput) || empty($itemsInput)) {
            $_SESSION[$sessionErrorKey] = 'يرجى إضافة منتجات إلى الطلب.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        $normalizedItems = [];
        $totalAmount = 0.0;
        $productIds = [];

        foreach ($itemsInput as $itemRow) {
            if (!is_array($itemRow)) {
                continue;
            }

            $productId = isset($itemRow['product_id']) ? (int)$itemRow['product_id'] : 0;
            $quantity = isset($itemRow['quantity']) ? (float)$itemRow['quantity'] : 0.0;
            $unitPrice = isset($itemRow['unit_price']) ? (float)$itemRow['unit_price'] : 0.0;

            if ($productId <= 0 || $quantity <= 0 || $unitPrice < 0) {
                continue;
            }

            $productIds[] = $productId;
            $lineTotal = round($quantity * $unitPrice, 2);
            $totalAmount += $lineTotal;

            $normalizedItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
            ];
        }

        if (empty($normalizedItems)) {
            $_SESSION[$sessionErrorKey] = 'يرجى التأكد من إدخال بيانات صحيحة للمنتجات.';
            header('Location: ' . $redirectUrl);
            exit;
        }

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

            foreach ($normalizedItems as $normalizedItem) {
                $productId = $normalizedItem['product_id'];
                $requestedQuantity = $normalizedItem['quantity'];

                $productRow = $productsMap[$productId] ?? null;
                if (!$productRow) {
                    throw new InvalidArgumentException('تعذر العثور على منتج من عناصر الطلب.');
                }

                $availableQuantity = (float)($productRow['quantity'] ?? 0);
                if ($availableQuantity < $requestedQuantity) {
                    throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . ($productRow['name'] ?? '') . ' غير كافية.');
                }
            }

            $invoiceItems = [];
            foreach ($normalizedItems as $normalizedItem) {
                $productRow = $productsMap[$normalizedItem['product_id']];
                $invoiceItems[] = [
                    'product_id' => $normalizedItem['product_id'],
                    'description' => $productRow['name'] ?? null,
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
                $productRow = $productsMap[$normalizedItem['product_id']];

                $db->execute(
                    "INSERT INTO shipping_company_order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)",
                    [
                        $orderId,
                        $normalizedItem['product_id'],
                        $normalizedItem['quantity'],
                        $normalizedItem['unit_price'],
                        $normalizedItem['total_price'],
                    ]
                );

                $movementNote = 'تسليم طلب شحن #' . $orderNumber . ' لشركة الشحن';
                $movementResult = recordInventoryMovement(
                    $normalizedItem['product_id'],
                    $mainWarehouse['id'] ?? null,
                    'out',
                    $normalizedItem['quantity'],
                    'shipping_order',
                    $orderId,
                    $movementNote,
                    $currentUser['id'] ?? null
                );

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

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'update_shipping_status') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['in_transit'];

        if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $_SESSION[$sessionErrorKey] = 'طلب غير صالح لتحديث الحالة.';
            header('Location: ' . $redirectUrl);
            exit;
        }

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

            $_SESSION[$sessionSuccessKey] = 'تم تحديث حالة طلب الشحن.';
        } catch (Throwable $statusError) {
            error_log('shipping_orders: update status error -> ' . $statusError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر تحديث حالة الطلب.';
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'complete_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

        if ($orderId <= 0) {
            $_SESSION[$sessionErrorKey] = 'طلب غير صالح لإتمام التسليم.';
            header('Location: ' . $redirectUrl);
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

            $_SESSION[$sessionSuccessKey] = 'تم تأكيد تسليم الطلب للعميل ونقل الدين بنجاح.';
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

        header('Location: ' . $redirectUrl);
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
    $activeCustomers = $db->query(
        "SELECT id, name, phone FROM customers WHERE status = 'active' ORDER BY name ASC"
    );
} catch (Throwable $customersError) {
    error_log('shipping_orders: failed fetching customers -> ' . $customersError->getMessage());
    $activeCustomers = [];
}

$availableProducts = [];
try {
    $availableProducts = $db->query(
        "SELECT id, name, quantity, unit, unit_price FROM products WHERE status = 'active' AND quantity > 0 ORDER BY name ASC"
    );
} catch (Throwable $productsError) {
    error_log('shipping_orders: failed fetching products -> ' . $productsError->getMessage());
    $availableProducts = [];
}

$orders = [];
try {
    $orders = $db->query(
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
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">أحدث طلبات الشحن</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($orders)): ?>
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
                        <?php foreach ($orders as $order): ?>
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
            'unit' => $product['unit'] ?? ''
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
            return `
                <option value="${product.id}" data-available="${available}" data-unit-price="${unitPrice}">
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
            </td>
            <td class="text-muted fw-semibold">
                <span class="available-qty d-inline-block">-</span>
            </td>
            <td>
                <input type="number" class="form-control" name="items[${rowIndex}][quantity]" step="0.01" min="0.01" value="1" required>
            </td>
            <td>
                <input type="number" class="form-control" name="items[${rowIndex}][unit_price]" step="0.01" min="0" required>
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
})();
</script>
