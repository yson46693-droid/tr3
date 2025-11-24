<?php
/**
 * Script: update_vehicle_inventory_unique_constraint.php
 * Purpose: تحديث قيد UNIQUE في جدول vehicle_inventory ليشمل finished_batch_id
 *          للسماح بمنتجات من نفس النوع برقم تشغيلة مختلف
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: text/plain; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $bootstrapError) {
    http_response_code(500);
    echo "Initialization error: " . $bootstrapError->getMessage() . "\n";
    exit;
}

try {
    $db = db();
    $conn = $db->getConnection();
} catch (Throwable $connectionError) {
    http_response_code(500);
    echo "Database connection error: " . $connectionError->getMessage() . "\n";
    exit;
}

try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
    if (empty($tableExists)) {
        echo "Table vehicle_inventory does not exist. Nothing to update.\n";
        exit;
    }
} catch (Throwable $tableError) {
    http_response_code(500);
    echo "Failed checking table existence: " . $tableError->getMessage() . "\n";
    exit;
}

try {
    // الحصول على جميع الفهارس الموجودة
    $indexesResult = $conn->query("SHOW INDEXES FROM vehicle_inventory");
    $existingIndexes = [];
    if ($indexesResult instanceof mysqli_result) {
        while ($index = $indexesResult->fetch_assoc()) {
            if (!empty($index['Key_name'])) {
                $existingIndexes[strtolower($index['Key_name'])] = true;
            }
        }
        $indexesResult->free();
    }

    $hasOldConstraint = isset($existingIndexes['vehicle_product_unique']) || isset($existingIndexes['vehicle_product']);
    $hasNewConstraint = isset($existingIndexes['vehicle_product_batch_unique']);

    if ($hasNewConstraint) {
        echo "The new unique constraint (vehicle_product_batch_unique) already exists. No update needed.\n";
        exit;
    }

    if ($hasOldConstraint) {
        echo "Updating unique constraint from old format to new format...\n";
        
        // حذف القيد القديم
        if (isset($existingIndexes['vehicle_product_unique'])) {
            echo "Dropping old constraint: vehicle_product_unique\n";
            $conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`");
        }
        if (isset($existingIndexes['vehicle_product'])) {
            echo "Dropping old constraint: vehicle_product\n";
            $conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`");
        }
        
        // إضافة القيد الجديد
        echo "Adding new constraint: vehicle_product_batch_unique (vehicle_id, product_id, finished_batch_id)\n";
        $conn->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
        
        echo "Successfully updated unique constraint!\n";
        echo "Now products with the same vehicle_id and product_id but different finished_batch_id can be stored separately.\n";
    } else {
        // لا يوجد قيد قديم، إضافة القيد الجديد مباشرة
        echo "Adding new unique constraint: vehicle_product_batch_unique (vehicle_id, product_id, finished_batch_id)\n";
        $conn->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
        echo "Successfully added unique constraint!\n";
    }

} catch (Throwable $updateError) {
    http_response_code(500);
    echo "Error updating constraint: " . $updateError->getMessage() . "\n";
    exit;
}

echo "\nUpdate completed successfully!\n";
