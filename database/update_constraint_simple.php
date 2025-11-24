<?php
/**
 * Script بسيط لتحديث قيد UNIQUE في جدول vehicle_inventory
 * يمكن تعديل إعدادات قاعدة البيانات في أول السكريبت
 */

// إعدادات قاعدة البيانات - عدّل هذه القيم حسب بيئتك
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'tr'; // اسم قاعدة البيانات

echo "=== تحديث قيد UNIQUE في جدول vehicle_inventory ===\n\n";

// الاتصال بقاعدة البيانات
$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    die("خطأ في الاتصال: " . $conn->connect_error . "\n");
}

$conn->set_charset("utf8mb4");

echo "✓ تم الاتصال بقاعدة البيانات بنجاح\n\n";

// التحقق من وجود الجدول
$result = $conn->query("SHOW TABLES LIKE 'vehicle_inventory'");
if (!$result || $result->num_rows === 0) {
    echo "✗ الجدول vehicle_inventory غير موجود. لا يوجد شيء لتحديثه.\n";
    $conn->close();
    exit(0);
}
$result->free();

echo "✓ الجدول vehicle_inventory موجود\n\n";

// الحصول على الفهارس الموجودة
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

// التحقق من القيد الجديد
if ($hasNewConstraint) {
    echo "✓ القيد الجديد (vehicle_product_batch_unique) موجود بالفعل. لا حاجة للتحديث.\n";
    $conn->close();
    exit(0);
}

echo "الحالة الحالية:\n";
echo "  - القيد القديم موجود: " . ($hasOldConstraint ? "نعم" : "لا") . "\n";
echo "  - القيد الجديد موجود: لا\n\n";

// حذف القيد القديم
if ($hasOldConstraint) {
    echo "جاري حذف القيد القديم...\n";
    
    if (isset($existingIndexes['vehicle_product_unique'])) {
        echo "  - حذف vehicle_product_unique...\n";
        if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`")) {
            echo "    ✓ تم الحذف بنجاح\n";
        } else {
            echo "    ✗ خطأ: " . $conn->error . "\n";
        }
    }
    
    if (isset($existingIndexes['vehicle_product'])) {
        echo "  - حذف vehicle_product...\n";
        if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`")) {
            echo "    ✓ تم الحذف بنجاح\n";
        } else {
            echo "    ✗ خطأ: " . $conn->error . "\n";
        }
    }
    
    echo "\n";
}

// إضافة القيد الجديد
echo "جاري إضافة القيد الجديد (vehicle_product_batch_unique)...\n";
$sql = "ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)";

if ($conn->query($sql)) {
    echo "✓ تم إضافة القيد الجديد بنجاح!\n\n";
    echo "النتيجة:\n";
    echo "  - الآن يمكن تخزين منتجات من نفس النوع (product_id) برقم تشغيلة مختلف (finished_batch_id)\n";
    echo "  - في نفس السيارة (vehicle_id)\n";
    echo "  - كل رقم تشغيلة سيتم تخزينه في سجل منفصل\n";
} else {
    echo "✗ خطأ في إضافة القيد: " . $conn->error . "\n";
    $conn->close();
    exit(1);
}

// التحقق من القيد الجديد
$verifyResult = $conn->query("SHOW INDEXES FROM vehicle_inventory WHERE Key_name = 'vehicle_product_batch_unique'");
if ($verifyResult && $verifyResult->num_rows > 0) {
    echo "\n✓ تم التحقق: القيد الجديد موجود ويعمل بشكل صحيح\n";
    $verifyResult->free();
}

$conn->close();
echo "\n=== اكتمل التحديث بنجاح ===\n";

