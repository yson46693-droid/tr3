<?php
/**
 * صفحة طباعة كشف حساب العميل (Statement)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoices.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/customer_history.php';

requireRole(['accountant', 'sales', 'manager']);

$customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customerId <= 0) {
    die('معرف العميل غير صحيح');
}

$db = db();
$currentUser = getCurrentUser();

// التحقق من ملكية العميل للمندوب (إذا كان المستخدم مندوب)
if ($currentUser['role'] === 'sales') {
    $customer = $db->queryOne("SELECT id, created_by FROM customers WHERE id = ?", [$customerId]);
    if (!$customer || (int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
        die('غير مصرح لك بعرض كشف حساب هذا العميل');
    }
}

// جلب بيانات العميل
$customer = $db->queryOne(
    "SELECT c.*, u.full_name as sales_rep_name, u.username as sales_rep_username
     FROM customers c
     LEFT JOIN users u ON c.created_by = u.id
     WHERE c.id = ?",
    [$customerId]
);

if (!$customer) {
    die('العميل غير موجود');
}

// جلب كل الحركات للعميل
$statementData = getCustomerStatementData($customerId);

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone = '01003533905';
$companyEmail = 'صفحة فيسبوك  : عسل نحل المصطفي';

$customerName = $customer['name'] ?? 'عميل';
$customerPhone = $customer['phone'] ?? '';
$customerAddress = $customer['address'] ?? '';
$salesRepName = $customer['sales_rep_name'] ?? $customer['sales_rep_username'] ?? null;
$customerCreatedAt = $customer['created_at'] ?? null;
$customerBalance = (float)($customer['balance'] ?? 0);

$statementDate = formatDate(date('Y-m-d'));
$customerJoinDate = $customerCreatedAt ? formatDate($customerCreatedAt) : 'غير محدد';

// باركود فيسبوك
$facebookPageUrl = 'https://www.facebook.com/share/1AHxSmFhEp/';
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($facebookPageUrl);

/**
 * جلب بيانات statement للعميل
 */
function getCustomerStatementData($customerId) {
    $db = db();
    
    // جلب الفواتير
    $invoices = $db->query(
        "SELECT 
            id, invoice_number, date, total_amount, paid_amount, status,
            (total_amount - paid_amount) as remaining_amount
         FROM invoices
         WHERE customer_id = ?
         ORDER BY date DESC, id DESC",
        [$customerId]
    ) ?: [];
    
    // جلب المرتجعات
    $returns = $db->query(
        "SELECT 
            id, return_number, return_date, refund_amount, status, invoice_id,
            (SELECT invoice_number FROM invoices WHERE id = returns.invoice_id) as invoice_number
         FROM returns
         WHERE customer_id = ?
         ORDER BY return_date DESC, id DESC",
        [$customerId]
    ) ?: [];
    
    // جلب الاستبدالات
    $exchanges = $db->query(
        "SELECT 
            e.id, e.exchange_date, e.exchange_type, e.difference_amount,
            e.original_total, e.new_total,
            r.return_number, r.invoice_id,
            (SELECT invoice_number FROM invoices WHERE id = r.invoice_id) as invoice_number
         FROM exchanges e
         LEFT JOIN returns r ON e.return_id = r.id
         WHERE e.customer_id = ?
         ORDER BY e.exchange_date DESC, e.id DESC",
        [$customerId]
    ) ?: [];
    
    // جلب التحصيلات
    // التحقق من وجود عمود invoice_id في جدول collections
    $hasInvoiceIdColumn = false;
    try {
        $invoiceIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'invoice_id'");
        $hasInvoiceIdColumn = !empty($invoiceIdColumnCheck);
    } catch (Throwable $e) {
        $hasInvoiceIdColumn = false;
    }
    
    if ($hasInvoiceIdColumn) {
        $collections = $db->query(
            "SELECT 
                id, amount, date, payment_method, notes,
                invoice_id,
                (SELECT invoice_number FROM invoices WHERE id = collections.invoice_id) as invoice_number
             FROM collections
             WHERE customer_id = ?
             ORDER BY date DESC, id DESC",
            [$customerId]
        ) ?: [];
    } else {
        $collections = $db->query(
            "SELECT 
                id, amount, date, payment_method, notes,
                NULL as invoice_id,
                NULL as invoice_number
             FROM collections
             WHERE customer_id = ?
             ORDER BY date DESC, id DESC",
            [$customerId]
        ) ?: [];
    }
    
    // حساب الإجماليات
    $totalInvoiced = 0;
    $totalPaid = 0;
    $totalReturns = 0;
    $totalExchanges = 0;
    $totalCollections = 0;
    
    foreach ($invoices as $inv) {
        $totalInvoiced += (float)($inv['total_amount'] ?? 0);
        $totalPaid += (float)($inv['paid_amount'] ?? 0);
    }
    
    foreach ($returns as $ret) {
        $totalReturns += (float)($ret['refund_amount'] ?? 0);
    }
    
    foreach ($exchanges as $exc) {
        $totalExchanges += (float)($exc['difference_amount'] ?? 0);
    }
    
    foreach ($collections as $col) {
        $totalCollections += (float)($col['amount'] ?? 0);
    }
    
    return [
        'invoices' => $invoices,
        'returns' => $returns,
        'exchanges' => $exchanges,
        'collections' => $collections,
        'totals' => [
            'total_invoiced' => $totalInvoiced,
            'total_paid' => $totalPaid,
            'total_returns' => $totalReturns,
            'total_exchanges' => $totalExchanges,
            'total_collections' => $totalCollections,
            'net_balance' => $totalInvoiced - $totalPaid - $totalReturns + $totalExchanges
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب - <?php echo htmlspecialchars($customerName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
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
        }
        
        .statement-wrapper {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            padding: 32px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
        }
        
        .statement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .brand-block {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .logo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 6px;
            background: linear-gradient(135deg,rgb(6, 59, 134) 0%,rgb(3, 71, 155) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
            overflow: hidden;
            position: relative;
        }
        
        .company-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
        }
        
        .logo-letter {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .company-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .statement-meta {
            text-align: left;
        }
        
        .statement-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .statement-date {
            font-size: 14px;
            color: #6b7280;
        }
        
        .customer-info {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .customer-info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 12px;
        }
        
        .customer-info-item {
            display: flex;
            flex-direction: column;
        }
        
        .customer-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .customer-info-value {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
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
        }
        
        .transactions-table th {
            background: #f9fafb;
            padding: 12px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transactions-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .transactions-table tr:hover {
            background: #f9fafb;
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
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-partial {
            background: #dbeafe;
            color: #1e40af;
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
        
        .print-button {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="bi bi-printer me-2"></i>طباعة
    </button>
    
    <div class="statement-wrapper">
        <header class="statement-header">
            <div class="brand-block">
                <div class="logo-placeholder">
                    <img src="<?php echo getRelativeUrl('assets/icons/icon-192x192.svg'); ?>" alt="Logo" class="company-logo-img" onerror="this.onerror=null; this.src='<?php echo getRelativeUrl('assets/icons/icon-192x192.png'); ?>'; this.onerror=function(){this.style.display='none'; this.nextElementSibling.style.display='flex';};">
                    <span class="logo-letter" style="display:none;"><?php echo mb_substr($companyName, 0, 1); ?></span>
                </div>
                <div>
                    <h1 class="company-name"><?php echo htmlspecialchars($companyName); ?></h1>
                    <div class="company-subtitle"><?php echo htmlspecialchars($companySubtitle); ?></div>
                </div>
            </div>
            <div class="statement-meta">
                <div class="statement-title">كشف حساب العميل</div>
                <div class="statement-date">تاريخ الطباعة: <?php echo $statementDate; ?></div>
            </div>
        </header>
        
        <div class="customer-info">
            <div class="customer-info-row">
                <div class="customer-info-item">
                    <div class="customer-info-label">اسم العميل</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($customerName); ?></div>
                </div>
                <div class="customer-info-item">
                    <div class="customer-info-label">رقم الهاتف</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($customerPhone ?: '-'); ?></div>
                </div>
                <div class="customer-info-item">
                    <div class="customer-info-label">العنوان</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($customerAddress ?: '-'); ?></div>
                </div>
            </div>
            <div class="customer-info-row">
                <div class="customer-info-item">
                    <div class="customer-info-label">تاريخ الإضافة</div>
                    <div class="customer-info-value"><?php echo $customerJoinDate; ?></div>
                </div>
                <div class="customer-info-item">
                    <div class="customer-info-label">مندوب المبيعات</div>
                    <div class="customer-info-value"><?php echo htmlspecialchars($salesRepName ?: '-'); ?></div>
                </div>
                <div class="customer-info-item">
                    <div class="customer-info-label">الرصيد الحالي</div>
                    <div class="customer-info-value <?php echo $customerBalance >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                        <?php echo formatCurrency(abs($customerBalance)); ?>
                        <?php echo $customerBalance < 0 ? ' (دائن)' : ' (مدين)'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- الفواتير -->
        <h2 class="section-title">الفواتير</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم الفاتورة</th>
                    <th>التاريخ</th>
                    <th>الإجمالي</th>
                    <th>المدفوع</th>
                    <th>المتبقي</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($statementData['invoices'])): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">لا توجد فواتير</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($statementData['invoices'] as $invoice): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                            <td><?php echo formatDate($invoice['date']); ?></td>
                            <td class="amount-positive"><?php echo formatCurrency($invoice['total_amount']); ?></td>
                            <td><?php echo formatCurrency($invoice['paid_amount']); ?></td>
                            <td class="amount-negative"><?php echo formatCurrency($invoice['remaining_amount']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $invoice['status']; ?>">
                                    <?php 
                                    $statusLabels = [
                                        'paid' => 'مدفوعة',
                                        'partial' => 'جزئي',
                                        'pending' => 'معلق',
                                        'sent' => 'مرسلة',
                                        'draft' => 'مسودة'
                                    ];
                                    echo $statusLabels[$invoice['status']] ?? $invoice['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- المرتجعات -->
        <h2 class="section-title">المرتجعات</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم المرتجع</th>
                    <th>رقم الفاتورة</th>
                    <th>التاريخ</th>
                    <th>المبلغ المرتجع</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($statementData['returns'])): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">لا توجد مرتجعات</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($statementData['returns'] as $return): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($return['return_number']); ?></td>
                            <td><?php echo htmlspecialchars($return['invoice_number'] ?: '-'); ?></td>
                            <td><?php echo formatDate($return['return_date']); ?></td>
                            <td class="amount-negative"><?php echo formatCurrency($return['refund_amount']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $return['status']; ?>">
                                    <?php 
                                    $statusLabels = [
                                        'processed' => 'معالج',
                                        'pending' => 'معلق',
                                        'approved' => 'معتمد',
                                        'rejected' => 'مرفوض'
                                    ];
                                    echo $statusLabels[$return['status']] ?? $return['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- الاستبدالات -->
        <h2 class="section-title">الاستبدالات</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم الاستبدال</th>
                    <th>رقم الفاتورة</th>
                    <th>التاريخ</th>
                    <th>الفرق</th>
                    <th>النوع</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($statementData['exchanges'])): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">لا توجد استبدالات</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($statementData['exchanges'] as $exchange): ?>
                        <tr>
                            <td>#<?php echo $exchange['id']; ?></td>
                            <td><?php echo htmlspecialchars($exchange['invoice_number'] ?: '-'); ?></td>
                            <td><?php echo formatDate($exchange['exchange_date']); ?></td>
                            <td class="<?php echo $exchange['difference_amount'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo formatCurrency(abs($exchange['difference_amount'])); ?>
                            </td>
                            <td>
                                <?php 
                                $typeLabels = [
                                    'upgrade' => 'ترقية',
                                    'downgrade' => 'تخفيض',
                                    'equal' => 'متساوي'
                                ];
                                echo $typeLabels[$exchange['exchange_type']] ?? $exchange['exchange_type'];
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- التحصيلات -->
        <h2 class="section-title">التحصيلات</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم التحصيل</th>
                    <th>رقم الفاتورة</th>
                    <th>التاريخ</th>
                    <th>المبلغ</th>
                    <th>طريقة الدفع</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($statementData['collections'])): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">لا توجد تحصيلات</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($statementData['collections'] as $collection): ?>
                        <tr>
                            <td>#<?php echo $collection['id']; ?></td>
                            <td><?php echo htmlspecialchars($collection['invoice_number'] ?: '-'); ?></td>
                            <td><?php echo formatDate($collection['date']); ?></td>
                            <td class="amount-positive"><?php echo formatCurrency($collection['amount']); ?></td>
                            <td>
                                <?php 
                                $methodLabels = [
                                    'cash' => 'نقدي',
                                    'bank_transfer' => 'تحويل بنكي',
                                    'check' => 'شيك'
                                ];
                                echo $methodLabels[$collection['payment_method']] ?? $collection['payment_method'];
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- الملخص -->
        <div class="summary-section">
            <h2 class="section-title" style="margin-top: 0;">ملخص الحساب</h2>
            <div class="summary-row">
                <span class="summary-label">إجمالي الفواتير</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($statementData['totals']['total_invoiced']); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي المدفوع</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($statementData['totals']['total_paid']); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي المرتجعات</span>
                <span class="summary-value amount-negative"><?php echo formatCurrency($statementData['totals']['total_returns']); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي الاستبدالات</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($statementData['totals']['total_exchanges']); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي التحصيلات</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($statementData['totals']['total_collections']); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">الرصيد الصافي</span>
                <span class="summary-value <?php echo $statementData['totals']['net_balance'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                    <?php echo formatCurrency(abs($statementData['totals']['net_balance'])); ?>
                    <?php echo $statementData['totals']['net_balance'] < 0 ? ' (دائن)' : ' (مدين)'; ?>
                </span>
            </div>
        </div>
        
        <footer style="margin-top: 32px; padding-top: 24px; border-top: 2px solid #e2e8f0; text-align: center; color: #6b7280; font-size: 14px;">
            <div style="margin-bottom: 8px;">نشكركم على ثقتكم بنا</div>
            <div>لأي استفسارات يرجى التواصل على: <?php echo htmlspecialchars($companyPhone); ?></div>
        </footer>
    </div>
</body>
</html>

