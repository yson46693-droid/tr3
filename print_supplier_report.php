<?php
/**
 * صفحة طباعة تقرير التوريدات للمورد
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/production_helper.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['accountant', 'manager']);

$supplierId = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');

if ($supplierId <= 0) {
    die('المورد غير محدد');
}

$db = db();

// الحصول على بيانات المورد
$supplier = $db->queryOne("SELECT * FROM suppliers WHERE id = ?", [$supplierId]);
if (!$supplier) {
    die('المورد غير موجود');
}

// الحصول على توريدات المورد في الفترة المحددة
$startDate = date('Y-m-d 00:00:00', strtotime($dateFrom));
$endDate = date('Y-m-d 23:59:59', strtotime($dateTo));

$supplyLogs = [];
if (ensureProductionSupplyLogsTable()) {
    try {
        $supplyLogs = $db->query(
            "SELECT id, supplier_id, supplier_name, material_category, material_label, quantity, unit, details, recorded_at
             FROM production_supply_logs
             WHERE supplier_id = ? AND recorded_at BETWEEN ? AND ?
             ORDER BY recorded_at DESC, id DESC",
            [$supplierId, $startDate, $endDate]
        );
    } catch (Exception $e) {
        error_log('Supplier report: failed to load supply logs -> ' . $e->getMessage());
        $supplyLogs = [];
    }
}

// حساب الإجماليات
$totalQuantity = 0.0;
$totalEntries = count($supplyLogs);
$categoryTotals = [];

foreach ($supplyLogs as $log) {
    $quantity = isset($log['quantity']) ? (float)$log['quantity'] : 0.0;
    $totalQuantity += $quantity;
    
    $category = $log['material_category'] ?? 'other';
    if (!isset($categoryTotals[$category])) {
        $categoryTotals[$category] = 0.0;
    }
    $categoryTotals[$category] += $quantity;
}

$supplierSupplyCategoryLabels = [
    'honey' => 'العسل',
    'olive_oil' => 'زيت الزيتون',
    'beeswax' => 'شمع العسل',
    'derivatives' => 'المشتقات',
    'nuts' => 'المكسرات',
    'packaging' => 'أدوات التعبئة',
    'raw' => 'المواد الخام',
    'sesame' => 'السمسم',
    'other' => 'مواد أخرى',
];

$companyName = COMPANY_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير التوريدات - <?php echo htmlspecialchars($supplier['name']); ?></title>
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
            .report-container {
                box-shadow: none;
                border: none;
            }
            .page-break {
                page-break-after: always;
            }
        }
        .report-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .report-header {
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .report-title {
            color: #0d6efd;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .supplier-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .summary-box {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .table {
            font-size: 14px;
        }
        .table thead {
            background: #0d6efd;
            color: white;
        }
        .table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        .category-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            background: #0d6efd;
            color: white;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #dee2e6;
            text-align: center;
            color: #6c757d;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="report-container">
            <div class="row mb-4 no-print">
                <div class="col-12 text-end">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>طباعة
                    </button>
                    <a href="<?php echo getRelativeUrl('dashboard/accountant.php?page=suppliers'); ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>رجوع
                    </a>
                </div>
            </div>
            
            <div class="report-header">
                <h2 class="report-title">
                    <i class="bi bi-file-earmark-text me-2"></i>تقرير التوريدات
                </h2>
                <div class="text-muted">
                    <?php echo htmlspecialchars($companyName); ?>
                </div>
            </div>
            
            <div class="supplier-info">
                <h5 class="mb-3"><i class="bi bi-truck me-2"></i>معلومات المورد</h5>
                <div class="row">
                    <div class="col-md-6">
                        <strong>اسم المورد:</strong> <?php echo htmlspecialchars($supplier['name']); ?>
                    </div>
                    <?php if (!empty($supplier['supplier_code'])): ?>
                    <div class="col-md-6">
                        <strong>كود المورد:</strong> <?php echo htmlspecialchars($supplier['supplier_code']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($supplier['phone'])): ?>
                    <div class="col-md-6 mt-2">
                        <strong>الهاتف:</strong> <?php echo htmlspecialchars($supplier['phone']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($supplier['email'])): ?>
                    <div class="col-md-6 mt-2">
                        <strong>البريد الإلكتروني:</strong> <?php echo htmlspecialchars($supplier['email']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="summary-box">
                <h5 class="mb-3"><i class="bi bi-calendar-range me-2"></i>الفترة</h5>
                <div class="row">
                    <div class="col-md-6">
                        <strong>من:</strong> <?php echo formatDate($dateFrom); ?>
                    </div>
                    <div class="col-md-6">
                        <strong>إلى:</strong> <?php echo formatDate($dateTo); ?>
                    </div>
                </div>
            </div>
            
            <div class="summary-box">
                <h5 class="mb-3"><i class="bi bi-bar-chart me-2"></i>ملخص التوريدات</h5>
                <div class="row">
                    <div class="col-md-4">
                        <strong>عدد السجلات:</strong> <?php echo number_format($totalEntries); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>إجمالي الكمية:</strong> <?php echo number_format($totalQuantity, 3); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>تاريخ الطباعة:</strong> <?php echo formatDate(date('Y-m-d')); ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($categoryTotals)): ?>
            <div class="summary-box mb-4">
                <h5 class="mb-3"><i class="bi bi-tags me-2"></i>الإجماليات حسب الفئة</h5>
                <div class="row">
                    <?php foreach ($categoryTotals as $category => $total): ?>
                    <div class="col-md-4 mb-2">
                        <span class="category-badge"><?php echo htmlspecialchars($supplierSupplyCategoryLabels[$category] ?? $category); ?></span>
                        <strong class="ms-2"><?php echo number_format($total, 3); ?></strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>الفئة</th>
                            <th>المادة</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplyLogs)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox me-2"></i>لا توجد توريدات في الفترة المحددة
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supplyLogs as $index => $log): ?>
                                <?php
                                    $recordedAt = $log['recorded_at'] ?? null;
                                    $entryDate = '—';
                                    $entryTime = '';
                                    if ($recordedAt) {
                                        $timestamp = strtotime($recordedAt);
                                        if ($timestamp) {
                                            $entryDate = formatDate($recordedAt);
                                            $entryTime = formatTime($recordedAt);
                                        }
                                    }
                                    $categoryKey = $log['material_category'] ?? '';
                                    $categoryLabel = $supplierSupplyCategoryLabels[$categoryKey] ?? ($categoryKey !== '' ? $categoryKey : '—');
                                    $materialLabel = trim((string)($log['material_label'] ?? ''));
                                    $details = trim((string)($log['details'] ?? ''));
                                    $quantityValue = isset($log['quantity']) ? (float)$log['quantity'] : 0.0;
                                    $unitLabel = isset($log['unit']) && $log['unit'] !== '' ? $log['unit'] : 'كجم';
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($entryDate); ?></div>
                                        <?php if ($entryTime !== ''): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars($entryTime); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="category-badge"><?php echo htmlspecialchars($categoryLabel); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($materialLabel !== '' ? $materialLabel : '-'); ?></td>
                                    <td>
                                        <span class="fw-semibold text-primary"><?php echo number_format($quantityValue, 3); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($unitLabel); ?></td>
                                    <td>
                                        <?php if ($details !== ''): ?>
                                            <?php echo htmlspecialchars($details); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($supplyLogs)): ?>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="4" class="text-end">الإجمالي:</th>
                            <th><?php echo number_format($totalQuantity, 3); ?></th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            
            <div class="footer">
                <div>تم إنشاء التقرير تلقائياً بواسطة النظام</div>
                <div class="mt-2"><?php echo htmlspecialchars($companyName); ?> - <?php echo date('Y'); ?></div>
            </div>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة
        window.onload = function() {
            if (window.location.search.includes('print=')) {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        };
    </script>
</body>
</html>

