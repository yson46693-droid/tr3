<?php
/**
 * نظام حركات المخزون
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';

/**
 * تسجيل حركة مخزون
 */
function recordInventoryMovement($productId, $warehouseId, $type, $quantity, $referenceType = null, $referenceId = null, $notes = null, $createdBy = null, $batchId = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        if (!$createdBy) {
            return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
        }
        
        // إذا كان هناك batch_id و referenceType = warehouse_transfer، نحاول الحصول على batch_id من warehouse_transfer_items
        if (!$batchId && $referenceType === 'warehouse_transfer' && $referenceId) {
            $transferItem = $db->queryOne(
                "SELECT batch_id FROM warehouse_transfer_items 
                 WHERE transfer_id = ? AND product_id = ? 
                 LIMIT 1",
                [$referenceId, $productId]
            );
            if ($transferItem && !empty($transferItem['batch_id'])) {
                $batchId = (int)$transferItem['batch_id'];
            }
        }
        
        // التحقق من نوع المخزن - إذا كان مخزن سيارة، نستخدم vehicle_inventory
        $warehouse = null;
        $isVehicleWarehouse = false;
        $vehicleId = null;
        $usingVehicleInventory = false;
        
        if ($warehouseId) {
            // جلب warehouse_type و vehicle_id في استعلام واحد
            $warehouse = $db->queryOne("SELECT id, warehouse_type, vehicle_id FROM warehouses WHERE id = ?", [$warehouseId]);
        }
        
        if ($warehouse && ($warehouse['warehouse_type'] ?? '') === 'vehicle') {
            $isVehicleWarehouse = true;
            // الحصول على vehicle_id مباشرة من warehouses (لأن warehouses.vehicle_id يشير إلى vehicles.id)
            if (!empty($warehouse['vehicle_id'])) {
                $vehicleId = (int)$warehouse['vehicle_id'];
                $usingVehicleInventory = true;
            }
        }
        
        // إذا كان مخزن سيارة ونوع الحركة خروج (بيع أو نقل)، نستخدم vehicle_inventory مباشرة
        // لأن الكميات الفعلية المتاحة للمندوبين محفوظة هناك وليس في جدول products
        if ($usingVehicleInventory && in_array($type, ['out', 'transfer'], true)) {
            // الحصول على الكمية من vehicle_inventory
            // ملاحظة: نستخدم FOR UPDATE لأن vehicle_inventory قد يتم تحديثه قبل استدعاء recordInventoryMovement
            $vehicleInventory = $db->queryOne(
                "SELECT quantity, finished_batch_id FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                [$vehicleId, $productId]
            );
            
            if (!$vehicleInventory) {
                return ['success' => false, 'message' => 'المنتج غير موجود في مخزون السيارة'];
            }
            
            $quantityBefore = (float)($vehicleInventory['quantity'] ?? 0);
            
            // إذا كان هناك finished_batch_id، نستخدمه فقط لتسجيله في الحركة
            // لكن الكمية الفعلية نستخدمها من vehicle_inventory لأنها الكمية الحقيقية المتاحة
            if (!empty($vehicleInventory['finished_batch_id']) && !$batchId) {
                $batchId = (int)$vehicleInventory['finished_batch_id'];
            }
            
            // ملاحظة: لا نتحقق من finished_products.quantity_produced عند البيع من vehicle_inventory
            // لأن vehicle_inventory.quantity هو الكمية الفعلية المتاحة في السيارة
            // و quantity_produced قد يكون أقل بسبب عمليات بيع سابقة من مخازن أخرى
            
            // إنشاء كائن product وهمي للتوافق مع باقي الكود
            $product = ['quantity' => $quantityBefore, 'warehouse_id' => $warehouseId];
        } else {
            // الحصول على الكمية الحالية من products
            $product = $db->queryOne(
                "SELECT quantity, warehouse_id FROM products WHERE id = ?",
                [$productId]
            );
            
            if (!$product) {
                return ['success' => false, 'message' => 'المنتج غير موجود'];
            }
        }
        
        // إذا كان هناك batch_id ونوع الحركة هو 'out' أو 'transfer'، نستخدم quantity_produced من finished_products
        if (!$usingVehicleInventory) {
            $quantityBefore = (float)($product['quantity'] ?? 0);
        }
        $usingFinishedProductQuantity = false;
        
        // إذا كان هناك batch_id ونوع الحركة هو 'out' أو 'transfer'، نستخدم quantity_produced من finished_products
        // لكن فقط إذا لم نكن نستخدم vehicle_inventory (لأننا استخدمناها بالفعل)
        if ($batchId && ($type === 'out' || $type === 'transfer') && !$usingVehicleInventory) {
            $finishedProduct = $db->queryOne(
                "SELECT quantity_produced FROM finished_products WHERE id = ?",
                [$batchId]
            );
            
            if ($finishedProduct && isset($finishedProduct['quantity_produced'])) {
                $quantityProducedRaw = (float)($finishedProduct['quantity_produced'] ?? 0);
                $usingFinishedProductQuantity = true;
                
                // إذا كان referenceType = 'warehouse_transfer'، نخصم الكميات المحجوزة في pending transfers الأخرى
                // لأن quantity_produced نفسه هو الكمية المتبقية بعد خصم approved/completed transfers
                // لكن يجب خصم pending transfers الأخرى أيضاً (غير النقل الحالي)
                if ($referenceType === 'warehouse_transfer' && $referenceId && $warehouseId) {
                    $pendingTransfers = $db->queryOne(
                        "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                         FROM warehouse_transfer_items wti
                         INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                         WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending' AND wt.id != ?",
                        [$batchId, $warehouseId, $referenceId]
                    );
                    $pendingQuantity = (float)($pendingTransfers['pending_quantity'] ?? 0);
                    $quantityBefore = $quantityProducedRaw - $pendingQuantity;
                    error_log("recordInventoryMovement: Using finished_products.quantity_produced = $quantityProducedRaw, pending (excluding current): $pendingQuantity, available: $quantityBefore for batch_id: $batchId, product_id: $productId, transfer_id: $referenceId");
                } else {
                    // إذا لم يكن warehouse_transfer، نستخدم quantity_produced مباشرة
                    $quantityBefore = $quantityProducedRaw;
                    error_log("recordInventoryMovement: Using finished_products.quantity_produced = $quantityBefore for batch_id: $batchId, product_id: $productId");
                }
            }
        }
        
        // حساب الكمية الجديدة
        $quantityAfter = $quantityBefore;
        switch ($type) {
            case 'in':
                $quantityAfter = $quantityBefore + $quantity;
                break;
            case 'out':
                $quantityAfter = $quantityBefore - $quantity;
                if ($quantityAfter < 0) {
                    // إذا كنا نستخدم finished_products، لا نفحص products.quantity
                    if ($usingFinishedProductQuantity) {
                        // التحقق من quantity_produced فقط
                        if ($quantityAfter < 0) {
                            error_log("recordInventoryMovement: Insufficient quantity in finished_products. batch_id: $batchId, quantity_produced: $quantityBefore, requested: $quantity");
                            return ['success' => false, 'message' => 'الكمية غير كافية في المخزون'];
                        }
                    } else {
                        if ($quantityAfter < 0) {
                            return ['success' => false, 'message' => 'الكمية غير كافية في المخزون'];
                        }
                    }
                }
                break;
            case 'adjustment':
                $quantityAfter = $quantity;
                break;
            case 'transfer':
                // للتحويل، نحتاج معالجة خاصة
                $quantityAfter = $quantityBefore - $quantity;
                if ($quantityAfter < 0) {
                    // إذا كنا نستخدم finished_products، لا نفحص products.quantity
                    if ($usingFinishedProductQuantity) {
                        // التحقق من quantity_produced فقط
                        if ($quantityAfter < 0) {
                            error_log("recordInventoryMovement: Insufficient quantity in finished_products for transfer. batch_id: $batchId, quantity_produced: $quantityBefore, requested: $quantity");
                            return ['success' => false, 'message' => 'الكمية غير كافية في المخزون'];
                        }
                    } else {
                        if ($quantityAfter < 0) {
                            return ['success' => false, 'message' => 'الكمية غير كافية في المخزون'];
                        }
                    }
                }
                break;
        }
        
        // تحديث كمية المنتج
        // إذا كنا نستخدم vehicle_inventory، لا نحدث products.quantity لأن الكمية الفعلية في vehicle_inventory
        // إذا كنا نستخدم finished_products.quantity_produced، لا نحدث products.quantity
        // لأن الكمية الفعلية موجودة في finished_products وليس في products
        if ($usingVehicleInventory) {
            // لا نحدث products.quantity لأن الكمية الفعلية في vehicle_inventory
            // vehicle_inventory يتم تحديثه في updateVehicleInventory قبل استدعاء recordInventoryMovement
            error_log("recordInventoryMovement: Skipping products.quantity update for vehicle warehouse, using vehicle_inventory instead");
        } elseif (!$usingFinishedProductQuantity) {
            $updateSql = "UPDATE products SET quantity = ?, warehouse_id = ? WHERE id = ?";
            $db->execute($updateSql, [$quantityAfter, $warehouseId ?? $product['warehouse_id'], $productId]);
        } else {
            // إذا كنا نستخدم finished_products، نحافظ على products.quantity كما هو
            // لأن quantity_produced يتم تحديثه في approveWarehouseTransfer
            error_log("recordInventoryMovement: Skipping products.quantity update for batch_id: $batchId, using finished_products.quantity_produced instead");
        }
        
        // تسجيل الحركة
        // إذا كنا نستخدم finished_products، نسجل quantity_produced في quantity_before/quantity_after
        $sql = "INSERT INTO inventory_movements 
                (product_id, warehouse_id, type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $result = $db->execute($sql, [
            $productId,
            $warehouseId ?? $product['warehouse_id'],
            $type,
            $quantity,
            $quantityBefore, // هذا سيكون quantity_produced إذا كان usingFinishedProductQuantity
            $quantityAfter,  // هذا سيكون quantity_produced - quantity إذا كان usingFinishedProductQuantity
            $referenceType,
            $referenceId,
            $notes,
            $createdBy
        ]);
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'inventory_movement', 'product', $productId, 
                 ['quantity_before' => $quantityBefore], 
                 ['quantity_after' => $quantityAfter, 'type' => $type]);
        
        return ['success' => true, 'movement_id' => $result['insert_id']];
        
    } catch (Exception $e) {
        error_log("Inventory Movement Error: " . $e->getMessage() . " | Product ID: $productId | Warehouse ID: $warehouseId | Type: $type | Quantity: $quantity");
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الحركة: ' . $e->getMessage()];
    }
}

/**
 * الحصول على حركات المخزون
 */
function getInventoryMovements($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT im.*, p.name as product_name, w.name as warehouse_name, 
                   u.username as created_by_name
            FROM inventory_movements im
            LEFT JOIN products p ON im.product_id = p.id
            LEFT JOIN warehouses w ON im.warehouse_id = w.id
            LEFT JOIN users u ON im.created_by = u.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND im.product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['warehouse_id'])) {
        $sql .= " AND im.warehouse_id = ?";
        $params[] = $filters['warehouse_id'];
    }
    
    if (!empty($filters['type'])) {
        $sql .= " AND im.type = ?";
        $params[] = $filters['type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(im.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(im.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY im.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على عدد حركات المخزون
 */
function getInventoryMovementsCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM inventory_movements WHERE 1=1";
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['warehouse_id'])) {
        $sql .= " AND warehouse_id = ?";
        $params[] = $filters['warehouse_id'];
    }
    
    if (!empty($filters['type'])) {
        $sql .= " AND type = ?";
        $params[] = $filters['type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

/**
 * الحصول على تقرير استهلاك المواد
 */
function getMaterialConsumptionReport($productId = null, $dateFrom = null, $dateTo = null) {
    $db = db();
    
    $sql = "SELECT im.product_id, p.name as product_name, 
                   SUM(CASE WHEN im.type = 'out' THEN im.quantity ELSE 0 END) as total_out,
                   SUM(CASE WHEN im.type = 'in' THEN im.quantity ELSE 0 END) as total_in,
                   (SUM(CASE WHEN im.type = 'in' THEN im.quantity ELSE 0 END) - 
                    SUM(CASE WHEN im.type = 'out' THEN im.quantity ELSE 0 END)) as net_consumption
            FROM inventory_movements im
            LEFT JOIN products p ON im.product_id = p.id
            WHERE 1=1";
    
    $params = [];
    
    if ($productId) {
        $sql .= " AND im.product_id = ?";
        $params[] = $productId;
    }
    
    if ($dateFrom) {
        $sql .= " AND DATE(im.created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND DATE(im.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " GROUP BY im.product_id, p.name
              ORDER BY net_consumption DESC";
    
    return $db->query($sql, $params);
}

