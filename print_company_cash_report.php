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

// إصلاح SQL injection - استخدام prepared statements
$statusPlaceholders = $includePending ? "?, ?" : "?";
$statusParams = $includePending ? ['approved', 'pending'] : ['approved'];

// جلب جميع الحركات المالية من financial_transactions
$financialQuery = "
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
    AND status IN ({$statusPlaceholders})
    ORDER BY created_at ASC
";
$financialParams = array_merge([$dateFrom, $dateTo], $statusParams);
$financialTransactions = $db->query($financialQuery, $financialParams) ?: [];

// جلب جميع الحركات من accountant_transactions
$accountantQuery = "
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
    AND status IN ({$statusPlaceholders})
    ORDER BY created_at ASC
";
$accountantParams = array_merge([$dateFrom, $dateTo], $statusParams);
$accountantTransactions = $db->query($accountantQuery, $accountantParams) ?: [];

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
            font-family: 'Segoe UI', 'Cairo', 'Tajawal', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 20px;
            color: #1a1a1a;
            line-height: 1.6;
        }
        
        .report-container {
            max-width: 1400px;
            margin: 0 auto;
            background: #ffffff;
            padding: 50px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            border-radius: 16px;
        }
        
        .report-header {
            text-align: center;
            background: linear-gradient(135deg,rgb(22, 52, 186) 0%,rgb(11, 54, 147) 100%);
            color: white;
            padding: 40px 30px;
            border-radius: 16px;
            margin-bottom: 40px;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        
        .report-header h1 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            letter-spacing: 0.5px;
        }
        
        .report-header .period {
            font-size: 18px;
            opacity: 0.95;
            margin-top: 10px;
            font-weight: 500;
        }
        
        .report-header .meta-info {
            margin-top: 15px;
            font-size: 14px;
            opacity: 0.85;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        
        .summary-section {
            background: linear-gradient(135deg,rgb(22, 52, 186) 0%,rgb(11, 54, 147) 100%);
            color: white;
            padding: 35px;
            border-radius: 16px;
            margin-bottom: 40px;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
        }
        
        .summary-section h2 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .summary-item {
            background: rgba(255,255,255,0.2);
            padding: 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .summary-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .summary-item-label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 8px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .summary-item-value {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .section-title {
            background: linear-gradient(90deg,rgb(22, 52, 186) 0%,rgb(13, 37, 175) 100%);
            color: white;
            padding: 18px 25px;
            border-radius: 10px;
            margin: 40px 0 20px 0;
            font-size: 20px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 40px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .transactions-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .transactions-table th {
            padding: 16px 14px;
            text-align: right;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(255,255,255,0.2);
            white-space: nowrap;
        }
        
        .transactions-table th:nth-child(1) {
            width: 50px;
        }
        
        .transactions-table th:nth-child(2) {
            width: 140px;
        }
        
        .transactions-table th:nth-child(3) {
            width: 120px;
        }
        
        .transactions-table th:nth-child(4) {
            min-width: 200px;
        }
        
        .transactions-table th:nth-child(5) {
            width: 150px;
        }
        
        .transactions-table th:nth-child(6) {
            width: 100px;
        }
        
        .transactions-table th:nth-child(7),
        .transactions-table th:nth-child(8) {
            width: 120px;
        }
        
        .transactions-table th:first-child {
            border-top-right-radius: 12px;
        }
        
        .transactions-table th:last-child {
            border-top-left-radius: 12px;
        }
        
        .transactions-table td {
            padding: 14px;
            border-bottom: 1px solid #e9ecef;
            font-size: 14px;
            vertical-align: middle;
            word-wrap: break-word;
        }
        
        .transactions-table td:nth-child(4) {
            max-width: 300px;
            word-break: break-word;
        }
        
        .transactions-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .transactions-table tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        
        .transactions-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }
        
        .transactions-table tbody tr:hover {
            background: #f0f4ff !important;
            transform: scale(1.005);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
        }
        
        .transactions-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .transactions-table tbody tr td:first-child {
            font-weight: 600;
            color: #6b7280;
        }
        
        .transactions-table tbody tr.total-row {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 700;
            font-size: 15px;
        }
        
        .transactions-table tbody tr.total-row:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transform: none;
        }
        
        .type-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .type-income {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .type-expense {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .type-payment {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .type-transfer {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .type-other {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        
        .amount {
            font-weight: 700;
            font-size: 15px;
            letter-spacing: 0.3px;
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
        
        .amount-transfer {
            color: #3b82f6;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 25px;
            border-top: 3px solid #e9ecef;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
        }
        
        .footer p {
            margin: 5px 0;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .report-container {
                box-shadow: none;
                padding: 20px;
                border-radius: 0;
            }
            
            .no-print {
                display: none;
            }
            
            .transactions-table {
                page-break-inside: auto;
            }
            
            .transactions-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .summary-section {
                page-break-inside: avoid;
            }
        }
        
        .print-button {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 1000;
            background: linear-gradient(135deg,rgb(22, 52, 186) 0%,rgb(11, 54, 147) 100%);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .print-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.5);
        }
        
        .print-button:active {
            transform: translateY(0);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #374151;
        }
        
        .empty-state p {
            font-size: 16px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()" title="طباعة التقرير">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" style="margin-left: 5px;">
            <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1h12a1 1 0 0 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1v-3a1 1 0 0 1 1h6a1 1 0 0 1 1z"/>
        </svg>
        طباعة التقرير
    </button>
    
    <div class="report-container">
        <div class="report-header">
            <h1><i class="bi bi-safe"></i> تقرير تفصيلي - خزنة الشركة</h1>
            <div class="period">
                <i class="bi bi-calendar-range"></i> الفترة: من <?php echo formatReportDate($dateFrom); ?> إلى <?php echo formatReportDate($dateTo); ?>
            </div>
            <div class="meta-info">
                <i class="bi bi-clock"></i> تاريخ الإنشاء: <?php echo date('Y/m/d H:i'); ?> | 
                <i class="bi bi-person"></i> أنشأه: <?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8'); ?>
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
        
        <?php if (empty($allTransactions)): ?>
            <div class="empty-state">
                <div style="font-size: 80px; color: #cbd5e1; margin-bottom: 20px;">📊</div>
                <h3>لا توجد حركات مالية</h3>
                <p>لا توجد حركات مالية في الفترة المحددة (من <?php echo formatReportDate($dateFrom); ?> إلى <?php echo formatReportDate($dateTo); ?>)</p>
            </div>
        <?php elseif ($groupByType): ?>
            <?php foreach ($transactionsByType as $type => $transactions): ?>
                <?php if (!empty($transactions)): ?>
                    <div class="section-title">
                        <i class="bi bi-list-ul"></i> 
                        <span><?php echo htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 14px; margin-right: 10px;">
                            <?php echo count($transactions); ?> حركة
                        </span>
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
                                    <td>
                                <?php if (!empty($trans['reference_number'])): ?>
                                    <span style="font-family: 'Courier New', monospace; font-size: 12px; color: #6b7280; background: #f3f4f6; padding: 4px 8px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo $statusColors[$trans['status']] ?? '#6b7280'; ?>; color: white;">
                                            <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getUserName($trans['created_by'], $users); ?></td>
                                    <td><?php echo getUserName($trans['approved_by'], $users); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="2" style="font-size: 15px;">
                                    <strong>الإجمالي</strong>
                                </td>
                                <td>
                                    <span class="amount amount-<?php echo $type; ?>" style="font-size: 16px;">
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
                <i class="bi bi-list-ul"></i> 
                <span>جميع الحركات المالية</span>
                <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 14px; margin-right: 10px;">
                    <?php echo count($allTransactions); ?> حركة
                </span>
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
                            <td style="max-width: 300px; word-wrap: break-word;">
                                <?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php if (!empty($trans['reference_number'])): ?>
                                    <span style="font-family: 'Courier New', monospace; font-size: 12px; color: #6b7280; background: #f3f4f6; padding: 4px 8px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
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
