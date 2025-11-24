<?php
/**
 * سكريبت لتحديث قيد UNIQUE في جدول vehicle_inventory
 * يعمل من CLI أو من المتصفح
 */

// السماح بالوصول من CLI أو localhost أو مستخدم مسجل دخوله
$isCLI = php_sapi_name() === 'cli';
$isLocalhost = false;
$isAuthenticated = false;

if (!$isCLI && isset($_SERVER['HTTP_HOST'])) {
    $host = $_SERVER['HTTP_HOST'];
    $isLocalhost = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
    
    // محاولة التحقق من المستخدم المسجل دخوله
    if (!$isLocalhost) {
        // بدء الجلسة إذا لم تكن بدأت
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            // تحميل ملفات التحقق من المستخدم
            if (!defined('ACCESS_ALLOWED')) {
                define('ACCESS_ALLOWED', true);
            }
            try {
                if (!function_exists('getCurrentUser')) {
                    require_once __DIR__ . '/../includes/config.php';
                    require_once __DIR__ . '/../includes/auth.php';
                }
                $currentUser = getCurrentUser();
                if ($currentUser && isset($currentUser['id'])) {
                    $isAuthenticated = true;
                }
            } catch (Throwable $e) {
                // إذا فشل تحميل ملفات التحقق، نعتبر أن المستخدم غير مسجل دخوله
                $isAuthenticated = false;
            }
        }
    }
}

if (!$isCLI && !$isLocalhost && !$isAuthenticated) {
    die('Access denied. يجب أن تكون مسجل دخول أو الوصول من localhost.');
}

if (!$isCLI) {
    header('Content-Type: text/html; charset=utf-8');
} else {
    // وضع CLI - إخراج نصي بسيط
    echo "=== تحديث قيد UNIQUE في جدول vehicle_inventory ===\n\n";
}


// دالة لإخراج الرسائل
function output($message, $type = 'info') {
    global $isCLI;
    if ($isCLI) {
        $prefix = '';
        if ($type === 'success') $prefix = '✓ ';
        if ($type === 'error') $prefix = '✗ ';
        echo $prefix . $message . "\n";
    } else {
        $class = $type;
        echo '<div class="message ' . $class . '">' . htmlspecialchars($message) . '</div>';
    }
}

if (!$isCLI) {
    ?>
    <!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>تحديث قيد UNIQUE</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 800px;
                margin: 50px auto;
                padding: 20px;
                background: #f5f5f5;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 {
                color: #333;
                border-bottom: 3px solid #007bff;
                padding-bottom: 10px;
            }
            .message {
                padding: 15px;
                margin: 15px 0;
                border-radius: 5px;
            }
            .success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .info {
                background: #d1ecf1;
                color: #0c5460;
                border: 1px solid #bee5eb;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔧 تحديث قيد UNIQUE في جدول vehicle_inventory</h1>
    <?php
}

try {
    // تحديد أننا في وضع CLI أو localhost للسماح بتحميل config
    if ($isCLI) {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
    }
    
    // تحميل الإعدادات (إذا لم يتم تحميلها مسبقاً)
    if (!defined('ACCESS_ALLOWED')) {
        define('ACCESS_ALLOWED', true);
    }
    
    if (!function_exists('db')) {
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
    }
    
    $db = db();
    $conn = $db->getConnection();
    
    output('تم الاتصال بقاعدة البيانات بنجاح', 'success');
    
    // التحقق من وجود الجدول
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'vehicle_inventory'");
    if (empty($tableExists)) {
        output('الجدول vehicle_inventory غير موجود', 'error');
        exit(1);
    }
    
    output('الجدول vehicle_inventory موجود', 'success');
    
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
    
    output('الحالة الحالية:', 'info');
    output('  - القيد القديم موجود: ' . ($hasOldConstraint ? 'نعم' : 'لا'), 'info');
    output('  - القيد الجديد موجود: ' . ($hasNewConstraint ? 'نعم' : 'لا'), 'info');
    
    if ($hasNewConstraint) {
        output('القيد الجديد موجود بالفعل. لا حاجة للتحديث.', 'success');
        exit(0);
    }
    
    // تنفيذ التحديث
    if ($hasOldConstraint) {
        output('جاري حذف القيد القديم...', 'info');
        
        if (isset($existingIndexes['vehicle_product_unique'])) {
            if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`")) {
                output('تم حذف vehicle_product_unique', 'success');
            } else {
                throw new Exception("فشل حذف vehicle_product_unique: " . $conn->error);
            }
        }
        
        if (isset($existingIndexes['vehicle_product'])) {
            if ($conn->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`")) {
                output('تم حذف vehicle_product', 'success');
            } else {
                throw new Exception("فشل حذف vehicle_product: " . $conn->error);
            }
        }
    }
    
    // إضافة القيد الجديد
    output('جاري إضافة القيد الجديد...', 'info');
    $sql = "ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)";
    
    if ($conn->query($sql)) {
        output('تم التحديث بنجاح!', 'success');
        output('الآن يمكن تخزين منتجات من نفس النوع برقم تشغيلة مختلف في نفس السيارة', 'info');
        output('كل رقم تشغيلة سيتم تخزينه في سجل منفصل', 'info');
    } else {
        throw new Exception("فشل إضافة القيد الجديد: " . $conn->error);
    }
    
    // التحقق
    $verifyResult = $conn->query("SHOW INDEXES FROM vehicle_inventory WHERE Key_name = 'vehicle_product_batch_unique'");
    if ($verifyResult && $verifyResult->num_rows > 0) {
        output('تم التحقق: القيد الجديد موجود ويعمل بشكل صحيح', 'success');
        $verifyResult->free();
    }
    
} catch (Exception $e) {
    output('خطأ: ' . $e->getMessage(), 'error');
    exit(1);
}

if (!$isCLI) {
    ?>
            <div class="message info" style="margin-top: 30px;">
                <strong>ملاحظة:</strong> يمكنك الآن إغلاق هذه الصفحة. التحديث تم بنجاح.
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    echo "\n=== اكتمل التحديث بنجاح ===\n";
}
