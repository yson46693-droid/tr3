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
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error loading includes in get_user_salaries.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'خطأ في تحميل الملفات المطلوبة: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من تسجيل الدخول
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // تسجيل معلومات الـ session للتشخيص
    error_log('get_user_salaries.php - Session status: ' . session_status());
    error_log('get_user_salaries.php - Session user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
    error_log('get_user_salaries.php - Session role: ' . ($_SESSION['role'] ?? 'NOT SET'));
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        ob_end_clean();
        error_log('get_user_salaries.php - User not logged in. user_id: ' . ($_SESSION['user_id'] ?? 'empty') . ', role: ' . ($_SESSION['role'] ?? 'empty'));
        echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول أولاً'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error checking session in get_user_salaries.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الصلاحيات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    // التحقق من وجود الأعمدة بشكل آمن
    $hasYearColumn = false;
    $isMonthDate = false;
    
    try {
        $yearColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries LIKE 'year'");
        $hasYearColumn = !empty($yearColumnCheck);
    } catch (Exception $e) {
        error_log('Error checking year column: ' . $e->getMessage());
        // افتراض أن year غير موجود في حالة الخطأ
        $hasYearColumn = false;
    }
    
    // التحقق من نوع عمود month بشكل آمن
    try {
        $monthColumnCheck = $db->queryOne("SHOW COLUMNS FROM salaries WHERE Field = 'month'");
        if (!empty($monthColumnCheck) && isset($monthColumnCheck['Type'])) {
            $monthType = $monthColumnCheck['Type'];
            $isMonthDate = stripos($monthType, 'date') !== false;
        }
    } catch (Exception $e) {
        error_log('Error checking month column: ' . $e->getMessage());
        // افتراض أن month من نوع INT في حالة الخطأ
        $isMonthDate = false;
    }
    
    // بناء الاستعلام - تبسيط الشروط لتشمل جميع الرواتب
    $query = "SELECT s.*, 
                     COALESCE(s.accumulated_amount, s.total_amount, 0) as calculated_accumulated,
                     COALESCE(s.paid_amount, 0) as paid_amount,
                     (COALESCE(s.accumulated_amount, s.total_amount, 0) - COALESCE(s.paid_amount, 0)) as remaining";
    
    if ($hasYearColumn) {
        // إذا كان month من نوع INT و year موجود
        $query .= ", CASE 
                       WHEN s.month > 0 AND s.month <= 12 AND s.year > 0 AND s.year <= 9999 
                       THEN CONCAT(s.year, '-', LPAD(s.month, 2, '0'))
                       ELSE CONCAT(COALESCE(s.year, YEAR(CURDATE())), '-', LPAD(COALESCE(s.month, MONTH(CURDATE())), 2, '0'))
                    END as month_label";
        // تبسيط WHERE clause - فقط التحقق من user_id
        $whereClause = "WHERE s.user_id = ?";
        $orderClause = "ORDER BY COALESCE(s.year, YEAR(CURDATE())) DESC, COALESCE(s.month, MONTH(CURDATE())) DESC, s.id DESC";
    } else {
        // إذا كان month من نوع DATE
        if ($isMonthDate) {
            $query .= ", CASE 
                           WHEN s.month IS NOT NULL AND s.month != '0000-00-00' AND s.month != '1970-01-01'
                           THEN DATE_FORMAT(s.month, '%Y-%m')
                           ELSE DATE_FORMAT(CURDATE(), '%Y-%m')
                        END as month_label";
            // تبسيط WHERE clause - فقط التحقق من user_id
            $whereClause = "WHERE s.user_id = ?";
            $orderClause = "ORDER BY s.month DESC, s.id DESC";
        } else {
            // إذا كان month من نوع INT بدون year
            $query .= ", DATE_FORMAT(CONCAT(YEAR(CURDATE()), '-', LPAD(COALESCE(s.month, MONTH(CURDATE())), 2, '0'), '-01'), '%Y-%m') as month_label";
            // تبسيط WHERE clause - فقط التحقق من user_id
            $whereClause = "WHERE s.user_id = ?";
            $orderClause = "ORDER BY s.month DESC, s.id DESC";
        }
    }
    
    $query .= " FROM salaries s " . $whereClause . " " . $orderClause;
    
    // تسجيل الاستعلام للتdebugging
    error_log("get_user_salaries.php query: " . $query);
    error_log("get_user_salaries.php params: " . json_encode([$userId]));
    error_log("get_user_salaries.php hasYearColumn: " . ($hasYearColumn ? 'true' : 'false'));
    error_log("get_user_salaries.php isMonthDate: " . ($isMonthDate ? 'true' : 'false'));
    
    // تنفيذ الاستعلام مع معالجة الأخطاء
    try {
        $salaries = $db->query($query, [$userId]);
        if (!is_array($salaries)) {
            error_log("get_user_salaries.php: query returned non-array result");
            $salaries = [];
        }
    } catch (Exception $e) {
        error_log("get_user_salaries.php: Database query error: " . $e->getMessage());
        error_log("get_user_salaries.php: Query was: " . $query);
        throw new Exception("خطأ في جلب الرواتب من قاعدة البيانات: " . $e->getMessage(), 0, $e);
    }
    
    // تسجيل عدد الرواتب المسترجعة
    error_log("get_user_salaries.php found " . count($salaries) . " salaries for user_id: " . $userId);
    
    // التحقق من وجود رواتب
    if (empty($salaries) || count($salaries) === 0) {
        error_log("get_user_salaries.php: No salaries found for user_id: " . $userId);
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'salaries' => [],
            'message' => 'لا توجد رواتب مسجلة لهذا الموظف'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $monthNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];
    
    $formattedSalaries = [];
    $monthYearMap = []; // لتجميع الرواتب حسب الشهر والسنة
    
    foreach ($salaries as $salary) {
        try {
            // التحقق من وجود user_id في الراتب
            if (empty($salary['user_id']) || intval($salary['user_id']) <= 0) {
                error_log("get_user_salaries.php: Skipping salary ID " . ($salary['id'] ?? 'unknown') . " - missing or invalid user_id");
                continue;
            }
            
            $month = 0;
            $year = date('Y');
            $monthLabel = 'غير محدد';
        
        // تحديد الشهر والسنة بناءً على نوع عمود month
        if ($hasYearColumn) {
            // month من نوع INT و year موجود
            $month = intval($salary['month'] ?? 0);
            $year = intval($salary['year'] ?? date('Y'));
            
            // استخدام القيم الافتراضية إذا كانت غير صحيحة
            if ($month <= 0 || $month > 12) {
                $month = date('n');
            }
            if ($year <= 0 || $year > 9999) {
                $year = date('Y');
            }
            
            $monthLabel = ($monthNames[$month] ?? 'شهر غير معروف') . ' ' . $year;
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
                        if (!empty($salary['month_label'])) {
                            $date = date_create_from_format('Y-m', $salary['month_label']);
                            if ($date) {
                                $year = intval($date->format('Y'));
                                $month = intval($date->format('n'));
                                $monthLabel = $monthNames[$month] . ' ' . $year;
                            } else {
                                // استخدام التاريخ الحالي كافتراضي
                                $year = date('Y');
                                $month = date('n');
                                $monthLabel = $monthNames[$month] . ' ' . $year;
                            }
                        } else {
                            // استخدام التاريخ الحالي كافتراضي
                            $year = date('Y');
                            $month = date('n');
                            $monthLabel = $monthNames[$month] . ' ' . $year;
                        }
                    }
                } else {
                    // استخدام التاريخ الحالي كافتراضي
                    $year = date('Y');
                    $month = date('n');
                    $monthLabel = $monthNames[$month] . ' ' . $year;
                }
            } else {
                // month من نوع INT بدون year
                $month = intval($salary['month'] ?? 0);
                if ($month <= 0 || $month > 12) {
                    $month = date('n');
                }
                $year = date('Y'); // استخدام السنة الحالية كافتراضي
                $monthLabel = $monthNames[$month] . ' ' . $year;
            }
        }
        
        // حساب المبلغ التراكمي بشكل صحيح (نفس طريقة get_salary_details.php)
        // استخدام نفس طريقة حساب الراتب من المكونات كما في بطاقة الموظف
        // الراتب الإجمالي = الراتب الأساسي + المكافآت + نسبة التحصيلات - الخصومات
        require_once __DIR__ . '/../includes/salary_calculator.php';
        
        $baseAmount = cleanFinancialValue($salary['base_amount'] ?? 0);
        $bonus = cleanFinancialValue($salary['bonus_standardized'] ?? ($salary['bonus'] ?? $salary['bonuses'] ?? 0));
        $deductions = cleanFinancialValue($salary['deductions'] ?? 0);
        $collectionsBonus = cleanFinancialValue($salary['collections_bonus'] ?? 0);
        
        // حساب الراتب الإجمالي من المكونات (مطابق لبطاقة الموظف)
        $currentTotal = round($baseAmount + $bonus + $collectionsBonus - $deductions, 2);
        
        // التأكد من أن الراتب الإجمالي لا يكون سالباً
        $currentTotal = max(0, $currentTotal);
        
        $salaryId = intval($salary['id'] ?? 0);
        
        // استخدام الدالة المشتركة لحساب المبلغ التراكمي
        $accumulatedData = calculateSalaryAccumulatedAmount(
            $userId, 
            $salaryId, 
            $currentTotal, 
            $month, 
            $year, 
            $db
        );
        
        $accumulated = $accumulatedData['accumulated'];
        $paid = floatval($salary['paid_amount'] ?? 0);
        $remaining = max(0, $accumulated - $paid);
        
        // عرض جميع الرواتب حتى لو كان المتبقي صفر (لإتاحة التسوية الكاملة)
        // يمكن تغيير هذا الشرط إذا أردت تصفية الرواتب المدفوعة بالكامل
        // if ($remaining < 0.01) {
        //     continue; // تخطي هذا الراتب
        // }
        
        // التأكد من أن month_label موجود دائماً
        if (empty($monthLabel) || $monthLabel === 'غير محدد') {
            // إعادة إنشاء month_label إذا كان فارغاً
            if ($month >= 1 && $month <= 12 && $year > 0 && $year <= 9999) {
                $monthLabel = ($monthNames[$month] ?? 'شهر غير معروف') . ' ' . $year;
            } else {
                // استخدام القيم الافتراضية
                $month = date('n');
                $year = date('Y');
                $monthLabel = ($monthNames[$month] ?? 'شهر غير معروف') . ' ' . $year;
            }
        }
        
        // إنشاء مفتاح فريد للشهر والسنة
        $monthYearKey = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
        
        // إذا كان هناك راتب آخر لنفس الشهر والسنة، نأخذ الراتب الأحدث (id أكبر)
        if (isset($monthYearMap[$monthYearKey])) {
            $existingId = intval($monthYearMap[$monthYearKey]['id']);
            $currentId = intval($salary['id'] ?? 0);
            
            // إذا كان الراتب الحالي أحدث (id أكبر)، نستبدله
            if ($currentId > $existingId) {
                $monthYearMap[$monthYearKey] = [
                    'id' => $salaryId,
                    'month' => $month,
                    'year' => $year,
                    'month_label' => $monthLabel,
                    'total_amount' => $currentTotal,
                    'accumulated_amount' => $accumulated,
                    'paid_amount' => $paid,
                    'remaining' => $remaining,
                    'status' => $salary['status'] ?? 'calculated'
                ];
            }
        } else {
            // إضافة الراتب الجديد
            $monthYearMap[$monthYearKey] = [
                'id' => $salaryId,
                'month' => $month,
                'year' => $year,
                'month_label' => $monthLabel,
                'total_amount' => $currentTotal,
                'accumulated_amount' => $accumulated,
                'paid_amount' => $paid,
                'remaining' => $remaining,
                'status' => $salary['status'] ?? 'calculated'
            ];
        }
        } catch (Exception $e) {
            // تسجيل الخطأ ولكن الاستمرار في معالجة الرواتب الأخرى
            error_log("Error processing salary ID " . ($salary['id'] ?? 'unknown') . ": " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            continue; // تخطي هذا الراتب والمتابعة مع البقية
        } catch (Throwable $e) {
            // معالجة جميع أنواع الأخطاء
            error_log("Fatal error processing salary ID " . ($salary['id'] ?? 'unknown') . ": " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            continue; // تخطي هذا الراتب والمتابعة مع البقية
        }
    }
    
    // تحويل الخريطة إلى مصفوفة وترتيبها حسب السنة والشهر (الأحدث أولاً)
    $formattedSalaries = array_values($monthYearMap);
    usort($formattedSalaries, function($a, $b) {
        if ($a['year'] != $b['year']) {
            return $b['year'] - $a['year']; // الأحدث أولاً
        }
        if ($a['month'] != $b['month']) {
            return $b['month'] - $a['month']; // الأحدث أولاً
        }
        return $b['id'] - $a['id']; // الأحدث أولاً
    });
    
    // تسجيل عدد الرواتب المعالجة
    error_log("get_user_salaries.php formatted " . count($formattedSalaries) . " salaries for user_id: " . $userId);
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'salaries' => $formattedSalaries
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    ob_end_clean();
    $errorMessage = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    
    error_log('Error in get_user_salaries.php: ' . $errorMessage);
    error_log('Stack trace: ' . $errorTrace);
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    
    // في بيئة التطوير، عرض رسالة الخطأ الفعلية للمساعدة في التشخيص
    // في بيئة الإنتاج، استخدم رسالة عامة
    $isDevelopment = (defined('DEBUG_MODE') && DEBUG_MODE) || 
                     (isset($_SERVER['SERVER_NAME']) && 
                      (strpos($_SERVER['SERVER_NAME'], 'localhost') !== false || 
                       strpos($_SERVER['SERVER_NAME'], '127.0.0.1') !== false));
    
    $displayMessage = $isDevelopment 
        ? 'حدث خطأ داخلي في الخادم: ' . $errorMessage 
        : 'حدث خطأ داخلي في الخادم. يرجى المحاولة مرة أخرى أو الاتصال بالدعم الفني.';
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $displayMessage,
        'debug' => $isDevelopment ? [
            'error' => $errorMessage,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

