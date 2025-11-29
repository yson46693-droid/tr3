<?php
/**
 * نظام المرتجعات الشامل
 * Unified Returns System
 * 
 * هذا الملف يحتوي على جميع وظائف نظام المرتجعات في مكان واحد:
 * - إنشاء طلبات المرتجعات
 * - المعالجة المالية
 * - إدارة المخزون
 * - خصم مرتب المندوب
 * - الموافقة على المرتجعات
 * 
 * تاريخ الإنشاء: 2024
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/inventory_movements.php';
require_once __DIR__ . '/vehicle_inventory.php';
require_once __DIR__ . '/approval_system.php';
require_once __DIR__ . '/salary_calculator.php';
require_once __DIR__ . '/product_name_helper.php';

// ============================================================================
// 1. وظائف توليد الأرقام والمساعدة
// ============================================================================

/**
 * توليد رقم مرتجع فريد
 */
function generateReturnNumber(): string
{
    $db = db();
    $year = date('Y');
    $month = date('m');
    $prefix = "RET-{$year}{$month}";
    
    $lastReturn = $db->queryOne(
        "SELECT return_number FROM returns WHERE return_number LIKE ? ORDER BY return_number DESC LIMIT 1",
        ["{$prefix}-%"]
    );
    
    $serial = 1;
    if ($lastReturn) {
        $parts = explode('-', $lastReturn['return_number']);
        $serial = intval($parts[2] ?? 0) + 1;
    }
    
    return sprintf("%s-%04d", $prefix, $serial);
}

/**
 * ضمان توافق جدول المرتجعات
 */
function ensureReturnSchemaCompatibility(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    
    try {
        $db = db();
        
        // إصلاح Foreign Key constraint في return_items
        // المشكلة: return_items قد يشير إلى sales_returns بدلاً من returns
        try {
            $fkCheck = $db->queryOne(
                "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME 
                 FROM information_schema.KEY_COLUMN_USAGE 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'return_items' 
                 AND COLUMN_NAME = 'return_id' 
                 AND REFERENCED_TABLE_NAME IS NOT NULL"
            );
            
            if (!empty($fkCheck)) {
                $referencedTable = $fkCheck['REFERENCED_TABLE_NAME'] ?? '';
                $constraintName = $fkCheck['CONSTRAINT_NAME'] ?? 'return_items_ibfk_1';
                
                // إذا كان يشير إلى sales_returns، نحذفه وننشئ واحداً جديداً
                if ($referencedTable === 'sales_returns') {
                    error_log("Fixing return_items foreign key: removing reference to sales_returns");
                    
                    // التحقق أولاً من وجود جدول returns
                    $returnsTableExists = $db->queryOne("SHOW TABLES LIKE 'returns'");
                    if (empty($returnsTableExists)) {
                        error_log("Warning: returns table does not exist. Cannot fix foreign key.");
                        return;
                    }
                    
                    // حذف الـ foreign key القديم
                    try {
                        $db->execute("ALTER TABLE return_items DROP FOREIGN KEY `{$constraintName}`");
                        error_log("Dropped old foreign key: {$constraintName}");
                    } catch (Throwable $e) {
                        error_log("Could not drop old FK {$constraintName}: " . $e->getMessage());
                        // محاولة حذف بأسماء مختلفة شائعة
                        $commonNames = ['return_items_ibfk_1', 'fk_return_items_return_id', 'return_items_return_id_foreign'];
                        foreach ($commonNames as $name) {
                            try {
                                $db->execute("ALTER TABLE return_items DROP FOREIGN KEY `{$name}`");
                                error_log("Dropped foreign key with alternative name: {$name}");
                                break;
                            } catch (Throwable $e2) {
                                // تجاهل
                            }
                        }
                    }
                    
                    // التحقق من عدم وجود constraint بالفعل يشير إلى returns
                    $existingFk = $db->queryOne(
                        "SELECT CONSTRAINT_NAME 
                         FROM information_schema.KEY_COLUMN_USAGE 
                         WHERE TABLE_SCHEMA = DATABASE() 
                         AND TABLE_NAME = 'return_items' 
                         AND COLUMN_NAME = 'return_id' 
                         AND REFERENCED_TABLE_NAME = 'returns'"
                    );
                    
                    if (empty($existingFk)) {
                        // إنشاء foreign key جديد يشير إلى returns
                        try {
                            $db->execute(
                                "ALTER TABLE return_items 
                                 ADD CONSTRAINT `return_items_ibfk_1` 
                                 FOREIGN KEY (`return_id`) 
                                 REFERENCES `returns` (`id`) 
                                 ON DELETE CASCADE"
                            );
                            error_log("Successfully created new foreign key pointing to returns table");
                        } catch (Throwable $e) {
                            error_log("Could not create new FK: " . $e->getMessage());
                        }
                    } else {
                        error_log("Foreign key already exists pointing to returns table");
                    }
                } elseif ($referencedTable !== 'returns') {
                    error_log("Warning: return_items foreign key points to unexpected table: {$referencedTable}");
                }
            }
        } catch (Throwable $e) {
            error_log("Error checking/fixing return_items FK: " . $e->getMessage());
            // لا نوقف العملية، فقط نسجل الخطأ
        }
        
        // تحديث sale_id ليكون nullable
        try {
            $saleIdColumn = $db->queryOne("SHOW COLUMNS FROM returns LIKE 'sale_id'");
            if (!empty($saleIdColumn) && strtoupper($saleIdColumn['Null'] ?? '') === 'NO') {
                $db->execute("ALTER TABLE returns MODIFY `sale_id` int(11) DEFAULT NULL");
            }
        } catch (Throwable $e) {
            // تجاهل الخطأ
        }
        
        // تحديث refund_method
        try {
            $refundColumn = $db->queryOne("SHOW COLUMNS FROM returns LIKE 'refund_method'");
            if (!empty($refundColumn)) {
                $type = $refundColumn['Type'] ?? '';
                if (stripos($type, 'company_request') === false) {
                    $db->execute("ALTER TABLE returns MODIFY `refund_method` enum('cash','credit','exchange','company_request') DEFAULT 'cash'");
                }
            }
        } catch (Throwable $e) {
            // تجاهل الخطأ
        }
        
        // إضافة invoice_item_id إلى return_items إذا لم يكن موجوداً
        try {
            $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
            if (empty($columnCheck)) {
                $db->execute("ALTER TABLE `return_items` ADD COLUMN `invoice_item_id` int(11) DEFAULT NULL AFTER `sale_item_id`");
                $db->execute("ALTER TABLE `return_items` ADD INDEX `idx_invoice_item_id` (`invoice_item_id`)");
            }
        } catch (Throwable $e) {
            // تجاهل الخطأ
        }
        
        // إضافة عمود is_damaged إلى return_items إذا لم يكن موجوداً
        try {
            $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'is_damaged'");
            if (empty($columnCheck)) {
                // التحقق من وجود عمود condition لتحديد الموضع
                $conditionColumn = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'condition'");
                if (!empty($conditionColumn)) {
                    $db->execute("ALTER TABLE `return_items` ADD COLUMN `is_damaged` TINYINT(1) NOT NULL DEFAULT 0 AFTER `condition`");
                } else {
                    // إذا لم يكن هناك عمود condition، أضفه في النهاية
                    $db->execute("ALTER TABLE `return_items` ADD COLUMN `is_damaged` TINYINT(1) NOT NULL DEFAULT 0");
                }
                error_log("Successfully added is_damaged column to return_items table");
            }
        } catch (Throwable $e) {
            error_log("Error adding is_damaged column: " . $e->getMessage());
            // لا نوقف العملية
        }
        
        // إضافة دعم return_request في approvals type enum
        try {
            $approvalsTableExists = $db->queryOne("SHOW TABLES LIKE 'approvals'");
            if (!empty($approvalsTableExists)) {
                // الحصول على معلومات عمود type
                $typeColumn = $db->queryOne(
                    "SELECT COLUMN_TYPE 
                     FROM information_schema.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'approvals' 
                     AND COLUMN_NAME = 'type'"
                );
                
                if (!empty($typeColumn)) {
                    $currentEnum = $typeColumn['COLUMN_TYPE'] ?? '';
                    
                    // التحقق إذا كان 'return_request' موجوداً
                    if (stripos($currentEnum, 'return_request') === false) {
                        error_log("Adding 'return_request' to approvals.type enum. Current enum: {$currentEnum}");
                        
                        // القيم الشائعة في enum
                        $commonTypes = [
                            'financial', 'sales', 'production', 'collection', 'salary',
                            'return_request', 'warehouse_transfer', 'exchange_request',
                            'invoice_return_company', 'salary_modification', 'inventory', 'other'
                        ];
                        
                        // محاولة استخراج القيم الموجودة
                        $existingValues = [];
                        if (preg_match_all("/'([^']+)'/", $currentEnum, $matches)) {
                            if (!empty($matches[1])) {
                                $existingValues = $matches[1];
                            }
                        }
                        
                        // إضافة return_request إذا لم يكن موجوداً
                        if (!in_array('return_request', $existingValues)) {
                            $existingValues[] = 'return_request';
                        }
                        
                        // إضافة أنواع أخرى مفقودة من القائمة الشائعة
                        foreach ($commonTypes as $type) {
                            if (!in_array($type, $existingValues)) {
                                $existingValues[] = $type;
                            }
                        }
                        
                        // إنشاء enum جديد
                        $newEnum = "enum('" . implode("','", $existingValues) . "')";
                        
                        // تحديث عمود type
                        try {
                            $db->execute(
                                "ALTER TABLE approvals MODIFY COLUMN `type` {$newEnum} NOT NULL"
                            );
                            error_log("Successfully added 'return_request' to approvals.type enum");
                        } catch (Throwable $e) {
                            error_log("Error updating approvals.type enum: " . $e->getMessage());
                            // محاولة طريقة أبسط - إضافة return_request فقط
                            try {
                                // استخراج enum الحالي وإضافة return_request
                                if (preg_match("/enum\s*\(\s*'([^']+)'(\s*,\s*'[^']+')*\s*\)/i", $currentEnum, $enumMatch)) {
                                    $currentTypes = array_filter(explode("','", str_replace(["enum('", "')", " "], "", $currentEnum)));
                                    if (!in_array('return_request', $currentTypes)) {
                                        $currentTypes[] = 'return_request';
                                        $simpleEnum = "enum('" . implode("','", $currentTypes) . "')";
                                        $db->execute("ALTER TABLE approvals MODIFY COLUMN `type` {$simpleEnum} NOT NULL");
                                        error_log("Successfully added 'return_request' using simple method");
                                    }
                                }
                            } catch (Throwable $e2) {
                                error_log("Error in simple enum update: " . $e2->getMessage());
                            }
                        }
                    } else {
                        error_log("'return_request' already exists in approvals.type enum");
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Error ensuring return_request approval type: " . $e->getMessage());
            // لا نوقف العملية
        }
        
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensureReturnSchemaCompatibility error: ' . $e->getMessage());
    }
}

// استدعاء تلقائي
ensureReturnSchemaCompatibility();

// ============================================================================
// 2. وظائف التحقق والمساعدة
// ============================================================================

/**
 * الحصول على الكمية المرتجعة بالفعل
 */
function getAlreadyReturnedQuantity(int $invoiceItemId, int $productId): float
{
    $db = db();
    
    // التحقق من وجود عمود invoice_item_id
    $hasInvoiceItemId = false;
    try {
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
        $hasInvoiceItemId = !empty($columnCheck);
    } catch (Throwable $e) {
        $hasInvoiceItemId = false;
    }
    
    if ($hasInvoiceItemId) {
        $result = $db->queryOne(
            "SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE ri.invoice_item_id = ?
               AND ri.product_id = ?
               AND r.status IN ('pending', 'approved', 'processed')",
            [$invoiceItemId, $productId]
        );
    } else {
        // Fallback: استخدام product_id فقط
        $result = $db->queryOne(
            "SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE ri.product_id = ?
               AND r.status IN ('pending', 'approved', 'processed')",
            [$productId]
        );
    }
    
    return (float)($result['returned_quantity'] ?? 0);
}

// ============================================================================
// 3. وظائف المعالجة المالية
// ============================================================================

/**
 * معالجة التسويات المالية للمرتجع
 * 
 * القواعد:
 * 1. إذا كان العميل مدين (balance > 0): خصم من الدين
 * 2. إذا كان غير مدين (balance <= 0): إضافة رصيد دائن
 * 3. إذا كان المرتجع أكبر من الدين: تخفيض الدين حتى 0 والمتبقي رصيد دائن
 */
function processReturnFinancials(int $returnId, ?int $processedBy = null): array
{
    error_log(">>> processReturnFinancials START - Return ID: {$returnId}");
    
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        $return = $db->queryOne(
            "SELECT r.*, c.id as customer_id, c.name as customer_name, c.balance as customer_balance
             FROM returns r
             INNER JOIN customers c ON r.customer_id = c.id
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        // التحقق من حالة المرتجع
        if ($return['status'] !== 'approved') {
            return ['success' => false, 'message' => 'المرتجع غير معتمد بعد. لا يمكن معالجة التسويات المالية. الحالة الحالية: ' . ($return['status'] ?? 'unknown')];
        }
        
        $customerId = (int)$return['customer_id'];
        $returnAmount = (float)$return['refund_amount'];
        $currentBalance = (float)$return['customer_balance'];
        
        if ($returnAmount <= 0) {
            return ['success' => false, 'message' => 'مبلغ المرتجع غير صالح'];
        }
        
        if ($processedBy === null) {
            $currentUser = getCurrentUser();
            $processedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        // حفظ الرصيد القديم
        $oldBalance = $currentBalance;
        
        // التحقق من وجود transaction نشطة
        $transactionStarted = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $transactionStarted = true;
        }
        
        try {
            // حساب الرصيد الجديد
            $debtReduction = 0.0;
            $creditAdded = 0.0;
            $newBalance = 0.0;
            
            $currentDebt = $currentBalance > 0 ? $currentBalance : 0.0;
            
            if ($currentDebt > 0) {
                if ($returnAmount <= $currentDebt) {
                    // المرتجع يغطي جزء أو كل الدين
                    $debtReduction = $returnAmount;
                    $newBalance = round($currentBalance - $returnAmount, 2);
                    $creditAdded = 0.0;
                } else {
                    // المرتجع أكبر من الدين
                    $debtReduction = $currentDebt;
                    $creditAdded = round($returnAmount - $currentDebt, 2);
                    $newBalance = -$creditAdded;
                }
            } else {
                // العميل غير مدين
                $debtReduction = 0.0;
                $creditAdded = $returnAmount;
                $newBalance = round($currentBalance - $returnAmount, 2);
            }
            
            // تحديث رصيد العميل
            $db->execute(
                "UPDATE customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
            
            // تسجيل في audit_logs
            logAudit($processedBy, 'process_return_financials', 'returns', $returnId, [
                'customer_id' => $customerId,
                'old_balance' => $currentBalance,
                'old_debt' => $currentDebt,
                'return_amount' => $returnAmount,
                'debt_reduction' => $debtReduction,
                'credit_added' => $creditAdded,
                'new_balance' => $newBalance,
                'action' => 'processed_return_financials',
                'details' => sprintf(
                    'تمت معالجة التسوية المالية: خصم %.2f ج.م من الدين، إضافة %.2f ج.م للرصيد الدائن',
                    $debtReduction,
                    $creditAdded
                )
            ], [
                'customer_id' => $customerId,
                'return_number' => $return['return_number'],
                'customer_name' => $return['customer_name'] ?? null
            ]);
            
            if ($transactionStarted) {
                $db->commit();
            }
            
            $message = 'تمت معالجة التسوية المالية بنجاح';
            if ($debtReduction > 0) {
                $message .= sprintf("\nتم خصم %.2f جنيه من دين العميل", $debtReduction);
            }
            if ($creditAdded > 0) {
                $message .= sprintf("\nتم إضافة %.2f جنيه كرصيد دائن للعميل", $creditAdded);
            }
            
            return [
                'success' => true,
                'message' => $message,
                'debt_reduction' => $debtReduction,
                'credit_added' => $creditAdded,
                'new_balance' => $newBalance,
                'old_balance' => $currentBalance
            ];
            
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log("processReturnFinancials error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء معالجة التسوية المالية: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("processReturnFinancials fatal error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

// ============================================================================
// 4. وظائف إدارة المخزون
// ============================================================================

/**
 * إرجاع المنتجات إلى مخزن سيارة المندوب
 */
function returnProductsToVehicleInventory(int $returnId, ?int $approvedBy = null): array
{
    error_log(">>> returnProductsToVehicleInventory START - Return ID: {$returnId}");
    
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        $return = $db->queryOne(
            "SELECT r.*, c.name as customer_name, u.id as sales_rep_id, u.full_name as sales_rep_name
             FROM returns r
             LEFT JOIN customers c ON r.customer_id = c.id
             LEFT JOIN users u ON r.sales_rep_id = u.id
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        // ملاحظة: لا نحتاج للتحقق من الحالة هنا لأن الدالة تُستدعى بعد تحديث الحالة إلى approved
        // if ($return['status'] !== 'approved') {
        //     return ['success' => false, 'message' => 'المرتجع غير معتمد بعد. لا يمكن إرجاع المنتجات للمخزون.'];
        // }
        
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        if ($salesRepId <= 0) {
            return ['success' => false, 'message' => 'المندوب غير محدد للمرتجع'];
        }
        
        // الحصول على سيارة المندوب
        $vehicle = $db->queryOne(
            "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$salesRepId]
        );
        
        if (!$vehicle) {
            return ['success' => false, 'message' => 'لا توجد سيارة نشطة للمندوب'];
        }
        
        $vehicleId = (int)$vehicle['id'];
        
        // الحصول على مخزن السيارة
        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
        if (!$vehicleWarehouse) {
            $createResult = createVehicleWarehouse($vehicleId);
            if (!$createResult['success']) {
                return ['success' => false, 'message' => 'تعذر تجهيز مخزن السيارة'];
            }
            $vehicleWarehouse = getVehicleWarehouse($vehicleId);
        }
        $warehouseId = $vehicleWarehouse ? (int)$vehicleWarehouse['id'] : null;
        
        // جلب عناصر المرتجع (استثناء التالف)
        // التحقق من وجود عمود is_damaged أولاً
        $hasIsDamaged = false;
        try {
            $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'is_damaged'");
            $hasIsDamaged = !empty($columnCheck);
        } catch (Throwable $e) {
            $hasIsDamaged = false;
        }
        
        if ($hasIsDamaged) {
            $returnItems = $db->query(
                "SELECT ri.*, p.name as product_name, p.unit
                 FROM return_items ri
                 LEFT JOIN products p ON ri.product_id = p.id
                 WHERE ri.return_id = ? AND (ri.is_damaged = 0 OR ri.is_damaged IS NULL)
                 ORDER BY ri.id",
                [$returnId]
            );
        } else {
            // إذا لم يكن العمود موجوداً، جلب جميع العناصر
            $returnItems = $db->query(
                "SELECT ri.*, p.name as product_name, p.unit
                 FROM return_items ri
                 LEFT JOIN products p ON ri.product_id = p.id
                 WHERE ri.return_id = ?
                 ORDER BY ri.id",
                [$returnId]
            );
        }
        
        if (empty($returnItems)) {
            return ['success' => true, 'message' => 'لا توجد منتجات سليمة للإرجاع للمخزون'];
        }
        
        if ($approvedBy === null) {
            $currentUser = getCurrentUser();
            $approvedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        // التحقق من وجود transaction
        $transactionStarted = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $transactionStarted = true;
        }
        
        try {
            foreach ($returnItems as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                $batchNumberId = isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null;
                $batchNumber = $item['batch_number'] ?? null;
                
                // البحث عن Batch Number في المخزون
                $inventoryRow = null;
                if ($batchNumberId) {
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity, finished_batch_id, finished_batch_number 
                         FROM vehicle_inventory 
                         WHERE vehicle_id = ? AND product_id = ? AND finished_batch_id = ?
                         FOR UPDATE",
                        [$vehicleId, $productId, $batchNumberId]
                    );
                }
                
                if (!$inventoryRow && $batchNumber) {
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity, finished_batch_id, finished_batch_number 
                         FROM vehicle_inventory 
                         WHERE vehicle_id = ? AND product_id = ? AND finished_batch_number = ?
                         FOR UPDATE",
                        [$vehicleId, $productId, $batchNumber]
                    );
                    if ($inventoryRow && $batchNumberId && !$inventoryRow['finished_batch_id']) {
                        $db->execute(
                            "UPDATE vehicle_inventory SET finished_batch_id = ? WHERE id = ?",
                            [$batchNumberId, (int)$inventoryRow['id']]
                        );
                    }
                }
                
                // البحث بدون batch
                if (!$inventoryRow) {
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity, finished_batch_id, finished_batch_number 
                         FROM vehicle_inventory 
                         WHERE vehicle_id = ? AND product_id = ? 
                         AND (finished_batch_id IS NULL OR finished_batch_id = 0)
                         AND (finished_batch_number IS NULL OR finished_batch_number = '')
                         FOR UPDATE",
                        [$vehicleId, $productId]
                    );
                }
                
                if ($inventoryRow) {
                    // تحديث الكمية الموجودة
                    $currentQuantity = (float)$inventoryRow['quantity'];
                    $newQuantity = round($currentQuantity + $quantity, 3);
                    
                    $db->execute(
                        "UPDATE vehicle_inventory 
                         SET quantity = ?, last_updated_by = ?, last_updated_at = NOW()
                         WHERE id = ?",
                        [$newQuantity, $approvedBy, (int)$inventoryRow['id']]
                    );
                    
                    $quantityBefore = $currentQuantity;
                    $quantityAfter = $newQuantity;
                } else {
                    // إنشاء سجل جديد
                    $product = $db->queryOne(
                        "SELECT name, category, unit FROM products WHERE id = ?",
                        [$productId]
                    );
                    
                    if (!$product) {
                        continue;
                    }
                    
                    // جلب بيانات التشغيلة
                    $batchInfo = null;
                    if ($batchNumberId) {
                        $batchInfo = $db->queryOne(
                            "SELECT bn.id, bn.batch_number, fp.production_date, fp.quantity_produced, fp.workers
                             FROM batch_numbers bn
                             LEFT JOIN finished_products fp ON bn.id = fp.batch_id
                             WHERE bn.id = ?",
                            [$batchNumberId]
                        );
                    }
                    
                    $db->execute(
                        "INSERT INTO vehicle_inventory 
                        (vehicle_id, warehouse_id, product_id, product_name, product_category, product_unit,
                         finished_batch_id, finished_batch_number, finished_production_date,
                         finished_quantity_produced, finished_workers, quantity, last_updated_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $vehicleId,
                            $warehouseId,
                            $productId,
                            $product['name'] ?? null,
                            $product['category'] ?? null,
                            $product['unit'] ?? null,
                            $batchNumberId,
                            $batchInfo ? ($batchInfo['batch_number'] ?? null) : null,
                            $batchInfo ? ($batchInfo['production_date'] ?? null) : null,
                            $batchInfo ? ($batchInfo['quantity_produced'] ?? null) : null,
                            $batchInfo ? ($batchInfo['workers'] ?? null) : null,
                            $quantity,
                            $approvedBy
                        ]
                    );
                    
                    $quantityBefore = 0;
                    $quantityAfter = $quantity;
                }
                
                // تسجيل حركة المخزون
                if (function_exists('recordInventoryMovement')) {
                    recordInventoryMovement(
                        $productId,
                        $warehouseId,
                        'in',
                        $quantity,
                        $quantityBefore,
                        $quantityAfter,
                        'return',
                        $returnId,
                        "إرجاع منتج من مرتجع رقم: {$return['return_number']}",
                        $approvedBy
                    );
                }
            }
            
            // تسجيل في audit_logs
            logAudit($approvedBy, 'return_to_inventory', 'returns', $returnId, null, [
                'return_number' => $return['return_number'],
                'items_count' => count($returnItems),
                'vehicle_id' => $vehicleId,
                'sales_rep_id' => $salesRepId,
                'customer_id' => $return['customer_id'] ?? null,
                'action' => 'returned_products_to_vehicle_inventory',
                'details' => 'تم إرجاع جميع المنتجات المرتجعة إلى مخزن سيارة المندوب'
            ]);
            
            if ($transactionStarted) {
                $db->commit();
            }
            
            return [
                'success' => true,
                'message' => 'تم إرجاع المنتجات إلى مخزن السيارة بنجاح',
                'items_count' => count($returnItems)
            ];
            
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log("returnProductsToVehicleInventory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء إرجاع المنتجات للمخزون: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("returnProductsToVehicleInventory fatal error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

// ============================================================================
// 5. وظائف خصم مرتب المندوب
// ============================================================================

/**
 * تطبيق خصم مرتب المندوب حسب القواعد
 * 
 * القواعد:
 * 1. إذا كان للعميل رصيد دائن (Credit) أكبر من مبلغ المرتجع: لا خصم (0%)
 * 2. إذا كان العميل لديه رصيد مدين (Debit) أقل من مبلغ المرتجع: خصم 2% من الفرق
 * 3. إذا كان رصيد العميل = 0 أو رصيد دائن <= مبلغ المرتجع: خصم 2% من كامل المرتجع
 */
function applyReturnSalaryDeduction(int $returnId, ?int $salesRepId = null, ?int $processedBy = null): array
{
    error_log(">>> applyReturnSalaryDeduction START - Return ID: {$returnId}");
    
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        $return = $db->queryOne(
            "SELECT r.*, c.id as customer_id, c.balance as current_customer_balance
             FROM returns r
             LEFT JOIN customers c ON r.customer_id = c.id
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        // الحصول على معرف المندوب
        if ($salesRepId === null) {
            $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        }
        
        if ($salesRepId <= 0) {
            return [
                'success' => true,
                'message' => 'لا يوجد مندوب مرتبط بالمرتجع. لا يتم تطبيق خصم.',
                'deduction_amount' => 0.0
            ];
        }
        
        // الحصول على رصيد العميل قبل المرتجع من audit_log
        $customerBalanceBeforeReturn = 0.0;
        $auditLog = $db->queryOne(
            "SELECT old_value FROM audit_logs
             WHERE action = 'process_return_financials'
             AND entity_type = 'returns'
             AND entity_id = ?
             ORDER BY created_at DESC
             LIMIT 1",
            [$returnId]
        );
        
        if ($auditLog && !empty($auditLog['old_value'])) {
            $oldValue = json_decode($auditLog['old_value'], true);
            $customerBalanceBeforeReturn = (float)($oldValue['old_balance'] ?? 0);
        } else {
            // استخدام الرصيد الحالي كتقدير
            $customerBalanceBeforeReturn = (float)($return['current_customer_balance'] ?? 0);
        }
        
        $returnAmount = (float)$return['refund_amount'];
        
        // حساب رصيد الدين والرصيد الدائن
        $customerDebitBeforeReturn = $customerBalanceBeforeReturn > 0 ? $customerBalanceBeforeReturn : 0.0;
        $customerCreditBeforeReturn = $customerBalanceBeforeReturn < 0 ? abs($customerBalanceBeforeReturn) : 0.0;
        
        // تطبيق القواعد
        $amountToDeduct = 0.0;
        $calculationDetails = [];
        
        // القاعدة 1: رصيد دائن > مبلغ المرتجع
        if ($customerCreditBeforeReturn > 0 && $customerCreditBeforeReturn > $returnAmount) {
            $amountToDeduct = 0.0;
            $calculationDetails = [
                'rule' => 1,
                'customer_credit' => $customerCreditBeforeReturn,
                'return_amount' => $returnAmount,
                'reason' => 'رصيد العميل الدائن أكبر من مبلغ المرتجع'
            ];
        }
        // القاعدة 2: رصيد مدين < مبلغ المرتجع
        elseif ($customerDebitBeforeReturn > 0 && $customerDebitBeforeReturn < $returnAmount) {
            $difference = $returnAmount - $customerDebitBeforeReturn;
            $amountToDeduct = round($difference * 0.02, 2);
            $calculationDetails = [
                'rule' => 2,
                'customer_debit' => $customerDebitBeforeReturn,
                'return_amount' => $returnAmount,
                'difference' => $difference,
                'deduction_percentage' => 2,
                'reason' => 'رصيد العميل المدين أقل من مبلغ المرتجع'
            ];
        }
        // القاعدة 3: رصيد = 0 أو رصيد دائن <= مبلغ المرتجع
        else {
            $amountToDeduct = round($returnAmount * 0.02, 2);
            $calculationDetails = [
                'rule' => 3,
                'customer_balance' => $customerBalanceBeforeReturn,
                'return_amount' => $returnAmount,
                'deduction_percentage' => 2,
                'reason' => 'رصيد العميل = 0 أو رصيد دائن (Credit <= returnAmount)'
            ];
        }
        
        if ($amountToDeduct <= 0) {
            return [
                'success' => true,
                'message' => 'لا يتم خصم أي مبلغ من مرتب المندوب',
                'deduction_amount' => 0.0,
                'calculation_details' => $calculationDetails
            ];
        }
        
        // الحصول على المستخدم الحالي
        if ($processedBy === null) {
            $currentUser = getCurrentUser();
            $processedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        // الحصول على الشهر والسنة
        $returnDate = $return['return_date'] ?? date('Y-m-d');
        $timestamp = strtotime($returnDate) ?: time();
        $month = (int)date('n', $timestamp);
        $year = (int)date('Y', $timestamp);
        
        // الحصول على أو إنشاء سجل الراتب
        $salaryResult = createOrUpdateSalary($salesRepId, $month, $year);
        
        if (!($salaryResult['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'فشل الحصول على سجل الراتب: ' . ($salaryResult['message'] ?? 'خطأ غير معروف')
            ];
        }
        
        $salaryId = (int)($salaryResult['salary_id'] ?? 0);
        if ($salaryId <= 0) {
            return [
                'success' => false,
                'message' => 'لم يتم العثور على معرف سجل الراتب'
            ];
        }
        
        // بدء المعاملة
        $transactionStarted = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $transactionStarted = true;
        }
        
        try {
            // الحصول على سجل الراتب
            $salary = $db->queryOne(
                "SELECT deductions, total_amount FROM salaries WHERE id = ? FOR UPDATE",
                [$salaryId]
            );
            
            if (!$salary) {
                throw new Exception('سجل الراتب غير موجود');
            }
            
            $currentDeductions = (float)($salary['deductions'] ?? 0);
            $currentTotal = (float)($salary['total_amount'] ?? 0);
            
            // التحقق من عدم تطبيق الخصم مسبقاً
            $existingDeduction = $db->queryOne(
                "SELECT id FROM audit_logs 
                 WHERE action = 'return_salary_deduction' 
                 AND entity_type = 'returns' 
                 AND entity_id = ?",
                [$returnId]
            );
            
            if ($existingDeduction) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                return [
                    'success' => true,
                    'message' => 'تم تطبيق الخصم مسبقاً',
                    'deduction_amount' => 0.0
                ];
            }
            
            // حساب الخصم الجديد
            $newDeductions = round($currentDeductions + $amountToDeduct, 2);
            $newTotal = round($currentTotal - $amountToDeduct, 2);
            
            // تحديث الراتب
            $db->execute(
                "UPDATE salaries SET deductions = ?, total_amount = ? WHERE id = ?",
                [$newDeductions, $newTotal, $salaryId]
            );
            
            // تسجيل في audit_logs
            logAudit($processedBy, 'return_salary_deduction', 'returns', $returnId, [
                'salary_id' => $salaryId,
                'sales_rep_id' => $salesRepId,
                'deduction_amount' => $amountToDeduct,
                'old_deductions' => $currentDeductions,
                'new_deductions' => $newDeductions,
                'old_total' => $currentTotal,
                'new_total' => $newTotal,
                'calculation_details' => $calculationDetails,
                'customer_balance_before_return' => $customerBalanceBeforeReturn,
                'return_amount' => $returnAmount
            ], [
                'return_number' => $return['return_number'],
                'month' => $month,
                'year' => $year,
                'customer_id' => (int)$return['customer_id']
            ]);
            
            // إرسال تنبيه للمندوب
            $salesRep = $db->queryOne(
                "SELECT full_name, email FROM users WHERE id = ?",
                [$salesRepId]
            );
            
            if ($salesRep) {
                sendNotification(
                    $salesRepId,
                    'خصم من المرتب - مرتجع',
                    sprintf(
                        "تم خصم %.2f جنيه من مرتبك بسبب مرتجع رقم %s.\nالشهر: %d/%d",
                        $amountToDeduct,
                        $return['return_number'],
                        $month,
                        $year
                    ),
                    'warning',
                    null
                );
            }
            
            if ($transactionStarted) {
                $db->commit();
            }
            
            return [
                'success' => true,
                'message' => sprintf('تم خصم %.2f جنيه من مرتب المندوب بنجاح', $amountToDeduct),
                'deduction_amount' => $amountToDeduct,
                'salary_id' => $salaryId,
                'month' => $month,
                'year' => $year
            ];
            
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
            }
            error_log("applyReturnSalaryDeduction error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء تطبيق خصم المرتب: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("applyReturnSalaryDeduction fatal error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

// ============================================================================
// 6. وظيفة الموافقة الشاملة على المرتجع
// ============================================================================

/**
 * الموافقة على المرتجع ومعالجة جميع الخطوات
 * 
 * الخطوات:
 * 1. التحقق من صحة البيانات
 * 2. تحديث الحالة إلى approved
 * 3. معالجة التسوية المالية
 * 4. إرجاع المنتجات للمخزون
 * 5. تطبيق خصم مرتب المندوب
 * 6. تحديث الحالة إلى processed
 */
function approveReturn(int $returnId, ?int $approvedBy = null, ?string $notes = null): array
{
    error_log("=== APPROVE RETURN START - Return ID: {$returnId} ===");
    
    try {
        $db = db();
        
        if ($approvedBy === null) {
            $currentUser = getCurrentUser();
            if (!$currentUser) {
                return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
            }
            $approvedBy = (int)$currentUser['id'];
        }
        
        // جلب بيانات المرتجع
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
            return ['success' => false, 'message' => 'طلب المرتجع غير موجود'];
        }
        
        // التحقق من الحالة
        if ($return['status'] !== 'pending') {
            return ['success' => false, 'message' => 'لا يمكن الموافقة على مرتجع تمت معالجته بالفعل'];
        }
        
        // التحقق من صحة البيانات
        $customerId = (int)$return['customer_id'];
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        $returnAmount = (float)$return['refund_amount'];
        $customerBalance = (float)($return['customer_balance'] ?? 0);
        
        if ($customerId <= 0) {
            return ['success' => false, 'message' => 'معرف العميل غير صالح'];
        }
        
        if ($returnAmount <= 0) {
            return ['success' => false, 'message' => 'مبلغ المرتجع يجب أن يكون أكبر من صفر'];
        }
        
        // التحقق من وجود العميل
        $customerExists = $db->queryOne(
            "SELECT id, name, balance FROM customers WHERE id = ?",
            [$customerId]
        );
        if (!$customerExists) {
            return ['success' => false, 'message' => 'العميل غير موجود في النظام'];
        }
        
        // جلب عناصر المرتجع
        $returnItems = $db->query(
            "SELECT ri.*, p.name as product_name, p.id as product_exists
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?",
            [$returnId]
        );
        
        if (empty($returnItems)) {
            return ['success' => false, 'message' => 'لا توجد عناصر في طلب المرتجع'];
        }
        
        // التحقق من صحة عناصر المرتجع
        foreach ($returnItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            
            if ($productId <= 0) {
                return ['success' => false, 'message' => 'معرف المنتج غير صالح في عناصر المرتجع'];
            }
            
            if ($quantity <= 0) {
                return ['success' => false, 'message' => 'كمية المنتج يجب أن تكون أكبر من صفر'];
            }
            
            if (!$item['product_exists']) {
                return ['success' => false, 'message' => "المنتج برقم {$productId} غير موجود في النظام"];
            }
        }
        
        // التحقق من وجود المندوب إذا كان محدداً
        if ($salesRepId > 0) {
            $salesRepExists = $db->queryOne(
                "SELECT id FROM users WHERE id = ? AND role = 'sales'",
                [$salesRepId]
            );
            if (!$salesRepExists) {
                return ['success' => false, 'message' => 'المندوب المحدد غير موجود أو ليس مندوب مبيعات'];
            }
        }
        
        // بدء المعاملة
        $db->beginTransaction();
        
        try {
            // 1. تحديث حالة المرتجع إلى approved
            $updateResult = $db->execute(
                "UPDATE returns SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
                [$approvedBy, $notes ?: null, $returnId]
            );
            
            if (($updateResult['affected_rows'] ?? 0) === 0) {
                throw new RuntimeException('فشل تحديث حالة المرتجع إلى approved');
            }
            
            error_log("Return {$returnId} status updated to 'approved', affected rows: " . ($updateResult['affected_rows'] ?? 0));
            
            // 2. معالجة التسوية المالية
            $financialResult = processReturnFinancials($returnId, $approvedBy);
            if (!$financialResult['success']) {
                throw new RuntimeException('فشل معالجة التسوية المالية: ' . ($financialResult['message'] ?? 'خطأ غير معروف'));
            }
            
            // 3. إرجاع المنتجات للمخزون
            $inventoryResult = returnProductsToVehicleInventory($returnId, $approvedBy);
            if (!$inventoryResult['success']) {
                throw new RuntimeException('فشل إرجاع المنتجات للمخزون: ' . ($inventoryResult['message'] ?? 'خطأ غير معروف'));
            }
            
            // 4. تطبيق خصم مرتب المندوب
            $penaltyResult = ['success' => true, 'deduction_amount' => 0.0];
            if ($salesRepId > 0) {
                $penaltyResult = applyReturnSalaryDeduction($returnId, $salesRepId, $approvedBy);
                // لا نفشل العملية إذا فشل الخصم (غير حرج)
                if (!$penaltyResult['success'] && ($penaltyResult['deduction_amount'] ?? 0) > 0) {
                    error_log("Warning: Failed to apply salary deduction: " . ($penaltyResult['message'] ?? ''));
                    $penaltyResult = ['success' => true, 'deduction_amount' => 0.0];
                }
            }
            
            // 5. حفظ المرتجعات التالفة
            try {
                // التحقق من وجود عمود is_damaged
                $hasIsDamaged = false;
                try {
                    $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'is_damaged'");
                    $hasIsDamaged = !empty($columnCheck);
                } catch (Throwable $e) {
                    $hasIsDamaged = false;
                }
                
                $damagedItems = [];
                if ($hasIsDamaged) {
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
                         WHERE ri.return_id = ? AND (ri.is_damaged = 1 OR ri.is_damaged = '1')",
                        [$returnId]
                    );
                } else {
                    // إذا لم يكن العمود موجوداً، نحاول استخدام condition column كبديل
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
                         WHERE ri.return_id = ? AND (ri.condition = 'damaged' OR ri.condition = 'defective')",
                        [$returnId]
                    );
                }
                
                if (!empty($damagedItems)) {
                    // التحقق من وجود جدول damaged_returns
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
                    
                    // حفظ المرتجعات التالفة
                    foreach ($damagedItems as $item) {
                        $existingRecord = $db->queryOne(
                            "SELECT id FROM damaged_returns WHERE return_item_id = ?",
                            [(int)$item['id']]
                        );
                        
                        if ($existingRecord) {
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
                        } else {
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
                        }
                    }
                }
            } catch (Throwable $e) {
                // لا نفشل العملية إذا فشل حفظ المرتجعات التالفة
                error_log("Warning: Failed to save damaged returns: " . $e->getMessage());
            }
            
            // 6. تحديث الحالة إلى processed
            $updateResult = $db->execute(
                "UPDATE returns SET status = 'processed', updated_at = NOW() WHERE id = ?",
                [$returnId]
            );
            
            if (($updateResult['affected_rows'] ?? 0) === 0) {
                throw new RuntimeException('فشل تحديث حالة المرتجع إلى processed');
            }
            
            error_log("Return {$returnId} status updated to 'processed' in transaction, affected rows: " . ($updateResult['affected_rows'] ?? 0));
            
            // 7. الموافقة على طلب الموافقة
            $entityColumn = getApprovalsEntityColumn();
            $approval = $db->queryOne(
                "SELECT id FROM approvals WHERE type = 'return_request' AND {$entityColumn} = ? AND status = 'pending'",
                [$returnId]
            );
            
            if ($approval) {
                approveRequest((int)$approval['id'], $approvedBy, $notes ?: 'تمت الموافقة على طلب المرتجع');
            }
            
            // تسجيل في audit_logs
            logAudit($approvedBy, 'approve_return', 'returns', $returnId, null, [
                'return_number' => $return['return_number'],
                'return_amount' => $returnAmount,
                'financial_result' => $financialResult,
                'penalty_result' => $penaltyResult,
                'inventory_result' => $inventoryResult,
                'notes' => $notes
            ]);
            
            // Commit المعاملة
            $db->commit();
            
            // التحقق من أن الحالة تم تحديثها بشكل صحيح بعد commit
            $verifyReturn = $db->queryOne(
                "SELECT status FROM returns WHERE id = ?",
                [$returnId]
            );
            
            if (empty($verifyReturn)) {
                error_log("ERROR: Return {$returnId} not found after commit!");
                throw new RuntimeException('المرتجع غير موجود بعد المعاملة');
            }
            
            $finalStatus = $verifyReturn['status'] ?? 'unknown';
            if ($finalStatus !== 'processed') {
                error_log("ERROR: Return {$returnId} status is '{$finalStatus}' instead of 'processed' after commit!");
                // لا نرمي خطأ هنا لأن المعاملة تم commit بالفعل
                // لكن نسجل الخطأ للمتابعة
            } else {
                error_log("SUCCESS: Return {$returnId} status confirmed as 'processed' after commit");
            }
            
            // بناء رسالة النجاح
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
            
            $successMessage = '✅ تمت الموافقة على طلب المرتجع بنجاح!';
            if ($financialNote) {
                $successMessage .= "\n\n" . $financialNote;
            }
            if (($inventoryResult['items_count'] ?? 0) > 0) {
                $successMessage .= "\n\n📦 تم إرجاع " . ($inventoryResult['items_count'] ?? 0) . " منتج(ات) إلى مخزن السيارة";
            }
            
            error_log("=== APPROVE RETURN COMPLETED SUCCESSFULLY ===");
            
            return [
                'success' => true,
                'message' => 'تمت الموافقة على طلب المرتجع بنجاح',
                'success_message' => $successMessage,
                'financial_note' => $financialNote,
                'new_balance' => $financialResult['new_balance'] ?? $customerBalance,
                'penalty_applied' => $deductionAmount,
                'items_returned' => $inventoryResult['items_count'] ?? 0,
                'return_number' => $return['return_number'] ?? '',
            ];
            
        } catch (Throwable $e) {
            $db->rollback();
            error_log("=== APPROVE RETURN ERROR ===");
            error_log("Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء معالجة الموافقة: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("approveReturn fatal error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

// ============================================================================
// 7. وظائف متوافقة مع النظام القديم (للتوافق العكسي)
// ============================================================================

/**
 * دالة متوافقة مع النظام القديم
 */
function processReturnFinancial(
    int $customerId,
    float $invoiceTotal,
    float $amountPaid,
    float $customerBalance,
    float $returnAmount,
    string $refundMethod = 'credit',
    ?int $salesRepId = null,
    string $returnNumber = '',
    ?int $processedBy = null
): array {
    // حساب الرصيد الجديد مباشرة
    $debtReduction = 0.0;
    $creditAdded = 0.0;
    $newBalance = 0.0;
    
    $currentDebt = $customerBalance > 0 ? $customerBalance : 0.0;
    
    if ($currentDebt > 0) {
        if ($returnAmount <= $currentDebt) {
            $debtReduction = $returnAmount;
            $newBalance = round($customerBalance - $returnAmount, 2);
            $creditAdded = 0.0;
        } else {
            $debtReduction = $currentDebt;
            $creditAdded = round($returnAmount - $currentDebt, 2);
            $newBalance = -$creditAdded;
        }
    } else {
        $debtReduction = 0.0;
        $creditAdded = $returnAmount;
        $newBalance = round($customerBalance - $returnAmount, 2);
    }
    
    // تحديث رصيد العميل
    try {
        $db = db();
        $db->execute(
            "UPDATE customers SET balance = ? WHERE id = ?",
            [$newBalance, $customerId]
        );
        
        $financialNote = '';
        if ($debtReduction > 0 && $creditAdded > 0) {
            $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل وإضافة %.2f ج.م لرصيده الدائن", $debtReduction, $creditAdded);
        } elseif ($debtReduction > 0) {
            $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل", $debtReduction);
        } elseif ($creditAdded > 0) {
            $financialNote = sprintf("تم إضافة %.2f ج.م لرصيد العميل الدائن", $creditAdded);
        }
        
        return [
            'success' => true,
            'message' => 'تمت معالجة التسوية المالية بنجاح',
            'financialNote' => $financialNote,
            'debt_reduction' => $debtReduction,
            'credit_added' => $creditAdded,
            'new_balance' => $newBalance
        ];
    } catch (Throwable $e) {
        error_log("processReturnFinancial error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ أثناء معالجة التسوية المالية: ' . $e->getMessage(),
            'financialNote' => ''
        ];
    }
}

