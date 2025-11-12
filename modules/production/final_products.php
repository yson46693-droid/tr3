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

$sessionErrorKey = 'production_inventory_error';
$sessionSuccessKey = 'production_inventory_success';

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

try {
    $specificationsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_specifications'");
    if (empty($specificationsTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_specifications` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL,
              `raw_materials` longtext DEFAULT NULL,
              `packaging` longtext DEFAULT NULL,
              `notes` longtext DEFAULT NULL,
              `created_by` int(11) DEFAULT NULL,
              `updated_by` int(11) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `updated_by` (`updated_by`),
              CONSTRAINT `product_specifications_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `product_specifications_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    error_log('final_products: failed ensuring product_specifications table -> ' . $e->getMessage());
}

if ($isManager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectUrl = $_SERVER['REQUEST_URI'] ?? $finalProductsUrl;

    if ($action === 'add_specification') {
        $productName   = trim($_POST['product_name'] ?? '');
        $rawMaterials  = trim($_POST['raw_materials'] ?? '');
        $packaging     = trim($_POST['packaging'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');

        if ($productName === '') {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال اسم المنتج للمواصفة الجديدة.';
        } else {
            try {
                $db->execute(
                    "INSERT INTO product_specifications (product_name, raw_materials, packaging, notes, created_by)
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $productName,
                        $rawMaterials !== '' ? $rawMaterials : null,
                        $packaging !== '' ? $packaging : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                    ]
                );
                $_SESSION[$sessionSuccessKey] = 'تم إضافة مواصفة المنتج بنجاح.';
            } catch (Exception $e) {
                error_log('final_products: failed adding specification -> ' . $e->getMessage());
                $_SESSION[$sessionErrorKey] = 'تعذر إضافة المواصفة. يرجى المحاولة لاحقاً.';
            }
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'edit_specification') {
        $specId        = intval($_POST['spec_id'] ?? 0);
        $productName   = trim($_POST['product_name'] ?? '');
        $rawMaterials  = trim($_POST['raw_materials'] ?? '');
        $packaging     = trim($_POST['packaging'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');

        if ($specId <= 0 || $productName === '') {
            $_SESSION[$sessionErrorKey] = 'بيانات المواصفة غير مكتملة.';
            header('Location: ' . $redirectUrl);
            exit;
        }

        try {
            $existingSpecification = $db->queryOne("SELECT id FROM product_specifications WHERE id = ?", [$specId]);
            if (!$existingSpecification) {
                $_SESSION[$sessionErrorKey] = 'المواصفة المحددة غير موجودة.';
            } else {
                $db->execute(
                    "UPDATE product_specifications 
                     SET product_name = ?, raw_materials = ?, packaging = ?, notes = ?, updated_by = ?, updated_at = NOW()
                     WHERE id = ?",
                    [
                        $productName,
                        $rawMaterials !== '' ? $rawMaterials : null,
                        $packaging !== '' ? $packaging : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                        $specId,
                    ]
                );
                $_SESSION[$sessionSuccessKey] = 'تم تحديث مواصفة المنتج بنجاح.';
            }
        } catch (Exception $e) {
            error_log('final_products: failed updating specification -> ' . $e->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر تحديث المواصفة. يرجى المحاولة لاحقاً.';
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}

$productSpecifications = [];
try {
    $productSpecifications = $db->query("
        SELECT ps.*, 
               creator.full_name AS creator_name,
               updater.full_name AS updater_name
        FROM product_specifications ps
        LEFT JOIN users creator ON ps.created_by = creator.id
        LEFT JOIN users updater ON ps.updated_by = updater.id
        ORDER BY ps.created_at DESC
    ");
} catch (Exception $e) {
    error_log('final_products: failed retrieving product specifications -> ' . $e->getMessage());
    $productSpecifications = [];
}

$currentPageSlug = $_GET['page'] ?? 'inventory';
$currentSection = $_GET['section'] ?? null;
$baseQueryString = '?page=' . urlencode($currentPageSlug);
if ($currentSection !== null && $currentSection !== '') {
    $baseQueryString .= '&section=' . urlencode($currentSection);
}

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

        $finishedProductOptions = getFinishedProductBatchOptions();
        $hasDestinationWarehouses = !empty($destinationWarehouses);
        $hasFinishedBatches = !empty($finishedProductOptions);

        $canCreateTransfers = !empty($primaryWarehouse) && $hasDestinationWarehouses && $hasFinishedBatches;
    } catch (Exception $warehouseException) {
        error_log('Production inventory warehouse setup error: ' . $warehouseException->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_transfer') {
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
                $productIds = array_values(array_unique(array_column($transferItems, 'product_id')));

                if (!empty($productIds)) {
                    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                    $stockRows = $db->query(
                        "SELECT id, name, quantity FROM products WHERE id IN ($placeholders) AND (product_type IS NULL OR product_type = 'internal')",
                        $productIds
                    );
                    $stockMap = [];

                    foreach ($stockRows as $row) {
                        $stockMap[intval($row['id'])] = [
                            'quantity' => floatval($row['quantity'] ?? 0),
                            'name' => $row['name'] ?? ''
                        ];
                    }

                    foreach ($transferItems as $transferItem) {
                        $productId = $transferItem['product_id'];
                        if (!isset($stockMap[$productId])) {
                            $transferErrors[] = 'المنتج المحدد غير موجود في المخزن الرئيسي.';
                            break;
                        }

                        if ($transferItem['quantity'] > $stockMap[$productId]['quantity']) {
                            $transferErrors[] = sprintf(
                                'الكمية المطلوبة للمنتج "%s" غير متاحة في المخزون الحالي.',
                                $stockMap[$productId]['name']
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
                            'quantity' => $item['quantity'],
                            'notes' => $item['notes'] ?? null
                        ];
                    }, $transferItems),
                    $reason !== '' ? $reason : null,
                    $notes !== '' ? $notes : null,
                    $currentUser['id'] ?? null
                );

                if (!empty($result['success'])) {
                    $_SESSION[$sessionSuccessKey] = sprintf(
                        'تم إرسال طلب النقل رقم %s إلى المدير للموافقة عليه.',
                        $result['transfer_number'] ?? '#'
                    );
                    header('Location: ' . $productionInventoryUrl);
                    exit;
                }

                $transferErrors[] = $result['message'] ?? 'تعذر إنشاء طلب النقل.';
            }
        }

        if (!empty($transferErrors)) {
            $error = implode(' | ', array_unique($transferErrors));
        }
    } elseif ($isManager && $postAction === 'create_external_product') {
        $name = trim((string)($_POST['external_name'] ?? ''));
        $channel = $_POST['external_channel'] ?? 'company';
        $initialQuantity = max(0, floatval($_POST['external_quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['external_price'] ?? 0));
        $unit = trim((string)($_POST['external_unit'] ?? 'قطعة'));
        $notesValue = trim((string)($_POST['external_description'] ?? ''));

        if ($name === '') {
            $_SESSION[$sessionErrorKey] = 'يرجى إدخال اسم المنتج الخارجي.';
            header('Location: ' . $managerInventoryUrl);
            exit;
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

        header('Location: ' . $managerInventoryUrl);
        exit;
    } elseif ($isManager && $postAction === 'adjust_external_stock') {
        $productId = intval($_POST['product_id'] ?? 0);
        $operation = $_POST['operation'] ?? 'add';
        $amount = max(0, floatval($_POST['quantity'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($productId <= 0 || $amount <= 0) {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار منتج وإدخال كمية صالحة.';
            header('Location: ' . $managerInventoryUrl);
            exit;
        }

        try {
            $productRow = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE id = ? AND product_type = 'external' LIMIT 1",
                [$productId]
            );

            if (!$productRow) {
                $_SESSION[$sessionErrorKey] = 'المنتج المطلوب غير موجود أو ليس منتجاً خارجياً.';
                header('Location: ' . $managerInventoryUrl);
                exit;
            }

            $oldQuantity = floatval($productRow['quantity'] ?? 0);
            $newQuantity = $oldQuantity;

            if ($operation === 'discard') {
                if ($amount > $oldQuantity) {
                    $_SESSION[$sessionErrorKey] = 'الكمية المراد إتلافها أكبر من الكمية المتاحة.';
                    header('Location: ' . $managerInventoryUrl);
                    exit;
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

        header('Location: ' . $managerInventoryUrl);
        exit;
    } elseif ($isManager && $postAction === 'update_external_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $name = trim((string)($_POST['edit_name'] ?? ''));
        $channel = $_POST['edit_channel'] ?? 'company';
        $unitPrice = max(0, floatval($_POST['edit_price'] ?? 0));
        $unit = trim((string)($_POST['edit_unit'] ?? 'قطعة'));
        $description = trim((string)($_POST['edit_description'] ?? ''));

        if ($productId <= 0 || $name === '') {
            $_SESSION[$sessionErrorKey] = 'يرجى اختيار منتج صالح وتحديد الاسم.';
            header('Location: ' . $managerInventoryUrl);
            exit;
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
                header('Location: ' . $managerInventoryUrl);
                exit;
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

        header('Location: ' . $managerInventoryUrl);
        exit;
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
    try {
        $finishedProductsRows = $db->query("
            SELECT 
                fp.id,
                fp.batch_id,
                fp.batch_number,
                fp.product_id,
                COALESCE(pr.name, fp.product_name) AS product_name,
                fp.production_date,
                fp.quantity_produced,
                GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
            FROM finished_products fp
            LEFT JOIN products pr ON fp.product_id = pr.id
            LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
            LEFT JOIN users u ON bw.employee_id = u.id
            GROUP BY fp.id
            ORDER BY fp.production_date DESC, fp.id DESC
            LIMIT 150
        ");
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
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#addExternalProductModal"
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
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
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
                                        <?php if (!$viewUrl && !$batchNumber): ?>
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
                                    data-bs-toggle="modal"
                                    data-bs-target="#externalStockModal"
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
                                    data-bs-toggle="modal"
                                    data-bs-target="#externalStockModal"
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
                                    data-bs-toggle="modal"
                                    data-bs-target="#editExternalProductModal"
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
<?php
$specificationsCount = is_countable($productSpecifications) ? count($productSpecifications) : 0;
?>
<div class="card shadow-sm mt-4">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>مواصفات المنتجات (الوصفات المرجعية)</h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark"><?php echo number_format($specificationsCount); ?> مواصفة</span>
            <?php if ($isManager): ?>
            <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addSpecificationModal">
                <i class="bi bi-plus-circle me-1"></i>إضافة مواصفة
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($specificationsCount === 0): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                لم يتم تسجيل مواصفات بعد.
                <?php if ($isManager): ?>
                    يمكنك إضافة المواصفة الأولى من خلال زر <strong>إضافة مواصفة</strong>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>اسم المنتج</th>
                            <th width="24%">المواد الخام</th>
                            <th width="24%">أدوات التعبئة</th>
                            <th width="22%">ملاحظات الإنشاء</th>
                            <th>آخر تحديث</th>
                            <?php if ($isManager): ?>
                                <th>إجراءات</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productSpecifications as $specification): ?>
                            <tr>
                                <td data-label="اسم المنتج">
                                    <strong><?php echo htmlspecialchars($specification['product_name']); ?></strong>
                                </td>
                                <td data-label="المواد الخام">
                                    <div class="text-muted small">الأوزان / الكميات</div>
                                    <div class="mt-1" style="white-space: pre-line;"><?php echo $specification['raw_materials'] !== null && $specification['raw_materials'] !== '' ? nl2br(htmlspecialchars($specification['raw_materials'])) : '<span class="text-muted">—</span>'; ?></div>
                                </td>
                                <td data-label="أدوات التعبئة">
                                    <div class="text-muted small">الأعداد / الأحجام</div>
                                    <div class="mt-1" style="white-space: pre-line;"><?php echo $specification['packaging'] !== null && $specification['packaging'] !== '' ? nl2br(htmlspecialchars($specification['packaging'])) : '<span class="text-muted">—</span>'; ?></div>
                                </td>
                                <td data-label="ملاحظات الإنشاء" style="white-space: pre-line;">
                                    <?php echo $specification['notes'] !== null && $specification['notes'] !== '' ? nl2br(htmlspecialchars($specification['notes'])) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td data-label="آخر تحديث">
                                    <?php
                                        $updatedAt = $specification['updated_at'] ?? null;
                                        $createdAt = $specification['created_at'] ?? null;
                                        echo $updatedAt
                                            ? htmlspecialchars(formatDateTime($updatedAt))
                                            : ($createdAt ? htmlspecialchars(formatDateTime($createdAt)) : '—');
                                    ?>
                                    <div class="text-muted small mt-1">
                                        <?php if (!empty($specification['updated_by'])): ?>
                                            بواسطة <?php echo htmlspecialchars($specification['updater_name'] ?? 'غير محدد'); ?>
                                        <?php elseif (!empty($specification['created_by'])): ?>
                                            مسجل بواسطة <?php echo htmlspecialchars($specification['creator_name'] ?? 'غير محدد'); ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php if ($isManager): ?>
                                <td data-label="إجراءات">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm js-edit-specification"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editSpecificationModal"
                                        data-id="<?php echo (int) $specification['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($specification['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-raw="<?php echo htmlspecialchars($specification['raw_materials'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-packaging="<?php echo htmlspecialchars($specification['packaging'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-notes="<?php echo htmlspecialchars($specification['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="bi bi-pencil-square me-1"></i>تعديل
                                    </button>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isManager): ?>
<div class="modal fade" id="addSpecificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="add_specification">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة مواصفة منتج جديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" name="product_name" class="form-control" required maxlength="255" placeholder="مثال: عسل باللوز 500 جم">
                </div>
                <div class="mb-3">
                    <label class="form-label">المواد الخام والأوزان</label>
                    <textarea name="raw_materials" rows="4" class="form-control" placeholder="مثال:
- عسل نقي: 450 جرام
- لوز مجروش: 50 جرام"></textarea>
                    <small class="text-muted">اكتب كل مادة في سطر مع ذكر الوزن أو النسبة.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">أدوات التعبئة والأعداد</label>
                    <textarea name="packaging" rows="4" class="form-control" placeholder="مثال:
- برطمان زجاجي 500 جم: 1
- غطاء معدني: 1
- ملصق خارجي: 1"></textarea>
                    <small class="text-muted">اذكر الأدوات المطلوبة وعدد كل منها لكل منتج.</small>
                </div>
                <div class="mb-0">
                    <label class="form-label">ملاحظات الإنشاء</label>
                    <textarea name="notes" rows="3" class="form-control" placeholder="خطوات خاصة بالإنتاج، درجة حرارة، وقت تبريد، ..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>حفظ المواصفة
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editSpecificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="edit_specification">
            <input type="hidden" name="spec_id" id="editSpecId" value="">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل مواصفة المنتج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" name="product_name" id="editProductName" class="form-control" required maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label">المواد الخام والأوزان</label>
                    <textarea name="raw_materials" id="editRawMaterials" rows="4" class="form-control"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">أدوات التعبئة والأعداد</label>
                    <textarea name="packaging" id="editPackaging" rows="4" class="form-control"></textarea>
                </div>
                <div class="mb-0">
                    <label class="form-label">ملاحظات الإنشاء</label>
                    <textarea name="notes" id="editNotes" rows="3" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addExternalProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="create_external_product">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" name="external_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">نوع البيع <span class="text-danger">*</span></label>
                    <select name="external_channel" class="form-select" required>
                        <option value="company">بيع داخل الشركة</option>
                        <option value="delegate">مندوب المبيعات</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">الكمية الابتدائية</label>
                        <input type="number" name="external_quantity" class="form-control" min="0" step="0.01" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الوحدة</label>
                        <input type="text" name="external_unit" class="form-control" value="قطعة">
                    </div>
                </div>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label">سعر البيع</label>
                        <input type="number" name="external_price" class="form-control" min="0" step="0.01" value="0.00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">وصف مختصر</label>
                        <input type="text" name="external_description" class="form-control" placeholder="اختياري">
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

<div class="modal fade" id="externalStockModal" tabindex="-1" aria-hidden="true">
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
                    <label class="form-label">اسم المنتج</label>
                    <input type="text" class="form-control js-external-stock-name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label js-external-stock-label">الكمية المراد إضافتها</label>
                    <input type="number" name="quantity" class="form-control" min="0" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">ملاحظة</label>
                    <textarea name="note" class="form-control" rows="2" placeholder="اختياري"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary js-external-stock-submit">حفظ</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editExternalProductModal" tabindex="-1" aria-hidden="true">
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
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" name="edit_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">نوع البيع <span class="text-danger">*</span></label>
                    <select name="edit_channel" class="form-select" required>
                        <option value="company">بيع داخل الشركة</option>
                        <option value="delegate">مندوب المبيعات</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">سعر البيع</label>
                        <input type="number" name="edit_price" class="form-control" min="0" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الوحدة</label>
                        <input type="text" name="edit_unit" class="form-control">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">ملاحظات</label>
                    <textarea name="edit_description" class="form-control" rows="2" placeholder="اختياري"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($primaryWarehouse): ?>
<div class="modal fade" id="requestTransferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
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
                                <div class="form-control-plaintext fw-semibold">
                                    <?php echo htmlspecialchars($primaryWarehouse['name']); ?> (مخزن رئيسي)
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">إلى المخزن <span class="text-danger">*</span></label>
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
                                <label class="form-label">تاريخ النقل <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">السبب</label>
                                <input type="text" class="form-control" name="reason" placeholder="مثال: تجهيز مخزون لمندوب المبيعات">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">عناصر النقل <span class="text-danger">*</span></label>
                            <div id="mainWarehouseTransferItems">
                                <div class="transfer-item row g-2 align-items-end mb-2">
                                    <div class="col-md-5">
                                        <label class="form-label small text-muted">المنتج</label>
                                        <select class="form-select product-select" required>
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
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted">الكمية</label>
                                        <input type="number" step="0.01" min="0.01" class="form-control quantity-input" name="items[0][quantity]" placeholder="الكمية" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted">ملاحظات</label>
                                        <input type="text" class="form-control" name="items[0][notes]" placeholder="ملاحظات (اختياري)">
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted available-hint d-block"></small>
                                        <input type="hidden" name="items[0][product_id]" class="selected-product-id">
                                        <input type="hidden" name="items[0][batch_id]" class="selected-batch-id">
                                        <input type="hidden" name="items[0][batch_number]" class="selected-batch-number">
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button type="button" class="btn btn-outline-danger remove-transfer-item" title="حذف العنصر">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addTransferItemBtn">
                                <i class="bi bi-plus-circle me-1"></i>
                                إضافة منتج آخر
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ملاحظات إضافية</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="أي تفاصيل إضافية للمدير (اختياري)"></textarea>
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
document.addEventListener('DOMContentLoaded', () => {
    const addSpecModal = document.getElementById('addSpecificationModal');
    if (addSpecModal) {
        addSpecModal.addEventListener('hidden.bs.modal', () => {
            const form = addSpecModal.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    }

    const editSpecModal = document.getElementById('editSpecificationModal');
    if (editSpecModal) {
        const editForm = editSpecModal.querySelector('form');
        const specIdInput = document.getElementById('editSpecId');
        const productNameInput = document.getElementById('editProductName');
        const rawMaterialsInput = document.getElementById('editRawMaterials');
        const packagingInput = document.getElementById('editPackaging');
        const notesInput = document.getElementById('editNotes');

        editSpecModal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) {
                return;
            }
            if (specIdInput) {
                specIdInput.value = button.getAttribute('data-id') || '';
            }
            if (productNameInput) {
                productNameInput.value = button.getAttribute('data-name') || '';
            }
            if (rawMaterialsInput) {
                rawMaterialsInput.value = button.getAttribute('data-raw') || '';
            }
            if (packagingInput) {
                packagingInput.value = button.getAttribute('data-packaging') || '';
            }
            if (notesInput) {
                notesInput.value = button.getAttribute('data-notes') || '';
            }
        });

        editSpecModal.addEventListener('hidden.bs.modal', () => {
            if (editForm) {
                editForm.reset();
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const transferForm = document.getElementById('mainWarehouseTransferForm');
    const itemsContainer = document.getElementById('mainWarehouseTransferItems');
    const addItemButton = document.getElementById('addTransferItemBtn');
    const destinationSelect = document.getElementById('transferToWarehouse');

    if (!transferForm || !itemsContainer) {
        return;
    }

    let transferItemIndex = 1;

    function buildItemRow(index) {
        const wrapper = document.createElement('div');
        wrapper.className = 'transfer-item row g-2 align-items-end mb-2';
        wrapper.innerHTML = `
            <div class="col-md-5">
                <label class="form-label small text-muted">المنتج</label>
                <select class="form-select product-select" required>
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
            <div class="col-md-3">
                <label class="form-label small text-muted">الكمية</label>
                <input type="number" step="0.01" min="0.01" class="form-control quantity-input"
                       name="items[${index}][quantity]" placeholder="الكمية" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">ملاحظات</label>
                <input type="text" class="form-control" name="items[${index}][notes]" placeholder="ملاحظات (اختياري)">
            </div>
            <div class="col-12">
                <small class="text-muted available-hint d-block"></small>
                <input type="hidden" name="items[${index}][product_id]" class="selected-product-id">
                <input type="hidden" name="items[${index}][batch_id]" class="selected-batch-id">
                <input type="hidden" name="items[${index}][batch_number]" class="selected-batch-number">
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-outline-danger remove-transfer-item" title="حذف العنصر">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        return wrapper;
    }

    function attachItemEvents(row) {
        const select = row.querySelector('.product-select');
        const quantityInput = row.querySelector('.quantity-input');
        const productIdInput = row.querySelector('.selected-product-id');
        const batchIdInput = row.querySelector('.selected-batch-id');
        const batchNumberInput = row.querySelector('.selected-batch-number');
        const availableHint = row.querySelector('.available-hint');

        if (!select || !quantityInput) {
            return;
        }

        const updateQuantityConstraints = () => {
            const selectedOption = select.options[select.selectedIndex];
            const available = selectedOption ? parseFloat(selectedOption.dataset.available || '0') : 0;
            const selectedProductId = selectedOption ? parseInt(selectedOption.dataset.productId || '0', 10) : 0;
            const selectedBatchId = selectedOption ? parseInt(selectedOption.dataset.batchId || '0', 10) : 0;
            const selectedBatchNumber = selectedOption ? selectedOption.dataset.batchNumber || '' : '';

            if (productIdInput) {
                productIdInput.value = selectedProductId > 0 ? selectedProductId : '';
            }
            if (batchIdInput) {
                batchIdInput.value = selectedBatchId > 0 ? selectedBatchId : '';
            }
            if (batchNumberInput) {
                batchNumberInput.value = selectedBatchNumber;
            }

            if (availableHint) {
                if (selectedOption && selectedOption.value) {
                    availableHint.textContent = `الكمية المتاحة لهذه التشغيلة: ${available.toLocaleString('ar-EG')} وحدة`;
                } else {
                    availableHint.textContent = '';
                }
            }

            if (available > 0) {
                quantityInput.setAttribute('max', available);
                if (parseFloat(quantityInput.value || '0') > available) {
                    quantityInput.value = available;
                }
            } else {
                quantityInput.removeAttribute('max');
            }
        };

        select.addEventListener('change', updateQuantityConstraints);
        updateQuantityConstraints();
    }

    if (addItemButton) {
        addItemButton.addEventListener('click', () => {
            const newRow = buildItemRow(transferItemIndex);
            itemsContainer.appendChild(newRow);
            attachItemEvents(newRow);
            transferItemIndex += 1;
        });
    }

    document.addEventListener('click', (event) => {
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

    itemsContainer.querySelectorAll('.transfer-item').forEach((row) => {
        attachItemEvents(row);
    });

    transferForm.addEventListener('submit', (event) => {
        const rows = itemsContainer.querySelectorAll('.transfer-item');
        if (!rows.length) {
            event.preventDefault();
            alert('أضف منتجاً واحداً على الأقل قبل إرسال الطلب.');
            return;
        }

        for (const row of rows) {
            const select = row.querySelector('.product-select');
            const quantityInput = row.querySelector('.quantity-input');

            if (!select || !quantityInput) {
                event.preventDefault();
                alert('يرجى التأكد من إدخال بيانات صحيحة لكل منتج.');
                return;
            }

            if (!select.value) {
                event.preventDefault();
                alert('اختر المنتج المراد نقله.');
                return;
            }

            const max = parseFloat(quantityInput.getAttribute('max') || '0');
            const min = parseFloat(quantityInput.getAttribute('min') || '0');
            const value = parseFloat(quantityInput.value || '0');

            if (value < min) {
                event.preventDefault();
                alert('يرجى إدخال كمية أكبر من الصفر.');
                return;
            }

            if (max > 0 && value > max) {
                event.preventDefault();
                alert('الكمية المطلوبة تتجاوز المتاح في المخزن الرئيسي.');
                return;
            }
        }

        if (destinationSelect && !destinationSelect.value) {
            event.preventDefault();
            alert('يرجى اختيار المخزن الوجهة قبل إرسال الطلب.');
        }
    });
});
</script>

<div class="modal fade" id="batchDetailsModal" tabindex="-1" aria-hidden="true">
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
    const batchDetailsEndpoint = <?php echo json_encode(getRelativeUrl('reader/api.php')); ?>;
    const statusLabelsMap = {
        'in_production': 'قيد الإنتاج',
        'completed': 'مكتملة',
        'archived': 'مؤرشفة',
        'cancelled': 'ملغاة',
        'in_stock': 'في المخزون',
        'sold': 'مباعة',
        'expired': 'منتهية الصلاحية',
        'approved': 'موافق عليها',
        'pending': 'معلقة',
        'rejected': 'مرفوضة'
    };
    let batchDetailsIsLoading = false;
    const batchDetailsRetryDelay = 2000;
    const batchDetailsMaxRetries = 3;
    let batchDetailsRetryTimeoutId = null;

    function getBatchDetailsModalBodyTemplate() {
        return `
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
        `;
    }

    function resetBatchDetailsModalState() {
        if (batchDetailsRetryTimeoutId) {
            clearTimeout(batchDetailsRetryTimeoutId);
            batchDetailsRetryTimeoutId = null;
        }
        batchDetailsIsLoading = false;
    }

    function ensureBatchDetailsModalStructure() {
        if (typeof document === 'undefined') {
            return null;
        }

        let modalElement = document.getElementById('batchDetailsModal');

        if (!modalElement && document.body) {
            modalElement = document.createElement('div');
            modalElement.className = 'modal fade';
            modalElement.id = 'batchDetailsModal';
            modalElement.tabIndex = -1;
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.innerHTML = `
                <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">تفاصيل التشغيلة</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                        </div>
                        <div class="modal-body">
                            ${getBatchDetailsModalBodyTemplate()}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modalElement);
        }

        if (!modalElement) {
            return null;
        }

        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody && !modalBody.querySelector('#batchDetailsContent')) {
            modalBody.innerHTML = getBatchDetailsModalBodyTemplate();
        }

        if (!modalElement.dataset.batchDetailsInit) {
            modalElement.addEventListener('hidden.bs.modal', resetBatchDetailsModalState);
            modalElement.dataset.batchDetailsInit = 'true';
        }

        return {
            modalElement,
            loader: modalElement.querySelector('#batchDetailsLoading'),
            errorAlert: modalElement.querySelector('#batchDetailsError'),
            contentWrapper: modalElement.querySelector('#batchDetailsContent')
        };
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

        if (data.status) {
            const statusLabel = statusLabelsMap[data.status] ?? data.status;
            summaryRows.push(['الحالة', statusLabel]);
        }
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
        if (batchDetailsIsLoading) {
            if (typeof bootstrap !== 'undefined' && typeof bootstrap.Modal !== 'undefined') {
                const existingModal = document.getElementById('batchDetailsModal');
                if (existingModal) {
                    bootstrap.Modal.getOrCreateInstance(existingModal).show();
                }
            }
            return;
        }

        if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal === 'undefined') {
            alert('تعذر فتح تفاصيل التشغيلة حالياً. يرجى تحديث الصفحة ثم المحاولة مرة أخرى.');
            return;
        }

        const structure = ensureBatchDetailsModalStructure();
        if (!structure || !structure.modalElement || !structure.loader || !structure.errorAlert || !structure.contentWrapper) {
            alert('تعذر تهيئة عرض تفاصيل التشغيلة. يرجى تحديث الصفحة والمحاولة لاحقاً.');
            return;
        }

        if (batchDetailsRetryTimeoutId) {
            clearTimeout(batchDetailsRetryTimeoutId);
            batchDetailsRetryTimeoutId = null;
        }

        const { modalElement, loader, errorAlert, contentWrapper } = structure;
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
        const modalTitle = modalElement.querySelector('.modal-title');

        if (modalTitle) {
            modalTitle.textContent = productName
                ? `تفاصيل التشغيلة - ${productName}`
                : 'تفاصيل التشغيلة';
        }

        loader.classList.remove('d-none');
        errorAlert.classList.add('d-none');
        errorAlert.textContent = '';
        contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = true;

        modalInstance.show();

        fetchBatchDetailsData(batchNumber, 1, structure);
    }

    function fetchBatchDetailsData(batchNumber, attempt, elements) {
        const { loader, errorAlert, contentWrapper } = elements;

        fetch(batchDetailsEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ batch_number: batchNumber })
        })
        .then(async (response) => {
            const contentType = response.headers.get('content-type') || '';
            const isJson = contentType.includes('application/json');
            const payload = isJson ? await response.json() : null;

            if (!response.ok || !payload) {
                const message = payload?.message ?? 'تعذر تحميل تفاصيل التشغيلة.';
                throw new Error(message);
            }

            if (!payload.success || !payload.batch) {
                throw new Error(payload.message ?? 'تعذر تحميل تفاصيل التشغيلة.');
            }

            return payload;
        })
        .then((payload) => {
            loader.classList.add('d-none');
            errorAlert.classList.add('d-none');
            errorAlert.textContent = '';
            contentWrapper.classList.remove('d-none');
            renderBatchDetails(payload.batch);
            batchDetailsIsLoading = false;
            batchDetailsRetryTimeoutId = null;
        })
        .catch((error) => {
            if (attempt < batchDetailsMaxRetries) {
                loader.classList.add('d-none');
                contentWrapper.classList.add('d-none');
                errorAlert.textContent = `${error.message || 'تعذر تحميل تفاصيل التشغيلة.'} سيتم إعادة المحاولة خلال ثانيتين.`;
                errorAlert.classList.remove('d-none');

                batchDetailsRetryTimeoutId = window.setTimeout(() => {
                    batchDetailsRetryTimeoutId = null;
                    loader.classList.remove('d-none');
                    errorAlert.classList.add('d-none');
                    errorAlert.textContent = '';
                    fetchBatchDetailsData(batchNumber, attempt + 1, elements);
                }, batchDetailsRetryDelay);
                return;
            }

            loader.classList.add('d-none');
            contentWrapper.classList.add('d-none');
            errorAlert.textContent = error.message || 'تعذر تحميل تفاصيل التشغيلة.';
            errorAlert.classList.remove('d-none');
            batchDetailsIsLoading = false;
            batchDetailsRetryTimeoutId = null;
        });
    }

    document.addEventListener('click', function (event) {
        const detailsButton = event.target.closest('.js-batch-details');
        if (detailsButton) {
            const batchNumber = detailsButton.dataset.batch;
            if (batchNumber) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                const productName = detailsButton.dataset.product || '';
                showBatchDetailsModal(batchNumber, productName);
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

        const adjustButton = event.target.closest('.js-external-adjust');
        if (adjustButton) {
            const modal = document.getElementById('externalStockModal');
            if (!modal) {
                return;
            }
            const form = modal.querySelector('form');
            const productIdInput = form?.querySelector('input[name="product_id"]');
            const operationInput = form?.querySelector('input[name="operation"]');
            const nameField = modal.querySelector('.js-external-stock-name');
            const title = modal.querySelector('.js-external-stock-title');
            const label = modal.querySelector('.js-external-stock-label');
            const submitButton = modal.querySelector('.js-external-stock-submit');

            const productId = adjustButton.dataset.product || '';
            const productName = adjustButton.dataset.name || '';
            const mode = adjustButton.dataset.mode === 'discard' ? 'discard' : 'add';

            if (productIdInput) {
                productIdInput.value = productId;
            }
            if (operationInput) {
                operationInput.value = mode;
            }
            if (nameField) {
                nameField.value = productName;
            }
            if (title) {
                title.textContent = mode === 'discard'
                    ? 'إتلاف كمية من المنتج الخارجي'
                    : 'إضافة كمية للمنتج الخارجي';
            }
            if (label) {
                label.textContent = mode === 'discard'
                    ? 'الكمية المراد إتلافها'
                    : 'الكمية المراد إضافتها';
            }
            if (submitButton) {
                submitButton.textContent = mode === 'discard' ? 'تأكيد الإتلاف' : 'حفظ الكمية';
                submitButton.className = mode === 'discard'
                    ? 'btn btn-danger js-external-stock-submit'
                    : 'btn btn-primary js-external-stock-submit';
            }
            return;
        }

        const editButton = event.target.closest('.js-external-edit');
        if (editButton) {
            const modal = document.getElementById('editExternalProductModal');
            if (!modal) {
                return;
            }
            const form = modal.querySelector('form');
            if (!form) {
                return;
            }

            const productIdInput = form.querySelector('input[name="product_id"]');
            const nameInput = form.querySelector('input[name="edit_name"]');
            const channelSelect = form.querySelector('select[name="edit_channel"]');
            const priceInput = form.querySelector('input[name="edit_price"]');
            const unitInput = form.querySelector('input[name="edit_unit"]');
            const descriptionInput = form.querySelector('textarea[name="edit_description"]');

            if (productIdInput) {
                productIdInput.value = editButton.dataset.product || '';
            }
            if (nameInput) {
                nameInput.value = editButton.dataset.name || '';
            }
            if (channelSelect) {
                const channelValue = editButton.dataset.channel || 'company';
                channelSelect.value = ['company', 'delegate', 'other'].includes(channelValue) ? channelValue : 'company';
            }
            if (priceInput) {
                priceInput.value = editButton.dataset.price || '0';
            }
            if (unitInput) {
                unitInput.value = editButton.dataset.unit || 'قطعة';
            }
            if (descriptionInput) {
                descriptionInput.value = editButton.dataset.description || '';
            }
        }
    });
</script>

