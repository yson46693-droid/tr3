<?php
/**
 * صفحة إدارة طلبات النقل بين المخازن (للمدير)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/vehicle_inventory.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

$warehouseTransfersParentPage = $warehouseTransfersParentPage ?? 'warehouse_transfers';
$warehouseTransfersSectionParam = $warehouseTransfersSectionParam ?? null;
$warehouseTransfersShowHeading = $warehouseTransfersShowHeading ?? true;
$warehouseTransfersBaseQueryParams = ['page' => $warehouseTransfersParentPage];
if ($warehouseTransfersSectionParam !== null && $warehouseTransfersSectionParam !== '') {
    $warehouseTransfersBaseQueryParams['section'] = $warehouseTransfersSectionParam;
}

$buildWarehouseTransfersUrl = static function (array $params = []) use ($warehouseTransfersBaseQueryParams): string {
    $query = array_merge($warehouseTransfersBaseQueryParams, $params);
    return '?' . http_build_query($query);
};

requireRole('manager');

$approvalsEntityColumn = getApprovalsEntityColumn();

$currentUser = getCurrentUser();
$db = db();

// استلام الرسائل من session (بعد redirect)
$error = $_SESSION['warehouse_transfer_error'] ?? '';
$success = $_SESSION['warehouse_transfer_success'] ?? '';
unset($_SESSION['warehouse_transfer_error'], $_SESSION['warehouse_transfer_success']);

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'from_warehouse_id' => $_GET['from_warehouse_id'] ?? '',
    'to_warehouse_id' => $_GET['to_warehouse_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'transfer_type' => $_GET['transfer_type'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve_transfer') {
        $transferId = intval($_POST['transfer_id'] ?? 0);
        
        if ($transferId > 0) {
            $approval = $db->queryOne(
                "SELECT id FROM approvals WHERE type = 'warehouse_transfer' AND `{$approvalsEntityColumn}` = ? AND status = 'pending'",
                [$transferId]
            );
            
            if ($approval) {
                $result = approveRequest($approval['id'], $currentUser['id'], 'الموافقة على طلب نقل المنتجات.');
                if ($result['success']) {
                    // بناء رسالة النجاح مع تفاصيل المنتجات
                    $successMessage = 'تمت الموافقة على طلب النقل وسيتم تنفيذه تلقائياً.';
                    
                    // إضافة تفاصيل المنتجات إذا كانت موجودة في session
                    if (!empty($_SESSION['warehouse_transfer_products'])) {
                        $products = $_SESSION['warehouse_transfer_products'];
                        unset($_SESSION['warehouse_transfer_products']);
                        
                        if (!empty($products)) {
                            $successMessage .= "\n\nالمنتجات المنقولة:\n";
                            foreach ($products as $product) {
                                $batchInfo = !empty($product['batch_number']) ? " - تشغيلة {$product['batch_number']}" : '';
                                $successMessage .= "• {$product['name']}{$batchInfo}: " . number_format($product['quantity'], 2) . "\n";
                            }
                        }
                    }
                    
                    $_SESSION['warehouse_transfer_success'] = $successMessage;
                } else {
                    $_SESSION['warehouse_transfer_error'] = $result['message'] ?? 'تعذر الموافقة على طلب النقل.';
                }
            } else {
                $result = approveWarehouseTransfer($transferId, $currentUser['id']);
                if ($result['success']) {
                    // بناء رسالة النجاح مع تفاصيل المنتجات
                    $successMessage = $result['message'] ?? 'تمت الموافقة على النقل بنجاح';
                    
                    if (!empty($result['transferred_products'])) {
                        $products = $result['transferred_products'];
                        $successMessage .= "\n\nالمنتجات المنقولة:\n";
                        foreach ($products as $product) {
                            $batchInfo = !empty($product['batch_number']) ? " - تشغيلة {$product['batch_number']}" : '';
                            $successMessage .= "• {$product['name']}{$batchInfo}: " . number_format($product['quantity'], 2) . "\n";
                        }
                    }
                    
                    $_SESSION['warehouse_transfer_success'] = $successMessage;
                } else {
                    $_SESSION['warehouse_transfer_error'] = $result['message'];
                }
            }
        }
    } elseif ($action === 'reject_transfer') {
        $transferId = intval($_POST['transfer_id'] ?? 0);
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        
        if ($transferId > 0 && !empty($rejectionReason)) {
            $approval = $db->queryOne(
                "SELECT id FROM approvals WHERE type = 'warehouse_transfer' AND `{$approvalsEntityColumn}` = ? AND status = 'pending'",
                [$transferId]
            );
            
            if ($approval) {
                $result = rejectRequest($approval['id'], $currentUser['id'], $rejectionReason);
                if ($result['success']) {
                    $_SESSION['warehouse_transfer_success'] = 'تم رفض طلب النقل.';
                } else {
                    $_SESSION['warehouse_transfer_error'] = $result['message'] ?? 'تعذر رفض طلب النقل.';
                }
            } else {
                $result = rejectWarehouseTransfer($transferId, $rejectionReason, $currentUser['id']);
                if ($result['success']) {
                    $_SESSION['warehouse_transfer_success'] = $result['message'];
                } else {
                    $_SESSION['warehouse_transfer_error'] = $result['message'];
                }
            }
        } else {
            $_SESSION['warehouse_transfer_error'] = 'يجب إدخال سبب الرفض';
        }
    }
    
    // إعادة التوجيه بعد معالجة POST (POST-Redirect-GET pattern)
    // الحفاظ على query parameters للفلترة فقط (إزالة id)
    require_once __DIR__ . '/../../includes/path_helper.php';
    $redirectFilters = $filters;
    if (!empty($warehouseTransfersSectionParam)) {
        $redirectFilters['section'] = $warehouseTransfersSectionParam;
    }
    redirectAfterPost($warehouseTransfersParentPage, $redirectFilters, ['id'], 'manager', $pageNum);
}

// الحصول على البيانات - حساب العدد الإجمالي مع الفلترة
$countSql = "SELECT COUNT(*) as total FROM warehouse_transfers wt WHERE 1=1";
$countParams = [];

if (!empty($filters['from_warehouse_id'])) {
    $countSql .= " AND wt.from_warehouse_id = ?";
    $countParams[] = $filters['from_warehouse_id'];
}

if (!empty($filters['to_warehouse_id'])) {
    $countSql .= " AND wt.to_warehouse_id = ?";
    $countParams[] = $filters['to_warehouse_id'];
}

if (!empty($filters['status'])) {
    $countSql .= " AND wt.status = ?";
    $countParams[] = $filters['status'];
}

if (!empty($filters['transfer_type'])) {
    $countSql .= " AND wt.transfer_type = ?";
    $countParams[] = $filters['transfer_type'];
}

if (!empty($filters['date_from'])) {
    $countSql .= " AND DATE(wt.transfer_date) >= ?";
    $countParams[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $countSql .= " AND DATE(wt.transfer_date) <= ?";
    $countParams[] = $filters['date_to'];
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalTransfers = $totalResult['total'] ?? 0;
$totalPages = ceil($totalTransfers / $perPage);
$transfers = getWarehouseTransfers($filters, $perPage, $offset);

$warehouses = $db->query("SELECT id, name, warehouse_type FROM warehouses WHERE status = 'active' ORDER BY name");

// طلب نقل محدد للعرض
$selectedTransfer = null;
if (isset($_GET['id'])) {
    $transferId = intval($_GET['id']);
    $selectedTransfer = $db->queryOne(
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
    
    if ($selectedTransfer) {
        // أولاً: جلب العناصر الأساسية بدون JOIN للتأكد من الحصول على جميع العناصر
        $basicItems = $db->query(
            "SELECT 
                wti.id as item_id,
                wti.transfer_id,
                wti.product_id,
                wti.batch_id,
                wti.batch_number,
                wti.quantity,
                wti.notes
             FROM warehouse_transfer_items wti
             WHERE wti.transfer_id = ?
             ORDER BY wti.id",
            [$transferId]
        );
        
        // إذا كانت هناك عناصر، نضيف التفاصيل من JOIN
        if (!empty($basicItems)) {
            $selectedTransfer['items'] = [];
            foreach ($basicItems as $basicItem) {
                $productId = $basicItem['product_id'] ?? null;
                $batchId = $basicItem['batch_id'] ?? null;
                
                // جلب اسم المنتج
                $productName = 'منتج غير معروف';
                if ($productId) {
                    $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                    if ($product && !empty($product['name'])) {
                        $productName = $product['name'];
                    }
                } elseif ($batchId) {
                    // محاولة الحصول على اسم المنتج من finished_products
                    $batch = $db->queryOne("SELECT product_name FROM finished_products WHERE id = ?", [$batchId]);
                    if ($batch && !empty($batch['product_name'])) {
                        $productName = $batch['product_name'];
                    }
                }
                
                // جلب بيانات التشغيلة
                $finishedBatchNumber = $basicItem['batch_number'];
                $batchQuantityProduced = null;
                $batchQuantityAvailable = null;
                
                if ($batchId) {
                    $batch = $db->queryOne(
                        "SELECT batch_number, quantity_produced FROM finished_products WHERE id = ?",
                        [$batchId]
                    );
                    if ($batch) {
                        $finishedBatchNumber = $batch['batch_number'] ?? $basicItem['batch_number'];
                        $batchQuantityProduced = $batch['quantity_produced'] ?? null;
                        
                        // حساب الكمية المتاحة
                        $transferred = $db->queryOne(
                            "SELECT COALESCE(SUM(wti2.quantity), 0) as total_transferred
                             FROM warehouse_transfer_items wti2
                             INNER JOIN warehouse_transfers wt2 ON wt2.id = wti2.transfer_id
                             WHERE wti2.batch_id = ? AND wt2.status IN ('approved', 'completed') AND wti2.id != ?",
                            [$batchId, $basicItem['item_id']]
                        );
                        $batchQuantityAvailable = ($batchQuantityProduced ?? 0) - (float)($transferred['total_transferred'] ?? 0);
                    }
                }
                
                $selectedTransfer['items'][] = [
                    'item_id' => $basicItem['item_id'],
                    'transfer_id' => $basicItem['transfer_id'],
                    'product_id' => $basicItem['product_id'],
                    'batch_id' => $basicItem['batch_id'],
                    'batch_number' => $basicItem['batch_number'],
                    'quantity' => $basicItem['quantity'],
                    'notes' => $basicItem['notes'],
                    'product_name' => $productName,
                    'finished_batch_number' => $finishedBatchNumber,
                    'batch_quantity_produced' => $batchQuantityProduced,
                    'batch_quantity_available' => $batchQuantityAvailable
                ];
            }
        } else {
            $selectedTransfer['items'] = [];
            
            // التحقق مرة أخرى من وجود العناصر
            $itemsCheck = $db->query(
                "SELECT COUNT(*) as count FROM warehouse_transfer_items WHERE transfer_id = ?",
                [$transferId]
            );
            if (!empty($itemsCheck) && ($itemsCheck[0]['count'] ?? 0) > 0) {
                error_log("Warning: Items exist in database for transfer ID $transferId but query returned empty.");
            }
        }
    }
}
?>
<?php if ($warehouseTransfersShowHeading): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right me-2"></i>طلبات النقل بين المخازن</h2>
</div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div style="white-space: pre-line;"><?php echo htmlspecialchars($success); ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($selectedTransfer): ?>
    <!-- عرض طلب نقل محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">طلب نقل رقم: <?php echo htmlspecialchars($selectedTransfer['transfer_number']); ?></h5>
            <a href="<?php echo $buildWarehouseTransfersUrl($filters); ?>" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle table-bordered">
                        <tr>
                            <th width="40%">من المخزن:</th>
                            <td>
                                <?php echo htmlspecialchars($selectedTransfer['from_warehouse_name'] ?? '-'); ?>
                                <span class="badge bg-info ms-2">
                                    <?php echo $selectedTransfer['from_warehouse_type'] === 'main' ? 'رئيسي' : 'سيارة'; ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>إلى المخزن:</th>
                            <td>
                                <?php echo htmlspecialchars($selectedTransfer['to_warehouse_name'] ?? '-'); ?>
                                <span class="badge bg-info ms-2">
                                    <?php echo $selectedTransfer['to_warehouse_type'] === 'main' ? 'رئيسي' : 'سيارة'; ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>تاريخ النقل:</th>
                            <td><?php echo formatDate($selectedTransfer['transfer_date']); ?></td>
                        </tr>
                        <tr>
                            <th>نوع النقل:</th>
                            <td>
                                <?php 
                                $types = [
                                    'to_vehicle' => 'إلى سيارة',
                                    'from_vehicle' => 'من سيارة',
                                    'between_warehouses' => 'بين مخازن'
                                ];
                                echo $types[$selectedTransfer['transfer_type']] ?? $selectedTransfer['transfer_type'];
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>طلب بواسطة:</th>
                            <td><?php echo htmlspecialchars($selectedTransfer['requested_by_name'] ?? '-'); ?></td>
                        </tr>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle table-bordered">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedTransfer['status'] === 'completed' ? 'success' : 
                                        ($selectedTransfer['status'] === 'rejected' ? 'danger' : 
                                        ($selectedTransfer['status'] === 'approved' ? 'info' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'approved' => 'موافق عليه',
                                        'rejected' => 'مرفوض',
                                        'completed' => 'مكتمل',
                                        'cancelled' => 'ملغى'
                                    ];
                                    echo $statuses[$selectedTransfer['status']] ?? $selectedTransfer['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <?php if ($selectedTransfer['approved_by_name']): ?>
                        <tr>
                            <th>تمت الموافقة بواسطة:</th>
                            <td><?php echo htmlspecialchars($selectedTransfer['approved_by_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($selectedTransfer['rejection_reason']): ?>
                        <tr>
                            <th>سبب الرفض:</th>
                            <td><?php echo htmlspecialchars($selectedTransfer['rejection_reason']); ?></td>
                        </tr>
                        <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($selectedTransfer['items'])): ?>
                <h6 class="mt-3">عناصر النقل:</h6>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>رقم التشغيلة</th>
                                <th>الكمية المطلوبة</th>
                                <th>المتبقي من التشغيلة</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedTransfer['items'] as $item): ?>
                                <?php
                                    $availableBatch = isset($item['batch_quantity_available']) && $item['batch_quantity_available'] !== null ? (float)$item['batch_quantity_available'] : null;
                                    $quantity = (float)($item['quantity'] ?? 0);
                                    $badgeClass = ($availableBatch !== null && $availableBatch < $quantity) ? 'table-warning' : '';
                                    
                                    // تحديد رقم التشغيلة للعرض
                                    $displayBatchNumber = $item['batch_number'] ?? $item['finished_batch_number'] ?? '-';
                                    
                                    // تحديد اسم المنتج للعرض
                                    $displayProductName = $item['product_name'] ?? 'منتج غير معروف';
                                ?>
                                <tr class="<?php echo $badgeClass; ?>">
                                    <td>
                                        <?php echo htmlspecialchars($displayProductName); ?>
                                        <?php if (empty($item['product_id'])): ?>
                                            <br><small class="text-muted">(معرف المنتج: غير محدد)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($displayBatchNumber); ?>
                                        <?php if (!empty($item['batch_id']) && $displayBatchNumber === '-'): ?>
                                            <br><small class="text-muted">(ID: <?php echo $item['batch_id']; ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo number_format($quantity, 2); ?></strong></td>
                                    <td>
                                        <?php 
                                            if ($availableBatch === null) {
                                                echo '<span class="text-muted">غير متاح</span>';
                                                if (!empty($item['batch_id'])) {
                                                    echo '<br><small class="text-muted">(لا توجد بيانات تشغيلة)</small>';
                                                }
                                            } else {
                                                echo number_format(max(0, $availableBatch), 2);
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($availableBatch !== null && $availableBatch < $quantity): ?>
                                            <span class="badge bg-warning mb-1 d-block">كمية غير متوفرة</span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['notes'])): ?>
                                            <?php echo htmlspecialchars($item['notes']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mt-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>تحذير:</strong> لم يتم العثور على عناصر في طلب النقل هذا.
                    <?php
                    // التحقق من وجود العناصر في قاعدة البيانات
                    $itemsCheck = $db->query(
                        "SELECT COUNT(*) as count FROM warehouse_transfer_items WHERE transfer_id = ?",
                        [$transferId]
                    );
                    if (!empty($itemsCheck) && ($itemsCheck[0]['count'] ?? 0) > 0) {
                        echo '<br><small class="text-muted">ملاحظة: يوجد ' . ($itemsCheck[0]['count'] ?? 0) . ' عنصر في قاعدة البيانات، لكن لا تظهر التفاصيل. قد تكون هناك مشكلة في ربط البيانات أو اسم المنتج.</small>';
                        
                        // محاولة عرض البيانات الخام للمساعدة في التصحيح
                        $rawItems = $db->query(
                            "SELECT wti.*, p.name as product_name, p.id as product_exists 
                             FROM warehouse_transfer_items wti
                             LEFT JOIN products p ON wti.product_id = p.id
                             WHERE wti.transfer_id = ?",
                            [$transferId]
                        );
                        if (!empty($rawItems)) {
                            echo '<br><small class="text-muted">البيانات الخام:</small><pre class="small">';
                            foreach ($rawItems as $rawItem) {
                                echo "Product ID: " . ($rawItem['product_id'] ?? 'NULL') . ", ";
                                echo "Product Name: " . ($rawItem['product_name'] ?? 'NULL') . ", ";
                                echo "Batch ID: " . ($rawItem['batch_id'] ?? 'NULL') . ", ";
                                echo "Quantity: " . ($rawItem['quantity'] ?? '0') . "\n";
                            }
                            echo '</pre>';
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if ($selectedTransfer['reason']): ?>
                <div class="mt-3">
                    <h6>السبب:</h6>
                    <p><?php echo nl2br(htmlspecialchars($selectedTransfer['reason'])); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($selectedTransfer['status'] === 'pending'): ?>
                <div class="mt-3">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="approve_transfer">
                        <input type="hidden" name="transfer_id" value="<?php echo $selectedTransfer['id']; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>الموافقة على النقل
                        </button>
                    </form>
                    <button class="btn btn-danger" onclick="showRejectModal(<?php echo $selectedTransfer['id']; ?>)">
                        <i class="bi bi-x-circle me-2"></i>رفض الطلب
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="<?php echo htmlspecialchars($warehouseTransfersParentPage); ?>">
            <?php if (!empty($warehouseTransfersSectionParam)): ?>
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($warehouseTransfersSectionParam); ?>">
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">من المخزن</label>
                <select class="form-select" name="from_warehouse_id">
                    <option value="">جميع المخازن</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedFromWarehouse = isset($filters['from_warehouse_id']) ? intval($filters['from_warehouse_id']) : 0;
                    $fromWarehouseValid = isValidSelectValue($selectedFromWarehouse, $warehouses, 'id');
                    foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo $warehouse['id']; ?>" 
                                <?php echo $fromWarehouseValid && $selectedFromWarehouse == $warehouse['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($warehouse['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="completed" <?php echo ($filters['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                    <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع النقل</label>
                <select class="form-select" name="transfer_type">
                    <option value="">جميع الأنواع</option>
                    <option value="to_vehicle" <?php echo ($filters['transfer_type'] ?? '') === 'to_vehicle' ? 'selected' : ''; ?>>إلى سيارة</option>
                    <option value="from_vehicle" <?php echo ($filters['transfer_type'] ?? '') === 'from_vehicle' ? 'selected' : ''; ?>>من سيارة</option>
                    <option value="between_warehouses" <?php echo ($filters['transfer_type'] ?? '') === 'between_warehouses' ? 'selected' : ''; ?>>بين مخازن</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة طلبات النقل -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة طلبات النقل (<?php echo $totalTransfers; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>من المخزن</th>
                        <th>إلى المخزن</th>
                        <th>تاريخ النقل</th>
                        <th>نوع النقل</th>
                        <th>طلب بواسطة</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transfers)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد طلبات نقل</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transfers as $transfer): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo $buildWarehouseTransfersUrl(array_merge($filters, ['id' => $transfer['id']])); ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($transfer['from_warehouse_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($transfer['to_warehouse_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($transfer['transfer_date']); ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php 
                                        $types = [
                                            'to_vehicle' => 'إلى سيارة',
                                            'from_vehicle' => 'من سيارة',
                                            'between_warehouses' => 'بين مخازن'
                                        ];
                                        echo $types[$transfer['transfer_type']] ?? $transfer['transfer_type'];
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($transfer['requested_by_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $transfer['status'] === 'completed' ? 'success' : 
                                            ($transfer['status'] === 'rejected' ? 'danger' : 
                                            ($transfer['status'] === 'approved' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'completed' => 'مكتمل',
                                            'cancelled' => 'ملغى'
                                        ];
                                        echo $statuses[$transfer['status']] ?? $transfer['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo $buildWarehouseTransfersUrl(array_merge($filters, ['id' => $transfer['id']])); ?>" 
                                       class="btn btn-info btn-sm" title="عرض">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $buildWarehouseTransfersUrl(array_merge($filters, ['p' => $pageNum - 1])); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo $buildWarehouseTransfersUrl(array_merge($filters, ['p' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $buildWarehouseTransfersUrl(array_merge($filters, ['p' => $pageNum + 1])); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal رفض الطلب -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">رفض طلب النقل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject_transfer">
                <input type="hidden" name="transfer_id" id="rejectTransferId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="rejection_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">رفض</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showRejectModal(transferId) {
    document.getElementById('rejectTransferId').value = transferId;
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}
</script>


