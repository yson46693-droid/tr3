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
require_once __DIR__ . '/../../includes/product_name_helper.php';

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

// السماح للمديرين والمندوبين بالوصول
requireRole(['manager', 'sales']);

$approvalsEntityColumn = getApprovalsEntityColumn();

$currentUser = getCurrentUser();
$isManager = ($currentUser['role'] ?? '') === 'manager';
$isSalesRep = ($currentUser['role'] ?? '') === 'sales';
$db = db();

// إذا كان المستخدم مندوب، الحصول على مخزن سيارة المندوب
$salesRepVehicleWarehouse = null;
if ($isSalesRep) {
    $salesRepVehicle = $db->queryOne(
        "SELECT v.id as vehicle_id, w.id as warehouse_id, w.name as warehouse_name
         FROM vehicles v
         LEFT JOIN warehouses w ON w.vehicle_id = v.id AND w.warehouse_type = 'vehicle'
         WHERE v.driver_id = ? AND v.status = 'active' AND w.status = 'active'
         LIMIT 1",
        [$currentUser['id']]
    );
    
    if ($salesRepVehicle && !empty($salesRepVehicle['warehouse_id'])) {
        $salesRepVehicleWarehouse = [
            'id' => (int)$salesRepVehicle['warehouse_id'],
            'name' => $salesRepVehicle['warehouse_name'] ?? 'مخزن السيارة'
        ];
    }
}

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

// معالجة AJAX لجلب المنتجات من مخزن معين
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_warehouse_products' && isset($_GET['warehouse_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $warehouseId = intval($_GET['warehouse_id']);
    
    try {
        $warehouse = $db->queryOne(
            "SELECT id, warehouse_type, vehicle_id FROM warehouses WHERE id = ? AND status = 'active'",
            [$warehouseId]
        );
        
        if (!$warehouse) {
            echo json_encode(['success' => false, 'message' => 'المخزن غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $products = [];
        
        // إذا كان المخزن سيارة، جلب المنتجات من vehicle_inventory
        if (($warehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($warehouse['vehicle_id'])) {
            $vehicleId = (int)$warehouse['vehicle_id'];
            $inventory = getVehicleInventory($vehicleId);
            
            if (is_array($inventory) && !empty($inventory)) {
                foreach ($inventory as $item) {
                    $batchNumber = '';
                    if (!empty($item['finished_batch_id'])) {
                        $batchInfo = $db->queryOne(
                            "SELECT batch_number FROM finished_products WHERE id = ?",
                            [intval($item['finished_batch_id'])]
                        );
                        if ($batchInfo && !empty($batchInfo['batch_number'])) {
                            $batchNumber = trim($batchInfo['batch_number']);
                        }
                    }
                    
                    $products[] = [
                        'product_id' => isset($item['product_id']) ? intval($item['product_id']) : 0,
                        'batch_id' => isset($item['finished_batch_id']) ? intval($item['finished_batch_id']) : 0,
                        'batch_number' => $batchNumber,
                        'product_name' => isset($item['product_name']) ? trim($item['product_name']) : 'غير محدد',
                        'quantity' => isset($item['quantity']) ? floatval($item['quantity']) : 0.0,
                        'unit' => isset($item['unit']) ? trim($item['unit']) : (isset($item['product_unit']) ? trim($item['product_unit']) : 'قطعة')
                    ];
                }
            }
        } else {
            // إذا كان المخزن رئيسي، جلب المنتجات من finished_products و products
            // جلب منتجات المصنع (finished_products)
            $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
            if (!empty($finishedProductsTableExists)) {
                $factoryProducts = $db->query("
                    SELECT 
                        fp.id as batch_id,
                        fp.batch_number,
                        COALESCE(fp.product_id, bn.product_id) AS product_id,
                        COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                        fp.quantity_produced,
                        pr.unit
                    FROM finished_products fp
                    LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                    LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                    WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                    ORDER BY fp.batch_number DESC, fp.production_date DESC
                    LIMIT 100
                ");
                
                foreach ($factoryProducts as $fp) {
                    $products[] = [
                        'product_id' => (int)($fp['product_id'] ?? 0),
                        'batch_id' => (int)($fp['batch_id'] ?? 0),
                        'batch_number' => $fp['batch_number'] ?? '-',
                        'product_name' => $fp['product_name'] ?? 'غير محدد',
                        'quantity' => (float)($fp['quantity_produced'] ?? 0),
                        'unit' => $fp['unit'] ?? 'قطعة'
                    ];
                }
            }
            
            // جلب المنتجات الخارجية
            $externalProducts = $db->query("
                SELECT id, name, quantity, unit
                FROM products
                WHERE product_type = 'external' AND quantity > 0 AND status = 'active'
                ORDER BY name
                LIMIT 100
            ");
            
            foreach ($externalProducts as $ep) {
                $products[] = [
                    'product_id' => (int)($ep['id'] ?? 0),
                    'batch_id' => 0,
                    'batch_number' => '-',
                    'product_name' => $ep['name'] ?? 'غير محدد',
                    'quantity' => (float)($ep['quantity'] ?? 0),
                    'unit' => $ep['unit'] ?? 'قطعة'
                ];
            }
        }
        
        echo json_encode([
            'success' => true, 
            'products' => $products,
            'count' => count($products)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        error_log('Error fetching warehouse products: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'حدث خطأ أثناء جلب المنتجات: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة AJAX لجلب المنتجات من مخزن سيارة المندوب
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_vehicle_inventory' && isset($_GET['vehicle_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $salesRepId = intval($_GET['vehicle_id']);
    
    try {
        // الحصول على سيارة المندوب
        $vehicle = $db->queryOne(
            "SELECT v.id as vehicle_id FROM vehicles v WHERE v.driver_id = ? AND v.status = 'active'",
            [$salesRepId]
        );
        
        if (!$vehicle) {
            echo json_encode(['success' => false, 'message' => 'لم يتم العثور على سيارة للمندوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $vehicleId = $vehicle['vehicle_id'];
        $inventory = getVehicleInventory($vehicleId);
        
        // تحويل البيانات إلى تنسيق مناسب
        $products = [];
        if (is_array($inventory) && !empty($inventory)) {
            foreach ($inventory as $item) {
                // الحصول على batch_number من finished_products إذا كان موجوداً
                $batchNumber = '';
                if (!empty($item['finished_batch_id'])) {
                    $batchInfo = $db->queryOne(
                        "SELECT batch_number FROM finished_products WHERE id = ?",
                        [intval($item['finished_batch_id'])]
                    );
                    if ($batchInfo && !empty($batchInfo['batch_number'])) {
                        $batchNumber = trim($batchInfo['batch_number']);
                    }
                }
                
                // إذا لم يكن هناك batch_number من finished_products، استخدم batch_number من vehicle_inventory
                if (empty($batchNumber) && isset($item['batch_number'])) {
                    $batchNumber = trim($item['batch_number']);
                }
                
                $products[] = [
                    'product_id' => isset($item['product_id']) ? intval($item['product_id']) : 0,
                    'batch_id' => isset($item['finished_batch_id']) ? intval($item['finished_batch_id']) : 0,
                    'batch_number' => $batchNumber,
                    'product_name' => isset($item['product_name']) ? trim($item['product_name']) : 'غير محدد',
                    'quantity' => isset($item['quantity']) ? floatval($item['quantity']) : 0.0,
                    'unit' => isset($item['unit']) ? trim($item['unit']) : (isset($item['product_unit']) ? trim($item['product_unit']) : 'قطعة')
                ];
            }
        }
        
        echo json_encode([
            'success' => true, 
            'products' => $products,
            'count' => count($products)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        error_log('Error fetching vehicle inventory: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'حدث خطأ أثناء جلب المنتجات: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_transfer_from_company') {
        // الحصول على المخزن الرئيسي (منتج الشركة)
        $companyWarehouse = $db->queryOne(
            "SELECT id FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1"
        );
        
        if (!$companyWarehouse) {
            // إنشاء المخزن الرئيسي إذا لم يكن موجوداً
            $db->execute(
                "INSERT INTO warehouses (name, warehouse_type, status, location, description) 
                 VALUES (?, 'main', 'active', ?, ?)",
                ['مخزن الشركة الرئيسي', 'الموقع الرئيسي للشركة', 'تم إنشاء هذا المخزن تلقائياً']
            );
            $companyWarehouseId = $db->getLastInsertId();
        } else {
            $companyWarehouseId = $companyWarehouse['id'];
        }
        
        $toWarehouseId = intval($_POST['to_warehouse_id'] ?? 0);
        $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // معالجة العناصر
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $productId = !empty($item['product_id']) ? intval($item['product_id']) : 0;
                $batchId = !empty($item['batch_id']) ? intval($item['batch_id']) : 0;
                $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0;
                
                if (($productId > 0 || $batchId > 0) && $quantity > 0) {
                    $items[] = [
                        'product_id' => $productId > 0 ? $productId : null,
                        'batch_id' => $batchId > 0 ? $batchId : null,
                        'batch_number' => !empty($item['batch_number']) ? trim($item['batch_number']) : null,
                        'quantity' => $quantity
                    ];
                }
            }
        }
        
        if ($toWarehouseId <= 0 || empty($items)) {
            $_SESSION['warehouse_transfer_error'] = 'يجب تحديد المخزن الهدف وإضافة منتج واحد على الأقل.';
        } else {
            // التحقق من أن المخزن الهدف هو مخزن سيارة
            $toWarehouse = $db->queryOne(
                "SELECT id, name, warehouse_type FROM warehouses WHERE id = ? AND status = 'active'",
                [$toWarehouseId]
            );
            
            if (!$toWarehouse || $toWarehouse['warehouse_type'] !== 'vehicle') {
                $_SESSION['warehouse_transfer_error'] = 'يجب تحديد مخزن سيارة كهدف للنقل.';
            } else {
                try {
                    $result = createWarehouseTransfer(
                        $companyWarehouseId,
                        $toWarehouseId,
                        $transferDate,
                        $items,
                        $reason ?: 'نقل منتجات من مخزن الشركة إلى مخزن السيارة',
                        $notes,
                        $currentUser['id']
                    );
                    
                    if ($result['success']) {
                        // التحقق من حالة النقل بعد الإنشاء
                        $transferInfo = $db->queryOne(
                            "SELECT status FROM warehouse_transfers WHERE id = ?",
                            [$result['transfer_id']]
                        );
                        
                        if ($transferInfo && $transferInfo['status'] === 'completed') {
                            $_SESSION['warehouse_transfer_success'] = 'تم تنفيذ النقل بنجاح. تم نقل المنتجات إلى المخزن المحدد مباشرة.';
                        } else {
                            $_SESSION['warehouse_transfer_success'] = 'تم إنشاء طلب النقل بنجاح. سيتم مراجعته و الموافقة عليه.';
                        }
                    } else {
                        $_SESSION['warehouse_transfer_error'] = $result['message'] ?? 'حدث خطأ أثناء إنشاء طلب النقل.';
                    }
                } catch (Exception $e) {
                    error_log('Error creating transfer from company: ' . $e->getMessage());
                    $_SESSION['warehouse_transfer_error'] = 'حدث خطأ أثناء إنشاء طلب النقل: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'create_general_transfer') {
        // طلب نقل عام بين المخازن (يمكن استخدامه من قبل المندوبين والمديرين)
        $fromWarehouseId = intval($_POST['from_warehouse_id'] ?? 0);
        $toWarehouseId = intval($_POST['to_warehouse_id'] ?? 0);
        $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // إذا كان المستخدم مندوب، يجب أن يكون "من المخزن" هو مخزن سيارة المندوب
        if ($isSalesRep) {
            if (!$salesRepVehicleWarehouse) {
                $_SESSION['warehouse_transfer_error'] = 'لم يتم العثور على مخزن سيارة للمندوب.';
            } else {
                // فرض استخدام مخزن سيارة المندوب
                $fromWarehouseId = $salesRepVehicleWarehouse['id'];
            }
        }
        
        if ($fromWarehouseId <= 0) {
            $_SESSION['warehouse_transfer_error'] = 'يجب تحديد المخزن المصدر.';
        } elseif ($toWarehouseId <= 0) {
            $_SESSION['warehouse_transfer_error'] = 'يجب تحديد المخزن الهدف.';
        } elseif ($fromWarehouseId === $toWarehouseId) {
            $_SESSION['warehouse_transfer_error'] = 'المخزن المصدر والهدف لا يمكن أن يكونا نفس المخزن.';
        } else {
            // معالجة العناصر
            $items = [];
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    $productId = isset($item['product_id']) ? intval($item['product_id']) : 0;
                    $batchId = isset($item['batch_id']) ? intval($item['batch_id']) : 0;
                    $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0;
                    
                    if (($productId > 0 || $batchId > 0) && $quantity > 0) {
                        $items[] = [
                            'product_id' => $productId > 0 ? $productId : null,
                            'batch_id' => $batchId > 0 ? $batchId : null,
                            'batch_number' => !empty($item['batch_number']) ? trim($item['batch_number']) : null,
                            'quantity' => $quantity
                        ];
                    }
                }
            }
            
            if (empty($items)) {
                $_SESSION['warehouse_transfer_error'] = 'يجب إضافة منتج واحد على الأقل.';
            } else {
                try {
                    $fromWarehouseName = $db->queryOne("SELECT name FROM warehouses WHERE id = ?", [$fromWarehouseId])['name'] ?? 'مخزن';
                    $toWarehouseName = $db->queryOne("SELECT name FROM warehouses WHERE id = ?", [$toWarehouseId])['name'] ?? 'مخزن';
                    
                    $result = createWarehouseTransfer(
                        $fromWarehouseId,
                        $toWarehouseId,
                        $transferDate,
                        $items,
                        $reason ?: "نقل منتجات من {$fromWarehouseName} إلى {$toWarehouseName}",
                        $notes,
                        $currentUser['id']
                    );
                    
                    if ($result['success']) {
                        $transferInfo = $db->queryOne(
                            "SELECT status FROM warehouse_transfers WHERE id = ?",
                            [$result['transfer_id']]
                        );
                        
                        if ($transferInfo && $transferInfo['status'] === 'completed') {
                            $_SESSION['warehouse_transfer_success'] = 'تم تنفيذ النقل بنجاح.';
                        } else {
                            $_SESSION['warehouse_transfer_success'] = 'تم إنشاء طلب النقل بنجاح. سيتم مراجعته و الموافقة عليه.';
                        }
                    } else {
                        $_SESSION['warehouse_transfer_error'] = $result['message'] ?? 'حدث خطأ أثناء إنشاء طلب النقل.';
                    }
                } catch (Exception $e) {
                    error_log('Error creating general transfer: ' . $e->getMessage());
                    $_SESSION['warehouse_transfer_error'] = 'حدث خطأ أثناء إنشاء طلب النقل: ' . $e->getMessage();
                }
            }
        }
        
        // إعادة التوجيه
        require_once __DIR__ . '/../../includes/path_helper.php';
        $redirectFilters = $filters;
        if (!empty($warehouseTransfersSectionParam)) {
            $redirectFilters['section'] = $warehouseTransfersSectionParam;
        }
        $dashboardSlug = $isManager ? 'manager' : 'sales';
        redirectAfterPost($warehouseTransfersParentPage, $redirectFilters, ['id'], $dashboardSlug, $pageNum);
    } elseif ($action === 'create_transfer_from_sales_rep') {
        // الحصول على المخزن الرئيسي (الهدف)
        $companyWarehouse = $db->queryOne(
            "SELECT id FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1"
        );
        
        if (!$companyWarehouse) {
            // إنشاء المخزن الرئيسي إذا لم يكن موجوداً
            $db->execute(
                "INSERT INTO warehouses (name, warehouse_type, status, location, description) 
                 VALUES (?, 'main', 'active', ?, ?)",
                ['مخزن الشركة الرئيسي', 'الموقع الرئيسي للشركة', 'تم إنشاء هذا المخزن تلقائياً']
            );
            $toWarehouseId = $db->getLastInsertId();
        } else {
            $toWarehouseId = $companyWarehouse['id'];
        }
        
        $salesRepId = intval($_POST['sales_rep_id'] ?? 0);
        $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // الحصول على سيارة المندوب ومخزنها
        $vehicle = $db->queryOne(
            "SELECT v.id as vehicle_id, w.id as warehouse_id, u.full_name as sales_rep_name
             FROM vehicles v
             LEFT JOIN warehouses w ON w.vehicle_id = v.id AND w.warehouse_type = 'vehicle'
             LEFT JOIN users u ON v.driver_id = u.id
             WHERE v.driver_id = ? AND v.status = 'active'",
            [$salesRepId]
        );
        
        if (!$vehicle || empty($vehicle['warehouse_id'])) {
            $_SESSION['warehouse_transfer_error'] = 'لم يتم العثور على مخزن سيارة للمندوب المحدد.';
        } else {
            $fromWarehouseId = $vehicle['warehouse_id'];
            $salesRepName = $vehicle['sales_rep_name'] ?? 'مندوب';
            
            // معالجة العناصر
            $items = [];
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    $productId = isset($item['product_id']) ? intval($item['product_id']) : 0;
                    $batchId = isset($item['batch_id']) ? intval($item['batch_id']) : 0;
                    $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0;
                    
                    // يجب أن يكون هناك product_id أو batch_id على الأقل، وكمية أكبر من صفر
                    if (($productId > 0 || $batchId > 0) && $quantity > 0) {
                        $items[] = [
                            'product_id' => $productId > 0 ? $productId : null,
                            'batch_id' => $batchId > 0 ? $batchId : null,
                            'batch_number' => !empty($item['batch_number']) ? trim($item['batch_number']) : null,
                            'quantity' => $quantity
                        ];
                    }
                }
            }
            
            // تسجيل تفاصيل العناصر للمساعدة في التصحيح
            if (empty($items)) {
                error_log('No items found in POST data. POST items: ' . json_encode($_POST['items'] ?? []));
                $_SESSION['warehouse_transfer_error'] = 'يجب إضافة منتج واحد على الأقل.';
            } else {
                try {
                    // إذا كان المدير: تنفيذ مباشر بدون موافقة
                    // إذا كان أي حساب آخر: يحتاج موافقة
                    $result = createWarehouseTransfer(
                        $fromWarehouseId,
                        $toWarehouseId,
                        $transferDate,
                        $items,
                        $reason ?: "نقل منتجات من بضاعة المندوب ({$salesRepName}) إلى المخزن الرئيسي",
                        $notes,
                        $currentUser['id']
                    );
                    
                    if ($result['success']) {
                        // التحقق من حالة النقل بعد الإنشاء
                        $transferInfo = $db->queryOne(
                            "SELECT status FROM warehouse_transfers WHERE id = ?",
                            [$result['transfer_id']]
                        );
                        
                        if ($transferInfo && $transferInfo['status'] === 'completed') {
                            $_SESSION['warehouse_transfer_success'] = 'تم تنفيذ النقل بنجاح. تم نقل المنتجات إلى المخزن الرئيسي مباشرة.';
                        } else {
                            $_SESSION['warehouse_transfer_success'] = 'تم إنشاء طلب النقل بنجاح. سيتم مراجعته و الموافقة عليه من قبل المدير.';
                        }
                    } else {
                        $_SESSION['warehouse_transfer_error'] = $result['message'] ?? 'حدث خطأ أثناء إنشاء طلب النقل.';
                    }
                } catch (Exception $e) {
                    error_log('Error creating transfer from sales rep: ' . $e->getMessage());
                    $_SESSION['warehouse_transfer_error'] = 'حدث خطأ أثناء إنشاء طلب النقل: ' . $e->getMessage();
                }
            }
        }
        
        // إعادة التوجيه
        require_once __DIR__ . '/../../includes/path_helper.php';
        $redirectFilters = $filters;
        if (!empty($warehouseTransfersSectionParam)) {
            $redirectFilters['section'] = $warehouseTransfersSectionParam;
        }
        $dashboardSlug = $isManager ? 'manager' : 'sales';
        redirectAfterPost($warehouseTransfersParentPage, $redirectFilters, ['id'], $dashboardSlug, $pageNum);
    }
    
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
                    // التحقق من قاعدة البيانات للتأكد من أن الطلب لم يتم رفضه بالفعل
                    $verifyTransfer = $db->queryOne(
                        "SELECT status FROM warehouse_transfers WHERE id = ?",
                        [$transferId]
                    );
                    
                    if ($verifyTransfer && $verifyTransfer['status'] === 'rejected') {
                        // الطلب تم رفضه بالفعل!
                        error_log("Warning: Transfer was rejected (ID: $transferId) but rejectRequest returned error. Details: " . json_encode($result));
                        $_SESSION['warehouse_transfer_success'] = 'تم رفض طلب النقل.';
                    } else {
                        $_SESSION['warehouse_transfer_error'] = $result['message'] ?? 'تعذر رفض طلب النقل.';
                    }
                }
            } else {
                $result = rejectWarehouseTransfer($transferId, $rejectionReason, $currentUser['id']);
                if ($result['success']) {
                    $_SESSION['warehouse_transfer_success'] = $result['message'];
                } else {
                    // التحقق من قاعدة البيانات للتأكد من أن الطلب لم يتم رفضه بالفعل
                    $verifyTransfer = $db->queryOne(
                        "SELECT status FROM warehouse_transfers WHERE id = ?",
                        [$transferId]
                    );
                    
                    if ($verifyTransfer && $verifyTransfer['status'] === 'rejected') {
                        // الطلب تم رفضه بالفعل!
                        error_log("Warning: Transfer was rejected (ID: $transferId) but rejectWarehouseTransfer returned error. Details: " . json_encode($result));
                        $_SESSION['warehouse_transfer_success'] = 'تم رفض طلب النقل.';
                    } else {
                        $_SESSION['warehouse_transfer_error'] = $result['message'];
                    }
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

// الحصول على مخازن السيارات فقط
$vehicleWarehouses = $db->query(
    "SELECT w.id, w.name, v.vehicle_number, u.full_name as driver_name
     FROM warehouses w
     LEFT JOIN vehicles v ON w.vehicle_id = v.id
     LEFT JOIN users u ON v.driver_id = u.id
     WHERE w.warehouse_type = 'vehicle' AND w.status = 'active'
     ORDER BY w.name"
);

// الحصول على قائمة المندوبين (sales reps) الذين لديهم سيارات
$salesReps = $db->query(
    "SELECT DISTINCT u.id, u.full_name, u.username, v.id as vehicle_id, v.vehicle_number
     FROM users u
     INNER JOIN vehicles v ON v.driver_id = u.id
     WHERE u.role = 'sales' AND u.status = 'active' AND v.status = 'active'
     ORDER BY u.full_name ASC"
);

// الحصول على منتجات الشركة (منتجات المصنع - finished_products)
$companyFactoryProducts = [];
try {
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    if (!empty($finishedProductsTableExists)) {
        $companyFactoryProducts = $db->query("
            SELECT 
                fp.id as batch_id,
                fp.batch_number,
                COALESCE(fp.product_id, bn.product_id) AS product_id,
                COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                fp.quantity_produced,
                fp.production_date,
                pr.unit,
                pr.unit_price
            FROM finished_products fp
            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
            LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
            WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
            ORDER BY fp.production_date DESC, fp.id DESC
            LIMIT 100
        ");
    }
} catch (Exception $e) {
    error_log('Error fetching factory products: ' . $e->getMessage());
}

// الحصول على المنتجات الخارجية
$companyExternalProducts = [];
try {
    $companyExternalProducts = $db->query("
        SELECT 
            id,
            name,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
          AND quantity > 0
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching external products: ' . $e->getMessage());
}

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
        // التأكد من أن transfer_id هو رقم صحيح
        $transferId = (int)$selectedTransfer['id'];
        $fromWarehouseId = (int)($selectedTransfer['from_warehouse_id'] ?? 0);
        $fromVehicleId = null;
        if (($selectedTransfer['from_warehouse_type'] ?? '') === 'vehicle' && $fromWarehouseId > 0) {
            $warehouseDetails = $db->queryOne(
                "SELECT vehicle_id FROM warehouses WHERE id = ?",
                [$fromWarehouseId]
            );
            if ($warehouseDetails && !empty($warehouseDetails['vehicle_id'])) {
                $fromVehicleId = (int)$warehouseDetails['vehicle_id'];
            }
        }
        $selectedTransfer['from_vehicle_id'] = $fromVehicleId;
        
        // تهيئة مصفوفة العناصر
        $selectedTransfer['items'] = [];
        
        // أولاً: التحقق من وجود عناصر في قاعدة البيانات
        $itemsCheck = $db->queryOne(
            "SELECT COUNT(*) as count FROM warehouse_transfer_items WHERE transfer_id = ?",
            [$transferId]
        );
        $itemsCount = (int)($itemsCheck['count'] ?? 0);
        
        // إذا كان هناك عناصر، جلبها
        if ($itemsCount > 0) {
            // جلب العناصر الأساسية بدون JOIN للتأكد من الحصول على جميع العناصر
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
            
            // التحقق من أن الاستعلام أعاد نتائج
            if (empty($basicItems) || !is_array($basicItems)) {
                error_log("Warning: Query returned empty or invalid result for transfer ID $transferId. Expected $itemsCount items.");
                // محاولة جلب العناصر بشكل مختلف
                $basicItems = [];
                try {
                    $stmt = $db->getConnection()->prepare(
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
                         ORDER BY wti.id"
                    );
                    if ($stmt) {
                        $stmt->bind_param("i", $transferId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $basicItems[] = $row;
                        }
                        $stmt->close();
                    }
                } catch (Exception $e) {
                    error_log("Error fetching transfer items: " . $e->getMessage());
                }
            }
            
            // إذا كانت هناك عناصر، نضيف التفاصيل من JOIN
            if (!empty($basicItems) && is_array($basicItems)) {
                $selectedTransfer['items'] = [];
                foreach ($basicItems as $basicItem) {
                $productId = $basicItem['product_id'] ?? null;
                $batchId = $basicItem['batch_id'] ?? null;
                $nameCandidates = [];
                $hasResolvedName = false;

                if (!empty($basicItem['product_name'])) {
                    $nameCandidates[] = $basicItem['product_name'];
                    $hasResolvedName = !isPlaceholderProductName($basicItem['product_name']);
                }
                
                if (!$productId && $batchId) {
                    $batchProductId = $db->queryOne(
                        "SELECT product_id FROM finished_products WHERE id = ?",
                        [$batchId]
                    );
                    if ($batchProductId && !empty($batchProductId['product_id'])) {
                        $productId = (int)$batchProductId['product_id'];
                    }
                }
                
                if ($productId) {
                    $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                    if ($product && isset($product['name'])) {
                        $productNameFromDb = trim($product['name']);
                        if ($productNameFromDb !== '') {
                            $nameCandidates[] = $productNameFromDb;
                            $hasResolvedName = $hasResolvedName || !isPlaceholderProductName($productNameFromDb);
                        }
                    }
                    
                    if (!$hasResolvedName) {
                        $transferInfo = $db->queryOne(
                            "SELECT from_warehouse_id FROM warehouse_transfers WHERE id = ?",
                            [$basicItem['transfer_id']]
                        );
                        if ($transferInfo) {
                            $fromWarehouse = $db->queryOne(
                                "SELECT warehouse_type, vehicle_id FROM warehouses WHERE id = ?",
                                [$transferInfo['from_warehouse_id']]
                            );
                            if ($fromWarehouse && ($fromWarehouse['warehouse_type'] ?? '') === 'vehicle' && !empty($fromWarehouse['vehicle_id'])) {
                                $vehicleProduct = $db->queryOne(
                                    "SELECT product_name FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ?",
                                    [$fromWarehouse['vehicle_id'], $productId]
                                );
                                if ($vehicleProduct && !empty($vehicleProduct['product_name'])) {
                                    $vehicleName = trim($vehicleProduct['product_name']);
                                    if ($vehicleName !== '') {
                                        $nameCandidates[] = $vehicleName;
                                        $hasResolvedName = !isPlaceholderProductName($vehicleName);
                                    }
                                }
                            }
                        }
                    }
                }
                
                if (!$hasResolvedName && $batchId) {
                    $batch = $db->queryOne(
                        "SELECT 
                            fp.product_id,
                            fp.product_name as finished_product_name,
                            COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(fp.product_name), ''), '') as best_name
                         FROM finished_products fp
                         LEFT JOIN products p ON fp.product_id = p.id
                         WHERE fp.id = ?",
                        [$batchId]
                    );
                    
                    if ($batch) {
                        if (!empty($batch['best_name'])) {
                            $bestName = trim($batch['best_name']);
                            if ($bestName !== '') {
                                $nameCandidates[] = $bestName;
                                $hasResolvedName = !isPlaceholderProductName($bestName);
                            }
                        }
                        if (!$hasResolvedName && !empty($batch['finished_product_name'])) {
                            $finishedName = trim($batch['finished_product_name']);
                            if ($finishedName !== '') {
                                $nameCandidates[] = $finishedName;
                                $hasResolvedName = !isPlaceholderProductName($finishedName);
                            }
                        }
                    }
                }

                $productName = resolveProductName($nameCandidates);
                
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
                        
                        $isVehicleSource = (($selectedTransfer['from_warehouse_type'] ?? '') === 'vehicle');
                        $fromWarehouseIdForItem = (int)($selectedTransfer['from_warehouse_id'] ?? 0);
                        $currentTransferId = (int)($selectedTransfer['id'] ?? 0);
                        
                        if ($isVehicleSource && !empty($selectedTransfer['from_vehicle_id'])) {
                            $availableRow = $db->queryOne(
                                "SELECT COALESCE(SUM(quantity), 0) AS qty
                                 FROM vehicle_inventory
                                 WHERE vehicle_id = ? AND finished_batch_id = ?",
                                [$selectedTransfer['from_vehicle_id'], $batchId]
                            );
                            $batchQuantityAvailable = (float)($availableRow['qty'] ?? 0);
                            
                            if ($batchQuantityAvailable <= 0 && !empty($finishedBatchNumber)) {
                                $availableRow = $db->queryOne(
                                    "SELECT COALESCE(SUM(quantity), 0) AS qty
                                     FROM vehicle_inventory
                                     WHERE vehicle_id = ? AND finished_batch_number = ?",
                                    [$selectedTransfer['from_vehicle_id'], $finishedBatchNumber]
                                );
                                $batchQuantityAvailable = (float)($availableRow['qty'] ?? 0);
                            }
                            
                            if ($batchQuantityAvailable <= 0 && !empty($basicItem['product_id'])) {
                                $availableRow = $db->queryOne(
                                    "SELECT COALESCE(SUM(quantity), 0) AS qty
                                     FROM vehicle_inventory
                                     WHERE vehicle_id = ? AND product_id = ?",
                                    [$selectedTransfer['from_vehicle_id'], (int)$basicItem['product_id']]
                                );
                                $batchQuantityAvailable = (float)($availableRow['qty'] ?? 0);
                            }
                            
                            // خصم الطلبات المعلقة الأخرى من نفس المخزن (باستثناء الطلب الحالي)
                            if ($batchQuantityAvailable > 0 && $fromWarehouseIdForItem > 0) {
                                $pending = $db->queryOne(
                                    "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                     FROM warehouse_transfer_items wti
                                     INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                     WHERE wti.batch_id = ?
                                       AND wt.status = 'pending'
                                       AND wt.from_warehouse_id = ?
                                       AND wt.id != ?",
                                    [$batchId, $fromWarehouseIdForItem, $currentTransferId]
                                );
                                $batchQuantityAvailable -= (float)($pending['pending_quantity'] ?? 0);
                            }
                            
                            $batchQuantityAvailable = max(0.0, $batchQuantityAvailable);
                        } else {
                            // حساب الكمية المتاحة من مخزون المصنع / المخزن الرئيسي
                            $availableQuantity = (float)($batchQuantityProduced ?? 0);
                            
                            $transferred = $db->queryOne(
                                "SELECT COALESCE(SUM(wti2.quantity), 0) as total_transferred
                                 FROM warehouse_transfer_items wti2
                                 INNER JOIN warehouse_transfers wt2 ON wt2.id = wti2.transfer_id
                                 WHERE wti2.batch_id = ?
                                   AND wt2.status IN ('approved', 'completed')
                                   AND wt2.from_warehouse_id = ?
                                   AND wti2.id != ?",
                                [$batchId, $fromWarehouseIdForItem, $basicItem['item_id']]
                            );
                            $availableQuantity -= (float)($transferred['total_transferred'] ?? 0);
                            
                            $pendingTransfers = $db->queryOne(
                                "SELECT COALESCE(SUM(wti2.quantity), 0) as pending_quantity
                                 FROM warehouse_transfer_items wti2
                                 INNER JOIN warehouse_transfers wt2 ON wt2.id = wti2.transfer_id
                                 WHERE wti2.batch_id = ?
                                   AND wt2.status = 'pending'
                                   AND wt2.from_warehouse_id = ?
                                   AND wt2.id != ?",
                                [$batchId, $fromWarehouseIdForItem, $currentTransferId]
                            );
                            $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                            
                            $batchQuantityAvailable = max(0.0, $availableQuantity);
                        }
                    }
                }
                
                $selectedTransfer['items'][] = [
                    'item_id' => $basicItem['item_id'],
                    'transfer_id' => $basicItem['transfer_id'],
                    'product_id' => $basicItem['product_id'] ?? null,
                    'batch_id' => $basicItem['batch_id'] ?? null,
                    'batch_number' => $basicItem['batch_number'] ?? null,
                    'quantity' => $basicItem['quantity'] ?? 0,
                    'notes' => $basicItem['notes'] ?? null,
                    'product_name' => $productName,
                    'finished_batch_number' => $finishedBatchNumber,
                    'batch_quantity_produced' => $batchQuantityProduced,
                    'batch_quantity_available' => $batchQuantityAvailable
                ];
                }
            }
        }
        
        // إذا لم تكن هناك عناصر أو فشل جلبها، تسجيل تحذير
        if (empty($selectedTransfer['items']) && $itemsCount > 0) {
            error_log("Warning: Failed to load items for transfer ID $transferId. Database shows $itemsCount items but query returned empty.");
            
            // محاولة جلب العناصر الخام لعرضها كبديل
            try {
                $rawItems = $db->query(
                    "SELECT wti.*, p.name as product_name 
                     FROM warehouse_transfer_items wti
                     LEFT JOIN products p ON wti.product_id = p.id
                     WHERE wti.transfer_id = ?",
                    [$transferId]
                );
                
                if (!empty($rawItems)) {
                    foreach ($rawItems as $rawItem) {
                        $selectedTransfer['items'][] = [
                            'item_id' => $rawItem['id'] ?? null,
                            'transfer_id' => $rawItem['transfer_id'] ?? null,
                            'product_id' => $rawItem['product_id'] ?? null,
                            'batch_id' => $rawItem['batch_id'] ?? null,
                            'batch_number' => $rawItem['batch_number'] ?? null,
                            'quantity' => $rawItem['quantity'] ?? 0,
                            'notes' => $rawItem['notes'] ?? null,
                            'product_name' => $rawItem['product_name'] ?? 'منتج غير معروف',
                            'finished_batch_number' => $rawItem['batch_number'] ?? '-',
                            'batch_quantity_produced' => null,
                            'batch_quantity_available' => null
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Error loading raw items: " . $e->getMessage());
            }
        }
    }
}
?>
<?php if ($warehouseTransfersShowHeading): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right me-2"></i>طلبات النقل بين المخازن</h2>
    <div class="d-flex gap-2">
        <?php if ($isManager): ?>
            <button type="button" class="btn btn-primary rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#transferFromCompanyModal">
                <i class="bi bi-box-arrow-right me-2"></i>نقل من منتجات الشركة
            </button>
            <button type="button" class="btn btn-success rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#transferFromSalesRepModal">
                <i class="bi bi-truck me-2"></i>نقل من بضاعة المندوب
            </button>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="d-flex justify-content-end mb-3 gap-2">
    <?php if ($isManager): ?>
        <button type="button" class="btn btn-primary rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#transferFromCompanyModal">
            <i class="bi bi-box-arrow-right me-2"></i>نقل من منتجات الشركة
        </button>
        <button type="button" class="btn btn-success rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#transferFromSalesRepModal">
            <i class="bi bi-truck me-2"></i>نقل من بضاعة المندوب
        </button>
    <?php endif; ?>
</div>
<?php endif; ?>

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
                                    $displayProductName = $item['product_name'] ?? null;
                                ?>
                                <tr class="<?php echo $badgeClass; ?>">
                                    <td>
                                        <?php 
                                        if (isPlaceholderProductName($displayProductName) && !empty($item['product_id'])) {
                                            $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$item['product_id']]);
                                            if ($product && isset($product['name'])) {
                                                $displayProductName = $product['name'];
                                            }
                                        }
                                        
                                        $cleanProductName = resolveProductName([$displayProductName]);
                                        echo htmlspecialchars($cleanProductName); 
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        // عرض رقم التشغيلة (إن وجد)
                                        if ($displayBatchNumber && $displayBatchNumber !== '-') {
                                            echo htmlspecialchars($displayBatchNumber);
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
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
            <form method="POST" id="rejectTransferForm">
                <input type="hidden" name="action" value="reject_transfer">
                <input type="hidden" name="transfer_id" id="rejectTransferId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="rejection_reason" id="rejectionReason" rows="3" required></textarea>
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

<!-- Modal نقل من منتجات الشركة -->
<div class="modal fade" id="transferFromCompanyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-box-arrow-right me-2"></i>نقل منتجات من مخزن الشركة إلى مخزن السيارة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transferFromCompanyForm">
                <input type="hidden" name="action" value="create_transfer_from_company">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">إلى مخزن السيارة <span class="text-danger">*</span></label>
                        <select class="form-select" name="to_warehouse_id" id="to_warehouse_id" required>
                            <option value="">-- اختر مخزن السيارة --</option>
                            <?php foreach ($vehicleWarehouses as $vWarehouse): ?>
                                <option value="<?php echo $vWarehouse['id']; ?>">
                                    <?php echo htmlspecialchars($vWarehouse['name']); ?>
                                    <?php if (!empty($vWarehouse['vehicle_number'])): ?>
                                        (<?php echo htmlspecialchars($vWarehouse['vehicle_number']); ?>)
                                    <?php endif; ?>
                                    <?php if (!empty($vWarehouse['driver_name'])): ?>
                                        - <?php echo htmlspecialchars($vWarehouse['driver_name']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ النقل <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <hr>
                    <h6 class="mb-3">المنتجات المراد نقلها:</h6>
                    
                    <!-- منتجات المصنع -->
                    <?php if (!empty($companyFactoryProducts)): ?>
                    <div class="mb-4">
                        <h6 class="text-primary mb-2"><i class="bi bi-building me-2"></i>منتجات المصنع</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th width="30px"></th>
                                        <th>رقم التشغيلة</th>
                                        <th>اسم المنتج</th>
                                        <th>المتاح</th>
                                        <th>الكمية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($companyFactoryProducts as $product): ?>
                                        <?php
                                        $availableQty = floatval($product['quantity_produced'] ?? 0);
                                        // حساب الكمية المحجوزة في طلبات النقل المعلقة
                                        $pendingTransfers = $db->queryOne(
                                            "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                             FROM warehouse_transfer_items wti
                                             INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                             WHERE wti.batch_id = ? AND wt.status = 'pending'",
                                            [$product['batch_id']]
                                        );
                                        $availableQty -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                                        $availableQty = max(0, $availableQty);
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input product-checkbox" 
                                                       data-type="factory" 
                                                       data-product-id="<?php echo $product['product_id'] ?? 0; ?>"
                                                       data-batch-id="<?php echo $product['batch_id']; ?>"
                                                       data-batch-number="<?php echo htmlspecialchars($product['batch_number'] ?? ''); ?>"
                                                       data-product-name="<?php echo htmlspecialchars($product['product_name'] ?? ''); ?>"
                                                       data-available="<?php echo $availableQty; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($product['batch_number'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($product['product_name'] ?? 'غير محدد'); ?></td>
                                            <td><?php echo number_format($availableQty, 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></td>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?php echo $availableQty; ?>" 
                                                       class="form-control form-control-sm quantity-input" 
                                                       data-type="factory"
                                                       data-batch-id="<?php echo $product['batch_id']; ?>"
                                                       disabled>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- المنتجات الخارجية -->
                    <?php if (!empty($companyExternalProducts)): ?>
                    <div class="mb-3">
                        <h6 class="text-success mb-2"><i class="bi bi-cart4 me-2"></i>المنتجات الخارجية</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th width="30px"></th>
                                        <th>اسم المنتج</th>
                                        <th>المتاح</th>
                                        <th>الكمية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($companyExternalProducts as $product): ?>
                                        <?php
                                        $availableQty = floatval($product['quantity'] ?? 0);
                                        // حساب الكمية المحجوزة في طلبات النقل المعلقة
                                        $pendingTransfers = $db->queryOne(
                                            "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                             FROM warehouse_transfer_items wti
                                             INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                             WHERE wti.product_id = ? AND wt.status = 'pending'",
                                            [$product['id']]
                                        );
                                        $availableQty -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                                        $availableQty = max(0, $availableQty);
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input product-checkbox" 
                                                       data-type="external" 
                                                       data-product-id="<?php echo $product['id']; ?>"
                                                       data-product-name="<?php echo htmlspecialchars($product['name'] ?? ''); ?>"
                                                       data-available="<?php echo $availableQty; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($product['name'] ?? ''); ?></td>
                                            <td><?php echo number_format($availableQty, 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></td>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?php echo $availableQty; ?>" 
                                                       class="form-control form-control-sm quantity-input" 
                                                       data-type="external"
                                                       data-product-id="<?php echo $product['id']; ?>"
                                                       disabled>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($companyFactoryProducts) && empty($companyExternalProducts)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            لا توجد منتجات متاحة للنقل حالياً.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" id="submitTransferBtn" disabled>
                        <i class="bi bi-check-circle me-2"></i>إنشاء طلب النقل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal نقل من بضاعة المندوب -->
<div class="modal fade" id="transferFromSalesRepModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i>نقل منتجات من بضاعة المندوب إلى المخزن الرئيسي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transferFromSalesRepForm">
                <input type="hidden" name="action" value="create_transfer_from_sales_rep">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المندوب <span class="text-danger">*</span></label>
                        <select class="form-select" name="sales_rep_id" id="sales_rep_id" required>
                            <option value="">-- اختر المندوب --</option>
                            <?php foreach ($salesReps as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>">
                                    <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username']); ?>
                                    <?php if (!empty($rep['vehicle_number'])): ?>
                                        (<?php echo htmlspecialchars($rep['vehicle_number']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ النقل <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">السبب</label>
                        <input type="text" class="form-control" name="reason" placeholder="سبب النقل (اختياري)">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3">المنتجات المراد نقلها:</h6>
                    
                    <div id="salesRepProductsContainer">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            يرجى اختيار المندوب أولاً لعرض المنتجات المتاحة في مخزن سيارته.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success" id="submitSalesRepTransferBtn" disabled>
                        <i class="bi bi-check-circle me-2"></i>إنشاء طلب النقل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal طلب نقل عام بين المخازن -->
<div class="modal fade" id="generalTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>طلب نقل منتجات بين المخازن</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="generalTransferForm">
                <input type="hidden" name="action" value="create_general_transfer">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">من المخزن <span class="text-danger">*</span></label>
                        <?php if ($isSalesRep && $salesRepVehicleWarehouse): ?>
                            <input type="hidden" name="from_warehouse_id" value="<?php echo $salesRepVehicleWarehouse['id']; ?>">
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($salesRepVehicleWarehouse['name']); ?>" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">مخزن سيارة المندوب (غير قابل للتعديل)</small>
                        <?php else: ?>
                            <select class="form-select" name="from_warehouse_id" id="general_from_warehouse_id" required>
                                <option value="">-- اختر المخزن المصدر --</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?php echo $warehouse['id']; ?>">
                                        <?php echo htmlspecialchars($warehouse['name']); ?>
                                        (<?php echo $warehouse['warehouse_type'] === 'main' ? 'رئيسي' : ($warehouse['warehouse_type'] === 'vehicle' ? 'سيارة' : 'أخرى'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">إلى المخزن <span class="text-danger">*</span></label>
                        <select class="form-select" name="to_warehouse_id" id="general_to_warehouse_id" required>
                            <option value="">-- اختر المخزن الهدف --</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?php echo $warehouse['id']; ?>">
                                    <?php echo htmlspecialchars($warehouse['name']); ?>
                                    (<?php echo $warehouse['warehouse_type'] === 'main' ? 'رئيسي' : ($warehouse['warehouse_type'] === 'vehicle' ? 'سيارة' : 'أخرى'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تاريخ النقل <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">السبب</label>
                        <input type="text" class="form-control" name="reason" placeholder="سبب النقل (اختياري)">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3">المنتجات المراد نقلها:</h6>
                    
                    <div id="generalTransferProductsContainer">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <?php if ($isSalesRep): ?>
                                سيتم عرض المنتجات المتاحة في مخزن سيارتك.
                            <?php else: ?>
                                يرجى اختيار المخزن المصدر أولاً لعرض المنتجات المتاحة.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-info" id="submitGeneralTransferBtn" disabled>
                        <i class="bi bi-check-circle me-2"></i>إنشاء طلب النقل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showRejectModal(transferId) {
    const rejectTransferIdInput = document.getElementById('rejectTransferId');
    const rejectionReasonTextarea = document.getElementById('rejectionReason');
    const rejectForm = document.getElementById('rejectTransferForm');
    
    if (rejectTransferIdInput) {
        rejectTransferIdInput.value = transferId;
    }
    
    // إعادة تعيين النموذج
    if (rejectionReasonTextarea) {
        rejectionReasonTextarea.value = '';
    }
    
    // إزالة أي رسائل خطأ سابقة
    if (rejectForm) {
        const existingAlerts = rejectForm.querySelectorAll('.alert');
        existingAlerts.forEach(alert => alert.remove());
    }
    
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
    
    // التركيز على حقل سبب الرفض
    if (rejectionReasonTextarea) {
        setTimeout(() => {
            rejectionReasonTextarea.focus();
        }, 500);
    }
}

// التحقق من صحة النموذج قبل الإرسال
document.addEventListener('DOMContentLoaded', function() {
    const rejectForm = document.getElementById('rejectTransferForm');
    if (rejectForm) {
        rejectForm.addEventListener('submit', function(e) {
            const rejectionReason = document.getElementById('rejectionReason');
            const transferId = document.getElementById('rejectTransferId');
            
            if (!rejectionReason || !rejectionReason.value || rejectionReason.value.trim() === '') {
                e.preventDefault();
                alert('يرجى إدخال سبب الرفض');
                if (rejectionReason) {
                    rejectionReason.focus();
                }
                return false;
            }
            
            if (!transferId || !transferId.value || transferId.value <= 0) {
                e.preventDefault();
                alert('خطأ: معرّف الطلب غير صحيح');
                return false;
            }
        });
    }
});

// إدارة نموذج نقل المنتجات من الشركة
document.addEventListener('DOMContentLoaded', function() {
    const transferModal = document.getElementById('transferFromCompanyModal');
    if (!transferModal) return;
    
    const checkboxes = transferModal.querySelectorAll('.product-checkbox');
    const quantityInputs = transferModal.querySelectorAll('.quantity-input');
    const submitBtn = document.getElementById('submitTransferBtn');
    const form = document.getElementById('transferFromCompanyForm');
    
    // تفعيل/تعطيل حقول الكمية عند اختيار المنتج
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const type = this.dataset.type;
            const identifier = type === 'factory' ? this.dataset.batchId : this.dataset.productId;
            const available = parseFloat(this.dataset.available || 0);
            
            // إيجاد حقول الكمية المرتبطة
            quantityInputs.forEach(input => {
                if (input.dataset.type === type && input.dataset[type === 'factory' ? 'batchId' : 'productId'] == identifier) {
                    input.disabled = !this.checked;
                    if (this.checked) {
                        input.max = available;
                        input.focus();
                    } else {
                        input.value = '';
                    }
                }
            });
            
            updateSubmitButton();
        });
    });
    
    // التحقق من صحة الكمية
    quantityInputs.forEach(input => {
        input.addEventListener('input', function() {
            const max = parseFloat(this.max || 0);
            const value = parseFloat(this.value || 0);
            if (value > max) {
                this.value = max;
                alert('الكمية المحددة تتجاوز الكمية المتاحة');
            }
            updateSubmitButton();
        });
    });
    
    // تحديث حالة زر الإرسال
    function updateSubmitButton() {
        let hasSelectedProducts = false;
        let allQuantitiesValid = true;
        
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                hasSelectedProducts = true;
                const type = checkbox.dataset.type;
                const identifier = type === 'factory' ? checkbox.dataset.batchId : checkbox.dataset.productId;
                const available = parseFloat(checkbox.dataset.available || 0);
                
                // التحقق من وجود كمية صحيحة
                let quantityFound = false;
                quantityInputs.forEach(input => {
                    if (input.dataset.type === type && input.dataset[type === 'factory' ? 'batchId' : 'productId'] == identifier) {
                        const qty = parseFloat(input.value || 0);
                        if (qty > 0 && qty <= available) {
                            quantityFound = true;
                        } else if (qty > available) {
                            allQuantitiesValid = false;
                        }
                    }
                });
                
                if (!quantityFound) {
                    allQuantitiesValid = false;
                }
            }
        });
        
        if (submitBtn) {
            submitBtn.disabled = !hasSelectedProducts || !allQuantitiesValid;
        }
    }
    
    // إعداد البيانات عند الإرسال
    form.addEventListener('submit', function(e) {
        const items = [];
        
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                const type = checkbox.dataset.type;
                const identifier = type === 'factory' ? checkbox.dataset.batchId : checkbox.dataset.productId;
                let quantity = 0;
                
                quantityInputs.forEach(input => {
                    if (input.dataset.type === type && input.dataset[type === 'factory' ? 'batchId' : 'productId'] == identifier) {
                        quantity = parseFloat(input.value || 0);
                    }
                });
                
                if (quantity > 0) {
                    const item = {
                        quantity: quantity
                    };
                    
                    if (type === 'factory') {
                        item.batch_id = checkbox.dataset.batchId;
                        item.batch_number = checkbox.dataset.batchNumber;
                        if (checkbox.dataset.productId && checkbox.dataset.productId != '0') {
                            item.product_id = checkbox.dataset.productId;
                        }
                    } else {
                        item.product_id = checkbox.dataset.productId;
                    }
                    
                    items.push(item);
                }
            }
        });
        
        if (items.length === 0) {
            e.preventDefault();
            alert('يرجى اختيار منتج واحد على الأقل وإدخال الكمية');
            return false;
        }
        
        // إضافة العناصر كحقول مخفية
        items.forEach((item, index) => {
            Object.keys(item).forEach(key => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `items[${index}][${key}]`;
                input.value = item[key];
                form.appendChild(input);
            });
        });
    });
    
    // إعادة تعيين النموذج عند إغلاق الـ modal
    transferModal.addEventListener('hidden.bs.modal', function() {
        checkboxes.forEach(cb => {
            cb.checked = false;
            cb.dispatchEvent(new Event('change'));
        });
        form.querySelectorAll('input[type="hidden"][name^="items"]').forEach(input => input.remove());
    });
});

// معالجة modal نقل من بضاعة المندوب
document.addEventListener('DOMContentLoaded', function() {
    const salesRepSelect = document.getElementById('sales_rep_id');
    const productsContainer = document.getElementById('salesRepProductsContainer');
    
    if (salesRepSelect) {
        salesRepSelect.addEventListener('change', function() {
            const salesRepId = this.value;
            productsContainer.innerHTML = '<div class="text-center py-3"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
            
            if (salesRepId) {
                // جلب المنتجات من مخزن سيارة المندوب
                const currentUrl = window.location.href.split('?')[0];
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('ajax', 'get_vehicle_inventory');
                urlParams.set('vehicle_id', salesRepId);
                
                fetch(currentUrl + '?' + urlParams.toString(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Vehicle inventory data:', data);
                        if (data.success && Array.isArray(data.products)) {
                            if (data.products.length > 0) {
                                displaySalesRepProducts(data.products);
                            } else {
                                productsContainer.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد منتجات في مخزن سيارة هذا المندوب.</div>';
                            }
                        } else {
                            productsContainer.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>' + (data.message || 'لا توجد منتجات في مخزن سيارة هذا المندوب.') + '</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching vehicle inventory:', error);
                        productsContainer.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i>حدث خطأ أثناء جلب المنتجات. يرجى المحاولة مرة أخرى.</div>';
                    });
            } else {
                productsContainer.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>يرجى اختيار المندوب أولاً.</div>';
            }
        });
    }
    
    function displaySalesRepProducts(products) {
        if (products.length === 0) {
            productsContainer.innerHTML = '<div class="alert alert-info">لا توجد منتجات في مخزن سيارة هذا المندوب.</div>';
            return;
        }
        
        let html = '<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">';
        html += '<table class="table table-sm table-bordered">';
        html += '<thead class="table-light sticky-top"><tr>';
        html += '<th width="30px"></th>';
        html += '<th>رقم التشغيلة</th>';
        html += '<th>اسم المنتج</th>';
        html += '<th>المتاح</th>';
        html += '<th>الكمية</th>';
        html += '</tr></thead><tbody>';
        
        products.forEach(product => {
            const availableQty = parseFloat(product.quantity || 0);
            const batchNumber = product.finished_batch_number || product.batch_number || '-';
            const productName = product.product_name || 'غير محدد';
            const unit = product.unit || product.product_unit || 'قطعة';
            const productId = product.product_id || 0;
            const batchId = product.finished_batch_id || product.batch_id || 0;
            
            html += '<tr>';
            html += '<td><input type="checkbox" class="form-check-input product-checkbox-sales" ';
            html += `data-product-id="${productId}" `;
            html += `data-batch-id="${batchId}" `;
            html += `data-batch-number="${batchNumber}" `;
            html += `data-product-name="${productName.replace(/"/g, '&quot;')}" `;
            html += `data-available="${availableQty}"></td>`;
            html += `<td>${batchNumber}</td>`;
            html += `<td>${productName}</td>`;
            html += `<td>${availableQty.toFixed(2)} ${unit}</td>`;
            html += `<td><input type="number" step="0.01" min="0" max="${availableQty}" `;
            html += `class="form-control form-control-sm quantity-input-sales" `;
            html += `data-product-id="${productId}" `;
            html += `data-batch-id="${batchId}" disabled></td>`;
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        productsContainer.innerHTML = html;
        
        // إضافة معالجات الأحداث
        document.querySelectorAll('.product-checkbox-sales').forEach(cb => {
            cb.addEventListener('change', function() {
                const quantityInput = this.closest('tr').querySelector('.quantity-input-sales');
                quantityInput.disabled = !this.checked;
                if (!this.checked) {
                    quantityInput.value = '';
                }
                updateSalesRepSubmitButton();
            });
        });
        
        // إضافة معالجات للكميات
        document.querySelectorAll('.quantity-input-sales').forEach(input => {
            input.addEventListener('input', function() {
                updateSalesRepSubmitButton();
            });
        });
        
        updateSalesRepSubmitButton();
    }
    
    function updateSalesRepSubmitButton() {
        const submitBtn = document.getElementById('submitSalesRepTransferBtn');
        if (!submitBtn) return;
        
        const checkedBoxes = document.querySelectorAll('.product-checkbox-sales:checked');
        let hasValidQuantity = false;
        
        checkedBoxes.forEach(cb => {
            const quantityInput = cb.closest('tr').querySelector('.quantity-input-sales');
            const quantity = parseFloat(quantityInput.value) || 0;
            if (quantity > 0) {
                hasValidQuantity = true;
            }
        });
        
        submitBtn.disabled = checkedBoxes.length === 0 || !hasValidQuantity;
    }
    
    // معالجة إرسال النموذج
    const salesRepForm = document.getElementById('transferFromSalesRepForm');
    if (salesRepForm) {
        salesRepForm.addEventListener('submit', function(e) {
            const checkedBoxes = this.querySelectorAll('.product-checkbox-sales:checked');
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('يرجى اختيار منتج واحد على الأقل.');
                return false;
            }
            
            // إزالة أي حقول hidden سابقة قبل إضافة الجديدة
            this.querySelectorAll('input[type="hidden"][name^="items"]').forEach(input => input.remove());
            
            let itemIndex = 0;
            checkedBoxes.forEach((cb) => {
                const quantityInput = cb.closest('tr').querySelector('.quantity-input-sales');
                const quantity = parseFloat(quantityInput.value) || 0;
                
                if (quantity <= 0) {
                    e.preventDefault();
                    alert('يرجى إدخال كمية صحيحة للمنتج: ' + cb.dataset.productName);
                    return false;
                }
                
                const productId = parseInt(cb.dataset.productId) || 0;
                const batchId = parseInt(cb.dataset.batchId) || 0;
                const batchNumber = cb.dataset.batchNumber || '';
                
                // يجب أن يكون هناك product_id أو batch_id على الأقل
                if (productId <= 0 && batchId <= 0) {
                    e.preventDefault();
                    alert('خطأ في بيانات المنتج: ' + cb.dataset.productName);
                    return false;
                }
                
                const itemPrefix = `items[${itemIndex}]`;
                
                // إضافة product_id فقط إذا كان موجوداً
                if (productId > 0) {
                    const productIdInput = document.createElement('input');
                    productIdInput.type = 'hidden';
                    productIdInput.name = `${itemPrefix}[product_id]`;
                    productIdInput.value = productId;
                    this.appendChild(productIdInput);
                }
                
                // إضافة batch_id فقط إذا كان موجوداً
                if (batchId > 0) {
                    const batchIdInput = document.createElement('input');
                    batchIdInput.type = 'hidden';
                    batchIdInput.name = `${itemPrefix}[batch_id]`;
                    batchIdInput.value = batchId;
                    this.appendChild(batchIdInput);
                }
                
                // إضافة batch_number إذا كان موجوداً
                if (batchNumber && batchNumber !== '-') {
                    const batchNumberInput = document.createElement('input');
                    batchNumberInput.type = 'hidden';
                    batchNumberInput.name = `${itemPrefix}[batch_number]`;
                    batchNumberInput.value = batchNumber;
                    this.appendChild(batchNumberInput);
                }
                
                // إضافة الكمية (مطلوبة دائماً)
                const quantityInputHidden = document.createElement('input');
                quantityInputHidden.type = 'hidden';
                quantityInputHidden.name = `${itemPrefix}[quantity]`;
                quantityInputHidden.value = quantity;
                this.appendChild(quantityInputHidden);
                
                itemIndex++;
            });
        });
        
        // تنظيف النموذج عند إغلاق الـ modal
        const salesRepModal = document.getElementById('transferFromSalesRepModal');
        if (salesRepModal) {
            salesRepModal.addEventListener('hidden.bs.modal', function() {
                salesRepForm.reset();
                productsContainer.innerHTML = '<div class="alert alert-info">يرجى اختيار المندوب أولاً.</div>';
                salesRepForm.querySelectorAll('input[type="hidden"][name^="items"]').forEach(input => input.remove());
            });
        }
    }
});

// إدارة modal النقل العام
document.addEventListener('DOMContentLoaded', function() {
    const generalTransferModal = document.getElementById('generalTransferModal');
    const generalTransferForm = document.getElementById('generalTransferForm');
    const fromWarehouseSelect = document.getElementById('general_from_warehouse_id');
    const toWarehouseSelect = document.getElementById('general_to_warehouse_id');
    const productsContainer = document.getElementById('generalTransferProductsContainer');
    const submitBtn = document.getElementById('submitGeneralTransferBtn');
    
    <?php if ($isSalesRep && $salesRepVehicleWarehouse): ?>
    // إذا كان المستخدم مندوب، جلب المنتجات تلقائياً عند فتح الـ modal
    if (generalTransferModal) {
        generalTransferModal.addEventListener('shown.bs.modal', function() {
            loadGeneralTransferProducts(<?php echo $salesRepVehicleWarehouse['id']; ?>);
        });
    }
    <?php else: ?>
    // إذا كان المستخدم مدير، جلب المنتجات عند اختيار المخزن المصدر
    if (fromWarehouseSelect) {
        fromWarehouseSelect.addEventListener('change', function() {
            const fromWarehouseId = this.value;
            if (fromWarehouseId) {
                loadGeneralTransferProducts(fromWarehouseId);
            } else {
                productsContainer.innerHTML = '<div class="alert alert-info">يرجى اختيار المخزن المصدر أولاً.</div>';
                submitBtn.disabled = true;
            }
        });
    }
    <?php endif; ?>
    
    function loadGeneralTransferProducts(warehouseId) {
        if (!warehouseId) return;
        
        productsContainer.innerHTML = '<div class="text-center py-3"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
        
        // جلب المنتجات من المخزن
        fetch(`?ajax=get_warehouse_products&warehouse_id=${warehouseId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.products && data.products.length > 0) {
                    let html = '<div class="table-responsive" style="max-height: 300px; overflow-y: auto;">';
                    html += '<table class="table table-sm table-bordered">';
                    html += '<thead class="table-light sticky-top"><tr><th width="30px"></th><th>اسم المنتج</th><th>رقم التشغيلة</th><th>المتاح</th><th>الكمية</th></tr></thead>';
                    html += '<tbody>';
                    
                    data.products.forEach(product => {
                        const productId = product.product_id || 0;
                        const batchId = product.batch_id || 0;
                        const batchNumber = product.batch_number || '-';
                        const productName = product.product_name || 'غير محدد';
                        const available = parseFloat(product.quantity || 0);
                        const unit = product.unit || 'قطعة';
                        
                        html += `<tr>
                            <td>
                                <input type="checkbox" class="form-check-input product-checkbox-general" 
                                       data-product-id="${productId}"
                                       data-batch-id="${batchId}"
                                       data-batch-number="${batchNumber}"
                                       data-product-name="${productName}"
                                       data-available="${available}">
                            </td>
                            <td>${productName}</td>
                            <td>${batchNumber}</td>
                            <td>${available.toFixed(2)} ${unit}</td>
                            <td>
                                <input type="number" step="0.01" min="0" max="${available}" 
                                       class="form-control form-control-sm quantity-input-general" 
                                       data-product-id="${productId}"
                                       data-batch-id="${batchId}"
                                       disabled>
                            </td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table></div>';
                    productsContainer.innerHTML = html;
                    
                    // إضافة معالجات الأحداث
                    document.querySelectorAll('.product-checkbox-general').forEach(cb => {
                        cb.addEventListener('change', function() {
                            const quantityInput = this.closest('tr').querySelector('.quantity-input-general');
                            quantityInput.disabled = !this.checked;
                            if (!this.checked) {
                                quantityInput.value = '';
                            }
                            updateGeneralSubmitButton();
                        });
                    });
                    
                    document.querySelectorAll('.quantity-input-general').forEach(input => {
                        input.addEventListener('input', function() {
                            updateGeneralSubmitButton();
                        });
                    });
                    
                    updateGeneralSubmitButton();
                } else {
                    productsContainer.innerHTML = '<div class="alert alert-warning">لا توجد منتجات متاحة في هذا المخزن.</div>';
                    submitBtn.disabled = true;
                }
            })
            .catch(error => {
                console.error('Error loading products:', error);
                productsContainer.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب المنتجات.</div>';
                submitBtn.disabled = true;
            });
    }
    
    function updateGeneralSubmitButton() {
        if (!submitBtn) return;
        
        const checkedBoxes = document.querySelectorAll('.product-checkbox-general:checked');
        let hasValidQuantity = false;
        
        checkedBoxes.forEach(cb => {
            const quantityInput = cb.closest('tr').querySelector('.quantity-input-general');
            const quantity = parseFloat(quantityInput.value) || 0;
            if (quantity > 0) {
                hasValidQuantity = true;
            }
        });
        
        const fromWarehouse = <?php echo $isSalesRep && $salesRepVehicleWarehouse ? $salesRepVehicleWarehouse['id'] : '(fromWarehouseSelect ? fromWarehouseSelect.value : null)'; ?>;
        const toWarehouse = toWarehouseSelect ? toWarehouseSelect.value : null;
        
        submitBtn.disabled = !fromWarehouse || !toWarehouse || checkedBoxes.length === 0 || !hasValidQuantity;
    }
    
    <?php if (!$isSalesRep || !$salesRepVehicleWarehouse): ?>
    if (toWarehouseSelect) {
        toWarehouseSelect.addEventListener('change', updateGeneralSubmitButton);
    }
    <?php endif; ?>
    
    // معالجة إرسال النموذج
    if (generalTransferForm) {
        generalTransferForm.addEventListener('submit', function(e) {
            const checkedBoxes = this.querySelectorAll('.product-checkbox-general:checked');
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('يرجى اختيار منتج واحد على الأقل.');
                return false;
            }
            
            // إزالة أي حقول hidden سابقة
            this.querySelectorAll('input[type="hidden"][name^="items"]').forEach(input => input.remove());
            
            let itemIndex = 0;
            checkedBoxes.forEach((cb) => {
                const quantityInput = cb.closest('tr').querySelector('.quantity-input-general');
                const quantity = parseFloat(quantityInput.value) || 0;
                
                if (quantity <= 0) {
                    e.preventDefault();
                    alert('يرجى إدخال كمية صحيحة للمنتج: ' + cb.dataset.productName);
                    return false;
                }
                
                const productId = parseInt(cb.dataset.productId) || 0;
                const batchId = parseInt(cb.dataset.batchId) || 0;
                const batchNumber = cb.dataset.batchNumber || '';
                
                if (productId <= 0 && batchId <= 0) {
                    e.preventDefault();
                    alert('خطأ في بيانات المنتج: ' + cb.dataset.productName);
                    return false;
                }
                
                const itemPrefix = `items[${itemIndex}]`;
                
                if (productId > 0) {
                    const productIdInput = document.createElement('input');
                    productIdInput.type = 'hidden';
                    productIdInput.name = `${itemPrefix}[product_id]`;
                    productIdInput.value = productId;
                    this.appendChild(productIdInput);
                }
                
                if (batchId > 0) {
                    const batchIdInput = document.createElement('input');
                    batchIdInput.type = 'hidden';
                    batchIdInput.name = `${itemPrefix}[batch_id]`;
                    batchIdInput.value = batchId;
                    this.appendChild(batchIdInput);
                }
                
                if (batchNumber && batchNumber !== '-') {
                    const batchNumberInput = document.createElement('input');
                    batchNumberInput.type = 'hidden';
                    batchNumberInput.name = `${itemPrefix}[batch_number]`;
                    batchNumberInput.value = batchNumber;
                    this.appendChild(batchNumberInput);
                }
                
                const quantityInputHidden = document.createElement('input');
                quantityInputHidden.type = 'hidden';
                quantityInputHidden.name = `${itemPrefix}[quantity]`;
                quantityInputHidden.value = quantity;
                this.appendChild(quantityInputHidden);
                
                itemIndex++;
            });
        });
        
        // تنظيف النموذج عند إغلاق الـ modal
        if (generalTransferModal) {
            generalTransferModal.addEventListener('hidden.bs.modal', function() {
                generalTransferForm.reset();
                productsContainer.innerHTML = '<div class="alert alert-info"><?php echo $isSalesRep ? "سيتم عرض المنتجات المتاحة في مخزن سيارتك." : "يرجى اختيار المخزن المصدر أولاً لعرض المنتجات المتاحة."; ?></div>';
                generalTransferForm.querySelectorAll('input[type="hidden"][name^="items"]').forEach(input => input.remove());
                submitBtn.disabled = true;
            });
        }
    }
});
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


