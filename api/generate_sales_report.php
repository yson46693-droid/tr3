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

// بناء استعلام SQL
$sql = "SELECT s.*, c.name as customer_name, 
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
               u.full_name as salesperson_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN products p ON s.product_id = p.id
        LEFT JOIN users u ON s.salesperson_id = u.id
        WHERE DATE(s.date) >= ? AND DATE(s.date) <= ?";

$params = [$dateFrom, $dateTo];

// إذا كان المستخدم مندوب مبيعات، عرض فقط مبيعاته
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

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'الفرع الرئيسي - العنوان: ابو يوسف الرئيسي';
$companyPhone = 'الهاتف: 0000000000';
$companyEmail = 'البريد الإلكتروني: info@example.com';

// تنسيق التواريخ
$dateFromFormatted = formatDate($dateFrom);
$dateToFormatted = formatDate($dateTo);
$generatedAt = formatDateTime(date('Y-m-d H:i:s'));

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        }

        .report-wrapper {
            max-width: 1000px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12);
            overflow: hidden;
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
        }

        .report-header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
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
        }

        .report-content {
            padding: 30px;
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
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .table {
            margin-bottom: 0;
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
        }

        .table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
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
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .report-header .meta-info {
                flex-direction: column;
                gap: 10px;
            }

            .print-actions {
                left: 10px;
                right: 10px;
                transform: none;
                flex-direction: column;
                padding: 15px;
            }

            .btn-print {
                width: 100%;
                justify-content: center;
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
                    <h4>لا توجد مبيعات في الفترة المحددة</h4>
                    <p>لم يتم تسجيل أي مبيعات خلال الفترة من <?php echo htmlspecialchars($dateFromFormatted); ?> إلى <?php echo htmlspecialchars($dateToFormatted); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>العميل</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المبلغ</th>
                                <th>الحالة</th>
                                <th>مندوب المبيعات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $sale): ?>
                                <?php
                                $status = strtolower($sale['status'] ?? 'pending');
                                $statusLabels = [
                                    'approved' => 'معتمدة',
                                    'pending' => 'معلقة',
                                    'rejected' => 'ملغاة'
                                ];
                                $statusBadgeClass = [
                                    'approved' => 'badge-approved',
                                    'pending' => 'badge-pending',
                                    'rejected' => 'badge-rejected'
                                ];
                                $statusLabel = $statusLabels[$status] ?? 'معلقة';
                                $badgeClass = $statusBadgeClass[$status] ?? 'badge-pending';
                                ?>
                                <tr>
                                    <td><?php echo formatDate($sale['date']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($sale['product_name'] ?? 'منتج غير محدد'); ?></td>
                                    <td><?php echo number_format((float)($sale['quantity'] ?? 0), 2); ?></td>
                                    <td><?php echo formatCurrency((float)($sale['total'] ?? 0)); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusLabel; ?></span></td>
                                    <td><?php echo htmlspecialchars($sale['salesperson_name'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: 600;">
                                <td colspan="3" style="text-align: left;">الإجمالي</td>
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

    <script>
        // طباعة تلقائية عند التحميل (اختياري - يمكن تعطيله)
        // window.addEventListener('load', function() {
        //     setTimeout(function() {
        //         window.print();
        //     }, 1000);
        // });
    </script>
</body>
</html>

