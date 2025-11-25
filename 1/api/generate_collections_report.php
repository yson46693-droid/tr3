<?php
/**
 * API لإنشاء تقرير التحصيلات حسب الفترة
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

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
$sql = "SELECT c.*, 
               cust.name as customer_name,
               u.full_name as collected_by_name
        FROM collections c
        LEFT JOIN customers cust ON c.customer_id = cust.id
        LEFT JOIN users u ON c.collected_by = u.id
        WHERE DATE(c.date) >= ? AND DATE(c.date) <= ?";

$params = [$dateFrom, $dateTo];

// إذا كان المستخدم مندوب مبيعات، عرض فقط تحصيلاته
if ($currentUser['role'] === 'sales') {
    $sql .= " AND c.collected_by = ?";
    $params[] = $currentUser['id'];
}

// التحقق من وجود عمود status
$statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
$hasStatusColumn = !empty($statusColumnCheck);

// التحقق من وجود عمود collection_number
$collectionNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'collection_number'");
$hasCollectionNumberColumn = !empty($collectionNumberColumnCheck);

$sql .= " ORDER BY c.date DESC, c.created_at DESC";

$collections = $db->query($sql, $params);

// حساب الإحصائيات
$totalCollections = count($collections);
$totalAmount = 0;
$paymentMethodCounts = [
    'cash' => 0,
    'bank_transfer' => 0,
    'check' => 0
];
$statusCounts = [
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

foreach ($collections as $collection) {
    $totalAmount += (float)($collection['amount'] ?? 0);
    
    $paymentMethod = strtolower($collection['payment_method'] ?? 'cash');
    if (isset($paymentMethodCounts[$paymentMethod])) {
        $paymentMethodCounts[$paymentMethod]++;
    }
    
    if ($hasStatusColumn) {
        $status = strtolower($collection['status'] ?? 'pending');
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
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

// تسميات طرق الدفع
$paymentMethodLabels = [
    'cash' => 'نقدي',
    'bank_transfer' => 'تحويل بنكي',
    'check' => 'شيك'
];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير التحصيلات - <?php echo htmlspecialchars($dateFromFormatted); ?> إلى <?php echo htmlspecialchars($dateToFormatted); ?></title>
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

        .badge-cash {
            background: #dcfce7;
            color: #166534;
        }

        .badge-bank {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-check {
            background: #fef3c7;
            color: #92400e;
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
            <h1><i class="bi bi-cash-coin me-2"></i>تقرير التحصيلات</h1>
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
                    <div class="stat-label">إجمالي التحصيلات</div>
                    <div class="stat-value"><?php echo $totalCollections; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">إجمالي المبلغ</div>
                    <div class="stat-value"><?php echo formatCurrency($totalAmount); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">نقدي</div>
                    <div class="stat-value"><?php echo $paymentMethodCounts['cash']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">تحويل بنكي</div>
                    <div class="stat-value"><?php echo $paymentMethodCounts['bank_transfer']; ?></div>
                </div>
                <?php if ($hasStatusColumn): ?>
                <div class="stat-card">
                    <div class="stat-label">معتمدة</div>
                    <div class="stat-value"><?php echo $statusCounts['approved']; ?></div>
                </div>
                <?php endif; ?>
            </div>

            <h2 class="section-title">تفاصيل التحصيلات</h2>

            <?php if (empty($collections)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h4>لا توجد تحصيلات في الفترة المحددة</h4>
                    <p>لم يتم تسجيل أي تحصيلات خلال الفترة من <?php echo htmlspecialchars($dateFromFormatted); ?> إلى <?php echo htmlspecialchars($dateToFormatted); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <?php if ($hasCollectionNumberColumn): ?>
                                <th>رقم التحصيل</th>
                                <?php endif; ?>
                                <th>التاريخ</th>
                                <th>العميل</th>
                                <th>المبلغ</th>
                                <th>طريقة الدفع</th>
                                <?php if ($hasStatusColumn): ?>
                                <th>الحالة</th>
                                <?php endif; ?>
                                <th>تم التحصيل بواسطة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($collections as $collection): ?>
                                <?php
                                $paymentMethod = strtolower($collection['payment_method'] ?? 'cash');
                                $paymentMethodLabel = $paymentMethodLabels[$paymentMethod] ?? 'نقدي';
                                $paymentMethodBadgeClass = [
                                    'cash' => 'badge-cash',
                                    'bank_transfer' => 'badge-bank',
                                    'check' => 'badge-check'
                                ];
                                $badgeClass = $paymentMethodBadgeClass[$paymentMethod] ?? 'badge-cash';
                                
                                if ($hasStatusColumn) {
                                    $status = strtolower($collection['status'] ?? 'pending');
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
                                    $statusBadge = $statusBadgeClass[$status] ?? 'badge-pending';
                                }
                                ?>
                                <tr>
                                    <?php if ($hasCollectionNumberColumn): ?>
                                    <td><?php echo htmlspecialchars($collection['collection_number'] ?? '#' . $collection['id']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo formatDate($collection['date']); ?></td>
                                    <td><?php echo htmlspecialchars($collection['customer_name'] ?? '-'); ?></td>
                                    <td><?php echo formatCurrency((float)($collection['amount'] ?? 0)); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $paymentMethodLabel; ?></span></td>
                                    <?php if ($hasStatusColumn): ?>
                                    <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($collection['collected_by_name'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: 600;">
                                <td colspan="<?php echo ($hasCollectionNumberColumn ? 3 : 2) + ($hasStatusColumn ? 2 : 1); ?>" style="text-align: left;">الإجمالي</td>
                                <td><?php echo formatCurrency($totalAmount); ?></td>
                                <td colspan="<?php echo $hasStatusColumn ? 1 : 0; ?>"></td>
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

