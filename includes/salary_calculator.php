<?php
/**
 * نظام حساب الرواتب
 * حساب الرواتب بناءً على سعر الساعة وعدد الساعات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';

/**
 * التأكد من وجود أعمدة مكافآت التحصيلات في جدول الرواتب.
 *
 * @return bool
 */
function ensureCollectionsBonusColumn(): bool {
    static $collectionsColumnsEnsured = null;
    
    if ($collectionsColumnsEnsured !== null) {
        return $collectionsColumnsEnsured;
    }
    
    try {
        $db = db();
        
        $bonusColumnExists = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'collections_bonus'");
        if (empty($bonusColumnExists)) {
            $db->execute("
                ALTER TABLE `salaries`
                ADD COLUMN `collections_bonus` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'مكافآت التحصيلات 2%' 
                AFTER `bonus`
            ");
        }
        
        $amountColumnExists = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'collections_amount'");
        if (empty($amountColumnExists)) {
            $db->execute("
                ALTER TABLE `salaries`
                ADD COLUMN `collections_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'إجمالي مبالغ التحصيلات للمندوب'
                AFTER `collections_bonus`
            ");
        }
        
        $collectionsColumnsEnsured = true;
    } catch (Throwable $columnError) {
        error_log('Failed to ensure collections bonus columns: ' . $columnError->getMessage());
        $collectionsColumnsEnsured = false;
    }
    
    return $collectionsColumnsEnsured;
}

/**
 * حساب عدد الساعات الشهرية للمستخدم
 * يستخدم جدول attendance_records الجديد
 */
function calculateMonthlyHours($userId, $month, $year) {
    $db = db();
    $hasCollectionsBonusColumn = ensureCollectionsBonusColumn();
    
    // التحقق من وجود جدول attendance_records
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    
    if (!empty($tableCheck)) {
        // استخدام الجدول الجديد
        $monthKey = sprintf('%04d-%02d', $year, $month);
        
        // 1. حساب الساعات من السجلات المكتملة (التي لديها check_out_time)
        $completedResult = $db->queryOne(
            "SELECT COALESCE(SUM(work_hours), 0) as total_hours 
             FROM attendance_records 
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             AND check_out_time IS NOT NULL
             AND work_hours IS NOT NULL
             AND work_hours > 0",
            [$userId, $monthKey]
        );
        
        $totalHours = round($completedResult['total_hours'] ?? 0, 2);
        
        // 2. حساب الساعات من السجلات غير المكتملة (حضور بدون انصراف)
        $incompleteRecords = $db->query(
            "SELECT id, date, check_in_time 
             FROM attendance_records 
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             AND check_out_time IS NULL
             AND check_in_time IS NOT NULL",
            [$userId, $monthKey]
        );
        
        // الحصول على موعد العمل الرسمي للمستخدم
        // استخدام نفس المنطق الموجود في attendance.php
        $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
        $workTime = null;
        if ($user) {
            $role = $user['role'];
            if ($role === 'accountant') {
                $workTime = ['start' => '10:00:00', 'end' => '19:00:00'];
            } elseif ($role === 'sales') {
                $workTime = ['start' => '10:00:00', 'end' => '19:00:00'];
            } elseif ($role !== 'manager') {
                // عمال الإنتاج
                $workTime = ['start' => '09:00:00', 'end' => '19:00:00'];
            }
        }
        
        foreach ($incompleteRecords as $record) {
            // إذا لم يسجل المستخدم الانصراف، يحتسب النظام 5 ساعات فقط
            $totalHours += 5.0;
        }
        
        $totalHours = round($totalHours, 2);
        
        // تسجيل للتأكد من أن الساعات تُحسب بشكل صحيح
        $completedHours = isset($completedResult['total_hours']) ? $completedResult['total_hours'] : 0;
        $incompleteCount = count($incompleteRecords);
        $incompleteHours = $incompleteCount * 5;
        error_log("calculateMonthlyHours: user_id={$userId}, month={$month}, year={$year}, month_key={$monthKey}, completed_hours={$completedHours}, incomplete_count={$incompleteCount}, incomplete_hours={$incompleteHours}, total_hours={$totalHours}");
        
        return $totalHours;
    } else {
        // Fallback للجدول القديم
        $totalHours = 0;
        
        $attendanceRecords = $db->query(
            "SELECT * FROM attendance 
             WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?
             AND check_in IS NOT NULL AND check_out IS NOT NULL
             ORDER BY date ASC",
            [$userId, $month, $year]
        );
        
        foreach ($attendanceRecords as $record) {
            $checkIn = strtotime($record['date'] . ' ' . $record['check_in']);
            $checkOut = strtotime($record['date'] . ' ' . $record['check_out']);
            
            if ($checkOut > $checkIn) {
                $hours = ($checkOut - $checkIn) / 3600;
                $totalHours += $hours;
            }
        }
        
        return round($totalHours, 2);
    }
}

/**
 * حساب مجموع المبالغ المستحقة للمكافأة 2% للمندوب خلال الشهر
 * الحالات:
 * 1. المبيعات بكامل: 2% على إجمالي الفاتورة
 * 2. التحصيلات الجزئية: 2% على المبلغ المحصل
 * 3. التحصيلات من عملاء المندوب: 2% على المبلغ المحصل
 */
function calculateSalesCollections($userId, $month, $year) {
    $db = db();
    
    $totalCommissionBase = 0;
    
    // الحالة 1: المبيعات بكامل - حساب 2% على إجمالي الفاتورة
    // الفواتير المدفوعة بالكامل (status='paid' و paid_amount = total_amount)
    $invoicesTableCheck = $db->queryOne("SHOW TABLES LIKE 'invoices'");
    if (!empty($invoicesTableCheck)) {
        $fullPaymentSales = $db->queryOne(
            "SELECT COALESCE(SUM(total_amount), 0) as total 
             FROM invoices 
             WHERE sales_rep_id = ? 
             AND MONTH(date) = ? 
             AND YEAR(date) = ?
             AND status = 'paid'
             AND ABS(paid_amount - total_amount) < 0.01",
            [$userId, $month, $year]
        );
        $totalCommissionBase += floatval($fullPaymentSales['total'] ?? 0);
    }
    
    // الحالة 2 و 3: التحصيلات الجزئية والتحصيلات من عملاء المندوب
    // حساب 2% على المبلغ المحصل
    $collectionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
    if (!empty($collectionsTableCheck)) {
        // التحقق من وجود عمود status في collections
        $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
        $hasStatus = !empty($statusColumnCheck);
        
        // التحقق من وجود جدول customers
        $customersTableCheck = $db->queryOne("SHOW TABLES LIKE 'customers'");
        $hasCustomers = !empty($customersTableCheck);
        
        if ($hasCustomers && !empty($invoicesTableCheck)) {
            // الحالة 2: التحصيلات من الفواتير الجزئية
            // التحصيلات التي تمت على فواتير بحالة partial للمندوب
            // نستخدم subquery لتجنب العد المزدوج إذا كان هناك أكثر من فاتورة جزئية للعميل نفسه
            $partialCollections = $db->queryOne(
                "SELECT COALESCE(SUM(c.amount), 0) as total 
                 FROM collections c
                 WHERE c.customer_id IN (
                     SELECT DISTINCT inv.customer_id 
                     FROM invoices inv
                     WHERE inv.sales_rep_id = ?
                     AND inv.status = 'partial'
                 )
                 AND MONTH(c.date) = ?
                 AND YEAR(c.date) = ?" . 
                 ($hasStatus ? " AND c.status IN ('pending','approved')" : ""),
                [$userId, $month, $year]
            );
            $partialAmount = floatval($partialCollections['total'] ?? 0);
            
            // الحالة 3: التحصيلات من عملاء المندوب (الذين أنشأهم المندوب)
            // نحسب جميع التحصيلات من عملاء المندوب
            // إذا كان التحصيل مؤهلاً للحالتين 2 و 3، سيتم احتسابه مرة واحدة فقط (في الحالة 2)
            // لذلك نستثني التحصيلات التي تم احتسابها في الحالة 2 (من عملاء لديهم فواتير جزئية)
            $customerCollections = $db->queryOne(
                "SELECT COALESCE(SUM(c.amount), 0) as total 
                 FROM collections c
                 INNER JOIN customers cust ON c.customer_id = cust.id
                 WHERE cust.created_by = ?
                 AND MONTH(c.date) = ?
                 AND YEAR(c.date) = ?
                 AND c.customer_id NOT IN (
                     SELECT DISTINCT inv.customer_id 
                     FROM invoices inv
                     WHERE inv.sales_rep_id = ?
                     AND inv.status = 'partial'
                 )" . 
                 ($hasStatus ? " AND c.status IN ('pending','approved')" : ""),
                [$userId, $month, $year, $userId]
            );
            $customerAmount = floatval($customerCollections['total'] ?? 0);
            
            // الحالة 4: التحصيلات التي قام بها المندوب مباشرة (collected_by)
            // نستثني التحصيلات التي تم احتسابها في الحالتين 2 و 3 لتجنب العد المزدوج
            $collectedByQuery = "
                SELECT COALESCE(SUM(c.amount), 0) as total 
                FROM collections c
                WHERE c.collected_by = ?
                AND MONTH(c.date) = ?
                AND YEAR(c.date) = ?
                AND c.customer_id NOT IN (
                    SELECT DISTINCT inv.customer_id 
                    FROM invoices inv
                    WHERE inv.sales_rep_id = ?
                    AND inv.status = 'partial'
                )
                AND (c.customer_id NOT IN (
                    SELECT DISTINCT cust.id
                    FROM customers cust
                    WHERE cust.created_by = ?
                ) OR c.customer_id IS NULL)" . 
                ($hasStatus ? " AND c.status IN ('pending','approved')" : "");
            
            $collectedByResult = $db->queryOne($collectedByQuery, [$userId, $month, $year, $userId, $userId]);
            $collectedByAmount = floatval($collectedByResult['total'] ?? 0);
            
            $totalCommissionBase += $partialAmount + $customerAmount + $collectedByAmount;
        } elseif ($hasCustomers) {
            // إذا لم يكن جدول invoices موجوداً، نحسب التحصيلات من عملاء المندوب
            $customerCollections = $db->queryOne(
                "SELECT COALESCE(SUM(c.amount), 0) as total 
                 FROM collections c
                 INNER JOIN customers cust ON c.customer_id = cust.id
                 WHERE cust.created_by = ?
                 AND MONTH(c.date) = ?
                 AND YEAR(c.date) = ?" . 
                 ($hasStatus ? " AND c.status IN ('pending','approved')" : ""),
                [$userId, $month, $year]
            );
            $customerAmount = floatval($customerCollections['total'] ?? 0);
            
            // التحصيلات التي قام بها المندوب مباشرة (collected_by) من عملاء ليسوا من عملاء المندوب
            $collectedByQuery = "
                SELECT COALESCE(SUM(c.amount), 0) as total 
                FROM collections c
                WHERE c.collected_by = ?
                AND MONTH(c.date) = ?
                AND YEAR(c.date) = ?
                AND (c.customer_id NOT IN (
                    SELECT DISTINCT cust.id
                    FROM customers cust
                    WHERE cust.created_by = ?
                ) OR c.customer_id IS NULL)" . 
                ($hasStatus ? " AND c.status IN ('pending','approved')" : "");
            
            $collectedByResult = $db->queryOne($collectedByQuery, [$userId, $month, $year, $userId]);
            $collectedByAmount = floatval($collectedByResult['total'] ?? 0);
            
            $totalCommissionBase += $customerAmount + $collectedByAmount;
        } else {
            // إذا لم يكن جدول customers موجوداً، نستخدم الطريقة القديمة
            if ($hasStatus) {
                $result = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_collections 
                     FROM collections 
                     WHERE collected_by = ? 
                     AND MONTH(date) = ? 
                     AND YEAR(date) = ?
                     AND status IN ('pending','approved')",
                    [$userId, $month, $year]
                );
            } else {
                $result = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_collections 
                     FROM collections 
                     WHERE collected_by = ? 
                     AND MONTH(date) = ? 
                     AND YEAR(date) = ?",
                    [$userId, $month, $year]
                );
            }
            $totalCommissionBase += floatval($result['total_collections'] ?? 0);
        }
    }
    
    return round($totalCommissionBase, 2);
}

/**
 * إضافة (أو خصم) مكافأة فورية بنسبة 2% على تحصيل مندوب المبيعات
 *
 * @param int $salesUserId      معرف المندوب
 * @param float $collectionAmount قيمة التحصيل
 * @param string|null $collectionDate تاريخ التحصيل (يستخدم لتحديد الشهر/السنة)
 * @param int|null $collectionId  معرف عملية التحصيل (لأغراض السجل)
 * @param int|null $triggeredBy   معرف المستخدم الذي نفّذ العملية (للتدقيق)
 * @param bool $reverse           في حالة true يتم خصم المكافأة (مثلاً عند حذف التحصيل)
 * @return bool نجاح أو فشل العملية
 */
function applyCollectionInstantReward($salesUserId, $collectionAmount, $collectionDate = null, $collectionId = null, $triggeredBy = null, $reverse = false) {
    $salesUserId = (int)$salesUserId;
    $collectionAmount = (float)$collectionAmount;
    
    if ($salesUserId <= 0 || $collectionAmount <= 0) {
        return false;
    }
    
    $collectionDate = $collectionDate ?: date('Y-m-d');
    $timestamp = strtotime($collectionDate) ?: time();
    $targetMonth = (int)date('n', $timestamp);
    $targetYear = (int)date('Y', $timestamp);
    
    $rewardAmount = round($collectionAmount * 0.02, 2);
    if ($reverse) {
        $rewardAmount *= -1;
    }
    
    if ($rewardAmount == 0.0) {
        return true;
    }
    
    $db = db();
    
    $summary = getSalarySummary($salesUserId, $targetMonth, $targetYear);
    if (!$summary['exists']) {
        $creation = createOrUpdateSalary($salesUserId, $targetMonth, $targetYear);
        if (!($creation['success'] ?? false)) {
            error_log('Instant reward: failed to ensure salary record for user ' . $salesUserId . ' (collection ' . ($collectionId ?? 'N/A') . ')');
            return false;
        }
        $summary = getSalarySummary($salesUserId, $targetMonth, $targetYear);
        if (!$summary['exists']) {
            return false;
        }
    }
    
    $salary = $summary['salary'];
    $salaryId = (int)($salary['id'] ?? 0);
    if ($salaryId <= 0) {
        return false;
    }
    
    static $salaryRewardColumns = null;
    if ($salaryRewardColumns === null) {
        $salaryRewardColumns = [
            'bonus' => null,
            'collections_bonus' => null,
            'collections_amount' => null,
            'total_amount' => null,
            'accumulated_amount' => null,
            'updated_at' => null,
        ];
        
        try {
            $columns = $db->query("SHOW COLUMNS FROM salaries");
            foreach ($columns as $column) {
                $field = $column['Field'] ?? '';
                if ($field === '') {
                    continue;
                }
                
                if ($salaryRewardColumns['bonus'] === null && in_array($field, ['bonus', 'total_bonus'], true)) {
                    $salaryRewardColumns['bonus'] = $field;
                } elseif ($salaryRewardColumns['collections_bonus'] === null && $field === 'collections_bonus') {
                    $salaryRewardColumns['collections_bonus'] = $field;
                } elseif ($salaryRewardColumns['collections_amount'] === null && $field === 'collections_amount') {
                    $salaryRewardColumns['collections_amount'] = $field;
                } elseif ($salaryRewardColumns['total_amount'] === null && in_array($field, ['total_amount', 'amount', 'net_total'], true)) {
                    $salaryRewardColumns['total_amount'] = $field;
                } elseif ($salaryRewardColumns['accumulated_amount'] === null && $field === 'accumulated_amount') {
                    $salaryRewardColumns['accumulated_amount'] = $field;
                } elseif ($salaryRewardColumns['updated_at'] === null && in_array($field, ['updated_at', 'modified_at', 'last_updated'], true)) {
                    $salaryRewardColumns['updated_at'] = $field;
                }
            }
        } catch (Throwable $columnError) {
            error_log('Instant reward: failed to read salaries columns - ' . $columnError->getMessage());
        }
        
        if ($salaryRewardColumns['collections_bonus'] === null && $hasCollectionsBonusColumn) {
            $salaryRewardColumns['collections_bonus'] = 'collections_bonus';
        }
        if ($salaryRewardColumns['collections_amount'] === null && $hasCollectionsBonusColumn) {
            $salaryRewardColumns['collections_amount'] = 'collections_amount';
        }
        
        if ($salaryRewardColumns['total_amount'] === null) {
            $salaryRewardColumns['total_amount'] = 'total_amount';
        }
    }
    
    $updateParts = [];
    $params = [];
    
    if (!empty($salaryRewardColumns['bonus'])) {
        $updateParts[] = "{$salaryRewardColumns['bonus']} = COALESCE({$salaryRewardColumns['bonus']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['collections_bonus'])) {
        $updateParts[] = "{$salaryRewardColumns['collections_bonus']} = COALESCE({$salaryRewardColumns['collections_bonus']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['collections_amount'])) {
        $updateParts[] = "{$salaryRewardColumns['collections_amount']} = COALESCE({$salaryRewardColumns['collections_amount']}, 0) + ?";
        $params[] = $reverse ? -abs($collectionAmount) : abs($collectionAmount);
    }
    
    if (!empty($salaryRewardColumns['total_amount'])) {
        $updateParts[] = "{$salaryRewardColumns['total_amount']} = COALESCE({$salaryRewardColumns['total_amount']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['accumulated_amount'])) {
        $updateParts[] = "{$salaryRewardColumns['accumulated_amount']} = COALESCE({$salaryRewardColumns['accumulated_amount']}, 0) + ?";
        $params[] = $rewardAmount;
    }
    
    if (!empty($salaryRewardColumns['updated_at'])) {
        $updateParts[] = "{$salaryRewardColumns['updated_at']} = NOW()";
    }
    
    if (empty($updateParts)) {
        return false;
    }
    
    $params[] = $salaryId;
    $db->execute(
        "UPDATE salaries SET " . implode(', ', $updateParts) . " WHERE id = ?",
        $params
    );
    
    if (function_exists('logAudit')) {
        logAudit(
            $triggeredBy ?: $salesUserId,
            $rewardAmount > 0 ? 'collection_reward_add' : 'collection_reward_remove',
            'salary',
            $salaryId,
            null,
            [
                'collection_id' => $collectionId,
                'collection_amount' => $collectionAmount,
                'reward_amount' => $rewardAmount,
                'month' => $targetMonth,
                'year' => $targetYear
            ]
        );
    }
    
    return true;
}

/**
 * حساب الراتب الشهري
 * للمندوبين: يضاف 2% من مجموع التحصيلات المعتمدة
 */
function calculateSalary($userId, $month, $year, $bonus = 0, $deductions = 0) {
    $db = db();
    
    // الحصول على بيانات المستخدم
    $user = $db->queryOne("SELECT hourly_rate, role FROM users WHERE id = ?", [$userId]);
    
    if (!$user) {
        return [
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ];
    }
    
    // تنظيف hourly_rate من 262145 - تنظيف شامل
    $hourlyRateRaw = $user['hourly_rate'] ?? 0;
    $hourlyRateStr = (string)$hourlyRateRaw;
    $hourlyRateStr = str_replace('262145', '', $hourlyRateStr);
    $hourlyRateStr = preg_replace('/262145\s*/', '', $hourlyRateStr);
    $hourlyRateStr = preg_replace('/\s*262145/', '', $hourlyRateStr);
    $hourlyRateStr = preg_replace('/\s+/', '', trim($hourlyRateStr));
    $hourlyRateStr = preg_replace('/[^0-9.]/', '', $hourlyRateStr);
    $hourlyRate = cleanFinancialValue($hourlyRateStr ?: 0);
    
    $role = $user['role'];
    
    if ($hourlyRate <= 0) {
        $errorMessage = ($role === 'sales') 
            ? 'لم يتم تحديد الراتب الشهري للمندوب'
            : 'لم يتم تحديد سعر الساعة للمستخدم';
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }
    
    // حساب عدد الساعات
    $totalHours = calculateMonthlyHours($userId, $month, $year);
    
    // حساب الراتب الأساسي
    // للمندوبين: hourly_rate هو راتب شهري ثابت وليس سعر ساعة
    // للآخرين: الراتب = الساعات × سعر الساعة
    if ($role === 'sales') {
        // للمندوبين: الراتب الأساسي هو hourly_rate مباشرة (راتب شهري ثابت)
        $baseAmount = $hourlyRate;
    } else {
        // للآخرين: الراتب = الساعات × سعر الساعة
        $baseAmount = $totalHours * $hourlyRate;
    }
    
    // حساب نسبة التحصيلات للمندوبين (2%)
    $collectionsBonus = 0;
    $collectionsAmount = 0;
    
    if ($role === 'sales') {
        $collectionsAmount = calculateSalesCollections($userId, $month, $year);
        $collectionsBonus = $collectionsAmount * 0.02; // 2%
    }
    
    // إضافة نسبة التحصيلات إلى المكافأة
    $totalBonus = $bonus + $collectionsBonus;
    
    // حساب السلف المعتمدة التي لم يتم خصمها بعد
    $advancesDeduction = 0;
    $advancesTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
    if (!empty($advancesTableCheck)) {
        $approvedAdvances = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM salary_advances 
             WHERE user_id = ? 
             AND status = 'manager_approved' 
             AND deducted_from_salary_id IS NULL",
            [$userId]
        );
        $advancesDeduction = floatval($approvedAdvances['total'] ?? 0);
    }
    
    // حساب الراتب الإجمالي (مع خصم السلف)
    $totalAmount = $baseAmount + $totalBonus - $deductions - $advancesDeduction;
    
    return [
        'success' => true,
        'hourly_rate' => $hourlyRate,
        'total_hours' => $totalHours,
        'base_amount' => round($baseAmount, 2),
        'collections_amount' => $collectionsAmount,
        'collections_bonus' => round($collectionsBonus, 2),
        'bonus' => $bonus,
        'total_bonus' => round($totalBonus, 2),
        'deductions' => $deductions,
        'advances_deduction' => round($advancesDeduction, 2),
        'total_amount' => round($totalAmount, 2)
    ];
}

/**
 * إنشاء أو تحديث راتب للمستخدم
 */
function createOrUpdateSalary($userId, $month, $year, $bonus = 0, $deductions = 0, $notes = null) {
    $db = db();
    $hasCollectionsBonusColumn = ensureCollectionsBonusColumn();
    
    // الحصول على المستخدم الحالي لاستخدامه في created_by
    $currentUser = getCurrentUser();
    $createdBy = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
    
    // التحقق من وجود عمود created_by
    $createdByColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'created_by'");
    $hasCreatedByColumn = !empty($createdByColumnCheck);
    
    // إذا كان created_by موجوداً ولكن القيمة null، استخدم user_id كبديل
    if ($hasCreatedByColumn && $createdBy === null) {
        $createdBy = $userId;
    }
    
    // حساب الراتب
    $calculation = calculateSalary($userId, $month, $year, $bonus, $deductions);
    $collectionsBonusCalc = cleanFinancialValue($calculation['collections_bonus'] ?? 0);
    $collectionsAmountCalc = cleanFinancialValue($calculation['collections_amount'] ?? ($collectionsBonusCalc > 0 ? $collectionsBonusCalc / 0.02 : 0));
    
    if (!$calculation['success']) {
        return $calculation;
    }
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    // التحقق من وجود عمود bonus - بشكل آمن
    // بشكل افتراضي، افترض أن العمود غير موجود لتجنب الأخطاء
    $hasBonusColumn = false;
    try {
        // محاولة التحقق من وجود العمود باستخدام INFORMATION_SCHEMA
        $columnExists = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'salaries' 
             AND COLUMN_NAME = 'bonus'"
        );
        // تأكد من أن القيمة أكبر من 0 وصحيحة
        if (!empty($columnExists) && isset($columnExists['cnt'])) {
            $hasBonusColumn = (int)$columnExists['cnt'] > 0;
        }
    } catch (Exception $e) {
        // إذا فشل، جرب طريقة بديلة
        try {
            $bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'bonus'");
            if (!empty($bonusColumnCheck) && isset($bonusColumnCheck['Field']) && $bonusColumnCheck['Field'] === 'bonus') {
                $hasBonusColumn = true;
            }
        } catch (Exception $e2) {
            // في حالة الخطأ، ابق القيمة false
            $hasBonusColumn = false;
        }
    }
    
    // تأكد نهائي - إذا لم نكن متأكدين، افترض false
    if (!$hasBonusColumn) {
        $hasBonusColumn = false;
    }

    // التحقق من وجود عمود notes
    $hasNotesColumn = false;
    try {
        $columnExists = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'salaries' 
             AND COLUMN_NAME = 'notes'"
        );
        if (!empty($columnExists) && isset($columnExists['cnt'])) {
            $hasNotesColumn = (int)$columnExists['cnt'] > 0;
        }
    } catch (Exception $e) {
        try {
            $notesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'notes'");
            if (!empty($notesColumnCheck) && isset($notesColumnCheck['Field']) && $notesColumnCheck['Field'] === 'notes') {
                $hasNotesColumn = true;
            }
        } catch (Exception $e2) {
            $hasNotesColumn = false;
        }
    }
    if (!$hasNotesColumn) {
        $hasNotesColumn = false;
    }
    
    // التحقق من نوع month إذا لم يكن year موجوداً
    $monthType = '';
    if (!$hasYearColumn) {
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'month'");
        $monthType = $monthColumnCheck['Type'] ?? '';
    }
    
    // التحقق من وجود راتب موجود
    if ($hasYearColumn) {
        $existingSalary = $db->queryOne(
            "SELECT id FROM salaries WHERE user_id = ? AND month = ? AND year = ?",
            [$userId, $month, $year]
        );
    } else {
        // إذا لم يكن year موجوداً، تحقق من نوع month
        if (stripos($monthType, 'date') !== false) {
            // إذا كان month من نوع DATE
            $targetDate = sprintf('%04d-%02d-01', $year, $month);
            $existingSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND DATE_FORMAT(month, '%Y-%m') = ?",
                [$userId, sprintf('%04d-%02d', $year, $month)]
            );
        } else {
            // إذا كان month من نوع INT فقط
            $existingSalary = $db->queryOne(
                "SELECT id FROM salaries WHERE user_id = ? AND month = ?",
                [$userId, $month]
            );
        }
    }
    
    // التحقق من وجود عمود accumulated_amount
    $accumulatedColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'accumulated_amount'");
    $hasAccumulatedColumn = !empty($accumulatedColumnCheck);
    
    if ($existingSalary) {
        // تحديث الراتب الموجود
        // الحصول على المبلغ التراكمي الحالي والسلفات المخصومة
        $currentAccumulated = 0.00;
        $hasDeductedAdvances = false;
        $currentTotalAmount = 0.00;
        
        if ($hasAccumulatedColumn) {
            $currentSalary = $db->queryOne("SELECT accumulated_amount, total_amount, advances_deduction FROM salaries WHERE id = ?", [$existingSalary['id']]);
            $currentAccumulated = floatval($currentSalary['accumulated_amount'] ?? 0);
            $currentTotalAmount = floatval($currentSalary['total_amount'] ?? 0);
            $currentAdvancesDeduction = floatval($currentSalary['advances_deduction'] ?? 0);
            
            // التحقق من وجود سلفات مخصومة بالفعل
            if ($currentAdvancesDeduction > 0) {
                $hasDeductedAdvances = true;
            } else {
                // التحقق من وجود سلفات مخصومة في جدول salary_advances
                $deductedAdvancesCheck = $db->queryOne(
                    "SELECT COUNT(*) as count FROM salary_advances 
                     WHERE deducted_from_salary_id = ? AND status = 'manager_approved'",
                    [$existingSalary['id']]
                );
                if (!empty($deductedAdvancesCheck) && intval($deductedAdvancesCheck['count'] ?? 0) > 0) {
                    $hasDeductedAdvances = true;
                }
            }
            
            // إضافة المبلغ الجديد للتراكمي (إذا تغير total_amount ولم تكن هناك سلفات مخصومة)
            if (!$hasDeductedAdvances) {
                $oldTotalAmount = $currentTotalAmount;
                $newTotalAmount = $calculation['total_amount'];
                if (abs($newTotalAmount - $oldTotalAmount) > 0.01) {
                    // إضافة الفرق للتراكمي
                    $currentAccumulated += ($newTotalAmount - $oldTotalAmount);
                }
            }
        } else {
            // إذا لم يكن هناك عمود accumulated_amount، احصل على total_amount الحالي
            $currentSalary = $db->queryOne("SELECT total_amount, advances_deduction FROM salaries WHERE id = ?", [$existingSalary['id']]);
            $currentTotalAmount = floatval($currentSalary['total_amount'] ?? 0);
            $currentAdvancesDeduction = floatval($currentSalary['advances_deduction'] ?? 0);
            
            // التحقق من وجود سلفات مخصومة بالفعل
            if ($currentAdvancesDeduction > 0) {
                $hasDeductedAdvances = true;
            } else {
                // التحقق من وجود سلفات مخصومة في جدول salary_advances
                $deductedAdvancesCheck = $db->queryOne(
                    "SELECT COUNT(*) as count FROM salary_advances 
                     WHERE deducted_from_salary_id = ? AND status = 'manager_approved'",
                    [$existingSalary['id']]
                );
                if (!empty($deductedAdvancesCheck) && intval($deductedAdvancesCheck['count'] ?? 0) > 0) {
                    $hasDeductedAdvances = true;
                }
            }
        }
        
        // إذا كانت هناك سلفات مخصومة، احسب total_amount بشكل صحيح
        if ($hasDeductedAdvances) {
            // الحصول على إجمالي السلفات المخصومة من هذا الراتب
            $deductedAdvancesTotal = 0;
            if ($currentAdvancesDeduction > 0) {
                $deductedAdvancesTotal = $currentAdvancesDeduction;
            } else {
                $deductedAdvancesQuery = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total FROM salary_advances 
                     WHERE deducted_from_salary_id = ? AND status = 'manager_approved'",
                    [$existingSalary['id']]
                );
                $deductedAdvancesTotal = floatval($deductedAdvancesQuery['total'] ?? 0);
            }
            
            // حساب الراتب الإجمالي قبل خصم السلفات
            // يجب طرح السلفة من deductions لأنها قد تكون مضمنة فيها
            $baseAmount = $calculation['base_amount'];
            $bonus = $calculation['total_bonus'];
            $otherDeductions = max(0, $calculation['deductions'] - $deductedAdvancesTotal);
            
            // حساب total_amount = الراتب قبل الخصم - السلفات المخصومة
            $totalBeforeAdvances = $baseAmount + $bonus - $otherDeductions;
            $calculation['total_amount'] = max(0, $totalBeforeAdvances - $deductedAdvancesTotal);
            
            // تحديث deductions لاستبعاد السلفة (إذا كانت مضمنة)
            if ($calculation['deductions'] >= $deductedAdvancesTotal) {
                $calculation['deductions'] = $otherDeductions;
            }
        }
        
        if ($hasBonusColumn) {
            if ($hasNotesColumn) {
                if ($hasAccumulatedColumn) {
                    $db->execute(
                        "UPDATE salaries SET 
                            hourly_rate = ?, 
                            total_hours = ?, 
                            base_amount = ?, 
                            bonus = ?, 
                            deductions = ?, 
                            total_amount = ?,
                            accumulated_amount = ?,
                            notes = ?,
                            updated_at = NOW()
                         WHERE id = ?",
                        [
                            $calculation['hourly_rate'],
                            $calculation['total_hours'],
                            $calculation['base_amount'],
                            $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                            $calculation['deductions'],
                            $calculation['total_amount'],
                            $currentAccumulated,
                            $notes,
                            $existingSalary['id']
                        ]
                    );
                } else {
                    $db->execute(
                        "UPDATE salaries SET 
                            hourly_rate = ?, 
                            total_hours = ?, 
                            base_amount = ?, 
                            bonus = ?, 
                            deductions = ?, 
                            total_amount = ?,
                            notes = ?,
                            updated_at = NOW()
                         WHERE id = ?",
                        [
                            $calculation['hourly_rate'],
                            $calculation['total_hours'],
                            $calculation['base_amount'],
                            $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                            $calculation['deductions'],
                            $calculation['total_amount'],
                            $notes,
                            $existingSalary['id']
                        ]
                    );
                }
            } else {
                $db->execute(
                    "UPDATE salaries SET 
                        hourly_rate = ?, 
                        total_hours = ?, 
                        base_amount = ?, 
                        bonus = ?, 
                        deductions = ?, 
                        total_amount = ?,
                        updated_at = NOW()
                     WHERE id = ?",
                    [
                        $calculation['hourly_rate'],
                        $calculation['total_hours'],
                        $calculation['base_amount'],
                        $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                        $calculation['deductions'],
                        $calculation['total_amount'],
                        $existingSalary['id']
                    ]
                );
            }
        } else {
            if ($hasNotesColumn) {
                $db->execute(
                    "UPDATE salaries SET 
                        hourly_rate = ?, 
                        total_hours = ?, 
                        base_amount = ?, 
                        deductions = ?, 
                        total_amount = ?,
                        notes = ?,
                        updated_at = NOW()
                     WHERE id = ?",
                    [
                        $calculation['hourly_rate'],
                        $calculation['total_hours'],
                        $calculation['base_amount'],
                        $calculation['deductions'],
                        $calculation['total_amount'],
                        $notes,
                        $existingSalary['id']
                    ]
                );
            } else {
                $db->execute(
                    "UPDATE salaries SET 
                        hourly_rate = ?, 
                        total_hours = ?, 
                        base_amount = ?, 
                        deductions = ?, 
                        total_amount = ?,
                        updated_at = NOW()
                     WHERE id = ?",
                    [
                        $calculation['hourly_rate'],
                        $calculation['total_hours'],
                        $calculation['base_amount'],
                        $calculation['deductions'],
                        $calculation['total_amount'],
                        $existingSalary['id']
                    ]
                );
            }
        }
        
        if ($hasCollectionsBonusColumn) {
            try {
                $db->execute(
                    "UPDATE salaries SET collections_bonus = ?, collections_amount = ? WHERE id = ?",
                    [round($collectionsBonusCalc, 2), round($collectionsAmountCalc, 2), $existingSalary['id']]
                );
            } catch (Throwable $collectionsBonusError) {
                error_log('Failed to update collections bonus columns (existing salary): ' . $collectionsBonusError->getMessage());
            }
        }
        
        return [
            'success' => true,
            'message' => 'تم تحديث الراتب بنجاح',
            'salary_id' => $existingSalary['id'],
            'calculation' => $calculation
        ];
    } else {
        // إنشاء راتب جديد
        // عند إنشاء راتب جديد، نضيف total_amount للتراكمي
        $newAccumulatedAmount = $calculation['total_amount'];
        if ($hasYearColumn) {
            // إذا كان عمود year موجوداً
            if ($hasBonusColumn) {
                if ($hasNotesColumn) {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes,
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes
                            ]
                        );
                    }
                } else {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'],
                                $calculation['deductions'],
                                $calculation['total_amount']
                            ]
                        );
                    }
                }
            } else {
                if ($hasNotesColumn) {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes,
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes
                            ]
                        );
                    }
                } else {
                    if ($hasCreatedByColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, created_by, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $createdBy
                            ]
                        );
                    } else {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, year, hourly_rate, total_hours, base_amount, deductions, total_amount, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $year,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['deductions'],
                                $calculation['total_amount']
                            ]
                        );
                    }
                }
            }
        } else {
            // إذا لم يكن year موجوداً
            if (stripos($monthType, 'date') !== false) {
                // إذا كان month من نوع DATE
                $targetDate = sprintf('%04d-%02d-01', $year, $month);
                if ($hasBonusColumn) {
                    if ($hasNotesColumn) {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes,
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes
                                ]
                            );
                        }
                    } else {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount']
                                ]
                            );
                        }
                    }
                } else {
                    if ($hasNotesColumn) {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes,
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes
                                ]
                            );
                        }
                    } else {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $targetDate,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount']
                                ]
                            );
                        }
                    }
                }
            } else {
                // إذا كان month من نوع INT فقط
                if ($hasBonusColumn) {
                    if ($hasNotesColumn) {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes,
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes
                                ]
                            );
                        }
                    } else {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['total_bonus'],
                                    $calculation['deductions'],
                                    $calculation['total_amount']
                                ]
                            );
                        }
                    }
                } else {
                    if ($hasNotesColumn) {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes,
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, notes, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $notes
                                ]
                            );
                        }
                    } else {
                        if ($hasCreatedByColumn) {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, created_by, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount'],
                                    $createdBy
                                ]
                            );
                        } else {
                            $result = $db->execute(
                                "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, deductions, total_amount, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
                                [
                                    $userId,
                                    $month,
                                    $calculation['hourly_rate'],
                                    $calculation['total_hours'],
                                    $calculation['base_amount'],
                                    $calculation['deductions'],
                                    $calculation['total_amount']
                                ]
                            );
                        }
                    }
                }
            }
        }
        
        $salaryId = $result['insert_id'] ?? null;
        
        // تحديث accumulated_amount بعد إنشاء الراتب
        if ($hasAccumulatedColumn && $salaryId) {
            // الحصول على المبلغ التراكمي الحالي للموظف من جميع الرواتب السابقة
            $previousAccumulated = $db->queryOne(
                "SELECT COALESCE(SUM(accumulated_amount), 0) as total 
                 FROM salaries 
                 WHERE user_id = ? AND id != ?",
                [$userId, $salaryId]
            );
            $previousAccumulated = floatval($previousAccumulated['total'] ?? 0);
            
            // إضافة المبلغ الجديد للتراكمي
            $newAccumulated = $previousAccumulated + $calculation['total_amount'];
            
            $db->execute(
                "UPDATE salaries SET accumulated_amount = ? WHERE id = ?",
                [$newAccumulated, $salaryId]
            );
        }
        
        if ($hasCollectionsBonusColumn && $salaryId) {
            try {
                $db->execute(
                    "UPDATE salaries SET collections_bonus = ?, collections_amount = ? WHERE id = ?",
                    [round($collectionsBonusCalc, 2), round($collectionsAmountCalc, 2), $salaryId]
                );
            } catch (Throwable $collectionsBonusError) {
                error_log('Failed to update collections bonus columns (new salary): ' . $collectionsBonusError->getMessage());
            }
        }
        
        return [
            'success' => true,
            'message' => 'تم إنشاء الراتب بنجاح',
            'salary_id' => $salaryId,
            'calculation' => $calculation
        ];
    }
}

/**
 * حساب رواتب جميع المستخدمين للشهر
 * يستبعد المديرين (role = 'manager')
 */
function calculateAllSalaries($month, $year) {
    $db = db();
    
    // استبعاد المديرين - ليس لديهم رواتب
    $users = $db->query(
        "SELECT id FROM users 
         WHERE status = 'active' 
         AND role != 'manager' 
         AND hourly_rate > 0"
    );
    
    $results = [];
    
    foreach ($users as $user) {
        $result = createOrUpdateSalary($user['id'], $month, $year);
        $results[] = [
            'user_id' => $user['id'],
            'result' => $result
        ];
    }
    
    return $results;
}

/**
 * الحصول على معرف المندوب المستحق للعمولة من عميل معين
 * يبحث عن المندوب من خلال:
 * 1. created_by في جدول customers (المندوب الذي أنشأ العميل)
 * 2. sales_rep_id في جدول invoices (المندوب المرتبط بفواتير العميل)
 *
 * @param int $customerId معرف العميل
 * @return int|null معرف المندوب أو null إذا لم يتم العثور عليه
 */
function getSalesRepForCustomer($customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        return null;
    }
    
    try {
        $db = db();
        
        // أولاً: البحث عن المندوب من خلال created_by في جدول customers
        $customer = $db->queryOne("SELECT created_by FROM customers WHERE id = ?", [$customerId]);
        if ($customer && !empty($customer['created_by'])) {
            $salesRepId = intval($customer['created_by']);
            // التحقق من أن المستخدم مندوب نشط
            $salesRep = $db->queryOne(
                "SELECT id FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                [$salesRepId]
            );
            if ($salesRep) {
                return $salesRepId;
            }
        }
        
        // ثانياً: البحث عن المندوب من خلال sales_rep_id في جدول invoices
        $invoicesTableCheck = $db->queryOne("SHOW TABLES LIKE 'invoices'");
        if (!empty($invoicesTableCheck)) {
            $invoice = $db->queryOne(
                "SELECT sales_rep_id FROM invoices 
                 WHERE customer_id = ? AND sales_rep_id IS NOT NULL 
                 ORDER BY date DESC LIMIT 1",
                [$customerId]
            );
            if ($invoice && !empty($invoice['sales_rep_id'])) {
                $salesRepId = intval($invoice['sales_rep_id']);
                // التحقق من أن المستخدم مندوب نشط
                $salesRep = $db->queryOne(
                    "SELECT id FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                    [$salesRepId]
                );
                if ($salesRep) {
                    return $salesRepId;
                }
            }
        }
        
        return null;
    } catch (Throwable $e) {
        error_log('Error getting sales rep for customer ' . $customerId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * إعادة احتساب راتب المندوب مباشرةً بعد حدوث عملية تؤثر على نسبة التحصيل.
 *
 * @param int $userId
 * @param string|null $referenceDate تاريخ العملية (يتم استخدام تاريخ اليوم إذا تُرك فارغاً)
 * @param string|null $reason ملاحظة يتم تمريرها لدالة الحساب (اختياري)
 * @return bool true عند نجاح إعادة الحساب أو false عند الفشل/تجاهل المستخدم
 */
function refreshSalesCommissionForUser($userId, $referenceDate = null, $reason = null) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }
    
    try {
        $db = db();
        $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
    } catch (Throwable $e) {
        error_log('Failed to read user role while refreshing salary: ' . $e->getMessage());
        return false;
    }
    
    if (!$user || strtolower((string)($user['role'] ?? '')) !== 'sales') {
        return false;
    }
    
    $timestamp = $referenceDate ? strtotime($referenceDate) : time();
    if ($timestamp === false) {
        $timestamp = time();
    }
    
    $month = (int)date('n', $timestamp);
    $year = (int)date('Y', $timestamp);
    
    $note = $reason ?: 'تحديث تلقائي بعد عملية تحصيل';
    
    try {
        $result = createOrUpdateSalary($userId, $month, $year, 0, 0, $note);
        if (!($result['success'] ?? false)) {
            error_log('Failed to refresh salary after collection for user ' . $userId . ': ' . ($result['message'] ?? 'unknown error'));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Exception while refreshing salary after collection for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * توليد تقرير رواتب شهري شامل
 */
function generateMonthlySalaryReport($month, $year) {
    $db = db();
    
    // استبعاد المديرين فقط - عرض جميع الأدوار الأخرى (production, accountant, sales)
    // حتى لو لم يكن لديهم hourly_rate (سيظهر لهم 0)
    $users = $db->query(
        "SELECT u.id, u.username, u.full_name, u.role, COALESCE(u.hourly_rate, 0) as hourly_rate
         FROM users u
         WHERE u.status = 'active' 
         AND u.role != 'manager'
         AND u.role IN ('production', 'accountant', 'sales')
         ORDER BY 
            CASE u.role 
                WHEN 'production' THEN 1
                WHEN 'accountant' THEN 2
                WHEN 'sales' THEN 3
                ELSE 4
            END,
            u.full_name ASC"
    );
    
    $report = [
        'month' => $month,
        'year' => $year,
        'total_users' => 0,
        'total_hours' => 0,
        'total_amount' => 0,
        'total_delay_minutes' => 0,
        'average_delay_minutes' => 0,
        'salaries' => []
    ];
    
    foreach ($users as $user) {
        // حساب أو الحصول على الراتب
        $delaySummary = [
            'total_minutes' => 0.0,
            'average_minutes' => 0.0,
            'delay_days' => 0,
            'attendance_days' => 0,
        ];

        if (function_exists('calculateMonthlyDelaySummary')) {
            $delaySummary = calculateMonthlyDelaySummary($user['id'], $month, $year);
        }
        
        // عرض جميع المستخدمين النشطين من الأدوار المطلوبة (production, accountant, sales)
        // حتى لو لم يكن لديهم حضور أو راتب مسجل في الشهر
        
        $salaryData = getSalarySummary($user['id'], $month, $year);
        
        if ($salaryData['exists']) {
            $salary = $salaryData['salary'];
            
            // حساب نسبة التحصيلات إذا كان مندوب
            $collectionsAmount = 0;
            $collectionsBonus = 0;
            if ($user['role'] === 'sales') {
                $collectionsAmount = calculateSalesCollections($user['id'], $month, $year);
                $collectionsBonus = $collectionsAmount * 0.02;
            }
            
            $report['salaries'][] = [
                'user_id' => $user['id'],
                'user_name' => $user['full_name'] ?? $user['username'],
                'role' => $user['role'],
                'hourly_rate' => $salary['hourly_rate'],
                'total_hours' => $salary['total_hours'],
                'base_amount' => $salary['base_amount'],
                'collections_amount' => $collectionsAmount,
                'collections_bonus' => round($collectionsBonus, 2),
                'bonus' => $salary['bonus'] ?? 0,
                'deductions' => $salary['deductions'] ?? 0,
                'total_amount' => $salary['total_amount'],
                'status' => $salary['status'] ?? 'pending',
                'total_delay_minutes' => $delaySummary['total_minutes'],
                'average_delay_minutes' => $delaySummary['average_minutes'],
                'delay_days' => $delaySummary['delay_days'],
                'attendance_days' => $delaySummary['attendance_days'],
            ];
            
            $report['total_hours'] += $salary['total_hours'];
            $report['total_amount'] += $salary['total_amount'];
            $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        } else if (isset($salaryData['calculation']) && $salaryData['calculation']['success']) {
            // حساب الراتب إذا لم يكن موجوداً
            $calc = $salaryData['calculation'];
            $report['salaries'][] = [
                'user_id' => $user['id'],
                'user_name' => $user['full_name'] ?? $user['username'],
                'role' => $user['role'],
                'hourly_rate' => $calc['hourly_rate'],
                'total_hours' => $calc['total_hours'],
                'base_amount' => $calc['base_amount'],
                'collections_amount' => $calc['collections_amount'] ?? 0,
                'collections_bonus' => $calc['collections_bonus'] ?? 0,
                'bonus' => $calc['bonus'],
                'deductions' => $calc['deductions'],
                'total_amount' => $calc['total_amount'],
                'status' => 'not_calculated',
                'total_delay_minutes' => $delaySummary['total_minutes'],
                'average_delay_minutes' => $delaySummary['average_minutes'],
                'delay_days' => $delaySummary['delay_days'],
                'attendance_days' => $delaySummary['attendance_days'],
            ];
            
            $report['total_hours'] += $calc['total_hours'];
            $report['total_amount'] += $calc['total_amount'];
            $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        } else {
            // حتى لو لم يكن لديهم راتب محسوب، نضيفهم للتقرير مع بيانات الحضور
            $monthHours = calculateMonthlyHours($user['id'], $month, $year);
            $hourlyRate = (float)($user['hourly_rate'] ?? 0);
            
            // حساب نسبة التحصيلات إذا كان مندوب
            $collectionsAmount = 0;
            $collectionsBonus = 0;
            if ($user['role'] === 'sales') {
                $collectionsAmount = calculateSalesCollections($user['id'], $month, $year);
                $collectionsBonus = $collectionsAmount * 0.02;
            }
            
            $baseAmount = round($monthHours * $hourlyRate, 2);
            $totalAmount = round($baseAmount + $collectionsBonus, 2);
            
            $report['salaries'][] = [
                'user_id' => $user['id'],
                'user_name' => $user['full_name'] ?? $user['username'],
                'role' => $user['role'],
                'hourly_rate' => $hourlyRate,
                'total_hours' => $monthHours,
                'base_amount' => $baseAmount,
                'collections_amount' => $collectionsAmount,
                'collections_bonus' => round($collectionsBonus, 2),
                'bonus' => 0,
                'deductions' => 0,
                'total_amount' => $totalAmount,
                'status' => 'not_calculated',
                'total_delay_minutes' => $delaySummary['total_minutes'],
                'average_delay_minutes' => $delaySummary['average_minutes'],
                'delay_days' => $delaySummary['delay_days'],
                'attendance_days' => $delaySummary['attendance_days'],
            ];
            
            $report['total_hours'] += $monthHours;
            $report['total_amount'] += $totalAmount;
            $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        }
    }
    
    $report['total_users'] = count($report['salaries']);
    $report['average_delay_minutes'] = $report['total_users'] > 0
        ? round($report['total_delay_minutes'] / $report['total_users'], 2)
        : 0;
    
    return $report;
}

/**
 * الحصول على ملخص الراتب
 */
function getSalarySummary($userId, $month, $year) {
    $db = db();
    
    // التحقق من وجود جدول salaries
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salaries'");
    if (empty($tableCheck)) {
        return [
            'exists' => false,
            'calculation' => calculateSalary($userId, $month, $year)
        ];
    }
    
    // التحقق من وجود عمود year
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    // بناء الاستعلام بناءً على وجود عمود year
    if ($hasYearColumn) {
        $salary = $db->queryOne(
            "SELECT s.*, u.full_name, u.username, u.hourly_rate as current_hourly_rate
             FROM salaries s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.user_id = ? AND s.month = ? AND s.year = ?",
            [$userId, $month, $year]
        );
    } else {
        // إذا لم يكن year موجوداً، استخدم month فقط أو DATE_FORMAT
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
        $monthType = $monthColumnCheck['Type'] ?? '';
        
        if (stripos($monthType, 'date') !== false) {
            // إذا كان month من نوع DATE
            $salary = $db->queryOne(
                "SELECT s.*, u.full_name, u.username, u.hourly_rate as current_hourly_rate
                 FROM salaries s
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.user_id = ? AND DATE_FORMAT(s.month, '%Y-%m') = ?",
                [$userId, sprintf('%04d-%02d', $year, $month)]
            );
        } else {
            // إذا كان month من نوع INT فقط
            $salary = $db->queryOne(
                "SELECT s.*, u.full_name, u.username, u.hourly_rate as current_hourly_rate
                 FROM salaries s
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.user_id = ? AND s.month = ?",
                [$userId, $month]
            );
        }
    }
    
    if (!$salary) {
        // حساب الراتب إذا لم يكن موجوداً
        $calculation = calculateSalary($userId, $month, $year);
        return [
            'exists' => false,
            'calculation' => $calculation
        ];
    }
    
    // تنظيف جميع القيم المالية من 262145
    if (isset($salary['hourly_rate'])) {
        $salary['hourly_rate'] = cleanFinancialValue($salary['hourly_rate']);
    }
    if (isset($salary['base_amount'])) {
        $salary['base_amount'] = cleanFinancialValue($salary['base_amount']);
    }
    if (isset($salary['total_amount'])) {
        $salary['total_amount'] = cleanFinancialValue($salary['total_amount']);
    }
    if (isset($salary['bonus'])) {
        $salary['bonus'] = cleanFinancialValue($salary['bonus']);
    }
    if (isset($salary['deductions'])) {
        $salary['deductions'] = cleanFinancialValue($salary['deductions']);
    }
    if (isset($salary['current_hourly_rate'])) {
        $salary['current_hourly_rate'] = cleanFinancialValue($salary['current_hourly_rate']);
    }
    if (isset($salary['collections_bonus'])) {
        $salary['collections_bonus'] = cleanFinancialValue($salary['collections_bonus']);
    }
    if (isset($salary['collections_amount'])) {
        $salary['collections_amount'] = cleanFinancialValue($salary['collections_amount']);
    }
    
    return [
        'exists' => true,
        'salary' => $salary
    ];
}

/**
 * الحصول على خريطة أعمدة جدول الرواتب المستخدمة في خصم السلف.
 *
 * @return array{
 *     deductions: string|null,
 *     advances_deduction: string|null,
 *     total_amount: string|null,
 *     updated_at: string|null
 * }
 */
function salaryAdvanceGetSalaryColumns(?Database $dbInstance = null): array
{
    static $columnMap = null;

    if ($columnMap !== null) {
        return $columnMap;
    }

    $db = $dbInstance ?: db();

    try {
        $columns = $db->query("SHOW COLUMNS FROM salaries");
    } catch (Throwable $e) {
        error_log('Unable to read salaries columns for advances deduction: ' . $e->getMessage());
        $columnMap = [
            'deductions' => null,
            'advances_deduction' => null,
            'total_amount' => null,
            'updated_at' => null,
        ];
        return $columnMap;
    }

    $columnMap = [
        'deductions' => null,
        'advances_deduction' => null,
        'total_amount' => null,
        'updated_at' => null,
    ];

    foreach ($columns as $column) {
        $field = $column['Field'] ?? '';
        if ($field === '') {
            continue;
        }

        if ($columnMap['deductions'] === null && in_array($field, ['deductions', 'total_deductions'], true)) {
            $columnMap['deductions'] = $field;
        } elseif ($columnMap['advances_deduction'] === null && $field === 'advances_deduction') {
            $columnMap['advances_deduction'] = $field;
        } elseif ($columnMap['total_amount'] === null && in_array($field, ['total_amount', 'net_total', 'amount'], true)) {
            $columnMap['total_amount'] = $field;
        } elseif ($columnMap['updated_at'] === null && in_array($field, ['updated_at', 'modified_at', 'last_updated'], true)) {
            $columnMap['updated_at'] = $field;
        }
    }

    return $columnMap;
}

/**
 * تجهيز الراتب الذي سيتم خصم السلفة منه، وإنشاء الراتب إن لم يكن موجوداً.
 *
 * @return array{
 *     success: bool,
 *     salary?: array,
 *     salary_id?: int,
 *     month?: int,
 *     year?: int,
 *     message?: string
 * }
 */
function salaryAdvanceResolveSalary(array $advance, ?Database $dbInstance = null): array
{
    $db = $dbInstance ?: db();

    $userId = isset($advance['user_id']) ? (int)$advance['user_id'] : 0;
    $amount = isset($advance['amount']) ? (float)$advance['amount'] : 0.0;

    if ($userId <= 0) {
        return ['success' => false, 'message' => 'معرف المستخدم غير صالح للسلفة.'];
    }

    if ($amount <= 0) {
        return ['success' => false, 'message' => 'مبلغ السلفة غير صالح للخصم.'];
    }

    $targetDate = $advance['request_date'] ?? date('Y-m-d');
    $timestamp = strtotime($targetDate) ?: time();
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp);

    $summary = getSalarySummary($userId, $month, $year);

    if (!$summary['exists']) {
        $creation = createOrUpdateSalary($userId, $month, $year);
        if (!($creation['success'] ?? false)) {
            $message = $creation['message'] ?? 'تعذر إنشاء الراتب لخصم السلفة.';
            return ['success' => false, 'message' => $message];
        }

        $summary = getSalarySummary($userId, $month, $year);
        if (!($summary['exists'] ?? false)) {
            return ['success' => false, 'message' => 'لم يتم العثور على الراتب بعد إنشائه.'];
        }
    }

    $salary = $summary['salary'];
    $salaryId = isset($salary['id']) ? (int)$salary['id'] : 0;

    if ($salaryId <= 0) {
        return ['success' => false, 'message' => 'تعذر تحديد الراتب لخصم السلفة.'];
    }

    return [
        'success' => true,
        'salary' => $salary,
        'salary_id' => $salaryId,
        'month' => $month,
        'year' => $year,
    ];
}

/**
 * تطبيق خصم السلفة على الراتب المحدد.
 *
 * @return array{success: bool, message?: string}
 */
function salaryAdvanceApplyDeduction(array $advance, array $salaryData, ?Database $dbInstance = null): array
{
    $db = $dbInstance ?: db();
    $amount = isset($advance['amount']) ? (float)$advance['amount'] : 0.0;

    if ($amount <= 0) {
        return ['success' => false, 'message' => 'مبلغ السلفة غير صالح.'];
    }

    $columns = salaryAdvanceGetSalaryColumns($db);
    if (
        $columns['advances_deduction'] === null
        && $columns['deductions'] === null
        && $columns['total_amount'] === null
    ) {
        return ['success' => false, 'message' => 'لا يمكن تنفيذ الخصم لعدم توفر الأعمدة المطلوبة.'];
    }

    $salaryId = isset($salaryData['id']) ? (int)$salaryData['id'] : 0;
    if ($salaryId <= 0) {
        return ['success' => false, 'message' => 'معرف الراتب غير صالح.'];
    }

    $updates = [];
    $params = [];

    if ($columns['advances_deduction'] !== null) {
        $updates[] = "{$columns['advances_deduction']} = COALESCE({$columns['advances_deduction']}, 0) + ?";
        $params[] = $amount;
    }

    if ($columns['deductions'] !== null) {
        $updates[] = "{$columns['deductions']} = COALESCE({$columns['deductions']}, 0) + ?";
        $params[] = $amount;
    }

    if ($columns['total_amount'] !== null) {
        $updates[] = "{$columns['total_amount']} = GREATEST(COALESCE({$columns['total_amount']}, 0) - ?, 0)";
        $params[] = $amount;
    }

    if ($columns['updated_at'] !== null) {
        $updates[] = "{$columns['updated_at']} = NOW()";
    }

    if (empty($updates)) {
        return ['success' => false, 'message' => 'لا توجد أعمدة صالحة لتحديث الراتب.'];
    }

    $params[] = $salaryId;

    try {
        $db->execute(
            "UPDATE salaries SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
    } catch (Throwable $e) {
        error_log('Failed to apply salary advance deduction: ' . $e->getMessage());
        return ['success' => false, 'message' => 'تعذر تحديث الراتب بخصم السلفة.'];
    }

    return ['success' => true];
}

/**
 * حساب الراتب الإجمالي بشكل صحيح مع نسبة التحصيلات
 * تستخدم نفس المنطق المستخدم في صفحة "مرتبي"
 */
function calculateTotalSalaryWithCollections($salaryRecord, $userId, $month, $year, $role) {
    $baseAmount = cleanFinancialValue($salaryRecord['base_amount'] ?? 0);
    $bonus = cleanFinancialValue($salaryRecord['bonus'] ?? 0);
    $deductions = cleanFinancialValue($salaryRecord['deductions'] ?? 0);
    $totalSalaryBase = cleanFinancialValue($salaryRecord['total_amount'] ?? 0);
    
    // حساب نسبة التحصيلات للمندوبين
    $collectionsBonus = 0;
    if ($role === 'sales') {
        $collectionsAmount = calculateSalesCollections($userId, $month, $year);
        $collectionsBonus = round($collectionsAmount * 0.02, 2);
        
        // إذا كان الراتب محفوظاً، تحقق من وجود نسبة التحصيلات المحفوظة
        if (isset($salaryRecord['collections_bonus'])) {
            $savedCollectionsBonus = cleanFinancialValue($salaryRecord['collections_bonus'] ?? 0);
            // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
            if ($collectionsBonus > $savedCollectionsBonus || $savedCollectionsBonus == 0) {
                // استخدم القيمة المحسوبة حديثاً
            } else {
                $collectionsBonus = $savedCollectionsBonus;
            }
        }
    }
    
    // حساب الراتب الإجمالي - دائماً احسبه من المكونات لضمان الدقة
    // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
    $totalSalary = $baseAmount + $bonus + $collectionsBonus - $deductions;
    
    // إذا كان الراتب الإجمالي المحفوظ ($totalSalaryBase) أكبر من الراتب المحسوب من المكونات
    // فهذا يعني أن هناك مكونات إضافية (مثل سلفات مخصومة)، لذا استخدم القيمة المحفوظة
    // لكن تأكد من تضمين نسبة التحصيلات إذا لم تكن مضمنة
    if ($role === 'sales' && $collectionsBonus > 0) {
        // حساب الراتب المتوقع بدون نسبة التحصيلات
        $expectedTotalWithoutCollections = $baseAmount + $bonus - $deductions;
        
        // إذا كان الراتب الإجمالي المحفوظ يساوي الراتب المتوقع بدون نسبة التحصيلات
        // فهذا يعني أن نسبة التحصيلات غير مضمنة، لذا أضفها
        if (abs($totalSalaryBase - $expectedTotalWithoutCollections) < 0.01) {
            // نسبة التحصيلات غير مضمنة، أضفها
            $totalSalary = $totalSalaryBase + $collectionsBonus;
        } else {
            // نسبة التحصيلات مضمنة أو هناك خصومات إضافية (مثل سلفات)
            // استخدم الراتب المحسوب من المكونات
            $totalSalary = $baseAmount + $bonus + $collectionsBonus - $deductions;
        }
    } else {
        // للمستخدمين الآخرين أو إذا لم تكن هناك نسبة تحصيلات
        // استخدم الراتب المحسوب من المكونات
        $totalSalary = $baseAmount + $bonus - $deductions;
    }
    
    return [
        'total_salary' => cleanFinancialValue($totalSalary),
        'collections_bonus' => $collectionsBonus,
        'base_amount' => $baseAmount,
        'bonus' => $bonus,
        'deductions' => $deductions
    ];
}

