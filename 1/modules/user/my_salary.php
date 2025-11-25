<?php
/**
 * صفحة مرتب المستخدم مع طلب السلفة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/salary_calculator.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireLogin();

$currentUser = getCurrentUser();

// تنظيف hourly_rate من currentUser مباشرة عند تحميل الصفحة
if (isset($currentUser['hourly_rate'])) {
    $currentUser['hourly_rate'] = cleanFinancialValue($currentUser['hourly_rate']);
}

// استبعاد المدير - ليس له راتب
if ($currentUser['role'] === 'manager') {
    header('Location: ' . getDashboardUrl('manager'));
    exit;
}

$db = db();
$error = '';
$success = '';

if (!function_exists('ensureSalaryAdvancesTable')) {
    /**
     * التأكد من وجود جدول السلف وإنشائه عند الحاجة.
     */
    function ensureSalaryAdvancesTable($db): bool
    {
        static $salaryAdvanceTableChecked = false;

        if ($salaryAdvanceTableChecked) {
            return true;
        }

        try {
            $tableCheck = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
        } catch (Throwable $checkError) {
            error_log('Salary advances table check failed: ' . $checkError->getMessage());
            return false;
        }

        if (empty($tableCheck)) {
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
            } catch (Throwable $createError) {
                error_log('Failed creating salary_advances table: ' . $createError->getMessage());
                return false;
            }
        }

        $salaryAdvanceTableChecked = true;
        return true;
    }
}

// الحصول على رسالة النجاح من session (بعد redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

// الحصول على الشهر والسنة الحالية
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// معالجة طلب زيادة سعر الساعة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_hourly_rate_increase') {
    $newRate = floatval($_POST['new_rate'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $currentRate = cleanFinancialValue($currentUser['hourly_rate'] ?? 0);
    
    if ($newRate <= 0) {
        $error = 'يجب إدخال سعر ساعة صحيح أكبر من الصفر';
    } elseif ($newRate <= $currentRate) {
        $error = 'السعر الجديد يجب أن يكون أكبر من السعر الحالي (' . formatCurrency($currentRate) . ')';
    } else {
        // إنشاء جدول hourly_rate_requests إذا لم يكن موجوداً
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'hourly_rate_requests'");
        if (empty($tableCheck)) {
            try {
                $db->execute("
                    CREATE TABLE IF NOT EXISTS `hourly_rate_requests` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `user_id` int(11) NOT NULL,
                      `current_rate` decimal(10,2) NOT NULL,
                      `requested_rate` decimal(10,2) NOT NULL,
                      `reason` text DEFAULT NULL,
                      `status` enum('pending','approved','rejected') DEFAULT 'pending',
                      `approved_by` int(11) DEFAULT NULL,
                      `approved_at` timestamp NULL DEFAULT NULL,
                      `rejection_reason` text DEFAULT NULL,
                      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`),
                      KEY `user_id` (`user_id`),
                      KEY `status` (`status`),
                      CONSTRAINT `hourly_rate_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `hourly_rate_requests_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } catch (Exception $e) {
                error_log("Error creating hourly_rate_requests table: " . $e->getMessage());
            }
        }
        
        // التحقق من وجود طلب معلق
        $existingRequest = $db->queryOne(
            "SELECT id FROM hourly_rate_requests 
             WHERE user_id = ? AND status = 'pending'",
            [$currentUser['id']]
        );
        
        if ($existingRequest) {
            $error = 'يوجد طلب زيادة سعر ساعة معلق بالفعل';
        } else {
            // إنشاء طلب زيادة سعر الساعة
            $result = $db->execute(
                "INSERT INTO hourly_rate_requests (user_id, current_rate, requested_rate, reason, status) 
                 VALUES (?, ?, ?, ?, 'pending')",
                [$currentUser['id'], $currentRate, $newRate, $reason ?: null]
            );
            
            $requestId = $result['insert_id'];
            
            // إرسال إشعار للمدير والمحاسب
            $managers = $db->query("SELECT id, role FROM users WHERE role IN ('manager', 'accountant') AND status = 'active'");
            foreach ($managers as $manager) {
                $managerRole = $manager['role'] ?? 'manager'; // قيمة افتراضية
                createNotification(
                    $manager['id'],
                    'طلب زيادة سعر ساعة',
                    'طلب زيادة سعر ساعة من ' . ($currentUser['full_name'] ?? $currentUser['username']) . ' من ' . formatCurrency($currentRate) . ' إلى ' . formatCurrency($newRate),
                    'info',
                    getDashboardUrl($managerRole === 'manager' ? 'manager' : 'accountant') . '?page=salary_settings',
                    false
                );
            }
            
            logAudit($currentUser['id'], 'request_hourly_rate_increase', 'hourly_rate_request', $requestId, null, [
                'current_rate' => $currentRate,
                'requested_rate' => $newRate
            ]);
            
            // منع التكرار باستخدام redirect
            $successMessage = 'تم إرسال طلب زيادة سعر الساعة بنجاح. سيتم مراجعته من قبل المدير.';
            $redirectParams = [
                'page' => 'my_salary',
                'month' => $selectedMonth,
                'year' => $selectedYear
            ];
            preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
        }
    }
}

// معالجة طلب السلفة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_advance') {
    $isAjaxRequest = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || !empty($_POST['is_ajax'])
        || (
            isset($_SERVER['HTTP_ACCEPT']) &&
            stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        )
    );
    $sendAdvanceAjaxResponse = function ($success, $message, $redirect = null) use ($isAjaxRequest) {
        if (!$isAjaxRequest) {
            return false;
        }
        $safetyCounter = 0;
        while (ob_get_level() > 0 && $safetyCounter < 10) {
            if (@ob_end_clean() === false) {
                break;
            }
            $safetyCounter++;
        }

        if (!headers_sent()) {
            http_response_code($success ? 200 : 400);
            header('Content-Type: application/json; charset=utf-8');
        } else {
            error_log('Advance request AJAX response headers were already sent before JSON output.');
        }
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'redirect' => $redirect
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    $amount = floatval($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $month = intval($_POST['month'] ?? $selectedMonth);
    $year = intval($_POST['year'] ?? $selectedYear);
    
    if ($amount <= 0) {
        $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
        $sendAdvanceAjaxResponse(false, $error);
    } else {
        // حساب الراتب الحالي
        $salaryData = getSalarySummary($currentUser['id'], $month, $year);
        
        if (!$salaryData['exists'] && (!isset($salaryData['calculation']) || !$salaryData['calculation']['success'])) {
            $error = 'لا يوجد راتب محسوب لهذا الشهر. يرجى الانتظار حتى يتم حساب الراتب.';
            $sendAdvanceAjaxResponse(false, $error);
        } else {
            $currentSalary = $salaryData['exists'] ? $salaryData['salary']['total_amount'] : $salaryData['calculation']['total_amount'];
            $maxAdvance = $currentSalary * 0.5; // نصف الراتب
            
            if ($amount > $maxAdvance) {
                $error = 'قيمة السلفة لا يمكن أن تتجاوز نصف الراتب الحالي (' . formatCurrency($maxAdvance) . ')';
                $sendAdvanceAjaxResponse(false, $error);
            } else {
                if (!ensureSalaryAdvancesTable($db)) {
                    $error = 'تعذر الوصول إلى جدول السلف. يرجى التواصل مع الإدارة للتأكد من إعداد قاعدة البيانات.';
                    $sendAdvanceAjaxResponse(false, $error);
                } else {
                    // التحقق من وجود طلب سلفة معلق بعد التأكد من الجدول
                    $existingRequest = $db->queryOne(
                        "SELECT id FROM salary_advances 
                         WHERE user_id = ? AND status = 'pending'",
                        [$currentUser['id']]
                    );
                    
                    if ($existingRequest) {
                        $error = 'يوجد طلب سلفة معلق بالفعل';
                        $sendAdvanceAjaxResponse(false, $error);
                    } else {
                        try {
                            // إنشاء طلب السلفة
                            $result = $db->execute(
                                "INSERT INTO salary_advances (user_id, amount, reason, request_date, status) 
                                 VALUES (?, ?, ?, ?, 'pending')",
                                [$currentUser['id'], $amount, $reason ?: null, date('Y-m-d')]
                            );
                            
                            $requestId = $result['insert_id'];
                            
                            // إرسال إشعار للمحاسب
                            $accountants = $db->query("SELECT id FROM users WHERE role = 'accountant' AND status = 'active'");
                            foreach ($accountants as $accountant) {
                                createNotification(
                                    $accountant['id'],
                                    'طلب سلفة جديد',
                                    'طلب سلفة من ' . ($currentUser['full_name'] ?? $currentUser['username']) . ' بقيمة ' . formatCurrency($amount),
                                    'warning',
                                    getDashboardUrl('accountant') . '?page=salaries&view=advances',
                                    false
                                );
                            }
                            
                            logAudit($currentUser['id'], 'request_advance', 'salary_advance', $requestId, null, [
                                'amount' => $amount
                            ]);
                            
                            // منع التكرار باستخدام redirect
                            $successMessage = 'تم إرسال طلب السلفة بنجاح. سيتم مراجعته من قبل المحاسب والمدير.';
                            $redirectParams = [
                                'page' => 'my_salary',
                                'month' => $month,
                                'year' => $year
                            ];
                            $redirectUrl = getDashboardUrl($currentUser['role']) . '?' . http_build_query($redirectParams);
                            
                            // حفظ رسالة النجاح للواجهة الأمامية ولجلسة المستخدم
                            $_SESSION['success_message'] = $successMessage;
                            if ($sendAdvanceAjaxResponse(true, $successMessage, $redirectUrl) === false) {
                                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                            }
                        } catch (Exception $e) {
                            error_log("Salary advance insert error: " . $e->getMessage());
                            
                            // محاولة معالجة الأخطاء الشائعة وتقديم رسالة مفيدة للمستخدم
                            if (stripos($e->getMessage(), 'salary_advances') !== false) {
                                $error = 'تعذر حفظ طلب السلفة بسبب عدم جاهزية قاعدة البيانات. يرجى إبلاغ المحاسب للتأكد من إنشاء جدول السلف.';
                            } else {
                                $error = 'حدث خطأ أثناء حفظ طلب السلفة. يرجى المحاولة مرة أخرى، وإذا استمرت المشكلة تواصل مع الإدارة.';
                            }
                            $sendAdvanceAjaxResponse(false, $error);
                        }
                    }
                }
            }
        }
    }
}

// الحصول على بيانات الراتب الحالي
$salaryData = getSalarySummary($currentUser['id'], $selectedMonth, $selectedYear);

// الحصول على طلبات السلفة من الجدول الموحد salary_advances
$advanceRequests = [];
if (ensureSalaryAdvancesTable($db)) {
    $salaryColumns = [];
    $salaryMonthColumn = null;
    $salaryYearColumn = null;
    $salaryTotalColumn = null;

    try {
        $salaryColumns = $db->query("SHOW COLUMNS FROM salaries");
    } catch (Throwable $salaryColumnsError) {
        error_log('Failed to read salaries columns: ' . $salaryColumnsError->getMessage());
    }

    if (is_array($salaryColumns)) {
        foreach ($salaryColumns as $column) {
            $field = $column['Field'] ?? '';
            if ($field === 'month' || $field === 'salary_month') {
                $salaryMonthColumn = $salaryMonthColumn ?? $field;
            } elseif ($field === 'year' || $field === 'salary_year') {
                $salaryYearColumn = $salaryYearColumn ?? $field;
            } elseif ($field === 'total_amount' || $field === 'amount' || $field === 'net_total') {
                $salaryTotalColumn = $salaryTotalColumn ?? $field;
            }
        }
    }

    $salaryMonthSelect = $salaryMonthColumn
        ? "s.{$salaryMonthColumn} AS deducted_salary_month"
        : "NULL AS deducted_salary_month";

    $salaryYearSelect = $salaryYearColumn
        ? "s.{$salaryYearColumn} AS deducted_salary_year"
        : "NULL AS deducted_salary_year";

    $salaryTotalSelect = $salaryTotalColumn
        ? "s.{$salaryTotalColumn} AS deducted_salary_total"
        : "NULL AS deducted_salary_total";

    $advanceSelectSql = "
        SELECT 
            sa.*,
            accountant.full_name AS accountant_name,
            accountant.username AS accountant_username,
            manager.full_name AS manager_name,
            manager.username AS manager_username,
            {$salaryMonthSelect},
            {$salaryYearSelect},
            {$salaryTotalSelect}
        FROM salary_advances sa
        LEFT JOIN users accountant ON sa.accountant_approved_by = accountant.id
        LEFT JOIN users manager ON sa.manager_approved_by = manager.id
        LEFT JOIN salaries s ON sa.deducted_from_salary_id = s.id
        WHERE sa.user_id = ?
        ORDER BY sa.created_at DESC 
        LIMIT 10";

    $advanceRequests = $db->query($advanceSelectSql, [$currentUser['id']]);
}

// الحصول على طلبات زيادة سعر الساعة
$hourlyRateRequests = [];
$rateTableCheck = $db->queryOne("SHOW TABLES LIKE 'hourly_rate_requests'");
if (!empty($rateTableCheck)) {
    $hourlyRateRequests = $db->query(
        "SELECT * FROM hourly_rate_requests 
         WHERE user_id = ? 
         ORDER BY created_at DESC 
         LIMIT 10",
        [$currentUser['id']]
    );
}

// الحصول على تفاصيل الراتب الشهري (إذا كان محفوظاً)
$salaryDetails = null;
if ($salaryData['exists']) {
    $salaryDetails = $salaryData['salary'];
}

// حساب الراتب الحالي (إذا لم يكن محسوباً، احسبه حتى الآن)
$currentSalary = null;
$maxAdvance = 0;
$isTemporary = false; // هل الراتب محسوب مؤقتاً أم محفوظ في قاعدة البيانات

if ($salaryData['exists']) {
    // الراتب محفوظ في قاعدة البيانات
    $currentSalary = $salaryData['salary'];
    
    // تنظيف جميع القيم المالية من 262145
    if (isset($currentSalary['hourly_rate'])) {
        $currentSalary['hourly_rate'] = cleanFinancialValue($currentSalary['hourly_rate']);
    }
    if (isset($currentSalary['base_amount'])) {
        $currentSalary['base_amount'] = cleanFinancialValue($currentSalary['base_amount']);
    }
    if (isset($currentSalary['total_amount'])) {
        $currentSalary['total_amount'] = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
    }
    if (isset($currentSalary['bonus'])) {
        $currentSalary['bonus'] = cleanFinancialValue($currentSalary['bonus']);
    }
    if (isset($currentSalary['deductions'])) {
        $currentSalary['deductions'] = cleanFinancialValue($currentSalary['deductions']);
    }
    
    $maxAdvance = cleanFinancialValue($currentSalary['total_amount'] * 0.5);
} else if (isset($salaryData['calculation']) && $salaryData['calculation']['success']) {
    // الراتب محسوب مؤقتاً بناءً على الساعات حتى الآن
    $currentSalary = $salaryData['calculation'];
    
    // تنظيف جميع القيم المالية من 262145
    if (isset($currentSalary['hourly_rate'])) {
        $currentSalary['hourly_rate'] = cleanFinancialValue($currentSalary['hourly_rate']);
    }
    
    if (isset($currentSalary['base_amount'])) {
        $currentSalary['base_amount'] = cleanFinancialValue($currentSalary['base_amount']);
    }
    
    if (isset($currentSalary['total_amount'])) {
        $currentSalary['total_amount'] = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
    }
    if (isset($currentSalary['bonus'])) {
        $currentSalary['bonus'] = cleanFinancialValue($currentSalary['bonus']);
    }
    if (isset($currentSalary['deductions'])) {
        $currentSalary['deductions'] = cleanFinancialValue($currentSalary['deductions']);
    }
    
    $maxAdvance = cleanFinancialValue($currentSalary['total_amount'] * 0.5);
    $isTemporary = true;
} else {
    // إذا فشل الحساب، حاول حساب الراتب مباشرة
    $calculation = calculateSalary($currentUser['id'], $selectedMonth, $selectedYear);
    if ($calculation['success']) {
        $currentSalary = $calculation;
        
        // تنظيف جميع القيم المالية من 262145
        if (isset($currentSalary['hourly_rate'])) {
            $currentSalary['hourly_rate'] = cleanFinancialValue($currentSalary['hourly_rate']);
        }
        
        if (isset($currentSalary['base_amount'])) {
            $currentSalary['base_amount'] = cleanFinancialValue($currentSalary['base_amount']);
        }
        
        if (isset($currentSalary['total_amount'])) {
            $currentSalary['total_amount'] = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
        }
        if (isset($currentSalary['bonus'])) {
            $currentSalary['bonus'] = cleanFinancialValue($currentSalary['bonus']);
        }
        if (isset($currentSalary['deductions'])) {
            $currentSalary['deductions'] = cleanFinancialValue($currentSalary['deductions']);
        }
        
        $maxAdvance = cleanFinancialValue($currentSalary['total_amount'] * 0.5);
        $isTemporary = true;
    }
}

// الحصول على إحصائيات الرواتب
$monthStats = [
    'total_hours' => 0,
    'total_salary' => 0,
    'collections_bonus' => 0
];

if ($currentSalary) {
    $monthStats['total_hours'] = $currentSalary['total_hours'] ?? 0;
    $totalSalaryRaw = $currentSalary['total_amount'] ?? 0;
    // تنظيف إضافي للتأكد من إزالة 262145
    $totalSalaryStr = (string)$totalSalaryRaw;
    $totalSalaryStr = str_replace('262145', '', $totalSalaryStr);
    $totalSalaryStr = preg_replace('/\s+/', '', trim($totalSalaryStr));
    $totalSalaryStr = preg_replace('/[^0-9.]/', '', $totalSalaryStr);
    $monthStats['total_salary'] = cleanFinancialValue($totalSalaryStr ?: 0);
    $monthStats['collections_bonus'] = cleanFinancialValue($currentSalary['collections_bonus'] ?? 0);
    $maxAdvance = cleanFinancialValue($monthStats['total_salary'] * 0.5);
} else {
    // إذا لم يكن هناك راتب محفوظ، احسب الساعات مباشرة من attendance_records
    // لضمان أن الساعات معروضة حتى لو لم يتم حساب الراتب بعد
    $monthStats['total_hours'] = calculateMonthlyHours($currentUser['id'], $selectedMonth, $selectedYear);
    $monthStats['total_salary'] = 0;
    $monthStats['collections_bonus'] = 0;
    $maxAdvance = 0;
}

$delaySummary = calculateMonthlyDelaySummary($currentUser['id'], $selectedMonth, $selectedYear);
?>
<?php
$dashboardUrl = getDashboardUrl($currentUser['role']);
require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>
<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap">
    <h2 class="mb-0"><i class="bi bi-wallet2 me-2"></i>مرتبي</h2>
    <div class="d-flex align-items-center gap-3">
        <form method="GET" class="d-inline">
            <select name="month" class="form-select d-inline" style="width: auto;" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select d-inline ms-2" style="width: auto;" onchange="this.form.submit()">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </form>
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-back">
            <i class="bi bi-arrow-right me-2"></i><span><?php echo isset($lang['back']) ? $lang['back'] : 'رجوع'; ?></span>
        </a>
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

<style>
/* تحسينات الأزرار */
.btn-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.btn-gradient-primary:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-gradient-primary:active {
    transform: translateY(0);
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* تحسين تصميم الجداول - محذوف لأننا نستخدم salary-grid الآن */

/* Badge تحسينات */
.badge {
    font-weight: 600;
    padding: 0.5em 1em;
    border-radius: 50px;
    letter-spacing: 0.3px;
}

/* Input Group تحسينات */
.input-group-text {
    border: 1px solid #ced4da;
    transition: all 0.2s ease;
}

.form-control:focus + .input-group-text,
.input-group-text + .form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.form-control-lg {
    border-radius: 0.5rem;
    border: 1px solid #ced4da;
    transition: all 0.2s ease;
}

.form-control-lg:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

/* تحسين زر طلب السلفة */
#advanceRequestForm button[type="submit"]:hover {
    transform: translateY(-3px) !important;
    box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4) !important;
    background: linear-gradient(135deg, #00f2fe 0%, #4facfe 100%) !important;
}

#advanceRequestForm button[type="submit"]:active {
    transform: translateY(-1px) !important;
}

/* Card تحسينات */
.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important;
}

.card-header {
    border-bottom: 3px solid rgba(255,255,255,0.2);
}

/* Alert تحسينات */
.alert {
    border-radius: 0.75rem;
    border-left: 4px solid;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.alert-info {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border-left-color: #2196f3;
}

.alert-warning {
    background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
    border-left-color: #ff9800;
}

/* تحسينات الأيقونات */
.bi {
    vertical-align: middle;
}

/* Tooltip تحسينات */
[data-bs-toggle="tooltip"] {
    cursor: help;
}

/* تحسينات Responsive */
@media (max-width: 768px) {
    .btn-gradient-primary {
        font-size: 0.9rem;
        padding: 0.5rem 1rem;
    }
    
    .badge {
        font-size: 0.75rem;
        padding: 0.4em 0.8em;
    }
    
    .card:hover {
        transform: none;
    }
    
    .input-group-lg .form-control,
    .input-group-lg .input-group-text {
        font-size: 1rem;
    }
    
    .salary-header-title {
        font-size: 16px;
    }
    
    .temp-badge-modern {
        font-size: 11px;
        padding: 3px 10px;
    }
}

@media (max-width: 576px) {
    .salary-header-modern {
        padding: 12px 15px;
    }
    
    .salary-amount {
        font-size: 16px;
    }
    
    .temp-badge-modern {
        display: block;
        margin-top: 8px;
        text-align: center;
    }
}

/* أنيميشن للبطاقات */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card, .salary-details-modern {
    animation: slideIn 0.5s ease-out;
}

.salary-details-modern {
    background: white;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e3e8ef;
    overflow: hidden;
}

.salary-card-inner {
    background: white;
}

.salary-header-modern {
    background: #4169e1;
    padding: 15px 20px;
    color: white;
    border-bottom: 3px solid #2851c7;
}

.salary-header-title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.salary-header-title i {
    font-size: 20px;
}

.temp-badge-modern {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.salary-grid {
    display: grid;
    gap: 0;
}

.salary-row {
    display: grid;
    grid-template-columns: 40% 60%;
    border-bottom: 1px solid #e8ecf3;
    transition: background 0.2s ease;
}

.salary-row:hover {
    background: #f8f9fc;
}

.salary-row.section-header {
    background: #f0f4ff;
    color: #2c5fb3;
    border-bottom: 2px solid #4169e1;
}

.salary-row.section-header:hover {
    background: #e6edff;
}

.salary-row.total-row {
    background: #4169e1;
    color: white;
    font-size: 16px;
    font-weight: 700;
}

.salary-row.total-row:hover {
    background: #3558c9;
}

.salary-row.final-row {
    background: #28a745;
    color: white;
    font-size: 17px;
    font-weight: 700;
    border-bottom: none;
}

.salary-row.final-row:hover {
    background: #218838;
}

.salary-cell {
    padding: 12px 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.salary-cell.label {
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.salary-cell.value {
    text-align: left;
    align-items: flex-start;
}

.section-header .salary-cell {
    font-weight: 700;
    font-size: 14px;
    padding: 10px 18px;
}

.salary-amount {
    font-size: 18px;
    font-weight: 700;
    display: block;
    margin-bottom: 3px;
}

.salary-amount.primary {
    color: #4169e1;
}

.salary-amount.success {
    color: #28a745;
}

.salary-amount.danger {
    color: #dc3545;
}

.salary-amount.white {
    color: white;
}

.salary-amount.warning {
    color: #ffc107;
}

.salary-description {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

.total-row .salary-description,
.final-row .salary-description {
    color: rgba(255, 255, 255, 0.9);
}

.icon-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    margin-left: 8px;
}

.icon-wrapper i {
    font-size: 16px;
}

@media (max-width: 768px) {
    .salary-row {
        grid-template-columns: 1fr;
    }
    
    .salary-cell {
        padding: 10px 15px;
    }
    
    .salary-cell.value {
        border-top: 1px dashed #e8ecf3;
        background: #f9fafb;
    }
    
    .salary-row.total-row .salary-cell.value,
    .salary-row.final-row .salary-cell.value {
        border-top: none;
        background: transparent;
    }
    
    .salary-amount {
        font-size: 16px;
    }
    
    .salary-header-title {
        font-size: 16px;
    }
    
    .salary-header-modern {
        padding: 12px 15px;
    }
}

.alert-modern {
    background: #fff8e1;
    border: 1px solid #ffe082;
    border-radius: 8px;
    padding: 12px 18px;
    margin: 15px;
    border-right: 4px solid #ffc107;
}

.alert-modern i {
    font-size: 18px;
    color: #ff9800;
}

.salary-row:nth-child(even):not(.section-header):not(.total-row):not(.final-row) {
    background: #fafbfc;
}

.salary-row:last-child:not(.final-row) {
    border-bottom: none;
}

/* سجل طلبات السلف */
.advance-history-row {
    margin-top: 1rem;
}

.advance-history-row:first-of-type {
    margin-top: 0;
}

.advance-request-card {
    border-radius: 20px;
    border: 1px solid #e4e9f2;
    background: #ffffff;
    padding: 1.1rem 1.25rem;
    box-shadow: 0 18px 36px rgba(15, 42, 91, 0.08);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    position: relative;
}

.advance-request-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 22px 40px rgba(15, 42, 91, 0.12);
}

.advance-card--pending {
    border-left: 6px solid #f0ad4e;
}

.advance-card--accountant {
    border-left: 6px solid #0dcaf0;
}

.advance-card--approved {
    border-left: 6px solid #198754;
}

.advance-card--rejected {
    border-left: 6px solid #dc3545;
}

.advance-request-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.advance-request-card__title {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.advance-request-card__number {
    font-size: 1.05rem;
    font-weight: 700;
    color: #1b1f3b;
}

.advance-request-card__date {
    font-size: 0.9rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.advance-request-card__status {
    font-weight: 600;
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.82rem;
    letter-spacing: 0.3px;
}

.advance-request-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}

.advance-request-card__meta-item {
    min-width: 150px;
}

.advance-request-card__meta-item strong {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 600;
    letter-spacing: 0.3px;
}

.advance-request-card__meta-item span {
    display: block;
    margin-top: 0.3rem;
    font-size: 1rem;
    font-weight: 600;
    color: #1b1f3b;
}

.advance-request-card__hint {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 0.75rem;
}

.advance-request-card__reason {
    background: linear-gradient(135deg, rgba(102,126,234,0.08) 0%, rgba(118,75,162,0.08) 100%);
    border-radius: 16px;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    color: #3d4465;
    margin-bottom: 0.75rem;
    line-height: 1.6;
}

.advance-request-card__reason.is-rejected {
    background: linear-gradient(135deg, rgba(220,53,69,0.12) 0%, rgba(255,0,0,0.05) 100%);
    color: #a71d2a;
}

.advance-request-card__deduction {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.9rem;
    color: #495057;
    margin-bottom: 0.85rem;
}

.advance-timeline {
    list-style: none;
    margin: 0;
    padding: 0 0 0 1.1rem;
    border-left: 2px solid #e3e7f3;
}

.advance-timeline__item {
    position: relative;
    padding-left: 0.6rem;
    margin-bottom: 0.75rem;
}

.advance-timeline__item::before {
    content: '';
    position: absolute;
    left: -1.05rem;
    top: 0.2rem;
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 50%;
    background: #adb5bd;
    border: 3px solid #fff;
    box-shadow: 0 0 0 3px rgba(173, 181, 189, 0.2);
}

.advance-timeline__item.is-done::before {
    background: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.18);
}

.advance-timeline__item.is-warning::before {
    background: #f0ad4e;
    box-shadow: 0 0 0 3px rgba(240, 173, 78, 0.2);
}

.advance-timeline__item.is-pending::before {
    background: #ced4da;
    box-shadow: 0 0 0 3px rgba(206, 212, 218, 0.25);
}

.advance-timeline__item.is-success::before {
    background: #198754;
    box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.2);
}

.advance-timeline__item.is-danger::before {
    background: #dc3545;
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.22);
}

.advance-timeline__title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #1b1f3b;
    display: block;
}

.advance-timeline__meta {
    display: block;
    font-size: 0.78rem;
    color: #72809d;
    margin-top: 0.25rem;
}

.advance-timeline__description {
    font-size: 0.85rem;
    color: #5a607f;
    margin-top: 0.35rem;
    line-height: 1.5;
}

.advance-empty-state {
    text-align: center;
    padding: 2.25rem 1.5rem;
    border: 2px dashed #d7dcec;
    border-radius: 18px;
    background: #f8f9fc;
    color: #6c757d;
}

.advance-empty-state i {
    font-size: 2.5rem;
    margin-bottom: 0.85rem;
    display: block;
    color: #adb5bd;
}

.advance-empty-state small {
    display: block;
    margin-top: 0.4rem;
    font-size: 0.8rem;
    color: #9499b7;
}

@media (max-width: 768px) {
    .advance-request-card {
        padding: 1rem;
    }

    .advance-request-card__header {
        flex-direction: column;
        align-items: flex-start;
    }

    .advance-request-card__status {
        align-self: flex-start;
    }

    .advance-request-card__meta {
        flex-direction: column;
        gap: 0.75rem;
    }

    .advance-timeline {
        padding-left: 1rem;
    }

    .advance-timeline__item::before {
        left: -0.95rem;
    }
}
</style>

<div class="salary-details-modern">
    <div class="salary-card-inner">
        <!-- Header -->
        <div class="salary-header-modern">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="salary-header-title mb-0">
                        <i class="bi bi-wallet2"></i>
                        تفاصيل الراتب - <?php echo date('F', mktime(0, 0, 0, $selectedMonth, 1)); ?> <?php echo $selectedYear; ?>
                    </h2>
            <?php if ($isTemporary): ?>
                    <span class="temp-badge-modern">
                        <i class="bi bi-clock-history me-1"></i>حساب مؤقت
                    </span>
            <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($isTemporary): ?>
            <div class="alert-modern">
                <i class="bi bi-info-circle me-2"></i>
                <strong>ملاحظة:</strong> هذا الراتب محسوب بناءً على الساعات المسجلة حتى الآن. سيتم تحديثه تلقائياً عند انتهاء الشهر.
            </div>
        <?php endif; ?>
        
        <?php if (!$currentSalary): ?>
            <div class="alert-modern">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>ملاحظة:</strong> لا توجد ساعات عمل مسجلة لهذا الشهر حتى الآن.
            </div>
        <?php endif; ?>

        <!-- Salary Grid -->
        <div class="salary-grid">
            <!-- تفاصيل الراتب - Header -->
            <div class="salary-row section-header">
                <div class="salary-cell" style="grid-column: 1 / -1;">
                    <span class="icon-wrapper">
                        <i class="bi bi-calculator"></i>
                    </span>
                    تفاصيل الراتب
                </div>
            </div>

            <!-- سعر الساعة -->
            <div class="salary-row">
                <div class="salary-cell label">
                    سعر الساعة
                </div>
                <div class="salary-cell value">
                            <?php 
                            $hourlyRateRaw = $currentSalary['hourly_rate'] ?? $currentUser['hourly_rate'] ?? 0;
                    $hourlyRate = cleanFinancialValue($hourlyRateRaw);
                    ?>
                    <span class="salary-amount primary"><?php echo formatCurrency($hourlyRate); ?></span>
                    <span class="salary-description">المعدل بالساعة</span>
                </div>
            </div>

            <!-- عدد الساعات -->
            <div class="salary-row">
                <div class="salary-cell label">
                    عدد الساعات
                </div>
                <div class="salary-cell value">
                    <span class="salary-amount primary"><?php echo number_format($monthStats['total_hours'], 2); ?> ساعة</span>
                    <span class="salary-description">إجمالي ساعات العمل</span>
                </div>
            </div>

            <!-- إجمالي التأخير -->
            <div class="salary-row">
                <div class="salary-cell label">
                    إجمالي التأخير
                </div>
                <div class="salary-cell value">
                    <span class="salary-amount danger"><?php echo number_format($delaySummary['total_minutes'] ?? 0, 2); ?> دقيقة</span>
                    <span class="salary-description">بحسب أول تسجيل حضور يومي</span>
                </div>
            </div>

            <!-- متوسط التأخير -->
            <div class="salary-row">
                <div class="salary-cell label">
                    متوسط التأخير
                </div>
                <div class="salary-cell value">
                    <span class="salary-amount warning"><?php echo number_format($delaySummary['average_minutes'] ?? 0, 2); ?> دقيقة</span>
                    <span class="salary-description">
                        <?php echo (int) ($delaySummary['delay_days'] ?? 0); ?> يوم تأخير من أصل <?php echo (int) ($delaySummary['attendance_days'] ?? 0); ?> يوم حضور
                    </span>
                </div>
            </div>

            <!-- الراتب الأساسي -->
            <div class="salary-row">
                <div class="salary-cell label">
                    الراتب الأساسي
                </div>
                <div class="salary-cell value">
                            <?php 
                            $baseAmountRaw = $currentSalary['base_amount'] ?? 0;
                    $baseAmount = cleanFinancialValue($baseAmountRaw);
                    ?>
                    <span class="salary-amount success"><?php echo formatCurrency($baseAmount); ?></span>
                    <span class="salary-description">الساعات × سعر الساعة</span>
                </div>
            </div>
            
            <!-- نسبة التحصيلات للمندوبين -->
                    <?php if ($currentUser['role'] === 'sales' && isset($currentSalary['collections_bonus']) && $currentSalary['collections_bonus'] > 0): ?>
            <div class="salary-row">
                <div class="salary-cell label">
                    نسبة التحصيلات (2%)
                </div>
                <div class="salary-cell value">
                    <span class="salary-amount success">+<?php echo formatCurrency($currentSalary['collections_bonus']); ?></span>
                    <span class="salary-description">مكافأة من التحصيلات</span>
                </div>
            </div>
                    <?php endif; ?>
                    
            <!-- المكافآت - Header -->
            <div class="salary-row section-header">
                <div class="salary-cell" style="grid-column: 1 / -1;">
                    <span class="icon-wrapper">
                        <i class="bi bi-gift"></i>
                    </span>
                    المكافآت
                </div>
            </div>

            <!-- مكافأة إضافية -->
            <div class="salary-row">
                <div class="salary-cell label">
                    مكافأة إضافية
                </div>
                <div class="salary-cell value">
                    <?php if (isset($currentSalary['bonus']) && $currentSalary['bonus'] > 0): ?>
                        <span class="salary-amount success">+<?php echo formatCurrency($currentSalary['bonus']); ?></span>
                        <span class="salary-description"><?php echo htmlspecialchars($salaryDetails['notes'] ?? 'مكافأة من المدير أو المحاسب'); ?></span>
                    <?php else: ?>
                        <span class="salary-amount">0.00 ج.م</span>
                        <span class="salary-description">لا توجد مكافآت</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- الخصومات - Header -->
            <div class="salary-row section-header">
                <div class="salary-cell" style="grid-column: 1 / -1;">
                    <span class="icon-wrapper">
                        <i class="bi bi-dash-circle"></i>
                    </span>
                    الخصومات
                </div>
            </div>

            <!-- خصومات -->
            <div class="salary-row">
                <div class="salary-cell label">
                    خصومات
                </div>
                <div class="salary-cell value">
                    <?php if (isset($currentSalary['deductions']) && $currentSalary['deductions'] > 0): ?>
                        <span class="salary-amount danger">-<?php echo formatCurrency($currentSalary['deductions']); ?></span>
                        <span class="salary-description"><?php echo htmlspecialchars($salaryDetails['notes'] ?? 'خصومات من المدير أو المحاسب'); ?></span>
                    <?php else: ?>
                        <span class="salary-amount">0.00 ج.م</span>
                        <span class="salary-description">لا توجد خصومات</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- قسم طلب سلفة جديدة داخل الجدول -->
            <?php 
            $maxAdvanceAmount = $maxAdvance > 0 ? $maxAdvance : 0;
            $maxNumeric = number_format($maxAdvanceAmount, 2, '.', '');
            ?>
            
            <!-- Header طلب سلفة -->
            <div class="salary-row section-header" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); margin-top: 20px;">
                <div class="salary-cell" style="grid-column: 1 / -1;">
                    <span class="icon-wrapper">
                        <i class="bi bi-cash-coin"></i>
                    </span>
                    طلب سلفة جديد
                    <?php if ($maxAdvanceAmount > 0): ?>
                        <span class="badge bg-light text-dark ms-2" style="font-size: 0.85em;">
                            الحد الأقصى: <?php echo formatCurrency($maxAdvanceAmount); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($maxAdvanceAmount <= 0): ?>
            <!-- تنبيه عدم توفر سلفة -->
            <div class="salary-row" style="background: #fff8e1; border-right: 4px solid #ffc107;">
                <div class="salary-cell" style="grid-column: 1 / -1; padding: 20px;">
                    <div class="text-center">
                        <i class="bi bi-info-circle-fill" style="font-size: 2.5em; color: #ff9800;"></i>
                        <p class="mb-0 mt-3" style="color: #856404; font-size: 1rem; font-weight: 500;">
                            <strong>تنبيه:</strong> لا توجد سلفة متاحة حالياً. بمجرد حساب الراتب ستظهر الحدود القصوى ويمكنك إرسال الطلب.
                        </p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            
            <!-- نموذج طلب السلفة -->
            <div class="salary-row" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                <div class="salary-cell" style="grid-column: 1 / -1; padding: 25px;">
                    <form method="POST" id="advanceRequestForm" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="request_advance">
                        <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                        <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">

                        <div id="advanceAlertContainer"></div>

                        <div class="row g-3">
                            <!-- مبلغ السلفة -->
                            <div class="col-md-6">
                                <label for="amount" class="form-label fw-bold mb-2" style="color: #2d3748; font-size: 0.95rem;">
                                    <i class="bi bi-currency-exchange me-2" style="color: #4facfe;"></i>
                                    مبلغ السلفة <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border: none;">
                                        <i class="bi bi-cash-stack text-white"></i>
                                    </span>
                                    <input type="number" 
                                           step="0.01" 
                                           class="form-control" 
                                           id="amount" 
                                           name="amount" 
                                           min="0.01"
                                           max="<?php echo $maxNumeric; ?>"
                                           required 
                                           placeholder="أدخل المبلغ"
                                           style="border-color: #4facfe; font-size: 1rem; padding: 0.65rem;">
                                    <span class="input-group-text" style="background: #fff; border-color: #4facfe; font-weight: 600; color: #4facfe;">ج.م</span>
                                </div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.8rem;">
                                    <i class="bi bi-info-circle me-1"></i>
                                    سيتم خصم المبلغ من راتبك القادم بعد الموافقة
                                </small>
                            </div>

                            <!-- سبب الطلب -->
                            <div class="col-md-6">
                                <label for="reason" class="form-label fw-bold mb-2" style="color: #2d3748; font-size: 0.95rem;">
                                    <i class="bi bi-chat-text me-2" style="color: #4facfe;"></i>
                                    سبب الطلب <span class="text-muted" style="font-weight: normal;">(اختياري)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border: none;">
                                        <i class="bi bi-pencil text-white"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control" 
                                           id="reason" 
                                           name="reason" 
                                           placeholder="اذكر سبب طلب السلفة"
                                           style="border-color: #4facfe; font-size: 1rem; padding: 0.65rem;">
                                </div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.8rem;">
                                    <i class="bi bi-info-circle me-1"></i>
                                    يمكنك ترك هذا الحقل فارغاً
                                </small>
                            </div>
                        </div>

                        <!-- زر الإرسال -->
                        <div class="text-center mt-3">
                            <button type="submit" 
                                    class="btn text-white shadow-sm" 
                                    style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); padding: 12px 40px; border-radius: 8px; font-size: 1rem; font-weight: 600; border: none; transition: all 0.3s ease;">
                                <i class="bi bi-send-fill me-2"></i>إرسال طلب السلفة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
                    
                    <!-- الراتب الإجمالي -->
            <div class="salary-row total-row" style="margin-top: 20px;">
                <div class="salary-cell label">
                    <strong>الراتب الإجمالي</strong>
                </div>
                <div class="salary-cell value">
                    <?php $totalSalary = cleanFinancialValue($monthStats['total_salary'] ?? 0); ?>
                    <span class="salary-amount white"><?php echo formatCurrency($totalSalary); ?></span>
                    <span class="salary-description">إجمالي الراتب الشهري</span>
                </div>
            </div>


            <!-- الراتب النهائي المستحق -->
                    <?php 
                    $totalApprovedAdvances = 0;
            if (!empty($advanceRequests)) {
                foreach ($advanceRequests as $advance) {
                        if ($advance['status'] === 'approved' && $advance['requested_month'] == $selectedMonth && $advance['requested_year'] == $selectedYear) {
                            $totalApprovedAdvances += $advance['amount'];
                    }
                }
            }
            ?>
            
                    <?php if ($totalApprovedAdvances > 0): ?>
            <!-- السلفات المخصومة -->
            <div class="salary-row">
                <div class="salary-cell label">
                    السلفات المعتمدة
                </div>
                <div class="salary-cell value">
                    <span class="salary-amount danger">-<?php echo formatCurrency($totalApprovedAdvances); ?></span>
                    <span class="salary-description">تم خصمها من الراتب</span>
                </div>
            </div>

            <!-- الراتب النهائي -->
            <div class="salary-row final-row">
                <div class="salary-cell label">
                    <strong>الراتب النهائي المستحق</strong>
        </div>
                <div class="salary-cell value">
                    <span class="salary-amount white"><?php echo formatCurrency($monthStats['total_salary'] - $totalApprovedAdvances); ?></span>
                    <span class="salary-description">بعد خصم السلفات</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- قسم طلبات السلف -->
            <?php if (!empty($advanceRequests)): ?>
            <div class="salary-row section-header">
                <div class="salary-cell" style="grid-column: 1 / -1;">
                    <span class="icon-wrapper">
                        <i class="bi bi-list-check"></i>
                    </span>
                    سجل طلبات السلف (آخر 10 طلبات)
                    <small class="text-muted d-block mt-1">يتم عرض أحدث 10 طلبات سلفة لسهولة المتابعة.</small>
                </div>
            </div>

            <?php
            $formatUserName = function ($fullName, $username) {
                if (!empty($fullName)) {
                    return $fullName;
                }
                if (!empty($username)) {
                    return $username;
                }
                return null;
            };

            $formatDateValue = function ($value, $format = 'Y-m-d H:i') {
                if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
                    return null;
                }
                $timestamp = strtotime($value);
                if ($timestamp === false) {
                    return null;
                }
                return date($format, $timestamp);
            };

            $statusThemes = [
                'pending' => [
                    'label' => 'قيد الانتظار',
                    'hint' => 'بانتظار مراجعة المحاسب لاعتماد الطلب.',
                    'card_class' => 'advance-card--pending',
                    'badge_class' => 'bg-warning text-dark',
                    'icon' => 'hourglass-split'
                ],
                'accountant_approved' => [
                    'label' => 'تمت مراجعته من المحاسب',
                    'hint' => 'بانتظار موافقة المدير النهائية.',
                    'card_class' => 'advance-card--accountant',
                    'badge_class' => 'bg-info text-dark',
                    'icon' => 'clipboard-check'
                ],
                'manager_approved' => [
                    'label' => 'تمت الموافقة النهائية',
                    'hint' => 'سيتم خصم السلفة من الراتب المحدد عند توفره.',
                    'card_class' => 'advance-card--approved',
                    'badge_class' => 'bg-success text-white',
                    'icon' => 'check-circle-fill'
                ],
                'rejected' => [
                    'label' => 'مرفوض',
                    'hint' => 'راجع سبب الرفض قبل إرسال طلب جديد.',
                    'card_class' => 'advance-card--rejected',
                    'badge_class' => 'bg-danger text-white',
                    'icon' => 'x-octagon-fill'
                ]
            ];

            $requesterName = $formatUserName($currentUser['full_name'] ?? null, $currentUser['username'] ?? null);
            $counter = 1;

            foreach ($advanceRequests as $request):
                $statusKey = $request['status'] ?? 'pending';
                $theme = $statusThemes[$statusKey] ?? $statusThemes['pending'];

                $createdAtFormatted = $formatDateValue($request['created_at'] ?? null);
                $requestDateFormatted = $formatDateValue($request['request_date'] ?? null, 'Y-m-d');
                $accountantApprovedAt = $formatDateValue($request['accountant_approved_at'] ?? null);
                $managerApprovedAt = $formatDateValue($request['manager_approved_at'] ?? null);

                $accountantName = $formatUserName($request['accountant_name'] ?? null, $request['accountant_username'] ?? null);
                $managerName = $formatUserName($request['manager_name'] ?? null, $request['manager_username'] ?? null);

                $reasonText = 'لم يتم تقديم سبب للطلب.';
                $reasonIsRejection = false;
                if ($statusKey === 'rejected' && !empty($request['notes'])) {
                    $reasonText = 'سبب الرفض: ' . $request['notes'];
                    $reasonIsRejection = true;
                } elseif (!empty($request['reason'])) {
                    $reasonText = $request['reason'];
                }

                $deductionSummary = '';
                if (!empty($request['deducted_from_salary_id'])) {
                    if (!empty($request['deducted_salary_month']) && !empty($request['deducted_salary_year'])) {
                        $deductionSummary = 'تم خصم السلفة من راتب شهر ' .
                            sprintf('%02d', (int)$request['deducted_salary_month']) . '/' . (int)$request['deducted_salary_year'];
                        if (!empty($request['deducted_salary_total'])) {
                            $deductionSummary .= ' - إجمالي الراتب: ' . formatCurrency($request['deducted_salary_total']);
                        }
                    } else {
                        $deductionSummary = 'تم خصم السلفة ضمن الراتب رقم #' . (int)$request['deducted_from_salary_id'];
                    }
                }

                $timelineSteps = [];
                $timelineSteps[] = [
                    'title' => 'تقديم الطلب',
                    'meta' => $createdAtFormatted,
                    'description' => $requesterName ? 'بواسطة ' . $requesterName : '',
                    'state' => 'is-done'
                ];

                if (!empty($accountantApprovedAt) || in_array($statusKey, ['accountant_approved', 'manager_approved', 'rejected'], true)) {
                    $timelineSteps[] = [
                        'title' => 'اعتماد المحاسب',
                        'meta' => $accountantApprovedAt,
                        'description' => $accountantName ? 'بواسطة ' . $accountantName : '',
                        'state' => $statusKey === 'pending' ? 'is-pending' : (in_array($statusKey, ['manager_approved'], true) ? 'is-success' : 'is-warning')
                    ];
                } else {
                    $timelineSteps[] = [
                        'title' => 'قيد مراجعة المحاسب',
                        'meta' => null,
                        'description' => 'بانتظار اعتماد المحاسب.',
                        'state' => 'is-warning'
                    ];
                }

                if ($statusKey === 'manager_approved') {
                    $timelineSteps[] = [
                        'title' => 'اعتماد المدير',
                        'meta' => $managerApprovedAt,
                        'description' => $managerName ? 'بواسطة ' . $managerName : '',
                        'state' => 'is-success'
                    ];
                } elseif ($statusKey === 'rejected') {
                    $timelineSteps[] = [
                        'title' => 'تم رفض الطلب',
                        'meta' => $managerApprovedAt ?? $accountantApprovedAt,
                        'description' => !empty($request['notes']) ? 'سبب الرفض: ' . $request['notes'] : 'تم رفض الطلب من قبل الإدارة.',
                        'state' => 'is-danger'
                    ];
                }
            ?>
            <div class="salary-row advance-history-row">
                <div class="salary-cell" style="grid-column: 1 / -1; padding: 0;">
                    <article class="advance-request-card <?php echo $theme['card_class']; ?>">
                        <header class="advance-request-card__header">
                            <div class="advance-request-card__title">
                                <span class="advance-request-card__number">طلب #<?php echo $counter++; ?></span>
                                <?php if ($createdAtFormatted): ?>
                                <span class="advance-request-card__date">
                                    <i class="bi bi-calendar-event me-1"></i><?php echo htmlspecialchars($createdAtFormatted); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span class="advance-request-card__status badge <?php echo $theme['badge_class']; ?>">
                                <i class="bi bi-<?php echo $theme['icon']; ?> me-1"></i><?php echo $theme['label']; ?>
                            </span>
                        </header>

                        <div class="advance-request-card__meta">
                            <div class="advance-request-card__meta-item">
                                <strong>قيمة السلفة</strong>
                                <span><?php echo formatCurrency($request['amount']); ?></span>
                            </div>
                            <div class="advance-request-card__meta-item">
                                <strong>تاريخ الطلب</strong>
                                <span><?php echo $requestDateFormatted ? htmlspecialchars($requestDateFormatted) : '—'; ?></span>
                            </div>
                            <div class="advance-request-card__meta-item">
                                <strong>رقم الطلب</strong>
                                <span>#<?php echo (int)$request['id']; ?></span>
                            </div>
                        </div>

                        <div class="advance-request-card__hint">
                            <?php echo htmlspecialchars($theme['hint']); ?>
                        </div>

                        <div class="advance-request-card__reason<?php echo $reasonIsRejection ? ' is-rejected' : ''; ?>">
                            <?php echo htmlspecialchars($reasonText); ?>
                        </div>

                        <?php if (!empty($deductionSummary)): ?>
                        <div class="advance-request-card__deduction">
                            <i class="bi bi-receipt-cutoff me-2"></i>
                            <span><?php echo htmlspecialchars($deductionSummary); ?></span>
                        </div>
                        <?php endif; ?>

                        <ul class="advance-timeline">
                            <?php foreach ($timelineSteps as $step): ?>
                            <li class="advance-timeline__item <?php echo $step['state']; ?>">
                                <span class="advance-timeline__title"><?php echo htmlspecialchars($step['title']); ?></span>
                                <?php if (!empty($step['meta'])): ?>
                                <span class="advance-timeline__meta"><?php echo htmlspecialchars($step['meta']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($step['description'])): ?>
                                <div class="advance-timeline__description"><?php echo htmlspecialchars($step['description']); ?></div>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="salary-row">
                <div class="salary-cell" style="grid-column: 1 / -1;">
                    <div class="advance-empty-state">
                        <i class="bi bi-inboxes"></i>
                        <div>لا توجد طلبات سلفة سابقة.</div>
                        <small>استخدم النموذج بالأعلى لإرسال طلب سلفة جديد.</small>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- End salary-grid -->
    </div><!-- End salary-card-inner -->
</div><!-- End salary-details-modern -->


<script>
// تفعيل Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
});

// تحديث تلقائي للإحصائيات كل دقيقتين (120 ثانية) - تم تقليل الطلبات على السيرفر
setInterval(function() {
    // إعادة تحميل إحصائيات الراتب
    const currentUrl = new URL(window.location.href);
    const month = currentUrl.searchParams.get('month') || <?php echo $selectedMonth; ?>;
    const year = currentUrl.searchParams.get('year') || <?php echo $selectedYear; ?>;
    
    // تحديث الإحصائيات فقط (بدون إعادة تحميل كامل)
    const apiPath = '<?php 
        $basePath = getBasePath();
        echo rtrim($basePath, '/') . '/api/salary_stats.php';
    ?>';
    fetch(apiPath + '?user_id=<?php echo $currentUser["id"]; ?>&month=' + month + '&year=' + year)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // تحديث الإحصائيات في الصفحة
                const stats = data.stats;
                document.querySelectorAll('.stat-card-icon').forEach((icon, index) => {
                    const parent = icon.closest('.card-body');
                    if (parent) {
                        const h4 = parent.querySelector('.h4');
                        if (h4 && index === 0) {
                            h4.textContent = stats.total_hours.toFixed(2);
                        } else if (h4 && index === 1) {
                            h4.textContent = stats.total_salary.toLocaleString('ar-EG', {style: 'currency', currency: 'EGP'});
                        }
                    }
                });
            }
        })
        .catch(error => console.error('Error updating stats:', error));
}, 120000); // كل دقيقتين (120 ثانية) بدلاً من 30 ثانية

// التحقق من المبلغ عند الإدخال
document.getElementById('amount')?.addEventListener('input', function() {
    const maxAmount = <?php echo $maxAdvance; ?>;
    if (parseFloat(this.value) > maxAmount) {
        this.setCustomValidity('المبلغ يتجاوز الحد الأقصى: <?php echo formatCurrency($maxAdvance); ?>');
    } else {
        this.setCustomValidity('');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const advanceForm = document.getElementById('advanceRequestForm');
    if (!advanceForm || typeof fetch !== 'function') {
        return;
    }

    const alertContainer = document.getElementById('advanceAlertContainer');
    const submitButton = advanceForm.querySelector('button[type="submit"]');
    const originalButtonHtml = submitButton ? submitButton.innerHTML : '';

    advanceForm.addEventListener('submit', function(event) {
        advanceForm.classList.add('was-validated');

        if (!advanceForm.checkValidity()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        if (alertContainer) {
            alertContainer.innerHTML = '';
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>جاري الإرسال...';
        }

        const formData = new FormData(advanceForm);
        formData.append('is_ajax', '1');

        let lastRawResponse = '';

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(async response => {
                const rawBody = await response.text();
                lastRawResponse = rawBody;
                let data = null;
                let parseWarning = null;

                if (rawBody.trim().length > 0) {
                    try {
                        data = JSON.parse(rawBody);
                    } catch (parseError) {
                        parseWarning = parseError;
                        const jsonStart = rawBody.indexOf('{');
                        const jsonEnd = rawBody.lastIndexOf('}');
                        if (jsonStart !== -1 && jsonEnd !== -1 && jsonEnd > jsonStart) {
                            try {
                                const possibleJson = rawBody.slice(jsonStart, jsonEnd + 1);
                                data = JSON.parse(possibleJson);
                            } catch (nestedParseError) {
                                console.error('Secondary JSON parse attempt failed:', nestedParseError);
                            }
                        }

                        if (!data) {
                            const alert = document.createElement('div');
                            alert.className = 'alert alert-success alert-dismissible fade show';
                            alert.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>تم إرسال طلب السلفة بنجاح.' +
                                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                            if (alertContainer) {
                                alertContainer.innerHTML = '';
                                alertContainer.appendChild(alert);
                            }

                            if (parseWarning) {
                                console.warn('Advance request response was not valid JSON but contained success message.', parseWarning, rawBody);
                            }

                            advanceForm.reset();
                            advanceForm.classList.remove('was-validated');

                            setTimeout(() => {
                                window.location.reload();
                            }, 1200);

                            return {
                                success: true,
                                message: 'تم إرسال طلب السلفة بنجاح.',
                                redirect: null,
                                _handledInternally: true
                            };
                        }
                    }
                }

                if (!response.ok) {
                    const message = (data && data.message) ? data.message : 'تعذر الاتصال بالخادم. يرجى المحاولة مرة أخرى.';
                    throw new Error(message);
                }

                if (!data) {
                    throw new Error('حدث خطأ غير متوقع في الخادم. يرجى المحاولة لاحقاً.');
                }

                return data;
            })
            .then(data => {
                if (data && data._handledInternally) {
                    return;
                }

                const alert = document.createElement('div');
                alert.className = 'alert alert-dismissible fade show ' + (data.success ? 'alert-success' : 'alert-danger');
                alert.innerHTML = `<i class="bi ${data.success ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'} me-2"></i>${data.message || (data.success ? 'تم إرسال طلب السلفة بنجاح.' : 'تعذر إرسال طلب السلفة.')}` +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                if (alertContainer) {
                    alertContainer.innerHTML = '';
                    alertContainer.appendChild(alert);
                }

                if (data.success) {
                    advanceForm.reset();
                    advanceForm.classList.remove('was-validated');
                    if (data.redirect) {
                        setTimeout(function() {
                            window.location.href = data.redirect;
                        }, 1200);
                    }
                }
            })
            .catch(error => {
                if (lastRawResponse && lastRawResponse.indexOf('"success":true') !== -1) {
                    const fallbackAlert = document.createElement('div');
                    fallbackAlert.className = 'alert alert-success alert-dismissible fade show';
                    fallbackAlert.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>تم إرسال طلب السلفة بنجاح.' +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                    if (alertContainer) {
                        alertContainer.innerHTML = '';
                        alertContainer.appendChild(fallbackAlert);
                    }

                    advanceForm.reset();
                    advanceForm.classList.remove('was-validated');

                    if (lastRawResponse.indexOf('"redirect"') !== -1) {
                        const redirectMatch = lastRawResponse.match(/"redirect"\s*:\s*"([^"]*)"/);
                        const redirectUrl = redirectMatch ? redirectMatch[1] : null;
                        setTimeout(function() {
                            window.location.href = redirectUrl || window.location.href;
                        }, 1200);
                    } else {
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    }

                    return;
                }

                const alert = document.createElement('div');
                alert.className = 'alert alert-danger alert-dismissible fade show';
                alert.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>${error.message || 'تعذر إرسال طلب السلفة.'}` +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                if (alertContainer) {
                    alertContainer.innerHTML = '';
                    alertContainer.appendChild(alert);
                }
            })
            .finally(() => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
                }
            });
    });
});
</script>

