<?php
/**
 * مدير إرجاع المنتجات للمخزون
 * Return Inventory Manager
 * 
 * هذا الملف مسؤول عن إرجاع المنتجات المرتجعة إلى مخزن سيارة المندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vehicle_inventory.php';
require_once __DIR__ . '/inventory_movements.php';
require_once __DIR__ . '/audit_log.php';

/**
 * إرجاع المنتجات إلى مخزن سيارة المندوب بعد الموافقة على المرتجع
 * 
 * @param int $returnId معرف المرتجع
 * @param int|null $approvedBy معرف المستخدم الذي وافق على المرتجع
 * @return array ['success' => bool, 'message' => string]
 */
function returnProductsToVehicleInventory(int $returnId, ?int $approvedBy = null): array
{
    try {
        $db = db();
        
        // جلب بيانات المرتجع
        $return = $db->queryOne(
            "SELECT r.*, c.name as customer_name, u.id as sales_rep_id, u.full_name as sales_rep_name
             FROM returns r
             LEFT JOIN customers c ON r.customer_id = c.id
             LEFT JOIN users u ON r.sales_rep_id = u.id
             WHERE r.id = ?",
            [$returnId]
        );
        
        if (!$return) {
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        // التحقق من حالة المرتجع (يجب أن يكون معتمد)
        if ($return['status'] !== 'approved') {
            return ['success' => false, 'message' => 'المرتجع غير معتمد بعد. لا يمكن إرجاع المنتجات للمخزون'];
        }
        
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        if ($salesRepId <= 0) {
            return ['success' => false, 'message' => 'المندوب غير محدد للمرتجع'];
        }
        
        // الحصول على سيارة المندوب
        $vehicle = $db->queryOne(
            "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$salesRepId]
        );
        
        if (!$vehicle) {
            return ['success' => false, 'message' => 'لا توجد سيارة نشطة للمندوب'];
        }
        
        $vehicleId = (int)$vehicle['id'];
        
        // الحصول على مخزن السيارة (getVehicleWarehouse موجود في vehicle_inventory.php)
        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
        if (!$vehicleWarehouse) {
            // إنشاء مخزن السيارة إذا لم يكن موجوداً
            $createResult = createVehicleWarehouse($vehicleId);
            if (!$createResult['success']) {
                return ['success' => false, 'message' => 'تعذر تجهيز مخزن السيارة'];
            }
            $vehicleWarehouse = getVehicleWarehouse($vehicleId);
        }
        $warehouseId = $vehicleWarehouse ? (int)$vehicleWarehouse['id'] : null;
        
        // جلب عناصر المرتجع (استثناء التالف منها - التي سيتم إضافتها لجدول التالف)
        $returnItems = $db->query(
            "SELECT ri.*, p.name as product_name, p.unit
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ? AND ri.is_damaged = 0
             ORDER BY ri.id",
            [$returnId]
        );
        
        if (empty($returnItems)) {
            return ['success' => true, 'message' => 'لا توجد منتجات سليمة للإرجاع للمخزون'];
        }
        
        // الحصول على المستخدم الحالي للموافقة
        if ($approvedBy === null) {
            $currentUser = getCurrentUser();
            $approvedBy = $currentUser ? (int)$currentUser['id'] : null;
        }
        
        $conn = $db->getConnection();
        $conn->begin_transaction();
        
        try {
            foreach ($returnItems as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                $batchNumberId = isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null;
                $batchNumber = $item['batch_number'] ?? null;
                
                // البحث عن Batch Number ID إذا كان لدينا batch_number string فقط
                if (!$batchNumberId && $batchNumber) {
                    $batchInfo = $db->queryOne(
                        "SELECT id FROM batch_numbers WHERE batch_number = ? AND product_id = ?",
                        [$batchNumber, $productId]
                    );
                    if ($batchInfo) {
                        $batchNumberId = (int)$batchInfo['id'];
                    }
                }
                
                // الحصول على الكمية الحالية في مخزن السيارة
                // البحث أولاً باستخدام finished_batch_id
                $inventoryRow = null;
                if ($batchNumberId) {
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity, finished_batch_id, finished_batch_number 
                         FROM vehicle_inventory 
                         WHERE vehicle_id = ? AND product_id = ? AND finished_batch_id = ?
                         FOR UPDATE",
                        [$vehicleId, $productId, $batchNumberId]
                    );
                }
                
                // إذا لم نجد باستخدام batch_id، جرب البحث باستخدام batch_number string
                if (!$inventoryRow && $batchNumber) {
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity, finished_batch_id, finished_batch_number 
                         FROM vehicle_inventory 
                         WHERE vehicle_id = ? AND product_id = ? AND finished_batch_number = ?
                         FOR UPDATE",
                        [$vehicleId, $productId, $batchNumber]
                    );
                    // إذا وجدنا باستخدام batch_number، نحدّث batch_id
                    if ($inventoryRow && $batchNumberId && !$inventoryRow['finished_batch_id']) {
                        $db->execute(
                            "UPDATE vehicle_inventory SET finished_batch_id = ? WHERE id = ?",
                            [$batchNumberId, (int)$inventoryRow['id']]
                        );
                    }
                }
                
                // إذا لم نجد بعد، جرب البحث بدون batch (للمنتجات بدون batch)
                if (!$inventoryRow) {
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity, finished_batch_id, finished_batch_number 
                         FROM vehicle_inventory 
                         WHERE vehicle_id = ? AND product_id = ? 
                         AND (finished_batch_id IS NULL OR finished_batch_id = 0)
                         AND (finished_batch_number IS NULL OR finished_batch_number = '')
                         FOR UPDATE",
                        [$vehicleId, $productId]
                    );
                }
                
                if ($inventoryRow) {
                    // تحديث الكمية الموجودة - إضافة كمية المرتجع للكمية الحالية
                    $currentQuantity = (float)$inventoryRow['quantity'];
                    $newQuantity = round($currentQuantity + $quantity, 3);
                    
                    $db->execute(
                        "UPDATE vehicle_inventory 
                         SET quantity = ?, last_updated_by = ?, last_updated_at = NOW()
                         WHERE id = ?",
                        [$newQuantity, $approvedBy, (int)$inventoryRow['id']]
                    );
                    
                    $quantityBefore = $currentQuantity;
                    $quantityAfter = $newQuantity;
                } else {
                    // إنشاء سجل جديد في المخزون
                    // نحتاج لجلب بيانات المنتج
                    $product = $db->queryOne(
                        "SELECT name, category, unit FROM products WHERE id = ?",
                        [$productId]
                    );
                    
                    if (!$product) {
                        continue; // تخطي المنتجات غير الموجودة
                    }
                    
                    // جلب بيانات التشغيلة إن وجدت
                    $batchInfo = null;
                    if ($batchNumberId) {
                        $batchInfo = $db->queryOne(
                            "SELECT bn.id, bn.batch_number, fp.production_date, fp.quantity_produced, fp.workers
                             FROM batch_numbers bn
                             LEFT JOIN finished_products fp ON bn.id = fp.batch_id
                             WHERE bn.id = ?",
                            [$batchNumberId]
                        );
                    }
                    
                    // إنشاء سجل جديد في vehicle_inventory
                    $db->execute(
                        "INSERT INTO vehicle_inventory 
                        (vehicle_id, warehouse_id, product_id, product_name, product_category, product_unit,
                         finished_batch_id, finished_batch_number, finished_production_date,
                         finished_quantity_produced, finished_workers, quantity, last_updated_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $vehicleId,
                            $warehouseId,
                            $productId,
                            $product['name'] ?? null,
                            $product['category'] ?? null,
                            $product['unit'] ?? null,
                            $batchNumberId,
                            $batchInfo ? ($batchInfo['batch_number'] ?? null) : null,
                            $batchInfo ? ($batchInfo['production_date'] ?? null) : null,
                            $batchInfo ? ($batchInfo['quantity_produced'] ?? null) : null,
                            $batchInfo ? ($batchInfo['workers'] ?? null) : null,
                            $quantity,
                            $approvedBy
                        ]
                    );
                    
                    $quantityBefore = 0;
                    $quantityAfter = $quantity;
                }
                
                // تسجيل حركة في inventory_movements
                if (function_exists('recordInventoryMovement')) {
                    recordInventoryMovement(
                        $productId,
                        $warehouseId,
                        'in',
                        $quantity,
                        $quantityBefore,
                        $quantityAfter,
                        'return',
                        $returnId,
                        "إرجاع منتج من مرتجع رقم: {$return['return_number']}",
                        $approvedBy
                    );
                }
            }
            
            // تحديث حالة المرتجع إلى processed (تمت معالجة المخزون)
            // ملاحظة: الحالة ستكون 'approved' قبل هذه الخطوة، ونقوم بتحديثها إلى 'processed'
            $db->execute(
                "UPDATE returns SET status = 'processed', updated_at = NOW() WHERE id = ?",
                [$returnId]
            );
            
            // تسجيل في سجل التدقيق - تسجيل عملية إرجاع المنتجات للمخزون
            logAudit($approvedBy, 'return_to_inventory', 'returns', $returnId, null, [
                'return_number' => $return['return_number'],
                'items_count' => count($returnItems),
                'vehicle_id' => $vehicleId,
                'sales_rep_id' => $salesRepId,
                'customer_id' => $return['customer_id'] ?? null,
                'action' => 'returned_products_to_vehicle_inventory',
                'details' => 'تم إرجاع جميع المنتجات المرتجعة إلى مخزن سيارة المندوب'
            ]);
            
            $conn->commit();
            
            return [
                'success' => true,
                'message' => 'تم إرجاع المنتجات إلى مخزن السيارة بنجاح',
                'items_count' => count($returnItems)
            ];
            
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Error returning products to inventory: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء إرجاع المنتجات للمخزون: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("Error in returnProductsToVehicleInventory: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

