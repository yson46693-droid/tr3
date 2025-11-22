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
 * Attempt to consume quantity from mixed_nuts table.
 *
 * @throws RuntimeException
 */
function batchCreationConsumeMixedNuts(
    PDO $pdo,
    float $quantityRequired,
    ?int $supplierId,
    string $materialName,
    string $unit
): void {
    if (!batchCreationTableExists($pdo, 'mixed_nuts')) {
        throw new RuntimeException('جدول مخزون الخلطات غير موجود للمادة: ' . $materialName);
    }

    // تنظيف اسم المادة من "(خلطة)" إذا كان موجوداً
    $cleanName = trim($materialName);
    $cleanName = preg_replace('/\s*\(خلطة\)\s*$/u', '', $cleanName);
    
    $attemptSuppliers = [];
    if ($supplierId) {
        $attemptSuppliers[] = $supplierId;
    }
    $attemptSuppliers[] = null; // fallback without supplier filter

    $remaining = $quantityRequired;
    $updateStmt = $pdo->prepare("UPDATE mixed_nuts SET total_quantity = GREATEST(total_quantity - ?, 0), updated_at = NOW() WHERE id = ?");

    foreach ($attemptSuppliers as $attemptSupplier) {
        if ($remaining <= 0) {
            break;
        }

        $sql = "SELECT id, total_quantity AS available FROM mixed_nuts WHERE total_quantity > 0";
        $params = [];

        if ($attemptSupplier) {
            $sql .= " AND supplier_id = ?";
            $params[] = $attemptSupplier;
        }

        // البحث عن الخلطة بالاسم
        if ($cleanName !== '') {
            $sql .= " AND (batch_name = ? OR batch_name LIKE ?)";
            $params[] = $cleanName;
            $params[] = '%' . addcslashes($cleanName, '%_') . '%';
        }

        $sql .= " ORDER BY total_quantity DESC";
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
                'المخزون غير كاف للخلطة %s. الكمية المطلوبة %.3f %s، المتبقي %.3f %s.',
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
        case 'honey_general':
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
            // التحقق أولاً إذا كانت المادة خلطة مكسرات (mixed_nuts)
            $isMixedNuts = false;
            if (batchCreationTableExists($pdo, 'mixed_nuts')) {
                // تنظيف اسم المادة من "(خلطة)" إذا كان موجوداً
                $cleanName = trim($materialName);
                $cleanName = preg_replace('/\s*\(خلطة\)\s*$/u', '', $cleanName);
                
                // البحث عن الخلطة في جدول mixed_nuts
                $checkSql = "SELECT COUNT(*) as count FROM mixed_nuts WHERE batch_name = ? OR batch_name LIKE ?";
                $checkParams = [$cleanName, '%' . addcslashes($cleanName, '%_') . '%'];
                
                if ($supplierId) {
                    $checkSql .= " AND supplier_id = ?";
                    $checkParams[] = $supplierId;
                }
                
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute($checkParams);
                $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($checkResult && (int)($checkResult['count'] ?? 0) > 0) {
                    $isMixedNuts = true;
                } else {
                    // محاولة أخرى بدون فلتر المورد
                    $checkSql2 = "SELECT COUNT(*) as count FROM mixed_nuts WHERE batch_name = ? OR batch_name LIKE ?";
                    $checkStmt2 = $pdo->prepare($checkSql2);
                    $checkStmt2->execute([$cleanName, '%' . addcslashes($cleanName, '%_') . '%']);
                    $checkResult2 = $checkStmt2->fetch(PDO::FETCH_ASSOC);
                    
                    if ($checkResult2 && (int)($checkResult2['count'] ?? 0) > 0) {
                        $isMixedNuts = true;
                    }
                }
            }
            
            if ($isMixedNuts) {
                // خصم من الخلطة (mixed_nuts)
                batchCreationConsumeMixedNuts($pdo, $totalRequired, $supplierId, $materialName, $unit);
            } else {
                // إذا لم توجد الخلطة، فشل العملية
                throw new RuntimeException(
                    sprintf(
                        'الخلطة "%s" غير موجودة في المخزون. يرجى التأكد من وجود الخلطة قبل إنشاء عملية الإنتاج.',
                        $materialName
                    )
                );
            }
            break;
        case 'raw_general':
            // محاولة التعرف على نوع المادة من اسمها والبحث في الجداول الخاصة
            $normalizedName = mb_strtolower(trim($materialName), 'UTF-8');
            
            // شمع -> beeswax_stock
            if (mb_stripos($normalizedName, 'شمع') !== false || stripos($normalizedName, 'beeswax') !== false || stripos($normalizedName, 'wax') !== false) {
                batchCreationConsumeSimpleStock($pdo, 'beeswax_stock', 'weight', $totalRequired, $supplierId, $materialName, $unit);
                break;
            }
            
            // عسل -> honey_stock
            if (mb_stripos($normalizedName, 'عسل') !== false || stripos($normalizedName, 'honey') !== false) {
                $hasRawKeyword = (mb_stripos($normalizedName, 'خام') !== false) || stripos($normalizedName, 'raw') !== false;
                if ($hasRawKeyword) {
                    batchCreationConsumeHoneyStock($pdo, 'raw_honey_quantity', $totalRequired, $supplierId, $honeyVariety, $materialName, $unit);
                } else {
                    batchCreationConsumeHoneyStock($pdo, 'filtered_honey_quantity', $totalRequired, $supplierId, $honeyVariety, $materialName, $unit);
                }
                break;
            }
            
            // زيت زيتون -> olive_oil_stock
            if (mb_stripos($normalizedName, 'زيت') !== false || stripos($normalizedName, 'olive') !== false || stripos($normalizedName, 'oil') !== false) {
                batchCreationConsumeSimpleStock($pdo, 'olive_oil_stock', 'quantity', $totalRequired, $supplierId, $materialName, $unit);
                break;
            }
            
            // مكسرات -> التحقق من الخلطة أولاً
            if (mb_stripos($normalizedName, 'مكسرات') !== false || mb_stripos($normalizedName, 'لوز') !== false || mb_stripos($normalizedName, 'جوز') !== false || 
                stripos($normalizedName, 'nuts') !== false || stripos($normalizedName, 'almond') !== false || stripos($normalizedName, 'walnut') !== false) {
                
                // التحقق أولاً إذا كانت المادة خلطة مكسرات (mixed_nuts)
                $isMixedNuts = false;
                if (batchCreationTableExists($pdo, 'mixed_nuts')) {
                    // تنظيف اسم المادة من "(خلطة)" إذا كان موجوداً
                    $cleanName = trim($materialName);
                    $cleanName = preg_replace('/\s*\(خلطة\)\s*$/u', '', $cleanName);
                    
                    // البحث عن الخلطة في جدول mixed_nuts
                    $checkSql = "SELECT COUNT(*) as count FROM mixed_nuts WHERE batch_name = ? OR batch_name LIKE ?";
                    $checkParams = [$cleanName, '%' . addcslashes($cleanName, '%_') . '%'];
                    
                    if ($supplierId) {
                        $checkSql .= " AND supplier_id = ?";
                        $checkParams[] = $supplierId;
                    }
                    
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute($checkParams);
                    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($checkResult && (int)($checkResult['count'] ?? 0) > 0) {
                        $isMixedNuts = true;
                    } else {
                        // محاولة أخرى بدون فلتر المورد
                        $checkSql2 = "SELECT COUNT(*) as count FROM mixed_nuts WHERE batch_name = ? OR batch_name LIKE ?";
                        $checkStmt2 = $pdo->prepare($checkSql2);
                        $checkStmt2->execute([$cleanName, '%' . addcslashes($cleanName, '%_') . '%']);
                        $checkResult2 = $checkStmt2->fetch(PDO::FETCH_ASSOC);
                        
                        if ($checkResult2 && (int)($checkResult2['count'] ?? 0) > 0) {
                            $isMixedNuts = true;
                        }
                    }
                }
                
                if ($isMixedNuts) {
                    // خصم من الخلطة (mixed_nuts)
                    batchCreationConsumeMixedNuts($pdo, $totalRequired, $supplierId, $materialName, $unit);
                } else {
                    // إذا لم توجد الخلطة، فشل العملية
                    throw new RuntimeException(
                        sprintf(
                            'الخلطة "%s" غير موجودة في المخزون. يرجى التأكد من وجود الخلطة قبل إنشاء عملية الإنتاج.',
                            $materialName
                        )
                    );
                }
                break;
            }
            
            // مشتقات -> derivatives_stock
            if (mb_stripos($normalizedName, 'مشتقات') !== false || stripos($normalizedName, 'derivatives') !== false || stripos($normalizedName, 'royal') !== false || stripos($normalizedName, 'propolis') !== false) {
                batchCreationConsumeSimpleStock($pdo, 'derivatives_stock', 'weight', $totalRequired, $supplierId, $materialName, $unit);
                break;
            }
            
            // إذا لم يتطابق مع أي نوع، نحاول البحث في raw_materials
            if (batchCreationTableExists($pdo, 'raw_materials')) {
                try {
                    $supplierColumnExists = batchCreationColumnExists($pdo, 'raw_materials', 'supplier_id');
                    $attemptSuppliers = [];
                    if ($supplierColumnExists && $supplierId) {
                        $attemptSuppliers[] = $supplierId;
                    }
                    $attemptSuppliers[] = null;
                    
                    $remaining = $totalRequired;
                    $updateStmt = $pdo->prepare("UPDATE raw_materials SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?");
                    
                    foreach ($attemptSuppliers as $attemptSupplier) {
                        if ($remaining <= 0) {
                            break;
                        }
                        
                        $sql = "SELECT id, quantity AS available FROM raw_materials WHERE quantity > 0";
                        $params = [];
                        
                        if ($supplierColumnExists && $attemptSupplier) {
                            $sql .= " AND supplier_id = ?";
                            $params[] = $attemptSupplier;
                        }
                        
                        // البحث بالاسم أيضاً إذا كان متوفراً
                        if (batchCreationColumnExists($pdo, 'raw_materials', 'name')) {
                            $sql .= " AND (name LIKE ? OR name = ?)";
                            $searchTerm = '%' . addcslashes($materialName, '%_') . '%';
                            $params[] = $searchTerm;
                            $params[] = $materialName;
                        }
                        
                        $sql .= " ORDER BY quantity DESC";
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
                    
                    if ($remaining <= 0.0001) {
                        // نجحنا في خصم الكمية من raw_materials
                        break;
                    }
                } catch (RuntimeException $rawMaterialsError) {
                    // إذا فشل البحث في raw_materials، نرمي الخطأ الأصلي
                }
            }
            
            throw new RuntimeException('لا يوجد إعداد مخزون مرتبط بالمادة: ' . $materialName . ' (النوع: ' . $materialType . ')');
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
            `material_name` varchar(255) DEFAULT NULL,
            `unit` varchar(50) DEFAULT NULL,
            `supplier_id` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `raw_material_id` (`raw_material_id`),
            KEY `supplier_id` (`supplier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `batch_packaging` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `packaging_material_id` int(11) DEFAULT NULL,
            `quantity_used` decimal(12,3) NOT NULL DEFAULT 0.000,
            `packaging_name` varchar(255) DEFAULT NULL,
            `unit` varchar(50) DEFAULT NULL,
            `supplier_id` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `packaging_material_id` (`packaging_material_id`),
            KEY `supplier_id` (`supplier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `batch_workers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `employee_id` int(11) NOT NULL,
            `worker_name` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `batch_suppliers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `batch_id` int(11) NOT NULL,
            `supplier_id` int(11) NOT NULL,
            `supplier_name` varchar(255) DEFAULT NULL,
            `role` varchar(100) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `batch_id` (`batch_id`),
            KEY `supplier_id` (`supplier_id`)
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
            `manager_unit_price` decimal(12,2) DEFAULT NULL,
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
        if (!batchCreationColumnExists($pdo, 'batch_raw_materials', 'supplier_id')) {
            $pdo->exec("
                ALTER TABLE `batch_raw_materials`
                ADD COLUMN `supplier_id` int(11) DEFAULT NULL AFTER `unit`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding supplier_id column to batch_raw_materials -> ' . $e->getMessage());
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

    try {
        if (!batchCreationColumnExists($pdo, 'batch_packaging', 'supplier_id')) {
            $pdo->exec("
                ALTER TABLE `batch_packaging`
                ADD COLUMN `supplier_id` int(11) DEFAULT NULL AFTER `unit`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding supplier_id column to batch_packaging -> ' . $e->getMessage());
    }

    try {
        if (!batchCreationTableExists($pdo, 'batch_suppliers')) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `batch_suppliers` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `batch_id` int(11) NOT NULL,
                  `supplier_id` int(11) NOT NULL,
                  `supplier_name` varchar(255) DEFAULT NULL,
                  `role` varchar(100) DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `batch_id` (`batch_id`),
                  KEY `supplier_id` (`supplier_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed ensuring batch_suppliers table -> ' . $e->getMessage());
    }

    try {
        if (batchCreationTableExists($pdo, 'batch_suppliers') && !batchCreationColumnExists($pdo, 'batch_suppliers', 'supplier_name')) {
            $pdo->exec("
                ALTER TABLE `batch_suppliers`
                ADD COLUMN `supplier_name` varchar(255) DEFAULT NULL AFTER `supplier_id`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding supplier_name column to batch_suppliers -> ' . $e->getMessage());
    }

    try {
        if (batchCreationTableExists($pdo, 'batch_workers') && !batchCreationColumnExists($pdo, 'batch_workers', 'worker_name')) {
            $pdo->exec("
                ALTER TABLE `batch_workers`
                ADD COLUMN `worker_name` varchar(255) DEFAULT NULL AFTER `employee_id`
            ");
        }
    } catch (Throwable $e) {
        error_log('batchCreationEnsureTables: failed adding worker_name column to batch_workers -> ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * توليد رقم تشغيلة بالشكل YYMMDD-XXXXXX (ستة أرقام عشوائية).
 */
function batchCreationGenerateNumber(PDO $pdo): string
{
    $datePrefix = date('ymd') . '-';

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $randomSegment = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $batchNumber   = $datePrefix . $randomSegment;
        $batchChecker  = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE batch_number = ? LIMIT 1');
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
function batchCreationCreate(int $templateId, int $units, array $rawUsage = [], array $packagingUsage = []): array
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

        $normalizeName = static function ($value): string {
            if ($value === null) {
                return '';
            }
            $trimmed = trim((string)$value);
            if ($trimmed === '') {
                return '';
            }
            $collapsed = preg_replace('/\s+/u', ' ', $trimmed);
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($collapsed, 'UTF-8');
            }
            return strtolower($collapsed);
        };

        $rawUsageByTemplateId = [];
        $rawUsageByName = [];
        foreach ($rawUsage as $usageEntry) {
            if (!is_array($usageEntry)) {
                continue;
            }
            $templateItemId = isset($usageEntry['template_item_id']) ? (int)$usageEntry['template_item_id'] : 0;
            if ($templateItemId > 0 && !isset($rawUsageByTemplateId[$templateItemId])) {
                $rawUsageByTemplateId[$templateItemId] = $usageEntry;
            }
            $nameCandidate = $usageEntry['material_name'] ?? ($usageEntry['display_name'] ?? '');
            $normalizedName = $normalizeName($nameCandidate);
            if ($normalizedName !== '' && !isset($rawUsageByName[$normalizedName])) {
                $rawUsageByName[$normalizedName] = $usageEntry;
            }
        }

        $packagingUsageByTemplateId = [];
        $packagingUsageByName = [];
        foreach ($packagingUsage as $packUsage) {
            if (!is_array($packUsage)) {
                continue;
            }
            $templateItemId = isset($packUsage['template_item_id']) ? (int)$packUsage['template_item_id'] : 0;
            if ($templateItemId > 0 && !isset($packagingUsageByTemplateId[$templateItemId])) {
                $packagingUsageByTemplateId[$templateItemId] = $packUsage;
            }
            $packName = $packUsage['name'] ?? '';
            $normalizedName = $normalizeName($packName);
            if ($normalizedName !== '' && !isset($packagingUsageByName[$normalizedName])) {
                $packagingUsageByName[$normalizedName] = $packUsage;
            }
        }

        $batchRawMaterialsExists = batchCreationTableExists($pdo, 'batch_raw_materials');
        $batchRawHasNameColumn = $batchRawMaterialsExists && batchCreationColumnExists($pdo, 'batch_raw_materials', 'material_name');
        $batchRawHasUnitColumn = $batchRawMaterialsExists && batchCreationColumnExists($pdo, 'batch_raw_materials', 'unit');
        $batchRawHasSupplierColumn = $batchRawMaterialsExists && batchCreationColumnExists($pdo, 'batch_raw_materials', 'supplier_id');
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
            if ($batchRawHasSupplierColumn) {
                $rawInsertColumns[] = 'supplier_id';
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
        $batchPackagingHasSupplierColumn = $batchPackagingExists && batchCreationColumnExists($pdo, 'batch_packaging', 'supplier_id');
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
            if ($batchPackagingHasSupplierColumn) {
                $packInsertColumns[] = 'supplier_id';
                $packInsertPlaceholders[] = '?';
            }
            $batchPackagingInsertStatement = $pdo->prepare(
                'INSERT INTO batch_packaging (' . implode(', ', $packInsertColumns) . ') VALUES (' . implode(', ', $packInsertPlaceholders) . ')'
            );
        }

        $batchSuppliersExists = batchCreationTableExists($pdo, 'batch_suppliers');
        $batchSuppliersHasNameColumn = $batchSuppliersExists && batchCreationColumnExists($pdo, 'batch_suppliers', 'supplier_name');
        $batchSuppliersInsertStatement = null;
        if ($batchSuppliersExists) {
            $supplierInsertColumns = ['batch_id', 'supplier_id'];
            $supplierInsertPlaceholders = ['?', '?'];
            if ($batchSuppliersHasNameColumn) {
                $supplierInsertColumns[] = 'supplier_name';
                $supplierInsertPlaceholders[] = '?';
            }
            $supplierInsertColumns[] = 'role';
            $supplierInsertPlaceholders[] = '?';
            $batchSuppliersInsertStatement = $pdo->prepare(
                'INSERT INTO batch_suppliers (' . implode(', ', $supplierInsertColumns) . ') VALUES (' . implode(', ', $supplierInsertPlaceholders) . ')'
            );
        }

        $supplierSnapshots = [];
        $collectSupplier = static function (?int $supplierId, string $role, ?string $name = null) use (&$supplierSnapshots): void {
            if ($supplierId !== null && $supplierId > 0) {
                if (!isset($supplierSnapshots[$supplierId])) {
                    $supplierSnapshots[$supplierId] = [
                        'roles' => [],
                        'name'  => null,
                    ];
                }
                $supplierSnapshots[$supplierId]['roles'][$role] = true;

                if ($name !== null) {
                    $trimmed = trim((string) $name);
                    if ($trimmed !== '') {
                        $supplierSnapshots[$supplierId]['name'] = $trimmed;
                    }
                }
            }
        };

        // جلب بيانات القالب
        $templateStmt = $pdo->prepare("
            SELECT 
                t.id,
                t.product_id,
                t.main_supplier_id,
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

        $mainSupplierId = isset($template['main_supplier_id']) ? (int) $template['main_supplier_id'] : null;
        if ($mainSupplierId) {
            $collectSupplier($mainSupplierId, 'template_main');
        }

        $templateRawDetailsByName = [];
        $templateRawDetailsById   = [];
        $templatePackagingDetailsById = [];
        $templatePackagingDetailsByName = [];
        $templateWorkerIdsFromDetails = [];

        if (!empty($template['details_json'])) {
            $decodedDetails = json_decode((string) $template['details_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails)) {
                if (!empty($decodedDetails['raw_materials']) && is_array($decodedDetails['raw_materials'])) {
                    foreach ($decodedDetails['raw_materials'] as $detailEntry) {
                        if (!is_array($detailEntry)) {
                            continue;
                        }
                        $supplierId = null;
                        if (isset($detailEntry['supplier_id'])) {
                            $supplierId = (int) $detailEntry['supplier_id'];
                        } elseif (isset($detailEntry['supplier'])) {
                            $supplierId = (int) $detailEntry['supplier'];
                        }
                        if ($supplierId) {
                            $collectSupplier($supplierId, 'raw_material');
                        }

                        if (isset($detailEntry['id'])) {
                            $templateRawDetailsById[(int) $detailEntry['id']] = $detailEntry;
                            $templateRawDetailsById[(string) $detailEntry['id']] = $detailEntry;
                        }

                        $detailName = '';
                        if (!empty($detailEntry['name'])) {
                            $detailName = (string) $detailEntry['name'];
                        } elseif (!empty($detailEntry['material_name'])) {
                            $detailName = (string) $detailEntry['material_name'];
                        }
                        $normalizedName = $detailName !== ''
                            ? (function_exists('mb_strtolower') ? mb_strtolower(trim($detailName), 'UTF-8') : strtolower(trim($detailName)))
                            : '';
                        if ($normalizedName !== '') {
                            $templateRawDetailsByName[$normalizedName] = $detailEntry;
                        }
                    }
                }

                $packagingGroups = [];
                foreach (['packaging', 'packaging_materials', 'packaging_items'] as $packKey) {
                    if (!empty($decodedDetails[$packKey]) && is_array($decodedDetails[$packKey])) {
                        $packagingGroups[] = $decodedDetails[$packKey];
                    }
                }
                foreach ($packagingGroups as $packGroup) {
                    foreach ($packGroup as $packDetail) {
                        if (!is_array($packDetail)) {
                            continue;
                        }
                        $supplierId = null;
                        if (isset($packDetail['supplier_id'])) {
                            $supplierId = (int) $packDetail['supplier_id'];
                        } elseif (isset($packDetail['supplier'])) {
                            $supplierId = (int) $packDetail['supplier'];
                        }
                        if ($supplierId) {
                            $collectSupplier($supplierId, 'packaging');
                        }

                        if (isset($packDetail['id'])) {
                            $templatePackagingDetailsById[(int) $packDetail['id']] = $packDetail;
                            $templatePackagingDetailsById[(string) $packDetail['id']] = $packDetail;
                        } elseif (isset($packDetail['packaging_material_id'])) {
                            $templatePackagingDetailsById[(int) $packDetail['packaging_material_id']] = $packDetail;
                            $templatePackagingDetailsById[(string) $packDetail['packaging_material_id']] = $packDetail;
                        }

                        $packName = '';
                        if (!empty($packDetail['name'])) {
                            $packName = (string) $packDetail['name'];
                        } elseif (!empty($packDetail['packaging_name'])) {
                            $packName = (string) $packDetail['packaging_name'];
                        }
                        $normalizedPackName = $packName !== ''
                            ? (function_exists('mb_strtolower') ? mb_strtolower(trim($packName), 'UTF-8') : strtolower(trim($packName)))
                            : '';
                        if ($normalizedPackName !== '') {
                            $templatePackagingDetailsByName[$normalizedPackName] = $packDetail;
                        }
                    }
                }

                if (!empty($decodedDetails['workers']) && is_array($decodedDetails['workers'])) {
                    foreach ($decodedDetails['workers'] as $workerEntry) {
                        $workerId = null;
                        if (is_array($workerEntry) && isset($workerEntry['id'])) {
                            $workerId = (int) $workerEntry['id'];
                        } elseif (is_numeric($workerEntry)) {
                            $workerId = (int) $workerEntry;
                        }
                        if ($workerId && $workerId > 0) {
                            $templateWorkerIdsFromDetails[] = $workerId;
                        }
                    }
                }

                foreach (['suppliers', 'extra_suppliers'] as $suppliersKey) {
                    if (empty($decodedDetails[$suppliersKey]) || !is_array($decodedDetails[$suppliersKey])) {
                        continue;
                    }
                    foreach ($decodedDetails[$suppliersKey] as $supplierEntry) {
                        $supplierId = null;
                        if (is_array($supplierEntry) && isset($supplierEntry['id'])) {
                            $supplierId = (int) $supplierEntry['id'];
                        } elseif (is_numeric($supplierEntry)) {
                            $supplierId = (int) $supplierEntry;
                        }
                        if ($supplierId) {
                            $collectSupplier($supplierId, 'template_extra');
                        }
                    }
                }
            }
        }

        $templateWorkerIdsFromDetails = array_values(array_unique(array_map('intval', $templateWorkerIdsFromDetails)));

        // تجهيز الجداول المرتبطة بالمواد الخام
        // البحث عن الجدول الصحيح - الأولوية لـ product_template_raw_materials ثم product_template_materials
        $templateMaterialsTable = null;
        if (batchCreationTableExists($pdo, 'product_template_raw_materials')) {
            $templateMaterialsTable = 'product_template_raw_materials';
        } elseif (batchCreationTableExists($pdo, 'product_template_materials')) {
            $templateMaterialsTable = 'product_template_materials';
        } elseif (batchCreationTableExists($pdo, 'template_materials')) {
            $templateMaterialsTable = 'template_materials';
        }

        if ($templateMaterialsTable === null) {
            throw new RuntimeException('جدول المواد الخام غير موجود');
        }

        $rawInventoryTable = batchCreationTableExists($pdo, 'raw_materials') ? 'raw_materials' : null;
        $canUpdateRawStock = $rawInventoryTable !== null && $templateMaterialsTable === 'template_materials';

        $materials = [];
        $materialsForStockDeduction = [];

        if ($templateMaterialsTable === 'product_template_raw_materials' || $templateMaterialsTable === 'product_template_materials') {
            // بناء SQL بناءً على الجدول المستخدم
            // product_template_raw_materials لا يحتوي على material_type, material_id, notes
            if ($templateMaterialsTable === 'product_template_raw_materials') {
                $materialsStmt = $pdo->prepare("
                    SELECT id, NULL as material_type, material_name, NULL as material_id, quantity_per_unit, unit, NULL as notes
                    FROM {$templateMaterialsTable}
                    WHERE template_id = ?
                ");
            } else {
                // product_template_materials يحتوي على جميع الأعمدة
                $materialsStmt = $pdo->prepare("
                    SELECT id, material_type, material_name, material_id, quantity_per_unit, unit, notes
                    FROM {$templateMaterialsTable}
                    WHERE template_id = ?
                ");
            }
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
                $detailEntry = [];
                if ($normalizedName !== '' && isset($templateRawDetailsByName[$normalizedName])) {
                    $detailEntry = $templateRawDetailsByName[$normalizedName];
                } elseif (!empty($materialRow['id']) && isset($templateRawDetailsById[(int) $materialRow['id']])) {
                    $detailEntry = $templateRawDetailsById[(int) $materialRow['id']];
                }

                $materialType = isset($detailEntry['type']) ? (string)$detailEntry['type'] : (string)($materialRow['material_type'] ?? 'other');
                if ($materialType === 'honey') {
                    $materialType = 'honey_filtered';
                } elseif ($materialType === 'honey_main') {
                    $materialType = 'honey_filtered';
                }

                $quantityPerUnit = isset($materialRow['quantity_per_unit'])
                    ? (float)$materialRow['quantity_per_unit']
                    : (isset($detailEntry['quantity']) ? (float)$detailEntry['quantity'] : 0.0);

                $supplierId = null;
                if (isset($detailEntry['supplier_id'])) {
                    $supplierId = (int) $detailEntry['supplier_id'];
                } elseif (isset($detailEntry['supplier'])) {
                    $supplierId = (int) $detailEntry['supplier'];
                } elseif (isset($materialRow['supplier_id'])) {
                    $supplierId = (int) $materialRow['supplier_id'];
                }

                $usageMatch = $rawUsageByTemplateId[(int)($materialRow['id'] ?? 0)] ?? $rawUsageByName[$normalizeName($materialName)] ?? null;
                if ($usageMatch && $supplierId === null && !empty($usageMatch['supplier_id'])) {
                    $supplierId = (int)$usageMatch['supplier_id'];
                }

                if ($supplierId) {
                    $usageName = '';
                    if (is_array($usageMatch)) {
                        $usageName = (string)($usageMatch['material_name'] ?? $usageMatch['display_name'] ?? '');
                    }
                    $supplierMaterialName = $usageName !== ''
                        ? $usageName
                        : ($materialName !== '' ? $materialName : ($detailEntry['name'] ?? $detailEntry['material_name'] ?? 'مادة خام'));
                    $collectSupplier($supplierId, 'raw_material', $supplierMaterialName);
                }

                $materialsForStockDeduction[] = [
                    'material_type'    => $materialType,
                    'material_name'    => $materialName !== '' ? $materialName : ($detailEntry['name'] ?? $detailEntry['material_name'] ?? 'مادة خام'),
                    'supplier_id'      => $supplierId,
                    'quantity_per_unit'=> $quantityPerUnit,
                    'unit'             => $detailEntry['unit'] ?? ($materialRow['unit'] ?? 'كجم'),
                    'honey_variety'    => $detailEntry['honey_variety'] ?? null,
                    'template_item_id' => (int)($materialRow['id'] ?? 0),
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
            $templateMaterialsHasSupplierColumn = batchCreationColumnExists($pdo, $templateMaterialsTable, 'supplier_id');
            $rawInventoryHasSupplierColumn = $rawInventoryTable !== null && batchCreationColumnExists($pdo, $rawInventoryTable, 'supplier_id');

            $rawNameExpression = $materialNameColumn !== null
                ? sprintf('COALESCE(rm.name, tm.%s)', $materialNameColumn)
                : 'rm.name';
            $unitExpression = $rawUnitColumn !== null ? 'rm.' . $rawUnitColumn : 'NULL';

            $selectParts = [
                'rm.id AS raw_id',
                $rawNameExpression . ' AS raw_name',
                'tm.quantity_per_unit',
                'rm.' . $rawStockColumn . ' AS available_stock',
                $unitExpression . ' AS unit',
                $templateMaterialsHasSupplierColumn ? 'tm.supplier_id AS template_supplier_id' : 'NULL AS template_supplier_id',
                $rawInventoryHasSupplierColumn ? 'rm.supplier_id AS inventory_supplier_id' : 'NULL AS inventory_supplier_id'
            ];

            $materialsSql = 'SELECT ' . implode(",\n                    ", $selectParts) . "\n"
                . "FROM {$templateMaterialsTable} tm\n"
                . "JOIN {$rawInventoryTable} rm ON rm.id = tm.{$materialIdColumn}\n"
                . "WHERE tm.template_id = ?";

            $materialsStmt = $pdo->prepare($materialsSql);
            $materialsStmt->execute([$templateId]);
            $materials = $materialsStmt->fetchAll();

            if (empty($materials)) {
                throw new RuntimeException('القالب لا يحتوي على مواد خام صالحة للإنتاج');
            }

            foreach ($materials as &$material) {
                $supplierId = null;
                if (!empty($material['template_supplier_id'])) {
                    $supplierId = (int) $material['template_supplier_id'];
                } elseif (!empty($material['inventory_supplier_id'])) {
                    $supplierId = (int) $material['inventory_supplier_id'];
                } elseif (isset($templateRawDetailsById[(int) ($material['raw_id'] ?? 0)]['supplier_id'])) {
                    $supplierId = (int) $templateRawDetailsById[(int) $material['raw_id']]['supplier_id'];
                }

                $rawName = (string) ($material['raw_name'] ?? '');
                $normalizedRawName = $normalizeName($rawName);

                if ($supplierId === null && $normalizedRawName !== '' && isset($templateRawDetailsByName[$normalizedRawName]['supplier_id'])) {
                    $supplierId = (int) $templateRawDetailsByName[$normalizedRawName]['supplier_id'];
                }

                if ($supplierId === null && $normalizedRawName !== '' && isset($rawUsageByName[$normalizedRawName])) {
                    $usageEntry = $rawUsageByName[$normalizedRawName];
                    if (!empty($usageEntry['supplier_id'])) {
                        $supplierId = (int)$usageEntry['supplier_id'];
                    }
                }

                if (!empty($templateRawDetailsById[(int) ($material['raw_id'] ?? 0)]['unit']) && ($material['unit'] === null || $material['unit'] === '')) {
                    $material['unit'] = $templateRawDetailsById[(int) $material['raw_id']]['unit'];
                }

                $material['supplier_id'] = $supplierId ?: null;
                if ($supplierId) {
                    $collectSupplier($supplierId, 'raw_material');
                }
            }
            unset($material);
        }

        if (empty($materials) && empty($materialsForStockDeduction)) {
            throw new RuntimeException('القالب لا يحتوي على مواد خام صالحة للإنتاج');
        }

        if (!empty($materialsForStockDeduction)) {
            foreach ($materialsForStockDeduction as &$stockMaterial) {
                $usageEntry = null;
                $templateItemId = isset($stockMaterial['template_item_id']) ? (int)$stockMaterial['template_item_id'] : 0;
                if ($templateItemId > 0 && isset($rawUsageByTemplateId[$templateItemId])) {
                    $usageEntry = $rawUsageByTemplateId[$templateItemId];
                }
                if ($usageEntry === null) {
                    $usageEntry = $rawUsageByName[$normalizeName($stockMaterial['material_name'] ?? '')] ?? null;
                }

                if ($usageEntry) {
                    if (empty($stockMaterial['supplier_id']) && !empty($usageEntry['supplier_id'])) {
                        $stockMaterial['supplier_id'] = (int)$usageEntry['supplier_id'];
                    }

                    if (!empty($usageEntry['honey_variety'])) {
                        $stockMaterial['honey_variety'] = trim((string)$usageEntry['honey_variety']);
                    }

                    if (!empty($usageEntry['material_type'])) {
                        $usageType = trim((string)$usageEntry['material_type']);
                        if ($usageType !== '') {
                            $lowerUsageType = function_exists('mb_strtolower') ? mb_strtolower($usageType, 'UTF-8') : strtolower($usageType);
                            if ($lowerUsageType === 'honey') {
                                $lowerUsageType = 'honey_filtered';
                            } elseif ($lowerUsageType === 'honey_main') {
                                $lowerUsageType = 'honey_filtered';
                            }
                            $stockMaterial['material_type'] = $lowerUsageType;
                        }
                    }

                    $usageQuantity = 0.0;
                    if (isset($usageEntry['quantity']) && is_numeric($usageEntry['quantity'])) {
                        $usageQuantity = (float)$usageEntry['quantity'];
                    } elseif (isset($usageEntry['quantity_used']) && is_numeric($usageEntry['quantity_used'])) {
                        $usageQuantity = (float)$usageEntry['quantity_used'];
                    } elseif (isset($usageEntry['quantity_per_unit']) && is_numeric($usageEntry['quantity_per_unit'])) {
                        $usageQuantity = (float)$usageEntry['quantity_per_unit'] * (float)$units;
                    }

                    if ($usageQuantity > 0 && $units > 0) {
                        $computedPerUnit = $usageQuantity / $units;
                        if ($computedPerUnit > 0) {
                            $stockMaterial['quantity_per_unit'] = $computedPerUnit;
                        }
                    }
                } else {
                    if (empty($stockMaterial['supplier_id'])) {
                        $stockMaterial['supplier_id'] = null;
                    }
                }
            }
            unset($stockMaterial);
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

        $packagingHasSupplierColumn = batchCreationColumnExists($pdo, $templatePackagingTable, 'supplier_id');
        $packagingInventoryHasSupplierColumn = batchCreationColumnExists($pdo, $packagingInventoryTable, 'supplier_id');

        $packNameExpression = $packagingNameColumn !== null
            ? sprintf('COALESCE(pm.name, tp.%s)', $packagingNameColumn)
            : 'pm.name';
        $packUnitExpression = $packagingUnitColumn !== null ? 'pm.' . $packagingUnitColumn : 'NULL';

        $packSelectParts = [
            'tp.id AS template_item_id',
            'pm.id AS pack_id',
            $packNameExpression . ' AS pack_name',
            'tp.quantity_per_unit',
            'pm.' . $packagingStockColumn . ' AS available_stock',
            $packUnitExpression . ' AS unit',
            $packagingHasSupplierColumn ? 'tp.supplier_id AS template_supplier_id' : 'NULL AS template_supplier_id',
            $packagingInventoryHasSupplierColumn ? 'pm.supplier_id AS inventory_supplier_id' : 'NULL AS inventory_supplier_id'
        ];

        $packagingSql = 'SELECT ' . implode(",\n                    ", $packSelectParts) . "\n"
            . "FROM {$templatePackagingTable} tp\n"
            . "LEFT JOIN {$packagingInventoryTable} pm ON pm.id = tp.{$packagingIdColumn}\n"
            . "WHERE tp.template_id = ?";

        $packagingStmt = $pdo->prepare($packagingSql);
        $packagingStmt->execute([$templateId]);
        $packaging = $packagingStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($packaging) && !empty($packagingUsage)) {
            foreach ($packagingUsage as $usagePack) {
                if (!is_array($usagePack)) {
                    continue;
                }

                $usageTemplateId = isset($usagePack['template_item_id']) ? (int)$usagePack['template_item_id'] : 0;
                $usageMaterialId = isset($usagePack['material_id']) ? (int)$usagePack['material_id'] : null;
                $usageName = trim((string)($usagePack['name'] ?? $usagePack['packaging_name'] ?? ''));
                $usageUnit = isset($usagePack['unit']) ? (string)$usagePack['unit'] : null;
                $usageSupplierId = isset($usagePack['supplier_id']) && is_numeric($usagePack['supplier_id'])
                    ? (int)$usagePack['supplier_id']
                    : null;

                $usagePerUnit = 0.0;
                if (isset($usagePack['quantity_per_unit']) && is_numeric($usagePack['quantity_per_unit'])) {
                    $usagePerUnit = (float)$usagePack['quantity_per_unit'];
                } elseif (isset($usagePack['quantity']) && is_numeric($usagePack['quantity']) && $units > 0) {
                    $computed = (float)$usagePack['quantity'] / $units;
                    if ($computed > 0) {
                        $usagePerUnit = $computed;
                    }
                }

                $packaging[] = [
                    'template_item_id' => $usageTemplateId,
                    'pack_id' => $usageMaterialId,
                    'pack_name' => $usageName,
                    'quantity_per_unit' => $usagePerUnit,
                    'available_stock' => null,
                    'unit' => $usageUnit,
                    'supplier_id' => $usageSupplierId,
                ];
            }
        }

        foreach ($packaging as &$pack) {
            $supplierId = null;
            if (!empty($pack['supplier_id'])) {
                $supplierId = (int)$pack['supplier_id'];
            }
            if (!empty($pack['template_supplier_id'])) {
                $supplierId = (int) $pack['template_supplier_id'];
            } elseif (!empty($pack['inventory_supplier_id'])) {
                $supplierId = (int) $pack['inventory_supplier_id'];
            } elseif (isset($templatePackagingDetailsById[(int) ($pack['pack_id'] ?? 0)]['supplier_id'])) {
                $supplierId = (int) $templatePackagingDetailsById[(int) $pack['pack_id']]['supplier_id'];
            }

            $packName = trim((string) ($pack['pack_name'] ?? ''));
            $normalizedPackName = $normalizeName($packName);
            $templatePackId = isset($pack['template_item_id']) ? (int)$pack['template_item_id'] : 0;
            $usagePack = null;

            if ($supplierId === null && $normalizedPackName !== '' && isset($templatePackagingDetailsByName[$normalizedPackName]['supplier_id'])) {
                $supplierId = (int) $templatePackagingDetailsByName[$normalizedPackName]['supplier_id'];
            }

            if ($supplierId === null && $templatePackId > 0 && isset($packagingUsageByTemplateId[$templatePackId])) {
                $usagePack = $packagingUsageByTemplateId[$templatePackId];
                if (!empty($usagePack['supplier_id'])) {
                    $supplierId = (int)$usagePack['supplier_id'];
                }
            }

            if ($supplierId === null && $normalizedPackName !== '' && isset($packagingUsageByName[$normalizedPackName])) {
                $usagePack = $packagingUsageByName[$normalizedPackName];
                if (!empty($usagePack['supplier_id'])) {
                    $supplierId = (int)$usagePack['supplier_id'];
                }
            }

            if ($usagePack === null && $templatePackId > 0 && isset($packagingUsageByTemplateId[$templatePackId])) {
                $usagePack = $packagingUsageByTemplateId[$templatePackId];
            }
            if ($usagePack === null && $normalizedPackName !== '' && isset($packagingUsageByName[$normalizedPackName])) {
                $usagePack = $packagingUsageByName[$normalizedPackName];
            }

            $pack['supplier_id'] = $supplierId ?: null;
            if ($supplierId) {
                $collectSupplier($supplierId, 'packaging');
            }

            $quantityPerUnit = isset($pack['quantity_per_unit']) && is_numeric($pack['quantity_per_unit'])
                ? (float)$pack['quantity_per_unit']
                : 0.0;

            if (is_array($usagePack)) {
                if (isset($usagePack['quantity_per_unit']) && is_numeric($usagePack['quantity_per_unit'])) {
                    $quantityPerUnit = (float)$usagePack['quantity_per_unit'];
                } elseif (isset($usagePack['quantity']) && is_numeric($usagePack['quantity']) && $units > 0) {
                    $computed = (float)$usagePack['quantity'] / $units;
                    if ($computed > 0) {
                        $quantityPerUnit = $computed;
                    }
                }

                if ($packName === '' && !empty($usagePack['name'])) {
                    $packName = trim((string)$usagePack['name']);
                } elseif ($packName === '' && !empty($usagePack['packaging_name'])) {
                    $packName = trim((string)$usagePack['packaging_name']);
                }

                if (empty($pack['unit']) && !empty($usagePack['unit'])) {
                    $pack['unit'] = (string)$usagePack['unit'];
                }
            }

            if ($packName === '') {
                $packName = 'أداة تعبئة';
            }

            $pack['pack_name'] = $packName;
            if ($quantityPerUnit <= 0) {
                $quantityPerUnit = 1.0;
            }
            $pack['quantity_per_unit'] = $quantityPerUnit;
            if (empty($pack['unit'])) {
                $pack['unit'] = 'قطعة';
            }
        }
        unset($pack);

        if (!empty($supplierSnapshots)) {
            $supplierIds = array_keys($supplierSnapshots);
            $resolvedSupplierNames = [];

            if (!empty($supplierIds) && batchCreationTableExists($pdo, 'suppliers')) {
                $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
                if ($placeholders !== '') {
                    $supplierStmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE id IN (' . $placeholders . ')');
                    $supplierStmt->execute($supplierIds);
                    foreach ($supplierStmt->fetchAll(PDO::FETCH_ASSOC) as $supplierRow) {
                        $supplierId = isset($supplierRow['id']) ? (int) $supplierRow['id'] : 0;
                        if ($supplierId <= 0) {
                            continue;
                        }
                        $name = trim((string) ($supplierRow['name'] ?? ''));
                        if ($name !== '') {
                            $resolvedSupplierNames[$supplierId] = $name;
                        }
                    }
                }
            }

            foreach ($supplierSnapshots as $supplierId => &$snapshot) {
                $currentName = isset($snapshot['name']) ? trim((string) $snapshot['name']) : '';
                if ($currentName === '') {
                    $fallback = $resolvedSupplierNames[$supplierId] ?? null;
                    if ($fallback === null || trim($fallback) === '') {
                        $fallback = 'مورد #' . $supplierId;
                    }
                    $snapshot['name'] = $fallback;
                } else {
                    $snapshot['name'] = $currentName;
                }
            }
            unset($snapshot);
        }

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
            $packSupplierId = !empty($pack['supplier_id']) ? (int)$pack['supplier_id'] : 0;

            if ($requiredQty > $available) {
                if ($packSupplierId > 0) {
                    // يتم الاعتماد على المورد الخارجي لتوفير أداة التعبئة، لذا يتم تجاوز التحقق الصارم للمخزون.
                    continue;
                }
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
                        'raw_material_id' => isset($stockMaterial['raw_id']) ? (int)$stockMaterial['raw_id'] : (isset($stockMaterial['material_id']) ? (int)$stockMaterial['material_id'] : null),
                        'quantity_used'   => $qtyUsed,
                        'material_name'   => $batchRawHasNameColumn ? (string)($stockMaterial['material_name'] ?? '') : null,
                        'unit'            => $batchRawHasUnitColumn ? ($stockMaterial['unit'] ?? null) : null,
                        'supplier_id'     => $stockMaterial['supplier_id'] ?? null,
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

        $attendanceWorkersCount = is_array($workers) ? count($workers) : 0;

        if ($attendanceWorkersCount === 0) {
            throw new RuntimeException('لا يمكن إنشاء التشغيله بدون عمال حاضرين اليوم. يرجى تسجيل حضور العمال أولاً.');
        }

        if (!empty($templateWorkerIdsFromDetails)) {
            $existingWorkerIds = array_map(static function (array $worker): int {
                return (int) ($worker['id'] ?? 0);
            }, $workers);
            $missingWorkerIds = array_values(array_diff($templateWorkerIdsFromDetails, $existingWorkerIds));

            if (!empty($missingWorkerIds)) {
                $additionalWorkers = [];

                if (batchCreationTableExists($pdo, 'employees')) {
                    $employeeNameColumn = batchCreationColumnExists($pdo, 'employees', 'name')
                        ? 'name'
                        : (batchCreationColumnExists($pdo, 'employees', 'full_name') ? 'full_name' : null);
                    if ($employeeNameColumn !== null) {
                        $placeholders = implode(',', array_fill(0, count($missingWorkerIds), '?'));
                        $workersStmt = $pdo->prepare("SELECT id, {$employeeNameColumn} AS name FROM employees WHERE id IN ({$placeholders})");
                        $workersStmt->execute($missingWorkerIds);
                        $additionalWorkers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }

                if (empty($additionalWorkers) && batchCreationTableExists($pdo, 'users')) {
                    $userNameColumn = batchCreationColumnExists($pdo, 'users', 'full_name')
                        ? 'full_name'
                        : (batchCreationColumnExists($pdo, 'users', 'name') ? 'name' : (batchCreationColumnExists($pdo, 'users', 'username') ? 'username' : 'id'));
                    $placeholders = implode(',', array_fill(0, count($missingWorkerIds), '?'));
                    $workersStmt = $pdo->prepare("SELECT id, {$userNameColumn} AS name FROM users WHERE id IN ({$placeholders})");
                    $workersStmt->execute($missingWorkerIds);
                    $additionalWorkers = array_merge($additionalWorkers, $workersStmt->fetchAll(PDO::FETCH_ASSOC));
                }

                $resolvedIds = [];
                foreach ($additionalWorkers as $workerRow) {
                    $workerId = (int) ($workerRow['id'] ?? 0);
                    if ($workerId > 0) {
                        $workers[] = [
                            'id' => $workerId,
                            'name' => (string) ($workerRow['name'] ?? ('الموظف #' . $workerId)),
                        ];
                        $resolvedIds[] = $workerId;
                    }
                }

                $unresolved = array_diff($missingWorkerIds, $resolvedIds);
                foreach ($unresolved as $workerId) {
                    $workerId = (int) $workerId;
                    if ($workerId > 0) {
                        $workers[] = [
                            'id' => $workerId,
                            'name' => 'الموظف #' . $workerId,
                        ];
                    }
                }
            }
        }

        if (!empty($workers)) {
            $workers = array_values(array_reduce(
                $workers,
                static function (array $carry, array $worker): array {
                    $workerId = (int) ($worker['id'] ?? 0);
                    if ($workerId > 0) {
                        $name = isset($worker['name']) ? trim((string) $worker['name']) : '';
                        if ($name === '') {
                            $name = 'الموظف #' . $workerId;
                        }
                        $carry[$workerId] = [
                            'id' => $workerId,
                            'name' => $name,
                        ];
                    }
                    return $carry;
                },
                []
            ));
        }

        $batchWorkersExists = batchCreationTableExists($pdo, 'batch_workers');
        $batchWorkersHasNameColumn = $batchWorkersExists && batchCreationColumnExists($pdo, 'batch_workers', 'worker_name');

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
                if ($batchRawHasSupplierColumn) {
                    $params[] = $material['supplier_id'] ?? null;
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
                if ($batchRawHasSupplierColumn) {
                    $params[] = $pendingRow['supplier_id'] ?? null;
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
                if ($batchPackagingHasSupplierColumn) {
                    $params[] = $pack['supplier_id'] ?? null;
                }
                $batchPackagingInsertStatement->execute($params);
            }
        }

        if ($batchSuppliersExists && $batchSuppliersInsertStatement instanceof PDOStatement && !empty($supplierSnapshots)) {
            foreach ($supplierSnapshots as $supplierId => $snapshot) {
                $roles = isset($snapshot['roles']) && is_array($snapshot['roles'])
                    ? array_keys($snapshot['roles'])
                    : [];
                sort($roles);
                $roleString = !empty($roles) ? implode(',', $roles) : null;
                $supplierName = isset($snapshot['name']) ? trim((string) $snapshot['name']) : '';
                if ($supplierName === '') {
                    $supplierName = 'مورد #' . $supplierId;
                }

                $params = [$batchId, (int) $supplierId];
                if ($batchSuppliersHasNameColumn) {
                    $params[] = $supplierName;
                }
                $params[] = $roleString !== null && $roleString !== '' ? $roleString : null;

                $batchSuppliersInsertStatement->execute($params);
            }
        }

        if (!empty($workers)) {
            if (!$batchWorkersExists) {
                throw new RuntimeException('جدول العمال المرتبطين بالتشغيلة غير موجود');
            }

            $workerInsertColumns = ['batch_id', 'employee_id'];
            $workerInsertPlaceholders = ['?', '?'];
            if ($batchWorkersHasNameColumn) {
                $workerInsertColumns[] = 'worker_name';
                $workerInsertPlaceholders[] = '?';
            }

            $insertWorker = $pdo->prepare(
                'INSERT INTO batch_workers (' . implode(', ', $workerInsertColumns) . ') VALUES (' . implode(', ', $workerInsertPlaceholders) . ')'
            );

            foreach ($workers as $worker) {
                $params = [$batchId, (int) $worker['id']];
                if ($batchWorkersHasNameColumn) {
                    $workerName = isset($worker['name']) ? trim((string) $worker['name']) : '';
                    if ($workerName === '') {
                        $workerName = 'الموظف #' . (int) $worker['id'];
                    }
                    $params[] = $workerName;
                }
                $insertWorker->execute($params);
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

        if ($productId > 0) {
            try {
                $productQuery = $pdo->prepare(
                    "SELECT quantity, warehouse_id, product_type FROM products WHERE id = ? LIMIT 1"
                );
                $productQuery->execute([$productId]);
                $productRow = $productQuery->fetch(PDO::FETCH_ASSOC);

                if ($productRow) {
                    $quantityBefore = (float)($productRow['quantity'] ?? 0);
                    $quantityAfter = $quantityBefore + (float)$units;
                    $assignedWarehouseId = isset($productRow['warehouse_id']) ? (int)$productRow['warehouse_id'] : 0;

                    if ($assignedWarehouseId <= 0 && batchCreationTableExists($pdo, 'warehouses')) {
                        $primaryWarehouseStmt = $pdo->query("
                            SELECT id
                            FROM warehouses
                            WHERE warehouse_type = 'main'
                            ORDER BY status = 'active' DESC, id ASC
                            LIMIT 1
                        ");
                        $primaryWarehouseRow = $primaryWarehouseStmt ? $primaryWarehouseStmt->fetch(PDO::FETCH_ASSOC) : null;
                        if (!empty($primaryWarehouseRow['id'])) {
                            $assignedWarehouseId = (int)$primaryWarehouseRow['id'];
                        } else {
                            $createWarehouseStmt = $pdo->prepare("
                                INSERT INTO warehouses (name, location, description, warehouse_type, status)
                                VALUES (?, ?, ?, 'main', 'active')
                            ");
                            $createWarehouseStmt->execute([
                                'المخزن الرئيسي',
                                'الموقع الرئيسي للشركة',
                                'تم إنشاؤه تلقائياً أثناء حفظ إنتاج جديد'
                            ]);
                            $assignedWarehouseId = (int)$pdo->lastInsertId();
                        }
                    }

                    $updateSql = "UPDATE products SET quantity = ?, updated_at = NOW()";
                    $updateParams = [$quantityAfter];
                    if ($assignedWarehouseId > 0) {
                        $updateSql .= ", warehouse_id = ?";
                        $updateParams[] = $assignedWarehouseId;
                    }
                    $updateSql .= " WHERE id = ?";
                    $updateParams[] = $productId;

                    $updateProductStmt = $pdo->prepare($updateSql);
                    $updateProductStmt->execute($updateParams);

                    if (batchCreationTableExists($pdo, 'inventory_movements')) {
                        if (!function_exists('getCurrentUser')) {
                            $authFile = __DIR__ . '/auth.php';
                            if (file_exists($authFile)) {
                                require_once $authFile;
                            }
                        }

                        $movementCreatedBy = null;
                        if (function_exists('getCurrentUser')) {
                            $currentUser = getCurrentUser();
                            if (!empty($currentUser['id'])) {
                                $movementCreatedBy = (int)$currentUser['id'];
                            }
                        }

                        if ($movementCreatedBy) {
                            $movementStmt = $pdo->prepare("
                                INSERT INTO inventory_movements
                                    (product_id, warehouse_id, type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by)
                                VALUES (?, ?, 'in', ?, ?, ?, 'production', ?, ?, ?)
                            ");
                            $movementStmt->execute([
                                $productId,
                                $assignedWarehouseId > 0 ? $assignedWarehouseId : null,
                                (float)$units,
                                $quantityBefore,
                                $quantityAfter,
                                $batchId,
                                'إضافة تلقائية من عملية الإنتاج',
                                $movementCreatedBy
                            ]);
                        }
                    }
                }
            } catch (Throwable $inventorySyncError) {
                error_log('batchCreationCreate inventory sync error: ' . $inventorySyncError->getMessage());
            }
        }

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
                        'name' => trim((string) $worker['name']),
                    ];
                },
                $workers
            ),
            'stock_deducted'  => true,
        ];
    } catch (RuntimeException $runtimeException) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => $runtimeException->getMessage(),
            'stock_deducted' => false,
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Batch creation error: ' . $throwable->getMessage());

        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع أثناء إنشاء التشغيله',
            'stock_deducted' => false,
        ];
    }
}

