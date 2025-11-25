<?php
/**
 * API: إحصائيات الراتب للمستخدم
 */

header('Content-Type: application/json; charset=utf-8');

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/salary_calculator.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

$currentUser = getCurrentUser();

// التحقق من أن المستخدم يطلب إحصائياته الخاصة أو أن المحاسب يطلب إحصائيات أي مستخدم
if ($userId > 0 && $userId != $currentUser['id'] && $currentUser['role'] !== 'accountant' && $currentUser['role'] !== 'manager') {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userId <= 0) {
    $userId = $currentUser['id'];
}

try {
    $salaryData = getSalarySummary($userId, $month, $year);
    
    $stats = [
        'total_hours' => 0,
        'total_salary' => 0,
        'collections_bonus' => 0,
        'max_advance' => 0
    ];
    
    if ($salaryData['exists']) {
        $salary = $salaryData['salary'];
        $stats['total_hours'] = $salary['total_hours'] ?? 0;
        $stats['total_salary'] = cleanFinancialValue($salary['total_amount'] ?? 0);
        $stats['collections_bonus'] = 0; // سيتم حسابها من الراتب
        $stats['max_advance'] = cleanFinancialValue($stats['total_salary'] * 0.5);
    } else if (isset($salaryData['calculation']) && $salaryData['calculation']['success']) {
        $calc = $salaryData['calculation'];
        $stats['total_hours'] = $calc['total_hours'] ?? 0;
        $stats['total_salary'] = cleanFinancialValue($calc['total_amount'] ?? 0);
        $stats['collections_bonus'] = cleanFinancialValue($calc['collections_bonus'] ?? 0);
        $stats['max_advance'] = cleanFinancialValue($stats['total_salary'] * 0.5);
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Salary Stats API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

