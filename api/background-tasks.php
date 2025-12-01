<?php
/**
 * API للعمليات الخلفية الثقيلة
 * يتم استدعاؤها بشكل غير متزامن بعد تحميل الصفحة لتحسين الأداء
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// تعيين timeout أطول للعمليات الخلفية
set_time_limit(30); // 30 ثانية
ignore_user_abort(true); // الاستمرار حتى لو ألغى المستخدم الطلب

header('Content-Type: application/json; charset=utf-8');

// قائمة العمليات المطلوب تنفيذها
$results = [];

// 1. معالجة تنبيهات الحضور والانصراف
if (function_exists('handleAttendanceRemindersForUser')) {
    try {
        handleAttendanceRemindersForUser($currentUser);
        $results['attendance_reminders'] = ['success' => true];
    } catch (Throwable $e) {
        error_log('Background task: Attendance reminders error: ' . $e->getMessage());
        $results['attendance_reminders'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 2. معالجة تنبيهات التعبئة اليومية (مرة واحدة يومياً فقط)
if (function_exists('processDailyPackagingAlert')) {
    try {
        // استخدام Cache للتحقق من تنفيذها اليوم
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        if (class_exists('Cache')) {
            $cacheKey = 'packaging_alert_' . date('Y-m-d');
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                processDailyPackagingAlert();
                Cache::put($cacheKey, true, 86400); // 24 ساعة
                $results['packaging_alert'] = ['success' => true];
            } else {
                $results['packaging_alert'] = ['success' => true, 'skipped' => 'already_processed'];
            }
        } else {
            // بدون Cache، تنفيذ مباشرة
            processDailyPackagingAlert();
            $results['packaging_alert'] = ['success' => true];
        }
    } catch (Throwable $e) {
        error_log('Background task: Packaging alert error: ' . $e->getMessage());
        $results['packaging_alert'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 3. معالجة الانصراف التلقائي (مرة واحدة يومياً فقط)
if (function_exists('processAutoCheckoutForMissingEmployees')) {
    try {
        // استخدام Cache للتحقق من تنفيذها اليوم
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        if (class_exists('Cache')) {
            $cacheKey = 'auto_checkout_' . date('Y-m-d');
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                processAutoCheckoutForMissingEmployees();
                Cache::put($cacheKey, true, 86400); // 24 ساعة
                $results['auto_checkout'] = ['success' => true];
            } else {
                $results['auto_checkout'] = ['success' => true, 'skipped' => 'already_processed'];
            }
        } else {
            processAutoCheckoutForMissingEmployees();
            $results['auto_checkout'] = ['success' => true];
        }
    } catch (Throwable $e) {
        error_log('Background task: Auto checkout error: ' . $e->getMessage());
        $results['auto_checkout'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 4. تصفير عداد الإنذارات (مرة واحدة شهرياً فقط)
if (function_exists('resetWarningCountsForNewMonth')) {
    try {
        // استخدام Cache للتحقق من تنفيذها هذا الشهر
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        if (class_exists('Cache')) {
            $cacheKey = 'warning_reset_' . date('Y-m');
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                resetWarningCountsForNewMonth();
                Cache::put($cacheKey, true, 2592000); // 30 يوم
                $results['warning_reset'] = ['success' => true];
            } else {
                $results['warning_reset'] = ['success' => true, 'skipped' => 'already_processed'];
            }
        } else {
            resetWarningCountsForNewMonth();
            $results['warning_reset'] = ['success' => true];
        }
    } catch (Throwable $e) {
        error_log('Background task: Warning reset error: ' . $e->getMessage());
        $results['warning_reset'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// 5. إشعارات المدفوعات للمبيعات
if ($currentUser && strtolower($currentUser['role']) === 'sales') {
    if (function_exists('notifyTodayPaymentSchedules')) {
        try {
            notifyTodayPaymentSchedules((int) ($currentUser['id'] ?? 0));
            $results['payment_notifications'] = ['success' => true];
        } catch (Throwable $e) {
            error_log('Background task: Payment notifications error: ' . $e->getMessage());
            $results['payment_notifications'] = ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// 6. التقارير الشهرية (مرة واحدة شهرياً فقط)
if (function_exists('maybeSendMonthlyProductionDetailedReport')) {
    try {
        // استخدام Cache للتحقق من تنفيذها هذا الشهر
        if (!class_exists('Cache')) {
            require_once __DIR__ . '/../includes/cache.php';
        }
        
        if (class_exists('Cache')) {
            $month = (int) date('n');
            $year = (int) date('Y');
            $cacheKey = 'production_report_' . $year . '_' . $month;
            $alreadyProcessed = Cache::get($cacheKey);
            
            if (!$alreadyProcessed) {
                maybeSendMonthlyProductionDetailedReport($month, $year);
                Cache::put($cacheKey, true, 2592000); // 30 يوم
                $results['production_report'] = ['success' => true];
            } else {
                $results['production_report'] = ['success' => true, 'skipped' => 'already_processed'];
            }
        } else {
            maybeSendMonthlyProductionDetailedReport((int) date('n'), (int) date('Y'));
            $results['production_report'] = ['success' => true];
        }
    } catch (Throwable $e) {
        error_log('Background task: Production report error: ' . $e->getMessage());
        $results['production_report'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// إرجاع النتائج
echo json_encode([
    'success' => true,
    'results' => $results,
    'timestamp' => time()
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

