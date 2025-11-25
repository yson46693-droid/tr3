<?php
/**
 * Script: run_vehicle_inventory_upgrade.php
 * Purpose: Ensure vehicle_inventory table has extended product metadata columns.
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: text/plain; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $bootstrapError) {
    http_response_code(500);
    echo "Initialization error: " . $bootstrapError->getMessage();
    exit;
}

try {
    $db = db();
} catch (Throwable $connectionError) {
    http_response_code(500);
    echo "Database connection error: " . $connectionError->getMessage();
    exit;
}

try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
    if (empty($tableExists)) {
        echo "Table vehicle_inventory does not exist. Nothing to upgrade.";
        exit;
    }
} catch (Throwable $tableError) {
    http_response_code(500);
    echo "Failed checking table existence: " . $tableError->getMessage();
    exit;
}

try {
    $columns = $db->query("SHOW COLUMNS FROM vehicle_inventory") ?? [];
} catch (Throwable $columnsError) {
    $columns = [];
}

$existing = [];
foreach ($columns as $columnInfo) {
    if (!empty($columnInfo['Field'])) {
        $existing[strtolower($columnInfo['Field'])] = true;
    }
}

$alterParts = [];

if (!isset($existing['warehouse_id'])) {
    $alterParts[] = "ADD COLUMN `warehouse_id` int(11) DEFAULT NULL COMMENT 'مخزن السيارة' AFTER `vehicle_id`";
}
if (!isset($existing['product_name'])) {
    $alterParts[] = "ADD COLUMN `product_name` varchar(255) DEFAULT NULL AFTER `product_id`";
}
if (!isset($existing['product_category'])) {
    $alterParts[] = "ADD COLUMN `product_category` varchar(100) DEFAULT NULL AFTER `product_name`";
}
if (!isset($existing['product_unit'])) {
    $alterParts[] = "ADD COLUMN `product_unit` varchar(50) DEFAULT NULL AFTER `product_category`";
}
if (!isset($existing['product_unit_price'])) {
    $alterParts[] = "ADD COLUMN `product_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit`";
}
if (!isset($existing['product_snapshot'])) {
    $alterParts[] = "ADD COLUMN `product_snapshot` longtext DEFAULT NULL AFTER `product_unit_price`";
}
if (!isset($existing['manager_unit_price'])) {
    $alterParts[] = "ADD COLUMN `manager_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit_price`";
}
if (!isset($existing['finished_batch_id'])) {
    $alterParts[] = "ADD COLUMN `finished_batch_id` int(11) DEFAULT NULL AFTER `manager_unit_price`";
}
if (!isset($existing['finished_batch_number'])) {
    $alterParts[] = "ADD COLUMN `finished_batch_number` varchar(100) DEFAULT NULL AFTER `finished_batch_id`";
}
if (!isset($existing['finished_production_date'])) {
    $alterParts[] = "ADD COLUMN `finished_production_date` date DEFAULT NULL AFTER `finished_batch_number`";
}
if (!isset($existing['finished_quantity_produced'])) {
    $alterParts[] = "ADD COLUMN `finished_quantity_produced` decimal(12,2) DEFAULT NULL AFTER `finished_production_date`";
}
if (!isset($existing['finished_workers'])) {
    $alterParts[] = "ADD COLUMN `finished_workers` text DEFAULT NULL AFTER `finished_quantity_produced`";
}
if (!isset($existing['last_updated_at'])) {
    $alterParts[] = "ADD COLUMN `last_updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `last_updated_by`";
}
if (!isset($existing['created_at'])) {
    $alterParts[] = "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_updated_at`";
}

if ($alterParts) {
    try {
        $db->execute("ALTER TABLE vehicle_inventory " . implode(', ', $alterParts));
        echo "Columns added/updated successfully.\n";
    } catch (Throwable $alterError) {
        http_response_code(500);
        echo "Failed adding columns: " . $alterError->getMessage();
        exit;
    }
} else {
    echo "All columns already exist.\n";
}

echo "Upgrade finished.\n";

// mark a flag in system settings
try {
    $db->execute(
        "INSERT INTO system_settings (`key`, `value`, updated_at) VALUES ('vehicle_inventory_upgraded', '1', NOW())
         ON DUPLICATE KEY UPDATE `value` = '1', updated_at = NOW()"
    );
    echo "Flag value stored in system_settings.\n";
} catch (Throwable $flagError) {
    echo "Warning: failed saving flag: " . $flagError->getMessage() . "\n";
}
