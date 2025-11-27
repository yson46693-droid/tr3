<?php

declare(strict_types=1);

// تعريف ACCESS_ALLOWED قبل تضمين أي ملفات
define('ACCESS_ALLOWED', true);

// إرسال header JSON أولاً
header('Content-Type: application/json; charset=utf-8');

// منع أي output قبل JSON
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// التحقق من الصلاحيات بدون استخدام requireLogin الذي قد يطبع HTML
try {
    // بدء الجلسة إذا لم تكن بدأت
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // التحقق من تسجيل الدخول
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'يجب تسجيل الدخول أولاً'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $currentRole = strtolower((string)($_SESSION['role'] ?? ''));
    
    if (!in_array($currentRole, ['manager', 'accountant'], true)) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'غير مصرح لك بالوصول'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $authError) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => 'خطأ في المصادقة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تنظيف أي output غير مرغوب فيه
ob_end_clean();

$db = db();
$repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;

if ($repId <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'معرف المندوب غير صحيح'
    ]);
    exit;
}

try {
    // جلب معلومات المندوب
    $rep = $db->queryOne(
        "SELECT id, full_name, username, phone, email, status 
         FROM users 
         WHERE id = ? AND role = 'sales' 
         LIMIT 1",
        [$repId]
    );
    
    if (!$rep) {
        echo json_encode([
            'success' => false,
            'error' => 'المندوب غير موجود'
        ]);
        exit;
    }
    
    // حساب الإحصائيات
    $stats = $db->queryOne(
        "SELECT 
            COUNT(DISTINCT id) AS customer_count,
            COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt,
            COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count
        FROM customers
        WHERE rep_id = ? OR created_by = ?",
        [$repId, $repId]
    );
    
    // جلب قائمة العملاء
    $customers = $db->query(
        "SELECT 
            id,
            name,
            phone,
            email,
            balance,
            status,
            latitude,
            longitude,
            location_captured_at,
            created_at
        FROM customers
        WHERE rep_id = ? OR created_by = ?
        ORDER BY name ASC
        LIMIT 50",
        [$repId, $repId]
    );
    
    // جلب التحصيلات
    $collections = [];
    try {
        $collectionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
        if (!empty($collectionsTableCheck)) {
            $hasStatus = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
            if (!empty($hasStatus)) {
                $collections = $db->query(
                    "SELECT 
                        c.id,
                        c.amount,
                        c.date,
                        c.created_at,
                        c.status,
                        cust.name AS customer_name
                    FROM collections c
                    LEFT JOIN customers cust ON c.customer_id = cust.id
                    WHERE c.collected_by = ? AND c.status IN ('pending', 'approved')
                    ORDER BY c.date DESC, c.created_at DESC
                    LIMIT 20",
                    [$repId]
                );
            } else {
                $collections = $db->query(
                    "SELECT 
                        c.id,
                        c.amount,
                        c.date,
                        c.created_at,
                        cust.name AS customer_name
                    FROM collections c
                    LEFT JOIN customers cust ON c.customer_id = cust.id
                    WHERE c.collected_by = ?
                    ORDER BY c.date DESC, c.created_at DESC
                    LIMIT 20",
                    [$repId]
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Collections query error: ' . $e->getMessage());
    }
    
    // حساب إجمالي التحصيلات
    $totalCollections = 0.0;
    foreach ($collections as $collection) {
        $totalCollections += (float)($collection['amount'] ?? 0.0);
    }
    
    // جلب المرتجعات
    $returns = [];
    try {
        $returnsTableCheck = $db->queryOne("SHOW TABLES LIKE 'returns'");
        if (!empty($returnsTableCheck)) {
            $returns = $db->query(
                "SELECT 
                    r.id,
                    r.refund_amount,
                    r.return_date,
                    r.created_at,
                    r.status,
                    c.name AS customer_name
                FROM returns r
                LEFT JOIN customers c ON r.customer_id = c.id
                WHERE r.sales_rep_id = ? AND r.status IN ('approved', 'processed', 'completed')
                ORDER BY r.return_date DESC, r.created_at DESC
                LIMIT 20",
                [$repId]
            );
        }
    } catch (Throwable $e) {
        error_log('Returns query error: ' . $e->getMessage());
    }
    
    // حساب إجمالي المرتجعات
    $totalReturns = 0.0;
    foreach ($returns as $returnItem) {
        $totalReturns += (float)($returnItem['refund_amount'] ?? 0.0);
    }
    
    echo json_encode([
        'success' => true,
        'rep' => $rep,
        'stats' => [
            'customer_count' => (int)($stats['customer_count'] ?? 0),
            'total_debt' => (float)($stats['total_debt'] ?? 0.0),
            'debtor_count' => (int)($stats['debtor_count'] ?? 0),
            'total_collections' => $totalCollections,
            'total_returns' => $totalReturns,
        ],
        'customers' => $customers,
        'collections' => $collections,
        'returns' => $returns,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('Get rep details error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'حدث خطأ أثناء جلب البيانات'
    ], JSON_UNESCAPED_UNICODE);
}

