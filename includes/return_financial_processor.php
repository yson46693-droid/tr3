<?php
/**
 * معالج التسويات المالية للمرتجعات
 * Return Financial Processor
 * 
 * هذا الملف مسؤول عن معالجة التأثيرات المالية للمرتجعات على حساب العميل
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit_log.php';

/**
 * معالجة التسويات المالية للعميل بعد الموافقة على المرتجع
 * 
 * القواعد:
 * 1. إذا كان العميل مدين (balance > 0): خصم من الدين
 * 2. إذا كان غير مدين (balance <= 0): إضافة رصيد دائن
 * 3. إذا كان المرتجع أكبر من الدين: تخفيض الدين حتى 0 والمتبقي رصيد دائن
 * 
 * @param int $returnId معرف المرتجع
 * @param int|null $processedBy معرف المستخدم الذي قام بالمعالجة
 * @return array ['success' => bool, 'message' => string, 'debt_reduction' => float, 'credit_added' => float, 'new_balance' => float]
 */
function processReturnFinancials(int $returnId, ?int $processedBy = null): array
{
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
        
        // التحقق من حالة المرتجع (يجب أن يكون معتمد)
        if ($return['status'] !== 'approved') {
            return ['success' => false, 'message' => 'المرتجع غير معتمد بعد. لا يمكن معالجة التسويات المالية'];
        }
        
        $customerId = (int)$return['customer_id'];
        $returnAmount = (float)$return['refund_amount'];
        $currentBalance = (float)$return['customer_balance'];
        
        if ($returnAmount <= 0) {
            return ['success' => false, 'message' => 'مبلغ المرتجع غير صالح'];
        }
        
        // الحصول على المستخدم الحالي
        if ($processedBy === null) {
            $currentUser = getCurrentUser();
            $processedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        // بدء المعاملة
        $conn = $db->getConnection();
        $conn->begin_transaction();
        
        try {
            // حساب الرصيد الجديد حسب القواعد المطلوبة:
            // 1. إذا كان للعميل رصيد ديون (Debit): يتم خصم قيمة المرتجع من رصيد الديون
            // 2. إذا لم يكن للعميل رصيد ديون: تتم إضافة المبلغ إلى الرصيد الدائن (Credit)
            // 3. إذا كان رصيد ديون العميل أقل من قيمة المرتجع:
            //    - يتم خصم المتاح من الديون حتى يصل إلى صفر
            //    - ثم يُضاف المبلغ المتبقي إلى الرصيد الدائن للعميل
            
            $debtReduction = 0.0;
            $creditAdded = 0.0;
            $newBalance = 0.0;
            
            // حساب الدين الحالي (Debit) - الرصيد الموجب
            $currentDebt = $currentBalance > 0 ? $currentBalance : 0.0;
            
            if ($currentDebt > 0) {
                // الحالة 1 و 3: العميل مدين
                if ($returnAmount <= $currentDebt) {
                    // الحالة 1: المرتجع يغطي جزء أو كل الدين
                    $debtReduction = $returnAmount;
                    $newBalance = round($currentBalance - $returnAmount, 2);
                    $creditAdded = 0.0;
                } else {
                    // الحالة 3: المرتجع أكبر من الدين
                    // خصم المتاح من الديون حتى يصل إلى صفر
                    $debtReduction = $currentDebt;
                    // ثم إضافة المبلغ المتبقي إلى الرصيد الدائن
                    $creditAdded = round($returnAmount - $currentDebt, 2);
                    $newBalance = -$creditAdded; // سالب = رصيد دائن
                }
            } else {
                // الحالة 2: العميل غير مدين (balance <= 0)
                // إضافة المبلغ كرصيد سالب (Credit)
                $debtReduction = 0.0;
                $creditAdded = $returnAmount;
                $newBalance = round($currentBalance - $returnAmount, 2); // يصبح أكثر سالبية
            }
            
            // تحديث رصيد العميل
            $db->execute(
                "UPDATE customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
            
            // تسجيل في سجل التدقيق - تسجيل عملية المعالجة المالية للمرتجع
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
            
            // تأكيد المعاملة
            $conn->commit();
            
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
            $conn->rollback();
            error_log("Error processing return financials: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء معالجة التسوية المالية: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("Error in processReturnFinancials: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

/**
 * حساب جزء الرصيد الدائن من المرتجع (لخصم مرتب المندوب)
 * 
 * @param int $returnId معرف المرتجع
 * @return float المبلغ الذي تحول إلى رصيد دائن
 */
function calculateReturnCreditPortion(int $returnId): float
{
    try {
        $db = db();
        
        // جلب بيانات المرتجع مع رصيد العميل قبل المرتجع
        $return = $db->queryOne(
            "SELECT r.refund_amount, r.customer_id
             FROM returns r
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            return 0.0;
        }
        
        // نحتاج للحصول على رصيد العميل قبل معالجة المرتجع
        // سنفحص audit_log للحصول على الرصيد القديم
        $auditLog = $db->queryOne(
            "SELECT old_value FROM audit_logs
             WHERE action = 'process_return_financials'
             AND entity_type = 'returns'
             AND entity_id = ?
             ORDER BY created_at DESC
             LIMIT 1",
            [$returnId]
        );
        
        $oldBalance = 0.0;
        if ($auditLog && !empty($auditLog['old_value'])) {
            $oldValue = json_decode($auditLog['old_value'], true);
            $oldBalance = (float)($oldValue['old_balance'] ?? 0);
        } else {
            // إذا لم نجد في audit_log، نحسب من رصيد العميل الحالي + مبلغ المرتجع
            $customer = $db->queryOne(
                "SELECT balance FROM customers WHERE id = ?",
                [$return['customer_id']]
            );
            
            if ($customer) {
                $currentBalance = (float)$customer['balance'];
                $returnAmount = (float)$return['refund_amount'];
                // الرصيد القديم = الرصيد الحالي + مبلغ المرتجع (إذا كان الدين)
                // أو الرصيد الحالي - مبلغ المرتجع (إذا كان رصيد دائن)
                if ($currentBalance >= 0) {
                    $oldBalance = $currentBalance + $returnAmount;
                } else {
                    // كان رصيد دائن، الرصيد القديم = الرصيد الحالي + المرتجع
                    $oldBalance = $currentBalance + $returnAmount;
                }
            }
        }
        
        $returnAmount = (float)$return['refund_amount'];
        
        // حساب جزء الرصيد الدائن
        if ($oldBalance > 0) {
            // كان هناك دين
            if ($returnAmount > $oldBalance) {
                // المرتجع أكبر من الدين
                return round($returnAmount - $oldBalance, 2);
            } else {
                // المرتجع يغطي الدين بالكامل
                return 0.0;
            }
        } else {
            // لم يكن هناك دين (رصيد دائن أو صفر)
            return $returnAmount;
        }
        
    } catch (Throwable $e) {
        error_log("Error calculating return credit portion: " . $e->getMessage());
        return 0.0;
    }
}

