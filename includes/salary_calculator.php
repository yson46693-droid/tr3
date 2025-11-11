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
            } else {
                if ($hasNotesColumn) {
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
        } else {
            // إذا لم يكن year موجوداً
            if (stripos($monthType, 'date') !== false) {
                // إذا كان month من نوع DATE
                $targetDate = sprintf('%04d-%02d-01', $year, $month);
                if ($hasBonusColumn) {
                    if ($hasNotesColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $targetDate,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes
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
                                $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                                $calculation['deductions'],
                                $calculation['total_amount']
                            ]
                        );
                    }
                } else {
                    if ($hasNotesColumn) {
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
            } else {
                // إذا كان month من نوع INT فقط
                if ($hasBonusColumn) {
                    if ($hasNotesColumn) {
                        $result = $db->execute(
                            "INSERT INTO salaries (user_id, month, hourly_rate, total_hours, base_amount, bonus, deductions, total_amount, notes, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                            [
                                $userId,
                                $month,
                                $calculation['hourly_rate'],
                                $calculation['total_hours'],
                                $calculation['base_amount'],
                                $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                                $calculation['deductions'],
                                $calculation['total_amount'],
                                $notes
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
                                $calculation['total_bonus'], // إجمالي المكافأة (بما في ذلك نسبة التحصيلات)
                                $calculation['deductions'],
                                $calculation['total_amount']
                            ]
                        );
                    }
                } else {
                    if ($hasNotesColumn) {
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
    
    // استبعاد المديرين
    $users = $db->query(
        "SELECT u.id, u.username, u.full_name, u.role, u.hourly_rate
         FROM users u
         WHERE u.status = 'active' 
         AND u.role != 'manager'
         AND u.hourly_rate > 0
         ORDER BY u.full_name ASC"
    );
    
    $report = [
        'month' => $month,
        'year' => $year,
        'total_users' => 0,
        'total_hours' => 0,
        'total_amount' => 0,
        'salaries' => []
    ];
    
    foreach ($users as $user) {
        // حساب أو الحصول على الراتب
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
                'status' => $salary['status'] ?? 'pending'
            ];
            
            $report['total_hours'] += $salary['total_hours'];
            $report['total_amount'] += $salary['total_amount'];
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
                'status' => 'not_calculated'
            ];
            
            $report['total_hours'] += $calc['total_hours'];
            $report['total_amount'] += $calc['total_amount'];
        }
    }
    
    $report['total_users'] = count($report['salaries']);
    
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

