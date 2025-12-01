<?php
/**
 * صفحة طباعة كشف حساب المرتب - تصميم محسّن مشابه لكشف حساب العميل
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireAnyRole(['accountant', 'manager']);

// المتغيرات المطلوبة من salaries.php
if (!isset($employee) || !isset($periodLabel) || !isset($statementSalaries) || !isset($statementAdvances) || !isset($statementSettlements)) {
    die('بيانات غير كاملة');
}

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyPhone = '01003533905';
$statementDate = formatDate(date('Y-m-d'));

// بناء رابط الرجوع
$rawScript = $_SERVER['PHP_SELF'] ?? '/dashboard/accountant.php';
$rawScript = ltrim($rawScript, '/');
$isManagerPage = (strpos($rawScript, 'manager.php') !== false);

if ($isManagerPage) {
    $backUrl = getRelativeUrl('dashboard/manager.php') . '?page=salaries&view=list';
} else {
    $backUrl = getRelativeUrl('dashboard/accountant.php') . '?page=salaries&view=list';
}

// إضافة معاملات الشهر والسنة إذا كانت موجودة
if (isset($_GET['month']) && isset($_GET['year'])) {
    $backUrl .= '&month=' . intval($_GET['month']) . '&year=' . intval($_GET['year']);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0,user-scalable=yes">
    <title>كشف حساب المرتب - <?php echo htmlspecialchars($employee['full_name'] ?? $employee['username']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .statement-wrapper {
                box-shadow: none;
                border: none;
                max-width: 100%;
            }
            .page-break {
                page-break-after: always;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
            color: #1f2937;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }
        
        .statement-wrapper {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            padding: 32px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
            position: relative;
            overflow: hidden;
            width: 100%;
            max-width: 100%;
        }
        
        .statement-wrapper::before {
            content: '';
            position: absolute;
            top: -40%;
            left: -25%;
            width: 60%;
            height: 120%;
            background: radial-gradient(circle at center, rgba(15, 76, 129, 0.12), transparent 70%);
            z-index: 0;
        }
        
        .statement-wrapper > * {
            position: relative;
            z-index: 1;
        }
        
        .statement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .brand-block {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            min-width: 200px;
        }
        
        .logo-placeholder {
            width: 80px;
            height: 80px;
            min-width: 80px;
            border-radius: 24px;
            background: linear-gradient(135deg, rgb(6, 59, 134) 0%, rgb(3, 71, 155) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
            overflow: hidden;
            position: relative;
            box-shadow: 0 12px 24px rgba(15, 76, 129, 0.25);
        }
        
        .company-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 4px;
        }
        
        .logo-letter {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
            line-height: 1.3;
        }
        
        .statement-meta {
            text-align: left;
            flex: 1;
            min-width: 200px;
        }
        
        .statement-title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .statement-date {
            font-size: 14px;
            color: #6b7280;
        }
        
        .employee-info {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .employee-info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 12px;
        }
        
        .employee-info-item {
            display: flex;
            flex-direction: column;
        }
        
        .employee-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .employee-info-value {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            word-break: break-word;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid rgba(15, 76, 129, 0.12);
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
        }
        
        .transactions-table::-webkit-scrollbar {
            height: 8px;
        }
        
        .transactions-table::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .transactions-table::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .transactions-table::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .transactions-table thead {
            background: linear-gradient(135deg, rgba(15, 76, 129, 0.1), rgba(15, 76, 129, 0.05));
        }
        
        .transactions-table th {
            padding: 14px 12px;
            text-align: right;
            font-weight: 600;
            font-size: 13px;
            color: #0f4c81;
            border-bottom: 1px solid rgba(15, 76, 129, 0.12);
            white-space: nowrap;
            min-width: 100px;
        }
        
        .transactions-table td {
            padding: 16px 12px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
            font-size: 14px;
            text-align: right;
            white-space: nowrap;
        }
        
        .transactions-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .transactions-table tbody tr:hover {
            background-color: rgba(15, 76, 129, 0.02);
        }
        
        .amount-positive {
            color: #059669;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .summary-section {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .summary-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 18px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 2px solid #1f2937;
        }
        
        .summary-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .summary-value {
            color: #1f2937;
            font-weight: 600;
            font-size: 16px;
        }
        
        .summary-row:last-child .summary-value {
            font-size: 18px;
        }
        
        .footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        
        .buttons-container {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            gap: 12px;
            padding: 0 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .print-button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            max-width: 150px;
            justify-content: center;
        }
        
        .print-button:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.5);
        }
        
        .back-button {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.4);
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            max-width: 150px;
            justify-content: center;
        }
        
        .back-button:hover {
            background: #4b5563;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(107, 114, 128, 0.5);
            color: white;
            text-decoration: none;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .statement-wrapper {
                padding: 20px;
                border-radius: 16px;
            }
            
            .statement-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .brand-block {
                width: 100%;
            }
            
            .logo-placeholder {
                width: 60px;
                height: 60px;
                min-width: 60px;
                font-size: 32px;
            }
            
            .company-name {
                font-size: 20px;
            }
            
            .statement-title {
                font-size: 18px;
            }
            
            .statement-meta {
                width: 100%;
            }
            
            .employee-info {
                padding: 15px;
            }
            
            .employee-info-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .transactions-table {
                font-size: 12px;
                margin: 0 -10px;
                width: calc(100% + 20px);
                max-width: calc(100% + 20px);
            }
            
            .transactions-table th,
            .transactions-table td {
                padding: 10px 8px;
                font-size: 12px;
                min-width: 80px;
            }
            
            .transactions-table table {
                min-width: 500px;
            }
            
            .section-title {
                font-size: 16px;
            }
            
            .summary-section {
                padding: 15px;
            }
            
            .summary-row {
                font-size: 14px;
            }
            
            .summary-value {
                font-size: 14px;
            }
            
            .summary-row:last-child .summary-value {
                font-size: 16px;
            }
            
            .buttons-container {
                flex-direction: column;
                gap: 10px;
                left: 10px;
                right: 10px;
            }
            
            .print-button,
            .back-button {
                max-width: 100%;
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .statement-wrapper {
                padding: 15px;
            }
            
            .logo-placeholder {
                width: 50px;
                height: 50px;
                min-width: 50px;
                font-size: 24px;
            }
            
            .company-name {
                font-size: 18px;
            }
            
            .statement-title {
                font-size: 16px;
            }
            
            .transactions-table {
                margin: 0 -8px;
                width: calc(100% + 16px);
                max-width: calc(100% + 16px);
            }
            
            .transactions-table table {
                min-width: 450px;
            }
            
            .transactions-table th,
            .transactions-table td {
                padding: 8px 6px;
                font-size: 11px;
                min-width: 70px;
            }
            
            .section-title {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="buttons-container no-print">
        <button class="print-button" onclick="window.print()">
            <i class="bi bi-printer me-2"></i>طباعة
        </button>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="back-button">
            <i class="bi bi-arrow-left me-2"></i>رجوع
        </a>
    </div>
    
    <div class="statement-wrapper">
        <header class="statement-header">
            <div class="brand-block">
                <div class="logo-placeholder">
                    <img src="<?php echo getRelativeUrl('assets/icons/icon-192x192.svg'); ?>" alt="Logo" class="company-logo-img" onerror="this.onerror=null; this.src='<?php echo getRelativeUrl('assets/icons/icon-192x192.png'); ?>'; this.onerror=function(){this.style.display='none'; this.nextElementSibling.style.display='flex';};">
                    <span class="logo-letter" style="display:none;"><?php echo mb_substr($companyName, 0, 1); ?></span>
                </div>
                <div>
                    <h1 class="company-name"><?php echo htmlspecialchars($companyName); ?></h1>
                </div>
            </div>
            <div class="statement-meta">
                <div class="statement-title">كشف حساب المرتب</div>
                <div class="statement-date">تاريخ الطباعة: <?php echo $statementDate; ?></div>
            </div>
        </header>
        
        <div class="employee-info">
            <div class="employee-info-row">
                <div class="employee-info-item">
                    <div class="employee-info-label">اسم الموظف</div>
                    <div class="employee-info-value"><?php echo htmlspecialchars($employee['full_name'] ?? $employee['username']); ?></div>
                </div>
                <div class="employee-info-item">
                    <div class="employee-info-label">اسم المستخدم</div>
                    <div class="employee-info-value"><?php echo htmlspecialchars($employee['username']); ?></div>
                </div>
                <div class="employee-info-item">
                    <div class="employee-info-label">المنصب</div>
                    <div class="employee-info-value">
                        <?php 
                        $roles = ['production' => 'إنتاج', 'accountant' => 'محاسب', 'sales' => 'مندوب مبيعات', 'manager' => 'مدير'];
                        echo $roles[$employee['role']] ?? $employee['role'];
                        ?>
                    </div>
                </div>
            </div>
            <div class="employee-info-row">
                <div class="employee-info-item">
                    <div class="employee-info-label">الفترة</div>
                    <div class="employee-info-value"><?php echo htmlspecialchars($periodLabel); ?></div>
                </div>
                <div class="employee-info-item">
                    <div class="employee-info-label">سعر الساعة</div>
                    <div class="employee-info-value"><?php echo formatCurrency($employee['hourly_rate'] ?? 0); ?></div>
                </div>
                <div class="employee-info-item">
                    <div class="employee-info-label">الراتب الفعلي</div>
                    <div class="employee-info-value amount-positive"><?php echo formatCurrency($employee['actual_salary'] ?? 0); ?></div>
                </div>
            </div>
            <div class="employee-info-row">
                <div class="employee-info-item">
                    <div class="employee-info-label">تاريخ الطباعة</div>
                    <div class="employee-info-value"><?php echo date('d/m/Y H:i'); ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($statementSalaries)): ?>
        <h2 class="section-title">
            <i class="bi bi-cash-stack me-2"></i>الرواتب
        </h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>الشهر</th>
                    <th>الساعات</th>
                    <th>الراتب الأساسي</th>
                    <th>المكافآت</th>
                    <th>نسبة التحصيلات</th>
                    <th>الخصومات</th>
                    <th>الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statementSalaries as $sal): ?>
                <tr>
                    <td>
                        <?php 
                        $month = intval($sal['month'] ?? 0);
                        $year = intval($sal['year'] ?? date('Y'));
                        if ($month > 0 && $month <= 12) {
                            $monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
                            echo ($monthNames[$month - 1] ?? date('F', mktime(0, 0, 0, $month, 1))) . ' ' . $year;
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td><?php echo number_format($sal['total_hours'] ?? 0, 2); ?></td>
                    <td class="amount-positive"><?php echo formatCurrency($sal['base_amount'] ?? 0); ?></td>
                    <td class="amount-positive"><?php echo formatCurrency($sal['bonus'] ?? 0); ?></td>
                    <td class="amount-positive"><?php echo formatCurrency($sal['collections_bonus'] ?? 0); ?></td>
                    <td class="amount-negative"><?php echo formatCurrency($sal['deductions'] ?? 0); ?></td>
                    <td><strong class="amount-positive"><?php echo formatCurrency($sal['total_amount'] ?? 0); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-cash-stack"></i></div>
            <div>لا توجد رواتب مسجلة</div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($statementAdvances)): ?>
        <h2 class="section-title">
            <i class="bi bi-arrow-down-circle me-2"></i>السلف
        </h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statementAdvances as $adv): ?>
                <tr>
                    <td><?php echo formatDate($adv['request_date']); ?></td>
                    <td class="amount-negative"><?php echo formatCurrency($adv['amount'] ?? 0); ?></td>
                    <td>
                        <span class="status-badge status-approved">موافق عليه</span>
                    </td>
                    <td><?php echo htmlspecialchars($adv['notes'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($statementSettlements)): ?>
        <h2 class="section-title">
            <i class="bi bi-check-circle me-2"></i>التسويات والمدفوعات
        </h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>المبلغ المدفوع</th>
                    <th>المبلغ التراكمي قبل التسوية</th>
                    <th>المتبقي بعد التسوية</th>
                    <th>نوع التسوية</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statementSettlements as $set): ?>
                <tr>
                    <td><?php echo formatDate($set['settlement_date']); ?></td>
                    <td class="amount-positive"><strong><?php echo formatCurrency($set['settlement_amount'] ?? 0); ?></strong></td>
                    <td><?php echo formatCurrency($set['previous_accumulated'] ?? 0); ?></td>
                    <td><?php echo formatCurrency($set['remaining_after_settlement'] ?? 0); ?></td>
                    <td>
                        <?php 
                        $type = $set['settlement_type'] ?? 'partial';
                        echo $type === 'full' ? 'تسوية كاملة' : 'تسوية جزئية';
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <div class="summary-section">
            <h2 class="section-title" style="margin-top: 0; margin-bottom: 16px;">ملخص الحساب</h2>
            <div class="summary-row">
                <span class="summary-label">إجمالي الرواتب</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($totalSalaries ?? 0); ?></span>
            </div>
            <?php if (($totalAdvances ?? 0) > 0): ?>
            <div class="summary-row">
                <span class="summary-label">إجمالي السلف</span>
                <span class="summary-value amount-negative"><?php echo formatCurrency($totalAdvances); ?></span>
            </div>
            <?php endif; ?>
            <?php if (($totalSettlements ?? 0) > 0): ?>
            <div class="summary-row">
                <span class="summary-label">إجمالي المدفوعات</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($totalSettlements); ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row">
                <span class="summary-label">الصافي</span>
                <span class="summary-value <?php echo ($netAmount ?? 0) >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                    <?php echo formatCurrency(abs($netAmount ?? 0)); ?>
                </span>
            </div>
        </div>
        
        <footer class="footer">
            <div style="margin-bottom: 8px;">نشكركم على ثقتكم بنا</div>
            <div>لأي استفسارات يرجى التواصل على: <?php echo htmlspecialchars($companyPhone); ?></div>
        </footer>
    </div>
</body>
</html>
