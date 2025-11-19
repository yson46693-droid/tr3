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
    
    // الحصول على معلومات المستخدم للتحقق من الدور
    $db = db();
    $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
    $isSales = ($user['role'] ?? '') === 'sales';
    
    $stats = [
        'total_hours' => 0,
        'total_salary' => 0,
        'collections_bonus' => 0,
        'collections_amount' => 0,
        'max_advance' => 0
    ];
    
    if ($salaryData['exists']) {
        $salary = $salaryData['salary'];
        $stats['total_hours'] = $salary['total_hours'] ?? 0;
        $stats['total_salary'] = cleanFinancialValue($salary['total_amount'] ?? 0);
        
        // حساب مكافأة التحصيلات - إعادة الحساب دائماً للتأكد من الدقة
        $collectionsBonusValue = cleanFinancialValue($salary['collections_bonus'] ?? 0);
        $collectionsBaseAmount = cleanFinancialValue($salary['collections_amount'] ?? 0);
        
        // إذا كان مندوب مبيعات، أعد حساب مكافأة التحصيلات من التحصيلات الفعلية
        if ($isSales) {
            $recalculatedCollectionsAmount = calculateSalesCollections($userId, $month, $year);
            $recalculatedCollectionsBonus = round($recalculatedCollectionsAmount * 0.02, 2);
            
            // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
            if ($recalculatedCollectionsBonus > $collectionsBonusValue || $collectionsBonusValue == 0) {
                $collectionsBonusValue = $recalculatedCollectionsBonus;
                $collectionsBaseAmount = $recalculatedCollectionsAmount;
            }
        }
        
        $stats['collections_bonus'] = $collectionsBonusValue;
        $stats['collections_amount'] = $collectionsBaseAmount;
        $stats['max_advance'] = cleanFinancialValue($stats['total_salary'] * 0.5);
    } else if (isset($salaryData['calculation']) && $salaryData['calculation']['success']) {
        $calc = $salaryData['calculation'];
        $stats['total_hours'] = $calc['total_hours'] ?? 0;
        $stats['total_salary'] = cleanFinancialValue($calc['total_amount'] ?? 0);
        
        // حساب مكافأة التحصيلات - إعادة الحساب دائماً للتأكد من الدقة
        $collectionsBonusValue = cleanFinancialValue($calc['collections_bonus'] ?? 0);
        $collectionsBaseAmount = cleanFinancialValue($calc['collections_amount'] ?? 0);
        
        // إذا كان مندوب مبيعات، أعد حساب مكافأة التحصيلات من التحصيلات الفعلية
        if ($isSales) {
            $recalculatedCollectionsAmount = calculateSalesCollections($userId, $month, $year);
            $recalculatedCollectionsBonus = round($recalculatedCollectionsAmount * 0.02, 2);
            
            // استخدم القيمة المحسوبة حديثاً إذا كانت أكبر من القيمة المحفوظة
            if ($recalculatedCollectionsBonus > $collectionsBonusValue || $collectionsBonusValue == 0) {
                $collectionsBonusValue = $recalculatedCollectionsBonus;
                $collectionsBaseAmount = $recalculatedCollectionsAmount;
            }
        }
        
        $stats['collections_bonus'] = $collectionsBonusValue;
        $stats['collections_amount'] = $collectionsBaseAmount;
        $stats['max_advance'] = cleanFinancialValue($stats['total_salary'] * 0.5);
    } else if ($isSales) {
        // حتى لو لم يكن هناك راتب محفوظ، احسب مكافأة التحصيلات
        $collectionsAmount = calculateSalesCollections($userId, $month, $year);
        $stats['collections_bonus'] = round($collectionsAmount * 0.02, 2);
        $stats['collections_amount'] = $collectionsAmount;
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

