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
    
    // التحقق من نوع عمود month
    $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
    $monthType = $monthColumnCheck['Type'] ?? '';
    $isMonthDate = stripos($monthType, 'date') !== false;
    
    // بناء الاستعلام مع تصفية القيم غير الصحيحة
    $query = "SELECT s.*, 
                     COALESCE(s.accumulated_amount, s.total_amount) as calculated_accumulated,
                     COALESCE(s.paid_amount, 0) as paid_amount,
                     (COALESCE(s.accumulated_amount, s.total_amount) - COALESCE(s.paid_amount, 0)) as remaining";
    
    if ($hasYearColumn) {
        // إذا كان month من نوع INT و year موجود
        $query .= ", CONCAT(s.year, '-', LPAD(s.month, 2, '0')) as month_label";
        $whereClause = "WHERE s.user_id = ? 
                       AND s.month > 0 
                       AND s.month <= 12 
                       AND s.year > 0 
                       AND s.year <= 9999";
        $orderClause = "ORDER BY s.year DESC, s.month DESC";
    } else {
        // إذا كان month من نوع DATE
        if ($isMonthDate) {
            $query .= ", DATE_FORMAT(s.month, '%Y-%m') as month_label";
            $whereClause = "WHERE s.user_id = ? 
                           AND s.month IS NOT NULL 
                           AND s.month != '0000-00-00' 
                           AND s.month != '1970-01-01'
                           AND YEAR(s.month) > 0 
                           AND YEAR(s.month) <= 9999";
            $orderClause = "ORDER BY s.month DESC";
        } else {
            // إذا كان month من نوع INT بدون year
            $query .= ", DATE_FORMAT(CONCAT(YEAR(CURDATE()), '-', LPAD(s.month, 2, '0'), '-01'), '%Y-%m') as month_label";
            $whereClause = "WHERE s.user_id = ? 
                           AND s.month > 0 
                           AND s.month <= 12";
            $orderClause = "ORDER BY s.month DESC";
        }
    }
    
    $query .= " FROM salaries s " . $whereClause . " " . $orderClause;
    
    $salaries = $db->query($query, [$userId]);
    
    $monthNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];
    
    $formattedSalaries = [];
    foreach ($salaries as $salary) {
        $month = 0;
        $year = date('Y');
        $monthLabel = 'غير محدد';
        
        // تحديد الشهر والسنة بناءً على نوع عمود month
        if ($hasYearColumn) {
            // month من نوع INT و year موجود
            $month = intval($salary['month'] ?? 0);
            $year = intval($salary['year'] ?? date('Y'));
            
            if ($month > 0 && $month <= 12 && $year > 0 && $year <= 9999) {
                $monthLabel = $monthNames[$month] . ' ' . $year;
            } else {
                // تخطي الرواتب ذات القيم غير الصحيحة
                continue;
            }
        } else {
            if ($isMonthDate) {
                // month من نوع DATE
                $monthDate = $salary['month'] ?? null;
                if ($monthDate && $monthDate !== '0000-00-00' && $monthDate !== '1970-01-01') {
                    $date = date_create_from_format('Y-m-d', $monthDate);
                    if ($date && $date->format('Y') > 0) {
                        $year = intval($date->format('Y'));
                        $month = intval($date->format('n'));
                        $monthLabel = $monthNames[$month] . ' ' . $year;
                    } else {
                        // محاولة استخدام month_label
                        $date = date_create_from_format('Y-m', $salary['month_label'] ?? '');
                        if ($date) {
                            $year = intval($date->format('Y'));
                            $month = intval($date->format('n'));
                            $monthLabel = $monthNames[$month] . ' ' . $year;
                        } else {
                            continue; // تخطي الرواتب ذات التواريخ غير الصحيحة
                        }
                    }
                } else {
                    continue; // تخطي الرواتب ذات التواريخ غير الصحيحة
                }
            } else {
                // month من نوع INT بدون year
                $month = intval($salary['month'] ?? 0);
                if ($month > 0 && $month <= 12) {
                    $year = date('Y'); // استخدام السنة الحالية كافتراضي
                    $monthLabel = $monthNames[$month] . ' ' . $year;
                } else {
                    continue; // تخطي الرواتب ذات القيم غير الصحيحة
                }
            }
        }
        
        // التأكد من أن جميع القيم صحيحة قبل الإضافة
        if ($month > 0 && $month <= 12 && $year > 0 && $year <= 9999) {
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

