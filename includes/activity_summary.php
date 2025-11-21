<?php
/**
 * ملخص الأنشطة السريع
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * الحصول على ملخص الأنشطة للمدير
 */
function getManagerActivitySummary() {
    $db = db();
    
    $summary = [
        'pending_approvals' => 0,
        'low_stock_products' => 0,
        'pending_production' => 0,
        'pending_sales' => 0,
        'recent_activities' => []
    ];
    
    // الموافقات المعلقة
    $result = $db->queryOne("SELECT COUNT(*) as count FROM approvals WHERE status = 'pending'");
    $summary['pending_approvals'] = $result['count'] ?? 0;
    
    // المنتجات منخفضة المخزون
    $result = $db->queryOne(
        "SELECT COUNT(*) as count FROM products WHERE quantity <= min_stock_level AND status = 'active'"
    );
    $summary['low_stock_products'] = $result['count'] ?? 0;
    
    // الإنتاج المعلق
    $result = $db->queryOne("SELECT COUNT(*) as count FROM production WHERE status = 'pending'");
    $summary['pending_production'] = $result['count'] ?? 0;
    
    // المبيعات المعلقة
    $result = $db->queryOne("SELECT COUNT(*) as count FROM sales WHERE status = 'pending'");
    $summary['pending_sales'] = $result['count'] ?? 0;
    
    // الأنشطة الأخيرة (آخر 10 أنشطة)
    $activities = [];
    
    // آخر الموافقات
    $approvals = $db->query(
        "SELECT 'approval' as type, id, created_at, status FROM approvals ORDER BY created_at DESC LIMIT 5"
    );
    foreach ($approvals as $approval) {
        $activities[] = [
            'type' => 'approval',
            'id' => $approval['id'],
            'status' => $approval['status'],
            'date' => $approval['created_at']
        ];
    }
    
    // آخر المعاملات المالية
    $transactions = $db->query(
        "SELECT 'financial' as type, id, created_at, status, amount FROM financial_transactions 
         ORDER BY created_at DESC LIMIT 5"
    );
    foreach ($transactions as $transaction) {
        $activities[] = [
            'type' => 'financial',
            'id' => $transaction['id'],
            'status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'date' => $transaction['created_at']
        ];
    }
    
    // ترتيب حسب التاريخ
    usort($activities, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    $summary['recent_activities'] = array_slice($activities, 0, 10);
    
    return $summary;
}

/**
 * الحصول على ملخص الأنشطة للمحاسب
 */
function getAccountantActivitySummary() {
    $db = db();
    
    $summary = [
        'pending_transactions' => 0,
        'low_stock_products' => 0,
        'unpaid_salaries' => 0,
        'recent_transactions' => []
    ];
    
    // المعاملات المالية المعلقة
    $result = $db->queryOne("SELECT COUNT(*) as count FROM financial_transactions WHERE status = 'pending'");
    $summary['pending_transactions'] = $result['count'] ?? 0;
    
    // المنتجات منخفضة المخزون
    $result = $db->queryOne(
        "SELECT COUNT(*) as count FROM products WHERE quantity <= min_stock_level AND status = 'active'"
    );
    $summary['low_stock_products'] = $result['count'] ?? 0;
    
    // الرواتب غير المدفوعة
    $result = $db->queryOne("SELECT COUNT(*) as count FROM salaries WHERE status != 'paid'");
    $summary['unpaid_salaries'] = $result['count'] ?? 0;
    
    // آخر المعاملات
    $summary['recent_transactions'] = $db->query(
        "SELECT * FROM financial_transactions ORDER BY created_at DESC LIMIT 10"
    );
    
    return $summary;
}

/**
 * الحصول على ملخص الأنشطة لمندوب المبيعات
 */
function getSalesActivitySummary() {
    $db = db();
    
    $summary = [
        'pending_orders' => 0,
        'today_sales' => 0,
        'month_sales' => 0,
        'recent_sales' => []
    ];
    
    // الطلبات المعلقة
    $result = $db->queryOne("SELECT COUNT(*) as count FROM sales WHERE status = 'pending'");
    $summary['pending_orders'] = $result['count'] ?? 0;
    
    // مبيعات اليوم
    $result = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(date) = CURDATE() AND status = 'approved'"
    );
    $summary['today_sales'] = $result['total'] ?? 0;
    
    // مبيعات الشهر
    $result = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as total FROM sales 
         WHERE MONTH(date) = MONTH(NOW()) AND YEAR(date) = YEAR(NOW()) AND status = 'approved'"
    );
    $summary['month_sales'] = $result['total'] ?? 0;
    
    // آخر المبيعات
    $summary['recent_sales'] = $db->query(
        "SELECT s.*, c.name as customer_name FROM sales s 
         LEFT JOIN customers c ON s.customer_id = c.id 
         ORDER BY s.created_at DESC LIMIT 10"
    );
    
    return $summary;
}

/**
 * الحصول على ملخص الأنشطة لعامل الإنتاج
 */
function getProductionActivitySummary() {
    $db = db();
    
    $summary = [
        'today_production' => 0,
        'month_production' => 0,
        'pending_tasks' => 0,
        'recent_production' => []
    ];
    
    // التحقق من وجود عمود date أو production_date
    $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
    $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
    
    $hasDateColumn = !empty($dateColumnCheck);
    $hasProductionDateColumn = !empty($productionDateColumnCheck);
    
    // تحديد اسم عمود التاريخ
    $dateColumn = null;
    if ($hasDateColumn) {
        $dateColumn = 'date';
    } elseif ($hasProductionDateColumn) {
        $dateColumn = 'production_date';
    } else {
        // إذا لم يكن هناك عمود تاريخ، استخدم created_at
        $dateColumn = 'created_at';
    }
    
    // إنتاج اليوم
    if ($dateColumn === 'created_at') {
        $result = $db->queryOne(
            "SELECT COUNT(*) as count FROM production WHERE DATE(created_at) = CURDATE() AND status = 'approved'"
        );
    } else {
        $result = $db->queryOne(
            "SELECT COUNT(*) as count FROM production WHERE DATE($dateColumn) = CURDATE() AND status = 'approved'"
        );
    }
    $summary['today_production'] = $result['count'] ?? 0;
    
    // إنتاج الشهر
    if ($dateColumn === 'created_at') {
        $result = $db->queryOne(
            "SELECT COUNT(*) as count FROM production 
             WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND status = 'approved'"
        );
    } else {
        $result = $db->queryOne(
            "SELECT COUNT(*) as count FROM production 
             WHERE MONTH($dateColumn) = MONTH(NOW()) AND YEAR($dateColumn) = YEAR(NOW()) AND status = 'approved'"
        );
    }
    $summary['month_production'] = $result['count'] ?? 0;
    
    // المهام المعلقة
    $result = $db->queryOne("SELECT COUNT(*) as count FROM production WHERE status = 'pending'");
    $summary['pending_tasks'] = $result['count'] ?? 0;
    
    // آخر الإنتاج
    $summary['recent_production'] = $db->query(
        "SELECT p.*, pr.name as product_name FROM production p 
         LEFT JOIN products pr ON p.product_id = pr.id 
         ORDER BY p.created_at DESC LIMIT 10"
    );
    
    return $summary;
}

/**
 * الحصول على ملخص الأنشطة حسب الدور
 */
function getActivitySummaryByRole($role) {
    switch ($role) {
        case 'manager':
            return getManagerActivitySummary();
        case 'accountant':
            return getAccountantActivitySummary();
        case 'sales':
            return getSalesActivitySummary();
        case 'production':
            return getProductionActivitySummary();
        default:
            return [];
    }
}

