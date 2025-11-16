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

if (!function_exists('ensureVehicleInventoryProductColumns')) {
    /**
     * التأكد من أن جدول vehicle_inventory يحتوي على أعمدة بيانات المنتج التفصيلية.
     */
    function ensureVehicleInventoryProductColumns(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        try {
            $db = db();
        } catch (Throwable $e) {
            return;
        }

        // Global flag to track migrations between deployments while still checking for new columns
        $flagFile = __DIR__ . '/../runtime/vehicle_inventory_upgrade.flag';
        $alreadyRun = file_exists($flagFile);

        try {
            $exists = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
            if (empty($exists)) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        try {
            $columns = $db->query("SHOW COLUMNS FROM vehicle_inventory") ?? [];
        } catch (Throwable $e) {
            $columns = [];
        }

        $existing = [];
        foreach ($columns as $info) {
            if (!empty($info['Field'])) {
                $existing[strtolower($info['Field'])] = true;
            }
        }

        $alterParts = [];

        if (!isset($existing['product_name'])) {
            $alterParts[] = "ADD COLUMN `product_name` varchar(255) DEFAULT NULL AFTER `product_id`";
        }
        if (!isset($existing['product_category'])) {
            $alterParts[] = "ADD COLUMN `product_category` varchar(100) DEFAULT NULL AFTER `product_name`";
        }
        if (!isset($existing['product_unit'])) {
            $alterParts[] = "ADD COLUMN `product_unit` varchar(50) DEFAULT NULL AFTER `product_category`";
        }
        if (!isset($existing['product_unit_price'])) {
            $alterParts[] = "ADD COLUMN `product_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit`";
        }
        if (!isset($existing['product_snapshot'])) {
            $alterParts[] = "ADD COLUMN `product_snapshot` longtext DEFAULT NULL AFTER `product_unit_price`";
        }
        if (!isset($existing['manager_unit_price'])) {
            $alterParts[] = "ADD COLUMN `manager_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit_price`";
        }
        if (!isset($existing['finished_batch_id'])) {
            $alterParts[] = "ADD COLUMN `finished_batch_id` int(11) DEFAULT NULL AFTER `manager_unit_price`";
        }
        if (!isset($existing['finished_batch_number'])) {
            $alterParts[] = "ADD COLUMN `finished_batch_number` varchar(100) DEFAULT NULL AFTER `finished_batch_id`";
        }
        if (!isset($existing['finished_production_date'])) {
            $alterParts[] = "ADD COLUMN `finished_production_date` date DEFAULT NULL AFTER `finished_batch_number`";
        }
        if (!isset($existing['finished_quantity_produced'])) {
            $alterParts[] = "ADD COLUMN `finished_quantity_produced` decimal(12,2) DEFAULT NULL AFTER `finished_production_date`";
        }
        if (!isset($existing['finished_workers'])) {
            $alterParts[] = "ADD COLUMN `finished_workers` text DEFAULT NULL AFTER `finished_quantity_produced`";
        }

        if (!isset($existing['created_at'])) {
            $alterParts[] = "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_updated_at`";
        }

        if (!isset($existing['last_updated_at'])) {
            $alterParts[] = "ADD COLUMN `last_updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `last_updated_by`";
        }

        if (!isset($existing['warehouse_id'])) {
            $alterParts[] = "ADD COLUMN `warehouse_id` int(11) DEFAULT NULL COMMENT 'مخزن السيارة' AFTER `vehicle_id`";
        }

        if ($alterParts) {
            try {
                $db->execute("ALTER TABLE vehicle_inventory " . implode(', ', $alterParts));
            } catch (Throwable $alterError) {
                error_log('VehicleInventory schema update error: ' . $alterError->getMessage());
                // Don't stop lock file creation
            }
        }

        $ensured = true;
        if (!is_dir(dirname($flagFile))) {
            @mkdir(dirname($flagFile), 0775, true);
        }
        if (!$alreadyRun || $alterParts) {
            @file_put_contents($flagFile, date('c'));
        }
    }
}

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

        $notesColumn = $db->queryOne("SHOW COLUMNS FROM warehouse_transfer_items LIKE 'notes'");
        if (empty($notesColumn)) {
            $db->execute("ALTER TABLE warehouse_transfer_items ADD COLUMN `notes` text DEFAULT NULL AFTER `quantity`");
        }
    } catch (Exception $e) {
        error_log('ensureWarehouseTransferBatchColumns error: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * الحصول على قائمة المنتجات بالتشغيلات المتاحة للنقل
 * الآن يحسب الكمية المتاحة من المخزن الفعلي بدلاً من quantity_produced
 */
function getFinishedProductBatchOptions($onlyAvailable = true, $fromWarehouseId = null): array
{
    $db = db();

    try {
        $finishedExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
        if (empty($finishedExists)) {
            return [];
        }

        ensureWarehouseTransferBatchColumns();

        // إذا لم يُحدد المخزن المصدر، نستخدم المخزن الرئيسي الافتراضي
        if ($fromWarehouseId === null) {
            $primaryWarehouse = $db->queryOne(
                "SELECT id FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' ORDER BY id ASC LIMIT 1"
            );
            $fromWarehouseId = $primaryWarehouse['id'] ?? null;
        }

        $sql = "
            SELECT
                fp.id AS batch_id,
                COALESCE(fp.product_id, bn.product_id) AS product_id,
                COALESCE(NULLIF(TRIM(fp.product_name), ''), p.name, 'غير محدد') AS product_name,
                fp.batch_number,
                fp.production_date,
                fp.quantity_produced,
                COALESCE(p.quantity, 0) AS product_stock_quantity
            FROM finished_products fp
            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
            LEFT JOIN products p ON COALESCE(fp.product_id, bn.product_id) = p.id
            GROUP BY fp.id, COALESCE(fp.product_id, bn.product_id), fp.product_name, fp.batch_number, fp.production_date, fp.quantity_produced, p.name, p.quantity
            ORDER BY fp.production_date DESC, product_name ASC, fp.batch_number ASC
        ";

        $rows = $db->query($sql) ?? [];
        $options = [];

        foreach ($rows as $row) {
            // استخدام product_id من batch_numbers إذا كان null في finished_products
            $productId = isset($row['product_id']) && $row['product_id'] !== null ? (int)$row['product_id'] : null;
            $batchId = (int)$row['batch_id'];
            $availableQuantity = 0.0;

            if ($productId > 0 && $fromWarehouseId) {
                // حساب الكمية المتاحة الفعلية من المخزن المصدر
                $fromWarehouse = $db->queryOne(
                    "SELECT warehouse_type, vehicle_id FROM warehouses WHERE id = ?",
                    [$fromWarehouseId]
                );

                if ($fromWarehouse) {
                    // إذا كان المخزن المصدر سيارة، نفحص مخزون السيارة
                    if (($fromWarehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($fromWarehouse['vehicle_id'])) {
                        $vehicleStock = $db->queryOne(
                            "SELECT quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                            [$fromWarehouse['vehicle_id'], $productId]
                        );
                        $availableQuantity = (float)($vehicleStock['quantity'] ?? 0);
                    } else {
                        // إذا كان المخزن المصدر رئيسي، نستخدم الكمية الفعلية من products.quantity
                        $availableQuantity = (float)($row['product_stock_quantity'] ?? 0);
                        $usingQuantityProduced = false;
                        
                        // إذا كانت الكمية في products = 0 أو NULL، نستخدم quantity_produced من finished_products
                        if ($availableQuantity <= 0) {
                            $availableQuantity = (float)($row['quantity_produced'] ?? 0);
                            $usingQuantityProduced = true;
                            
                            // خصم الكمية المنقولة بالفعل (approved أو completed) فقط من quantity_produced
                            if ($availableQuantity > 0) {
                                $transferred = $db->queryOne(
                                    "SELECT COALESCE(SUM(
                                        CASE
                                            WHEN wt.status IN ('approved', 'completed') THEN wti.quantity
                                            ELSE 0
                                        END
                                    ), 0) AS transferred_quantity
                                    FROM warehouse_transfer_items wti
                                    LEFT JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                    WHERE wti.batch_id = ?",
                                    [$batchId]
                                );
                                $availableQuantity -= (float)($transferred['transferred_quantity'] ?? 0);
                            }
                        }
                    }

                    // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                    // فقط عندما نستخدم quantity_produced، وليس عندما نستخدم products.quantity مباشرة
                    // لأن products.quantity تعكس الكمية الفعلية بعد جميع النقلات
                    if (isset($usingQuantityProduced) && $usingQuantityProduced && $batchId > 0) {
                        $pendingTransfers = $db->queryOne(
                            "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                             FROM warehouse_transfer_items wti
                             INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                             WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending'",
                            [$batchId, $fromWarehouseId]
                        );
                        $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                    }
                }
            } else {
                // إذا لم يكن هناك product_id أو fromWarehouseId، نستخدم الكمية الفعلية من batch_numbers
                $actualQuantity = null;
                $batchNumber = $row['batch_number'] ?? '';
                
                // محاولة الحصول على الكمية الفعلية من batch_numbers إذا كان هناك batch_number
                if (!empty($batchNumber)) {
                    $batchNumbersRow = $db->queryOne(
                        "SELECT `quantity` 
                         FROM `batch_numbers` 
                         WHERE `batch_number` = ? 
                         ORDER BY `batch_numbers`.`quantity` ASC 
                         LIMIT 1",
                        [trim($batchNumber)]
                    );
                    
                    if ($batchNumbersRow && isset($batchNumbersRow['quantity'])) {
                        $actualQuantity = floatval($batchNumbersRow['quantity']);
                    }
                }
                
                // إذا لم نجد الكمية من batch_numbers، نستخدم finished_products كبديل
                if ($actualQuantity === null || $actualQuantity <= 0) {
                    $actualQuantity = (float)$row['quantity_produced'];
                }
                
                // حساب الكمية المنقولة بالفعل
                $transferred = $db->queryOne(
                    "SELECT COALESCE(SUM(
                        CASE
                            WHEN wt.status IN ('approved', 'completed') THEN wti.quantity
                            ELSE 0
                        END
                    ), 0) AS transferred_quantity
                    FROM warehouse_transfer_items wti
                    LEFT JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                    WHERE wti.batch_id = ?",
                    [$batchId]
                );
                $availableQuantity = $actualQuantity - (float)($transferred['transferred_quantity'] ?? 0);
                
                // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن إذا كان fromWarehouseId موجوداً
                if ($fromWarehouseId) {
                    $pendingTransfers = $db->queryOne(
                        "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                         FROM warehouse_transfer_items wti
                         INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                         WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending'",
                        [$batchId, $fromWarehouseId]
                    );
                    $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                }
            }

            // إذا كان onlyAvailable = true، نعرض المنتجات التي لديها كمية متاحة > 0
            // لكن أيضاً نعرض المنتجات التي لديها quantity_produced > 0 حتى لو كانت الكمية المتاحة = 0
            // (لأن الكمية قد تكون = 0 بسبب خصم الكميات المنقولة، لكن المنتج موجود في المخزن)
            if ($onlyAvailable) {
                $quantityProduced = (float)($row['quantity_produced'] ?? 0);
                // إذا كانت الكمية المتاحة <= 0 و quantity_produced <= 0، نتخطى هذا المنتج
                if ($availableQuantity <= 0 && $quantityProduced <= 0) {
                    continue;
                }
            }

            $options[] = [
                'batch_id' => $batchId,
                'product_id' => $productId,
                'product_name' => $row['product_name'] ?? 'منتج غير محدد',
                'batch_number' => $row['batch_number'] ?? '',
                'production_date' => $row['production_date'] ?? null,
                'quantity_produced' => (float)$row['quantity_produced'],
                'quantity_available' => max(0, $availableQuantity),
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
    ensureVehicleInventoryProductColumns();
    
    $warehouse = getVehicleWarehouse($vehicleId);
    
    if (!$warehouse) {
        return [];
    }
    
    $nameExpr = "COALESCE(p.name, vi.product_name)";
    $categoryExpr = "COALESCE(p.category, vi.product_category)";
    $unitExpr = "COALESCE(p.unit, vi.product_unit)";

    $buildQuery = static function (bool $withManagerPrice) use (
        $nameExpr,
        $categoryExpr,
        $unitExpr
    ) {
        $unitPriceExpr = $withManagerPrice
            ? "COALESCE(vi.manager_unit_price, vi.product_unit_price, p.unit_price, 0)"
            : "COALESCE(vi.product_unit_price, p.unit_price, 0)";

        return [
            "SELECT 
                vi.*,
                {$nameExpr} AS product_name,
                {$categoryExpr} AS product_category,
                {$categoryExpr} AS category,
                {$unitExpr} AS product_unit,
                {$unitExpr} AS unit,
                {$unitPriceExpr} AS unit_price,
                (vi.quantity * {$unitPriceExpr}) AS total_value
            FROM vehicle_inventory vi
            LEFT JOIN products p ON vi.product_id = p.id
            WHERE vi.vehicle_id = ? AND vi.quantity > 0",
            $unitPriceExpr,
        ];
    };

    [$sql, $unitPriceExpr] = $buildQuery(true);
    $params = [$vehicleId];

    if (!empty($filters['product_id'])) {
        $sql .= " AND vi.product_id = ?";
        $params[] = $filters['product_id'];
    }

    if (!empty($filters['product_name'])) {
        $sql .= " AND (p.name LIKE ? OR vi.product_name LIKE ?)";
        $params[] = "%{$filters['product_name']}%";
        $params[] = "%{$filters['product_name']}%";
    }

    $sql .= " ORDER BY {$nameExpr} ASC";

    try {
        return $db->query($sql, $params);
    } catch (Throwable $queryError) {
        $message = $queryError->getMessage();
        if ($message && stripos($message, "unknown column 'vi.manager_unit_price'") !== false) {
            [$sqlFallback] = $buildQuery(false);
            $sqlFallback .= " ORDER BY {$nameExpr} ASC";
            try {
                return $db->query($sqlFallback, $params);
            } catch (Throwable $fallbackError) {
                throw $fallbackError;
            }
        }
        throw $queryError;
    }
}

/**
 * تحديث مخزون سيارة
 */
function updateVehicleInventory($vehicleId, $productId, $quantity, $userId = null, $unitPriceOverride = null, array $finishedProductData = []) {
    try {
        $db = db();
        ensureVehicleInventoryProductColumns();
        
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
            "SELECT id, quantity, product_name, product_category, product_unit, product_unit_price, product_snapshot,
                    manager_unit_price, finished_batch_id, finished_batch_number, finished_production_date,
                    finished_quantity_produced, finished_workers
             FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
            [$vehicleId, $productId]
        );

        $productName = $finishedProductData['product_name'] ?? null; // استخدام product_name من finishedMetadata إذا كان متوفراً
        $productCategory = null;
        $productUnit = null;
        $productUnitPrice = null;
        $productSnapshot = null;
        $managerUnitPriceValue = $finishedProductData['manager_unit_price'] ?? $finishedProductData['manager_price'] ?? null;
        $finishedBatchId = $finishedProductData['batch_id'] ?? $finishedProductData['finished_batch_id'] ?? null;
        $finishedBatchNumber = $finishedProductData['batch_number'] ?? $finishedProductData['finished_batch_number'] ?? null;
        $finishedProductionDate = $finishedProductData['production_date'] ?? $finishedProductData['finished_production_date'] ?? null;
        $finishedQuantityProduced = $finishedProductData['quantity_produced'] ?? $finishedProductData['finished_quantity_produced'] ?? null;
        $finishedWorkers = $finishedProductData['workers'] ?? $finishedProductData['finished_workers'] ?? null;

        if ($productId > 0) {
            try {
                $productRecord = $db->queryOne(
                    "SELECT id, name, category, unit, unit_price, description, status, quantity, min_stock, created_at, updated_at 
                     FROM products WHERE id = ?",
                    [$productId]
                );
            } catch (Throwable $productError) {
                $productRecord = null;
            }

            if ($productRecord) {
                // استخدام product_name من finishedMetadata أولاً، ثم من products
                if (!$productName) {
                    $productName = $productRecord['name'] ?? null;
                }
                $productCategory = $productRecord['category'] ?? null;
                $productUnit = $productRecord['unit'] ?? null;
                $productSnapshot = json_encode($productRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                // استخدام manager_unit_price من finishedMetadata أولاً، ثم من products
                if ($managerUnitPriceValue === null && array_key_exists('unit_price', $productRecord) && $productRecord['unit_price'] !== null) {
                    $managerUnitPriceValue = (float)$productRecord['unit_price'];
                }
            }
        }

        if ($unitPriceOverride !== null) {
            $productUnitPrice = (float)$unitPriceOverride;
            if ($managerUnitPriceValue === null) {
                $managerUnitPriceValue = $productUnitPrice;
            }
        } elseif ($managerUnitPriceValue !== null) {
            $managerUnitPriceValue = (float)$managerUnitPriceValue;
            $productUnitPrice = $managerUnitPriceValue;
        } elseif ($existing && isset($existing['product_unit_price']) && $existing['product_unit_price'] !== null) {
            $productUnitPrice = (float)$existing['product_unit_price'];
        } elseif (isset($productRecord) && $productRecord && array_key_exists('unit_price', $productRecord) && $productRecord['unit_price'] !== null) {
            $productUnitPrice = (float)$productRecord['unit_price'];
        }

        if ($managerUnitPriceValue === null) {
            if ($existing && isset($existing['manager_unit_price']) && $existing['manager_unit_price'] !== null) {
                $managerUnitPriceValue = (float)$existing['manager_unit_price'];
            } elseif ($productUnitPrice !== null) {
                $managerUnitPriceValue = $productUnitPrice;
            }
        }

        if ($existing && !$productName) {
            $productName = $existing['product_name'] ?? $productName;
        }
        if ($existing && !$productCategory) {
            $productCategory = $existing['product_category'] ?? $productCategory;
        }
        if ($existing && !$productUnit) {
            $productUnit = $existing['product_unit'] ?? $productUnit;
        }
        if ($existing && !$productSnapshot) {
            $productSnapshot = $existing['product_snapshot'] ?? null;
        }
        if ($finishedBatchId !== null) {
            $finishedBatchId = (int)$finishedBatchId;
        }
        if ($finishedQuantityProduced !== null) {
            $finishedQuantityProduced = (float)$finishedQuantityProduced;
        }

        if ($existing && $finishedBatchId === null) {
            $finishedBatchId = $existing['finished_batch_id'] ?? null;
        }
        if ($existing && $finishedBatchNumber === null) {
            $finishedBatchNumber = $existing['finished_batch_number'] ?? null;
        }
        if ($existing && $finishedProductionDate === null) {
            $finishedProductionDate = $existing['finished_production_date'] ?? null;
        }
        if ($existing && $finishedQuantityProduced === null) {
            $finishedQuantityProduced = $existing['finished_quantity_produced'] ?? null;
        }
        if ($existing && $finishedWorkers === null) {
            $finishedWorkers = $existing['finished_workers'] ?? null;
        }
        
        if ($existing) {
            $db->execute(
                "UPDATE vehicle_inventory 
                 SET quantity = ?, last_updated_by = ?, last_updated_at = NOW(),
                     product_name = ?, product_category = ?, product_unit = ?, product_unit_price = ?, product_snapshot = ?,
                     manager_unit_price = ?, finished_batch_id = ?, finished_batch_number = ?, finished_production_date = ?,
                     finished_quantity_produced = ?, finished_workers = ?
                 WHERE id = ?",
                [
                    $quantity,
                    $userId,
                    $productName,
                    $productCategory,
                    $productUnit,
                    $productUnitPrice,
                    $productSnapshot,
                    $managerUnitPriceValue,
                    $finishedBatchId,
                    $finishedBatchNumber,
                    $finishedProductionDate,
                    $finishedQuantityProduced,
                    $finishedWorkers,
                    $existing['id']
                ]
            );
        } else {
            $db->execute(
                "INSERT INTO vehicle_inventory (
                    vehicle_id, warehouse_id, product_id, product_name, product_category,
                    product_unit, product_unit_price, product_snapshot, manager_unit_price,
                    finished_batch_id, finished_batch_number, finished_production_date,
                    finished_quantity_produced, finished_workers,
                    quantity, last_updated_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $vehicleId,
                    $warehouseId,
                    $productId,
                    $productName,
                    $productCategory,
                    $productUnit,
                    $productUnitPrice,
                    $productSnapshot,
                    $managerUnitPriceValue,
                    $finishedBatchId,
                    $finishedBatchNumber,
                    $finishedProductionDate,
                    $finishedQuantityProduced,
                    $finishedWorkers,
                    $quantity,
                    $userId
                ]
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
    $transferId = null;
    $transferNumber = null;
    
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
        $fromWarehouse = $db->queryOne("SELECT id, name, warehouse_type, vehicle_id FROM warehouses WHERE id = ?", [$fromWarehouseId]);
        $toWarehouse = $db->queryOne("SELECT id, name, warehouse_type FROM warehouses WHERE id = ?", [$toWarehouseId]);
        
        if (!$fromWarehouse || !$toWarehouse) {
            return ['success' => false, 'message' => 'المخزن المحدد غير موجود.'];
        }
        
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
        
        if (!$transferId || $transferId <= 0) {
            throw new Exception('فشل في الحصول على معرف طلب النقل من قاعدة البيانات.');
        }
        
        // حفظ transferId و transferNumber في متغيرات خارجية للتحقق منها في catch
        
        // إضافة العناصر
        foreach ($items as $item) {
            $batchId = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
            $batchNumber = isset($item['batch_number']) ? trim((string)$item['batch_number']) : null;
            $batchName = $batchNumber ?? 'بدون رقم تشغيلة';
            $productIdForInsert = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $finishedMetadata = [];

            if ($batchId) {
                $batchRow = $db->queryOne(
                    "SELECT 
                        fp.quantity_produced,
                        fp.product_id,
                        fp.batch_number,
                        fp.product_name,
                        fp.production_date,
                        fp.manager_unit_price,
                        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers_summary
                     FROM finished_products fp
                     LEFT JOIN batch_workers bw ON bw.batch_id = fp.batch_id
                     LEFT JOIN users u ON bw.employee_id = u.id
                     WHERE fp.id = ?
                     GROUP BY fp.id",
                    [$batchId]
                );

                if (!$batchRow) {
                    throw new Exception("رقم التشغيلة المحدد غير موجود.");
                }

                if ($productIdForInsert <= 0 && !empty($batchRow['product_id'])) {
                    $productIdForInsert = (int)$batchRow['product_id'];
                }

                // حساب الكمية المتاحة الفعلية من المخزن المصدر
                $availableQuantity = 0.0;
                
                if ($productIdForInsert > 0) {
                    // إذا كان المخزن المصدر سيارة، نفحص مخزون السيارة
                    if (($fromWarehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($fromWarehouse['vehicle_id'])) {
                        $vehicleStock = $db->queryOne(
                            "SELECT quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                            [$fromWarehouse['vehicle_id'], $productIdForInsert]
                        );
                        $availableQuantity = (float)($vehicleStock['quantity'] ?? 0);
                    } else {
                        // إذا كان المخزن المصدر رئيسي، نفحص مخزون المنتج
                        $productStock = $db->queryOne(
                            "SELECT quantity FROM products WHERE id = ?",
                            [$productIdForInsert]
                        );
                        $availableQuantity = (float)($productStock['quantity'] ?? 0);
                    }
                    
                    // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                    // نستخدم batch_id إذا كان متوفراً لتحديد أكثر دقة، وإلا نستخدم product_id
                    $pendingTransferCondition = '';
                    $pendingParams = [$fromWarehouseId];
                    
                    if ($batchId) {
                        $pendingTransferCondition = 'wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = \'pending\'';
                        $pendingParams = [$batchId, $fromWarehouseId];
                    } else {
                        $pendingTransferCondition = 'wti.product_id = ? AND wt.from_warehouse_id = ? AND wt.status = \'pending\'';
                        $pendingParams = [$productIdForInsert, $fromWarehouseId];
                    }
                    
                    $pendingTransfers = $db->queryOne(
                        "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                         FROM warehouse_transfer_items wti
                         INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                         WHERE {$pendingTransferCondition}",
                        $pendingParams
                    );
                    $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                } else {
                    // إذا لم يكن هناك product_id، نستخدم الطريقة القديمة كبديل
                    $transferred = $db->queryOne(
                        "SELECT COALESCE(SUM(wti.quantity), 0) AS total_transferred
                         FROM warehouse_transfer_items wti
                         INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                         WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND (wt.status = 'approved' OR wt.status = 'pending')",
                        [$batchId, $fromWarehouseId]
                    );
                    $availableQuantity = (float)$batchRow['quantity_produced'] - (float)($transferred['total_transferred'] ?? 0);
                }

                if ($item['quantity'] > $availableQuantity + 1e-6) {
                    throw new Exception(sprintf(
                        'الكمية المطلوبة للتشغيلة %s تتجاوز المتاح حالياً (%.2f).',
                        $batchRow['batch_number'] ?? $batchName,
                        max(0, $availableQuantity)
                    ));
                }

                if ($productIdForInsert <= 0) {
                    $batchProductName = trim((string)($batchRow['product_name'] ?? ''));
                    if ($batchProductName !== '') {
                        $existingProduct = $db->queryOne(
                            "SELECT id FROM products WHERE name = ? LIMIT 1",
                            [$batchProductName]
                        );
                        if ($existingProduct && !empty($existingProduct['id'])) {
                            $productIdForInsert = (int)$existingProduct['id'];
                        } else {
                            static $productTypeColumnExists = null;
                            if ($productTypeColumnExists === null) {
                                $productTypeColumnExists = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                                $productTypeColumnExists = !empty($productTypeColumnExists);
                            }

                            $columns = ['name', 'category', 'quantity', 'unit', 'description', 'status'];
                            $placeholders = ['?', "'منتجات نهائية'", '0', "'قطعة'", "'تم إنشاؤه تلقائياً من تشغيلات الإنتاج'", "'active'"];
                            $values = [$batchProductName];

                            if ($productTypeColumnExists) {
                                $columns[] = 'product_type';
                                $placeholders[] = '?';
                                $values[] = 'internal';
                            }

                            $insertSql = sprintf(
                                "INSERT INTO products (%s) VALUES (%s)",
                                implode(', ', $columns),
                                implode(', ', $placeholders)
                            );
                            $insertResult = $db->execute($insertSql, $values);
                            $productIdForInsert = (int)($insertResult['insert_id'] ?? 0);
                        }
                    }
                }

                if ($productIdForInsert <= 0) {
                    throw new Exception('تعذر ربط التشغيلة بمنتج صالح للنقل. يرجى التأكد من بيانات المنتج.');
                }

                if (!$batchNumber) {
                    $batchNumber = $batchRow['batch_number'] ?? null;
                }

                $finishedMetadata = [
                    'batch_id' => $batchId,
                    'batch_number' => $batchNumber ?? ($batchRow['batch_number'] ?? null),
                    'production_date' => $batchRow['production_date'] ?? null,
                    'quantity_produced' => $batchRow['quantity_produced'] ?? null,
                    'manager_unit_price' => $batchRow['manager_unit_price'] ?? null,
                    'workers' => $batchRow['workers_summary'] ?? null,
                ];
            }

            if ($productIdForInsert <= 0) {
                throw new Exception('المنتج المحدد غير صالح للنقل.');
            }

            $db->execute(
                "INSERT INTO warehouse_transfer_items (transfer_id, product_id, batch_id, batch_number, quantity, notes) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $transferId,
                    $productIdForInsert,
                    $batchId ?: null,
                    $batchNumber ?: null,
                    $item['quantity'],
                    $item['notes'] ?? null
                ]
            );
        }
        
        // النقل تم إنشاؤه بنجاح، الآن نقوم بعمليات إضافية (لا يجب أن تؤثر على نجاح العملية)
        // إرسال إشعار للمديرين للموافقة
        try {
            require_once __DIR__ . '/approval_system.php';
            $approvalNotes = sprintf(
                "طلب نقل منتجات من المخزن %s إلى المخزن %s بتاريخ %s",
                $fromWarehouse['name'] ?? ('#' . $fromWarehouseId),
                $toWarehouse['name'] ?? ('#' . $toWarehouseId),
                $transferDate
            );
            $approvalResult = requestApproval('warehouse_transfer', $transferId, $requestedBy, $approvalNotes);
            if (!($approvalResult['success'] ?? false)) {
                error_log('Warehouse transfer approval request warning: ' . ($approvalResult['message'] ?? 'Unknown error'));
            }
        } catch (Exception $approvalException) {
            // لا نسمح لفشل طلب الموافقة بإلغاء نجاح إنشاء النقل
            error_log('Warehouse transfer approval request exception: ' . $approvalException->getMessage());
        }
        
        // تسجيل عملية التدقيق
        try {
            logAudit($requestedBy, 'create_transfer', 'warehouse_transfer', $transferId, null, [
                'transfer_number' => $transferNumber,
                'transfer_type' => $transferType
            ]);
        } catch (Exception $auditException) {
            // لا نسمح لفشل تسجيل التدقيق بإلغاء نجاح إنشاء النقل
            error_log('Warehouse transfer audit log exception: ' . $auditException->getMessage());
        }
        
        // التحقق النهائي من أن الطلب تم إنشاؤه بنجاح في قاعدة البيانات
        // لا نرمي استثناء هنا حتى لو فشل التحقق - نعتمد على القيم المحفوظة
        $verifyTransfer = $db->queryOne(
            "SELECT id, transfer_number FROM warehouse_transfers WHERE id = ?",
            [$transferId]
        );
        
        if ($verifyTransfer) {
            // الطلب موجود - نستخدم القيم من قاعدة البيانات
            $transferId = (int)$verifyTransfer['id'];
            $transferNumber = $verifyTransfer['transfer_number'];
        } else {
            // الطلب غير موجود - نستخدم القيم المحفوظة (قد يكون هناك تأخير في قاعدة البيانات)
            error_log("Warning: Transfer ID $transferId was created but not immediately found in database. Using saved values.");
        }
        
        // إرجاع النتيجة - حتى لو فشل التحقق، نعتمد على أن الطلب تم إنشاؤه
        return [
            'success' => true, 
            'transfer_id' => $transferId, 
            'transfer_number' => $transferNumber
        ];
        
    } catch (Exception $e) {
        error_log("Transfer Creation Error: " . $e->getMessage());
        error_log("Transfer Creation Error Stack: " . $e->getTraceAsString());
        
        // إذا كان الطلب تم إنشاؤه بالفعل (transferId موجود)، نتحقق من قاعدة البيانات
        if (!empty($transferId)) {
            try {
                $db = db(); // إعادة الحصول على اتصال قاعدة البيانات
                $verifyTransfer = $db->queryOne(
                    "SELECT id, transfer_number FROM warehouse_transfers WHERE id = ?",
                    [$transferId]
                );
                
                if ($verifyTransfer) {
                    // الطلب موجود في قاعدة البيانات! كان هناك خطأ بعد إنشاء الطلب
                    error_log("Warning: Transfer was created (ID: {$verifyTransfer['id']}, Number: {$verifyTransfer['transfer_number']}) but exception occurred after creation: " . $e->getMessage());
                    // نعيد نجاح لأن الطلب تم إنشاؤه فعلياً
                    return [
                        'success' => true,
                        'transfer_id' => (int)$verifyTransfer['id'],
                        'transfer_number' => $verifyTransfer['transfer_number']
                    ];
                }
            } catch (Exception $dbException) {
                error_log("Error checking database in catch block: " . $dbException->getMessage());
            }
        }
        
        // الطلب لم يتم إنشاؤه - خطأ حقيقي
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء طلب النقل: ' . $e->getMessage()];
    }
}

/**
 * الموافقة على نقل
 */
function approveWarehouseTransfer($transferId, $approvedBy = null) {
    try {
        $db = db();
        $approvalsEntityColumn = getApprovalsEntityColumn();
        
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
        
        if (empty($items)) {
            throw new Exception('لا توجد عناصر في طلب النقل هذا.');
        }
        
        error_log("Approving warehouse transfer ID: $transferId with " . count($items) . " items");
        
        // معلومات المخازن
        $fromWarehouse = $db->queryOne(
            "SELECT id, warehouse_type, vehicle_id FROM warehouses WHERE id = ?",
            [$transfer['from_warehouse_id']]
        );

        if (!$fromWarehouse) {
            throw new Exception('المخزن المصدر غير موجود');
        }

        $toWarehouse = $db->queryOne(
            "SELECT id, warehouse_type, vehicle_id FROM warehouses WHERE id = ?",
            [$transfer['to_warehouse_id']]
        );

        if (!$toWarehouse) {
            throw new Exception('المخزن الوجهة غير موجود');
        }

        // تنفيذ النقل
        foreach ($items as $item) {
            $batchId = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
            $batchNumber = $item['batch_number'] ?? null;
            $batchNote = $batchNumber ? " - تشغيلة {$batchNumber}" : '';
            $finishedMetadata = []; // تهيئة finishedMetadata
            
            // جلب finishedMetadata من finished_products إذا كان هناك batch_id
            if ($batchId) {
                $finishedProd = $db->queryOne(
                    "SELECT fp.*, p.unit_price, p.name as product_name
                     FROM finished_products fp
                     LEFT JOIN products p ON fp.product_id = p.id
                     WHERE fp.id = ?",
                    [$batchId]
                );
                if ($finishedProd) {
                    $finishedMetadata = [
                        'finished_batch_id' => $batchId,
                        'finished_batch_number' => $batchNumber ?? $finishedProd['batch_number'] ?? null,
                        'finished_production_date' => $finishedProd['production_date'] ?? null,
                        'finished_quantity_produced' => $finishedProd['quantity_produced'] ?? null,
                        'finished_workers' => $finishedProd['workers'] ?? null,
                        'manager_unit_price' => $finishedProd['unit_price'] ?? null,
                        'product_name' => $finishedProd['product_name'] ?? null
                    ];
                }
            }

            // التحقق من توفر الكمية في المخزن المصدر
            $requestedQuantity = (float) $item['quantity'];
            $availableQuantity = 0.0;
            $fromVehicleStockRow = null;
            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;

            // حساب الكمية المتاحة الفعلية من المخزن المصدر
            // إذا كان هناك batch_id، نستخدم quantity_produced من finished_products أولاً
            // لأن الكمية الفعلية للمنتجات النهائية موجودة في finished_products وليس في products
            if ($batchId) {
                // الحصول على product_id من finished_products إذا لم يكن متوفراً
                if ($productId <= 0) {
                    $batchInfo = $db->queryOne(
                        "SELECT product_id FROM finished_products WHERE id = ?",
                        [$batchId]
                    );
                    if ($batchInfo && !empty($batchInfo['product_id'])) {
                        $productId = (int)$batchInfo['product_id'];
                    }
                }
                
                // إذا كان المخزن المصدر سيارة، نفحص مخزون السيارة
                if (($fromWarehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($fromWarehouse['vehicle_id'])) {
                    $vehicleStock = $db->queryOne(
                        "SELECT quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                        [$fromWarehouse['vehicle_id'], $productId]
                    );
                    $availableQuantity = (float)($vehicleStock['quantity'] ?? 0);
                    
                    // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                    $pendingTransfers = $db->queryOne(
                        "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                         FROM warehouse_transfer_items wti
                         INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                         WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending' AND wt.id != ?",
                        [$batchId, $transfer['from_warehouse_id'], $transferId]
                    );
                    $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                } else {
                    // إذا كان المخزن المصدر رئيسي، نستخدم quantity_produced من finished_products
                    $batchRow = $db->queryOne(
                        "SELECT quantity_produced FROM finished_products WHERE id = ?",
                        [$batchId]
                    );
                    
                    if ($batchRow) {
                        $quantityProduced = (float)($batchRow['quantity_produced'] ?? 0);
                        error_log("Approval check - Transfer ID: $transferId, Batch ID: $batchId, quantity_produced: $quantityProduced, requested: $requestedQuantity");
                        
                        // quantity_produced هو الكمية الحالية المتبقية في finished_products
                        // لكن عند الموافقة على نقل، يتم خصم الكمية مباشرة من quantity_produced
                        // لذلك، يجب أن نحسب الكمية المتاحة بناءً على quantity_produced الحالي
                        // ونخصم فقط الكميات المحجوزة في طلبات pending الأخرى (غير النقل الحالي)
                        $availableQuantity = $quantityProduced;
                        
                        // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                        // نستبعد النقل الحالي من الحساب لأنه هو نفسه
                        // ملاحظة: لا نخصم approved/completed لأن quantity_produced نفسه تم تحديثه عند الموافقة السابقة
                        $pendingTransfers = $db->queryOne(
                            "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                             FROM warehouse_transfer_items wti
                             INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                             WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending' AND wt.id != ?",
                            [$batchId, $transfer['from_warehouse_id'], $transferId]
                        );
                        $pendingQuantity = (float)($pendingTransfers['pending_quantity'] ?? 0);
                        $availableQuantity -= $pendingQuantity;
                        
                        error_log("quantity_produced: $quantityProduced, pending (excluding current): $pendingQuantity, final available: $availableQuantity for batch_id: $batchId");
                    } else {
                        $availableQuantity = 0.0;
                        error_log("Warning: Batch ID $batchId not found in finished_products");
                    }
                }
            } else if ($productId > 0) {
                // إذا كان المخزن المصدر سيارة، نفحص مخزون السيارة
                if (($fromWarehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($fromWarehouse['vehicle_id'])) {
                    $fromVehicleStockRow = $db->queryOne(
                        "SELECT quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                        [$fromWarehouse['vehicle_id'], $productId]
                    );
                    $availableQuantity = (float)($fromVehicleStockRow['quantity'] ?? 0);
                } else {
                    // إذا كان المخزن المصدر رئيسي، نفحص مخزون المنتج
                    $productStock = $db->queryOne(
                        "SELECT quantity FROM products WHERE id = ?",
                        [$productId]
                    );
                    $availableQuantity = (float)($productStock['quantity'] ?? 0);
                }
                
                // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                // نستبعد النقل الحالي من الحساب
                $pendingTransfers = $db->queryOne(
                    "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                     FROM warehouse_transfer_items wti
                     INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                     WHERE wti.product_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending' AND wt.id != ?",
                    [$productId, $transfer['from_warehouse_id'], $transferId]
                );
                $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
            }

            if ($availableQuantity < $requestedQuantity - 1e-6) {
                throw new Exception(sprintf(
                    "الكمية غير متوفرة في المخزن المصدر. المتاح: %.2f، المطلوب: %.2f",
                    max(0, $availableQuantity),
                    $requestedQuantity
                ));
            }
            
            // تسجيل حركة خروج
            // تمرير batch_id إذا كان موجوداً للتحقق من finished_products.quantity_produced
            $movementOut = recordInventoryMovement(
                $item['product_id'],
                $transfer['from_warehouse_id'],
                'out',
                $requestedQuantity,
                'warehouse_transfer',
                $transferId,
                "نقل إلى مخزن آخر{$batchNote}",
                $approvedBy,
                $batchId // تمرير batch_id للتحقق من finished_products
            );

            if (empty($movementOut['success'])) {
                $message = $movementOut['message'] ?? 'تعذر تسجيل حركة الخروج من المخزن المصدر.';
                throw new Exception($message);
            }

            // تحديث مخزون السيارة المصدر إن وجد
            if (($fromWarehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($fromWarehouse['vehicle_id'])) {
                $remainingQuantity = max(0.0, $availableQuantity - $requestedQuantity);
                $updateVehicleResult = updateVehicleInventory($fromWarehouse['vehicle_id'], $item['product_id'], $remainingQuantity, $approvedBy);
                if (empty($updateVehicleResult['success'])) {
                    $message = $updateVehicleResult['message'] ?? 'تعذر تحديث مخزون السيارة المصدر.';
                    throw new Exception($message);
                }
            } else if ($batchId && ($fromWarehouse['warehouse_type'] ?? '') !== 'vehicle') {
                // إذا كان المخزن المصدر رئيسي (ليس سيارة) وهناك batch_id، خصم من quantity_produced
                $finishedProd = $db->queryOne(
                    "SELECT quantity_produced FROM finished_products WHERE id = ?",
                    [$batchId]
                );
                
                if ($finishedProd) {
                    $currentRemaining = (float)($finishedProd['quantity_produced'] ?? 0);
                    $newRemaining = max(0.0, $currentRemaining - $requestedQuantity);
                    $db->execute(
                        "UPDATE finished_products SET quantity_produced = ? WHERE id = ?",
                        [$newRemaining, $batchId]
                    );
                }
            }
            
            // دخول إلى المخزن الوجهة
            // إذا كان المخزن الوجهة سيارة، تحديث مخزون السيارة
            if ($toWarehouse && $toWarehouse['vehicle_id']) {
                $unitPriceOverride = null;
                if (!empty($finishedMetadata) && isset($finishedMetadata['manager_unit_price']) && $finishedMetadata['manager_unit_price'] !== null) {
                    $unitPriceOverride = (float)$finishedMetadata['manager_unit_price'];
                }
                if ($unitPriceOverride === null) {
                    $productPriceRow = $db->queryOne(
                        "SELECT unit_price FROM products WHERE id = ?",
                        [$item['product_id']]
                    );
                    if ($productPriceRow && $productPriceRow['unit_price'] !== null) {
                        $unitPriceOverride = (float)$productPriceRow['unit_price'];
                    }
                }

                $currentInventory = $db->queryOne(
                    "SELECT quantity FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ?",
                    [$toWarehouse['vehicle_id'], $item['product_id']]
                );
                
                $newQuantity = ($currentInventory['quantity'] ?? 0) + $item['quantity'];
                $updateVehicleResult = updateVehicleInventory(
                    $toWarehouse['vehicle_id'],
                    $item['product_id'],
                    $newQuantity,
                    $approvedBy,
                    $unitPriceOverride,
                    $finishedMetadata
                );
                if (empty($updateVehicleResult['success'])) {
                    $message = $updateVehicleResult['message'] ?? 'تعذر تحديث مخزون السيارة الوجهة.';
                    throw new Exception($message);
                }
            } else {
                // إذا كان المخزن الوجهة ليس vehicle، نضيف المنتج إلى products ونحدث warehouse_id
                // تحديث كمية المنتج في المخزن الوجهة
                $currentProduct = $db->queryOne(
                    "SELECT quantity, warehouse_id FROM products WHERE id = ?",
                    [$item['product_id']]
                );
                
                if ($currentProduct) {
                    // إضافة الكمية إلى products مع تحديث warehouse_id
                    $db->execute(
                        "UPDATE products SET quantity = quantity + ?, warehouse_id = ? WHERE id = ?",
                        [$requestedQuantity, $transfer['to_warehouse_id'], $item['product_id']]
                    );
                }
            }
            
            // تسجيل حركة دخول فقط إذا لم يكن المخزن الوجهة vehicle
            // (لأن المنتج في vehicle_inventory وليس في products)
            if (!($toWarehouse && $toWarehouse['vehicle_id'])) {
                $movementIn = recordInventoryMovement(
                    $item['product_id'],
                    $transfer['to_warehouse_id'],
                    'in',
                    $requestedQuantity,
                    'warehouse_transfer',
                    $transferId,
                    "نقل من مخزن آخر{$batchNote}",
                    $approvedBy
                );

                if (empty($movementIn['success'])) {
                    $message = $movementIn['message'] ?? 'تعذر تسجيل حركة الدخول إلى المخزن الوجهة.';
                    throw new Exception($message);
                }
            } else {
                // تسجيل الحركة في inventory_movements فقط دون تحديث products.quantity
                // لأن المنتج موجود في vehicle_inventory
                try {
                    $product = $db->queryOne(
                        "SELECT quantity, warehouse_id FROM products WHERE id = ?",
                        [$item['product_id']]
                    );
                    
                    if ($product) {
                        $db->execute(
                            "INSERT INTO inventory_movements 
                             (product_id, warehouse_id, type, quantity, quantity_before, quantity_after, 
                              reference_type, reference_id, notes, created_by) 
                             VALUES (?, ?, 'in', ?, ?, ?, 'warehouse_transfer', ?, ?, ?)",
                            [
                                $item['product_id'],
                                $transfer['to_warehouse_id'],
                                $requestedQuantity,
                                $product['quantity'],
                                $product['quantity'], // لا نضيف إلى products.quantity لأن المنتج في vehicle_inventory
                                $transferId,
                                "نقل إلى مخزن سيارة{$batchNote}",
                                $approvedBy
                            ]
                        );
                    }
                } catch (Exception $e) {
                    // لا نوقف العملية إذا فشل تسجيل الحركة
                    error_log("Failed to record inventory movement for vehicle transfer: " . $e->getMessage());
                }
            }
        }
        
        error_log("Successfully processed " . count($items) . " items for transfer ID: $transferId");
        
        // تحديث حالة الطلب إلى مكتمل
        $db->execute(
            "UPDATE warehouse_transfers SET status = 'completed' WHERE id = ?",
            [$transferId]
        );

        // تحديث سجل الموافقات إن وجد
        $db->execute(
            "UPDATE approvals 
             SET status = 'approved', approved_by = ? 
             WHERE type = 'warehouse_transfer' AND `{$approvalsEntityColumn}` = ? AND status = 'pending'",
            [$approvedBy, $transferId]
        );
        
        $db->getConnection()->commit();
        
        // جمع معلومات المنتجات المنقولة للرسالة
        $transferredProducts = [];
        foreach ($items as $item) {
            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $quantity = (float)($item['quantity'] ?? 0);
            $batchNumber = $item['batch_number'] ?? null;
            
            if ($productId > 0) {
                $productInfo = $db->queryOne(
                    "SELECT name FROM products WHERE id = ?",
                    [$productId]
                );
                $productName = $productInfo['name'] ?? 'منتج غير معروف';
                
                $batchInfo = $batchNumber ? " - تشغيلة {$batchNumber}" : '';
                $transferredProducts[] = [
                    'name' => $productName,
                    'quantity' => $quantity,
                    'batch_number' => $batchNumber
                ];
            }
        }
        
        logAudit($approvedBy, 'approve_transfer', 'warehouse_transfer', $transferId, 
                 ['old_status' => $transfer['status']], 
                 ['new_status' => 'approved']);
        
        return [
            'success' => true, 
            'message' => 'تمت الموافقة على النقل بنجاح',
            'transferred_products' => $transferredProducts
        ];
        
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
        $approvalsEntityColumn = getApprovalsEntityColumn();
        
        if ($rejectedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $rejectedBy = $currentUser['id'] ?? null;
        }
        
        $transfer = $db->queryOne("SELECT * FROM warehouse_transfers WHERE id = ?", [$transferId]);
        
        if (!$transfer) {
            return ['success' => false, 'message' => 'طلب النقل غير موجود'];
        }
        
        // حفظ الحالة القديمة قبل التحديث
        $oldStatus = $transfer['status'];
        
        // تحديث حالة الطلب
        $db->execute(
            "UPDATE warehouse_transfers 
             SET status = 'rejected', approved_by = ?, rejection_reason = ?, approved_at = NOW() 
             WHERE id = ?",
            [$rejectedBy, $rejectionReason, $transferId]
        );

        // تحديث حالة الموافقة
        $db->execute(
            "UPDATE approvals 
             SET status = 'rejected', approved_by = ?, rejection_reason = ? 
             WHERE type = 'warehouse_transfer' AND `{$approvalsEntityColumn}` = ? AND status = 'pending'",
            [$rejectedBy, $rejectionReason, $transferId]
        );
        
        // تسجيل التدقيق (مع حماية من الأخطاء)
        try {
            logAudit($rejectedBy, 'reject_transfer', 'warehouse_transfer', $transferId, 
                     ['old_status' => $oldStatus], 
                     ['new_status' => 'rejected']);
        } catch (Exception $auditException) {
            // لا نسمح لفشل تسجيل التدقيق بإلغاء نجاح الرفض
            error_log('Transfer rejection audit log exception: ' . $auditException->getMessage());
        }
        
        // التحقق النهائي من أن الطلب تم رفضه فعلياً في قاعدة البيانات
        $verifyTransfer = $db->queryOne(
            "SELECT status, rejection_reason FROM warehouse_transfers WHERE id = ?",
            [$transferId]
        );
        
        if ($verifyTransfer && $verifyTransfer['status'] === 'rejected') {
            // الطلب تم رفضه بنجاح
            return ['success' => true, 'message' => 'تم رفض طلب النقل'];
        } else {
            // الطلب لم يتم رفضه - هناك مشكلة
            error_log("Warning: Transfer rejection failed - Transfer ID: $transferId, Expected status: rejected, Actual status: " . ($verifyTransfer['status'] ?? 'null'));
            return ['success' => false, 'message' => 'تعذر رفض طلب النقل. يرجى المحاولة مرة أخرى.'];
        }
        
    } catch (Exception $e) {
        error_log("Transfer Rejection Error: " . $e->getMessage());
        error_log("Transfer Rejection Error Stack: " . $e->getTraceAsString());
        
        // التحقق من قاعدة البيانات إذا كان الطلب تم رفضه بالفعل
        try {
            $db = db();
            $verifyTransfer = $db->queryOne(
                "SELECT status, rejection_reason FROM warehouse_transfers WHERE id = ?",
                [$transferId]
            );
            
            if ($verifyTransfer && $verifyTransfer['status'] === 'rejected') {
                // الطلب تم رفضه بالفعل في قاعدة البيانات!
                error_log("Warning: Transfer was rejected (ID: $transferId) but exception occurred: " . $e->getMessage());
                // نعيد نجاح لأن الطلب تم رفضه فعلياً
                return [
                    'success' => true,
                    'message' => 'تم رفض طلب النقل'
                ];
            }
        } catch (Exception $dbException) {
            error_log("Error checking database in catch block: " . $dbException->getMessage());
        }
        
        // الطلب لم يتم رفضه - خطأ حقيقي
        return ['success' => false, 'message' => 'حدث خطأ في رفض الطلب: ' . $e->getMessage()];
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

