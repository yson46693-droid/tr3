<?php
/**
 * صفحة طباعة فاتورة السلفة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/salary_calculator.php';
require_once __DIR__ . '/includes/path_helper.php';

requireAnyRole(['accountant', 'manager', 'sales', 'production']);

$advanceId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($advanceId <= 0) {
    die('رقم طلب السلفة غير صحيح');
}

$db = db();

// جلب بيانات السلفة
$advance = $db->queryOne(
    "SELECT sa.*, 
            u.full_name, u.username, u.role,
            accountant.full_name AS accountant_name,
            manager.full_name AS manager_name,
            manager.username AS manager_username
     FROM salary_advances sa
     LEFT JOIN users u ON sa.user_id = u.id
     LEFT JOIN users accountant ON sa.accountant_approved_by = accountant.id
     LEFT JOIN users manager ON sa.manager_approved_by = manager.id
     WHERE sa.id = ?",
    [$advanceId]
);

if (!$advance) {
    die('طلب السلفة غير موجود');
}

// جلب بيانات الراتب المرتبط بالسلفة
$salaryData = null;
$salaryDetails = null;

if (!empty($advance['deducted_from_salary_id'])) {
    $salaryDetails = $db->queryOne(
        "SELECT * FROM salaries WHERE id = ?",
        [$advance['deducted_from_salary_id']]
    );
    
    if ($salaryDetails) {
        // تنظيف القيم المالية
        $salaryDetails['base_amount'] = cleanFinancialValue($salaryDetails['base_amount'] ?? 0);
        $salaryDetails['bonus'] = cleanFinancialValue($salaryDetails['bonus'] ?? 0);
        $salaryDetails['deductions'] = cleanFinancialValue($salaryDetails['deductions'] ?? 0);
        $salaryDetails['total_amount'] = cleanFinancialValue($salaryDetails['total_amount'] ?? 0);
        $salaryDetails['collections_bonus'] = cleanFinancialValue($salaryDetails['collections_bonus'] ?? 0);
        $salaryDetails['collections_amount'] = cleanFinancialValue($salaryDetails['collections_amount'] ?? 0);
        $salaryDetails['advances_deduction'] = cleanFinancialValue($salaryDetails['advances_deduction'] ?? 0);
        
        // حساب الراتب الإجمالي مع نسبة التحصيلات
        $month = $salaryDetails['month'] ?? date('n');
        $year = $salaryDetails['year'] ?? date('Y');
        
        // حساب الخصومات الأخرى (بدون السلفة)
        // إذا كان هناك عمود advances_deduction، استخدمه لفصل السلفة عن الخصومات الأخرى
        if ($salaryDetails['advances_deduction'] > 0) {
            // السلفة موجودة في عمود منفصل
            $otherDeductions = $salaryDetails['deductions'] - $salaryDetails['advances_deduction'];
        } else {
            // السلفة موجودة في deductions، اطرحها
            $otherDeductions = max(0, $salaryDetails['deductions'] - $advanceAmount);
        }
        
        // حساب الراتب الإجمالي قبل خصم السلفة
        $baseAmount = $salaryDetails['base_amount'];
        $bonus = $salaryDetails['bonus'];
        $collectionsBonus = $salaryDetails['collections_bonus'];
        
        // الراتب الإجمالي قبل خصم السلفة
        $totalBeforeAdvance = $baseAmount + $bonus + $collectionsBonus - $otherDeductions;
        
        // الراتب الإجمالي بعد خصم السلفة (يجب أن يساوي total_amount في قاعدة البيانات)
        $totalAfterAdvance = $totalBeforeAdvance - $advanceAmount;
        
        // تحديث total_amount بالقيمة الصحيحة بعد خصم السلفة
        // إذا كان total_amount في قاعدة البيانات مختلفاً، استخدم القيمة المحسوبة
        if (abs($salaryDetails['total_amount'] - $totalAfterAdvance) > 0.01) {
            $salaryDetails['total_amount'] = max(0, $totalAfterAdvance);
        } else {
            // القيمة صحيحة، تأكد من أنها ليست سالبة
            $salaryDetails['total_amount'] = max(0, $salaryDetails['total_amount']);
        }
        
        // تحديث otherDeductions للعرض
        $salaryDetails['other_deductions'] = $otherDeductions;
        
        $salaryData = getSalarySummary($advance['user_id'], $month, $year);
    }
}

$companyName = COMPANY_NAME;
$companyAddress = 'الفرع الرئيسي';
$companyPhone = 'الهاتف: 0000000000';
$companyEmail = 'info@example.com';

$advanceDate = formatDate($advance['request_date']);
$advanceAmount = cleanFinancialValue($advance['amount']);
$advanceStatus = $advance['status'];
$statusLabels = [
    'pending' => 'قيد الانتظار',
    'accountant_approved' => 'موافق عليه من المحاسب',
    'manager_approved' => 'موافق عليه',
    'rejected' => 'مرفوض'
];
$statusLabel = $statusLabels[$advanceStatus] ?? $advanceStatus;

$employeeName = $advance['full_name'] ?? $advance['username'] ?? 'غير معروف';
$employeeUsername = $advance['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة سلفة - <?php echo htmlspecialchars($employeeName); ?></title>
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
            @page {
                margin: 1cm;
            }
        }
        body {
            background: #f5f5f5;
            font-family: 'Cairo', 'Arial', sans-serif;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .invoice-header {
            border-bottom: 3px solid #2d8cf0;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .company-info {
            text-align: center;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #2d8cf0;
            margin-bottom: 10px;
        }
        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin: 20px 0;
            text-align: center;
        }
        .info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #555;
        }
        .info-value {
            color: #333;
        }
        .table-section {
            margin: 30px 0;
        }
        .table-section h5 {
            background: #2d8cf0;
            color: white;
            padding: 10px 15px;
            margin: 0;
            border-radius: 5px 5px 0 0;
        }
        .table-section table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table-section th {
            background: #f8f9fa;
            padding: 12px;
            text-align: right;
            border: 1px solid #dee2e6;
            font-weight: bold;
        }
        .table-section td {
            padding: 12px;
            text-align: right;
            border: 1px solid #dee2e6;
        }
        .table-section tr:last-child td {
            font-weight: bold;
            background: #f8f9fa;
        }
        .total-box {
            background: #2d8cf0;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 30px 0;
        }
        .total-box .total-label {
            font-size: 18px;
            margin-bottom: 10px;
        }
        .total-box .total-value {
            font-size: 32px;
            font-weight: bold;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-around;
        }
        .signature-box {
            text-align: center;
            width: 200px;
        }
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 60px;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="invoice-container">
            <!-- أزرار التحكم -->
            <div class="row mb-4 no-print">
                <div class="col-12 text-end">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>طباعة
                    </button>
                    <a href="javascript:history.back()" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>رجوع
                    </a>
                </div>
            </div>
            
            <!-- رأس الفاتورة -->
            <div class="invoice-header">
                <div class="company-info">
                    <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
                    <div style="color: #666; margin-bottom: 10px;"><?php echo htmlspecialchars($companyAddress); ?></div>
                    <div style="color: #666; font-size: 14px;">
                        <?php echo htmlspecialchars($companyPhone); ?> | <?php echo htmlspecialchars($companyEmail); ?>
                    </div>
                </div>
                <div class="invoice-title">
                    <i class="bi bi-cash-coin me-2"></i>فاتورة سلفة
                </div>
            </div>
            
            <!-- معلومات السلفة -->
            <div class="info-section">
                <h5 class="mb-3"><i class="bi bi-info-circle me-2"></i>معلومات السلفة</h5>
                <div class="info-row">
                    <span class="info-label">رقم الطلب:</span>
                    <span class="info-value">#<?php echo $advanceId; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">تاريخ الطلب:</span>
                    <span class="info-value"><?php echo $advanceDate; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">مبلغ السلفة:</span>
                    <span class="info-value" style="font-weight: bold; color: #2d8cf0; font-size: 18px;">
                        <?php echo formatCurrency($advanceAmount); ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">الحالة:</span>
                    <span class="info-value">
                        <span class="badge bg-<?php echo $advanceStatus === 'manager_approved' ? 'success' : ($advanceStatus === 'rejected' ? 'danger' : 'warning'); ?>">
                            <?php echo $statusLabel; ?>
                        </span>
                    </span>
                </div>
                <?php if (!empty($advance['reason'])): ?>
                <div class="info-row">
                    <span class="info-label">سبب الطلب:</span>
                    <span class="info-value"><?php echo htmlspecialchars($advance['reason']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- معلومات الموظف -->
            <div class="info-section">
                <h5 class="mb-3"><i class="bi bi-person me-2"></i>معلومات الموظف</h5>
                <div class="info-row">
                    <span class="info-label">اسم الموظف:</span>
                    <span class="info-value"><?php echo htmlspecialchars($employeeName); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">اسم المستخدم:</span>
                    <span class="info-value">@<?php echo htmlspecialchars($employeeUsername); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">الدور:</span>
                    <span class="info-value">
                        <?php 
                        $roleLabels = [
                            'sales' => 'مندوب مبيعات',
                            'production' => 'موظف إنتاج',
                            'accountant' => 'محاسب',
                            'manager' => 'مدير'
                        ];
                        echo $roleLabels[$advance['role']] ?? $advance['role'];
                        ?>
                    </span>
                </div>
            </div>
            
            <!-- تفاصيل الراتب -->
            <?php if ($salaryDetails): ?>
            <div class="table-section">
                <h5><i class="bi bi-wallet2 me-2"></i>تفاصيل الراتب</h5>
                <table>
                    <thead>
                        <tr>
                            <th>البند</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>الراتب الأساسي</td>
                            <td><?php echo formatCurrency($salaryDetails['base_amount']); ?></td>
                        </tr>
                        <?php if ($advance['role'] === 'sales' && $salaryDetails['collections_bonus'] > 0): ?>
                        <tr>
                            <td>نسبة التحصيلات (2%)</td>
                            <td><?php echo formatCurrency($salaryDetails['collections_bonus']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($salaryDetails['bonus'] > 0): ?>
                        <tr>
                            <td>المكافآت</td>
                            <td><?php echo formatCurrency($salaryDetails['bonus']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php 
                        $otherDeductions = $salaryDetails['other_deductions'] ?? max(0, $salaryDetails['deductions'] - $advanceAmount);
                        if ($otherDeductions > 0 || $advanceAmount > 0): 
                        ?>
                        <?php if ($otherDeductions > 0): ?>
                        <tr>
                            <td>الخصومات الأخرى</td>
                            <td style="color: #dc3545;">-<?php echo formatCurrency($otherDeductions); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($advanceAmount > 0): ?>
                        <tr>
                            <td>خصم السلفة</td>
                            <td style="color: #dc3545; font-weight: bold;">-<?php echo formatCurrency($advanceAmount); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php endif; ?>
                        <tr>
                            <td><strong>الراتب الإجمالي (بعد خصم السلفة)</strong></td>
                            <td><strong><?php echo formatCurrency($salaryDetails['total_amount']); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- معلومات الموافقة -->
            <?php if ($advanceStatus === 'manager_approved'): ?>
            <div class="info-section">
                <h5 class="mb-3"><i class="bi bi-check-circle me-2"></i>معلومات الموافقة</h5>
                <?php if (!empty($advance['accountant_name'])): ?>
                <div class="info-row">
                    <span class="info-label">موافق عليه من المحاسب:</span>
                    <span class="info-value"><?php echo htmlspecialchars($advance['accountant_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($advance['manager_name'])): ?>
                <div class="info-row">
                    <span class="info-label">موافق عليه من المدير:</span>
                    <span class="info-value"><?php echo htmlspecialchars($advance['manager_name']); ?></span>
                </div>
                <?php if (!empty($advance['manager_approved_at'])): ?>
                <div class="info-row">
                    <span class="info-label">تاريخ الموافقة:</span>
                    <span class="info-value"><?php echo formatDateTime($advance['manager_approved_at']); ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- المبلغ الإجمالي -->
            <div class="total-box">
                <div class="total-label">مبلغ السلفة</div>
                <div class="total-value"><?php echo formatCurrency($advanceAmount); ?></div>
            </div>
            
            <!-- التوقيعات -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line">
                        <strong>توقيع الموظف</strong>
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">
                        <strong>توقيع المدير</strong>
                    </div>
                </div>
            </div>
            
            <!-- التذييل -->
            <div class="footer">
                <p>تم إصدار هذه الفاتورة إلكترونياً من نظام إدارة المبيعات</p>
                <p>تاريخ الطباعة: <?php echo formatDateTime(date('Y-m-d H:i:s')); ?></p>
            </div>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة
        window.onload = function() {
            if (window.location.search.includes('print=1')) {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        };
    </script>
</body>
</html>

