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
    
    // حساب المبلغ التراكمي من الرواتب السابقة - استخدام الدالة المشتركة
    require_once __DIR__ . '/../includes/salary_calculator.php';
    
    $userId = intval($salary['user_id']);
    $salaryMonth = intval($salary['month'] ?? 0);
    $salaryYear = intval($salary['year'] ?? date('Y'));
    
    // استخدام نفس طريقة حساب الراتب من المكونات كما في بطاقة الموظف
    // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
    // cleanFinancialValue موجودة في config.php الذي تم تحميله في السطر 32
    $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
    $bonus = cleanFinancialValue($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0));
    $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
    $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
    
    // حساب الراتب الإجمالي من المكونات (مطابق لبطاقة الموظف)
    $currentTotal = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
    
    // التأكد من أن الراتب الإجمالي لا يكون سالباً
    $currentTotal = max(0, $currentTotal);
    
    // استخدام الدالة المشتركة لحساب المبلغ التراكمي
    $accumulatedData = calculateSalaryAccumulatedAmount(
        $userId, 
        $salaryId, 
        $currentTotal, 
        $salaryMonth, 
        $salaryYear, 
        $db
    );
    
    $accumulated = $accumulatedData['accumulated'];
    $paid = floatval($salary['paid_amount'] ?? 0);
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

