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
    $customerItems = isset($_POST['customer_items']) && is_array($_POST['customer_items']) ? $_POST['customer_items'] : [];
    $carItems = isset($_POST['car_items']) && is_array($_POST['car_items']) ? $_POST['car_items'] : [];
    
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
    
    // التحقق من الصلاحيات
    if ($currentUser['role'] === 'sales') {
        if ((int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
            throw new InvalidArgumentException('هذا العميل غير مرتبط بك');
        }
    }
    
    // الحصول على المندوب
    $salesRepId = getSalesRepForCustomer($customerId);
    if (!$salesRepId) {
        throw new RuntimeException('لم يتم العثور على مندوب مسؤول عن هذا العميل');
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
            
            // التحقق من الكمية المتاحة
            $availableQty = (float)$invoiceItem['quantity'];
            
            // حساب الكميات المستبدلة سابقاً
            $exchangedQty = 0.0;
            $hasExchangeItemsTable = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_return_items'"));
            
            if ($hasExchangeItemsTable) {
                $exchanged = $db->queryOne(
                    "SELECT COALESCE(SUM(eri.quantity), 0) AS exchanged_quantity
                     FROM exchange_return_items eri
                     INNER JOIN product_exchanges pe ON pe.id = eri.exchange_id
                     WHERE eri.invoice_item_id = ? AND pe.status IN ('pending', 'approved', 'completed')",
                    [$invoiceItemId]
                );
                $exchangedQty = (float)($exchanged['exchanged_quantity'] ?? 0);
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
                'invoice_number' => $invoiceItem['invoice_number']
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
        
        // الحالة 1: customer_total == car_total
        if (abs($difference) < 0.01) {
            // لا تغيير
            $newBalance = $currentBalance;
            $collectionAmount = 0.0;
        }
        // الحالة 2: customer_total < car_total
        elseif ($customerTotal < $carTotal) {
            $diff = round($carTotal - $customerTotal, 2);
            // إضافة diff إلى رصيد العميل المدين
            $newBalance = round($currentBalance + $diff, 2);
            // إضافة diff إلى خزنة المندوب
            $collectionAmount = $diff;
            $collectionIsNegative = false;
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
        }
        
        // ========== تحديث مخزون السيارة ==========
        // إضافة الكميات المسترجعة من العميل
        foreach ($customerItemsProcessed as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];
            
            // البحث عن المنتج في مخزن السيارة
            $existingInventory = $db->queryOne(
                "SELECT * FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                [$vehicleId, $productId]
            );
            
            if ($existingInventory) {
                // تحديث الكمية
                $db->execute(
                    "UPDATE vehicle_inventory SET quantity = quantity + ? WHERE vehicle_id = ? AND product_id = ?",
                    [$quantity, $vehicleId, $productId]
                );
            } else {
                // الحصول على بيانات المنتج
                $product = $db->queryOne("SELECT * FROM products WHERE id = ?", [$productId]);
                if ($product) {
                    // إضافة سجل جديد
                    $db->execute(
                        "INSERT INTO vehicle_inventory (vehicle_id, product_id, product_name, product_unit, product_unit_price, quantity, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())",
                        [
                            $vehicleId,
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
        
        // خصم الكميات المعطاة للعميل
        foreach ($carItemsProcessed as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];
            
            $db->execute(
                "UPDATE vehicle_inventory SET quantity = quantity - ? WHERE vehicle_id = ? AND product_id = ?",
                [$quantity, $vehicleId, $productId]
            );
            
            // التحقق من أن الكمية لا تصبح سالبة
            $updatedInventory = $db->queryOne(
                "SELECT quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                [$vehicleId, $productId]
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
                        if ($hasInvoiceItemIdColumn) {
                            $db->execute(
                                "INSERT INTO exchange_return_items (
                                    exchange_id, product_id, invoice_item_id, quantity, unit_price, total_price, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                                [
                                    $exchangeId,
                                    $item['product_id'],
                                    $item['invoice_item_id'],
                                    $item['quantity'],
                                    $item['unit_price'],
                                    $item['total_price']
                                ]
                            );
                        } else {
                            $db->execute(
                                "INSERT INTO exchange_return_items (
                                    exchange_id, product_id, quantity, unit_price, total_price, created_at
                                ) VALUES (?, ?, ?, ?, ?, NOW())",
                                [
                                    $exchangeId,
                                    $item['product_id'],
                                    $item['quantity'],
                                    $item['unit_price'],
                                    $item['total_price']
                                ]
                            );
                        }
                    }
                }
                
                // تسجيل عناصر الاستبدال الجديدة (من السيارة)
                $hasExchangeNewItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_new_items'"));
                if ($hasExchangeNewItems && $exchangeId) {
                    foreach ($carItemsProcessed as $item) {
                        $db->execute(
                            "INSERT INTO exchange_new_items (
                                exchange_id, product_id, quantity, unit_price, total_price, created_at
                            ) VALUES (?, ?, ?, ?, ?, NOW())",
                            [
                                $exchangeId,
                                $item['product_id'],
                                $item['quantity'],
                                $item['unit_price'],
                                $item['total_price']
                            ]
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('Error creating exchange record: ' . $e->getMessage());
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
                
                // تحديث المبيعات للمندوب
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

