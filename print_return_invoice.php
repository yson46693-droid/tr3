<?php
/**
 * صفحة طباعة فاتورة المرتجع
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/returns_system.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['manager', 'accountant', 'sales']);

$returnId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($returnId <= 0) {
    die('رقم المرتجع غير صحيح');
}

$db = db();

$returnSummary = $db->queryOne(
    "SELECT 
        r.*,
        i.invoice_number,
        c.name as customer_name,
        c.phone as customer_phone,
        c.address as customer_address,
        u.full_name as sales_rep_name,
        u2.full_name as created_by_name
     FROM returns r
     LEFT JOIN invoices i ON r.invoice_id = i.id
     LEFT JOIN customers c ON r.customer_id = c.id
     LEFT JOIN users u ON r.sales_rep_id = u.id
     LEFT JOIN users u2 ON r.created_by = u2.id
     WHERE r.id = ?",
    [$returnId]
);

if (!$returnSummary) {
    die('المرتجع غير موجود');
}

$returnItems = $db->query(
    "SELECT ri.*, 
            p.name as product_name, 
            p.unit,
            bn.batch_number,
            ri.condition,
            ri.notes
     FROM return_items ri
     LEFT JOIN products p ON ri.product_id = p.id
     LEFT JOIN batch_numbers bn ON ri.batch_number_id = bn.id
     WHERE ri.return_id = ?
     ORDER BY ri.id",
    [$returnId]
);

// التأكد من أن return_date موجود، وإلا استخدم created_at أو التاريخ الحالي
if (empty($returnSummary['return_date']) && !empty($returnSummary['created_at'])) {
    $returnSummary['return_date'] = $returnSummary['created_at'];
} elseif (empty($returnSummary['return_date'])) {
    $returnSummary['return_date'] = date('Y-m-d');
}

// التأكد من أن جميع الحقول المطلوبة موجودة
if (empty($returnSummary['return_number'])) {
    $returnSummary['return_number'] = 'RET-' . str_pad($returnId, 4, '0', STR_PAD_LEFT);
}

$returnDetails = [
    'summary' => $returnSummary,
    'items'   => $returnItems,
];

$returnNumber = $returnSummary['return_number'] ?? ('RET-' . $returnId);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مرتجع <?php echo htmlspecialchars($returnNumber); ?></title>
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
                    <a href="<?php echo getRelativeUrl('dashboard/manager.php?page=returns'); ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>رجوع
                    </a>
                </div>
            </div>

            <?php 
            try {
                include __DIR__ . '/modules/accountant/invoice_print.php'; 
            } catch (Throwable $e) {
                error_log('Error printing return invoice: ' . $e->getMessage());
                echo '<div class="alert alert-danger">حدث خطأ في طباعة المرتجع: ' . htmlspecialchars($e->getMessage()) . '</div>';
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

