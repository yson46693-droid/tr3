<?php
/**
 * API for Approving Return Requests
 * Handles manager approval workflow with customer balance adjustments
 */

define('ACCESS_ALLOWED', true);

// إضافة تسجيل فوري للتأكد من استدعاء الملف - طرق متعددة
$timestamp = date('Y-m-d H:i:s');
$logMessage = "[{$timestamp}] === APPROVE RETURN API CALLED ===\n";
$logMessage .= "Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . "\n";
$logMessage .= "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN') . "\n";
$logMessage .= "Script Name: " . ($_SERVER['SCRIPT_NAME'] ?? 'UNKNOWN') . "\n";
$logMessage .= "Remote Addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') . "\n";
$phpInput = @file_get_contents('php://input');
$logMessage .= "PHP Input: " . ($phpInput ?: 'EMPTY') . "\n";
$logMessage .= "========================================\n";

// محاولة 1: استخدام المسار المحدد في config
$logFile1 = __DIR__ . '/../private/storage/logs/php-errors.log';
@file_put_contents($logFile1, $logMessage, FILE_APPEND);

// محاولة 2: استخدام مسار بديل في نفس المجلد
$logFile2 = __DIR__ . '/approve_return_debug.log';
@file_put_contents($logFile2, $logMessage, FILE_APPEND);

// محاولة 3: استخدام error_log
@error_log("=== APPROVE RETURN API CALLED ===");
@error_log("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
@error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
@error_log("PHP Input: " . ($phpInput ?: 'EMPTY'));

// محاولة 4: استخدام ini_get للحصول على مسار error_log
$errorLogPath = ini_get('error_log');
if ($errorLogPath) {
    @file_put_contents($errorLogPath, $logMessage, FILE_APPEND);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/return_processor.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/return_inventory_manager.php';
require_once __DIR__ . '/../includes/return_financial_processor.php';
require_once __DIR__ . '/../includes/return_salary_deduction.php';

header('Content-Type: application/json; charset=utf-8');
error_log("Headers set. Starting authentication check...");
error_log("Checking user role...");
requireRole(['manager']);

error_log("Getting current user...");
$currentUser = getCurrentUser();
if (!$currentUser) {
    error_log("ERROR: No current user found - session expired");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}
error_log("Current user: " . ($currentUser['id'] ?? 'N/A') . " (" . ($currentUser['full_name'] ?? 'N/A') . ")");

error_log("Checking request method...");
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("ERROR: Invalid request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'يجب استخدام طلب POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

error_log("Parsing JSON payload...");
$payload = json_decode(file_get_contents('php://input'), true);
error_log("Payload decoded: " . json_encode($payload, JSON_UNESCAPED_UNICODE));

if (!is_array($payload)) {
    error_log("ERROR: Invalid payload format");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnId = isset($payload['return_id']) ? (int)$payload['return_id'] : 0;
$action = $payload['action'] ?? 'approve'; // 'approve' or 'reject'
$notes = trim($payload['notes'] ?? '');

error_log("Extracted values - Return ID: {$returnId}, Action: {$action}, Notes: " . (strlen($notes) > 0 ? 'provided' : 'empty'));

if ($returnId <= 0) {
    error_log("ERROR: Invalid return_id: {$returnId}");
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'معرف المرتجع غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

// تسجيل إضافي قبل بدء المعالجة
$debugLog = __DIR__ . '/approve_return_debug.log';
@file_put_contents($debugLog, "[{$timestamp}] Starting main processing for return ID: {$returnId}\n", FILE_APPEND);
error_log("Starting main processing...");
try {
    error_log("Getting database connection...");
    @file_put_contents($debugLog, "[{$timestamp}] Getting database connection...\n", FILE_APPEND);
    $db = db();
    $conn = $db->getConnection();
    error_log("Database connection established");
    @file_put_contents($debugLog, "[{$timestamp}] Database connection established\n", FILE_APPEND);
    
    // Get return request
    error_log("Fetching return data from database for ID: {$returnId}");
    @file_put_contents($debugLog, "[{$timestamp}] Fetching return data for ID: {$returnId}\n", FILE_APPEND);
    $return = $db->queryOne(
        "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
                u.full_name as sales_rep_name
         FROM returns r
         LEFT JOIN customers c ON r.customer_id = c.id
         LEFT JOIN users u ON r.sales_rep_id = u.id
         WHERE r.id = ?",
        [$returnId]
    );
    
    if (!$return) {
        $errorMsg = "ERROR: Return not found in database for ID: {$returnId}";
        error_log($errorMsg);
        @file_put_contents($debugLog, "[{$timestamp}] {$errorMsg}\n", FILE_APPEND);
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'طلب المرتجع غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $returnInfo = "Return found: " . ($return['return_number'] ?? 'N/A') . ", Status: " . ($return['status'] ?? 'N/A');
    error_log($returnInfo);
    @file_put_contents($debugLog, "[{$timestamp}] {$returnInfo}\n", FILE_APPEND);
    
    if ($action === 'reject') {
        // Check if return can be rejected (only pending returns)
        if ($return['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'لا يمكن رفض مرتجع تمت معالجته بالفعل'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Reject return request
        $db->beginTransaction();
        
        try {
            $db->execute(
                "UPDATE returns SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
                [$currentUser['id'], $notes ?: 'تم رفض الطلب', $returnId]
            );
            
            // Reject approval request
            $entityColumn = getApprovalsEntityColumn();
            $approval = $db->queryOne(
                "SELECT id FROM approvals WHERE type = 'return_request' AND {$entityColumn} = ? AND status = 'pending'",
                [$returnId]
            );
            
            if ($approval) {
                rejectRequest((int)$approval['id'], $currentUser['id'], $notes ?: 'تم رفض طلب المرتجع');
            }
            
            logAudit($currentUser['id'], 'reject_return', 'returns', $returnId, null, [
                'return_number' => $return['return_number'],
                'notes' => $notes
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'تم رفض طلب المرتجع بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
    
    // Approve return request
    // Check if return can be approved (only pending returns)
    $currentStatus = $return['status'] ?? 'unknown';
    error_log("=== APPROVE RETURN START ===");
    error_log("Return ID: {$returnId}");
    error_log("Current Status: {$currentStatus}");
    error_log("Customer ID: " . ($return['customer_id'] ?? 'N/A'));
    error_log("Sales Rep ID: " . ($return['sales_rep_id'] ?? 'N/A'));
    error_log("Return Amount: " . ($return['refund_amount'] ?? 'N/A'));
    error_log("Approved By: " . ($currentUser['id'] ?? 'N/A') . " (" . ($currentUser['full_name'] ?? 'N/A') . ")");
    
    if ($currentStatus !== 'pending') {
        error_log("ERROR: Return {$returnId} cannot be approved - status is '{$currentStatus}', expected 'pending'");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'لا يمكن الموافقة على مرتجع تمت معالجته بالفعل'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    error_log("Starting database transaction...");
    $db->beginTransaction();
    
    try {
        error_log("Transaction started successfully. Beginning validation...");
        // التحقق من صحة البيانات قبل المعالجة
        $customerId = (int)$return['customer_id'];
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        $returnAmount = (float)$return['refund_amount'];
        $customerBalance = (float)($return['customer_balance'] ?? 0);
        
        error_log("--- VALIDATION PHASE ---");
        error_log("Customer ID: {$customerId}");
        error_log("Sales Rep ID: {$salesRepId}");
        error_log("Return Amount: {$returnAmount}");
        error_log("Customer Balance: {$customerBalance}");
        
        // Validation: التحقق من صحة البيانات
        if ($customerId <= 0) {
            error_log("VALIDATION ERROR: Invalid customer ID");
            throw new RuntimeException('معرف العميل غير صالح');
        }
        
        if ($returnAmount <= 0) {
            error_log("VALIDATION ERROR: Return amount is zero or negative");
            throw new RuntimeException('مبلغ المرتجع يجب أن يكون أكبر من صفر');
        }
        
        // التحقق من وجود العميل
        error_log("Checking if customer exists...");
        $customerExists = $db->queryOne(
            "SELECT id, name, balance FROM customers WHERE id = ?",
            [$customerId]
        );
        if (!$customerExists) {
            error_log("VALIDATION ERROR: Customer not found in database");
            throw new RuntimeException('العميل غير موجود في النظام');
        }
        error_log("Customer found: " . ($customerExists['name'] ?? 'N/A') . " (Balance: " . ($customerExists['balance'] ?? 0) . ")");
        
        // Get return items
        error_log("Fetching return items...");
        $returnItems = $db->query(
            "SELECT ri.*, p.name as product_name, p.id as product_exists
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?",
            [$returnId]
        );
        
        if (empty($returnItems)) {
            error_log("VALIDATION ERROR: No return items found");
            throw new RuntimeException('لا توجد عناصر في طلب المرتجع');
        }
        error_log("Found " . count($returnItems) . " return items");
        
        // Validation: التحقق من صحة عناصر المرتجع
        $itemIndex = 0;
        foreach ($returnItems as $item) {
            $itemIndex++;
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            
            error_log("Validating item #{$itemIndex}: Product ID={$productId}, Quantity={$quantity}");
            
            if ($productId <= 0) {
                error_log("VALIDATION ERROR: Invalid product ID in item #{$itemIndex}");
                throw new RuntimeException('معرف المنتج غير صالح في عناصر المرتجع');
            }
            
            if ($quantity <= 0) {
                error_log("VALIDATION ERROR: Invalid quantity in item #{$itemIndex}");
                throw new RuntimeException('كمية المنتج يجب أن تكون أكبر من صفر');
            }
            
            if (!$item['product_exists']) {
                error_log("VALIDATION ERROR: Product {$productId} not found in system");
                throw new RuntimeException("المنتج برقم {$productId} غير موجود في النظام");
            }
            error_log("Item #{$itemIndex} validated successfully");
        }
        
        // التحقق من وجود المندوب إذا كان محدداً
        if ($salesRepId > 0) {
            error_log("Checking if sales rep exists...");
            $salesRepExists = $db->queryOne(
                "SELECT id FROM users WHERE id = ? AND role = 'sales'",
                [$salesRepId]
            );
            if (!$salesRepExists) {
                error_log("VALIDATION ERROR: Sales rep not found or not a sales role");
                throw new RuntimeException('المندوب المحدد غير موجود أو ليس مندوب مبيعات');
            }
            error_log("Sales rep validated successfully");
        } else {
            error_log("No sales rep specified (optional)");
        }
        
        error_log("--- VALIDATION COMPLETED SUCCESSFULLY ---");
        
        // Update return status to approved first (before processing)
        error_log("--- STEP 1: UPDATING RETURN STATUS TO APPROVED ---");
        $updateResult = $db->execute(
            "UPDATE returns SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
            [$currentUser['id'], $notes ?: null, $returnId]
        );
        error_log("Status update executed. Affected rows: " . ($updateResult['affected_rows'] ?? 0));
        
        // التحقق من أن التحديث تم بنجاح
        $updatedReturn = $db->queryOne(
            "SELECT status FROM returns WHERE id = ?",
            [$returnId]
        );
        
        if (!$updatedReturn || $updatedReturn['status'] !== 'approved') {
            error_log("ERROR: Failed to update return status. Current status: " . ($updatedReturn['status'] ?? 'NULL'));
            throw new RuntimeException('فشل تحديث حالة المرتجع إلى approved');
        }
        error_log("Return status confirmed as 'approved'");
        
        // Process financials using new system
        error_log("--- STEP 2: PROCESSING FINANCIAL SETTLEMENTS ---");
        error_log("Calling processReturnFinancials()...");
        $financialResult = processReturnFinancials($returnId, $currentUser['id']);
        if (!$financialResult['success']) {
            error_log("ERROR: Financial processing failed!");
            error_log("Error message: " . ($financialResult['message'] ?? 'خطأ غير معروف'));
            error_log("Financial result: " . json_encode($financialResult, JSON_UNESCAPED_UNICODE));
            throw new RuntimeException('فشل معالجة التسوية المالية: ' . ($financialResult['message'] ?? 'خطأ غير معروف'));
        }
        error_log("Financial processing completed successfully");
        error_log("Debt Reduction: " . ($financialResult['debt_reduction'] ?? 0));
        error_log("Credit Added: " . ($financialResult['credit_added'] ?? 0));
        error_log("New Balance: " . ($financialResult['new_balance'] ?? 0));
        
        // Return products to inventory using new system
        error_log("--- STEP 3: RETURNING PRODUCTS TO INVENTORY ---");
        error_log("Calling returnProductsToVehicleInventory()...");
        $inventoryResult = returnProductsToVehicleInventory($returnId, $currentUser['id']);
        if (!$inventoryResult['success']) {
            error_log("ERROR: Inventory processing failed!");
            error_log("Error message: " . ($inventoryResult['message'] ?? 'خطأ غير معروف'));
            error_log("Inventory result: " . json_encode($inventoryResult, JSON_UNESCAPED_UNICODE));
            throw new RuntimeException('فشل إرجاع المنتجات للمخزون: ' . ($inventoryResult['message'] ?? 'خطأ غير معروف'));
        }
        error_log("Inventory processing completed successfully");
        error_log("Items returned: " . ($inventoryResult['items_count'] ?? 0));
        
        // تسجيل عملية المرتجع في سجلات المرتجعات
        // (تم التسجيل بالفعل في return_inventory_manager.php و return_financial_processor.php)
        
        // Apply salary deduction using new system
        error_log("--- STEP 4: APPLYING SALARY DEDUCTION ---");
        if ($salesRepId > 0) {
            error_log("Calling applyReturnSalaryDeduction() for sales rep ID: {$salesRepId}");
            $penaltyResult = applyReturnSalaryDeduction($returnId, $salesRepId, $currentUser['id']);
            if (!$penaltyResult['success'] && ($penaltyResult['deduction_amount'] ?? 0) > 0) {
                // Log but don't fail if penalty fails (it's non-critical)
                error_log("WARNING: Failed to apply salary deduction: " . ($penaltyResult['message'] ?? ''));
                error_log("Penalty result: " . json_encode($penaltyResult, JSON_UNESCAPED_UNICODE));
            } else {
                error_log("Salary deduction processed successfully");
                error_log("Deduction Amount: " . ($penaltyResult['deduction_amount'] ?? 0));
            }
        } else {
            error_log("No sales rep specified - skipping salary deduction");
            $penaltyResult = [
                'success' => true,
                'message' => 'لا يوجد مندوب مرتبط',
                'deduction_amount' => 0.0
            ];
        }
        
        // تحديث حالة المرتجع إلى processed بعد استدعاء جميع الدوال
        error_log("--- STEP 5: UPDATING RETURN STATUS TO PROCESSED ---");
        $processResult = $db->execute(
            "UPDATE returns SET status = 'processed', updated_at = NOW() WHERE id = ?",
            [$returnId]
        );
        error_log("Status updated to 'processed'. Affected rows: " . ($processResult['affected_rows'] ?? 0));
        
        // حفظ المرتجعات التالفة في جدول damaged_returns بشكل كامل
        error_log("--- STEP 6: PROCESSING DAMAGED RETURNS ---");
        try {
            // جلب المنتجات التالفة من return_items
            $damagedItems = $db->query(
                "SELECT ri.*, p.name as product_name,
                        COALESCE(
                            (SELECT fp2.product_name 
                             FROM finished_products fp2 
                             WHERE fp2.product_id = ri.product_id 
                               AND fp2.product_name IS NOT NULL 
                               AND TRIM(fp2.product_name) != ''
                               AND fp2.product_name NOT LIKE 'منتج رقم%'
                             ORDER BY fp2.id DESC 
                             LIMIT 1),
                            NULLIF(TRIM(p.name), ''),
                            CONCAT('منتج رقم ', ri.product_id)
                        ) as display_product_name,
                        b.batch_number,
                        i.invoice_number,
                        u.full_name as sales_rep_name
                 FROM return_items ri
                 LEFT JOIN products p ON ri.product_id = p.id
                 LEFT JOIN batch_numbers b ON ri.batch_number_id = b.id
                 LEFT JOIN returns r ON ri.return_id = r.id
                 LEFT JOIN invoices i ON r.invoice_id = i.id
                 LEFT JOIN users u ON r.sales_rep_id = u.id
                 WHERE ri.return_id = ? AND ri.is_damaged = 1",
                [$returnId]
            );
            
            if (!empty($damagedItems)) {
                // التحقق من وجود جدول damaged_returns وإنشاؤه إذا لم يكن موجوداً
                $damagedReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'damaged_returns'");
                if (empty($damagedReturnsTableExists)) {
                    $db->execute("
                        CREATE TABLE IF NOT EXISTS `damaged_returns` (
                          `id` INT(11) NOT NULL AUTO_INCREMENT,
                          `return_id` INT(11) NOT NULL,
                          `return_item_id` INT(11) NOT NULL,
                          `product_id` INT(11) NOT NULL,
                          `batch_number_id` INT(11) DEFAULT NULL,
                          `quantity` DECIMAL(10,2) NOT NULL,
                          `damage_reason` TEXT DEFAULT NULL,
                          `invoice_id` INT(11) DEFAULT NULL,
                          `invoice_number` VARCHAR(100) DEFAULT NULL,
                          `return_date` DATE DEFAULT NULL,
                          `return_transaction_number` VARCHAR(100) DEFAULT NULL,
                          `approval_status` VARCHAR(50) DEFAULT 'approved',
                          `sales_rep_id` INT(11) DEFAULT NULL,
                          `sales_rep_name` VARCHAR(255) DEFAULT NULL,
                          `product_name` VARCHAR(255) DEFAULT NULL,
                          `batch_number` VARCHAR(100) DEFAULT NULL,
                          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (`id`),
                          KEY `idx_return_id` (`return_id`),
                          KEY `idx_return_item_id` (`return_item_id`),
                          KEY `idx_product_id` (`product_id`),
                          KEY `idx_batch_number_id` (`batch_number_id`),
                          KEY `idx_sales_rep_id` (`sales_rep_id`),
                          KEY `idx_approval_status` (`approval_status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
                
                // تحديث السجلات الموجودة أو إدراج سجلات جديدة
                foreach ($damagedItems as $item) {
                    // التحقق من وجود سجل سابق
                    $existingRecord = $db->queryOne(
                        "SELECT id FROM damaged_returns WHERE return_item_id = ?",
                        [(int)$item['id']]
                    );
                    
                    $damagedReturnId = null;
                    if ($existingRecord) {
                        // تحديث السجل الموجود
                        $db->execute(
                            "UPDATE damaged_returns SET
                             invoice_id = ?,
                             invoice_number = ?,
                             return_date = ?,
                             return_transaction_number = ?,
                             approval_status = 'approved',
                             sales_rep_id = ?,
                             sales_rep_name = ?,
                             product_name = ?,
                             batch_number = ?
                             WHERE return_item_id = ?",
                            [
                                (int)$return['invoice_id'],
                                $item['invoice_number'] ?? $return['invoice_id'] ?? null,
                                $return['return_date'] ?? date('Y-m-d'),
                                $return['return_number'],
                                $salesRepId > 0 ? $salesRepId : null,
                                $item['sales_rep_name'] ?? null,
                                $item['display_product_name'] ?? $item['product_name'] ?? null,
                                $item['batch_number'] ?? null,
                                (int)$item['id']
                            ]
                        );
                        $damagedReturnId = (int)$existingRecord['id'];
                    } else {
                        // إدراج سجل جديد
                        $db->execute(
                            "INSERT INTO damaged_returns 
                            (return_id, return_item_id, product_id, batch_number_id, quantity, damage_reason,
                             invoice_id, invoice_number, return_date, return_transaction_number,
                             approval_status, sales_rep_id, sales_rep_name, product_name, batch_number)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?)",
                            [
                                $returnId,
                                (int)$item['id'],
                                (int)$item['product_id'],
                                isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null,
                                (float)$item['quantity'],
                                $item['notes'] ?? null,
                                (int)$return['invoice_id'],
                                $item['invoice_number'] ?? null,
                                $return['return_date'] ?? date('Y-m-d'),
                                $return['return_number'],
                                $salesRepId > 0 ? $salesRepId : null,
                                $item['sales_rep_name'] ?? null,
                                $item['display_product_name'] ?? $item['product_name'] ?? null,
                                $item['batch_number'] ?? null
                            ]
                        );
                        $damagedReturnId = $db->getLastInsertId();
                    }
                    
                    // إضافة إلى مخزن توالف المصنع (factory_waste_products)
                    try {
                        // التحقق من وجود جدول factory_waste_products
                        $tableExists = $db->queryOne("SHOW TABLES LIKE 'factory_waste_products'");
                        if ($tableExists) {
                            // جلب معلومات المنتج
                            $product = $db->queryOne(
                                "SELECT id, name, unit_price FROM products WHERE id = ?",
                                [(int)$item['product_id']]
                            );
                            
                            // حساب قيمة التوالف باستخدام unit_price
                            $wasteValue = 0;
                            if ($product && isset($product['unit_price'])) {
                                $wasteValue = (float)$item['quantity'] * (float)$product['unit_price'];
                            }
                            
                            // التحقق من عدم وجود سجل مسبق
                            $existingWaste = $db->queryOne(
                                "SELECT id FROM factory_waste_products WHERE damaged_return_id = ?",
                                [$damagedReturnId]
                            );
                            
                            if (!$existingWaste) {
                                $db->execute(
                                    "INSERT INTO factory_waste_products 
                                    (damaged_return_id, product_id, product_name, product_code, batch_number, 
                                     batch_number_id, damaged_quantity, waste_value, source, transaction_number, added_date)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'damaged_returns', ?, ?)",
                                    [
                                        $damagedReturnId,
                                        (int)$item['product_id'],
                                        $item['display_product_name'] ?? $item['product_name'] ?? 'منتج رقم ' . $item['product_id'],
                                        null, // product_code - لا يوجد في جدول products
                                        $item['batch_number'] ?? null,
                                        isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null,
                                        (float)$item['quantity'],
                                        $wasteValue,
                                        $return['return_number'],
                                        $return['return_date'] ?? date('Y-m-d')
                                    ]
                                );
                            }
                        }
                    } catch (Throwable $wasteError) {
                        // لا نوقف العملية إذا فشل حفظ في مخزن التوالف، فقط نسجل الخطأ
                        error_log('Warning: Failed to save to factory_waste_products: ' . $wasteError->getMessage());
                    }
                }
                
                // تسجيل في سجل التدقيق
                logAudit($currentUser['id'], 'save_damaged_returns', 'damaged_returns', $returnId, null, [
                    'return_number' => $return['return_number'],
                    'damaged_items_count' => count($damagedItems)
                ]);
                error_log("Damaged returns saved successfully. Count: " . count($damagedItems));
            } else {
                error_log("No damaged items found");
            }
        } catch (Throwable $e) {
            // لا نوقف العملية إذا فشل حفظ المرتجعات التالفة، فقط نسجل الخطأ
            error_log("WARNING: Failed to save damaged returns: " . $e->getMessage());
            error_log("Exception trace: " . $e->getTraceAsString());
        }
        
        // Approve approval request
        error_log("--- STEP 7: APPROVING APPROVAL REQUEST ---");
        $entityColumn = getApprovalsEntityColumn();
        $approval = $db->queryOne(
            "SELECT id FROM approvals WHERE type = 'return_request' AND {$entityColumn} = ? AND status = 'pending'",
            [$returnId]
        );
        
        if ($approval) {
            error_log("Approval request found. ID: " . $approval['id']);
            approveRequest((int)$approval['id'], $currentUser['id'], $notes ?: 'تمت الموافقة على طلب المرتجع');
            error_log("Approval request approved successfully");
        } else {
            error_log("No pending approval request found");
        }
        
        // Build financial note
        error_log("--- STEP 8: BUILDING RESPONSE ---");
        $financialNote = '';
        $debtReduction = $financialResult['debt_reduction'] ?? 0;
        $creditAdded = $financialResult['credit_added'] ?? 0;
        $deductionAmount = $penaltyResult['deduction_amount'] ?? 0;
        
        if ($debtReduction > 0 && $creditAdded > 0) {
            $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل وإضافة %.2f ج.م لرصيده الدائن", $debtReduction, $creditAdded);
        } elseif ($debtReduction > 0) {
            $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل", $debtReduction);
        } elseif ($creditAdded > 0) {
            $financialNote = sprintf("تم إضافة %.2f ج.م لرصيد العميل الدائن", $creditAdded);
        }
        
        if ($deductionAmount > 0) {
            if ($financialNote) {
                $financialNote .= "\n";
            }
            $financialNote .= sprintf("تم خصم 2%% (%.2f ج.م) من راتب المندوب", $deductionAmount);
        }
        
        error_log("Logging audit trail...");
        logAudit($currentUser['id'], 'approve_return', 'returns', $returnId, null, [
            'return_number' => $return['return_number'],
            'return_amount' => $returnAmount,
            'financial_result' => $financialResult,
            'penalty_result' => $penaltyResult,
            'inventory_result' => $inventoryResult,
            'notes' => $notes
        ]);
        
        error_log("--- STEP 9: COMMITTING TRANSACTION ---");
        $db->commit();
        error_log("Transaction committed successfully");
        
        error_log("=== APPROVE RETURN COMPLETED SUCCESSFULLY ===");
        error_log("Return ID: {$returnId}");
        error_log("Final Status: processed");
        error_log("Financial Note: {$financialNote}");
        error_log("New Balance: " . ($financialResult['new_balance'] ?? $customerBalance));
        error_log("Penalty Applied: " . ($penaltyResult['deduction_amount'] ?? 0));
        error_log("=============================================");
        
        // بناء رسالة النجاح التفصيلية
        $successMessage = '✅ تمت الموافقة على طلب المرتجع بنجاح!';
        if ($financialNote) {
            $successMessage .= "\n\n" . $financialNote;
        }
        if (($inventoryResult['items_count'] ?? 0) > 0) {
            $successMessage .= "\n\n📦 تم إرجاع " . ($inventoryResult['items_count'] ?? 0) . " منتج(ات) إلى مخزن السيارة";
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'تمت الموافقة على طلب المرتجع بنجاح',
            'success_message' => $successMessage,
            'financial_note' => $financialNote,
            'new_balance' => $financialResult['new_balance'] ?? $customerBalance,
            'penalty_applied' => $penaltyResult['deduction_amount'] ?? 0,
            'items_returned' => $inventoryResult['items_count'] ?? 0,
            'return_number' => $return['return_number'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        error_log("=== APPROVE RETURN ERROR ===");
        error_log("Return ID: {$returnId}");
        error_log("Error Message: " . $e->getMessage());
        error_log("Error Type: " . get_class($e));
        error_log("Stack Trace: " . $e->getTraceAsString());
        error_log("=============================================");
        
        $db->rollback();
        error_log("Transaction rolled back");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء معالجة الموافقة: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Throwable $e) {
    error_log('approve_return API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

