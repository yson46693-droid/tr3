<?php
/**
 * API لإنشاء تقرير المبيعات حسب الفترة
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/product_name_helper.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();

// الحصول على الفترة
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // أول يوم من الشهر الحالي
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // اليوم الحالي

// التحقق من صحة التاريخ
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    die('تاريخ غير صحيح');
}

if (strtotime($dateFrom) > strtotime($dateTo)) {
    die('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
}

// بناء استعلام SQL - استخدام نفس المنطق المستخدم في صفحة manager.php
// استخدام invoice_items بدلاً من sales للحصول على نفس البيانات
$sql = "SELECT 
            ii.id as sale_id,
            ii.invoice_id,
            i.invoice_number,
            i.date,
            i.customer_id,
            i.sales_rep_id as salesperson_id,
            ii.product_id,
            ii.quantity,
            ii.unit_price as price,
            ii.total_price as total,
            i.status,
            c.name as customer_name,
            COALESCE(
                (SELECT fp2.product_name 
                 FROM finished_products fp2 
                 WHERE fp2.product_id = p.id 
                   AND fp2.product_name IS NOT NULL 
                   AND TRIM(fp2.product_name) != ''
                   AND fp2.product_name NOT LIKE 'منتج رقم%'
                 ORDER BY fp2.id DESC 
                 LIMIT 1),
                NULLIF(TRIM(p.name), ''),
                CONCAT('منتج رقم ', p.id)
            ) as product_name,
            u.full_name as salesperson_name,
            (SELECT GROUP_CONCAT(DISTINCT bn.batch_number ORDER BY bn.batch_number SEPARATOR ', ')
             FROM sales_batch_numbers sbn2
             LEFT JOIN batch_numbers bn ON sbn2.batch_number_id = bn.id
             WHERE sbn2.invoice_item_id = ii.id
               AND bn.batch_number IS NOT NULL
             LIMIT 1) as batch_numbers,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM shipping_company_orders sco 
                    WHERE sco.invoice_id = i.id
                ) THEN 'shipping'
                WHEN i.sales_rep_id IS NOT NULL AND u.role = 'sales' THEN 'rep'
                ELSE 'manager'
            END as sale_type
        FROM invoice_items ii
        INNER JOIN invoices i ON ii.invoice_id = i.id
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN users u ON i.sales_rep_id = u.id
        WHERE i.status != 'cancelled'
          AND DATE(i.date) >= ? AND DATE(i.date) <= ?";

$params = [$dateFrom, $dateTo];

// إذا كان المستخدم مندوب مبيعات، عرض فقط مبيعاته
if ($currentUser['role'] === 'sales') {
    $sql .= " AND i.sales_rep_id = ?";
    $params[] = $currentUser['id'];
}

$sql .= " ORDER BY i.date DESC, i.created_at DESC, ii.id DESC";

$sales = $db->query($sql, $params);

// جلب المرتجعات لكل منتج (نفس المنطق المستخدم في manager.php)
$returnsQuery = "
    SELECT 
        ri.product_id,
        COALESCE(SUM(ri.quantity), 0) AS returned_qty,
        COALESCE(SUM(ri.total_price), 0) AS returned_total
    FROM return_items ri
    INNER JOIN sales_returns sr ON ri.return_id = sr.id
    WHERE sr.status IN ('approved', 'processed')
      AND DATE(sr.return_date) >= ? AND DATE(sr.return_date) <= ?
    GROUP BY ri.product_id
";

$returnsData = $db->query($returnsQuery, [$dateFrom, $dateTo]);
$returnsByProduct = [];
foreach ($returnsData as $return) {
    $returnsByProduct[$return['product_id']] = [
        'qty' => (float)$return['returned_qty'],
        'total' => (float)$return['returned_total']
    ];
}

// حساب الإحصائيات مع مراعاة المرتجعات
$totalSales = 0;
$totalAmount = 0;
$totalQuantity = 0;
$statusCounts = [
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

// تجميع المبيعات حسب المنتج لحساب المرتجعات بشكل صحيح
$salesByProduct = [];
foreach ($sales as $sale) {
    $productId = $sale['product_id'];
    if (!isset($salesByProduct[$productId])) {
        $salesByProduct[$productId] = [
            'quantity' => 0,
            'total' => 0
        ];
    }
    $salesByProduct[$productId]['quantity'] += (float)($sale['quantity'] ?? 0);
    $salesByProduct[$productId]['total'] += (float)($sale['total'] ?? 0);
}

// حساب المرتجعات المتناسبة لكل منتج
$returnedAmountByProduct = [];
foreach ($salesByProduct as $productId => $productSales) {
    $returnedQty = isset($returnsByProduct[$productId]) ? $returnsByProduct[$productId]['qty'] : 0;
    $returnedTotal = isset($returnsByProduct[$productId]) ? $returnsByProduct[$productId]['total'] : 0;
    
    if ($productSales['quantity'] > 0) {
        // توزيع المرتجعات بشكل متناسب
        $returnRatio = min(1, $returnedQty / $productSales['quantity']);
        $returnedAmountByProduct[$productId] = $productSales['total'] * $returnRatio;
    } else {
        $returnedAmountByProduct[$productId] = 0;
    }
}

// حساب الإجماليات النهائية
foreach ($sales as $sale) {
    $productId = $sale['product_id'];
    $saleQty = (float)($sale['quantity'] ?? 0);
    $saleTotal = (float)($sale['total'] ?? 0);
    
    // حساب نسبة هذا البيع من إجمالي مبيعات المنتج
    $productTotalQty = $salesByProduct[$productId]['quantity'];
    $productReturnedAmount = $returnedAmountByProduct[$productId] ?? 0;
    
    if ($productTotalQty > 0) {
        $saleRatio = $saleQty / $productTotalQty;
        $saleReturnedAmount = $productReturnedAmount * $saleRatio;
        $netTotal = max(0, $saleTotal - $saleReturnedAmount);
    } else {
        $netTotal = $saleTotal;
    }
    
    $totalSales++;
    $totalAmount += $netTotal;
    $totalQuantity += $saleQty;
    
    $status = strtolower($sale['status'] ?? 'pending');
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone = '01003533905';
$companyEmail = 'صفحة فيسبوك  : عسل نحل المصطفي';

// تنسيق التواريخ
$dateFromFormatted = formatDate($dateFrom);
$dateToFormatted = formatDate($dateTo);
$generatedAt = formatDateTime(date('Y-m-d H:i:s'));

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0,user-scalable=yes">
    <title>تقرير المبيعات - <?php echo htmlspecialchars($dateFromFormatted); ?> إلى <?php echo htmlspecialchars($dateToFormatted); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Cairo', Arial, sans-serif;
            background: #f8fafc;
            color: #1f2937;
            padding: 20px;
            line-height: 1.6;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .report-wrapper {
            max-width: 1000px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            width: 100%;
            max-width: 100%;
        }

        .report-header {
            background: linear-gradient(135deg, #0f4c81 0%, #1e5a9e 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
            width: 100%;
        }

        .report-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            word-wrap: break-word;
        }

        .report-header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
            word-wrap: break-word;
        }

        .report-header .meta-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 14px;
            margin-top: 15px;
        }

        .report-header .meta-item {
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 16px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
            white-space: nowrap;
        }

        .report-content {
            padding: 30px;
            width: 100%;
            max-width: 100%;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            width: 100%;
            max-width: 100%;
        }

        .stat-card .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
            word-wrap: break-word;
        }

        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f4c81;
            word-wrap: break-word;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #0f4c81;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            word-wrap: break-word;
        }

        .table-responsive {
            border-radius: 16px;
            overflow-x: auto;
            overflow-y: visible;
            border: 1px solid #e2e8f0;
            width: 100%;
            max-width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 600px;
            table-layout: auto;
        }

        .table thead {
            background: linear-gradient(135deg, rgba(15, 76, 129, 0.1), rgba(15, 76, 129, 0.05));
        }

        .table thead th {
            background: transparent;
            color: #0f4c81;
            font-weight: 600;
            padding: 14px;
            border-bottom: 2px solid #e2e8f0;
            text-align: right;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-pending {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-warning {
            background: #fbbf24;
            color: #78350f;
        }

        .badge-info {
            background: #0dcaf0;
            color: #055160;
        }

        .print-actions {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #ffffff;
            padding: 15px 30px;
            border-radius: 50px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.2);
            z-index: 1000;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-print {
            background: #0f4c81;
            color: #ffffff;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .btn-print:hover {
            background: #0d3d6b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 76, 129, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @media screen and (max-width: 768px) {
            body {
                padding: 15px 10px;
            }

            .report-wrapper {
                border-radius: 12px;
            }

            .report-header {
                padding: 20px 15px;
            }

            .report-header h1 {
                font-size: 22px;
            }

            .report-header .subtitle {
                font-size: 14px;
            }

            .report-header .meta-info {
                flex-direction: column;
                gap: 10px;
                font-size: 13px;
            }

            .report-header .meta-item {
                padding: 6px 12px;
                font-size: 12px;
            }

            .report-content {
                padding: 20px 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
                margin-bottom: 20px;
            }

            .stat-card {
                padding: 15px;
                border-radius: 12px;
            }

            .stat-card .stat-label {
                font-size: 13px;
            }

            .stat-card .stat-value {
                font-size: 20px;
            }

            .section-title {
                font-size: 18px;
                margin-bottom: 15px;
            }

            .table-responsive {
                border-radius: 12px;
                margin: 0 -10px;
                width: calc(100% + 20px);
                max-width: calc(100% + 20px);
            }

            .table {
                min-width: 500px;
                font-size: 12px;
            }

            .table thead th {
                padding: 10px 8px;
                font-size: 11px;
            }

            .table tbody td {
                padding: 10px 8px;
                font-size: 11px;
            }

            .badge {
                padding: 5px 10px;
                font-size: 11px;
            }

            .print-actions {
                left: 10px;
                right: 10px;
                transform: none;
                flex-direction: column;
                padding: 15px;
                border-radius: 12px;
            }

            .btn-print {
                width: 100%;
                justify-content: center;
            }

            .empty-state {
                padding: 40px 15px;
            }

            .empty-state i {
                font-size: 48px;
            }
        }

        @media screen and (max-width: 480px) {
            body {
                padding: 10px 8px;
            }

            .report-header {
                padding: 15px 12px;
            }

            .report-header h1 {
                font-size: 20px;
            }

            .report-header .subtitle {
                font-size: 13px;
            }

            .report-content {
                padding: 15px 12px;
            }

            .stat-card {
                padding: 12px;
            }

            .stat-card .stat-label {
                font-size: 12px;
            }

            .stat-card .stat-value {
                font-size: 18px;
            }

            .section-title {
                font-size: 16px;
            }

            .table {
                min-width: 450px;
                font-size: 11px;
            }

            .table thead th {
                padding: 8px 6px;
                font-size: 10px;
            }

            .table tbody td {
                padding: 8px 6px;
                font-size: 10px;
            }

            .badge {
                padding: 4px 8px;
                font-size: 10px;
            }

            .print-actions {
                padding: 12px;
            }
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }

            .print-actions {
                display: none !important;
            }

            .report-wrapper {
                box-shadow: none;
                border-radius: 0;
            }

            .report-header {
                page-break-after: avoid;
            }

            .table {
                page-break-inside: avoid;
            }

            .table-responsive {
                overflow: visible;
            }

            .table {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <div class="report-wrapper">
        <div class="report-header">
            <h1><i class="bi bi-file-earmark-text me-2"></i>تقرير المبيعات</h1>
            <div class="subtitle"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="meta-info">
                <div class="meta-item">
                    <i class="bi bi-calendar3 me-2"></i>
                    من: <?php echo htmlspecialchars($dateFromFormatted); ?>
                </div>
                <div class="meta-item">
                    <i class="bi bi-calendar3 me-2"></i>
                    إلى: <?php echo htmlspecialchars($dateToFormatted); ?>
                </div>
                <div class="meta-item">
                    <i class="bi bi-clock me-2"></i>
                    تاريخ الإنشاء: <?php echo htmlspecialchars($generatedAt); ?>
                </div>
            </div>
        </div>

        <div class="report-content">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">عدد عمليات البيع</div>
                    <div class="stat-value"><?php echo $totalSales; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">إجمالي المبلغ الصافي</div>
                    <div class="stat-value"><?php echo formatCurrency($totalAmount); ?></div>
                    <small style="color: #94a3b8; font-size: 12px;">بعد خصم المرتجعات</small>
                </div>
                <div class="stat-card">
                    <div class="stat-label">إجمالي الكمية</div>
                    <div class="stat-value"><?php echo number_format($totalQuantity, 2); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">المعتمدة</div>
                    <div class="stat-value"><?php echo $statusCounts['approved']; ?></div>
                </div>
            </div>

            <h2 class="section-title">تفاصيل المبيعات</h2>

            <?php if (empty($sales)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h4>لا توجد مبيعات في الفترة المحددة</h4>
                    <p>لم يتم تسجيل أي مبيعات خلال الفترة من <?php echo htmlspecialchars($dateFromFormatted); ?> إلى <?php echo htmlspecialchars($dateToFormatted); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>رقم الفاتورة</th>
                                <th>العميل</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المبلغ</th>
                                <th>النوع</th>
                                <th>رقم التشغيلة</th>
                                <th>مندوب المبيعات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $sale): ?>
                                <?php
                                // جلب أرقام التشغيلة
                                $batchNumbers = !empty($sale['batch_numbers']) ? trim($sale['batch_numbers']) : '';
                                
                                // تحديد نوع البيع
                                $saleType = $sale['sale_type'] ?? 'manager';
                                $saleTypeLabels = [
                                    'shipping' => 'شحن',
                                    'rep' => 'مندوب',
                                    'manager' => 'مدير'
                                ];
                                $saleTypeLabel = $saleTypeLabels[$saleType] ?? 'غير محدد';
                                $saleTypeBadgeClass = [
                                    'shipping' => 'badge-info',
                                    'rep' => 'badge-primary',
                                    'manager' => 'badge-warning'
                                ];
                                $badgeClass = $saleTypeBadgeClass[$saleType] ?? 'badge-secondary';
                                
                                // حساب المبلغ الصافي بعد خصم المرتجعات
                                $productId = $sale['product_id'];
                                $saleQty = (float)($sale['quantity'] ?? 0);
                                $saleTotal = (float)($sale['total'] ?? 0);
                                $productTotalQty = $salesByProduct[$productId]['quantity'] ?? $saleQty;
                                $productReturnedAmount = $returnedAmountByProduct[$productId] ?? 0;
                                
                                if ($productTotalQty > 0) {
                                    $saleRatio = $saleQty / $productTotalQty;
                                    $saleReturnedAmount = $productReturnedAmount * $saleRatio;
                                    $netTotal = max(0, $saleTotal - $saleReturnedAmount);
                                } else {
                                    $netTotal = $saleTotal;
                                }
                                ?>
                                <tr>
                                    <td><?php echo formatDate($sale['date']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['invoice_number'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($sale['product_name'] ?? 'منتج غير محدد'); ?></td>
                                    <td><?php echo number_format($saleQty, 2); ?></td>
                                    <td><?php echo formatCurrency($netTotal); ?></td>
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?>" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($saleTypeLabel); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($batchNumbers)): ?>
                                            <span class="badge badge-info" style="background: #0dcaf0; color: #055160; font-size: 11px;">
                                                <?php echo htmlspecialchars($batchNumbers); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($sale['salesperson_name'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: 600;">
                                <td colspan="4" style="text-align: left;">الإجمالي الصافي</td>
                                <td><?php echo number_format($totalQuantity, 2); ?></td>
                                <td><?php echo formatCurrency($totalAmount); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-actions">
        <button class="btn-print" onclick="window.print()">
            <i class="bi bi-printer"></i>
            <span>طباعة / حفظ كـ PDF</span>
        </button>
    </div>
</body>
</html>

