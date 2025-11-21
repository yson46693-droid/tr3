<?php
/**
 * API: جلب بيانات الفاتورة للمرتجع (للمبيعات والمحاسبين)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/invoices.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الصلاحيات
$currentUser = getCurrentUser();
if (!in_array($currentUser['role'], ['sales', 'accountant', 'manager'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoiceNumber = trim($_GET['invoice_number'] ?? '');

if ($invoiceNumber === '') {
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال رقم الفاتورة'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $invoice = getInvoiceByNumberDetailed($invoiceNumber);
    
    if (!$invoice) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على فاتورة بهذا الرقم'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // حساب الكميات المرتجعة بالفعل
    $db = db();
    $alreadyReturned = [];
    
    $rows = $db->query(
        "SELECT ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
         FROM return_items ri
         INNER JOIN returns r ON r.id = ri.return_id
         WHERE r.invoice_id = ?
           AND r.status IN ('pending','approved','processed')
         GROUP BY ri.product_id",
        [$invoice['id']]
    );
    
    foreach ($rows as $row) {
        $alreadyReturned[(int)$row['product_id']] = (float)$row['returned_quantity'];
    }
    
    // إعداد بيانات العناصر
    $items = [];
    foreach ($invoice['items'] as $item) {
        $productId = (int)$item['product_id'];
        $soldQuantity = (float)$item['quantity'];
        $returnedQuantity = $alreadyReturned[$productId] ?? 0.0;
        $remaining = max(0, round($soldQuantity - $returnedQuantity, 3));
        
        $items[] = [
            'invoice_item_id' => (int)$item['id'],
            'product_id' => $productId,
            'product_name' => $item['product_name'] ?? $item['description'] ?? '',
            'unit' => $item['unit'] ?? null,
            'quantity' => $soldQuantity,
            'returned_quantity' => $returnedQuantity,
            'remaining_quantity' => $remaining,
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
        ];
    }
    
    // حساب معلومات الدفع
    $paidAmount = (float)($invoice['paid_amount'] ?? 0);
    $remainingAmount = (float)($invoice['remaining_amount'] ?? 0);
    $totalAmount = (float)($invoice['total_amount'] ?? 0);
    
    // تحديد حالة الدفع
    $paymentStatus = 'unpaid'; // غير مدفوع
    if ($paidAmount >= $totalAmount) {
        $paymentStatus = 'fully_paid'; // مدفوع بالكامل
    } elseif ($paidAmount > 0) {
        $paymentStatus = 'partially_paid'; // مدفوع جزئياً
    }
    
    $response = [
        'success' => true,
        'invoice' => [
            'id' => (int)$invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'customer_id' => (int)$invoice['customer_id'],
            'customer_name' => $invoice['customer_name'] ?? '',
            'customer_phone' => $invoice['customer_phone'] ?? '',
            'sales_rep_id' => (int)($invoice['sales_rep_id'] ?? 0),
            'sales_rep_name' => $invoice['sales_rep_name'] ?? '',
            'date' => $invoice['date'],
            'status' => $invoice['status'],
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'remaining_amount' => $remainingAmount,
            'payment_status' => $paymentStatus,
        ],
        'items' => $items,
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Invoice for Return Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في جلب بيانات الفاتورة'], JSON_UNESCAPED_UNICODE);
}

