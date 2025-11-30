<?php
/**
 * صفحة طباعة كشف حساب المرتب
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

function formatCurrency($amount) {
    return number_format($amount, 2) . ' ج.م';
}

function formatDate($date) {
    return date('d/m/Y', strtotime($date));
}

$companyName = COMPANY_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب المرتب - <?php echo htmlspecialchars($employee['full_name'] ?? $employee['username']); ?></title>
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
            .statement-container {
                box-shadow: none;
                border: none;
            }
            .page-break {
                page-break-after: always;
            }
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .statement-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #007bff;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #007bff;
            margin: 0;
            font-size: 28px;
        }
        .header h2 {
            color: #333;
            margin: 10px 0 0 0;
            font-size: 20px;
        }
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #495057;
        }
        .info-value {
            color: #212529;
        }
        .section-title {
            background: #007bff;
            color: white;
            padding: 12px 20px;
            margin: 30px 0 15px 0;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
        }
        .table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }
        .table thead {
            background: #007bff;
            color: white;
        }
        .table th, .table td {
            padding: 12px;
            text-align: right;
            border: 1px solid #dee2e6;
        }
        .table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        .summary-section {
            background: #e9ecef;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 16px;
            border-bottom: 1px solid #ced4da;
        }
        .summary-row:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 18px;
            color: #007bff;
            margin-top: 10px;
            padding-top: 15px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #dee2e6;
            color: #6c757d;
        }
        .btn-print {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="statement-container">
        <div class="header">
            <h1><?php echo htmlspecialchars($companyName); ?></h1>
            <h2>كشف حساب المرتب</h2>
        </div>
        
        <div class="info-section">
            <div>
                <div class="info-item">
                    <span class="info-label">اسم الموظف:</span>
                    <span class="info-value"><?php echo htmlspecialchars($employee['full_name'] ?? $employee['username']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">اسم المستخدم:</span>
                    <span class="info-value"><?php echo htmlspecialchars($employee['username']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">المنصب:</span>
                    <span class="info-value">
                        <?php 
                        $roles = ['production' => 'إنتاج', 'accountant' => 'محاسب', 'sales' => 'مندوب مبيعات'];
                        echo $roles[$employee['role']] ?? $employee['role'];
                        ?>
                    </span>
                </div>
            </div>
            <div>
                <div class="info-item">
                    <span class="info-label">الفترة:</span>
                    <span class="info-value"><?php echo htmlspecialchars($periodLabel); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">تاريخ الطباعة:</span>
                    <span class="info-value"><?php echo date('d/m/Y H:i'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">سعر الساعة:</span>
                    <span class="info-value"><?php echo formatCurrency($employee['hourly_rate'] ?? 0); ?></span>
                </div>
            </div>
        </div>
        
        <?php if (!empty($statementSalaries)): ?>
        <div class="section-title">
            <i class="bi bi-cash-stack me-2"></i>الرواتب
        </div>
        <table class="table">
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
                        echo date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year;
                        ?>
                    </td>
                    <td><?php echo number_format($sal['total_hours'] ?? 0, 2); ?></td>
                    <td><?php echo formatCurrency($sal['base_amount'] ?? 0); ?></td>
                    <td><?php echo formatCurrency($sal['bonus'] ?? 0); ?></td>
                    <td><?php echo formatCurrency($sal['collections_bonus'] ?? 0); ?></td>
                    <td><?php echo formatCurrency($sal['deductions'] ?? 0); ?></td>
                    <td><strong><?php echo formatCurrency($sal['total_amount'] ?? 0); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($statementAdvances)): ?>
        <div class="section-title">
            <i class="bi bi-arrow-down-circle me-2"></i>السلف
        </div>
        <table class="table">
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
                    <td><?php echo formatCurrency($adv['amount'] ?? 0); ?></td>
                    <td>
                        <span class="badge bg-success">موافق عليه</span>
                    </td>
                    <td><?php echo htmlspecialchars($adv['notes'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($statementSettlements)): ?>
        <div class="section-title">
            <i class="bi bi-check-circle me-2"></i>التسويات والمدفوعات
        </div>
        <table class="table">
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
                    <td><strong class="text-success"><?php echo formatCurrency($set['settlement_amount'] ?? 0); ?></strong></td>
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
            <div class="summary-row">
                <span>إجمالي الرواتب:</span>
                <span><?php echo formatCurrency($totalSalaries); ?></span>
            </div>
            <?php if ($totalAdvances > 0): ?>
            <div class="summary-row">
                <span>إجمالي السلف:</span>
                <span class="text-danger">- <?php echo formatCurrency($totalAdvances); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($totalSettlements > 0): ?>
            <div class="summary-row">
                <span>إجمالي المدفوعات:</span>
                <span class="text-success">- <?php echo formatCurrency($totalSettlements); ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row">
                <span>الصافي:</span>
                <span><?php echo formatCurrency($netAmount); ?></span>
            </div>
        </div>
        
        <div class="footer">
            <p>تم إنشاء هذا الكشف تلقائياً من النظام</p>
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?></p>
        </div>
    </div>
    
    <button class="btn btn-primary btn-print no-print" onclick="window.print()">
        <i class="bi bi-printer me-2"></i>طباعة
    </button>
</body>
</html>

