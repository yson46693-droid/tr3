<?php
declare(strict_types=1);

// تعريف ACCESS_ALLOWED قبل تضمين أي ملفات
define('ACCESS_ALLOWED', true);

// تعطيل عرض الأخطاء في المتصفح لمنع HTML في JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

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
    require_once __DIR__ . '/../includes/functions.php';
    
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
    error_log('Error in get_user_salaries.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الصلاحيات'], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_clean();

try {
    $userId = intval($_GET['user_id'] ?? 0);
    
    if ($userId <= 0) {
        http_response_code(400);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'معرف المستخدم غير صحيح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $db = db();
    
    // جلب جميع الرواتب للموظف مع حساب المتبقي
    $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
    $hasYearColumn = !empty($yearColumnCheck);
    
    $query = "SELECT s.*, 
                     COALESCE(s.accumulated_amount, s.total_amount) as calculated_accumulated,
                     COALESCE(s.paid_amount, 0) as paid_amount,
                     (COALESCE(s.accumulated_amount, s.total_amount) - COALESCE(s.paid_amount, 0)) as remaining";
    if ($hasYearColumn) {
        $query .= ", CONCAT(s.year, '-', LPAD(s.month, 2, '0')) as month_label";
    } else {
        $query .= ", DATE_FORMAT(s.month, '%Y-%m') as month_label";
    }
    $query .= " FROM salaries s 
               WHERE s.user_id = ? 
               ORDER BY " . ($hasYearColumn ? "s.year DESC, s.month DESC" : "s.month DESC");
    
    $salaries = $db->query($query, [$userId]);
    
    $monthNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];
    
    $formattedSalaries = [];
    foreach ($salaries as $salary) {
        $month = intval($salary['month'] ?? 0);
        $year = intval($salary['year'] ?? date('Y'));
        
        if ($hasYearColumn) {
            $monthLabel = $monthNames[$month] . ' ' . $year;
        } else {
            $date = date_create_from_format('Y-m', $salary['month_label']);
            if ($date) {
                $monthLabel = $monthNames[$date->format('n')] . ' ' . $date->format('Y');
            } else {
                $monthLabel = $salary['month_label'];
            }
        }
        
        $formattedSalaries[] = [
            'id' => intval($salary['id']),
            'month' => $month,
            'year' => $year,
            'month_label' => $monthLabel,
            'total_amount' => floatval($salary['total_amount'] ?? 0),
            'accumulated_amount' => floatval($salary['calculated_accumulated'] ?? 0),
            'paid_amount' => floatval($salary['paid_amount'] ?? 0),
            'remaining' => max(0, floatval($salary['remaining'] ?? 0)),
            'status' => $salary['status'] ?? 'calculated'
        ];
    }
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'salaries' => $formattedSalaries
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error in get_user_salaries.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ داخلي في الخادم'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

