<?php
/**
 * API for Local Customer Returns
 * API endpoint for handling returns from local customers
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/inventory_movements.php';

ob_clean();

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJson(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$allowedRoles = ['manager', 'accountant'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    returnJson(['success' => false, 'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'], 403);
}

try {
    switch ($action) {
        case 'create_return':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleCreateLocalReturn();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('local_returns API error: ' . $e->getMessage());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()], 500);
}

function returnJson(array $data, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * إنشاء طلب مرتجع للعميل المحلي
 */
function handleCreateLocalReturn(): void
{
    global $currentUser;
    
    // التأكد من وجود الجداول
    ensureLocalReturnsTables();
    
    $payload = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($payload)) {
        returnJson(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], 400);
    }
    
    $customerId = isset($payload['customer_id']) ? (int)$payload['customer_id'] : 0;
    $items = $payload['items'] ?? [];
    $notes = trim($payload['notes'] ?? '');
    $refundMethod = $payload['refund_method'] ?? 'credit'; // 'cash' or 'credit'
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'يجب اختيار عميل'], 422);
    }
    
    if (empty($items) || !is_array($items)) {
        returnJson(['success' => false, 'message' => 'يجب اختيار منتجات لإرجاعها'], 422);
    }
    
    if (!in_array($refundMethod, ['cash', 'credit'], true)) {
        returnJson(['success' => false, 'message' => 'طريقة الاسترداد غير صحيحة'], 422);
    }
    
    $db = db();
    
    // التحقق من العميل المحلي
    $customer = $db->queryOne(
        "SELECT id, name, balance FROM local_customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل المحلي غير موجود'], 404);
    }
    
    try {
        $db->beginTransaction();
        
        // توليد رقم المرتجع
        $year = date('Y');
        $month = date('m');
        $lastReturn = $db->queryOne(
            "SELECT return_number FROM local_returns WHERE return_number LIKE ? ORDER BY return_number DESC LIMIT 1 FOR UPDATE",
            ["LOC-RET-{$year}{$month}-%"]
        );
        
        $serial = 1;
        if (!empty($lastReturn['return_number'])) {
            $parts = explode('-', $lastReturn['return_number']);
            $serial = intval($parts[3] ?? 0) + 1;
        }
        
        $returnNumber = sprintf("LOC-RET-%s%s-%04d", $year, $month, $serial);
        
        // حساب المبلغ الإجمالي
        $totalRefund = 0.0;
        $selectedItems = [];
        
        foreach ($items as $item) {
            $invoiceItemId = isset($item['invoice_item_id']) ? (int)$item['invoice_item_id'] : 0;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            
            if ($invoiceItemId <= 0 || $quantity <= 0) {
                continue;
            }
            
            // جلب بيانات عنصر الفاتورة مع batch_number و batch_id
            $hasBatchNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_number'"));
            $hasBatchId = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_id'"));
            
            $batchColumns = '';
            if ($hasBatchNumber && $hasBatchId) {
                $batchColumns = ', ii.batch_number, ii.batch_id';
            } elseif ($hasBatchNumber) {
                $batchColumns = ', ii.batch_number';
            } elseif ($hasBatchId) {
                $batchColumns = ', ii.batch_id';
            }
            
            $invoiceItem = $db->queryOne(
                "SELECT ii.*, i.invoice_number, i.customer_id $batchColumns
                 FROM local_invoice_items ii
                 INNER JOIN local_invoices i ON ii.invoice_id = i.id
                 WHERE ii.id = ? AND i.customer_id = ?",
                [$invoiceItemId, $customerId]
            );
            
            if (!$invoiceItem) {
                throw new InvalidArgumentException("عنصر الفاتورة رقم {$invoiceItemId} غير موجود أو لا ينتمي لهذا العميل");
            }
            
            // التحقق من الكمية المتاحة للإرجاع
            $returnedQuantity = 0.0;
            $hasInvoiceItemId = !empty($db->queryOne("SHOW COLUMNS FROM local_return_items LIKE 'invoice_item_id'"));
            if ($hasInvoiceItemId) {
                $returnedResult = $db->queryOne(
                    "SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                     FROM local_return_items ri
                     INNER JOIN local_returns r ON r.id = ri.return_id
                     WHERE ri.invoice_item_id = ? 
                       AND r.customer_id = ?
                       AND r.status IN ('pending', 'approved', 'processed', 'completed')",
                    [$invoiceItemId, $customerId]
                );
                $returnedQuantity = (float)($returnedResult['returned_quantity'] ?? 0);
            }
            
            $availableQuantity = (float)$invoiceItem['quantity'] - $returnedQuantity;
            
            if ($quantity > $availableQuantity) {
                throw new InvalidArgumentException("الكمية المطلوب إرجاعها ({$quantity}) أكبر من المتاح ({$availableQuantity}) لعنصر الفاتورة رقم {$invoiceItemId}");
            }
            
            $itemTotal = $quantity * (float)$invoiceItem['unit_price'];
            $totalRefund += $itemTotal;
            
            $selectedItems[] = [
                'invoice_item_id' => $invoiceItemId,
                'invoice_id' => (int)$invoiceItem['invoice_id'],
                'invoice_number' => $invoiceItem['invoice_number'],
                'product_id' => (int)$invoiceItem['product_id'],
                'quantity' => $quantity,
                'unit_price' => (float)$invoiceItem['unit_price'],
                'total_price' => $itemTotal
            ];
        }
        
        if (empty($selectedItems)) {
            throw new InvalidArgumentException('لا توجد عناصر صالحة للإرجاع');
        }
        
        if ($totalRefund <= 0) {
            throw new InvalidArgumentException('المبلغ الإجمالي للمرتجع يجب أن يكون أكبر من صفر');
        }
        
        // التحقق من وجود عمود approved_at وإضافته إذا لم يكن موجوداً
        $hasApprovedAt = !empty($db->queryOne("SHOW COLUMNS FROM local_returns LIKE 'approved_at'"));
        if (!$hasApprovedAt) {
            try {
                $db->execute("ALTER TABLE local_returns ADD COLUMN approved_at timestamp NULL DEFAULT NULL AFTER approved_by");
            } catch (Throwable $e) {
                error_log('Error adding approved_at column: ' . $e->getMessage());
            }
        }
        
        // إنشاء سجل المرتجع
        $returnDate = date('Y-m-d');
        
        // بناء الاستعلام ديناميكياً حسب الأعمدة المتوفرة
        $columns = ['return_number', 'customer_id', 'return_date', 'refund_amount', 'refund_method', 'status', 'notes', 'created_by', 'approved_by'];
        $values = [
            $returnNumber,
            $customerId,
            $returnDate,
            $totalRefund,
            $refundMethod,
            'approved',
            $notes ?: null,
            $currentUser['id'],
            $currentUser['id']
        ];
        
        // التحقق مرة أخرى من وجود approved_at بعد محاولة إضافته
        $hasApprovedAt = !empty($db->queryOne("SHOW COLUMNS FROM local_returns LIKE 'approved_at'"));
        if ($hasApprovedAt) {
            $columns[] = 'approved_at';
            $values[] = date('Y-m-d H:i:s');
        }
        
        $columnsStr = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        
        $db->execute(
            "INSERT INTO local_returns ($columnsStr) VALUES ($placeholders)",
            $values
        );
        
        $returnId = $db->getLastInsertId();
        
        // إضافة عناصر المرتجع
        foreach ($selectedItems as $item) {
            $columns = ['return_id', 'invoice_item_id', 'product_id', 'quantity', 'unit_price', 'total_price'];
            $values = [
                $returnId,
                $item['invoice_item_id'],
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price']
            ];
            
            $hasInvoiceId = !empty($db->queryOne("SHOW COLUMNS FROM local_return_items LIKE 'invoice_id'"));
            if ($hasInvoiceId) {
                $columns[] = 'invoice_id';
                $values[] = $item['invoice_id'];
            }
            
            $columnsStr = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            
            $db->execute(
                "INSERT INTO local_return_items ($columnsStr) VALUES ($placeholders)",
                $values
            );
        }
        
        // معالجة التسويات المالية
        $currentBalance = (float)($customer['balance'] ?? 0);
        $newBalance = $currentBalance;
        
        if ($refundMethod === 'credit') {
            // إذا كان العميل مدين: خصم من الدين
            // إذا كان غير مدين: إضافة رصيد دائن
            if ($currentBalance > 0) {
                // العميل مدين - خصم من الدين
                $newBalance = max(0, $currentBalance - $totalRefund);
            } else {
                // العميل غير مدين - إضافة رصيد دائن (قيمة سالبة)
                $newBalance = $currentBalance - $totalRefund;
            }
        }
        // إذا كان refundMethod === 'cash' لا نغير الرصيد
        
        // تحديث رصيد العميل
        if ($refundMethod === 'credit') {
            $db->execute(
                "UPDATE local_customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
        }
        
        // إضافة مصروف في خزنة الشركة إذا كان الاسترداد نقدي
        if ($refundMethod === 'cash') {
            $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
            if (!empty($accountantTableExists)) {
                $description = 'إرجاع منتجات من عميل محلي: ' . htmlspecialchars($customer['name'] ?? '') . ' - رقم المرتجع: ' . $returnNumber;
                $referenceNumber = $returnNumber;
                
                $db->execute(
                    "INSERT INTO accountant_transactions (transaction_type, amount, description, reference_number, payment_method, status, approved_by, created_by, approved_at, notes)
                     VALUES (?, ?, ?, ?, 'cash', 'approved', ?, ?, NOW(), ?)",
                    [
                        'expense',
                        $totalRefund,
                        $description,
                        $referenceNumber,
                        $currentUser['id'],
                        $currentUser['id'],
                        'مرتجع نقدي من عميل محلي'
                    ]
                );
            }
        }
        
        // إرجاع المنتجات إلى مخزن الشركة
        // الحصول على المخزن الرئيسي
        $mainWarehouse = $db->queryOne(
            "SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' ORDER BY id ASC LIMIT 1"
        );
        
        if (!$mainWarehouse) {
            // إنشاء المخزن الرئيسي إذا لم يكن موجوداً
            $db->execute(
                "INSERT INTO warehouses (name, warehouse_type, status, location, description) 
                 VALUES (?, 'main', 'active', ?, ?)",
                ['المخزن الرئيسي', 'الموقع الرئيسي للشركة', 'المخزن الرئيسي للشركة']
            );
            $mainWarehouseId = $db->getLastInsertId();
        } else {
            $mainWarehouseId = (int)$mainWarehouse['id'];
        }
        
        // إرجاع كل منتج إلى المخزن
        foreach ($selectedItems as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];
            $invoiceItemId = (int)$item['invoice_item_id'];
            
            // جلب معلومات المنتج
            $product = $db->queryOne(
                "SELECT id, name, quantity, warehouse_id FROM products WHERE id = ?",
                [$productId]
            );
            
            if (!$product) {
                error_log("Product not found for return: product_id = $productId");
                continue;
            }
            
            // تحديد warehouse_id - إذا لم يكن محدداً، نستخدم المخزن الرئيسي
            $warehouseId = (int)($product['warehouse_id'] ?? 0);
            if ($warehouseId <= 0) {
                $warehouseId = $mainWarehouseId;
                // تحديث warehouse_id في products إذا لم يكن محدداً
                $db->execute(
                    "UPDATE products SET warehouse_id = ? WHERE id = ? AND (warehouse_id IS NULL OR warehouse_id = 0)",
                    [$warehouseId, $productId]
                );
            }
            
            // ملاحظة: لا نقوم بتحديث products.quantity هنا لأن recordInventoryMovement() سيقوم بذلك تلقائياً
            // recordInventoryMovement() يقوم بتحديث products.quantity عند type='in'
            
            // البحث عن batch_number من الفاتورة المحلية
            $batchId = null;
            $batchNumber = null;
            
            // البحث عن batch_number و batch_id من local_invoice_items
            // نستخدم البيانات التي تم جلبها مسبقاً في $selectedItems
            $batchId = null;
            $batchNumber = null;
            
            // التحقق من وجود أعمدة batch_number و batch_id في local_invoice_items
            $hasBatchNumber = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_number'"));
            $hasBatchId = !empty($db->queryOne("SHOW COLUMNS FROM local_invoice_items LIKE 'batch_id'"));
            
            if ($hasBatchNumber || $hasBatchId) {
                $batchSelect = '';
                if ($hasBatchNumber && $hasBatchId) {
                    $batchSelect = 'batch_number, batch_id';
                } elseif ($hasBatchNumber) {
                    $batchSelect = 'batch_number, NULL as batch_id';
                } elseif ($hasBatchId) {
                    $batchSelect = 'NULL as batch_number, batch_id';
                }
                
                $invoiceItemBatch = $db->queryOne(
                    "SELECT $batchSelect FROM local_invoice_items WHERE id = ?",
                    [$invoiceItemId]
                );
                
                if ($invoiceItemBatch) {
                    $batchNumber = $invoiceItemBatch['batch_number'] ?? null;
                    $batchId = isset($invoiceItemBatch['batch_id']) && $invoiceItemBatch['batch_id'] ? (int)$invoiceItemBatch['batch_id'] : null;
                    
                    // إذا كان هناك batch_number لكن لا يوجد batch_id، نحاول البحث عنه
                    if ($batchNumber && !$batchId) {
                        $batchInfo = $db->queryOne(
                            "SELECT id FROM batch_numbers WHERE batch_number = ? LIMIT 1",
                            [$batchNumber]
                        );
                        if ($batchInfo) {
                            $batchId = (int)$batchInfo['id'];
                        }
                    }
                }
            }
            
            // تحديث quantity_produced في finished_products
            // ملاحظة: recordInventoryMovement() لا يقوم بتحديث finished_products.quantity_produced 
            // في حالة type='in'، لذلك يجب تحديثه يدوياً هنا
            if ($batchId) {
                $finishedProduct = $db->queryOne(
                    "SELECT id, quantity_produced FROM finished_products WHERE batch_id = ? LIMIT 1",
                    [$batchId]
                );
                
                if ($finishedProduct) {
                    $currentProduced = (float)($finishedProduct['quantity_produced'] ?? 0);
                    $newProduced = round($currentProduced + $quantity, 3);
                    
                    $db->execute(
                        "UPDATE finished_products SET quantity_produced = ? WHERE id = ?",
                        [$newProduced, (int)$finishedProduct['id']]
                    );
                }
            }
            
            // تسجيل حركة المخزون
            try {
                $movementNotes = 'إرجاع من عميل محلي - رقم المرتجع: ' . $returnNumber;
                if ($batchNumber) {
                    $movementNotes .= ' - رقم التشغيلة: ' . $batchNumber;
                }
                
                $movementResult = recordInventoryMovement(
                    $productId,
                    $warehouseId,
                    'in',
                    $quantity,
                    'local_return',
                    $returnId,
                    $movementNotes,
                    $currentUser['id'],
                    $batchId
                );
                
                if (empty($movementResult['success'])) {
                    error_log('Failed to record inventory movement for local return: ' . ($movementResult['message'] ?? 'Unknown error'));
                }
            } catch (Throwable $movementError) {
                error_log('Error recording inventory movement for local return: ' . $movementError->getMessage());
                // لا نوقف العملية إذا فشل تسجيل الحركة
            }
        }
        
        logAudit(
            $currentUser['id'],
            'create_local_return',
            'local_return',
            $returnId,
            null,
            [
                'return_number' => $returnNumber,
                'customer_id' => $customerId,
                'refund_amount' => $totalRefund,
                'refund_method' => $refundMethod,
                'items_count' => count($selectedItems)
            ]
        );
        
        $db->commit();
        
        $printUrl = getRelativeUrl('print_local_return.php?id=' . $returnId);
        
        returnJson([
            'success' => true,
            'message' => 'تم تسجيل المرتجع بنجاح',
            'return_id' => $returnId,
            'return_number' => $returnNumber,
            'refund_amount' => $totalRefund,
            'refund_method' => $refundMethod,
            'new_balance' => $newBalance,
            'print_url' => $printUrl
        ]);
        
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        returnJson(['success' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log('Error creating local return: ' . $e->getMessage());
        returnJson(['success' => false, 'message' => 'حدث خطأ أثناء إنشاء المرتجع: ' . $e->getMessage()], 500);
    }
}

/**
 * التأكد من وجود جداول المرتجعات المحلية
 */
function ensureLocalReturnsTables(): void
{
    $db = db();
    
    // إنشاء جدول local_returns
    $returnsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");
    if (empty($returnsTableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `local_returns` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `return_number` varchar(50) NOT NULL,
              `customer_id` int(11) NOT NULL,
              `return_date` date NOT NULL,
              `refund_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
              `refund_method` enum('cash','credit') DEFAULT 'credit',
              `status` enum('pending','approved','rejected','processed','completed') DEFAULT 'pending',
              `approved_by` int(11) DEFAULT NULL,
              `processed_by` int(11) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `return_number` (`return_number`),
              KEY `customer_id` (`customer_id`),
              KEY `status` (`status`),
              KEY `created_by` (`created_by`),
              KEY `approved_by` (`approved_by`),
              CONSTRAINT `local_returns_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `local_customers` (`id`) ON DELETE CASCADE,
              CONSTRAINT `local_returns_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `local_returns_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إضافة عمود approved_at إذا لم يكن موجوداً
    $hasApprovedAt = !empty($db->queryOne("SHOW COLUMNS FROM local_returns LIKE 'approved_at'"));
    if (!$hasApprovedAt) {
        try {
            $db->execute("ALTER TABLE local_returns ADD COLUMN approved_at timestamp NULL DEFAULT NULL AFTER approved_by");
        } catch (Throwable $e) {
            error_log('Error adding approved_at column to local_returns: ' . $e->getMessage());
        }
    }
    
    // إنشاء جدول local_return_items
    $returnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
    if (empty($returnItemsTableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `local_return_items` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `return_id` int(11) NOT NULL,
              `invoice_id` int(11) DEFAULT NULL,
              `invoice_item_id` int(11) DEFAULT NULL,
              `product_id` int(11) NOT NULL,
              `quantity` decimal(10,2) NOT NULL,
              `unit_price` decimal(15,2) NOT NULL,
              `total_price` decimal(15,2) NOT NULL,
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `return_id` (`return_id`),
              KEY `invoice_id` (`invoice_id`),
              KEY `invoice_item_id` (`invoice_item_id`),
              KEY `product_id` (`product_id`),
              CONSTRAINT `local_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `local_returns` (`id`) ON DELETE CASCADE,
              CONSTRAINT `local_return_items_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE SET NULL,
              CONSTRAINT `local_return_items_ibfk_3` FOREIGN KEY (`invoice_item_id`) REFERENCES `local_invoice_items` (`id`) ON DELETE SET NULL,
              CONSTRAINT `local_return_items_ibfk_4` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إضافة عمود invoice_item_id إذا لم يكن موجوداً
    $hasInvoiceItemId = !empty($db->queryOne("SHOW COLUMNS FROM local_return_items LIKE 'invoice_item_id'"));
    if (!$hasInvoiceItemId) {
        try {
            $db->execute("ALTER TABLE local_return_items ADD COLUMN invoice_item_id int(11) DEFAULT NULL AFTER invoice_id, ADD KEY invoice_item_id (invoice_item_id)");
            $db->execute("ALTER TABLE local_return_items ADD CONSTRAINT local_return_items_ibfk_3 FOREIGN KEY (invoice_item_id) REFERENCES local_invoice_items(id) ON DELETE SET NULL");
        } catch (Throwable $e) {
            error_log('Error adding invoice_item_id column: ' . $e->getMessage());
        }
    }
}

