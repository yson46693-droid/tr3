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
 * تطبيق خصم مرتب المندوب حسب قواعد المرتجعات
 * 
 * القواعد:
 * 1. إذا كان العميل مدين قبل المرتجع: لا يتم خصم أي مبلغ
 * 2. إذا كان العميل غير مدين: خصم 2% من إجمالي مبلغ المرتجع
 * 3. إذا كان جزء يغطي الدين والجزء الآخر رصيد دائن: خصم 2% من جزء الرصيد الدائن فقط
 * 
 * @param int $returnId معرف المرتجع
 * @param int|null $salesRepId معرف المندوب (null يعني جلب من المرتجع)
 * @param int|null $processedBy معرف المستخدم الذي قام بالمعالجة
 * @return array ['success' => bool, 'message' => string, 'deduction_amount' => float]
 */
function applyReturnSalaryDeduction(int $returnId, ?int $salesRepId = null, ?int $processedBy = null): array
{
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        $return = $db->queryOne(
            "SELECT r.*, c.balance as customer_balance_before_return
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
        
        // الحصول على رصيد العميل قبل معالجة المرتجع
        // نحتاج للحصول على الرصيد من audit_log
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
            // إذا لم نجد في audit_log، نحسب من رصيد العميل الحالي
            // نحتاج لعكس العملية: إذا كان الرصيد الحالي تم تعديله بسبب المرتجع
            $customer = $db->queryOne(
                "SELECT balance FROM customers WHERE id = ?",
                [(int)$return['customer_id']]
            );
            
            if ($customer) {
                $currentBalance = (float)$customer['balance'];
                $returnAmount = (float)$return['refund_amount'];
                // حساب الرصيد السابق: نحتاج لعكس العملية المالية
                // إذا كان المرتجع خصم من الدين، كان الرصيد السابق = الرصيد الحالي + المرتجع
                // إذا كان المرتجع أضاف رصيد دائن، كان الرصيد السابق = الرصيد الحالي - المرتجع (لكن المرتجع يكون سالباً)
                // لكن هذا معقد، لذا سنحاول طريقة أبسط: نفحص من audit log للمعالجة المالية
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
                    // استخدام طريقة تقديرية: نفترض أن الرصيد الحالي = الرصيد السابق + التأثير المالي
                    // لكن هذا غير دقيق، لذا سنستخدم 0 كقيمة افتراضية ونحسب من القواعد
                    $customerBalanceBeforeReturn = 0.0;
                }
            }
        }
        
        $returnAmount = (float)$return['refund_amount'];
        
        // تحديد المبلغ المطلوب خصمه حسب القواعد
        $amountToDeduct = 0.0;
        
        if ($customerBalanceBeforeReturn > 0) {
            // الحالة 1: العميل كان مدين
            // الحالة 3: جزء يغطي الدين والجزء الآخر رصيد دائن
            if ($returnAmount > $customerBalanceBeforeReturn) {
                // الحالة 3: جزء يغطي الدين والجزء الآخر رصيد دائن
                $creditPortion = $returnAmount - $customerBalanceBeforeReturn;
                $amountToDeduct = round($creditPortion * 0.02, 2); // 2% من جزء الرصيد الدائن
            } else {
                // الحالة 1: المرتجع يغطي الدين بالكامل - لا خصم
                $amountToDeduct = 0.0;
            }
        } else {
            // الحالة 2: العميل غير مدين (balance <= 0)
            $amountToDeduct = round($returnAmount * 0.02, 2); // 2% من إجمالي المرتجع
        }
        
        if ($amountToDeduct <= 0) {
            return [
                'success' => true,
                'message' => 'لا يتم خصم أي مبلغ من مرتب المندوب (العميل كان مدين)',
                'deduction_amount' => 0.0
            ];
        }
        
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
        
        // بدء المعاملة
        $conn = $db->getConnection();
        $conn->begin_transaction();
        
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
                $conn->rollback();
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
            
            // تسجيل في سجل التدقيق
            logAudit($processedBy, 'return_salary_deduction', 'returns', $returnId, [
                'salary_id' => $salaryId,
                'sales_rep_id' => $salesRepId,
                'deduction_amount' => $amountToDeduct,
                'old_deductions' => $currentDeductions,
                'new_deductions' => $newDeductions,
                'old_total' => $currentTotal,
                'new_total' => $newTotal
            ], [
                'return_number' => $return['return_number'],
                'month' => $month,
                'year' => $year
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
            
            // تأكيد المعاملة
            $conn->commit();
            
            return [
                'success' => true,
                'message' => sprintf('تم خصم %.2f جنيه من مرتب المندوب بنجاح', $amountToDeduct),
                'deduction_amount' => $amountToDeduct,
                'salary_id' => $salaryId,
                'month' => $month,
                'year' => $year
            ];
            
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Error applying salary deduction: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء تطبيق خصم المرتب: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("Error in applyReturnSalaryDeduction: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

