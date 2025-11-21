<?php
/**
 * استيراد بيانات التغليف من JSON إلى قاعدة البيانات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// قراءة ملف JSON
$jsonFile = __DIR__ . '/../packaging.json';
if (!file_exists($jsonFile)) {
    die("ملف packaging.json غير موجود\n");
}

$jsonData = file_get_contents($jsonFile);
$packaging = json_decode($jsonData, true);

if (!$packaging || !is_array($packaging)) {
    die("خطأ في قراءة ملف JSON\n");
}

$db = db();
$imported = 0;
$updated = 0;
$errors = 0;

echo "بدء استيراد بيانات التغليف...\n\n";

foreach ($packaging as $item) {
    try {
        // التحقق من وجود المنتج
        $existing = $db->queryOne(
            "SELECT id FROM products WHERE name = ? AND category = ?",
            [$item['specifications'], $item['type']]
        );
        
        if ($existing) {
            // تحديث المنتج الموجود
            $db->execute(
                "UPDATE products SET 
                    quantity = ?,
                    unit = ?,
                    updated_at = NOW()
                 WHERE id = ?",
                [
                    $item['quantity'],
                    $item['unit'],
                    $existing['id']
                ]
            );
            $updated++;
            echo "✓ تم تحديث: {$item['specifications']}\n";
        } else {
            // إضافة منتج جديد
            $db->execute(
                "INSERT INTO products (name, category, quantity, unit, description, status) 
                 VALUES (?, ?, ?, ?, ?, 'active')",
                [
                    $item['specifications'],
                    $item['type'],
                    $item['quantity'],
                    $item['unit'],
                    "ID: {$item['id']}"
                ]
            );
            $imported++;
            echo "✓ تم إضافة: {$item['specifications']}\n";
        }
    } catch (Exception $e) {
        $errors++;
        echo "✗ خطأ في: {$item['specifications']} - {$e->getMessage()}\n";
    }
}

echo "\n\n";
echo "تم استيراد: $imported عنصر\n";
echo "تم تحديث: $updated عنصر\n";
echo "أخطاء: $errors\n";
echo "\nتم الانتهاء!\n";

