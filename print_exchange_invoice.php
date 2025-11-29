<?php
/**
 * صفحة طباعة فاتورة الاستبدال
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exchanges.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/product_name_helper.php';

requireRole(['manager', 'accountant', 'sales']);

$exchangeId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($exchangeId <= 0) {
    die('رقم الاستبدال غير صحيح');
}

$db = db();
$currentUser = getCurrentUser();

// التحقق من ملكية الاستبدال للمندوب (إذا كان المستخدم مندوب)
if ($currentUser['role'] === 'sales') {
    $exchange = $db->queryOne("SELECT sales_rep_id FROM exchanges WHERE id = ?", [$exchangeId]);
    if (!$exchange || (int)($exchange['sales_rep_id'] ?? 0) !== (int)$currentUser['id']) {
        die('غير مصرح لك بعرض فاتورة هذا الاستبدال');
    }
}

// جلب بيانات الاستبدال
$exchangeSummary = $db->queryOne(
    "SELECT 
        e.*,
        c.name as customer_name,
        c.phone as customer_phone,
        c.address as customer_address,
        u.full_name as sales_rep_name,
        u2.full_name as created_by_name,
        r.return_number,
        i.invoice_number as original_invoice_number
     FROM exchanges e
     LEFT JOIN customers c ON e.customer_id = c.id
     LEFT JOIN users u ON e.sales_rep_id = u.id
     LEFT JOIN users u2 ON e.created_by = u2.id
     LEFT JOIN returns r ON e.return_id = r.id
     LEFT JOIN invoices i ON r.invoice_id = i.id
     WHERE e.id = ?",
    [$exchangeId]
);

if (!$exchangeSummary) {
    die('الاستبدال غير موجود');
}

// جلب المنتجات المرتجعة
$returnItems = [];
$hasExchangeReturnItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_return_items'"));
if ($hasExchangeReturnItems) {
    $returnItems = $db->query(
        "SELECT 
            eri.*,
            p.name as product_name,
            p.unit,
            bn.batch_number,
            COALESCE(
                (SELECT fp2.product_name 
                 FROM finished_products fp2 
                 WHERE fp2.product_id = eri.product_id 
                   AND fp2.product_name IS NOT NULL 
                   AND TRIM(fp2.product_name) != ''
                   AND fp2.product_name NOT LIKE 'منتج رقم%'
                 ORDER BY fp2.id DESC 
                 LIMIT 1),
                NULLIF(TRIM(p.name), ''),
                CONCAT('منتج رقم ', eri.product_id)
            ) as resolved_product_name
         FROM exchange_return_items eri
         LEFT JOIN products p ON eri.product_id = p.id
         LEFT JOIN batch_numbers bn ON eri.batch_number_id = bn.id
         WHERE eri.exchange_id = ?
         ORDER BY eri.id",
        [$exchangeId]
    ) ?: [];
}

// جلب المنتجات الجديدة
$newItems = [];
$hasExchangeNewItems = !empty($db->queryOne("SHOW TABLES LIKE 'exchange_new_items'"));
if ($hasExchangeNewItems) {
    $newItems = $db->query(
        "SELECT 
            eni.*,
            p.name as product_name,
            p.unit,
            bn.batch_number,
            COALESCE(
                (SELECT fp2.product_name 
                 FROM finished_products fp2 
                 WHERE fp2.product_id = eni.product_id 
                   AND fp2.product_name IS NOT NULL 
                   AND TRIM(fp2.product_name) != ''
                   AND fp2.product_name NOT LIKE 'منتج رقم%'
                 ORDER BY fp2.id DESC 
                 LIMIT 1),
                NULLIF(TRIM(p.name), ''),
                CONCAT('منتج رقم ', eni.product_id)
            ) as resolved_product_name
         FROM exchange_new_items eni
         LEFT JOIN products p ON eni.product_id = p.id
         LEFT JOIN batch_numbers bn ON eni.batch_number_id = bn.id
         WHERE eni.exchange_id = ?
         ORDER BY eni.id",
        [$exchangeId]
    ) ?: [];
}

// دمج العناصر لعرضها في الفاتورة
$allItems = [];

// إضافة المنتجات المرتجعة
foreach ($returnItems as $item) {
    $productName = $item['resolved_product_name'] ?? $item['product_name'] ?? 'منتج';
    if (!empty($item['batch_number'])) {
        $productName .= ' (تشغيلة: ' . $item['batch_number'] . ')';
    }
    
    $allItems[] = [
        'product_name' => $productName,
        'description' => 'مرتجع',
        'quantity' => (float)($item['quantity'] ?? 0),
        'unit_price' => (float)($item['unit_price'] ?? 0),
        'total_price' => (float)($item['total_price'] ?? ($item['quantity'] * $item['unit_price'])),
        'batch_number' => $item['batch_number'] ?? null,
        'notes' => $item['notes'] ?? '',
    ];
}

// إضافة المنتجات الجديدة
foreach ($newItems as $item) {
    $productName = $item['resolved_product_name'] ?? $item['product_name'] ?? 'منتج';
    if (!empty($item['batch_number'])) {
        $productName .= ' (تشغيلة: ' . $item['batch_number'] . ')';
    }
    
    $allItems[] = [
        'product_name' => $productName,
        'description' => 'جديد',
        'quantity' => (float)($item['quantity'] ?? 0),
        'unit_price' => (float)($item['unit_price'] ?? 0),
        'total_price' => (float)($item['total_price'] ?? ($item['quantity'] * $item['unit_price'])),
        'batch_number' => $item['batch_number'] ?? null,
        'notes' => $item['notes'] ?? '',
    ];
}

// إعداد بيانات الفاتورة للطباعة
$exchangeTypeLabels = [
    'same_product' => 'نفس المنتج',
    'different_product' => 'منتج مختلف',
    'upgrade' => 'ترقية',
    'downgrade' => 'تخفيض'
];

$exchangeTypeLabel = $exchangeTypeLabels[$exchangeSummary['exchange_type']] ?? $exchangeSummary['exchange_type'];

$exchangeDetails = [
    'summary' => $exchangeSummary,
    'items' => $allItems,
];

// تمرير البيانات لقالب الفاتورة
$returnDetails = $exchangeDetails; // استخدام نفس المتغير للتوافق مع القالب
$isReturnDocument = true;

$returnSummary = $exchangeSummary;
$returnSummary['return_number'] = $exchangeSummary['exchange_number'] ?? ('EXC-' . $exchangeId);
$returnSummary['return_date'] = $exchangeSummary['exchange_date'] ?? date('Y-m-d');
$returnSummary['refund_amount'] = abs((float)($exchangeSummary['difference_amount'] ?? 0));
$returnSummary['return_type'] = $exchangeTypeLabel;
$returnSummary['reason'] = 'استبدال منتجات - ' . $exchangeTypeLabel;
$returnSummary['notes'] = $exchangeSummary['notes'] ?? '';

$companyName = COMPANY_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة استبدال <?php echo htmlspecialchars($exchangeSummary['exchange_number'] ?? 'EXC-' . $exchangeId); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 20px;
            }
            .invoice-container {
                box-shadow: none;
                border: none;
            }
        }
        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 16px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="invoice-container">
            <div class="row mb-4 no-print">
                <div class="col-12 text-end">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>طباعة
                    </button>
                    <a href="<?php echo getRelativeUrl('dashboard/sales.php?page=sales_records'); ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>رجوع
                    </a>
                </div>
            </div>

            <?php 
            try {
                // تعديل بيانات الاستبدال لتتوافق مع قالب المرتجع
                $returnDetails = [
                    'summary' => $returnSummary,
                    'items' => $allItems,
                ];
                
                include __DIR__ . '/modules/accountant/invoice_print.php'; 
            } catch (Throwable $e) {
                error_log('Error printing exchange invoice: ' . $e->getMessage());
                echo '<div class="alert alert-danger">حدث خطأ في طباعة فاتورة الاستبدال: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 400);
        });
    </script>
</body>
</html>

