<?php
/**
 * سكريبت شامل لتحديث جميع الجداول المطلوبة
 * يعمل من CLI أو من المتصفح
 */

// السماح بالوصول من CLI أو localhost
$isCLI = php_sapi_name() === 'cli';
$isLocalhost = false;

if (!$isCLI && isset($_SERVER['HTTP_HOST'])) {
    $host = $_SERVER['HTTP_HOST'];
    $isLocalhost = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
}

// في CLI أو localhost فقط
if (!$isCLI && !$isLocalhost) {
    die('Access denied');
}

// تحديد أننا في وضع CLI أو localhost
if ($isCLI) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
    echo "=== تحديث قاعدة البيانات ===\n\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>تحديث قاعدة البيانات</title></head><body><pre>";
}

// دالة لإخراج الرسائل
function msg($message, $type = 'info') {
    global $isCLI;
    $prefix = '';
    if ($type === 'success') $prefix = '✓ ';
    if ($type === 'error') $prefix = '✗ ';
    if ($type === 'info') $prefix = '→ ';
    
    if ($isCLI) {
        echo $prefix . $message . "\n";
    } else {
        echo htmlspecialchars($prefix . $message) . "\n";
    }
}

try {
    // تحميل الإعدادات
    define('ACCESS_ALLOWED', true);
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    
    $db = db();
    $conn = $db->getConnection();
    
    msg('تم الاتصال بقاعدة البيانات', 'success');
    
    // ===== تحديث قيد vehicle_inventory =====
    msg('', 'info');
    msg('=== تحديث قيد UNIQUE في جدول vehicle_inventory ===', 'info');
    
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
    if (empty($tableExists)) {
        msg('الجدول vehicle_inventory غير موجود - يتم تخطيه', 'info');
    } else {
        msg('الجدول vehicle_inventory موجود', 'success');
        
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
        
        if ($hasNewConstraint) {
            msg('القيد الجديد موجود بالفعل - لا حاجة للتحديث', 'success');
        } else {
            if ($hasOldConstraint) {
                msg('جاري حذف القيد القديم...', 'info');
                
                if (isset($existingIndexes['vehicle_product_unique'])) {
                    if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`")) {
                        msg('تم حذف vehicle_product_unique', 'success');
                    }
                }
                
                if (isset($existingIndexes['vehicle_product'])) {
                    if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`")) {
                        msg('تم حذف vehicle_product', 'success');
                    }
                }
            }
            
            // إضافة القيد الجديد
            msg('جاري إضافة القيد الجديد...', 'info');
            $sql = "ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)";
            
            if ($conn->query($sql)) {
                msg('تم إضافة القيد الجديد بنجاح!', 'success');
                msg('الآن يمكن تخزين منتجات من نفس النوع برقم تشغيلة مختلف', 'info');
            } else {
                msg('تحذير: ' . $conn->error, 'error');
            }
        }
    }
    
    // ===== تحديثات أخرى يمكن إضافتها هنا =====
    
    msg('', 'info');
    msg('=== اكتملت جميع التحديثات ===', 'success');
    
} catch (Exception $e) {
    msg('خطأ: ' . $e->getMessage(), 'error');
    if ($isCLI) {
        exit(1);
    }
}

if (!$isCLI) {
    echo "</pre></body></html>";
} else {
    echo "\n";
}

