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

