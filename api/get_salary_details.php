<?php
declare(strict_types=1);

// تعريف ACCESS_ALLOWED قبل تضمين أي ملفات
define('ACCESS_ALLOWED', true);

// تعطيل عرض الأخطاء في المتصفح لمنع HTML في JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// تعيين error handler لمنع عرض الأخطاء
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // منع عرض الخطأ الافتراضي
}, E_ALL);

// تنظيف أي output buffer موجود
while (ob_get_level() > 0) {
    ob_end_clean();
}

// بدء output buffering جديد
ob_start();

// إرسال header JSON أولاً
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    
    // تنظيف أي output تم إنتاجه من الملفات المحملة
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    // التحقق من تسجيل الدخول
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول أولاً'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error in get_salary_details.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الصلاحيات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $salaryId = intval($_GET['salary_id'] ?? 0);
    
    if ($salaryId <= 0) {
        http_response_code(400);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'معرف الراتب غير صحيح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $db = db();
    
    // جلب بيانات الراتب
    $salary = $db->queryOne(
        "SELECT s.*, 
                COALESCE(s.accumulated_amount, s.total_amount) as calculated_accumulated,
                COALESCE(s.paid_amount, 0) as paid_amount,
                (COALESCE(s.accumulated_amount, s.total_amount) - COALESCE(s.paid_amount, 0)) as remaining
         FROM salaries s 
         WHERE s.id = ?",
        [$salaryId]
    );
    
    if (!$salary) {
        http_response_code(404);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'الراتب غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // حساب المبلغ التراكمي من الرواتب السابقة
    // استخدام نفس منطق الحساب المستخدم في بطاقة الموظف
    $userId = intval($salary['user_id']);
    $salaryMonth = intval($salary['month'] ?? 0);
    $salaryYear = intval($salary['year'] ?? date('Y'));
    
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    // استخدام cleanFinancialValue مثل بطاقة الموظف
    require_once __DIR__ . '/../includes/config.php';
    if (!function_exists('cleanFinancialValue')) {
        function cleanFinancialValue($value) {
            return floatval(str_replace(',', '', $value ?? 0));
        }
    }
    
    $currentTotal = cleanFinancialValue($salary['total_amount'] ?? 0);
    $accumulated = $currentTotal; // ابدأ بالراتب الحالي (نفس منطق بطاقة الموظف)
    
    if ($hasYearColumn) {
        $previousSalaries = $db->query(
            "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                    COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
             FROM salaries s
             WHERE s.user_id = ? AND s.id != ? 
             AND (s.year < ? OR (s.year = ? AND s.month < ?))
             ORDER BY s.year ASC, s.month ASC",
            [$userId, $salaryId, $salaryYear, $salaryYear, $salaryMonth]
        );
    } else {
        $previousSalaries = $db->query(
            "SELECT s.total_amount, s.paid_amount, s.accumulated_amount,
                    COALESCE(s.accumulated_amount, s.total_amount) as prev_accumulated
             FROM salaries s
             WHERE s.user_id = ? AND s.id != ? 
             AND s.month < ?
             ORDER BY s.month ASC",
            [$userId, $salaryId, $salaryMonth]
        );
    }
    
    // جمع المتبقي من الرواتب السابقة (نفس منطق بطاقة الموظف)
    foreach ($previousSalaries as $prevSalary) {
        $prevTotal = cleanFinancialValue($prevSalary['total_amount'] ?? 0);
        $prevPaid = cleanFinancialValue($prevSalary['paid_amount'] ?? 0);
        $prevAccumulated = cleanFinancialValue($prevSalary['prev_accumulated'] ?? $prevTotal);
        
        // حساب المتبقي من الراتب السابق (نفس منطق بطاقة الموظف)
        $prevRemaining = max(0, $prevAccumulated - $prevPaid);
        
        // إضافة المتبقي إلى المبلغ التراكمي فقط إذا كان هناك متبقي (نفس منطق بطاقة الموظف)
        if ($prevRemaining > 0.01) {
            $accumulated += $prevRemaining;
        }
    }
    
    $paid = cleanFinancialValue($salary['paid_amount'] ?? 0);
    $remaining = max(0, $accumulated - $paid);
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'salary' => [
            'id' => intval($salary['id']),
            'user_id' => $userId,
            'month' => $salaryMonth,
            'year' => $salaryYear,
            'total_amount' => $currentTotal,
            'calculated_accumulated' => $accumulated,
            'paid_amount' => $paid,
            'remaining' => $remaining,
            'status' => $salary['status'] ?? 'calculated'
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error in get_salary_details.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ داخلي في الخادم'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

