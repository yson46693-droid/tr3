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
    error_log(">>> processReturnFinancials START - Return ID: {$returnId}, Processed By: " . ($processedBy ?? 'N/A'));
    
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        error_log("Fetching return data from database...");
        $return = $db->queryOne(
            "SELECT r.*, c.id as customer_id, c.name as customer_name, c.balance as customer_balance
             FROM returns r
             INNER JOIN customers c ON r.customer_id = c.id
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            error_log("ERROR: Return {$returnId} not found in database");
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        // التحقق من حالة المرتجع (يجب أن يكون معتمد)
        $currentStatus = $return['status'] ?? 'unknown';
        error_log("Return status check: Current='{$currentStatus}', Expected='approved'");
        
        if ($currentStatus !== 'approved') {
            error_log("ERROR: Return status is '{$currentStatus}', expected 'approved'");
            return ['success' => false, 'message' => 'المرتجع غير معتمد بعد. لا يمكن معالجة التسويات المالية. الحالة الحالية: ' . $currentStatus];
        }
        
        error_log("Return status validated. Customer ID: " . ($return['customer_id'] ?? 'N/A'));
        
        $customerId = (int)$return['customer_id'];
        $returnAmount = (float)$return['refund_amount'];
        $currentBalance = (float)$return['customer_balance'];
        
        error_log("Financial calculation parameters:");
        error_log("  Customer ID: {$customerId}");
        error_log("  Return Amount: {$returnAmount}");
        error_log("  Current Balance: {$currentBalance}");
        
        if ($returnAmount <= 0) {
            error_log("ERROR: Invalid return amount: {$returnAmount}");
            return ['success' => false, 'message' => 'مبلغ المرتجع غير صالح'];
        }
        
        // الحصول على المستخدم الحالي
        if ($processedBy === null) {
            $currentUser = getCurrentUser();
            $processedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        // حفظ الرصيد القديم قبل المعالجة (للاستخدام في حساب خصم المندوب)
        $oldBalance = $currentBalance;
        error_log("Old balance saved: {$oldBalance}");
        
        // التحقق من وجود transaction نشطة
        $conn = $db->getConnection();
        $transactionStarted = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $transactionStarted = true;
        }
        
        try {
            // حساب الرصيد الجديد حسب القواعد المطلوبة:
            error_log("Calculating new balance according to rules...");
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
            error_log("Current debt: {$currentDebt}");
            
            if ($currentDebt > 0) {
                // الحالة 1 و 3: العميل مدين
                error_log("Customer has debt. Applying rule 1 or 3...");
                if ($returnAmount <= $currentDebt) {
                    // الحالة 1: المرتجع يغطي جزء أو كل الدين
                    error_log("Rule 1: Return amount covers debt");
                    $debtReduction = $returnAmount;
                    $newBalance = round($currentBalance - $returnAmount, 2);
                    $creditAdded = 0.0;
                } else {
                    // الحالة 3: المرتجع أكبر من الدين
                    error_log("Rule 3: Return amount exceeds debt");
                    // خصم المتاح من الديون حتى يصل إلى صفر
                    $debtReduction = $currentDebt;
                    // ثم إضافة المبلغ المتبقي إلى الرصيد الدائن
                    $creditAdded = round($returnAmount - $currentDebt, 2);
                    $newBalance = -$creditAdded; // سالب = رصيد دائن
                }
            } else {
                // الحالة 2: العميل غير مدين (balance <= 0)
                error_log("Rule 2: Customer has no debt, adding credit");
                // إضافة المبلغ كرصيد سالب (Credit)
                $debtReduction = 0.0;
                $creditAdded = $returnAmount;
                $newBalance = round($currentBalance - $returnAmount, 2); // يصبح أكثر سالبية
            }
            
            error_log("Calculation results:");
            error_log("  Debt Reduction: {$debtReduction}");
            error_log("  Credit Added: {$creditAdded}");
            error_log("  New Balance: {$newBalance}");
            
            // تحديث رصيد العميل
            error_log("Updating customer balance in database...");
            $updateResult = $db->execute(
                "UPDATE customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
            error_log("Customer balance updated. Affected rows: " . ($updateResult['affected_rows'] ?? 0));
            
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
            
            // تأكيد المعاملة فقط إذا بدأناها نحن
            if ($transactionStarted) {
                error_log("Committing transaction (we started it)...");
                $db->commit();
            } else {
                error_log("Transaction was started by caller, not committing here");
            }
            
            $message = 'تمت معالجة التسوية المالية بنجاح';
            if ($debtReduction > 0) {
                $message .= sprintf("\nتم خصم %.2f جنيه من دين العميل", $debtReduction);
            }
            if ($creditAdded > 0) {
                $message .= sprintf("\nتم إضافة %.2f جنيه كرصيد دائن للعميل", $creditAdded);
            }
            
            error_log(">>> processReturnFinancials SUCCESS");
            error_log("Final result: Debt Reduction={$debtReduction}, Credit Added={$creditAdded}, New Balance={$newBalance}");
            
            return [
                'success' => true,
                'message' => $message,
                'debt_reduction' => $debtReduction,
                'credit_added' => $creditAdded,
                'new_balance' => $newBalance,
                'old_balance' => $currentBalance
            ];
            
        } catch (Throwable $e) {
            // Rollback فقط إذا بدأنا transaction
            if ($transactionStarted) {
                error_log("ERROR: Rolling back transaction...");
                $db->rollback();
            }
            error_log(">>> processReturnFinancials ERROR");
            error_log("Error message: " . $e->getMessage());
            error_log("Error type: " . get_class($e));
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء معالجة التسوية المالية: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log(">>> processReturnFinancials FATAL ERROR");
        error_log("Error message: " . $e->getMessage());
        error_log("Error type: " . get_class($e));
        error_log("Stack trace: " . $e->getTraceAsString());
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

/**
 * دالة متوافقة مع النظام القديم - تعالج التسوية المالية للمرتجع
 * هذه الدالة wrapper للدالة الجديدة processReturnFinancials()
 * 
 * @param int $customerId معرف العميل
 * @param float $invoiceTotal إجمالي الفاتورة
 * @param float $amountPaid المبلغ المدفوع
 * @param float $customerBalance رصيد العميل الحالي
 * @param float $returnAmount مبلغ المرتجع
 * @param string $refundMethod طريقة الاسترداد
 * @param int|null $salesRepId معرف المندوب
 * @param string $returnNumber رقم المرتجع
 * @param int|null $processedBy معرف المستخدم الذي قام بالمعالجة
 * @return array ['success' => bool, 'message' => string, 'financialNote' => string]
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
    error_log(">>> processReturnFinancial (Legacy) START");
    error_log("Customer ID: {$customerId}, Return Amount: {$returnAmount}, Balance: {$customerBalance}");
    
    try {
        $db = db();
        
        // البحث عن المرتجع بالرقم أو إنشاء واحد مؤقت إذا لم يكن موجوداً
        $returnId = null;
        if ($returnNumber) {
            $return = $db->queryOne(
                "SELECT id, status FROM returns WHERE return_number = ?",
                [$returnNumber]
            );
            if ($return) {
                $returnId = (int)$return['id'];
            }
        }
        
        // إذا لم نجد المرتجع، نحسب مباشرة (للسيناريوهات القديمة)
        if (!$returnId) {
            // حساب الرصيد الجديد حسب القواعد
            $debtReduction = 0.0;
            $creditAdded = 0.0;
            $newBalance = 0.0;
            
            $currentDebt = $customerBalance > 0 ? $customerBalance : 0.0;
            
            if ($currentDebt > 0) {
                if ($returnAmount <= $currentDebt) {
                    // المرتجع يغطي جزء أو كل الدين
                    $debtReduction = $returnAmount;
                    $newBalance = round($customerBalance - $returnAmount, 2);
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
                $newBalance = round($customerBalance - $returnAmount, 2);
            }
            
            // تحديث رصيد العميل
            $db->execute(
                "UPDATE customers SET balance = ? WHERE id = ?",
                [$newBalance, $customerId]
            );
            
            // بناء رسالة النتيجة
            $financialNote = '';
            if ($debtReduction > 0 && $creditAdded > 0) {
                $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل وإضافة %.2f ج.م لرصيده الدائن", $debtReduction, $creditAdded);
            } elseif ($debtReduction > 0) {
                $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل", $debtReduction);
            } elseif ($creditAdded > 0) {
                $financialNote = sprintf("تم إضافة %.2f ج.م لرصيد العميل الدائن", $creditAdded);
            }
            
            error_log(">>> processReturnFinancial (Legacy) SUCCESS");
            
            return [
                'success' => true,
                'message' => 'تمت معالجة التسوية المالية بنجاح',
                'financialNote' => $financialNote,
                'debt_reduction' => $debtReduction,
                'credit_added' => $creditAdded,
                'new_balance' => $newBalance
            ];
        }
        
        // إذا كان المرتجع موجوداً، استخدم الدالة الجديدة
        $result = processReturnFinancials($returnId, $processedBy);
        
        if ($result['success']) {
            // بناء financialNote
            $financialNote = '';
            $debtReduction = $result['debt_reduction'] ?? 0.0;
            $creditAdded = $result['credit_added'] ?? 0.0;
            
            if ($debtReduction > 0 && $creditAdded > 0) {
                $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل وإضافة %.2f ج.م لرصيده الدائن", $debtReduction, $creditAdded);
            } elseif ($debtReduction > 0) {
                $financialNote = sprintf("تم خصم %.2f ج.م من دين العميل", $debtReduction);
            } elseif ($creditAdded > 0) {
                $financialNote = sprintf("تم إضافة %.2f ج.م لرصيد العميل الدائن", $creditAdded);
            }
            
            return [
                'success' => true,
                'message' => $result['message'] ?? 'تمت معالجة التسوية المالية بنجاح',
                'financialNote' => $financialNote,
                'debt_reduction' => $debtReduction,
                'credit_added' => $creditAdded,
                'new_balance' => $result['new_balance'] ?? 0.0
            ];
        }
        
        return $result;
        
    } catch (Throwable $e) {
        error_log(">>> processReturnFinancial (Legacy) ERROR: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ أثناء معالجة التسوية المالية: ' . $e->getMessage(),
            'financialNote' => ''
        ];
    }
}

