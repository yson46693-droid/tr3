<?php
/**
 * صفحة تقارير الإنتاجية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/production_helper.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// الفلاتر
$filters = [
    'user_id' => $_GET['user_id'] ?? '',
    'product_id' => $_GET['product_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'), // بداية الشهر الحالي
    'date_to' => $_GET['date_to'] ?? date('Y-m-t'), // نهاية الشهر الحالي
    'report_type' => $_GET['report_type'] ?? 'daily', // daily, weekly, monthly
    'supply_category' => $_GET['supply_category'] ?? ''
];

$supplyCategoryLabels = [
    'honey' => 'العسل',
    'olive_oil' => 'زيت الزيتون',
    'beeswax' => 'شمع العسل',
    'derivatives' => 'المشتقات',
    'nuts' => 'المكسرات',
    'sesame' => 'السمسم',
    'tahini' => 'الطحينة'
];

// الحصول على رسالة النجاح من session (بعد redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

// معالجة طلب التقرير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_report') {
    $reportType = $_POST['report_type'] ?? 'pdf';
    $reportFormat = $_POST['format'] ?? 'daily';
    
    // سيتم تنفيذ هذا لاحقاً عند إضافة مكتبات PDF/Excel
    $successMessage = 'سيتم إضافة تقارير PDF/Excel قريباً';
    
    // منع التكرار باستخدام redirect
    $redirectParams = array_merge($filters, ['page' => 'productivity_reports']);
    preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
}

// الحصول على تقرير الإنتاجية
$productivityData = getProductivityReport(
    $filters['user_id'] ? intval($filters['user_id']) : null,
    $filters['date_from'],
    $filters['date_to']
);

$supplyCategoryFilter = isset($filters['supply_category']) ? trim((string)$filters['supply_category']) : '';
$supplyLogs = getProductionSupplyLogs(
    $filters['date_from'],
    $filters['date_to'],
    $supplyCategoryFilter !== '' ? $supplyCategoryFilter : null
);
$supplyTotalQuantity = 0.0;
$supplySuppliersSet = [];
foreach ($supplyLogs as $logItem) {
    $supplyTotalQuantity += isset($logItem['quantity']) ? (float)$logItem['quantity'] : 0.0;
    if (!empty($logItem['supplier_id'])) {
        $supplySuppliersSet['id_' . $logItem['supplier_id']] = true;
    } elseif (!empty($logItem['supplier_name'])) {
        $supplySuppliersSet['name_' . mb_strtolower(trim((string)$logItem['supplier_name']), 'UTF-8')] = true;
    }
}
$supplySuppliersCount = count($supplySuppliersSet);

// إحصائيات الإنتاجية
$stats = [
    'total_production' => 0,
    'total_products' => 0,
    'total_cost' => 0,
    'average_per_day' => 0,
    'top_workers' => [],
    'top_products' => []
];

if (!empty($productivityData)) {
    $stats['total_production'] = count($productivityData);
    
    // حساب إجمالي التكلفة مع تنظيف القيم
    foreach ($productivityData as $item) {
        $cost = $item['total_cost'] ?? 0;
        
        // تنظيف القيمة من 262145
        if (is_string($cost)) {
            $cost = str_replace('262145', '', $cost);
            $cost = preg_replace('/262145\s*/', '', $cost);
            $cost = preg_replace('/\s*262145/', '', $cost);
            $cost = preg_replace('/[^0-9.]/', '', $cost);
            $cost = trim($cost);
        }
        
        $cost = floatval($cost);
        
        // إزالة القيم غير المنطقية
        if (abs($cost - 262145) < 0.01 || $cost > 10000000 || $cost < 0) {
            $cost = 0;
        }
        
        // التحقق النهائي: إذا كان النص الأصلي يحتوي على 262145، إرجاع 0
        if (isset($item['total_cost']) && is_string($item['total_cost']) && strpos($item['total_cost'], '262145') !== false) {
            $cost = 0;
        }
        
        $stats['total_cost'] += $cost;
    }
    
    // حساب متوسط الإنتاج اليومي
    $days = ceil((strtotime($filters['date_to']) - strtotime($filters['date_from'])) / 86400);
    if ($days > 0) {
        $stats['average_per_day'] = round($stats['total_production'] / $days, 2);
    }
    
    // أفضل العمال
    $workersStats = [];
    foreach ($productivityData as $item) {
        $workerId = $item['user_id'] ?? $item['worker_id'] ?? 'unknown';
        if (!isset($workersStats[$workerId])) {
            $workersStats[$workerId] = [
                'user_id' => $workerId,
                'user_name' => $item['user_name'] ?? 'غير محدد',
                'count' => 0,
                'total_cost' => 0
            ];
        }
        $workersStats[$workerId]['count']++;
        
        // تنظيف قيمة التكلفة قبل الإضافة
        $cost = $item['total_cost'] ?? 0;
        if (is_string($cost)) {
            $cost = str_replace('262145', '', $cost);
            $cost = preg_replace('/262145\s*/', '', $cost);
            $cost = preg_replace('/\s*262145/', '', $cost);
            $cost = preg_replace('/[^0-9.]/', '', $cost);
            $cost = trim($cost);
        }
        $cost = floatval($cost);
        if (abs($cost - 262145) < 0.01 || $cost > 10000000 || $cost < 0) {
            $cost = 0;
        }
        if (isset($item['total_cost']) && is_string($item['total_cost']) && strpos($item['total_cost'], '262145') !== false) {
            $cost = 0;
        }
        
        $workersStats[$workerId]['total_cost'] += $cost;
    }
    
    usort($workersStats, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    $stats['top_workers'] = array_slice($workersStats, 0, 5);
    
    // أفضل المنتجات
    $productsStats = [];
    foreach ($productivityData as $item) {
        $productId = $item['product_id'] ?? 0;
        if ($productId > 0 && !isset($productsStats[$productId])) {
            $productsStats[$productId] = [
                'product_id' => $productId,
                'product_name' => $item['product_name'] ?? 'غير محدد',
                'count' => 0,
                'total_quantity' => 0
            ];
        }
        if ($productId > 0) {
            $productsStats[$productId]['count']++;
            $productsStats[$productId]['total_quantity'] += ($item['quantity'] ?? 0);
        }
    }
    
    usort($productsStats, function($a, $b) {
        return $b['total_quantity'] - $a['total_quantity'];
    });
    $stats['top_products'] = array_slice($productsStats, 0, 5);
}

$users = $db->query("SELECT id, username, full_name FROM users WHERE role = 'production' AND status = 'active' ORDER BY username");
$products = $db->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up-arrow me-2"></i>تقارير الإنتاجية</h2>
    <div>
        <button class="btn btn-success" onclick="generatePDFReport()">
            <i class="bi bi-file-pdf me-2"></i>تصدير PDF
        </button>
        <button class="btn btn-primary" onclick="generateExcelReport()">
            <i class="bi bi-file-earmark-excel me-2"></i>تصدير Excel
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- الفلاتر -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="productivity_reports">
            <div class="col-md-3">
                <label class="form-label">العامل</label>
                <select class="form-select" name="user_id">
                    <option value="">جميع العمال</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedUserId = isset($filters['user_id']) ? intval($filters['user_id']) : 0;
                    $userIdValid = isValidSelectValue($selectedUserId, $users, 'id');
                    foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" 
                                <?php echo $userIdValid && $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">المنتج</label>
                <select class="form-select" name="product_id">
                    <option value="">جميع المنتجات</option>
                    <?php 
                    $selectedProductId = isset($filters['product_id']) ? intval($filters['product_id']) : 0;
                    $productIdValid = isValidSelectValue($selectedProductId, $products, 'id');
                    foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>" 
                                <?php echo $productIdValid && $selectedProductId == $product['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($product['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from']); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($filters['date_to']); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع التقرير</label>
                <select class="form-select" name="report_type">
                    <option value="daily" <?php echo ($filters['report_type'] ?? '') === 'daily' ? 'selected' : ''; ?>>يومي</option>
                    <option value="weekly" <?php echo ($filters['report_type'] ?? '') === 'weekly' ? 'selected' : ''; ?>>أسبوعي</option>
                    <option value="monthly" <?php echo ($filters['report_type'] ?? '') === 'monthly' ? 'selected' : ''; ?>>شهري</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">قسم التوريدات</label>
                <select class="form-select" name="supply_category">
                    <option value="">جميع الأقسام</option>
                    <?php foreach ($supplyCategoryLabels as $categoryKey => $categoryLabel): ?>
                        <option value="<?php echo htmlspecialchars($categoryKey); ?>"
                            <?php echo ($filters['supply_category'] ?? '') === $categoryKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($categoryLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-2"></i>عرض التقرير
                </button>
            </div>
        </form>
    </div>
</div>

<!-- بطاقات الإحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-box-seam"></i></div>
            <div class="card-title">إجمالي الإنتاج</div>
            <div class="card-value"><?php echo $stats['total_production']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-currency-dollar"></i></div>
            <div class="card-title">إجمالي التكلفة</div>
            <div class="card-value"><?php 
                // تنظيف القيمة النهائية قبل العرض
                $finalCost = $stats['total_cost'];
                if (is_string($finalCost)) {
                    $finalCost = str_replace('262145', '', $finalCost);
                    $finalCost = preg_replace('/262145\s*/', '', $finalCost);
                    $finalCost = preg_replace('/\s*262145/', '', $finalCost);
                    $finalCost = preg_replace('/[^0-9.]/', '', $finalCost);
                    $finalCost = trim($finalCost);
                }
                $finalCost = floatval($finalCost);
                if (abs($finalCost - 262145) < 0.01 || $finalCost > 10000000 || $finalCost < 0) {
                    $finalCost = 0;
                }
                echo formatCurrency($finalCost); 
            ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-calendar-day"></i></div>
            <div class="card-title">متوسط الإنتاج اليومي</div>
            <div class="card-value"><?php echo $stats['average_per_day']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-people"></i></div>
            <div class="card-title">عدد العمال</div>
            <div class="card-value"><?php echo count(array_unique(array_column($productivityData, 'user_id'))); ?></div>
        </div>
    </div>
</div>

<div class="row">
    <!-- تقرير الإنتاجية التفصيلي -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">التقرير التفصيلي</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>المنتج</th>
                                <th>العامل</th>
                                <th>الكمية</th>
                                <th>عدد المواد</th>
                                <th>التكلفة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($productivityData)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">لا توجد بيانات</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($productivityData as $item): ?>
                                    <tr>
                                        <td><?php echo formatDate($item['date']); ?></td>
                                        <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($item['user_name'] ?? '-'); ?></td>
                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                        <td><?php echo $item['materials_count'] ?? 0; ?></td>
                                        <td><?php 
                                            $itemCost = $item['total_cost'] ?? 0;
                                            // تنظيف القيمة قبل العرض
                                            if (is_string($itemCost)) {
                                                $itemCost = str_replace('262145', '', $itemCost);
                                                $itemCost = preg_replace('/262145\s*/', '', $itemCost);
                                                $itemCost = preg_replace('/\s*262145/', '', $itemCost);
                                                $itemCost = preg_replace('/[^0-9.]/', '', $itemCost);
                                                $itemCost = trim($itemCost);
                                            }
                                            $itemCost = floatval($itemCost);
                                            if (abs($itemCost - 262145) < 0.01 || $itemCost > 10000000 || $itemCost < 0) {
                                                $itemCost = 0;
                                            }
                                            if (isset($item['total_cost']) && is_string($item['total_cost']) && strpos($item['total_cost'], '262145') !== false) {
                                                $itemCost = 0;
                                            }
                                            echo formatCurrency($itemCost); 
                                        ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- أفضل العمال والمنتجات -->
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0">أفضل العمال</h6>
            </div>
            <div class="card-body">
                <?php if (empty($stats['top_workers'])): ?>
                    <p class="text-muted mb-0">لا توجد بيانات</p>
                <?php else: ?>
                    <ol>
                        <?php foreach ($stats['top_workers'] as $worker): ?>
                            <li>
                                <strong><?php echo htmlspecialchars($worker['user_name']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?php echo $worker['count']; ?> عملية إنتاج
                                    | <?php 
                                        $workerCost = $worker['total_cost'];
                                        // تنظيف القيمة قبل العرض
                                        if (is_string($workerCost)) {
                                            $workerCost = str_replace('262145', '', $workerCost);
                                            $workerCost = preg_replace('/262145\s*/', '', $workerCost);
                                            $workerCost = preg_replace('/\s*262145/', '', $workerCost);
                                            $workerCost = preg_replace('/[^0-9.]/', '', $workerCost);
                                            $workerCost = trim($workerCost);
                                        }
                                        $workerCost = floatval($workerCost);
                                        if (abs($workerCost - 262145) < 0.01 || $workerCost > 10000000 || $workerCost < 0) {
                                            $workerCost = 0;
                                        }
                                        echo formatCurrency($workerCost); 
                                    ?>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0">أفضل المنتجات</h6>
            </div>
            <div class="card-body">
                <?php if (empty($stats['top_products'])): ?>
                    <p class="text-muted mb-0">لا توجد بيانات</p>
                <?php else: ?>
                    <ol>
                        <?php foreach ($stats['top_products'] as $product): ?>
                            <li>
                                <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?php echo number_format($product['total_quantity'], 2); ?> وحدة
                                    | <?php echo $product['count']; ?> عملية
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- سجل التوريدات -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-warning bg-gradient">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0 text-dark">
                <i class="bi bi-truck me-2"></i>سجل التوريدات
            </h5>
            <div class="text-dark small fw-semibold">
                إجمالي الكميات: 
                <span class="text-primary"><?php echo number_format($supplyTotalQuantity, 3); ?></span>
                <?php if ($supplyCategoryFilter !== '' && isset($supplyCategoryLabels[$supplyCategoryFilter])): ?>
                    <span class="badge bg-primary text-white ms-2">
                        <?php echo htmlspecialchars($supplyCategoryLabels[$supplyCategoryFilter]); ?>
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary text-white ms-2">جميع الأقسام</span>
                <?php endif; ?>
                <span class="ms-3 text-muted">
                    عدد الموردين: <?php echo $supplySuppliersCount; ?>
                </span>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>القسم</th>
                        <th>المورد</th>
                        <th>الكمية</th>
                        <th>التفاصيل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($supplyLogs)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox me-2"></i>لا توجد توريدات في الفترة المحددة
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($supplyLogs as $supplyLog): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo formatDate($supplyLog['recorded_at']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars(date('H:i', strtotime($supplyLog['recorded_at']))); ?></div>
                                </td>
                                <td>
                                    <?php 
                                        $categoryKey = $supplyLog['material_category'] ?? '';
                                        echo htmlspecialchars($supplyCategoryLabels[$categoryKey] ?? $categoryKey ?: '-'); 
                                    ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($supplyLog['supplier_name'] ?: '-'); ?>
                                </td>
                                <td>
                                    <span class="fw-semibold text-primary">
                                        <?php echo number_format((float)($supplyLog['quantity'] ?? 0), 3); ?>
                                    </span>
                                    <span class="text-muted small"><?php echo htmlspecialchars($supplyLog['unit'] ?? 'كجم'); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($supplyLog['details'])): ?>
                                        <span><?php echo htmlspecialchars($supplyLog['details']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function generatePDFReport() {
    const filters = <?php echo json_encode($filters); ?>;
    window.location.href = 'api/generate_report.php?type=productivity&format=pdf&' + new URLSearchParams(filters).toString();
}

function generateExcelReport() {
    const filters = <?php echo json_encode($filters); ?>;
    window.location.href = 'api/generate_report.php?type=productivity&format=excel&' + new URLSearchParams(filters).toString();
}
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>

