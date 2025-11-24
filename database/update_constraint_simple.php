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

// تفعيل عرض الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 1);

// الاتصال بقاعدة البيانات
echo "جاري الاتصال بقاعدة البيانات...\n";
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    die("✗ خطأ في الاتصال: " . $conn->connect_error . "\n");
}

if (!$conn->set_charset("utf8mb4")) {
    echo "⚠ تحذير: فشل تعيين charset إلى utf8mb4: " . $conn->error . "\n";
}

echo "✓ تم الاتصال بقاعدة البيانات بنجاح\n\n";

// التحقق من وجود الجدول
echo "التحقق من وجود الجدول vehicle_inventory...\n";
$result = $conn->query("SHOW TABLES LIKE 'vehicle_inventory'");
if (!$result) {
    die("✗ خطأ في الاستعلام: " . $conn->error . "\n");
}
if ($result->num_rows === 0) {
    echo "✗ الجدول vehicle_inventory غير موجود. لا يوجد شيء لتحديثه.\n";
    $conn->close();
    exit(0);
}
$result->free();

echo "✓ الجدول vehicle_inventory موجود\n\n";

// الحصول على الفهارس الموجودة
echo "جاري فحص الفهارس الموجودة...\n";
$indexesResult = $conn->query("SHOW INDEXES FROM vehicle_inventory");
if (!$indexesResult) {
    die("✗ خطأ في الاستعلام: " . $conn->error . "\n");
}
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
            $errorMsg = $conn->error;
            echo "    ⚠ تحذير: " . $errorMsg . "\n";
            
            // إذا كان الخطأ بسبب قيد خارجي، نحاول حذف القيود الخارجية أولاً
            if (strpos($errorMsg, 'foreign key') !== false || strpos($errorMsg, 'needed in') !== false) {
                echo "    - محاولة حذف القيود الخارجية المرتبطة...\n";
                
                // البحث عن جميع قيود foreign key في جدول vehicle_inventory
                $fkResult = $conn->query("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.TABLE_CONSTRAINTS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'vehicle_inventory' 
                    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                ");
                
                $droppedFKs = [];
                if ($fkResult instanceof mysqli_result) {
                    while ($fk = $fkResult->fetch_assoc()) {
                        if (!empty($fk['CONSTRAINT_NAME'])) {
                            $fkName = $fk['CONSTRAINT_NAME'];
                            echo "      - حذف القيد الخارجي {$fkName}...\n";
                            if ($conn->query("ALTER TABLE vehicle_inventory DROP FOREIGN KEY `{$fkName}`")) {
                                $droppedFKs[] = $fkName;
                                echo "        ✓ تم الحذف\n";
                            } else {
                                echo "        ⚠ فشل الحذف: " . $conn->error . "\n";
                            }
                        }
                    }
                    $fkResult->free();
                }
                
                // البحث عن قيود foreign key في جداول أخرى تشير إلى vehicle_inventory
                $fkResult2 = $conn->query("
                    SELECT DISTINCT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME
                    FROM information_schema.KEY_COLUMN_USAGE kcu
                    INNER JOIN information_schema.TABLE_CONSTRAINTS tc 
                        ON kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME 
                        AND kcu.TABLE_SCHEMA = tc.TABLE_SCHEMA
                        AND kcu.TABLE_NAME = tc.TABLE_NAME
                    WHERE kcu.TABLE_SCHEMA = DATABASE()
                    AND kcu.REFERENCED_TABLE_NAME = 'vehicle_inventory'
                    AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
                    AND (kcu.REFERENCED_COLUMN_NAME = 'vehicle_id' OR kcu.REFERENCED_COLUMN_NAME = 'product_id')
                ");
                
                if ($fkResult2 instanceof mysqli_result) {
                    while ($fk = $fkResult2->fetch_assoc()) {
                        if (!empty($fk['CONSTRAINT_NAME']) && !empty($fk['TABLE_NAME'])) {
                            $fkName = $fk['CONSTRAINT_NAME'];
                            $tableName = $fk['TABLE_NAME'];
                            echo "      - حذف القيد الخارجي {$fkName} من جدول {$tableName}...\n";
                            if ($conn->query("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$fkName}`")) {
                                $droppedFKs[] = $fkName . ' (من ' . $tableName . ')';
                                echo "        ✓ تم الحذف\n";
                            } else {
                                echo "        ⚠ فشل الحذف: " . $conn->error . "\n";
                            }
                        }
                    }
                    $fkResult2->free();
                }
                
                // محاولة حذف القيد الفريد مرة أخرى
                if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`")) {
                    echo "    ✓ تم حذف vehicle_product_unique بعد حذف القيود الخارجية\n";
                    
                    if (!empty($droppedFKs)) {
                        echo "    - ملاحظة: تم حذف " . count($droppedFKs) . " قيد خارجي.\n";
                        echo "      يمكن إعادة إنشائها لاحقاً إذا لزم الأمر.\n";
                    }
                } else {
                    echo "    ✗ فشل حذف vehicle_product_unique حتى بعد حذف القيود الخارجية: " . $conn->error . "\n";
                    echo "    ⚠ قد تحتاج إلى حذف القيود الخارجية يدوياً\n";
                }
            } else {
                echo "    ✗ خطأ غير متوقع: " . $errorMsg . "\n";
            }
        }
    }
    
    if (isset($existingIndexes['vehicle_product'])) {
        echo "  - حذف vehicle_product...\n";
        if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`")) {
            echo "    ✓ تم الحذف بنجاح\n";
        } else {
            echo "    ⚠ تحذير: " . $conn->error . "\n";
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

