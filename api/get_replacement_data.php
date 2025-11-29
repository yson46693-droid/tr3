<?php
/**
 * API لجلب بيانات الاستبدال (مشتريات العميل ومخزن السيارة)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/product_name_helper.php';
require_once __DIR__ . '/../includes/vehicle_inventory.php';

requireRole(['sales', 'accountant', 'manager']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$currentUser = getCurrentUser();
$db = db();

// تنظيف أي output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    
    if ($customerId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'معرف العميل غير صالح'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // التحقق من وجود العميل
    $customer = $db->queryOne(
        "SELECT id, name, created_by FROM customers WHERE id = ?",
        [$customerId]
    );
    
    if (!$customer) {
        echo json_encode([
            'success' => false,
            'message' => 'العميل غير موجود'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // التحقق من الصلاحيات
    if ($currentUser['role'] === 'sales') {
        if ((int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
            echo json_encode([
                'success' => false,
                'message' => 'هذا العميل غير مرتبط بك'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // جلب المندوب المسؤول عن العميل
    require_once __DIR__ . '/../includes/salary_calculator.php';
    $salesRepId = getSalesRepForCustomer($customerId);
    
    if (!$salesRepId) {
        echo json_encode([
            'success' => false,
            'message' => 'لم يتم العثور على مندوب مسؤول عن هذا العميل'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ========== جلب مشتريات العميل ==========
    $purchaseHistory = $db->query(
        "SELECT 
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
            GROUP_CONCAT(DISTINCT bn.id ORDER BY bn.id SEPARATOR ',') as batch_number_ids,
            GROUP_CONCAT(DISTINCT fp.id ORDER BY fp.id SEPARATOR ',') as finished_batch_ids
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
        LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
        LEFT JOIN finished_products fp ON fp.batch_id = bn.id
        WHERE i.customer_id = ? AND i.status != 'cancelled'
        GROUP BY i.id, ii.id
        ORDER BY i.date DESC, i.id DESC, ii.id ASC",
        [$customerId]
    );
    
    // حساب الكميات المستبدلة سابقاً
    $exchangedQuantities = [];
    $hasExchangeItemsTable = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_return_items'"));
    
    if ($hasExchangeItemsTable) {
        // التحقق من وجود عمود invoice_item_id
        $hasInvoiceItemIdColumn = false;
        try {
            $columnCheck = $db->queryOne("SHOW COLUMNS FROM exchange_return_items LIKE 'invoice_item_id'");
            $hasInvoiceItemIdColumn = !empty($columnCheck);
        } catch (Throwable $e) {
            $hasInvoiceItemIdColumn = false;
        }
        
        if ($hasInvoiceItemIdColumn) {
            $exchangedRows = $db->query(
                "SELECT eri.product_id, eri.invoice_item_id, COALESCE(SUM(eri.quantity), 0) AS exchanged_quantity
                 FROM exchange_return_items eri
                 INNER JOIN product_exchanges pe ON pe.id = eri.exchange_id
                 WHERE pe.customer_id = ?
                   AND pe.status IN ('pending', 'approved', 'completed')
                   AND eri.invoice_item_id IS NOT NULL
                 GROUP BY eri.product_id, eri.invoice_item_id",
                [$customerId]
            );
            
            foreach ($exchangedRows as $row) {
                $invoiceItemId = (int)$row['invoice_item_id'];
                $exchangedQuantities[$invoiceItemId] = (float)$row['exchanged_quantity'];
            }
        }
    }
    
    // حساب الكميات المرتجعة أيضاً
    $returnedQuantities = [];
    $hasReturnItemsTable = !empty($db->queryOne("SHOW TABLES LIKE 'return_items'"));
    
    if ($hasReturnItemsTable) {
        $hasInvoiceItemIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'"));
        
        if ($hasInvoiceItemIdColumn) {
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
        }
    }
    
    // تنسيق مشتريات العميل
    $customerPurchases = [];
    foreach ($purchaseHistory as $item) {
        $invoiceItemId = (int)$item['invoice_item_id'];
        $quantity = (float)$item['quantity'];
        $exchangedQty = $exchangedQuantities[$invoiceItemId] ?? 0.0;
        $returnedQty = $returnedQuantities[$invoiceItemId] ?? 0.0;
        
        $availableQty = max(0, $quantity - $exchangedQty - $returnedQty);
        
        if ($availableQty > 0) {
            $customerPurchases[] = [
                'invoice_id' => (int)$item['invoice_id'],
                'invoice_item_id' => $invoiceItemId,
                'invoice_number' => $item['invoice_number'],
                'invoice_date' => $item['invoice_date'],
                'product_id' => (int)$item['product_id'],
                'product_name' => $item['product_name'] ?? 'غير معروف',
                'unit' => $item['unit'] ?? 'قطعة',
                'quantity' => $quantity,
                'available_quantity' => $availableQty,
                'unit_price' => (float)$item['unit_price'],
                'total_price' => (float)$item['total_price'],
                'batch_numbers' => !empty($item['batch_numbers']) ? explode(', ', $item['batch_numbers']) : [],
                'batch_number_ids' => !empty($item['batch_number_ids']) ? array_map('intval', explode(',', $item['batch_number_ids'])) : [],
                'finished_batch_ids' => !empty($item['finished_batch_ids']) ? array_map('intval', explode(',', $item['finished_batch_ids'])) : []
            ];
        }
    }
    
    // ========== جلب مخزن سيارة المندوب ==========
    $vehicle = $db->queryOne(
        "SELECT v.id as vehicle_id, v.vehicle_number
         FROM vehicles v
         WHERE v.driver_id = ? AND v.status = 'active'
         ORDER BY v.id DESC LIMIT 1",
        [$salesRepId]
    );
    
    if (!$vehicle) {
        echo json_encode([
            'success' => false,
            'message' => 'لم يتم العثور على سيارة للمندوب'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $vehicleId = (int)$vehicle['vehicle_id'];
    
    // جلب مخزون السيارة
    $vehicleInventory = getVehicleInventory($vehicleId);
    
    // تنسيق مخزون السيارة
    $carInventory = [];
    foreach ($vehicleInventory as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        
        if ($qty > 0) {
            $productName = resolveProductName([
                $item['product_name'] ?? null,
                $item['finished_product_name'] ?? null,
                $item['name'] ?? null
            ]);
            
            $carInventory[] = [
                'product_id' => (int)($item['product_id'] ?? 0),
                'product_name' => $productName ?: 'غير معروف',
                'unit' => $item['unit'] ?? $item['product_unit'] ?? 'قطعة',
                'quantity' => $qty,
                'unit_price' => (float)($item['product_unit_price'] ?? $item['unit_price'] ?? 0),
                'total_value' => $qty * (float)($item['product_unit_price'] ?? $item['unit_price'] ?? 0),
                'finished_batch_id' => isset($item['finished_batch_id']) ? (int)$item['finished_batch_id'] : null,
                'finished_batch_number' => $item['finished_batch_number'] ?? null
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'name' => $customer['name']
        ],
        'sales_rep_id' => $salesRepId,
        'vehicle_id' => $vehicleId,
        'customer_purchases' => $customerPurchases,
        'car_inventory' => $carInventory
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('get_replacement_data error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب البيانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

