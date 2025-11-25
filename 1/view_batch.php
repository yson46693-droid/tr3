<?php
/**
 * صفحة عرض تفاصيل التشغيلة عند مسح الباركود
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/batch_numbers.php';
require_once __DIR__ . '/includes/path_helper.php';

// السماح بالوصول بدون تسجيل دخول للباركودات العامة
$batchNumber = $_GET['batch'] ?? '';
if (empty($batchNumber)) {
    die('رقم التشغيلة مطلوب');
}

$batch = getBatchByNumber($batchNumber);
if (!$batch) {
    die('رقم التشغيلة غير موجود');
}

// تسجيل فحص الباركود (إذا كان المستخدم مسجل دخول)
$currentUser = null;
try {
    $currentUser = getCurrentUser();
    if ($currentUser) {
        $scanType = $_GET['scan_type'] ?? 'verification';
        $scanLocation = $_GET['location'] ?? null;
        recordBarcodeScan($batchNumber, $scanType, $scanLocation);
    }
} catch (Exception $e) {
    // لا شيء - السماح بالعرض حتى بدون تسجيل دخول
}

$dashboardUrl = $currentUser ? getDashboardUrl($currentUser['role']) : '#';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل التشغيلة - <?php echo htmlspecialchars($batchNumber); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .batch-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .batch-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .batch-header h1 {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .batch-header .batch-number {
            font-size: 1.5rem;
            opacity: 0.9;
            letter-spacing: 2px;
        }
        .info-section {
            padding: 30px;
        }
        .info-row {
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #667eea;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        .info-value {
            color: #333;
            font-size: 1rem;
        }
        .badge-status {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        .status-in_production { background: #ffc107; color: #000; }
        .status-completed { background: #17a2b8; color: #fff; }
        .status-in_stock { background: #28a745; color: #fff; }
        .status-sold { background: #6c757d; color: #fff; }
        .status-expired { background: #dc3545; color: #fff; }
        .action-buttons {
            padding: 20px 30px;
            background: #f8f9fa;
            text-align: center;
        }
        .btn-action {
            margin: 5px;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="batch-card mt-4 mb-4">
                    <!-- Header -->
                    <div class="batch-header">
                        <h1><i class="bi bi-box-seam me-2"></i><?php echo htmlspecialchars($batch['product_name'] ?? 'منتج غير معروف'); ?></h1>
                        <div class="batch-number"><?php echo htmlspecialchars($batchNumber); ?></div>
                    </div>
                    
                    <!-- Content -->
                    <div class="info-section">
                        <!-- معلومات أساسية -->
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-calendar-event me-2"></i>تاريخ الإنتاج</div>
                            <div class="info-value"><?php echo formatDate($batch['production_date']); ?></div>
                        </div>
                        
                        <?php if ($batch['expiry_date']): ?>
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-calendar-x me-2"></i>تاريخ انتهاء الصلاحية</div>
                            <div class="info-value"><?php echo formatDate($batch['expiry_date']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-123 me-2"></i>الكمية</div>
                            <div class="info-value"><?php echo number_format($batch['quantity'], 0); ?> قطعة</div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-info-circle me-2"></i>الحالة</div>
                            <div class="info-value">
                                <span class="badge-status status-<?php echo $batch['status']; ?>">
                                    <?php
                                    $statusLabels = [
                                        'in_production' => 'قيد الإنتاج',
                                        'completed' => 'مكتمل',
                                        'in_stock' => 'في المخزون',
                                        'sold' => 'تم البيع',
                                        'expired' => 'منتهي الصلاحية'
                                    ];
                                    echo $statusLabels[$batch['status']] ?? $batch['status'];
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- مورد العسل -->
                        <?php if (!empty($batch['honey_supplier_name'])): ?>
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-droplet me-2"></i>مورد العسل</div>
                            <div class="info-value"><?php echo htmlspecialchars($batch['honey_supplier_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- مورد مواد التعبئة -->
                        <?php if (!empty($batch['packaging_supplier_name'])): ?>
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-box me-2"></i>مورد مواد التعبئة</div>
                            <div class="info-value"><?php echo htmlspecialchars($batch['packaging_supplier_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- مواد التعبئة -->
                        <?php if (!empty($batch['packaging_materials_details']) && is_array($batch['packaging_materials_details'])): ?>
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-tags me-2"></i>مواد التعبئة المستخدمة</div>
                            <div class="info-value">
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($batch['packaging_materials_details'] as $material): ?>
                                        <li>
                                            <i class="bi bi-check-circle text-success me-2"></i>
                                            <?php echo htmlspecialchars($material['name']); ?>
                                            <?php if (!empty($material['specifications'])): ?>
                                                <span class="text-muted">(<?php echo htmlspecialchars($material['specifications']); ?>)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- العمال -->
                        <?php if (!empty($batch['workers_details']) && is_array($batch['workers_details'])): ?>
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-people me-2"></i>العمال المشاركون</div>
                            <div class="info-value">
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($batch['workers_details'] as $worker): ?>
                                        <li>
                                            <i class="bi bi-person-check text-primary me-2"></i>
                                            <?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- الملاحظات -->
                        <?php if (!empty($batch['notes'])): ?>
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-sticky me-2"></i>ملاحظات</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($batch['notes'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- تاريخ الإنشاء -->
                        <div class="info-row">
                            <div class="info-label"><i class="bi bi-clock me-2"></i>تاريخ الإنشاء</div>
                            <div class="info-value"><?php echo formatDateTime($batch['created_at'] ?? ''); ?></div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <?php if ($currentUser): ?>
                    <div class="action-buttons">
                        <a href="<?php echo $dashboardUrl; ?>" class="btn btn-primary btn-action">
                            <i class="bi bi-house me-2"></i>العودة للوحة التحكم
                        </a>
                        <a href="print_barcode.php?batch=<?php echo urlencode($batchNumber); ?>&quantity=1" 
                           class="btn btn-success btn-action" target="_blank">
                            <i class="bi bi-printer me-2"></i>طباعة الباركود
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="action-buttons">
                        <a href="index.php" class="btn btn-primary btn-action">
                            <i class="bi bi-box-arrow-in-right me-2"></i>تسجيل الدخول
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

