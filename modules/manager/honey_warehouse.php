<?php
/**
 * صفحة مخزن العسل - المدير (عرض فقط)
 * Honey Warehouse Page - Manager (View Only)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// الفلترة والبحث
$filters = [
    'search' => $_GET['search'] ?? '',
    'supplier_id' => isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0
];

// الحصول على جميع الموردين
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name");

// الحصول على مخزون العسل
$whereConditions = [];
$params = [];

if (!empty($filters['search'])) {
    $whereConditions[] = "s.name LIKE ?";
    $params[] = "%{$filters['search']}%";
}

if ($filters['supplier_id'] > 0) {
    $whereConditions[] = "hs.supplier_id = ?";
    $params[] = $filters['supplier_id'];
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// الحصول على العدد الإجمالي
$countQuery = "SELECT COUNT(*) as total 
               FROM honey_stock hs 
               LEFT JOIN suppliers s ON hs.supplier_id = s.id 
               $whereClause";
$totalCount = $db->queryOne($countQuery, $params)['total'] ?? 0;
$totalPages = ceil($totalCount / $perPage);

// الحصول على بيانات المخزون
$query = "SELECT hs.*, s.name as supplier_name, s.phone as supplier_phone
          FROM honey_stock hs
          LEFT JOIN suppliers s ON hs.supplier_id = s.id
          $whereClause
          ORDER BY s.name ASC, hs.honey_variety ASC
          LIMIT ? OFFSET ?";
$queryParams = array_merge($params, [$perPage, $offset]);
$honeyStock = $db->query($query, $queryParams);

// حساب الكمية المستخدمة في الإنتاج لكل مورد
// (من خلال جدول batch_numbers إذا كان هناك honey_supplier_id)
$honeyUsage = [];
foreach ($honeyStock as $stock) {
    $supplierId = $stock['supplier_id'];
    
    // حساب الكمية المستخدمة من العسل المصفى في الإنتاج
    // من خلال جدول batch_numbers
    $usedInBatches = $db->queryOne(
        "SELECT COALESCE(SUM(p.quantity), 0) as total_used
         FROM batch_numbers bn
         LEFT JOIN production p ON bn.production_id = p.id
         WHERE bn.honey_supplier_id = ? 
         AND bn.status IN ('in_production', 'completed', 'in_stock', 'sold')",
        [$supplierId]
    );
    
    $usedQuantity = floatval($usedInBatches['total_used'] ?? 0);
    
    // حساب المتبقي بعد الاستخدام
    $remainingQuantity = $stock['filtered_honey_quantity'] - $usedQuantity;
    if ($remainingQuantity < 0) {
        $remainingQuantity = 0; // لا يمكن أن يكون سالب
    }
    
    $honeyUsage[$supplierId] = [
        'used' => $usedQuantity,
        'remaining' => $remainingQuantity
    ];
}

// إحصائيات
$stats = [
    'total_suppliers' => $db->queryOne("SELECT COUNT(*) as total FROM honey_stock")['total'] ?? 0,
    'total_raw_honey' => $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
    'total_filtered_honey' => $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
    'total_used_honey' => 0,
    'total_remaining_honey' => 0
];

// حساب إجمالي المستخدم والمتبقي
foreach ($honeyUsage as $usage) {
    $stats['total_used_honey'] += $usage['used'];
    $stats['total_remaining_honey'] += $usage['remaining'];
}

$stats['total_honey'] = $stats['total_raw_honey'] + $stats['total_filtered_honey'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-droplet me-2"></i>مخزن العسل (عرض فقط)</h2>
</div>

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $stats['total_suppliers']; ?></div>
                    </div>
                    <div class="text-primary">
                        <i class="bi bi-people fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">إجمالي العسل الخام</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_raw_honey'], 2); ?> كجم</div>
                    </div>
                    <div class="text-warning">
                        <i class="bi bi-droplet-half fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">إجمالي العسل المصفى</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_filtered_honey'], 2); ?> كجم</div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-droplet fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">المتبقي بعد الاستخدام</div>
                        <div class="h4 mb-0"><?php echo number_format($stats['total_remaining_honey'], 2); ?> كجم</div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-droplet-fill fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="honey_warehouse">
            <div class="col-md-6">
                <label class="form-label">البحث</label>
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($filters['search']); ?>" 
                       placeholder="اسم المورد...">
            </div>
            <div class="col-md-4">
                <label class="form-label">المورد</label>
                <select class="form-select" name="supplier_id">
                    <option value="0">جميع الموردين</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedSupplierId = $filters['supplier_id'];
                    $supplierValid = isValidSelectValue($selectedSupplierId, $suppliers, 'id');
                    foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['id']; ?>" 
                                <?php echo $supplierValid && $selectedSupplierId == $supplier['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة مخزون العسل -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">مخزون العسل (<?php echo $totalCount; ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($honeyStock)): ?>
            <div class="text-center text-muted py-4">لا يوجد مخزون عسل</div>
        <?php else: ?>
            <!-- عرض الجدول على الشاشات الكبيرة -->
            <div class="table-responsive dashboard-table-wrapper d-none d-md-block">
                <table class="table dashboard-table align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th class="text-center" style="width: 50px;">#</th>
                            <th style="min-width: 160px;"><i class="bi bi-person me-1"></i>المورد</th>
                            <th class="text-center" style="min-width: 140px;"><i class="bi bi-tag me-1"></i>نوع العسل</th>
                            <th class="text-center" style="min-width: 160px;"><i class="bi bi-droplet-half me-1"></i>العسل الخام (كجم)</th>
                            <th class="text-center" style="min-width: 170px;"><i class="bi bi-box-arrow-in-down me-1"></i>المستخدم في الإنتاج (كجم)</th>
                            <th class="text-center" style="min-width: 170px;"><i class="bi bi-box-arrow-up me-1"></i>المتبقي بعد الاستخدام (كجم)</th>
                            <th class="text-center" style="min-width: 150px;"><i class="bi bi-clock-history me-1"></i>آخر تحديث</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($honeyStock as $index => $stock): ?>
                            <?php 
                            $supplierId = $stock['supplier_id'];
                            $usage = $honeyUsage[$supplierId] ?? ['used' => 0, 'remaining' => $stock['filtered_honey_quantity']];
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($stock['supplier_name'] ?? '-'); ?></strong>
                                    <?php if (!empty($stock['supplier_phone'])): ?>
                                        <br><small class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info">
                                        <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($stock['honey_variety'] ?? 'أخرى'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <strong class="text-warning">
                                        <i class="bi bi-droplet-half me-1"></i><?php echo number_format($stock['raw_honey_quantity'], 2); ?> <small class="text-muted">كجم</small>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <strong class="text-info">
                                        <i class="bi bi-box-arrow-in-down me-1"></i><?php echo number_format($usage['used'], 2); ?> <small class="text-muted">كجم</small>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <strong class="text-primary">
                                        <i class="bi bi-box-arrow-up me-1"></i><?php echo number_format($usage['remaining'], 2); ?> <small class="text-muted">كجم</small>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <small><i class="bi bi-clock-history me-1"></i><?php echo formatDateTime($stock['updated_at'] ?? $stock['created_at']); ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- عرض Cards على الموبايل -->
            <div class="d-md-none">
                <?php foreach ($honeyStock as $index => $stock): ?>
                    <?php 
                    $supplierId = $stock['supplier_id'];
                    $usage = $honeyUsage[$supplierId] ?? ['used' => 0, 'remaining' => $stock['filtered_honey_quantity']];
                    ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($stock['supplier_name'] ?? '-'); ?></h6>
                                    <?php if (!empty($stock['supplier_phone'])): ?>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                    <?php endif; ?>
                                    <span class="badge bg-info mt-1">
                                        <?php echo htmlspecialchars($stock['honey_variety'] ?? 'أخرى'); ?>
                                    </span>
                                </div>
                                <span class="badge bg-primary">#<?php echo $offset + $index + 1; ?></span>
                            </div>
                            
                            <div class="mb-2">
                                <small class="text-muted d-block">العسل الخام:</small>
                                <strong class="text-warning"><?php echo number_format($stock['raw_honey_quantity'], 2); ?> كجم</strong>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">المستخدم:</small>
                                    <strong class="text-danger"><?php echo number_format($usage['used'], 2); ?> كجم</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">المتبقي:</small>
                                    <strong class="text-primary"><?php echo number_format($usage['remaining'], 2); ?> كجم</strong>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-12">
                                    <small class="text-muted d-block">آخر تحديث:</small>
                                    <strong><?php echo formatDateTime($stock['updated_at'] ?? $stock['created_at']); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=honey_warehouse&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=honey_warehouse&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=honey_warehouse&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=honey_warehouse&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=honey_warehouse&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

