<?php
/**
 * API for Customer Purchase History
 * API endpoint for retrieving customer purchase history with batch numbers
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/product_name_helper.php';

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
        case 'get_history':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleGetHistory();
            break;
            
        case 'search':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET'], 405);
            }
            handleSearch();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('customer_purchase_history API error: ' . $e->getMessage());
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
 * Get customer purchase history
 */
function handleGetHistory(): void
{
    global $currentUser;
    
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    $customerType = isset($_GET['type']) ? trim($_GET['type']) : 'normal'; // 'normal' or 'local'
    $isLocalCustomer = ($customerType === 'local');
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $db = db();
    
    // Verify customer exists
    if ($isLocalCustomer) {
        // عميل محلي
        $customer = $db->queryOne(
            "SELECT id, name, phone, address, balance FROM local_customers WHERE id = ?",
            [$customerId]
        );
    } else {
        // عميل عادي
        $customer = $db->queryOne(
            "SELECT id, name, phone, address, created_by, balance FROM customers WHERE id = ?",
            [$customerId]
        );
        
        // التحقق من ملكية العميل للمندوب (إذا كان المستخدم مندوب)
        if ($currentUser['role'] === 'sales') {
            $salesRepId = (int)$currentUser['id'];
            if ((int)($customer['created_by'] ?? 0) !== $salesRepId) {
                returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
            }
        }
    }
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    // Get purchase history based on customer type
    if ($isLocalCustomer) {
        // جلب سجل المشتريات من الفواتير المحلية
        $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
        if (empty($localInvoicesTableExists)) {
            returnJson([
                'success' => true,
                'customer' => [
                    'id' => (int)$customer['id'],
                    'name' => $customer['name'],
                    'phone' => $customer['phone'] ?? '',
                    'address' => $customer['address'] ?? '',
                    'balance' => (float)($customer['balance'] ?? 0)
                ],
                'purchase_history' => []
            ]);
        }
        
        // جلب سجل المشتريات من الفواتير المحلية
        $localInvoiceItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoice_items'");
        if (empty($localInvoiceItemsTableExists)) {
            // إذا لم يكن هناك جدول local_invoice_items، نعيد قائمة فارغة
            $purchaseHistory = [];
        } else {
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
                    NULLIF(TRIM(p.name), '') as product_name,
                    p.unit,
                    ii.quantity,
                    ii.unit_price,
                    ii.total_price,
                    '' as batch_numbers,
                    '' as batch_number_ids
                FROM local_invoices i
                INNER JOIN local_invoice_items ii ON i.id = ii.invoice_id
                LEFT JOIN products p ON ii.product_id = p.id
                WHERE i.customer_id = ?
                GROUP BY i.id, ii.id
                ORDER BY i.date DESC, i.id DESC, ii.id ASC",
                [$customerId]
            ) ?: [];
        }
    } else {
        // Get purchase history from invoices with batch numbers
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
                    CONCAT('منتج رقم ', p.id)
                ) as product_name,
                p.unit,
                ii.quantity,
                ii.unit_price,
                ii.total_price,
                GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers,
                GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',') as batch_number_ids
            FROM invoices i
            INNER JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
            LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
            WHERE i.customer_id = ?
            GROUP BY i.id, ii.id
            ORDER BY i.date DESC, i.id DESC, ii.id ASC",
            [$customerId]
        );
    }
    
    // Calculate already returned quantities (للعملاء المحليين والعاديين)
    
    // Calculate already returned quantities
    $returnedQuantities = [];
    
    // Check if invoice_item_id column exists
    $hasInvoiceItemId = false;
    try {
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
        $hasInvoiceItemId = !empty($columnCheck);
    } catch (Throwable $e) {
        $hasInvoiceItemId = false;
    }
    
    if ($hasInvoiceItemId) {
        if ($isLocalCustomer) {
            // للعملاء المحليين - جلب المرتجعات من local_returns إن وجدت
            $localReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_returns'");
            if (!empty($localReturnsTableExists)) {
                $localReturnItemsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_return_items'");
                if (!empty($localReturnItemsTableExists)) {
                    $hasLocalInvoiceItemId = !empty($db->queryOne("SHOW COLUMNS FROM local_return_items LIKE 'invoice_item_id'"));
                    if ($hasLocalInvoiceItemId) {
                        $returnedRows = $db->query(
                            "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                             FROM local_return_items ri
                             INNER JOIN local_returns r ON r.id = ri.return_id
                             WHERE r.customer_id = ?
                               AND r.status IN ('pending', 'approved', 'processed', 'completed')
                               AND ri.invoice_item_id IS NOT NULL
                             GROUP BY ri.invoice_item_id",
                            [$customerId]
                        ) ?: [];
                        
                        foreach ($returnedRows as $row) {
                            $invoiceItemId = (int)$row['invoice_item_id'];
                            $returnedQuantities[$invoiceItemId] = (float)$row['returned_quantity'];
                        }
                    }
                }
            }
        } else {
            // للعملاء العاديين - حساب الكمية المرتجعة لكل invoice_item_id
            $returnedRows = $db->query(
                "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                 FROM return_items ri
                 INNER JOIN returns r ON r.id = ri.return_id
                 WHERE r.customer_id = ?
                   AND r.status IN ('pending', 'approved', 'processed', 'completed')
                   AND ri.invoice_item_id IS NOT NULL
                 GROUP BY ri.invoice_item_id",
                [$customerId]
            ) ?: [];
            
            foreach ($returnedRows as $row) {
                $invoiceItemId = (int)$row['invoice_item_id'];
                $returnedQuantities[$invoiceItemId] = (float)$row['returned_quantity'];
            }
        }
    }
    
    // Format results
    $result = [];
    foreach ($purchaseHistory as $item) {
        $invoiceItemId = (int)$item['invoice_item_id'];
        $quantity = (float)$item['quantity'];
        $batchNumberIds = [];
        $batchNumbers = [];
        
        if ($isLocalCustomer) {
            // للعملاء المحليين - لا توجد batch numbers حالياً
            $batchNumberIds = [];
            $batchNumbers = [];
        } else {
            $batchNumberIds = !empty($item['batch_number_ids']) && $item['batch_number_ids'] !== '' ? explode(',', $item['batch_number_ids']) : [];
            $batchNumbers = !empty($item['batch_numbers']) && $item['batch_numbers'] !== '' ? explode(', ', $item['batch_numbers']) : [];
        }
        
        // Calculate returned quantity - مجموع الكميات المرتجعة لكل invoice_item_id
        $returnedQuantity = 0.0;
        if ($hasInvoiceItemId) {
            $returnedQuantity = $returnedQuantities[$invoiceItemId] ?? 0.0;
        }
        
        $availableToReturn = max(0, $quantity - $returnedQuantity);
        
        $result[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'],
            'invoice_date' => $item['invoice_date'],
            'invoice_item_id' => $invoiceItemId,
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? 'قطعة',
            'quantity' => $quantity,
            'returned_quantity' => $returnedQuantity,
            'available_to_return' => $availableToReturn,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => $batchNumbers,
            'batch_number_ids' => array_map('intval', $batchNumberIds),
            'can_return' => $availableToReturn > 0
        ];
    }
    
    returnJson([
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'name' => $customer['name'],
            'phone' => $customer['phone'] ?? '',
            'address' => $customer['address'] ?? '',
            'balance' => (float)$customer['balance']
        ],
        'purchase_history' => $result
    ]);
}

/**
 * Search purchase history
 */
function handleSearch(): void
{
    global $currentUser;
    
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    $batchNumber = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
    $productName = isset($_GET['product_name']) ? trim($_GET['product_name']) : '';
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $db = db();
    
    // Verify customer
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
    
    // Build search query
    $sql = "SELECT 
            i.id as invoice_id,
            i.invoice_number,
            i.date as invoice_date,
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
                 ORDER BY fp2.id DESC 
                 LIMIT 1),
                NULLIF(TRIM(p.name), ''),
                CONCAT('منتج رقم ', p.id)
            ) as product_name,
            p.unit,
            ii.quantity,
            ii.unit_price,
            ii.total_price,
            GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ') as batch_numbers,
            GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',') as batch_number_ids
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
        LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
        WHERE i.customer_id = ?";
    
    $params = [$customerId];
    
    if ($batchNumber) {
        $sql .= " AND bn.batch_number LIKE ?";
        $params[] = "%{$batchNumber}%";
    }
    
    if ($productName) {
        $sql .= " AND (p.name LIKE ? OR 
                EXISTS (SELECT 1 FROM finished_products fp 
                        INNER JOIN batch_numbers bn3 ON fp.batch_id = bn3.id
                        INNER JOIN sales_batch_numbers sbn3 ON bn3.id = sbn3.batch_number_id
                        WHERE sbn3.invoice_item_id = ii.id 
                        AND fp.product_name LIKE ?))";
        $params[] = "%{$productName}%";
        $params[] = "%{$productName}%";
    }
    
    $sql .= " GROUP BY i.id, ii.id
              ORDER BY i.date DESC, i.id DESC";
    
    $results = $db->query($sql, $params);
    
    $formatted = [];
    foreach ($results as $item) {
        $formatted[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'],
            'invoice_date' => $item['invoice_date'],
            'invoice_item_id' => (int)$item['invoice_item_id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? 'قطعة',
            'quantity' => (float)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => !empty($item['batch_numbers']) ? explode(', ', $item['batch_numbers']) : [],
            'batch_number_ids' => !empty($item['batch_number_ids']) ? array_map('intval', explode(',', $item['batch_number_ids'])) : []
        ];
    }
    
    returnJson([
        'success' => true,
        'results' => $formatted
    ]);
}

