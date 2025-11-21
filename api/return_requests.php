<?php
/**
 * API for Return Requests
 * Handles return request creation, customer selection, and purchase history
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/path_helper.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJson(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

requireRole(['sales', 'manager', 'accountant']);

try {
    switch ($action) {
        case 'get_customers':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleGetCustomers();
            break;
            
        case 'get_purchase_history':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleGetPurchaseHistory();
            break;
            
        case 'create':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST لهذا الإجراء'], 405);
            }
            handleCreateReturnRequest();
            break;
            
        case 'get_return_details':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleGetReturnDetails();
            break;
            
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('return_requests API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع أثناء المعالجة: ' . $e->getMessage()], 500);
}

function returnJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get customers assigned to current sales rep
 */
function handleGetCustomers(): void
{
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $db = db();
    
    // For sales rep, only show their customers
    // For manager/accountant, show all customers
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
 * Get customer purchase history with batch numbers
 */
function handleGetPurchaseHistory(): void
{
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    if ($customerId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف العميل غير صالح'], 422);
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $db = db();
    
    // Verify customer exists and belongs to sales rep (if sales rep)
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
    
    // Get purchase history from invoices
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
            p.name as product_name,
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
        $returnedRows = $db->query(
            "SELECT ri.invoice_item_id, ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE r.customer_id = ?
               AND r.status IN ('pending', 'approved', 'processed', 'completed')
               AND ri.invoice_item_id IS NOT NULL
             GROUP BY ri.invoice_item_id, ri.product_id",
            [$customerId]
        );
        
        foreach ($returnedRows as $row) {
            $invoiceItemId = (int)$row['invoice_item_id'];
            $productId = (int)$row['product_id'];
            $key = "{$invoiceItemId}_{$productId}";
            $returnedQuantities[$key] = (float)$row['returned_quantity'];
        }
    } else {
        // Fallback: group by product_id only
        $returnedRows = $db->query(
            "SELECT ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             INNER JOIN invoices i ON r.invoice_id = i.id
             WHERE r.customer_id = ?
               AND r.status IN ('pending', 'approved', 'processed', 'completed')
             GROUP BY ri.product_id",
            [$customerId]
        );
        
        foreach ($returnedRows as $row) {
            $productId = (int)$row['product_id'];
            $key = "product_{$productId}";
            $returnedQuantities[$key] = (float)$row['returned_quantity'];
        }
    }
    
    $result = [];
    foreach ($purchaseHistory as $item) {
        $invoiceItemId = (int)$item['invoice_item_id'];
        $productId = (int)$item['product_id'];
        
        // Calculate returned quantity
        $returnedQty = 0.0;
        if ($hasInvoiceItemId) {
            $key = "{$invoiceItemId}_{$productId}";
            $returnedQty = $returnedQuantities[$key] ?? 0.0;
        } else {
            // Fallback: use product_id only
            $key = "product_{$productId}";
            $returnedQty = $returnedQuantities[$key] ?? 0.0;
        }
        
        $purchasedQty = (float)$item['quantity'];
        $remainingQty = max(0, round($purchasedQty - $returnedQty, 3));
        
        if ($remainingQty <= 0) {
            continue; // Skip fully returned items
        }
        
        $result[] = [
            'invoice_id' => (int)$item['invoice_id'],
            'invoice_number' => $item['invoice_number'],
            'invoice_date' => $item['invoice_date'],
            'invoice_item_id' => $invoiceItemId,
            'product_id' => $productId,
            'product_name' => $item['product_name'] ?? 'غير معروف',
            'unit' => $item['unit'] ?? '',
            'quantity_purchased' => $purchasedQty,
            'quantity_returned' => $returnedQty,
            'quantity_remaining' => $remainingQty,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'batch_numbers' => $item['batch_numbers'] ?? '',
            'batch_number_ids' => $item['batch_number_ids'] ?? '',
        ];
    }
    
    returnJson(['success' => true, 'purchase_history' => $result]);
}

/**
 * Create return request
 */
function handleCreateReturnRequest(): void
{
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
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
    
    // Verify customer
    $customer = $db->queryOne(
        "SELECT id, name, created_by FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    
    // Verify sales rep ownership
    $salesRepId = (int)($customer['created_by'] ?? 0);
    if ($currentUser['role'] === 'sales' && $salesRepId !== (int)$currentUser['id']) {
        returnJson(['success' => false, 'message' => 'هذا العميل غير مرتبط بك'], 403);
    }
    
    // Get sales rep ID (use customer's created_by or current user if manager/accountant)
    if ($salesRepId <= 0 && in_array($currentUser['role'], ['manager', 'accountant'], true)) {
        // Try to get from first invoice
        $firstInvoice = $db->queryOne(
            "SELECT sales_rep_id FROM invoices WHERE customer_id = ? ORDER BY id ASC LIMIT 1",
            [$customerId]
        );
        $salesRepId = (int)($firstInvoice['sales_rep_id'] ?? 0);
    }
    
    $conn->begin_transaction();
    
    try {
        // Generate return number
        $year = date('Y');
        $month = date('m');
        $lastReturn = $db->queryOne(
            "SELECT return_number FROM returns WHERE return_number LIKE ? ORDER BY return_number DESC LIMIT 1",
            ["RET-{$year}{$month}-%"]
        );
        
        $serial = 1;
        if ($lastReturn) {
            $parts = explode('-', $lastReturn['return_number']);
            $serial = intval($parts[2] ?? 0) + 1;
        }
        $returnNumber = sprintf("RET-%s%s-%04d", $year, $month, $serial);
        
        // Validate and process items
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
            
            // Get invoice item details
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
            
            // Check already returned quantity
            // Check if invoice_item_id column exists
            $hasInvoiceItemIdCol = false;
            try {
                $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
                $hasInvoiceItemIdCol = !empty($colCheck);
            } catch (Throwable $e) {
                $hasInvoiceItemIdCol = false;
            }
            
            if ($hasInvoiceItemIdCol) {
                $alreadyReturned = $db->queryOne(
                    "SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                     FROM return_items ri
                     INNER JOIN returns r ON r.id = ri.return_id
                     WHERE ri.invoice_item_id = ?
                       AND r.status IN ('pending', 'approved', 'processed', 'completed')",
                    [$invoiceItemId]
                );
            } else {
                // Fallback: check by invoice_id and product_id
                $alreadyReturned = $db->queryOne(
                    "SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                     FROM return_items ri
                     INNER JOIN returns r ON r.id = ri.return_id
                     WHERE r.invoice_id = ? AND ri.product_id = ?
                       AND r.status IN ('pending', 'approved', 'processed', 'completed')",
                    [$invoiceItem['invoice_id'], $invoiceItem['product_id']]
                );
            }
            
            $returnedQty = (float)($alreadyReturned['returned_quantity'] ?? 0);
            $purchasedQty = (float)$invoiceItem['quantity'];
            $remainingQty = max(0, round($purchasedQty - $returnedQty, 3));
            
            if ($quantity > $remainingQty + 0.0001) {
                throw new RuntimeException("الكمية المطلوبة للمنتج {$invoiceItem['product_name']} تتجاوز الحد المتاح ({$remainingQty})");
            }
            
            // Get batch numbers for this invoice item
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
            ];
            
            if (!in_array((int)$invoiceItem['invoice_id'], $invoiceIds)) {
                $invoiceIds[] = (int)$invoiceItem['invoice_id'];
            }
        }
        
        if (empty($selectedItems)) {
            throw new RuntimeException('لم يتم اختيار أي منتجات صالحة للمرتجع');
        }
        
        $totalRefund = round($totalRefund, 2);
        
        // Use first invoice's sales rep if not set
        if ($salesRepId <= 0 && !empty($invoiceIds)) {
            $firstInvoice = $db->queryOne(
                "SELECT sales_rep_id FROM invoices WHERE id = ?",
                [$invoiceIds[0]]
            );
            $salesRepId = (int)($firstInvoice['sales_rep_id'] ?? 0);
        }
        
        // Create return record
        $db->execute(
            "INSERT INTO returns
             (return_number, invoice_id, customer_id, sales_rep_id, return_date, return_type,
              reason, refund_amount, refund_method, status, notes, created_by)
             VALUES (?, ?, ?, ?, CURDATE(), 'partial', 'customer_request', ?, 'credit', 'pending', ?, ?)",
            [
                $returnNumber,
                $invoiceIds[0] ?? null, // Use first invoice ID
                $customerId,
                $salesRepId ?: null,
                $totalRefund,
                $notes ?: null,
                $currentUser['id'],
            ]
        );
        
        $returnId = (int)$db->getLastInsertId();
        
        // Create return items
        foreach ($selectedItems as $item) {
            $batchNumberId = !empty($item['batch_number_ids']) ? $item['batch_number_ids'][0] : null;
            $batchNumber = $item['batch_numbers'] ?? null;
            
            $db->execute(
                "INSERT INTO return_items
                 (return_id, invoice_item_id, product_id, quantity, unit_price, total_price, 
                  batch_number_id, batch_number, condition)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new')",
                [
                    $returnId,
                    $item['invoice_item_id'],
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price'],
                    $batchNumberId,
                    $batchNumber,
                ]
            );
        }
        
        // Create approval request
        $approvalNotes = "مرتجع فاتورة رقم: {$returnNumber}\n";
        $approvalNotes .= "العميل: {$customer['name']}\n";
        $approvalNotes .= "المبلغ: " . number_format($totalRefund, 2) . " ج.م\n";
        $approvalNotes .= "عدد المنتجات: " . count($selectedItems);
        
        requestApproval('return_request', $returnId, $currentUser['id'], $approvalNotes);
        
        $conn->commit();
        
        returnJson([
            'success' => true,
            'message' => 'تم إنشاء طلب المرتجع بنجاح وتم إرساله للموافقة',
            'return_id' => $returnId,
            'return_number' => $returnNumber,
            'refund_amount' => $totalRefund,
        ]);
        
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Get return details by ID
 */
function handleGetReturnDetails(): void
{
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }
    
    $returnId = isset($_GET['return_id']) ? (int)$_GET['return_id'] : 0;
    
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف المرتجع غير صالح'], 422);
    }
    
    $db = db();
    
    // Get return details
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
    
    // Get return items
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

