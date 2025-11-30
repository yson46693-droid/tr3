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

// دالة مساعدة للحصول على اسم جدول عناصر الطلب
function getOrderItemsTableName($db) {
    $tableNames = ['order_items', 'customer_order_items'];
    foreach ($tableNames as $tableName) {
        // استخدام escape للاسم الآمن
        $escapedTableName = $db->escape($tableName);
        $tableCheck = $db->queryOne("SHOW TABLES LIKE '{$escapedTableName}'");
        if (!empty($tableCheck)) {
            return $tableName;
        }
    }
    // إذا لم يكن الجدول موجوداً، نعيد اسم الجدول الافتراضي
    return 'order_items';
}

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

$userRole = $currentUser['role'] ?? '';
$isSalesUser = $userRole === 'sales';
$isManagerOrAccountant = in_array($userRole, ['manager', 'accountant'], true);

// التحقق من وجود عمود order_type وإضافته إذا لم يكن موجوداً
try {
    $hasOrderTypeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customer_orders LIKE 'order_type'"));
    
    if (!$hasOrderTypeColumn) {
        $db->execute("ALTER TABLE customer_orders ADD COLUMN order_type ENUM('sales_rep', 'company') DEFAULT 'sales_rep' AFTER sales_rep_id");
        error_log('Added order_type column to customer_orders table');
    }
} catch (Throwable $e) {
    error_log('Error checking/adding order_type column: ' . $e->getMessage());
}

// التحقق من وجود عمود order_type وإضافته إذا لم يكن موجوداً
try {
    $hasOrderTypeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customer_orders LIKE 'order_type'"));
    
    if (!$hasOrderTypeColumn) {
        $db->execute("ALTER TABLE customer_orders ADD COLUMN order_type ENUM('sales_rep', 'company') DEFAULT 'sales_rep' AFTER sales_rep_id");
        error_log('Added order_type column to customer_orders table');
    }
} catch (Throwable $e) {
    error_log('Error checking/adding order_type column: ' . $e->getMessage());
}

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

// معالجة طلبات AJAX لجلب عملاء المندوب
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_customers' && isset($_GET['sales_rep_id'])) {
    $salesRepId = intval($_GET['sales_rep_id']);
    
    if ($salesRepId > 0) {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        
        $customers = $db->query(
            "SELECT id, name FROM customers WHERE (created_by = ? OR rep_id = ?) AND status = 'active' ORDER BY name ASC",
            [$salesRepId, $salesRepId]
        );
        
        echo json_encode([
            'success' => true,
            'customers' => $customers
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'معرف المندوب غير صحيح'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_order') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        // إذا كان المستخدم مندوب مبيعات، استخدم معرفه مباشرة
        if ($isSalesUser) {
            $salesRepId = $currentUser['id'];
        } else {
            $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : null;
        }
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $priority = $_POST['priority'] ?? 'normal';
        $notes = '';
        $createNewCustomer = isset($_POST['create_new_customer']) && $_POST['create_new_customer'] === '1';
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
        $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');
        
        // معالجة العناصر - الآن template_name بدلاً من product_id
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $templateName = trim($item['template_name'] ?? '');
                $quantity = floatval($item['quantity'] ?? 0);
                if (!empty($templateName) && $quantity > 0) {
                    $items[] = [
                        'template_name' => $templateName,
                        'quantity' => $quantity,
                        'unit_price' => 0.0,
                        'total_price' => 0.0
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
                        
                        // تحديث الموقع إذا كان موجوداً
                        $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                        $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                        $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                        
                        if ($hasLatitudeColumn && $newCustomerLatitude !== null) {
                            $updateFields[] = "latitude = ?";
                            $updateParams[] = (float)$newCustomerLatitude;
                        }
                        if ($hasLongitudeColumn && $newCustomerLongitude !== null) {
                            $updateFields[] = "longitude = ?";
                            $updateParams[] = (float)$newCustomerLongitude;
                        }
                        if ($hasLocationCapturedAtColumn && $newCustomerLatitude !== null && $newCustomerLongitude !== null) {
                            $updateFields[] = "location_captured_at = NOW()";
                        }

                        if (!empty($updateFields)) {
                            $updateParams[] = $customerId;
                            $db->execute(
                                "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                                $updateParams
                            );
                        }
                    } else {
                        $newCustomerCreator = $customerCreatorId ?? $currentUser['id'];
                        $newCustomerRepId = $salesRepId ?? ($isSalesUser ? $currentUser['id'] : null);
                        $createdByAdminFlag = ($isSalesUser && $newCustomerRepId) ? 0 : 1;

                        // التحقق من وجود أعمدة اللوكيشن
                        $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                        $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                        $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                        
                        $customerColumns = ['name', 'phone', 'address', 'balance', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                        $customerValues = [
                            $newCustomerName,
                            $newCustomerPhone !== '' ? $newCustomerPhone : null,
                            $newCustomerAddress !== '' ? $newCustomerAddress : null,
                            0,
                            'active',
                            $newCustomerCreator,
                            $newCustomerRepId,
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

                $subtotal = 0.0;
                $discountAmount = 0.0;
                $totalAmount = 0.0;

                // تحديد نوع الطلب
                $orderType = 'sales_rep'; // طلبات المناديب
                
                $db->execute(
                    "INSERT INTO customer_orders 
                    (order_number, customer_id, sales_rep_id, order_type, order_date, delivery_date, 
                     subtotal, discount_amount, total_amount, priority, notes, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [
                        $orderNumber,
                        $customerId,
                        $salesRepId ?? $currentUser['id'],
                        $orderType,
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

                if ($orderId <= 0) {
                    throw new RuntimeException('فشل إنشاء الطلب: لم يتم الحصول على معرف الطلب.');
                }

                // التحقق من وجود الجدول وإنشائه إذا لم يكن موجوداً
                $orderItemsTable = null;
                $tableNames = ['order_items', 'customer_order_items'];
                foreach ($tableNames as $tableName) {
                    // استخدام escape للاسم الآمن
                    $escapedTableName = $db->escape($tableName);
                    $tableCheck = $db->queryOne("SHOW TABLES LIKE '{$escapedTableName}'");
                    if (!empty($tableCheck)) {
                        $orderItemsTable = $tableName;
                        break;
                    }
                }
                
                // إذا لم يكن الجدول موجوداً، إنشاؤه
                if (empty($orderItemsTable)) {
                    $orderItemsTable = 'order_items';
                    try {
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `order_items` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `order_id` int(11) NOT NULL,
                              `product_id` int(11) NULL,
                              `template_id` int(11) NULL,
                              `quantity` decimal(10,2) NOT NULL,
                              `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
                              `production_status` enum('pending','in_production','completed') DEFAULT 'pending',
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `order_id` (`order_id`),
                              KEY `product_id` (`product_id`),
                              KEY `template_id` (`template_id`),
                              CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `customer_orders` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                        error_log('Created order_items table successfully');
                    } catch (Throwable $createError) {
                        error_log('Error creating order_items table: ' . $createError->getMessage());
                        throw new RuntimeException('فشل إنشاء جدول عناصر الطلب: ' . $createError->getMessage());
                    }
                }

                // التحقق من وجود عمود template_id وإضافته إذا لم يكن موجوداً
                try {
                    $templateIdColumn = $db->queryOne("SHOW COLUMNS FROM {$orderItemsTable} LIKE 'template_id'");
                    if (empty($templateIdColumn)) {
                        $db->execute("ALTER TABLE {$orderItemsTable} ADD COLUMN template_id INT(11) NULL AFTER product_id");
                    }
                } catch (Throwable $alterError) {
                    error_log('Error checking/adding template_id column: ' . $alterError->getMessage());
                }
                
                // محاولة تعديل product_id ليكون NULL إذا كان NOT NULL
                try {
                    $productIdColumn = $db->queryOne("SHOW COLUMNS FROM {$orderItemsTable} WHERE Field = 'product_id'");
                    if (!empty($productIdColumn) && isset($productIdColumn['Null']) && $productIdColumn['Null'] === 'NO') {
                        // محاولة إزالة Foreign Key constraint أولاً
                        try {
                            $db->execute("ALTER TABLE {$orderItemsTable} DROP FOREIGN KEY order_items_ibfk_2");
                        } catch (Throwable $fkError) {
                            // قد يكون اسم الـ constraint مختلفاً أو غير موجود
                            error_log('Error dropping foreign key (may not exist): ' . $fkError->getMessage());
                        }
                        // تعديل product_id ليكون NULL
                        $db->execute("ALTER TABLE {$orderItemsTable} MODIFY COLUMN product_id INT(11) NULL");
                    }
                } catch (Throwable $modifyError) {
                    error_log('Error modifying product_id column: ' . $modifyError->getMessage());
                }

                foreach ($items as $item) {
                    $templateName = $item['template_name'];
                    
                    // البحث عن القالب بالاسم في unified_product_templates أولاً
                    $templateId = null;
                    $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                    if (!empty($unifiedCheck)) {
                        $template = $db->queryOne(
                            "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                            [$templateName, $templateName]
                        );
                        if ($template) {
                            $templateId = (int)$template['id'];
                        }
                    }
                    
                    // إذا لم يُعثر عليه، البحث في product_templates
                    if (!$templateId) {
                        $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                        if (!empty($productTemplatesCheck)) {
                            $template = $db->queryOne(
                                "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                [$templateName, $templateName]
                            );
                            if ($template) {
                                $templateId = (int)$template['id'];
                            }
                        }
                    }
                    
                    // إدراج العنصر - إذا لم يُعثر على القالب، نستخدم NULL للـ template_id ونحفظ الاسم في notes أو نستخدم product_id = NULL
                    try {
                        // محاولة إدراج مع template_id إذا وُجد
                        if ($templateId) {
                            $db->execute(
                                "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                 VALUES (?, NULL, ?, ?, ?, ?)",
                                [
                                    $orderId,
                                    $templateId,
                                    $item['quantity'],
                                    $item['unit_price'],
                                    $item['total_price']
                                ]
                            );
                        } else {
                            // إذا لم يُعثر على القالب، إدراج بدون template_id (سيتم حفظ الاسم في template_name لاحقاً إذا كان هناك عمود لذلك)
                            // أو يمكننا إضافة عمود template_name للجدول
                            try {
                                $db->execute(
                                    "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                     VALUES (?, NULL, NULL, ?, ?, ?)",
                                    [
                                        $orderId,
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['total_price']
                                    ]
                                );
                            } catch (Throwable $insertError) {
                                // إذا فشل لأن template_id غير موجود، نجرب بدون template_id
                                if (stripos($insertError->getMessage(), 'template_id') !== false || 
                                    stripos($insertError->getMessage(), 'Unknown column') !== false) {
                                    $db->execute(
                                        "INSERT INTO {$orderItemsTable} (order_id, product_id, quantity, unit_price, total_price) 
                                         VALUES (?, NULL, ?, ?, ?)",
                                        [
                                            $orderId,
                                            $item['quantity'],
                                            $item['unit_price'],
                                            $item['total_price']
                                        ]
                                    );
                                } else {
                                    throw $insertError;
                                }
                            }
                        }
                    } catch (Throwable $insertError) {
                        error_log('Error inserting order item: ' . $insertError->getMessage());
                        throw $insertError;
                    }
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
                error_log('Create order error trace: ' . $createOrderError->getTraceAsString());
                error_log('Create order POST data: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
                $error = 'حدث خطأ أثناء إنشاء الطلب: ' . $createOrderError->getMessage();
            }
        }
    } elseif ($action === 'create_company_order' && $isManagerOrAccountant) {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $priority = $_POST['priority'] ?? 'normal';
        $notes = '';
        $createNewCustomer = isset($_POST['create_new_customer']) && $_POST['create_new_customer'] === '1';
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
        $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');
        
        // معالجة العناصر - الآن template_name بدلاً من product_id
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $templateName = trim($item['template_name'] ?? '');
                $quantity = floatval($item['quantity'] ?? 0);
                if (!empty($templateName) && $quantity > 0) {
                    $items[] = [
                        'template_name' => $templateName,
                        'quantity' => $quantity,
                        'unit_price' => 0.0,
                        'total_price' => 0.0
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
                        
                        // تحديث الموقع إذا كان موجوداً
                        $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                        $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                        $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                        
                        if ($hasLatitudeColumn && $newCustomerLatitude !== null) {
                            $updateFields[] = "latitude = ?";
                            $updateParams[] = (float)$newCustomerLatitude;
                        }
                        if ($hasLongitudeColumn && $newCustomerLongitude !== null) {
                            $updateFields[] = "longitude = ?";
                            $updateParams[] = (float)$newCustomerLongitude;
                        }
                        if ($hasLocationCapturedAtColumn && $newCustomerLatitude !== null && $newCustomerLongitude !== null) {
                            $updateFields[] = "location_captured_at = NOW()";
                        }

                        if (!empty($updateFields)) {
                            $updateParams[] = $customerId;
                            $db->execute(
                                "UPDATE customers SET " . implode(', ', $updateFields) . " WHERE id = ?",
                                $updateParams
                            );
                        }
                    } else {
                        // إنشاء عميل جديد للشركة (بدون مندوب)
                        // التحقق من وجود أعمدة اللوكيشن
                        $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                        $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                        $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                        
                        $customerColumns = ['name', 'phone', 'address', 'balance', 'status', 'created_by', 'created_by_admin'];
                        $customerValues = [
                            $newCustomerName,
                            $newCustomerPhone !== '' ? $newCustomerPhone : null,
                            $newCustomerAddress !== '' ? $newCustomerAddress : null,
                            0,
                            'active',
                            $currentUser['id'],
                            1
                        ];
                        $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?'];
                        
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
                    ["CMP-{$year}{$month}-%"]
                );

                $serial = 1;
                if ($lastOrder) {
                    $parts = explode('-', $lastOrder['order_number']);
                    $serial = intval($parts[2] ?? 0) + 1;
                }
                $orderNumber = sprintf("CMP-%s%s-%04d", $year, $month, $serial);

                $subtotal = 0.0;
                $discountAmount = 0.0;
                $totalAmount = 0.0;

                // إنشاء طلب الشركة (بدون sales_rep_id و order_type = 'company')
                $db->execute(
                    "INSERT INTO customer_orders 
                    (order_number, customer_id, sales_rep_id, order_type, order_date, delivery_date, 
                     subtotal, discount_amount, total_amount, priority, notes, created_by, status) 
                    VALUES (?, ?, NULL, 'company', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [
                        $orderNumber,
                        $customerId,
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

                if ($orderId <= 0) {
                    throw new RuntimeException('فشل إنشاء الطلب: لم يتم الحصول على معرف الطلب.');
                }

                // إضافة العناصر
                $orderItemsTable = getOrderItemsTableName($db);
                foreach ($items as $item) {
                    $templateName = $item['template_name'];
                    
                    // البحث عن القالب بالاسم في unified_product_templates أولاً
                    $templateId = null;
                    $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                    if (!empty($unifiedCheck)) {
                        $template = $db->queryOne(
                            "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                            [$templateName, $templateName]
                        );
                        if ($template) {
                            $templateId = (int)$template['id'];
                        }
                    }
                    
                    // إذا لم يُعثر عليه، البحث في product_templates
                    if (!$templateId) {
                        $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                        if (!empty($productTemplatesCheck)) {
                            $template = $db->queryOne(
                                "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                [$templateName, $templateName]
                            );
                            if ($template) {
                                $templateId = (int)$template['id'];
                            }
                        }
                    }
                    
                    // إدراج العنصر
                    try {
                        if ($templateId) {
                            $db->execute(
                                "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                 VALUES (?, NULL, ?, ?, ?, ?)",
                                [
                                    $orderId,
                                    $templateId,
                                    $item['quantity'],
                                    $item['unit_price'],
                                    $item['total_price']
                                ]
                            );
                        } else {
                            try {
                                $db->execute(
                                    "INSERT INTO {$orderItemsTable} (order_id, product_id, template_id, quantity, unit_price, total_price) 
                                     VALUES (?, NULL, NULL, ?, ?, ?)",
                                    [
                                        $orderId,
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['total_price']
                                    ]
                                );
                            } catch (Throwable $insertError) {
                                if (stripos($insertError->getMessage(), 'template_id') !== false || 
                                    stripos($insertError->getMessage(), 'Unknown column') !== false) {
                                    $db->execute(
                                        "INSERT INTO {$orderItemsTable} (order_id, product_id, quantity, unit_price, total_price) 
                                         VALUES (?, NULL, ?, ?, ?)",
                                        [
                                            $orderId,
                                            $item['quantity'],
                                            $item['unit_price'],
                                            $item['total_price']
                                        ]
                                    );
                                } else {
                                    throw $insertError;
                                }
                            }
                        }
                    } catch (Throwable $insertError) {
                        error_log('Error inserting company order item: ' . $insertError->getMessage());
                        throw $insertError;
                    }
                }

                if ($newCustomerCreated) {
                    logAudit($currentUser['id'], 'create_customer_from_order', 'customer', $customerId, null, [
                        'name' => $newCustomerName,
                        'phone' => $newCustomerPhone,
                        'address' => $newCustomerAddress,
                        'order_type' => 'company'
                    ]);
                }

                logAudit($currentUser['id'], 'create_company_order', 'customer_order', $orderId, null, [
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount
                ]);

                $db->commit();
                $transactionStarted = false;
                $success = 'تم إنشاء طلب الشركة بنجاح: ' . $orderNumber;
                preventDuplicateSubmission($success, ['page' => 'orders'], null, $currentUser['role']);
            } catch (Throwable $createOrderError) {
                if ($transactionStarted) {
                    try {
                        $db->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Rollback error: ' . $rollbackError->getMessage());
                    }
                }
                error_log('Create company order error: ' . $createOrderError->getMessage());
                $error = 'حدث خطأ أثناء إنشاء طلب الشركة: ' . $createOrderError->getMessage();
            }
        }
    } elseif ($action === 'update_status') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($orderId > 0 && !empty($status)) {
            // التحقق من صلاحيات المندوب
            if ($isSalesUser) {
                // المندوب يمكنه فقط تغيير الحالة إلى "تم التسليم" أو "ملغى"
                $allowedStatuses = ['delivered', 'cancelled'];
                if (!in_array($status, $allowedStatuses, true)) {
                    $error = 'غير مصرح لك بتغيير الحالة إلى: ' . $status;
                } else {
                    // التحقق من أن الطلب يخص هذا المندوب
                    $order = $db->queryOne(
                        "SELECT id, sales_rep_id FROM customer_orders WHERE id = ?",
                        [$orderId]
                    );
                    
                    if (!$order) {
                        $error = 'الطلب غير موجود.';
                    } elseif ((int)($order['sales_rep_id'] ?? 0) !== (int)$currentUser['id']) {
                        $error = 'غير مصرح لك بتعديل هذا الطلب.';
                    } else {
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
            } else {
                // المدير والمحاسب يمكنهم تغيير الحالة إلى أي حالة
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
}

// إذا كان المستخدم مندوب مبيعات، عرض فقط طلباته (ولا تظهر طلبات الشركة)
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

// إخفاء طلبات الشركة عن المناديب (فقط المدير والمحاسب يرونها)
if ($isSalesUser) {
    $sql .= " AND (o.order_type IS NULL OR o.order_type = 'sales_rep')";
    $countSql .= " AND (order_type IS NULL OR order_type = 'sales_rep')";
}

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

// جلب عملاء الشركة (الذين ليس لديهم مندوب أو تم إنشاؤهم بواسطة المدير)
$companyCustomers = [];
try {
    // التحقق من وجود عمود created_by_admin
    $hasCreatedByAdmin = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'created_by_admin'"));
    $hasRepId = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'rep_id'"));
    
    if ($hasCreatedByAdmin && $hasRepId) {
        $companyCustomers = $db->query(
            "SELECT id, name FROM customers 
             WHERE status = 'active' 
             AND (created_by_admin = 1 OR rep_id IS NULL OR created_by IN (SELECT id FROM users WHERE role IN ('manager', 'accountant')))
             ORDER BY name"
        );
    } else {
        // إذا لم تكن الأعمدة موجودة، جلب جميع العملاء النشطين
        $companyCustomers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
    }
} catch (Throwable $e) {
    error_log('Error fetching company customers: ' . $e->getMessage());
    $companyCustomers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
}

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// جلب القوالب من unified_product_templates و product_templates
$templates = [];
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    $unifiedTemplates = $db->query("
        SELECT id, 
               COALESCE(product_name, CONCAT('قالب #', id)) as name,
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
               COALESCE(product_name, CONCAT('قالب #', id)) as name,
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
        $orderItemsTable = getOrderItemsTableName($db);
        
        // جلب العناصر مع اسم القالب
        $items = $db->query(
            "SELECT oi.*, oi.template_id
             FROM {$orderItemsTable} oi
             WHERE oi.order_id = ?
             ORDER BY oi.id",
            [$orderId]
        );
        
        // جلب أسماء القوالب
        foreach ($items as &$item) {
            $templateName = '-';
            if (!empty($item['template_id'])) {
                // البحث في unified_product_templates
                $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                if (!empty($unifiedCheck)) {
                    $template = $db->queryOne(
                        "SELECT COALESCE(product_name, CONCAT('قالب #', id)) as name 
                         FROM unified_product_templates 
                         WHERE id = ?",
                        [$item['template_id']]
                    );
                    if ($template) {
                        $templateName = $template['name'];
                    }
                }
                
                // إذا لم يُعثر عليه، البحث في product_templates
                if ($templateName === '-') {
                    $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                    if (!empty($productTemplatesCheck)) {
                        $template = $db->queryOne(
                            "SELECT COALESCE(product_name, CONCAT('قالب #', id)) as name 
                             FROM product_templates 
                             WHERE id = ?",
                            [$item['template_id']]
                        );
                        if ($template) {
                            $templateName = $template['name'];
                        }
                    }
                }
            }
            $item['product_name'] = $templateName;
            // التأكد من وجود production_status
            if (!isset($item['production_status'])) {
                $item['production_status'] = 'pending';
            }
        }
        unset($item);
        
        $selectedOrder['items'] = $items;
    }
}
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <h2 class="mb-0"><i class="bi bi-cart-check me-2"></i>إدارة طلبات العملاء</h2>
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrderModal">
            <i class="bi bi-plus-circle me-2"></i>طلب عميل مندوب
        </button>
        <?php if ($isManagerOrAccountant): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCompanyOrderModal">
                <i class="bi bi-building me-2"></i>طلب عميل شركة
            </button>
        <?php endif; ?>
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
                            <th>رقم العميل:</th>
                            <td><?php echo htmlspecialchars($selectedOrder['customer_phone'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>عنوان العميل:</th>
                            <td><?php echo htmlspecialchars($selectedOrder['customer_address'] ?? '-'); ?></td>
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
                                <th>حالة الإنتاج</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedOrder['items'] as $item): ?>
                                <tr>
                                    <td data-label="المنتج"><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                    <td data-label="الكمية"><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td data-label="حالة الإنتاج">
                                        <span class="badge bg-<?php 
                                            $productionStatus = $item['production_status'] ?? 'pending';
                                            echo $productionStatus === 'completed' ? 'success' : 
                                                ($productionStatus === 'in_production' ? 'info' : 'warning'); 
                                        ?>">
                                            <?php 
                                            $productionStatuses = [
                                                'pending' => 'معلق',
                                                'in_production' => 'قيد الإنتاج',
                                                'completed' => 'مكتمل'
                                            ];
                                            echo $productionStatuses[$productionStatus] ?? $productionStatus;
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
                        <?php if (!$isSalesUser): ?>
                            <th>المندوب</th>
                            <th>النوع</th>
                        <?php endif; ?>
                        <th>الأولوية</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="<?php echo $isSalesUser ? 7 : 9; ?>" class="text-center text-muted">لا توجد طلبات</td>
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
                                <?php if (!$isSalesUser): ?>
                                <td><?php echo htmlspecialchars($order['sales_rep_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if (isset($order['order_type']) && $order['order_type'] === 'company'): ?>
                                        <span class="badge bg-success">شركة</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">مندوب</span>
                                    <?php endif; ?>
                                </td>
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
                <?php if ($isSalesUser): ?>
                    <input type="hidden" name="sales_rep_id" value="<?php echo $currentUser['id']; ?>">
                <?php endif; ?>
                <div class="modal-body">
                    <div class="row mb-3">
                        <?php if (!$isSalesUser): ?>
                        <div class="col-md-3">
                            <label class="form-label">مندوب المبيعات <span class="text-danger">*</span></label>
                            <select class="form-select" name="sales_rep_id" id="salesRepSelect" required>
                                <option value="">اختر مندوب</option>
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo $rep['id'] == $currentUser['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-<?php echo $isSalesUser ? '5' : '4'; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="toggleNewCustomer" name="create_new_customer" value="1">
                                    <label class="form-check-label small" for="toggleNewCustomer">عميل جديد</label>
                                </div>
                            </div>
                            <select class="form-select" name="customer_id" id="existingCustomerSelect" <?php echo $isSalesUser ? '' : 'disabled'; ?> required>
                                <?php if ($isSalesUser): ?>
                                    <option value="">اختر العميل</option>
                                    <?php 
                                    // جلب عملاء المندوب الحالي مباشرة
                                    $currentUserCustomers = $db->query(
                                        "SELECT id, name FROM customers WHERE (created_by = ? OR rep_id = ?) AND status = 'active' ORDER BY name ASC",
                                        [$currentUser['id'], $currentUser['id']]
                                    );
                                    foreach ($currentUserCustomers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">اختر المندوب أولاً</option>
                                <?php endif; ?>
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
                        <div class="col-12">
                            <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                            <div class="d-flex gap-2">
                                <input type="text" class="form-control" name="new_customer_latitude" id="newCustomerLatitude" placeholder="خط العرض" readonly>
                                <input type="text" class="form-control" name="new_customer_longitude" id="newCustomerLongitude" placeholder="خط الطول" readonly>
                                <button type="button" class="btn btn-outline-primary" id="getLocationBtn" title="الحصول على الموقع الحالي">
                                    <i class="bi bi-geo-alt"></i>
                                </button>
                            </div>
                            <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الطلب</label>
                        <div id="orderItems">
                            <div class="order-item row mb-2">
                                <div class="col-md-9">
                                    <input type="text" class="form-control template-input" 
                                           name="items[0][template_name]" placeholder="اسم المنتج" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger w-100 remove-item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
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

<!-- Modal إنشاء طلب شركة -->
<?php if ($isManagerOrAccountant): ?>
<div class="modal fade" id="addCompanyOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building me-2"></i>إنشاء طلب عميل شركة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="companyOrderForm">
                <input type="hidden" name="action" value="create_company_order">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">العميل <span class="text-danger">*</span></label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="toggleNewCompanyCustomer" name="create_new_customer" value="1">
                                    <label class="form-check-label small" for="toggleNewCompanyCustomer">عميل جديد</label>
                                </div>
                            </div>
                            <select class="form-select" name="customer_id" id="companyCustomerSelect" required>
                                <option value="">اختر العميل</option>
                                <?php foreach ($companyCustomers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
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
                        <div class="col-md-2">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal">عادية</option>
                                <option value="low">منخفضة</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="newCompanyCustomerFields" class="row g-3 mb-3 d-none">
                        <div class="col-md-4">
                            <label class="form-label">اسم العميل الجديد <span class="text-danger">*</span></label>
                            <input type="text" class="form-control new-company-customer-required" name="new_customer_name" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="text" class="form-control" name="new_customer_phone" autocomplete="off" placeholder="مثال: 01234567890">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">عنوان العميل</label>
                            <textarea class="form-control" name="new_customer_address" rows="2" autocomplete="off" placeholder="اكتب العنوان بالتفصيل"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">موقع العميل <span class="text-muted">(اختياري)</span></label>
                            <div class="d-flex gap-2">
                                <input type="text" class="form-control" name="new_customer_latitude" id="companyNewCustomerLatitude" placeholder="خط العرض" readonly>
                                <input type="text" class="form-control" name="new_customer_longitude" id="companyNewCustomerLongitude" placeholder="خط الطول" readonly>
                                <button type="button" class="btn btn-outline-primary" id="companyGetLocationBtn" title="الحصول على الموقع الحالي">
                                    <i class="bi bi-geo-alt"></i>
                                </button>
                            </div>
                            <small class="text-muted">اضغط على زر الموقع للحصول على موقعك الحالي</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر الطلب</label>
                        <div id="companyOrderItems">
                            <div class="order-item row mb-2">
                                <div class="col-md-9">
                                    <input type="text" class="form-control template-input" 
                                           name="items[0][template_name]" placeholder="اسم المنتج" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger w-100 remove-item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addCompanyItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">إنشاء طلب شركة</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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
                            <?php if ($isSalesUser): ?>
                                <!-- للمندوب: فقط حالتين -->
                                <option value="delivered">تم التسليم</option>
                                <option value="cancelled">ملغى</option>
                            <?php else: ?>
                                <!-- للمدير والمحاسب: جميع الحالات -->
                                <option value="pending">معلق</option>
                                <option value="confirmed">مؤكد</option>
                                <option value="in_production">قيد الإنتاج</option>
                                <option value="ready">جاهز</option>
                                <option value="delivered">تم التسليم</option>
                                <option value="cancelled">ملغى</option>
                            <?php endif; ?>
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

// لم نعد بحاجة إلى productOptions لأننا نستخدم input text الآن

// إضافة عنصر جديد
document.getElementById('addItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    newItem.innerHTML = `
        <div class="col-md-9">
            <input type="text" class="form-control template-input" 
                   name="items[${itemIndex}][template_name]" placeholder="اسم القالب" required>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control quantity" 
                   name="items[${itemIndex}][quantity]" placeholder="الكمية" required>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 remove-item">
                <i class="bi bi-trash"></i>
            </button>
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

function updateNewCustomerState() {
    const newCustomerToggle = document.getElementById('toggleNewCustomer');
    const existingCustomerSelect = document.getElementById('existingCustomerSelect');
    const newCustomerFields = document.getElementById('newCustomerFields');
    
    if (!newCustomerToggle || !existingCustomerSelect || !newCustomerFields) {
        return false;
    }

    const newCustomerRequiredInputs = Array.from(document.querySelectorAll('#addOrderModal .new-customer-required'));
    
    if (newCustomerToggle.checked) {
        // إظهار حقول العميل الجديد
        newCustomerFields.classList.remove('d-none');
        newCustomerFields.style.display = '';
        
        // تعطيل حقل اختيار العميل الموجود
        existingCustomerSelect.value = '';
        existingCustomerSelect.setAttribute('disabled', 'disabled');
        existingCustomerSelect.removeAttribute('required');
        
        // تفعيل الحقول المطلوبة للعميل الجديد
        newCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
        return true;
    } else {
        // إخفاء حقول العميل الجديد
        newCustomerFields.classList.add('d-none');
        
        // تفعيل حقل اختيار العميل الموجود
        existingCustomerSelect.removeAttribute('disabled');
        existingCustomerSelect.setAttribute('required', 'required');
        
        // إلغاء تفعيل الحقول المطلوبة للعميل الجديد
        newCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
        return false;
    }
}

// تعريف addOrderModalElement خارج الدالة للوصول إليه من أي مكان
const addOrderModalElement = document.getElementById('addOrderModal');

// ربط الأحداث - استخدام عدة طرق لضمان العمل
(function() {
    // ربط مباشر على document للـ event delegation الشامل
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'toggleNewCustomer') {
            updateNewCustomerState();
        }
    });
    
    document.addEventListener('click', function(e) {
        if (e.target && (e.target.id === 'toggleNewCustomer' || e.target.closest('#toggleNewCustomer'))) {
            setTimeout(function() {
                updateNewCustomerState();
            }, 50);
        }
    });
    
    // ربط الأحداث عند فتح Modal
    if (addOrderModalElement) {
        // استخدام event delegation على Modal
        addOrderModalElement.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'toggleNewCustomer') {
                updateNewCustomerState();
            }
        });
        
        addOrderModalElement.addEventListener('click', function(e) {
            if (e.target && (e.target.id === 'toggleNewCustomer' || e.target.closest('#toggleNewCustomer'))) {
                setTimeout(function() {
                    updateNewCustomerState();
                }, 50);
            }
        });
        
        // تحديث الحالة عند فتح Modal
        if (typeof bootstrap !== 'undefined') {
            addOrderModalElement.addEventListener('shown.bs.modal', function() {
                // ربط مباشر على toggle عند فتح Modal
                const toggle = document.getElementById('toggleNewCustomer');
                if (toggle) {
                    toggle.addEventListener('change', updateNewCustomerState);
                    toggle.addEventListener('click', function() {
                        setTimeout(updateNewCustomerState, 50);
                    });
                }
                updateNewCustomerState();
            });
        }
    }
    
    // أيضاً ربط عند تحميل الصفحة
    function initToggle() {
        const toggle = document.getElementById('toggleNewCustomer');
        if (toggle) {
            toggle.addEventListener('change', updateNewCustomerState);
            toggle.addEventListener('click', function() {
                setTimeout(updateNewCustomerState, 50);
            });
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToggle);
    } else {
        initToggle();
    }
})();

// إعادة تعيين النموذج عند إغلاق Modal
if (addOrderModalElement && typeof bootstrap !== 'undefined') {
    addOrderModalElement.addEventListener('hidden.bs.modal', function() {
        const newCustomerToggle = document.getElementById('toggleNewCustomer');
        if (newCustomerToggle) {
            newCustomerToggle.checked = false;
            updateNewCustomerState();
        }
        const newCustomerRequiredInputs = Array.from(document.querySelectorAll('#addOrderModal .new-customer-required'));
        newCustomerRequiredInputs.forEach(function(input) {
            input.value = '';
        });
        const newCustomerPhoneInput = document.querySelector('#addOrderModal input[name="new_customer_phone"]');
        const newCustomerAddressInput = document.querySelector('#addOrderModal textarea[name="new_customer_address"]');
        const newCustomerLatitudeInput = document.querySelector('#addOrderModal input[name="new_customer_latitude"]');
        const newCustomerLongitudeInput = document.querySelector('#addOrderModal input[name="new_customer_longitude"]');
        if (newCustomerPhoneInput) {
            newCustomerPhoneInput.value = '';
        }
        if (newCustomerAddressInput) {
            newCustomerAddressInput.value = '';
        }
        if (newCustomerLatitudeInput) {
            newCustomerLatitudeInput.value = '';
        }
        if (newCustomerLongitudeInput) {
            newCustomerLongitudeInput.value = '';
        }
    });
}

// دالة الحصول على موقع المستخدم للعميل الجديد
// ربط الأحداث عند فتح Modal لضمان وجود العناصر
function setupLocationButton() {
    const getLocationBtn = document.getElementById('getLocationBtn');
    const latitudeInput = document.getElementById('newCustomerLatitude');
    const longitudeInput = document.getElementById('newCustomerLongitude');
    
    if (getLocationBtn && latitudeInput && longitudeInput) {
        // إزالة أي event listeners سابقة
        const newBtn = getLocationBtn.cloneNode(true);
        getLocationBtn.parentNode.replaceChild(newBtn, getLocationBtn);
        
        // ربط event listener جديد
        const newGetLocationBtn = document.getElementById('getLocationBtn');
        if (newGetLocationBtn) {
            newGetLocationBtn.addEventListener('click', function() {
                if (!navigator.geolocation) {
                    alert('المتصفح لا يدعم الحصول على الموقع');
                    return;
                }
                
                newGetLocationBtn.disabled = true;
                newGetLocationBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
                
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const latInput = document.getElementById('newCustomerLatitude');
                        const lngInput = document.getElementById('newCustomerLongitude');
                        if (latInput && lngInput) {
                            latInput.value = position.coords.latitude.toFixed(8);
                            lngInput.value = position.coords.longitude.toFixed(8);
                        }
                        newGetLocationBtn.disabled = false;
                        newGetLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                    },
                    function(error) {
                        alert('فشل الحصول على الموقع: ' + error.message);
                        newGetLocationBtn.disabled = false;
                        newGetLocationBtn.innerHTML = '<i class="bi bi-geo-alt"></i>';
                    }
                );
            });
        }
    }
}

// ربط الأحداث عند فتح Modal
if (addOrderModalElement && typeof bootstrap !== 'undefined') {
    addOrderModalElement.addEventListener('shown.bs.modal', function() {
        setupLocationButton();
    });
}

// أيضاً محاولة الربط مباشرة عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupLocationButton);
} else {
    setupLocationButton();
}

// ربط الأحداث عند فتح Modal طلب الشركة
const addCompanyOrderModalElement = document.getElementById('addCompanyOrderModal');
if (addCompanyOrderModalElement && typeof bootstrap !== 'undefined') {
    addCompanyOrderModalElement.addEventListener('shown.bs.modal', function() {
        setupCompanyLocationButton();
    });
}

// أيضاً محاولة الربط مباشرة عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupCompanyLocationButton);
} else {
    setupCompanyLocationButton();
}

// ربط أحداث العناصر
function attachItemEvents(item) {
    // لا حاجة لحسابات السعر والإجمالي
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

// تحميل عملاء المندوب عند اختياره (فقط إذا لم يكن المستخدم مندوب مبيعات)
const salesRepSelect = document.getElementById('salesRepSelect');
const existingCustomerSelect = document.getElementById('existingCustomerSelect');

if (salesRepSelect && existingCustomerSelect) {
    salesRepSelect.addEventListener('change', function() {
        const salesRepId = this.value;
        
        // إعادة تعيين حقل العميل
        existingCustomerSelect.innerHTML = '<option value="">جاري التحميل...</option>';
        existingCustomerSelect.disabled = true;
        
        if (!salesRepId) {
            existingCustomerSelect.innerHTML = '<option value="">اختر المندوب أولاً</option>';
            existingCustomerSelect.disabled = true;
            if (newCustomerToggle) {
                newCustomerToggle.checked = false;
                updateNewCustomerState();
            }
            return;
        }
        
        // جلب عملاء المندوب عبر AJAX
        const currentUrl = new URL(window.location.href);
        const baseUrl = currentUrl.pathname + '?page=orders&ajax=get_customers';
        fetch(baseUrl + '&sales_rep_id=' + encodeURIComponent(salesRepId))
            .then(response => response.json())
            .then(data => {
                existingCustomerSelect.innerHTML = '<option value="">اختر العميل</option>';
                
                if (data.success && data.customers) {
                    data.customers.forEach(function(customer) {
                        const option = document.createElement('option');
                        option.value = customer.id;
                        option.textContent = customer.name;
                        existingCustomerSelect.appendChild(option);
                    });
                    
                    existingCustomerSelect.disabled = false;
                    existingCustomerSelect.removeAttribute('required');
                    existingCustomerSelect.setAttribute('required', 'required');
                } else {
                    existingCustomerSelect.innerHTML = '<option value="">لا يوجد عملاء لهذا المندوب</option>';
                    existingCustomerSelect.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error loading customers:', error);
                existingCustomerSelect.innerHTML = '<option value="">خطأ في تحميل العملاء</option>';
            });
    });
    
    // إذا كان هناك مندوب محدد مسبقاً، تحميل عملاءه
    if (salesRepSelect.value) {
        salesRepSelect.dispatchEvent(new Event('change'));
    }
}

// تحديث حالة تعطيل العميل عند تغيير toggleNewCustomer (فقط إذا لم يكن المستخدم مندوب مبيعات)
if (addOrderModalElement && typeof bootstrap !== 'undefined') {
    addOrderModalElement.addEventListener('shown.bs.modal', function() {
        const newCustomerToggle = document.getElementById('toggleNewCustomer');
        const salesRepSelect = document.getElementById('salesRepSelect');
        const existingCustomerSelect = document.getElementById('existingCustomerSelect');
        
        // إذا كان هناك مندوب محدد، تحميل عملاءه
        if (salesRepSelect && salesRepSelect.value) {
            salesRepSelect.dispatchEvent(new Event('change'));
        }
        
        // التأكد من أن حقل العميل معطل إذا لم يتم اختيار مندوب (فقط للمدير)
        if (newCustomerToggle && salesRepSelect && existingCustomerSelect) {
            if (!salesRepSelect.value && !newCustomerToggle.checked) {
                existingCustomerSelect.disabled = true;
                existingCustomerSelect.innerHTML = '<option value="">اختر المندوب أولاً</option>';
            }
        }
    });
}

// JavaScript لمعالجة Modal طلب الشركة
<?php if ($isManagerOrAccountant): ?>
let companyItemIndex = 1;

// إضافة عنصر جديد لطلب الشركة
document.getElementById('addCompanyItemBtn')?.addEventListener('click', function() {
    const itemsDiv = document.getElementById('companyOrderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item row mb-2';
    newItem.innerHTML = `
        <div class="col-md-9">
            <input type="text" class="form-control template-input" 
                   name="items[${companyItemIndex}][template_name]" placeholder="اسم القالب" required>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control quantity" 
                   name="items[${companyItemIndex}][quantity]" placeholder="الكمية" required>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 remove-item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    itemsDiv.appendChild(newItem);
    companyItemIndex++;
});

// معالجة toggle عميل جديد للشركة
const toggleNewCompanyCustomer = document.getElementById('toggleNewCompanyCustomer');
const companyCustomerSelect = document.getElementById('companyCustomerSelect');
const newCompanyCustomerFields = document.getElementById('newCompanyCustomerFields');
const newCompanyCustomerRequiredInputs = Array.from(document.querySelectorAll('.new-company-customer-required'));

function updateNewCompanyCustomerState() {
    if (!toggleNewCompanyCustomer || !companyCustomerSelect || !newCompanyCustomerFields) {
        return;
    }

    if (toggleNewCompanyCustomer.checked) {
        newCompanyCustomerFields.classList.remove('d-none');
        companyCustomerSelect.value = '';
        companyCustomerSelect.setAttribute('disabled', 'disabled');
        companyCustomerSelect.removeAttribute('required');
        newCompanyCustomerRequiredInputs.forEach(function(input) {
            input.setAttribute('required', 'required');
        });
    } else {
        newCompanyCustomerFields.classList.add('d-none');
        companyCustomerSelect.removeAttribute('disabled');
        companyCustomerSelect.setAttribute('required', 'required');
        newCompanyCustomerRequiredInputs.forEach(function(input) {
            input.removeAttribute('required');
        });
    }
}

if (toggleNewCompanyCustomer) {
    toggleNewCompanyCustomer.addEventListener('change', updateNewCompanyCustomerState);
    updateNewCompanyCustomerState();
}

// إعادة تعيين نموذج طلب الشركة عند إغلاق الـ modal
const addCompanyOrderModalElement = document.getElementById('addCompanyOrderModal');
if (addCompanyOrderModalElement && typeof bootstrap !== 'undefined') {
    addCompanyOrderModalElement.addEventListener('hidden.bs.modal', function() {
        if (toggleNewCompanyCustomer) {
            toggleNewCompanyCustomer.checked = false;
            updateNewCompanyCustomerState();
        }
        newCompanyCustomerRequiredInputs.forEach(function(input) {
            input.value = '';
        });
        const newCustomerPhoneInput = document.querySelector('#addCompanyOrderModal input[name="new_customer_phone"]');
        const newCustomerAddressInput = document.querySelector('#addCompanyOrderModal textarea[name="new_customer_address"]');
        const newCustomerLatitudeInput = document.querySelector('#addCompanyOrderModal input[name="new_customer_latitude"]');
        const newCustomerLongitudeInput = document.querySelector('#addCompanyOrderModal input[name="new_customer_longitude"]');
        if (newCustomerPhoneInput) {
            newCustomerPhoneInput.value = '';
        }
        if (newCustomerAddressInput) {
            newCustomerAddressInput.value = '';
        }
        if (newCustomerLatitudeInput) {
            newCustomerLatitudeInput.value = '';
        }
        if (newCustomerLongitudeInput) {
            newCustomerLongitudeInput.value = '';
        }
        // إعادة تعيين العناصر
        const companyOrderItems = document.getElementById('companyOrderItems');
        if (companyOrderItems) {
            companyOrderItems.innerHTML = `
                <div class="order-item row mb-2">
                    <div class="col-md-9">
                        <input type="text" class="form-control template-input" 
                               name="items[0][template_name]" placeholder="اسم القالب" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control quantity" 
                               name="items[0][quantity]" placeholder="الكمية" required>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-danger w-100 remove-item">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            companyItemIndex = 1;
        }
    });
}
<?php endif; ?>
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

<?php
// نهاية الملف
?>


