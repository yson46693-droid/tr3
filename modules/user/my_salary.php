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
        
        // تنظيف جميع output buffers بشكل آمن
        $safetyCounter = 0;
        while (ob_get_level() > 0 && $safetyCounter < 10) {
            if (@ob_end_clean() === false) {
                break;
            }
            $safetyCounter++;
        }

        // التأكد من عدم إرسال headers مسبقاً
        if (!headers_sent()) {
            http_response_code($success ? 200 : 400);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            // منع التخزين المؤقت للاستجابة
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        } else {
            error_log('Advance request AJAX response headers were already sent before JSON output.');
            // محاولة إرسال JSON على أي حال
        }
        
        // إرسال JSON فقط بدون أي محتوى إضافي
        $response = [
            'success' => $success,
            'message' => $message
        ];
        
        if ($redirect !== null) {
            $response['redirect'] = $redirect;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // إنهاء التنفيذ فوراً
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit;
    };

    $amount = floatval($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $month = intval($_POST['month'] ?? $selectedMonth);
    $year = intval($_POST['year'] ?? $selectedYear);
    
    if ($amount <= 0) {
        $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // حساب الراتب الحالي
    $salaryData = getSalarySummary($currentUser['id'], $month, $year);
    
    if (!$salaryData['exists'] && (!isset($salaryData['calculation']) || !$salaryData['calculation']['success'])) {
        $error = 'لا يوجد راتب محسوب لهذا الشهر. يرجى الانتظار حتى يتم حساب الراتب.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    $currentSalary = $salaryData['exists'] ? $salaryData['salary']['total_amount'] : $salaryData['calculation']['total_amount'];
    $maxAdvance = $currentSalary * 0.5; // نصف الراتب
    
    if ($amount > $maxAdvance) {
        $error = 'قيمة السلفة لا يمكن أن تتجاوز نصف الراتب الحالي (' . formatCurrency($maxAdvance) . ')';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    if (!ensureSalaryAdvancesTable($db)) {
        $error = 'تعذر الوصول إلى جدول السلف. يرجى التواصل مع الإدارة للتأكد من إعداد قاعدة البيانات.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // التحقق من وجود طلب سلفة معلق بعد التأكد من الجدول
    $existingRequest = $db->queryOne(
        "SELECT id FROM salary_advances 
         WHERE user_id = ? AND status = 'pending'",
        [$currentUser['id']]
    );
    
    if ($existingRequest) {
        $error = 'يوجد طلب سلفة معلق بالفعل';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
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
        exit;
    } catch (Exception $e) {
        error_log("Salary advance insert error: " . $e->getMessage());
        
        // محاولة معالجة الأخطاء الشائعة وتقديم رسالة مفيدة للمستخدم
        if (stripos($e->getMessage(), 'salary_advances') !== false) {
            $error = 'تعذر حفظ طلب السلفة بسبب عدم جاهزية قاعدة البيانات. يرجى إبلاغ المحاسب للتأكد من إنشاء جدول السلف.';
        } else {
            $error = 'حدث خطأ أثناء حفظ طلب السلفة. يرجى المحاولة مرة أخرى، وإذا استمرت المشكلة تواصل مع الإدارة.';
        }
        $sendAdvanceAjaxResponse(false, $error);
        exit;
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
    'recorded_hours' => 0,
    'total_salary' => 0,
    'collections_bonus' => 0,
    'collections_amount' => 0
];

if ($currentSalary) {
    $monthStats['total_hours'] = $currentSalary['total_hours'] ?? 0;
    $monthStats['recorded_hours'] = $currentSalary['total_hours'] ?? 0; // نفس إجمالي الساعات
    $totalSalaryRaw = $currentSalary['total_amount'] ?? 0;
    // تنظيف إضافي للتأكد من إزالة 262145
    $totalSalaryStr = (string)$totalSalaryRaw;
    $totalSalaryStr = str_replace('262145', '', $totalSalaryStr);
    $totalSalaryStr = preg_replace('/\s+/', '', trim($totalSalaryStr));
    $totalSalaryStr = preg_replace('/[^0-9.]/', '', $totalSalaryStr);
    $monthStats['total_salary'] = cleanFinancialValue($totalSalaryStr ?: 0);
    
    // حساب مكافأة التحصيلات - إعادة الحساب دائماً للتأكد من الدقة
    $collectionsBonusValue = cleanFinancialValue($currentSalary['collections_bonus'] ?? 0);
    $collectionsBaseAmount = cleanFinancialValue($currentSalary['collections_amount'] ?? 0);
    
    // إذا كان مندوب مبيعات، أعد حساب مكافأة التحصيلات من التحصيلات الفعلية
    if ($currentUser['role'] === 'sales') {
        $recalculatedCollectionsAmount = calculateSalesCollections($currentUser['id'], $selectedMonth, $selectedYear);
        $recalculatedCollectionsBonus = round($recalculatedCollectionsAmount * 0.02, 2);
        
        // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
        if ($recalculatedCollectionsBonus > $collectionsBonusValue || $collectionsBonusValue == 0) {
            $collectionsBonusValue = $recalculatedCollectionsBonus;
            $collectionsBaseAmount = $recalculatedCollectionsAmount;
        }
    }
    
    $monthStats['collections_bonus'] = $collectionsBonusValue;
    $monthStats['collections_amount'] = $collectionsBaseAmount;
    
    // تحديث الراتب الإجمالي ليتضمن نسبة التحصيلات إذا لم تكن مضمنة
    $baseAmountCheck = cleanFinancialValue($currentSalary['base_amount'] ?? 0);
    $bonusCheck = cleanFinancialValue($currentSalary['bonus'] ?? 0);
    $deductionsCheck = cleanFinancialValue($currentSalary['deductions'] ?? 0);
    $expectedTotalWithCollections = $baseAmountCheck + $bonusCheck + $collectionsBonusValue - $deductionsCheck;
    
    // إذا كان الراتب الإجمالي المحفوظ لا يتضمن نسبة التحصيلات، أضفها
    if (abs($monthStats['total_salary'] - $expectedTotalWithCollections) > 0.01) {
        $monthStats['total_salary'] = $monthStats['total_salary'] + $collectionsBonusValue;
    }
    
    $maxAdvance = cleanFinancialValue($monthStats['total_salary'] * 0.5);
} else {
    // إذا لم يكن هناك راتب محفوظ، احسب الساعات مباشرة من attendance_records
    // لضمان أن الساعات معروضة حتى لو لم يتم حساب الراتب بعد
    $monthStats['total_hours'] = calculateMonthlyHours($currentUser['id'], $selectedMonth, $selectedYear);
    $monthStats['recorded_hours'] = $monthStats['total_hours']; // نفس إجمالي الساعات
    $monthStats['total_salary'] = 0;
    
    // حساب مكافأة التحصيلات حتى لو لم يكن هناك راتب محفوظ
    if ($currentUser['role'] === 'sales') {
        $collectionsAmount = calculateSalesCollections($currentUser['id'], $selectedMonth, $selectedYear);
        $monthStats['collections_bonus'] = round($collectionsAmount * 0.02, 2);
        $monthStats['collections_amount'] = $collectionsAmount;
    } else {
        $monthStats['collections_bonus'] = 0;
        $monthStats['collections_amount'] = 0;
    }
    
    $maxAdvance = 0;
}

$delaySummary = calculateMonthlyDelaySummary($currentUser['id'], $selectedMonth, $selectedYear);

// حساب القيم المطلوبة للعرض
$hourlyRate = cleanFinancialValue($currentSalary['hourly_rate'] ?? $currentUser['hourly_rate'] ?? 0);
$baseAmount = cleanFinancialValue($currentSalary['base_amount'] ?? 0);
$bonus = cleanFinancialValue($currentSalary['bonus'] ?? 0);
$deductions = cleanFinancialValue($currentSalary['deductions'] ?? 0);
$collectionsBonus = cleanFinancialValue($monthStats['collections_bonus'] ?? 0);

// حساب الراتب الإجمالي - التأكد من تضمين نسبة التحصيلات
$totalSalaryBase = cleanFinancialValue($monthStats['total_salary'] ?? 0);

// إذا كان المستخدم مندوب مبيعات ولديه نسبة تحصيلات، تأكد من تضمينها في الراتب الإجمالي
if ($currentUser['role'] === 'sales' && $collectionsBonus > 0) {
    // حساب الراتب المتوقع مع نسبة التحصيلات
    $expectedTotalWithCollections = $baseAmount + $bonus + $collectionsBonus - $deductions;
    
    // إذا كان الراتب الإجمالي الحالي لا يتطابق مع الراتب المتوقع (مع نسبة التحصيلات)
    // فهذا يعني أن نسبة التحصيلات غير مضمنة، لذا أضفها
    if (abs($totalSalaryBase - $expectedTotalWithCollections) > 0.01) {
        $totalSalary = $totalSalaryBase + $collectionsBonus;
    } else {
        // نسبة التحصيلات مضمنة بالفعل
        $totalSalary = $totalSalaryBase;
    }
} else {
    // للمستخدمين الآخرين أو إذا لم تكن هناك نسبة تحصيلات
    $totalSalary = $totalSalaryBase;
}

// تحديث monthStats للعرض الصحيح
$monthStats['total_salary'] = $totalSalary;

// إعادة حساب الحد الأقصى للسلفة بناءً على الراتب الإجمالي الصحيح (مع نسبة التحصيلات)
$maxAdvance = cleanFinancialValue($totalSalary * 0.5);

// حساب إجمالي السلفات المعتمدة لهذا الشهر
$totalApprovedAdvances = 0;
if (!empty($advanceRequests)) {
    foreach ($advanceRequests as $advance) {
        if ($advance['status'] === 'manager_approved') {
            // التحقق من أن السلفة مرتبطة براتب هذا الشهر
            $advanceMonth = isset($advance['deducted_salary_month']) ? (int)$advance['deducted_salary_month'] : null;
            $advanceYear = isset($advance['deducted_salary_year']) ? (int)$advance['deducted_salary_year'] : null;
            if ($advanceMonth === $selectedMonth && $advanceYear === $selectedYear) {
                $totalApprovedAdvances += cleanFinancialValue($advance['amount'] ?? 0);
            }
        }
    }
}

$dashboardUrl = getDashboardUrl($currentUser['role']);
$monthName = date('F', mktime(0, 0, 0, $selectedMonth, 1));
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');

.salary-page-header {
    background: #2d8cf0;
    color: white;
    padding: 25px 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    font-family: 'Cairo', sans-serif;
}

.salary-page-header h1 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: white;
    font-family: 'Cairo', sans-serif;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e3e8ef;
}

.summary-card-title {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 10px;
    font-weight: 600;
}

.summary-card-value {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 5px;
}

.summary-card-description {
    font-size: 12px;
    color: #9ca3af;
}

.salary-details-table {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e3e8ef;
}

.salary-details-table table {
    width: 100%;
    border-collapse: collapse;
}

.salary-details-table th,
.salary-details-table td {
    padding: 12px 15px;
    text-align: right;
    border-bottom: 1px solid #e5e7eb;
}

.salary-details-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.salary-details-table td {
    color: #1f2937;
    font-size: 15px;
}

.salary-details-table tr:last-child td {
    border-bottom: none;
}

.advance-form-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e3e8ef;
}

.advance-form-card h3 {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 20px;
}

.btn-submit-advance {
    background: #2d8cf0;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-submit-advance:hover {
    background: #1e7ae6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(45, 140, 240, 0.3);
}

.advance-history-table {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e3e8ef;
}

.advance-history-table table {
    width: 100%;
    border-collapse: collapse;
}

.advance-history-table th,
.advance-history-table td {
    padding: 12px 15px;
    text-align: right;
    border-bottom: 1px solid #e5e7eb;
}

.advance-history-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.advance-history-table td {
    color: #1f2937;
    font-size: 14px;
}

.advance-history-table tr:last-child td {
    border-bottom: none;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

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

@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .salary-page-header {
        padding: 20px;
    }
    
    .salary-page-header h1 {
        font-size: 22px;
    }
    
    .salary-details-table,
    .advance-form-card,
    .advance-history-table {
        padding: 15px;
    }
    
    .salary-details-table table,
    .advance-history-table table {
        font-size: 13px;
    }
    
    .salary-details-table th,
    .salary-details-table td,
    .advance-history-table th,
    .advance-history-table td {
        padding: 8px 10px;
    }
}

@media (max-width: 576px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .salary-details-table table,
    .advance-history-table table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
}
</style>

<!-- Header للشهر والسنة -->
<div class="salary-page-header">
    <h1>مرتبي - <?php echo htmlspecialchars($monthName); ?> <?php echo $selectedYear; ?></h1>
</div>

<!-- رسائل النجاح والخطأ -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<!-- جدول تفاصيل الراتب -->
<div class="salary-details-table">
    <h3 class="mb-4">تفاصيل الراتب</h3>
    <table>
        <thead>
            <tr>
                <th>المادة</th>
                <th>القيمة</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo ($currentUser['role'] === 'sales') ? 'الراتب الشهري' : 'سعر الساعة'; ?></td>
                <td><?php echo formatCurrency($hourlyRate); ?></td>
            </tr>
            <?php if ($currentUser['role'] !== 'sales'): ?>
            <tr>
                <td>عدد الساعات</td>
                <td><?php echo number_format($monthStats['total_hours'], 2); ?> ساعة</td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>إجمالي التأخير</td>
                <td><?php echo number_format($delaySummary['total_minutes'] ?? 0, 2); ?> دقيقة</td>
            </tr>
            <tr>
                <td>متوسط التأخير</td>
                <td><?php echo number_format($delaySummary['average_minutes'] ?? 0, 2); ?> دقيقة</td>
            </tr>
            <tr>
                <td>الراتب الأساسي</td>
                <td><?php echo formatCurrency($baseAmount); ?></td>
            </tr>
            <?php if ($currentUser['role'] === 'sales'): ?>
            <tr>
                <td>نسبة التحصيلات</td>
                <td><?php echo formatCurrency($collectionsBonus); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>المكافآت</td>
                <td><?php echo formatCurrency($bonus); ?></td>
            </tr>
            <tr>
                <td>الخصومات</td>
                <td><?php echo formatCurrency($deductions); ?></td>
            </tr>
            <tr>
                <td><strong>الراتب الإجمالي</strong></td>
                <td><strong><?php echo formatCurrency($totalSalary); ?></strong></td>
            </tr>
            <?php if ($currentUser['role'] === 'sales' && $collectionsBonus > 0): ?>
            <tr>
                <td colspan="2" style="text-align: center; color: #6b7280; font-size: 13px; padding-top: 10px;">
                    <i class="bi bi-info-circle me-1"></i>
                    ملاحظة: الراتب الإجمالي يتضمن نسبة التحصيلات (<?php echo formatCurrency($collectionsBonus); ?>)
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- نموذج طلب السلفة -->
<div class="advance-form-card">
    <h3>طلب سلفة</h3>
    <form method="POST" id="advanceRequestForm" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="request_advance">
        <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
        <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
        
        <div id="advanceAlertContainer"></div>
        
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label for="amount" class="form-label">مبلغ السلفة <span class="text-danger">*</span></label>
                <input type="number" 
                       step="0.01" 
                       class="form-control" 
                       id="amount" 
                       name="amount" 
                       min="0.01"
                       max="<?php echo $maxAdvance > 0 ? number_format($maxAdvance, 2, '.', '') : ''; ?>"
                       required 
                       placeholder="أدخل المبلغ">
                <small class="text-muted">الحد الأقصى: <?php echo formatCurrency($maxAdvance); ?></small>
            </div>
            <div class="col-md-6">
                <label for="reason" class="form-label">سبب الطلب <span class="text-muted">(اختياري)</span></label>
                <input type="text" 
                       class="form-control" 
                       id="reason" 
                       name="reason" 
                       placeholder="اذكر سبب طلب السلفة">
            </div>
        </div>
        
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            سيتم خصم المبلغ من راتبك الحالي بعد الموافقة
        </div>
        
        <div class="text-center">
            <button type="submit" class="btn-submit-advance">
                <i class="bi bi-send-fill me-2"></i>إرسال طلب السلفة
            </button>
        </div>
    </form>
</div>

<!-- جدول سجل طلبات السلف (آخر 10) -->
<div class="advance-history-table">
    <h3 class="mb-4">سجل طلبات السلف (آخر 10 طلبات)</h3>
    <?php if (!empty($advanceRequests)): ?>
    <table>
        <thead>
            <tr>
                <th>رقم الطلب</th>
                <th>تاريخ الطلب</th>
                <th>المبلغ</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($advanceRequests as $request): 
                $status = $request['status'] ?? 'pending';
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
                
                $requestDate = !empty($request['request_date']) ? date('Y-m-d', strtotime($request['request_date'])) : '—';
                $notes = !empty($request['notes']) ? htmlspecialchars($request['notes']) : (!empty($request['reason']) ? htmlspecialchars($request['reason']) : '—');
            ?>
            <tr>
                <td>#<?php echo (int)$request['id']; ?></td>
                <td><?php echo $requestDate; ?></td>
                <td><?php echo formatCurrency($request['amount'] ?? 0); ?></td>
                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                <td><?php echo $notes; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="text-center py-4 text-muted">
        <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
        لا توجد طلبات سلفة سابقة
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
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
    
    // التحقق من المبلغ عند الإدخال
    const amountInput = document.getElementById('amount');
    if (amountInput) {
        const maxAmount = <?php echo $maxAdvance; ?>;
        amountInput.addEventListener('input', function() {
            if (parseFloat(this.value) > maxAmount) {
                this.setCustomValidity('المبلغ يتجاوز الحد الأقصى: <?php echo formatCurrency($maxAdvance); ?>');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // معالجة نموذج طلب السلفة
    const advanceForm = document.getElementById('advanceRequestForm');
    if (advanceForm) {
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
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async response => {
                const contentType = response.headers.get('content-type') || '';
                const isJson = contentType.includes('application/json');
                const rawBody = await response.text();
                let data = null;
                
                if (isJson && rawBody.trim().length > 0) {
                    try {
                        data = JSON.parse(rawBody);
                    } catch (parseError) {
                        const jsonStart = rawBody.indexOf('{');
                        const jsonEnd = rawBody.lastIndexOf('}');
                        if (jsonStart !== -1 && jsonEnd !== -1 && jsonEnd > jsonStart) {
                            try {
                                data = JSON.parse(rawBody.slice(jsonStart, jsonEnd + 1));
                            } catch (e) {
                                // ignore
                            }
                        }
                    }
                } else if (!isJson && rawBody.trim().length > 0) {
                    if (rawBody.includes('success') || rawBody.includes('تم إرسال') || rawBody.includes('نجاح')) {
                        data = {
                            success: true,
                            message: 'تم إرسال طلب السلفة بنجاح.'
                        };
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
                    } else {
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    }
                }
            })
            .catch(error => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
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
                if (submitButton && !submitButton.disabled) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
                }
            });
        });
    }
});
</script>
