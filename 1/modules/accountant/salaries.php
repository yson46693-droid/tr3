<?php
/**
 * صفحة إدارة الرواتب الشاملة - حساب وعرض وتعديل الرواتب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/salary_calculator.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireAnyRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$approvalsEntityColumn = getApprovalsEntityColumn();
$error = '';
$success = '';

// إنشاء جدول السلف
$advancesTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
if (empty($advancesTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `salary_advances` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL COMMENT 'الموظف',
              `amount` decimal(10,2) NOT NULL COMMENT 'مبلغ السلفة',
              `reason` text DEFAULT NULL COMMENT 'سبب السلفة',
              `request_date` date NOT NULL COMMENT 'تاريخ الطلب',
              `status` enum('pending','accountant_approved','manager_approved','rejected') DEFAULT 'pending' COMMENT 'حالة الطلب',
              `accountant_approved_by` int(11) DEFAULT NULL,
              `accountant_approved_at` timestamp NULL DEFAULT NULL,
              `manager_approved_by` int(11) DEFAULT NULL,
              `manager_approved_at` timestamp NULL DEFAULT NULL,
              `deducted_from_salary_id` int(11) DEFAULT NULL COMMENT 'الراتب الذي تم خصم السلفة منه',
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `status` (`status`),
              KEY `request_date` (`request_date`),
              KEY `accountant_approved_by` (`accountant_approved_by`),
              KEY `manager_approved_by` (`manager_approved_by`),
              KEY `deducted_from_salary_id` (`deducted_from_salary_id`),
              CONSTRAINT `salary_advances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_advances_ibfk_2` FOREIGN KEY (`accountant_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `salary_advances_ibfk_3` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `salary_advances_ibfk_4` FOREIGN KEY (`deducted_from_salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating salary_advances table: " . $e->getMessage());
    }
}

// إضافة عمود advances_deduction في جدول salaries
$advancesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'advances_deduction'");
if (empty($advancesColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `advances_deduction` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'خصم السلف' 
            AFTER `deductions`
        ");
    } catch (Exception $e) {
        error_log("Error adding advances_deduction column: " . $e->getMessage());
    }
}

// الحصول على الشهر والسنة الحالية
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selectedUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$salaryId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$view = isset($_GET['view']) ? $_GET['view'] : ''; // 'list' أو 'pending' أو 'advances'

// تحديد التبويب الافتراضي
if (empty($view)) {
    $view = 'list'; // الافتراضي: قائمة الرواتب
}

// التحقق من طلب عرض التقرير الشهري
$showReport = isset($_GET['report']) && $_GET['report'] == '1';

// تعريف دالة بناء الروابط (يجب تعريفها مبكراً لاستخدامها في معالجة POST)
require_once __DIR__ . '/../../includes/path_helper.php';
$rawScript = $_SERVER['PHP_SELF'] ?? '/dashboard/accountant.php';
$rawScript = ltrim($rawScript, '/');
if ($rawScript === '') {
    $currentScript = 'dashboard/accountant.php';
} else {
    $dashboardPos = strpos($rawScript, 'dashboard/');
    if ($dashboardPos !== false) {
        $currentScript = substr($rawScript, $dashboardPos);
    } else {
        $currentScript = $rawScript;
    }
    if (strpos($currentScript, 'dashboard/') !== 0) {
        $currentScript = 'dashboard/accountant.php';
    }
}
$currentUrl = getRelativeUrl($currentScript);
$viewBaseQuery = [
    'page' => 'salaries',
    'month' => $selectedMonth,
    'year' => $selectedYear,
];
$buildViewUrl = function (string $targetView, array $extra = []) use ($currentUrl, $viewBaseQuery) {
    $query = array_merge($viewBaseQuery, ['view' => $targetView], $extra);
    return $currentUrl . '?' . http_build_query($query);
};

// قراءة الرسائل من session (Post-Redirect-Get pattern)
if (isset($_SESSION['salaries_success'])) {
    $success = $_SESSION['salaries_success'];
    unset($_SESSION['salaries_success']);
}

if (isset($_SESSION['salaries_error'])) {
    $error = $_SESSION['salaries_error'];
    unset($_SESSION['salaries_error']);
}

// قراءة حالة التقرير من session
if (isset($_SESSION['salaries_show_report']) && $_SESSION['salaries_show_report']) {
    $showReport = true;
    if (isset($_SESSION['salaries_report_month'])) {
        $selectedMonth = (int)$_SESSION['salaries_report_month'];
    }
    if (isset($_SESSION['salaries_report_year'])) {
        $selectedYear = (int)$_SESSION['salaries_report_year'];
    }
    // تنظيف session
    unset($_SESSION['salaries_show_report']);
    unset($_SESSION['salaries_report_month']);
    unset($_SESSION['salaries_report_year']);
}

$monthlyReport = null;

if ($showReport) {
    $monthlyReport = generateMonthlySalaryReport($selectedMonth, $selectedYear);
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'calculate_all') {
        $month = intval($_POST['month'] ?? $selectedMonth);
        $year = intval($_POST['year'] ?? $selectedYear);
        
        $results = calculateAllSalaries($month, $year);
        
        $successCount = 0;
        foreach ($results as $result) {
            if ($result['result']['success']) {
                $successCount++;
                // طلب موافقة لكل راتب
                if (isset($result['result']['salary_id'])) {
                    requestApproval('salary', $result['result']['salary_id'], $currentUser['id'], 'حساب تلقائي لجميع الرواتب');
                }
            }
        }
        
        logAudit($currentUser['id'], 'calculate_all_salaries', 'salary', null, null, [
            'month' => $month,
            'year' => $year,
            'count' => $successCount
        ]);
        
        $success = "تم حساب $successCount راتب بنجاح";
        $view = 'list'; // الانتقال إلى قائمة الرواتب
    } elseif ($action === 'send_attendance_report') {
        $month = intval($_POST['month'] ?? $selectedMonth);
        $year  = intval($_POST['year'] ?? $selectedYear);

        $reportResult = sendMonthlyAttendanceReportToTelegram($month, $year, [
            'force' => true,
            'triggered_by' => $currentUser['id'] ?? null,
        ]);

        // حفظ الرسالة في session لمنع التكرار
        if ($reportResult['success']) {
            $_SESSION['salaries_success'] = $reportResult['message'];
        } else {
            $_SESSION['salaries_error'] = $reportResult['message'];
        }

        // حفظ حالة التقرير في session
        $_SESSION['salaries_show_report'] = true;
        $_SESSION['salaries_report_month'] = $month;
        $_SESSION['salaries_report_year'] = $year;

        // عمل redirect لمنع التكرار عند refresh (Post-Redirect-Get pattern)
        // تحديد URL الصحيح بناءً على الصفحة الحالية
        $rawScript = $_SERVER['PHP_SELF'] ?? '/dashboard/accountant.php';
        $rawScript = ltrim($rawScript, '/');
        
        $isManagerPage = (strpos($rawScript, 'manager.php') !== false);
        
        if ($isManagerPage) {
            $redirectUrl = getRelativeUrl('dashboard/manager.php');
        } else {
            $redirectUrl = getRelativeUrl('dashboard/accountant.php');
        }
        
        $redirectUrl .= '?page=salaries&view=list&report=1&month=' . $month . '&year=' . $year;
        header('Location: ' . $redirectUrl);
        exit;
    } elseif ($action === 'modify_salary') {
        // وظيفة تعديل الراتب من salary_details.php
        $salaryId = intval($_POST['salary_id'] ?? 0);
        $bonus = floatval($_POST['bonus'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($salaryId <= 0) {
            $error = 'معرف الراتب غير صحيح';
        } else {
            // الحصول على بيانات الراتب
            $salary = $db->queryOne(
                "SELECT s.*, u.full_name, u.username, u.role 
                 FROM salaries s 
                 LEFT JOIN users u ON s.user_id = u.id 
                 WHERE s.id = ?",
                [$salaryId]
            );
            
            if (!$salary) {
                $error = 'الراتب غير موجود';
            } else {
                // حساب الراتب الجديد
                $newBaseAmount = $salary['base_amount'];
                $newTotalAmount = $newBaseAmount + $bonus - $deductions;
                
                // التحقق من وجود عمود bonus
                $bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'bonus'");
                $hasBonusColumn = !empty($bonusColumnCheck);
                
                // إذا كان المحاسب فقط (وليس المدير)، يحتاج موافقة
                if ($currentUser['role'] === 'accountant') {
                    // التحقق من وجود موافقة معلقة
                    $pendingApproval = $db->queryOne(
                        "SELECT id FROM approvals 
                         WHERE type = 'salary_modification' AND `{$approvalsEntityColumn}` = ? AND status = 'pending'",
                        [$salaryId]
                    );
                    
                    if ($pendingApproval) {
                        $error = 'يوجد طلب تعديل معلق بالفعل على هذا الراتب';
                    } else {
                        // حفظ بيانات التعديل في JSON
                        $modificationData = json_encode([
                            'bonus' => $bonus,
                            'deductions' => $deductions,
                            'original_bonus' => $salary['bonus'] ?? 0,
                            'original_deductions' => $salary['deductions'] ?? 0,
                            'notes' => $notes
                        ]);
                        
                        // طلب موافقة المدير
                        $approvalNotes = "طلب تعديل راتب للمستخدم {$salary['full_name']}. مكافأة: " . number_format($bonus, 2) . " جنيه, خصومات: " . number_format($deductions, 2) . " جنيه. " . ($notes ? "السبب: {$notes}" : "");
                        
                        $approvalResult = requestApproval(
                            'salary_modification',
                            $salaryId,
                            $currentUser['id'],
                            $approvalNotes . "\n[DATA]:" . $modificationData
                        );
                        
                        if ($approvalResult['success']) {
                            logAudit($currentUser['id'], 'request_salary_modification', 'salary', $salaryId, null, [
                                'bonus' => $bonus,
                                'deductions' => $deductions,
                                'approval_id' => $approvalResult['approval_id']
                            ]);
                            
                            $success = 'تم إرسال طلب التعديل. في انتظار موافقة المدير.';
                        } else {
                            $error = $approvalResult['message'] ?? 'فشل إرسال طلب الموافقة';
                        }
                    }
                } else {
                    // المدير - يمكنه الموافقة مباشرة
                    if ($hasBonusColumn) {
                        $db->execute(
                            "UPDATE salaries SET 
                                bonus = ?,
                                deductions = ?,
                                total_amount = ?,
                                notes = ?,
                                updated_at = NOW()
                             WHERE id = ?",
                            [$bonus, $deductions, $newTotalAmount, $notes ?: null, $salaryId]
                        );
                    } else {
                        $db->execute(
                            "UPDATE salaries SET 
                                deductions = ?,
                                total_amount = ?,
                                notes = ?,
                                updated_at = NOW()
                             WHERE id = ?",
                            [$deductions, $newTotalAmount, $notes ?: null, $salaryId]
                        );
                    }
                    
                    // إرسال إشعار للمستخدم
                    createNotification(
                        $salary['user_id'],
                        'تم تعديل راتبك',
                        "تم تعديل راتبك للشهر " . date('F', mktime(0, 0, 0, $selectedMonth, 1)) . ". مكافأة: " . formatCurrency($bonus) . ", خصومات: " . formatCurrency($deductions),
                        'info',
                        null,
                        false
                    );
                    
                    logAudit($currentUser['id'], 'modify_salary', 'salary', $salaryId, null, [
                        'bonus' => $bonus,
                        'deductions' => $deductions
                    ]);
                    
                    $success = 'تم تعديل الراتب بنجاح';
                }
            }
        }
    }
    
    // طلب سلفة جديدة
    elseif ($action === 'request_advance') {
        $userId = intval($_POST['user_id'] ?? $currentUser['id']);
        $amount = floatval($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $requestDate = $_POST['request_date'] ?? date('Y-m-d');
        
        if ($amount <= 0) {
            $error = 'يجب إدخال مبلغ السلفة';
        } else {
            try {
                $result = $db->execute(
                    "INSERT INTO salary_advances (user_id, amount, reason, request_date, status) 
                     VALUES (?, ?, ?, ?, 'pending')",
                    [$userId, $amount, $reason ?: null, $requestDate]
                );
                
                logAudit($currentUser['id'], 'request_advance', 'salary_advance', $result['insert_id'], null, [
                    'user_id' => $userId,
                    'amount' => $amount
                ]);
                
                $success = 'تم إرسال طلب السلفة بنجاح. في انتظار موافقة المحاسب.';
            } catch (Exception $e) {
                $error = 'حدث خطأ في إرسال الطلب: ' . $e->getMessage();
            }
        }
    }
    
    // موافقة المحاسب على السلفة
    elseif ($action === 'accountant_approve_advance') {
        if ($currentUser['role'] !== 'accountant' && $currentUser['role'] !== 'manager') {
            $error = 'غير مصرح لك بهذا الإجراء';
        } else {
            $advanceId = intval($_POST['advance_id'] ?? 0);
            
            if ($advanceId <= 0) {
                $error = 'معرف السلفة غير صحيح';
            } else {
                $advance = $db->queryOne("SELECT * FROM salary_advances WHERE id = ?", [$advanceId]);
                
                if (!$advance) {
                    $error = 'السلفة غير موجودة';
                } elseif ($advance['status'] !== 'pending') {
                    $error = 'هذه السلفة تمت معالجتها بالفعل';
                } else {
                    $db->execute(
                        "UPDATE salary_advances 
                         SET status = 'accountant_approved', 
                             accountant_approved_by = ?, 
                             accountant_approved_at = NOW() 
                         WHERE id = ?",
                        [$currentUser['id'], $advanceId]
                    );
                    
                    logAudit($currentUser['id'], 'accountant_approve_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount']
                    ]);
                    
                    // إرسال إشعار للمدير
                    $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND status = 'active'");
                    $managerAdvancesLink = $buildViewUrl('advances');
                    foreach ($managers as $manager) {
                        createNotification(
                            $manager['id'],
                            'طلب سلفة يحتاج موافقتك',
                            "طلب سلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م يحتاج موافقتك",
                            'warning',
                            $managerAdvancesLink,
                            false
                        );
                    }
                    
                    $success = 'تم استلام الطلب. تم إرساله للمدير للموافقة النهائية.';
                }
            }
        }
    }
    
    // موافقة المدير على السلفة
    elseif ($action === 'manager_approve_advance') {
        if ($currentUser['role'] !== 'manager') {
            $error = 'غير مصرح لك بهذا الإجراء';
        } else {
            $advanceId = intval($_POST['advance_id'] ?? 0);
            
            if ($advanceId <= 0) {
                $error = 'معرف السلفة غير صحيح';
            } else {
                $advance = $db->queryOne("SELECT * FROM salary_advances WHERE id = ?", [$advanceId]);
                
                if (!$advance) {
                    $error = 'السلفة غير موجودة';
                } elseif (!in_array($advance['status'], ['pending', 'accountant_approved'], true)) {
                    $error = 'لا يمكن الموافقة على الطلب في حالته الحالية';
                } elseif (!empty($advance['deducted_from_salary_id'])) {
                    $error = 'تم خصم هذه السلفة من الراتب بالفعل.';
                } else {
                    $resolution = salaryAdvanceResolveSalary($advance, $db);
                    if (!($resolution['success'] ?? false)) {
                        $error = $resolution['message'] ?? 'تعذر تحديد الراتب المناسب لخصم السلفة.';
                    } else {
                        $salaryData = $resolution['salary'];
                        $salaryId = (int) ($resolution['salary_id'] ?? 0);

                        if ($salaryId <= 0) {
                            $error = 'تعذر تحديد الراتب المراد الخصم منه.';
                        } else {
                            try {
                                $db->beginTransaction();

                                $deductionResult = salaryAdvanceApplyDeduction($advance, $salaryData, $db);
                                if (!($deductionResult['success'] ?? false)) {
                                    $message = $deductionResult['message'] ?? 'تعذر تطبيق الخصم على الراتب.';
                                    throw new Exception($message);
                                }

                                if ($advance['status'] === 'pending') {
                                    $db->execute(
                                        "UPDATE salary_advances 
                                         SET status = 'manager_approved', 
                                             accountant_approved_by = ?, 
                                             accountant_approved_at = NOW(), 
                                             manager_approved_by = ?, 
                                             manager_approved_at = NOW(),
                                             deducted_from_salary_id = ?
                                         WHERE id = ?",
                                        [$currentUser['id'], $currentUser['id'], $salaryId, $advanceId]
                                    );
                                } else {
                                    $db->execute(
                                        "UPDATE salary_advances 
                                         SET status = 'manager_approved', 
                                             manager_approved_by = ?, 
                                             manager_approved_at = NOW(),
                                             deducted_from_salary_id = ?
                                         WHERE id = ?",
                                        [$currentUser['id'], $salaryId, $advanceId]
                                    );
                                }

                                $db->commit();
                            } catch (Throwable $approvalError) {
                                $db->rollback();
                                $error = $approvalError->getMessage() ?: 'تعذر إتمام الموافقة على السلفة.';
                            }

                            if (empty($error)) {
                                logAudit($currentUser['id'], 'manager_approve_advance', 'salary_advance', $advanceId, null, [
                                    'user_id' => $advance['user_id'],
                                    'amount' => $advance['amount'],
                                    'salary_id' => $salaryId
                                ]);
                                
                                createNotification(
                                    $advance['user_id'],
                                    'تمت الموافقة على طلب السلفة',
                                    "تمت الموافقة على طلب السلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م. وتم خصمها من راتبك الحالي.",
                                    'success',
                                    null,
                                    false
                                );
                                
                                $success = 'تمت الموافقة على السلفة بنجاح وتم خصمها من الراتب الحالي.';
                            }
                        }
                    }
                }
            }
        }
    }
    
    // رفض السلفة
    elseif ($action === 'reject_advance') {
        if ($currentUser['role'] !== 'accountant' && $currentUser['role'] !== 'manager') {
            $error = 'غير مصرح لك بهذا الإجراء';
        } else {
            $advanceId = intval($_POST['advance_id'] ?? 0);
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');
            
            if ($advanceId <= 0) {
                $error = 'معرف السلفة غير صحيح';
            } else {
                $advance = $db->queryOne("SELECT * FROM salary_advances WHERE id = ?", [$advanceId]);
                
                if (!$advance) {
                    $error = 'السلفة غير موجودة';
                } elseif ($advance['status'] === 'manager_approved') {
                    $error = 'لا يمكن رفض سلفة تمت الموافقة عليها';
                } else {
                    $db->execute(
                        "UPDATE salary_advances 
                         SET status = 'rejected', 
                             notes = ? 
                         WHERE id = ?",
                        [$rejectionReason ?: 'تم رفض الطلب', $advanceId]
                    );
                    
                    logAudit($currentUser['id'], 'reject_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount']
                    ]);
                    
                    // إرسال إشعار للموظف
                    createNotification(
                        $advance['user_id'],
                        'تم رفض طلب السلفة',
                        "تم رفض طلب السلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م. السبب: " . ($rejectionReason ?: 'غير محدد'),
                        'error',
                        null,
                        false
                    );
                    
                    $success = 'تم رفض السلفة';
                }
            }
        }
    }
}

// الحصول على قائمة المستخدمين (استبعاد المديرين)
$users = $db->query(
    "SELECT id, username, full_name, hourly_rate, role 
     FROM users 
     WHERE status = 'active' 
     AND role != 'manager'
     ORDER BY full_name ASC"
);

// الحصول على الرواتب للشهر المحدد مع فلترة
$yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
$hasYearColumn = !empty($yearColumnCheck);

$whereConditions = [];
$params = [];

if ($hasYearColumn) {
    $whereConditions[] = "s.month = ? AND s.year = ?";
    $params[] = $selectedMonth;
    $params[] = $selectedYear;
} else {
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    
    if (stripos($monthType, 'date') !== false) {
        $whereConditions[] = "DATE_FORMAT(s.month, '%Y-%m') = ?";
        $params[] = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
    } else {
        $whereConditions[] = "s.month = ?";
        $params[] = $selectedMonth;
    }
}

if ($selectedUserId > 0) {
    $whereConditions[] = "s.user_id = ?";
    $params[] = $selectedUserId;
}

if ($salaryId > 0) {
    $whereConditions[] = "s.id = ?";
    $params[] = $salaryId;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

$salaries = $db->query(
    "SELECT s.*, u.full_name, u.username, u.role, u.hourly_rate as current_hourly_rate,
            approver.full_name as approver_name
     FROM salaries s
     LEFT JOIN users u ON s.user_id = u.id
     LEFT JOIN users approver ON s.approved_by = approver.id
     $whereClause
     ORDER BY u.full_name ASC",
    $params
);

// استبعاد المديرين من قائمة الرواتب المعروضة
$salaries = array_values(array_filter($salaries, function ($salary) {
    $role = strtolower($salary['role'] ?? '');
    $hourlyRate = isset($salary['hourly_rate']) ? floatval($salary['hourly_rate']) : (isset($salary['current_hourly_rate']) ? floatval($salary['current_hourly_rate']) : 0);
    return $role !== 'manager' && $hourlyRate > 0;
}));

// الحصول على طلبات تعديل الرواتب المعلقة (للمدير فقط)
$pendingModifications = [];
if ($currentUser['role'] === 'manager') {
    try {
        $entityColumn = getApprovalsEntityColumn();
        
        $sql = "SELECT a.*, s.user_id, u.full_name, u.username
                FROM approvals a
                LEFT JOIN salaries s ON a.`" . $entityColumn . "` = s.id
                LEFT JOIN users u ON s.user_id = u.id
                WHERE a.type = 'salary_modification' AND a.status = 'pending'
                ORDER BY a.created_at DESC";
        
        $pendingModifications = $db->query($sql);
    } catch (Exception $e) {
        error_log("Error fetching pending modifications: " . $e->getMessage());
        $pendingModifications = [];
    }
}

// جلب طلبات السلف
$advances = [];
if ($view === 'advances' || $currentUser['role'] === 'accountant' || $currentUser['role'] === 'manager') {
    $advancesQuery = "
        SELECT sa.*, 
               u.full_name as user_name, u.username,
               accountant.full_name as accountant_name,
               manager.full_name as manager_name
        FROM salary_advances sa
        INNER JOIN users u ON sa.user_id = u.id
        LEFT JOIN users accountant ON sa.accountant_approved_by = accountant.id
        LEFT JOIN users manager ON sa.manager_approved_by = manager.id
        ORDER BY sa.created_at DESC
    ";
    
    $advances = $db->query($advancesQuery);
}

// معالجة AJAX لتفاصيل الراتب
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && $salaryId > 0) {
    $salary = $db->queryOne(
        "SELECT s.*, u.full_name, u.username, u.role, u.hourly_rate as current_hourly_rate,
                approver.full_name as approver_name
         FROM salaries s
         LEFT JOIN users u ON s.user_id = u.id
         LEFT JOIN users approver ON s.approved_by = approver.id
         WHERE s.id = ?
           AND (u.role IS NULL OR LOWER(u.role) != 'manager')
           AND (u.hourly_rate IS NULL OR u.hourly_rate > 0)",
        [$salaryId]
    );
    
    if ($salary) {
        ?>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">معلومات الراتب</h6>
                    </div>
                    <div class="card-body">
                        <p><strong>المستخدم:</strong> <?php echo htmlspecialchars($salary['full_name'] ?? $salary['username']); ?></p>
                        <p><strong>الشهر:</strong> <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?></p>
                        <p><strong>سعر الساعة:</strong> <?php echo formatCurrency($salary['hourly_rate']); ?></p>
                        <p><strong>عدد الساعات:</strong> <?php echo number_format($salary['total_hours'], 2); ?> ساعة</p>
                        <p><strong>الراتب الأساسي:</strong> <?php echo formatCurrency($salary['base_amount']); ?></p>
                        <p><strong>مكافأة:</strong> <?php echo formatCurrency($salary['bonus'] ?? 0); ?></p>
                        <p><strong>خصومات:</strong> <?php echo formatCurrency($salary['deductions'] ?? 0); ?></p>
                        <p><strong>الإجمالي:</strong> <strong class="text-success"><?php echo formatCurrency($salary['total_amount']); ?></strong></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">تفاصيل إضافية</h6>
                    </div>
                    <div class="card-body">
                        <p><strong>الحالة:</strong> 
                            <span class="badge bg-<?php 
                                echo $salary['status'] === 'approved' ? 'success' : 
                                    ($salary['status'] === 'rejected' ? 'danger' : 
                                    ($salary['status'] === 'paid' ? 'info' : 'warning')); 
                            ?>">
                                <?php 
                                $statusLabels = [
                                    'pending' => 'معلق',
                                    'approved' => 'موافق عليه',
                                    'rejected' => 'مرفوض',
                                    'paid' => 'مدفوع'
                                ];
                                echo $statusLabels[$salary['status']] ?? $salary['status'];
                                ?>
                            </span>
                        </p>
                        <?php if (!empty($salary['notes'])): ?>
                            <p><strong>ملاحظات:</strong><br><?php echo nl2br(htmlspecialchars($salary['notes'])); ?></p>
                        <?php endif; ?>
                        <p><strong>تاريخ الإنشاء:</strong> <?php echo formatDateTime($salary['created_at']); ?></p>
                        <?php if (!empty($salary['updated_at'])): ?>
                            <p><strong>آخر تحديث:</strong> <?php echo formatDateTime($salary['updated_at']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    } else {
        echo '<div class="alert alert-danger">الراتب غير موجود أو غير متاح للعرض.</div>';
    }
    exit;
}
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <h2 class="mb-0"><i class="bi bi-currency-dollar me-2"></i><?php echo isset($lang['salaries']) ? $lang['salaries'] : 'الرواتب'; ?></h2>
    <div class="d-flex align-items-center gap-3 mt-2 mt-md-0">
        <form method="GET" class="d-inline" action="<?php echo htmlspecialchars($currentUrl); ?>">
            <input type="hidden" name="page" value="salaries">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <select name="month" class="form-select d-inline" style="width: auto;" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select d-inline ms-2" style="width: auto;" onchange="this.form.submit()">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- التبويبات -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $view === 'list' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('list')); ?>">
            <i class="bi bi-list-ul me-2"></i>قائمة الرواتب
        </a>
    </li>
    <?php if ($currentUser['role'] === 'manager' && !empty($pendingModifications)): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $view === 'pending' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('pending')); ?>">
            <i class="bi bi-hourglass-split me-2"></i>طلبات معلقة 
            <span class="badge bg-warning text-dark ms-1"><?php echo count($pendingModifications); ?></span>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?php echo $view === 'advances' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('advances')); ?>">
            <i class="bi bi-cash-coin me-2"></i>السلف
            <?php 
            $pendingAdvances = array_filter($advances, function($adv) {
                return $adv['status'] === 'pending' || $adv['status'] === 'accountant_approved';
            });
            if (count($pendingAdvances) > 0): 
            ?>
            <span class="badge bg-danger ms-1"><?php echo count($pendingAdvances); ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($showReport && $monthlyReport): ?>
<!-- تقرير رواتب شهري -->
<div class="card shadow-sm mb-4">
    <div class="card-header salary-header-gradient text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-file-earmark-text me-2"></i>
            تقرير رواتب شهري - <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="send_attendance_report">
                <input type="hidden" name="month" value="<?php echo (int) $selectedMonth; ?>">
                <input type="hidden" name="year" value="<?php echo (int) $selectedYear; ?>">
                <button type="submit" class="btn btn-warning btn-sm text-dark">
                    <i class="bi bi-send-fill me-1"></i> إرسال تقرير التأخيرات إلى Telegram
                </button>
            </form>
            <a href="<?php echo $currentUrl; ?>?page=salaries&view=<?php echo $view; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-light btn-sm">
                <i class="bi bi-x-lg"></i> إغلاق التقرير
            </a>
        </div>
    </div>
    <div class="card-body">
        <!-- ملخص التقرير -->
        <div class="row mb-4 g-3">
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-blue text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center p-4">
                        <div class="mb-2">
                            <i class="bi bi-people fs-1"></i>
                        </div>
                        <h6 class="card-title mb-2 fw-bold text-uppercase small">عدد الموظفين</h6>
                        <h2 class="mb-0 fw-bold"><?php echo $monthlyReport['total_users']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-green text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center p-4">
                        <div class="mb-2">
                            <i class="bi bi-clock-history fs-1"></i>
                        </div>
                        <h6 class="card-title mb-2 fw-bold text-uppercase small">إجمالي الساعات</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($monthlyReport['total_hours'], 2); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-yellow text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center p-4">
                        <div class="mb-2">
                            <i class="bi bi-cash-stack fs-1"></i>
                        </div>
                        <h6 class="card-title mb-2 fw-bold text-uppercase small">إجمالي الرواتب</h6>
                        <h2 class="mb-0 fw-bold"><?php echo formatCurrency($monthlyReport['total_amount']); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-red text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center p-4">
                        <div class="mb-2">
                            <i class="bi bi-graph-up-arrow fs-1"></i>
                        </div>
                        <h6 class="card-title mb-2 fw-bold text-uppercase small">متوسط الراتب</h6>
                        <h2 class="mb-0 fw-bold">
                            <?php 
                            $avgSalary = $monthlyReport['total_users'] > 0 
                                ? $monthlyReport['total_amount'] / $monthlyReport['total_users'] 
                                : 0;
                            echo formatCurrency($avgSalary); 
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mb-4 g-3">
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-red text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center p-4">
                        <div class="mb-2">
                            <i class="bi bi-exclamation-triangle fs-1"></i>
                        </div>
                        <h6 class="card-title mb-2 fw-bold text-uppercase small">إجمالي التأخيرات (دقائق)</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($monthlyReport['total_delay_minutes'], 2); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-yellow text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center p-4">
                        <div class="mb-2">
                            <i class="bi bi-stopwatch fs-1"></i>
                        </div>
                        <h6 class="card-title mb-2 fw-bold text-uppercase small">متوسط التأخير لكل موظف (دقيقة)</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($monthlyReport['average_delay_minutes'], 2); ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- جدول الرواتب -->
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table table-hover dashboard-table align-middle salary-report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المستخدم</th>
                        <th>الدور</th>
                        <th>سعر الساعة</th>
                        <th>عدد الساعات</th>
                        <th>إجمالي التأخير (دقائق)</th>
                        <th>متوسط التأخير</th>
                        <th>الراتب الأساسي</th>
                        <th>مكافأة</th>
                        <th>نسبة التحصيلات (2%)</th>
                        <th>خصومات</th>
                        <th>الإجمالي</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthlyReport['salaries'])): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted">لا توجد رواتب</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthlyReport['salaries'] as $index => $salary): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="المستخدم">
                                    <strong><?php echo htmlspecialchars($salary['user_name']); ?></strong>
                                </td>
                                <td data-label="الدور">
                                    <?php
                                    $roleColors = [
                                        'production' => 'bg-primary',
                                        'accountant' => 'bg-info',
                                        'sales' => 'bg-success'
                                    ];
                                    $roleLabels = [
                                        'production' => 'إنتاج',
                                        'accountant' => 'محاسب',
                                        'sales' => 'مندوب'
                                    ];
                                    $roleColor = $roleColors[$salary['role']] ?? 'bg-secondary';
                                    $roleLabel = $roleLabels[$salary['role']] ?? $salary['role'];
                                    ?>
                                    <span class="badge <?php echo $roleColor; ?> fs-6 px-3 py-2"><?php echo htmlspecialchars($roleLabel); ?></span>
                                </td>
                                <td data-label="سعر الساعة"><?php echo formatCurrency($salary['hourly_rate']); ?></td>
                                <td data-label="عدد الساعات">
                                    <strong><?php echo number_format($salary['total_hours'], 2); ?> ساعة</strong>
                                </td>
                                <td data-label="إجمالي التأخير (دقائق)">
                                    <strong><?php echo number_format($salary['total_delay_minutes'] ?? 0, 2); ?></strong>
                                    <?php if (!empty($salary['delay_days'])): ?>
                                        <div class="text-muted small"><?php echo (int) $salary['delay_days']; ?> يوم متأخر</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="متوسط التأخير">
                                    <strong><?php echo number_format($salary['average_delay_minutes'] ?? 0, 2); ?> دقيقة</strong>
                                    <?php if (!empty($salary['attendance_days'])): ?>
                                        <div class="text-muted small">من <?php echo (int) $salary['attendance_days']; ?> يوم حضور</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="الراتب الأساسي"><?php echo formatCurrency($salary['base_amount']); ?></td>
                                <td data-label="مكافأة"><?php echo formatCurrency($salary['bonus'] ?? 0); ?></td>
                                <td data-label="نسبة التحصيلات (2%)">
                                    <?php if (isset($salary['collections_bonus']) && $salary['collections_bonus'] > 0): ?>
                                        <span class="text-info">
                                            <?php echo formatCurrency($salary['collections_bonus']); ?>
                                            <br><small>(من <?php echo formatCurrency($salary['collections_amount'] ?? 0); ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="خصومات"><?php echo formatCurrency($salary['deductions'] ?? 0); ?></td>
                                <td data-label="الإجمالي">
                                    <strong class="text-success"><?php echo formatCurrency($salary['total_amount']); ?></strong>
                                </td>
                                <td data-label="الحالة">
                                    <span class="badge bg-<?php 
                                        echo $salary['status'] === 'approved' ? 'success' : 
                                            ($salary['status'] === 'rejected' ? 'danger' : 
                                            ($salary['status'] === 'paid' ? 'info' : 
                                            ($salary['status'] === 'not_calculated' ? 'secondary' : 'warning'))); 
                                    ?>">
                                        <?php 
                                        $statusLabels = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'paid' => 'مدفوع',
                                            'not_calculated' => 'غير محسوب'
                                        ];
                                        echo $statusLabels[$salary['status']] ?? $salary['status']; 
                                        ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-info">
                        <td colspan="4" class="text-end"><strong>الإجمالي:</strong></td>
                        <td><strong><?php echo number_format($monthlyReport['total_hours'], 2); ?> ساعة</strong></td>
                        <td><strong><?php echo number_format($monthlyReport['total_delay_minutes'], 2); ?> دقيقة</strong></td>
                        <td><strong><?php echo number_format($monthlyReport['average_delay_minutes'], 2); ?> دقيقة</strong></td>
                        <td colspan="4"></td>
                        <td><strong><?php echo formatCurrency($monthlyReport['total_amount']); ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>
/* تحسين عرض بطاقات الملخص - الألوان المطلوبة */
.salary-card-blue {
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%) !important;
}

.salary-card-green {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%) !important;
}

.salary-card-yellow {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%) !important;
}

.salary-card-red {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 50%, #f87171 100%) !important;
}

.salary-summary-card {
    border-radius: 16px;
    transition: all 0.3s ease;
    border: none !important;
}

.salary-summary-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25) !important;
}

.salary-summary-card .card-body {
    position: relative;
}

.salary-summary-card i {
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

.salary-summary-card h2 {
    font-size: 2.5rem;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.salary-summary-card h6 {
    font-size: 0.9rem;
    letter-spacing: 0.5px;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

/* تحسين رأس التقرير - تدرج الأزرق والأبيض */
.salary-header-gradient {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%) !important;
    border: none;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

/* تحسين عرض الجدول - تدرج الأزرق والأبيض */
.salary-report-table {
    font-size: 0.95rem;
    border-collapse: separate;
    border-spacing: 0;
}

.salary-report-table thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 30%, #3b82f6 60%, #60a5fa 100%);
    color: #ffffff;
    font-weight: 700;
    padding: 1.25rem 0.75rem;
    border: none;
    text-align: center;
    vertical-align: middle;
    font-size: 0.95rem;
    white-space: nowrap;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    border-bottom: 3px solid #1e3a8a;
}

.salary-report-table thead th:first-child {
    border-top-right-radius: 12px;
}

.salary-report-table thead th:last-child {
    border-top-left-radius: 12px;
}

.salary-report-table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    text-align: center;
    border-bottom: 1px solid #e5e7eb;
    background-color: #ffffff;
    transition: all 0.2s ease;
}

.salary-report-table tbody tr {
    transition: all 0.2s ease;
}

.salary-report-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.05) 0%, rgba(96, 165, 250, 0.1) 100%);
    transform: scale(1.01);
}

.salary-report-table tbody tr:nth-child(even) {
    background-color: #f8fafc;
}

.salary-report-table tbody tr:nth-child(even):hover {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.08) 0%, rgba(96, 165, 250, 0.12) 100%);
}

.salary-report-table tbody td strong {
    font-weight: 700;
    color: #1e293b;
}

.salary-report-table .badge {
    font-size: 0.85rem;
    padding: 0.5rem 0.75rem;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* تحسين عرض تذييل الجدول - تدرج الأزرق الفاتح والأبيض */
.salary-report-table tfoot {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 50%, #bfdbfe 100%);
}

.salary-report-table tfoot td {
    font-weight: 800;
    font-size: 1.1rem;
    padding: 1.5rem 0.75rem;
    border-top: 3px solid #3b82f6;
    color: #1e3a8a;
}

.salary-report-table tfoot td:first-child {
    border-bottom-right-radius: 12px;
}

.salary-report-table tfoot td:last-child {
    border-bottom-left-radius: 12px;
}

/* تحسين عرض الأدوار */
.badge.bg-primary {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%) !important;
    color: #ffffff;
}

.badge.bg-info {
    background: linear-gradient(135deg, #0369a1 0%, #0ea5e9 100%) !important;
    color: #ffffff;
}

.badge.bg-success {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important;
    color: #ffffff;
}

/* تحسين حجم الأرقام */
.salary-report-table .text-success {
    font-weight: 800;
    font-size: 1.1rem;
    color: #059669 !important;
}

/* تحسين الاستجابة */
@media (max-width: 768px) {
    .salary-summary-card h2 {
        font-size: 1.75rem;
    }
    
    .salary-summary-card .card-body {
        padding: 1.25rem !important;
    }
    
    .salary-report-table {
        font-size: 0.85rem;
    }
    
    .salary-report-table thead th,
    .salary-report-table tbody td {
        padding: 0.75rem 0.5rem;
        font-size: 0.85rem;
    }
}
</style>
<?php endif; ?>

<!-- تبويب قائمة الرواتب -->
<div id="listTab" class="tab-content">
    <!-- فلترة -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3" action="<?php echo htmlspecialchars($currentUrl); ?>">
                <input type="hidden" name="page" value="salaries">
                <input type="hidden" name="view" value="list">
                <div class="col-md-3">
                    <label class="form-label">الشهر</label>
                    <select name="month" class="form-select" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">السنة</label>
                    <select name="year" class="form-select" onchange="this.form.submit()">
                        <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">المستخدم</label>
                    <select name="user_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">جميع المستخدمين</option>
                        <?php 
                        require_once __DIR__ . '/../../includes/path_helper.php';
                        $selectedUserIdValid = isValidSelectValue($selectedUserId, $users, 'id');
                        foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserIdValid && $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-info w-100" onclick="showMonthlyReport()">
                        <i class="bi bi-file-earmark-text me-2"></i>تقرير شهري شامل
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة الرواتب -->
    <div class="card shadow-sm">
        <div class="card-header salary-header-gradient text-white">
            <h5 class="mb-0 fw-bold">رواتب <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table table-hover dashboard-table align-middle salary-report-table">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>سعر الساعة</th>
                            <th>عدد الساعات</th>
                            <th>الراتب الأساسي</th>
                            <th>مكافأة</th>
                            <th>خصومات</th>
                            <th>الإجمالي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($salaries)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">لا توجد رواتب مسجلة</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salaries as $salary): ?>
                                <tr>
                                    <td data-label="المستخدم">
                                        <strong><?php echo htmlspecialchars($salary['full_name'] ?? $salary['username']); ?></strong>
                                        <br><small class="text-muted"><?php echo isset($lang['role_' . $salary['role']]) ? $lang['role_' . $salary['role']] : $salary['role']; ?></small>
                                    </td>
                                    <td data-label="سعر الساعة"><?php echo formatCurrency($salary['hourly_rate']); ?></td>
                                    <td data-label="عدد الساعات"><?php echo number_format($salary['total_hours'], 2); ?> ساعة</td>
                                    <td data-label="الراتب الأساسي"><?php echo formatCurrency($salary['base_amount']); ?></td>
                                    <td data-label="مكافأة">
                                        <?php 
                                        $bonusAmount = $salary['bonus'] ?? 0;
                                        if (isset($salary['collections_bonus']) && $salary['collections_bonus'] > 0) {
                                            echo formatCurrency($bonusAmount);
                                            echo '<br><small class="text-info">(2% من التحصيلات: ' . formatCurrency($salary['collections_bonus']) . ')</small>';
                                        } else {
                                            echo formatCurrency($bonusAmount);
                                        }
                                        ?>
                                    </td>
                                    <td data-label="خصومات"><?php echo formatCurrency($salary['deductions'] ?? 0); ?></td>
                                    <td data-label="الإجمالي"><strong><?php echo formatCurrency($salary['total_amount']); ?></strong></td>
                                    <td data-label="الحالة">
                                        <span class="badge bg-<?php 
                                            echo $salary['status'] === 'approved' ? 'success' : 
                                                ($salary['status'] === 'rejected' ? 'danger' : 
                                                ($salary['status'] === 'paid' ? 'info' : 'warning')); 
                                        ?>">
                                            <?php 
                                            $statusLabels = [
                                                'pending' => 'معلق',
                                                'approved' => 'موافق عليه',
                                                'rejected' => 'مرفوض',
                                                'paid' => 'مدفوع'
                                            ];
                                            echo $statusLabels[$salary['status']] ?? ($salary['status'] ?? 'غير محدد');
                                            ?>
                                        </span>
                                    </td>
                                    <td data-label="الإجراءات">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-info" 
                                                    onclick="viewSalaryDetails(<?php echo $salary['id']; ?>)" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#salaryDetailsModal"
                                                    data-salary-id="<?php echo $salary['id']; ?>"
                                                    title="تفاصيل">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-warning" 
                                                    onclick="openModifyModal(<?php echo $salary['id']; ?>, <?php echo htmlspecialchars(json_encode($salary), ENT_QUOTES); ?>)" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#modifySalaryModal"
                                                    title="تعديل">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- تبويب الطلبات المعلقة (للمدير فقط) -->
<?php if ($currentUser['role'] === 'manager' && !empty($pendingModifications)): ?>
<div id="pendingTab" class="tab-content" style="display: <?php echo $view === 'pending' ? 'block' : 'none'; ?>;">
    <div class="card shadow-sm border-warning">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>طلبات تعديل رواتب معلقة (<?php echo count($pendingModifications); ?>)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table dashboard-table--compact align-middle">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>المحاسب</th>
                            <th>التاريخ</th>
                            <th>الملاحظات</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingModifications as $mod): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($mod['full_name'] ?? $mod['username']); ?></strong></td>
                                <td><?php 
                                    $requester = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$mod['requested_by']]);
                                    echo htmlspecialchars($requester['full_name'] ?? $requester['username']);
                                ?></td>
                                <td><?php echo formatDateTime($mod['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($mod['notes'] ?? '-'); ?></td>
                                <td>
                                    <a href="<?php echo $currentUrl; ?>?page=salaries&approval_id=<?php echo $mod['id']; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>&view=pending" 
                                       class="btn btn-sm btn-primary">
                                        <i class="bi bi-eye"></i> مراجعة
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal تفاصيل الراتب -->
<div class="modal fade" id="salaryDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">تفاصيل الراتب</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="salaryDetailsContent">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal تعديل الراتب -->
<div class="modal fade" id="modifySalaryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">تعديل الراتب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="modifySalaryForm">
                <input type="hidden" name="action" value="modify_salary">
                <input type="hidden" name="salary_id" id="modifySalaryId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المستخدم</label>
                        <input type="text" class="form-control" id="modifyUserName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الراتب الأساسي</label>
                        <input type="text" class="form-control" id="modifyBaseAmount" readonly>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modifyBonus" class="form-label">مكافأة</label>
                            <input type="number" step="0.01" class="form-control" id="modifyBonus" name="bonus" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modifyDeductions" class="form-label">خصومات</label>
                            <input type="number" step="0.01" class="form-control" id="modifyDeductions" name="deductions" value="0" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modifyNotes" class="form-label">ملاحظات</label>
                        <textarea class="form-control" id="modifyNotes" name="notes" rows="3" 
                                  placeholder="اذكر سبب التعديل (اختياري)"></textarea>
                    </div>
                    <div class="alert alert-info">
                        <strong>الراتب الجديد:</strong> <span id="newTotalAmount">0.00</span>
                    </div>
                    <?php if ($currentUser['role'] === 'accountant'): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            هذا التعديل يحتاج موافقة من المدير
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle me-2"></i>
                        <?php echo $currentUser['role'] === 'accountant' ? 'إرسال للموافقة' : 'تأكيد التعديل'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($view === 'advances'): ?>
<!-- قسم السلف -->
<div class="card shadow-sm">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>طلبات السلف</h5>
        <?php if ($currentUser['role'] !== 'manager'): ?>
        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#requestAdvanceModal">
            <i class="bi bi-plus-circle me-1"></i>طلب سلفة جديدة
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($advances)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                <h5>لا توجد طلبات سلف</h5>
                <?php if ($currentUser['role'] !== 'manager'): ?>
                <p>يمكنك طلب سلفة جديدة باستخدام الزر أعلاه</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الموظف</th>
                            <th>المبلغ</th>
                            <th>السبب</th>
                            <th>تاريخ الطلب</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advances as $advance): ?>
                            <tr>
                                <td><?php echo $advance['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($advance['user_name']); ?></strong>
                                    <br><small class="text-muted">@<?php echo htmlspecialchars($advance['username']); ?></small>
                                </td>
                                <td>
                                    <strong class="text-success"><?php echo number_format($advance['amount'], 2); ?> ج.م</strong>
                                </td>
                                <td>
                                    <?php if ($advance['reason']): ?>
                                        <small><?php echo htmlspecialchars($advance['reason']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($advance['request_date'])); ?></td>
                                <td>
                                    <?php
                                    $statusBadges = [
                                        'pending' => '<span class="badge bg-warning text-dark">قيد الانتظار</span>',
                                        'accountant_approved' => '<span class="badge bg-info">تم الاستلام</span>',
                                        'manager_approved' => '<span class="badge bg-success">تمت الموافقة</span>',
                                        'rejected' => '<span class="badge bg-danger">مرفوض</span>'
                                    ];
                                    echo $statusBadges[$advance['status']] ?? $advance['status'];
                                    ?>
                                </td>
                                <td>
                                    <?php if ($currentUser['role'] === 'accountant' && $advance['status'] === 'pending'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('هل أنت متأكد من استلام هذا الطلب؟');">
                                            <input type="hidden" name="action" value="accountant_approve_advance">
                                            <input type="hidden" name="advance_id" value="<?php echo $advance['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-info" title="استلام الطلب">
                                                <i class="bi bi-check-circle"></i> استلام
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                                onclick="rejectAdvance(<?php echo $advance['id']; ?>)" title="رفض">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    <?php elseif ($currentUser['role'] === 'manager' && $advance['status'] === 'accountant_approved'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('هل أنت متأكد من الموافقة على هذه السلفة؟');">
                                            <input type="hidden" name="action" value="manager_approve_advance">
                                            <input type="hidden" name="advance_id" value="<?php echo $advance['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success" title="الموافقة">
                                                <i class="bi bi-check-lg"></i> موافقة
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                                onclick="rejectAdvance(<?php echo $advance['id']; ?>)" title="رفض">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="viewAdvanceDetails(<?php echo $advance['id']; ?>)">
                                            <i class="bi bi-eye"></i> عرض
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal طلب سلفة جديدة -->
<div class="modal fade" id="requestAdvanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>طلب سلفة جديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="request_advance">
                <div class="modal-body">
                    <?php if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'manager'): ?>
                    <div class="mb-3">
                        <label class="form-label">الموظف <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" required>
                            <option value="">اختر الموظف</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">مبلغ السلفة (ج.م) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="1" required 
                               placeholder="0.00">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">سبب الطلب (اختياري)</label>
                        <textarea name="reason" class="form-control" rows="3" 
                                  placeholder="اذكر سبب طلب السلفة..."></textarea>
                        <small class="text-muted">يمكنك ترك هذا الحقل فارغاً</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ الطلب</label>
                        <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>ملاحظة:</strong> سيتم خصم مبلغ السلفة من راتبك القادم بعد موافقة المحاسب والمدير.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send me-1"></i>إرسال الطلب
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal رفض السلفة -->
<div class="modal fade" id="rejectAdvanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>رفض طلب السلفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="rejectAdvanceForm">
                <input type="hidden" name="action" value="reject_advance">
                <input type="hidden" name="advance_id" id="rejectAdvanceId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">سبب الرفض</label>
                        <textarea name="rejection_reason" class="form-control" rows="3" 
                                  placeholder="اذكر سبب رفض الطلب..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-1"></i>تأكيد الرفض
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function calculateAllSalaries() {
    if (confirm('هل تريد حساب رواتب جميع المستخدمين للشهر الحالي؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="calculate_all">
            <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
            <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewSalaryDetails(salaryId) {
    const modal = document.getElementById('salaryDetailsModal');
    const content = document.getElementById('salaryDetailsContent');
    
    // تحميل التفاصيل عبر AJAX
    fetch(<?php echo json_encode($currentUrl, JSON_UNESCAPED_SLASHES); ?> + '?page=salaries&ajax=1&id=' + salaryId)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(error => {
            content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
        });
}

function openModifyModal(salaryId, salaryData) {
    document.getElementById('modifySalaryId').value = salaryId;
    document.getElementById('modifyUserName').value = salaryData.full_name || salaryData.username;
    document.getElementById('modifyBaseAmount').value = formatCurrency(salaryData.base_amount || 0);
    document.getElementById('modifyBonus').value = salaryData.bonus || 0;
    document.getElementById('modifyDeductions').value = salaryData.deductions || 0;
    
    calculateNewTotal();
}

function calculateNewTotal() {
    const baseAmount = parseFloat(document.getElementById('modifyBaseAmount').value.replace(/[^\d.]/g, '')) || 0;
    const bonus = parseFloat(document.getElementById('modifyBonus').value) || 0;
    const deductions = parseFloat(document.getElementById('modifyDeductions').value) || 0;
    
    const newTotal = baseAmount + bonus - deductions;
    document.getElementById('newTotalAmount').textContent = formatCurrency(newTotal);
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP',
        minimumFractionDigits: 2
    }).format(amount);
}

function showMonthlyReport() {
    window.location.href = <?php echo json_encode($currentUrl, JSON_UNESCAPED_SLASHES); ?> + '?page=salaries&report=1&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>&view=<?php echo $view; ?>';
}

// حساب الراتب الجديد عند التغيير
document.getElementById('modifyBonus')?.addEventListener('input', calculateNewTotal);
document.getElementById('modifyDeductions')?.addEventListener('input', calculateNewTotal);

// وظائف السلف
function rejectAdvance(advanceId) {
    document.getElementById('rejectAdvanceId').value = advanceId;
    const rejectModal = new bootstrap.Modal(document.getElementById('rejectAdvanceModal'));
    rejectModal.show();
}

function viewAdvanceDetails(advanceId) {
    alert('عرض تفاصيل السلفة #' + advanceId);
    // يمكن إضافة modal لعرض التفاصيل لاحقاً
}
</script>

}
</script>
