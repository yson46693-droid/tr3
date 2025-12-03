<?php
/**
 * صفحة طباعة فاتورة تحصيل من مندوب
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['manager', 'accountant', 'sales']);

$transactionId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transactionId <= 0) {
    die('رقم العملية غير صحيح');
}

$db = db();
$currentUser = getCurrentUser();

// جلب بيانات المعاملة
$transaction = $db->queryOne(
    "SELECT at.*,
            sales_rep.full_name as sales_rep_name,
            sales_rep.username as sales_rep_username,
            sales_rep.phone as sales_rep_phone,
            collector.full_name as collector_name,
            collector.username as collector_username,
            accountant.full_name as accountant_name,
            accountant.username as accountant_username
     FROM accountant_transactions at
     LEFT JOIN users sales_rep ON at.sales_rep_id = sales_rep.id
     LEFT JOIN users collector ON at.created_by = collector.id
     LEFT JOIN users accountant ON at.approved_by = accountant.id
     WHERE at.id = ? AND at.transaction_type = 'collection_from_sales_rep'",
    [$transactionId]
);

if (!$transaction) {
    die('عملية التحصيل غير موجودة');
}

// التحقق من الصلاحيات - المندوب يمكنه فقط رؤية تحصيلاته الخاصة
if (($currentUser['role'] ?? '') === 'sales' && (int)($transaction['sales_rep_id'] ?? 0) !== (int)($currentUser['id'])) {
    die('ليس لديك صلاحية لعرض هذه العملية');
}

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone = '01003533905';

$salesRepName = $transaction['sales_rep_name'] ?? $transaction['sales_rep_username'] ?? 'غير محدد';
$collectorName = $transaction['collector_name'] ?? $transaction['collector_username'] ?? 'غير محدد';
$accountantName = $transaction['accountant_name'] ?? $transaction['accountant_username'] ?? $collectorName;
$referenceNumber = $transaction['reference_number'] ?? ('COL-' . $transactionId);
$amount = (float)($transaction['amount'] ?? 0);
$description = $transaction['description'] ?? 'تحصيل من مندوب';
$collectionDate = $transaction['created_at'] ?? date('Y-m-d H:i:s');
$notes = $transaction['notes'] ?? '';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة تحصيل من مندوب - <?php echo htmlspecialchars($referenceNumber); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 20px;
            }
            .receipt-container {
                box-shadow: none;
                border: none;
            }
            @page {
                margin: 10mm;
            }
        }
        
        * {
            font-family: 'Tajawal', Arial, sans-serif;
        }
        
        body {
            background-color: #f5f5f5;
            padding: 20px;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 3px solid #007bff;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .receipt-header h1 {
            color: #007bff;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .receipt-header .company-name {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .receipt-header .company-subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .receipt-header .company-info {
            font-size: 12px;
            color: #888;
        }
        
        .receipt-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        
        .amount-section {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            margin: 30px 0;
        }
        
        .amount-label {
            font-size: 18px;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        
        .amount-value {
            font-size: 48px;
            font-weight: 700;
            margin: 0;
        }
        
        .details-section {
            margin-top: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .details-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #666;
        }
        
        .detail-value {
            color: #333;
            text-align: left;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #dee2e6;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        
        .signature-section {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            padding-top: 20px;
            border-top: 2px solid #dee2e6;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 50px;
            padding-top: 10px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="row mb-4 no-print">
            <div class="col-12 text-end">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
                <?php
                // تحديد رابط العودة بناءً على دور المستخدم
                $userRole = $currentUser['role'] ?? 'accountant';
                if ($userRole === 'manager') {
                    $backUrl = getRelativeUrl('dashboard/manager.php?page=company_cash');
                } elseif ($userRole === 'accountant') {
                    $backUrl = getRelativeUrl('dashboard/accountant.php?page=financial');
                } else {
                    $backUrl = getRelativeUrl('dashboard/sales.php?page=cash_register');
                }
                ?>
                <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>رجوع
                </a>
            </div>
        </div>
        
        <div class="receipt-header">
            <h1><i class="bi bi-receipt-cutoff me-2"></i>فاتورة تحصيل من مندوب</h1>
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="company-subtitle"><?php echo htmlspecialchars($companySubtitle); ?></div>
            <div class="company-info">
                <?php echo htmlspecialchars($companyAddress); ?><br>
                <?php echo htmlspecialchars($companyPhone); ?>
            </div>
        </div>
        
        <div class="receipt-info">
            <div class="info-item">
                <span class="info-label">رقم المرجع:</span>
                <span class="info-value"><?php echo htmlspecialchars($referenceNumber); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">تاريخ التحصيل:</span>
                <span class="info-value"><?php echo formatDateTime($collectionDate); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">المندوب:</span>
                <span class="info-value"><?php echo htmlspecialchars($salesRepName); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">قام بالتحصيل:</span>
                <span class="info-value"><?php echo htmlspecialchars($collectorName); ?></span>
            </div>
        </div>
        
        <div class="amount-section">
            <div class="amount-label">المبلغ المحصل</div>
            <div class="amount-value"><?php echo formatCurrency($amount); ?></div>
        </div>
        
        <div class="details-section">
            <div class="details-title"><i class="bi bi-info-circle me-2"></i>تفاصيل العملية</div>
            <div class="detail-row">
                <span class="detail-label">الوصف:</span>
                <span class="detail-value"><?php echo htmlspecialchars($description); ?></span>
            </div>
            <?php if (!empty($notes)): ?>
            <div class="detail-row">
                <span class="detail-label">ملاحظات:</span>
                <span class="detail-value"><?php echo htmlspecialchars($notes); ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">الحالة:</span>
                <span class="detail-value">
                    <?php 
                    $status = $transaction['status'] ?? 'approved';
                    $statusLabels = [
                        'pending' => 'معلق',
                        'approved' => 'معتمد',
                        'rejected' => 'مرفوض'
                    ];
                    echo htmlspecialchars($statusLabels[$status] ?? $status);
                    ?>
                </span>
            </div>
            <?php if (!empty($accountantName)): ?>
            <div class="detail-row">
                <span class="detail-label">اعتمدها:</span>
                <span class="detail-value"><?php echo htmlspecialchars($accountantName); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">توقيع المندوب</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">توقيع المحاسب/المدير</div>
            </div>
        </div>
        
        <div class="footer">
            <p>شكراً لكم على تعاونكم</p>
            <p>تم إنشاء هذه الفاتورة تلقائياً من النظام - <?php echo date('Y/m/d H:i:s'); ?></p>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة مع معامل print
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

