<?php
/**
 * نظام مخازن سيارات المندوبين
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/inventory_movements.php';

/**
 * ضمان وجود أعمدة رقم التشغيلة في جدول عناصر طلبات النقل
 */
function ensureWarehouseTransferBatchColumns(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $db = db();

    try {
        $batchIdColumn = $db->queryOne("SHOW COLUMNS FROM warehouse_transfer_items LIKE 'batch_id'");
        if (empty($batchIdColumn)) {
            $db->execute("ALTER TABLE warehouse_transfer_items ADD COLUMN `batch_id` int(11) DEFAULT NULL AFTER `product_id`");
            $db->execute("ALTER TABLE warehouse_transfer_items ADD KEY `batch_id` (`batch_id`)");
        }

        $batchNumberColumn = $db->queryOne("SHOW COLUMNS FROM warehouse_transfer_items LIKE 'batch_number'");
        if (empty($batchNumberColumn)) {
            $db->execute("ALTER TABLE warehouse_transfer_items ADD COLUMN `batch_number` varchar(100) DEFAULT NULL AFTER `batch_id`");
            $db->execute("ALTER TABLE warehouse_transfer_items ADD KEY `batch_number` (`batch_number`)");
        }
    } catch (Exception $e) {
        error_log('ensureWarehouseTransferBatchColumns error: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * الحصول على قائمة المنتجات بالتشغيلات المتاحة للنقل
 */
function getFinishedProductBatchOptions($onlyAvailable = true): array
{
    $db = db();

    try {
        $finishedExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
        if (empty($finishedExists)) {
            return [];
        }

        ensureWarehouseTransferBatchColumns();

        $sql = "
            SELECT
                fp.id AS batch_id,
                fp.product_id,
                COALESCE(p.name, fp.product_name) AS product_name,
                fp.batch_number,
                fp.production_date,
                fp.quantity_produced,
                COALESCE(SUM(wti.quantity), 0) AS transferred_quantity
            FROM finished_products fp
            LEFT JOIN products p ON fp.product_id = p.id
            LEFT JOIN warehouse_transfer_items wti ON wti.batch_id = fp.id
            GROUP BY fp.id, fp.product_id, fp.product_name, fp.batch_number, fp.production_date, fp.quantity_produced, p.name
            ORDER BY fp.production_date DESC, product_name ASC, fp.batch_number ASC
        ";

        $rows = $db->query($sql) ?? [];
        $options = [];

        foreach ($rows as $row) {
            $available = (float)$row['quantity_produced'] - (float)$row['transferred_quantity'];
            if ($onlyAvailable && $available <= 0) {
                continue;
            }

            $options[] = [
                'batch_id' => (int)$row['batch_id'],
                'product_id' => $row['product_id'] ? (int)$row['product_id'] : null,
                'product_name' => $row['product_name'] ?? 'منتج غير محدد',
                'batch_number' => $row['batch_number'] ?? '',
                'production_date' => $row['production_date'] ?? null,
                'quantity_produced' => (float)$row['quantity_produced'],
                'quantity_available' => max(0, $available),
            ];
        }

        return $options;
    } catch (Exception $e) {
        error_log('getFinishedProductBatchOptions error: ' . $e->getMessage());
        return [];
    }
}

/**
 * إنشاء أو تحديث مخزن سيارة
 */
function createVehicleWarehouse($vehicleId, $vehicleName = null) {
    try {
        $db = db();
        
        // التحقق من وجود مخزن السيارة
        $existing = $db->queryOne(
            "SELECT id FROM warehouses WHERE vehicle_id = ? AND warehouse_type = 'vehicle'",
            [$vehicleId]
        );
        
        if ($existing) {
            return ['success' => true, 'warehouse_id' => $existing['id']];
        }
        
        // إنشاء مخزن جديد للسيارة
        $vehicle = $db->queryOne("SELECT vehicle_number, driver_id FROM vehicles WHERE id = ?", [$vehicleId]);
        
        if (!$vehicle) {
            return ['success' => false, 'message' => 'السيارة غير موجودة'];
        }
        
        $warehouseName = $vehicleName ?? "مخزن سيارة " . $vehicle['vehicle_number'];
        
        $db->execute(
            "INSERT INTO warehouses (name, warehouse_type, vehicle_id, location, status) 
             VALUES (?, 'vehicle', ?, 'سيارة', 'active')",
            [$warehouseName, $vehicleId]
        );
        
        $warehouseId = $db->getLastInsertId();
        
        return ['success' => true, 'warehouse_id' => $warehouseId];
        
    } catch (Exception $e) {
        error_log("Vehicle Warehouse Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء مخزن السيارة'];
    }
}

/**
 * الحصول على مخزن سيارة
 */
function getVehicleWarehouse($vehicleId) {
    $db = db();
    
    return $db->queryOne(
        "SELECT w.*, v.vehicle_number, v.driver_id, u.full_name as driver_name
         FROM warehouses w
         LEFT JOIN vehicles v ON w.vehicle_id = v.id
         LEFT JOIN users u ON v.driver_id = u.id
         WHERE w.vehicle_id = ? AND w.warehouse_type = 'vehicle'",
        [$vehicleId]
    );
}

/**
 * الحصول على مخزون سيارة
 */
function getVehicleInventory($vehicleId, $filters = []) {
    $db = db();
    
    $warehouse = getVehicleWarehouse($vehicleId);
    
    if (!$warehouse) {
        return [];
    }
    
    $sql = "SELECT vi.*, p.name as product_name, p.unit_price, p.category,
                   (vi.quantity * p.unit_price) as total_value
            FROM vehicle_inventory vi
            LEFT JOIN products p ON vi.product_id = p.id
            WHERE vi.vehicle_id = ? AND vi.quantity > 0";
    
    $params = [$vehicleId];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND vi.product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['product_name'])) {
        $sql .= " AND p.name LIKE ?";
        $params[] = "%{$filters['product_name']}%";
    }
    
    $sql .= " ORDER BY p.name ASC";
    
    return $db->query($sql, $params);
}

/**
 * تحديث مخزون سيارة
 */
function updateVehicleInventory($vehicleId, $productId, $quantity, $userId = null) {
    try {
        $db = db();
        
        if ($userId === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $userId = $currentUser['id'] ?? null;
        }
        
        $warehouse = getVehicleWarehouse($vehicleId);
        
        if (!$warehouse) {
            $result = createVehicleWarehouse($vehicleId);
            if (!$result['success']) {
                return ['success' => false, 'message' => $result['message']];
            }
            $warehouseId = $result['warehouse_id'];
        } else {
            $warehouseId = $warehouse['id'];
        }
        
        // التحقق من وجود السجل
        $existing = $db->queryOne(
            "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
            [$vehicleId, $productId]
        );
        
        if ($existing) {
            $db->execute(
                "UPDATE vehicle_inventory 
                 SET quantity = ?, last_updated_by = ?, last_updated_at = NOW() 
                 WHERE id = ?",
                [$quantity, $userId, $existing['id']]
            );
        } else {
            $db->execute(
                "INSERT INTO vehicle_inventory (vehicle_id, warehouse_id, product_id, quantity, last_updated_by) 
                 VALUES (?, ?, ?, ?, ?)",
                [$vehicleId, $warehouseId, $productId, $quantity, $userId]
            );
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Vehicle Inventory Update Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تحديث المخزون'];
    }
}

/**
 * توليد رقم طلب نقل
 */
function generateTransferNumber() {
    $db = db();
    $prefix = 'TRF-' . date('Ym');
    $lastTransfer = $db->queryOne(
        "SELECT transfer_number FROM warehouse_transfers WHERE transfer_number LIKE ? ORDER BY transfer_number DESC LIMIT 1",
        [$prefix . '%']
    );
    
    $serial = 1;
    if ($lastTransfer) {
        $parts = explode('-', $lastTransfer['transfer_number']);
        $serial = intval($parts[2] ?? 0) + 1;
    }
    
    return sprintf("%s-%04d", $prefix, $serial);
}

/**
 * إنشاء طلب نقل بين المخازن
 */
function createWarehouseTransfer($fromWarehouseId, $toWarehouseId, $transferDate, $items, 
                                 $reason = null, $notes = null, $requestedBy = null) {
    try {
        $db = db();

        ensureWarehouseTransferBatchColumns();
        
        if ($requestedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $requestedBy = $currentUser['id'] ?? null;
        }
        
        if (!$requestedBy || empty($items)) {
            return ['success' => false, 'message' => 'البيانات غير مكتملة'];
        }
        
        // تحديد نوع النقل
        $fromWarehouse = $db->queryOne("SELECT warehouse_type FROM warehouses WHERE id = ?", [$fromWarehouseId]);
        $toWarehouse = $db->queryOne("SELECT warehouse_type FROM warehouses WHERE id = ?", [$toWarehouseId]);
        
        $transferType = 'between_warehouses';
        if ($fromWarehouse['warehouse_type'] === 'main' && $toWarehouse['warehouse_type'] === 'vehicle') {
            $transferType = 'to_vehicle';
        } elseif ($fromWarehouse['warehouse_type'] === 'vehicle' && $toWarehouse['warehouse_type'] === 'main') {
            $transferType = 'from_vehicle';
        }
        
        $transferNumber = generateTransferNumber();
        
        $db->execute(
            "INSERT INTO warehouse_transfers 
            (transfer_number, from_warehouse_id, to_warehouse_id, transfer_date, 
             transfer_type, reason, status, requested_by, notes) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [
                $transferNumber,
                $fromWarehouseId,
                $toWarehouseId,
                $transferDate,
                $transferType,
                $reason,
                $requestedBy,
                $notes
            ]
        );
        
        $transferId = $db->getLastInsertId();
        
        // إضافة العناصر
        foreach ($items as $item) {
            $batchId = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
            $batchNumber = isset($item['batch_number']) ? trim((string)$item['batch_number']) : null;
            $batchName = $batchNumber ?? 'بدون رقم تشغيلة';

            if ($batchId) {
                $batchRow = $db->queryOne(
                    "SELECT quantity_produced, product_id, batch_number 
                     FROM finished_products WHERE id = ?",
                    [$batchId]
                );

                if (!$batchRow) {
                    throw new Exception("رقم التشغيلة المحدد غير موجود.");
                }

                $transferred = $db->queryOne(
                    "SELECT COALESCE(SUM(quantity), 0) AS total_transferred
                     FROM warehouse_transfer_items
                     WHERE batch_id = ?",
                    [$batchId]
                );

                $available = (float)$batchRow['quantity_produced'] - (float)($transferred['total_transferred'] ?? 0);

                if ($item['quantity'] > $available + 1e-6) {
                    throw new Exception(sprintf(
                        'الكمية المطلوبة للتشغيلة %s تتجاوز المتاح حالياً (%.2f).',
                        $batchRow['batch_number'] ?? $batchName,
                        max(0, $available)
                    ));
                }

                if (empty($item['product_id']) && !empty($batchRow['product_id'])) {
                    $item['product_id'] = (int)$batchRow['product_id'];
                }

                if (!$batchNumber) {
                    $batchNumber = $batchRow['batch_number'] ?? null;
                }
            }

            $db->execute(
                "INSERT INTO warehouse_transfer_items (transfer_id, product_id, batch_id, batch_number, quantity, notes) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $transferId,
                    $item['product_id'],
                    $batchId ?: null,
                    $batchNumber ?: null,
                    $item['quantity'],
                    $item['notes'] ?? null
                ]
            );
        }
        
        // إرسال إشعار للمديرين للموافقة
        notifyManagers(
            'طلب نقل منتجات بين المخازن',
            "تم إنشاء طلب نقل جديد رقم {$transferNumber}",
            'info',
            "dashboard/manager.php?page=warehouse_transfers&id={$transferId}"
        );
        
        logAudit($requestedBy, 'create_transfer', 'warehouse_transfer', $transferId, null, [
            'transfer_number' => $transferNumber,
            'transfer_type' => $transferType
        ]);
        
        return ['success' => true, 'transfer_id' => $transferId, 'transfer_number' => $transferNumber];
        
    } catch (Exception $e) {
        error_log("Transfer Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء طلب النقل'];
    }
}

/**
 * الموافقة على نقل
 */
function approveWarehouseTransfer($transferId, $approvedBy = null) {
    try {
        $db = db();
        
        if ($approvedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $approvedBy = $currentUser['id'] ?? null;
        }
        
        $transfer = $db->queryOne("SELECT * FROM warehouse_transfers WHERE id = ?", [$transferId]);
        
        if (!$transfer) {
            return ['success' => false, 'message' => 'طلب النقل غير موجود'];
        }
        
        if ($transfer['status'] !== 'pending') {
            return ['success' => false, 'message' => 'تم معالجة هذا الطلب بالفعل'];
        }
        
        $db->getConnection()->begin_transaction();
        
        // تحديث حالة الطلب
        $db->execute(
            "UPDATE warehouse_transfers 
             SET status = 'approved', approved_by = ?, approved_at = NOW() 
             WHERE id = ?",
            [$approvedBy, $transferId]
        );
        
        // الحصول على العناصر
        $items = $db->query(
            "SELECT * FROM warehouse_transfer_items WHERE transfer_id = ?",
            [$transferId]
        );
        
        // تنفيذ النقل
        foreach ($items as $item) {
            $batchId = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
            $batchNumber = $item['batch_number'] ?? null;
            $batchNote = $batchNumber ? " - تشغيلة {$batchNumber}" : '';

            // خروج من المخزن المصدر
            $fromWarehouse = $db->queryOne(
                "SELECT id FROM warehouses WHERE id = ?",
                [$transfer['from_warehouse_id']]
            );
            
            // التحقق من توفر الكمية في المخزن المصدر
            $availableStock = $db->queryOne(
                "SELECT quantity FROM products WHERE id = ?",
                [$item['product_id']]
            );
            
            if (($availableStock['quantity'] ?? 0) < $item['quantity']) {
                throw new Exception("الكمية غير متوفرة في المخزن المصدر");
            }
            
            // تسجيل حركة خروج
            recordInventoryMovement(
                $item['product_id'],
                $transfer['from_warehouse_id'],
                'out',
                $item['quantity'],
                'warehouse_transfer',
                $transferId,
                "نقل إلى مخزن آخر{$batchNote}",
                $approvedBy
            );
            
            // دخول إلى المخزن الوجهة
            $toWarehouse = $db->queryOne(
                "SELECT id, vehicle_id FROM warehouses WHERE id = ?",
                [$transfer['to_warehouse_id']]
            );
            
            // إذا كان المخزن الوجهة سيارة، تحديث مخزون السيارة
            if ($toWarehouse && $toWarehouse['vehicle_id']) {
                $currentInventory = $db->queryOne(
                    "SELECT quantity FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ?",
                    [$toWarehouse['vehicle_id'], $item['product_id']]
                );
                
                $newQuantity = ($currentInventory['quantity'] ?? 0) + $item['quantity'];
                updateVehicleInventory($toWarehouse['vehicle_id'], $item['product_id'], $newQuantity, $approvedBy);
            }
            
            // تسجيل حركة دخول
            recordInventoryMovement(
                $item['product_id'],
                $transfer['to_warehouse_id'],
                'in',
                $item['quantity'],
                'warehouse_transfer',
                $transferId,
                "نقل من مخزن آخر{$batchNote}",
                $approvedBy
            );
        }
        
        // تحديث حالة الطلب إلى مكتمل
        $db->execute(
            "UPDATE warehouse_transfers SET status = 'completed' WHERE id = ?",
            [$transferId]
        );
        
        $db->getConnection()->commit();
        
        logAudit($approvedBy, 'approve_transfer', 'warehouse_transfer', $transferId, 
                 ['old_status' => $transfer['status']], 
                 ['new_status' => 'approved']);
        
        return ['success' => true, 'message' => 'تمت الموافقة على النقل بنجاح'];
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        error_log("Transfer Approval Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
    }
}

/**
 * رفض نقل
 */
function rejectWarehouseTransfer($transferId, $rejectionReason, $rejectedBy = null) {
    try {
        $db = db();
        
        if ($rejectedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $rejectedBy = $currentUser['id'] ?? null;
        }
        
        $transfer = $db->queryOne("SELECT * FROM warehouse_transfers WHERE id = ?", [$transferId]);
        
        if (!$transfer) {
            return ['success' => false, 'message' => 'طلب النقل غير موجود'];
        }
        
        $db->execute(
            "UPDATE warehouse_transfers 
             SET status = 'rejected', approved_by = ?, rejection_reason = ?, approved_at = NOW() 
             WHERE id = ?",
            [$rejectedBy, $rejectionReason, $transferId]
        );
        
        logAudit($rejectedBy, 'reject_transfer', 'warehouse_transfer', $transferId, 
                 ['old_status' => $transfer['status']], 
                 ['new_status' => 'rejected']);
        
        return ['success' => true, 'message' => 'تم رفض طلب النقل'];
        
    } catch (Exception $e) {
        error_log("Transfer Rejection Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في رفض الطلب'];
    }
}

/**
 * الحصول على طلبات النقل
 */
function getWarehouseTransfers($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT wt.*, 
                   w1.name as from_warehouse_name, w1.warehouse_type as from_warehouse_type,
                   w2.name as to_warehouse_name, w2.warehouse_type as to_warehouse_type,
                   u1.full_name as requested_by_name, u2.full_name as approved_by_name
            FROM warehouse_transfers wt
            LEFT JOIN warehouses w1 ON wt.from_warehouse_id = w1.id
            LEFT JOIN warehouses w2 ON wt.to_warehouse_id = w2.id
            LEFT JOIN users u1 ON wt.requested_by = u1.id
            LEFT JOIN users u2 ON wt.approved_by = u2.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['from_warehouse_id'])) {
        $sql .= " AND wt.from_warehouse_id = ?";
        $params[] = $filters['from_warehouse_id'];
    }
    
    if (!empty($filters['to_warehouse_id'])) {
        $sql .= " AND wt.to_warehouse_id = ?";
        $params[] = $filters['to_warehouse_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND wt.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['transfer_type'])) {
        $sql .= " AND wt.transfer_type = ?";
        $params[] = $filters['transfer_type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(wt.transfer_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(wt.transfer_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY wt.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على السيارات والمندوبين
 */
function getVehicles($filters = []) {
    $db = db();
    
    $sql = "SELECT v.*, u.full_name as driver_name, u.username as driver_username,
                   w.id as warehouse_id, w.name as warehouse_name
            FROM vehicles v
            LEFT JOIN users u ON v.driver_id = u.id
            LEFT JOIN warehouses w ON w.vehicle_id = v.id AND w.warehouse_type = 'vehicle'
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['driver_id'])) {
        $sql .= " AND v.driver_id = ?";
        $params[] = $filters['driver_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND v.status = ?";
        $params[] = $filters['status'];
    }
    
    $sql .= " ORDER BY v.vehicle_number ASC";
    
    return $db->query($sql, $params);
}

