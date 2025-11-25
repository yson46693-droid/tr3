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

/**
 * حساب عدد الساعات الشهرية للمستخدم
 * يستخدم جدول attendance_records الجديد
 */
function calculateMonthlyHours($userId, $month, $year) {
    $db = db();
    
    // التحقق من وجود جدول attendance_records
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    
    if (!empty($tableCheck)) {
        // استخدام الجدول الجديد
        // حساب الساعات من جميع السجلات التي تم إكمالها (check_out_time IS NOT NULL)
        // حتى لو كانت الساعات قليلة (مثل ربع ساعة)
        $result = $db->queryOne(
            "SELECT COALESCE(SUM(work_hours), 0) as total_hours 
             FROM attendance_records 
             WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?
             AND check_out_time IS NOT NULL
             AND work_hours IS NOT NULL
             AND work_hours > 0",
            [$userId, $month, $year]
        );
        
        $totalHours = round($result['total_hours'] ?? 0, 2);
        
        // تسجيل للتأكد من أن الساعات تُحسب بشكل صحيح
        error_log("calculateMonthlyHours: user_id={$userId}, month={$month}, year={$year}, total_hours={$totalHours}");
        
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
 * حساب مجموع التحصيلات للمندوب خلال الشهر
 */
function calculateSalesCollections($userId, $month, $year) {
    $db = db();
    
    // التحقق من وجود جدول collections
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
    if (empty($tableCheck)) {
        return 0;
    }
    
    // التحقق من وجود عمود status
    $columnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatus = !empty($columnCheck);
    
    if ($hasStatus) {
        // حساب مجموع التحصيلات المعتمدة فقط (approved)
        $result = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections 
             FROM collections 
             WHERE collected_by = ? 
             AND MONTH(date) = ? 
             AND YEAR(date) = ?
             AND status = 'approved'",
            [$userId, $month, $year]
        );
    } else {
        // إذا لم يكن status موجوداً، احسب جميع التحصيلات
        $result = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections 
             FROM collections 
             WHERE collected_by = ? 
             AND MONTH(date) = ? 
             AND YEAR(date) = ?",
            [$userId, $month, $year]
        );
    }
    
    return round($result['total_collections'] ?? 0, 2);
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
    
    if ($hourlyRate <= 0) {
        return [
            'success' => false,
            'message' => 'لم يتم تحديد سعر الساعة للمستخدم'
        ];
    }
    
    $role = $user['role'];
    
    // حساب عدد الساعات
    $totalHours = calculateMonthlyHours($userId, $month, $year);
    
    // حساب الراتب الأساسي
    $baseAmount = $totalHours * $hourlyRate;
    
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
    
    if ($existingSalary) {
        // تحديث الراتب الموجود
        if ($hasBonusColumn) {
            if ($hasNotesColumn) {
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
        
        return [
            'success' => true,
            'message' => 'تم تحديث الراتب بنجاح',
            'salary_id' => $existingSalary['id'],
            'calculation' => $calculation
        ];
    } else {
        // إنشاء راتب جديد
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
        
        return [
            'success' => true,
            'message' => 'تم إنشاء الراتب بنجاح',
            'salary_id' => $result['insert_id'],
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
        
        // إضافة جميع المستخدمين الذين لديهم حضور (حتى لو لم يكن لديهم راتب)
        // التحقق من وجود أي سجل حضور في الشهر
        $hasRecords = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM attendance_records 
             WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?",
            [$user['id'], $month, $year]
        );
        $hasAttendance = !empty($hasRecords) && ($hasRecords['cnt'] ?? 0) > 0;
        
        // إذا لم يكن لديهم حضور في الشهر، لا نضيفهم للتقرير
        if (!$hasAttendance && $delaySummary['attendance_days'] === 0) {
            continue;
        }
        
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

