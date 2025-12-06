<?php
/**
 * تقرير تفصيلي لحركات خزنة الشركة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/approval_system.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

// الحصول على الفترة من GET
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');
$includePending = isset($_GET['include_pending']) && $_GET['include_pending'] == '1';
$groupByType = isset($_GET['group_by_type']) && $_GET['group_by_type'] == '1';

// التحقق من صحة التواريخ
if (!strtotime($dateFrom) || !strtotime($dateTo)) {
    die('تواريخ غير صحيحة');
}

if (strtotime($dateFrom) > strtotime($dateTo)) {
    die('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
}

// حساب ملخص الخزنة للفترة
$statusFilter = $includePending ? "('approved', 'pending')" : "('approved')";

// جلب جميع الحركات المالية من financial_transactions
$financialTransactions = $db->query("
    SELECT 
        id,
        type,
        amount,
        description,
        reference_number,
        status,
        created_at,
        created_by,
        approved_by,
        approved_at,
        'financial_transactions' as source_table
    FROM financial_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND status IN {$statusFilter}
    ORDER BY created_at ASC
", [$dateFrom, $dateTo]) ?: [];

// جلب جميع الحركات من accountant_transactions
$accountantTransactions = $db->query("
    SELECT 
        id,
        CASE 
            WHEN transaction_type = 'collection_from_sales_rep' THEN 'income'
            WHEN transaction_type = 'expense' THEN 'expense'
            WHEN transaction_type = 'income' THEN 'income'
            WHEN transaction_type = 'transfer' THEN 'transfer'
            WHEN transaction_type = 'payment' THEN 'payment'
            ELSE 'other'
        END as type,
        amount,
        description,
        reference_number,
        status,
        created_at,
        created_by,
        approved_by,
        approved_at,
        transaction_type,
        'accountant_transactions' as source_table
    FROM accountant_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND status IN {$statusFilter}
    ORDER BY created_at ASC
", [$dateFrom, $dateTo]) ?: [];

// دمج الحركات
$allTransactions = [];
foreach ($financialTransactions as $trans) {
    $trans['transaction_type'] = null;
    $allTransactions[] = $trans;
}
foreach ($accountantTransactions as $trans) {
    $allTransactions[] = $trans;
}

// ترتيب حسب التاريخ
usort($allTransactions, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

// حساب الإجماليات
$totalIncome = 0.0;
$totalExpense = 0.0;
$totalPayment = 0.0;
$totalSalaryAdjustments = 0.0;
$totalCustomerSettlements = 0.0;
$totalCollections = 0.0;

$transactionsByType = [
    'income' => [],
    'expense' => [],
    'payment' => [],
    'transfer' => [],
    'other' => []
];

foreach ($allTransactions as $trans) {
    $type = $trans['type'] ?? 'other';
    $amount = (float)($trans['amount'] ?? 0);
    
    if (!isset($transactionsByType[$type])) {
        $transactionsByType[$type] = [];
    }
    $transactionsByType[$type][] = $trans;
    
    // حساب الإجماليات
    if ($type === 'income') {
        $totalIncome += $amount;
        // التحقق من نوع الإيراد
        if (isset($trans['transaction_type']) && $trans['transaction_type'] === 'collection_from_sales_rep') {
            $totalCollections += $amount;
        }
    } elseif ($type === 'expense') {
        $totalExpense += $amount;
        // التحقق من نوع المصروف
        $description = strtolower($trans['description'] ?? '');
        if (strpos($description, 'تسوية راتب') !== false) {
            $totalSalaryAdjustments += $amount;
        } elseif (strpos($description, 'تسوية رصيد دائن لعميل') !== false || strpos($description, 'تسوية رصيد دائن ل') !== false) {
            $totalCustomerSettlements += $amount;
        }
    } elseif ($type === 'payment') {
        $totalPayment += $amount;
    }
}

$netBalance = $totalIncome - $totalExpense - $totalPayment;

// جلب أسماء المستخدمين
$userIds = [];
foreach ($allTransactions as $trans) {
    if (!empty($trans['created_by'])) $userIds[] = $trans['created_by'];
    if (!empty($trans['approved_by'])) $userIds[] = $trans['approved_by'];
}
$userIds = array_unique($userIds);

$users = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $usersResult = $db->query("SELECT id, full_name, username FROM users WHERE id IN ($placeholders)", $userIds) ?: [];
    foreach ($usersResult as $user) {
        $users[$user['id']] = $user;
    }
}

// دالة مساعدة للحصول على اسم المستخدم
function getUserName($userId, $users) {
    if (empty($userId) || !isset($users[$userId])) {
        return '-';
    }
    return htmlspecialchars($users[$userId]['full_name'] ?? $users[$userId]['username'] ?? '-', ENT_QUOTES, 'UTF-8');
}

// دالة لتنسيق التاريخ
function formatReportDate($date) {
    return date('Y/m/d', strtotime($date));
}

// دالة لتنسيق التاريخ والوقت
function formatReportDateTime($datetime) {
    return date('Y/m/d H:i', strtotime($datetime));
}

$typeLabels = [
    'income' => 'إيراد',
    'expense' => 'مصروف',
    'payment' => 'دفعة',
    'transfer' => 'تحويل',
    'other' => 'أخرى'
];

$statusLabels = [
    'pending' => 'معلق',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض'
];

$statusColors = [
    'pending' => '#f59e0b',
    'approved' => '#10b981',
    'rejected' => '#ef4444'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير تفصيلي - خزنة الشركة</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            padding: 20px;
            color: #333;
        }
        
        .report-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .report-header {
            text-align: center;
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .report-header h1 {
            color: #0d6efd;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .report-header .period {
            color: #666;
            font-size: 16px;
        }
        
        .summary-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .summary-item {
            background: rgba(255,255,255,0.15);
            padding: 15px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }
        
        .summary-item-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .summary-item-value {
            font-size: 24px;
            font-weight: bold;
        }
        
        .section-title {
            background: #f8f9fa;
            padding: 15px 20px;
            border-right: 4px solid #0d6efd;
            margin: 30px 0 15px 0;
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        .transactions-table thead {
            background: #0d6efd;
            color: white;
        }
        
        .transactions-table th {
            padding: 12px;
            text-align: right;
            font-weight: 600;
        }
        
        .transactions-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .transactions-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .type-income {
            background: #d1fae5;
            color: #065f46;
        }
        
        .type-expense {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .type-payment {
            background: #fef3c7;
            color: #92400e;
        }
        
        .type-transfer {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .amount {
            font-weight: 600;
            font-size: 14px;
        }
        
        .amount-income {
            color: #10b981;
        }
        
        .amount-expense {
            color: #ef4444;
        }
        
        .amount-payment {
            color: #f59e0b;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .report-container {
                box-shadow: none;
                padding: 20px;
            }
            
            .no-print {
                display: none;
            }
        }
        
        .print-button {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1000;
            background: #0d6efd;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .print-button:hover {
            background: #0b5ed7;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="bi bi-printer"></i> طباعة التقرير
    </button>
    
    <div class="report-container">
        <div class="report-header">
            <h1><i class="bi bi-safe"></i> تقرير تفصيلي - خزنة الشركة</h1>
            <div class="period">
                الفترة: من <?php echo formatReportDate($dateFrom); ?> إلى <?php echo formatReportDate($dateTo); ?>
            </div>
            <div style="margin-top: 10px; font-size: 14px; color: #999;">
                تاريخ الإنشاء: <?php echo date('Y/m/d H:i'); ?> | 
                أنشأه: <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        
        <div class="summary-section">
            <h2 style="margin-bottom: 15px;">ملخص الحركات المالية</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-item-label">إجمالي الإيرادات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalIncome); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">إجمالي المصروفات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalExpense); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">إجمالي المدفوعات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalPayment); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-item-label">صافي الرصيد</div>
                    <div class="summary-item-value" style="color: <?php echo $netBalance >= 0 ? '#10b981' : '#ef4444'; ?>">
                        <?php echo formatCurrency($netBalance); ?>
                    </div>
                </div>
                <?php if ($totalCollections > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">التحصيلات من المندوبين</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalCollections); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalSalaryAdjustments > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">تسويات المرتبات</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalSalaryAdjustments); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalCustomerSettlements > 0): ?>
                <div class="summary-item">
                    <div class="summary-item-label">تسويات أرصدة العملاء</div>
                    <div class="summary-item-value"><?php echo formatCurrency($totalCustomerSettlements); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($groupByType): ?>
            <?php foreach ($transactionsByType as $type => $transactions): ?>
                <?php if (!empty($transactions)): ?>
                    <div class="section-title">
                        <i class="bi bi-list-ul"></i> <?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?> 
                        (<?php echo count($transactions); ?> حركة)
                    </div>
                    <table class="transactions-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المبلغ</th>
                                <th>الوصف</th>
                                <th>الرقم المرجعي</th>
                                <th>الحالة</th>
                                <th>أنشأه</th>
                                <th>اعتمده</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $typeTotal = 0.0;
                            foreach ($transactions as $index => $trans): 
                                $typeTotal += (float)($trans['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo formatReportDateTime($trans['created_at']); ?></td>
                                    <td>
                                        <span class="amount amount-<?php echo $type; ?>">
                                            <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($trans['amount']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($trans['reference_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo $statusColors[$trans['status']] ?? '#6b7280'; ?>; color: white;">
                                            <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getUserName($trans['created_by'], $users); ?></td>
                                    <td><?php echo getUserName($trans['approved_by'], $users); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td colspan="2">الإجمالي</td>
                                <td>
                                    <span class="amount amount-<?php echo $type; ?>">
                                        <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($typeTotal); ?>
                                    </span>
                                </td>
                                <td colspan="5"></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="section-title">
                <i class="bi bi-list-ul"></i> جميع الحركات المالية (<?php echo count($allTransactions); ?> حركة)
            </div>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                        <th>الوصف</th>
                        <th>الرقم المرجعي</th>
                        <th>الحالة</th>
                        <th>أنشأه</th>
                        <th>اعتمده</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allTransactions as $index => $trans): ?>
                        <?php $type = $trans['type'] ?? 'other'; ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo formatReportDateTime($trans['created_at']); ?></td>
                            <td>
                                <span class="type-badge type-<?php echo $type; ?>">
                                    <?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount amount-<?php echo $type; ?>">
                                    <?php echo ($type === 'income' ? '+' : '-'); ?><?php echo formatCurrency($trans['amount']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($trans['reference_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="status-badge" style="background: <?php echo $statusColors[$trans['status']] ?? '#6b7280'; ?>; color: white;">
                                    <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo getUserName($trans['created_by'], $users); ?></td>
                            <td><?php echo getUserName($trans['approved_by'], $users); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="footer">
            <p>تم إنشاء هذا التقرير تلقائياً من نظام إدارة خزنة الشركة</p>
            <p>© <?php echo date('Y'); ?> - جميع الحقوق محفوظة</p>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة (اختياري)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>
</html>
