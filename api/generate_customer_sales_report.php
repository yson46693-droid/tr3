<?php
/**
 * API لإنشاء تقرير مبيعات العميل
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

// الحصول على معرف العميل
$customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customerId <= 0) {
    die('معرف العميل غير صحيح');
}

// الحصول على بيانات العميل
$customer = $db->queryOne("SELECT * FROM customers WHERE id = ?", [$customerId]);

if (!$customer) {
    die('العميل غير موجود');
}

// بناء استعلام SQL لجلب جميع مبيعات العميل مع أرقام التشغيلة
// نستخدم invoices و invoice_items مباشرة للحصول على أرقام التشغيلة بدقة
$sql = "SELECT 
               s.id,
               s.customer_id,
               s.product_id,
               s.quantity,
               s.price,
               s.total,
               s.date,
               s.salesperson_id,
               s.status,
               s.approved_by,
               s.approved_at,
               s.created_at,
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
                FROM invoices i
                INNER JOIN invoice_items ii ON i.id = ii.invoice_id
                LEFT JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                LEFT JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                WHERE i.customer_id = s.customer_id 
                  AND DATE(i.date) = DATE(s.date)
                  AND (
                    i.sales_rep_id = s.salesperson_id 
                    OR (i.sales_rep_id IS NULL AND s.salesperson_id IS NOT NULL)
                    OR (i.sales_rep_id IS NOT NULL AND s.salesperson_id IS NULL)
                    OR (i.sales_rep_id IS NULL AND s.salesperson_id IS NULL)
                  )
                  AND ii.product_id = s.product_id
                  AND bn.batch_number IS NOT NULL
                GROUP BY ii.id
                ORDER BY 
                  CASE WHEN i.sales_rep_id = s.salesperson_id THEN 0 ELSE 1 END,
                  i.id DESC, 
                  ii.id DESC
                LIMIT 1) as batch_numbers
        FROM sales s
        LEFT JOIN products p ON s.product_id = p.id
        LEFT JOIN users u ON s.salesperson_id = u.id
        WHERE s.customer_id = ?";

$params = [$customerId];

// إذا كان المستخدم مندوب مبيعات، عرض فقط مبيعاته لهذا العميل
if ($currentUser['role'] === 'sales') {
    $sql .= " AND s.salesperson_id = ?";
    $params[] = $currentUser['id'];
}

$sql .= " ORDER BY s.date DESC, s.created_at DESC";

$sales = $db->query($sql, $params);

// حساب الإحصائيات
$totalSales = count($sales);
$totalAmount = 0;
$totalQuantity = 0;
$statusCounts = [
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

foreach ($sales as $sale) {
    $totalAmount += (float)($sale['total'] ?? 0);
    $totalQuantity += (float)($sale['quantity'] ?? 0);
    $status = strtolower($sale['status'] ?? 'pending');
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// حساب الرصيد الدائن/المدين
$customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone = '01003533905';
$companyEmail = 'صفحة فيسبوك  : عسل نحل المصطفي';

// تنسيق التواريخ
$generatedAt = formatDateTime(date('Y-m-d H:i:s'));

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0,user-scalable=yes">
    <title>تقرير مبيعات العميل - <?php echo htmlspecialchars($customer['name']); ?></title>
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

        .customer-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .customer-info h3 {
            font-size: 20px;
            font-weight: 600;
            color: #0f4c81;
            margin-bottom: 15px;
        }

        .customer-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .customer-detail-item {
            display: flex;
            flex-direction: column;
        }

        .customer-detail-item label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
        }

        .customer-detail-item .value {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .stat-card .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f4c81;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #0f4c81;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
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

        .badge-debtor {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-creditor {
            background: #dbeafe;
            color: #1e40af;
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

            .customer-info {
                padding: 15px;
                border-radius: 12px;
            }

            .customer-details {
                grid-template-columns: 1fr;
                gap: 12px;
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

            .print-actions {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="report-wrapper">
        <div class="report-header">
            <h1><i class="bi bi-person-badge me-2"></i>تقرير مبيعات العميل</h1>
            <div class="subtitle"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="meta-info">
                <div class="meta-item">
                    <i class="bi bi-clock me-2"></i>
                    تاريخ الإنشاء: <?php echo htmlspecialchars($generatedAt); ?>
                </div>
            </div>
        </div>

        <div class="report-content">
            <div class="customer-info">
                <h3><i class="bi bi-person-circle me-2"></i>بيانات العميل</h3>
                <div class="customer-details">
                    <div class="customer-detail-item">
                        <label>اسم العميل</label>
                        <div class="value"><?php echo htmlspecialchars($customer['name']); ?></div>
                    </div>
                    <?php if (!empty($customer['phone'])): ?>
                    <div class="customer-detail-item">
                        <label>الهاتف</label>
                        <div class="value"><?php echo htmlspecialchars($customer['phone']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($customer['address'])): ?>
                    <div class="customer-detail-item">
                        <label>العنوان</label>
                        <div class="value"><?php echo htmlspecialchars($customer['address']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="customer-detail-item">
                        <label>الرصيد</label>
                        <div class="value">
                            <?php
                            $balanceValue = abs($customerBalance);
                            $balanceType = $customerBalance > 0 ? 'مدين' : ($customerBalance < 0 ? 'دائن' : 'صفر');
                            $balanceBadgeClass = $customerBalance > 0 ? 'badge-debtor' : ($customerBalance < 0 ? 'badge-creditor' : '');
                            ?>
                            <?php echo formatCurrency($balanceValue); ?>
                            <?php if ($customerBalance !== 0.0): ?>
                                <span class="badge <?php echo $balanceBadgeClass; ?> ms-2"><?php echo $balanceType; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">إجمالي المبيعات</div>
                    <div class="stat-value"><?php echo $totalSales; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">إجمالي المبلغ</div>
                    <div class="stat-value"><?php echo formatCurrency($totalAmount); ?></div>
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
                    <h4>لا توجد مبيعات لهذا العميل</h4>
                    <p>لم يتم تسجيل أي مبيعات للعميل <?php echo htmlspecialchars($customer['name']); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المبلغ</th>
                                <th>رقم التشغيلة</th>
                                <th>مندوب المبيعات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $sale): ?>
                                <?php
                                // جلب أرقام التشغيلة
                                $batchNumbers = !empty($sale['batch_numbers']) ? trim($sale['batch_numbers']) : '';
                                ?>
                                <tr>
                                    <td><?php echo formatDate($sale['date']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['product_name'] ?? 'منتج غير محدد'); ?></td>
                                    <td><?php echo number_format((float)($sale['quantity'] ?? 0), 2); ?></td>
                                    <td><?php echo formatCurrency((float)($sale['total'] ?? 0)); ?></td>
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
                                <td colspan="2" style="text-align: left;">الإجمالي</td>
                                <td><?php echo number_format($totalQuantity, 2); ?></td>
                                <td><?php echo formatCurrency($totalAmount); ?></td>
                                <td colspan="2"></td>
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

