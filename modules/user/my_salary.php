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

// تحديد اسم عمود المكافآت الصحيح (bonus أو bonuses)
$bonusColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field IN ('bonus', 'bonuses')");
$bonusColumnName = $bonusColumnCheck ? $bonusColumnCheck['Field'] : 'bonus'; // افتراضي: bonus

// التأكد من وجود دالة calculateTotalSalaryWithCollections
if (!function_exists('calculateTotalSalaryWithCollections')) {
    /**
     * حساب الراتب الإجمالي بشكل صحيح مع نسبة التحصيلات (دالة بديلة)
     */
    function calculateTotalSalaryWithCollections($salaryRecord, $userId, $month, $year, $role) {
        global $bonusColumnName;
        $baseAmount = cleanFinancialValue($salaryRecord['base_amount'] ?? 0);
        // استخدام اسم العمود الصحيح (bonus أو bonuses)
        $bonus = cleanFinancialValue($salaryRecord[$bonusColumnName] ?? $salaryRecord['bonus'] ?? $salaryRecord['bonuses'] ?? 0);
        $deductions = cleanFinancialValue($salaryRecord['deductions'] ?? 0);
        $totalSalaryBase = cleanFinancialValue($salaryRecord['total_amount'] ?? 0);
        
        // حساب نسبة التحصيلات للمندوبين
        $collectionsBonus = 0;
        $collectionsAmount = 0;
        if ($role === 'sales' && function_exists('calculateSalesCollections')) {
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
        
        // حساب الراتب الإجمالي
        $totalSalary = $baseAmount + $bonus + $collectionsBonus - $deductions;
        
        // إذا كان الراتب الإجمالي المحفوظ أكبر من الراتب المحسوب، استخدم القيمة المحفوظة
        if ($totalSalaryBase > $totalSalary) {
            // تحقق من أن نسبة التحصيلات مضمنة
            $expectedWithoutCollections = $baseAmount + $bonus - $deductions;
            if (abs($totalSalaryBase - $expectedWithoutCollections) < 0.01 && $collectionsBonus > 0) {
                // نسبة التحصيلات غير مضمنة، أضفها
                $totalSalary = $totalSalaryBase + $collectionsBonus;
            } else {
                $totalSalary = $totalSalaryBase;
            }
        }
        
        return [
            'total_salary' => round($totalSalary, 2),
            'collections_bonus' => round($collectionsBonus, 2),
            'collections_amount' => round($collectionsAmount, 2)
        ];
    }
}

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
    // تنظيف أي output buffers موجودة مسبقاً في بداية معالجة الطلب
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // بدء output buffering جديد لضمان عدم إرسال أي output قبل الأوان
    if (!ob_get_level()) {
        ob_start();
    }
    
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
            // إذا تم إرسال headers بالفعل، نحاول تنظيف output buffer فقط
            $headersLocation = headers_sent($file, $line);
            error_log("Advance request AJAX response headers were already sent before JSON output. Headers sent in: {$file} on line {$line}");
            // تنظيف أي output موجود
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
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
        $error = 'فشل إرسال طلب السلفة: يجب إدخال مبلغ صحيح أكبر من الصفر. المبلغ المدخل: ' . formatCurrency($amount);
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // حساب الراتب الحالي
    try {
        $salaryData = getSalarySummary($currentUser['id'], $month, $year);
    } catch (Exception $salaryError) {
        error_log("Error getting salary summary: " . $salaryError->getMessage());
        $error = 'فشل إرسال طلب السلفة: تعذر حساب الراتب الحالي. يرجى المحاولة مرة أخرى أو التواصل مع الإدارة.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    if (!$salaryData['exists'] && (!isset($salaryData['calculation']) || !$salaryData['calculation']['success'])) {
        $error = 'فشل إرسال طلب السلفة: لا يوجد راتب محسوب لهذا الشهر (' . $month . '/' . $year . '). يرجى الانتظار حتى يتم حساب الراتب أو التواصل مع الإدارة.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // الحصول على بيانات الراتب
    $salaryRecord = $salaryData['exists'] ? $salaryData['salary'] : $salaryData['calculation'];
    
    // حساب عدد الساعات الفعلية من الحضور
    $actualHours = calculateMonthlyHours($currentUser['id'], $month, $year);
    $hourlyRate = cleanFinancialValue($salaryRecord['hourly_rate'] ?? $currentUser['hourly_rate'] ?? 0);
    // استخدام اسم العمود الصحيح (bonus أو bonuses)
    $bonus = cleanFinancialValue($salaryRecord[$bonusColumnName] ?? $salaryRecord['bonus'] ?? $salaryRecord['bonuses'] ?? 0);
    $deductions = cleanFinancialValue($salaryRecord['deductions'] ?? 0);
    
    // حساب الراتب الإجمالي بناءً على عدد الساعات الفعلية
    // حساب الراتب الأساسي من الساعات المكتملة فقط (لجميع الأدوار)
    require_once __DIR__ . '/../../includes/salary_calculator.php';
    $completedHours = calculateCompletedMonthlyHours($currentUser['id'], $month, $year);
    $baseAmount = round($completedHours * $hourlyRate, 2);
    
    if ($currentUser['role'] === 'sales') {
        // للمندوبين: احسب نسبة التحصيلات وأضفها
        $collectionsAmount = calculateSalesCollections($currentUser['id'], $month, $year);
        $collectionsBonus = round($collectionsAmount * 0.02, 2);
        // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
        $currentSalary = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
    } else {
        // لعمال الإنتاج والمحاسبين: الراتب الإجمالي = الراتب الأساسي + المكافآت - الخصومات
        $currentSalary = round($baseAmount + $bonus - $deductions, 2);
    }
    
    $maxAdvance = cleanFinancialValue($currentSalary * 0.5); // نصف الراتب
    
    if ($amount > $maxAdvance) {
        $error = 'فشل إرسال طلب السلفة: قيمة السلفة (' . formatCurrency($amount) . ') لا يمكن أن تتجاوز نصف الراتب الحالي (' . formatCurrency($maxAdvance) . '). يرجى إدخال مبلغ أقل أو يساوي ' . formatCurrency($maxAdvance);
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    if (!ensureSalaryAdvancesTable($db)) {
        $error = 'فشل إرسال طلب السلفة: تعذر الوصول إلى جدول طلبات السلف في قاعدة البيانات. يرجى التواصل مع الإدارة للتأكد من إعداد قاعدة البيانات بشكل صحيح.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // التحقق الإضافي من وجود الجدول وجاهزيته
    try {
        $tableExists = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
        if (empty($tableExists)) {
            $error = 'فشل إرسال طلب السلفة: جدول طلبات السلف غير موجود في قاعدة البيانات. يرجى التواصل مع الإدارة لإنشاء الجدول.';
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
        
        // التحقق من وجود الأعمدة الأساسية
        $columns = $db->query("SHOW COLUMNS FROM salary_advances");
        $requiredColumns = ['id', 'user_id', 'amount', 'request_date', 'status'];
        $existingColumns = [];
        foreach ($columns as $col) {
            $existingColumns[] = $col['Field'] ?? '';
        }
        
        $missingColumns = array_diff($requiredColumns, $existingColumns);
        if (!empty($missingColumns)) {
            $error = 'فشل إرسال طلب السلفة: جدول طلبات السلف غير مكتمل. الأعمدة المفقودة: ' . implode(', ', $missingColumns) . '. يرجى التواصل مع الإدارة.';
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
    } catch (Exception $tableCheckError) {
        error_log("Table verification error: " . $tableCheckError->getMessage());
        $error = 'فشل إرسال طلب السلفة: تعذر التحقق من جاهزية جدول طلبات السلف. يرجى التواصل مع الإدارة.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // التحقق من آخر تسجيل حضور/انصراف
    $attendanceCheck = canRequestAdvance($currentUser['id']);
    if (!$attendanceCheck['allowed']) {
        $error = $attendanceCheck['message'];
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
    
    // التحقق من وجود طلب سلفة معلق بعد التأكد من الجدول
    // التحقق من الطلبات المعلقة (pending) أو الموافق عليها من المحاسب (accountant_approved)
    try {
        $existingRequest = $db->queryOne(
            "SELECT id, status, amount, request_date 
             FROM salary_advances 
             WHERE user_id = ? AND status IN ('pending', 'accountant_approved')",
            [$currentUser['id']]
        );
        
        if ($existingRequest) {
            $existingStatus = $existingRequest['status'] ?? 'pending';
            $statusLabel = ($existingStatus === 'pending') ? 'قيد الانتظار' : 'موافق عليه من المحاسب';
            $error = 'يوجد طلب سلفة معلق بالفعل في انتظار الموافقة النهائية (حالة: ' . $statusLabel . '). يرجى انتظار معالجة الطلب الحالي قبل إرسال طلب جديد.';
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
    } catch (Exception $checkError) {
        error_log("Error checking existing advance requests: " . $checkError->getMessage());
        $error = 'فشل إرسال طلب السلفة: تعذر التحقق من الطلبات السابقة. يرجى المحاولة مرة أخرى أو التواصل مع الإدارة.';
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
        
        // التحقق من إنشاء الطلب بنجاح
        if (empty($requestId) || $requestId <= 0) {
            $error = 'فشل إنشاء طلب السلفة: لم يتم الحصول على رقم تعريف للطلب من قاعدة البيانات. يرجى المحاولة مرة أخرى أو التواصل مع الإدارة.';
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
        
        // التحقق من وجود الطلب في الجدول بعد الإدراج مباشرة
        $verifyRequest = $db->queryOne(
            "SELECT id, user_id, amount, request_date, status 
             FROM salary_advances 
             WHERE id = ? AND user_id = ?",
            [$requestId, $currentUser['id']]
        );
        
        if (empty($verifyRequest)) {
            $error = 'فشل إرسال طلب السلفة: تم إنشاء الطلب لكن لم يتم العثور عليه في قاعدة البيانات. يرجى التحقق من الجدول أو التواصل مع الإدارة.';
            error_log("Advance request verification failed: Request ID {$requestId} not found in salary_advances table after insert");
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
        
        // التحقق من تطابق البيانات
        $verifyAmount = floatval($verifyRequest['amount'] ?? 0);
        $verifyDate = $verifyRequest['request_date'] ?? '';
        $verifyStatus = $verifyRequest['status'] ?? '';
        
        if (abs($verifyAmount - $amount) > 0.01) {
            $error = 'فشل إرسال طلب السلفة: المبلغ المحفوظ (' . formatCurrency($verifyAmount) . ') لا يطابق المبلغ المرسل (' . formatCurrency($amount) . '). يرجى التواصل مع الإدارة.';
            error_log("Advance request data mismatch: Amount mismatch for request ID {$requestId}. Expected: {$amount}, Found: {$verifyAmount}");
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
        
        if ($verifyStatus !== 'pending') {
            $error = 'فشل إرسال طلب السلفة: حالة الطلب المحفوظة (' . $verifyStatus . ') غير صحيحة. يرجى التواصل مع الإدارة.';
            error_log("Advance request status mismatch: Status mismatch for request ID {$requestId}. Expected: pending, Found: {$verifyStatus}");
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
        
        // تسجيل نجاح التحقق
        error_log("Advance request #{$requestId}: Successfully verified in database. Amount: {$amount}, Date: {$verifyDate}, Status: {$verifyStatus}");
        
        // التحقق النهائي: التأكد من أن الطلب سيظهر في الجدول (نفس الاستعلام المستخدم في عرض الجدول)
        try {
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

            $finalCheckSql = "
                SELECT 
                    sa.id,
                    sa.amount,
                    sa.status,
                    sa.request_date,
                    {$salaryMonthSelect},
                    {$salaryYearSelect},
                    {$salaryTotalSelect}
                FROM salary_advances sa
                LEFT JOIN salaries s ON sa.deducted_from_salary_id = s.id
                WHERE sa.id = ? AND sa.user_id = ?
                ORDER BY sa.created_at DESC 
                LIMIT 1";

            $finalCheck = $db->queryOne($finalCheckSql, [$requestId, $currentUser['id']]);
            
            if (empty($finalCheck)) {
                $error = 'فشل إرسال طلب السلفة: تم إنشاء الطلب لكن لا يظهر في الجدول. يرجى التحقق من الجدول أو التواصل مع الإدارة.';
                error_log("Advance request final check failed: Request ID {$requestId} not found in table query result");
                $sendAdvanceAjaxResponse(false, $error);
                exit;
            }
            
            // التحقق من أن البيانات صحيحة
            $finalAmount = floatval($finalCheck['amount'] ?? 0);
            if (abs($finalAmount - $amount) > 0.01) {
                $error = 'فشل إرسال طلب السلفة: المبلغ في الجدول (' . formatCurrency($finalAmount) . ') لا يطابق المبلغ المرسل (' . formatCurrency($amount) . '). يرجى التواصل مع الإدارة.';
                error_log("Advance request final check: Amount mismatch. Expected: {$amount}, Found: {$finalAmount}");
                $sendAdvanceAjaxResponse(false, $error);
                exit;
            }
            
            error_log("Advance request #{$requestId}: Final check passed. Request will appear in table.");
        } catch (Exception $finalCheckError) {
            error_log("Final check error for advance request #{$requestId}: " . $finalCheckError->getMessage());
            $error = 'فشل إرسال طلب السلفة: تعذر التحقق النهائي من ظهور الطلب في الجدول. يرجى التحقق من الجدول أو التواصل مع الإدارة.';
            $sendAdvanceAjaxResponse(false, $error);
            exit;
        }
        
        // إرسال إشعار للمحاسب - مع التحقق من وجود محاسبين نشطين
        $accountants = $db->query("SELECT id FROM users WHERE role = 'accountant' AND status = 'active'");
        
        $notificationsSent = 0;
        
        if (empty($accountants)) {
            // إذا لم يكن هناك محاسبون، إرسال إشعار للمديرين فقط
            $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND status = 'active'");
            foreach ($managers as $manager) {
                try {
                    createNotification(
                        $manager['id'],
                        'طلب سلفة جديد (لا يوجد محاسب نشط)',
                        'طلب سلفة من ' . ($currentUser['full_name'] ?? $currentUser['username']) . ' بقيمة ' . formatCurrency($amount) . ' - لا يوجد محاسب نشط لمراجعته',
                        'warning',
                        getDashboardUrl('manager') . '?page=salaries&view=advances',
                        false
                    );
                    $notificationsSent++;
                } catch (Exception $notifError) {
                    error_log("Failed to create notification for manager ID: {$manager['id']} for advance request ID: {$requestId}. Error: " . $notifError->getMessage());
                }
            }
            error_log("Advance request #{$requestId}: No active accountants found, notification sent to managers instead");
        } else {
            // إرسال إشعار للمحاسبين
            foreach ($accountants as $accountant) {
                try {
                    createNotification(
                        $accountant['id'],
                        'طلب سلفة جديد',
                        'طلب سلفة من ' . ($currentUser['full_name'] ?? $currentUser['username']) . ' بقيمة ' . formatCurrency($amount),
                        'warning',
                        getDashboardUrl('accountant') . '?page=salaries&view=advances',
                        false
                    );
                    $notificationsSent++;
                } catch (Exception $notifError) {
                    error_log("Failed to create notification for accountant ID: {$accountant['id']} for advance request ID: {$requestId}. Error: " . $notifError->getMessage());
                }
            }
            
            // إرسال إشعار للمديرين أيضاً حتى يكونوا على علم بطلب السلفة
            $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND status = 'active'");
            foreach ($managers as $manager) {
                try {
                    createNotification(
                        $manager['id'],
                        'طلب سلفة جديد',
                        'طلب سلفة من ' . ($currentUser['full_name'] ?? $currentUser['username']) . ' بقيمة ' . formatCurrency($amount) . ' - بانتظار مراجعة المحاسب',
                        'info',
                        getDashboardUrl('manager') . '?page=salaries&view=advances',
                        false
                    );
                    $notificationsSent++;
                } catch (Exception $notifError) {
                    error_log("Failed to create notification for manager ID: {$manager['id']} for advance request ID: {$requestId}. Error: " . $notifError->getMessage());
                }
            }
            
            if ($notificationsSent === 0) {
                error_log("Advance request #{$requestId}: Failed to send any notifications");
            } else {
                error_log("Advance request #{$requestId}: Successfully sent {$notificationsSent} notification(s)");
            }
        }
        
        logAudit($currentUser['id'], 'request_advance', 'salary_advance', $requestId, null, [
            'amount' => $amount
        ]);
        
        // منع التكرار باستخدام redirect
        $successMessage = 'تم إرسال طلب السلفة بنجاح. سيتم مراجعته من قبل المحاسب والمدير.';
        $redirectParams = [
            'page' => 'my_salary',
            'month' => $month,
            'year' => $year,
            'advance_id' => $requestId // إضافة رقم الطلب للتحقق منه بعد إعادة التحميل
        ];
        $redirectUrl = getDashboardUrl($currentUser['role']) . '?' . http_build_query($redirectParams);
        
        // حفظ رسالة النجاح ورقم الطلب للواجهة الأمامية ولجلسة المستخدم
        $_SESSION['success_message'] = $successMessage;
        $_SESSION['last_advance_request_id'] = $requestId;
        $_SESSION['last_advance_request_amount'] = $amount;
        
        if ($sendAdvanceAjaxResponse(true, $successMessage, $redirectUrl) === false) {
            preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
        }
        exit;
    } catch (Exception $e) {
        error_log("Salary advance insert error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        // محاولة معالجة الأخطاء الشائعة وتقديم رسالة مفيدة للمستخدم
        $errorMessage = $e->getMessage();
        
        if (stripos($errorMessage, 'salary_advances') !== false || stripos($errorMessage, 'Table') !== false) {
            $error = 'فشل إرسال طلب السلفة: تعذر الوصول إلى جدول طلبات السلف في قاعدة البيانات. يرجى إبلاغ المحاسب للتأكد من إنشاء الجدول.';
        } elseif (stripos($errorMessage, 'foreign key') !== false || stripos($errorMessage, 'constraint') !== false) {
            $error = 'فشل إرسال طلب السلفة: خطأ في البيانات المرسلة. يرجى التأكد من صحة البيانات والمحاولة مرة أخرى.';
        } elseif (stripos($errorMessage, 'connection') !== false || stripos($errorMessage, 'database') !== false) {
            $error = 'فشل إرسال طلب السلفة: تعذر الاتصال بقاعدة البيانات. يرجى التحقق من الاتصال والمحاولة مرة أخرى.';
        } elseif (stripos($errorMessage, 'duplicate') !== false) {
            $error = 'فشل إرسال طلب السلفة: يبدو أن هناك طلب سلفة مكرر. يرجى التحقق من سجل الطلبات أو التواصل مع الإدارة.';
        } else {
            $error = 'فشل إرسال طلب السلفة: حدث خطأ غير متوقع أثناء حفظ الطلب في قاعدة البيانات. يرجى المحاولة مرة أخرى، وإذا استمرت المشكلة تواصل مع الإدارة مع رقم الخطأ: ' . substr(md5($errorMessage), 0, 8);
        }
        
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    } catch (Throwable $e) {
        error_log("Salary advance insert fatal error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        $error = 'فشل إرسال طلب السلفة: حدث خطأ فني أثناء معالجة الطلب. يرجى المحاولة مرة أخرى أو التواصل مع الإدارة.';
        $sendAdvanceAjaxResponse(false, $error);
        exit;
    }
}

// التحقق من حالة الحضور/الانصراف
$attendanceCheck = canRequestAdvance($currentUser['id']);
$canRequestAdvanceByAttendance = $attendanceCheck['allowed'];
$attendanceMessage = $attendanceCheck['message'] ?? '';
$canRequestAdvance = $canRequestAdvanceByAttendance;
$hasPendingAdvance = false;
$pendingAdvanceMessage = '';

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
    
    // التحقق من وجود طلب سلفة معلق (pending أو accountant_approved)
    $pendingRequest = $db->queryOne(
        "SELECT id, amount, request_date, status 
         FROM salary_advances 
         WHERE user_id = ? AND status IN ('pending', 'accountant_approved')
         ORDER BY created_at DESC 
         LIMIT 1",
        [$currentUser['id']]
    );
    
    // إذا كان هناك طلب معلق، تعطيل النموذج
    if ($pendingRequest) {
        $hasPendingAdvance = true;
        $canRequestAdvance = false;
        $pendingStatus = $pendingRequest['status'] ?? 'pending';
        $statusLabel = ($pendingStatus === 'pending') ? 'قيد الانتظار' : 'موافق عليه من المحاسب';
        $pendingAmount = formatCurrency($pendingRequest['amount'] ?? 0);
        $pendingDate = !empty($pendingRequest['request_date']) ? date('Y-m-d', strtotime($pendingRequest['request_date'])) : '';
        
        $pendingAdvanceMessage = 'يوجد طلب سلفة معلق (حالة: ' . $statusLabel . '، المبلغ: ' . $pendingAmount . '، التاريخ: ' . $pendingDate . '). يرجى انتظار معالجة الطلب الحالي قبل إرسال طلب جديد.';
        
        if (!empty($attendanceMessage)) {
            $attendanceMessage = $pendingAdvanceMessage . ' ' . $attendanceMessage;
        } else {
            $attendanceMessage = $pendingAdvanceMessage;
        }
    }
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
    
    // حساب الراتب الإجمالي بشكل صحيح مع نسبة التحصيلات
    $salaryCalculation = calculateTotalSalaryWithCollections($currentSalary, $currentUser['id'], $selectedMonth, $selectedYear, $currentUser['role']);
    $totalSalaryForAdvance = $salaryCalculation['total_salary'];
    
    // استخدام الراتب الإجمالي من جدول تفاصيل الراتب إذا كان محفوظاً
    if (isset($currentSalary['total_amount'])) {
        $totalSalaryFromTable = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
        if ($totalSalaryFromTable > 0) {
            $totalSalaryForAdvance = $totalSalaryFromTable;
        }
    }
    
    $maxAdvance = cleanFinancialValue($totalSalaryForAdvance * 0.5);
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
    // استخدام اسم العمود الصحيح (bonus أو bonuses)
    if (isset($currentSalary[$bonusColumnName])) {
        $currentSalary[$bonusColumnName] = cleanFinancialValue($currentSalary[$bonusColumnName]);
    } elseif (isset($currentSalary['bonus'])) {
        $currentSalary['bonus'] = cleanFinancialValue($currentSalary['bonus']);
    } elseif (isset($currentSalary['bonuses'])) {
        $currentSalary['bonuses'] = cleanFinancialValue($currentSalary['bonuses']);
    }
    if (isset($currentSalary['deductions'])) {
        $currentSalary['deductions'] = cleanFinancialValue($currentSalary['deductions']);
    }
    
    // حساب الراتب الإجمالي بشكل صحيح مع نسبة التحصيلات
    $salaryCalculation = calculateTotalSalaryWithCollections($currentSalary, $currentUser['id'], $selectedMonth, $selectedYear, $currentUser['role']);
    $totalSalaryForAdvance = $salaryCalculation['total_salary'];
    
    // استخدام الراتب الإجمالي من جدول تفاصيل الراتب إذا كان محفوظاً
    if (isset($currentSalary['total_amount'])) {
        $totalSalaryFromTable = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
        if ($totalSalaryFromTable > 0) {
            $totalSalaryForAdvance = $totalSalaryFromTable;
        }
    }
    
    $maxAdvance = cleanFinancialValue($totalSalaryForAdvance * 0.5);
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
        
        // حساب الراتب الإجمالي بشكل صحيح مع نسبة التحصيلات
        $salaryCalculation = calculateTotalSalaryWithCollections($currentSalary, $currentUser['id'], $selectedMonth, $selectedYear, $currentUser['role']);
        $totalSalaryForAdvance = $salaryCalculation['total_salary'];
        
        // استخدام الراتب الإجمالي من جدول تفاصيل الراتب إذا كان محفوظاً
        if (isset($currentSalary['total_amount'])) {
            $totalSalaryFromTable = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
            if ($totalSalaryFromTable > 0) {
                $totalSalaryForAdvance = $totalSalaryFromTable;
            }
        }
        
        $maxAdvance = cleanFinancialValue($totalSalaryForAdvance * 0.5);
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
    // استخدام القيم مباشرة من جدول salaries
    $monthStats['total_hours'] = cleanFinancialValue($currentSalary['total_hours'] ?? 0);
    $monthStats['recorded_hours'] = $monthStats['total_hours']; // نفس إجمالي الساعات
    $monthStats['total_salary'] = cleanFinancialValue($currentSalary['total_amount'] ?? 0);
    
    // استخدام نسبة التحصيلات من قاعدة البيانات
    if ($currentUser['role'] === 'sales') {
        $monthStats['collections_bonus'] = cleanFinancialValue($currentSalary['collections_bonus'] ?? 0);
        $monthStats['collections_amount'] = cleanFinancialValue($currentSalary['collections_amount'] ?? 0);
    } else {
        $monthStats['collections_bonus'] = 0;
        $monthStats['collections_amount'] = 0;
    }
    
    // حساب الحد الأقصى للسلفة بناءً على الراتب الإجمالي من جدول تفاصيل الراتب
    $maxAdvance = cleanFinancialValue($monthStats['total_salary'] * 0.5);
} else {
    // إذا لم يكن هناك راتب محفوظ، احسب الساعات مباشرة من attendance_records
    // لضمان أن الساعات معروضة حتى لو لم يتم حساب الراتب بعد
    $monthStats['total_hours'] = calculateMonthlyHours($currentUser['id'], $selectedMonth, $selectedYear);
    $monthStats['recorded_hours'] = $monthStats['total_hours']; // نفس إجمالي الساعات
    $monthStats['total_salary'] = 0;
    
    // حساب مكافأة التحصيلات حتى لو لم يكن هناك راتب محفوظ
    // استخدام نفس منطق cash_register.php: حساب من جدول collections فقط
    if ($currentUser['role'] === 'sales') {
        $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
        $totalCollectionsForMonth = 0.0;
        if (!empty($collectionsTableExists)) {
            $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
            $hasStatusColumn = !empty($statusColumnCheck);
            
            if ($hasStatusColumn) {
                $collectionsResult = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_collections
                     FROM collections
                     WHERE collected_by = ? 
                     AND MONTH(date) = ? 
                     AND YEAR(date) = ?
                     AND status IN ('pending', 'approved')",
                    [$currentUser['id'], $selectedMonth, $selectedYear]
                );
            } else {
                $collectionsResult = $db->queryOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_collections
                     FROM collections
                     WHERE collected_by = ? 
                     AND MONTH(date) = ? 
                     AND YEAR(date) = ?",
                    [$currentUser['id'], $selectedMonth, $selectedYear]
                );
            }
            $totalCollectionsForMonth = (float)($collectionsResult['total_collections'] ?? 0);
        }
        $monthStats['collections_amount'] = $totalCollectionsForMonth;
        $monthStats['collections_bonus'] = round($totalCollectionsForMonth * 0.02, 2);
    } else {
        $monthStats['collections_bonus'] = 0;
        $monthStats['collections_amount'] = 0;
    }
    
    $maxAdvance = 0;
}

$delaySummary = calculateMonthlyDelaySummary($currentUser['id'], $selectedMonth, $selectedYear);

// حساب القيم المطلوبة للعرض
$hourlyRate = cleanFinancialValue($currentSalary['hourly_rate'] ?? $currentUser['hourly_rate'] ?? 0);
// استخدام اسم العمود الصحيح (bonus أو bonuses)
$bonus = cleanFinancialValue($currentSalary[$bonusColumnName] ?? $currentSalary['bonus'] ?? $currentSalary['bonuses'] ?? 0);
$deductions = cleanFinancialValue($currentSalary['deductions'] ?? 0);

// حساب إجمالي التحصيلات من جدول collections فقط (مثل cash_register.php)
// لضمان أن نسبة التحصيلات = 2% من إجمالي التحصيلات من العملاء
$totalCollectionsFromTable = 0.0;
if ($currentUser['role'] === 'sales') {
    $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
    if (!empty($collectionsTableExists)) {
        // التحقق من وجود عمود status
        $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
        $hasStatusColumn = !empty($statusColumnCheck);
        
        if ($hasStatusColumn) {
            // حساب جميع التحصيلات (pending و approved) للشهر والسنة المحددين
            $collectionsResult = $db->queryOne(
                "SELECT COALESCE(SUM(amount), 0) as total_collections
                 FROM collections
                 WHERE collected_by = ? 
                 AND MONTH(date) = ? 
                 AND YEAR(date) = ?
                 AND status IN ('pending', 'approved')",
                [$currentUser['id'], $selectedMonth, $selectedYear]
            );
        } else {
            $collectionsResult = $db->queryOne(
                "SELECT COALESCE(SUM(amount), 0) as total_collections
                 FROM collections
                 WHERE collected_by = ? 
                 AND MONTH(date) = ? 
                 AND YEAR(date) = ?",
                [$currentUser['id'], $selectedMonth, $selectedYear]
            );
        }
        $totalCollectionsFromTable = (float)($collectionsResult['total_collections'] ?? 0);
    }
}

// استخدام القيم من جدول salaries وإعادة حساب الراتب الإجمالي من المكونات لضمان الدقة
if ($currentSalary && isset($currentSalary['base_amount'])) {
    // استخدام القيم من قاعدة البيانات
    $baseAmount = cleanFinancialValue($currentSalary['base_amount'] ?? 0);
    
    // حساب نسبة التحصيلات بناءً على إجمالي التحصيلات من جدول collections فقط (مثل cash_register.php)
    // نسبة التحصيلات = 2% من إجمالي التحصيلات من العملاء
    if ($currentUser['role'] === 'sales') {
        $collectionsAmount = $totalCollectionsFromTable;
        $collectionsBonus = round($collectionsAmount * 0.02, 2);
    } else {
        $collectionsBonus = 0;
        $collectionsAmount = 0;
    }
    
    // إعادة حساب الراتب الإجمالي من المكونات لضمان الدقة
    // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
    $totalSalary = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
    
    // تحديث $monthStats بالقيم المحسوبة
    $monthStats['total_salary'] = $totalSalary;
    if ($currentUser['role'] === 'sales') {
        $monthStats['collections_bonus'] = $collectionsBonus;
        $monthStats['collections_amount'] = $collectionsAmount;
    } else {
        $collectionsBonus = 0;
        $collectionsAmount = 0;
    }
} else {
    // إذا لم يكن هناك راتب محفوظ، استخدم القيم من $monthStats
    $baseAmount = 0;
    $totalSalary = $monthStats['total_salary'] ?? 0;
    
    // حساب نسبة التحصيلات بناءً على إجمالي التحصيلات من جدول collections فقط
    if ($currentUser['role'] === 'sales') {
        $collectionsAmount = $totalCollectionsFromTable;
        $collectionsBonus = round($collectionsAmount * 0.02, 2);
    } else {
        $collectionsBonus = 0;
        $collectionsAmount = 0;
    }
    
    // إعادة حساب الراتب الإجمالي من المكونات
    if ($currentUser['role'] === 'sales') {
        $totalSalary = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
    } else {
        $totalSalary = round($baseAmount + $bonus - $deductions, 2);
    }
}

// إعادة حساب الحد الأقصى للسلفة بناءً على الراتب الإجمالي النهائي المعروض في الجدول
// هذا يضمن أن الحد الأقصى يعتمد على نفس القيمة المعروضة للمستخدم (159.80 ج.م)
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

.btn-submit-advance:hover:not(:disabled) {
    background: #1e7ae6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(45, 140, 240, 0.3);
}

.btn-submit-advance:disabled {
    background: #9ca3af;
    color: #6b7280;
    cursor: not-allowed;
    opacity: 0.6;
}

.disabled-input {
    background-color: #f3f4f6 !important;
    color: #9ca3af !important;
    cursor: not-allowed !important;
}

.disabled-input::placeholder {
    color: #d1d5db !important;
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
<div id="pageAlertContainer"></div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php 
// عرض رسالة النجاح من session فقط إذا لم يكن هناك advance_id في URL
// لأن JavaScript سيتحقق من وجود الطلب في الجدول ويعرض الرسالة
$showSuccessFromSession = $success && !isset($_GET['advance_id']);
if ($showSuccessFromSession): ?>
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
                <td><?php echo ($currentUser['role'] === 'sales') ? 'سعر الساعه' : 'سعر الساعة'; ?></td>
                <td><?php echo formatCurrency($hourlyRate); ?></td>
            </tr>
            <tr>
                <td>عدد الساعات</td>
                <td><?php echo formatHours($monthStats['total_hours']); ?></td>
            </tr>
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
<div class="advance-form-card" <?php if (!$canRequestAdvance): ?>style="opacity: 0.6;"<?php endif; ?>>
    <h3>طلب سلفة</h3>
    <?php if (!$canRequestAdvance): ?>
    <div class="alert alert-warning alert-dismissible fade show mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php if ($hasPendingAdvance): ?>
            <strong>يوجد طلب سلفة معلق:</strong> <?php echo htmlspecialchars($pendingAdvanceMessage); ?>
        <?php elseif (!$canRequestAdvanceByAttendance): ?>
            <strong>يجب تسجيل الانصراف أولاً:</strong> <?php echo htmlspecialchars($attendanceMessage); ?>
        <?php else: ?>
            <strong>غير متاح:</strong> <?php echo htmlspecialchars($attendanceMessage); ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <form method="POST" id="advanceRequestForm" class="needs-validation" novalidate <?php if (!$canRequestAdvance): ?>onsubmit="return false;"<?php endif; ?>>
        <input type="hidden" name="action" value="request_advance">
        <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
        <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
        
        <div id="advanceAlertContainer"></div>
        
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label for="amount" class="form-label">مبلغ السلفة <span class="text-danger">*</span></label>
                <input type="number" 
                       step="0.01" 
                       class="form-control <?php if (!$canRequestAdvance): ?>disabled-input<?php endif; ?>" 
                       id="amount" 
                       name="amount" 
                       min="0.01"
                       max="<?php echo $maxAdvance > 0 ? number_format($maxAdvance, 2, '.', '') : ''; ?>"
                       required 
                       placeholder="أدخل المبلغ"
                       <?php if (!$canRequestAdvance): ?>disabled<?php endif; ?>>
                <small class="text-muted">الحد الأقصى: <?php echo formatCurrency($maxAdvance); ?></small>
            </div>
            <div class="col-md-6">
                <label for="reason" class="form-label">سبب الطلب <span class="text-muted">(اختياري)</span></label>
                <input type="text" 
                       class="form-control <?php if (!$canRequestAdvance): ?>disabled-input<?php endif; ?>" 
                       id="reason" 
                       name="reason" 
                       placeholder="اذكر سبب طلب السلفة"
                       <?php if (!$canRequestAdvance): ?>disabled<?php endif; ?>>
            </div>
        </div>
        
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            سيتم خصم المبلغ من راتبك الحالي بعد الموافقة
        </div>
        
        <div class="text-center">
            <button type="submit" class="btn-submit-advance" <?php if (!$canRequestAdvance): ?>disabled style="opacity: 0.5; cursor: not-allowed;"<?php endif; ?>>
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

<?php if ($currentUser['role'] === 'sales'): ?>
<!-- جدول سجلات نسبة التحصيلات والخصومات -->
<?php
// جلب سجلات العمليات التي تؤثر على نسبة التحصيلات والخصومات
$commissionAndDeductionLogs = [];

try {
    // جلب التحصيلات من عملاء المندوب (للشهر الحالي)
    $collectionsQuery = "
        SELECT c.*, cust.name as customer_name, cust.id as customer_id
        FROM collections c
        INNER JOIN customers cust ON c.customer_id = cust.id
        WHERE cust.created_by = ?
        AND MONTH(c.date) = ?
        AND YEAR(c.date) = ?
        ORDER BY c.created_at DESC
    ";
    
    $collections = $db->query($collectionsQuery, [$currentUser['id'], $selectedMonth, $selectedYear]);
    
    foreach ($collections as $collection) {
        $collectionAmount = abs(floatval($collection['amount'] ?? 0));
        if ($collectionAmount > 0) {
            // حساب نسبة التحصيلات (2%)
            $commissionAmount = round($collectionAmount * 0.02, 2);
            if ($commissionAmount > 0) {
                $commissionAndDeductionLogs[] = [
                    'id' => 'collection_' . $collection['id'],
                    'action' => 'add_collection',
                    'operation_type' => 'إضافة',
                    'amount' => $commissionAmount,
                    'description' => 'نسبة تحصيلات من عميل (' . htmlspecialchars($collection['customer_name'] ?? '') . ') - مبلغ التحصيل: ' . formatCurrency($collectionAmount) . ' (نسبة 2%)',
                    'created_at' => $collection['created_at'] ?? $collection['date'],
                    'username' => 'نظام'
                ];
            }
        }
    }
    
    // جلب سجلات audit_logs المتعلقة بنسبة التحصيلات
    $rewardActions = ['collection_reward_add', 'collection_reward_remove'];
    $placeholders = implode(',', array_fill(0, count($rewardActions), '?'));
    
    $rewardLogsQuery = "
        SELECT al.*, u.username, u.full_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.action IN ($placeholders)
        ORDER BY al.created_at DESC
        LIMIT 100
    ";
    
    $rewardLogs = $db->query($rewardLogsQuery, $rewardActions);
    
    foreach ($rewardLogs as $log) {
        $newValue = $log['new_value'] ?? '';
        if (empty($newValue)) continue;
        
        $newValueData = json_decode($newValue, true);
        if (json_last_error() !== JSON_ERROR_NONE) continue;
        
        // التحقق من أن السجل متعلق بالمندوب الحالي
        $userId = isset($newValueData['user_id']) ? (int)$newValueData['user_id'] : 0;
        if ($userId !== (int)$currentUser['id']) continue;
        
        $rewardAmount = abs(floatval($newValueData['reward_amount'] ?? 0));
        if ($rewardAmount > 0) {
            $operationType = $log['action'] === 'collection_reward_add' ? 'إضافة' : 'خصم';
            $collectionId = $newValueData['collection_id'] ?? null;
            
            $description = 'نسبة التحصيلات: ' . ($operationType === 'إضافة' ? 'إضافة' : 'خصم');
            if ($collectionId) {
                $description .= ' - رقم التحصيل: #' . $collectionId;
            }
            
            $commissionAndDeductionLogs[] = [
                'id' => 'reward_' . $log['id'],
                'action' => $log['action'],
                'operation_type' => $operationType,
                'amount' => $rewardAmount,
                'description' => $description,
                'created_at' => $log['created_at'],
                'username' => $log['username'] ?? $log['full_name'] ?? 'نظام'
            ];
        }
    }
    
    // جلب سجلات الخصومات من المرتجعات
    $returnDeductionQuery = "
        SELECT al.*, u.username, u.full_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.action IN ('return_salary_deduction', 'return_deduction')
        ORDER BY al.created_at DESC
        LIMIT 100
    ";
    
    $returnLogs = $db->query($returnDeductionQuery);
    
    foreach ($returnLogs as $log) {
        $newValue = $log['new_value'] ?? '';
        if (empty($newValue)) continue;
        
        $newValueData = json_decode($newValue, true);
        if (json_last_error() !== JSON_ERROR_NONE) continue;
        
        $salesRepId = isset($newValueData['sales_rep_id']) ? (int)$newValueData['sales_rep_id'] : 0;
        if ($salesRepId !== (int)$currentUser['id']) continue;
        
        // التحقق من أن الخصم متعلق بالشهر والسنة المحددين
        $salaryId = isset($newValueData['salary_id']) ? (int)$newValueData['salary_id'] : 0;
        if ($salaryId > 0) {
            // جلب معلومات الراتب للتحقق من الشهر والسنة
            $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
            $hasYearColumn = !empty($yearColumnCheck);
            
            if ($hasYearColumn) {
                $salaryInfo = $db->queryOne(
                    "SELECT month, year FROM salaries WHERE id = ? AND user_id = ?",
                    [$salaryId, $currentUser['id']]
                );
            } else {
                $salaryInfo = $db->queryOne(
                    "SELECT month FROM salaries WHERE id = ? AND user_id = ?",
                    [$salaryId, $currentUser['id']]
                );
            }
            
            if ($salaryInfo) {
                $logMonth = isset($salaryInfo['month']) ? (int)$salaryInfo['month'] : null;
                $logYear = isset($salaryInfo['year']) ? (int)$salaryInfo['year'] : null;
                
                // إذا كان هناك عمود year، تحقق من الشهر والسنة
                if ($hasYearColumn) {
                    if ($logMonth !== $selectedMonth || $logYear !== $selectedYear) {
                        continue; // تخطي هذا السجل لأنه ليس للشهر والسنة المحددين
                    }
                } else {
                    // إذا لم يكن هناك عمود year، تحقق من الشهر فقط
                    // قد نحتاج للتحقق من التاريخ من created_at
                    $logDate = strtotime($log['created_at'] ?? '');
                    $logYearFromDate = (int)date('Y', $logDate);
                    $logMonthFromDate = (int)date('m', $logDate);
                    
                    // تحقق من الشهر
                    if ($logMonth !== $selectedMonth) {
                        continue;
                    }
                    
                    // تحقق من السنة من تاريخ السجل
                    if ($logYearFromDate !== $selectedYear) {
                        continue;
                    }
                }
            } else {
                // إذا لم يتم العثور على سجل الراتب، تخطي هذا السجل
                continue;
            }
        } else {
            // إذا لم يكن هناك salary_id، حاول استخراج الشهر والسنة من notes أو new_value
            $logMonth = isset($newValueData['month']) ? (int)$newValueData['month'] : null;
            $logYear = isset($newValueData['year']) ? (int)$newValueData['year'] : null;
            
            if ($logMonth !== null && $logYear !== null) {
                if ($logMonth !== $selectedMonth || $logYear !== $selectedYear) {
                    continue;
                }
            } else {
                // إذا لم يتم العثور على الشهر والسنة، تخطي هذا السجل
                continue;
            }
        }
        
        $deductionAmount = abs(floatval($newValueData['deduction_amount'] ?? 0));
        if ($deductionAmount > 0) {
            $returnId = $newValueData['return_id'] ?? $log['entity_id'] ?? null;
            $description = 'خصم بسبب مرتجع';
            if ($returnId) {
                $description .= ' - رقم المرتجع: #' . $returnId;
            }
            
            $commissionAndDeductionLogs[] = [
                'id' => 'return_' . $log['id'],
                'action' => $log['action'],
                'operation_type' => 'خصم',
                'amount' => $deductionAmount,
                'description' => $description,
                'created_at' => $log['created_at'],
                'username' => $log['username'] ?? $log['full_name'] ?? 'نظام'
            ];
        }
    }
    
    // جلب سجلات الخصومات من تعديلات الراتب
    // التحقق من وجود عمود year في جدول salaries
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    $yearSelect = $hasYearColumn ? "s.year" : "NULL as year";
    $salaryModificationQuery = "
        SELECT al.*, u.username, u.full_name, s.month, {$yearSelect}, s.deductions as current_deductions
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN salaries s ON al.entity_id = s.id AND s.user_id = ?
        WHERE al.action IN ('modify_salary', 'request_salary_modification')
        AND al.entity_type = 'salary'
        AND s.id IS NOT NULL
        ORDER BY al.created_at DESC
        LIMIT 100
    ";
    
    $salaryModificationLogs = $db->query($salaryModificationQuery, [$currentUser['id']]);
    
    foreach ($salaryModificationLogs as $log) {
        // التحقق من أن السجل متعلق بالشهر والسنة المحددين
        $logMonth = isset($log['month']) ? (int)$log['month'] : null;
        $logYear = isset($log['year']) ? (int)$log['year'] : null;
        
        // إذا كان الشهر والسنة متطابقين مع المحددين، أو إذا لم يكن هناك شهر وسنة محددين، اعرض السجل
        if (($logMonth === $selectedMonth && $logYear === $selectedYear) || ($logMonth === null && $logYear === null)) {
            $newValue = $log['new_value'] ?? '';
            $oldValue = $log['old_value'] ?? '';
            
            // محاولة استخراج الخصومات من new_value
            $deductions = 0;
            $oldDeductions = 0;
            
            if (!empty($newValue)) {
                $newValueData = json_decode($newValue, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($newValueData['deductions'])) {
                    $deductions = abs(floatval($newValueData['deductions'] ?? 0));
                }
            }
            
            if (!empty($oldValue)) {
                $oldValueData = json_decode($oldValue, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($oldValueData['deductions'])) {
                    $oldDeductions = abs(floatval($oldValueData['deductions'] ?? 0));
                }
            }
            
            // إذا لم يكن هناك old_value، استخدم القيمة الحالية من جدول salaries
            if ($oldDeductions == 0 && isset($log['current_deductions'])) {
                // محاولة الحصول على القيمة السابقة من سجل audit_logs السابق
                $previousLog = $db->queryOne(
                    "SELECT new_value, old_value FROM audit_logs 
                     WHERE entity_type = 'salary' AND entity_id = ? AND created_at < ? 
                     ORDER BY created_at DESC LIMIT 1",
                    [$log['entity_id'] ?? 0, $log['created_at'] ?? '']
                );
                
                if ($previousLog) {
                    $prevNewValue = $previousLog['new_value'] ?? '';
                    if (!empty($prevNewValue)) {
                        $prevData = json_decode($prevNewValue, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($prevData['deductions'])) {
                            $oldDeductions = abs(floatval($prevData['deductions'] ?? 0));
                        }
                    }
                }
            }
            
            // حساب الفرق في الخصومات
            $deductionDiff = $deductions - $oldDeductions;
            
            if (abs($deductionDiff) > 0.01) {
                $operationType = $deductionDiff > 0 ? 'خصم' : 'إضافة';
                $description = 'تعديل راتب';
                if ($log['action'] === 'request_salary_modification') {
                    $description = 'طلب تعديل راتب';
                }
                
                // إضافة معلومات إضافية من notes إذا كانت موجودة
                $notes = $log['notes'] ?? '';
                if (!empty($notes)) {
                    $description .= ' - ' . htmlspecialchars(substr($notes, 0, 100));
                }
                
                $commissionAndDeductionLogs[] = [
                    'id' => 'salary_mod_' . $log['id'],
                    'action' => $log['action'],
                    'operation_type' => $operationType,
                    'amount' => abs($deductionDiff),
                    'description' => $description,
                    'created_at' => $log['created_at'],
                    'username' => $log['username'] ?? $log['full_name'] ?? 'نظام'
                ];
            } elseif ($deductions > 0 && $oldDeductions == 0) {
                // إذا كانت هناك خصومات جديدة بدون old_value، اعرضها كخصم
                $description = 'تعديل راتب - خصومات';
                if ($log['action'] === 'request_salary_modification') {
                    $description = 'طلب تعديل راتب - خصومات';
                }
                
                $notes = $log['notes'] ?? '';
                if (!empty($notes)) {
                    $description .= ' - ' . htmlspecialchars(substr($notes, 0, 100));
                }
                
                $commissionAndDeductionLogs[] = [
                    'id' => 'salary_mod_' . $log['id'],
                    'action' => $log['action'],
                    'operation_type' => 'خصم',
                    'amount' => $deductions,
                    'description' => $description,
                    'created_at' => $log['created_at'],
                    'username' => $log['username'] ?? $log['full_name'] ?? 'نظام'
                ];
            }
        }
    }
    
    // ترتيب حسب التاريخ
    usort($commissionAndDeductionLogs, function($a, $b) {
        $timeA = strtotime($a['created_at'] ?? '1970-01-01');
        $timeB = strtotime($b['created_at'] ?? '1970-01-01');
        return $timeB - $timeA;
    });
    
    // أخذ آخر 50 سجل فقط
    $commissionAndDeductionLogs = array_slice($commissionAndDeductionLogs, 0, 50);
    
} catch (Throwable $e) {
    error_log('Error fetching commission and deduction logs: ' . $e->getMessage());
    $commissionAndDeductionLogs = [];
}
?>

<div class="advance-history-table" style="margin-top: 30px;">
    <h3 class="mb-4">سجلات نسبة التحصيلات والخصومات</h3>
    <?php if (!empty($commissionAndDeductionLogs)): ?>
    <table>
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>نوع العملية</th>
                <th>المبلغ</th>
                <th>الوصف</th>
                <th>المستخدم</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($commissionAndDeductionLogs as $log): 
                $date = !empty($log['created_at']) ? date('Y-m-d H:i', strtotime($log['created_at'])) : '—';
                $operationType = $log['operation_type'] ?? '—';
                $amount = $log['amount'] ?? 0;
                $description = $log['description'] ?? '—';
                $username = $log['username'] ?? 'نظام';
                
                // تحديد لون العملية
                $typeClass = '';
                if ($operationType === 'إضافة') {
                    $typeClass = 'text-success';
                } elseif ($operationType === 'خصم') {
                    $typeClass = 'text-danger';
                }
            ?>
            <tr>
                <td><?php echo htmlspecialchars($date); ?></td>
                <td><span class="<?php echo $typeClass; ?> font-weight-bold"><?php echo htmlspecialchars($operationType); ?></span></td>
                <td class="<?php echo $typeClass; ?>">
                    <?php 
                    if ($operationType === 'إضافة') {
                        echo '+';
                    } elseif ($operationType === 'خصم') {
                        echo '-';
                    }
                    echo formatCurrency($amount); 
                    ?>
                </td>
                <td><?php echo htmlspecialchars($description); ?></td>
                <td><?php echo htmlspecialchars($username); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="text-center py-4 text-muted">
        <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
        لا توجد سجلات لنسبة التحصيلات والخصومات
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // التحقق من وجود طلب السلفة في الجدول بعد إعادة تحميل الصفحة
    const urlParams = new URLSearchParams(window.location.search);
    const advanceId = urlParams.get('advance_id');
    
    if (advanceId) {
        // التحقق من وجود الطلب في الجدول
        const advanceTable = document.querySelector('.advance-history-table table tbody');
        let requestFound = false;
        let requestRow = null;
        
        if (advanceTable) {
            const rows = advanceTable.querySelectorAll('tr');
            if (rows.length === 0) {
                // الجدول فارغ - الطلب غير موجود
                requestFound = false;
            } else {
                rows.forEach(row => {
                    const firstCell = row.querySelector('td:first-child');
                    if (firstCell) {
                        const cellText = firstCell.textContent.trim();
                        // البحث عن رقم الطلب في الخلية الأولى (مثل #123)
                        const match = cellText.match(/#(\d+)/);
                        if (match && match[1] === advanceId) {
                            requestFound = true;
                            requestRow = row;
                        }
                    }
                });
            }
        } else {
            // لا يوجد جدول على الإطلاق - الطلب غير موجود
            requestFound = false;
        }
        
        // إزالة رسالة النجاح الافتراضية من session إذا كانت موجودة
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            successAlert.remove();
        }
        
        if (!requestFound) {
            // إذا لم يتم العثور على الطلب في الجدول، عرض رسالة خطأ واضحة في أعلى الصفحة
            const pageAlertContainer = document.getElementById('pageAlertContainer');
            if (pageAlertContainer) {
                // إزالة أي رسائل موجودة مسبقاً
                pageAlertContainer.innerHTML = '';
                
                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger alert-dismissible fade show mb-4';
                errorAlert.style.cssText = 'font-size: 16px; font-weight: 600;';
                errorAlert.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>فشل إرسال طلب السلفة:</strong> لم يتم العثور على الطلب في الجدول. يرجى التحقق من سجل الطلبات أو التواصل مع الإدارة.' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                pageAlertContainer.appendChild(errorAlert);
                
                // تمرير الصفحة إلى أعلى لعرض الرسالة
                setTimeout(() => {
                    pageAlertContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
            
            // عرض رسالة خطأ أيضاً في نموذج طلب السلفة
            const alertContainer = document.getElementById('advanceAlertContainer');
            if (alertContainer) {
                // إزالة أي رسائل موجودة مسبقاً
                alertContainer.innerHTML = '';
                
                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger alert-dismissible fade show';
                errorAlert.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>فشل إرسال طلب السلفة:</strong> لم يتم العثور على الطلب في الجدول. يرجى التحقق من سجل الطلبات أو التواصل مع الإدارة.' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                alertContainer.appendChild(errorAlert);
            }
            
            // إزالة advance_id من URL
            urlParams.delete('advance_id');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
            
            console.error('Advance request verification failed: Request ID ' + advanceId + ' not found in table');
        } else {
            // إذا تم العثور على الطلب، عرض رسالة النجاح في أعلى الصفحة
            const pageAlertContainer = document.getElementById('pageAlertContainer');
            if (pageAlertContainer) {
                const successAlert = document.createElement('div');
                successAlert.className = 'alert alert-success alert-dismissible fade show mb-4';
                successAlert.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i><strong>تم إرسال طلب السلفة بنجاح.</strong> سيتم مراجعته من قبل المحاسب والمدير.' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                pageAlertContainer.appendChild(successAlert);
            }
            
            // عرض رسالة النجاح أيضاً في نموذج طلب السلفة
            const alertContainer = document.getElementById('advanceAlertContainer');
            if (alertContainer) {
                const successAlert = document.createElement('div');
                successAlert.className = 'alert alert-success alert-dismissible fade show';
                successAlert.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>تم إرسال طلب السلفة بنجاح. سيتم مراجعته من قبل المحاسب والمدير.' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                alertContainer.appendChild(successAlert);
            }
            
            // تمييز الصف في الجدول (اختياري)
            if (requestRow) {
                requestRow.style.backgroundColor = '#d1fae5';
                setTimeout(() => {
                    requestRow.style.backgroundColor = '';
                }, 3000);
            }
            
            // إزالة advance_id من URL بعد عرض الرسالة
            setTimeout(() => {
                urlParams.delete('advance_id');
                const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                window.history.replaceState({}, '', newUrl);
            }, 2000);
            
            console.log('Advance request verification passed: Request ID ' + advanceId + ' found in table');
        }
    }
    
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
            // التحقق من أن النموذج غير معطل
            if (submitButton && submitButton.disabled) {
                event.preventDefault();
                event.stopImmediatePropagation();
                
                // عرض رسالة تذكير
                if (alertContainer) {
                    alertContainer.innerHTML = '';
                    const warningAlert = document.createElement('div');
                    warningAlert.className = 'alert alert-warning alert-dismissible fade show';
                    warningAlert.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>يجب تسجيل الانصراف أولاً قبل إرسال طلب السلفة.' +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                    alertContainer.appendChild(warningAlert);
                }
                return;
            }
            
            advanceForm.classList.add('was-validated');
            
            if (!advanceForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
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
                    const message = (data && data.message) ? data.message : 'فشل إرسال طلب السلفة: تعذر الاتصال بالخادم. يرجى التحقق من الاتصال بالإنترنت والمحاولة مرة أخرى.';
                    throw new Error(message);
                }
                
                if (!data) {
                    throw new Error('فشل إرسال طلب السلفة: حدث خطأ غير متوقع في الخادم. يرجى المحاولة لاحقاً أو التواصل مع الإدارة.');
                }
                
                return data;
            })
            .then(data => {
                if (!data.success) {
                    // عرض رسالة الخطأ مباشرة
                    const alert = document.createElement('div');
                    alert.className = 'alert alert-danger alert-dismissible fade show';
                    const message = data.message || 'فشل إرسال طلب السلفة. يرجى المحاولة مرة أخرى أو التواصل مع الإدارة.';
                    alert.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>${message}` +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                    
                    if (alertContainer) {
                        alertContainer.innerHTML = '';
                        alertContainer.appendChild(alert);
                    }
                    
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalButtonHtml;
                    }
                    return;
                }
                
                // في حالة النجاح، لا نعرض رسالة النجاح الآن
                // بل ننتظر إعادة تحميل الصفحة والتحقق من وجود الطلب في الجدول أولاً
                advanceForm.reset();
                advanceForm.classList.remove('was-validated');
                
                // عرض رسالة تحميل مؤقتة
                if (alertContainer) {
                    const loadingAlert = document.createElement('div');
                    loadingAlert.className = 'alert alert-info alert-dismissible fade show';
                    loadingAlert.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>جاري التحقق من حفظ الطلب...' +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                    alertContainer.innerHTML = '';
                    alertContainer.appendChild(loadingAlert);
                }
                
                // إعادة تحميل الصفحة للتحقق من وجود الطلب في الجدول
                if (data.redirect) {
                    setTimeout(function() {
                        window.location.href = data.redirect;
                    }, 500);
                } else {
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                }
            })
            .catch(error => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
                }
                
                // عرض رسالة خطأ واضحة
                let errorMessage = error.message || 'فشل إرسال طلب السلفة: حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى أو التواصل مع الإدارة.';
                
                // إذا كانت الرسالة لا تبدأ بـ "فشل إرسال طلب السلفة"، أضفها
                if (!errorMessage.includes('فشل إرسال طلب السلفة')) {
                    errorMessage = 'فشل إرسال طلب السلفة: ' + errorMessage;
                }
                
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger alert-dismissible fade show';
                alert.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>${errorMessage}` +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                
                if (alertContainer) {
                    alertContainer.innerHTML = '';
                    alertContainer.appendChild(alert);
                }
                
                // تسجيل الخطأ في console للمطورين
                console.error('Advance request error:', error);
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
