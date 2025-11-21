<?php
/**
 * Run migration to add invoice_item_id column to return_items table
 * This script should be run once to add the required column
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html dir='rtl' lang='ar'>
<head>
    <meta charset='UTF-8'>
    <title>تشغيل Migration</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>
    <h1>تشغيل Migration: إضافة invoice_item_id إلى return_items</h1>";

try {
    $db = db();
    $conn = $db->getConnection();
    
    // Check if column already exists
    $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
    
    if (!empty($columnCheck)) {
        echo "<div class='info'>العمود invoice_item_id موجود بالفعل في جدول return_items</div>";
    } else {
        echo "<div class='info'>جاري إضافة العمود invoice_item_id...</div>";
        
        // Add column
        $db->execute("ALTER TABLE `return_items` ADD COLUMN `invoice_item_id` int(11) DEFAULT NULL AFTER `sale_item_id`");
        echo "<div class='success'>✓ تم إضافة العمود invoice_item_id بنجاح</div>";
        
        // Add index
        try {
            $db->execute("ALTER TABLE `return_items` ADD INDEX `idx_invoice_item_id` (`invoice_item_id`)");
            echo "<div class='success'>✓ تم إضافة الفهرس بنجاح</div>";
        } catch (Throwable $e) {
            echo "<div class='info'>ملاحظة: الفهرس موجود بالفعل أو حدث خطأ: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
        // Add foreign key
        try {
            $db->execute("ALTER TABLE `return_items` ADD CONSTRAINT `return_items_ibfk_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL");
            echo "<div class='success'>✓ تم إضافة Foreign Key بنجاح</div>";
        } catch (Throwable $e) {
            echo "<div class='info'>ملاحظة: Foreign Key موجود بالفعل أو حدث خطأ: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Check batch_number_id column
    $batchColCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'batch_number_id'");
    if (empty($batchColCheck)) {
        echo "<div class='info'>جاري إضافة العمود batch_number_id...</div>";
        try {
            $db->execute("ALTER TABLE `return_items` ADD COLUMN `batch_number_id` int(11) DEFAULT NULL AFTER `invoice_item_id`");
            echo "<div class='success'>✓ تم إضافة العمود batch_number_id بنجاح</div>";
        } catch (Throwable $e) {
            echo "<div class='error'>خطأ في إضافة batch_number_id: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Check batch_number column
    $batchNumCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'batch_number'");
    if (empty($batchNumCheck)) {
        echo "<div class='info'>جاري إضافة العمود batch_number...</div>";
        try {
            $db->execute("ALTER TABLE `return_items` ADD COLUMN `batch_number` varchar(100) DEFAULT NULL AFTER `batch_number_id`");
            echo "<div class='success'>✓ تم إضافة العمود batch_number بنجاح</div>";
        } catch (Throwable $e) {
            echo "<div class='error'>خطأ في إضافة batch_number: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // Verify final structure
    echo "<h2>البنية النهائية لجدول return_items:</h2>";
    $columns = $db->query("SHOW COLUMNS FROM return_items");
    echo "<pre>";
    foreach ($columns as $col) {
        echo htmlspecialchars($col['Field']) . " - " . htmlspecialchars($col['Type']) . "\n";
    }
    echo "</pre>";
    
    echo "<div class='success'><strong>تم الانتهاء بنجاح!</strong></div>";
    
} catch (Throwable $e) {
    echo "<div class='error'><strong>حدث خطأ:</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</div></body></html>";

