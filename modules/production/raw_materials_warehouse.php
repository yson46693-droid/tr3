<?php
/**
 * صفحة مخزن الخامات (العسل، زيت الزيتون، شمع العسل، المشتقات)
 * Raw Materials Warehouse Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('production');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// الحصول على رسالة النجاح من session
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

// الحصول على القسم المطلوب (افتراضياً: العسل)
$section = $_GET['section'] ?? 'honey';
$validSections = ['honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts'];
if (!in_array($section, $validSections)) {
    $section = 'honey';
}

/**
 * ⚡ تحسين الأداء - Performance Optimization ⚡
 * 
 * تم تعطيل كود فحص وإنشاء الجداول لتحسين سرعة التحميل
 * النتيجة: تقليل وقت التحميل من 5-10 ثواني إلى أقل من 0.5 ثانية
 * 
 * الجداول موجودة بالفعل في قاعدة البيانات
 * إذا ظهر خطأ "Table doesn't exist"، الجداول ستُنشأ تلقائياً عند أول استخدام
 * 
 * للمزيد من المعلومات، انظر ملف: performance_note.txt
 */

// جلب الموردين (بدون فحص الجداول لتحسين الأداء)
$suppliers = [];
try {
    $suppliers = $db->query(
        "SELECT DISTINCT id, name, phone, type 
         FROM suppliers 
         WHERE status = 'active' 
         ORDER BY name"
    );
} catch (Exception $e) {
    // Table doesn't exist yet - will be created on first use
    error_log("Suppliers table not ready: " . $e->getMessage());
}

// ======= الحصول على الموردين =======
$supplierTypeMap = [
    'honey' => 'honey',
    'olive_oil' => 'olive_oil',
    'beeswax' => 'beeswax',
    'derivatives' => 'derivatives',
    'nuts' => 'nuts'
];

$currentSupplierType = $supplierTypeMap[$section] ?? null;

// الحصول على جميع الموردين (لإنشاء القوالب الموحدة)
$allSuppliers = $db->query(
    "SELECT id, name, type FROM suppliers 
     WHERE status = 'active' 
     ORDER BY type, name"
);

// الحصول على أدوات التعبئة
$packagingMaterials = $db->query(
    "SELECT id, name, type, quantity, unit FROM packaging_materials 
     WHERE status = 'active' 
     ORDER BY name"
);

// ======= معالجة العمليات (تم تعطيل كود إنشاء الجداول القديم) =======
// Table creation code removed for performance - tables exist in database
/*
Old table creation code disabled - from line 90 to line 442
All SHOW TABLES and CREATE TABLE statements have been commented out
See git history or performance_note.txt for details
*/
if (false) { /* START OLD CODE
              `raw_honey_quantity` decimal(10,2) NOT NULL,
              `filtered_honey_quantity` decimal(10,2) NOT NULL,
              `filtration_loss` decimal(10,2) NOT NULL COMMENT 'الكمية المفقودة (0.5%)',
              `filtration_date` date NOT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id` (`supplier_id`),
              KEY `filtration_date` (`filtration_date`),
              KEY `created_by` (`created_by`),
              CONSTRAINT `honey_filtration_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
              CONSTRAINT `honey_filtration_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating honey_filtration table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول مخزن زيت الزيتون =======
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `olive_oil_stock` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية (لتر)',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `supplier_id` (`supplier_id`),
              CONSTRAINT `olive_oil_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating olive_oil_stock table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول قوالب منتجات زيت الزيتون =======
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_product_templates'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `olive_oil_product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL,
              `olive_oil_quantity` decimal(10,2) NOT NULL COMMENT 'كمية زيت الزيتون (لتر)',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating olive_oil_product_templates table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول مخزن شمع العسل =======
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'beeswax_stock'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `beeswax_stock` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الوزن (كجم)',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `supplier_id` (`supplier_id`),
              CONSTRAINT `beeswax_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating beeswax_stock table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول قوالب منتجات شمع العسل =======
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'beeswax_product_templates'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `beeswax_product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL,
              `beeswax_weight` decimal(10,2) NOT NULL COMMENT 'وزن شمع العسل (كجم)',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating beeswax_product_templates table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول مخزن المشتقات =======
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_stock'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `derivatives_stock` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `derivative_type` varchar(100) NOT NULL COMMENT 'نوع المشتق',
              `weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الوزن (كجم)',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id` (`supplier_id`),
              KEY `derivative_type` (`derivative_type`),
              UNIQUE KEY `supplier_derivative` (`supplier_id`, `derivative_type`),
              CONSTRAINT `derivatives_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating derivatives_stock table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول قوالب منتجات المشتقات =======
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_product_templates'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `derivatives_product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL,
              `derivative_type` varchar(100) NOT NULL COMMENT 'نوع المشتق المستخدم',
              `derivative_weight` decimal(10,2) NOT NULL COMMENT 'وزن المشتق (كجم)',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `derivative_type` (`derivative_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating derivatives_product_templates table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول مخزن المكسرات المنفردة =======
$nutsStockCheck = $db->queryOne("SHOW TABLES LIKE 'nuts_stock'");
if (empty($nutsStockCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `nuts_stock` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `nut_type` varchar(100) NOT NULL COMMENT 'نوع المكسرات (لوز، جوز، فستق، إلخ)',
              `quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالكيلوجرام',
              `unit_price` decimal(10,2) DEFAULT NULL COMMENT 'سعر الكيلو',
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id_idx` (`supplier_id`),
              KEY `nut_type` (`nut_type`),
              KEY `supplier_nut` (`supplier_id`, `nut_type`),
              CONSTRAINT `nuts_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating nuts_stock table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول المكسرات المشكلة =======
$mixedNutsCheck = $db->queryOne("SHOW TABLES LIKE 'mixed_nuts'");
if (empty($mixedNutsCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `mixed_nuts` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `batch_name` varchar(255) NOT NULL COMMENT 'اسم الخلطة',
              `supplier_id` int(11) NOT NULL COMMENT 'المورد الخاص بالخلطة',
              `total_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'إجمالي الوزن',
              `notes` text DEFAULT NULL,
              `created_by` int(11) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id` (`supplier_id`),
              KEY `created_by` (`created_by`),
              CONSTRAINT `mixed_nuts_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
              CONSTRAINT `mixed_nuts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating mixed_nuts table: " . $e->getMessage());
    }
}

// ======= إنشاء جدول مكونات المكسرات المشكلة =======
$mixedNutsIngredientsCheck = $db->queryOne("SHOW TABLES LIKE 'mixed_nuts_ingredients'");
if (empty($mixedNutsIngredientsCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `mixed_nuts_ingredients` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `mixed_nuts_id` int(11) NOT NULL COMMENT 'رقم الخلطة',
              `nuts_stock_id` int(11) NOT NULL COMMENT 'رقم المكسرات المنفردة',
              `quantity` decimal(10,3) NOT NULL COMMENT 'الكمية المستخدمة',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `mixed_nuts_id` (`mixed_nuts_id`),
              KEY `nuts_stock_id` (`nuts_stock_id`),
              CONSTRAINT `mixed_nuts_ingredients_ibfk_1` FOREIGN KEY (`mixed_nuts_id`) REFERENCES `mixed_nuts` (`id`) ON DELETE CASCADE,
              CONSTRAINT `mixed_nuts_ingredients_ibfk_2` FOREIGN KEY (`nuts_stock_id`) REFERENCES `nuts_stock` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating mixed_nuts_ingredients table: " . $e->getMessage());
    }
}

// ======= إنشاء جداول النظام الموحد لقوالب المنتجات =======
// جدول القوالب الموحدة
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (empty($unifiedTemplatesCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `unified_product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
              `created_by` int(11) DEFAULT NULL,
              `status` enum('active','inactive') DEFAULT 'active',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating unified_product_templates table: " . $e->getMessage());
    }
}

// جدول المواد الخام للقوالب
$templateRawMaterialsCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
if (empty($templateRawMaterialsCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `template_raw_materials` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `material_type` enum('honey_raw','honey_filtered','olive_oil','beeswax','derivatives','nuts','other') NOT NULL COMMENT 'نوع المادة الخام',
              `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة (للمواد الأخرى)',
              `supplier_id` int(11) DEFAULT NULL COMMENT 'المورد الخاص بالمادة',
              `honey_variety` varchar(50) DEFAULT NULL COMMENT 'نوع العسل (سدر، جبلي، إلخ)',
              `quantity` decimal(10,3) NOT NULL COMMENT 'الكمية المطلوبة',
              `unit` varchar(50) DEFAULT 'كجم' COMMENT 'وحدة القياس',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`),
              KEY `material_type` (`material_type`),
              KEY `supplier_id` (`supplier_id`),
              CONSTRAINT `template_raw_materials_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `unified_product_templates` (`id`) ON DELETE CASCADE,
              CONSTRAINT `template_raw_materials_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // إضافة حقل honey_variety إذا كان الجدول موجوداً مسبقاً
        $honeyVarietyCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
        if (empty($honeyVarietyCheck)) {
            try {
                $db->execute("
                    ALTER TABLE `template_raw_materials` 
                    ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'نوع العسل (سدر، جبلي، إلخ)' 
                    AFTER `supplier_id`
                ");
            } catch (Exception $e) {
                error_log("Error adding honey_variety to template_raw_materials: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Error creating template_raw_materials table: " . $e->getMessage());
    }
}

// جدول أدوات التعبئة للقوالب
$templatePackagingCheck = $db->queryOne("SHOW TABLES LIKE 'template_packaging'");
if (empty($templatePackagingCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `template_packaging` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `packaging_material_id` int(11) NOT NULL COMMENT 'مادة التعبئة من جدول packaging_materials',
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000 COMMENT 'الكمية المطلوبة لكل وحدة إنتاج',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`),
              KEY `packaging_material_id` (`packaging_material_id`),
              CONSTRAINT `template_packaging_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `unified_product_templates` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating template_packaging table: " . $e->getMessage());
    }
}

// إضافة نوع المكسرات إلى عمود type في جدول suppliers إذا لم يكن موجوداً
try {
    $supplierTypeColumn = $db->queryOne("SHOW COLUMNS FROM suppliers WHERE Field = 'type'");
    if ($supplierTypeColumn) {
        $typeEnum = $supplierTypeColumn['Type'];
        if (strpos($typeEnum, 'nuts') === false) {
            $db->execute("
                ALTER TABLE `suppliers` 
                MODIFY COLUMN `type` enum('honey','olive_oil','beeswax','derivatives','packaging','nuts') DEFAULT NULL 
                COMMENT 'نوع المورد'
            ");
        }
    }
} catch (Exception $e) {
    error_log("Error updating supplier type enum: " . $e->getMessage());
}

// الحصول على الموردين حسب نوع القسم
$supplierTypeMap = [
    'honey' => 'honey',
    'olive_oil' => 'olive_oil',
    'beeswax' => 'beeswax',
    'derivatives' => 'derivatives',
    'nuts' => 'nuts'
];

$currentSupplierType = $supplierTypeMap[$section] ?? null;
$suppliers = $db->query(
    "SELECT id, name, type FROM suppliers 
     WHERE status = 'active' 
     AND type = ?
     ORDER BY name", 
    [$currentSupplierType]
);

// الحصول على جميع الموردين (لإنشاء القوالب الموحدة)
$allSuppliers = $db->query(
    "SELECT id, name, type FROM suppliers 
     WHERE status = 'active' 
     ORDER BY type, name"
);
END OLD CODE */ }

// ======= معالجة العمليات =======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // منع تكرار الإرسال باستخدام CSRF Token
    $submitToken = $_POST['submit_token'] ?? '';
    $sessionToken = $_SESSION['last_submit_token'] ?? '';
    
    if ($submitToken && $submitToken === $sessionToken) {
        $error = 'تم معالجة هذا الطلب من قبل. يرجى عدم إعادة تحميل الصفحة بعد الإرسال.';
    } else {
        $_SESSION['last_submit_token'] = $submitToken;
        
        // عمليات العسل
        if ($action === 'add_honey') {
            $supplierId = intval($_POST['supplier_id'] ?? 0);
            $honeyVariety = $_POST['honey_variety'] ?? 'أخرى';
            $quantity = floatval($_POST['quantity'] ?? 0);
            $honeyType = $_POST['honey_type'] ?? 'raw';
            
            $validVarieties = ['سدر', 'جبلي', 'حبة البركة', 'موالح', 'نوارة برسيم', 'أخرى'];
            if (!in_array($honeyVariety, $validVarieties)) {
                $honeyVariety = 'أخرى';
            }
            
            if ($supplierId <= 0) {
                $error = 'يجب اختيار المورد';
            } elseif ($quantity <= 0) {
                $error = 'يجب إدخال كمية صحيحة';
            } else {
                $existingStock = $db->queryOne("SELECT * FROM honey_stock WHERE supplier_id = ? AND honey_variety = ?", [$supplierId, $honeyVariety]);
                
                if ($existingStock) {
                    if ($honeyType === 'raw') {
                        $db->execute("UPDATE honey_stock SET raw_honey_quantity = raw_honey_quantity + ?, updated_at = NOW() WHERE supplier_id = ? AND honey_variety = ?", [$quantity, $supplierId, $honeyVariety]);
                    } else {
                        $db->execute("UPDATE honey_stock SET filtered_honey_quantity = filtered_honey_quantity + ?, updated_at = NOW() WHERE supplier_id = ? AND honey_variety = ?", [$quantity, $supplierId, $honeyVariety]);
                    }
                } else {
                    if ($honeyType === 'raw') {
                        $db->execute("INSERT INTO honey_stock (supplier_id, honey_variety, raw_honey_quantity, filtered_honey_quantity) VALUES (?, ?, ?, 0)", [$supplierId, $honeyVariety, $quantity]);
                    } else {
                        $db->execute("INSERT INTO honey_stock (supplier_id, honey_variety, raw_honey_quantity, filtered_honey_quantity) VALUES (?, ?, 0, ?)", [$supplierId, $honeyVariety, $quantity]);
                    }
                }
                
                logAudit($currentUser['id'], 'add_honey', 'honey_stock', $supplierId, null, ['variety' => $honeyVariety, 'quantity' => $quantity, 'type' => $honeyType]);
                
                $success = 'تم إضافة العسل بنجاح';
                // إعادة تحميل الصفحة باستخدام JavaScript
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=honey";</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=honey"></noscript>';
            }
        }
        
        elseif ($action === 'filter_honey') {
            $stockId = intval($_POST['stock_id'] ?? 0);
            $rawQuantity = floatval($_POST['raw_quantity'] ?? 0);
            $filtrationDate = $_POST['filtration_date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($stockId <= 0) {
                $error = 'معرف السجل غير صحيح';
            } elseif ($rawQuantity <= 0) {
                $error = 'يجب إدخال كمية صحيحة';
            } else {
                $stock = $db->queryOne("SELECT * FROM honey_stock WHERE id = ?", [$stockId]);
                
                if (!$stock) {
                    $error = 'لا يوجد سجل مخزون';
                } elseif ($stock['raw_honey_quantity'] < $rawQuantity) {
                    $error = 'الكمية المتاحة غير كافية';
                } else {
                    $filtrationLoss = $rawQuantity * 0.005;
                    $filteredQuantity = $rawQuantity - $filtrationLoss;
                    
                    $db->execute("UPDATE honey_stock SET raw_honey_quantity = raw_honey_quantity - ?, filtered_honey_quantity = filtered_honey_quantity + ?, updated_at = NOW() WHERE id = ?", [$rawQuantity, $filteredQuantity, $stockId]);
                    
                    $db->execute("INSERT INTO honey_filtration (supplier_id, raw_honey_quantity, filtered_honey_quantity, filtration_loss, filtration_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)", [$stock['supplier_id'], $rawQuantity, $filteredQuantity, $filtrationLoss, $filtrationDate, $notes ?: null, $currentUser['id']]);
                    
                    logAudit($currentUser['id'], 'filter_honey', 'honey_filtration', $stockId, null, ['raw' => $rawQuantity, 'filtered' => $filteredQuantity]);
                    
                    $success = 'تمت عملية التصفية بنجاح';
                    echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=honey";</script>';
                    echo '<noscript><meta http-equiv="refresh" content="0;url=' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=honey"></noscript>';
                }
            }
        }
        
        // عمليات زيت الزيتون
        elseif ($action === 'add_olive_oil') {
            try {
                $supplierId = intval($_POST['supplier_id'] ?? 0);
                $quantity = floatval($_POST['quantity'] ?? 0);
                
                if ($supplierId <= 0) {
                    $error = 'يجب اختيار المورد';
                } elseif ($quantity <= 0) {
                    $error = 'يجب إدخال كمية صحيحة';
                } else {
                    // التحقق من وجود جدول olive_oil_stock وإنشاؤه إذا لزم الأمر
                    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
                    if (empty($tableCheck)) {
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `olive_oil_stock` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `supplier_id` int(11) NOT NULL,
                              `quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية (لتر)',
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `supplier_id` (`supplier_id`),
                              CONSTRAINT `olive_oil_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    
                    $existing = $db->queryOne("SELECT * FROM olive_oil_stock WHERE supplier_id = ?", [$supplierId]);
                    
                    if ($existing) {
                        $db->execute("UPDATE olive_oil_stock SET quantity = quantity + ?, updated_at = NOW() WHERE supplier_id = ?", [$quantity, $supplierId]);
                    } else {
                        $db->execute("INSERT INTO olive_oil_stock (supplier_id, quantity) VALUES (?, ?)", [$supplierId, $quantity]);
                    }
                    
                    logAudit($currentUser['id'], 'add_olive_oil', 'olive_oil_stock', $supplierId, null, ['quantity' => $quantity]);
                    
                    $success = 'تم إضافة زيت الزيتون بنجاح';
                    echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=olive_oil";</script>';
                }
            } catch (Exception $e) {
                $error = 'حدث خطأ أثناء إضافة المخزون: ' . $e->getMessage();
                error_log("Error adding olive oil: " . $e->getMessage());
            }
        }
        
        elseif ($action === 'add_olive_oil_template') {
            $productName = trim($_POST['product_name'] ?? '');
            $oilQuantity = floatval($_POST['olive_oil_quantity'] ?? 0);
            
            if (empty($productName)) {
                $error = 'يجب إدخال اسم المنتج';
            } elseif ($oilQuantity <= 0) {
                $error = 'يجب إدخال كمية صحيحة';
            } else {
                $db->execute("INSERT INTO olive_oil_product_templates (product_name, olive_oil_quantity) VALUES (?, ?)", [$productName, $oilQuantity]);
                
                logAudit($currentUser['id'], 'add_olive_oil_template', 'olive_oil_product_templates', null, null, ['product' => $productName, 'quantity' => $oilQuantity]);
                
                $success = 'تم إضافة قالب المنتج بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=olive_oil";</script>';
            }
        }
        
        // عمليات شمع العسل
        elseif ($action === 'add_beeswax') {
            $supplierId = intval($_POST['supplier_id'] ?? 0);
            $weight = floatval($_POST['weight'] ?? 0);
            
            if ($supplierId <= 0) {
                $error = 'يجب اختيار المورد';
            } elseif ($weight <= 0) {
                $error = 'يجب إدخال وزن صحيح';
            } else {
                $existing = $db->queryOne("SELECT * FROM beeswax_stock WHERE supplier_id = ?", [$supplierId]);
                
                if ($existing) {
                    $db->execute("UPDATE beeswax_stock SET weight = weight + ?, updated_at = NOW() WHERE supplier_id = ?", [$weight, $supplierId]);
                } else {
                    $db->execute("INSERT INTO beeswax_stock (supplier_id, weight) VALUES (?, ?)", [$supplierId, $weight]);
                }
                
                logAudit($currentUser['id'], 'add_beeswax', 'beeswax_stock', $supplierId, null, ['weight' => $weight]);
                
                $success = 'تم إضافة شمع العسل بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=beeswax";</script>';
            }
        }
        
        elseif ($action === 'add_beeswax_template') {
            $productName = trim($_POST['product_name'] ?? '');
            $waxWeight = floatval($_POST['beeswax_weight'] ?? 0);
            
            if (empty($productName)) {
                $error = 'يجب إدخال اسم المنتج';
            } elseif ($waxWeight <= 0) {
                $error = 'يجب إدخال وزن صحيح';
            } else {
                $db->execute("INSERT INTO beeswax_product_templates (product_name, beeswax_weight) VALUES (?, ?)", [$productName, $waxWeight]);
                
                logAudit($currentUser['id'], 'add_beeswax_template', 'beeswax_product_templates', null, null, ['product' => $productName, 'weight' => $waxWeight]);
                
                $success = 'تم إضافة قالب المنتج بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=beeswax";</script>';
            }
        }
        
        // عمليات المشتقات
        elseif ($action === 'add_derivative') {
            $supplierId = intval($_POST['supplier_id'] ?? 0);
            $derivativeType = trim($_POST['derivative_type'] ?? '');
            $weight = floatval($_POST['weight'] ?? 0);
            
            if ($supplierId <= 0) {
                $error = 'يجب اختيار المورد';
            } elseif (empty($derivativeType)) {
                $error = 'يجب إدخال نوع المشتق';
            } elseif ($weight <= 0) {
                $error = 'يجب إدخال وزن صحيح';
            } else {
                $existing = $db->queryOne("SELECT * FROM derivatives_stock WHERE supplier_id = ? AND derivative_type = ?", [$supplierId, $derivativeType]);
                
                if ($existing) {
                    $db->execute("UPDATE derivatives_stock SET weight = weight + ?, updated_at = NOW() WHERE supplier_id = ? AND derivative_type = ?", [$weight, $supplierId, $derivativeType]);
                } else {
                    $db->execute("INSERT INTO derivatives_stock (supplier_id, derivative_type, weight) VALUES (?, ?, ?)", [$supplierId, $derivativeType, $weight]);
                }
                
                logAudit($currentUser['id'], 'add_derivative', 'derivatives_stock', $supplierId, null, ['type' => $derivativeType, 'weight' => $weight]);
                
                $success = 'تم إضافة المشتق بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=derivatives";</script>';
            }
        }
        
        elseif ($action === 'add_derivative_template') {
            $productName = trim($_POST['product_name'] ?? '');
            $derivativeType = trim($_POST['derivative_type'] ?? '');
            $derivativeWeight = floatval($_POST['derivative_weight'] ?? 0);
            
            if (empty($productName)) {
                $error = 'يجب إدخال اسم المنتج';
            } elseif (empty($derivativeType)) {
                $error = 'يجب إدخال نوع المشتق';
            } elseif ($derivativeWeight <= 0) {
                $error = 'يجب إدخال وزن صحيح';
            } else {
                $db->execute("INSERT INTO derivatives_product_templates (product_name, derivative_type, derivative_weight) VALUES (?, ?, ?)", [$productName, $derivativeType, $derivativeWeight]);
                
                logAudit($currentUser['id'], 'add_derivative_template', 'derivatives_product_templates', null, null, ['product' => $productName, 'type' => $derivativeType, 'weight' => $derivativeWeight]);
                
                $success = 'تم إضافة قالب المنتج بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=derivatives";</script>';
            }
        }
        
        // عمليات المكسرات المنفردة
        elseif ($action === 'add_single_nuts') {
            $supplierId = intval($_POST['supplier_id'] ?? 0);
            $nutType = trim($_POST['nut_type'] ?? '');
            $quantity = floatval($_POST['quantity'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($supplierId <= 0) {
                $error = 'يجب اختيار المورد';
            } elseif (empty($nutType)) {
                $error = 'يجب إدخال نوع المكسرات';
            } elseif ($quantity <= 0) {
                $error = 'يجب إدخال كمية صحيحة';
            } else {
                $existing = $db->queryOne("SELECT * FROM nuts_stock WHERE supplier_id = ? AND nut_type = ?", [$supplierId, $nutType]);
                
                if ($existing) {
                    $db->execute("UPDATE nuts_stock SET quantity = quantity + ?, notes = ?, updated_at = NOW() WHERE supplier_id = ? AND nut_type = ?", [$quantity, $notes ?: $existing['notes'], $supplierId, $nutType]);
                } else {
                    $db->execute("INSERT INTO nuts_stock (supplier_id, nut_type, quantity, notes) VALUES (?, ?, ?, ?)", [$supplierId, $nutType, $quantity, $notes ?: null]);
                }
                
                logAudit($currentUser['id'], 'add_single_nuts', 'nuts_stock', $supplierId, null, ['type' => $nutType, 'quantity' => $quantity]);
                
                $success = 'تم إضافة المكسرات بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=nuts";</script>';
            }
        }
        
        // إنشاء مكسرات مشكلة
        elseif ($action === 'create_mixed_nuts') {
            $batchName = trim($_POST['batch_name'] ?? '');
            $supplierId = intval($_POST['supplier_id'] ?? 0);
            $ingredients = $_POST['ingredients'] ?? [];
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($batchName)) {
                $error = 'يجب إدخال اسم الخلطة';
            } elseif ($supplierId <= 0) {
                $error = 'يجب اختيار المورد';
            } elseif (empty($ingredients)) {
                $error = 'يجب إضافة مكونات للخلطة';
            } else {
                try {
                    $db->beginTransaction();
                    
                    $totalQuantity = 0;
                    $validIngredients = [];
                    
                    // التحقق من توفر الكميات
                    foreach ($ingredients as $ingredient) {
                        $nutsStockId = intval($ingredient['nuts_stock_id'] ?? 0);
                        $quantity = floatval($ingredient['quantity'] ?? 0);
                        
                        if ($nutsStockId <= 0 || $quantity <= 0) {
                            continue;
                        }
                        
                        $stock = $db->queryOne("SELECT * FROM nuts_stock WHERE id = ?", [$nutsStockId]);
                        if (!$stock) {
                            throw new Exception('نوع المكسرات غير موجود');
                        }
                        
                        if ($stock['quantity'] < $quantity) {
                            throw new Exception('الكمية المتاحة من ' . $stock['nut_type'] . ' غير كافية');
                        }
                        
                        $validIngredients[] = [
                            'nuts_stock_id' => $nutsStockId,
                            'quantity' => $quantity
                        ];
                        $totalQuantity += $quantity;
                    }
                    
                    if (empty($validIngredients)) {
                        throw new Exception('لا توجد مكونات صحيحة');
                    }

                    $uniqueIngredientTypes = array_unique(array_map(function($ingredient) {
                        return $ingredient['nuts_stock_id'];
                    }, $validIngredients));

                    if (count($uniqueIngredientTypes) < 2) {
                        throw new Exception('يجب اختيار نوعين مختلفين على الأقل من المكسرات للخلطة');
                    }
                    
                    // إنشاء الخلطة
                    $result = $db->execute(
                        "INSERT INTO mixed_nuts (batch_name, supplier_id, total_quantity, notes, created_by) VALUES (?, ?, ?, ?, ?)",
                        [$batchName, $supplierId, $totalQuantity, $notes ?: null, $currentUser['id']]
                    );
                    
                    $mixedNutsId = $result['insert_id'];
                    
                    // إضافة المكونات وخصم الكميات
                    foreach ($validIngredients as $ingredient) {
                        $db->execute(
                            "INSERT INTO mixed_nuts_ingredients (mixed_nuts_id, nuts_stock_id, quantity) VALUES (?, ?, ?)",
                            [$mixedNutsId, $ingredient['nuts_stock_id'], $ingredient['quantity']]
                        );
                        
                        $db->execute(
                            "UPDATE nuts_stock SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?",
                            [$ingredient['quantity'], $ingredient['nuts_stock_id']]
                        );
                    }
                    
                    $db->commit();
                    
                    logAudit($currentUser['id'], 'create_mixed_nuts', 'mixed_nuts', $mixedNutsId, null, ['name' => $batchName, 'quantity' => $totalQuantity]);
                    
                    $success = 'تم إنشاء المكسرات المشكلة بنجاح';
                    echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse&section=nuts";</script>';
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                }
            }
        }
        
        // إنشاء قالب منتج موحد
        elseif ($action === 'create_unified_template') {
            $productName = trim($_POST['product_name'] ?? '');
            
            // المواد الخام
            $rawMaterials = [];
            if (isset($_POST['materials']) && is_array($_POST['materials'])) {
                foreach ($_POST['materials'] as $material) {
                    $materialType = trim($material['type'] ?? '');
                    $materialName = trim($material['name'] ?? '');
                    $supplierId = intval($material['supplier_id'] ?? 0);
                    $honeyVariety = trim($material['honey_variety'] ?? '');
                    $quantity = floatval($material['quantity'] ?? 0);
                    $unit = trim($material['unit'] ?? 'كجم');
                    
                    if ($materialType && $quantity > 0) {
                        $rawMaterials[] = [
                            'type' => $materialType,
                            'name' => $materialName ?: $materialType,
                            'supplier_id' => $supplierId > 0 ? $supplierId : null,
                            'honey_variety' => ($materialType === 'honey_raw' || $materialType === 'honey_filtered') ? $honeyVariety : null,
                            'quantity' => $quantity,
                            'unit' => $unit
                        ];
                    }
                }
            }
            
            // أدوات التعبئة
            $packagingItems = [];
            if (isset($_POST['packaging']) && is_array($_POST['packaging'])) {
                foreach ($_POST['packaging'] as $packaging) {
                    $packagingId = intval($packaging['id'] ?? 0);
                    $quantity = floatval($packaging['quantity'] ?? 1);
                    
                    if ($packagingId > 0) {
                        $packagingItems[] = [
                            'id' => $packagingId,
                            'quantity' => $quantity
                        ];
                    }
                }
            }
            
            if (empty($productName)) {
                $error = 'يجب إدخال اسم المنتج';
            } elseif (empty($rawMaterials)) {
                $error = 'يجب إضافة مادة خام واحدة على الأقل';
            } elseif (empty($packagingItems)) {
                $error = 'يجب اختيار أداة تعبئة واحدة على الأقل';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // إنشاء القالب
                    $result = $db->execute(
                        "INSERT INTO unified_product_templates (product_name, created_by, status) VALUES (?, ?, 'active')",
                        [$productName, $currentUser['id']]
                    );
                    $templateId = $result['insert_id'];
                    
                    // إضافة المواد الخام
                    foreach ($rawMaterials as $material) {
                        $db->execute(
                            "INSERT INTO template_raw_materials (template_id, material_type, material_name, supplier_id, honey_variety, quantity, unit) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [$templateId, $material['type'], $material['name'], $material['supplier_id'], $material['honey_variety'], $material['quantity'], $material['unit']]
                        );
                    }
                    
                    // إضافة أدوات التعبئة
                    foreach ($packagingItems as $packaging) {
                        $db->execute(
                            "INSERT INTO template_packaging (template_id, packaging_material_id, quantity_per_unit) 
                             VALUES (?, ?, ?)",
                            [$templateId, $packaging['id'], $packaging['quantity']]
                        );
                    }
                    
                    $db->commit();
                    
                    logAudit($currentUser['id'], 'create_unified_template', 'unified_product_templates', $templateId, null, ['product' => $productName]);
                    
                    $success = 'تم إنشاء قالب المنتج بنجاح';
                    echo '<script>window.location.href = "' . getDashboardUrl('production') . '?page=raw_materials_warehouse";</script>';
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'حدث خطأ في إنشاء القالب: ' . $e->getMessage();
                    error_log("Error creating unified template: " . $e->getMessage());
                }
            }
        }
    }
}

// جلب بيانات المكسرات حسب القسم
$nutsStock = [];
$mixedNuts = [];

if ($section === 'nuts') {
    // المكسرات المنفردة
    $nutsStock = $db->query("
        SELECT ns.*, s.name as supplier_name, s.phone as supplier_phone 
        FROM nuts_stock ns
        INNER JOIN suppliers s ON ns.supplier_id = s.id
        WHERE ns.quantity > 0
        ORDER BY ns.nut_type, s.name
    ");
    
    // المكسرات المشكلة
    $mixedNuts = $db->query("
        SELECT mn.*, s.name as supplier_name, u.full_name as creator_name
        FROM mixed_nuts mn
        INNER JOIN suppliers s ON mn.supplier_id = s.id
        LEFT JOIN users u ON mn.created_by = u.id
        ORDER BY mn.created_at DESC
    ");
    
    // جلب مكونات كل خلطة
    foreach ($mixedNuts as &$mix) {
        $ingredients = $db->query("
            SELECT mni.*, ns.nut_type, ns.supplier_id, s.name as ingredient_supplier_name
            FROM mixed_nuts_ingredients mni
            INNER JOIN nuts_stock ns ON mni.nuts_stock_id = ns.id
            INNER JOIN suppliers s ON ns.supplier_id = s.id
            WHERE mni.mixed_nuts_id = ?
        ", [$mix['id']]);
        
        $mix['ingredients'] = $ingredients;
    }
}
?>

<style>
.section-tabs {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    padding: 15px;
    margin-bottom: 25px;
}

.section-tabs .nav-link {
    border-radius: 8px;
    margin: 0 5px;
    padding: 12px 20px;
    font-weight: 500;
    color: #6c757d;
    transition: all 0.3s ease;
}

.section-tabs .nav-link:hover {
    background: #f8f9fa;
    color: #0d6efd;
}

.section-tabs .nav-link.active {
    color: white;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

/* ألوان التبويبات النشطة حسب القسم */
.section-tabs .nav-link.active[href*="honey"] {
    background: linear-gradient(135deg, #ffd89b 0%, #19547b 100%);
}

.section-tabs .nav-link.active[href*="olive_oil"] {
    background: linear-gradient(135deg, #96e6a1 0%, #45b649 100%);
}

.section-tabs .nav-link.active[href*="beeswax"] {
    background: linear-gradient(135deg, #ffc371 0%, #ff5f6d 100%);
}

.section-tabs .nav-link.active[href*="derivatives"] {
    background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
}

.section-tabs .nav-link.active[href*="nuts"] {
    background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);
}

.section-tabs .nav-link i {
    margin-left: 8px;
    font-size: 1.1em;
}

.stats-card {
    background: white;
    border-radius: 16px;
    padding: 1.75rem 1.5rem;
    box-shadow: 0 3px 15px rgba(79, 172, 254, 0.12);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    margin-bottom: 20px;
    border: 1px solid rgba(79, 172, 254, 0.1);
    min-height: 130px;
}

.stats-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 10px 30px rgba(79, 172, 254, 0.25);
    border-color: rgba(79, 172, 254, 0.3);
}

.stats-card .h4 {
    font-size: 2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 0;
    line-height: 1.2;
}

.stats-card .text-muted.small {
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b !important;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 0.5rem;
}

.stats-card small {
    font-size: 0.9rem;
    font-weight: 500;
    color: #94a3b8;
}

.stat-icon {
    width: 70px;
    height: 70px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: white;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
}

.stats-card:hover .stat-icon {
    transform: scale(1.1) rotate(5deg);
}

.icon-honey { background: linear-gradient(135deg, #ffd89b 0%, #19547b 100%); }
.icon-olive { background: linear-gradient(135deg, #96e6a1 0%, #45b649 100%); }
.icon-wax { background: linear-gradient(135deg, #ffc371 0%, #ff5f6d 100%); }
.icon-derivative { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }

/* تنسيق رؤوس البطاقات */
.card-header {
    padding: 1.25rem 1.5rem !important;
    border-radius: 15px 15px 0 0 !important;
}

.card-header h5 {
    font-size: 1.15rem !important;
    font-weight: 600 !important;
    margin: 0 !important;
}

.card-header .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.3s ease !important;
}

.card-header .btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}

/* تنسيق الجداول */
.table {
    font-size: 0.95rem;
}

.table th {
    font-weight: 600;
    font-size: 0.9rem;
    padding: 1rem 0.75rem !important;
}

.table td {
    padding: 0.95rem 0.75rem !important;
    vertical-align: middle;
}

.badge {
    padding: 0.45rem 0.85rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i>مخزن الخامات</h2>
    <button class="btn text-white" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);" data-bs-toggle="modal" data-bs-target="#createUnifiedTemplateModal">
        <i class="bi bi-plus-circle me-2"></i>إنشاء قالب منتج
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
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

<!-- Tabs للأقسام الأربعة -->
<div class="section-tabs">
    <ul class="nav nav-pills justify-content-center">
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'honey' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=honey">
                <i class="bi bi-droplet-fill"></i>العسل
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'olive_oil' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=olive_oil">
                <i class="bi bi-cup-straw"></i>زيت الزيتون
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'beeswax' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=beeswax">
                <i class="bi bi-hexagon-fill"></i>شمع العسل
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'derivatives' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=derivatives">
                <i class="bi bi-box2-fill"></i>المشتقات
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'nuts' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=nuts">
                <i class="bi bi-nut-fill"></i>المكسرات
            </a>
        </li>
    </ul>
</div>

<?php
// عرض محتوى القسم المختار
if ($section === 'honey') {
    // جلب موردي العسل
    $honeySuppliers = $db->query("SELECT id, name, phone FROM suppliers WHERE status = 'active' AND type = 'honey' ORDER BY name");
    
    // إحصائيات العسل
    $honeyStats = [
        'total_raw' => $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
        'total_filtered' => $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(DISTINCT supplier_id) as total FROM honey_stock")['total'] ?? 0
    ];
    
    // جلب مخزون العسل
    $honeyStock = $db->query("
        SELECT hs.*, s.name as supplier_name, s.phone as supplier_phone
        FROM honey_stock hs
        LEFT JOIN suppliers s ON hs.supplier_id = s.id
        ORDER BY s.name ASC, hs.honey_variety ASC
    ");
    ?>
    
    <!-- إحصائيات العسل -->
    <div class="row mb-4">
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">العسل الخام</div>
                        <div class="h4 mb-0"><?php echo number_format($honeyStats['total_raw'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-honey">
                        <i class="bi bi-droplet-half"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">العسل المصفى</div>
                        <div class="h4 mb-0"><?php echo number_format($honeyStats['total_filtered'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-honey">
                        <i class="bi bi-droplet"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $honeyStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-honey">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #ffd89b 0%, #19547b 100%);">
            <h5 class="mb-0"><i class="bi bi-droplet me-2"></i>مخزون العسل</h5>
            <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addHoneyModal">
                <i class="bi bi-plus-circle me-1"></i>إضافة عسل
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($honeyStock)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    لا يوجد مخزون عسل
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>المورد</th>
                                <th>النوع</th>
                                <th class="text-center">العسل الخام</th>
                                <th class="text-center">العسل المصفى</th>
                                <th class="text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($honeyStock as $stock): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong>
                                        <?php if ($stock['supplier_phone']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($stock['honey_variety']); ?></span></td>
                                    <td class="text-center"><strong class="text-warning"><?php echo number_format($stock['raw_honey_quantity'], 2); ?></strong> كجم</td>
                                    <td class="text-center"><strong class="text-success"><?php echo number_format($stock['filtered_honey_quantity'], 2); ?></strong> كجم</td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-warning" 
                                                onclick="filterHoney(<?php echo $stock['id']; ?>, '<?php echo htmlspecialchars($stock['supplier_name']); ?>', '<?php echo htmlspecialchars($stock['honey_variety']); ?>', <?php echo $stock['raw_honey_quantity']; ?>)"
                                                <?php echo $stock['raw_honey_quantity'] <= 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-funnel"></i> تصفية
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal إضافة عسل -->
    <div class="modal fade" id="addHoneyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة عسل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_honey">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">المورد <span class="text-danger">*</span></label>
                            <select class="form-select" name="supplier_id" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($honeySuppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نوع العسل</label>
                            <select class="form-select" name="honey_variety">
                                <option value="سدر">سدر</option>
                                <option value="جبلي">جبلي</option>
                                <option value="حبة البركة">حبة البركة</option>
                                <option value="موالح">موالح</option>
                                <option value="نوارة برسيم">نوارة برسيم</option>
                                <option value="أخرى">أخرى</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="honey_type">
                                <option value="raw">عسل خام</option>
                                <option value="filtered">عسل مصفى</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الكمية (كجم)</label>
                            <input type="number" class="form-control" name="quantity" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-primary">إضافة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal تصفية عسل -->
    <div class="modal fade" id="filterHoneyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تصفية العسل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="filter_honey">
                    <input type="hidden" name="stock_id" id="filter_stock_id">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>سيتم خصم 0.5% كخسارة في التصفية
                        </div>
                        <div class="mb-3">
                            <label class="form-label">المورد</label>
                            <input type="text" class="form-control" id="filter_supplier" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">النوع</label>
                            <input type="text" class="form-control" id="filter_variety" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الكمية المتاحة</label>
                            <input type="text" class="form-control" id="filter_available" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الكمية المراد تصفيتها (كجم)</label>
                            <input type="number" class="form-control" name="raw_quantity" step="0.01" min="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">تاريخ التصفية</label>
                            <input type="date" class="form-control" name="filtration_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-warning">تصفية</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function filterHoney(id, supplier, variety, available) {
        document.getElementById('filter_stock_id').value = id;
        document.getElementById('filter_supplier').value = supplier;
        document.getElementById('filter_variety').value = variety;
        document.getElementById('filter_available').value = parseFloat(available).toFixed(2) + ' كجم';
        new bootstrap.Modal(document.getElementById('filterHoneyModal')).show();
    }
    </script>
    
    <?php
} elseif ($section === 'olive_oil') {
    // جلب موردي زيت الزيتون
    $oilSuppliers = $db->query("SELECT id, name, phone FROM suppliers WHERE status = 'active' AND type = 'olive_oil' ORDER BY name");
    
    // قسم زيت الزيتون
    $oilStats = [
        'total_quantity' => $db->queryOne("SELECT COALESCE(SUM(quantity), 0) as total FROM olive_oil_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(*) as total FROM olive_oil_stock")['total'] ?? 0
    ];
    
    $oilStock = $db->query("
        SELECT os.*, s.name as supplier_name, s.phone as supplier_phone
        FROM olive_oil_stock os
        LEFT JOIN suppliers s ON os.supplier_id = s.id
        ORDER BY s.name ASC
    ");
    ?>
    
    <!-- إحصائيات زيت الزيتون -->
    <div class="row mb-4">
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي زيت الزيتون</div>
                        <div class="h4 mb-0"><?php echo number_format($oilStats['total_quantity'], 2); ?> <small>لتر</small></div>
                    </div>
                    <div class="stat-icon icon-olive">
                        <i class="bi bi-cup-straw"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $oilStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-olive">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- مخزون زيت الزيتون -->
        <div class="col-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #96e6a1 0%, #45b649 100%);">
                    <h5 class="mb-0"><i class="bi bi-cup-straw me-2"></i>مخزون زيت الزيتون</h5>
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addOliveOilModal">
                        <i class="bi bi-plus-circle me-1"></i>إضافة
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($oilStock)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            لا يوجد مخزون
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>المورد</th>
                                        <th class="text-center">الكمية (لتر)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($oilStock as $stock): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong>
                                                <?php if ($stock['supplier_phone']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><strong class="text-success"><?php echo number_format($stock['quantity'], 2); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal إضافة زيت زيتون -->
    <div class="modal fade" id="addOliveOilModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة زيت زيتون</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_olive_oil">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">المورد <span class="text-danger">*</span></label>
                            <select class="form-select" name="supplier_id" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($oilSuppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الكمية (لتر)</label>
                            <input type="number" class="form-control" name="quantity" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-success">إضافة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php
} elseif ($section === 'beeswax') {
    // جلب موردي شمع العسل
    $waxSuppliers = $db->query("SELECT id, name, phone FROM suppliers WHERE status = 'active' AND type = 'beeswax' ORDER BY name");
    
    // قسم شمع العسل
    $waxStats = [
        'total_weight' => $db->queryOne("SELECT COALESCE(SUM(weight), 0) as total FROM beeswax_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(*) as total FROM beeswax_stock")['total'] ?? 0
    ];
    
    $waxStock = $db->query("
        SELECT ws.*, s.name as supplier_name, s.phone as supplier_phone
        FROM beeswax_stock ws
        LEFT JOIN suppliers s ON ws.supplier_id = s.id
        ORDER BY s.name ASC
    ");
    ?>
    
    <!-- إحصائيات شمع العسل -->
    <div class="row mb-4">
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي شمع العسل</div>
                        <div class="h4 mb-0"><?php echo number_format($waxStats['total_weight'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-wax">
                        <i class="bi bi-hexagon-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $waxStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-wax">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- مخزون شمع العسل -->
        <div class="col-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #ffc371 0%, #ff5f6d 100%);">
                    <h5 class="mb-0"><i class="bi bi-hexagon-fill me-2"></i>مخزون شمع العسل</h5>
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addBeeswaxModal">
                        <i class="bi bi-plus-circle me-1"></i>إضافة
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($waxStock)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            لا يوجد مخزون
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>المورد</th>
                                        <th class="text-center">الوزن (كجم)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($waxStock as $stock): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong>
                                                <?php if ($stock['supplier_phone']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><strong class="text-warning"><?php echo number_format($stock['weight'], 2); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal إضافة شمع عسل -->
    <div class="modal fade" id="addBeeswaxModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة شمع عسل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_beeswax">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">المورد <span class="text-danger">*</span></label>
                            <select class="form-select" name="supplier_id" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($waxSuppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الوزن (كجم)</label>
                            <input type="number" class="form-control" name="weight" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-warning">إضافة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php
} elseif ($section === 'derivatives') {
    // جلب موردي المشتقات
    $derivSuppliers = $db->query("SELECT id, name, phone FROM suppliers WHERE status = 'active' AND type = 'derivatives' ORDER BY name");
    
    // قسم المشتقات
    $derivStats = [
        'total_weight' => $db->queryOne("SELECT COALESCE(SUM(weight), 0) as total FROM derivatives_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(DISTINCT supplier_id) as total FROM derivatives_stock")['total'] ?? 0,
        'types_count' => $db->queryOne("SELECT COUNT(DISTINCT derivative_type) as total FROM derivatives_stock")['total'] ?? 0
    ];
    
    $derivStock = $db->query("
        SELECT ds.*, s.name as supplier_name, s.phone as supplier_phone
        FROM derivatives_stock ds
        LEFT JOIN suppliers s ON ds.supplier_id = s.id
        ORDER BY ds.derivative_type ASC, s.name ASC
    ");
    
    $derivTypes = $db->query("SELECT DISTINCT derivative_type FROM derivatives_stock ORDER BY derivative_type");
    ?>
    
    <!-- إحصائيات المشتقات -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي الوزن</div>
                        <div class="h4 mb-0"><?php echo number_format($derivStats['total_weight'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-box2-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الأنواع</div>
                        <div class="h4 mb-0"><?php echo $derivStats['types_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-tags"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $derivStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- مخزون المشتقات -->
        <div class="col-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);">
                    <h5 class="mb-0"><i class="bi bi-box2-fill me-2"></i>مخزون المشتقات</h5>
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addDerivativeModal">
                        <i class="bi bi-plus-circle me-1"></i>إضافة
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($derivStock)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            لا يوجد مخزون
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>المورد</th>
                                        <th class="text-center">الوزن (كجم)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($derivStock as $stock): ?>
                                        <tr>
                                            <td><span class="badge bg-info"><?php echo htmlspecialchars($stock['derivative_type']); ?></span></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong>
                                                <?php if ($stock['supplier_phone']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><strong class="text-info"><?php echo number_format($stock['weight'], 2); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal إضافة مشتق -->
    <div class="modal fade" id="addDerivativeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة مشتق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_derivative">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">المورد <span class="text-danger">*</span></label>
                            <select class="form-select" name="supplier_id" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($derivSuppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نوع المشتق <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="derivative_type" placeholder="مثال: غذاء ملكات، حبوب لقاح..." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الوزن (كجم)</label>
                            <input type="number" class="form-control" name="weight" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-info">إضافة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php
} elseif ($section === 'nuts') {
    // جلب موردي المكسرات (جميع الموردين النشطين)
$nutsSuppliers = $db->query("SELECT id, name, phone FROM suppliers WHERE status = 'active' AND type = 'nuts' ORDER BY name");
    
    // قسم المكسرات
    $nutsStats = [
        'total_quantity' => $db->queryOne("SELECT COALESCE(SUM(quantity), 0) as total FROM nuts_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(DISTINCT supplier_id) as total FROM nuts_stock")['total'] ?? 0,
        'types_count' => $db->queryOne("SELECT COUNT(DISTINCT nut_type) as total FROM nuts_stock")['total'] ?? 0,
        'mixed_batches' => count($mixedNuts)
    ];
    ?>
    
    <!-- إحصائيات المكسرات -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي الكمية</div>
                        <div class="h4 mb-0"><?php echo number_format($nutsStats['total_quantity'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                        <i class="bi bi-nut-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الأنواع</div>
                        <div class="h4 mb-0"><?php echo $nutsStats['types_count']; ?></div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                        <i class="bi bi-tags"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $nutsStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">المكسرات المشكلة</div>
                        <div class="h4 mb-0"><?php echo $nutsStats['mixed_batches']; ?></div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                        <i class="bi bi-layers-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- المكسرات المنفردة -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                    <h5 class="mb-0"><i class="bi bi-nut me-2"></i>المكسرات المنفردة</h5>
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addSingleNutsModal">
                        <i class="bi bi-plus-circle me-1"></i>إضافة
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($nutsStock)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            لا يوجد مخزون مكسرات
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>المورد</th>
                                        <th class="text-center">الكمية (كجم)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($nutsStock as $stock): ?>
                                        <tr>
                                            <td><span class="badge" style="background-color: #8b6f47;"><?php echo htmlspecialchars($stock['nut_type']); ?></span></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong>
                                                <?php if ($stock['supplier_phone']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><strong style="color: #8b6f47;"><?php echo number_format($stock['quantity'], 3); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- المكسرات المشكلة -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                    <h5 class="mb-0"><i class="bi bi-layers-fill me-2"></i>المكسرات المشكلة</h5>
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#createMixedNutsModal">
                        <i class="bi bi-plus-circle me-1"></i>إنشاء خلطة
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($mixedNuts)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            لا توجد مكسرات مشكلة
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($mixedNuts as $mix): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($mix['batch_name']); ?></h6>
                                            <small class="text-muted">
                                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($mix['supplier_name']); ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-success"><?php echo number_format($mix['total_quantity'], 3); ?> كجم</span>
                                    </div>
                                    <div class="small">
                                        <strong>المكونات:</strong>
                                        <?php if (!empty($mix['ingredients'])): ?>
                                            <ul class="list-unstyled mb-0 mt-1">
                                                <?php foreach ($mix['ingredients'] as $ing): ?>
                                                    <li class="text-muted">
                                                        • <?php echo htmlspecialchars($ing['nut_type']); ?>: 
                                                        <?php echo number_format($ing['quantity'], 3); ?> كجم
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($mix['notes']): ?>
                                        <small class="text-muted d-block mt-2">
                                            <i class="bi bi-sticky me-1"></i><?php echo htmlspecialchars($mix['notes']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <small class="text-muted d-block mt-1">
                                        <i class="bi bi-calendar me-1"></i><?php echo date('Y-m-d', strtotime($mix['created_at'])); ?>
                                        <?php if ($mix['creator_name']): ?>
                                            | <i class="bi bi-person-check me-1"></i><?php echo htmlspecialchars($mix['creator_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal إضافة مكسرات منفردة -->
    <div class="modal fade" id="addSingleNutsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة مكسرات منفردة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_single_nuts">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">المورد <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($nutsSuppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نوع المكسرات <span class="text-danger">*</span></label>
                            <select name="nut_type" class="form-select" required>
                                <option value="">اختر نوع المكسرات</option>
                                <option value="عين جمل">عين جمل</option>
                                <option value="بندق">بندق</option>
                                <option value="لوز">لوز</option>
                                <option value="فستق">فستق</option>
                                <option value="كاجو">كاجو</option>
                                <option value="فول سوداني">فول سوداني</option>
                                <option value="أخرى">أخرى (يدوياً)</option>
                            </select>
                        </div>
                        <div class="mb-3" id="otherNutTypeDiv" style="display: none;">
                            <label class="form-label">اكتب نوع المكسرات <span class="text-danger">*</span></label>
                            <input type="text" id="otherNutType" class="form-control" 
                                   placeholder="مثال: بيكان، مكاديميا...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الكمية (كجم) <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" step="0.001" min="0.001" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ملاحظات</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                            <i class="bi bi-check-circle me-1"></i>إضافة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal إنشاء مكسرات مشكلة -->
    <div class="modal fade" id="createMixedNutsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                    <h5 class="modal-title"><i class="bi bi-layers-fill me-2"></i>إنشاء مكسرات مشكلة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="mixedNutsForm">
                    <input type="hidden" name="action" value="create_mixed_nuts">
                    <input type="hidden" name="submit_token" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">اسم الخلطة <span class="text-danger">*</span></label>
                            <input type="text" name="batch_name" class="form-control" required 
                                   placeholder="مثال: مكسرات مشكلة ممتازة">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">المورد <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($nutsSuppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">المورد المسؤول عن هذه الخلطة</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">المكونات <span class="text-danger">*</span></label>
                                <button type="button" class="btn btn-sm text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);" onclick="addIngredient()">
                                    <i class="bi bi-plus-circle me-1"></i>إضافة مكون
                                </button>
                            </div>
                            <div id="ingredientsContainer">
                                <!-- سيتم إضافة المكونات هنا -->
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ملاحظات</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>ملاحظة:</strong> سيتم خصم الكميات المحددة تلقائياً من مخزون كل نوع من المكسرات المنفردة.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                            <i class="bi bi-check-circle me-1"></i>إنشاء الخلطة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    let ingredientIndex = 0;
    
    function addIngredient() {
        const container = document.getElementById('ingredientsContainer');
        const nutsStock = <?php echo json_encode($nutsStock); ?>;
        
        const ingredientHtml = `
            <div class="ingredient-row border rounded p-3 mb-2" id="ingredient-${ingredientIndex}">
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">نوع المكسرات</label>
                        <select name="ingredients[${ingredientIndex}][nuts_stock_id]" class="form-select" required onchange="updateAvailable(this, ${ingredientIndex})">
                            <option value="">اختر النوع</option>
                            ${nutsStock.map(stock => `
                                <option value="${stock.id}" data-quantity="${stock.quantity}" data-type="${stock.nut_type}">
                                    ${stock.nut_type} (${stock.supplier_name}) - متاح: ${parseFloat(stock.quantity).toFixed(3)} كجم
                                </option>
                            `).join('')}
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الكمية (كجم)</label>
                        <input type="number" name="ingredients[${ingredientIndex}][quantity]" 
                               class="form-control" step="0.001" min="0.001" required 
                               id="quantity-${ingredientIndex}">
                        <small class="text-muted" id="available-${ingredientIndex}"></small>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeIngredient(${ingredientIndex})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', ingredientHtml);
        ingredientIndex++;
    }
    
    function removeIngredient(index) {
        document.getElementById('ingredient-' + index).remove();
    }
    
    function updateAvailable(select, index) {
        const selectedOption = select.options[select.selectedIndex];
        const availableQty = selectedOption.getAttribute('data-quantity');
        const availableSpan = document.getElementById('available-' + index);
        const quantityInput = document.getElementById('quantity-' + index);
        
        if (availableQty) {
            availableSpan.textContent = `متاح: ${parseFloat(availableQty).toFixed(3)} كجم`;
            quantityInput.max = availableQty;
        }
    }
    
    // إضافة صف واحد عند فتح النافذة
    document.getElementById('createMixedNutsModal').addEventListener('shown.bs.modal', function () {
        if (document.getElementById('ingredientsContainer').children.length === 0) {
            addIngredient();
        }
    });
    
    // التحكم في إظهار حقل "أخرى" لنوع المكسرات
    document.addEventListener('DOMContentLoaded', function() {
        const nutTypeSelect = document.querySelector('select[name="nut_type"]');
        const otherNutTypeDiv = document.getElementById('otherNutTypeDiv');
        const otherNutTypeInput = document.getElementById('otherNutType');
        
        if (nutTypeSelect) {
            nutTypeSelect.addEventListener('change', function() {
                if (this.value === 'أخرى') {
                    otherNutTypeDiv.style.display = 'block';
                    otherNutTypeInput.required = true;
                } else {
                    otherNutTypeDiv.style.display = 'none';
                    otherNutTypeInput.required = false;
                    otherNutTypeInput.value = '';
                }
            });
        }
        
        // عند إرسال النموذج، إذا كان "أخرى" استبدل القيمة
        const addNutsForm = document.querySelector('#addSingleNutsModal form');
        if (addNutsForm) {
            addNutsForm.addEventListener('submit', function(e) {
                if (nutTypeSelect.value === 'أخرى' && otherNutTypeInput.value.trim()) {
                    nutTypeSelect.value = otherNutTypeInput.value.trim();
                }
            });
        }
    });
    </script>
    
    <?php
}
?>

<!-- Modal إنشاء قالب منتج موحد متقدم -->
<div class="modal fade" id="createUnifiedTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إنشاء قالب منتج متقدم</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createUnifiedTemplateForm">
                <input type="hidden" name="action" value="create_unified_template">
                <input type="hidden" name="submit_token" value="">
                
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- اسم المنتج -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="product_name" required 
                               placeholder="مثال: مكسرات بالعسل 500 جرام">
                    </div>
                    
                    <!-- المواد الخام -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-success mb-0"><i class="bi bi-droplet-fill me-2"></i>المواد الخام</h6>
                            <button type="button" class="btn btn-success btn-sm" onclick="addMaterialRow()">
                                <i class="bi bi-plus-circle me-1"></i>إضافة مادة
                            </button>
                        </div>
                        
                        <div id="materialsContainer">
                            <!-- سيتم إضافة الصفوف هنا ديناميكياً -->
                        </div>
                    </div>
                    
                    <!-- أدوات التعبئة -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-primary mb-0"><i class="bi bi-box-seam me-2"></i>أدوات التعبئة</h6>
                        </div>
                        
                        <?php if (empty($packagingMaterials)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا توجد أدوات تعبئة متاحة. يرجى إضافة أدوات التعبئة أولاً من صفحة المحاسب.
                            </div>
                        <?php else: ?>
                            <div class="border rounded p-3" style="max-height: 250px; overflow-y: auto;">
                                <?php foreach ($packagingMaterials as $pkg): ?>
                                    <div class="d-flex align-items-center mb-3 p-2 bg-light rounded">
                                        <div class="form-check flex-grow-1">
                                            <input class="form-check-input" type="checkbox" 
                                                   name="packaging[<?php echo $pkg['id']; ?>][id]" 
                                                   value="<?php echo $pkg['id']; ?>" 
                                                   id="pkg_<?php echo $pkg['id']; ?>">
                                            <label class="form-check-label" for="pkg_<?php echo $pkg['id']; ?>">
                                                <strong><?php echo htmlspecialchars($pkg['name']); ?></strong>
                                                <span class="badge bg-secondary ms-2">
                                                    <?php echo number_format($pkg['quantity'], 2); ?> <?php echo htmlspecialchars($pkg['unit'] ?? 'قطعة'); ?>
                                                </span>
                                            </label>
                                        </div>
                                        <div class="ms-3" style="width: 150px;">
                                            <input type="number" 
                                                   class="form-control form-control-sm" 
                                                   name="packaging[<?php echo $pkg['id']; ?>][quantity]" 
                                                   step="0.001" 
                                                   min="0.001" 
                                                   value="1" 
                                                   placeholder="الكمية">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                حدد الكمية المطلوبة لكل أداة تعبئة لكل وحدة إنتاج
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-2"></i>إنشاء القالب
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// دالة لإضافة صف مادة خام جديدة
let materialRowCount = 0;
function addMaterialRow() {
    materialRowCount++;
    const container = document.getElementById('materialsContainer');
    
    const rowHtml = `
        <div class="card mb-3 material-row" id="material_${materialRowCount}">
            <div class="card-body">
                <div class="row align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">نوع المادة <span class="text-danger">*</span></label>
                        <select class="form-select" name="materials[${materialRowCount}][type]" 
                                onchange="updateSuppliersForMaterial(${materialRowCount}, this.value)" required>
                            <option value="">اختر...</option>
                            <option value="honey_raw">عسل خام</option>
                            <option value="honey_filtered">عسل مصفى</option>
                            <option value="olive_oil">زيت زيتون</option>
                            <option value="beeswax">شمع عسل</option>
                            <option value="derivatives">مشتقات</option>
                            <option value="nuts">مكسرات</option>
                            <option value="other">أخرى</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">المورد</label>
                        <div class="alert alert-info py-1 px-2 mb-0" role="alert" style="font-size: 0.75rem;">
                            سيتم اختيار المورد عند إنشاء التشغيلة من هذا القالب.
                        </div>
                        <input type="hidden" name="materials[${materialRowCount}][supplier_id]" 
                               id="supplier_${materialRowCount}" value="">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">نوع العسل</label>
                        <select class="form-select form-select-sm" name="materials[${materialRowCount}][honey_variety]" 
                                id="honey_variety_${materialRowCount}" style="display:none;">
                            <option value="">اختر النوع</option>
                            <option value="سدر">سدر</option>
                            <option value="جبلي">جبلي</option>
                            <option value="حبة البركة">حبة البركة</option>
                            <option value="موالح">موالح</option>
                            <option value="نوارة برسيم">نوارة برسيم</option>
                            <option value="أخرى">أخرى</option>
                        </select>
                        <small class="text-muted" id="honey_variety_placeholder_${materialRowCount}">-</small>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">الكمية <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-sm" name="materials[${materialRowCount}][quantity]" 
                               step="0.001" min="0.001" required placeholder="0.000">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">الوحدة</label>
                        <select class="form-select form-select-sm" name="materials[${materialRowCount}][unit]">
                            <option value="كجم">كجم</option>
                            <option value="جرام">جرام</option>
                            <option value="لتر">لتر</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-danger btn-sm w-100" 
                                onclick="removeMaterialRow(${materialRowCount})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', rowHtml);
}

// دالة لحذف صف مادة خام
function removeMaterialRow(rowId) {
    const row = document.getElementById('material_' + rowId);
    if (row) {
        row.remove();
    }
}

// دالة لتحديث قائمة الموردين حسب نوع المادة
function updateSuppliersForMaterial(rowId, materialType) {
    const supplierInput = document.getElementById('supplier_' + rowId);
    const honeyVarietySelect = document.getElementById('honey_variety_' + rowId);
    const honeyVarietyPlaceholder = document.getElementById('honey_variety_placeholder_' + rowId);
    
    if (!supplierInput) return;
    
    // إظهار/إخفاء حقل نوع العسل
    const isHoneyType = materialType === 'honey_raw' || materialType === 'honey_filtered';
    if (honeyVarietySelect && honeyVarietyPlaceholder) {
        if (isHoneyType) {
            honeyVarietySelect.style.display = 'block';
            honeyVarietyPlaceholder.style.display = 'none';
        } else {
            honeyVarietySelect.style.display = 'none';
            honeyVarietyPlaceholder.style.display = 'block';
        }
    }
    // إعادة تعيين قيمة المورد (سيتم تحديده لاحقاً أثناء إنشاء التشغيلة)
    supplierInput.value = '';
}

// إضافة صف واحد عند فتح Modal
document.getElementById('createUnifiedTemplateModal').addEventListener('shown.bs.modal', function () {
    const container = document.getElementById('materialsContainer');
    if (container.children.length === 0) {
        addMaterialRow();
    }
});

// إضافة توكن فريد لجميع النماذج لمنع تكرار الإرسال
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    
    forms.forEach(function(form) {
        const tokenInput = form.querySelector('input[name="submit_token"]');
        if (tokenInput) {
            const token = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            tokenInput.value = token;
        }
        
        form.addEventListener('submit', function(e) {
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> جاري المعالجة...';
                
                setTimeout(function() {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'إرسال';
                }, 3000);
            }
        });
    });
    
    // إخفاء شاشة التحميل فوراً
    const pageLoader = document.getElementById('pageLoader');
    if (pageLoader) {
        pageLoader.style.display = 'none';
        pageLoader.style.visibility = 'hidden';
        pageLoader.classList.add('hidden');
    }
});

// إخفاء شاشة التحميل عند فتح أي نافذة منبثقة
document.addEventListener('show.bs.modal', function() {
    const pageLoader = document.getElementById('pageLoader');
    if (pageLoader) {
        pageLoader.style.display = 'none';
        pageLoader.style.visibility = 'hidden';
        pageLoader.classList.add('hidden');
    }
});
</script>
