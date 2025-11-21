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

// إضافة عمود notes في جدول salaries إذا لم يكن موجوداً
$notesColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'notes'");
if (empty($notesColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات' 
            AFTER `updated_at`
        ");
    } catch (Exception $e) {
        error_log("Error adding notes column: " . $e->getMessage());
    }
}

// إضافة أعمدة accumulated_amount و paid_amount في جدول salaries
$accumulatedColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'accumulated_amount'");
if (empty($accumulatedColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `accumulated_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'المبلغ التراكمي' 
            AFTER `total_amount`
        ");
    } catch (Exception $e) {
        error_log("Error adding accumulated_amount column: " . $e->getMessage());
    }
}

$paidAmountColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'paid_amount'");
if (empty($paidAmountColumnCheck)) {
    try {
        $db->execute("
            ALTER TABLE `salaries` 
            ADD COLUMN `paid_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'المبلغ المدفوع' 
            AFTER `accumulated_amount`
        ");
    } catch (Exception $e) {
        error_log("Error adding paid_amount column: " . $e->getMessage());
    }
}

// إنشاء جدول salary_settlements لتسجيل عمليات التسوية
$settlementsTableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_settlements'");
if (empty($settlementsTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `salary_settlements` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `salary_id` int(11) NOT NULL COMMENT 'معرف الراتب',
              `user_id` int(11) NOT NULL COMMENT 'معرف الموظف',
              `settlement_amount` decimal(10,2) NOT NULL COMMENT 'مبلغ التسوية',
              `previous_accumulated` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ التراكمي السابق',
              `remaining_after_settlement` decimal(10,2) DEFAULT 0.00 COMMENT 'المتبقي بعد التسوية',
              `settlement_type` enum('full','partial') DEFAULT 'partial' COMMENT 'نوع التسوية',
              `settlement_date` date NOT NULL COMMENT 'تاريخ التسوية',
              `notes` text DEFAULT NULL COMMENT 'ملاحظات',
              `created_by` int(11) DEFAULT NULL COMMENT 'من قام بالتسوية',
              `invoice_path` varchar(500) DEFAULT NULL COMMENT 'مسار فاتورة PDF',
              `telegram_sent` tinyint(1) DEFAULT 0 COMMENT 'تم الإرسال إلى تليجرام',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `salary_id` (`salary_id`),
              KEY `user_id` (`user_id`),
              KEY `settlement_date` (`settlement_date`),
              KEY `created_by` (`created_by`),
              CONSTRAINT `salary_settlements_ibfk_1` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_settlements_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_settlements_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating salary_settlements table: " . $e->getMessage());
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
            // التحقق من وجود طلب سلفة معلق (pending أو accountant_approved)
            $existingRequest = $db->queryOne(
                "SELECT id FROM salary_advances 
                 WHERE user_id = ? AND status IN ('pending', 'accountant_approved')",
                [$userId]
            );
            
            if ($existingRequest) {
                $error = 'يوجد طلب سلفة معلق بالفعل لهذا الموظف في انتظار الموافقة النهائية';
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
    } elseif ($action === 'settle_salary') {
        // معالجة تسوية مستحقات الموظف
        $salaryId = intval($_POST['salary_id'] ?? 0);
        $settlementAmount = floatval($_POST['settlement_amount'] ?? 0);
        $settlementDate = trim($_POST['settlement_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');
        
        if ($salaryId <= 0) {
            $error = 'معرف الراتب غير صحيح';
        } elseif ($settlementAmount <= 0) {
            $error = 'يجب إدخال مبلغ تسوية صحيح';
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
                // الحصول على المبلغ التراكمي الحالي
                $currentAccumulated = floatval($salary['accumulated_amount'] ?? $salary['total_amount'] ?? 0);
                
                if ($settlementAmount > $currentAccumulated) {
                    $error = 'مبلغ التسوية يتجاوز المبلغ التراكمي المتاح (' . formatCurrency($currentAccumulated) . ')';
                } else {
                    try {
                        $db->getConnection()->beginTransaction();
                        
                        // حساب المتبقي بعد التسوية
                        $remainingAfter = $currentAccumulated - $settlementAmount;
                        $settlementType = ($remainingAfter <= 0.01) ? 'full' : 'partial';
                        
                        // تحديث الراتب: خصم من accumulated_amount وإضافة لـ paid_amount
                        $newPaidAmount = floatval($salary['paid_amount'] ?? 0) + $settlementAmount;
                        $newAccumulated = max(0, $remainingAfter);
                        
                        $db->execute(
                            "UPDATE salaries SET 
                                accumulated_amount = ?,
                                paid_amount = ?
                             WHERE id = ?",
                            [$newAccumulated, $newPaidAmount, $salaryId]
                        );
                        
                        // إنشاء سجل التسوية
                        $db->execute(
                            "INSERT INTO salary_settlements 
                                (salary_id, user_id, settlement_amount, previous_accumulated, 
                                 remaining_after_settlement, settlement_type, settlement_date, 
                                 notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $salaryId,
                                $salary['user_id'],
                                $settlementAmount,
                                $currentAccumulated,
                                $remainingAfter,
                                $settlementType,
                                $settlementDate,
                                $notes,
                                $currentUser['id']
                            ]
                        );
                        
                        $settlementId = $db->getLastInsertId();
                        
                        // إنشاء فاتورة PDF
                        require_once __DIR__ . '/../../includes/invoices.php';
                        $invoicePath = generateSalarySettlementInvoice($settlementId, $salary, $settlementAmount, $currentAccumulated, $remainingAfter, $settlementDate, $notes);
                        
                        // تحديث مسار الفاتورة
                        if ($invoicePath) {
                            $db->execute(
                                "UPDATE salary_settlements SET invoice_path = ? WHERE id = ?",
                                [$invoicePath, $settlementId]
                            );
                        }
                        
                        // إرسال الفاتورة إلى تليجرام
                        require_once __DIR__ . '/../../includes/simple_telegram.php';
                        $telegramSent = sendSalarySettlementToTelegram($settlementId, $salary, $settlementAmount, $currentAccumulated, $remainingAfter, $settlementType, $settlementDate, $invoicePath);
                        
                        if ($telegramSent) {
                            $db->execute(
                                "UPDATE salary_settlements SET telegram_sent = 1 WHERE id = ?",
                                [$settlementId]
                            );
                        }
                        
                        $db->getConnection()->commit();
                        
                        logAudit($currentUser['id'], 'settle_salary', 'salary', $salaryId, null, [
                            'settlement_amount' => $settlementAmount,
                            'previous_accumulated' => $currentAccumulated,
                            'remaining' => $remainingAfter,
                            'settlement_type' => $settlementType
                        ]);
                        
                        $success = 'تم تسوية مستحقات الموظف بنجاح. المبلغ المسدد: ' . formatCurrency($settlementAmount) . 
                                   ($remainingAfter > 0 ? ' | المتبقي: ' . formatCurrency($remainingAfter) : '');
                        
                    } catch (Exception $e) {
                        $db->getConnection()->rollBack();
                        error_log('Error settling salary: ' . $e->getMessage());
                        $error = 'حدث خطأ أثناء تسوية المستحقات: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// الحصول على قائمة المستخدمين (استبعاد المديرين) - فقط الأدوار التي لها رواتب
$usersQuery = "SELECT id, username, full_name, hourly_rate, role 
               FROM users 
               WHERE status = 'active' 
               AND role != 'manager'
               AND role IN ('production', 'accountant', 'sales')";
               
if ($selectedUserId > 0) {
    $usersQuery .= " AND id = " . intval($selectedUserId);
}

$usersQuery .= " ORDER BY 
    CASE role 
        WHEN 'production' THEN 1
        WHEN 'accountant' THEN 2
        WHEN 'sales' THEN 3
        ELSE 4
    END,
    full_name ASC";

$users = $db->query($usersQuery);

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

$salariesFromDb = $db->query(
    "SELECT s.*, u.full_name, u.username, u.role, u.hourly_rate as current_hourly_rate,
            approver.full_name as approver_name
     FROM salaries s
     LEFT JOIN users u ON s.user_id = u.id
     LEFT JOIN users approver ON s.approved_by = approver.id
     $whereClause
     ORDER BY u.full_name ASC",
    $params
);

// إنشاء مصفوفة مرتبة برقم المستخدم للبحث السريع
$salariesMap = [];
foreach ($salariesFromDb as $salary) {
    $userId = intval($salary['user_id'] ?? 0);
    if ($userId > 0) {
        $salariesMap[$userId] = $salary;
    }
}

// دمج جميع المستخدمين مع رواتبهم (أو إنشاء سجل فارغ إذا لم يكن لديهم راتب)
$salaries = [];
foreach ($users as $user) {
    $userId = intval($user['id']);
    if (isset($salariesMap[$userId])) {
        // المستخدم لديه راتب مسجل
        $salaries[] = $salariesMap[$userId];
    } else {
        // المستخدم ليس لديه راتب مسجل - إنشاء سجل فارغ
        $hourlyRate = cleanFinancialValue($user['hourly_rate'] ?? 0);
        $monthHours = calculateMonthlyHours($userId, $selectedMonth, $selectedYear);
        $baseAmount = round($monthHours * $hourlyRate, 2);
        
        // حساب نسبة التحصيلات إذا كان مندوب
        $collectionsAmount = 0;
        $collectionsBonus = 0;
        if ($user['role'] === 'sales') {
            $collectionsAmount = calculateSalesCollections($userId, $selectedMonth, $selectedYear);
            $collectionsBonus = $collectionsAmount * 0.02;
        }
        
        $totalAmount = round($baseAmount + $collectionsBonus, 2);
        
        $salaries[] = [
            'id' => null,
            'user_id' => $userId,
            'full_name' => $user['full_name'] ?? $user['username'],
            'username' => $user['username'],
            'role' => $user['role'],
            'hourly_rate' => $hourlyRate,
            'current_hourly_rate' => $hourlyRate,
            'total_hours' => $monthHours,
            'base_amount' => $baseAmount,
            'bonus' => 0,
            'collections_bonus' => round($collectionsBonus, 2),
            'collections_amount' => $collectionsAmount,
            'deductions' => 0,
            'total_amount' => $totalAmount,
            'status' => 'not_calculated',
            'approved_by' => null,
            'approver_name' => null,
            'created_at' => null,
            'updated_at' => null
        ];
    }
}

// استبعاد المديرين من قائمة الرواتب المعروضة (للأمان)
$salaries = array_values(array_filter($salaries, function ($salary) {
    $role = strtolower($salary['role'] ?? '');
    $hourlyRate = isset($salary['hourly_rate']) ? floatval($salary['hourly_rate']) : (isset($salary['current_hourly_rate']) ? floatval($salary['current_hourly_rate']) : 0);
    return $role !== 'manager';
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700&display=swap');

* {
    box-sizing: border-box;
}

body {
    font-family: 'Tajawal', sans-serif;
}

/* Modern Gradient Header */
.salary-page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 16px;
    margin-bottom: 30px;
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
    font-family: 'Tajawal', sans-serif;
}

.salary-page-header h1 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: white;
    font-family: 'Tajawal', sans-serif;
}

.salary-page-header .header-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.salary-page-header .header-controls .form-select {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-weight: 500;
}

.salary-page-header .header-controls .form-select option {
    background: #667eea;
    color: white;
}

/* Export Buttons */
.btn-export {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.btn-export:hover {
    background: rgba(255, 255, 255, 0.3);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Employee Cards Grid */
.employee-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

@media (max-width: 768px) {
    .employee-cards-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
}

/* Employee Card */
.employee-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.employee-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.employee-card-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f3f4f6;
}

/* Profile Icon (WhatsApp-style) */
.profile-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.profile-icon.production {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}

.profile-icon.accountant {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
}

.profile-icon.sales {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.employee-info {
    flex: 1;
    min-width: 0;
}

.employee-name {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 5px 0;
    font-family: 'Tajawal', sans-serif;
}

/* Role Badge */
.role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 5px;
}

.role-badge.production {
    background: #dbeafe;
    color: #1e40af;
}

.role-badge.accountant {
    background: #cffafe;
    color: #0e7490;
}

.role-badge.sales {
    background: #d1fae5;
    color: #065f46;
}

/* Salary Amount */
.salary-amount {
    font-size: 24px;
    font-weight: 700;
    color: #059669;
    margin: 10px 0;
    font-family: 'Tajawal', sans-serif;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin: 8px 0;
}

.status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.approved {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.paid {
    background: #dbeafe;
    color: #1e40af;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.not_calculated {
    background: #f3f4f6;
    color: #6b7280;
}

/* Legacy status classes for advances section */
.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #d1fae5;
    color: #065f46;
}

.status-accountant {
    background: #dbeafe;
    color: #1e40af;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}

/* View Details Button */
.btn-view-details {
    width: 100%;
    background: #f9fafb;
    color: #374151;
    border: 1px solid #e5e7eb;
    padding: 10px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 15px;
}

.btn-view-details:hover {
    background: #f3f4f6;
    color: #1f2937;
    border-color: #d1d5db;
}

.btn-view-details .arrow-icon {
    transition: transform 0.3s ease;
}

.btn-view-details[aria-expanded="true"] .arrow-icon {
    transform: rotate(180deg);
}

/* Collapsible Details */
.salary-details-collapse {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #f3f4f6;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 14px;
}

.detail-value {
    font-weight: 700;
    color: #1f2937;
    font-size: 15px;
}

.detail-actions {
    display: flex;
    gap: 8px;
    margin-top: 15px;
    flex-wrap: wrap;
}

.detail-actions .btn {
    flex: 1;
    min-width: 80px;
    font-size: 13px;
    padding: 8px 12px;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

/* Summary Cards for Monthly Report */
.salary-summary-card {
    border-radius: 16px;
    transition: all 0.3s ease;
    border: none !important;
}

.salary-summary-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25) !important;
}

.salary-card-blue {
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%) !important;
}

.salary-card-green {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%) !important;
}

.salary-card-yellow {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%) !important;
}

/* Button Styles */
.btn-primary-salary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
}

.btn-primary-salary:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    color: white;
}

/* Advances Section Styling */
.advances-table-wrapper {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    overflow-x: auto;
}

/* Responsive */
@media (max-width: 768px) {
    .salary-page-header {
        padding: 20px;
    }
    
    .salary-page-header h1 {
        font-size: 22px;
    }
    
    .employee-card {
        padding: 15px;
    }
    
    .profile-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .employee-name {
        font-size: 16px;
    }
    
    .salary-amount {
        font-size: 20px;
    }
}

@media (max-width: 576px) {
    .salary-page-header {
        padding: 15px;
    }
    
    .salary-page-header h1 {
        font-size: 18px;
    }
    
    .header-controls {
        width: 100%;
    }
    
    .header-controls .form-select,
    .header-controls .btn-export {
        width: 100%;
        margin-bottom: 8px;
    }
}
</style>

<?php 
$monthName = date('F', mktime(0, 0, 0, $selectedMonth, 1));
$pageTitle = ($view === 'advances') ? 'السلف' : (($view === 'pending') ? 'طلبات معلقة' : 'الرواتب');
?>

<?php if ($view === 'advances'): ?>
    <!-- صفحة السلف - عرض جدول طلبات السلف فقط -->
    <!-- Header للشهر والسنة -->
    <div class="salary-page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h1>السلف - <?php echo htmlspecialchars($monthName); ?> <?php echo $selectedYear; ?></h1>
            <div class="header-controls">
                <a href="<?php echo htmlspecialchars($buildViewUrl('list')); ?>" class="btn btn-export">
                    <i class="bi bi-arrow-right me-2"></i>الرجوع
                </a>
            </div>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" id="errorAlert" data-auto-refresh="true">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" id="successAlert" data-auto-refresh="true">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php else: ?>
<!-- Header للشهر والسنة -->
<div class="salary-page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($monthName); ?> <?php echo $selectedYear; ?></h1>
        <div class="header-controls">
            <form method="GET" class="d-inline" action="<?php echo htmlspecialchars($currentUrl); ?>">
                <input type="hidden" name="page" value="salaries">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <select name="month" class="form-select d-inline" style="width: 140px; max-width: 100%;" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year" class="form-select d-inline ms-2" style="width: 100px; max-width: 100%;" onchange="this.form.submit()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </form>
            <button type="button" class="btn btn-export" onclick="window.print()" title="طباعة">
                <i class="bi bi-printer me-1"></i>طباعة
            </button>
            <button type="button" class="btn btn-export" onclick="exportToPDF()" title="تصدير PDF">
                <i class="bi bi-file-pdf me-1"></i>PDF
            </button>
            <button type="button" class="btn btn-export" onclick="exportToExcel()" title="تصدير Excel">
                <i class="bi bi-file-excel me-1"></i>Excel
            </button>
        </div>
    </div>
</div>

<!-- رسائل النجاح والخطأ -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" id="successAlert" data-auto-refresh="true">
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
<?php endif; ?>

<?php if ($view !== 'advances' && $showReport && $monthlyReport): ?>
<!-- تقرير رواتب شهري -->
<div class="salary-card mb-4">
    <div class="salary-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>
            تقرير رواتب شهري - <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo $currentUrl; ?>?page=salaries&view=<?php echo $view; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-light btn-sm">
                <i class="bi bi-x-lg"></i> إغلاق التقرير
            </a>
        </div>
    </div>
    <div>
        <!-- ملخص التقرير -->
        <div class="row mb-3 g-2">
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-blue text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center">
                        <div class="mb-1">
                            <i class="bi bi-people"></i>
                        </div>
                        <h6 class="card-title mb-1 fw-bold text-uppercase small">عدد الموظفين</h6>
                        <h2 class="mb-0 fw-bold"><?php echo $monthlyReport['total_users']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-green text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center">
                        <div class="mb-1">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <h6 class="card-title mb-1 fw-bold text-uppercase small">إجمالي الساعات</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($monthlyReport['total_hours'], 2); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card salary-summary-card salary-card-yellow text-white h-100 shadow-lg border-0">
                    <div class="card-body text-center">
                        <div class="mb-1">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <h6 class="card-title mb-1 fw-bold text-uppercase small">إجمالي الرواتب</h6>
                        <h2 class="mb-0 fw-bold"><?php echo formatCurrency($monthlyReport['total_amount']); ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- جدول الرواتب -->
        <div class="salary-table-wrapper">
            <table class="table table-hover align-middle">
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
    padding: 1rem !important;
}

.salary-summary-card i {
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
    font-size: 1.5rem !important;
}

.salary-summary-card h2 {
    font-size: 1.25rem;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    margin-bottom: 0.25rem !important;
}

.salary-summary-card h6 {
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    margin-bottom: 0.5rem !important;
}

/* تحسين رأس التقرير - تدرج الأزرق والأبيض */
.salary-header-gradient {
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%) !important;
    border: none;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    color: #ffffff !important;
}

.salary-header-gradient h5,
.salary-header-gradient .text-white {
    color: #ffffff !important;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}

/* تحسين عرض الجدول - تدرج الأزرق والأبيض */
.salary-report-table {
    font-size: 0.8rem;
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    table-layout: auto;
}

.salary-report-table thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 30%, #3b82f6 60%, #60a5fa 100%);
    color: #ffffff;
    font-weight: 700;
    padding: 0.5rem 0.4rem;
    border: none;
    text-align: center;
    vertical-align: middle;
    font-size: 0.7rem;
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
    padding: 0.45rem 0.4rem;
    vertical-align: middle;
    text-align: center;
    border-bottom: 1px solid #e5e7eb;
    background-color: #ffffff;
    transition: all 0.2s ease;
    font-size: 0.75rem;
}

.salary-report-table tbody td .small,
.salary-report-table tbody td small {
    font-size: 0.65rem;
    line-height: 1.2;
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
    font-size: 0.65rem;
    padding: 0.2rem 0.45rem;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    white-space: nowrap;
}

/* تحسين عرض تذييل الجدول - تدرج الأزرق الفاتح والأبيض */
.salary-report-table tfoot {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 50%, #bfdbfe 100%);
}

.salary-report-table tfoot td {
    font-weight: 800;
    font-size: 0.9rem;
    padding: 0.75rem 0.4rem;
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
    font-size: 0.9rem;
    color: #059669 !important;
}

/* تحسين الاستجابة */
@media (max-width: 768px) {
    .salary-summary-card h2 {
        font-size: 1.1rem;
    }
    
    .salary-summary-card .card-body {
        padding: 0.65rem !important;
    }
    
    .salary-summary-card i {
        font-size: 1.25rem !important;
    }
    
    .salary-report-table {
        font-size: 0.7rem;
    }
    
    .salary-report-table thead th,
    .salary-report-table tbody td {
        padding: 0.4rem 0.3rem;
        font-size: 0.7rem;
    }
}
</style>
<?php endif; ?>

<?php if ($view !== 'advances'): ?>
<!-- تبويب قائمة الرواتب -->
<div id="listTab" class="tab-content">
    <!-- فلترة -->
    <div class="filter-card">
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
                <button type="button" class="btn btn-primary-salary w-100" onclick="showMonthlyReport()">
                    <i class="bi bi-file-earmark-text me-2"></i>تقرير شهري شامل
                </button>
            </div>
        </form>
    </div>

    <!-- قائمة الرواتب - Employee Cards -->
    <?php if (empty($salaries)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <h5>لا توجد رواتب مسجلة</h5>
        </div>
    <?php else: ?>
        <div class="employee-cards-grid">
            <?php foreach ($salaries as $salary): ?>
                <?php 
                $hasSalaryId = !empty($salary['id']);
                $roleLabels = [
                    'production' => 'إنتاج',
                    'accountant' => 'محاسب',
                    'sales' => 'مندوب مبيعات'
                ];
                $roleLabel = $roleLabels[$salary['role']] ?? $salary['role'];
                $roleClass = $salary['role'] ?? 'production';
                $employeeName = htmlspecialchars($salary['full_name'] ?? $salary['username']);
                $firstName = mb_substr($employeeName, 0, 1, 'UTF-8');
                $status = $salary['status'] ?? 'not_calculated';
                
                // حساب الإجمالي الصحيح مع تضمين نسبة التحصيلات للمندوبين
                $userId = intval($salary['user_id'] ?? 0);
                $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                $bonus = cleanFinancialValue($salary['bonus'] ?? 0);
                $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
                $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                
                // إذا كان مندوب مبيعات، أعد حساب نسبة التحصيلات
                if ($roleClass === 'sales') {
                    $recalculatedCollectionsAmount = calculateSalesCollections($userId, $selectedMonth, $selectedYear);
                    $recalculatedCollectionsBonus = round($recalculatedCollectionsAmount * 0.02, 2);
                    
                    // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
                    if ($recalculatedCollectionsBonus > $collectionsBonus || $collectionsBonus == 0) {
                        $collectionsBonus = $recalculatedCollectionsBonus;
                    }
                }
                
                // حساب الراتب الإجمالي الصحيح
                $totalAmount = $baseAmount + $bonus + $collectionsBonus - $deductions;
                
                $accumulated = floatval($salary['accumulated_amount'] ?? $totalAmount);
                $paid = floatval($salary['paid_amount'] ?? 0);
                $remaining = max(0, $accumulated - $paid);
                $collapseId = 'collapse_' . ($salary['id'] ?? 'temp_' . uniqid());
                ?>
                <div class="employee-card">
                    <div class="employee-card-header">
                        <div class="profile-icon <?php echo $roleClass; ?>">
                            <?php echo $firstName; ?>
                        </div>
                        <div class="employee-info">
                            <h3 class="employee-name"><?php echo $employeeName; ?></h3>
                            <span class="role-badge <?php echo $roleClass; ?>"><?php echo htmlspecialchars($roleLabel); ?></span>
                        </div>
                    </div>
                    
                    <div class="salary-amount">
                        <?php echo formatCurrency($totalAmount); ?>
                    </div>
                    
                    <div>
                        <span class="status-badge <?php 
                            echo $status === 'approved' ? 'approved' : 
                                ($status === 'rejected' ? 'rejected' : 
                                ($status === 'paid' ? 'paid' : 
                                ($status === 'not_calculated' ? 'not_calculated' : 'pending'))); 
                        ?>">
                            <?php 
                            $statusLabels = [
                                'pending' => 'معلق',
                                'approved' => 'موافق عليه',
                                'rejected' => 'مرفوض',
                                'paid' => 'مدفوع',
                                'not_calculated' => 'غير محسوب'
                            ];
                            echo $statusLabels[$status] ?? 'غير محدد';
                            ?>
                        </span>
                        <?php if ($remaining > 0): ?>
                            <span class="status-badge pending ms-2">
                                متبقي: <?php echo formatCurrency($remaining); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <button class="btn btn-view-details" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
                        <span>عرض التفاصيل</span>
                        <i class="bi bi-chevron-down arrow-icon"></i>
                    </button>
                    
                    <div class="collapse salary-details-collapse" id="<?php echo $collapseId; ?>">
                        <?php
                        // حساب بيانات التأخير
                        $userId = intval($salary['user_id'] ?? 0);
                        $delaySummary = calculateMonthlyDelaySummary($userId, $selectedMonth, $selectedYear);
                        
                        // الحصول على سعر الساعة
                        $hourlyRate = cleanFinancialValue($salary['hourly_rate'] ?? $salary['current_hourly_rate'] ?? 0);
                        $userRole = $salary['role'] ?? 'production';
                        
                        // حساب نسبة التحصيلات - إعادة الحساب دائماً للمندوبين للتأكد من الدقة
                        $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
                        $collectionsAmount = cleanFinancialValue($salary['collections_amount'] ?? 0);
                        
                        // إذا كان مندوب مبيعات، أعد حساب مكافأة التحصيلات من التحصيلات الفعلية
                        if ($userRole === 'sales') {
                            $recalculatedCollectionsAmount = calculateSalesCollections($userId, $selectedMonth, $selectedYear);
                            $recalculatedCollectionsBonus = round($recalculatedCollectionsAmount * 0.02, 2);
                            
                            // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
                            if ($recalculatedCollectionsBonus > $collectionsBonus || $collectionsBonus == 0) {
                                $collectionsBonus = $recalculatedCollectionsBonus;
                                $collectionsAmount = $recalculatedCollectionsAmount;
                            }
                        }
                        
                        // الحصول على القيم المالية
                        $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
                        $bonus = cleanFinancialValue($salary['bonus'] ?? 0);
                        $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
                        $totalSalary = cleanFinancialValue($salary['total_amount'] ?? 0);
                        
                        // حساب الراتب الإجمالي المتوقع مع نسبة التحصيلات
                        $expectedTotalWithCollections = $baseAmount + $bonus + $collectionsBonus - $deductions;
                        
                        // إذا كان الراتب الإجمالي المحفوظ لا يتضمن نسبة التحصيلات، أضفها
                        if ($userRole === 'sales' && abs($totalSalary - $expectedTotalWithCollections) > 0.01) {
                            $totalSalary = $expectedTotalWithCollections;
                        }
                        ?>
                        <div class="detail-row">
                            <span class="detail-label"><?php echo ($userRole === 'sales') ? 'الراتب الشهري' : 'سعر الساعة'; ?>:</span>
                            <span class="detail-value"><?php echo formatCurrency($hourlyRate); ?></span>
                        </div>
                        <?php if ($userRole !== 'sales'): ?>
                        <div class="detail-row">
                            <span class="detail-label">عدد الساعات:</span>
                            <span class="detail-value"><?php echo number_format($salary['total_hours'] ?? 0, 2); ?> ساعة</span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="detail-label">إجمالي التأخير:</span>
                            <span class="detail-value"><?php echo number_format($delaySummary['total_minutes'] ?? 0, 2); ?> دقيقة</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">متوسط التأخير:</span>
                            <span class="detail-value"><?php echo number_format($delaySummary['average_minutes'] ?? 0, 2); ?> دقيقة</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">الراتب الأساسي:</span>
                            <span class="detail-value"><?php echo formatCurrency($baseAmount); ?></span>
                        </div>
                        <?php if ($userRole === 'sales'): ?>
                        <div class="detail-row">
                            <span class="detail-label">نسبة التحصيلات:</span>
                            <span class="detail-value text-info">
                                <?php echo formatCurrency($collectionsBonus); ?>
                                <?php if ($collectionsAmount > 0): ?>
                                    <small class="text-muted d-block" style="font-size: 11px; margin-top: 2px;">
                                        (من <?php echo formatCurrency($collectionsAmount); ?>)
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted d-block" style="font-size: 11px; margin-top: 2px;">
                                        (لا توجد تحصيلات)
                                    </small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="detail-label">المكافآت:</span>
                            <span class="detail-value"><?php echo formatCurrency($bonus); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">الخصومات:</span>
                            <span class="detail-value"><?php echo formatCurrency($deductions); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><strong>الراتب الإجمالي:</strong></span>
                            <span class="detail-value"><strong><?php echo formatCurrency($totalSalary); ?></strong></span>
                        </div>
                        <?php if ($userRole === 'sales' && $collectionsBonus > 0): ?>
                        <div class="detail-row" style="border-bottom: none; padding-top: 8px;">
                            <span class="detail-label" style="font-size: 12px; color: #6b7280; font-weight: normal;">
                                <i class="bi bi-info-circle me-1"></i>
                                ملاحظة: الراتب الإجمالي يتضمن نسبة التحصيلات (<?php echo formatCurrency($collectionsBonus); ?>)
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasSalaryId): ?>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #f3f4f6;">
                        <div class="detail-row">
                            <span class="detail-label">المبلغ التراكمي:</span>
                            <span class="detail-value text-primary"><?php echo formatCurrency($accumulated); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">المبلغ المدفوع:</span>
                            <span class="detail-value text-success"><?php echo formatCurrency($paid); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">المتبقي:</span>
                            <span class="detail-value <?php echo $remaining > 0 ? 'text-warning' : 'text-success'; ?>">
                                <?php echo formatCurrency($remaining); ?>
                            </span>
                        </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($hasSalaryId): ?>
                        <div class="detail-actions">
                            <button class="btn btn-info btn-sm" 
                                    onclick="viewSalaryDetails(<?php echo $salary['id']; ?>)" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#salaryDetailsModal"
                                    data-salary-id="<?php echo $salary['id']; ?>"
                                    title="تفاصيل">
                                <i class="bi bi-eye me-1"></i>عرض
                            </button>
                            <button class="btn btn-warning btn-sm" 
                                    onclick="openModifyModal(<?php echo $salary['id']; ?>, <?php echo htmlspecialchars(json_encode($salary), ENT_QUOTES); ?>)" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#modifySalaryModal"
                                    title="تعديل">
                                <i class="bi bi-pencil me-1"></i>تعديل
                            </button>
                            <?php if ($remaining > 0): ?>
                            <button class="btn btn-success btn-sm" 
                                    onclick="openSettleModal(<?php echo $salary['id']; ?>, <?php echo htmlspecialchars(json_encode($salary), ENT_QUOTES); ?>, <?php echo $remaining; ?>)" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#settleSalaryModal"
                                    title="تسوية مستحقات">
                                <i class="bi bi-cash-coin me-1"></i>تسوية
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- تبويب الطلبات المعلقة (للمدير فقط) -->
<?php if ($view !== 'advances' && $currentUser['role'] === 'manager' && !empty($pendingModifications)): ?>
<div id="pendingTab" class="tab-content" style="display: <?php echo $view === 'pending' ? 'block' : 'none'; ?>;">
    <div class="salary-card">
        <div class="salary-card-header" style="background: #f59e0b;">
            <h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>طلبات تعديل رواتب معلقة (<?php echo count($pendingModifications); ?>)</h5>
        </div>
        <div class="salary-table-wrapper">
            <table class="table align-middle">
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
                                       class="btn btn-sm btn-primary-salary">
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
<!-- قسم السلف - جدول طلبات السلف فقط -->
<?php if (empty($advances)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
        <h5>لا توجد طلبات سلف</h5>
    </div>
<?php else: ?>
    <div class="advances-table-wrapper">
        <table class="table align-middle">
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
                                    $status = $advance['status'] ?? 'pending';
                                    $statusLabels = [
                                        'pending' => 'قيد الانتظار',
                                        'accountant_approved' => 'قيد مراجعة المحاسب',
                                        'manager_approved' => 'تمت الموافقة',
                                        'rejected' => 'مرفوض'
                                    ];
                                    $statusClasses = [
                                        'pending' => 'status-pending',
                                        'accountant_approved' => 'status-accountant',
                                        'manager_approved' => 'status-approved',
                                        'rejected' => 'status-rejected'
                                    ];
                                    $statusLabel = $statusLabels[$status] ?? 'غير معروف';
                                    $statusClass = $statusClasses[$status] ?? 'status-pending';
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
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
// Export Functions
function exportToPDF() {
    // Placeholder for PDF export - can be connected to backend handler
    alert('تصدير PDF - سيتم إضافة هذه الوظيفة قريباً');
    // Example: window.location.href = '<?php echo $currentUrl; ?>?page=salaries&export=pdf&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>';
}

function exportToExcel() {
    // Placeholder for Excel export - can be connected to backend handler
    alert('تصدير Excel - سيتم إضافة هذه الوظيفة قريباً');
    // Example: window.location.href = '<?php echo $currentUrl; ?>?page=salaries&export=excel&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>';
}

// Arrow rotation on collapse toggle
document.addEventListener('DOMContentLoaded', function() {
    const collapseButtons = document.querySelectorAll('.btn-view-details[data-bs-toggle="collapse"]');
    collapseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const arrowIcon = this.querySelector('.arrow-icon');
            if (arrowIcon) {
                // Bootstrap will handle the aria-expanded attribute
                // CSS will handle the rotation based on aria-expanded
            }
        });
    });
});

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

function openSettleModal(salaryId, salaryData, remainingAmount) {
    document.getElementById('settleSalaryId').value = salaryId;
    document.getElementById('settleUserName').textContent = salaryData.full_name || salaryData.username;
    document.getElementById('settleAccumulatedAmount').textContent = formatCurrency(salaryData.accumulated_amount || salaryData.total_amount || 0);
    document.getElementById('settlePaidAmount').textContent = formatCurrency(salaryData.paid_amount || 0);
    document.getElementById('settleRemainingAmount').textContent = formatCurrency(remainingAmount);
    document.getElementById('settleAmount').value = '';
    document.getElementById('settleAmount').max = remainingAmount;
    document.getElementById('settleDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('settleNotes').value = '';
    updateSettleRemaining();
}

function updateSettleRemaining() {
    const remaining = parseFloat(document.getElementById('settleRemainingAmount').textContent.replace(/[^\d.]/g, '')) || 0;
    const settleAmount = parseFloat(document.getElementById('settleAmount').value) || 0;
    const newRemaining = Math.max(0, remaining - settleAmount);
    document.getElementById('settleNewRemaining').textContent = formatCurrency(newRemaining);
    
    const submitBtn = document.getElementById('settleSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = settleAmount <= 0 || settleAmount > remaining;
    }
}
</script>

<!-- Modal تسوية مستحقات الموظف -->
<div class="modal fade" id="settleSalaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تسوية مستحقات موظف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="settleSalaryForm">
                <input type="hidden" name="action" value="settle_salary">
                <input type="hidden" name="salary_id" id="settleSalaryId">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>الموظف:</strong> <span id="settleUserName"></span>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">المبلغ التراكمي</label>
                            <div class="form-control-plaintext fw-bold text-primary" id="settleAccumulatedAmount">0.00</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">المبلغ المدفوع</label>
                            <div class="form-control-plaintext fw-bold text-success" id="settlePaidAmount">0.00</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">المتبقي</label>
                            <div class="form-control-plaintext fw-bold text-warning" id="settleRemainingAmount">0.00</div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label">مبلغ التسوية <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" class="form-control" name="settlement_amount" id="settleAmount" required oninput="updateSettleRemaining()">
                        <small class="text-muted">أقصى مبلغ متاح: <span id="settleRemainingAmount2"></span></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ التسوية <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="settlement_date" id="settleDate" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" id="settleNotes" rows="3" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>المتبقي بعد التسوية:</strong> <span id="settleNewRemaining" class="fw-bold">0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success" id="settleSubmitBtn" disabled>
                        <i class="bi bi-check-circle me-2"></i>تأكيد التسوية
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// تحديث المتبقي عند فتح modal
document.getElementById('settleAmount')?.addEventListener('input', function() {
    updateSettleRemaining();
    const remaining = parseFloat(document.getElementById('settleRemainingAmount').textContent.replace(/[^\d.]/g, '')) || 0;
    document.getElementById('settleRemainingAmount2').textContent = formatCurrency(remaining);
});

// تحديث المتبقي عند فتح modal
document.getElementById('settleSalaryModal')?.addEventListener('shown.bs.modal', function() {
    const remaining = parseFloat(document.getElementById('settleRemainingAmount').textContent.replace(/[^\d.]/g, '')) || 0;
    document.getElementById('settleRemainingAmount2').textContent = formatCurrency(remaining);
});
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>
