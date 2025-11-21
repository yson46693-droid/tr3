<?php
/**
 * صفحة المنتجات النهائية - تعرض ما تم إنتاجه من خطوط الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/vehicle_inventory.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$isManager = isset($currentUser['role']) && $currentUser['role'] === 'manager';
$managerInventoryUrl = getRelativeUrl('manager.php?page=final_products');
$productionInventoryUrl = getRelativeUrl('production.php?page=inventory');
$finalProductsUrl = getRelativeUrl('production.php?page=final_products');
$error = '';
$success = '';

if (!function_exists('productionSafeRedirect')) {
    function productionSafeRedirect(string $url, array $redirectParams = [], ?string $role = null): void
    {
        $redirectUrl = (!empty($redirectParams) || $role !== null) ? null : $url;
        preventDuplicateSubmission(null, $redirectParams, $redirectUrl, $role);
    }
}

$sessionErrorKey = 'production_inventory_error';
$sessionSuccessKey = 'production_inventory_success';

// إنشاء token لمنع duplicate submission
if (!isset($_SESSION['transfer_submission_token'])) {
    $_SESSION['transfer_submission_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION[$sessionErrorKey])) {
    $error = $_SESSION[$sessionErrorKey];
    unset($_SESSION[$sessionErrorKey]);
}

if (!empty($_SESSION[$sessionSuccessKey])) {
    $success = $_SESSION[$sessionSuccessKey];
    unset($_SESSION[$sessionSuccessKey]);
}

// Ensure new columns for external products
try {
    $productTypeColumn = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
    if (empty($productTypeColumn)) {
        $db->execute("ALTER TABLE `products` ADD COLUMN `product_type` ENUM('internal','external') DEFAULT 'internal' AFTER `category`");
        $db->execute("UPDATE products SET product_type = 'internal' WHERE product_type IS NULL OR product_type = ''");
    }
} catch (Exception $e) {
    error_log('final_products: failed ensuring product_type column -> ' . $e->getMessage());
}

try {
    $externalChannelColumn = $db->queryOne("SHOW COLUMNS FROM products LIKE 'external_channel'");
    if (empty($externalChannelColumn)) {
        $db->execute("ALTER TABLE `products` ADD COLUMN `external_channel` ENUM('company','delegate','other') DEFAULT NULL AFTER `product_type`");
    }
} catch (Exception $e) {
    error_log('final_products: failed ensuring external_channel column -> ' . $e->getMessage());
}

$currentPageSlug = $_GET['page'] ?? 'inventory';
$currentSection = $_GET['section'] ?? null;
$baseQueryString = '?page=' . urlencode($currentPageSlug);
if ($currentSection !== null && $currentSection !== '') {
    $baseQueryString .= '&section=' . urlencode($currentSection);
}

$productionRedirectParams = [
    'page' => $currentPageSlug ?: 'inventory',
];
if ($currentSection !== null && $currentSection !== '') {
    $productionRedirectParams['section'] = $currentSection;
}
$productionRedirectRole = $currentUser['role'] ?? 'production';

$managerRedirectParams = [
    'page' => 'final_products',
];
if ($currentSection !== null && $currentSection !== '') {
    $managerRedirectParams['section'] = $currentSection;
}
$managerRedirectRole = 'manager';

// معالجة AJAX لجلب المنتجات من مخزن سيارة المندوب
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_vehicle_inventory' && isset($_GET['vehicle_id'])) {
    header('Content-Type: application/json');
    $salesRepId = intval($_GET['vehicle_id']);
    
    // الحصول على سيارة المندوب
    $vehicle = $db->queryOne(
        "SELECT v.id as vehicle_id FROM vehicles v WHERE v.driver_id = ? AND v.status = 'active'",
        [$salesRepId]
    );
    
    if (!$vehicle) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على سيارة للمندوب']);
        exit;
    }
    
    $vehicleId = $vehicle['vehicle_id'];
    $inventory = getVehicleInventory($vehicleId);
    
    // تحويل البيانات إلى تنسيق مناسب
    $products = [];
    foreach ($inventory as $item) {
        $products[] = [
            'product_id' => $item['product_id'] ?? 0,
            'batch_id' => $item['finished_batch_id'] ?? 0,
            'batch_number' => $item['finished_batch_number'] ?? $item['batch_number'] ?? '',
            'product_name' => $item['product_name'] ?? 'غير محدد',
            'quantity' => floatval($item['quantity'] ?? 0),
            'unit' => $item['unit'] ?? $item['product_unit'] ?? 'قطعة'
        ];
    }
    
    echo json_encode(['success' => true, 'products' => $products]);
    exit;
}

// معالجة طلبات AJAX لتحميل المنتجات
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_products') {
    // تنظيف أي output buffer موجود
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
    
    try {
        // التأكد من تحميل الدوال المطلوبة
        if (!function_exists('getFinishedProductBatchOptions')) {
            require_once __DIR__ . '/../../includes/vehicle_inventory.php';
        }
        
        $products = getFinishedProductBatchOptions(true, $warehouseId);
        echo json_encode([
            'success' => true,
            'products' => $products
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

/**
 * دالة البحث عن كمية تشغيلة فعلية من جدول batch_numbers
 * @param string $batchNumber رقم التشغيلة
 * @return float|null الكمية الفعلية أو null إذا لم يتم العثور على التشغيلة
 */
if (!function_exists('getBatchActualQuantity')) {
    function getBatchActualQuantity($batchNumber) {
        if (empty($batchNumber) || !is_string($batchNumber)) {
            return null;
        }
        
        try {
            $db = db();
            
            // التحقق من وجود جدول batch_numbers
            $tableExists = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
            if (empty($tableExists)) {
                error_log('getBatchActualQuantity: batch_numbers table does not exist');
                return null;
            }
            
            // البحث عن الكمية الفعلية للتشغيلة
            $batch = $db->queryOne(
                "SELECT `quantity` 
                 FROM `batch_numbers` 
                 WHERE `batch_number` = ? 
                 ORDER BY `batch_numbers`.`quantity` ASC 
                 LIMIT 1",
                [trim($batchNumber)]
            );
            
            if ($batch && isset($batch['quantity'])) {
                $quantity = floatval($batch['quantity']);
                return $quantity > 0 ? $quantity : null;
            }
            
            return null;
        } catch (Exception $e) {
            error_log('getBatchActualQuantity error: ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * دالة تحديث product_id في finished_products من batch_numbers
 * لحل مشكلة التضارب عندما يكون product_id في finished_products = null
 * @return int عدد السجلات المحدثة
 */
if (!function_exists('syncFinishedProductsProductId')) {
    function syncFinishedProductsProductId() {
        try {
            $db = db();
            
            // التحقق من وجود الجداول
            $finishedExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
            $batchExists = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
            
            if (empty($finishedExists) || empty($batchExists)) {
                return 0;
            }
            
            // تحديث product_id في finished_products من batch_numbers بناءً على batch_number
            $result = $db->execute(
                "UPDATE finished_products fp
                 INNER JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                 SET fp.product_id = bn.product_id
                 WHERE fp.product_id IS NULL 
                   AND bn.product_id IS NOT NULL 
                   AND bn.product_id > 0"
            );
            
            $updated = $result['affected_rows'] ?? 0;
            
            if ($updated > 0) {
                error_log("syncFinishedProductsProductId: Updated {$updated} records in finished_products");
            }
            
            return $updated;
        } catch (Exception $e) {
            error_log('syncFinishedProductsProductId error: ' . $e->getMessage());
            return 0;
        }
    }
}

// معالجة تحديث السعر اليدوي للمنتجات النهائية

// مزامنة product_id من batch_numbers إلى finished_products لحل التضارب
syncFinishedProductsProductId();

// التحقق من وجود عمود date أو production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

// التحقق من وجود عمود user_id أو worker_id
$userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
$workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
$hasUserIdColumn = !empty($userIdColumnCheck);
$hasWorkerIdColumn = !empty($workerIdColumnCheck);
$userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

// معالجة طلبات AJAX لتفاصيل المنتج قبل أي إخراج
if (isset($_GET['ajax']) && $_GET['ajax'] === 'product_details') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'معرف المنتج غير صالح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $whereDetails = ["p.product_id = ?", "(p.status = 'completed' OR p.status = 'approved')"];
        $detailsParams = [$productId];

        $detailsSql = "SELECT 
                            p.id,
                            p.quantity,
                            p.$dateColumn as production_date,
                            p.created_at,
                            p.status" .
                        ($userIdColumn
                            ? ", u.full_name as worker_name, u.username as worker_username"
                            : ", 'غير محدد' as worker_name, 'غير محدد' as worker_username"
                        ) .
                        " FROM production p";

        if ($userIdColumn) {
            $detailsSql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
        }

        $detailsSql .= " WHERE " . implode(' AND ', $whereDetails) . "
                         ORDER BY p.$dateColumn ASC";

        $details = $db->query($detailsSql, $detailsParams);

        $statusLabels = [
            'pending' => 'معلق',
            'approved' => 'موافق عليه',
            'completed' => 'مكتمل',
            'rejected' => 'مرفوض'
        ];

        $formattedDetails = array_map(function($detail) use ($statusLabels) {
            return [
                'id' => $detail['id'],
                'quantity' => $detail['quantity'],
                'date' => formatDate($detail['production_date'] ?? $detail['created_at']),
                'worker' => $detail['worker_name'] ?? $detail['worker_username'] ?? 'غير محدد',
                'status' => $detail['status'],
                'status_text' => $statusLabels[$detail['status']] ?? $detail['status']
            ];
        }, $details ?? []);

        echo json_encode([
            'success' => true,
            'details' => $formattedDetails
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('Final products AJAX error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء تحميل تفاصيل المنتج'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// التحقق من وجود جدول production_lines وإنشاءه إذا لم يكن موجوداً
$lineTableCheck = $db->queryOne("SHOW TABLES LIKE 'production_lines'");
if (empty($lineTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `production_lines` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `line_name` varchar(100) NOT NULL,
              `product_id` int(11) NOT NULL,
              `target_quantity` decimal(10,2) DEFAULT 0.00,
              `priority` enum('low','medium','high') DEFAULT 'medium',
              `start_date` date DEFAULT NULL,
              `end_date` date DEFAULT NULL,
              `worker_id` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `notes` text DEFAULT NULL,
              `status` enum('pending','active','completed','paused','cancelled') DEFAULT 'pending',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `product_id` (`product_id`),
              KEY `worker_id` (`worker_id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`),
              CONSTRAINT `production_lines_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
              CONSTRAINT `production_lines_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `production_lines_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating production_lines table: " . $e->getMessage());
    }
}

// التحقق من وجود عمود production_line_id في جدول production
$productionLineIdColumn = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_line_id'");
if (empty($productionLineIdColumn)) {
    try {
        $db->execute("ALTER TABLE `production` ADD COLUMN `production_line_id` int(11) DEFAULT NULL COMMENT 'خط الإنتاج'");
        $db->execute("ALTER TABLE `production` ADD KEY `production_line_id` (`production_line_id`)");
        $db->execute("ALTER TABLE `production` ADD CONSTRAINT `production_ibfk_line` FOREIGN KEY (`production_line_id`) REFERENCES `production_lines` (`id`) ON DELETE SET NULL");
    } catch (Exception $e) {
        error_log("Error adding production_line_id column: " . $e->getMessage());
    }
}

$warehousesTableExists = $db->queryOne("SHOW TABLES LIKE 'warehouses'");
$primaryWarehouse = null;
$transferWarehouses = [];
$destinationWarehouses = [];
$finishedProductOptions = [];
$hasDestinationWarehouses = false;
$hasFinishedBatches = false;
$canCreateTransfers = false;

if (!empty($warehousesTableExists)) {
    try {
        $primaryWarehouse = $db->queryOne(
            "SELECT id, name, location, description, warehouse_type, status
             FROM warehouses
             WHERE warehouse_type = 'main'
             ORDER BY status = 'active' DESC, id ASC
             LIMIT 1"
        );

        if (!$primaryWarehouse) {
            $db->execute(
                "INSERT INTO warehouses (name, location, description, warehouse_type, status)
                 VALUES (?, ?, ?, 'main', 'active')",
                [
                    'المخزن الرئيسي',
                    'الموقع الرئيسي للشركة',
                    'تم إنشاء هذا المخزن تلقائياً كمخزن رئيسي للنظام'
                ]
            );

            $primaryWarehouseId = $db->getLastInsertId();
            $primaryWarehouse = $db->queryOne(
                "SELECT id, name, location, description, warehouse_type, status
                 FROM warehouses
                 WHERE id = ?",
                [$primaryWarehouseId]
            );

            if (!empty($currentUser['id'])) {
                logAudit($currentUser['id'], 'create_warehouse', 'warehouse', $primaryWarehouseId, null, [
                    'auto_created' => true,
                    'source' => 'production_inventory'
                ]);
            }
        }

        if ($primaryWarehouse) {
            $productsWithoutWarehouse = $db->queryOne(
                "SELECT COUNT(*) as total FROM products WHERE warehouse_id IS NULL AND (product_type IS NULL OR product_type = 'internal')"
            );

            if (($productsWithoutWarehouse['total'] ?? 0) > 0) {
                $db->execute(
                    "UPDATE products SET warehouse_id = ? WHERE warehouse_id IS NULL AND (product_type IS NULL OR product_type = 'internal')",
                    [$primaryWarehouse['id']]
                );
            }
        }

        $transferWarehouses = $db->query(
            "SELECT id, name, warehouse_type, status
             FROM warehouses
             WHERE status = 'active'
             ORDER BY (id = ?) DESC, warehouse_type ASC, name ASC",
            [$primaryWarehouse['id'] ?? 0]
        );

        if (is_array($transferWarehouses)) {
            foreach ($transferWarehouses as $warehouse) {
                if ($primaryWarehouse && intval($warehouse['id']) === intval($primaryWarehouse['id'])) {
                    continue;
                }
                $destinationWarehouses[] = $warehouse;
            }
        }

        $finishedProductOptions = [];
        if ($primaryWarehouse && !empty($primaryWarehouse['id'])) {
            $finishedProductOptions = getFinishedProductBatchOptions(true, $primaryWarehouse['id']);
        }
        $hasDestinationWarehouses = !empty($destinationWarehouses);
        $hasFinishedBatches = !empty($finishedProductOptions);

        $canCreateTransfers = !empty($primaryWarehouse) && $hasDestinationWarehouses && $hasFinishedBatches;
    } catch (Exception $warehouseException) {
        error_log('Production inventory warehouse setup error: ' . $warehouseException->getMessage());
    }
}

// الحصول على قائمة المندوبين (sales reps) الذين لديهم سيارات
$salesReps = [];
try {
    $salesReps = $db->query(
        "SELECT DISTINCT u.id, u.full_name, u.username, v.id as vehicle_id, v.vehicle_number
         FROM users u
         INNER JOIN vehicles v ON v.driver_id = u.id
         WHERE u.role = 'sales' AND u.status = 'active' AND v.status = 'active'
         ORDER BY u.full_name ASC"
    );
} catch (Exception $e) {
    error_log('Error fetching sales reps: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_transfer') {
        // التحقق من duplicate submission باستخدام session token
        $submissionToken = $_POST['transfer_token'] ?? '';
        $sessionTokenKey = 'transfer_submission_token';
        $storedToken = $_SESSION[$sessionTokenKey] ?? null;
        
        if ($submissionToken === '' || $submissionToken !== $storedToken) {
            // إما لم يتم إرسال token أو token غير صحيح (duplicate submission)
            $_SESSION[$sessionErrorKey] = 'تم إرسال هذا الطلب مسبقاً. يرجى عدم إعادة تحميل الصفحة.';
            productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
            exit;
        }
        
        // حذف token فوراً بعد التحقق منه لمنع الإرسال المزدوج
        unset($_SESSION[$sessionTokenKey]);
        
        $transferErrors = [];

        if (!$canCreateTransfers || !$primaryWarehouse) {
            $transferErrors[] = 'لا يمكن إنشاء طلب النقل حالياً بسبب عدم توفر مخزن رئيسي أو مخازن وجهة نشطة.';
        } else {
            $fromWarehouseId = intval($primaryWarehouse['id']);
            $toWarehouseId = intval($_POST['to_warehouse_id'] ?? 0);
            $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
            $reason = trim((string)($_POST['reason'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($toWarehouseId <= 0) {
                $transferErrors[] = 'يرجى اختيار المخزن الوجهة.';
            }

            if ($toWarehouseId === $fromWarehouseId) {
                $transferErrors[] = 'لا يمكن النقل من وإلى نفس المخزن.';
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
                $transferDate = date('Y-m-d');
            }

            $rawItems = $_POST['items'] ?? [];
            $transferItems = [];

            if (is_array($rawItems)) {
                foreach ($rawItems as $item) {
                    $productId = isset($item['product_id']) ? intval($item['product_id']) : 0;
                    $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0.0;
                    $itemNotes = isset($item['notes']) ? trim((string)$item['notes']) : null;
                    $batchId = isset($item['batch_id']) ? intval($item['batch_id']) : 0;
                    $batchNumber = isset($item['batch_number']) ? trim((string)$item['batch_number']) : '';

                    if ($quantity > 0 && ($productId > 0 || $batchId > 0)) {
                        $transferItems[] = [
                            'product_id' => $productId > 0 ? $productId : null,
                            'batch_id' => $batchId > 0 ? $batchId : null,
                            'batch_number' => $batchNumber !== '' ? $batchNumber : null,
                            'quantity' => round($quantity, 4),
                            'notes' => $itemNotes !== '' ? $itemNotes : null,
                        ];
                    }
                }
            }

            if (empty($transferItems)) {
                $transferErrors[] = 'أضف منتجاً واحداً على الأقل مع كمية صالحة.';
            }

            if (empty($transferErrors)) {
                // محاولة تعيين معرف المنتج تلقائياً للتشغيلات المختارة إن لم يتم إرساله من الواجهة
                $resolveBatchIds = [];
                foreach ($transferItems as $transferItem) {
                    $productId = isset($transferItem['product_id']) ? (int)$transferItem['product_id'] : 0;
                    $batchId = isset($transferItem['batch_id']) ? (int)$transferItem['batch_id'] : 0;
                    if ($productId <= 0 && $batchId > 0) {
                        $resolveBatchIds[$batchId] = $batchId;
                    }
                }

                $batchProductMap = [];
                if (!empty($resolveBatchIds)) {
                    $placeholders = implode(',', array_fill(0, count($resolveBatchIds), '?'));
                    $batchRows = $db->query(
                        "SELECT id, product_id FROM finished_products WHERE id IN ($placeholders)",
                        array_values($resolveBatchIds)
                    );
                    foreach ($batchRows as $batchRow) {
                        $batchId = (int)($batchRow['id'] ?? 0);
                        $productId = (int)($batchRow['product_id'] ?? 0);
                        if ($batchId > 0 && $productId > 0) {
                            $batchProductMap[$batchId] = $productId;
                        }
                    }
                }

                foreach ($transferItems as &$transferItem) {
                    $productId = isset($transferItem['product_id']) ? (int)$transferItem['product_id'] : 0;
                    if ($productId > 0) {
                        continue;
                    }
                    $batchId = isset($transferItem['batch_id']) ? (int)$transferItem['batch_id'] : 0;
                    if ($batchId > 0 && isset($batchProductMap[$batchId])) {
                        $transferItem['product_id'] = $batchProductMap[$batchId];
                        continue;
                    }
                    if ($batchId <= 0) {
                        $transferErrors[] = 'المنتج المحدد غير صالح للنقل.';
                        break;
                    }

                    // إبقاء المنتج بدون معرف، وسيتم ربطه لاحقاً أثناء إنشاء طلب النقل
                    $transferItem['product_id'] = null;
                }
                unset($transferItem);

                if (empty($transferErrors)) {
                    // حساب الكمية المتاحة لكل منتج/تشغيلة
                    $availabilityMap = [];
                    
                    foreach ($transferItems as $transferItem) {
                        $productId = isset($transferItem['product_id']) ? (int)$transferItem['product_id'] : 0;
                        $batchId = isset($transferItem['batch_id']) ? (int)($transferItem['batch_id'] ?? 0) : 0;
                        
                        if ($productId <= 0 && $batchId <= 0) {
                            continue;
                        }
                        
                        $availableQuantity = 0.0;
                        $productName = 'منتج غير معروف';
                        
                        // إذا كان هناك batch_id، نستخدم quantity_produced من finished_products أولاً
                        // لأن الكمية الفعلية للمنتجات النهائية موجودة في finished_products وليس في products
                        if ($batchId > 0) {
                            $finishedRow = $db->queryOne(
                                "SELECT fp.quantity_produced, 
                                        COALESCE(p.name, fp.product_name) as product_name,
                                        fp.product_id
                                 FROM finished_products fp
                                 LEFT JOIN products p ON fp.product_id = p.id
                                 WHERE fp.id = ?",
                                [$batchId]
                            );
                            
                            if ($finishedRow) {
                                $availableQuantity = (float)($finishedRow['quantity_produced'] ?? 0);
                                $productName = $finishedRow['product_name'] ?? 'منتج غير محدد';
                                
                                // إذا لم يكن هناك product_id، نستخدم product_id من finished_products
                                if ($productId <= 0 && !empty($finishedRow['product_id'])) {
                                    $productId = (int)$finishedRow['product_id'];
                                }
                                
                                // خصم الكمية المنقولة بالفعل (approved أو completed)
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
                                
                                // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                                $pendingTransfers = $db->queryOne(
                                    "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                     FROM warehouse_transfer_items wti
                                     INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                     WHERE wti.batch_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending'",
                                    [$batchId, $fromWarehouseId]
                                );
                                $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                                
                                $availabilityMap[$productId . '_' . $batchId] = [
                                    'product_id' => $productId,
                                    'batch_id' => $batchId,
                                    'available' => max(0, $availableQuantity),
                                    'name' => $productName
                                ];
                            } else {
                                // إذا لم يكن هناك batch، نستخدم products.quantity كبديل
                                if ($productId > 0) {
                                    $productRow = $db->queryOne(
                                        "SELECT id, name, quantity FROM products WHERE id = ? AND (product_type IS NULL OR product_type = 'internal')",
                                        [$productId]
                                    );
                                    
                                    if ($productRow) {
                                        $availableQuantity = (float)($productRow['quantity'] ?? 0);
                                        $productName = $productRow['name'] ?? '';
                                        
                                        // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                                        $pendingTransfers = $db->queryOne(
                                            "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                             FROM warehouse_transfer_items wti
                                             INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                             WHERE wti.product_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending'",
                                            [$productId, $fromWarehouseId]
                                        );
                                        $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                                        
                                        $availabilityMap[$productId . '_' . $batchId] = [
                                            'product_id' => $productId,
                                            'batch_id' => $batchId,
                                            'available' => max(0, $availableQuantity),
                                            'name' => $productName
                                        ];
                                    }
                                }
                            }
                        } elseif ($productId > 0) {
                            // إذا لم يكن هناك batch_id، نستخدم products.quantity
                            $productRow = $db->queryOne(
                                "SELECT id, name, quantity FROM products WHERE id = ? AND (product_type IS NULL OR product_type = 'internal')",
                                [$productId]
                            );
                            
                            if ($productRow) {
                                $availableQuantity = (float)($productRow['quantity'] ?? 0);
                                $productName = $productRow['name'] ?? '';
                                
                                // خصم الكمية المحجوزة في طلبات النقل المعلقة (pending) من نفس المخزن
                                $pendingTransfers = $db->queryOne(
                                    "SELECT COALESCE(SUM(wti.quantity), 0) AS pending_quantity
                                     FROM warehouse_transfer_items wti
                                     INNER JOIN warehouse_transfers wt ON wt.id = wti.transfer_id
                                     WHERE wti.product_id = ? AND wt.from_warehouse_id = ? AND wt.status = 'pending'",
                                    [$productId, $fromWarehouseId]
                                );
                                $availableQuantity -= (float)($pendingTransfers['pending_quantity'] ?? 0);
                                
                                $availabilityMap[$productId . '_' . $batchId] = [
                                    'product_id' => $productId,
                                    'batch_id' => $batchId,
                                    'available' => max(0, $availableQuantity),
                                    'name' => $productName
                                ];
                            }
                        }
                    }
                    
                    // التحقق من الكميات المتاحة
                    foreach ($transferItems as $transferItem) {
                        $productId = isset($transferItem['product_id']) ? (int)$transferItem['product_id'] : 0;
                        $batchId = isset($transferItem['batch_id']) ? (int)($transferItem['batch_id'] ?? 0) : 0;
                        $requestedQuantity = (float)($transferItem['quantity'] ?? 0);
                        
                        if ($productId <= 0 && $batchId <= 0) {
                            $transferErrors[] = 'المنتج المحدد غير صالح للنقل.';
                            break;
                        }
                        
                        $key = $productId . '_' . $batchId;
                        if (!isset($availabilityMap[$key])) {
                            $transferErrors[] = 'المنتج المحدد غير موجود في المخزن الرئيسي.';
                            break;
                        }
                        
                        $available = $availabilityMap[$key]['available'];
                        $productName = $availabilityMap[$key]['name'];
                        
                        if ($requestedQuantity > $available + 0.0001) { // إضافة هامش صغير للأخطاء العشرية
                            $transferErrors[] = sprintf(
                                'الكمية المطلوبة للمنتج "%s" (%.2f) غير متاحة في المخزون الحالي. المتاح: %.2f',
                                $productName,
                                $requestedQuantity,
                                $available
                            );
                            break;
                        }
                    }
                }
            }

            if (empty($transferErrors)) {
                $result = createWarehouseTransfer(
                    $fromWarehouseId,
                    $toWarehouseId,
                    $transferDate,
                    array_map(static function ($item) {
                        return [
                            'product_id' => $item['product_id'],
                            'batch_id' => $item['batch_id'] ?? null,
                            'batch_number' => $item['batch_number'] ?? null,
                            'quantity' => $item['quantity'],
                            'notes' => $item['notes'] ?? null,
                        ];
                    }, $transferItems),
                    $reason !== '' ? $reason : null,
                    $notes !== '' ? $notes : null,
                    $currentUser['id'] ?? null
                );

                // التحقق من نجاح العملية - التحقق أولاً من وجود transfer_id و transfer_number
                $transferId = $result['transfer_id'] ?? null;
                $transferNumber = $result['transfer_number'] ?? null;
                
                // التحقق من إدراج العناصر بشكل صحيح
                if (!empty($transferId)) {
                    $insertedItemsCheck = $db->queryOne(
                        "SELECT COUNT(*) as count FROM warehouse_transfer_items WHERE transfer_id = ?",
                        [$transferId]
                    );
                    $insertedCount = (int)($insertedItemsCheck['count'] ?? 0);
                    $expectedCount = count($transferItems);
                    
                    error_log("Transfer created - ID: $transferId, Items inserted: $insertedCount out of $expectedCount");
                    
                    // إذا لم يتم إدراج العناصر، نحاول إدراجها يدوياً
                    if ($insertedCount === 0 && !empty($transferItems)) {
                        error_log("WARNING: No items were inserted for transfer ID $transferId! Attempting manual insertion...");
                        
                        foreach ($transferItems as $item) {
                            $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
                            $batchId = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
                            $quantity = (float)($item['quantity'] ?? 0);
                            $batchNumber = isset($item['batch_number']) ? trim((string)$item['batch_number']) : null;
                            $itemNotes = isset($item['notes']) ? trim((string)$item['notes']) : null;
                            
                            if ($quantity <= 0) {
                                continue;
                            }
                            
                            // إذا لم يكن هناك product_id، نحاول الحصول عليه من batch_id
                            if ($productId <= 0 && $batchId > 0) {
                                $batchRow = $db->queryOne("SELECT product_id FROM finished_products WHERE id = ?", [$batchId]);
                                if ($batchRow && !empty($batchRow['product_id'])) {
                                    $productId = (int)$batchRow['product_id'];
                                }
                            }
                            
                            // إذا لم يكن هناك product_id، نحاول إنشاؤه من batch
                            if ($productId <= 0 && $batchId > 0) {
                                $batchRow = $db->queryOne(
                                    "SELECT product_name FROM finished_products WHERE id = ?",
                                    [$batchId]
                                );
                                if ($batchRow && !empty($batchRow['product_name'])) {
                                    $batchProductName = trim($batchRow['product_name']);
                                    $existingProduct = $db->queryOne(
                                        "SELECT id FROM products WHERE name = ? LIMIT 1",
                                        [$batchProductName]
                                    );
                                    if ($existingProduct && !empty($existingProduct['id'])) {
                                        $productId = (int)$existingProduct['id'];
                                    } else {
                                        // إنشاء منتج جديد
                                        $productTypeColumnExists = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                                        $productTypeColumnExists = !empty($productTypeColumnExists);
                                        
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
                                        $productId = (int)($insertResult['insert_id'] ?? 0);
                                    }
                                }
                            }
                            
                            // إذا كان هناك product_id الآن، نقوم بإدراج العنصر
                            if ($productId > 0) {
                                try {
                                    $db->execute(
                                        "INSERT INTO warehouse_transfer_items (transfer_id, product_id, batch_id, batch_number, quantity, notes)
                                         VALUES (?, ?, ?, ?, ?, ?)",
                                        [
                                            $transferId,
                                            $productId,
                                            $batchId ?: null,
                                            $batchNumber ?: null,
                                            $quantity,
                                            $itemNotes ?: null
                                        ]
                                    );
                                    error_log("Manually inserted item for transfer ID $transferId: product_id=$productId, batch_id=" . ($batchId ?? 'NULL') . ", quantity=$quantity");
                                } catch (Exception $insertError) {
                                    error_log("Error manually inserting item for transfer ID $transferId: " . $insertError->getMessage());
                                }
                            } else {
                                error_log("WARNING: Could not determine product_id for item in transfer ID $transferId. Batch ID: " . ($batchId ?? 'NULL'));
                            }
                        }
                        
                        // التحقق مرة أخرى بعد الإدراج اليدوي
                        $finalItemsCheck = $db->queryOne(
                            "SELECT COUNT(*) as count FROM warehouse_transfer_items WHERE transfer_id = ?",
                            [$transferId]
                        );
                        $finalCount = (int)($finalItemsCheck['count'] ?? 0);
                        error_log("After manual insertion - Transfer ID $transferId has $finalCount items");
                    }
                }
                
                // التحقق من قاعدة البيانات للتأكد من وجود الطلب فعلياً
                // حتى لو كان success => false، نتحقق من قاعدة البيانات
                $verifyTransfer = null;
                if (!empty($transferId)) {
                    $verifyTransfer = $db->queryOne(
                        "SELECT id, transfer_number FROM warehouse_transfers WHERE id = ?",
                        [$transferId]
                    );
                } elseif (!empty($transferNumber)) {
                    // إذا لم يكن هناك transfer_id، نحاول البحث بـ transfer_number
                    $verifyTransfer = $db->queryOne(
                        "SELECT id, transfer_number FROM warehouse_transfers WHERE transfer_number = ?",
                        [$transferNumber]
                    );
                }
                
                if ($verifyTransfer) {
                    // الطلب موجود في قاعدة البيانات - نجح الإنشاء فعلياً
                    $transferId = (int)$verifyTransfer['id'];
                    $transferNumber = $verifyTransfer['transfer_number'];
                    error_log("Transfer verified in database: ID=$transferId, Number=$transferNumber");
                } else {
                    // الطلب غير موجود - نستخدم القيم من النتيجة إذا كانت موجودة
                    if (empty($transferId) && empty($transferNumber)) {
                        // لا توجد قيم على الإطلاق - فشل حقيقي
                        error_log("No transfer ID or number in result: " . json_encode($result));
                    } else {
                        // قد يكون هناك تأخير في قاعدة البيانات - نستخدم القيم من النتيجة
                        error_log("Transfer not found in database but ID/Number exists in result. ID=$transferId, Number=$transferNumber");
                    }
                }
                
                // إذا كان الطلب تم إنشاؤه بنجاح (يوجد transfer_id و transfer_number)
                if (!empty($transferId) && !empty($transferNumber)) {
                    $isManagerInitiator = isset($currentUser['role']) && $currentUser['role'] === 'manager';

                    if ($isManagerInitiator && !empty($transferId)) {
                        try {
                            ensureWarehouseTransferBatchColumns();
                            
                            foreach ($transferItems as $item) {
                                $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
                                $quantity = (float)($item['quantity'] ?? 0);
                                $batchIdValue = isset($item['batch_id']) ? (int)$item['batch_id'] : null;

                                if ($quantity <= 0) {
                                    continue;
                                }

                                if ($productId) {
                                    $db->execute(
                                        "UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?",
                                        [$quantity, $productId]
                                    );
                                }

                                if ($batchIdValue) {
                                    $finishedProd = $db->queryOne(
                                        "SELECT quantity_produced FROM finished_products WHERE id = ? LIMIT 1",
                                        [$batchIdValue]
                                    );
                                    $currentRemaining = $finishedProd ? (float)$finishedProd['quantity_produced'] : null;
                                    if ($currentRemaining !== null) {
                                        $newRemaining = max($currentRemaining - $quantity, 0);
                                        $db->execute(
                                            "UPDATE finished_products SET quantity_produced = ? WHERE id = ?",
                                            [$newRemaining, $batchIdValue]
                                        );
                                    }
                                }

                                $transferItemParams = [
                                    $transferId,
                                    $productId,
                                    $batchIdValue ?: null,
                                    $item['batch_number'] ?? null,
                                    $quantity,
                                    $item['notes'] ?? null,
                                ];

                                $db->execute(
                                    "INSERT INTO warehouse_transfer_items (transfer_id, product_id, batch_id, batch_number, quantity, notes)
                                     VALUES (?, ?, ?, ?, ?, ?)",
                                    $transferItemParams
                                );
                            }

                            $db->execute(
                                "UPDATE warehouse_transfers
                                 SET status = 'completed',
                                     approved_by = ?,
                                     approved_at = NOW(),
                                     notes = CONCAT(IFNULL(notes, ''), '\\n(الموافقة من نفس المدير المُنشئ)')
                                 WHERE id = ?",
                                [$currentUser['id'] ?? null, $transferId]
                            );

                            if (!empty($currentUser['id'])) {
                                try {
                                    logAudit(
                                        $currentUser['id'],
                                        'warehouse_transfer_auto_approved',
                                        'warehouse_transfer',
                                        $transferId,
                                        null,
                                        [
                                            'transfer_number' => $transferNumber,
                                            'auto_approved' => true,
                                            'initiator_role' => $currentUser['role'] ?? null,
                                        ]
                                    );
                                } catch (Exception $auditException) {
                                    // لا نسمح لفشل تسجيل التدقيق بإلغاء نجاح العملية
                                    error_log('final_products audit log exception: ' . $auditException->getMessage());
                                }
                            }

                            $_SESSION[$sessionSuccessKey] = sprintf(
                                'تم تنفيذ نقل المنتجات رقم %s بواسطة المدير بنجاح دون الحاجة للموافقة.',
                                $transferNumber
                            );
                            // حذف token بعد نجاح الطلب لمنع إعادة الإرسال
                            unset($_SESSION[$sessionTokenKey]);
                            productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
                        } catch (Throwable $autoApprovalError) {
                            error_log('final_products auto-approval transfer error: ' . $autoApprovalError->getMessage());
                            $_SESSION[$sessionErrorKey] = sprintf(
                                'تم حفظ طلب النقل رقم %s لكن تعذر تنفيذه تلقائياً. يرجى المراجعة اليدوية.',
                                $transferNumber
                            );
                            // حذف token بعد فشل الموافقة التلقائية (الطلب موجود لكن فشل التنفيذ)
                            unset($_SESSION[$sessionTokenKey]);
                            productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
                        }
                    } else {
                        // المستخدم ليس مديراً - الطلب تم إنشاؤه بنجاح وتم إرساله للموافقة
                        $_SESSION[$sessionSuccessKey] = sprintf(
                            'تم إرسال طلب النقل رقم %s إلى المدير للموافقة عليه.',
                            $transferNumber
                        );
                        // حذف token بعد نجاح الطلب لمنع إعادة الإرسال
                        unset($_SESSION[$sessionTokenKey]);
                        productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
                    }
                } else {
                    // الطلب لم يتم إنشاؤه بنجاح حسب الشرط - التحقق مرة أخرى من قاعدة البيانات
                    // قد يكون الطلب موجوداً في قاعدة البيانات رغم فشل الشرط
                    $verificationTransferId = $result['transfer_id'] ?? null;
                    $verificationTransferNumber = $result['transfer_number'] ?? null;
                    
                    // محاولة العثور على الطلب في قاعدة البيانات بجميع الطرق الممكنة
                    $verifyTransfer = null;
                    
                    // البحث بـ transfer_id أولاً
                    if (!empty($verificationTransferId)) {
                        $verifyTransfer = $db->queryOne(
                            "SELECT id, transfer_number FROM warehouse_transfers WHERE id = ?",
                            [$verificationTransferId]
                        );
                    }
                    
                    // إذا لم نجد، نبحث بـ transfer_number
                    if (!$verifyTransfer && !empty($verificationTransferNumber)) {
                        $verifyTransfer = $db->queryOne(
                            "SELECT id, transfer_number FROM warehouse_transfers WHERE transfer_number = ?",
                            [$verificationTransferNumber]
                        );
                    }
                    
                    // إذا لم نجد، نبحث عن آخر طلب نقل تم إنشاؤه من نفس المستخدم في آخر دقيقة
                    if (!$verifyTransfer && !empty($currentUser['id'])) {
                        $verifyTransfer = $db->queryOne(
                            "SELECT id, transfer_number FROM warehouse_transfers 
                             WHERE requested_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                             ORDER BY id DESC LIMIT 1",
                            [$currentUser['id']]
                        );
                    }
                    
                    if ($verifyTransfer) {
                        // الطلب موجود في قاعدة البيانات! كان هناك خطأ في الإرجاع من createWarehouseTransfer
                        error_log("Warning: Transfer was created (ID: {$verifyTransfer['id']}, Number: {$verifyTransfer['transfer_number']}) but createWarehouseTransfer may have returned error. Details: " . json_encode($result));
                        $_SESSION[$sessionSuccessKey] = sprintf(
                            'تم إرسال طلب النقل رقم %s إلى المدير للموافقة عليه.',
                            $verifyTransfer['transfer_number']
                        );
                        // حذف token
                        unset($_SESSION[$sessionTokenKey]);
                        productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
                    } else {
                        // الطلب غير موجود - هناك خطأ حقيقي
                        $errorMessage = $result['message'] ?? 'تعذر إنشاء طلب النقل.';
                        error_log("Failed to create warehouse transfer. Error: $errorMessage. Result: " . json_encode($result));
                        $transferErrors[] = $errorMessage;
                        // إعادة إنشاء token للسماح بإعادة المحاولة
                        $_SESSION[$sessionTokenKey] = bin2hex(random_bytes(32));
                    }
                }
            }
        }

        if (!empty($transferErrors)) {
            $error = implode(' | ', array_unique($transferErrors));
            // إعادة إنشاء token للسماح بإعادة المحاولة في حالة وجود أخطاء
            $_SESSION[$sessionTokenKey] = bin2hex(random_bytes(32));
        }
    } elseif ($postAction === 'create_transfer_from_sales_rep') {
        // التحقق من duplicate submission
        $submissionToken = $_POST['transfer_token'] ?? '';
        $sessionTokenKey = 'transfer_submission_token';
        $storedToken = $_SESSION[$sessionTokenKey] ?? null;
        
        if ($submissionToken === '' || $submissionToken !== $storedToken) {
            $_SESSION[$sessionErrorKey] = 'تم إرسال هذا الطلب مسبقاً. يرجى عدم إعادة تحميل الصفحة.';
            productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
            exit;
        }
        
        unset($_SESSION[$sessionTokenKey]);
        
        $transferErrors = [];
        
        if (!$primaryWarehouse) {
            $transferErrors[] = 'لا يمكن إنشاء طلب الاستلام حالياً بسبب عدم توفر مخزن رئيسي.';
        } else {
            $toWarehouseId = intval($primaryWarehouse['id']);
            $salesRepId = intval($_POST['sales_rep_id'] ?? 0);
            $transferDate = $_POST['transfer_date'] ?? date('Y-m-d');
            $reason = trim((string)($_POST['reason'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            
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
                $transferErrors[] = 'لم يتم العثور على مخزن سيارة للمندوب المحدد.';
            } else {
                $fromWarehouseId = $vehicle['warehouse_id'];
                $salesRepName = $vehicle['sales_rep_name'] ?? 'مندوب';
                
                if ($fromWarehouseId === $toWarehouseId) {
                    $transferErrors[] = 'لا يمكن النقل من وإلى نفس المخزن.';
                }
                
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
                    $transferDate = date('Y-m-d');
                }
                
                $rawItems = $_POST['items'] ?? [];
                $transferItems = [];
                
                if (is_array($rawItems)) {
                    foreach ($rawItems as $item) {
                        $productId = isset($item['product_id']) ? intval($item['product_id']) : 0;
                        $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0.0;
                        $itemNotes = isset($item['notes']) ? trim((string)$item['notes']) : null;
                        $batchId = isset($item['batch_id']) ? intval($item['batch_id']) : 0;
                        $batchNumber = isset($item['batch_number']) ? trim((string)$item['batch_number']) : '';
                        
                        if ($quantity > 0 && ($productId > 0 || $batchId > 0)) {
                            $transferItems[] = [
                                'product_id' => $productId > 0 ? $productId : null,
                                'batch_id' => $batchId > 0 ? $batchId : null,
                                'batch_number' => $batchNumber ?: null,
                                'quantity' => $quantity,
                                'notes' => $itemNotes
                            ];
                        }
                    }
                }
                
                if (empty($transferItems)) {
                    $transferErrors[] = 'يجب إضافة منتج واحد على الأقل.';
                }
                
                if (empty($transferErrors)) {
                    try {
                        $result = createWarehouseTransfer(
                            $fromWarehouseId,
                            $toWarehouseId,
                            $transferDate,
                            $transferItems,
                            $reason ?: "طلب استلام منتجات من بضاعة المندوب ({$salesRepName})",
                            $notes,
                            $currentUser['id']
                        );
                        
                        if ($result['success']) {
                            $transferInfo = $db->queryOne(
                                "SELECT status FROM warehouse_transfers WHERE id = ?",
                                [$result['transfer_id']]
                            );
                            
                            if ($transferInfo && $transferInfo['status'] === 'completed') {
                                $_SESSION[$sessionSuccessKey] = 'تم استلام المنتجات بنجاح. تم نقل المنتجات إلى المخزن الرئيسي مباشرة.';
                            } else {
                                $_SESSION[$sessionSuccessKey] = 'تم إنشاء طلب الاستلام بنجاح. سيتم مراجعته و الموافقة عليه من قبل المدير.';
                            }
                        } else {
                            $_SESSION[$sessionErrorKey] = $result['message'] ?? 'حدث خطأ أثناء إنشاء طلب الاستلام.';
                        }
                    } catch (Exception $e) {
                        error_log('Error creating transfer from sales rep: ' . $e->getMessage());
                        $_SESSION[$sessionErrorKey] = 'حدث خطأ أثناء إنشاء طلب الاستلام: ' . $e->getMessage();
                    }
                } else {
                    $_SESSION[$sessionErrorKey] = implode(' ', $transferErrors);
                }
            }
        }
        
        productionSafeRedirect($productionInventoryUrl, $productionRedirectParams, $productionRedirectRole);
        exit;
    }
    
    if ($isManager && $postAction === 'create_external_product') {
        $name = trim((string)($_POST['external_name'] ?? ''));
        $channel = $_POST['external_channel'] ?? 'company';
        $initialQuantity = max(0, floatval($_POST['external_quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['external_price'] ?? 0));
        $unit = trim((string)($_POST['external_unit'] ?? 'قطعة'));
        $notesValue = trim((string)($_POST['external_description'] ?? ''));

        if ($name === '') {
            $_SESSION[$sessionErrorKey] = 'يرجى إدخال اسم المنتج الخارجي.';
            productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
        }

        if (!in_array($channel, ['company', 'delegate', 'other'], true)) {
            $channel = 'company';
        }

        try {
            $insertResult = $db->execute(
                "INSERT INTO products (name, category, product_type, external_channel, quantity, unit, unit_price, description, status)
                 VALUES (?, ?, 'external', ?, ?, ?, ?, ?, 'active')",
                [
                    $name,
                    $channel === 'company' ? 'بيع داخلي' : ($channel === 'delegate' ? 'مندوب مبيعات' : 'خارجي'),
                    $channel,
                    $initialQuantity,
                    $unit !== '' ? $unit : 'قطعة',
                    $unitPrice,
                    $notesValue !== '' ? $notesValue : null,
                ]
            );

            $productId = $insertResult['insert_id'] ?? null;
            if (!empty($productId) && !empty($currentUser['id'])) {
                logAudit(
                    $currentUser['id'],
                    'external_product_create',
                    'product',
                    $productId,
                    null,
                    [
                        'name' => $name,
                        'channel' => $channel,
                        'quantity' => $initialQuantity,
                        'unit_price' => $unitPrice,
                    ]
                );
            }

            $_SESSION[$sessionSuccessKey] = 'تم إضافة المنتج الخارجي بنجاح.';
        } catch (Exception $e) {
            error_log('create_external_product error: ' . $e->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر إضافة المنتج الخارجي. يرجى المحاولة لاحقاً.';
        }

        productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
    } elseif ($isManager && $postAction === 'adjust_external_stock') {
        $productId = intval($_POST['product_id'] ?? 0);
        $operation = $_POST['operation'] ?? 'add';
        $amount = max(0, floatval($_POST['quantity'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($productId <= 0 || $amount <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار منتج وإدخال كمية صالحة.';
            productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
        }

        try {
            $productRow = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE id = ? AND product_type = 'external' LIMIT 1",
                [$productId]
            );

            if (!$productRow) {
                $_SESSION[$sessionErrorKey] = 'المنتج المطلوب غير موجود أو ليس منتجاً خارجياً.';
                productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
            }

            $oldQuantity = floatval($productRow['quantity'] ?? 0);
            $newQuantity = $oldQuantity;

            if ($operation === 'discard') {
                if ($amount > $oldQuantity) {
                    $_SESSION[$sessionErrorKey] = 'الكمية المراد إتلافها أكبر من الكمية المتاحة.';
                    productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
                }
                $newQuantity = $oldQuantity - $amount;
            } else {
                $newQuantity = $oldQuantity + $amount;
                $operation = 'add';
            }

            $db->execute(
                "UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ? AND product_type = 'external'",
                [$newQuantity, $productId]
            );

            if (!empty($currentUser['id'])) {
                logAudit(
                    $currentUser['id'],
                    $operation === 'add' ? 'external_product_increase' : 'external_product_discard',
                    'product',
                    $productId,
                    ['quantity' => $oldQuantity],
                    [
                        'quantity' => $newQuantity,
                        'change' => $amount,
                        'note' => $note !== '' ? $note : null,
                    ]
                );
            }

            $_SESSION[$sessionSuccessKey] = $operation === 'add'
                ? 'تم زيادة كمية المنتج الخارجي بنجاح.'
                : 'تم إتلاف الكمية المحددة من المنتج الخارجي.';
        } catch (Exception $e) {
            error_log('adjust_external_stock error: ' . $e->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر تحديث كمية المنتج الخارجي.';
        }

        productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
    } elseif ($isManager && $postAction === 'update_external_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $name = trim((string)($_POST['edit_name'] ?? ''));
        $channel = $_POST['edit_channel'] ?? 'company';
        $unitPrice = max(0, floatval($_POST['edit_price'] ?? 0));
        $unit = trim((string)($_POST['edit_unit'] ?? 'قطعة'));
        $description = trim((string)($_POST['edit_description'] ?? ''));

        if ($productId <= 0 || $name === '') {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار منتج صالح وتحديد الاسم.';
            productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
        }

        if (!in_array($channel, ['company', 'delegate', 'other'], true)) {
            $channel = 'company';
        }

        try {
            $existing = $db->queryOne(
                "SELECT id, name, external_channel, unit_price, unit, description
                 FROM products WHERE id = ? AND product_type = 'external' LIMIT 1",
                [$productId]
            );

            if (!$existing) {
                $_SESSION[$sessionErrorKey] = 'المنتج المطلوب غير موجود أو ليس منتجاً خارجياً.';
                productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
            }

            $db->execute(
                "UPDATE products
                 SET name = ?, category = ?, external_channel = ?, unit_price = ?, unit = ?, description = ?, updated_at = NOW()
                 WHERE id = ? AND product_type = 'external'",
                [
                    $name,
                    $channel === 'company' ? 'بيع داخلي' : ($channel === 'delegate' ? 'مندوب مبيعات' : 'خارجي'),
                    $channel,
                    $unitPrice,
                    $unit !== '' ? $unit : 'قطعة',
                    $description !== '' ? $description : null,
                    $productId,
                ]
            );

            if (!empty($currentUser['id'])) {
                logAudit(
                    $currentUser['id'],
                    'external_product_update',
                    'product',
                    $productId,
                    [
                        'name' => $existing['name'] ?? null,
                        'channel' => $existing['external_channel'] ?? null,
                        'unit_price' => $existing['unit_price'] ?? null,
                        'unit' => $existing['unit'] ?? null,
                    ],
                    [
                        'name' => $name,
                        'channel' => $channel,
                        'unit_price' => $unitPrice,
                        'unit' => $unit,
                    ]
                );
            }

            $_SESSION[$sessionSuccessKey] = 'تم تحديث بيانات المنتج الخارجي بنجاح.';
        } catch (Exception $e) {
            error_log('update_external_product error: ' . $e->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر تحديث بيانات المنتج الخارجي.';
        }

        productionSafeRedirect($managerInventoryUrl, $managerRedirectParams, $managerRedirectRole);
    }
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = $_GET['search'] ?? '';
$productId = $_GET['product_id'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// بناء استعلام البحث
$whereConditions = ['1=1'];
$params = [];

// التحقق من وجود عمود date أو production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

if ($search) {
    $searchParam = '%' . $search . '%';
    if ($userIdColumn) {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    } else {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
}

if ($productId) {
    $whereConditions[] = "p.product_id = ?";
    $params[] = intval($productId);
}

if ($status) {
    $whereConditions[] = "p.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(p.$dateColumn) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(p.$dateColumn) <= ?";
    $params[] = $dateTo;
}

// فقط المنتجات المكتملة أو الموافق عليها
$whereConditions[] = "(p.status = 'completed' OR p.status = 'approved')";

$whereClause = implode(' AND ', $whereConditions);

// حساب العدد الإجمالي
$countSql = "SELECT COUNT(*) as total 
             FROM production p
             LEFT JOIN products pr ON p.product_id = pr.id";
             
// إضافة JOIN مع users فقط إذا كان العمود موجوداً
if ($userIdColumn) {
    $countSql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
}

$countSql .= " WHERE $whereClause";

$totalResult = $db->queryOne($countSql, $params);
$totalProducts = $totalResult['total'] ?? 0;
$totalPages = ceil($totalProducts / $perPage);

// الحصول على البيانات مع تجميع حسب المنتج وخط الإنتاج
$sql = "SELECT 
            p.id,
            p.product_id,
            pr.name as product_name,
            pr.category as product_category,
            pr.unit_price,
            pr.quantity as available_quantity,
            SUM(p.quantity) as total_produced,
            COUNT(DISTINCT p.id) as production_count,
            MIN(p.$dateColumn) as first_production_date,
            MAX(p.$dateColumn) as last_production_date,
            " . ($userIdColumn ? "GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') as workers," : "'غير محدد' as workers,") . "
            p.status as production_status
        FROM production p
        LEFT JOIN products pr ON p.product_id = pr.id";
        
// إضافة JOIN مع users فقط إذا كان العمود موجوداً
if ($userIdColumn) {
    $sql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
}

$sql .= " WHERE $whereClause
        GROUP BY p.product_id
        ORDER BY MIN(p.$dateColumn) ASC, pr.name ASC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$finalProducts = $db->query($sql, $params);

$totalAvailableSum = 0.0;
$totalProducedSum = 0.0;
$totalProductionCountSum = 0;
if (is_array($finalProducts)) {
    foreach ($finalProducts as $product) {
        $availableQuantity = (float)($product['available_quantity'] ?? 0);
        $producedQuantity = (float)($product['total_produced'] ?? 0);
        $productOperations = (int)($product['production_count'] ?? 0);
        $productUnitPrice = isset($product['unit_price']) ? (float)$product['unit_price'] : 0.0;

        $totalAvailableSum += $availableQuantity;
        $totalProducedSum += $producedQuantity;
        $totalProductionCountSum += $productOperations;
    }
}

$statusLabels = [
    'pending' => 'معلّق',
    'approved' => 'موافق عليه',
    'completed' => 'مكتمل',
    'rejected' => 'مرفوض'
];

$finishedProductsRows = [];
$finishedProductsCount = 0;
$finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
if (!empty($finishedProductsTableExists)) {
    // حذف حقل manager_unit_price إذا كان موجوداً
    try {
        $managerPriceColumn = $db->queryOne("SHOW COLUMNS FROM finished_products LIKE 'manager_unit_price'");
        if (!empty($managerPriceColumn)) {
            $db->execute("ALTER TABLE `finished_products` DROP COLUMN `manager_unit_price`");
        }
    } catch (Exception $priceColumnError) {
        error_log('Finished products drop manager_unit_price column error: ' . $priceColumnError->getMessage());
    }
    
    // إضافة حقل unit_price (سعر الوحدة)
    try {
        $unitPriceColumn = $db->queryOne("SHOW COLUMNS FROM finished_products LIKE 'unit_price'");
        if (empty($unitPriceColumn)) {
            $db->execute("ALTER TABLE `finished_products` ADD COLUMN `unit_price` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'سعر الوحدة من القالب' AFTER `quantity_produced`");
        }
    } catch (Exception $e) {
        error_log('Failed to ensure unit_price column in finished_products: ' . $e->getMessage());
    }
    
    // إضافة حقل total_price (السعر الإجمالي)
    try {
        $totalPriceColumn = $db->queryOne("SHOW COLUMNS FROM finished_products LIKE 'total_price'");
        if (empty($totalPriceColumn)) {
            $db->execute("ALTER TABLE `finished_products` ADD COLUMN `total_price` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'السعر الإجمالي (unit_price × quantity_produced)' AFTER `unit_price`");
        }
    } catch (Exception $e) {
        error_log('Failed to ensure total_price column in finished_products: ' . $e->getMessage());
    }
    
    // التأكد من وجود الأعمدة المطلوبة في جدول product_templates
    try {
        $productIdColumn = $db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'product_id'");
        if (empty($productIdColumn)) {
            $db->execute("ALTER TABLE `product_templates` ADD COLUMN `product_id` int(11) NULL DEFAULT NULL AFTER `id`");
            $db->execute("ALTER TABLE `product_templates` ADD KEY `product_id` (`product_id`)");
        }
    } catch (Exception $e) {
        error_log('Failed to ensure product_id column in product_templates: ' . $e->getMessage());
    }
    
    try {
        $unitPriceColumn = $db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'unit_price'");
        if (empty($unitPriceColumn)) {
            $db->execute("ALTER TABLE `product_templates` ADD COLUMN `unit_price` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'سعر الوحدة بالجنيه'");
        }
    } catch (Exception $e) {
        error_log('Failed to ensure unit_price column in product_templates: ' . $e->getMessage());
    }
    
    try {
        $finishedProductsRows = $db->query("
            SELECT 
                fp.id,
                fp.batch_id,
                fp.batch_number,
                COALESCE(fp.product_id, bn.product_id) AS product_id,
                COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                fp.production_date,
                fp.quantity_produced,
                fp.unit_price,
                fp.total_price,
                (SELECT pt.unit_price 
                 FROM product_templates pt 
                 WHERE pt.status = 'active' 
                   AND pt.unit_price IS NOT NULL 
                   AND pt.unit_price > 0
                   AND pt.unit_price <= 10000
                   AND (
                       -- مطابقة product_id أولاً (الأكثر دقة)
                       (
                           COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                           AND COALESCE(fp.product_id, bn.product_id) > 0
                           AND pt.product_id IS NOT NULL 
                           AND pt.product_id > 0 
                           AND pt.product_id = COALESCE(fp.product_id, bn.product_id)
                       )
                       -- مطابقة product_name (مطابقة دقيقة أو جزئية)
                       OR (
                           pt.product_name IS NOT NULL 
                           AND pt.product_name != ''
                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                           AND (
                               LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                               OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                               OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                           )
                       )
                       -- إذا لم يكن هناك product_id في القالب، نبحث فقط بالاسم
                       OR (
                           (pt.product_id IS NULL OR pt.product_id = 0)
                           AND pt.product_name IS NOT NULL 
                           AND pt.product_name != ''
                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                           AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                           AND (
                               LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                               OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                               OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                           )
                       )
                   )
                 ORDER BY pt.unit_price DESC
                 LIMIT 1) AS template_unit_price,
                GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
            FROM finished_products fp
            LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
            LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
            LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
            LEFT JOIN users u ON bw.employee_id = u.id
            WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
            GROUP BY fp.id
            ORDER BY fp.production_date DESC, fp.id DESC
            LIMIT 150
        ");
        
        // تحديث الحقول unit_price و total_price للمنتجات التي لا تحتوي عليها
        if (is_array($finishedProductsRows)) {
            foreach ($finishedProductsRows as $row) {
                $fpId = (int)($row['id'] ?? 0);
                $templatePrice = isset($row['template_unit_price']) && $row['template_unit_price'] !== null 
                    ? (float)$row['template_unit_price'] 
                    : null;
                $currentUnitPrice = isset($row['unit_price']) && $row['unit_price'] !== null 
                    ? (float)$row['unit_price'] 
                    : null;
                $quantity = (float)($row['quantity_produced'] ?? 0);
                
                // إذا كان هناك سعر قالب ولم يكن هناك unit_price محفوظ، قم بتحديثه
                if ($templatePrice !== null && $templatePrice > 0 && $templatePrice <= 10000 && $currentUnitPrice === null) {
                    $totalPrice = $templatePrice * $quantity;
                    try {
                        $db->execute(
                            "UPDATE finished_products SET unit_price = ?, total_price = ? WHERE id = ?",
                            [$templatePrice, $totalPrice, $fpId]
                        );
                        // تحديث القيم في المصفوفة للعرض
                        $row['unit_price'] = $templatePrice;
                        $row['total_price'] = $totalPrice;
                    } catch (Exception $e) {
                        error_log('Failed to update unit_price and total_price for finished_product ' . $fpId . ': ' . $e->getMessage());
                    }
                }
                // إذا كان هناك unit_price ولكن لا يوجد total_price، قم بحسابه
                elseif ($currentUnitPrice !== null && $currentUnitPrice > 0 && $quantity > 0) {
                    $currentTotalPrice = isset($row['total_price']) && $row['total_price'] !== null 
                        ? (float)$row['total_price'] 
                        : null;
                    if ($currentTotalPrice === null) {
                        $totalPrice = $currentUnitPrice * $quantity;
                        try {
                            $db->execute(
                                "UPDATE finished_products SET total_price = ? WHERE id = ?",
                                [$totalPrice, $fpId]
                            );
                            $row['total_price'] = $totalPrice;
                        } catch (Exception $e) {
                            error_log('Failed to update total_price for finished_product ' . $fpId . ': ' . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        $finishedProductsCount = is_array($finishedProductsRows) ? count($finishedProductsRows) : 0;
    } catch (Exception $finishedProductsError) {
        error_log('Finished products query error: ' . $finishedProductsError->getMessage());
    }
}

$productDetailsMap = [];
if (!empty($finalProducts)) {
    $productIds = array_column($finalProducts, 'product_id');
    $productIds = array_values(array_filter(array_map('intval', $productIds)));

    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $detailsSql = "SELECT 
                            p.product_id,
                            p.id,
                            p.quantity,
                            p.$dateColumn as production_date,
                            p.status,
                            p.production_line_id,
                            pl.line_name,
                            " . ($userIdColumn ? "u.full_name as worker_name, u.username as worker_username" : "NULL as worker_name, NULL as worker_username") . "
                       FROM production p
                       LEFT JOIN production_lines pl ON p.production_line_id = pl.id";

        if ($userIdColumn) {
            $detailsSql .= " LEFT JOIN users u ON p.$userIdColumn = u.id";
        }

        $detailsSql .= " WHERE p.product_id IN ($placeholders)
                         AND (p.status = 'completed' OR p.status = 'approved')
                         ORDER BY p.product_id, p.$dateColumn ASC";

        $detailsRows = $db->query($detailsSql, $productIds);

        foreach ($detailsRows as $detailRow) {
            $workerName = $detailRow['worker_name'] ?? $detailRow['worker_username'] ?? null;
            $productDetailsMap[$detailRow['product_id']][] = [
                'id' => (int)$detailRow['id'],
                'quantity' => (float)($detailRow['quantity'] ?? 0),
                'date' => $detailRow['production_date'] ?? null,
                'status' => $detailRow['status'] ?? 'completed',
                'worker' => $workerName ? trim($workerName) : 'غير محدد',
                'line_name' => $detailRow['line_name'] ?? null,
            ];
        }
    }
}

// الحصول على المنتجات وخطوط الإنتاج للفلترة
$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' AND (product_type IS NULL OR product_type = 'internal') ORDER BY name");
// حساب الإحصائيات
$statsSql = "SELECT 
                COUNT(DISTINCT p.product_id) as total_products,
                COUNT(DISTINCT p.id) as total_production_records
             FROM production p
             WHERE (p.status = 'completed' OR p.status = 'approved')";
$stats = $db->queryOne($statsSql);

if ($dateFrom || $dateTo) {
    $statsWhere = ["(p.status = 'completed' OR p.status = 'approved')"];
    if ($dateFrom) {
        $statsWhere[] = "DATE(p.$dateColumn) >= ?";
    }
    if ($dateTo) {
        $statsWhere[] = "DATE(p.$dateColumn) <= ?";
    }
    $statsParams = [];
    if ($dateFrom) $statsParams[] = $dateFrom;
    if ($dateTo) $statsParams[] = $dateTo;
    
    $statsSql = "SELECT 
                    COUNT(DISTINCT p.product_id) as total_products,
                    COUNT(DISTINCT p.id) as total_production_records
                 FROM production p
                 WHERE " . implode(' AND ', $statsWhere);
    $stats = $db->queryOne($statsSql, $statsParams);
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];

$externalProducts = [];
$externalChannelLabels = [
    'company' => 'بيع داخل الشركة',
    'delegate' => 'مندوب مبيعات',
    'other' => 'متنوع',
    '' => 'غير محدد',
    null => 'غير محدد',
];

if ($isManager) {
    try {
        $externalProducts = $db->query(
            "SELECT id, name, external_channel, quantity, unit_price, unit, updated_at, created_at, description
             FROM products
             WHERE product_type = 'external'
             ORDER BY updated_at DESC, created_at DESC"
        );
    } catch (Exception $e) {
        error_log('final_products: failed loading external products -> ' . $e->getMessage());
        $externalProducts = [];
    }
}
?>

<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h2 class="mb-0"><i class="bi bi-boxes me-2"></i>جدول المنتجات</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($isManager): ?>
        <button
            type="button"
            class="btn btn-success js-open-add-external-modal"
        >
            <i class="bi bi-plus-circle me-1"></i>
            إضافة منتج خارجي
        </button>
        <?php endif; ?>
        <button
            type="button"
            class="btn btn-outline-primary"
            data-bs-toggle="modal"
            data-bs-target="#requestTransferModal"
            <?php echo $canCreateTransfers ? '' : 'disabled'; ?>
            title="<?php echo $canCreateTransfers ? '' : 'يرجى التأكد من وجود مخازن وجهة نشطة.'; ?>"
        >
            <i class="bi bi-arrow-left-right me-1"></i>
            طلب نقل منتجات
        </button>
        <button
            type="button"
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#receiveFromSalesRepModal"
            <?php echo !empty($salesReps) && $primaryWarehouse ? '' : 'disabled'; ?>
            title="<?php echo !empty($salesReps) && $primaryWarehouse ? '' : 'يرجى التأكد من وجود مندوبين ومخزن رئيسي.'; ?>"
        >
            <i class="bi bi-truck me-1"></i>
            طلب استلام منتجات
        </button>
    </div>
</div>

<?php if ($primaryWarehouse): ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill fs-5"></i>
        <div>
            هذه الصفحة تمثل المخزن الرئيسي للشركة
            <strong><?php echo htmlspecialchars($primaryWarehouse['name']); ?></strong>.
            كل المنتجات المعروضة يتم اعتمادها كمخزون رئيسي يظهر للمدير والمحاسب في الصفحات الأخرى.
        </div>
    </div>
<?php elseif (!empty($warehousesTableExists)): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>
            لم يتم العثور على مخزن رئيسي نشط. يرجى إنشاء مخزن رئيسي من إعدادات المخازن لضمان ظهور المخزون في باقي الصفحات.
        </div>
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
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-archive-fill me-2"></i>جدول المنتجات</h5>
        <span class="badge bg-light text-dark">
            <?php echo number_format($finishedProductsCount); ?> عنصر
        </span>
    </div>
    <div class="card-body">
        <?php if (!empty($finishedProductsRows)): ?>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>رقم التشغيله</th>
                            <th>اسم المنتج</th>
                            <th>تاريخ الإنتاج</th>
                            <th>الكمية المنتجة</th>
                            <?php if ($isManager): ?>
                                <th>السعر الإجمالي</th>
                            <?php endif; ?>
                            <th>العمال المشاركون</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($finishedProductsRows as $finishedRow): ?>
                            <?php
                                $batchNumber = $finishedRow['batch_number'] ?? '';
                                $workersList = array_filter(array_map('trim', explode(',', (string)($finishedRow['workers'] ?? ''))));
                                $workersDisplay = !empty($workersList)
                                    ? implode('، ', array_map('htmlspecialchars', $workersList))
                                    : 'غير محدد';
                                $viewUrl = $batchNumber
                                    ? getRelativeUrl('production.php?page=batch_numbers&batch_number=' . urlencode($batchNumber))
                                    : null;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batchNumber ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($finishedRow['product_name'] ?? 'غير محدد'); ?></td>
                                <td><?php echo !empty($finishedRow['production_date']) ? htmlspecialchars(formatDate($finishedRow['production_date'])) : '—'; ?></td>
                                <td><?php echo number_format((float)($finishedRow['quantity_produced'] ?? 0), 2); ?></td>
                                <?php if ($isManager): ?>
                                    <td>
                                        <?php 
                                            // استخدام template_unit_price كبديل إذا كان unit_price فارغاً
                                            $unitPrice = null;
                                            if (isset($finishedRow['unit_price']) && $finishedRow['unit_price'] !== null) {
                                                $unitPrice = (float)$finishedRow['unit_price'];
                                            } elseif (isset($finishedRow['template_unit_price']) && $finishedRow['template_unit_price'] !== null) {
                                                $unitPrice = (float)$finishedRow['template_unit_price'];
                                            }
                                            
                                            $totalPrice = isset($finishedRow['total_price']) && $finishedRow['total_price'] !== null 
                                                ? (float)$finishedRow['total_price'] 
                                                : null;
                                            $quantity = (float)($finishedRow['quantity_produced'] ?? 0);
                                            
                                            // إذا كان total_price فارغاً ولكن unit_price موجود، احسبه
                                            if ($totalPrice === null && $unitPrice !== null && $unitPrice > 0 && $quantity > 0) {
                                                $totalPrice = $unitPrice * $quantity;
                                            }
                                            
                                            if ($totalPrice !== null && $totalPrice > 0) {
                                                echo '<span class="fw-bold text-success">' . htmlspecialchars(formatCurrency($totalPrice)) . '</span>';
                                                if ($unitPrice !== null && $unitPrice > 0 && $quantity > 0) {
                                                    echo '<br><small class="text-muted">(' . htmlspecialchars(formatCurrency($unitPrice)) . ' × ' . number_format($quantity, 2) . ')</small>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                                if ($unitPrice === null) {
                                                    echo '<br><small class="text-muted"><i class="bi bi-info-circle"></i> لا يوجد سعر للوحدة</small>';
                                                }
                                            }
                                        ?>
                                    </td>
                                <?php endif; ?>
                                <td><?php echo $workersDisplay; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if ($batchNumber): ?>
                                            <button type="button"
                                                    class="btn btn-primary js-batch-details"
                                                    data-batch="<?php echo htmlspecialchars($batchNumber); ?>"
                                                    data-product="<?php echo htmlspecialchars($finishedRow['product_name'] ?? ''); ?>"
                                                    data-view-url="<?php echo htmlspecialchars($viewUrl ?? ''); ?>">
                                                <i class="bi bi-eye"></i> عرض تفاصيل التشغيلة
                                            </button>
                                        <?php elseif ($viewUrl): ?>
                                            <a class="btn btn-primary" href="<?php echo htmlspecialchars($viewUrl); ?>">
                                                <i class="bi bi-eye"></i> عرض تفاصيل التشغيلة
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($batchNumber): ?>
                                        <button type="button"
                                                class="btn btn-outline-secondary js-copy-batch"
                                                data-batch="<?php echo htmlspecialchars($batchNumber); ?>">
                                            <i class="bi bi-clipboard"></i>
                                            <span class="d-none d-sm-inline">نسخ الرقم</span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$viewUrl && !$batchNumber && !$isManager): ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                لا توجد بيانات متاحة حالياً.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isManager): ?>
<div class="card shadow-sm mt-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-cart4 me-2"></i>المنتجات الخارجية (لا تؤثر على مخزون الشركة)</h5>
        <span class="badge bg-light text-dark"><?php echo number_format(is_countable($externalProducts) ? count($externalProducts) : 0); ?> منتج</span>
    </div>
    <div class="card-body">
        <?php if (!empty($externalProducts)): ?>
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>اسم المنتج</th>
                        <th>نوع البيع</th>
                        <th>الكمية المتاحة</th>
                        <th>سعر البيع</th>
                        <th>آخر تحديث</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($externalProducts as $externalProduct): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($externalProduct['name'] ?? ''); ?></strong>
                            <?php if (!empty($externalProduct['description'])): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($externalProduct['description']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php
                            $channelKey = $externalProduct['external_channel'] ?? null;
                            echo htmlspecialchars($externalChannelLabels[$channelKey] ?? $externalChannelLabels[null]);
                        ?></td>
                        <td><?php echo number_format((float)($externalProduct['quantity'] ?? 0), 2); ?> <?php echo htmlspecialchars($externalProduct['unit'] ?? ''); ?></td>
                        <td><?php echo formatCurrency($externalProduct['unit_price'] ?? 0); ?></td>
                        <td>
                            <?php
                                $updatedAt = $externalProduct['updated_at'] ?? $externalProduct['created_at'] ?? null;
                                echo $updatedAt ? htmlspecialchars(formatDateTime($updatedAt)) : '—';
                            ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                <button
                                    type="button"
                                    class="btn btn-outline-success js-external-adjust"
                                    data-mode="add"
                                    data-product="<?php echo intval($externalProduct['id']); ?>"
                                    data-name="<?php echo htmlspecialchars($externalProduct['name'] ?? '', ENT_QUOTES); ?>"
                                >
                                    <i class="bi bi-plus-circle"></i>
                                    إضافة كمية
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-danger js-external-adjust"
                                    data-mode="discard"
                                    data-product="<?php echo intval($externalProduct['id']); ?>"
                                    data-name="<?php echo htmlspecialchars($externalProduct['name'] ?? '', ENT_QUOTES); ?>"
                                >
                                    <i class="bi bi-trash3"></i>
                                    إتلاف
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-primary js-external-edit"
                                    data-product="<?php echo intval($externalProduct['id']); ?>"
                                    data-name="<?php echo htmlspecialchars($externalProduct['name'] ?? '', ENT_QUOTES); ?>"
                                    data-channel="<?php echo htmlspecialchars($externalProduct['external_channel'] ?? '', ENT_QUOTES); ?>"
                                    data-price="<?php echo htmlspecialchars($externalProduct['unit_price'] ?? 0, ENT_QUOTES); ?>"
                                    data-unit="<?php echo htmlspecialchars($externalProduct['unit'] ?? 'قطعة', ENT_QUOTES); ?>"
                                    data-description="<?php echo htmlspecialchars($externalProduct['description'] ?? '', ENT_QUOTES); ?>"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                    تعديل
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-2"></i>
            لم يتم إضافة منتجات خارجية بعد.
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($isManager): ?>
<div class="modal fade" id="addExternalProductModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="create_external_product">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="externalProductName">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" id="externalProductName" name="external_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="externalProductChannel">نوع البيع <span class="text-danger">*</span></label>
                    <select id="externalProductChannel" name="external_channel" class="form-select" required>
                        <option value="company">بيع داخل الشركة</option>
                        <option value="delegate">مندوب المبيعات</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="externalProductQuantity">الكمية الابتدائية</label>
                        <input type="number" id="externalProductQuantity" name="external_quantity" class="form-control" min="0" step="0.01" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="externalProductUnit">الوحدة</label>
                        <input type="text" id="externalProductUnit" name="external_unit" class="form-control" value="قطعة">
                    </div>
                </div>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label" for="externalProductPrice">سعر البيع</label>
                        <input type="number" id="externalProductPrice" name="external_price" class="form-control" min="0" step="0.01" value="0.00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="externalProductDescription">وصف مختصر</label>
                        <input type="text" id="externalProductDescription" name="external_description" class="form-control" placeholder="اختياري">
                    </div>
                </div>
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-2"></i>
                    هذه المنتجات لا تؤثر على مخزون الشركة وتُستخدم للمنتجات الخارجية التي يتم بيعها داخلياً أو عبر المناديب.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-success">حفظ المنتج</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="externalStockModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="adjust_external_stock">
            <input type="hidden" name="product_id" value="">
            <input type="hidden" name="operation" value="add">
            <div class="modal-header">
                <h5 class="modal-title js-external-stock-title">تحديث كمية المنتج الخارجي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="externalStockProductName">اسم المنتج</label>
                    <input type="text" id="externalStockProductName" class="form-control js-external-stock-name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label js-external-stock-label" for="externalStockQuantity">الكمية المراد إضافتها</label>
                    <input type="number" id="externalStockQuantity" name="quantity" class="form-control" min="0" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="externalStockNote">ملاحظة</label>
                    <textarea id="externalStockNote" name="note" class="form-control" rows="2" placeholder="اختياري"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary js-external-stock-submit">حفظ</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editExternalProductModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="update_external_product">
            <input type="hidden" name="product_id" value="">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات المنتج الخارجي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="editExternalProductName">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" id="editExternalProductName" name="edit_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="editExternalProductChannel">نوع البيع <span class="text-danger">*</span></label>
                    <select id="editExternalProductChannel" name="edit_channel" class="form-select" required>
                        <option value="company">بيع داخل الشركة</option>
                        <option value="delegate">مندوب المبيعات</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="editExternalProductPrice">سعر البيع</label>
                        <input type="number" id="editExternalProductPrice" name="edit_price" class="form-control" min="0" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editExternalProductUnit">الوحدة</label>
                        <input type="text" id="editExternalProductUnit" name="edit_unit" class="form-control">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label" for="editExternalProductDescription">ملاحظات</label>
                    <textarea id="editExternalProductDescription" name="edit_description" class="form-control" rows="2" placeholder="اختياري"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="setManualPriceModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="update_manual_price">
            <input type="hidden" name="finished_product_id" id="manualPriceProductId" value="">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تحديد السعر اليدوي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="form-label fw-bold">المنتج</div>
                    <div class="form-control-plaintext" id="manualPriceProductName">—</div>
                </div>
                <div class="mb-3">
                    <div class="form-label fw-bold">رقم التشغيلة</div>
                    <div class="form-control-plaintext" id="manualPriceBatchNumber">—</div>
                </div>
                <div class="mb-3">
                    <div class="form-label fw-bold">الكمية في التشغيلة</div>
                    <div class="form-control-plaintext" id="manualPriceQuantity">—</div>
                </div>
                <div class="mb-3">
                    <div class="form-label text-muted small">سعر القالب (للحدة الواحدة)</div>
                    <div class="form-control-plaintext small" id="manualPriceTemplatePrice">—</div>
                </div>
                <div class="mb-3">
                    <div class="form-label text-muted small">السعر المحسوب تلقائياً (الكمية × سعر القالب)</div>
                    <div class="form-control-plaintext small text-primary fw-semibold" id="manualPriceCalculated">—</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="manualPriceInput">السعر اليدوي الإجمالي للتشغيلة <span class="text-danger">*</span></label>
                    <input type="number" 
                           name="manual_price" 
                           id="manualPriceInput" 
                           class="form-control" 
                           min="0" 
                           max="10000000" 
                           step="0.01" 
                           placeholder="أدخل السعر الإجمالي للتشغيلة">
                    <small class="form-text text-muted">
                        هذا السعر هو السعر الإجمالي للتشغيلة (وليس للوحدة الواحدة). سيتم استخدامه بدلاً من الحساب التلقائي (الكمية × سعر القالب). اتركه فارغاً لإزالة السعر اليدوي والعودة للحساب التلقائي.
                    </small>
                </div>
                <div class="mb-3">
                    <div class="form-label text-muted small">السعر اليدوي الحالي</div>
                    <div class="form-control-plaintext small" id="manualPriceCurrentPrice">—</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-info">حفظ السعر</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($primaryWarehouse): ?>
<div class="modal fade" id="requestTransferModal" tabindex="-1" aria-labelledby="requestTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-left-right me-2"></i>
                    طلب نقل منتجات من المخزن الرئيسي
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" id="mainWarehouseTransferForm">
                <input type="hidden" name="action" value="create_transfer">
                <input type="hidden" name="from_warehouse_id" value="<?php echo intval($primaryWarehouse['id']); ?>">
                <input type="hidden" name="transfer_token" id="transferToken" value="">
                <div class="modal-body">
                    <?php if (!$canCreateTransfers): ?>
                        <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                            <div>
                                <?php if (empty($primaryWarehouse)): ?>
                                    لا يوجد مخزن رئيسي معرف بعد. يرجى إنشاء المخزن الرئيسي أولاً.
                                <?php elseif (!$hasDestinationWarehouses): ?>
                                    لا توجد مخازن وجهة نشطة متاحة حالياً. يرجى إنشاء أو تفعيل مخزن وجهة قبل إرسال طلب النقل.
                                <?php elseif (!$hasFinishedBatches): ?>
                                    لا توجد تشغيلات جاهزة للنقل حالياً. تأكد من إضافة منتجات نهائية بتشغيلاتها في جدول الإنتاج.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">من المخزن</label>
                                <div class="form-control-plaintext fw-semibold" id="transferFromWarehouse">
                                    <?php echo htmlspecialchars($primaryWarehouse['name']); ?> (مخزن رئيسي)
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="transferToWarehouse">إلى المخزن <span class="text-danger">*</span></label>
                                <select class="form-select" name="to_warehouse_id" id="transferToWarehouse" required>
                                    <option value="">اختر المخزن الوجهة</option>
                                    <?php foreach ($destinationWarehouses as $warehouse): ?>
                                        <option value="<?php echo intval($warehouse['id']); ?>">
                                            <?php echo htmlspecialchars($warehouse['name']); ?>
                                            (<?php echo $warehouse['warehouse_type'] === 'vehicle' ? 'مخزن سيارة' : 'مخزن'; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="transferDate">تاريخ النقل <span class="text-danger">*</span></label>
                                <input type="date" id="transferDate" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="transferReason">السبب</label>
                                <input type="text" id="transferReason" class="form-control" name="reason" placeholder="مثال: تجهيز مخزون لمندوب المبيعات">
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-label">عناصر النقل <span class="text-danger">*</span></div>
                            <div id="mainWarehouseTransferItems">
                                <div class="transfer-item row g-2 align-items-end mb-2">
                                    <div class="col-md-5">
                                        <label class="form-label small text-muted" for="transferProduct0">المنتج</label>
                                        <select id="transferProduct0" class="form-select product-select" name="items[0][product_select]" required>
                                            <option value="">اختر المنتج</option>
                                            <?php foreach ($finishedProductOptions as $option): ?>
                                                <option value="<?php echo intval($option['product_id'] ?? 0); ?>"
                                                        data-product-id="<?php echo intval($option['product_id'] ?? 0); ?>"
                                                        data-batch-id="<?php echo intval($option['batch_id']); ?>"
                                                        data-batch-number="<?php echo htmlspecialchars($option['batch_number']); ?>"
                                                        data-available="<?php echo number_format((float)$option['quantity_available'], 2, '.', ''); ?>">
                                                    <?php echo htmlspecialchars($option['product_name']); ?>
                                                    - تشغيلة <?php echo htmlspecialchars($option['batch_number'] ?: 'بدون'); ?>
                                                    (متاح: <?php echo number_format((float)$option['quantity_available'], 2); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted" for="transferQuantity0">الكمية</label>
                                        <input type="number" id="transferQuantity0" step="0.01" min="0.01" class="form-control quantity-input" name="items[0][quantity]" placeholder="الكمية" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted d-block">&nbsp;</label>
                                        <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-transfer-item" title="حذف العنصر">
                                            <i class="bi bi-trash"></i> حذف
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted available-hint d-block"></small>
                                        <input type="hidden" id="transferProductId0" name="items[0][product_id]" class="selected-product-id">
                                        <input type="hidden" id="transferBatchId0" name="items[0][batch_id]" class="selected-batch-id">
                                        <input type="hidden" id="transferBatchNumber0" name="items[0][batch_number]" class="selected-batch-number">
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addTransferItemBtn">
                                <i class="bi bi-plus-circle me-1"></i>
                                إضافة منتج آخر
                            </button>
                        </div>

                        <div class="alert alert-info d-flex align-items-center gap-2">
                            <i class="bi bi-info-circle-fill fs-5"></i>
                            <div>
                                سيتم إرسال طلب النقل للمدير للمراجعة والموافقة قبل خصم الكميات من المخزن الرئيسي.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" <?php echo $canCreateTransfers ? '' : 'disabled'; ?>>
                        إرسال الطلب
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// كود نظيف تماماً بدون أي event listeners معقدة
if (!window.transferFormInitialized) {
    window.transferFormInitialized = true;
    
    document.addEventListener('DOMContentLoaded', function () {
        const transferForm = document.getElementById('mainWarehouseTransferForm');
        const itemsContainer = document.getElementById('mainWarehouseTransferItems');
        const addItemButton = document.getElementById('addTransferItemBtn');
        
        if (!transferForm || !itemsContainer) {
            return;
        }

        let transferItemIndex = 1;
        let allFinishedProductOptions = <?php echo json_encode($finishedProductOptions ?? []); ?>;

        // استخدام event delegation - لا نضيف listeners لكل عنصر
        itemsContainer.addEventListener('change', function(e) {
            if (e.target.classList.contains('product-select')) {
                const select = e.target;
                const row = select.closest('.transfer-item');
                if (!row) return;
                
                const selectedOption = select.options[select.selectedIndex];
                const available = selectedOption ? parseFloat(selectedOption.dataset.available || '0') : 0;
                const productIdInput = row.querySelector('.selected-product-id');
                const batchIdInput = row.querySelector('.selected-batch-id');
                const batchNumberInput = row.querySelector('.selected-batch-number');
                const availableHint = row.querySelector('.available-hint');
                const quantityInput = row.querySelector('.quantity-input');
                
                if (productIdInput) {
                    productIdInput.value = selectedOption ? parseInt(selectedOption.dataset.productId || '0', 10) : '';
                }
                if (batchIdInput) {
                    batchIdInput.value = selectedOption ? parseInt(selectedOption.dataset.batchId || '0', 10) : '';
                }
                if (batchNumberInput) {
                    batchNumberInput.value = selectedOption ? selectedOption.dataset.batchNumber || '' : '';
                }
                
                if (availableHint) {
                    if (selectedOption && selectedOption.value) {
                        availableHint.textContent = `الكمية المتاحة: ${available.toLocaleString('ar-EG')} وحدة`;
                    } else {
                        availableHint.textContent = '';
                    }
                }
                
                if (quantityInput) {
                    if (available > 0) {
                        quantityInput.setAttribute('max', available);
                        if (parseFloat(quantityInput.value || '0') > available) {
                            quantityInput.value = available;
                        }
                    } else {
                        quantityInput.removeAttribute('max');
                    }
                }
            }
        });

        function buildItemRow(index) {
            const wrapper = document.createElement('div');
            wrapper.className = 'transfer-item row g-2 align-items-end mb-2';
            
            let optionsHtml = '<option value="">اختر المنتج</option>';
            allFinishedProductOptions.forEach(function(option) {
                const productId = parseInt(option.product_id || 0, 10);
                const batchId = parseInt(option.batch_id || 0, 10);
                const batchNumber = (option.batch_number || '').replace(/"/g, '&quot;');
                const productName = (option.product_name || 'غير محدد').replace(/"/g, '&quot;');
                const available = parseFloat(option.quantity_available || 0);
                const availableFormatted = available.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                optionsHtml += `<option value="${productId}"
                        data-product-id="${productId}"
                        data-batch-id="${batchId}"
                        data-batch-number="${batchNumber}"
                        data-available="${available.toFixed(2)}">
                    ${productName} - تشغيلة ${batchNumber || 'بدون'} (متاح: ${availableFormatted})
                </option>`;
            });
            
            wrapper.innerHTML = `
                <div class="col-md-5">
                    <label class="form-label small text-muted" for="transferProduct${index}">المنتج</label>
                    <select id="transferProduct${index}" class="form-select product-select" name="items[${index}][product_select]" required>
                        ${optionsHtml}
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted" for="transferQuantity${index}">الكمية</label>
                    <input type="number" id="transferQuantity${index}" step="0.01" min="0.01" class="form-control quantity-input" name="items[${index}][quantity]" placeholder="الكمية" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted d-block">&nbsp;</label>
                    <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-transfer-item" title="حذف العنصر">
                        <i class="bi bi-trash"></i> حذف
                    </button>
                </div>
                <div class="col-12">
                    <small class="text-muted available-hint d-block"></small>
                    <input type="hidden" id="transferProductId${index}" name="items[${index}][product_id]" class="selected-product-id">
                    <input type="hidden" id="transferBatchId${index}" name="items[${index}][batch_id]" class="selected-batch-id">
                    <input type="hidden" id="transferBatchNumber${index}" name="items[${index}][batch_number]" class="selected-batch-number">
                </div>
            `;
            return wrapper;
        }

        if (addItemButton) {
            addItemButton.addEventListener('click', () => {
                const newRow = buildItemRow(transferItemIndex);
                itemsContainer.appendChild(newRow);
                transferItemIndex += 1;
            });
        }

        // استخدام event delegation لحذف العناصر
        itemsContainer.addEventListener('click', (event) => {
            const removeButton = event.target.closest('.remove-transfer-item');
            if (!removeButton) {
                return;
            }

            const rows = itemsContainer.querySelectorAll('.transfer-item');
            if (rows.length <= 1) {
                return;
            }

            removeButton.closest('.transfer-item').remove();
        });

        // تهيئة العناصر الموجودة مسبقاً
        itemsContainer.querySelectorAll('.product-select').forEach(select => {
            const row = select.closest('.transfer-item');
            if (row) {
                const selectedOption = select.options[select.selectedIndex];
                if (selectedOption && selectedOption.value) {
                    const available = parseFloat(selectedOption.dataset.available || '0');
                    const quantityInput = row.querySelector('.quantity-input');
                    if (quantityInput && available > 0) {
                        quantityInput.setAttribute('max', available);
                    }
                }
            }
        });

        // إدارة نموذج طلب النقل من المخزن الرئيسي
        const requestTransferModal = document.getElementById('requestTransferModal');
        if (requestTransferModal) {
            // تحميل المنتجات من المخزن الرئيسي عند فتح النموذج لأول مرة (مثل صفحة مخزون السيارات)
            if (allFinishedProductOptions.length === 0 && <?php echo $primaryWarehouse ? 'true' : 'false'; ?>) {
                const loadProductsOnce = function() {
                    if (allFinishedProductOptions.length > 0) {
                        return; // تم التحميل بالفعل
                    }

                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('ajax', 'load_products');
                    currentUrl.searchParams.set('warehouse_id', <?php echo $primaryWarehouse['id'] ?? 'null'; ?>);

                    fetch(currentUrl.toString())
                        .then(response => {
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                return response.text().then(text => {
                                    throw new Error('Expected JSON but got: ' + text.substring(0, 100));
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success && data.products) {
                                allFinishedProductOptions = data.products;
                                // تحديث القوائم بدون إعادة ربط الأحداث
                                if (itemsContainer) {
                                    itemsContainer.querySelectorAll('.product-select').forEach(select => {
                                        const currentValue = select.value;
                                        select.innerHTML = '<option value="">اختر المنتج</option>';
                                        allFinishedProductOptions.forEach(option => {
                                            const optionElement = document.createElement('option');
                                            optionElement.value = option.product_id || 0;
                                            optionElement.dataset.productId = option.product_id || 0;
                                            optionElement.dataset.batchId = option.batch_id;
                                            optionElement.dataset.batchNumber = option.batch_number || '';
                                            optionElement.dataset.available = option.quantity_available || 0;
                                            optionElement.textContent = `${option.product_name} - تشغيلة ${option.batch_number || 'بدون'} (متاح: ${parseFloat(option.quantity_available || 0).toFixed(2)})`;
                                            if (currentValue && option.product_id == currentValue) {
                                                optionElement.selected = true;
                                            }
                                            select.appendChild(optionElement);
                                        });
                                    });
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error loading products:', error);
                        });
                };

                // استدعاء التحميل عند فتح النموذج مرة واحدة فقط
                requestTransferModal.addEventListener('show.bs.modal', loadProductsOnce, { once: true });
            }

            // تحديث token عند فتح النموذج
            const transferTokenInput = document.getElementById('transferToken');
            if (transferTokenInput) {
                requestTransferModal.addEventListener('show.bs.modal', function() {
                    if (transferTokenInput.value === '') {
                        transferTokenInput.value = '<?php echo htmlspecialchars($_SESSION['transfer_submission_token'] ?? '', ENT_QUOTES); ?>';
                    }
                });
            }
            
            // إصلاح تموضع الـ modal عند فتحه
            requestTransferModal.addEventListener('shown.bs.modal', function() {
                // التأكد من وجود backdrop واحد فقط
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    for (let i = 1; i < backdrops.length; i++) {
                        backdrops[i].remove();
                    }
                }
                
                // إصلاح تموضع الـ modal
                requestTransferModal.style.position = 'fixed';
                requestTransferModal.style.top = '0';
                requestTransferModal.style.left = '0';
                requestTransferModal.style.zIndex = '1055';
                requestTransferModal.style.width = '100%';
                requestTransferModal.style.height = '100%';
                requestTransferModal.style.display = 'block';
                
                // التأكد من أن الـ modal-dialog في الموضع الصحيح
                const modalDialog = requestTransferModal.querySelector('.modal-dialog');
                if (modalDialog) {
                    modalDialog.style.position = 'relative';
                    modalDialog.style.zIndex = 'auto';
                    modalDialog.style.margin = '1.75rem auto';
                }
                
                // التأكد من أن الـ modal-content في الموضع الصحيح
                const modalContent = requestTransferModal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.position = 'relative';
                    modalContent.style.zIndex = 'auto';
                }
            });
            
            // تنظيف عند إغلاق النموذج
            requestTransferModal.addEventListener('hidden.bs.modal', function() {
                // إزالة أي backdrops متبقية
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => backdrop.remove());
                
                // إزالة class modal-open من body إذا لم تكن هناك modals أخرى
                const otherModals = document.querySelectorAll('.modal.show');
                if (otherModals.length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            });
        }

        // تعطيل زر الإرسال بعد النقر لمنع double-click
        let isSubmitting = false;
        transferForm.addEventListener('submit', (event) => {
            if (isSubmitting) {
                event.preventDefault();
                return;
            }
            
            isSubmitting = true;
            const submitButton = transferForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جارٍ الإرسال...';
            }

            const rows = itemsContainer.querySelectorAll('.transfer-item');
            if (!rows.length) {
                isSubmitting = false;
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال الطلب';
                }
                event.preventDefault();
                alert('أضف منتجاً واحداً على الأقل قبل إرسال الطلب.');
                return;
            }

            for (const row of rows) {
                const select = row.querySelector('.product-select');
                const quantityInput = row.querySelector('.quantity-input');

                if (!select || !quantityInput) {
                    event.preventDefault();
                    isSubmitting = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال الطلب';
                    }
                    alert('يرجى التأكد من إدخال بيانات صحيحة لكل منتج.');
                    return;
                }

                if (!select.value) {
                    event.preventDefault();
                    isSubmitting = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال الطلب';
                    }
                    alert('اختر المنتج المراد نقله.');
                    return;
                }

                const max = parseFloat(quantityInput.getAttribute('max') || '0');
                const min = parseFloat(quantityInput.getAttribute('min') || '0');
                const value = parseFloat(quantityInput.value || '0');

                if (value < min) {
                    event.preventDefault();
                    isSubmitting = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال الطلب';
                    }
                    alert('يرجى إدخال كمية أكبر من الصفر.');
                    return;
                }

                if (max > 0 && value > max) {
                    event.preventDefault();
                    isSubmitting = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال الطلب';
                    }
                    alert('الكمية المطلوبة تتجاوز المتاح في المخزن الرئيسي.');
                    return;
                }
            }

            const destinationSelect = document.getElementById('transferToWarehouse');
            if (destinationSelect && !destinationSelect.value) {
                event.preventDefault();
                isSubmitting = false;
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال الطلب';
                }
                alert('يرجى اختيار المخزن الوجهة قبل إرسال الطلب.');
                return;
            }
        });
    });
}
</script>

<div class="modal fade" id="batchDetailsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل التشغيلة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="batchDetailsLoading" class="d-flex justify-content-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جارٍ التحميل...</span>
                    </div>
                </div>
                <div id="batchDetailsError" class="alert alert-danger d-none" role="alert"></div>
                <div id="batchDetailsContent" class="d-none">
                    <div id="batchSummarySection" class="mb-4"></div>
                    <div id="batchMaterialsSection" class="mb-4"></div>
                    <div id="batchRawMaterialsSection" class="mb-4"></div>
                    <div id="batchWorkersSection" class="mb-0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
    // منع التهيئة المتعددة
    if (window.finalProductsInitialized) {
        console.warn('Final products script already initialized, skipping duplicate initialization');
    } else {
        window.finalProductsInitialized = true;
        
    // دالة لعرض رسائل Toast (مشتركة بين جميع الأقسام)
    function showToast(message, type = 'warning') {
        const toastContainer = document.getElementById('toastContainer') || (function() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '11000';
            document.body.appendChild(container);
            return container;
        })();
        
        const toastId = 'toast-' + Date.now();
        const bgClass = type === 'error' || type === 'danger' ? 'bg-danger' : 
                       type === 'success' ? 'bg-success' : 'bg-warning';
        
        const toastHtml = `
            <div id="${toastId}" class="toast ${bgClass} text-white" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header ${bgClass} text-white border-0">
                    <strong class="me-auto">${type === 'success' ? 'نجح' : type === 'error' || type === 'danger' ? 'خطأ' : 'تحذير'}</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="إغلاق"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        
        if (typeof bootstrap !== 'undefined' && typeof bootstrap.Toast !== 'undefined') {
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: 5000
            });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function() {
                toastElement.remove();
            });
        } else {
            // Fallback إذا لم يكن Bootstrap متاحاً
            setTimeout(function() {
                if (toastElement && toastElement.parentNode) {
                    toastElement.remove();
                }
            }, 5000);
        }
    }
    
    // جعل الدالة متاحة عالمياً
    window.showToast = showToast;
        
    // ========== إدارة نماذج تفاصيل التشغيلة ==========
    const batchDetailsEndpoint = <?php echo json_encode(getRelativeUrl('api/production/get_batch_details.php')); ?>;
    let batchDetailsIsLoading = false;

    function createBatchDetailsModal() {
        if (document.getElementById('batchDetailsModal')) {
            return; // النموذج موجود بالفعل
        }
        
        const modal = document.createElement('div');
        modal.id = 'batchDetailsModal';
        modal.className = 'modal fade';
        modal.setAttribute('tabindex', '-1');
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">تفاصيل التشغيلة</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body">
                        <div id="batchDetailsLoading" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">جارٍ التحميل...</span>
                            </div>
                        </div>
                        <div id="batchDetailsError" class="alert alert-danger d-none"></div>
                        <div id="batchDetailsContent" class="d-none">
                            <div id="batchSummarySection" class="mb-4"></div>
                            <div id="batchMaterialsSection" class="mb-4"></div>
                            <div id="batchRawMaterialsSection" class="mb-4"></div>
                            <div id="batchWorkersSection" class="mb-0"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    function formatDateValue(value) {
        return value ? value : '—';
    }

    function formatQuantity(value, unit) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        const numeric = Number(value);
        const formatted = Number.isFinite(numeric)
            ? numeric.toLocaleString('ar-EG')
            : value;
        return unit ? `${formatted} ${unit}` : `${formatted}`;
    }

    function renderBatchDetails(data) {
        const summarySection = document.getElementById('batchSummarySection');
        const materialsSection = document.getElementById('batchMaterialsSection');
        const rawMaterialsSection = document.getElementById('batchRawMaterialsSection');
        const workersSection = document.getElementById('batchWorkersSection');

        const batchNumber = data.batch_number ?? '—';
        const summaryRows = [
            ['رقم التشغيلة', batchNumber],
            ['المنتج', data.product_name ?? '—'],
            ['تاريخ الإنتاج', formatDateValue(data.production_date)],
            ['الكمية المنتجة', data.quantity_produced ?? data.quantity ?? '—']
        ];

        if (data.honey_supplier_name) {
            summaryRows.push(['مورد العسل', data.honey_supplier_name]);
        }
        if (data.packaging_supplier_name) {
            summaryRows.push(['مورد التعبئة', data.packaging_supplier_name]);
        }
        if (data.notes) {
            summaryRows.push(['ملاحظات', data.notes]);
        }

        summarySection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص التشغيلة</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <tbody>
                                ${summaryRows.map(([label, value]) => `
                                    <tr>
                                        <th class="w-25">${label}</th>
                                        <td>${value}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        const packagingItems = Array.isArray(data.packaging_materials) ? data.packaging_materials : [];
        if (packagingItems.length > 0) {
            materialsSection.innerHTML = `
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>مواد التعبئة المستخدمة</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            ${packagingItems.map(item => `
                                <li class="mb-2">
                                    <strong>${item.name ?? '—'}</strong>
                                    <div class="text-muted small">
                                        ${formatQuantity(item.quantity_used, item.unit ?? '')}
                                        ${item.supplier_name ? ` • المورد: ${item.supplier_name}` : ''}
                                    </div>
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                </div>
            `;
        } else {
            materialsSection.innerHTML = '';
        }

        const rawItems = Array.isArray(data.raw_materials) ? data.raw_materials : [];
        if (rawItems.length > 0) {
            rawMaterialsSection.innerHTML = `
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-droplet-half me-2"></i>المواد الخام المستخدمة</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            ${rawItems.map(item => `
                                <li class="mb-2">
                                    <strong>${item.name ?? '—'}</strong>
                                    <div class="text-muted small">
                                        ${formatQuantity(item.quantity_used, item.unit ?? '')}
                                        ${item.supplier_name ? ` • المورد: ${item.supplier_name}` : ''}
                                    </div>
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                </div>
            `;
        } else {
            rawMaterialsSection.innerHTML = '';
        }

        const workers = Array.isArray(data.workers) ? data.workers : [];
        if (workers.length > 0) {
            workersSection.innerHTML = `
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-people-fill me-2"></i>فريق الإنتاج</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            ${workers.map(worker => `
                                <li class="mb-2">
                                    <strong>${worker.full_name ?? worker.username ?? '—'}</strong>
                                    <div class="text-muted small">${worker.role ?? 'عامل إنتاج'}</div>
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                </div>
            `;
        } else {
            workersSection.innerHTML = '';
        }
    }

    function showBatchDetailsModal(batchNumber, productName) {
        if (!batchNumber || typeof batchNumber !== 'string' || batchNumber.trim() === '') {
            console.error('Invalid batch number');
            return;
        }
        
        if (batchDetailsIsLoading) {
            return; // منع الطلبات المتعددة
        }
        
        if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal === 'undefined') {
            alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
            return;
        }
        
        createBatchDetailsModal();
        
        const modalElement = document.getElementById('batchDetailsModal');
        if (!modalElement) {
            console.error('Failed to create batch details modal');
            return;
        }
        
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
        const loader = modalElement.querySelector('#batchDetailsLoading');
        const errorAlert = modalElement.querySelector('#batchDetailsError');
        const contentWrapper = modalElement.querySelector('#batchDetailsContent');
        const modalTitle = modalElement.querySelector('.modal-title');
        
        if (modalTitle) {
            modalTitle.textContent = productName ? `تفاصيل التشغيلة - ${productName}` : 'تفاصيل التشغيلة';
        }
        
        loader.classList.remove('d-none');
        errorAlert.classList.add('d-none');
        contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = true;
        
        modalInstance.show();
        
        // تحميل البيانات
        fetch(batchDetailsEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_number: batchNumber })
        })
        .then(response => response.json())
        .then(data => {
            loader.classList.add('d-none');
            batchDetailsIsLoading = false;
            
            if (data.success && data.batch) {
                renderBatchDetails(data.batch);
                contentWrapper.classList.remove('d-none');
            } else {
                errorAlert.textContent = data.message || 'تعذر تحميل تفاصيل التشغيلة';
                errorAlert.classList.remove('d-none');
            }
        })
        .catch(error => {
            loader.classList.add('d-none');
            errorAlert.textContent = 'حدث خطأ أثناء تحميل التفاصيل';
            errorAlert.classList.remove('d-none');
            batchDetailsIsLoading = false;
            console.error('Error loading batch details:', error);
        });
    }


    // ========== ربط الأحداث للأزرار ==========
    function attachClickEvents() {
        // منع التهيئة المتعددة
        if (window.finalProductsClickEventsAttached) {
            return;
        }
        window.finalProductsClickEventsAttached = true;
        
        document.addEventListener('click', function(event) {
            // زر تفاصيل التشغيلة
            const detailsButton = event.target.closest('.js-batch-details');
            if (detailsButton) {
                event.preventDefault();
                event.stopPropagation();
                const batchNumber = detailsButton.dataset.batch;
                const productName = detailsButton.dataset.product || '';
                if (batchNumber) {
                    showBatchDetailsModal(batchNumber, productName);
                }
                return;
            }
            
            // فتح نموذج إضافة منتج خارجي
            const addExternalBtn = event.target.closest('.js-open-add-external-modal');
            if (addExternalBtn) {
                event.preventDefault();
                event.stopPropagation();
                const modal = document.getElementById('addExternalProductModal');
                if (modal) {
                    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
                        modalInstance.show();
                    } else {
                        // Fallback إذا لم يكن Bootstrap متاحاً
                        modal.style.display = 'block';
                        modal.classList.add('show');
                        document.body.classList.add('modal-open');
                        const backdrop = document.createElement('div');
                        backdrop.className = 'modal-backdrop fade show';
                        document.body.appendChild(backdrop);
                    }
                } else {
                    console.error('Modal addExternalProductModal not found');
                }
                return;
            }

            const copyButton = event.target.closest('.js-copy-batch');
            if (copyButton) {
                const batchNumber = copyButton.dataset.batch;
                if (!batchNumber) {
                    return;
                }

                const originalHtml = copyButton.innerHTML;
                const originalClasses = copyButton.className;

                function showCopiedFeedback(success) {
                    copyButton.className = success ? 'btn btn-success btn-sm' : 'btn btn-warning btn-sm';
                    copyButton.innerHTML = success
                        ? '<i class="bi bi-check-circle"></i> تم النسخ'
                        : '<i class="bi bi-exclamation-triangle"></i> تعذر النسخ';

                    setTimeout(() => {
                        copyButton.className = originalClasses;
                        copyButton.innerHTML = originalHtml;
                    }, 2000);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(batchNumber)
                        .then(() => showCopiedFeedback(true))
                        .catch(() => showCopiedFeedback(false));
                } else {
                    const tempInput = document.createElement('input');
                    tempInput.style.position = 'fixed';
                    tempInput.style.opacity = '0';
                    tempInput.value = batchNumber;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    try {
                        const successful = document.execCommand('copy');
                        showCopiedFeedback(successful);
                    } catch (err) {
                        showCopiedFeedback(false);
                    }
                    document.body.removeChild(tempInput);
                }
                return;
            }

            // زر تعديل كمية المنتج الخارجي
            const adjustButton = event.target.closest('.js-external-adjust');
            if (adjustButton) {
                event.preventDefault();
                event.stopPropagation();
                const modal = document.getElementById('externalStockModal');
                if (!modal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                    return;
                }
                
                const form = modal.querySelector('form');
                if (!form) return;
                
                const productId = adjustButton.dataset.product || '';
                const productName = adjustButton.dataset.name || '';
                const mode = adjustButton.dataset.mode === 'discard' ? 'discard' : 'add';
                
                // تحديث حقول النموذج
                const productIdInput = form.querySelector('input[name="product_id"]');
                const operationInput = form.querySelector('input[name="operation"]');
                const nameField = modal.querySelector('.js-external-stock-name');
                const title = modal.querySelector('.js-external-stock-title');
                const label = modal.querySelector('.js-external-stock-label');
                const submitButton = modal.querySelector('.js-external-stock-submit');
                
                if (productIdInput) productIdInput.value = productId;
                if (operationInput) operationInput.value = mode;
                if (nameField) nameField.value = productName;
                if (title) title.textContent = mode === 'discard' ? 'إتلاف كمية من المنتج الخارجي' : 'إضافة كمية للمنتج الخارجي';
                if (label) label.textContent = mode === 'discard' ? 'الكمية المراد إتلافها' : 'الكمية المراد إضافتها';
                if (submitButton) {
                    submitButton.textContent = mode === 'discard' ? 'تأكيد الإتلاف' : 'حفظ الكمية';
                    submitButton.className = mode === 'discard' ? 'btn btn-danger js-external-stock-submit' : 'btn btn-primary js-external-stock-submit';
                }
                
                bootstrap.Modal.getOrCreateInstance(modal).show();
                return;
            }

            // زر تعديل المنتج الخارجي
            const editButton = event.target.closest('.js-external-edit');
            if (editButton) {
                event.preventDefault();
                event.stopPropagation();
                const modal = document.getElementById('editExternalProductModal');
                if (!modal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                    return;
                }
                
                const form = modal.querySelector('form');
                if (!form) return;
                
                // تحديث حقول النموذج
                const productIdInput = form.querySelector('input[name="product_id"]');
                const nameInput = form.querySelector('input[name="edit_name"]');
                const channelSelect = form.querySelector('select[name="edit_channel"]');
                const priceInput = form.querySelector('input[name="edit_price"]');
                const unitInput = form.querySelector('input[name="edit_unit"]');
                const descriptionInput = form.querySelector('textarea[name="edit_description"]');
                
                if (productIdInput) productIdInput.value = editButton.dataset.product || '';
                if (nameInput) nameInput.value = editButton.dataset.name || '';
                if (channelSelect) {
                    const channelValue = editButton.dataset.channel || 'company';
                    channelSelect.value = ['company', 'delegate', 'other'].includes(channelValue) ? channelValue : 'company';
                }
                if (priceInput) priceInput.value = editButton.dataset.price || '0';
                if (unitInput) unitInput.value = editButton.dataset.unit || 'قطعة';
                if (descriptionInput) descriptionInput.value = editButton.dataset.description || '';
                
                bootstrap.Modal.getOrCreateInstance(modal).show();
                return;
            }
        });
    }
    
    // جعل الدالة متاحة عالمياً
    window.showBatchDetailsModal = showBatchDetailsModal;
    
    // تهيئة الأحداث عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachClickEvents);
    } else {
        attachClickEvents();
    }
}
</script>

<!-- Modal طلب استلام منتجات من بضاعة المندوب -->
<div class="modal fade" id="receiveFromSalesRepModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i>طلب استلام منتجات من بضاعة المندوب</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="receiveFromSalesRepForm">
                <input type="hidden" name="action" value="create_transfer_from_sales_rep">
                <input type="hidden" name="transfer_token" value="<?php echo htmlspecialchars($_SESSION['transfer_submission_token'] ?? ''); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المندوب <span class="text-danger">*</span></label>
                        <select class="form-select" name="sales_rep_id" id="sales_rep_id_receive" required>
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
                        <label class="form-label">تاريخ الاستلام <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">السبب</label>
                        <input type="text" class="form-control" name="reason" placeholder="سبب الاستلام (اختياري)">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3">المنتجات المراد استلامها:</h6>
                    
                    <div id="salesRepProductsContainerReceive">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            يرجى اختيار المندوب أولاً لعرض المنتجات المتاحة في مخزن سيارته.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success" id="submitReceiveSalesRepBtn" disabled>
                        <i class="bi bi-check-circle me-2"></i>إنشاء طلب الاستلام
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// معالجة modal طلب استلام منتجات من بضاعة المندوب
document.addEventListener('DOMContentLoaded', function() {
    const salesRepSelectReceive = document.getElementById('sales_rep_id_receive');
    const productsContainerReceive = document.getElementById('salesRepProductsContainerReceive');
    
    if (salesRepSelectReceive) {
        salesRepSelectReceive.addEventListener('change', function() {
            const salesRepId = this.value;
            productsContainerReceive.innerHTML = '<div class="text-center py-3"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
            
            if (salesRepId) {
                // جلب المنتجات من مخزن سيارة المندوب
                const currentUrl = window.location.href.split('?')[0];
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('ajax', 'get_vehicle_inventory');
                urlParams.set('vehicle_id', salesRepId);
                fetch(currentUrl + '?' + urlParams.toString())
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.products) {
                            displaySalesRepProductsReceive(data.products);
                        } else {
                            productsContainerReceive.innerHTML = '<div class="alert alert-info">لا توجد منتجات في مخزن سيارة هذا المندوب.</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        productsContainerReceive.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء جلب المنتجات.</div>';
                    });
            } else {
                productsContainerReceive.innerHTML = '<div class="alert alert-info">يرجى اختيار المندوب أولاً.</div>';
            }
        });
    }
    
    function displaySalesRepProductsReceive(products) {
        if (products.length === 0) {
            productsContainerReceive.innerHTML = '<div class="alert alert-info">لا توجد منتجات في مخزن سيارة هذا المندوب.</div>';
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
            html += '<td><input type="checkbox" class="form-check-input product-checkbox-receive" ';
            html += `data-product-id="${productId}" `;
            html += `data-batch-id="${batchId}" `;
            html += `data-batch-number="${batchNumber}" `;
            html += `data-product-name="${productName.replace(/"/g, '&quot;')}" `;
            html += `data-available="${availableQty}"></td>`;
            html += `<td>${batchNumber}</td>`;
            html += `<td>${productName}</td>`;
            html += `<td>${availableQty.toFixed(2)} ${unit}</td>`;
            html += `<td><input type="number" step="0.01" min="0" max="${availableQty}" `;
            html += `class="form-control form-control-sm quantity-input-receive" `;
            html += `data-product-id="${productId}" `;
            html += `data-batch-id="${batchId}" disabled></td>`;
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        productsContainerReceive.innerHTML = html;
        
        // إضافة معالجات الأحداث
        document.querySelectorAll('.product-checkbox-receive').forEach(cb => {
            cb.addEventListener('change', function() {
                const quantityInput = this.closest('tr').querySelector('.quantity-input-receive');
                quantityInput.disabled = !this.checked;
                if (!this.checked) {
                    quantityInput.value = '';
                }
                updateReceiveSubmitButton();
            });
        });
        
        // إضافة معالجات للكميات
        document.querySelectorAll('.quantity-input-receive').forEach(input => {
            input.addEventListener('input', function() {
                updateReceiveSubmitButton();
            });
        });
        
        updateReceiveSubmitButton();
    }
    
    function updateReceiveSubmitButton() {
        const submitBtn = document.getElementById('submitReceiveSalesRepBtn');
        if (!submitBtn) return;
        
        const checkedBoxes = document.querySelectorAll('.product-checkbox-receive:checked');
        let hasValidQuantity = false;
        
        checkedBoxes.forEach(cb => {
            const quantityInput = cb.closest('tr').querySelector('.quantity-input-receive');
            const quantity = parseFloat(quantityInput.value) || 0;
            if (quantity > 0) {
                hasValidQuantity = true;
            }
        });
        
        submitBtn.disabled = checkedBoxes.length === 0 || !hasValidQuantity;
    }
    
    // معالجة إرسال النموذج
    const receiveForm = document.getElementById('receiveFromSalesRepForm');
    if (receiveForm) {
        receiveForm.addEventListener('submit', function(e) {
            const checkedBoxes = this.querySelectorAll('.product-checkbox-receive:checked');
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('يرجى اختيار منتج واحد على الأقل.');
                return false;
            }
            
            checkedBoxes.forEach((cb, index) => {
                const quantityInput = cb.closest('tr').querySelector('.quantity-input-receive');
                const quantity = parseFloat(quantityInput.value) || 0;
                
                if (quantity <= 0) {
                    e.preventDefault();
                    alert('يرجى إدخال كمية صحيحة للمنتج: ' + cb.dataset.productName);
                    return false;
                }
                
                const productId = cb.dataset.productId || '';
                const batchId = cb.dataset.batchId || '';
                const batchNumber = cb.dataset.batchNumber || '';
                
                const itemPrefix = `items[${index}]`;
                const productIdInput = document.createElement('input');
                productIdInput.type = 'hidden';
                productIdInput.name = `${itemPrefix}[product_id]`;
                productIdInput.value = productId;
                this.appendChild(productIdInput);
                
                if (batchId) {
                    const batchIdInput = document.createElement('input');
                    batchIdInput.type = 'hidden';
                    batchIdInput.name = `${itemPrefix}[batch_id]`;
                    batchIdInput.value = batchId;
                    this.appendChild(batchIdInput);
                }
                
                if (batchNumber) {
                    const batchNumberInput = document.createElement('input');
                    batchNumberInput.type = 'hidden';
                    batchNumberInput.name = `${itemPrefix}[batch_number]`;
                    batchNumberInput.value = batchNumber;
                    this.appendChild(batchNumberInput);
                }
                
                const quantityInput = document.createElement('input');
                quantityInput.type = 'hidden';
                quantityInput.name = `${itemPrefix}[quantity]`;
                quantityInput.value = quantity;
                this.appendChild(quantityInput);
            });
        });
        
        // تنظيف النموذج عند إغلاق الـ modal
        const receiveModal = document.getElementById('receiveFromSalesRepModal');
        if (receiveModal) {
            receiveModal.addEventListener('hidden.bs.modal', function() {
                receiveForm.reset();
                productsContainerReceive.innerHTML = '<div class="alert alert-info">يرجى اختيار المندوب أولاً.</div>';
                receiveForm.querySelectorAll('input[type="hidden"][name^="items"]').forEach(input => input.remove());
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



