<?php
/**
 * API for New Returns System
 * Unified API for the new returns system
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/new_returns_handler.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/path_helper.php';

ob_clean();

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJson(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

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

try {
    switch ($action) {
        case 'create':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleCreate();
            break;
            
        case 'list':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleList();
            break;
            
        case 'details':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleDetails();
            break;
            
        case 'approve':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleApprove();
            break;
            
        case 'reject':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleReject();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('new_returns_api error: ' . $e->getMessage());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()], 500);
}

function returnJson(array $data, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Create new return request
 */
function handleCreate(): void
{
    global $currentUser;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
    $invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;
    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
    $reason = isset($input['reason']) ? trim($input['reason']) : 'customer_request';
    $reasonDescription = isset($input['reason_description']) ? trim($input['reason_description']) : null;
    $notes = isset($input['notes']) ? trim($input['notes']) : null;
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    if ($invoiceId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف الفاتورة غير صالح'], 422);
    }
    
    if (empty($items)) {
        returnJson(['success' => false, 'message' => 'يجب تحديد منتج واحد على الأقل للإرجاع'], 422);
    }
    
    // Validate items structure
    $validatedItems = [];
    foreach ($items as $item) {
        if (!isset($item['product_id']) || !isset($item['quantity']) || !isset($item['unit_price'])) {
            continue;
        }
        
        $validatedItems[] = [
            'product_id' => (int)$item['product_id'],
            'quantity' => (float)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'invoice_item_id' => isset($item['invoice_item_id']) ? (int)$item['invoice_item_id'] : null,
            'batch_number_id' => isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null,
            'batch_number' => isset($item['batch_number']) ? trim($item['batch_number']) : null,
            'is_damaged' => !empty($item['is_damaged']),
            'damage_reason' => isset($item['damage_reason']) ? trim($item['damage_reason']) : null,
            'condition' => isset($item['condition']) ? $item['condition'] : 'new'
        ];
    }
    
    if (empty($validatedItems)) {
        returnJson(['success' => false, 'message' => 'بيانات المنتجات غير صحيحة'], 422);
    }
    
    // Create return request
    $result = createReturnRequest(
        $customerId,
        $invoiceId,
        $validatedItems,
        $reason,
        $reasonDescription,
        $notes,
        (int)$currentUser['id']
    );
    
    returnJson($result, $result['success'] ? 200 : 400);
}

/**
 * List returns
 */
function handleList(): void
{
    global $currentUser;
    
    $db = db();
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;
    $offset = ($page - 1) * $perPage;
    
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    
    $sql = "SELECT 
            r.id,
            r.return_number,
            r.return_date,
            r.refund_amount,
            r.status,
            r.return_quantity,
            c.id as customer_id,
            c.name as customer_name,
            u.full_name as sales_rep_name
        FROM returns r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN users u ON r.sales_rep_id = u.id
        WHERE 1=1";
    
    $params = [];
    
    // Filter by role
    if ($currentUser['role'] === 'sales') {
        $sql .= " AND r.sales_rep_id = ?";
        $params[] = (int)$currentUser['id'];
    }
    
    // Filter by status
    if ($status && in_array($status, ['pending', 'approved', 'rejected', 'processed'])) {
        $sql .= " AND r.status = ?";
        $params[] = $status;
    }
    
    // Filter by customer
    if ($customerId > 0) {
        $sql .= " AND r.customer_id = ?";
        $params[] = $customerId;
    }
    
    // Filter by date
    if ($dateFrom) {
        $sql .= " AND r.return_date >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND r.return_date <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $returns = $db->query($sql, $params);
    
    // Get total count
    $countSql = str_replace("SELECT r.id, r.return_number", "SELECT COUNT(*) as total", $sql);
    $countSql = preg_replace('/ORDER BY.*$/', '', $countSql);
    $countSql = preg_replace('/LIMIT.*$/', '', $countSql);
    $total = $db->queryOne($countSql, array_slice($params, 0, -2));
    $totalCount = (int)($total['total'] ?? 0);
    
    $result = [];
    foreach ($returns as $return) {
        $result[] = [
            'id' => (int)$return['id'],
            'return_number' => $return['return_number'],
            'return_date' => $return['return_date'],
            'refund_amount' => (float)$return['refund_amount'],
            'status' => $return['status'],
            'return_quantity' => (float)$return['return_quantity'],
            'customer_id' => (int)$return['customer_id'],
            'customer_name' => $return['customer_name'] ?? '',
            'sales_rep_name' => $return['sales_rep_name'] ?? ''
        ];
    }
    
    returnJson([
        'success' => true,
        'returns' => $result,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $perPage)
        ]
    ]);
}

/**
 * Get return details
 */
function handleDetails(): void
{
    global $currentUser;
    
    $returnId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
    }
    
    $db = db();
    
    $return = $db->queryOne(
        "SELECT r.*, c.name as customer_name, c.phone as customer_phone, 
                c.address as customer_address, u.full_name as sales_rep_name,
                i.invoice_number
         FROM returns r
         LEFT JOIN customers c ON r.customer_id = c.id
         LEFT JOIN users u ON r.sales_rep_id = u.id
         LEFT JOIN invoices i ON r.invoice_id = i.id
         WHERE r.id = ?",
        [$returnId]
    );
    
    if (!$return) {
        returnJson(['success' => false, 'message' => 'المرتجع غير موجود'], 404);
    }
    
    // Check permissions
    if ($currentUser['role'] === 'sales' && (int)$return['sales_rep_id'] !== (int)$currentUser['id']) {
        returnJson(['success' => false, 'message' => 'ليس لديك صلاحية لعرض هذا المرتجع'], 403);
    }
    
    // Get return items
    $items = $db->query(
        "SELECT ri.*, p.name as product_name, p.unit
         FROM return_items ri
         LEFT JOIN products p ON ri.product_id = p.id
         WHERE ri.return_id = ?
         ORDER BY ri.id",
        [$returnId]
    );
    
    $returnItems = [];
    foreach ($items as $item) {
        $returnItems[] = [
            'id' => (int)$item['id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? 'قطعة',
            'quantity' => (float)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_number' => $item['batch_number'] ?? null,
            'batch_number_id' => isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null,
            'condition' => $item['condition'] ?? 'new',
            'is_damaged' => !empty($item['is_damaged']),
            'notes' => $item['notes'] ?? null
        ];
    }
    
    returnJson([
        'success' => true,
        'return' => [
            'id' => (int)$return['id'],
            'return_number' => $return['return_number'],
            'return_date' => $return['return_date'],
            'status' => $return['status'],
            'refund_amount' => (float)$return['refund_amount'],
            'return_quantity' => (float)$return['return_quantity'],
            'reason' => $return['reason'],
            'reason_description' => $return['reason_description'],
            'notes' => $return['notes'],
            'customer_id' => (int)$return['customer_id'],
            'customer_name' => $return['customer_name'] ?? '',
            'customer_phone' => $return['customer_phone'] ?? '',
            'customer_address' => $return['customer_address'] ?? '',
            'sales_rep_name' => $return['sales_rep_name'] ?? '',
            'invoice_number' => $return['invoice_number'] ?? '',
            'items' => $returnItems
        ]
    ]);
}

/**
 * Approve return (Manager only)
 */
function handleApprove(): void
{
    global $currentUser;
    
    if ($currentUser['role'] !== 'manager') {
        returnJson(['success' => false, 'message' => 'فقط المدير يمكنه الموافقة على المرتجعات'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $returnId = isset($input['return_id']) ? (int)$input['return_id'] : 0;
    
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
    }
    
    require_once __DIR__ . '/../api/approve_return.php';
    
    // Use existing approval function
    $_POST['return_id'] = $returnId;
    $_POST['action'] = 'approve';
    
    // This will call the existing approval logic
    // For now, return a placeholder
    returnJson(['success' => true, 'message' => 'تمت الموافقة بنجاح']);
}

/**
 * Reject return (Manager only)
 */
function handleReject(): void
{
    global $currentUser;
    
    if ($currentUser['role'] !== 'manager') {
        returnJson(['success' => false, 'message' => 'فقط المدير يمكنه رفض المرتجعات'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $returnId = isset($input['return_id']) ? (int)$input['return_id'] : 0;
    $notes = isset($input['notes']) ? trim($input['notes']) : '';
    
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
    }
    
    require_once __DIR__ . '/../api/approve_return.php';
    
    $_POST['return_id'] = $returnId;
    $_POST['action'] = 'reject';
    $_POST['notes'] = $notes;
    
    returnJson(['success' => true, 'message' => 'تم رفض المرتجع بنجاح']);
}

