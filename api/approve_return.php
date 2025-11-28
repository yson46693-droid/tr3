<?php
/**
 * API for Approving Return Requests
 * Handles manager approval workflow with customer balance adjustments
 */

define('ACCESS_ALLOWED', true);

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

requireRole(['manager']);

$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'يجب استخدام طلب POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnId = isset($payload['return_id']) ? (int)$payload['return_id'] : 0;
$action = $payload['action'] ?? 'approve'; // 'approve' or 'reject'
$notes = trim($payload['notes'] ?? '');

if ($returnId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'معرف المرتجع غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();
    $conn = $db->getConnection();
    
    // Get return request
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
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'طلب المرتجع غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($action === 'reject') {
        // Check if return can be rejected (only pending returns)
        if ($return['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'لا يمكن رفض مرتجع تمت معالجته بالفعل'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Reject return request
        $conn->begin_transaction();
        
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
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'تم رفض طلب المرتجع بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    // Approve return request
    // Check if return can be approved (only pending returns)
    if ($return['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'لا يمكن الموافقة على مرتجع تمت معالجته بالفعل'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $conn->begin_transaction();
    
    try {
        // التحقق من صحة البيانات قبل المعالجة
        $customerId = (int)$return['customer_id'];
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        $returnAmount = (float)$return['refund_amount'];
        $customerBalance = (float)($return['customer_balance'] ?? 0);
        
        // Validation: التحقق من صحة البيانات
        if ($customerId <= 0) {
            throw new RuntimeException('معرف العميل غير صالح');
        }
        
        if ($returnAmount <= 0) {
            throw new RuntimeException('مبلغ المرتجع يجب أن يكون أكبر من صفر');
        }
        
        // التحقق من وجود العميل
        $customerExists = $db->queryOne(
            "SELECT id, name, balance FROM customers WHERE id = ?",
            [$customerId]
        );
        if (!$customerExists) {
            throw new RuntimeException('العميل غير موجود في النظام');
        }
        
        // Get return items
        $returnItems = $db->query(
            "SELECT ri.*, p.name as product_name, p.id as product_exists
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?",
            [$returnId]
        );
        
        if (empty($returnItems)) {
            throw new RuntimeException('لا توجد عناصر في طلب المرتجع');
        }
        
        // Validation: التحقق من صحة عناصر المرتجع
        foreach ($returnItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            
            if ($productId <= 0) {
                throw new RuntimeException('معرف المنتج غير صالح في عناصر المرتجع');
            }
            
            if ($quantity <= 0) {
                throw new RuntimeException('كمية المنتج يجب أن تكون أكبر من صفر');
            }
            
            if (!$item['product_exists']) {
                throw new RuntimeException("المنتج برقم {$productId} غير موجود في النظام");
            }
        }
        
        // التحقق من وجود المندوب إذا كان محدداً
        if ($salesRepId > 0) {
            $salesRepExists = $db->queryOne(
                "SELECT id FROM users WHERE id = ? AND role = 'sales'",
                [$salesRepId]
            );
            if (!$salesRepExists) {
                throw new RuntimeException('المندوب المحدد غير موجود أو ليس مندوب مبيعات');
            }
        }
        
        // Update return status to approved first (before processing)
        $db->execute(
            "UPDATE returns SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
            [$currentUser['id'], $notes ?: null, $returnId]
        );
        
        // Process financials using new system
        $financialResult = processReturnFinancials($returnId, $currentUser['id']);
        if (!$financialResult['success']) {
            throw new RuntimeException('فشل معالجة التسوية المالية: ' . ($financialResult['message'] ?? 'خطأ غير معروف'));
        }
        
        // Return products to inventory using new system
        // هذا سيضيف الكمية للمخزون إذا كان Batch Number موجود، أو ينشئ سجل جديد
        $inventoryResult = returnProductsToVehicleInventory($returnId, $currentUser['id']);
        if (!$inventoryResult['success']) {
            throw new RuntimeException('فشل إرجاع المنتجات للمخزون: ' . ($inventoryResult['message'] ?? 'خطأ غير معروف'));
        }
        
        // تسجيل عملية المرتجع في سجلات المرتجعات
        // (تم التسجيل بالفعل في return_inventory_manager.php و return_financial_processor.php)
        
        // Apply salary deduction using new system
        $penaltyResult = applyReturnSalaryDeduction($returnId, $salesRepId, $currentUser['id']);
        if (!$penaltyResult['success'] && $penaltyResult['deduction_amount'] > 0) {
            // Log but don't fail if penalty fails (it's non-critical)
            error_log('Warning: Failed to apply salary deduction: ' . ($penaltyResult['message'] ?? ''));
        }
        
        // حفظ المرتجعات التالفة في جدول damaged_returns بشكل كامل
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
                    }
                }
                
                // تسجيل في سجل التدقيق
                logAudit($currentUser['id'], 'save_damaged_returns', 'damaged_returns', $returnId, null, [
                    'return_number' => $return['return_number'],
                    'damaged_items_count' => count($damagedItems)
                ]);
            }
        } catch (Throwable $e) {
            // لا نوقف العملية إذا فشل حفظ المرتجعات التالفة، فقط نسجل الخطأ
            error_log('Warning: Failed to save damaged returns: ' . $e->getMessage());
        }
        
        // Approve approval request
        $entityColumn = getApprovalsEntityColumn();
        $approval = $db->queryOne(
            "SELECT id FROM approvals WHERE type = 'return_request' AND {$entityColumn} = ? AND status = 'pending'",
            [$returnId]
        );
        
        if ($approval) {
            approveRequest((int)$approval['id'], $currentUser['id'], $notes ?: 'تمت الموافقة على طلب المرتجع');
        }
        
        // Build financial note
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
        
        logAudit($currentUser['id'], 'approve_return', 'returns', $returnId, null, [
            'return_number' => $return['return_number'],
            'return_amount' => $returnAmount,
            'financial_result' => $financialResult,
            'penalty_result' => $penaltyResult,
            'inventory_result' => $inventoryResult,
            'notes' => $notes
        ]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تمت الموافقة على طلب المرتجع بنجاح',
            'financial_note' => $financialNote,
            'new_balance' => $financialResult['new_balance'] ?? $customerBalance,
            'penalty_applied' => $penaltyResult['deduction_amount'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('approve_return error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
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

