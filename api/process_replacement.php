<?php
/**
 * API لمعالجة عملية الاستبدال
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/salary_calculator.php';
require_once __DIR__ . '/../includes/vehicle_inventory.php';

requireRole(['sales', 'accountant', 'manager']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$currentUser = getCurrentUser();
$db = db();

// تنظيف أي output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير صالحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // التحقق من البيانات
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    
    // تحويل JSON strings إلى arrays
    $customerItemsRaw = $_POST['customer_items'] ?? '';
    $carItemsRaw = $_POST['car_items'] ?? '';
    
    // محاولة تحويل JSON string إلى array
    if (is_string($customerItemsRaw) && !empty($customerItemsRaw)) {
        $decodedCustomerItems = json_decode($customerItemsRaw, true);
        $customerItems = (is_array($decodedCustomerItems)) ? $decodedCustomerItems : [];
    } elseif (is_array($customerItemsRaw)) {
        $customerItems = $customerItemsRaw;
    } else {
        $customerItems = [];
    }
    
    if (is_string($carItemsRaw) && !empty($carItemsRaw)) {
        $decodedCarItems = json_decode($carItemsRaw, true);
        $carItems = (is_array($decodedCarItems)) ? $decodedCarItems : [];
    } elseif (is_array($carItemsRaw)) {
        $carItems = $carItemsRaw;
    } else {
        $carItems = [];
    }
    
    if ($customerId <= 0) {
        throw new InvalidArgumentException('معرف العميل غير صالح');
    }
    
    if (empty($customerItems)) {
        throw new InvalidArgumentException('لم يتم اختيار أي منتجات من مشتريات العميل');
    }
    
    if (empty($carItems)) {
        throw new InvalidArgumentException('لم يتم اختيار أي منتجات من مخزن السيارة');
    }
    
    // التحقق من وجود العميل
    $customer = $db->queryOne(
        "SELECT id, name, balance, created_by FROM customers WHERE id = ? FOR UPDATE",
        [$customerId]
    );
    
    if (!$customer) {
        throw new InvalidArgumentException('العميل غير موجود');
    }
    
    // تحديد sales_rep_id - إذا كان المستخدم مندوب مبيعات، استخدم معرفه دائماً
    $salesRepId = 0;
    
    if ($currentUser['role'] === 'sales') {
        // إذا كان المستخدم مندوب مبيعات، استخدم معرفه دائماً
        $salesRepId = (int)$currentUser['id'];
        
        // التحقق من أن العميل مرتبط بهذا المندوب
        $customerCreatedBy = (int)($customer['created_by'] ?? 0);
        if ($customerCreatedBy !== $salesRepId) {
            throw new InvalidArgumentException('هذا العميل غير مرتبط بك');
        }
    } else {
        // للمديرين والمحاسبين: الحصول على المندوب من العميل أو الفاتورة
        $salesRepId = getSalesRepForCustomer($customerId);
        if (!$salesRepId || $salesRepId <= 0) {
            // محاولة الحصول على sales_rep_id من created_by في العميل
            $salesRepId = (int)($customer['created_by'] ?? 0);
            if ($salesRepId <= 0) {
                throw new RuntimeException('لم يتم العثور على مندوب مسؤول عن هذا العميل');
            }
        }
    }
    
    // الحصول على سيارة المندوب
    $vehicle = $db->queryOne(
        "SELECT v.id as vehicle_id FROM vehicles v WHERE v.driver_id = ? AND v.status = 'active' ORDER BY v.id DESC LIMIT 1",
        [$salesRepId]
    );
    
    if (!$vehicle) {
        throw new RuntimeException('لم يتم العثور على سيارة للمندوب');
    }
    
    $vehicleId = (int)$vehicle['vehicle_id'];
    
    // بدء المعاملة
    $db->beginTransaction();
    
    try {
        // ========== حساب إجمالي المنتجات من العميل ==========
        $customerTotal = 0.0;
        $customerItemsProcessed = [];
        
        foreach ($customerItems as $item) {
            $invoiceItemId = isset($item['invoice_item_id']) ? (int)$item['invoice_item_id'] : 0;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;
            
            if ($invoiceItemId <= 0 || $quantity <= 0 || $unitPrice <= 0) {
                continue;
            }
            
            // التحقق من أن المنتج موجود في فاتورة العميل
            $invoiceItem = $db->queryOne(
                "SELECT ii.*, i.customer_id, i.invoice_number
                 FROM invoice_items ii
                 INNER JOIN invoices i ON i.id = ii.invoice_id
                 WHERE ii.id = ? AND i.customer_id = ? AND i.status != 'cancelled'",
                [$invoiceItemId, $customerId]
            );
            
            if (!$invoiceItem) {
                throw new InvalidArgumentException('منتج من مشتريات العميل غير موجود: ' . $invoiceItemId);
            }
            
            // جلب معلومات batch_number من invoice_item
            // محاولة استخدام البيانات المرسلة من JavaScript أولاً
            $batchNumberId = null;
            $batchNumber = null;
            $finishedBatchId = null;
            
            if (isset($item['finished_batch_ids']) && is_array($item['finished_batch_ids']) && !empty($item['finished_batch_ids'])) {
                // استخدام finished_batch_id المرسل من JavaScript
                $finishedBatchId = (int)$item['finished_batch_ids'][0];
                
                // جلب batch_number_id من finished_batch_id
                $finishedBatch = $db->queryOne(
                    "SELECT fp.batch_id, bn.batch_number 
                     FROM finished_products fp
                     LEFT JOIN batch_numbers bn ON bn.id = fp.batch_id
                     WHERE fp.id = ?",
                    [$finishedBatchId]
                );
                
                if ($finishedBatch) {
                    $batchNumberId = isset($finishedBatch['batch_id']) ? (int)$finishedBatch['batch_id'] : null;
                    $batchNumber = $finishedBatch['batch_number'] ?? null;
                }
            } else {
                // جلب من قاعدة البيانات
                $batchInfo = $db->queryOne(
                    "SELECT sbn.batch_number_id, bn.batch_number, fp.id as finished_batch_id, fp.product_name as finished_product_name
                     FROM sales_batch_numbers sbn
                     INNER JOIN batch_numbers bn ON bn.id = sbn.batch_number_id
                     LEFT JOIN finished_products fp ON fp.batch_id = bn.id
                     WHERE sbn.invoice_item_id = ?
                     LIMIT 1",
                    [$invoiceItemId]
                );
                
                if ($batchInfo) {
                    $batchNumberId = (int)($batchInfo['batch_number_id'] ?? 0);
                    $batchNumber = $batchInfo['batch_number'] ?? null;
                    $finishedBatchId = isset($batchInfo['finished_batch_id']) ? (int)$batchInfo['finished_batch_id'] : null;
                }
            }
            
            // التحقق من الكمية المتاحة
            $availableQty = (float)$invoiceItem['quantity'];
            
            // حساب الكميات المستبدلة سابقاً
            $exchangedQty = 0.0;
            $hasExchangeItemsTable = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_return_items'"));
            
            if ($hasExchangeItemsTable) {
                // التحقق من وجود عمود invoice_item_id قبل استخدامه
                $hasInvoiceItemIdColumn = false;
                try {
                    $columnCheck = $db->queryOne("SHOW COLUMNS FROM exchange_return_items LIKE 'invoice_item_id'");
                    $hasInvoiceItemIdColumn = !empty($columnCheck);
                } catch (Throwable $e) {
                    $hasInvoiceItemIdColumn = false;
                }
                
                if ($hasInvoiceItemIdColumn) {
                    $exchanged = $db->queryOne(
                        "SELECT COALESCE(SUM(eri.quantity), 0) AS exchanged_quantity
                         FROM exchange_return_items eri
                         INNER JOIN product_exchanges pe ON pe.id = eri.exchange_id
                         WHERE eri.invoice_item_id = ? AND pe.status IN ('pending', 'approved', 'completed')",
                        [$invoiceItemId]
                    );
                    $exchangedQty = (float)($exchanged['exchanged_quantity'] ?? 0);
                }
            }
            
            // حساب الكميات المرتجعة
            $returnedQty = 0.0;
            $hasReturnItemsTable = !empty($db->queryOne("SHOW TABLES LIKE 'return_items'"));
            if ($hasReturnItemsTable) {
                $hasInvoiceItemIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'"));
                if ($hasInvoiceItemIdColumn) {
                    $returned = $db->queryOne(
                        "SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                         FROM return_items ri
                         INNER JOIN returns r ON r.id = ri.return_id
                         WHERE ri.invoice_item_id = ? AND r.status IN ('pending', 'approved', 'processed', 'completed')",
                        [$invoiceItemId]
                    );
                    $returnedQty = (float)($returned['returned_quantity'] ?? 0);
                }
            }
            
            $available = max(0, $availableQty - $exchangedQty - $returnedQty);
            
            if ($quantity > $available) {
                throw new InvalidArgumentException('الكمية المطلوبة (' . $quantity . ') أكبر من الكمية المتاحة (' . $available . ')');
            }
            
            $lineTotal = round($quantity * $unitPrice, 2);
            $customerTotal += $lineTotal;
            
            $customerItemsProcessed[] = [
                'invoice_item_id' => $invoiceItemId,
                'product_id' => (int)$invoiceItem['product_id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'invoice_id' => (int)$invoiceItem['invoice_id'],
                'invoice_number' => $invoiceItem['invoice_number'],
                'batch_number_id' => $batchNumberId > 0 ? $batchNumberId : null,
                'batch_number' => $batchNumber,
                'finished_batch_id' => $finishedBatchId > 0 ? $finishedBatchId : null
            ];
        }
        
        // ========== حساب إجمالي المنتجات من السيارة ==========
        $carTotal = 0.0;
        $carItemsProcessed = [];
        
        foreach ($carItems as $item) {
            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;
            
            if ($productId <= 0 || $quantity <= 0 || $unitPrice <= 0) {
                continue;
            }
            
            // التحقق من وجود المنتج في مخزن السيارة
            $inventory = $db->queryOne(
                "SELECT * FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                [$vehicleId, $productId]
            );
            
            if (!$inventory) {
                throw new InvalidArgumentException('المنتج غير موجود في مخزن السيارة: ' . $productId);
            }
            
            $availableQty = (float)$inventory['quantity'];
            
            if ($quantity > $availableQty) {
                throw new InvalidArgumentException('الكمية المطلوبة (' . $quantity . ') أكبر من الكمية المتاحة في مخزن السيارة (' . $availableQty . ')');
            }
            
            $lineTotal = round($quantity * $unitPrice, 2);
            $carTotal += $lineTotal;
            
            $carItemsProcessed[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'finished_batch_id' => isset($item['finished_batch_id']) ? (int)$item['finished_batch_id'] : null,
                'finished_batch_number' => $item['finished_batch_number'] ?? null
            ];
        }
        
        // التقريب
        $customerTotal = round($customerTotal, 2);
        $carTotal = round($carTotal, 2);
        $difference = round($customerTotal - $carTotal, 2);
        
        // ========== تطبيق منطق الاستبدال ==========
        $currentBalance = (float)($customer['balance'] ?? 0);
        $newBalance = $currentBalance;
        $collectionAmount = 0.0;
        $collectionIsNegative = false;
        $salaryDeduction = 0.0;
        $shouldUpdateCommission = false; // تحديد ما إذا كان يجب تحديث نسبة التحصيلات
        
        // الحالة 1: customer_total == car_total
        if (abs($difference) < 0.01) {
            // لا تغيير
            $newBalance = $currentBalance;
            $collectionAmount = 0.0;
            $shouldUpdateCommission = false;
        }
        // الحالة 2: customer_total < car_total
        elseif ($customerTotal < $carTotal) {
            $diff = round($carTotal - $customerTotal, 2);
            // إضافة diff إلى رصيد العميل المدين
            $newBalance = round($currentBalance + $diff, 2);
            // إضافة diff إلى خزنة المندوب
            $collectionAmount = $diff;
            $collectionIsNegative = false;
            // لا يجب تحديث نسبة التحصيلات لأن العميل مدين وليس هناك تحصيل فعلي
            $shouldUpdateCommission = false;
        }
        // الحالة 3: customer_total > car_total
        else {
            $diff = round($customerTotal - $carTotal, 2);
            // إذا كان رصيد العميل == 0 أو رصيد دائن
            if ($currentBalance <= 0) {
                // إضافة diff إلى الرصيد الدائن
                $newBalance = round($currentBalance - $diff, 2);
            } else {
                // خصم من الرصيد المدين
                $newBalance = round($currentBalance - $diff, 2);
                // إذا أصبح سالباً، نجعله رصيد دائن
                if ($newBalance < 0) {
                    // الرصيد الدائن هو القيمة المطلقة للرقم السالب
                    // لا حاجة لتغيير، فقط نتركه سالب
                }
            }
            // خصم diff من خزنة المندوب
            $collectionAmount = $diff;
            $collectionIsNegative = true;
            // خصم 2% من diff من راتب المندوب
            $salaryDeduction = round($diff * 0.02, 2);
            // لا يجب تحديث نسبة التحصيلات في هذه الحالة لأننا نخصم من خزنة المندوب
            $shouldUpdateCommission = false;
        }
        
        // ========== تحديث مخزون السيارة ==========
        // إضافة الكميات المسترجعة من العميل
        foreach ($customerItemsProcessed as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];
            $finishedBatchId = $item['finished_batch_id'] ?? null;
            $batchNumber = $item['batch_number'] ?? null;
            $batchNumberId = $item['batch_number_id'] ?? null;
            
            // إذا كان المنتج يحتوي على batch number، نبحث عن نفس التشغيلة في مخزن السيارة
            if ($finishedBatchId && $finishedBatchId > 0) {
                // البحث عن نفس التشغيلة في مخزن السيارة
                $existingInventory = $db->queryOne(
                    "SELECT * FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ? AND finished_batch_id = ?",
                    [$vehicleId, $productId, $finishedBatchId]
                );
                
                if ($existingInventory) {
                    // تحديث الكمية في نفس التشغيلة
                    $db->execute(
                        "UPDATE vehicle_inventory SET quantity = quantity + ? WHERE id = ?",
                        [$quantity, $existingInventory['id']]
                    );
                } else {
                    // إنشاء سجل جديد مع معلومات التشغيلة
                    $finishedProduct = $db->queryOne(
                        "SELECT fp.*, bn.batch_number 
                         FROM finished_products fp
                         LEFT JOIN batch_numbers bn ON bn.id = fp.batch_id
                         WHERE fp.id = ?",
                        [$finishedBatchId]
                    );
                    
                    $product = $db->queryOne("SELECT * FROM products WHERE id = ?", [$productId]);
                    $warehouseId = null;
                    
                    // الحصول على warehouse_id للسيارة
                    $warehouse = $db->queryOne(
                        "SELECT id FROM warehouses WHERE vehicle_id = ? AND warehouse_type = 'vehicle' LIMIT 1",
                        [$vehicleId]
                    );
                    if ($warehouse) {
                        $warehouseId = (int)$warehouse['id'];
                    }
                    
                    $db->execute(
                        "INSERT INTO vehicle_inventory (
                            vehicle_id, warehouse_id, product_id, product_name, product_unit, 
                            product_unit_price, finished_batch_id, finished_batch_number,
                            finished_production_date, finished_quantity_produced, quantity, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            $vehicleId,
                            $warehouseId,
                            $productId,
                            $finishedProduct['product_name'] ?? ($product['name'] ?? null),
                            $finishedProduct['unit'] ?? ($product['unit'] ?? 'قطعة'),
                            $item['unit_price'],
                            $finishedBatchId,
                            $batchNumber,
                            $finishedProduct['production_date'] ?? null,
                            $finishedProduct['quantity_produced'] ?? null,
                            $quantity
                        ]
                    );
                }
            } else {
                // المنتج بدون batch number - البحث عن أي سجل بنفس product_id بدون batch
                $existingInventory = $db->queryOne(
                    "SELECT * FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ? 
                     AND (finished_batch_id IS NULL OR finished_batch_id = 0)",
                    [$vehicleId, $productId]
                );
                
                if ($existingInventory) {
                    // تحديث الكمية
                    $db->execute(
                        "UPDATE vehicle_inventory SET quantity = quantity + ? WHERE id = ?",
                        [$quantity, $existingInventory['id']]
                    );
                } else {
                    // الحصول على بيانات المنتج
                    $product = $db->queryOne("SELECT * FROM products WHERE id = ?", [$productId]);
                    if ($product) {
                        $warehouseId = null;
                        
                        // الحصول على warehouse_id للسيارة
                        $warehouse = $db->queryOne(
                            "SELECT id FROM warehouses WHERE vehicle_id = ? AND warehouse_type = 'vehicle' LIMIT 1",
                            [$vehicleId]
                        );
                        if ($warehouse) {
                            $warehouseId = (int)$warehouse['id'];
                        }
                        
                        // إضافة سجل جديد
                        $db->execute(
                            "INSERT INTO vehicle_inventory (vehicle_id, warehouse_id, product_id, product_name, product_unit, product_unit_price, quantity, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                            [
                                $vehicleId,
                                $warehouseId,
                                $productId,
                                $product['name'] ?? null,
                                $product['unit'] ?? 'قطعة',
                                $item['unit_price'],
                                $quantity
                            ]
                        );
                    }
                }
            }
        }
        
        // خصم الكميات المعطاة للعميل
        foreach ($carItemsProcessed as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];
            $finishedBatchId = $item['finished_batch_id'] ?? null;
            
            if ($finishedBatchId && $finishedBatchId > 0) {
                // خصم من نفس التشغيلة
                $inventory = $db->queryOne(
                    "SELECT * FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ? AND finished_batch_id = ? FOR UPDATE",
                    [$vehicleId, $productId, $finishedBatchId]
                );
                
                if (!$inventory) {
                    throw new RuntimeException('التشغيلة غير موجودة في مخزن السيارة');
                }
                
                $currentQty = (float)$inventory['quantity'];
                if ($quantity > $currentQty) {
                    throw new RuntimeException('الكمية المطلوبة (' . $quantity . ') أكبر من الكمية المتاحة في التشغيلة (' . $currentQty . ')');
                }
                
                $db->execute(
                    "UPDATE vehicle_inventory SET quantity = quantity - ? WHERE id = ?",
                    [$quantity, $inventory['id']]
                );
            } else {
                // خصم من أي سجل بدون batch number
                $inventory = $db->queryOne(
                    "SELECT * FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ? 
                     AND (finished_batch_id IS NULL OR finished_batch_id = 0) FOR UPDATE",
                    [$vehicleId, $productId]
                );
                
                if (!$inventory) {
                    throw new RuntimeException('المنتج غير موجود في مخزن السيارة');
                }
                
                $currentQty = (float)$inventory['quantity'];
                if ($quantity > $currentQty) {
                    throw new RuntimeException('الكمية المطلوبة (' . $quantity . ') أكبر من الكمية المتاحة (' . $currentQty . ')');
                }
                
                $db->execute(
                    "UPDATE vehicle_inventory SET quantity = quantity - ? WHERE id = ?",
                    [$quantity, $inventory['id']]
                );
            }
            
            // التحقق من أن الكمية لا تصبح سالبة
            $updatedInventory = $db->queryOne(
                "SELECT quantity FROM vehicle_inventory WHERE id = ?",
                [$inventory['id']]
            );
            
            if ($updatedInventory && (float)$updatedInventory['quantity'] < 0) {
                throw new RuntimeException('الكمية في مخزن السيارة أصبحت سالبة');
            }
        }
        
        // ========== تحديث رصيد العميل ==========
        if (abs($newBalance - $currentBalance) > 0.01) {
            $db->execute(
                "UPDATE customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
        }
        
        // ========== تسجيل عملية الاستبدال ==========
        $exchangeNumber = 'EXCH-' . date('Ymd') . '-' . str_pad((string)time(), 8, '0', STR_PAD_LEFT);
        
        // التحقق من وجود جدول product_exchanges
        $hasExchangeTable = !empty($db->queryOne("SHOW TABLES LIKE 'product_exchanges'"));
        
        if ($hasExchangeTable) {
            $exchangeId = null;
            
            try {
                $db->execute(
                    "INSERT INTO product_exchanges (
                        exchange_number, customer_id, sales_rep_id, exchange_date,
                        original_total, new_total, difference_amount, status, created_by, created_at
                    ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, 'completed', ?, NOW())",
                    [
                        $exchangeNumber,
                        $customerId,
                        $salesRepId,
                        $customerTotal,
                        $carTotal,
                        $difference,
                        $currentUser['id']
                    ]
                );
                
                $exchangeId = $db->getLastInsertId();
                
                // تسجيل عناصر الاستبدال المرتجعة (من العميل)
                $hasExchangeReturnItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_return_items'"));
                if ($hasExchangeReturnItems && $exchangeId) {
                    // التحقق من وجود عمود invoice_item_id
                    $hasInvoiceItemIdColumn = false;
                    try {
                        $columnCheck = $db->queryOne("SHOW COLUMNS FROM exchange_return_items LIKE 'invoice_item_id'");
                        $hasInvoiceItemIdColumn = !empty($columnCheck);
                    } catch (Throwable $e) {
                        $hasInvoiceItemIdColumn = false;
                    }
                    
                    foreach ($customerItemsProcessed as $item) {
                        // التحقق من وجود عمود batch_number_id
                        $hasBatchNumberIdColumn = false;
                        try {
                            $batchColumnCheck = $db->queryOne("SHOW COLUMNS FROM exchange_return_items LIKE 'batch_number_id'");
                            $hasBatchNumberIdColumn = !empty($batchColumnCheck);
                        } catch (Throwable $e) {
                            $hasBatchNumberIdColumn = false;
                        }
                        
                        $fields = [];
                        $placeholders = [];
                        $values = [];
                        
                        $fields[] = 'exchange_id';
                        $placeholders[] = '?';
                        $values[] = $exchangeId;
                        
                        $fields[] = 'product_id';
                        $placeholders[] = '?';
                        $values[] = $item['product_id'];
                        
                        if ($hasInvoiceItemIdColumn) {
                            $fields[] = 'invoice_item_id';
                            $placeholders[] = '?';
                            $values[] = $item['invoice_item_id'];
                        }
                        
                        if ($hasBatchNumberIdColumn && isset($item['batch_number_id']) && $item['batch_number_id'] > 0) {
                            $fields[] = 'batch_number_id';
                            $placeholders[] = '?';
                            $values[] = $item['batch_number_id'];
                        }
                        
                        $fields[] = 'quantity';
                        $placeholders[] = '?';
                        $values[] = $item['quantity'];
                        
                        $fields[] = 'unit_price';
                        $placeholders[] = '?';
                        $values[] = $item['unit_price'];
                        
                        $fields[] = 'total_price';
                        $placeholders[] = '?';
                        $values[] = $item['total_price'];
                        
                        $fields[] = 'created_at';
                        $placeholders[] = 'NOW()';
                        
                        $db->execute(
                            "INSERT INTO exchange_return_items (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")",
                            $values
                        );
                    }
                }
                
                // تسجيل عناصر الاستبدال الجديدة (من السيارة)
                $hasExchangeNewItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_new_items'"));
                if ($hasExchangeNewItems && $exchangeId) {
                    // التحقق من وجود عمود batch_number_id
                    $hasBatchNumberIdColumn = false;
                    try {
                        $batchColumnCheck = $db->queryOne("SHOW COLUMNS FROM exchange_new_items LIKE 'batch_number_id'");
                        $hasBatchNumberIdColumn = !empty($batchColumnCheck);
                    } catch (Throwable $e) {
                        $hasBatchNumberIdColumn = false;
                    }
                    
                    foreach ($carItemsProcessed as $item) {
                        $fields = [];
                        $placeholders = [];
                        $values = [];
                        
                        $fields[] = 'exchange_id';
                        $placeholders[] = '?';
                        $values[] = $exchangeId;
                        
                        $fields[] = 'product_id';
                        $placeholders[] = '?';
                        $values[] = $item['product_id'];
                        
                        if ($hasBatchNumberIdColumn && isset($item['finished_batch_id']) && $item['finished_batch_id'] > 0) {
                            // الحصول على batch_number_id من finished_batch_id
                            $batchInfo = $db->queryOne(
                                "SELECT batch_id FROM finished_products WHERE id = ?",
                                [$item['finished_batch_id']]
                            );
                            if ($batchInfo && isset($batchInfo['batch_id']) && $batchInfo['batch_id'] > 0) {
                                $fields[] = 'batch_number_id';
                                $placeholders[] = '?';
                                $values[] = (int)$batchInfo['batch_id'];
                            }
                        }
                        
                        $fields[] = 'quantity';
                        $placeholders[] = '?';
                        $values[] = $item['quantity'];
                        
                        $fields[] = 'unit_price';
                        $placeholders[] = '?';
                        $values[] = $item['unit_price'];
                        
                        $fields[] = 'total_price';
                        $placeholders[] = '?';
                        $values[] = $item['total_price'];
                        
                        $fields[] = 'created_at';
                        $placeholders[] = 'NOW()';
                        
                        $db->execute(
                            "INSERT INTO exchange_new_items (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")",
                            $values
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('Error creating exchange record: ' . $e->getMessage());
                // لا نوقف العملية إذا فشل تسجيل الاستبدال
            }
        }
        
        // ========== تسجيل في جدول exchanges الجديد ==========
        $hasExchangesTable = !empty($db->queryOne("SHOW TABLES LIKE 'exchanges'"));
        if ($hasExchangesTable) {
            try {
                // الحصول على invoice_id من أول منتج مرجع
                $invoiceId = null;
                if (!empty($customerItemsProcessed)) {
                    $firstItem = $customerItemsProcessed[0];
                    if (isset($firstItem['invoice_id'])) {
                        $invoiceId = (int)$firstItem['invoice_id'];
                    } else if (isset($firstItem['invoice_item_id'])) {
                        $invoiceItem = $db->queryOne(
                            "SELECT invoice_id FROM invoice_items WHERE id = ?",
                            [(int)$firstItem['invoice_item_id']]
                        );
                        if ($invoiceItem) {
                            $invoiceId = (int)$invoiceItem['invoice_id'];
                        }
                    }
                }
                
                // إنشاء سجل في جدول exchanges
                // التأكد من تسجيل sales_rep_id بشكل صحيح - إذا كان المستخدم مندوب، يجب أن يكون sales_rep_id = معرفه
                $finalSalesRepId = null;
                if ($salesRepId > 0) {
                    $finalSalesRepId = $salesRepId;
                } elseif ($currentUser['role'] === 'sales') {
                    // إذا كان المستخدم مندوب ولم يتم تحديد sales_rep_id، استخدم معرفه
                    $finalSalesRepId = (int)$currentUser['id'];
                }
                
                $db->execute(
                    "INSERT INTO exchanges
                     (exchange_number, invoice_id, customer_id, sales_rep_id, exchange_date, exchange_type,
                      original_total, new_total, difference_amount, status, notes, created_by, approved_by, approved_at)
                     VALUES (?, ?, ?, ?, CURDATE(), 'different_product', ?, ?, ?, 'completed', ?, ?, ?, NOW())",
                    [
                        $exchangeNumber,
                        $invoiceId,
                        $customerId,
                        $finalSalesRepId,
                        $customerTotal,
                        $carTotal,
                        $difference,
                        null, // notes
                        $currentUser['id'],
                        $currentUser['id'], // approved_by = created_by
                    ]
                );
                
                $newExchangeId = (int)$db->getLastInsertId();
                
                // نسخ عناصر الاستبدال من product_exchanges إلى exchanges
                // نسخ exchange_return_items
                if ($hasExchangeReturnItems && isset($exchangeId) && $exchangeId && $newExchangeId) {
                    try {
                        $returnItems = $db->query(
                            "SELECT * FROM exchange_return_items WHERE exchange_id = ?",
                            [$exchangeId]
                        );
                        
                        foreach ($returnItems as $returnItem) {
                            $fields = [];
                            $placeholders = [];
                            $values = [];
                            
                            $fields[] = 'exchange_id';
                            $placeholders[] = '?';
                            $values[] = $newExchangeId;
                            
                            if (isset($returnItem['invoice_item_id'])) {
                                $fields[] = 'invoice_item_id';
                                $placeholders[] = '?';
                                $values[] = $returnItem['invoice_item_id'];
                            }
                            
                            $fields[] = 'product_id';
                            $placeholders[] = '?';
                            $values[] = $returnItem['product_id'];
                            
                            if (isset($returnItem['batch_number_id']) && $returnItem['batch_number_id'] > 0) {
                                $fields[] = 'batch_number_id';
                                $placeholders[] = '?';
                                $values[] = $returnItem['batch_number_id'];
                            }
                            
                            if (isset($returnItem['batch_number'])) {
                                $fields[] = 'batch_number';
                                $placeholders[] = '?';
                                $values[] = $returnItem['batch_number'];
                            }
                            
                            $fields[] = 'quantity';
                            $placeholders[] = '?';
                            $values[] = $returnItem['quantity'];
                            
                            $fields[] = 'unit_price';
                            $placeholders[] = '?';
                            $values[] = $returnItem['unit_price'];
                            
                            $fields[] = 'total_price';
                            $placeholders[] = '?';
                            $values[] = $returnItem['total_price'];
                            
                            // التحقق من وجود جدول exchange_return_items المرتبط بـ exchanges
                            $hasExchangesReturnItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_return_items'"));
                            if ($hasExchangesReturnItems) {
                                // التحقق من وجود exchange_id في exchange_return_items
                                $hasExchangeIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM exchange_return_items LIKE 'exchange_id'"));
                                if ($hasExchangeIdColumn) {
                                    // التحقق من أن exchange_id يشير إلى جدول exchanges
                                    // نستخدم exchange_id الجديد مباشرة
                                    $db->execute(
                                        "INSERT INTO exchange_return_items (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")",
                                        $values
                                    );
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Error copying exchange_return_items to exchanges table: ' . $e->getMessage());
                    }
                }
                
                // نسخ exchange_new_items
                if ($hasExchangeNewItems && isset($exchangeId) && $exchangeId && $newExchangeId) {
                    try {
                        $newItems = $db->query(
                            "SELECT * FROM exchange_new_items WHERE exchange_id = ?",
                            [$exchangeId]
                        );
                        
                        foreach ($newItems as $newItem) {
                            $fields = [];
                            $placeholders = [];
                            $values = [];
                            
                            $fields[] = 'exchange_id';
                            $placeholders[] = '?';
                            $values[] = $newExchangeId;
                            
                            $fields[] = 'product_id';
                            $placeholders[] = '?';
                            $values[] = $newItem['product_id'];
                            
                            if (isset($newItem['batch_number_id']) && $newItem['batch_number_id'] > 0) {
                                $fields[] = 'batch_number_id';
                                $placeholders[] = '?';
                                $values[] = $newItem['batch_number_id'];
                            }
                            
                            if (isset($newItem['batch_number'])) {
                                $fields[] = 'batch_number';
                                $placeholders[] = '?';
                                $values[] = $newItem['batch_number'];
                            }
                            
                            $fields[] = 'quantity';
                            $placeholders[] = '?';
                            $values[] = $newItem['quantity'];
                            
                            $fields[] = 'unit_price';
                            $placeholders[] = '?';
                            $values[] = $newItem['unit_price'];
                            
                            $fields[] = 'total_price';
                            $placeholders[] = '?';
                            $values[] = $newItem['total_price'];
                            
                            // التحقق من وجود جدول exchange_new_items المرتبط بـ exchanges
                            $hasExchangesNewItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_new_items'"));
                            if ($hasExchangesNewItems) {
                                // التحقق من وجود exchange_id في exchange_new_items
                                $hasExchangeIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM exchange_new_items LIKE 'exchange_id'"));
                                if ($hasExchangeIdColumn) {
                                    $db->execute(
                                        "INSERT INTO exchange_new_items (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")",
                                        $values
                                    );
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Error copying exchange_new_items to exchanges table: ' . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                error_log('Error creating exchange record in exchanges table: ' . $e->getMessage());
                // لا نوقف العملية إذا فشل تسجيل الاستبدال
            }
        }
        
        // ========== تحديث خزنة المندوب ==========
        if ($collectionAmount > 0.01) {
            $hasCollectionsTable = !empty($db->queryOne("SHOW TABLES LIKE 'collections'"));
            
            if ($hasCollectionsTable) {
                $columns = $db->query("SHOW COLUMNS FROM collections") ?? [];
                $columnNames = [];
                foreach ($columns as $column) {
                    if (!empty($column['Field'])) {
                        $columnNames[] = $column['Field'];
                    }
                }
                
                $hasStatus = in_array('status', $columnNames, true);
                $hasCollectionNumber = in_array('collection_number', $columnNames, true);
                $hasNotes = in_array('notes', $columnNames, true);
                
                $fields = ['customer_id', 'amount', 'date', 'payment_method', 'collected_by'];
                $values = [
                    $customerId,
                    $collectionIsNegative ? -$collectionAmount : $collectionAmount,
                    date('Y-m-d'),
                    'cash',
                    $salesRepId
                ];
                
                if ($hasCollectionNumber) {
                    array_unshift($fields, 'collection_number');
                    array_unshift($values, 'REP-' . $exchangeNumber);
                }
                
                if ($hasNotes) {
                    $fields[] = 'notes';
                    $values[] = 'استبدال - ' . ($collectionIsNegative ? 'خصم' : 'إضافة') . ' - ' . $exchangeNumber;
                }
                
                if ($hasStatus) {
                    $fields[] = 'status';
                    $values[] = 'approved';
                }
                
                $placeholders = array_fill(0, count($values), '?');
                
                $db->execute(
                    "INSERT INTO collections (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")",
                    $values
                );
                
                // تحديث المبيعات للمندوب فقط إذا كان هناك تحصيل فعلي (وليس دين)
                // في حالة الاستبدال، لا يجب تحديث نسبة التحصيلات لأن:
                // - الحالة 2: العميل مدين (لا تحصيل فعلي)
                // - الحالة 3: نخصم من خزنة المندوب (لا تحصيل فعلي)
                if ($shouldUpdateCommission) {
                    try {
                        refreshSalesCommissionForUser(
                            $salesRepId,
                            date('Y-m-d'),
                            'تحديث تلقائي بعد عملية استبدال'
                        );
                    } catch (Throwable $e) {
                        error_log('Error updating sales commission: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // ========== خصم 2% من راتب المندوب (الحالة الثالثة) ==========
        if ($salaryDeduction > 0.01) {
            $currentDate = date('Y-m-d');
            $month = (int)date('n');
            $year = (int)date('Y');
            
            $summary = getSalarySummary($salesRepId, $month, $year);
            
            if (!$summary['exists']) {
                $creation = createOrUpdateSalary($salesRepId, $month, $year);
                if ($creation['success']) {
                    $summary = getSalarySummary($salesRepId, $month, $year);
                }
            }
            
            if ($summary['exists']) {
                $salary = $summary['salary'];
                $salaryId = (int)($salary['id'] ?? 0);
                
                if ($salaryId > 0) {
                    $currentDeductions = (float)($salary['deductions'] ?? 0);
                    $newDeductions = round($currentDeductions + $salaryDeduction, 2);
                    $currentTotal = (float)($salary['total_amount'] ?? 0);
                    $newTotal = round($currentTotal - $salaryDeduction, 2);
                    
                    $db->execute(
                        "UPDATE salaries SET deductions = ?, total_amount = ? WHERE id = ?",
                        [$newDeductions, $newTotal, $salaryId]
                    );
                }
            }
        }
        
        // تسجيل Audit Log
        logAudit(
            $currentUser['id'],
            'process_replacement',
            'customer',
            $customerId,
            null,
            [
                'exchange_number' => $exchangeNumber,
                'customer_total' => $customerTotal,
                'car_total' => $carTotal,
                'difference' => $difference,
                'old_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'collection_amount' => $collectionAmount,
                'salary_deduction' => $salaryDeduction
            ]
        );
        
        // إتمام المعاملة
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تم تنفيذ عملية الاستبدال بنجاح',
            'exchange_number' => $exchangeNumber,
            'customer_total' => $customerTotal,
            'car_total' => $carTotal,
            'difference' => $difference,
            'new_balance' => $newBalance
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (InvalidArgumentException $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('process_replacement error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء معالجة الاستبدال: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

