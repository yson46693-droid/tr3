<?php
/**
 * API for Exchange Requests
 * Handles exchange creation, customer selection, purchase history, and car inventory
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/returns_system.php';
require_once __DIR__ . '/../includes/vehicle_inventory.php';
require_once __DIR__ . '/../includes/path_helper.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJson(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

requireRole(['sales', 'manager', 'accountant']);

try {
    switch ($action) {
        case 'get_customers':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleGetCustomers();
            break;
            
        case 'get_purchase_history':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleGetPurchaseHistory();
            break;
            
        case 'get_car_inventory':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleGetCarInventory();
            break;
            
        case 'create':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST لهذا الإجراء'], 405);
            }
            handleCreateExchange();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('exchange_requests API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع أثناء المعالجة: ' . $e->getMessage()], 500);
}

function returnJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get customers assigned to current sales rep
 */
function handleGetCustomers(): void
{
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $db = db();
    
    $salesRepId = null;
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
    } elseif (isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] > 0) {
        $salesRepId = (int)$_GET['sales_rep_id'];
    }
    
    $search = trim($_GET['search'] ?? '');
    
    $sql = "SELECT c.id, c.name, c.phone, c.address, c.balance, c.status
            FROM customers c
            WHERE c.status = 'active'";
    
    $params = [];
    
    if ($salesRepId) {
        $sql .= " AND c.created_by = ?";
        $params[] = $salesRepId;
    }
    
    if ($search !== '') {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY c.name ASC LIMIT 100";
    
    $customers = $db->query($sql, $params);
    
    $result = [];
    foreach ($customers as $customer) {
        $balance = (float)($customer['balance'] ?? 0);
        $result[] = [
            'id' => (int)$customer['id'],
            'name' => $customer['name'],
            'phone' => $customer['phone'] ?? '',
            'address' => $customer['address'] ?? '',
            'balance' => $balance,
            'debt' => $balance > 0 ? $balance : 0,
            'credit' => $balance < 0 ? abs($balance) : 0,
        ];
    }
    
    returnJson(['success' => true, 'customers' => $result]);
}

/**
 * Get customer purchase history with batch numbers
 */
function handleGetPurchaseHistory(): void
{
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $salesRepId = null;
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
    }
    
    $purchaseHistory = getCustomerPurchaseHistory($customerId, $salesRepId);
    
    returnJson(['success' => true, 'purchase_history' => $purchaseHistory]);
}

/**
 * Get salesman car inventory
 */
function handleGetCarInventory(): void
{
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $salesRepId = isset($_GET['sales_rep_id']) ? (int)$_GET['sales_rep_id'] : 0;
    
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
    }
    
    if ($salesRepId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المندوب غير صالح'], 422);
    }
    
    $db = db();
    
    // Get vehicle ID
    $vehicle = $db->queryOne(
        "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
        [$salesRepId]
    );
    
    if (!$vehicle) {
        returnJson(['success' => true, 'inventory' => []]);
    }
    
    $vehicleId = (int)$vehicle['id'];
    
    // Get inventory
    $inventory = $db->query(
        "SELECT vi.*, p.name as product_name, p.unit
         FROM vehicle_inventory vi
         LEFT JOIN products p ON vi.product_id = p.id
         WHERE vi.vehicle_id = ? AND vi.quantity > 0
         ORDER BY p.name ASC",
        [$vehicleId]
    );
    
    $result = [];
    foreach ($inventory as $item) {
        $result[] = [
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? '',
            'quantity' => (float)$item['quantity'],
            'unit_price' => (float)($item['product_unit_price'] ?? $item['manager_unit_price'] ?? 0),
            'batch_number' => $item['finished_batch_number'] ?? null,
            'batch_number_id' => isset($item['finished_batch_id']) ? (int)$item['finished_batch_id'] : null,
        ];
    }
    
    returnJson(['success' => true, 'inventory' => $result]);
}

/**
 * Create exchange request
 */
function handleCreateExchange(): void
{
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $payload = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($payload)) {
        returnJson(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], 400);
    }
    
    $customerId = isset($payload['customer_id']) ? (int)$payload['customer_id'] : 0;
    $returnItems = $payload['return_items'] ?? [];
    $replacementItems = $payload['replacement_items'] ?? [];
    $notes = trim($payload['notes'] ?? '');
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'يجب اختيار عميل'], 422);
    }
    
    if (empty($returnItems) || !is_array($returnItems)) {
        returnJson(['success' => false, 'message' => 'يجب اختيار منتجات للإرجاع'], 422);
    }
    
    if (empty($replacementItems) || !is_array($replacementItems)) {
        returnJson(['success' => false, 'message' => 'يجب اختيار منتجات للاستبدال'], 422);
    }
    
    $db = db();
    $conn = $db->getConnection();
    
    // Verify customer
    $customer = $db->queryOne(
        "SELECT id, name, created_by, balance FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    // Verify sales rep ownership
    $salesRepId = (int)($customer['created_by'] ?? 0);
    if ($currentUser['role'] === 'sales' && $salesRepId !== (int)$currentUser['id']) {
        returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
    }
    
    // Get sales rep ID
    if ($salesRepId <= 0 && in_array($currentUser['role'], ['manager', 'accountant'], true)) {
        $firstInvoice = $db->queryOne(
            "SELECT sales_rep_id FROM invoices WHERE customer_id = ? ORDER BY id ASC LIMIT 1",
            [$customerId]
        );
        $salesRepId = (int)($firstInvoice['sales_rep_id'] ?? 0);
    }
    
    $conn->begin_transaction();
    
    try {
        // Calculate old value and new value
        $oldValue = 0.0;
        $newValue = 0.0;
        
        // Process return items
        $processedReturnItems = [];
        foreach ($returnItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $invoiceItemId = (int)($item['invoice_item_id'] ?? 0);
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            
            if ($invoiceItemId <= 0 || $quantity <= 0) {
                continue;
            }
            
            // Get invoice item details
            $invoiceItem = $db->queryOne(
                "SELECT ii.*, i.id as invoice_id, p.name as product_name
                 FROM invoice_items ii
                 INNER JOIN invoices i ON ii.invoice_id = i.id
                 LEFT JOIN products p ON ii.product_id = p.id
                 WHERE ii.id = ? AND i.customer_id = ?",
                [$invoiceItemId, $customerId]
            );
            
            if (!$invoiceItem) {
                throw new RuntimeException('عنصر الفاتورة غير موجود');
            }
            
            // Get batch numbers
            $batchNumbers = $db->query(
                "SELECT sbn.batch_number_id, bn.batch_number
                 FROM sales_batch_numbers sbn
                 INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                 WHERE sbn.invoice_item_id = ?
                 ORDER BY bn.id ASC",
                [$invoiceItemId]
            );
            
            $batchNumberIds = [];
            $batchNumberStrings = [];
            foreach ($batchNumbers as $bn) {
                $batchNumberIds[] = (int)$bn['batch_number_id'];
                $batchNumberStrings[] = $bn['batch_number'];
            }
            
            $unitPrice = (float)$invoiceItem['unit_price'];
            $lineTotal = round($quantity * $unitPrice, 2);
            $oldValue += $lineTotal;
            
            $processedReturnItems[] = [
                'invoice_item_id' => $invoiceItemId,
                'invoice_id' => (int)$invoiceItem['invoice_id'],
                'product_id' => (int)$invoiceItem['product_id'],
                'product_name' => $invoiceItem['product_name'] ?? 'غير معروف',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'batch_number_ids' => $batchNumberIds,
                'batch_numbers' => implode(', ', $batchNumberStrings),
            ];
        }
        
        // Process replacement items
        $processedReplacementItems = [];
        foreach ($replacementItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;
            
            if ($productId <= 0 || $quantity <= 0 || $unitPrice <= 0) {
                continue;
            }
            
            // Verify inventory availability
            $vehicle = $db->queryOne(
                "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$salesRepId]
            );
            
            if (!$vehicle) {
                throw new RuntimeException('لا توجد سيارة مرتبطة بهذا المندوب');
            }
            
            $vehicleId = (int)$vehicle['id'];
            
            $inventory = $db->queryOne(
                "SELECT quantity, finished_batch_number, finished_batch_id FROM vehicle_inventory 
                 WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                [$vehicleId, $productId]
            );
            
            if (!$inventory) {
                throw new RuntimeException("المنتج غير موجود في مخزن السيارة");
            }
            
            $availableQty = (float)$inventory['quantity'];
            if ($availableQty < $quantity) {
                throw new RuntimeException("الكمية المتاحة ({$availableQty}) أقل من المطلوب ({$quantity})");
            }
            
            $lineTotal = round($quantity * $unitPrice, 2);
            $newValue += $lineTotal;
            
            $processedReplacementItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'batch_number' => $inventory['finished_batch_number'] ?? null,
                'batch_number_id' => isset($inventory['finished_batch_id']) ? (int)$inventory['finished_batch_id'] : null,
            ];
        }
        
        if (empty($processedReturnItems) || empty($processedReplacementItems)) {
            throw new RuntimeException('يجب اختيار منتجات صالحة للإرجاع والاستبدال');
        }
        
        $oldValue = round($oldValue, 2);
        $newValue = round($newValue, 2);
        $difference = round($newValue - $oldValue, 2);
        
        // Generate exchange number
        $year = date('Y');
        $month = date('m');
        $lastExchange = $db->queryOne(
            "SELECT exchange_number FROM exchanges WHERE exchange_number LIKE ? ORDER BY exchange_number DESC LIMIT 1",
            ["EXC-{$year}{$month}-%"]
        );
        
        $serial = 1;
        if ($lastExchange) {
            $parts = explode('-', $lastExchange['exchange_number']);
            $serial = intval($parts[2] ?? 0) + 1;
        }
        $exchangeNumber = sprintf("EXC-%s%s-%04d", $year, $month, $serial);
        
        // Get first invoice ID for linking
        $firstInvoice = $db->queryOne(
            "SELECT id FROM invoices WHERE customer_id = ? ORDER BY id ASC LIMIT 1",
            [$customerId]
        );
        $invoiceId = $firstInvoice ? (int)$firstInvoice['id'] : null;
        
        // Create exchange record with approved status (no approval needed)
        $db->execute(
            "INSERT INTO exchanges
             (exchange_number, invoice_id, customer_id, sales_rep_id, exchange_date, exchange_type,
              original_total, new_total, difference_amount, status, notes, created_by, approved_by, approved_at)
             VALUES (?, ?, ?, ?, CURDATE(), 'different_product', ?, ?, ?, 'approved', ?, ?, ?, NOW())",
            [
                $exchangeNumber,
                $invoiceId,
                $customerId,
                $salesRepId ?: null,
                $oldValue,
                $newValue,
                $difference,
                $notes ?: null,
                $currentUser['id'],
                $currentUser['id'], // approved_by = created_by
            ]
        );
        
        $exchangeId = (int)$db->getLastInsertId();
        
        // Create exchange return items
        foreach ($processedReturnItems as $item) {
            $batchNumberId = !empty($item['batch_number_ids']) ? $item['batch_number_ids'][0] : null;
            $batchNumber = $item['batch_numbers'] ?? null;
            
            $db->execute(
                "INSERT INTO exchange_return_items
                 (exchange_id, invoice_item_id, product_id, quantity, unit_price, total_price,
                  batch_number_id, batch_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $exchangeId,
                    $item['invoice_item_id'],
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price'],
                    $batchNumberId,
                    $batchNumber,
                ]
            );
        }
        
        // Create exchange new items
        foreach ($processedReplacementItems as $item) {
            $db->execute(
                "INSERT INTO exchange_new_items
                 (exchange_id, product_id, quantity, unit_price, total_price,
                  batch_number_id, batch_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $exchangeId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price'],
                    $item['batch_number_id'],
                    $item['batch_number'],
                ]
            );
        }
        
        // Process inventory and balance immediately (no approval needed)
        require_once __DIR__ . '/../includes/inventory_movements.php';
        require_once __DIR__ . '/../includes/vehicle_inventory.php';
        
        // Get return items and replacement items
        $returnItems = $db->query(
            "SELECT * FROM exchange_return_items WHERE exchange_id = ?",
            [$exchangeId]
        );
        
        $replacementItems = $db->query(
            "SELECT * FROM exchange_new_items WHERE exchange_id = ?",
            [$exchangeId]
        );
        
        // Return old products to vehicle inventory
        if ($salesRepId > 0 && !empty($returnItems)) {
            $vehicle = $db->queryOne(
                "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$salesRepId]
            );
            
            if ($vehicle) {
                $vehicleId = (int)$vehicle['id'];
                $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                
                if ($vehicleWarehouse) {
                    $warehouseId = $vehicleWarehouse['id'];
                    
                    foreach ($returnItems as $item) {
                        $productId = (int)$item['product_id'];
                        $quantity = (float)$item['quantity'];
                        
                        // Get current quantity in vehicle inventory
                        $inventoryRow = $db->queryOne(
                            "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                            [$vehicleId, $productId]
                        );
                        
                        $currentQuantity = (float)($inventoryRow['quantity'] ?? 0);
                        $newQuantity = round($currentQuantity + $quantity, 3);
                        
                        // Update vehicle inventory
                        $updateResult = updateVehicleInventory($vehicleId, $productId, $newQuantity, $currentUser['id']);
                        if (empty($updateResult['success'])) {
                            throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
                        }
                        
                        // Record inventory movement
                        recordInventoryMovement(
                            $productId,
                            $warehouseId,
                            'in',
                            $quantity,
                            'exchange',
                            $exchangeId,
                            'إرجاع من استبدال ' . $exchangeNumber,
                            $currentUser['id']
                        );
                    }
                }
            }
        }
        
        // Remove new products from vehicle inventory
        if ($salesRepId > 0 && !empty($replacementItems)) {
            $vehicle = $db->queryOne(
                "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$salesRepId]
            );
            
            if ($vehicle) {
                $vehicleId = (int)$vehicle['id'];
                $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                
                if ($vehicleWarehouse) {
                    $warehouseId = $vehicleWarehouse['id'];
                    
                    foreach ($replacementItems as $item) {
                        $productId = (int)$item['product_id'];
                        $quantity = (float)$item['quantity'];
                        
                        // Check availability in vehicle inventory
                        $inventoryRow = $db->queryOne(
                            "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                            [$vehicleId, $productId]
                        );
                        
                        if (!$inventoryRow) {
                            throw new Exception("المنتج غير موجود في مخزن السيارة");
                        }
                        
                        $currentQuantity = (float)$inventoryRow['quantity'];
                        if ($currentQuantity < $quantity) {
                            throw new Exception("الكمية المتاحة ({$currentQuantity}) أقل من المطلوب ({$quantity})");
                        }
                        
                        $newQuantity = round($currentQuantity - $quantity, 3);
                        
                        // Update vehicle inventory
                        $updateResult = updateVehicleInventory($vehicleId, $productId, $newQuantity, $currentUser['id']);
                        if (empty($updateResult['success'])) {
                            throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
                        }
                        
                        // Record inventory movement
                        recordInventoryMovement(
                            $productId,
                            $warehouseId,
                            'out',
                            $quantity,
                            'exchange',
                            $exchangeId,
                            'استبدال رقم ' . $exchangeNumber,
                            $currentUser['id']
                        );
                    }
                }
            }
        }
        
        // Update customer balance
        if (abs($difference) >= 0.01) {
            $customer = $db->queryOne("SELECT balance FROM customers WHERE id = ? FOR UPDATE", [$customerId]);
            $customerBalance = (float)($customer['balance'] ?? 0);
            $oldBalance = $customerBalance; // حفظ الرصيد القديم
            
            if ($difference < 0) {
                // المنتج البديل أرخص - إضافة للرصيد الدائن
                $newBalance = round($customerBalance - abs($difference), 2);
            } else {
                // المنتج البديل أغلى - إضافة للدين
                $newBalance = round($customerBalance + $difference, 2);
            }
            
            $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
            
            // خصم المندوب فقط في حالة معينة:
            // 1. إذا كان إجمالي منتجات العميل أكبر من إجمالي منتجات السيارة (difference < 0)
            // 2. إذا كان العميل مدين قبل العملية (oldBalance > 0)
            // 3. إذا أصبح رصيد العميل دائن بعد العملية (newBalance < 0)
            if ($difference < 0 && $oldBalance > 0 && $newBalance < 0 && $salesRepId > 0) {
                // حساب قيمة الرصيد الدائن المضاف للعميل
                // الرصيد الدائن المضاف = الفرق بين الرصيد القديم والجديد (الجزء الذي تحول من مدين إلى دائن)
                $creditAdded = abs($newBalance); // القيمة المطلقة للرصيد الدائن الجديد
                
                // خصم 2% من المندوب من قيمة الرصيد الدائن المضاف
                $deductionAmount = round($creditAdded * 0.02, 2);
                
                if ($deductionAmount > 0) {
                    require_once __DIR__ . '/../includes/salary_calculator.php';
                    require_once __DIR__ . '/../includes/audit_log.php';
                    
                    // التحقق من عدم تطبيق الخصم مسبقاً (منع الخصم المكرر)
                    $existingDeduction = $db->queryOne(
                        "SELECT id FROM audit_logs 
                         WHERE action = 'exchange_deduction' 
                         AND entity_type = 'salary' 
                         AND new_value LIKE ?",
                        ['%"exchange_id":' . $exchangeId . '%']
                    );
                    
                    if (empty($existingDeduction)) {
                        // تحديد الشهر والسنة من تاريخ الاستبدال
                        $exchangeDate = date('Y-m-d');
                        $timestamp = strtotime($exchangeDate) ?: time();
                        $month = (int)date('n', $timestamp);
                        $year = (int)date('Y', $timestamp);
                        
                        // الحصول على أو إنشاء سجل الراتب
                        $summary = getSalarySummary($salesRepId, $month, $year);
                        
                        if (!$summary['exists']) {
                            $creation = createOrUpdateSalary($salesRepId, $month, $year);
                            if (!($creation['success'] ?? false)) {
                                error_log('Failed to create salary for exchange deduction: ' . ($creation['message'] ?? 'unknown error'));
                                // لا نرمي استثناء، فقط نسجل الخطأ
                            } else {
                                $summary = getSalarySummary($salesRepId, $month, $year);
                            }
                        }
                        
                        if ($summary['exists'] ?? false) {
                            $salary = $summary['salary'];
                            $salaryId = (int)($salary['id'] ?? 0);
                            
                            if ($salaryId > 0) {
                                // الحصول على أسماء الأعمدة في جدول الرواتب
                                $columns = $db->query("SHOW COLUMNS FROM salaries");
                                $columnMap = [
                                    'deductions' => null,
                                    'total_amount' => null,
                                    'updated_at' => null
                                ];
                                
                                foreach ($columns as $column) {
                                    $field = $column['Field'] ?? '';
                                    if ($field === 'deductions' || $field === 'total_deductions') {
                                        $columnMap['deductions'] = $field;
                                    } elseif ($field === 'total_amount' || $field === 'amount' || $field === 'net_total') {
                                        $columnMap['total_amount'] = $field;
                                    } elseif ($field === 'updated_at' || $field === 'modified_at' || $field === 'last_updated') {
                                        $columnMap['updated_at'] = $field;
                                    }
                                }
                                
                                // بناء استعلام التحديث
                                $updates = [];
                                $params = [];
                                
                                if ($columnMap['deductions'] !== null) {
                                    $updates[] = "{$columnMap['deductions']} = COALESCE({$columnMap['deductions']}, 0) + ?";
                                    $params[] = $deductionAmount;
                                }
                                
                                if ($columnMap['total_amount'] !== null) {
                                    $updates[] = "{$columnMap['total_amount']} = GREATEST(COALESCE({$columnMap['total_amount']}, 0) - ?, 0)";
                                    $params[] = $deductionAmount;
                                }
                                
                                if ($columnMap['updated_at'] !== null) {
                                    $updates[] = "{$columnMap['updated_at']} = NOW()";
                                }
                                
                                if (!empty($updates)) {
                                    $params[] = $salaryId;
                                    $db->execute(
                                        "UPDATE salaries SET " . implode(', ', $updates) . " WHERE id = ?",
                                        $params
                                    );
                                    
                                    // تحديث ملاحظات الراتب لتوثيق الخصم
                                    $currentNotes = $salary['notes'] ?? '';
                                    $deductionNote = "\n[خصم استبدال]: تم خصم " . number_format($deductionAmount, 2) . " ج.م (2% من رصيد دائن مضاف للعميل بقيمة " . number_format($creditAdded, 2) . " ج.م من استبدال {$exchangeNumber})";
                                    $newNotes = $currentNotes . $deductionNote;
                                    
                                    $db->execute(
                                        "UPDATE salaries SET notes = ? WHERE id = ?",
                                        [$newNotes, $salaryId]
                                    );
                                    
                                    // تسجيل سجل التدقيق
                                    logAudit($currentUser['id'], 'exchange_deduction', 'salary', $salaryId, null, [
                                        'exchange_id' => $exchangeId,
                                        'exchange_number' => $exchangeNumber,
                                        'credit_added' => $creditAdded,
                                        'deduction_amount' => $deductionAmount,
                                        'sales_rep_id' => $salesRepId,
                                        'old_balance' => $oldBalance,
                                        'new_balance' => $newBalance
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // تحديث إجمالي المبيعات ومبيعات الشهر بناءً على الفرق
        // إذا كان الفرق موجب (أحمر): خصم من المبيعات
        // إذا كان الفرق سالب (أخضر): إضافة إلى المبيعات
        if (abs($difference) >= 0.01 && $salesRepId > 0) {
            require_once __DIR__ . '/../includes/approval_system.php';
            
            // التحقق من عدم إنشاء سجل تحصيل مسبقاً (منع التكرار)
            $existingCollection = $db->queryOne(
                "SELECT id FROM collections 
                 WHERE reference_number = ? 
                 AND collected_by = ?",
                ['EXCHANGE-' . $exchangeNumber, $salesRepId]
            );
            
            if (empty($existingCollection)) {
                // إنشاء سجل تحصيل (سالب أو موجب) حسب الفرق
                // الفرق موجب (أحمر) = المنتج البديل أغلى = خصم من المبيعات (سالب)
                // الفرق سالب (أخضر) = المنتج البديل أرخص = إضافة إلى المبيعات (موجب)
                $collectionAmount = -$difference; // سالب الفرق = موجب إذا كان الفرق سالب
                
                // الحصول على أعمدة جدول collections
                $columns = $db->query("SHOW COLUMNS FROM collections") ?? [];
                $columnNames = [];
                foreach ($columns as $column) {
                    if (!empty($column['Field'])) {
                        $columnNames[] = $column['Field'];
                    }
                }
                
                $hasStatus = in_array('status', $columnNames, true);
                $hasApprovedBy = in_array('approved_by', $columnNames, true);
                $hasApprovedAt = in_array('approved_at', $columnNames, true);
                
                $fields = [];
                $placeholders = [];
                $values = [];
                
                $baseData = [
                    'customer_id' => $customerId,
                    'amount' => $collectionAmount,
                    'date' => date('Y-m-d'),
                    'payment_method' => 'cash',
                    'reference_number' => 'EXCHANGE-' . $exchangeNumber,
                    'notes' => 'استبدال رقم ' . $exchangeNumber . ' - ' . ($difference > 0 ? 'خصم' : 'إضافة') . ' ' . number_format(abs($difference), 2) . ' ج.م',
                    'collected_by' => $salesRepId,
                ];
                
                foreach ($baseData as $column => $value) {
                    if (in_array($column, $columnNames, true)) {
                        $fields[] = $column;
                        $placeholders[] = '?';
                        $values[] = $value;
                    }
                }
                
                if ($hasStatus) {
                    $fields[] = 'status';
                    $placeholders[] = '?';
                    $values[] = 'approved';
                }
                
                if ($hasApprovedBy) {
                    $fields[] = 'approved_by';
                    $placeholders[] = '?';
                    $values[] = $currentUser['id'];
                }
                
                if ($hasApprovedAt) {
                    $fields[] = 'approved_at';
                    $placeholders[] = '?';
                    $values[] = date('Y-m-d H:i:s');
                }
                
                if (!empty($fields)) {
                    $db->execute(
                        "INSERT INTO collections (" . implode(', ', $fields) . ") 
                         VALUES (" . implode(', ', $placeholders) . ")",
                        $values
                    );
                    
                    // تسجيل في سجل التدقيق
                    logAudit($currentUser['id'], 'exchange_collection', 'collections', $db->getLastInsertId(), null, [
                        'exchange_id' => $exchangeId,
                        'exchange_number' => $exchangeNumber,
                        'difference_amount' => $difference,
                        'collection_amount' => $collectionAmount,
                        'sales_rep_id' => $salesRepId
                    ]);
                }
            }
        }
        
        // Update customer purchase history immediately
        try {
            require_once __DIR__ . '/../includes/customer_history.php';
            customerHistorySyncForCustomer($customerId);
        } catch (Exception $historyException) {
            // لا نسمح لفشل تحديث السجل بإلغاء نجاح العملية
            error_log('Failed to sync customer history after exchange creation: ' . $historyException->getMessage());
        }
        
        // Log audit
        require_once __DIR__ . '/../includes/audit_log.php';
        logAudit($currentUser['id'], 'create_exchange', 'exchange', $exchangeId, null, [
            'exchange_number' => $exchangeNumber,
            'difference_amount' => $difference,
            'status' => 'approved'
        ]);
        
        $conn->commit();
        
        returnJson([
            'success' => true,
            'message' => 'تم إنشاء الاستبدال وتسجيله كمعتمد بنجاح',
            'exchange_id' => $exchangeId,
            'exchange_number' => $exchangeNumber,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'difference' => $difference,
        ]);
        
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

