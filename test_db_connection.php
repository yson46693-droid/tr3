<?php
/**
 * ملف اختبار الاتصال بقاعدة البيانات
 * استخدم هذا الملف للتحقق من أن الاتصال يعمل
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(30);

echo "<h1>اختبار الاتصال بقاعدة البيانات</h1>";
echo "<pre>";

try {
    require_once __DIR__ . '/includes/config.php';
    
    echo "✓ تم تحميل config.php\n";
    echo "DB_HOST: " . DB_HOST . "\n";
    echo "DB_NAME: " . DB_NAME . "\n";
    echo "DB_USER: " . DB_USER . "\n";
    echo "DB_PORT: " . DB_PORT . "\n\n";
    
    echo "محاولة الاتصال...\n";
    $startTime = microtime(true);
    
    $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    $connectionTime = microtime(true) - $startTime;
    echo "✓ تم الاتصال بنجاح في " . round($connectionTime, 3) . " ثانية\n\n";
    
    if ($connection->connect_error) {
        throw new Exception("Connection failed: " . $connection->connect_error);
    }
    
    echo "✓ الاتصال ناجح\n";
    echo "✓ Server info: " . $connection->server_info . "\n\n";
    
    // اختبار استعلام بسيط
    echo "اختبار استعلام بسيط...\n";
    $result = $connection->query("SELECT 1 as test");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✓ الاستعلام نجح: " . $row['test'] . "\n\n";
    }
    
    // التحقق من وجود جدول customers
    echo "التحقق من وجود جدول customers...\n";
    $tableCheck = $connection->query("SHOW TABLES LIKE 'customers'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        echo "✓ جدول customers موجود\n";
        
        // التحقق من الأعمدة
        echo "\nالتحقق من الأعمدة...\n";
        $columns = $connection->query("SHOW COLUMNS FROM customers");
        $columnNames = [];
        while ($col = $columns->fetch_assoc()) {
            $columnNames[] = $col['Field'];
        }
        
        $neededColumns = ['rep_id', 'created_from_pos', 'created_by_admin'];
        foreach ($neededColumns as $col) {
            if (in_array($col, $columnNames)) {
                echo "✓ العمود '$col' موجود\n";
            } else {
                echo "✗ العمود '$col' غير موجود\n";
            }
        }
    } else {
        echo "✗ جدول customers غير موجود\n";
    }
    
    $connection->close();
    echo "\n✓ تم إغلاق الاتصال بنجاح\n";
    
} catch (Exception $e) {
    echo "\n✗ خطأ: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>

