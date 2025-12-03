<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/returns_system.php';
require_once __DIR__ . '/../includes/vehicle_inventory.php';
require_once __DIR__ . '/../includes/inventory_movements.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['manager']);

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$action) {
    returnJson(['success' => false, 'message' => 'الإجراء غير معروف'], 400);
}

try {
    switch ($action) {
        case 'fetch_invoice':
            if ($method !== 'GET') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب GET لهذا الإجراء'], 405);
            }
            handleFetchInvoice();
            break;
        case 'submit_return':
            if ($method !== 'POST') {
                returnJson(['success' => false, 'message' => 'يجب استخدام طلب POST لهذا الإجراء'], 405);
            }
            handleSubmitReturn();
            break;
        default:
            returnJson(['success' => false, 'message' => 'إجراء غير مدعوم'], 400);
    }
} catch (Throwable $e) {
    error_log('invoice_returns API error: ' . $e->getMessage());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع أثناء المعالجة'], 500);
}

function returnJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function handleFetchInvoice(): void
{
    $invoiceNumber = trim($_GET['invoice_number'] ?? '');

    if ($invoiceNumber === '') {
        returnJson(['success' => false, 'message' => 'يرجى إدخال رقم الفاتورة'], 422);
    }

    $invoice = getInvoiceByNumberDetailed($invoiceNumber);

    if (!$invoice) {
        returnJson(['success' => false, 'message' => 'لم يتم العثور على فاتورة بهذا الرقم'], 404);
    }

    $db = db();
    $alreadyReturned = [];
    $alreadyReturnedByInvoiceItem = [];

    // التحقق من وجود عمود invoice_item_id
    $hasInvoiceItemId = false;
    try {
        $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
        $hasInvoiceItemId = !empty($colCheck);
    } catch (Throwable $e) {
        $hasInvoiceItemId = false;
    }

    // حساب الكمية المرتجعة بناءً على invoice_item_id إن كان متوفراً
    if ($hasInvoiceItemId) {
        $rows = $db->query(
            "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE r.invoice_id = ?
               AND r.status IN ('pending','approved','processed','completed')
               AND ri.invoice_item_id IS NOT NULL
             GROUP BY ri.invoice_item_id",
            [$invoice['id']]
        );

        foreach ($rows as $row) {
            $alreadyReturnedByInvoiceItem[(int)$row['invoice_item_id']] = (float)$row['returned_quantity'];
        }
    } else {
        // Fallback: حساب بناءً على product_id فقط
        $rows = $db->query(
            "SELECT ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE r.invoice_id = ?
               AND r.status IN ('pending','approved','processed','completed')
             GROUP BY ri.product_id",
            [$invoice['id']]
        );

        foreach ($rows as $row) {
            $alreadyReturned[(int)$row['product_id']] = (float)$row['returned_quantity'];
        }
    }

    $items = [];
    foreach ($invoice['items'] as $item) {
        $invoiceItemId = (int)$item['id'];
        $productId = (int)$item['product_id'];
        $soldQuantity = (float)$item['quantity'];
        
        // حساب الكمية المرتجعة بناءً على invoice_item_id إن كان متوفراً
        if ($hasInvoiceItemId) {
            $returnedQuantity = $alreadyReturnedByInvoiceItem[$invoiceItemId] ?? 0.0;
        } else {
            $returnedQuantity = $alreadyReturned[$productId] ?? 0.0;
        }
        
        $remaining = max(0, round($soldQuantity - $returnedQuantity, 3));

        $items[] = [
            'invoice_item_id' => (int)$item['id'],
            'product_id' => $productId,
            'product_name' => $item['product_name'] ?? $item['description'],
            'unit' => $item['unit'] ?? null,
            'quantity' => $soldQuantity,
            'returned_quantity' => $returnedQuantity,
            'remaining_quantity' => $remaining,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
        ];
    }

    $response = [
        'success' => true,
        'invoice' => [
            'id' => (int)$invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'customer_id' => (int)$invoice['customer_id'],
            'customer_name' => $invoice['customer_name'],
            'customer_phone' => $invoice['customer_phone'],
            'customer_balance' => (float)($invoice['customer_balance'] ?? 0),
            'sales_rep_id' => (int)($invoice['sales_rep_id'] ?? 0),
            'sales_rep_name' => $invoice['sales_rep_name'],
            'date' => $invoice['date'],
            'status' => $invoice['status'],
            'total_amount' => (float)$invoice['total_amount'],
            'paid_amount' => (float)$invoice['paid_amount'],
            'remaining_amount' => (float)($invoice['remaining_amount'] ?? max(0, $invoice['total_amount'] - $invoice['paid_amount'])),
        ],
        'items' => $items,
    ];

    returnJson($response);
}

function handleSubmitReturn(): void
{
    $payload = json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        returnJson(['success' => false, 'message' => 'صيغة البيانات غير صحيحة'], 400);
    }

    $invoiceNumber = trim($payload['invoice_number'] ?? '');
    $refundMethod = $payload['refund_method'] ?? '';
    $itemsPayload = $payload['items'] ?? [];
    $reason = $payload['reason'] ?? 'customer_request';
    $reasonDescription = trim($payload['reason_description'] ?? '');
    $notes = trim($payload['notes'] ?? '');

    if ($invoiceNumber === '') {
        returnJson(['success' => false, 'message' => 'رقم الفاتورة مطلوب'], 422);
    }

    if (!in_array($refundMethod, ['cash', 'credit', 'company_request'], true)) {
        returnJson(['success' => false, 'message' => 'طريقة الاسترداد غير صحيحة'], 422);
    }

    if (empty($itemsPayload) || !is_array($itemsPayload)) {
        returnJson(['success' => false, 'message' => 'يجب اختيار منتجات لإرجاعها'], 422);
    }

    $invoice = getInvoiceByNumberDetailed($invoiceNumber);
    if (!$invoice) {
        returnJson(['success' => false, 'message' => 'لم يتم العثور على الفاتورة المطلوبة'], 404);
    }

    if (empty($invoice['sales_rep_id'])) {
        returnJson(['success' => false, 'message' => 'لا يوجد مندوب مرتبط بهذه الفاتورة'], 422);
    }

    $itemMap = [];
    foreach ($invoice['items'] as $item) {
        $itemMap[(int)$item['id']] = $item;
    }

    $db = db();
    $alreadyReturned = [];
    $alreadyReturnedByInvoiceItem = [];

    // التحقق من وجود عمود invoice_item_id
    $hasInvoiceItemId = false;
    try {
        $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
        $hasInvoiceItemId = !empty($colCheck);
    } catch (Throwable $e) {
        $hasInvoiceItemId = false;
    }

    // حساب الكمية المرتجعة بناءً على invoice_item_id إن كان متوفراً
    if ($hasInvoiceItemId) {
        $rows = $db->query(
            "SELECT ri.invoice_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE r.invoice_id = ?
               AND r.status IN ('pending','approved','processed','completed')
               AND ri.invoice_item_id IS NOT NULL
             GROUP BY ri.invoice_item_id",
            [$invoice['id']]
        );

        foreach ($rows as $row) {
            $alreadyReturnedByInvoiceItem[(int)$row['invoice_item_id']] = (float)$row['returned_quantity'];
        }
    } else {
        // Fallback: حساب بناءً على product_id فقط
        $rows = $db->query(
            "SELECT ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM return_items ri
             INNER JOIN returns r ON r.id = ri.return_id
             WHERE r.invoice_id = ?
               AND r.status IN ('pending','approved','processed','completed')
             GROUP BY ri.product_id",
            [$invoice['id']]
        );

        foreach ($rows as $row) {
            $alreadyReturned[(int)$row['product_id']] = (float)$row['returned_quantity'];
        }
    }

    $selectedItems = [];
    $totalRefund = 0.0;
    $invoiceTotalsByProduct = [];
    foreach ($invoice['items'] as $item) {
        $invoiceTotalsByProduct[(int)$item['product_id']] = (float)$item['quantity'];
    }

    foreach ($itemsPayload as $submitted) {
        if (!is_array($submitted)) {
            continue;
        }
        $invoiceItemId = (int)($submitted['invoice_item_id'] ?? 0);
        $quantity = isset($submitted['quantity']) ? (float)$submitted['quantity'] : 0.0;

        if ($invoiceItemId <= 0 || $quantity <= 0) {
            continue;
        }

        if (!isset($itemMap[$invoiceItemId])) {
            returnJson(['success' => false, 'message' => 'تم إرسال عناصر غير صالحة للمرتجع'], 422);
        }

        $item = $itemMap[$invoiceItemId];
        $productId = (int)$item['product_id'];
        $soldQuantity = (float)$item['quantity'];
        
        // حساب الكمية المرتجعة بناءً على invoice_item_id إن كان متوفراً
        if ($hasInvoiceItemId) {
            $returnedQuantity = $alreadyReturnedByInvoiceItem[$invoiceItemId] ?? 0.0;
        } else {
            $returnedQuantity = $alreadyReturned[$productId] ?? 0.0;
        }
        
        $remaining = max(0, round($soldQuantity - $returnedQuantity, 3));

        if ($remaining <= 0) {
            returnJson(['success' => false, 'message' => 'تم إرجاع هذا المنتج بالكامل بالفعل'], 422);
        }

        if ($quantity - $remaining > 0.0001) {
            returnJson(['success' => false, 'message' => 'الكمية المطلوبة للمنتج ' . ($item['product_name'] ?? $item['description']) . ' تتجاوز الحد المتاح (' . $remaining . ')'], 422);
        }

        // تحديث الكمية المرتجعة
        if ($hasInvoiceItemId) {
            $alreadyReturnedByInvoiceItem[$invoiceItemId] = $returnedQuantity + $quantity;
        } else {
            $alreadyReturned[$productId] = $returnedQuantity + $quantity;
        }
        $lineTotal = round($quantity * (float)$item['unit_price'], 2);
        $totalRefund += $lineTotal;

        $selectedItems[] = [
            'invoice_item_id' => $invoiceItemId,
            'product_id' => $productId,
            'product_name' => $item['product_name'] ?? $item['description'],
            'quantity' => $quantity,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => $lineTotal,
        ];
    }

    if (empty($selectedItems)) {
        returnJson(['success' => false, 'message' => 'لم يتم اختيار أي منتجات صالحة للمرتجع'], 422);
    }

    $totalRefund = round($totalRefund, 2);

    $salesRepId = (int)$invoice['sales_rep_id'];
    $customerId = (int)$invoice['customer_id'];

    $vehicleId = resolveSalesRepVehicleId($salesRepId);
    if (!$vehicleId) {
        returnJson(['success' => false, 'message' => 'لا يوجد مخزن سيارة مرتبط بهذا المندوب لإيداع المرتجع'], 422);
    }

    $vehicleWarehouse = getVehicleWarehouse($vehicleId);
    if (!$vehicleWarehouse) {
        $createWarehouse = createVehicleWarehouse($vehicleId);
        if (empty($createWarehouse['success'])) {
            returnJson(['success' => false, 'message' => 'تعذر تجهيز مخزن السيارة لاستلام المرتجع'], 500);
        }
        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
    }

    $warehouseId = $vehicleWarehouse['id'] ?? null;
    if (!$warehouseId) {
        returnJson(['success' => false, 'message' => 'تعذر تحديد مخزن السيارة'], 500);
    }

    $currentUser = getCurrentUser();
    $userId = $currentUser['id'] ?? null;
    if (!$userId) {
        returnJson(['success' => false, 'message' => 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول'], 401);
    }

    $conn = $db->getConnection();
    $conn->begin_transaction();

    try {
        $returnNumber = generateReturnNumber();
        $returnType = determineReturnType($selectedItems, $itemMap, $alreadyReturned);

        $db->execute(
            "INSERT INTO returns
             (return_number, sale_id, invoice_id, customer_id, sales_rep_id, return_date, return_type,
              reason, reason_description, refund_amount, refund_method, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [
                $returnNumber,
                null,
                $invoice['id'],
                $customerId,
                $salesRepId,
                date('Y-m-d'),
                $returnType,
                $reason,
                $reasonDescription ?: null,
                $totalRefund,
                $refundMethod,
                $notes ?: null,
                $userId,
            ]
        );

        $returnId = (int)$db->getLastInsertId();

        // التحقق من وجود الأعمدة invoice_item_id و batch_number_id
        $hasInvoiceItemId = false;
        $hasBatchNumberId = false;
        try {
            $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
            $hasInvoiceItemId = !empty($colCheck);
            
            $colCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'batch_number_id'");
            $hasBatchNumberId = !empty($colCheck);
        } catch (Throwable $e) {
            // تجاهل الأخطاء
        }

        foreach ($selectedItems as $item) {
            // الحصول على batch_number_id من invoice_item_id إذا كان متوفراً
            $batchNumberId = null;
            if ($hasBatchNumberId && isset($item['invoice_item_id'])) {
                $batchRow = $db->queryOne(
                    "SELECT batch_number_id FROM sales_batch_numbers 
                     WHERE invoice_item_id = ? 
                     ORDER BY id ASC LIMIT 1",
                    [$item['invoice_item_id']]
                );
                if ($batchRow && !empty($batchRow['batch_number_id'])) {
                    $batchNumberId = (int)$batchRow['batch_number_id'];
                }
            }

            // بناء قائمة الأعمدة والقيم بشكل ديناميكي
            $columns = ['return_id', 'sale_item_id', 'product_id', 'quantity', 'unit_price', 'total_price', '`condition`'];
            $values = [$returnId, null, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price'], 'new'];
            
            if ($hasInvoiceItemId && isset($item['invoice_item_id'])) {
                $columns[] = 'invoice_item_id';
                $values[] = $item['invoice_item_id'];
            }
            
            if ($hasBatchNumberId && $batchNumberId) {
                $columns[] = 'batch_number_id';
                $values[] = $batchNumberId;
            }

            $placeholders = str_repeat('?,', count($values) - 1) . '?';
            $sql = "INSERT INTO return_items (" . implode(', ', $columns) . ") VALUES ($placeholders)";
            
            $db->execute($sql, $values);

            $inventoryRow = $db->queryOne(
                "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                [$vehicleId, $item['product_id']]
            );

            $currentQuantity = (float)($inventoryRow['quantity'] ?? 0);
            $newQuantity = round($currentQuantity + $item['quantity'], 3);

            $updateResult = updateVehicleInventory($vehicleId, $item['product_id'], $newQuantity, $userId);
            if (empty($updateResult['success'])) {
                throw new RuntimeException($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
            }

            $movementResult = recordInventoryMovement(
                $item['product_id'],
                $warehouseId,
                'in',
                $item['quantity'],
                'invoice_return',
                $returnId,
                'إرجاع فاتورة #' . $invoiceNumber,
                $userId
            );

            if (empty($movementResult['success'])) {
                throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون');
            }
        }

        // ========== UNIFIED RETURN PROCESSING ==========
        // Step 1: Input Variables
        $invoiceTotal = (float)($invoice['total_amount'] ?? 0);
        $amountPaid = (float)($invoice['paid_amount'] ?? 0);
        $customer = $db->queryOne("SELECT balance FROM customers WHERE id = ? FOR UPDATE", [$customerId]);
        $customerBalance = (float)($customer['balance'] ?? 0);
        $returnAmount = $totalRefund;
        
        // Step 2: Determine Customer Status
        $customerStatus = determineCustomerStatus($amountPaid, $invoiceTotal, $customerBalance);
        
        // Step 3: Process Return Financial
        $financialNote = null;
        $statusAfterProcessing = 'processed';
        
        if ($refundMethod === 'company_request') {
            // Special handling for company request
            $statusAfterProcessing = 'pending';
            $unpaidAmount = $invoiceTotal - $amountPaid;
            
            if ($returnAmount <= $unpaidAmount + 0.0001) {
                $financialNote = sprintf('تم خصم %s من رصيد العميل المدين. المبلغ المتبقي يحتاج موافقة.', 
                    formatCurrency($returnAmount));
            } else {
                $debtorUsed = $unpaidAmount;
                $remainingRefund = round($returnAmount - $unpaidAmount, 2);
                $financialNote = sprintf('تم خصم %s من رصيد العميل المدين. المبلغ المتبقي %s يحتاج موافقة.', 
                    formatCurrency($debtorUsed), formatCurrency($remainingRefund));
            }
            
            $approvalNotes = "مرتجع فاتورة {$invoiceNumber}\nالمبلغ: " . number_format($returnAmount, 2);
            $approvalNotes .= "\nحالة الدفع: " . $customerStatus['payment'];
            if ($customerStatus['hasCredit']) {
                $approvalNotes .= "\nرصيد العميل المدين: " . number_format($customerStatus['creditAmount'], 2);
            }
            requestApproval('invoice_return_company', $returnId, $userId, $approvalNotes);
        } else {
            // Process return using unified formula
            $returnResult = processReturnFinancial(
                $customerId,
                $invoiceTotal,
                $amountPaid,
                $customerBalance,
                $returnAmount,
                $refundMethod,
                $salesRepId,
                $returnNumber,
                $userId
            );
            
            if (!$returnResult['success']) {
                throw new RuntimeException($returnResult['message'] ?? 'حدث خطأ في معالجة المرتجع المالي');
            }
            
            $financialNote = $returnResult['financialNote'];
        }

        if ($statusAfterProcessing !== 'pending') {
            $db->execute(
                "UPDATE returns SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$statusAfterProcessing, $userId, $returnId]
            );
        }

        logAudit($userId, 'create_invoice_return', 'returns', $returnId, null, [
            'invoice_number' => $invoiceNumber,
            'refund_method' => $refundMethod,
            'refund_amount' => $totalRefund,
            'items' => $selectedItems,
        ]);

        $conn->commit();

        $printUrl = getRelativeUrl('print_return_invoice.php?id=' . $returnId);

        returnJson([
            'success' => true,
            'message' => 'تم تسجيل المرتجع بنجاح. ' . ($financialNote ?? ''),
            'return_id' => $returnId,
            'return_number' => $returnNumber,
            'refund_amount' => $totalRefund,
            'status' => $statusAfterProcessing,
            'print_url' => $printUrl,
            'status_label' => $refundMethod === 'company_request' ? 'قيد التطوير' : null,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function resolveSalesRepVehicleId(int $salesRepId): ?int
{
    $db = db();
    $vehicle = $db->queryOne(
        "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
        [$salesRepId]
    );

    return $vehicle ? (int)$vehicle['id'] : null;
}

function determineReturnType(array $selectedItems, array $invoiceItems, array $runningTotals): string
{
    $isFull = true;
    foreach ($invoiceItems as $invoiceItem) {
        $productId = (int)$invoiceItem['product_id'];
        $soldQuantity = (float)$invoiceItem['quantity'];
        $returned = $runningTotals[$productId] ?? 0.0;
        if (abs($returned - $soldQuantity) > 0.0001) {
            $isFull = false;
            break;
        }
    }

    return $isFull ? 'full' : 'partial';
}

function calculateSalesRepCashBalance(int $salesRepId): float
{
    $db = db();
    $cashBalance = 0.0;

    $invoicesExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
    $collectionsExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
    $accountantTransactionsExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");

    $totalCollections = 0.0;
    if (!empty($collectionsExists)) {
        // التحقق من وجود عمود status
        $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
        $hasStatusColumn = !empty($statusColumnCheck);
        
        if ($hasStatusColumn) {
            // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
            $collectionsResult = $db->queryOne(
                "SELECT COALESCE(SUM(amount), 0) as total_collections
                 FROM collections
                 WHERE collected_by = ? AND status IN ('pending', 'approved')",
                [$salesRepId]
            );
        } else {
            $collectionsResult = $db->queryOne(
                "SELECT COALESCE(SUM(amount), 0) as total_collections
                 FROM collections
                 WHERE collected_by = ?",
                [$salesRepId]
            );
        }
        $totalCollections = (float)($collectionsResult['total_collections'] ?? 0);
    }

    $fullyPaidSales = 0.0;
    if (!empty($invoicesExists)) {
        // التحقق من وجود الأعمدة المطلوبة
        $hasAmountAddedToSalesColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'amount_added_to_sales'"));
        $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
        $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
        $hasInvoiceIdColumn = !empty($collectionsExists) && !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'invoice_id'"));
        
        // استخدام amount_added_to_sales إذا كان محدداً، وإلا استخدام total_amount
        // هذا يضمن أن المبالغ المدفوعة من الرصيد الدائن لا تُضاف إلى خزنة المندوب
        // استبعاد الفواتير التي تم تسجيلها في collections (من خلال invoice_id أو notes)
        // عند استخدام الرصيد الدائن (paid_from_credit = 1): لا يُضاف المبلغ المستخدم من الرصيد الدائن إلى خزنة المندوب
        if (!empty($collectionsExists)) {
            if ($hasAmountAddedToSalesColumn) {
                if ($hasInvoiceIdColumn) {
                    // إذا كان هناك عمود invoice_id، نستخدمه للربط
                    if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                        // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط (لا يشمل المبلغ المستخدم من الرصيد الدائن)
                        $fullyPaidSql = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN paid_from_credit = 1 AND credit_used > 0
                                THEN COALESCE(amount_added_to_sales, 0)
                                WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                                THEN amount_added_to_sales 
                                ELSE total_amount 
                            END
                        ), 0) as fully_paid
                         FROM invoices i
                         WHERE i.sales_rep_id = ? 
                         AND i.status = 'paid' 
                         AND i.paid_amount >= i.total_amount
                         AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
                    } elseif ($hasPaidFromCreditColumn) {
                        // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط
                        $fullyPaidSql = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN paid_from_credit = 1
                                THEN COALESCE(amount_added_to_sales, 0)
                                WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                                THEN amount_added_to_sales 
                                ELSE total_amount 
                            END
                        ), 0) as fully_paid
                         FROM invoices i
                         WHERE i.sales_rep_id = ? 
                         AND i.status = 'paid' 
                         AND i.paid_amount >= i.total_amount
                         AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
                    } else {
                        // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
                        $fullyPaidSql = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                                THEN amount_added_to_sales 
                                ELSE total_amount 
                            END
                        ), 0) as fully_paid
                         FROM invoices i
                         WHERE i.sales_rep_id = ? 
                         AND i.status = 'paid' 
                         AND i.paid_amount >= i.total_amount
                         AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
                    }
                    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
                } else {
                    // إذا لم يكن هناك عمود invoice_id، نستخدم notes للبحث عن رقم الفاتورة
                    if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                        // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط (لا يشمل المبلغ المستخدم من الرصيد الدائن)
                        $fullyPaidSql = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN paid_from_credit = 1 AND credit_used > 0
                                THEN COALESCE(amount_added_to_sales, 0)
                                WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                                THEN amount_added_to_sales 
                                ELSE total_amount 
                            END
                        ), 0) as fully_paid
                         FROM invoices i
                         WHERE i.sales_rep_id = ? 
                         AND i.status = 'paid' 
                         AND i.paid_amount >= i.total_amount
                         AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
                    } elseif ($hasPaidFromCreditColumn) {
                        // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط
                        $fullyPaidSql = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN paid_from_credit = 1
                                THEN COALESCE(amount_added_to_sales, 0)
                                WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                                THEN amount_added_to_sales 
                                ELSE total_amount 
                            END
                        ), 0) as fully_paid
                         FROM invoices i
                         WHERE i.sales_rep_id = ? 
                         AND i.status = 'paid' 
                         AND i.paid_amount >= i.total_amount
                         AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
                    } else {
                        // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
                        $fullyPaidSql = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                                THEN amount_added_to_sales 
                                ELSE total_amount 
                            END
                        ), 0) as fully_paid
                         FROM invoices i
                         WHERE i.sales_rep_id = ? 
                         AND i.status = 'paid' 
                         AND i.paid_amount >= i.total_amount
                         AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
                    }
                    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
                }
            } else {
                // إذا لم يكن العمود موجوداً، نستخدم total_amount (للتوافق مع الإصدارات القديمة)
                if ($hasInvoiceIdColumn) {
                    $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
                     FROM invoices i
                     WHERE i.sales_rep_id = ? 
                     AND i.status = 'paid' 
                     AND i.paid_amount >= i.total_amount
                     AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
                    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
                } else {
                    $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
                     FROM invoices i
                     WHERE i.sales_rep_id = ? 
                     AND i.status = 'paid' 
                     AND i.paid_amount >= i.total_amount
                     AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
                    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
                }
            }
        } else {
            // إذا لم يكن جدول collections موجوداً، نستخدم الطريقة القديمة
            if ($hasAmountAddedToSalesColumn) {
                if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                    $fullyPaidSql = "SELECT COALESCE(SUM(
                        CASE 
                            WHEN paid_from_credit = 1 AND credit_used > 0
                            THEN COALESCE(amount_added_to_sales, 0)
                            WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                            THEN amount_added_to_sales 
                            ELSE total_amount 
                        END
                    ), 0) as fully_paid
                     FROM invoices
                     WHERE sales_rep_id = ? 
                     AND status = 'paid' 
                     AND paid_amount >= total_amount
                     AND status != 'cancelled'";
                } elseif ($hasPaidFromCreditColumn) {
                    $fullyPaidSql = "SELECT COALESCE(SUM(
                        CASE 
                            WHEN paid_from_credit = 1
                            THEN COALESCE(amount_added_to_sales, 0)
                            WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                            THEN amount_added_to_sales 
                            ELSE total_amount 
                        END
                    ), 0) as fully_paid
                     FROM invoices
                     WHERE sales_rep_id = ? 
                     AND status = 'paid' 
                     AND paid_amount >= total_amount
                     AND status != 'cancelled'";
                } else {
                    $fullyPaidSql = "SELECT COALESCE(SUM(
                        CASE 
                            WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                            THEN amount_added_to_sales 
                            ELSE total_amount 
                        END
                    ), 0) as fully_paid
                     FROM invoices
                     WHERE sales_rep_id = ? 
                     AND status = 'paid' 
                     AND paid_amount >= total_amount
                     AND status != 'cancelled'";
                }
            } else {
                $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
                 FROM invoices
                 WHERE sales_rep_id = ?
                   AND status = 'paid'
                   AND paid_amount >= total_amount
                   AND status != 'cancelled'";
            }
            $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId]);
        }
        
        $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
    }

    // خصم المبالغ المحصلة من المندوب (من accountant_transactions)
    $collectedFromRep = 0.0;
    if (!empty($accountantTransactionsExists)) {
        $collectedResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collected
             FROM accountant_transactions
             WHERE sales_rep_id = ? 
             AND transaction_type = 'collection_from_sales_rep'
             AND status = 'approved'",
            [$salesRepId]
        );
        $collectedFromRep = (float)($collectedResult['total_collected'] ?? 0);
    }

    // حساب الإضافات المباشرة للرصيد
    $totalCashAdditions = 0.0;
    $cashAdditionsTableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
    if (!empty($cashAdditionsTableExists)) {
        try {
            $additionsResult = $db->queryOne(
                "SELECT COALESCE(SUM(amount), 0) as total_additions
                 FROM cash_register_additions
                 WHERE sales_rep_id = ?",
                [$salesRepId]
            );
            $totalCashAdditions = (float)($additionsResult['total_additions'] ?? 0);
        } catch (Throwable $additionsError) {
            error_log('Cash additions calculation error: ' . $additionsError->getMessage());
            $totalCashAdditions = 0.0;
        }
    }

    return $totalCollections + $fullyPaidSales + $totalCashAdditions - $collectedFromRep;
}

function insertNegativeCollection(int $customerId, int $salesRepId, float $amount, string $returnNumber, int $approvedBy): void
{
    $db = db();
    $columns = $db->query("SHOW COLUMNS FROM collections") ?? [];
    $columnNames = [];
    foreach ($columns as $column) {
        if (!empty($column['Field'])) {
            $columnNames[] = $column['Field'];
        }
    }

    $hasStatus = in_array('status', $columnNames, true);
    $hasApprovedBy = in_array('approved_by', $columnNames, true);
    $hasApprovedAt = in_array('approved_at', $columnNames, true);

    $fields = [];
    $placeholders = [];
    $values = [];

    $baseData = [
        'customer_id' => $customerId,
        'amount' => $amount * -1,
        'date' => date('Y-m-d'),
        'payment_method' => 'cash',
        'reference_number' => 'REFUND-' . $returnNumber,
        'notes' => 'صرف نقدي - مرتجع فاتورة ' . $returnNumber,
        'collected_by' => $salesRepId,
    ];

    foreach ($baseData as $column => $value) {
        $fields[] = $column;
        $placeholders[] = '?';
        $values[] = $value;
    }

    if ($hasStatus) {
        $fields[] = 'status';
        $placeholders[] = '?';
        $values[] = 'approved';
    }

    if ($hasApprovedBy) {
        $fields[] = 'approved_by';
        $placeholders[] = '?';
        $values[] = $approvedBy;
    }

    if ($hasApprovedAt) {
        $fields[] = 'approved_at';
        $placeholders[] = 'NOW()';
    }

    $sql = "INSERT INTO collections (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";

    $db->execute($sql, $values);
}

