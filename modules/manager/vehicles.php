<?php
/**
 * صفحة إدارة السيارات (للمدير)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/vehicle_inventory.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_vehicle') {
        $vehicleNumber = trim($_POST['vehicle_number'] ?? '');
        $vehicleType = trim($_POST['vehicle_type'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = !empty($_POST['year']) ? intval($_POST['year']) : null;
        $driverId = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($vehicleNumber)) {
            $error = 'يجب إدخال رقم السيارة';
        } else {
            // التحقق من عدم تكرار رقم السيارة
            $existing = $db->queryOne("SELECT id FROM vehicles WHERE vehicle_number = ?", [$vehicleNumber]);
            
            if ($existing) {
                $error = 'رقم السيارة موجود بالفعل';
            } else {
                $db->execute(
                    "INSERT INTO vehicles (vehicle_number, vehicle_type, model, year, driver_id, status, notes, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$vehicleNumber, $vehicleType, $model, $year, $driverId, $status, $notes, $currentUser['id']]
                );
                
                $vehicleId = $db->getLastInsertId();
                
                // إنشاء مخزن السيارة تلقائياً
                createVehicleWarehouse($vehicleId);
                
                logAudit($currentUser['id'], 'create_vehicle', 'vehicle', $vehicleId, null, [
                    'vehicle_number' => $vehicleNumber
                ]);
                
                $success = 'تم إنشاء السيارة بنجاح';
                
                // Redirect بعد النجاح
                header('Location: ' . getDashboardUrl('manager') . '?page=vehicles&success=' . urlencode($success));
                exit;
            }
        }
    } elseif ($action === 'update_vehicle') {
        $vehicleId = intval($_POST['vehicle_id'] ?? 0);
        $vehicleNumber = trim($_POST['vehicle_number'] ?? '');
        $vehicleType = trim($_POST['vehicle_type'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = !empty($_POST['year']) ? intval($_POST['year']) : null;
        $driverId = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($vehicleId > 0) {
            $oldVehicle = $db->queryOne("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
            
            // التحقق من عدم تكرار رقم السيارة إذا تغير
            if ($oldVehicle && $oldVehicle['vehicle_number'] !== $vehicleNumber) {
                $existing = $db->queryOne("SELECT id FROM vehicles WHERE vehicle_number = ? AND id != ?", [$vehicleNumber, $vehicleId]);
                if ($existing) {
                    $error = 'رقم السيارة موجود بالفعل';
                } else {
                    $db->execute(
                        "UPDATE vehicles 
                         SET vehicle_number = ?, vehicle_type = ?, model = ?, year = ?, 
                             driver_id = ?, status = ?, notes = ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$vehicleNumber, $vehicleType, $model, $year, $driverId, $status, $notes, $vehicleId]
                    );
                    
                    logAudit($currentUser['id'], 'update_vehicle', 'vehicle', $vehicleId, 
                             ['old' => $oldVehicle], 
                             ['new' => ['vehicle_number' => $vehicleNumber, 'status' => $status]]);
                    
                    $success = 'تم تحديث السيارة بنجاح';
                }
            } else {
                $db->execute(
                    "UPDATE vehicles 
                     SET vehicle_number = ?, vehicle_type = ?, model = ?, year = ?, 
                         driver_id = ?, status = ?, notes = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$vehicleNumber, $vehicleType, $model, $year, $driverId, $status, $notes, $vehicleId]
                );
                
                logAudit($currentUser['id'], 'update_vehicle', 'vehicle', $vehicleId, 
                         ['old' => $oldVehicle], 
                         ['new' => ['vehicle_number' => $vehicleNumber, 'status' => $status]]);
                
                $success = 'تم تحديث السيارة بنجاح';
            }
            
            if ($success) {
                // Redirect بعد النجاح
                header('Location: ' . getDashboardUrl('manager') . '?page=vehicles&success=' . urlencode($success));
                exit;
            }
        }
    } elseif ($action === 'delete_vehicle') {
        $vehicleId = intval($_POST['vehicle_id'] ?? 0);
        
        if ($vehicleId > 0) {
            // التحقق من وجود معاملات مرتبطة بالسيارة
            $hasInventory = $db->queryOne(
                "SELECT COUNT(*) as count FROM vehicle_inventory WHERE vehicle_id = ? AND quantity > 0",
                [$vehicleId]
            );
            
            // التحقق من وجود طلبات مرتبطة بالسيارة (إذا كان الجدول موجوداً)
            $hasOrders = ['count' => 0];
            try {
                // التحقق من وجود جدول orders أولاً
                $ordersTableExists = $db->queryOne("SHOW TABLES LIKE 'orders'");
                if (!empty($ordersTableExists)) {
                    // التحقق من وجود عمود vehicle_id في جدول orders
                    $vehicleIdColumnExists = $db->queryOne("
                        SELECT COLUMN_NAME 
                        FROM information_schema.COLUMNS 
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'orders'
                          AND COLUMN_NAME = 'vehicle_id'
                    ");
                    if (!empty($vehicleIdColumnExists)) {
                        $hasOrders = $db->queryOne(
                            "SELECT COUNT(*) as count FROM orders WHERE vehicle_id = ?",
                            [$vehicleId]
                        );
                    }
                } else {
                    // إذا لم يكن جدول orders موجوداً، لا يوجد طلبات مرتبطة
                    $hasOrders = ['count' => 0];
                }
            } catch (Throwable $e) {
                // تجاهل الخطأ إذا كان الجدول غير موجود
                error_log("Vehicles: Error checking orders table: " . $e->getMessage());
                $hasOrders = ['count' => 0];
            }
            
            $hasTransfers = $db->queryOne(
                "SELECT COUNT(*) as count FROM warehouse_transfers WHERE vehicle_id = ? OR to_vehicle_id = ?",
                [$vehicleId, $vehicleId]
            );
            
            if (($hasInventory && $hasInventory['count'] > 0) || 
                ($hasOrders && $hasOrders['count'] > 0) || 
                ($hasTransfers && $hasTransfers['count'] > 0)) {
                $error = 'لا يمكن حذف السيارة لأنها مرتبطة بمخزون أو طلبات أو نقلات. يمكنك تغيير حالة السيارة إلى "غير نشطة" بدلاً من ذلك.';
            } else {
                $vehicle = $db->queryOne("SELECT vehicle_number FROM vehicles WHERE id = ?", [$vehicleId]);
                
                if ($vehicle) {
                    // حذف مخزن السيارة إذا كان موجوداً
                    $warehouse = $db->queryOne("SELECT id FROM warehouses WHERE vehicle_id = ? AND warehouse_type = 'vehicle'", [$vehicleId]);
                    if ($warehouse) {
                        $db->execute("DELETE FROM warehouses WHERE id = ?", [$warehouse['id']]);
                    }
                    
                    // حذف السيارة
                    $db->execute("DELETE FROM vehicles WHERE id = ?", [$vehicleId]);
                    
                    logAudit($currentUser['id'], 'delete_vehicle', 'vehicle', $vehicleId, null, [
                        'vehicle_number' => $vehicle['vehicle_number']
                    ]);
                    
                    $success = 'تم حذف السيارة بنجاح';
                    
                    header('Location: ' . getDashboardUrl('manager') . '?page=vehicles&success=' . urlencode($success));
                    exit;
                } else {
                    $error = 'السيارة غير موجودة';
                }
            }
        }
    }
}

// قراءة الرسائل من URL (Post-Redirect-Get pattern)
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// الحصول على السيارات
$vehicles = getVehicles(['status' => 'active']);
$salesReps = $db->query("SELECT id, username, full_name FROM users WHERE role = 'sales' AND status = 'active' ORDER BY username");

// سيارة محددة للعرض/التعديل
$selectedVehicle = null;
if (isset($_GET['id'])) {
    $selectedVehicle = $db->queryOne(
        "SELECT v.*, u.full_name as driver_name, u.username as driver_username,
                w.id as warehouse_id, w.name as warehouse_name
         FROM vehicles v
         LEFT JOIN users u ON v.driver_id = u.id
         LEFT JOIN warehouses w ON w.vehicle_id = v.id AND w.warehouse_type = 'vehicle'
         WHERE v.id = ?",
        [intval($_GET['id'])]
    );
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-truck me-2"></i>إدارة السيارات</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
        <i class="bi bi-plus-circle me-2"></i>إضافة سيارة جديدة
    </button>
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

<?php if ($selectedVehicle): ?>
    <!-- عرض/تعديل سيارة محددة -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">سيارة: <?php echo htmlspecialchars($selectedVehicle['vehicle_number']); ?></h5>
            <a href="<?php echo getDashboardUrl('manager'); ?>?page=vehicles" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" value="<?php echo $selectedVehicle['id']; ?>">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">رقم السيارة <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="vehicle_number" 
                                   value="<?php echo htmlspecialchars($selectedVehicle['vehicle_number']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نوع السيارة</label>
                            <input type="text" class="form-control" name="vehicle_type" 
                                   value="<?php echo htmlspecialchars($selectedVehicle['vehicle_type'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الموديل</label>
                            <input type="text" class="form-control" name="model" 
                                   value="<?php echo htmlspecialchars($selectedVehicle['model'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">السنة</label>
                            <input type="number" class="form-control" name="year" 
                                   value="<?php echo $selectedVehicle['year'] ? date('Y', strtotime($selectedVehicle['year'] . '-01-01')) : ''; ?>" 
                                   min="2000" max="<?php echo date('Y'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">المندوب (السائق)</label>
                            <select class="form-select" name="driver_id">
                                <option value="">لا يوجد</option>
                                <?php 
                                require_once __DIR__ . '/../../includes/path_helper.php';
                                $driverIdValid = isValidSelectValue($selectedVehicle['driver_id'] ?? 0, $salesReps, 'id');
                                foreach ($salesReps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>" 
                                            <?php echo $driverIdValid && ($selectedVehicle['driver_id'] ?? 0) == $rep['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status">
                                <option value="active" <?php echo $selectedVehicle['status'] === 'active' ? 'selected' : ''; ?>>نشطة</option>
                                <option value="inactive" <?php echo $selectedVehicle['status'] === 'inactive' ? 'selected' : ''; ?>>غير نشطة</option>
                                <option value="maintenance" <?php echo $selectedVehicle['status'] === 'maintenance' ? 'selected' : ''; ?>>صيانة</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="mb-3">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="3"><?php echo htmlspecialchars($selectedVehicle['notes'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>حفظ التغييرات
                        </button>
                        <a href="<?php echo getDashboardUrl('manager'); ?>?page=vehicle_inventory&vehicle_id=<?php echo $selectedVehicle['id']; ?>" 
                           class="btn btn-info">
                            <i class="bi bi-box-seam me-2"></i>عرض المخزون
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- قائمة السيارات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة السيارات</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم السيارة</th>
                        <th>النوع</th>
                        <th>الموديل</th>
                        <th>السنة</th>
                        <th>المندوب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehicles)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">لا توجد سيارات مسجلة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($vehicle['vehicle_type'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($vehicle['model'] ?? '-'); ?></td>
                                <td><?php echo $vehicle['year'] ? date('Y', strtotime($vehicle['year'] . '-01-01')) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($vehicle['driver_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $vehicle['status'] === 'active' ? 'success' : 
                                            ($vehicle['status'] === 'maintenance' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'active' => 'نشطة',
                                            'inactive' => 'غير نشطة',
                                            'maintenance' => 'صيانة'
                                        ];
                                        echo $statuses[$vehicle['status']] ?? $vehicle['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="<?php echo getDashboardUrl('manager'); ?>?page=vehicles&id=<?php echo $vehicle['id']; ?>" 
                                           class="btn btn-info" title="عرض/تعديل">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-warning" 
                                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($vehicle), ENT_QUOTES); ?>)"
                                                title="تعديل سريع">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="<?php echo getDashboardUrl('manager'); ?>?page=vehicle_inventory&vehicle_id=<?php echo $vehicle['id']; ?>" 
                                           class="btn btn-primary" title="المخزون">
                                            <i class="bi bi-box-seam"></i>
                                        </a>
                                        <button type="button" class="btn btn-danger" 
                                                onclick="confirmDelete(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['vehicle_number'], ENT_QUOTES); ?>')"
                                                title="حذف">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal إضافة سيارة -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة سيارة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_vehicle">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">رقم السيارة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع السيارة</label>
                        <input type="text" class="form-control" name="vehicle_type" placeholder="مثال: شاحنة">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الموديل</label>
                        <input type="text" class="form-control" name="model" placeholder="مثال: تويوتا هايلكس">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">السنة</label>
                        <input type="number" class="form-control" name="year" 
                               min="2000" max="<?php echo date('Y'); ?>" 
                               placeholder="<?php echo date('Y'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المندوب (السائق)</label>
                        <select class="form-select" name="driver_id">
                            <option value="">لا يوجد</option>
                            <?php foreach ($salesReps as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>">
                                    <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status">
                            <option value="active">نشطة</option>
                            <option value="inactive">غير نشطة</option>
                            <option value="maintenance">صيانة</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal التعديل السريع -->
<div class="modal fade" id="editVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل السيارة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editVehicleForm">
                <input type="hidden" name="action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" id="editVehicleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">رقم السيارة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_number" id="editVehicleNumber" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع السيارة</label>
                        <input type="text" class="form-control" name="vehicle_type" id="editVehicleType">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الموديل</label>
                        <input type="text" class="form-control" name="model" id="editVehicleModel">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">السنة</label>
                        <input type="number" class="form-control" name="year" id="editVehicleYear" 
                               min="2000" max="<?php echo date('Y'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المندوب (السائق)</label>
                        <select class="form-select" name="driver_id" id="editVehicleDriver">
                            <option value="">لا يوجد</option>
                            <?php foreach ($salesReps as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>">
                                    <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status" id="editVehicleStatus">
                            <option value="active">نشطة</option>
                            <option value="inactive">غير نشطة</option>
                            <option value="maintenance">صيانة</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" id="editVehicleNotes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save me-2"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تأكيد الحذف -->
<div class="modal fade" id="deleteVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>تحذير:</strong> سيتم حذف السيارة بشكل نهائي ولا يمكن التراجع عن هذه العملية.
                </div>
                <p>هل أنت متأكد من حذف السيارة رقم: <strong id="deleteVehicleNumber"></strong>؟</p>
                <p class="text-muted small">ملاحظة: لا يمكن حذف السيارة إذا كانت مرتبطة بمخزون أو طلبات أو نقلات.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <form method="POST" id="deleteVehicleForm" style="display: inline;">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="vehicle_id" id="deleteVehicleId">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>حذف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openEditModal(vehicleData) {
    document.getElementById('editVehicleId').value = vehicleData.id;
    document.getElementById('editVehicleNumber').value = vehicleData.vehicle_number || '';
    document.getElementById('editVehicleType').value = vehicleData.vehicle_type || '';
    document.getElementById('editVehicleModel').value = vehicleData.model || '';
    
    // معالجة السنة
    if (vehicleData.year) {
        const yearValue = typeof vehicleData.year === 'string' ? 
            new Date(vehicleData.year + '-01-01').getFullYear() : 
            vehicleData.year;
        document.getElementById('editVehicleYear').value = yearValue;
    } else {
        document.getElementById('editVehicleYear').value = '';
    }
    
    document.getElementById('editVehicleDriver').value = vehicleData.driver_id || '';
    document.getElementById('editVehicleStatus').value = vehicleData.status || 'active';
    document.getElementById('editVehicleNotes').value = vehicleData.notes || '';
    
    // فتح الـ modal
    const modal = new bootstrap.Modal(document.getElementById('editVehicleModal'));
    modal.show();
}

function confirmDelete(vehicleId, vehicleNumber) {
    document.getElementById('deleteVehicleId').value = vehicleId;
    document.getElementById('deleteVehicleNumber').textContent = vehicleNumber;
    
    // فتح الـ modal
    const modal = new bootstrap.Modal(document.getElementById('deleteVehicleModal'));
    modal.show();
}
</script>


