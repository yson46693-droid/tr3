<?php
/**
 * دوال مساعدة للإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_movements.php';

/**
 * التحقق من وجود عمود في جدول مع التخزين المؤقت للنتائج.
 */
function productionColumnExists($table, $column, $forceRefresh = false) {
    static $cache = [];

    $cacheKey = $table . '.' . $column;
    if ($forceRefresh) {
        unset($cache[$cacheKey]);
    }
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $db = db();

    try {
        $result = $db->queryOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $cache[$cacheKey] = !empty($result);
    } catch (Exception $e) {
        error_log("Production Helper: column existence check failed for {$table}.{$column}: " . $e->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

/**
 * إنشاء تعبير SELECT آمن لعمود قد يكون غير موجود.
 */
function getColumnSelectExpression($table, $column, $alias = null, $tableAlias = null) {
    $alias = $alias ?: $column;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $alias)) {
        throw new InvalidArgumentException('Invalid column alias supplied.');
    }

    $columnExists = productionColumnExists($table, $column);
    $qualifiedColumn = $tableAlias
        ? "`{$tableAlias}`.`{$column}`"
        : "`{$column}`";

    if ($columnExists) {
        if ($alias !== $column) {
            return "{$qualifiedColumn} AS `{$alias}`";
        }
        return $qualifiedColumn;
    }

    return "NULL AS `{$alias}`";
}

/**
 * التأكد من وجود عمود supplier_id في جدول production_materials
 */
function ensureProductionMaterialsSupplierColumn(): bool
{
    static $ensured = false;

    if ($ensured && productionColumnExists('production_materials', 'supplier_id')) {
        return true;
    }

    $db = db();

    try {
        $exists = productionColumnExists('production_materials', 'supplier_id');
        if (!$exists) {
            $db->execute("
                ALTER TABLE production_materials
                ADD COLUMN `supplier_id` int(11) NULL,
                ADD KEY `supplier_id` (`supplier_id`),
                ADD CONSTRAINT `production_materials_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
            ");
            $exists = productionColumnExists('production_materials', 'supplier_id', true);
        }
        $ensured = true;
        return $exists;
    } catch (Exception $e) {
        error_log('Production Helper: unable to ensure supplier_id column on production_materials: ' . $e->getMessage());
        $ensured = productionColumnExists('production_materials', 'supplier_id');
        return $ensured;
    }
}

/**
 * التأكد من وجود جدول سجل التوريدات
 */
function ensureProductionSupplyLogsTable(): bool
{
    static $ensured = false;

    if ($ensured) {
        return true;
    }

    $db = db();

    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `production_supply_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `material_category` varchar(50) NOT NULL,
              `material_label` varchar(190) DEFAULT NULL,
              `stock_source` varchar(80) DEFAULT NULL,
              `stock_id` int(11) DEFAULT NULL,
              `supplier_id` int(11) DEFAULT NULL,
              `supplier_name` varchar(190) DEFAULT NULL,
              `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
              `unit` varchar(20) DEFAULT 'كجم',
              `details` text DEFAULT NULL,
              `recorded_by` int(11) DEFAULT NULL,
              `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `production_supply_logs_category_date_idx` (`material_category`, `recorded_at`),
              KEY `production_supply_logs_supplier_idx` (`supplier_id`),
              KEY `production_supply_logs_recorded_at_idx` (`recorded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ensured = true;
        return true;
    } catch (Exception $e) {
        error_log('Production Helper: unable to ensure production_supply_logs table: ' . $e->getMessage());
        return false;
    }
}

/**
 * التأكد من وجود جدول تلفيات المواد الخام
 */
function ensureRawMaterialDamageLogsTable(): bool
{
    static $ensured = false;

    if ($ensured) {
        return true;
    }

    $db = db();

    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `raw_material_damage_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `material_category` varchar(50) NOT NULL COMMENT 'نوع الخام (honey, olive_oil, beeswax, derivatives, nuts)',
              `stock_id` int(11) DEFAULT NULL COMMENT 'معرف السجل في جدول المخزون',
              `supplier_id` int(11) DEFAULT NULL COMMENT 'المورد المرتبط',
              `item_label` varchar(255) NOT NULL COMMENT 'اسم المادة التالفة',
              `variety` varchar(255) DEFAULT NULL COMMENT 'النوع/الصنف (اختياري)',
              `quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية التالفة',
              `unit` varchar(20) NOT NULL DEFAULT 'كجم' COMMENT 'وحدة القياس',
              `reason` text DEFAULT NULL COMMENT 'سبب التلف',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `material_category` (`material_category`),
              KEY `created_by` (`created_by`),
              KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ensured = true;
        return true;
    } catch (Exception $e) {
        error_log('Production Helper: unable to ensure raw_material_damage_logs table: ' . $e->getMessage());
        return false;
    }
}

/**
 * تسجيل توريد جديد في سجل الإنتاج
 *
 * @param array{
 *     material_category:string,
 *     material_label?:string|null,
 *     stock_source?:string|null,
 *     stock_id?:int|null,
 *     supplier_id?:int|null,
 *     supplier_name?:string|null,
 *     quantity?:float|int|string|null,
 *     unit?:string|null,
 *     details?:string|null,
 *     recorded_by?:int|null,
 *     recorded_at?:string|null
 * } $payload
 */
function recordProductionSupplyLog(array $payload): bool
{
    if (empty($payload['material_category'])) {
        return false;
    }

    if (!ensureProductionSupplyLogsTable()) {
        return false;
    }

    $db = db();

    $materialCategory = mb_substr(trim((string)$payload['material_category']), 0, 50, 'UTF-8');
    $materialLabel = isset($payload['material_label']) ? mb_substr(trim((string)$payload['material_label']), 0, 190, 'UTF-8') : null;
    $stockSource = isset($payload['stock_source']) ? mb_substr(trim((string)$payload['stock_source']), 0, 80, 'UTF-8') : null;
    $stockId = isset($payload['stock_id']) ? (int)$payload['stock_id'] : null;
    $supplierId = isset($payload['supplier_id']) && $payload['supplier_id'] !== '' ? (int)$payload['supplier_id'] : null;
    $supplierName = isset($payload['supplier_name']) ? mb_substr(trim((string)$payload['supplier_name']), 0, 190, 'UTF-8') : null;
    $quantity = isset($payload['quantity']) ? (float)$payload['quantity'] : 0.0;
    $unit = isset($payload['unit']) ? mb_substr(trim((string)$payload['unit']), 0, 20, 'UTF-8') : 'كجم';
    $details = isset($payload['details']) ? mb_substr(trim((string)$payload['details']), 0, 1000, 'UTF-8') : null;
    $recordedBy = isset($payload['recorded_by']) ? (int)$payload['recorded_by'] : null;
    $recordedAt = isset($payload['recorded_at']) ? trim((string)$payload['recorded_at']) : null;

    try {
        if ($supplierId && $supplierName === null) {
            $supplier = $db->queryOne("SELECT name FROM suppliers WHERE id = ? LIMIT 1", [$supplierId]);
            if ($supplier && isset($supplier['name'])) {
                $supplierName = mb_substr(trim((string)$supplier['name']), 0, 190, 'UTF-8');
            }
        }

        $params = [
            $materialCategory,
            $materialLabel ?: null,
            $stockSource ?: null,
            $stockId ?: null,
            $supplierId ?: null,
            $supplierName ?: null,
            round($quantity, 3),
            $unit ?: 'كجم',
            $details ?: null,
            $recordedBy ?: null,
        ];

        $sql = "
            INSERT INTO production_supply_logs
                (material_category, material_label, stock_source, stock_id, supplier_id, supplier_name, quantity, unit, details, recorded_by, recorded_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($recordedAt ? '?' : 'DEFAULT') . ")
        ";

        if ($recordedAt) {
            $params[] = $recordedAt;
        }

        $db->execute($sql, $params);
        return true;
    } catch (Exception $e) {
        error_log('Production Helper: unable to record supply log: ' . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على سجل التوريدات حسب الفترة التصنيفية
 *
 * @param string $dateFrom تاريخ البداية (YYYY-MM-DD)
 * @param string $dateTo   تاريخ النهاية (YYYY-MM-DD)
 * @param string|null $category تصنيف المادة (اختياري)
 * @return array<int, array<string, mixed>>
 */
function getProductionSupplyLogs(string $dateFrom, string $dateTo, ?string $category = null): array
{
    if (!ensureProductionSupplyLogsTable()) {
        return [];
    }

    $db = db();

    $start = date('Y-m-d 00:00:00', strtotime($dateFrom));
    $end = date('Y-m-d 23:59:59', strtotime($dateTo));

    $sql = "
        SELECT id, material_category, material_label, stock_source, stock_id, supplier_id, supplier_name,
               quantity, unit, details, recorded_by, recorded_at
        FROM production_supply_logs
        WHERE recorded_at BETWEEN ? AND ?
    ";
    $params = [$start, $end];

    if ($category !== null && $category !== '') {
        $sql .= " AND material_category = ? ";
        $params[] = $category;
    }

    $sql .= " ORDER BY recorded_at DESC, id DESC LIMIT 500";

    try {
        return $db->query($sql, $params);
    } catch (Exception $e) {
        error_log('Production Helper: unable to fetch supply logs: ' . $e->getMessage());
        return [];
    }
}

/**
 * ربط مواد التغليف بعملية إنتاج
 */
function linkPackagingToProduction($productionId, $materials) {
    $db = db();
    
    try {
        // التحقق من وجود عمود material_id أو product_id
        $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
        $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
        $hasMaterialIdColumn = !empty($materialIdColumnCheck);
        $hasProductIdColumn = !empty($productIdColumnCheck);
        
        $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
        
        if (!$materialColumn) {
            error_log("Link Packaging Error: No material_id or product_id column found in production_materials");
            return false;
        }
        $supplierColumnExists = ensureProductionMaterialsSupplierColumn();

        // حذف المواد السابقة
        $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);
        
        // إضافة المواد الجديدة
        foreach ($materials as $material) {
            $materialId = intval($material['material_id'] ?? $material['product_id'] ?? 0);
            $quantity = floatval($material['quantity'] ?? $material['quantity_used'] ?? 0);
            $supplierId = isset($material['supplier_id']) ? intval($material['supplier_id']) : null;
            
            if ($materialId > 0 && $quantity > 0) {
                $columnsSql = "production_id, {$materialColumn}, quantity_used";
                $valuesSql = "?, ?, ?";
                $params = [$productionId, $materialId, $quantity];

                if ($supplierColumnExists) {
                    $columnsSql = "production_id, {$materialColumn}, supplier_id, quantity_used";
                    $valuesSql = "?, ?, ?, ?";
                    $params = [$productionId, $materialId, $supplierId ?: null, $quantity];
                }

                $db->execute(
                    "INSERT INTO production_materials ({$columnsSql}) VALUES ({$valuesSql})",
                    $params
                );
                
                // تسجيل حركة خروج للمواد
                recordInventoryMovement(
                    $materialId,
                    null,
                    'out',
                    $quantity,
                    'production',
                    $productionId,
                    "استخدام في الإنتاج #{$productionId}"
                );
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Link Packaging Error: " . $e->getMessage());
        return false;
    }
}

/**
 * التأكد من وجود منتج يمثل مادة خام أو أداة تعبئة
 */
function ensureProductionMaterialProductId($name, $category = 'raw_material', $unit = null) {
    $name = trim((string)$name);
    if ($name === '') {
        return null;
    }

    static $productCache = [];
    $cacheKey = mb_strtolower($name, 'UTF-8') . '|' . $category;
    if (isset($productCache[$cacheKey])) {
        return $productCache[$cacheKey];
    }

    $db = db();

    try {
        $existing = $db->queryOne("SELECT id, unit FROM products WHERE name = ? LIMIT 1", [$name]);
        if ($existing && !empty($existing['id'])) {
            $productCache[$cacheKey] = (int)$existing['id'];
            return $productCache[$cacheKey];
        }

        $unitValue = $unit ?: ($category === 'packaging' ? 'قطعة' : 'كجم');
        $result = $db->execute(
            "INSERT INTO products (name, category, unit, status, quantity) VALUES (?, ?, ?, 'active', 0)",
            [$name, $category, $unitValue]
        );
        $productId = (int)($result['insert_id'] ?? 0);
        $productCache[$cacheKey] = $productId;
        return $productId ?: null;
    } catch (Exception $e) {
        error_log("ensureProductionMaterialProductId error: " . $e->getMessage());
        return null;
    }
}

/**
 * حفظ المواد المستخدمة في عملية إنتاج داخل جدول production_materials
 */
function storeProductionMaterialsUsage($productionId, $rawMaterials = [], $packagingMaterials = []) {
    $productionId = intval($productionId);
    if ($productionId <= 0) {
        return;
    }

    try {
        $db = db();

        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'production_materials'");
        if (empty($tableCheck)) {
            return;
        }

        $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
        $columnName = !empty($materialIdColumnCheck) ? 'material_id' : 'product_id';

        $supplierColumnExists = ensureProductionMaterialsSupplierColumn();

        $materialsMap = [];
        $addMaterial = function($productId, $quantity, $supplierId = null) use (&$materialsMap) {
            $productId = intval($productId);
            $quantity = (float)$quantity;
            $supplierKey = $supplierId ? intval($supplierId) : 0;
            if ($productId <= 0 || $quantity <= 0) {
                return;
            }
            $mapKey = $productId . ':' . $supplierKey;
            if (!isset($materialsMap[$mapKey])) {
                $materialsMap[$mapKey] = [
                    'product_id' => $productId,
                    'quantity' => 0.0,
                    'supplier_id' => $supplierKey > 0 ? $supplierKey : null
                ];
            }
            $materialsMap[$mapKey]['quantity'] += $quantity;
        };

        static $packagingTableExists = null;
        if ($packagingTableExists === null) {
            $packagingTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
        }

        foreach ($packagingMaterials as $packItem) {
            $quantity = (float)($packItem['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $productId = isset($packItem['product_id']) ? (int)$packItem['product_id'] : 0;
            $packagingName = trim((string)($packItem['name'] ?? ''));
            $packagingUnit = $packItem['unit'] ?? null;

            if (!$productId && !empty($packItem['material_id']) && $packagingTableExists) {
                $packagingInfo = $db->queryOne(
                    "SELECT product_id, name, unit FROM packaging_materials WHERE id = ?",
                    [intval($packItem['material_id'])]
                );
                if ($packagingInfo) {
                    if (!empty($packagingInfo['product_id'])) {
                        $productId = (int)$packagingInfo['product_id'];
                    }
                    if ($packagingName === '' && !empty($packagingInfo['name'])) {
                        $packagingName = $packagingInfo['name'];
                    }
                    if ($packagingUnit === null && !empty($packagingInfo['unit'])) {
                        $packagingUnit = $packagingInfo['unit'];
                    }
                }
            }

            if (!$productId) {
                $nameForProduct = $packagingName !== '' ? $packagingName : ('مادة تعبئة #' . ($packItem['material_id'] ?? '?'));
                $productId = ensureProductionMaterialProductId($nameForProduct, 'packaging', $packagingUnit);
            }

            $supplierId = isset($packItem['supplier_id']) ? (int)$packItem['supplier_id'] : null;

            $addMaterial($productId, $quantity, $supplierId);
        }

        foreach ($rawMaterials as $rawItem) {
            $quantity = (float)($rawItem['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $productId = isset($rawItem['product_id']) ? (int)$rawItem['product_id'] : 0;
            $materialName = trim((string)($rawItem['display_name'] ?? $rawItem['material_name'] ?? ''));
            $materialUnit = $rawItem['unit'] ?? 'كجم';

            if (!$productId) {
                if ($materialName === '') {
                    $materialName = 'مادة خام';
                }
                $productId = ensureProductionMaterialProductId($materialName, 'raw_material', $materialUnit);
            }

            $supplierId = isset($rawItem['supplier_id']) ? (int)$rawItem['supplier_id'] : null;

            $addMaterial($productId, $quantity, $supplierId);
        }

        $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);

        foreach ($materialsMap as $mapItem) {
            $productId = $mapItem['product_id'];
            $totalQuantity = $mapItem['quantity'];
            $supplierId = $mapItem['supplier_id'] ?? null;

            $columnsSql = "production_id, {$columnName}, quantity_used";
            $valuesSql = "?, ?, ?";
            $params = [$productionId, $productId, $totalQuantity];

            if ($supplierColumnExists) {
                $columnsSql = "production_id, {$columnName}, supplier_id, quantity_used";
                $valuesSql = "?, ?, ?, ?";
                $params = [$productionId, $productId, $supplierId ?: null, $totalQuantity];
            }

            $db->execute(
                "INSERT INTO production_materials ({$columnsSql}) VALUES ({$valuesSql})",
                $params
            );
        }
    } catch (Exception $e) {
        error_log("storeProductionMaterialsUsage error: " . $e->getMessage());
    }
}

/**
 * حساب تكلفة الإنتاج بناءً على المواد
 */
function calculateProductionCost($productionId) {
    $db = db();
    
    // التحقق من وجود عمود material_id أو product_id
    $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
    $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
    $hasMaterialIdColumn = !empty($materialIdColumnCheck);
    $hasProductIdColumn = !empty($productIdColumnCheck);
    
    $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
    
    if (!$materialColumn) {
        return 0; // لا يوجد عمود للمواد
    }
    
    $materials = $db->query(
        "SELECT pm.quantity_used, p.unit_price
         FROM production_materials pm
         LEFT JOIN products p ON pm.{$materialColumn} = p.id
         WHERE pm.production_id = ?",
        [$productionId]
    );
    
    $totalCost = 0;
    foreach ($materials as $material) {
        $totalCost += ($material['quantity_used'] * ($material['unit_price'] ?? 0));
    }
    
    return $totalCost;
}

/**
 * التأكد من الأعمدة الإضافية لجداول قوالب المنتجات.
 */
function ensureProductTemplatesExtendedSchema($db): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $columnAdjustments = [
        'template_type' => "ALTER TABLE `product_templates` ADD COLUMN `template_type` varchar(50) DEFAULT 'general' AFTER `honey_quantity`",
        'source_template_id' => "ALTER TABLE `product_templates` ADD COLUMN `source_template_id` int(11) DEFAULT NULL AFTER `template_type`",
        'main_supplier_id' => "ALTER TABLE `product_templates` ADD COLUMN `main_supplier_id` int(11) DEFAULT NULL AFTER `source_template_id`",
        'notes' => "ALTER TABLE `product_templates` ADD COLUMN `notes` text DEFAULT NULL AFTER `main_supplier_id`",
        'details_json' => "ALTER TABLE `product_templates` ADD COLUMN `details_json` longtext DEFAULT NULL AFTER `notes`",
        'unit_price' => "ALTER TABLE `product_templates` ADD COLUMN `unit_price` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'سعر الوحدة بالجنيه' AFTER `details_json`",
    ];

    foreach ($columnAdjustments as $column => $alterSql) {
        try {
            $columnExists = $db->queryOne("SHOW COLUMNS FROM `product_templates` LIKE '{$column}'");
            if (empty($columnExists)) {
                $db->execute($alterSql);
            }
        } catch (Exception $e) {
            error_log('ensureProductTemplatesExtendedSchema: failed altering column ' . $column . ' -> ' . $e->getMessage());
        }
    }

    // فهارس إضافية
    try {
        $sourceIndex = $db->queryOne("SHOW INDEX FROM `product_templates` WHERE Key_name = 'source_template_id'");
        if (empty($sourceIndex)) {
            $db->execute("ALTER TABLE `product_templates` ADD KEY `source_template_id` (`source_template_id`)");
        }
    } catch (Exception $e) {
        error_log('ensureProductTemplatesExtendedSchema: failed adding index source_template_id -> ' . $e->getMessage());
    }

    try {
        $mainSupplierIndex = $db->queryOne("SHOW INDEX FROM `product_templates` WHERE Key_name = 'main_supplier_id'");
        if (empty($mainSupplierIndex)) {
            $db->execute("ALTER TABLE `product_templates` ADD KEY `main_supplier_id` (`main_supplier_id`)");
        }
    } catch (Exception $e) {
        error_log('ensureProductTemplatesExtendedSchema: failed adding index main_supplier_id -> ' . $e->getMessage());
    }

    // إزالة أي علاقة قديمة مع جدول المنتجات إذا كانت موجودة
    try {
        $fkInfo = $db->queryOne("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
            WHERE CONSTRAINT_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'product_templates' 
              AND REFERENCED_TABLE_NAME = 'products'
        ");
        if (!empty($fkInfo['CONSTRAINT_NAME'])) {
            $constraintName = $fkInfo['CONSTRAINT_NAME'];
            $db->execute("ALTER TABLE `product_templates` DROP FOREIGN KEY `{$constraintName}`");
        }
    } catch (Exception $e) {
        error_log('ensureProductTemplatesExtendedSchema: failed dropping old FK -> ' . $e->getMessage());
    }

    // التأكد من قابلية حقل product_id للرابط الاختياري فقط
    try {
        $productIdColumn = $db->queryOne("SHOW COLUMNS FROM `product_templates` LIKE 'product_id'");
        if (!empty($productIdColumn)) {
            $nullable = ($productIdColumn['Null'] ?? '') === 'YES';
            if (!$nullable) {
                $db->execute("ALTER TABLE `product_templates` MODIFY `product_id` int(11) NULL DEFAULT NULL");
            }
        }
    } catch (Exception $e) {
        error_log('ensureProductTemplatesExtendedSchema: failed updating product_id column -> ' . $e->getMessage());
    }

    // تعيين قيمة افتراضية لأنواع القوالب القديمة
    try {
        $db->execute("UPDATE product_templates SET template_type = 'legacy' WHERE (template_type IS NULL OR template_type = '')");
    } catch (Exception $e) {
        error_log('ensureProductTemplatesExtendedSchema: failed normalising template_type -> ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * مزامنة قالب موحد إلى جداول product_templates والملحقات.
 */
function syncUnifiedTemplateToProductTemplate($db, int $unifiedTemplateId)
{
    ensureProductTemplatesExtendedSchema($db);

    $template = $db->queryOne(
        "SELECT * FROM unified_product_templates WHERE id = ?",
        [$unifiedTemplateId]
    );

    if (!$template) {
        return null;
    }

    $rawMaterials = [];
    if (productionColumnExists('template_raw_materials', 'template_id')) {
        $rawMaterials = $db->query(
            "SELECT material_type, material_name, supplier_id, honey_variety, quantity, unit
             FROM template_raw_materials
             WHERE template_id = ?",
            [$unifiedTemplateId]
        );
    }

    $packagingItems = [];
    if (productionColumnExists('template_packaging', 'template_id')) {
        $packagingItems = $db->query(
            "SELECT tp.packaging_material_id, tp.quantity_per_unit,
                    pm.name AS packaging_name_db, pm.unit AS packaging_unit
             FROM template_packaging tp
             LEFT JOIN packaging_materials pm ON pm.id = tp.packaging_material_id
             WHERE tp.template_id = ?",
            [$unifiedTemplateId]
        );
    }

    $honeyQuantity = 0.0;
    foreach ($rawMaterials as $material) {
        if (in_array($material['material_type'], ['honey_raw', 'honey_filtered'], true)) {
            $honeyQuantity += (float)($material['quantity'] ?? 0);
        }
    }

    $templateType = 'unified';
    $createdBy = (int)($template['created_by'] ?? 0);
    $status = $template['status'] ?? 'active';
    $mainSupplierId = $template['main_supplier_id'] ?? null;
    $notes = $template['notes'] ?? null;
    $detailsJson = $template['form_payload'] ?? null;

    $existing = $db->queryOne(
        "SELECT id FROM product_templates WHERE source_template_id = ? LIMIT 1",
        [$unifiedTemplateId]
    );

    if ($existing) {
        $productTemplateId = (int)$existing['id'];
        $db->execute(
            "UPDATE product_templates 
             SET product_name = ?, honey_quantity = ?, status = ?, template_type = ?, main_supplier_id = ?, notes = ?, details_json = ?, updated_at = NOW()
             WHERE id = ?",
            [
                $template['product_name'],
                $honeyQuantity,
                $status,
                $templateType,
                $mainSupplierId,
                $notes,
                $detailsJson,
                $productTemplateId
            ]
        );
    } else {
        $result = $db->execute(
            "INSERT INTO product_templates (product_name, honey_quantity, status, created_by, template_type, source_template_id, main_supplier_id, notes, details_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $template['product_name'],
                $honeyQuantity,
                $status,
                $createdBy,
                $templateType,
                $unifiedTemplateId,
                $mainSupplierId,
                $notes,
                $detailsJson
            ]
        );
        $productTemplateId = (int)$result['insert_id'];
    }

    // تحديث المواد الخام
    $db->execute("DELETE FROM product_template_raw_materials WHERE template_id = ?", [$productTemplateId]);
    foreach ($rawMaterials as $material) {
        $materialName = trim((string)($material['material_name'] ?? ''));
        if ($materialName === '') {
            $materialName = match ($material['material_type']) {
                'honey_raw', 'honey_filtered' => 'عسل',
                'olive_oil' => 'زيت زيتون',
                'beeswax' => 'شمع عسل',
                'derivatives' => 'مشتق',
                'nuts' => 'مكسرات',
                default => 'مادة خام',
            };
        }

        $quantity = (float)($material['quantity'] ?? 0);
        if ($quantity <= 0) {
            continue;
        }

        $unit = $material['unit'] ?? 'وحدة';

        $db->execute(
            "INSERT INTO product_template_raw_materials (template_id, material_name, quantity_per_unit, unit) 
             VALUES (?, ?, ?, ?)",
            [$productTemplateId, $materialName, $quantity, $unit]
        );
    }

    // تحديث أدوات التعبئة
    $db->execute("DELETE FROM product_template_packaging WHERE template_id = ?", [$productTemplateId]);
    foreach ($packagingItems as $packaging) {
        $packagingId = (int)($packaging['packaging_material_id'] ?? 0);
        $quantityPerUnit = (float)($packaging['quantity_per_unit'] ?? 0);
        if ($packagingId <= 0 || $quantityPerUnit <= 0) {
            continue;
        }

        $packagingName = $packaging['packaging_name_db'] ?? '';
        if ($packagingName === '') {
            $fallback = $db->queryOne("SELECT name FROM packaging_materials WHERE id = ?", [$packagingId]);
            $packagingName = $fallback['name'] ?? ('أداة تعبئة #' . $packagingId);
        }

        $db->execute(
            "INSERT INTO product_template_packaging (template_id, packaging_material_id, packaging_name, quantity_per_unit)
             VALUES (?, ?, ?, ?)",
            [$productTemplateId, $packagingId, $packagingName, $quantityPerUnit]
        );
    }

    return $productTemplateId;
}

/**
 * مزامنة جميع القوالب الموحدة الموجودة إلى product_templates.
 */
function syncAllUnifiedTemplatesToProductTemplates($db): void
{
    ensureProductTemplatesExtendedSchema($db);

    if (!productionColumnExists('unified_product_templates', 'id')) {
        return;
    }

    $templates = $db->query("SELECT id FROM unified_product_templates");
    foreach ($templates as $template) {
        try {
            syncUnifiedTemplateToProductTemplate($db, (int)$template['id']);
        } catch (Exception $e) {
            error_log('syncAllUnifiedTemplatesToProductTemplates: ' . $e->getMessage());
        }
    }
}
/**
 * الحصول على تقرير الإنتاجية
 */
function getProductivityReport($userId = null, $dateFrom = null, $dateTo = null) {
    $db = db();
    
    // التحقق من وجود عمود material_id أو product_id في production_materials
    $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
    $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
    $hasMaterialIdColumn = !empty($materialIdColumnCheck);
    $hasProductIdColumn = !empty($productIdColumnCheck);
    
    $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
    
    // التحقق من وجود عمود date أو production_date في production
    $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
    $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
    $hasDateColumn = !empty($dateColumnCheck);
    $hasProductionDateColumn = !empty($productionDateColumnCheck);
    $dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');
    
    // التحقق من وجود عمود user_id أو worker_id في production
    $userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
    $workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
    $hasUserIdColumn = !empty($userIdColumnCheck);
    $hasWorkerIdColumn = !empty($workerIdColumnCheck);
    $userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);
    
    // بناء استعلام materials_count و total_cost بشكل ديناميكي
    $materialsCountSubquery = "(SELECT COUNT(*) FROM production_materials pm WHERE pm.production_id = p.id)";
    
    $totalCostSubquery = "0"; // القيمة الافتراضية
    if ($materialColumn) {
        $totalCostSubquery = "(SELECT SUM(pm.quantity_used * pr2.unit_price) 
                               FROM production_materials pm 
                               LEFT JOIN products pr2 ON pm.{$materialColumn} = pr2.id 
                               WHERE pm.production_id = p.id)";
    }
    
    $sql = "SELECT p.*, pr.name as product_name, 
                   {$materialsCountSubquery} as materials_count,
                   {$totalCostSubquery} as total_cost";
    
    if ($userIdColumn) {
        $sql .= ", p.{$userIdColumn} as user_id, u.full_name as user_name";
    } else {
        $sql .= ", NULL as user_id, 'غير محدد' as user_name";
    }
    
    $sql .= " FROM production p
              LEFT JOIN products pr ON p.product_id = pr.id";
    
    if ($userIdColumn) {
        $sql .= " LEFT JOIN users u ON p.{$userIdColumn} = u.id";
    }
    
    $sql .= " WHERE p.status = 'approved'";
    
    $params = [];
    
    if ($userId && $userIdColumn) {
        $sql .= " AND p.{$userIdColumn} = ?";
        $params[] = $userId;
    }
    
    if ($dateFrom) {
        $sql .= " AND DATE(p.{$dateColumn}) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND DATE(p.{$dateColumn}) <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " ORDER BY p.{$dateColumn} DESC, p.created_at DESC";
    
    return $db->query($sql, $params);
}

