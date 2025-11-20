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
         WHERE r.id = ? AND r.status = 'pending'",
        [$returnId]
    );
    
    if (!$return) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'طلب المرتجع غير موجود أو تمت معالجته بالفعل'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($action === 'reject') {
        // Reject return request
        $conn->begin_transaction();
        
        try {
            $db->execute(
                "UPDATE returns SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
                [$currentUser['id'], $notes ?: 'تم رفض الطلب', $returnId]
            );
            
            // Reject approval request
            $approval = $db->queryOne(
                "SELECT id FROM approvals WHERE type = 'return_request' AND entity_id = ? AND status = 'pending'",
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
    $conn->begin_transaction();
    
    try {
        // Get return items
        $returnItems = $db->query(
            "SELECT ri.*, p.name as product_name
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?",
            [$returnId]
        );
        
        if (empty($returnItems)) {
            throw new RuntimeException('لا توجد عناصر في طلب المرتجع');
        }
        
        $customerId = (int)$return['customer_id'];
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        $returnAmount = (float)$return['refund_amount'];
        $customerBalance = (float)($return['customer_balance'] ?? 0);
        
        // Calculate return impact
        $impact = calculateReturnImpact($customerBalance, $returnAmount);
        
        $financialResult = null;
        $penaltyResult = null;
        
        // Apply business logic based on case
        switch ($impact['case']) {
            case 1: // Customer owes money (customerDebt > 0)
                $financialResult = applyCustomerDebtRules($customerId, $returnAmount, $impact['customerDebt']);
                break;
                
            case 2: // Customer has credit (customerCredit > 0)
                $financialResult = applyCustomerCreditRules($customerId, $returnAmount, $impact['customerCredit']);
                break;
                
            case 3: // Customer has zero debt and zero credit
                // Apply 2% penalty to sales rep
                if ($salesRepId > 0) {
                    $penaltyResult = applySalesRepPenalty($salesRepId, $returnAmount);
                    if (!$penaltyResult['success']) {
                        throw new RuntimeException('فشل تطبيق عقوبة المندوب: ' . ($penaltyResult['message'] ?? 'خطأ غير معروف'));
                    }
                }
                $financialResult = [
                    'success' => true,
                    'newBalance' => $customerBalance,
                    'message' => 'لا يوجد تغيير في رصيد العميل - تم خصم 2% من راتب المندوب'
                ];
                break;
        }
        
        if (!$financialResult || !($financialResult['success'] ?? false)) {
            throw new RuntimeException('فشل تطبيق قواعد الرصيد: ' . ($financialResult['message'] ?? 'خطأ غير معروف'));
        }
        
        // Move inventory to salesman car stock
        $itemsForInventory = [];
        foreach ($returnItems as $item) {
            $itemsForInventory[] = [
                'product_id' => (int)$item['product_id'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'batch_number' => $item['batch_number'] ?? null,
                'batch_number_id' => isset($item['batch_number_id']) ? (int)$item['batch_number_id'] : null,
            ];
        }
        
        $inventoryResult = moveInventoryToSalesmanCar($itemsForInventory, $salesRepId, null, $currentUser['id']);
        if (!$inventoryResult['success']) {
            throw new RuntimeException('فشل نقل المخزون: ' . ($inventoryResult['message'] ?? 'خطأ غير معروف'));
        }
        
        // Update return status
        $db->execute(
            "UPDATE returns SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
            [$currentUser['id'], $notes ?: null, $returnId]
        );
        
        // Approve approval request
        $approval = $db->queryOne(
            "SELECT id FROM approvals WHERE type = 'return_request' AND entity_id = ? AND status = 'pending'",
            [$returnId]
        );
        
        if ($approval) {
            approveRequest((int)$approval['id'], $currentUser['id'], $notes ?: 'تمت الموافقة على طلب المرتجع');
        }
        
        // Build financial note
        $financialNote = '';
        if ($impact['case'] === 1) {
            $debtReduced = $financialResult['debtReduced'] ?? 0;
            $creditAdded = $financialResult['creditAdded'] ?? 0;
            if ($creditAdded > 0) {
                $financialNote = "تم خصم {$debtReduced} ج.م من دين العميل وإضافة {$creditAdded} ج.م لرصيده الدائن";
            } else {
                $financialNote = "تم خصم {$debtReduced} ج.م من دين العميل";
            }
        } elseif ($impact['case'] === 2) {
            $creditAdded = $financialResult['creditAdded'] ?? $returnAmount;
            $financialNote = "تم إضافة {$creditAdded} ج.م لرصيد العميل الدائن";
        } elseif ($impact['case'] === 3) {
            $penaltyAmount = $penaltyResult['penaltyAmount'] ?? ($returnAmount * 0.02);
            $financialNote = "تم خصم 2% ({$penaltyAmount} ج.م) من راتب المندوب - لا يوجد تغيير في رصيد العميل";
        }
        
        logAudit($currentUser['id'], 'approve_return', 'returns', $returnId, null, [
            'return_number' => $return['return_number'],
            'return_amount' => $returnAmount,
            'case' => $impact['case'],
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
            'new_balance' => $financialResult['newBalance'] ?? $customerBalance,
            'penalty_applied' => $penaltyResult ? $penaltyResult['penaltyAmount'] : 0,
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

