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
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireAnyRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
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
$view = isset($_GET['view']) ? $_GET['view'] : ''; // 'calculate' أو 'list' أو 'details'

// تحديد التبويب الافتراضي
if (empty($view)) {
    $view = 'calculate'; // الافتراضي: حساب الرواتب
}

// التحقق من طلب عرض التقرير الشهري
$showReport = isset($_GET['report']) && $_GET['report'] == '1';
$monthlyReport = null;

if ($showReport) {
    $monthlyReport = generateMonthlySalaryReport($selectedMonth, $selectedYear);
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'calculate_salary') {
        $userId = intval($_POST['user_id'] ?? 0);
        $month = intval($_POST['month'] ?? $selectedMonth);
        $year = intval($_POST['year'] ?? $selectedYear);
        $bonus = floatval($_POST['bonus'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($userId <= 0) {
            $error = 'يجب اختيار مستخدم';
        } else {
            $result = createOrUpdateSalary($userId, $month, $year, $bonus, $deductions, $notes);
            
            if ($result['success']) {
                // طلب موافقة
                requestApproval('salary', $result['salary_id'], $currentUser['id'], $notes);
                
                logAudit($currentUser['id'], 'create_salary', 'salary', $result['salary_id'], null, [
                    'user_id' => $userId,
                    'month' => $month,
                    'year' => $year,
                    'amount' => $result['calculation']['total_amount']
                ]);
                
                $success = $result['message'];
                $view = 'list'; // الانتقال إلى قائمة الرواتب بعد الحساب
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'calculate_all') {
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
                         WHERE type = 'salary_modification' AND entity_id = ? AND status = 'pending'",
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
                    foreach ($managers as $manager) {
                        createNotification(
                            $manager['id'],
                            'طلب سلفة يحتاج موافقتك',
                            "طلب سلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م يحتاج موافقتك",
                            'warning',
                            '?page=salaries&view=advances',
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
                } elseif ($advance['status'] !== 'accountant_approved') {
                    $error = 'يجب أن يوافق المحاسب أولاً على هذه السلفة';
                } else {
                    $db->execute(
                        "UPDATE salary_advances 
                         SET status = 'manager_approved', 
                             manager_approved_by = ?, 
                             manager_approved_at = NOW() 
                         WHERE id = ?",
                        [$currentUser['id'], $advanceId]
                    );
                    
                    logAudit($currentUser['id'], 'manager_approve_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount']
                    ]);
                    
                    // إرسال إشعار للموظف
                    createNotification(
                        $advance['user_id'],
                        'تمت الموافقة على طلب السلفة',
                        "تمت الموافقة على طلب السلفة بمبلغ " . number_format($advance['amount'], 2) . " ج.م. سيتم خصمها من راتبك القادم.",
                        'success',
                        null,
                        false
                    );
                    
                    $success = 'تمت الموافقة على السلفة بنجاح. سيتم خصمها من الراتب القادم.';
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
    return isset($salary['role']) ? strtolower($salary['role']) !== 'manager' : true;
}));

// الحصول على طلبات تعديل الرواتب المعلقة (للمدير فقط)
$pendingModifications = [];
if ($currentUser['role'] === 'manager') {
    try {
        $entityColumnCheck = $db->queryOne("SHOW COLUMNS FROM approvals LIKE 'entity_id'");
        $referenceColumnCheck = $db->queryOne("SHOW COLUMNS FROM approvals LIKE 'reference_id'");
        
        $entityColumn = !empty($entityColumnCheck) ? 'entity_id' : (!empty($referenceColumnCheck) ? 'reference_id' : 'entity_id');
        
        if (!in_array($entityColumn, ['entity_id', 'reference_id'])) {
            $entityColumn = 'entity_id';
        }
        
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

require_once __DIR__ . '/../../includes/path_helper.php';
$currentScript = $_SERVER['PHP_SELF'] ?? '/dashboard/accountant.php';
$currentScript = ltrim($currentScript, '/');
if ($currentScript === '' || strpos($currentScript, 'dashboard/') !== 0) {
    $currentScript = 'dashboard/accountant.php';
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
         WHERE s.id = ?",
        [$salaryId]
    );
    
    if ($salary && (!isset($salary['role']) || strtolower($salary['role']) !== 'manager')) {
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
        <form method="GET" class="d-inline">
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
        <a class="nav-link <?php echo $view === 'calculate' ? 'active' : ''; ?>" 
           href="<?php echo htmlspecialchars($buildViewUrl('calculate')); ?>">
            <i class="bi bi-calculator me-2"></i>حساب الرواتب
        </a>
    </li>
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
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>
            تقرير رواتب شهري - <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?>
        </h5>
        <a href="<?php echo $currentUrl; ?>?page=salaries&view=<?php echo $view; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-light btn-sm">
            <i class="bi bi-x-lg"></i> إغلاق التقرير
        </a>
    </div>
    <div class="card-body">
        <!-- ملخص التقرير -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title">عدد الموظفين</h6>
                        <h3 class="mb-0"><?php echo $monthlyReport['total_users']; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title">إجمالي الساعات</h6>
                        <h3 class="mb-0"><?php echo number_format($monthlyReport['total_hours'], 2); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-warning text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title">إجمالي الرواتب</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($monthlyReport['total_amount']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title">متوسط الراتب</h6>
                        <h3 class="mb-0">
                            <?php 
                            $avgSalary = $monthlyReport['total_users'] > 0 
                                ? $monthlyReport['total_amount'] / $monthlyReport['total_users'] 
                                : 0;
                            echo formatCurrency($avgSalary); 
                            ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- جدول الرواتب -->
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المستخدم</th>
                        <th>الدور</th>
                        <th>سعر الساعة</th>
                        <th>عدد الساعات</th>
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
                            <td colspan="11" class="text-center text-muted">لا توجد رواتب</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthlyReport['salaries'] as $index => $salary): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="المستخدم">
                                    <strong><?php echo htmlspecialchars($salary['user_name']); ?></strong>
                                </td>
                                <td data-label="الدور">
                                    <span class="badge bg-secondary"><?php echo $salary['role']; ?></span>
                                </td>
                                <td data-label="سعر الساعة"><?php echo formatCurrency($salary['hourly_rate']); ?></td>
                                <td data-label="عدد الساعات">
                                    <strong><?php echo number_format($salary['total_hours'], 2); ?> ساعة</strong>
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
                        <td colspan="4"></td>
                        <td><strong><?php echo formatCurrency($monthlyReport['total_amount']); ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- تبويب حساب الرواتب -->
<div id="calculateTab" class="tab-content" style="display: <?php echo $view === 'calculate' ? 'block' : 'none'; ?>;">
    <!-- نموذج حساب راتب -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>حساب راتب</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="calculate_salary">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="user_id" class="form-label">المستخدم <span class="text-danger">*</span></label>
                        <select class="form-select" id="user_id" name="user_id" required>
                            <option value="">اختر مستخدم</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" data-hourly-rate="<?php echo $user['hourly_rate']; ?>">
                                    <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?> 
                                    (<?php echo formatCurrency($user['hourly_rate']); ?>/ساعة)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label for="bonus" class="form-label">مكافأة</label>
                        <input type="number" step="0.01" class="form-control" id="bonus" name="bonus" value="0" min="0">
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label for="deductions" class="form-label">خصومات</label>
                        <input type="number" step="0.01" class="form-control" id="deductions" name="deductions" value="0" min="0">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="notes" class="form-label">ملاحظات</label>
                        <input type="text" class="form-control" id="notes" name="notes" placeholder="ملاحظات إضافية">
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-2"></i>حساب الراتب
                    </button>
                    <button type="button" class="btn btn-success" onclick="calculateAllSalaries()">
                        <i class="bi bi-calculator-fill me-2"></i>حساب جميع الرواتب
                    </button>
                    <button type="button" class="btn btn-info" onclick="showMonthlyReport()">
                        <i class="bi bi-file-earmark-text me-2"></i>تقرير شهري شامل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- تبويب قائمة الرواتب -->
<div id="listTab" class="tab-content" style="display: <?php echo $view === 'list' ? 'block' : 'none'; ?>;">
    <!-- فلترة -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
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
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-secondary" onclick="switchTab('calculate')">
                            <i class="bi bi-plus-circle me-2"></i>حساب جديد
                        </button>
                        <button type="button" class="btn btn-info" onclick="showMonthlyReport()">
                            <i class="bi bi-file-earmark-text me-2"></i>تقرير شهري شامل
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة الرواتب -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">رواتب <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
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
        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#requestAdvanceModal">
            <i class="bi bi-plus-circle me-1"></i>طلب سلفة جديدة
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($advances)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                <h5>لا توجد طلبات سلف</h5>
                <p>يمكنك طلب سلفة جديدة باستخدام الزر أعلاه</p>
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
const salaryViewUrls = <?php echo json_encode([
    'calculate' => $buildViewUrl('calculate'),
    'list' => $buildViewUrl('list'),
    'pending' => $buildViewUrl('pending'),
    'advances' => $buildViewUrl('advances'),
], JSON_UNESCAPED_SLASHES); ?>;

function switchTab(tabName) {
    const target = salaryViewUrls[tabName] || salaryViewUrls.calculate;
    window.location.href = target;
}

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
