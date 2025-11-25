<?php
/**
 * سكريبت لاستيراد أدوات التعبئة من packaging.json
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('manager');

$db = db();

// إنشاء جدول packaging_materials إذا لم يكن موجوداً
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `packaging_materials` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `material_id` varchar(50) NOT NULL COMMENT 'معرف فريد مثل PKG-001',
              `name` varchar(255) NOT NULL COMMENT 'اسم مأخوذ من type + specifications',
              `type` varchar(100) NOT NULL COMMENT 'نوع الأداة مثل: عبوات زجاجية',
              `specifications` varchar(255) DEFAULT NULL COMMENT 'المواصفات مثل: برطمان 720م دائري',
              `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
              `unit` varchar(50) DEFAULT 'قطعة',
              `unit_price` decimal(10,2) DEFAULT 0.00,
              `status` enum('active','inactive') DEFAULT 'active',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `material_id` (`material_id`),
              KEY `type` (`type`),
              KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "تم إنشاء جدول packaging_materials\n";
    } catch (Exception $e) {
        die("خطأ في إنشاء الجدول: " . $e->getMessage() . "\n");
    }
}

// قراءة ملف packaging.json
$jsonFile = __DIR__ . '/../packaging.json';
if (!file_exists($jsonFile)) {
    die("ملف packaging.json غير موجود\n");
}

$jsonContent = file_get_contents($jsonFile);
$packagingData = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("خطأ في قراءة ملف JSON: " . json_last_error_msg() . "\n");
}

$added = 0;
$updated = 0;
$errors = [];

foreach ($packagingData as $item) {
    try {
        $materialId = $item['id'] ?? '';
        $type = $item['type'] ?? '';
        $specifications = $item['specifications'] ?? '';
        $quantity = floatval($item['quantity'] ?? 0);
        $unit = $item['unit'] ?? 'قطعة';
        
        // بناء الاسم من type + specifications
        $name = trim($type . ' - ' . $specifications);
        if (empty($name) || $name === ' - ') {
            $name = $materialId;
        }
        
        if (empty($materialId)) {
            $errors[] = "عنصر بدون material_id: " . json_encode($item);
            continue;
        }
        
        // التحقق من وجود السجل
        $existing = $db->queryOne(
            "SELECT id FROM packaging_materials WHERE material_id = ?",
            [$materialId]
        );
        
        if ($existing) {
            // تحديث السجل الموجود
            $db->execute(
                "UPDATE packaging_materials 
                 SET name = ?, type = ?, specifications = ?, quantity = ?, unit = ?, updated_at = NOW()
                 WHERE material_id = ?",
                [$name, $type, $specifications, $quantity, $unit, $materialId]
            );
            $updated++;
        } else {
            // إضافة سجل جديد
            $db->execute(
                "INSERT INTO packaging_materials (material_id, name, type, specifications, quantity, unit, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')",
                [$materialId, $name, $type, $specifications, $quantity, $unit]
            );
            $added++;
        }
    } catch (Exception $e) {
        $errors[] = "خطأ في معالجة {$item['id']}: " . $e->getMessage();
    }
}

// حذف أدوات التعبئة من جدول products
try {
    // البحث عن المنتجات التي تحتوي على "تغليف" أو "packaging"
    $packagingProducts = $db->query(
        "SELECT id, name FROM products 
         WHERE (category LIKE '%تغليف%' OR category LIKE '%packaging%' 
                OR type LIKE '%تغليف%' OR type LIKE '%packaging%')
         AND status = 'active'"
    );
    
    $deletedFromProducts = 0;
    foreach ($packagingProducts as $product) {
        // حذف المنتج (أو تعطيله)
        $db->execute(
            "UPDATE products SET status = 'inactive' WHERE id = ?",
            [$product['id']]
        );
        $deletedFromProducts++;
    }
    
    echo "تم تعطيل {$deletedFromProducts} منتج من جدول products\n";
} catch (Exception $e) {
    echo "تحذير: خطأ في حذف المنتجات من products: " . $e->getMessage() . "\n";
}

echo "\n=== ملخص الاستيراد ===\n";
echo "تم إضافة: {$added} سجل جديد\n";
echo "تم تحديث: {$updated} سجل موجود\n";
if (!empty($errors)) {
    echo "\nالأخطاء:\n";
    foreach ($errors as $error) {
        echo "- {$error}\n";
    }
}
echo "\nتم الاستيراد بنجاح!\n";
