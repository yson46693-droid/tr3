<?php
/**
 * صفحة طباعة فاتورة نقل المنتجات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/vehicle_inventory.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['manager', 'accountant']);

$transferId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transferId <= 0) {
    die('رقم النقل غير صحيح');
}

$db = db();

// جلب بيانات النقل
$transfer = $db->queryOne(
    "SELECT wt.*, 
            w1.name as from_warehouse_name, w1.warehouse_type as from_warehouse_type,
            w2.name as to_warehouse_name, w2.warehouse_type as to_warehouse_type,
            u1.full_name as requested_by_name, u2.full_name as approved_by_name
     FROM warehouse_transfers wt
     LEFT JOIN warehouses w1 ON wt.from_warehouse_id = w1.id
     LEFT JOIN warehouses w2 ON wt.to_warehouse_id = w2.id
     LEFT JOIN users u1 ON wt.requested_by = u1.id
     LEFT JOIN users u2 ON wt.approved_by = u2.id
     WHERE wt.id = ?",
    [$transferId]
);

if (!$transfer) {
    die('النقل غير موجود');
}

// جلب عناصر النقل
$transferItems = $db->query(
    "SELECT 
        wti.*, 
        COALESCE(
            NULLIF(TRIM(fp.product_name), ''),
            NULLIF(TRIM(p_fp.name), ''),
            NULLIF(TRIM(p.name), ''),
            'منتج غير معروف'
        ) AS product_name,
        COALESCE(p.unit, p_fp.unit, 'قطعة') AS unit,
        p.unit_price,
        fp.batch_number as finished_batch_number, 
        fp.production_date
     FROM warehouse_transfer_items wti
     LEFT JOIN products p ON wti.product_id = p.id
     LEFT JOIN finished_products fp ON wti.batch_id = fp.id
     LEFT JOIN products p_fp ON fp.product_id = p_fp.id
     WHERE wti.transfer_id = ?
     ORDER BY wti.id",
    [$transferId]
);

$companyName = COMPANY_NAME ?? 'شركة';
$transferDate = formatDate($transfer['transfer_date']);
$transferTime = formatDateTime($transfer['approved_at'] ?? $transfer['created_at']);

$transferTypeLabels = [
    'to_vehicle' => 'إلى مخزن سيارة',
    'from_vehicle' => 'من مخزن سيارة',
    'between_warehouses' => 'بين مخازن'
];

$statusLabels = [
    'pending' => 'معلق',
    'approved' => 'موافق عليه',
    'completed' => 'مكتمل',
    'rejected' => 'مرفوض'
];

$statusColors = [
    'pending' => '#f59e0b',
    'approved' => '#3b82f6',
    'completed' => '#10b981',
    'rejected' => '#ef4444'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة نقل المنتجات - <?php echo htmlspecialchars($transfer['transfer_number']); ?></title>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 10px;
            }
            .invoice-container {
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
            background: #f3f4f6;
            padding: 20px;
            color: #1f2937;
        }
        
        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 3px solid #3b82f6;
        }
        
        .company-info {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        
        .logo-placeholder {
            width: 74px;
            height: 74px;
            border-radius: 20px;
            background: linear-gradient(135deg, #0f4c81, #0a2d4a);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 24px rgba(15, 76, 129, 0.25);
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }
        
        .company-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
        }
        
        .logo-letter {
            transform: translateY(2px);
            color: #fff;
            font-size: 30px;
            font-weight: 700;
        }
        
        .company-info-text {
            flex: 1;
        }
        
        .company-info-text h1 {
            font-size: 28px;
            color: #1e40af;
            margin-bottom: 8px;
        }
        
        .company-info-text p {
            color: #6b7280;
            font-size: 14px;
        }
        
        .invoice-title {
            text-align: left;
        }
        
        .invoice-title h2 {
            font-size: 32px;
            color: #1f2937;
            margin-bottom: 10px;
        }
        
        .invoice-title .transfer-number {
            font-size: 18px;
            color: #6b7280;
            font-weight: normal;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .details-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .detail-card {
            background: #f9fafb;
            padding: 20px;
            border-radius: 12px;
            border-right: 4px solid #3b82f6;
        }
        
        .detail-card h3 {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-card .value {
            font-size: 18px;
            color: #1f2937;
            font-weight: 600;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        .items-table thead {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
        }
        
        .items-table th {
            padding: 16px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
        }
        
        .items-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .items-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .items-table td {
            padding: 16px;
            font-size: 15px;
        }
        
        .items-table td:first-child {
            font-weight: 600;
            color: #1f2937;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .batch-badge {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
        }
        
        .footer p {
            color: #6b7280;
            font-size: 14px;
            margin: 5px 0;
        }
        
        .print-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 20px;
        }
        
        .print-btn:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="no-print" style="text-align: left; margin-bottom: 20px;">
            <button class="print-btn" onclick="window.print()">
                🖨️ طباعة الفاتورة
            </button>
        </div>
        
        <div class="invoice-header">
            <div class="company-info">
                <div class="logo-placeholder">
                    <img src="<?php echo getRelativeUrl('assets/icons/icon-192x192.svg'); ?>" alt="Logo" class="company-logo-img" onerror="this.onerror=null; this.src='<?php echo getRelativeUrl('assets/icons/icon-192x192.png'); ?>'; this.onerror=function(){this.style.display='none'; this.nextElementSibling.style.display='flex';};">
                    <span class="logo-letter" style="display:none;"><?php echo mb_substr($companyName, 0, 1); ?></span>
                </div>
                <div class="company-info-text">
                    <h1><?php echo htmlspecialchars($companyName); ?></h1>
                    <p>فاتورة نقل المنتجات</p>
                </div>
            </div>
            <div class="invoice-title">
                <h2>فاتورة نقل <span class="transfer-number"><?php echo htmlspecialchars($transfer['transfer_number']); ?></span></h2>
                <span class="status-badge" style="background: <?php echo $statusColors[$transfer['status']] ?? '#6b7280'; ?>; color: white;">
                    <?php echo $statusLabels[$transfer['status']] ?? $transfer['status']; ?>
                </span>
            </div>
        </div>
        
        <div class="details-section">
            <div class="detail-card">
                <h3>من المخزن</h3>
                <div class="value"><?php echo htmlspecialchars($transfer['from_warehouse_name'] ?? '-'); ?></div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 5px;">
                    <?php echo $transfer['from_warehouse_type'] === 'main' ? 'مخزن رئيسي' : 'مخزن سيارة'; ?>
                </div>
            </div>
            <div class="detail-card">
                <h3>إلى المخزن</h3>
                <div class="value"><?php echo htmlspecialchars($transfer['to_warehouse_name'] ?? '-'); ?></div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 5px;">
                    <?php echo $transfer['to_warehouse_type'] === 'main' ? 'مخزن رئيسي' : 'مخزن سيارة'; ?>
                </div>
            </div>
            <div class="detail-card">
                <h3>تاريخ النقل</h3>
                <div class="value"><?php echo $transferDate; ?></div>
            </div>
            <div class="detail-card">
                <h3>نوع النقل</h3>
                <div class="value"><?php echo $transferTypeLabels[$transfer['transfer_type']] ?? $transfer['transfer_type']; ?></div>
            </div>
            <div class="detail-card">
                <h3>طلب بواسطة</h3>
                <div class="value"><?php echo htmlspecialchars($transfer['requested_by_name'] ?? '-'); ?></div>
            </div>
            <?php if ($transfer['approved_by_name']): ?>
            <div class="detail-card">
                <h3>تمت الموافقة بواسطة</h3>
                <div class="value"><?php echo htmlspecialchars($transfer['approved_by_name']); ?></div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 5px;">
                    <?php echo $transferTime; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <h3 style="margin-bottom: 20px; color: #1f2937; font-size: 20px;">المنتجات المنقولة</h3>
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>المنتج</th>
                    <th class="text-center">رقم التشغيلة</th>
                    <th class="text-center">الكمية</th>
                    <th class="text-center">الوحدة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transferItems)): ?>
                    <tr>
                        <td colspan="5" class="text-center" style="padding: 40px; color: #6b7280;">
                            لا توجد منتجات
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $index = 1; $totalQuantity = 0; ?>
                    <?php foreach ($transferItems as $item): ?>
                        <?php 
                        $quantity = floatval($item['quantity'] ?? 0);
                        $totalQuantity += $quantity;
                        $batchNumber = $item['batch_number'] ?? $item['finished_batch_number'] ?? null;
                        ?>
                        <tr>
                            <td><?php echo $index++; ?></td>
                            <td><?php echo htmlspecialchars($item['product_name'] ?? 'منتج غير معروف'); ?></td>
                            <td class="text-center">
                                <?php if ($batchNumber): ?>
                                    <span class="batch-badge"><?php echo htmlspecialchars($batchNumber); ?></span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><strong><?php echo number_format($quantity, 2); ?></strong></td>
                            <td class="text-center"><?php echo htmlspecialchars($item['unit'] ?? 'قطعة'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background: #f9fafb; font-weight: 600;">
                        <td colspan="3" class="text-right">الإجمالي:</td>
                        <td class="text-center"><?php echo number_format($totalQuantity, 2); ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if (!empty($transfer['reason'])): ?>
        <div style="background: #fef3c7; padding: 20px; border-radius: 12px; margin-top: 30px; border-right: 4px solid #f59e0b;">
            <h3 style="margin-bottom: 10px; color: #92400e; font-size: 16px;">السبب / الملاحظات</h3>
            <p style="color: #78350f; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($transfer['reason'])); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <p><strong>نشكركم على استخدام نظام إدارة المخازن</strong></p>
            <p>تم إنشاء هذه الفاتورة تلقائياً من النظام</p>
            <p style="margin-top: 15px; font-size: 12px; color: #9ca3af;">
                تاريخ الطباعة: <?php echo formatDateTime(date('Y-m-d H:i:s')); ?>
            </p>
        </div>
    </div>
    
    <script>
        // طباعة تلقائية عند فتح الصفحة مع ?print=1
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

