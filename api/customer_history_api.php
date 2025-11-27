<?php
/**
 * API for Customer Purchase History (Full History with Invoices, Returns, Exchanges)
 * Returns complete purchase history in the format expected by the modal
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

// تنظيف أي output قبل إرسال JSON
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/customer_history.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
}

$allowedRoles = ['sales', 'manager', 'accountant'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles, true)) {
    returnJson(['success' => false, 'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'], 403);
}

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if ($customerId <= 0) {
    returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 400);
}

try {
    // الحصول على بيانات سجل المشتريات
    $historyPayload = customerHistoryGetHistory($customerId);
    
    if (!isset($historyPayload['success']) || !$historyPayload['success']) {
        returnJson([
            'success' => false,
            'message' => $historyPayload['message'] ?? 'فشل تحميل سجل المشتريات'
        ], 500);
    }
    
    // التأكد من أن النتيجة في التنسيق الصحيح
    if (!isset($historyPayload['history'])) {
        $historyPayload['history'] = [
            'window_start' => date('Y-m-d', strtotime('-6 months')),
            'invoices' => [],
            'totals' => [
                'invoice_count' => 0,
                'total_invoiced' => 0.0,
                'total_paid' => 0.0,
                'total_returns' => 0.0,
                'total_exchanges' => 0.0,
                'net_total' => 0.0,
            ],
            'returns' => [],
            'exchanges' => [],
        ];
    }
    
    // التأكد من أن totals موجود
    if (!isset($historyPayload['history']['totals'])) {
        $historyPayload['history']['totals'] = [
            'invoice_count' => 0,
            'total_invoiced' => 0.0,
            'total_paid' => 0.0,
            'total_returns' => 0.0,
            'total_exchanges' => 0.0,
            'net_total' => 0.0,
        ];
    }
    
    // التأكد من أن المصفوفات موجودة
    if (!isset($historyPayload['history']['invoices']) || !is_array($historyPayload['history']['invoices'])) {
        $historyPayload['history']['invoices'] = [];
    }
    if (!isset($historyPayload['history']['returns']) || !is_array($historyPayload['history']['returns'])) {
        $historyPayload['history']['returns'] = [];
    }
    if (!isset($historyPayload['history']['exchanges']) || !is_array($historyPayload['history']['exchanges'])) {
        $historyPayload['history']['exchanges'] = [];
    }
    
    // إرجاع JSON
    returnJson($historyPayload);
    
} catch (Throwable $e) {
    error_log('Customer history API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    returnJson([
        'success' => false,
        'message' => 'حدث خطأ أثناء تحميل سجل المشتريات: ' . $e->getMessage()
    ], 500);
}

/**
 * Return JSON response
 */
function returnJson(array $data, int $status = 200): void
{
    // تنظيف أي output قبل إرسال JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

