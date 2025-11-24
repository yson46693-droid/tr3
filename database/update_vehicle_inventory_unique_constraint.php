<?php
/**
 * Script: update_vehicle_inventory_unique_constraint.php
 * Purpose: تحديث قيد UNIQUE في جدول vehicle_inventory ليشمل finished_batch_id
 *          للسماح بمنتجات من نفس النوع برقم تشغيلة مختلف
 */

// تجنب مشاكل headers
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

// تحميل إعدادات قاعدة البيانات مباشرة
try {
    $configFile = __DIR__ . '/../includes/config.php';
    if (!file_exists($configFile)) {
        throw new Exception("Config file not found: $configFile");
    }
    
    // قراءة ملف الإعدادات للعثور على إعدادات قاعدة البيانات
    $configContent = file_get_contents($configFile);
    
    // استخراج إعدادات قاعدة البيانات - البحث عن define('DB_...
    preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $dbHost);
    preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $dbName);
    preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $dbUser);
    preg_match("/define\s*\(\s*['\"]DB_PASS['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $configContent, $dbPass);
    
    // إذا لم نجد define، نبحث عن الإعدادات داخل الشرط
    if (empty($dbName[1])) {
        // البحث عن DB_NAME داخل الشرط if
        if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $dbName)) {
            $dbName = $dbName[1];
        } else {
            // محاولة أخرى: البحث عن 'tr' أو اسم قاعدة البيانات مباشرة
            if (preg_match("/DB_NAME['\"].*?['\"]([^'\"]+)['\"]/", $configContent, $matches)) {
                $dbName = $matches[1];
            } else {
                $dbName = 'tr'; // افتراضي للـ localhost
            }
        }
    } else {
        $dbName = $dbName[1];
    }
    
    $dbHost = isset($dbHost[1]) && !empty($dbHost[1]) ? $dbHost[1] : 'localhost';
    $dbUser = isset($dbUser[1]) && !empty($dbUser[1]) ? $dbUser[1] : 'root';
    $dbPass = isset($dbPass[1]) ? $dbPass[1] : '';
    
    // تحديد قاعدة البيانات بناءً على localhost
    if (empty($dbName) || $dbName === 'if0_40278066_co_db') {
        // للـ localhost، استخدم 'tr'
        $dbName = 'tr';
    }
    
    // الاتصال بقاعدة البيانات مباشرة
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    
} catch (Throwable $bootstrapError) {
    if (php_sapi_name() !== 'cli' && function_exists('http_response_code')) {
        http_response_code(500);
    }
    echo "Initialization error: " . $bootstrapError->getMessage() . "\n";
    exit(1);
}

// استخدام الاتصال المباشر الموجود بالفعل

try {
    $result = $conn->query("SHOW TABLES LIKE 'vehicle_inventory'");
    if (!$result || $result->num_rows === 0) {
        echo "Table vehicle_inventory does not exist. Nothing to update.\n";
        $conn->close();
        exit(0);
    }
    $result->free();
} catch (Throwable $tableError) {
    if (php_sapi_name() !== 'cli' && function_exists('http_response_code')) {
        http_response_code(500);
    }
    echo "Failed checking table existence: " . $tableError->getMessage() . "\n";
    $conn->close();
    exit(1);
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
        $conn->close();
        exit(0);
    }

    if ($hasOldConstraint) {
        echo "Updating unique constraint from old format to new format...\n";
        
        // حذف القيد القديم
        if (isset($existingIndexes['vehicle_product_unique'])) {
            echo "Dropping old constraint: vehicle_product_unique\n";
            if (!$conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`")) {
                throw new Exception("Failed to drop vehicle_product_unique: " . $conn->error);
            }
        }
        if (isset($existingIndexes['vehicle_product'])) {
            echo "Dropping old constraint: vehicle_product\n";
            if (!$conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`")) {
                throw new Exception("Failed to drop vehicle_product: " . $conn->error);
            }
        }
        
        // إضافة القيد الجديد
        echo "Adding new constraint: vehicle_product_batch_unique (vehicle_id, product_id, finished_batch_id)\n";
        if (!$conn->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)")) {
            throw new Exception("Failed to add vehicle_product_batch_unique: " . $conn->error);
        }
        
        echo "Successfully updated unique constraint!\n";
        echo "Now products with the same vehicle_id and product_id but different finished_batch_id can be stored separately.\n";
    } else {
        // لا يوجد قيد قديم، إضافة القيد الجديد مباشرة
        echo "Adding new unique constraint: vehicle_product_batch_unique (vehicle_id, product_id, finished_batch_id)\n";
        if (!$conn->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)")) {
            throw new Exception("Failed to add vehicle_product_batch_unique: " . $conn->error);
        }
        echo "Successfully added unique constraint!\n";
    }

} catch (Throwable $updateError) {
    if (php_sapi_name() !== 'cli' && function_exists('http_response_code')) {
        http_response_code(500);
    }
    echo "Error updating constraint: " . $updateError->getMessage() . "\n";
    if (isset($conn)) {
        $conn->close();
    }
    exit(1);
}

echo "\nUpdate completed successfully!\n";
$conn->close();
exit(0);
