<?php
/**
 * Return & Exchange Processor - New Business Logic
 * Implements the new return and exchange processing rules
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/inventory_movements.php';
require_once __DIR__ . '/vehicle_inventory.php';
require_once __DIR__ . '/approval_system.php';
require_once __DIR__ . '/salary_calculator.php';

/**
 * Get customer purchase history with batch numbers
 * 
 * @param int $customerId
 * @param int|null $salesRepId
 * @return array Purchase history entries
 */
function getCustomerPurchaseHistory(int $customerId, ?int $salesRepId = null): array
{
    $db = db();
    
    $sql = "SELECT 
                i.id as invoice_id,
                i.invoice_number,
                i.date as invoice_date,
                i.total_amount,
                i.paid_amount,
                i.status as invoice_status,
                ii.id as invoice_item_id,
                ii.product_id,
                p.name as product_name,
                p.unit,
                ii.quantity,
                ii.unit_price,
                ii.total_price,
                GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers,
                GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',') as batch_number_ids
            FROM invoices i
            INNER JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
            LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
            WHERE i.customer_id = ?";
    
    $params = [$customerId];
    
    if ($salesRepId) {
        $sql .= " AND i.sales_rep_id = ?";
        $params[] = $salesRepId;
    }
    
    $sql .= " GROUP BY i.id, ii.id
              ORDER BY i.date DESC, i.id DESC, ii.id ASC";
    
    $purchaseHistory = $db->query($sql, $params);
    
    // Calculate already returned quantities
    $returnedQuantities = [];
    $returnedRows = $db->query(
        "SELECT ri.invoice_item_id, ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
         FROM return_items ri
         INNER JOIN returns r ON r.id = ri.return_id
         WHERE r.customer_id = ?
           AND r.status IN ('pending', 'approved', 'processed', 'completed')
           AND ri.invoice_item_id IS NOT NULL
         GROUP BY ri.invoice_item_id, ri.product_id",
        [$customerId]
    );
    
    foreach ($returnedRows as $row) {
        $invoiceItemId = (int)$row['invoice_item_id'];
        $productId = (int)$row['product_id'];
        $key = "{$invoiceItemId}_{$productId}";
        $returnedQuantities[$key] = (float)$row['returned_quantity'];
    }
    
    $result = [];
    foreach ($purchaseHistory as $item) {
        $invoiceItemId = (int)$item['invoice_item_id'];
        $productId = (int)$item['product_id'];
        $key = "{$invoiceItemId}_{$productId}";
        $returnedQty = $returnedQuantities[$key] ?? 0.0;
        $purchasedQty = (float)$item['quantity'];
        $remainingQty = max(0, round($purchasedQty - $returnedQty, 3));
        
        $result[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'],
            'invoice_date' => $item['invoice_date'],
            'invoice_item_id' => $invoiceItemId,
            'product_id' => $productId,
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? '',
            'quantity_purchased' => $purchasedQty,
            'quantity_returned' => $returnedQty,
            'quantity_remaining' => $remainingQty,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => $item['batch_numbers'] ?? '',
            'batch_number_ids' => $item['batch_number_ids'] ?? '',
        ];
    }
    
    return $result;
}

/**
 * Calculate return impact - determines which case applies
 * 
 * @param float $customerBalance Current customer balance (positive = debt, negative = credit)
 * @param float $returnAmount Return amount
 * @return array Impact calculation details
 */
function calculateReturnImpact(float $customerBalance, float $returnAmount): array
{
    $customerDebt = $customerBalance > 0 ? $customerBalance : 0;
    $customerCredit = $customerBalance < 0 ? abs($customerBalance) : 0;
    
    if ($customerDebt > 0) {
        return [
            'case' => 1,
            'customerDebt' => $customerDebt,
            'customerCredit' => 0,
            'description' => 'Customer owes money to company'
        ];
    } elseif ($customerCredit > 0) {
        return [
            'case' => 2,
            'customerDebt' => 0,
            'customerCredit' => $customerCredit,
            'description' => 'Customer has credit'
        ];
    } else {
        return [
            'case' => 3,
            'customerDebt' => 0,
            'customerCredit' => 0,
            'description' => 'Customer has zero debt and zero credit'
        ];
    }
}

/**
 * Apply customer debt rules (CASE 1)
 * Deduct return amount from customer debt
 * If returnAmount > customerDebt, set debt to 0 and remaining becomes credit
 * 
 * @param int $customerId
 * @param float $returnAmount
 * @param float $currentDebt
 * @return array Result with new balance
 */
function applyCustomerDebtRules(int $customerId, float $returnAmount, float $currentDebt): array
{
    $db = db();
    
    if ($returnAmount <= $currentDebt) {
        // Deduct from debt
        $newDebt = round($currentDebt - $returnAmount, 2);
        $newCredit = 0;
        $newBalance = $newDebt;
    } else {
        // Debt becomes 0, remaining becomes credit
        $remainingAmount = round($returnAmount - $currentDebt, 2);
        $newDebt = 0;
        $newCredit = $remainingAmount;
        $newBalance = -$newCredit; // Negative balance = credit
    }
    
    $db->execute(
        "UPDATE customers SET balance = ? WHERE id = ?",
        [$newBalance, $customerId]
    );
    
    return [
        'success' => true,
        'newBalance' => $newBalance,
        'newDebt' => $newDebt,
        'newCredit' => $newCredit,
        'debtReduced' => min($returnAmount, $currentDebt),
        'creditAdded' => $returnAmount > $currentDebt ? round($returnAmount - $currentDebt, 2) : 0
    ];
}

/**
 * Apply customer credit rules (CASE 2)
 * Add entire returnAmount to customerCredit
 * 
 * @param int $customerId
 * @param float $returnAmount
 * @param float $currentCredit
 * @return array Result with new balance
 */
function applyCustomerCreditRules(int $customerId, float $returnAmount, float $currentCredit): array
{
    $db = db();
    
    $newCredit = round($currentCredit + $returnAmount, 2);
    $newBalance = -$newCredit; // Negative balance = credit
    
    $db->execute(
        "UPDATE customers SET balance = ? WHERE id = ?",
        [$newBalance, $customerId]
    );
    
    return [
        'success' => true,
        'newBalance' => $newBalance,
        'newCredit' => $newCredit,
        'creditAdded' => $returnAmount
    ];
}

/**
 * Apply sales rep penalty (CASE 3)
 * Deduct 2% of return amount from current month salary
 * 
 * @param int $salesRepId
 * @param float $returnAmount
 * @return array Result
 */
function applySalesRepPenalty(int $salesRepId, float $returnAmount): array
{
    $db = db();
    
    $penaltyAmount = round($returnAmount * 0.02, 2); // 2% penalty
    
    if ($penaltyAmount <= 0) {
        return [
            'success' => true,
            'penaltyAmount' => 0,
            'message' => 'No penalty applied'
        ];
    }
    
    // Get current month and year
    $currentMonth = (int)date('m');
    $currentYear = (int)date('Y');
    
    // Get or create current month salary
    require_once __DIR__ . '/salary_calculator.php';
    $salaryResult = createOrUpdateSalary($salesRepId, $currentMonth, $currentYear);
    
    if (!($salaryResult['success'] ?? false)) {
        return [
            'success' => false,
            'message' => 'Failed to get/create salary record: ' . ($salaryResult['message'] ?? 'Unknown error')
        ];
    }
    
    $salaryId = $salaryResult['salary_id'] ?? null;
    if (!$salaryId) {
        return [
            'success' => false,
            'message' => 'Salary ID not found'
        ];
    }
    
    // Get current salary record
    $salary = $db->queryOne(
        "SELECT deductions, total_amount FROM salaries WHERE id = ? FOR UPDATE",
        [$salaryId]
    );
    
    if (!$salary) {
        return [
            'success' => false,
            'message' => 'Salary record not found'
        ];
    }
    
    $currentDeductions = (float)($salary['deductions'] ?? 0);
    $currentTotal = (float)($salary['total_amount'] ?? 0);
    
    $newDeductions = round($currentDeductions + $penaltyAmount, 2);
    $newTotal = round($currentTotal - $penaltyAmount, 2);
    
    // Update salary
    $db->execute(
        "UPDATE salaries SET deductions = ?, total_amount = ? WHERE id = ?",
        [$newDeductions, $newTotal, $salaryId]
    );
    
    // Log audit
    logAudit($salesRepId, 'sales_rep_penalty', 'salaries', $salaryId, null, [
        'penalty_amount' => $penaltyAmount,
        'return_amount' => $returnAmount,
        'reason' => 'Customer return with zero balance'
    ]);
    
    return [
        'success' => true,
        'penaltyAmount' => $penaltyAmount,
        'salaryId' => $salaryId,
        'newDeductions' => $newDeductions,
        'newTotal' => $newTotal,
        'message' => "تم خصم {$penaltyAmount} ج.م من راتب المندوب"
    ];
}

/**
 * Move inventory to salesman car stock
 * Preserves batch numbers and prices
 * 
 * @param array $returnItems Array of return items with product_id, quantity, batch_number, unit_price
 * @param int $salesRepId
 * @param int|null $vehicleId
 * @param int $userId
 * @return array Result
 */
function moveInventoryToSalesmanCar(array $returnItems, int $salesRepId, ?int $vehicleId = null, int $userId): array
{
    $db = db();
    
    // Get or resolve vehicle ID
    if (!$vehicleId) {
        $vehicle = $db->queryOne(
            "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$salesRepId]
        );
        $vehicleId = $vehicle ? (int)$vehicle['id'] : null;
    }
    
    if (!$vehicleId) {
        return [
            'success' => false,
            'message' => 'لا يوجد سيارة مرتبطة بهذا المندوب'
        ];
    }
    
    // Get or create vehicle warehouse
    require_once __DIR__ . '/vehicle_inventory.php';
    $vehicleWarehouse = getVehicleWarehouse($vehicleId);
    if (!$vehicleWarehouse) {
        $createResult = createVehicleWarehouse($vehicleId);
        if (empty($createResult['success'])) {
            return [
                'success' => false,
                'message' => 'تعذر تجهيز مخزن السيارة'
            ];
        }
        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
    }
    
    $warehouseId = $vehicleWarehouse['id'] ?? null;
    if (!$warehouseId) {
        return [
            'success' => false,
            'message' => 'تعذر تحديد مخزن السيارة'
        ];
    }
    
    $conn = $db->getConnection();
    $conn->begin_transaction();
    
    try {
        foreach ($returnItems as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $batchNumber = $item['batch_number'] ?? null;
            $batchNumberId = isset($item['batch_number_id']) ? (int)$item['batch_number_id'] : null;
            
            // Get or create vehicle inventory record
            $inventoryRow = $db->queryOne(
                "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                [$vehicleId, $productId]
            );
            
            $currentQuantity = (float)($inventoryRow['quantity'] ?? 0);
            $newQuantity = round($currentQuantity + $quantity, 3);
            
            if ($inventoryRow) {
                // Update existing inventory
                $updateData = [
                    'quantity' => $newQuantity,
                    'last_updated_by' => $userId,
                    'last_updated_at' => date('Y-m-d H:i:s')
                ];
                
                // Update batch number if provided
                if ($batchNumberId) {
                    $updateData['finished_batch_id'] = $batchNumberId;
                }
                if ($batchNumber) {
                    $updateData['finished_batch_number'] = $batchNumber;
                }
                if ($unitPrice > 0) {
                    $updateData['product_unit_price'] = $unitPrice;
                }
                
                $setClause = [];
                $params = [];
                foreach ($updateData as $key => $value) {
                    $setClause[] = "{$key} = ?";
                    $params[] = $value;
                }
                $params[] = $inventoryRow['id'];
                
                $db->execute(
                    "UPDATE vehicle_inventory SET " . implode(', ', $setClause) . " WHERE id = ?",
                    $params
                );
            } else {
                // Create new inventory record
                $insertData = [
                    'vehicle_id' => $vehicleId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $newQuantity,
                    'last_updated_by' => $userId,
                ];
                
                if ($batchNumberId) {
                    $insertData['finished_batch_id'] = $batchNumberId;
                }
                if ($batchNumber) {
                    $insertData['finished_batch_number'] = $batchNumber;
                }
                if ($unitPrice > 0) {
                    $insertData['product_unit_price'] = $unitPrice;
                }
                
                $columns = array_keys($insertData);
                $values = array_values($insertData);
                $placeholders = array_fill(0, count($values), '?');
                
                $db->execute(
                    "INSERT INTO vehicle_inventory (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")",
                    $values
                );
            }
            
            // Record inventory movement
            recordInventoryMovement(
                $productId,
                $warehouseId,
                'in',
                $quantity,
                'return',
                null, // reference_id
                "إرجاع منتج - مرتجع",
                $userId
            );
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'تم نقل المخزون إلى مخزن السيارة بنجاح',
            'items_processed' => count($returnItems)
        ];
        
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('moveInventoryToSalesmanCar error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ أثناء نقل المخزون: ' . $e->getMessage()
        ];
    }
}

/**
 * Process exchange inventory switching
 * Move returned products from customer purchase → salesman car stock
 * Move replacement products from salesman car stock → customer purchase
 * 
 * @param array $returnItems Items being returned
 * @param array $replacementItems Items being given as replacement
 * @param int $customerId
 * @param int $salesRepId
 * @param int $userId
 * @return array Result
 */
function processExchangeInventory(array $returnItems, array $replacementItems, int $customerId, int $salesRepId, int $userId): array
{
    $db = db();
    $conn = $db->getConnection();
    
    try {
        // Step 1: Move returned items to salesman car stock
        $moveResult = moveInventoryToSalesmanCar($returnItems, $salesRepId, null, $userId);
        if (!$moveResult['success']) {
            throw new RuntimeException($moveResult['message'] ?? 'Failed to move returned items');
        }
        
        // Step 2: Move replacement items from salesman car stock to customer purchase
        // Get vehicle ID
        $vehicle = $db->queryOne(
            "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$salesRepId]
        );
        $vehicleId = $vehicle ? (int)$vehicle['id'] : null;
        
        if (!$vehicleId) {
            throw new RuntimeException('لا يوجد سيارة مرتبطة بهذا المندوب');
        }
        
        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
        if (!$vehicleWarehouse) {
            throw new RuntimeException('تعذر تحديد مخزن السيارة');
        }
        $warehouseId = $vehicleWarehouse['id'];
        
        // Deduct replacement items from vehicle inventory
        foreach ($replacementItems as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];
            
            $inventoryRow = $db->queryOne(
                "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                [$vehicleId, $productId]
            );
            
            if (!$inventoryRow) {
                throw new RuntimeException("المنتج غير موجود في مخزن السيارة");
            }
            
            $currentQuantity = (float)$inventoryRow['quantity'];
            if ($currentQuantity < $quantity) {
                throw new RuntimeException("الكمية المتاحة في مخزن السيارة ({$currentQuantity}) أقل من المطلوب ({$quantity})");
            }
            
            $newQuantity = round($currentQuantity - $quantity, 3);
            
            $db->execute(
                "UPDATE vehicle_inventory SET quantity = ?, last_updated_by = ?, last_updated_at = NOW() WHERE id = ?",
                [$newQuantity, $userId, $inventoryRow['id']]
            );
            
            // Record inventory movement
            recordInventoryMovement(
                $productId,
                $warehouseId,
                'out',
                $quantity,
                'exchange',
                null,
                "استبدال - نقل من مخزن السيارة للعميل",
                $userId
            );
        }
        
        // Step 3: Update customer purchase history (create new invoice items for replacement)
        // This would typically be done by creating a new invoice, but for exchange we track it differently
        // The exchange record itself tracks the replacement items
        
        return [
            'success' => true,
            'message' => 'تم استبدال المنتجات بنجاح',
            'returned_items' => count($returnItems),
            'replacement_items' => count($replacementItems)
        ];
        
    } catch (Throwable $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        error_log('processExchangeInventory error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ أثناء استبدال المنتجات: ' . $e->getMessage()
        ];
    }
}

/**
 * Format currency
 */
function formatCurrency(float $amount): string
{
    return number_format($amount, 2) . ' ج.م';
}
