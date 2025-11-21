<?php
/**
 * لوحة التحكم لعمال الإنتاج
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_summary.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/production_helper.php';
require_once __DIR__ . '/../includes/batch_numbers.php';

requireRole(['production', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

if ($page === 'attendance') {
    header('Location: ../v1/attendance.php');
    exit;
}

$isTemplateAjax = ($page === 'production' && isset($_GET['ajax']));

if ($isTemplateAjax) {
    $ajaxAction = $_GET['ajax'];

    if ($ajaxAction === 'template_details' && isset($_GET['template_id'])) {
        header('Content-Type: application/json; charset=utf-8');

        $templateId = intval($_GET['template_id']);
        $templateType = $_GET['template_type'] ?? '';
        $templateTypeKey = 'legacy';
        $response = [
            'success' => false,
            'mode' => 'advanced',
            'components' => [],
            'hint' => 'يرجى اختيار المورد لكل مادة مستخدمة في التشغيلة.',
            'cache_key' => null
        ];

        try {
            $template = $db->queryOne(
                "SELECT * FROM product_templates WHERE id = ?",
                [$templateId]
            );

            if (!$template) {
                throw new Exception('القالب غير موجود أو تم حذفه.');
            }

            $templateTypeKey = trim($template['template_type'] ?? '') ?: 'legacy';
            $components = [];

            $detailsPayload = [];
            if (!empty($template['details_json'])) {
                $decoded = json_decode($template['details_json'], true);
                if (is_array($decoded)) {
                    $detailsPayload = $decoded;
                }
            }

            $normalizeMaterialName = static function ($value): string {
                if (!is_string($value)) {
                    $value = (string) $value;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return '';
                }
                $normalizedWhitespace = preg_replace('/\s+/u', ' ', $trimmed);
                return function_exists('mb_strtolower')
                    ? mb_strtolower($normalizedWhitespace, 'UTF-8')
                    : strtolower($normalizedWhitespace);
            };

            $rawDefaults = [];
            if (!empty($detailsPayload['raw_materials']) && is_array($detailsPayload['raw_materials'])) {
                foreach ($detailsPayload['raw_materials'] as $rawItem) {
                    $nameKey = trim((string)($rawItem['name'] ?? ''));
                    if ($nameKey === '' && !empty($rawItem['material_name'])) {
                        $nameKey = trim((string)$rawItem['material_name']);
                    }
                    $normalizedKey = $nameKey !== '' ? $normalizeMaterialName($nameKey) : '';
                    if ($normalizedKey !== '') {
                        $rawDefaults[$normalizedKey] = [
                            'supplier_id' => $rawItem['supplier_id'] ?? null,
                            'honey_variety' => $rawItem['honey_variety'] ?? null,
                            'type' => $rawItem['type'] ?? ($rawItem['material_type'] ?? null)
                        ];
                    }
                }
            }

            $packagingDefaults = [];
            if (!empty($detailsPayload['packaging']) && is_array($detailsPayload['packaging'])) {
                foreach ($detailsPayload['packaging'] as $packItem) {
                    $packId = $packItem['packaging_material_id'] ?? null;
                    if ($packId) {
                        $packagingDefaults[$packId] = [
                            'supplier_id' => $packItem['supplier_id'] ?? null
                        ];
                    }
                }
            }

            $honeyQuantity = (float)($template['honey_quantity'] ?? 0);
            if ($honeyQuantity > 0) {
                $honeyQuantityDisplay = number_format($honeyQuantity, 3);
                $components[] = [
                    'key' => 'honey_main',
                    'name' => 'عسل',
                    'label' => 'مورد العسل',
                    'description' => 'الكمية لكل وحدة: ' . $honeyQuantityDisplay . ' كجم',
                    'type' => 'honey_main',
                    'requires_variety' => true,
                    'default_supplier' => $detailsPayload['main_supplier_id'] ?? null,
                    'honey_variety' => $detailsPayload['honey_variety'] ?? null,
                    'quantity_per_unit' => $honeyQuantity,
                    'quantity_display' => $honeyQuantityDisplay . ' كجم',
                    'unit' => 'كجم',
                    'unit_label' => 'كجم'
                ];
            }

            $rawMaterials = $db->query(
                "SELECT id, material_name, quantity_per_unit, unit 
                 FROM product_template_raw_materials 
                 WHERE template_id = ?",
                [$templateId]
            );
            foreach ($rawMaterials as $rawMaterial) {
                $name = trim((string)($rawMaterial['material_name'] ?? 'مادة خام'));
                $quantity = number_format((float)($rawMaterial['quantity_per_unit'] ?? 0), 3);
                $unit = $rawMaterial['unit'] ?? 'وحدة';
                $defaultSupplier = null;
                $defaultHoneyVariety = null;
                $detailType = null;
                if ($name !== '') {
                    $normalizedName = $normalizeMaterialName($name);
                    if ($normalizedName !== '' && isset($rawDefaults[$normalizedName])) {
                        $defaultSupplier = $rawDefaults[$normalizedName]['supplier_id'] ?? null;
                        $defaultHoneyVariety = $rawDefaults[$normalizedName]['honey_variety'] ?? null;
                        if (!empty($rawDefaults[$normalizedName]['type'])) {
                            $detailType = (string)$rawDefaults[$normalizedName]['type'];
                        }
                    }
                }

                $isHoneyMaterial = false;
                $componentType = $detailType !== null ? trim((string)$detailType) : 'raw_general';
                $hasHoneyKeyword = (mb_stripos($name, 'عسل') !== false) || (stripos($name, 'honey') !== false);
                if ($detailType === null && $hasHoneyKeyword) {
                    $isHoneyMaterial = true;
                    $hasFilteredKeyword = (mb_stripos($name, 'مصفى') !== false) || (stripos($name, 'filtered') !== false);
                    $hasRawKeyword = (mb_stripos($name, 'خام') !== false) || (stripos($name, 'raw') !== false);

                    if ($hasFilteredKeyword && !$hasRawKeyword) {
                        $componentType = 'honey_filtered';
                    } elseif ($hasRawKeyword && !$hasFilteredKeyword) {
                        $componentType = 'honey_raw';
                    } else {
                        $componentType = 'honey_general';
                    }
                } elseif ($detailType !== null) {
                    $isHoneyMaterial = in_array($componentType, ['honey_raw', 'honey_filtered', 'honey_main', 'honey_general'], true);
                }

                $quantityDisplay = $quantity . ' ' . $unit;
                $components[] = [
                    'key' => 'raw_' . $rawMaterial['id'],
                    'name' => $name,
                    'label' => 'مورد المادة: ' . $name,
                    'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit,
                    'type' => $componentType,
                    'default_supplier' => $defaultSupplier,
                    'honey_variety' => $defaultHoneyVariety,
                    'requires_variety' => $isHoneyMaterial,
                    'quantity_per_unit' => (float)($rawMaterial['quantity_per_unit'] ?? 0),
                    'quantity_display' => $quantityDisplay,
                    'unit' => $unit,
                    'unit_label' => $unit
                ];
            }
            if (empty($rawMaterials) && !empty($detailsPayload['raw_materials']) && is_array($detailsPayload['raw_materials'])) {
                foreach ($detailsPayload['raw_materials'] as $index => $rawItem) {
                    $name = trim((string)($rawItem['name'] ?? 'مادة خام'));
                    if ($name === '') {
                        continue;
                    }
                    $quantityValue = isset($rawItem['quantity']) ? (float)$rawItem['quantity'] : 0.0;
                    if ($quantityValue <= 0) {
                        continue;
                    }
                    $quantity = number_format($quantityValue, 3);
                    $unit = $rawItem['unit'] ?? 'وحدة';
                    $defaultSupplier = $rawItem['supplier_id'] ?? null;
                    $defaultHoneyVariety = $rawItem['honey_variety'] ?? null;

                    $isHoneyMaterial = false;
                    $detailType = isset($rawItem['type']) ? (string)$rawItem['type'] : (isset($rawItem['material_type']) ? (string)$rawItem['material_type'] : '');
                    $componentType = $detailType !== '' ? trim($detailType) : 'raw_general';
                    $hasHoneyKeyword = (mb_stripos($name, 'عسل') !== false) || (stripos($name, 'honey') !== false);
                    if ($detailType === '' && $hasHoneyKeyword) {
                        $isHoneyMaterial = true;
                        $hasFilteredKeyword = (mb_stripos($name, 'مصفى') !== false) || (stripos($name, 'filtered') !== false);
                        $hasRawKeyword = (mb_stripos($name, 'خام') !== false) || (stripos($name, 'raw') !== false);

                        if ($hasFilteredKeyword && !$hasRawKeyword) {
                            $componentType = 'honey_filtered';
                        } elseif ($hasRawKeyword && !$hasFilteredKeyword) {
                            $componentType = 'honey_raw';
                        } else {
                            $componentType = 'honey_general';
                        }
                    } elseif ($detailType !== '') {
                        $isHoneyMaterial = in_array($componentType, ['honey_raw', 'honey_filtered', 'honey_main', 'honey_general'], true);
                    }

                    $quantityDisplay = $quantity . ' ' . $unit;
                    $components[] = [
                        'key' => 'raw_fallback_' . $index,
                        'name' => $name,
                        'label' => 'مورد المادة: ' . $name,
                        'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit,
                        'type' => $componentType,
                        'default_supplier' => $defaultSupplier,
                        'honey_variety' => $defaultHoneyVariety,
                        'requires_variety' => $isHoneyMaterial,
                        'quantity_per_unit' => $quantityValue,
                        'quantity_display' => $quantityDisplay,
                        'unit' => $unit,
                        'unit_label' => $unit
                    ];
                }
            }

            $packagingItems = $db->query(
                "SELECT id, packaging_material_id, packaging_name, quantity_per_unit 
                 FROM product_template_packaging 
                 WHERE template_id = ?",
                [$templateId]
            );
            foreach ($packagingItems as $packItem) {
                $packagingId = $packItem['packaging_material_id'] ?? null;
                $name = $packItem['packaging_name'] ?? ('أداة تعبئة #' . ($packagingId ?: $packItem['id']));
                $quantity = number_format((float)($packItem['quantity_per_unit'] ?? 0), 3);
                $defaultSupplier = null;
                if ($packagingId && isset($packagingDefaults[$packagingId])) {
                    $defaultSupplier = $packagingDefaults[$packagingId]['supplier_id'] ?? null;
                }

                $quantityDisplay = $quantity . ' قطعة';
                $components[] = [
                    'key' => 'pack_' . ($packagingId ?: $packItem['id']),
                    'name' => $name,
                    'label' => 'مورد أداة التعبئة: ' . $name,
                    'description' => 'الكمية لكل وحدة: ' . $quantity . ' قطعة',
                    'type' => 'packaging',
                    'default_supplier' => $defaultSupplier,
                    'quantity_per_unit' => (float)($packItem['quantity_per_unit'] ?? 0),
                    'quantity_display' => $quantityDisplay,
                    'unit' => 'قطعة',
                    'unit_label' => 'قطعة'
                ];
            }
            if (empty($packagingItems) && !empty($detailsPayload['packaging']) && is_array($detailsPayload['packaging'])) {
                foreach ($detailsPayload['packaging'] as $index => $packItem) {
                    $packagingId = $packItem['packaging_material_id'] ?? null;
                    $quantityValue = isset($packItem['quantity_per_unit']) ? (float)$packItem['quantity_per_unit'] : 0.0;
                    if ($quantityValue <= 0) {
                        continue;
                    }
                    $quantity = number_format($quantityValue, 3);
                    $name = 'أداة تعبئة';
                    if ($packagingId) {
                        $packagingRow = $db->queryOne(
                            "SELECT name FROM packaging_materials WHERE id = ?",
                            [$packagingId]
                        );
                        if ($packagingRow && !empty($packagingRow['name'])) {
                            $name = $packagingRow['name'];
                        } else {
                            $name .= ' #' . $packagingId;
                        }
                    } elseif (!empty($packItem['name'])) {
                        $name = $packItem['name'];
                    } else {
                        $name .= ' #' . ($index + 1);
                    }
                    $quantityDisplay = $quantity . ' قطعة';

                    $components[] = [
                        'key' => 'pack_fallback_' . ($packagingId ?: $index),
                        'name' => $name,
                        'label' => 'مورد أداة التعبئة: ' . $name,
                        'description' => 'الكمية لكل وحدة: ' . $quantity . ' قطعة',
                        'type' => 'packaging',
                        'default_supplier' => $packItem['supplier_id'] ?? null,
                        'quantity_per_unit' => $quantityValue,
                        'quantity_display' => $quantityDisplay,
                        'unit' => 'قطعة',
                        'unit_label' => 'قطعة'
                    ];
                }
            }

            $response['hint'] = 'اختر المورد لكل مادة خام وأداة تعبئة مرتبطة بالقالب.';
            $response['components'] = $components;
            $response['template_type'] = $templateTypeKey;
            $response['success'] = true;
            $response['cache_key'] = $templateId . '::' . $templateTypeKey;
        } catch (Exception $e) {
            $response['success'] = false;
            $response['message'] = $e->getMessage();
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajaxAction === 'bootstrap_batch_print' && isset($_GET['batch'])) {
        header('Content-Type: application/json; charset=utf-8');

        $batchNumber = trim((string)$_GET['batch']);

        if ($batchNumber === '') {
            echo json_encode(['success' => false, 'error' => 'رقم التشغيلة مطلوب.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $batchDetails = getBatchByNumber($batchNumber);
        } catch (Throwable $batchError) {
            error_log('bootstrap_batch_print getBatchByNumber error: ' . $batchError->getMessage());
            echo json_encode(['success' => false, 'error' => 'تعذر تحميل بيانات التشغيلة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$batchDetails) {
            echo json_encode(['success' => false, 'error' => 'لم يتم العثور على التشغيلة المطلوبة.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $batchNumberRow = null;
        try {
            $batchNumberRow = $db->queryOne("SELECT * FROM batch_numbers WHERE batch_number = ? LIMIT 1", [$batchNumber]);
        } catch (Throwable $batchRowError) {
            error_log('bootstrap_batch_print batch_numbers query failed: ' . $batchRowError->getMessage());
        }

        $generateToken = static function (): string {
            if (function_exists('random_bytes')) {
                try {
                    return bin2hex(random_bytes(16));
                } catch (Throwable $ignored) {
                }
            }
            if (function_exists('openssl_random_pseudo_bytes')) {
                $opensslBytes = @openssl_random_pseudo_bytes(16);
                if ($opensslBytes !== false) {
                    return bin2hex($opensslBytes);
                }
            }
            return sha1(uniqid((string)mt_rand(), true));
        };

        $contextToken = $generateToken();

        $quantityValue = null;
        if (isset($batchNumberRow['quantity'])) {
            $quantityValue = (int)$batchNumberRow['quantity'];
        } elseif (isset($batchDetails['quantity_produced'])) {
            $quantityValue = (int)$batchDetails['quantity_produced'];
        } elseif (isset($batchDetails['quantity'])) {
            $quantityValue = (int)$batchDetails['quantity'];
        }
        if ($quantityValue === null || $quantityValue <= 0) {
            $quantityValue = 1;
        }

        $productName = $batchDetails['product_name'] ?? '';

        $workersNames = [];
        if (!empty($batchDetails['workers_details']) && is_array($batchDetails['workers_details'])) {
            foreach ($batchDetails['workers_details'] as $worker) {
                $name = trim((string)($worker['full_name'] ?? ''));
                if ($name === '' && isset($worker['id'])) {
                    $name = 'عامل #' . (int)$worker['id'];
                }
                if ($name !== '') {
                    $workersNames[] = $name;
                }
            }
        }
        $workersNames = array_values(array_unique($workersNames));

        $rawMaterialsSummary = [];
        if (!empty($batchDetails['raw_materials']) && is_array($batchDetails['raw_materials'])) {
            foreach ($batchDetails['raw_materials'] as $item) {
                $rawMaterialsSummary[] = [
                    'name' => trim((string)($item['name'] ?? 'مادة خام')),
                    'quantity' => isset($item['quantity_used']) ? (float)$item['quantity_used'] : null,
                    'unit' => $item['unit'] ?? null,
                ];
            }
        }

        $packagingSummary = [];
        if (!empty($batchDetails['packaging_materials_details']) && is_array($batchDetails['packaging_materials_details'])) {
            foreach ($batchDetails['packaging_materials_details'] as $item) {
                $packagingSummary[] = [
                    'name' => trim((string)($item['name'] ?? 'مادة تغليف')),
                    'quantity' => isset($item['quantity_used']) ? (float)$item['quantity_used'] : null,
                    'unit' => $item['unit'] ?? null,
                ];
            }
        }

        $honeySupplierName = null;
        $honeySupplierId = null;
        $packagingSupplierName = null;
        $packagingSupplierId = null;
        $extraSuppliers = [];
        $notes = null;
        $templateId = null;
        $quantityUnitLabel = null;
        $createdByName = '';
        $createdById = null;

        if (is_array($batchNumberRow)) {
            $honeySupplierId = isset($batchNumberRow['honey_supplier_id']) ? (int)$batchNumberRow['honey_supplier_id'] : null;
            $packagingSupplierId = isset($batchNumberRow['packaging_supplier_id']) ? (int)$batchNumberRow['packaging_supplier_id'] : null;
            $notes = $batchNumberRow['notes'] ?? null;
            $templateId = isset($batchNumberRow['template_id']) ? (int)$batchNumberRow['template_id'] : null;
            $createdById = isset($batchNumberRow['created_by']) ? (int)$batchNumberRow['created_by'] : null;

            if (array_key_exists('unit', $batchNumberRow) && $batchNumberRow['unit'] !== null) {
                $quantityUnitLabel = (string)$batchNumberRow['unit'];
            } elseif (array_key_exists('quantity_unit_label', $batchNumberRow) && $batchNumberRow['quantity_unit_label'] !== null) {
                $quantityUnitLabel = (string)$batchNumberRow['quantity_unit_label'];
            }

            if (!empty($batchNumberRow['all_suppliers'])) {
                $decodedSuppliers = json_decode((string)$batchNumberRow['all_suppliers'], true);
                if (is_array($decodedSuppliers)) {
                    foreach ($decodedSuppliers as $supplierRow) {
                        $label = trim((string)($supplierRow['name'] ?? ''));
                        if ($label === '' && !empty($supplierRow['type'])) {
                            $label = 'مورد (' . $supplierRow['type'] . ')';
                        }
                        if ($label !== '') {
                            $extraSuppliers[] = $label;
                        }
                    }
                }
            }
        }

        if ($honeySupplierId) {
            try {
                $row = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$honeySupplierId]);
                if (!empty($row['name'])) {
                    $honeySupplierName = $row['name'];
                }
            } catch (Throwable $supplierError) {
                error_log('bootstrap_batch_print honey supplier lookup failed: ' . $supplierError->getMessage());
            }
        }

        if ($packagingSupplierId) {
            try {
                $row = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$packagingSupplierId]);
                if (!empty($row['name'])) {
                    $packagingSupplierName = $row['name'];
                }
            } catch (Throwable $supplierError) {
                error_log('bootstrap_batch_print packaging supplier lookup failed: ' . $supplierError->getMessage());
            }
        }

        if ($createdById) {
            try {
                $userRow = $db->queryOne("SELECT full_name, username FROM users WHERE id = ? LIMIT 1", [$createdById]);
                if (!empty($userRow)) {
                    $createdByName = trim((string)($userRow['full_name'] ?? $userRow['username'] ?? ''));
                }
            } catch (Throwable $userError) {
                error_log('bootstrap_batch_print user lookup failed: ' . $userError->getMessage());
            }
        }

        $metadata = [
            'batch_number' => $batchNumber,
            'batch_id' => $batchDetails['id'] ?? null,
            'product_id' => $batchDetails['product_id'] ?? ($batchNumberRow['product_id'] ?? null),
            'product_name' => $productName,
            'production_date' => $batchDetails['production_date'] ?? null,
            'quantity' => $quantityValue,
            'unit' => $quantityUnitLabel ?? 'قطعة',
            'quantity_unit_label' => $quantityUnitLabel ?? 'قطعة',
            'created_by' => $createdByName,
            'created_by_id' => $createdById,
            'workers' => $workersNames,
            'honey_supplier_name' => $honeySupplierName,
            'honey_supplier_id' => $honeySupplierId,
            'packaging_supplier_name' => $packagingSupplierName,
            'packaging_supplier_id' => $packagingSupplierId,
            'extra_suppliers' => $extraSuppliers,
            'notes' => $notes,
            'raw_materials' => $rawMaterialsSummary,
            'packaging_materials' => $packagingSummary,
            'template_id' => $templateId,
            'context_token' => $contextToken,
        ];

        $_SESSION['created_batch_context_token'] = $contextToken;
        $_SESSION['created_batch_metadata'] = $metadata;
        $_SESSION['created_batch_numbers'] = [$batchNumber];
        $_SESSION['created_batch_product_name'] = $productName;
        $_SESSION['created_batch_quantity'] = $quantityValue;

        echo json_encode([
            'success' => true,
            'context_token' => $contextToken,
            'metadata' => $metadata,
            'quantity' => $quantityValue,
            'product_name' => $productName,
            'telegram_enabled' => isTelegramConfigured(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajaxAction === '1' && isset($_GET['id'])) {
        header('Content-Type: application/json; charset=utf-8');

        $productionId = intval($_GET['id']);

        $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
        $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
        $hasDateColumn = !empty($dateColumnCheck);
        $hasProductionDateColumn = !empty($productionDateColumnCheck);
        $dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

        $userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
        $workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
        $hasUserIdColumn = !empty($userIdColumnCheck);
        $hasWorkerIdColumn = !empty($workerIdColumnCheck);
        $userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

        if ($userIdColumn) {
            $production = $db->queryOne(
                "SELECT p.*, pr.name as product_name, u.full_name as worker_name
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 LEFT JOIN users u ON p.{$userIdColumn} = u.id
                 WHERE p.id = ?",
                [$productionId]
            );
        } else {
            $production = $db->queryOne(
                "SELECT p.*, pr.name as product_name, 'غير محدد' as worker_name
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 WHERE p.id = ?",
                [$productionId]
            );
        }

        if ($production) {
            $production['date'] = $production[$dateColumn] ?? $production['created_at'] ?? date('Y-m-d');
            echo json_encode(['success' => true, 'production' => $production], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => 'سجل الإنتاج غير موجود'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

require_once __DIR__ . '/../includes/table_styles.php';

$isPackagingPost = (
    $page === 'packaging_warehouse'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
);

if ($isPackagingPost) {
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        include $modulePath;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'صفحة مخزن أدوات التعبئة غير متاحة حالياً.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];

if (!in_array('assets/css/production-page.css', $pageStylesheets, true)) {
    $pageStylesheets[] = 'assets/css/production-page.css';
}

$isAjaxRequest = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (!empty($_POST['is_ajax'])) ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isAjaxRequest) {
    $ajaxModulePath = null;

    if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
        $ajaxModulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    } elseif ($page === 'my_salary' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_advance') {
        // تنظيف أي output buffer قبل تضمين الملف
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $ajaxModulePath = __DIR__ . '/../modules/user/my_salary.php';
    }

    if ($ajaxModulePath && file_exists($ajaxModulePath)) {
        include $ajaxModulePath;
        exit;
    }
}

// معالجة AJAX القديمة لمخزن أدوات التعبئة (للتوافق)
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        include $modulePath;
        exit;
    }
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['production_dashboard']) ? $lang['production_dashboard'] : 'لوحة الإنتاج';
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <?php
                // التحقق من وجود جدول الإنتاج
                $hasProductionTable = !empty($db->queryOne("SHOW TABLES LIKE 'production'"));
                $hasAttendanceTable = !empty($db->queryOne("SHOW TABLES LIKE 'attendance'"));

                $dateColumn = 'created_at';
                $userIdColumn = null;
                $todayProduction = [];
                $monthStats = [
                    'total_production' => 0,
                    'total_quantity' => 0,
                    'total_workers' => 0
                ];
                $attendanceStats = [
                    'total_days' => 0,
                    'total_hours' => 0
                ];
                $activitySummary = [
                    'today_production' => 0,
                    'month_production' => 0,
                    'pending_tasks' => 0,
                    'recent_production' => []
                ];

                if ($hasProductionTable) {
                    // التحقق من وجود الأعمدة
                    $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
                    $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
                    $hasDateColumn = !empty($dateColumnCheck);
                    $hasProductionDateColumn = !empty($productionDateColumnCheck);
                    $dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');
                    
                    $userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
                    $workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
                    $hasUserIdColumn = !empty($userIdColumnCheck);
                    $hasWorkerIdColumn = !empty($workerIdColumnCheck);
                    $userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);
                    $productionHasProductNameColumn = !empty($db->queryOne("SHOW COLUMNS FROM production LIKE 'product_name'"));
                    $productNameExpression = $productionHasProductNameColumn
                        ? "COALESCE(p.product_name, pr.name)"
                        : "pr.name";

                    // الحصول على ملخص الأنشطة
                    $activitySummary = getProductionActivitySummary();

                    // إحصائيات الإنتاج اليومي
                    $dateExpression = $dateColumn === 'created_at' ? 'created_at' : $dateColumn;
                    if ($userIdColumn) {
                        $todayProduction = $db->query(
                            "SELECT p.*, {$productNameExpression} AS product_name, u.full_name as worker_name 
                             FROM production p 
                             LEFT JOIN products pr ON p.product_id = pr.id 
                             LEFT JOIN users u ON p.{$userIdColumn} = u.id 
                             WHERE DATE(p.{$dateExpression}) = CURDATE() 
                             ORDER BY p.created_at DESC 
                             LIMIT 10"
                        );
                    } else {
                        $todayProduction = $db->query(
                            "SELECT p.*, {$productNameExpression} AS product_name, 'غير محدد' as worker_name 
                             FROM production p 
                             LEFT JOIN products pr ON p.product_id = pr.id 
                             WHERE DATE(p.{$dateExpression}) = CURDATE() 
                             ORDER BY p.created_at DESC 
                             LIMIT 10"
                        );
                    }

                    // إحصائيات الإنتاج الشهري
                    if ($userIdColumn) {
                        $monthStats = $db->queryOne(
                            "SELECT 
                                COUNT(*) as total_production,
                                SUM(quantity) as total_quantity,
                                COUNT(DISTINCT {$userIdColumn}) as total_workers
                             FROM production 
                             WHERE MONTH({$dateExpression}) = MONTH(NOW()) 
                             AND YEAR({$dateExpression}) = YEAR(NOW()) 
                             AND status = 'approved'"
                        );
                    } else {
                        $monthStats = $db->queryOne(
                            "SELECT 
                                COUNT(*) as total_production,
                                SUM(quantity) as total_quantity,
                                0 as total_workers
                             FROM production 
                             WHERE MONTH({$dateExpression}) = MONTH(NOW()) 
                             AND YEAR({$dateExpression}) = YEAR(NOW()) 
                             AND status = 'approved'"
                        );
                    }
                }

                if ($hasAttendanceTable) {
                    $attendanceStats = $db->queryOne(
                        "SELECT 
                            COUNT(*) as total_days,
                            SUM(TIMESTAMPDIFF(HOUR, check_in, IFNULL(check_out, NOW()))) as total_hours
                         FROM attendance 
                         WHERE user_id = ? 
                         AND MONTH(date) = MONTH(NOW()) 
                         AND YEAR(date) = YEAR(NOW())
                         AND status = 'present'",
                        [$currentUser['id']]
                    );
                }

                $notifications = getUserNotifications($currentUser['id'], true, 10) ?? [];
                $tasksTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'tasks'"));
                $activeTaskTitles = [];

                if ($tasksTableExists) {
                    try {
                        $userTasks = $db->query(
                            "SELECT title FROM tasks 
                             WHERE assigned_to = ? 
                             AND status NOT IN ('completed', 'cancelled')",
                            [$currentUser['id']]
                        );

                        if (!empty($userTasks)) {
                            foreach ($userTasks as $taskRow) {
                                $title = trim((string)($taskRow['title'] ?? ''));
                                if ($title === '') {
                                    continue;
                                }
                                $normalized = mb_strtolower($title, 'UTF-8');
                                $activeTaskTitles[$normalized] = true;
                            }
                        }
                    } catch (Exception $taskLookupError) {
                        error_log('Dashboard task notification filter error: ' . $taskLookupError->getMessage());
                    }
                }

                $containsText = static function ($text, $needle) {
                    if ($text === '' || $needle === '') {
                        return false;
                    }
                    if (function_exists('mb_stripos')) {
                        return mb_stripos($text, $needle) !== false;
                    }
                    return stripos($text, $needle) !== false;
                };

                if (!empty($notifications)) {
                    $notifications = array_filter(
                        $notifications,
                        function ($notification) use ($db, $currentUser, $containsText, $tasksTableExists, $activeTaskTitles) {
                            $title = trim($notification['title'] ?? '');
                            $message = trim($notification['message'] ?? '');
                            $link = trim($notification['link'] ?? '');

                            $isCompletionAlert =
                                $containsText($message, 'كمكتملة') ||
                                $containsText($title, 'كمكتملة') ||
                                ($link !== '' && strpos($link, 'status=completed') !== false);

                            if ($isCompletionAlert) {
                                if (!empty($notification['id'])) {
                                    markNotificationAsRead((int)$notification['id'], (int)$currentUser['id']);
                                }
                                return false;
                            }

                            if (!empty($notification['id']) && ($containsText($title, 'تم إكمال') || $containsText($title, 'تم تحديث حالة'))) {
                                $titleSnippet = mb_substr($title, 0, 120);
                                $task = $db->queryOne(
                                    "SELECT status FROM tasks 
                                     WHERE assigned_to = ? 
                                     AND (
                                        title = ? 
                                        OR title LIKE CONCAT('%', ?) 
                                        OR ? LIKE CONCAT('%', title, '%')
                                     )
                                     ORDER BY updated_at DESC 
                                     LIMIT 1",
                                    [$currentUser['id'], $title, $titleSnippet, $title]
                                );

                                if ($task && $containsText((string)($task['status'] ?? ''), 'completed')) {
                                    markNotificationAsRead((int)$notification['id'], (int)$currentUser['id']);
                                    return false;
                                }
                            }

                            if ($tasksTableExists && $message !== '') {
                                $normalizedMessage = mb_strtolower($message, 'UTF-8');
                                $looksLikeTaskNotification =
                                    $containsText($title, 'مهمة') ||
                                    $containsText($title, 'task') ||
                                    $containsText($title, 'إدارة');

                                if ($looksLikeTaskNotification && !isset($activeTaskTitles[$normalizedMessage])) {
                                    if (!empty($notification['id'])) {
                                        markNotificationAsRead((int)$notification['id'], (int)$currentUser['id']);
                                    }
                                    return false;
                                }
                            }

                            return true;
                        }
                    );

                    if (!empty($notifications)) {
                        $notifications = array_slice(array_values($notifications), 0, 5);
                    }
                }
                ?>
                
                <div class="page-header mb-4">
                    <h2><i class="bi bi-speedometer2 me-2"></i><?php echo isset($lang['production_dashboard']) ? $lang['production_dashboard'] : 'لوحة الإنتاج'; ?></h2>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-bell me-2"></i><?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($notifications)): ?>
                        <div class="list-group">
                            <?php foreach ($notifications as $notif): ?>
                                <div class="list-group-item production-dashboard-notification" data-notification-id="<?php echo (int)($notif['id'] ?? 0); ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($notif['title'] ?? ''); ?></h6>
                                        <p class="mb-1"><?php echo htmlspecialchars($notif['message'] ?? ''); ?></p>
                                        <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($notif['created_at'] ?? 'now')); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <?php if (empty($notif['read'])): ?>
                                            <span class="badge bg-primary d-block mb-2"><?php echo isset($lang['new']) ? $lang['new'] : 'جديد'; ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mark-dashboard-notification" data-id="<?php echo (int)($notif['id'] ?? 0); ?>">
                                            <i class="bi bi-check2 me-1"></i>تم الرؤية
                                        </button>
                                    </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-center text-muted mb-0"><?php echo isset($lang['no_notifications']) ? $lang['no_notifications'] : 'لا توجد إشعارات حالياً'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                    window.initialNotifications = <?php echo json_encode($notifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                </script>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.mark-dashboard-notification').forEach(function(button) {
                            button.addEventListener('click', function() {
                                const notificationId = this.getAttribute('data-id');
                                if (!notificationId) {
                                    return;
                                }

                                const parentItem = this.closest('.production-dashboard-notification');
                                const listGroup = parentItem ? parentItem.parentElement : null;
                                deleteNotification(notificationId).then(function() {
                                    if (parentItem) {
                                        parentItem.remove();
                                    }
                                    if (listGroup && !listGroup.querySelector('.production-dashboard-notification')) {
                                        listGroup.innerHTML = '<p class="text-center text-muted mb-0">لا توجد إشعارات حالياً</p>';
                                    }
                                }).catch(function(err) {
                                    console.error('Mark notification as read failed:', err);
                                });
                            });
                        });
                    });
                </script>
                
                <!-- آخر الإنتاج -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?php echo isset($lang['recent_production']) ? $lang['recent_production'] : 'آخر الإنتاج'; ?></h5>
                        <?php 
                        $basePath = getBasePath();
                        $productionUrl = rtrim($basePath, '/') . '/dashboard/production.php?page=production';
                        ?>
                        <a href="<?php echo $productionUrl; ?>" class="btn btn-sm btn-light">
                            <i class="bi bi-arrow-left me-2"></i><?php echo isset($lang['view_all']) ? $lang['view_all'] : 'عرض الكل'; ?>
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?></th>
                                        <th><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?></th>
                                        <th><?php echo isset($lang['worker']) ? $lang['worker'] : 'العامل'; ?></th>
                                        <th><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?></th>
                                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($todayProduction)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">
                                                <?php echo isset($lang['no_production_today']) ? $lang['no_production_today'] : 'لا يوجد إنتاج لهذا اليوم'; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($todayProduction as $prod): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($prod['product_name'] ?? 'غير محدد'); ?></td>
                                                <td><?php echo number_format($prod['quantity'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($prod['worker_name'] ?? 'غير محدد'); ?></td>
                                                <td><?php echo date('Y-m-d', strtotime($prod[$dateColumn] ?? $prod['created_at'] ?? 'now')); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($prod['status'] ?? 'pending') === 'approved' ? 'success' : (($prod['status'] ?? 'pending') === 'rejected' ? 'danger' : 'warning'); ?>">
                                                        <?php 
                                                        $status = $prod['status'] ?? 'pending';
                                                        echo isset($lang[$status]) ? $lang[$status] : ucfirst($status);
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($page === 'chat'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/chat/group_chat.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">وحدة الدردشة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'production'): ?>
                <!-- صفحة إدارة الإنتاج -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/production.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الإنتاج غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'tasks'): ?>
                <!-- صفحة إدارة المهام -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/tasks.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المهام غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'packaging_warehouse'): ?>
                <!-- صفحة مخزن أدوات التعبئة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن أدوات التعبئة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'raw_materials_warehouse'): ?>
                <!-- صفحة مخزن الخامات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/raw_materials_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن الخامات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'honey_warehouse'): ?>
                <!-- إعادة توجيه من الرابط القديم -->
                <?php 
                header('Location: production.php?page=raw_materials_warehouse&section=honey');
                exit;
                ?>
                
            <?php elseif ($page === 'inventory'): ?>
                <!-- صفحة المنتجات النهائية -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/final_products.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المنتجات النهائية غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'my_salary'): ?>
                <!-- صفحة مرتب المستخدم -->
                <?php 
                $modulePath = __DIR__ . '/../modules/user/my_salary.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'batch_reader'): ?>
                <!-- صفحة قارئ أرقام التشغيلات -->
                <div class="container-fluid p-0" style="height: 100vh; overflow: hidden;">
                    <iframe src="<?php echo getRelativeUrl('reader/index.php'); ?>" 
                            style="width: 100%; height: 100%; border: none; display: block;"></iframe>
                </div>
                
            <?php endif; ?>

<script>
    // تمرير بيانات المستخدم للـ JavaScript
    window.currentUser = {
        id: <?php echo $currentUser['id']; ?>,
        role: '<?php echo htmlspecialchars($currentUser['role']); ?>'
    };
</script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

