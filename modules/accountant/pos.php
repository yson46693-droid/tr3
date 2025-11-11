<?php
/**
 * صفحة نقطة البيع المحلية (POS)
 * للمدير والمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// الحصول على مخزن الشركة الرئيسي
$mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
if (!$mainWarehouse) {
    // إنشاء مخزن رئيسي افتراضي إذا لم يكن موجوداً
    $db->execute(
        "INSERT INTO warehouses (name, warehouse_type, status) VALUES (?, 'main', 'active')",
        ['مخزن الشركة الرئيسي']
    );
    $mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_customer') {
        // إضافة عميل جديد
        $customerName = trim($_POST['name'] ?? '');
        $customerPhone = trim($_POST['phone'] ?? '');
        $customerEmail = trim($_POST['email'] ?? '');
        $customerAddress = trim($_POST['address'] ?? '');
        
        if (empty($customerName)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            // التحقق من عدم تكرار رقم الهاتف
            if (!empty($customerPhone)) {
                $existing = $db->queryOne("SELECT id FROM customers WHERE phone = ?", [$customerPhone]);
                if ($existing) {
                    $error = 'رقم الهاتف موجود بالفعل';
                }
            }
            
            if (empty($error)) {
                $db->execute(
                    "INSERT INTO customers (name, phone, email, address, status, created_by) 
                     VALUES (?, ?, ?, ?, 'active', ?)",
                    [$customerName, $customerPhone ?: null, $customerEmail ?: null, $customerAddress ?: null, $currentUser['id']]
                );
                
                $success = 'تم إضافة العميل بنجاح';
            }
        }
    } elseif ($action === 'complete_sale') {
        // إتمام عملية البيع
        $customerId = intval($_POST['customer_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $discountAmount = floatval($_POST['discount_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($customerId <= 0) {
            $error = 'يجب اختيار العميل';
        } elseif (empty($items) || !is_array($items)) {
            $error = 'يجب إضافة منتجات للبيع';
        } else {
            // التحقق من توفر الكميات
            $insufficientProducts = [];
            foreach ($items as $item) {
                $productId = intval($item['product_id'] ?? 0);
                $quantity = floatval($item['quantity'] ?? 0);
                
                if ($productId > 0 && $quantity > 0) {
                    $product = $db->queryOne("SELECT name, quantity FROM products WHERE id = ?", [$productId]);
                    if (!$product) {
                        $insufficientProducts[] = "المنتج غير موجود";
                    } elseif ($product['quantity'] < $quantity) {
                        $insufficientProducts[] = $product['name'] . " (المتاح: " . $product['quantity'] . ")";
                    }
                }
            }
            
            if (!empty($insufficientProducts)) {
                $error = 'الكميات غير كافية للمنتجات التالية: ' . implode(', ', $insufficientProducts);
            } else {
                // إنشاء الفاتورة
                $invoiceItems = [];
                foreach ($items as $item) {
                    $invoiceItems[] = [
                        'product_id' => intval($item['product_id']),
                        'description' => trim($item['description'] ?? ''),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
                
                $result = createInvoice($customerId, null, date('Y-m-d'), $invoiceItems, 0, $discountAmount, $notes);
                
                if ($result['success']) {
                    // تحديث المخزون
                    foreach ($items as $item) {
                        $productId = intval($item['product_id']);
                        $quantity = floatval($item['quantity']);
                        
                        recordInventoryMovement(
                            $productId,
                            $mainWarehouse['id'],
                            'out',
                            $quantity,
                            'invoice',
                            $result['invoice_id'],
                            'بيع مباشر من نقطة البيع'
                        );
                    }
                    
                    // تحديث حالة الفاتورة إلى مدفوعة (نقداً)
                    $db->execute(
                        "UPDATE invoices SET status = 'paid', paid_amount = total_amount, remaining_amount = 0 WHERE id = ?",
                        [$result['invoice_id']]
                    );
                    
                    logAudit($currentUser['id'], 'pos_sale', 'invoice', $result['invoice_id'], null, [
                        'invoice_number' => $result['invoice_number'],
                        'total_amount' => $result['total_amount'] ?? 0
                    ]);
                    
                    $success = 'تم إتمام عملية البيع بنجاح - رقم الفاتورة: ' . $result['invoice_number'];
                } else {
                    $error = $result['message'] ?? 'حدث خطأ في إنشاء الفاتورة';
                }
            }
        }
    }
}

// الحصول على المنتجات من المخزن الرئيسي
$unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
$hasUnitColumn = !empty($unitColumnCheck);

if ($hasUnitColumn) {
    $products = $db->query(
        "SELECT id, name, quantity, unit_price, unit, category 
         FROM products 
         WHERE status = 'active' AND quantity > 0 
         ORDER BY name"
    );
} else {
    $products = $db->query(
        "SELECT id, name, quantity, unit_price, category 
         FROM products 
         WHERE status = 'active' AND quantity > 0 
         ORDER BY name"
    );
    // إضافة unit افتراضي
    foreach ($products as &$product) {
        $product['unit'] = 'قطعة';
    }
}

// الحصول على العملاء
$customers = $db->query("SELECT id, name, phone FROM customers WHERE status = 'active' ORDER BY name");

$totalProductsCount = is_array($products) ? count($products) : 0;
$totalQuantity = 0.0;
$totalStockValue = 0.0;
$uniqueCategories = [];

if ($totalProductsCount > 0) {
    foreach ($products as $product) {
        $quantity = (float)($product['quantity'] ?? 0);
        $unitPrice = (float)($product['unit_price'] ?? 0);
        $totalQuantity += $quantity;
        $totalStockValue += $quantity * $unitPrice;

        $categoryLabel = trim((string)($product['category'] ?? ''));
        if ($categoryLabel !== '') {
            $uniqueCategories[$categoryLabel] = true;
        }
    }
}

$totalCategories = count($uniqueCategories);
?>

<div class="pos-page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h2 class="mb-1"><i class="bi bi-cash-register me-2"></i>نقطة البيع المحلية</h2>
        <p class="text-muted mb-0">بيع مباشر من مخزن الشركة الرئيسي وإدارة الفواتير الفورية.</p>
    </div>
    <div class="d-flex flex-wrap alignئا

