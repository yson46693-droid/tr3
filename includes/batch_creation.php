<?php
declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * الحصول على اتصال PDO مهيأ.
 */
function batchCreationGetPdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('تعذر الاتصال بقاعدة البيانات: ' . $e->getMessage(), 0, $e);
    }

    return $pdo;
}

/**
 * التحقق من وجود جدول.
 */
function batchCreationTableExists(PDO $pdo, string $tableName): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        throw new InvalidArgumentException('Invalid table name supplied.');
    }

    $sql = sprintf("SHOW TABLES LIKE '%s'", addslashes($tableName));
    $result = $pdo->query($sql);

    return $result !== false && $result->fetchColumn() !== false;
}

/**
 * التحقق من وجود عمود داخل جدول.
 */
function batchCreationColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    if (
        !preg_match('/^[a-zA-Z0-9_]+$/', $tableName) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)
    ) {
        throw new InvalidArgumentException('Invalid identifier supplied.');
    }

    $sql = sprintf(
        "SHOW COLUMNS FROM `%s` LIKE '%s'",
        $tableName,
        addslashes($columnName)
    );

    $result = $pdo->query($sql);

    return $result !== false && $result->fetchColumn() !== false;
}

/**
 * Attempt to consume quantity from the honey stock table.
 *
 * @throws RuntimeException
 */
function batchCreationConsumeHoneyStock(
    PDO $pdo,
    string $quantityColumn,
    float $quantityRequired,
    ?int $supplierId,
    ?string $honeyVariety,
    string $materialName,
    string $unit
): void {
    if (!batchCreationTableExists($pdo, 'honey_stock')) {
        throw new RuntimeException('جدول مخزون العسل غير موجود لتغطية المادة: ' . $materialName);
    }

    if (!batchCreationColumnExists($pdo, 'honey_stock', $quantityColumn)) {
        throw new RuntimeException('عمود المخزون غير موجود في جدول العسل للمادة: ' . $materialName);
    }

    $attempts = [
        ['supplier' => $supplierId, 'variety' => $honeyVariety],
        ['supplier' => $supplierId, 'variety' => null],
        ['supplier' => null, 'variety' => null],
    ];

    $remaining = $quantityRequired;
    $updateStmt = $pdo->prepare("UPDATE honey_stock SET {$quantityColumn} = GREATEST({$quantityColumn} - ?, 0) WHERE id = ?");

    foreach ($attempts as $attempt) {
        if ($remaining <= 0) {
            break;
        }

        $sql = "SELECT id, {$quantityColumn} AS available FROM honey_stock WHERE {$quantityColumn} > 0";
        $params = [];

        if ($attempt['supplier']) {
            $sql .= " AND supplier_id = ?";
            $params[] = $attempt['supplier'];
        }

        if ($attempt['variety'] !== null && $attempt['variety'] !== '') {
            if (batchCreationColumnExists($pdo, 'honey_stock', 'honey_variety')) {
                $sql .= " AND honey_variety = ?";
                $params[] = $attempt['variety'];
            }
        }

        $sql .= " ORDER BY {$quantityColumn} DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float)($row['available'] ?? 0);
            if ($available <= 0) {
                continue;
            }

            $deduct = min($available, $remaining);
            $updateStmt->execute([$deduct, $row['id']]);
            $remaining -= $deduct;
        }
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException(
            sprintf(
                'المخزون غير كاف للمادة %s. الكمية المطلوبة %.3f %s، المتبقي %.3f %s.',
                $materialName,
                $quantityRequired,
                $unit,
                $remaining,
                $unit
            )
        );
    }
}

/**
 * Attempt to consume quantity from a simple stock table (olive oil, beeswax, derivatives, nuts, ...).
 *
 * @throws RuntimeException
 */
function batchCreationConsumeSimpleStock(
    PDO $pdo,
    string $tableName,
    string $quantityColumn,
    float $quantityRequired,
    ?int $supplierId,
    string $materialName,
    string $unit
): void {
    if (!batchCreationTableExists($pdo, $tableName)) {
        throw new RuntimeException('جدول المخزون ' . $tableName . ' غير موجود للمادة: ' . $materialName);
    }

    if (!batchCreationColumnExists($pdo, $tableName, $quantityColumn)) {
        throw new RuntimeException('عمود المخزون غير موجود في جدول ' . $tableName . ' للمادة: ' . $materialName);
    }

    $supplierColumnExists = batchCreationColumnExists($pdo, $tableName, 'supplier_id');

    $attemptSuppliers = [];
    if ($supplierColumnExists && $supplierId) {
        $attemptSuppliers[] = $supplierId;
    }
    $attemptSuppliers[] = null; // fallback without supplier filter

    $remaining = $quantityRequired;
    $updateStmt = $pdo->prepare("UPDATE {$tableName} SET {$quantityColumn} = GREATEST({$quantityColumn} - ?, 0) WHERE id = ?");

    foreach ($attemptSuppliers as $attemptSupplier) {
        if ($remaining <= 0) {
            break;
        }

        $sql = "SELECT id, {$quantityColumn} AS available FROM {$tableName} WHERE {$quantityColumn} > 0";
        $params = [];

        if ($supplierColumnExists && $attemptSupplier) {
            $sql .= " AND supplier_id = ?";
            $params[] = $attemptSupplier;
        }

        $sql .= " ORDER BY {$quantityColumn} DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float)($row['available'] ?? 0);
            if ($available <= 0) {
                continue;
            }

            $deduct = min($available, $remaining);
            $updateStmt->execute([$deduct, $row['id']]);
            $remaining -= $deduct;
        }
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException(
            sprintf(
                'المخزون غير كاف للمادة %s. الكمية المطلوبة %.3f %s، المتبقي %.3f %s.',
                $materialName,
                $quantityRequired,
                $unit,
                $remaining,
                $unit
            )
        );
    }
}

/**
 * Deduct stock for a specific material type using the specialised stock tables.
 *
 * @throws RuntimeException
 */
function batchCreationDeductTypedStock(PDO $pdo, array $material, float $unitsMultiplier): void
{
    $quantityPerUnit = (float)($material['quantity_per_unit'] ?? 0);
    if ($quantityPerUnit <= 0 || $unitsMultiplier <= 0) {
        return;
    }

    $totalRequired = $quantityPerUnit * $unitsMultiplier;
    $materialType = (string)($material['material_type'] ?? 'other');
    $materialName = trim((string)($material['material_name'] ?? 'مادة خام'));
    $supplierId = isset($material['supplier_id']) ? (int)$material['supplier_id'] : null;
    $unit = $material['unit'] ?? 'وحدة';
    $honeyVariety = $material['honey_variety'] ?? null;

    switch ($materialType) {
        case 'honey_raw':
            batchCreationConsumeHoneyStock($pdo, 'raw_honey_quantity', $totalRequired, $supplierId, $honeyVariety, $materialName, $unit);
            break;
        case 'honey_filtered':
        case 'honey':
        case 'honey_main':
            batchCreationConsumeHoneyStock($pdo, 'filtered_honey_quantity', $totalRequired, $supplierId, $honeyVariety, $materialName, $unit);
            break;
        case 'olive_oil':
            batchCreationConsumeSimpleStock($pdo, 'olive_oil_stock', 'quantity', $totalRequired, $supplierId, $materialName, $unit);
            break;
        case 'beeswax':
            batchCreationConsumeSimpleStock($pdo, 'beeswax_stock', 'weight', $totalRequired, $supplierId, $materialName, $unit);
            break;
        case 'derivatives':
            batchCreationConsumeSimpleStock($pdo, 'derivatives_stock', 'weight', $totalRequired, $supplierId, $materialName, $unit);
            break;
        case 'nuts':
            batchCreationConsumeSimpleStock($pdo, 'nuts_stock', 'quantity', $totalRequired, $supplierId, $materialName, $unit);
            break;
        default:
            throw new RuntimeException('لا يوجد إعداد مخزون مرتبط بالمادة: ' . $materialName . ' (النوع: ' . $materialType . ')');
    }
}

/**
 * التأكد من وجود الجداول المطلوبة للتشغيل.
 */
function batchCreationEnsureTables(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $tableStatements = [
        "CREATE TABLE IF NOT EXISTS `batches` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `product_id` int(11) DEFAULT NULL,
            `batch_number` varchar(100) NOT NULL,
            `production_date` date NOT NULL,
            `expiry_date` date DEFAULT NULL,
            `quantity` int(11) NOT NULL DEFAULT 0,
            `status` enum('in_production','completed','cancelled') DEFAULT 'in_production',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_batch_number` (`batch_number`),
            KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `batch_raw_materials` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `raw_material_id` int(11) DEFAULT NULL,
            `quantity_used` decimal(12,3) NOT NULL DEFAULT 0.000,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `raw_material_id` (`raw_material_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `batch_packaging` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `packaging_material_id` int(11) DEFAULT NULL,
            `quantity_used` decimal(12,3) NOT NULL DEFAULT 0.000,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `packaging_material_id` (`packaging_material_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `batch_workers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `employee_id` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `finished_products` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `product_id` int(11) DEFAULT NULL,
            `product_name` varchar(255) NOT NULL,
            `batch_number` varchar(100) NOT NULL,
            `production_date` date NOT NULL,
            `expiry_date` date DEFAULT NULL,
            `quantity_produced` int(11) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `product_id` (`product_id`),
            KEY `batch_number` (`batch_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($tableStatements as $sql) {
        $pdo->exec($sql);
    }

    try {
        if (!batchCreationColumnExists($pdo, 'batch_raw_materials', 'material_name')) {
            $pdo->exec("
                ALTER TABLE `batch_raw_materials`
                ADD COLUMN `material_name` varchar(255) DEFAULT NULL AFTER `raw_material_id`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding material_name column -> ' . $e->getMessage());
    }

    try {
        if (!batchCreationColumnExists($pdo, 'batch_raw_materials', 'unit')) {
            $pdo->exec("
                ALTER TABLE `batch_raw_materials`
                ADD COLUMN `unit` varchar(50) DEFAULT NULL AFTER `material_name`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding unit column -> ' . $e->getMessage());
    }

    try {
        if (!batchCreationColumnExists($pdo, 'batch_packaging', 'packaging_name')) {
            $pdo->exec("
                ALTER TABLE `batch_packaging`
                ADD COLUMN `packaging_name` varchar(255) DEFAULT NULL AFTER `packaging_material_id`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding packaging_name column -> ' . $e->getMessage());
    }

    try {
        if (!batchCreationColumnExists($pdo, 'batch_packaging', 'unit')) {
            $pdo->exec("
                ALTER TABLE `batch_packaging`
                ADD COLUMN `unit` varchar(50) DEFAULT NULL AFTER `packaging_name`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding packaging unit column -> ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * توليد رقم تشغيلة بالشكل BATCH-YYYYMMDD-###.
 */
function batchCreationGenerateNumber(PDO $pdo): string
{
    $datePrefix = 'BATCH-' . date('Ymd') . '-';

    for ($attempt = 0; $attempt < 25; $attempt++) {
        $sequence     = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $batchNumber  = $datePrefix . $sequence;
        $batchChecker = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE batch_number = ? LIMIT 1');
        $batchChecker->execute([$batchNumber]);

        if ((int) $batchChecker->fetchColumn() === 0) {
            return $batchNumber;
        }
    }

    throw new RuntimeException('تعذر إنشاء رقم تشغيل فريد بعد عدة محاولات');
}

/**
 * إنشاء تشغيلة إنتاج جديدة اعتماداً على القالب وعدد الوحدات المطلوبة.
 *
 * @return array{
 *     success: bool,
 *     message?: string,
 *     batch_id?: int,
 *     batch_number?: string,
 *     product_id?: int|null,
 *     product_name?: string,
 *     quantity?: int,
 *     production_date?: string,
 *     expiry_date?: string,
 *     workers?: array<int, array{id:int,name:string}>
 * }
 */
function batchCreationCreate(int $templateId, int $units): array
{
    if ($templateId <= 0) {
        return ['success' => false, 'message' => 'معرف القالب غير صالح'];
    }

    if ($units <= 0) {
        return ['success' => false, 'message' => 'عدد الوحدات غير صالح'];
    }

    $pdo = batchCreationGetPdo();

    try {
        batchCreationEnsureTables($pdo);
        foreach (['product_templates', 'batches', 'finished_products'] as $requiredTable) {
            if (!batchCreationTableExists($pdo, $requiredTable)) {
                throw new RuntimeException("جدول {$requiredTable} غير موجود في قاعدة البيانات");
            }
        }

        $pdo->beginTransaction();

        $batchRawMaterialsExists = batchCreationTableExists($pdo, 'batch_raw_materials');
        $batchRawHasNameColumn = $batchRawMaterialsExists && batchCreationColumnExists($pdo, 'batch_raw_materials', 'material_name');
        $batchRawHasUnitColumn = $batchRawMaterialsExists && batchCreationColumnExists($pdo, 'batch_raw_materials', 'unit');
        $batchRawInsertStatement = null;
        if ($batchRawMaterialsExists) {
            $rawInsertColumns = ['batch_id', 'raw_material_id', 'quantity_used'];
            $rawInsertPlaceholders = ['?', '?', '?'];
            if ($batchRawHasNameColumn) {
                $rawInsertColumns[] = 'material_name';
                $rawInsertPlaceholders[] = '?';
            }
            if ($batchRawHasUnitColumn) {
                $rawInsertColumns[] = 'unit';
                $rawInsertPlaceholders[] = '?';
            }
            $batchRawInsertStatement = $pdo->prepare(
                'INSERT INTO batch_raw_materials (' . implode(', ', $rawInsertColumns) . ') VALUES (' . implode(', ', $rawInsertPlaceholders) . ')'
            );
        }
        $pendingRawMaterialRows = [];

        $batchPackagingExists = batchCreationTableExists($pdo, 'batch_packaging');
        $batchPackagingHasNameColumn = $batchPackagingExists && batchCreationColumnExists($pdo, 'batch_packaging', 'packaging_name');
        $batchPackagingHasUnitColumn = $batchPackagingExists && batchCreationColumnExists($pdo, 'batch_packaging', 'unit');
        $batchPackagingInsertStatement = null;
        if ($batchPackagingExists) {
            $packInsertColumns = ['batch_id', 'packaging_material_id', 'quantity_used'];
            $packInsertPlaceholders = ['?', '?', '?'];
            if ($batchPackagingHasNameColumn) {
                $packInsertColumns[] = 'packaging_name';
                $packInsertPlaceholders[] = '?';
            }
            if ($batchPackagingHasUnitColumn) {
                $packInsertColumns[] = 'unit';
                $packInsertPlaceholders[] = '?';
            }
            $batchPackagingInsertStatement = $pdo->prepare(
                'INSERT INTO batch_packaging (' . implode(', ', $packInsertColumns) . ') VALUES (' . implode(', ', $packInsertPlaceholders) . ')'
            );
        }

        // جلب بيانات القالب
        $templateStmt = $pdo->prepare("
            SELECT 
                t.id,
                t.product_id,
                t.details_json,
                COALESCE(t.product_name, p.name) AS product_name,
                COALESCE(p.id, t.product_id)     AS resolved_product_id
            FROM product_templates t
            LEFT JOIN products p ON t.product_id = p.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $templateStmt->execute([$templateId]);
        $template = $templateStmt->fetch();

        if (!$template) {
            throw new RuntimeException('القالب غير موجود');
        }

        $productId   = (int) ($template['resolved_product_id'] ?? 0);
        $productName = trim((string) ($template['product_name'] ?? ''));

        if ($productId <= 0 && $productName === '') {
            throw new RuntimeException('المنتج المرتبط بالقالب غير محدد');
        }

        $templateDetailsMap = [];
        if (!empty($template['details_json'])) {
            $decodedDetails = json_decode((string)$template['details_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($decodedDetails['raw_materials']) && is_array($decodedDetails['raw_materials'])) {
                foreach ($decodedDetails['raw_materials'] as $detailEntry) {
                    if (!is_array($detailEntry)) {
                        continue;
                    }
                    $detailName = '';
                    if (!empty($detailEntry['name'])) {
                        $detailName = (string)$detailEntry['name'];
                    } elseif (!empty($detailEntry['material_name'])) {
                        $detailName = (string)$detailEntry['material_name'];
                    }
                    $normalizedName = $detailName !== ''
                        ? (function_exists('mb_strtolower') ? mb_strtolower(trim($detailName), 'UTF-8') : strtolower(trim($detailName)))
                        : '';
                    if ($normalizedName !== '') {
                        $templateDetailsMap[$normalizedName] = $detailEntry;
                    }
                }
            }
        }

        // تجهيز الجداول المرتبطة بالمواد الخام
        $templateMaterialsTable = batchCreationTableExists($pdo, 'template_materials')
            ? 'template_materials'
            : (batchCreationTableExists($pdo, 'product_template_materials') ? 'product_template_materials' : null);

        if ($templateMaterialsTable === null) {
            throw new RuntimeException('جدول المواد الخام غير موجود');
        }

        $rawInventoryTable = batchCreationTableExists($pdo, 'raw_materials') ? 'raw_materials' : null;
        $canUpdateRawStock = $rawInventoryTable !== null && $templateMaterialsTable === 'template_materials';

        $materials = [];
        $materialsForStockDeduction = [];

        if ($templateMaterialsTable === 'product_template_materials') {
            $materialsStmt = $pdo->prepare("
                SELECT id, material_type, material_name, material_id, quantity_per_unit, unit, notes
                FROM product_template_materials
                WHERE template_id = ?
            ");
            $materialsStmt->execute([$templateId]);
            $productTemplateMaterials = $materialsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($productTemplateMaterials)) {
                throw new RuntimeException('القالب لا يحتوي على مواد خام');
            }

            foreach ($productTemplateMaterials as $materialRow) {
                $materialName = trim((string)($materialRow['material_name'] ?? ''));
                $normalizedName = $materialName !== ''
                    ? (function_exists('mb_strtolower') ? mb_strtolower($materialName, 'UTF-8') : strtolower($materialName))
                    : '';
                $detailEntry = ($normalizedName !== '' && isset($templateDetailsMap[$normalizedName]))
                    ? $templateDetailsMap[$normalizedName]
                    : [];

                $materialType = isset($detailEntry['type']) ? (string)$detailEntry['type'] : (string)($materialRow['material_type'] ?? 'other');
                if ($materialType === 'honey') {
                    $materialType = 'honey_filtered';
                } elseif ($materialType === 'honey_main') {
                    $materialType = 'honey_filtered';
                }

                $quantityPerUnit = isset($materialRow['quantity_per_unit'])
                    ? (float)$materialRow['quantity_per_unit']
                    : (isset($detailEntry['quantity']) ? (float)$detailEntry['quantity'] : 0.0);

                $materialsForStockDeduction[] = [
                    'material_type'    => $materialType,
                    'material_name'    => $materialName !== '' ? $materialName : ($detailEntry['name'] ?? $detailEntry['material_name'] ?? 'مادة خام'),
                    'supplier_id'      => isset($detailEntry['supplier_id'])
                        ? (int)$detailEntry['supplier_id']
                        : (!empty($materialRow['material_id']) ? (int)$materialRow['material_id'] : null),
                    'quantity_per_unit'=> $quantityPerUnit,
                    'unit'             => $detailEntry['unit'] ?? ($materialRow['unit'] ?? 'كجم'),
                    'honey_variety'    => $detailEntry['honey_variety'] ?? null,
                ];
            }
        } else {
            $materialIdColumn = batchCreationColumnExists($pdo, $templateMaterialsTable, 'raw_material_id')
                ? 'raw_material_id'
                : (batchCreationColumnExists($pdo, $templateMaterialsTable, 'material_id') ? 'material_id' : null);

            if ($materialIdColumn === null) {
                throw new RuntimeException('لم يتم العثور على عمود معرف المادة الخام في جدول القوالب');
            }

            $materialNameColumn = batchCreationColumnExists($pdo, $templateMaterialsTable, 'material_name')
                ? 'material_name'
                : null;

            $rawStockColumn = batchCreationColumnExists($pdo, $rawInventoryTable, 'stock')
                ? 'stock'
                : (batchCreationColumnExists($pdo, $rawInventoryTable, 'quantity') ? 'quantity' : null);

            if ($rawStockColumn === null) {
                throw new RuntimeException('لم يتم العثور على عمود المخزون في جدول المواد الخام');
            }

            $rawUnitColumn = batchCreationColumnExists($pdo, $rawInventoryTable, 'unit') ? 'unit' : null;

            $materialsStmt = $pdo->prepare(sprintf(
                'SELECT 
                    rm.id AS raw_id,
                    %s AS raw_name,
                    tm.quantity_per_unit,
                    rm.%s AS available_stock,
                    %s
                FROM %s tm
                JOIN %s rm ON rm.id = tm.%s
                WHERE tm.template_id = ?',
                $materialNameColumn !== null
                    ? sprintf('COALESCE(rm.name, tm.%s)', $materialNameColumn)
                    : 'rm.name',
                $rawStockColumn,
                $rawUnitColumn !== null ? 'rm.' . $rawUnitColumn . ' AS unit' : 'NULL AS unit',
                $templateMaterialsTable,
                $rawInventoryTable,
                $materialIdColumn
            ));
            $materialsStmt->execute([$templateId]);
            $materials = $materialsStmt->fetchAll();
        }

        if (empty($materials) && empty($materialsForStockDeduction)) {
            throw new RuntimeException('القالب لا يحتوي على مواد خام صالحة للإنتاج');
        }

        // تجهيز جداول أدوات التعبئة
        $templatePackagingTable = batchCreationTableExists($pdo, 'template_packaging')
            ? 'template_packaging'
            : (batchCreationTableExists($pdo, 'product_template_packaging') ? 'product_template_packaging' : null);

        if ($templatePackagingTable === null) {
            throw new RuntimeException('جدول أدوات التعبئة غير موجود');
        }

        $packagingInventoryTable = batchCreationTableExists($pdo, 'packaging_materials') ? 'packaging_materials' : null;
        if ($packagingInventoryTable === null) {
            throw new RuntimeException('جدول مخزون أدوات التعبئة غير موجود');
        }

        $packagingIdColumn = batchCreationColumnExists($pdo, $templatePackagingTable, 'packaging_material_id')
            ? 'packaging_material_id'
            : null;

        if ($packagingIdColumn === null) {
            throw new RuntimeException('لم يتم العثور على عمود معرف أداة التعبئة في جدول القوالب');
        }

        $packagingNameColumn = batchCreationColumnExists($pdo, $templatePackagingTable, 'packaging_name')
            ? 'packaging_name'
            : null;

        $packagingStockColumn = batchCreationColumnExists($pdo, $packagingInventoryTable, 'stock')
            ? 'stock'
            : (batchCreationColumnExists($pdo, $packagingInventoryTable, 'quantity') ? 'quantity' : null);

        if ($packagingStockColumn === null) {
            throw new RuntimeException('لم يتم العثور على عمود المخزون في جدول أدوات التعبئة');
        }

        $packagingUnitColumn = batchCreationColumnExists($pdo, $packagingInventoryTable, 'unit') ? 'unit' : null;

        $packagingStmt = $pdo->prepare(sprintf(
            'SELECT 
                pm.id AS pack_id,
                %s AS pack_name,
                tp.quantity_per_unit,
                pm.%s AS available_stock,
                %s
            FROM %s tp
            JOIN %s pm ON pm.id = tp.%s
            WHERE tp.template_id = ?',
            $packagingNameColumn !== null
                ? sprintf('COALESCE(pm.name, tp.%s)', $packagingNameColumn)
                : 'pm.name',
            $packagingStockColumn,
            $packagingUnitColumn !== null ? 'pm.' . $packagingUnitColumn . ' AS unit' : 'NULL AS unit',
            $templatePackagingTable,
            $packagingInventoryTable,
            $packagingIdColumn
        ));
        $packagingStmt->execute([$templateId]);
        $packaging = $packagingStmt->fetchAll();

        // التحقق من توفر المخزون في حالة وجود جدول مخزون عام
        if ($canUpdateRawStock && !empty($materials)) {
            foreach ($materials as $material) {
                $requiredQty = (float) $material['quantity_per_unit'] * $units;
                $available   = (float) ($material['available_stock'] ?? 0);

                if ($requiredQty > $available) {
                    throw new RuntimeException(
                        sprintf(
                            'المخزون غير كافٍ للمادة الخام "%s" (المتاح: %s، المطلوب: %s)',
                            $material['raw_name'],
                            number_format($available, 3),
                            number_format($requiredQty, 3)
                        )
                    );
                }
            }
        }

        foreach ($packaging as $pack) {
            $requiredQty = (float) $pack['quantity_per_unit'] * $units;
            $available   = (float) ($pack['available_stock'] ?? 0);

            if ($requiredQty > $available) {
                throw new RuntimeException(
                    sprintf(
                        'المخزون غير كافٍ لأداة التعبئة "%s" (المتاح: %s، المطلوب: %s)',
                        $pack['pack_name'],
                        number_format($available, 3),
                        number_format($requiredQty, 3)
                    )
                );
            }
        }

        if (!$canUpdateRawStock && !empty($materialsForStockDeduction)) {
            foreach ($materialsForStockDeduction as $stockMaterial) {
                batchCreationDeductTypedStock($pdo, $stockMaterial, (float)$units);

                if ($batchRawMaterialsExists && $batchRawInsertStatement instanceof PDOStatement) {
                    $qtyUsed = (float)($stockMaterial['quantity_per_unit'] ?? 0) * $units;
                    $pendingRawMaterialRows[] = [
                        'raw_material_id' => null,
                        'quantity_used'   => $qtyUsed,
                        'material_name'   => $batchRawHasNameColumn ? (string)($stockMaterial['material_name'] ?? '') : null,
                        'unit'            => $batchRawHasUnitColumn ? ($stockMaterial['unit'] ?? null) : null,
                    ];
                }
            }
        }

        // جلب العمال الحاضرين اليوم
        $workers = [];
        $today = date('Y-m-d');

        if (batchCreationTableExists($pdo, 'attendance') && batchCreationTableExists($pdo, 'employees')) {
            $workersStmt = $pdo->prepare("
                SELECT e.id, e.name
                FROM employees e
                JOIN attendance a ON a.employee_id = e.id
                WHERE a.attendance_date = ? AND a.status = 'present'
            ");
            $workersStmt->execute([$today]);
            $workers = $workersStmt->fetchAll();
        } elseif (batchCreationTableExists($pdo, 'attendance_records') && batchCreationTableExists($pdo, 'users')) {
            $userColumn = batchCreationColumnExists($pdo, 'attendance_records', 'user_id')
                ? 'user_id'
                : (batchCreationColumnExists($pdo, 'attendance_records', 'employee_id') ? 'employee_id' : null);

            if ($userColumn !== null) {
                $candidateDateColumns = ['attendance_date', 'date', 'check_in_time', 'checked_in_at', 'created_at'];
                $dateColumn = null;
                $dateColumnIsDateTime = false;
                foreach ($candidateDateColumns as $candidate) {
                    if (batchCreationColumnExists($pdo, 'attendance_records', $candidate)) {
                        $dateColumn = $candidate;
                        $dateColumnIsDateTime = in_array($candidate, ['check_in_time', 'checked_in_at', 'created_at'], true);
                        break;
                    }
                }

                $candidateStatusColumns = ['status', 'attendance_status', 'state'];
                $statusColumn = null;
                foreach ($candidateStatusColumns as $candidate) {
                    if (batchCreationColumnExists($pdo, 'attendance_records', $candidate)) {
                        $statusColumn = $candidate;
                        break;
                    }
                }

                $candidateNameColumns = ['full_name', 'name', 'username'];
                $userNameColumn = null;
                foreach ($candidateNameColumns as $candidate) {
                    if (batchCreationColumnExists($pdo, 'users', $candidate)) {
                        $userNameColumn = $candidate;
                        break;
                    }
                }
                if ($userNameColumn === null) {
                    $userNameColumn = 'id';
                }

                $conditions = [];
                $params = [];

                if ($dateColumn !== null) {
                    if ($dateColumnIsDateTime) {
                        $conditions[] = "DATE(ar.{$dateColumn}) = ?";
                    } else {
                        $conditions[] = "ar.{$dateColumn} = ?";
                    }
                    $params[] = $today;
                }

                if ($statusColumn !== null) {
                    $conditions[] = "ar.{$statusColumn} = 'present'";
                }

                $sql = "
                    SELECT u.id, u.{$userNameColumn} AS name
                    FROM attendance_records ar
                    JOIN users u ON ar.{$userColumn} = u.id
                ";

                if (!empty($conditions)) {
                    $sql .= ' WHERE ' . implode(' AND ', $conditions);
                }

                $workersStmt = $pdo->prepare($sql);
                $workersStmt->execute($params);
                $workers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $batchNumber    = batchCreationGenerateNumber($pdo);
        $productionDate = date('Y-m-d');
        $expiryDate     = date('Y-m-d', strtotime('+1 year'));

        // حفظ سجل التشغيلة
        $insertBatch = $pdo->prepare("
            INSERT INTO batches (product_id, batch_number, production_date, expiry_date, quantity)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insertBatch->execute([
            $productId > 0 ? $productId : null,
            $batchNumber,
            $productionDate,
            $expiryDate,
            $units,
        ]);

        $batchId = (int) $pdo->lastInsertId();
        if ($batchId <= 0) {
            throw new RuntimeException('تعذر حفظ بيانات التشغيله');
        }

        if ($canUpdateRawStock && !empty($materials)) {
            if (!$batchRawMaterialsExists || !$batchRawInsertStatement instanceof PDOStatement) {
                throw new RuntimeException('جدول تفاصيل المواد الخام غير موجود');
            }

            $updateRawStock = $pdo->prepare(sprintf(
                'UPDATE %s SET %s = GREATEST(%s - ?, 0) WHERE id = ?',
                $rawInventoryTable,
                $rawStockColumn,
                $rawStockColumn
            ));

            foreach ($materials as $material) {
                $qtyUsed = (float) $material['quantity_per_unit'] * $units;

                $updateRawStock->execute([$qtyUsed, $material['raw_id']]);
                $params = [$batchId, $material['raw_id'], $qtyUsed];
                if ($batchRawHasNameColumn) {
                    $params[] = (string)($material['raw_name'] ?? '');
                }
                if ($batchRawHasUnitColumn) {
                    $params[] = $material['unit'] ?? null;
                }
                $batchRawInsertStatement->execute($params);
            }
        }

        if (!empty($pendingRawMaterialRows) && $batchRawMaterialsExists && $batchRawInsertStatement instanceof PDOStatement) {
            foreach ($pendingRawMaterialRows as $pendingRow) {
                $params = [$batchId, $pendingRow['raw_material_id'], $pendingRow['quantity_used']];
                if ($batchRawHasNameColumn) {
                    $params[] = $pendingRow['material_name'];
                }
                if ($batchRawHasUnitColumn) {
                    $params[] = $pendingRow['unit'];
                }
                $batchRawInsertStatement->execute($params);
            }
        }

        if (!empty($packaging)) {
            if (!$batchPackagingExists || !$batchPackagingInsertStatement instanceof PDOStatement) {
                throw new RuntimeException('جدول تفاصيل أدوات التعبئة غير موجود');
            }

            $updatePackStock = $pdo->prepare(sprintf(
                'UPDATE %s SET %s = GREATEST(%s - ?, 0) WHERE id = ?',
                $packagingInventoryTable,
                $packagingStockColumn,
                $packagingStockColumn
            ));

            foreach ($packaging as $pack) {
                $qtyUsed = (float) $pack['quantity_per_unit'] * $units;

                if (!empty($pack['pack_id'])) {
                    $updatePackStock->execute([$qtyUsed, $pack['pack_id']]);
                }

                $params = [$batchId, $pack['pack_id'], $qtyUsed];
                if ($batchPackagingHasNameColumn) {
                    $params[] = (string)($pack['pack_name'] ?? '');
                }
                if ($batchPackagingHasUnitColumn) {
                    $params[] = $pack['unit'] ?? null;
                }
                $batchPackagingInsertStatement->execute($params);
            }
        }

        if (!empty($workers)) {
            if (!batchCreationTableExists($pdo, 'batch_workers')) {
                throw new RuntimeException('جدول العمال المرتبطين بالتشغيلة غير موجود');
            }

            $insertWorker = $pdo->prepare('INSERT INTO batch_workers (batch_id, employee_id) VALUES (?, ?)');
            foreach ($workers as $worker) {
                $insertWorker->execute([$batchId, $worker['id']]);
            }
        }

        $insertFinished = $pdo->prepare('
            INSERT INTO finished_products
                (batch_id, product_id, product_name, batch_number, production_date, expiry_date, quantity_produced)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $insertFinished->execute([
            $batchId,
            $productId > 0 ? $productId : null,
            $productName,
            $batchNumber,
            $productionDate,
            $expiryDate,
            $units,
        ]);

        $pdo->commit();

        return [
            'success'        => true,
            'message'        => 'تم إنشاء التشغيله بنجاح',
            'batch_id'       => $batchId,
            'batch_number'   => $batchNumber,
            'product_id'     => $productId > 0 ? $productId : null,
            'product_name'   => $productName,
            'quantity'       => $units,
            'production_date'=> $productionDate,
            'expiry_date'    => $expiryDate,
            'workers'        => array_map(
                static function (array $worker): array {
                    return [
                        'id'   => (int) $worker['id'],
                        'name' => (string) $worker['name'],
                    ];
                },
                $workers
            ),
        ];
    } catch (RuntimeException $runtimeException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => $runtimeException->getMessage(),
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Batch creation error: ' . $throwable->getMessage());

        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع أثناء إنشاء التشغيله',
        ];
    }
}

