<?php
/**
 * API موحد لنظام المرتجعات
 * Unified Returns API
 * 
 * هذا الملف يحتوي على جميع طلبات API للمرتجعات:
 * - إنشاء طلبات المرتجعات
 * - جلب العملاء وسجل المشتريات
 * - الموافقة/الرفض على المرتجعات
 * - جلب تفاصيل المرتجعات
 * 
 * تاريخ الإنشاء: 2024
 */
// تعطيل عرض الأخطاء لمنع طباعة HTML في API
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

// تعيين معالج الأخطاء المخصص لمنع عرض HTML
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // تسجيل الخطأ فقط، لا نطبع أي شيء
    error_log("PHP Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}");
    return true; // منع معالج الخطأ الافتراضي
}, E_ALL);

// تعيين معالج الاستثناءات
set_exception_handler(function($exception) {
    // تنظيف أي output buffers
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    error_log("Uncaught Exception: " . $exception->getMessage());
    error_log("Stack trace: " . $exception->getTraceAsString());
    
    @header('Content-Type: application/json; charset=utf-8', true);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ غير متوقع في الخادم'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// تنظيف أي output buffer موجود
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// بدء output buffer جديد
@ob_start();

@header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/returns_system.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/product_name_helper.php';

// تنظيف الـ output buffer مرة أخرى بعد التضمين
while (ob_get_level() > 0) {
    ob_end_clean();
}

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
        case 'get_customers':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetCustomers();
            break;
            
        case 'get_purchase_history':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            try {
                handleGetPurchaseHistory();
            } catch (Throwable $e) {
                error_log('Error in handleGetPurchaseHistory: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                returnJson([
                    'success' => false,
                    'message' => 'حدث خطأ أثناء جلب سجل المشتريات: ' . $e->getMessage()
                ], 500);
            }
            break;
            
        case 'create':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleCreateReturn();
            break;
            
        case 'get_return_details':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetReturnDetails();
            break;
            
        case 'approve':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleApproveReturn();
            break;
            
        case 'reject':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST'], 405);
            }
            handleRejectReturn();
            break;
            
        case 'get_recent_requests':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetRecentRequests();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('returns API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()], 500);
}

function returnJson(array $data, int $status = 200): void
{
    // تنظيف جميع output buffers
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // إزالة أي output غير مرغوب فيه
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    
    // إرسال headers
    @header('Content-Type: application/json; charset=utf-8', true);
    @header('X-Content-Type-Options: nosniff', true);
    http_response_code($status);
    
    // إرسال JSON
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // إنهاء التنفيذ
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    exit;
}

/**
 * جلب العملاء
 */
function handleGetCustomers(): void
{
    global $currentUser;
    
    $db = db();
    
    $salesRepId = null;
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
    } elseif (isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] > 0) {
        $salesRepId = (int)$_GET['sales_rep_id'];
    }
    
    $search = trim($_GET['search'] ?? '');
    
    $sql = "SELECT c.id, c.name, c.phone, c.address, c.balance, c.status
            FROM customers c
            WHERE c.status = 'active'";
    
    $params = [];
    
    if ($salesRepId) {
        $sql .= " AND c.created_by = ?";
        $params[] = $salesRepId;
    }
    
    if ($search !== '') {
        $sql .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY c.name ASC LIMIT 100";
    
    $customers = $db->query($sql, $params);
    
    $result = [];
    foreach ($customers as $customer) {
        $balance = (float)($customer['balance'] ?? 0);
        $result[] = [
            'id' => (int)$customer['id'],
            'name' => $customer['name'],
            'phone' => $customer['phone'] ?? '',
            'address' => $customer['address'] ?? '',
            'balance' => $balance,
            'debt' => $balance > 0 ? $balance : 0,
            'credit' => $balance < 0 ? abs($balance) : 0,
        ];
    }
    
    returnJson(['success' => true, 'customers' => $result]);
}

/**
 * جلب سجل مشتريات العميل
 */
function handleGetPurchaseHistory(): void
{
    global $currentUser;
    
    // تنظيف أي output buffer قبل البدء
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $db = db();
    
    // التحقق من العميل
    $customer = $db->queryOne(
        "SELECT id, name, created_by FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
        if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
            returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
        }
    }
    
    // جلب سجل المشتريات - تحسين الاستعلام
    try {
        $purchaseHistory = $db->query(
            "SELECT 
                i.id as invoice_id,
                i.invoice_number,
                i.date as invoice_date,
                i.total_amount,
                i.paid_amount,
                i.status as invoice_status,
                ii.id as invoice_item_id,
                ii.product_id,
                COALESCE(
                    (SELECT fp2.product_name 
                     FROM finished_products fp2 
                     INNER JOIN batch_numbers bn2 ON fp2.batch_id = bn2.id
                     INNER JOIN sales_batch_numbers sbn2 ON bn2.id = sbn2.batch_number_id
                     WHERE sbn2.invoice_item_id = ii.id
                       AND fp2.product_name IS NOT NULL 
                       AND TRIM(fp2.product_name) != ''
                       AND fp2.product_name NOT LIKE 'منتج رقم%'
                     ORDER BY fp2.id DESC 
                     LIMIT 1),
                    NULLIF(TRIM(p.name), ''),
                    CONCAT('منتج رقم ', ii.product_id)
                ) as product_name,
                COALESCE(p.unit, 'قطعة') as unit,
                ii.quantity,
                ii.unit_price,
                ii.total_price,
                GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers,
                GROUP_CONCAT(DISTINCT CAST(bn.id AS CHAR) ORDER BY bn.id SEPARATOR ',') as batch_number_ids
            FROM invoices i
            INNER JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
            LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
            WHERE i.customer_id = ?
              AND i.status NOT IN ('cancelled', 'draft')
            GROUP BY i.id, i.invoice_number, i.date, i.total_amount, i.paid_amount, i.status, 
                     ii.id, ii.product_id, ii.quantity, ii.unit_price, ii.total_price, p.unit
            ORDER BY i.date DESC, i.id DESC, ii.id ASC",
            [$customerId]
        );
    } catch (Throwable $e) {
        error_log('Error executing purchase history query: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        returnJson([
            'success' => false,
            'message' => 'حدث خطأ أثناء جلب سجل المشتريات: ' . $e->getMessage()
        ], 500);
        return;
    }
    
    // تأكد من أن $purchaseHistory هو array
    if (!is_array($purchaseHistory)) {
        $purchaseHistory = [];
    }

    error_log("Purchase history query returned " . count($purchaseHistory) . " items for customer ID: {$customerId}");
    
    // إرجاع معلومات العميل
    $customerInfo = $db->queryOne(
        "SELECT id, name, phone, address FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (empty($purchaseHistory)) {
        error_log("No purchase history found for customer ID: {$customerId}");
        returnJson([
            'success' => true,
            'purchase_history' => [],
            'customer' => $customerInfo ? [
                'id' => (int)$customerInfo['id'],
                'name' => $customerInfo['name'],
                'phone' => $customerInfo['phone'] ?? '',
                'address' => $customerInfo['address'] ?? ''
            ] : null
        ]);
        return;
    }
    
    // حساب الكميات المرتجعة
    $returnedQuantities = [];
    $hasInvoiceItemId = false;
    try {
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
        $hasInvoiceItemId = !empty($columnCheck);
    } catch (Throwable $e) {
        $hasInvoiceItemId = false;
    }
    
    if ($hasInvoiceItemId) {
        try {
            // حساب الكمية المرتجعة لكل invoice_item_id (مجموع الكميات بغض النظر عن batch_number_id)
            $returnedRows = $db->query(
                "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                 FROM return_items ri
                 INNER JOIN returns r ON r.id = ri.return_id
                 WHERE r.customer_id = ?
                   AND r.status IN ('pending', 'approved', 'processed', 'completed')
                   AND ri.invoice_item_id IS NOT NULL
                 GROUP BY ri.invoice_item_id",
                [$customerId]
            );
            
            foreach ($returnedRows as $row) {
                $invoiceItemId = (int)$row['invoice_item_id'];
                $returnedQuantities[$invoiceItemId] = (float)$row['returned_quantity'];
            }
            error_log("Returned quantities for customer {$customerId}: " . json_encode($returnedQuantities));
        } catch (Throwable $e) {
            error_log('Error calculating returned quantities: ' . $e->getMessage());
            // نستمر في العملية حتى لو فشل حساب الكميات المرتجعة
        }
    }
    
    // إرجاع كل عنصر فاتورة كصف منفصل
    $result = [];

    foreach ($purchaseHistory as $item) {
        $invoiceItemId = (int)$item['invoice_item_id'];
        $productId = (int)$item['product_id'];
        
        // حساب الكمية المرتجعة - مجموع الكميات المرتجعة لكل invoice_item_id
        $returnedQty = 0.0;
        if ($hasInvoiceItemId) {
            $returnedQty = $returnedQuantities[$invoiceItemId] ?? 0.0;
        }
        
        $purchasedQty = (float)$item['quantity'];
        $remainingQty = max(0, round($purchasedQty - $returnedQty, 3));
        
        // تخطي العناصر التي تم إرجاعها بالكامل
        if ($remainingQty <= 0) {
            continue;
        }
        
        // جلب اسم المنتج الحقيقي
        try {
            $finalProductName = resolveProductName([$item['product_name'] ?? null], 'اسم المنتج غير متوفر');
        } catch (Throwable $e) {
            error_log('Error resolving product name: ' . $e->getMessage());
            $finalProductName = $item['product_name'] ?? 'اسم المنتج غير متوفر';
        }
        
        // تحويل batch_numbers من string إلى array
        $batchNumbersArray = [];
        if (!empty($item['batch_numbers'])) {
            $batchNumbersArray = array_map('trim', explode(', ', $item['batch_numbers']));
            $batchNumbersArray = array_filter($batchNumbersArray); // إزالة القيم الفارغة
            $batchNumbersArray = array_values($batchNumbersArray); // إعادة ترقيم المفاتيح
        }
        
        // تحويل batch_number_ids من string إلى array
        $batchNumberIdsArray = [];
        if (!empty($item['batch_number_ids'])) {
            $batchNumberIdsArray = array_map('intval', explode(',', $item['batch_number_ids']));
            $batchNumberIdsArray = array_filter($batchNumberIdsArray); // إزالة القيم الصفرية
            $batchNumberIdsArray = array_values($batchNumberIdsArray); // إعادة ترقيم المفاتيح
        }
        
        $result[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_item_id' => $invoiceItemId,
            'invoice_number' => $item['invoice_number'] ?? '',
            'invoice_date' => !empty($item['invoice_date']) ? date('Y-m-d', strtotime($item['invoice_date'])) : '',
            'product_id' => $productId,
            'product_name' => $finalProductName,
            'unit' => $item['unit'] ?? '',
            'quantity' => $purchasedQty,
            'quantity_purchased' => $purchasedQty,
            'returned_quantity' => $returnedQty,
            'quantity_returned' => $returnedQty,
            'available_to_return' => $remainingQty,
            'quantity_remaining' => $remainingQty,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => $batchNumbersArray, // array
            'batch_number_ids' => $batchNumberIdsArray, // array
            'can_return' => true // يمكن الإرجاع إذا كان هناك كمية متاحة
        ];
    }
    
    // التأكد من أن $result هو array قبل استخدام count()
    if (!is_array($result)) {
        $result = [];
    }
    
    error_log("Processed " . count($result) . " items after filtering for customer ID: {$customerId}");
    
    returnJson([
        'success' => true,
        'purchase_history' => $result,
        'customer' => $customerInfo ? [
            'id' => (int)$customerInfo['id'],
            'name' => $customerInfo['name'],
            'phone' => $customerInfo['phone'] ?? '',
            'address' => $customerInfo['address'] ?? ''
        ] : null
    ]);
}

/**
 * إنشاء طلب مرتجع
 */
function handleCreateReturn(): void
{
    global $currentUser;
    
    // التأكد من توافق قاعدة البيانات قبل البدء
    ensureReturnSchemaCompatibility();
    
    $payload = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($payload)) {
        returnJson(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], 400);
    }
    
    $customerId = isset($payload['customer_id']) ? (int)$payload['customer_id'] : 0;
    $items = $payload['items'] ?? [];
    $notes = trim($payload['notes'] ?? '');
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'يجب اختيار عميل'], 422);
    }
    
    if (empty($items) || !is_array($items)) {
        returnJson(['success' => false, 'message' => 'يجب اختيار منتجات لإرجاعها'], 422);
    }
    
    $db = db();
    $conn = $db->getConnection();
    
    // التحقق من العميل
    $customer = $db->queryOne(
        "SELECT id, name, created_by FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    // التحقق من ملكية العميل
    $salesRepId = (int)($customer['created_by'] ?? 0);
    if ($currentUser['role'] === 'sales' && $salesRepId !== (int)$currentUser['id']) {
        returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
    }
    
    // الحصول على invoice_id من أول عنصر
    $firstInvoiceItemId = 0;
    foreach ($items as $item) {
        if (isset($item['invoice_item_id']) && $item['invoice_item_id'] > 0) {
            $firstInvoiceItemId = (int)$item['invoice_item_id'];
            break;
        }
    }
    
    if ($firstInvoiceItemId <= 0) {
        returnJson(['success' => false, 'message' => 'يجب تحديد عنصر فاتورة صالح'], 422);
    }
    
    // جلب invoice_id
    $invoiceItem = $db->queryOne(
        "SELECT invoice_id FROM invoice_items WHERE id = ?",
        [$firstInvoiceItemId]
    );
    
    if (!$invoiceItem) {
        returnJson(['success' => false, 'message' => 'عنصر الفاتورة غير موجود'], 404);
    }
    
    $invoiceId = (int)$invoiceItem['invoice_id'];
    
    $conn->begin_transaction();
    
    try {
        // توليد رقم المرتجع
        $returnNumber = generateReturnNumber();
        
        // معالجة العناصر
        $selectedItems = [];
        $totalRefund = 0.0;
        $invoiceIds = [];
        
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $invoiceItemId = (int)($item['invoice_item_id'] ?? 0);
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            
            if ($invoiceItemId <= 0 || $quantity <= 0) {
                continue;
            }
            
            // جلب تفاصيل عنصر الفاتورة
            $invoiceItem = $db->queryOne(
                "SELECT ii.*, i.id as invoice_id, i.invoice_number, i.customer_id, i.sales_rep_id,
                        p.name as product_name, p.unit
                 FROM invoice_items ii
                 INNER JOIN invoices i ON ii.invoice_id = i.id
                 LEFT JOIN products p ON ii.product_id = p.id
                 WHERE ii.id = ? AND i.customer_id = ?",
                [$invoiceItemId, $customerId]
            );
            
            if (!$invoiceItem) {
                throw new RuntimeException('عنصر الفاتورة غير موجود أو غير مرتبط بهذا العميل');
            }
            
            // التحقق من الكمية المرتجعة
            $alreadyReturned = getAlreadyReturnedQuantity($invoiceItemId, (int)$invoiceItem['product_id']);
            $returnedQty = (float)$alreadyReturned;
            $purchasedQty = (float)$invoiceItem['quantity'];
            $remainingQty = max(0, round($purchasedQty - $returnedQty, 3));
            
            if ($quantity > $remainingQty + 0.0001) {
                throw new RuntimeException("الكمية المطلوبة للمنتج {$invoiceItem['product_name']} تتجاوز الحد المتاح ({$remainingQty})");
            }
            
            // جلب أرقام التشغيلات
            $batchNumbers = $db->query(
                "SELECT sbn.batch_number_id, bn.batch_number
                 FROM sales_batch_numbers sbn
                 INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                 WHERE sbn.invoice_item_id = ?
                 ORDER BY bn.id ASC",
                [$invoiceItemId]
            );
            
            $batchNumberIds = [];
            $batchNumberStrings = [];
            foreach ($batchNumbers as $bn) {
                $batchNumberIds[] = (int)$bn['batch_number_id'];
                $batchNumberStrings[] = $bn['batch_number'];
            }
            
            $lineTotal = round($quantity * (float)$invoiceItem['unit_price'], 2);
            $totalRefund += $lineTotal;
            
            $selectedItems[] = [
                'invoice_item_id' => $invoiceItemId,
                'invoice_id' => (int)$invoiceItem['invoice_id'],
                'product_id' => (int)$invoiceItem['product_id'],
                'product_name' => $invoiceItem['product_name'] ?? 'غير معروف',
                'quantity' => $quantity,
                'unit_price' => (float)$invoiceItem['unit_price'],
                'total_price' => $lineTotal,
                'batch_number_ids' => $batchNumberIds,
                'batch_numbers' => implode(', ', $batchNumberStrings),
                'is_damaged' => !empty($item['is_damaged']),
                'damage_reason' => $item['damage_reason'] ?? null,
            ];
            
            if (!in_array((int)$invoiceItem['invoice_id'], $invoiceIds)) {
                $invoiceIds[] = (int)$invoiceItem['invoice_id'];
            }
        }
        
        if (empty($selectedItems)) {
            throw new RuntimeException('لم يتم اختيار أي منتجات صالحة للمرتجع');
        }
        
        $totalRefund = round($totalRefund, 2);
        
        // استخدام sales_rep_id من العميل أو من الفاتورة
        if ($salesRepId <= 0 && !empty($invoiceIds)) {
            $firstInvoice = $db->queryOne(
                "SELECT sales_rep_id FROM invoices WHERE id = ?",
                [$invoiceIds[0]]
            );
            $salesRepId = (int)($firstInvoice['sales_rep_id'] ?? 0);
        }
        
        // إنشاء سجل المرتجع
        $db->execute(
            "INSERT INTO returns
             (return_number, invoice_id, customer_id, sales_rep_id, return_date, return_type,
              reason, refund_amount, refund_method, status, notes, created_by)
             VALUES (?, ?, ?, ?, CURDATE(), 'partial', 'customer_request', ?, 'credit', 'pending', ?, ?)",
            [
                $returnNumber,
                $invoiceId,
                $customerId,
                $salesRepId ?: null,
                $totalRefund,
                $notes ?: null,
                $currentUser['id'],
            ]
        );
        
        $returnId = (int)$db->getLastInsertId();
        
        // إنشاء عناصر المرتجع
        foreach ($selectedItems as $item) {
            $batchNumberId = !empty($item['batch_number_ids']) ? $item['batch_number_ids'][0] : null;
            $batchNumber = $item['batch_numbers'] ?? null;
            
            // التحقق من وجود الأعمدة
            $hasInvoiceItemId = false;
            $hasBatchNumberId = false;
            $hasIsDamaged = false;
            
            try {
                $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
                $hasInvoiceItemId = !empty($colCheck);
                
                $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'batch_number_id'");
                $hasBatchNumberId = !empty($colCheck);
                
                $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'is_damaged'");
                $hasIsDamaged = !empty($colCheck);
            } catch (Throwable $e) {
                // تجاهل
            }
            
            $columns = ['return_id', 'product_id', 'quantity', 'unit_price', 'total_price', '`condition`'];
            $values = [$returnId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price'], 'new'];
            
            if ($hasInvoiceItemId) {
                $columns[] = 'invoice_item_id';
                $values[] = $item['invoice_item_id'];
            }
            
            if ($hasBatchNumberId && $batchNumberId) {
                $columns[] = 'batch_number_id';
                $values[] = $batchNumberId;
            }
            
            if ($batchNumber) {
                $columns[] = 'batch_number';
                $values[] = $batchNumber;
            }
            
            if ($hasIsDamaged) {
                $columns[] = 'is_damaged';
                $values[] = $item['is_damaged'] ? 1 : 0;
            }
            
            $columns[] = 'notes';
            $values[] = $item['damage_reason'] ?? null;
            
            $columnsStr = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            
            $db->execute(
                "INSERT INTO return_items ($columnsStr) VALUES ($placeholders)",
                $values
            );
        }
        
        // إنشاء طلب الموافقة
        $approvalNotes = "مرتجع فاتورة رقم: {$returnNumber}\n";
        $approvalNotes .= "العميل: {$customer['name']}\n";
        $approvalNotes .= "المبلغ: " . number_format($totalRefund, 2) . " ج.م\n";
        $approvalNotes .= "عدد المنتجات: " . count($selectedItems);
        
        $approvalResult = requestApproval('return_request', $returnId, $currentUser['id'], $approvalNotes);
        
        if (isset($approvalResult['success']) && !$approvalResult['success']) {
            throw new RuntimeException('فشل إرسال طلب الموافقة: ' . ($approvalResult['message'] ?? 'خطأ غير معروف'));
        }
        
        // التأكد من أن الحالة pending
        $returnStatus = $db->queryOne(
            "SELECT status FROM returns WHERE id = ?",
            [$returnId]
        );
        
        if (!$returnStatus || $returnStatus['status'] !== 'pending') {
            $db->execute(
                "UPDATE returns SET status = 'pending' WHERE id = ?",
                [$returnId]
            );
        }
        
        $conn->commit();
        
        $printUrl = getRelativeUrl('print_return_invoice.php?id=' . $returnId);
        
        returnJson([
            'success' => true,
            'message' => 'تم إنشاء طلب المرتجع بنجاح وتم إرساله للموافقة',
            'return_id' => $returnId,
            'return_number' => $returnNumber,
            'refund_amount' => $totalRefund,
            'status' => 'pending',
            'print_url' => $printUrl
        ]);
        
    } catch (Throwable $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        error_log('Error creating return request: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        returnJson([
            'success' => false,
            'message' => 'حدث خطأ أثناء إنشاء طلب المرتجع: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * جلب تفاصيل المرتجع
 */
function handleGetReturnDetails(): void
{
    global $currentUser;
    
    $returnId = isset($_GET['return_id']) ? (int)$_GET['return_id'] : 0;
    
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
    }
    
    $db = db();
    
    $return = $db->queryOne(
        "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
                u.full_name as sales_rep_name,
                approver.full_name as approved_by_name,
                i.invoice_number
         FROM returns r
         LEFT JOIN customers c ON r.customer_id = c.id
         LEFT JOIN users u ON r.sales_rep_id = u.id
         LEFT JOIN users approver ON r.approved_by = approver.id
         LEFT JOIN invoices i ON r.invoice_id = i.id
         WHERE r.id = ?",
        [$returnId]
    );
    
    if (!$return) {
        returnJson(['success' => false, 'message' => 'المرتجع غير موجود'], 404);
    }
    
    // جلب عناصر المرتجع
    $items = $db->query(
        "SELECT ri.*, p.name as product_name, p.unit
         FROM return_items ri
         LEFT JOIN products p ON ri.product_id = p.id
         WHERE ri.return_id = ?
         ORDER BY ri.id",
        [$returnId]
    );
    
    $result = [
        'return_number' => $return['return_number'] ?? '',
        'return_date' => $return['return_date'] ?? '',
        'customer_name' => $return['customer_name'] ?? '',
        'sales_rep_name' => $return['sales_rep_name'] ?? '',
        'refund_amount' => (float)($return['refund_amount'] ?? 0),
        'status' => $return['status'] ?? '',
        'notes' => $return['notes'] ?? '',
        'invoice_number' => $return['invoice_number'] ?? '',
        'approved_by_name' => $return['approved_by_name'] ?? '',
        'approved_at' => $return['approved_at'] ?? '',
        'items' => []
    ];
    
    foreach ($items as $item) {
        $result['items'][] = [
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'quantity' => (float)($item['quantity'] ?? 0),
            'unit_price' => (float)($item['unit_price'] ?? 0),
            'total_price' => (float)($item['total_price'] ?? 0),
            'batch_number' => $item['batch_number'] ?? '',
            'unit' => $item['unit'] ?? '',
        ];
    }
    
    returnJson([
        'success' => true,
        'return' => $result
    ]);
}

/**
 * الموافقة على المرتجع
 */
function handleApproveReturn(): void
{
    global $currentUser;
    
    // تنظيف أي output buffers قبل البدء
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    try {
        if ($currentUser['role'] !== 'manager') {
            returnJson(['success' => false, 'message' => 'فقط المدير يمكنه الموافقة على المرتجعات'], 403);
        }
        
        $payload = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($payload)) {
            returnJson(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], 400);
        }
        
        $returnId = isset($payload['return_id']) ? (int)$payload['return_id'] : 0;
        $notes = trim($payload['notes'] ?? '');
        
        if ($returnId <= 0) {
            returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
        }
        
        error_log("=== API: Approving return ID: {$returnId} by user: {$currentUser['id']} ===");
        
        // استخدام دالة الموافقة الشاملة
        $result = approveReturn($returnId, (int)$currentUser['id'], $notes);
        
        error_log("=== API: Approve result: " . json_encode($result, JSON_UNESCAPED_UNICODE) . " ===");
        
        if (!$result['success']) {
            error_log("=== API: Approval failed: " . ($result['message'] ?? 'Unknown error') . " ===");
        }
        
        // تنظيف أي output buffers قبل إرجاع JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        returnJson($result, $result['success'] ? 200 : 400);
        
    } catch (Throwable $e) {
        // تنظيف أي output buffers قبل إرجاع الخطأ
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        error_log("=== API: Approve Return Exception ===");
        error_log("Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        returnJson([
            'success' => false,
            'message' => 'حدث خطأ أثناء معالجة الموافقة: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * رفض المرتجع
 */
function handleRejectReturn(): void
{
    global $currentUser;
    
    if ($currentUser['role'] !== 'manager') {
        returnJson(['success' => false, 'message' => 'فقط المدير يمكنه رفض المرتجعات'], 403);
    }
    
    $payload = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($payload)) {
        returnJson(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], 400);
    }
    
    $returnId = isset($payload['return_id']) ? (int)$payload['return_id'] : 0;
    $notes = trim($payload['notes'] ?? '');
    
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
    }
    
    $db = db();
    
    // التحقق من المرتجع
    $return = $db->queryOne(
        "SELECT status FROM returns WHERE id = ?",
        [$returnId]
    );
    
    if (!$return) {
        returnJson(['success' => false, 'message' => 'المرتجع غير موجود'], 404);
    }
    
    if ($return['status'] !== 'pending') {
        returnJson(['success' => false, 'message' => 'لا يمكن رفض مرتجع تمت معالجته بالفعل'], 400);
    }
    
    $db->beginTransaction();
    
    try {
        // تحديث حالة المرتجع
        $db->execute(
            "UPDATE returns SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = ? WHERE id = ?",
            [$currentUser['id'], $notes ?: 'تم رفض الطلب', $returnId]
        );
        
        // رفض طلب الموافقة
        $entityColumn = getApprovalsEntityColumn();
        $approval = $db->queryOne(
            "SELECT id FROM approvals WHERE type = 'return_request' AND {$entityColumn} = ? AND status = 'pending'",
            [$returnId]
        );
        
        if ($approval) {
            rejectRequest((int)$approval['id'], $currentUser['id'], $notes ?: 'تم رفض طلب المرتجع');
        }
        
        // تسجيل في audit_logs
        require_once __DIR__ . '/../includes/audit_log.php';
        logAudit($currentUser['id'], 'reject_return', 'returns', $returnId, null, [
            'return_number' => $return['return_number'] ?? '',
            'notes' => $notes
        ]);
        
        $db->commit();
        
        returnJson([
            'success' => true,
            'message' => 'تم رفض طلب المرتجع بنجاح'
        ]);
        
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Error rejecting return: ' . $e->getMessage());
        returnJson([
            'success' => false,
            'message' => 'حدث خطأ أثناء رفض المرتجع: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * جلب الطلبات الأخيرة
 */
function handleGetRecentRequests(): void
{
    global $currentUser;
    
    $db = db();
    
    $salesRepId = null;
    if ($currentUser['role'] === 'sales') {
        $salesRepId = (int)$currentUser['id'];
    }
    
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
    
    $result = [
        'returns' => [],
        'exchanges' => []
    ];
    
    // جلب المرتجعات الأخيرة
    $returnsSql = "SELECT 
                        r.id,
                        r.return_number,
                        r.return_date,
                        r.status,
                        r.refund_amount,
                        r.notes,
                        c.name as customer_name,
                        i.invoice_number,
                        r.created_at
                    FROM returns r
                    LEFT JOIN customers c ON r.customer_id = c.id
                    LEFT JOIN invoices i ON r.invoice_id = i.id
                    WHERE 1=1";
    
    $params = [];
    
    // للمندوب: عرض المرتجعات المرتبطة به
    if ($salesRepId) {
        $returnsSql .= " AND (r.sales_rep_id = ? OR r.created_by = ?)";
        $params[] = $salesRepId;
        $params[] = $currentUser['id'];
    } else {
        // للمدير: عرض جميع المرتجعات
        $returnsSql .= " AND r.created_by = ?";
        $params[] = $currentUser['id'];
    }
    
    $returnsSql .= " ORDER BY r.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $returns = $db->query($returnsSql, $params);
    
    $statusLabels = [
        'pending' => 'قيد المراجعة',
        'approved' => 'موافق عليه',
        'rejected' => 'مرفوض',
        'completed' => 'مكتمل',
        'processed' => 'تم المعالجة'
    ];
    
    foreach ($returns as $return) {
        $status = $return['status'] ?? 'pending';
        $result['returns'][] = [
            'id' => (int)$return['id'],
            'return_number' => $return['return_number'],
            'return_date' => $return['return_date'],
            'status' => $status,
            'status_label' => $statusLabels[$status] ?? $status,
            'refund_amount' => (float)$return['refund_amount'],
            'customer_name' => $return['customer_name'] ?? 'غير معروف',
            'invoice_number' => $return['invoice_number'] ?? '-',
            'notes' => $return['notes'] ?? '',
            'created_at' => $return['created_at'],
            'type' => 'return'
        ];
    }
    
    returnJson([
        'success' => true,
        'data' => $result
    ]);
}

