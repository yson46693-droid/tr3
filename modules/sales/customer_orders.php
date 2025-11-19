<?php
/**
 * صفحة إدارة طلبات العملاء
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

$userRole = $currentUser['role'] ?? '';
$isSalesUser = $userRole === 'sales';
$isManagerOrAccountant = in_array($userRole, ['manager', 'accountant'], true);

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'order_number' => $_GET['order_number'] ?? '',
    'status' => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'sales_rep_id' => isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : ''
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_order') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : null;
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $priority = $_POST['priority'] ?? 'normal';
        $notes = trim($_POST['notes'] ?? '');
        $createNewCustomer = isset($_POST['create_new_customer']) && $_POST['create_new_customer'] === '1';
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
        $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');
        
        // معالجة العناصر
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $items[] = [
                        'product_id' => intval($item['product_id']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price']),
                        'total_price' => floatval($item['quantity']) * floatval($item['unit_price'])
                    ];
                }
            }
        }
        
        if (empty($items)) {
            $error = 'يجب إضافة عنصر واحد على الأقل للطلب.';
        } elseif (!$createNewCustomer && $customerId <= 0) {
            $error = 'يجب اختيار العميل أو تحديد خيار العميل الجديد.';
        } elseif ($createNewCustomer && $newCustomerName === '') {
            $error = 'اسم العميل الجديد مطلوب.';
        } else {
            $transactionStarted = false;
            $orderNumber = '';
            $orderId = null;
            $totalAmount = 0.0;
            $newCustomerCreated = false;
            $customerCreatorId = $salesRepId ?: ($currentUser['id'] ?? null);

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                if ($createNewCustomer) {
                    $existingCustomer = null;
                    if ($newCustomerPhone !== '') {
                        $existingCustomer = $db->queryOne(
                            "SELECT id, phone, address, created_by FROM customers WHERE phone = ? LIMIT 1",
                            [$newCustomerPhone]
                        );
                    }
                    if (!$existingCustomer) {
                        $existingCustomer = $db->queryOne(
                            "SELECT id, phone, address, created_by FROM customers WHERE name = ? LIMIT 1",
                            [$newCustomerName]
                        );
                    }

                    if ($existingCustomer) {
                        $customerId = (int)$existingCustomer['id'];
                        $updateFields = [];
                        $updateParams = [];

                        if ($newCustomerPhone !== '' && $newCustomerPhone !== ($existingCustomer['phone'] ?? '')) {
                            $updateFields[] = "phone = ?";
                            $updateParams[] = $newCustomerPhone;
                        }
                        if ($newCustomerAddress !== '' && $newCustomerAddress !== ($existingCustomer['address'] ?? '')) {
                            $updateFields[] = "address = ?";
                            $updateParams[] = $newCustomerAddress;
                        }
                        if ($customerCreatorId && (int)($existingCustomer['created_by'] ?? 0) !== $customerCreatorId) {
                            $updateFields[] = "created_by = ?";
                            $updateParams[] = $customerCreatorId;
                        }

                        if (!empty($updateFields)) {
                            $updateParams[] = $customerId;
                            $db->execute(
                                "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                                $updateParams
                            );
                        }
                    } else {
                        $db->execute(
                            "INSERT INTO customers (name, phone, address, balance, status, created_by) 
                             VALUES (?, ?, ?, 0, 'active', ?)",
                            [
                                $newCustomerName,
                                $newCustomerPhone !== '' ? $newCustomerPhone : null,
                                $newCustomerAddress !== '' ? $newCustomerAddress : null,
                                $customerCreatorId ?? $currentUser['id']
                            ]
                        );
                        $customerId = (int)$db->getLastInsertId();
                        $newCustomerCreated = true;
                    }
                }

                if ($customerId <= 0) {
                    throw new RuntimeException('تعذر تحديد العميل المرتبط بالطلب.');
                }

                $year = date('Y');
                $month = date('m');
                $lastOrder = $db->queryOne(
                    "SELECT order_number FROM customer_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1",
                    ["ORD-{$year}{$month}-%"]
                );

                $serial = 1;
                if ($lastOrder) {
                    $parts = explode('-', $lastOrder['order_number']);
                    $serial = intval($parts[2] ?? 0) + 1;
                }
                $orderNumber = sprintf("ORD-%s%s-%04d", $year, $month, $serial);

                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += $item['total_price'];
                }
                $discountAmount = 0.0;
                $totalAmount = $subtotal;

                $db->execute(
                    "INSERT INTO customer_orders 
                    (order_number, customer_id, sales_rep_id, order_date, delivery_date, 
                     subtotal, discount_amount, total_amount, priority, notes, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [
                        $orderNumber,
                        $customerId,
                        $salesRepId ?? $currentUser['id'],
                        $orderDate,
                        $deliveryDate,
                        $subtotal,
                        $discountAmount,
                        $totalAmount,
                        $priority,
                        $notes,
                        $currentUser['id']
                    ]
                );

                $orderId = (int)$db->getLastInsertId();

                foreach ($items as $item) {
                    $db->execute(
                        "INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) 
                         VALUES (?, ?, ?, ?, ?)",
                        [
                            $orderId,
                            $item['product_id'],
                            $item['quantity'],
                            $item['unit_price'],
                            $item['total_price']
                        ]
                    );
                }

                $db->commit();
                $transactionStarted = false;

                if ($newCustomerCreated) {
                    logAudit($currentUser['id'], 'create_customer_from_order', 'customer', $customerId, null, [
                        'name' => $newCustomerName,
                        'phone' => $newCustomerPhone,
                        'address' => $newCustomerAddress
                    ]);
                }

                notifyManagers(
                    'طلب عميل جديد',
                    "تم إنشاء طلب جديد رقم {$orderNumber} للعميل",
                    'info',
                    "dashboard/sales.php?page=orders&id={$orderId}"
                );

                logAudit($currentUser['id'], 'create_order', 'customer_order', $orderId, null, [
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount
                ]);

                // تطبيق PRG pattern لمنع التكرار
                $successMessage = 'تم إنشاء الطلب بنجاح: ' . $orderNumber;
                preventDuplicateSubmission($successMessage, ['page' => 'orders'], null, $currentUser['role']);
            } catch (Throwable $createOrderError) {
                if ($transactionStarted) {
                    try {
                        $db->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Rollback error: ' . $rollbackError->getMessage());
                    }
                }
                error_log('Create order error: ' . $createOrderError->getMessage());
                $error = 'حدث خطأ أثناء إنشاء الطلب. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'send_sales_order' && $isManagerOrAccountant) {
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $customerAddress = trim($_POST['customer_address'] ?? '');
        $salesRepId = isset($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : 0;
        $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $packageCount = isset($_POST['package_count']) ? floatval($_POST['package_count']) : 0;
        $orderDateRaw = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDateRaw = $_POST['delivery_date'] ?? null;
        $totalAmount = isset($_POST['total_amount']) ? cleanFinancialValue($_POST['total_amount']) : 0;
        $notes = trim($_POST['notes'] ?? '');

        if ($customerName === '' || $salesRepId <= 0 || $productId <= 0 || $packageCount <= 0 || $totalAmount <= 0) {
            $error = 'يرجى إدخال جميع البيانات المطلوبة لإرسال الطلب للمندوب.';
        } else {
            $orderDateObj = DateTime::createFromFormat('Y-m-d', $orderDateRaw);
            $orderDate = $orderDateObj ? $orderDateObj->format('Y-m-d') : date('Y-m-d');

            $deliveryDate = null;
            if (!empty($deliveryDateRaw)) {
                $deliveryDateObj = DateTime::createFromFormat('Y-m-d', $deliveryDateRaw);
                if ($deliveryDateObj) {
                    $deliveryDate = $deliveryDateObj->format('Y-m-d');
                }
            }

            $salesRep = $db->queryOne(
                "SELECT id, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                [$salesRepId]
            );
            if (!$salesRep) {
                $error = 'المندوب المحدد غير متاح.';
            } else {
                $product = $db->queryOne(
                    "SELECT id, name, unit_price FROM products WHERE id = ? AND status = 'active'",
                    [$productId]
                );

                if (!$product) {
                    $error = 'المنتج المحدد غير متاح.';
                } else {
                    $transactionStarted = false;

                    try {
                        $db->beginTransaction();
                        $transactionStarted = true;

                        $customerId = null;
                        if ($customerPhone !== '') {
                            $existingCustomer = $db->queryOne(
                                "SELECT id, created_by FROM customers WHERE phone = ? LIMIT 1",
                                [$customerPhone]
                            );
                        } else {
                            $existingCustomer = $db->queryOne(
                                "SELECT id, created_by FROM customers WHERE name = ? LIMIT 1",
                                [$customerName]
                            );
                        }

                        if ($existingCustomer) {
                            $customerId = (int)$existingCustomer['id'];

                            $updateFields = [];
                            $updateParams = [];

                            if ($customerAddress !== '') {
                                $updateFields[] = "address = ?";
                                $updateParams[] = $customerAddress;
                            }

                            if ($customerPhone !== '') {
                                $updateFields[] = "phone = ?";
                                $updateParams[] = $customerPhone;
                            }

                            if ((int)($existingCustomer['created_by'] ?? 0) !== $salesRepId) {
                                $updateFields[] = "created_by = ?";
                                $updateParams[] = $salesRepId;
                            }

                            if (!empty($updateFields)) {
                                $updateParams[] = $customerId;
                                $db->execute(
                                    "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                                    $updateParams
                                );
                            }
                        } else {
                            $db->execute(
                                "INSERT INTO customers (name, phone, address, balance, status, created_by) 
                                 VALUES (?, ?, ?, 0, 'active', ?)",
                                [
                                    $customerName,
                                    $customerPhone !== '' ? $customerPhone : null,
                                    $customerAddress !== '' ? $customerAddress : null,
                                    $salesRepId
                                ]
                            );
                            $customerId = $db->getLastInsertId();
                        }

                        if (!$customerId) {
                            throw new RuntimeException('تعذر تحديد العميل المرتبط بالطلب.');
                        }

                        $year = date('Y');
                        $month = date('m');
                        $lastOrder = $db->queryOne(
                            "SELECT order_number FROM customer_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1",
                            ["ORD-{$year}{$month}-%"]
                        );

                        $serial = 1;
                        if ($lastOrder) {
                            $parts = explode('-', $lastOrder['order_number']);
                            $serial = intval($parts[2] ?? 0) + 1;
                        }
                        $orderNumber = sprintf("ORD-%s%s-%04d", $year, $month, $serial);

                        $totalAmountValue = (float)$totalAmount;
                        $discountAmount = 0.0;
                        $subtotal = $totalAmountValue;
                        $unitPrice = $packageCount > 0 ? round($totalAmountValue / $packageCount, 2) : $product['unit_price'];

                        $db->execute(
                            "INSERT INTO customer_orders 
                                (order_number, customer_id, sales_rep_id, order_date, delivery_date, subtotal, discount_amount, total_amount, priority, notes, created_by, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'normal', ?, ?, 'pending')",
                            [
                                $orderNumber,
                                $customerId,
                                $salesRepId,
                                $orderDate,
                                $deliveryDate,
                                $subtotal,
                                $discountAmount,
                                $totalAmountValue,
                                $notes,
                                $currentUser['id']
                            ]
                        );

                        $orderId = $db->getLastInsertId();

                        $db->execute(
                            "INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) 
                             VALUES (?, ?, ?, ?, ?)",
                            [
                                $orderId,
                                $productId,
                                $packageCount,
                                $unitPrice,
                                $totalAmountValue
                            ]
                        );

                        logAudit($currentUser['id'], 'assign_order_to_sales', 'customer_order', $orderId, null, [
                            'order_number' => $orderNumber,
                            'sales_rep_id' => $salesRepId,
                            'total_amount' => $totalAmountValue
                        ]);

                        createNotification(
                            $salesRepId,
                            'طلب عميل جديد',
                            "تم إرسال طلب جديد رقم {$orderNumber} إليك.",
                            'info',
                            "dashboard/sales.php?page=orders&id={$orderId}",
                            true
                        );

                        $db->commit();
                        $transactionStarted = false;
                        $success = 'تم إرسال الطلب للمندوب بنجاح: ' . $orderNumber;
                    } catch (Throwable $sendOrderError) {
                        if ($transactionStarted) {
                            $db->rollback();
                        }
                        error_log('Send order to sales error: ' . $sendOrderError->getMessage());
                        $error = 'حدث خطأ أثناء إرسال الطلب للمندوب. يرجى المحاولة مرة أخرى.';
                    }
                }
            }
        }
    } elseif ($action === 'update_status') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($orderId > 0 && !empty($status)) {
            $oldOrder = $db->queryOne("SELECT status FROM customer_orders WHERE id = ?", [$orderId]);
            
            $db->execute(
                "UPDATE customer_orders SET status = ?, updated_at = NOW() WHERE id = ?",
                [$status, $orderId]
            );
            
            logAudit($currentUser['id'], 'update_order_status', 'customer_order', $orderId, 
                     ['old_status' => $oldOrder['status']], 
                     ['new_status' => $status]);
            
            $success = 'تم تحديث حالة الطلب بنجاح';
        }
    }
}

// إذا كان المستخدم مندوب مبيعات، عرض فقط طلباته
if ($isSalesUser) {
    $filters['sales_rep_id'] = $currentUser['id'];
}

// الحصول على الطلبات
$sql = "SELECT o.*, c.name as customer_name, u.full_name as sales_rep_name
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.sales_rep_id = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM customer_orders WHERE 1=1";
$params = [];

if (!empty($filters['customer_id'])) {
    $sql .= " AND o.customer_id = ?";
    $countSql .= " AND customer_id = ?";
    $params[] = $filters['customer_id'];
}

if (!empty($filters['sales_rep_id'])) {
    $sql .= " AND o.sales_rep_id = ?";
    $countSql .= " AND sales_rep_id = ?";
    $params[] = $filters['sales_rep_id'];
}

if (!empty($filters['order_number'])) {
    $sql .= " AND o.order_number LIKE ?";
    $countSql .= " AND order_number LIKE ?";
    $params[] = "%{$filters['order_number']}%";
}

if (!empty($filters['status'])) {
    $sql .= " AND o.status = ?";
    $countSql .= " AND status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['priority'])) {
    $sql .= " AND o.priority = ?";
    $countSql .= " AND priority = ?";
    $params[] = $filters['priority'];
}

if (!empty($filters['date_from'])) {
    $sql .= " AND DATE(o.order_date) >= ?";
    $countSql .= " AND DATE(order_date) >= ?";
    $params[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $sql .= " AND DATE(o.order_date) <= ?";
    $countSql .= " AND DATE(order_date) <= ?";
    $params[] = $filters['date_to'];
}

$totalOrders = $db->queryOne($countSql, $params);
$totalOrders = $totalOrders['total'] ?? 0;
$totalPages = ceil($totalOrders / $perPage);

$sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$orders = $db->query($sql, $params);

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// جلب القوالب من unified_product_templates و product_templates
$templates = [];
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    $unifiedTemplates = $db->query("
        SELECT id, 
               COALESCE(template_name, product_name, CONCAT('قالب #', id)) as name,
               0 as unit_price
        FROM unified_product_templates 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $templates = array_merge($templates, $unifiedTemplates);
}

$productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
if (!empty($productTemplatesCheck)) {
    $productTemplates = $db->query("
        SELECT id, 
               COALESCE(template_name, product_name, CONCAT('قالب #', id)) as name,
               0 as unit_price
        FROM product_templates 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $templates = array_merge($templates, $productTemplates);
}

// استخدام القوالب بدلاً من المنتجات
$products = $templates;
$salesReps = $db->query("SELECT id, username, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY username");
$canSendOrderToRep = !empty($salesReps) && !empty($products);

// طلب محدد للعرض
$selectedOrder = null;
if (isset($_GET['id'])) {
    $orderId = intval($_GET['id']);
    $selectedOrder = $db->queryOne(
        "SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                u.full_name as sales_rep_name
         FROM customer_orders o
         LEFT JOIN customers c ON o.customer_id = c.id
         LEFT JOIN users u ON o.sales_rep_id = u.id
         WHERE o.id = ?",
        [$orderId]
    );
    
    if ($selectedOrder) {
        $selectedOrder['items'] = $db->query(
            "SELECT oi.*, p.name as product_name
             FROM order_items oi
             LEFT JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id = ?
             ORDER BY oi.id",
            [$orderId]
        );
    }
}
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <h2 class="mb-0"><i class="bi bi-cart-check me-2"></i>إدارة طلبات العملاء</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($isManagerOrAccountant): ?>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sendOrderModal" <?php echo $canSendOrderToRep ? '' : 'disabled'; ?>>
                <i class="bi bi-send-check me-2"></i>إرسال أوردر للمندوب
            </button>
        <?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrderModal">
            <i class="bi bi-plus-circle me-2"></i>إنشاء طلب جديد
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

<?php if ($selectedOrder): ?>
    <!-- عرض طلب محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">طلب رقم: <?php echo htmlspecialchars($selectedOrder['order_number']); ?></h5>
            <a href="?page=orders" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">العميل:</th>
                            <td><?php echo htmlspecialchars($selectedOrder['customer_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ الطلب:</th>
                            <td><?php echo formatDate($selectedOrder['order_date']); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ التسليم المطلوب:</th>
                            <td><?php echo $selectedOrder['delivery_date'] ? formatDate($selectedOrder['delivery_date']) : '-'; ?></td>
                        </tr>
                        <tr>
                            <th>مندوب المبيعات:</th>
                            <td><?php echo htmlspecialchars($selectedOrder['sales_rep_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>الأولوية:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedOrder['priority'] === 'urgent' ? 'danger' : 
                                        ($selectedOrder['priority'] === 'high' ? 'warning' : 
                                        ($selectedOrder['priority'] === 'normal' ? 'info' : 'secondary')); 
                                ?>">
                                    <?php 
                                    $priorities = [
                                        'low' => 'منخفضة',
                                        'normal' => 'عادية',
                                        'high' => 'عالية',
                                        'urgent' => 'عاجلة'
                                    ];
                                    echo $priorities[$selectedOrder['priority']] ?? $selectedOrder['priority'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedOrder['status'] === 'delivered' ? 'success' : 
                                        ($selectedOrder['status'] === 'in_production' ? 'info' : 
                                        ($selectedOrder['status'] === 'cancelled' ? 'danger' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'confirmed' => 'مؤكد',
                                        'in_production' => 'قيد الإنتاج',
                                        'ready' => 'جاهز',
                                        'delivered' => 'تم التسليم',
                                        'cancelled' => 'ملغى'
                                    ];
                                    echo $statuses[$selectedOrder['status']] ?? $selectedOrder['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>المجموع الفرعي:</th>
                            <td><?php echo formatCurrency($selectedOrder['subtotal']); ?></td>
                        </tr>
                        <tr>
                            <th>الإجمالي:</th>
                            <td><strong><?php echo formatCurrency($selectedOrder['total_amount']); ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <?php if (!empty($selectedOrder['items'])): ?>
                <h6 class="mt-3">عناصر الطلب:</h6>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--compact align-middle">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>سعر الوحدة</th>
                                <th>الإجمالي</th>
                                <th>حالة الإنتاج</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedOrder['items'] as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                    <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $item['production_status'] === 'completed' ? 'success' : 
                                                ($item['production_status'] === 'in_production' ? 'info' : 'warning'); 
                                        ?>">
                                            <?php 
                                            $prodStatuses = [
                                                'pending' => 'معلق',
                                                'in_production' => 'قيد الإنتاج',
                                                'completed' => 'مكتمل'
                                            ];
                                            echo $prodStatuses[$item['production_status']] ?? $item['production_status'];
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <?php if ($selectedOrder['notes']): ?>
                <div class="mt-3">
                    <h6>ملاحظات:</h6>
                    <p><?php echo nl2br(htmlspecialchars($selectedOrder['notes'])); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="orders">
            <div class="col-md-3">
                <label class="form-label">رقم الطلب</label>
                <input type="text" class="form-control" name="order_number" 
                       value="<?php echo htmlspecialchars($filters['order_number'] ?? ''); ?>" 
                       placeholder="ORD-...">
            </div>
            <div class="col-md-2">
                <label class="form-label">العميل</label>
                <select class="form-select" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($filters['customer_id']) ? intval($filters['customer_id']) : 0;
                    $customerValid = isValidSelectValue($selectedCustomerId, $customers, 'id');
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo $customerValid && $selectedCustomerId == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($isManagerOrAccountant): ?>
            <div class="col-md-2">
                <label class="form-label">المندوب</label>
                <select class="form-select" name="sales_rep_id">
                    <option value="">جميع المناديب</option>
                    <?php 
                    $selectedRepId = isset($filters['sales_rep_id']) ? intval($filters['sales_rep_id']) : 0;
                    $repValid = isValidSelectValue($selectedRepId, $salesReps, 'id');
                    foreach ($salesReps as $rep): ?>
                        <option value="<?php echo $rep['id']; ?>" <?php echo $repValid && $selectedRepId == $rep['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="confirmed" <?php echo ($filters['status'] ?? '') === 'confirmed' ? 'selected' : ''; ?>>مؤكد</option>
                    <option value="in_production" <?php echo ($filters['status'] ?? '') === 'in_production' ? 'selected' : ''; ?>>قيد الإنتاج</option>
                    <option value="ready" <?php echo ($filters['status'] ?? '') === 'ready' ? 'selected' : ''; ?>>جاهز</option>
                    <option value="delivered" <?php echo ($filters['status'] ?? '') === 'delivered' ? 'selected' : ''; ?>>تم التسليم</option>
                    <option value="cancelled" <?php echo ($filters['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>ملغى</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الأولوية</label>
                <select class="form-select" name="priority">
                    <option value="">جميع الأولويات</option>
                    <option value="low" <?php echo ($filters['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                    <option value="normal" <?php echo ($filters['priority'] ?? '') === 'normal' ? 'selected' : ''; ?>>عادية</option>
                    <option value="high" <?php echo ($filters['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>عالية</option>
                    <option value="urgent" <?php echo ($filters['priority'] ?? '') === 'urgent' ? 'selected' : ''; ?>>عاجلة</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
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

<!-- قائمة الطلبات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة الطلبات (<?php echo $totalOrders; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>العميل</th>
                        <th>تاريخ الطلب</th>
                        <th>تاريخ التسليم</th>
                        <th>المبلغ الإجمالي</th>
                        <?php if (!$isSalesUser): ?>
                            <th>المندوب</th>
                        <?php endif; ?>
                        <th>الأولوية</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="<?php echo $isSalesUser ? 8 : 9; ?>" class="text-center text-muted">لا توجد طلبات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <a href="?page=orders&id=<?php echo $order['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($order['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($order['order_date']); ?></td>
                                <td><?php echo $order['delivery_date'] ? formatDate($order['delivery_date']) : '-'; ?></td>
                                <td><?php echo formatCurrency($order['total_amount']); ?></td>
                                <?php if (!$isSalesUser): ?>
                                <td><?php echo htmlspecialchars($order['sales_rep_name'] ?? '-'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $order['priority'] === 'urgent' ? 'danger' : 
                                            ($order['priority'] === 'high' ? 'warning' : 
                                            ($order['priority'] === 'normal' ? 'info' : 'secondary')); 
                                    ?>">
                                        <?php 
                                        $priorities = [
                                            'low' => 'منخفضة',
                                            'normal' => 'عادية',
                                            'high' => 'عالية',
                                            'urgent' => 'عاجلة'
                                        ];
                                        echo $priorities[$order['priority']] ?? $order['priority'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $order['status'] === 'delivered' ? 'success' : 
                                            ($order['status'] === 'in_production' ? 'info' : 
                                            ($order['status'] === 'cancelled' ? 'danger' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'confirmed' => 'مؤكد',
                                            'in_production' => 'قيد الإنتاج',
                                            'ready' => 'جاهز',
                                            'delivered' => 'تم التسليم',
                                            'cancelled' => 'ملغى'
                                        ];
                                        echo $statuses[$order['status']] ?? $order['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="?page=orders&id=<?php echo $order['id']; ?>" 
                                           class="btn btn-info" title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($order['status'] !== 'delivered' && $order['status'] !== 'cancelled'): ?>
                                        <button class="btn btn-warning" 
                                                onclick="showStatusModal(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')"
                                                title="تغيير الحالة">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
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
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=orders&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=orders&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=orders&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=orders&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=orders&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php if ($isManagerOrAccountant): ?>
<!-- Modal إرسال أوردر للمندوب -->
<div class="modal fade" id="sendOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send-check me-2"></i>إرسال أوردر للمندوب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="send_sales_order">
                <div class="modal-body">
                    <?php if (!$canSendOrderToRep): ?>
                        <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                            <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                            <div>لا يمكن إرسال طلب حالياً. تأكد من وجود منتجات نشطة ومناديب مبيعات نشطين.</div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم الهاتف</label>
                                <input type="text" class="form-control" name="customer_phone" placeholder="مثال: 01234567890">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">عنوان العميل</label>
                            <textarea class="form-control" name="customer_address" rows="2" placeholder="اكتب العنوان بالتفصيل"></textarea>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">المندوب المستلم <span class="text-danger">*</span></label>
                                <select class="form-select" name="sales_rep_id" required>
                                    <option value="">اختر المندوب</option>
                                    <?php foreach ($salesReps as $rep): ?>
                                        <option value="<?php echo $rep['id']; ?>">
                                            <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المنتج المطلوب <span class="text-danger">*</span></label>
                                <select class="form-select" name="product_id" required>
                                    <option value="">اختر المنتج</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo $product['id']; ?>">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-4">
                                <label class="form-label">عدد العبوات <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="package_count" min="1" step="1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">تاريخ استلام الطلب <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">تاريخ التسليم للعميل</label>
                                <input type="date" class="form-control" name="delivery_date">
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">إجمالي المبلغ <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="total_amount" min="0.01" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ملاحظات إضافية</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="معلومات إضافية للمندوب (اختياري)"></textarea>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" <?php echo $canSendOrderToRep ? '' : 'disabled'; ?>>إرسال الطلب</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal إنشاء طلب -->
<div class="modal fade" id="addOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء طلب جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="orderForm">
                <input type="hidden" name="action" value="create_order">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="toggleNewCustomer" name="create_new_customer" value="1">
                                    <label class="form-check-label small" for="toggleNewCustomer">عميل جديد</label>
                                </div>
                            </div>
                            <select class="form-select" name="customer_id" id="existingCustomerSelect" required>
                                <option value="">اختر العميل</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">مندوب المبيعات</label>
                            <select class="form-select" name="sales_rep_id">
                                <option value="">اختر مندوب</option>
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo $rep['id'] == $currentUser['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ الطلب <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ التسليم</label>
                            <input type="date" class="form-control" name="delivery_date">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal">عادية</option>
                                <option value="low">منخفضة</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="newCustomerFields" class="row g-3 mb-3 d-none">
                        <div class="col-md-4">
                            <label class="form-label">اسم العميل الجديد <span class="text-danger">*</span></label>
                            <input type="text" class="form-control new-customer-required" name="new_customer_name" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="text" class="form-control" name="new_customer_phone" autocomplete="off" placeholder="مثال: 01234567890">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">عنوان العميل</label>
                            <textarea class="form-control" name="new_customer_address" rows="2" autocomplete="off" placeholder="اكتب العنوان بالتفصيل"></textarea>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الطلب</label>
                        <div id="orderItems">
                            <div class="order-item row mb-2">
                                <div class="col-md-5">
                                    <select class="form-select product-select" name="items[0][product_id]" required>
                                        <option value="">اختر القالب</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>" 
                                                    data-price="<?php echo $product['unit_price']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" step="0.01" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control unit-price" 
                                           name="items[0][unit_price]" placeholder="السعر" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <div class="d-flex">
                                        <input type="text" class="form-control item-total" readonly placeholder="الإجمالي">
                                        <button type="button" class="btn btn-danger ms-2 remove-item">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <h5>الإجمالي:</h5>
                                        <h5 id="totalAmount">0.00 ج.م</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء طلب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تغيير الحالة -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغيير حالة الطلب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" id="statusOrderId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status" id="statusSelect" required>
                            <option value="pending">معلق</option>
                            <option value="confirmed">مؤكد</option>
                            <option value="in_production">قيد الإنتاج</option>
                            <option value="ready">جاهز</option>
                            <option value="delivered">تم التسليم</option>
                            <option value="cancelled">ملغى</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let itemIndex = 1;

// إضافة عنصر جديد
document.getElementById('addItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    newItem.innerHTML = `
        <div class="col-md-5">
            <select class="form-select product-select" name="items[${itemIndex}][product_id]" required>
                <option value="">اختر القالب</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo $product['id']; ?>" 
                            data-price="<?php echo $product['unit_price']; ?>">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" class="form-control quantity" 
                   name="items[${itemIndex}][quantity]" placeholder="الكمية" required min="0.01">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" class="form-control unit-price" 
                   name="items[${itemIndex}][unit_price]" placeholder="السعر" required min="0.01">
        </div>
        <div class="col-md-2">
            <div class="d-flex">
                <input type="text" class="form-control item-total" readonly placeholder="الإجمالي">
                <button type="button" class="btn btn-danger ms-2 remove-item">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    itemIndex++;
    attachItemEvents(newItem);
});

// حذف عنصر
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        e.target.closest('.order-item').remove();
        calculateOrderTotal();
    }
});

const newCustomerToggle = document.getElementById('toggleNewCustomer');
const existingCustomerSelect = document.getElementById('existingCustomerSelect');
const newCustomerFields = document.getElementById('newCustomerFields');
const newCustomerRequiredInputs = Array.from(document.querySelectorAll('.new-customer-required'));

function updateNewCustomerState() {
    if (!newCustomerToggle || !existingCustomerSelect || !newCustomerFields) {
        return;
    }

    if (newCustomerToggle.checked) {
        newCustomerFields.classList.remove('d-none');
        existingCustomerSelect.value = '';
        existingCustomerSelect.setAttribute('disabled', 'disabled');
        existingCustomerSelect.removeAttribute('required');
        newCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
    } else {
        newCustomerFields.classList.add('d-none');
        existingCustomerSelect.removeAttribute('disabled');
        existingCustomerSelect.setAttribute('required', 'required');
        newCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
    }
}

if (newCustomerToggle) {
    newCustomerToggle.addEventListener('change', updateNewCustomerState);
    updateNewCustomerState();
}

const addOrderModalElement = document.getElementById('addOrderModal');
if (addOrderModalElement && typeof bootstrap !== 'undefined') {
    addOrderModalElement.addEventListener('hidden.bs.modal', function() {
        if (!newCustomerToggle) {
            return;
        }
        newCustomerToggle.checked = false;
        updateNewCustomerState();
        newCustomerRequiredInputs.forEach(function(input) {
            input.value = '';
        });
        const newCustomerPhoneInput = document.querySelector('input[name="new_customer_phone"]');
        const newCustomerAddressInput = document.querySelector('textarea[name="new_customer_address"]');
        if (newCustomerPhoneInput) {
            newCustomerPhoneInput.value = '';
        }
        if (newCustomerAddressInput) {
            newCustomerAddressInput.value = '';
        }
    });
}

// ربط أحداث العناصر
function attachItemEvents(item) {
    const productSelect = item.querySelector('.product-select');
    const quantityInput = item.querySelector('.quantity');
    const unitPriceInput = item.querySelector('.unit-price');
    const itemTotal = item.querySelector('.item-total');
    
    productSelect?.addEventListener('change', function() {
        const price = this.options[this.selectedIndex].dataset.price;
        if (price) {
            unitPriceInput.value = price;
            calculateItemTotal(item);
            calculateOrderTotal();
        }
    });
    
    [quantityInput, unitPriceInput].forEach(input => {
        input?.addEventListener('input', function() {
            calculateItemTotal(item);
            calculateOrderTotal();
        });
    });
}

function calculateItemTotal(item) {
    const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    const total = quantity * unitPrice;
    item.querySelector('.item-total').value = total.toFixed(2);
}

function calculateOrderTotal() {
    const form = document.getElementById('orderForm');
    if (!form) return;
    
    let total = 0;
    document.querySelectorAll('.item-total').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    
    document.getElementById('totalAmount').textContent = total.toFixed(2) + ' ج.م';
}

// ربط الأحداث للعناصر الموجودة
document.querySelectorAll('.order-item').forEach(item => {
    attachItemEvents(item);
});

function showStatusModal(orderId, currentStatus) {
    document.getElementById('statusOrderId').value = orderId;
    document.getElementById('statusSelect').value = currentStatus;
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
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

