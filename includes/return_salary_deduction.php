<?php
/**
 * معالج خصومات مرتب المندوب بسبب المرتجعات
 * Return Salary Deduction Handler
 * 
 * هذا الملف مسؤول عن خصم 2% من مرتب المندوب في حالات معينة من المرتجعات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/salary_calculator.php';
require_once __DIR__ . '/return_financial_processor.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit_log.php';

/**
 * تطبيق خصم مرتب المندوب حسب قواعد المرتجعات الجديدة
 * 
 * القواعد الجديدة:
 * 1. إذا كان للعميل رصيد دائن (Credit) أكبر من إجمالي مبلغ المرتجعات:
 *    - لا يتم خصم أي نسبة من تحصيلات المندوب (0%)
 * 
 * 2. إذا كان العميل لديه رصيد مدين (Debit) أقل من إجمالي مبلغ المرتجعات:
 *    - احسب الفرق بين إجمالي مبلغ المرتجعات والرصيد المدين للعميل (x)
 *    - نسبة خصم المندوب = 2% من قيمة (x)
 * 
 * 3. إذا كان رصيد العميل = 0 أو كان لديه رصيد دائن:
 *    - يتم تطبيق خصم 2% من إجمالي مبلغ المرتجعات في العملية بالكامل
 * 
 * @param int $returnId معرف المرتجع
 * @param int|null $salesRepId معرف المندوب (null يعني جلب من المرتجع)
 * @param int|null $processedBy معرف المستخدم الذي قام بالمعالجة
 * @return array ['success' => bool, 'message' => string, 'deduction_amount' => float]
 */
function applyReturnSalaryDeduction(int $returnId, ?int $salesRepId = null, ?int $processedBy = null): array
{
    error_log(">>> applyReturnSalaryDeduction START - Return ID: {$returnId}, Sales Rep ID: " . ($salesRepId ?? 'NULL') . ", Processed By: " . ($processedBy ?? 'N/A'));
    
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        error_log("Fetching return data from database...");
        $return = $db->queryOne(
            "SELECT r.*, c.id as customer_id, c.balance as current_customer_balance
             FROM returns r
             LEFT JOIN customers c ON r.customer_id = c.id
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            error_log("ERROR: Return {$returnId} not found in database");
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        error_log("Return found. Customer ID: " . ($return['customer_id'] ?? 'N/A'));
        
        // الحصول على معرف المندوب
        if ($salesRepId === null) {
            $salesRepId = (int)($return['sales_rep_id'] ?? 0);
            error_log("Sales rep ID from return: {$salesRepId}");
        } else {
            error_log("Sales rep ID provided: {$salesRepId}");
        }
        
        if ($salesRepId <= 0) {
            error_log("No sales rep specified - skipping salary deduction");
            return [
                'success' => true,
                'message' => 'لا يوجد مندوب مرتبط بالمرتجع. لا يتم تطبيق خصم.',
                'deduction_amount' => 0.0
            ];
        }
        
        // الحصول على رصيد العميل قبل معالجة المرتجع من audit_log
        error_log("Fetching customer balance before return from audit logs...");
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
            error_log("Customer balance before return (from audit log): {$customerBalanceBeforeReturn}");
        } else {
            error_log("Audit log not found, trying alternative method...");
            // إذا لم نجد في audit_log، نحاول حساب الرصيد السابق من الرصيد الحالي
            // نستخدم البيانات من return_financial_processor
            $currentBalance = (float)($return['current_customer_balance'] ?? 0);
            $returnAmount = (float)$return['refund_amount'];
            
            // محاولة عكس العملية المالية
            // إذا كان الرصيد الحالي سالب (رصيد دائن)، كان الرصيد السابق = الرصيد الحالي + المرتجع
            // إذا كان الرصيد الحالي موجب (دين)، كان الرصيد السابق = الرصيد الحالي + المرتجع (إذا كان المرتجع خصم من الدين)
            // لكن هذا معقد، لذا سنستخدم طريقة أبسط: نفحص من audit log
            $financialAudit = $db->queryOne(
                "SELECT old_value FROM audit_logs
                 WHERE action = 'process_return_financials'
                 AND entity_type = 'returns'
                 AND entity_id = ?
                 ORDER BY created_at DESC
                 LIMIT 1",
                [$returnId]
            );
            
            if ($financialAudit && !empty($financialAudit['old_value'])) {
                $financialData = json_decode($financialAudit['old_value'], true);
                $customerBalanceBeforeReturn = (float)($financialData['old_balance'] ?? 0);
            } else {
                // إذا لم نجد، نستخدم الرصيد الحالي كتقدير (قد يكون غير دقيق)
                // لكن سنحسب من القواعد بناءً على الرصيد الحالي
                $customerBalanceBeforeReturn = $currentBalance;
            }
        }
        
        $returnAmount = (float)$return['refund_amount'];
        error_log("Return amount: {$returnAmount}");
        error_log("Customer balance before return: {$customerBalanceBeforeReturn}");
        
        // حساب رصيد الدين (Debit) ورصيد الدائن (Credit) قبل المرتجع
        $customerDebitBeforeReturn = $customerBalanceBeforeReturn > 0 ? $customerBalanceBeforeReturn : 0.0;
        $customerCreditBeforeReturn = $customerBalanceBeforeReturn < 0 ? abs($customerBalanceBeforeReturn) : 0.0;
        
        error_log("Customer Debit (before return): {$customerDebitBeforeReturn}");
        error_log("Customer Credit (before return): {$customerCreditBeforeReturn}");
        
        // تطبيق القواعد الجديدة
        error_log("Applying salary deduction rules...");
        $amountToDeduct = 0.0;
        $calculationDetails = [];
        
        // القاعدة 1: إذا كان للعميل رصيد دائن (Credit) أكبر من مبلغ المرتجع
        if ($customerCreditBeforeReturn > 0 && $customerCreditBeforeReturn > $returnAmount) {
            // لا يتم خصم أي نسبة (0%)
            error_log("Rule 1 applied: Credit ({$customerCreditBeforeReturn}) > Return Amount ({$returnAmount})");
            $amountToDeduct = 0.0;
            $calculationDetails = [
                'rule' => 1,
                'customer_credit' => $customerCreditBeforeReturn,
                'return_amount' => $returnAmount,
                'reason' => 'رصيد العميل الدائن أكبر من مبلغ المرتجع'
            ];
        }
        // القاعدة 2: إذا كان للعميل رصيد مدين (Debit) أقل من مبلغ المرتجع
        elseif ($customerDebitBeforeReturn > 0 && $customerDebitBeforeReturn < $returnAmount) {
            // حساب الفرق (x) = مبلغ المرتجع - رصيد المدين
            $difference = $returnAmount - $customerDebitBeforeReturn;
            // خصم = 2% من الفرق
            $amountToDeduct = round($difference * 0.02, 2);
            error_log("Rule 2 applied: Debit ({$customerDebitBeforeReturn}) < Return Amount ({$returnAmount})");
            error_log("  Difference: {$difference}, Deduction (2%): {$amountToDeduct}");
            $calculationDetails = [
                'rule' => 2,
                'customer_debit' => $customerDebitBeforeReturn,
                'return_amount' => $returnAmount,
                'difference' => $difference,
                'deduction_percentage' => 2,
                'reason' => 'رصيد العميل المدين أقل من مبلغ المرتجع'
            ];
        }
        // القاعدة 3: إذا كان رصيد العميل = 0 أو رصيد دائن (ولكن Credit <= returnAmount)
        else {
            // خصم 2% من كامل مبلغ المرتجع
            $amountToDeduct = round($returnAmount * 0.02, 2);
            error_log("Rule 3 applied: Balance = 0 or Credit <= Return Amount");
            error_log("  Deduction (2% of return amount): {$amountToDeduct}");
            $calculationDetails = [
                'rule' => 3,
                'customer_balance' => $customerBalanceBeforeReturn,
                'return_amount' => $returnAmount,
                'deduction_percentage' => 2,
                'reason' => 'رصيد العميل = 0 أو رصيد دائن (Credit <= returnAmount)'
            ];
        }
        
        error_log("Deduction calculation completed. Amount to deduct: {$amountToDeduct}");
        
        if ($amountToDeduct <= 0) {
            $reasonMessage = 'لا يتم خصم أي مبلغ من مرتب المندوب';
            if (!empty($calculationDetails)) {
                if ($calculationDetails['rule'] == 1) {
                    $reasonMessage .= ' (رصيد العميل الدائن أكبر من مبلغ المرتجع)';
                } else {
                    $reasonMessage .= ' (حسب القواعد المحددة)';
                }
            }
            error_log("No deduction needed. Reason: " . ($calculationDetails['reason'] ?? 'N/A'));
            error_log(">>> applyReturnSalaryDeduction SUCCESS (no deduction)");
            return [
                'success' => true,
                'message' => $reasonMessage,
                'deduction_amount' => 0.0,
                'calculation_details' => $calculationDetails
            ];
        }
        
        error_log("Deduction required: {$amountToDeduct}. Processing salary update...");
        
        // الحصول على المستخدم الحالي
        if ($processedBy === null) {
            $currentUser = getCurrentUser();
            $processedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        // الحصول على الشهر والسنة من تاريخ المرتجع
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
        
        // بدء المعاملة (فقط إذا لم تكن هناك transaction نشطة)
        $conn = $db->getConnection();
        $transactionStarted = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $transactionStarted = true;
        }
        
        try {
            // الحصول على سجل الراتب الحالي
            $salary = $db->queryOne(
                "SELECT deductions, total_amount FROM salaries WHERE id = ? FOR UPDATE",
                [$salaryId]
            );
            
            if (!$salary) {
                throw new Exception('سجل الراتب غير موجود');
            }
            
            $currentDeductions = (float)($salary['deductions'] ?? 0);
            $currentTotal = (float)($salary['total_amount'] ?? 0);
            
            // التحقق من عدم تطبيق الخصم مسبقاً (منع الخصم المكرر)
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
            
            // تسجيل في سجل التدقيق مع تفاصيل الحساب
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
            
            // تأكيد المعاملة (فقط إذا بدأناها نحن)
            if ($transactionStarted) {
                error_log("Committing transaction (we started it)...");
                $db->commit();
            } else {
                error_log("Transaction was started by caller, not committing here");
            }
            
            error_log(">>> applyReturnSalaryDeduction SUCCESS");
            error_log("Deduction amount: {$amountToDeduct}");
            error_log("Salary ID: {$salaryId}");
            error_log("Month: {$month}, Year: {$year}");
            
            return [
                'success' => true,
                'message' => sprintf('تم خصم %.2f جنيه من مرتب المندوب بنجاح', $amountToDeduct),
                'deduction_amount' => $amountToDeduct,
                'salary_id' => $salaryId,
                'month' => $month,
                'year' => $year
            ];
            
        } catch (Throwable $e) {
            // Rollback فقط إذا بدأنا transaction
            if ($transactionStarted) {
                error_log("ERROR: Rolling back transaction...");
                $db->rollback();
            }
            error_log(">>> applyReturnSalaryDeduction ERROR");
            error_log("Error message: " . $e->getMessage());
            error_log("Error type: " . get_class($e));
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء تطبيق خصم المرتب: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log(">>> applyReturnSalaryDeduction FATAL ERROR");
        error_log("Error message: " . $e->getMessage());
        error_log("Error type: " . get_class($e));
        error_log("Stack trace: " . $e->getTraceAsString());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

