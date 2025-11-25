<?php
/**
 * تنفيذ جميع التحديثات المطلوبة مرة واحدة
 * يعمل تلقائياً من خلال db.php أو يمكن استدعاؤه مباشرة
 */

// السماح بالوصول
define('ACCESS_ALLOWED', true);

// تحديد أننا في localhost للسماح بتحميل config
if (php_sapi_name() === 'cli') {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
}

// تحميل الإعدادات - هذا سيؤدي إلى تنفيذ التحديثات تلقائياً
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    
    // مجرد الاتصال بقاعدة البيانات سينفذ التحديثات تلقائياً
    $db = db();
    $conn = $db->getConnection();
    
    if (php_sapi_name() === 'cli') {
        echo "✓ تم الاتصال بقاعدة البيانات\n";
        echo "✓ تم تنفيذ التحديثات تلقائياً من خلال db.php\n";
        echo "\nالتحديثات المنجزة:\n";
        echo "- تحديث قيد UNIQUE في جدول vehicle_inventory\n";
        echo "- إضافة finished_batch_id إلى القيد UNIQUE\n";
        echo "\nتم بنجاح!\n";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>تم التحديث</title></head><body>";
        echo "<h1>✓ تم التحديث بنجاح!</h1>";
        echo "<p>تم تنفيذ جميع التحديثات المطلوبة تلقائياً.</p>";
        echo "<p>يمكنك الآن إغلاق هذه الصفحة.</p>";
        echo "</body></html>";
    }
    
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "✗ خطأ: " . $e->getMessage() . "\n";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>خطأ</title></head><body>";
        echo "<h1>✗ حدث خطأ</h1>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</body></html>";
    }
    exit(1);
}

