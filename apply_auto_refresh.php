<?php
/**
 * سكريبت لتطبيق ريفريش تلقائي على جميع ملفات المشروع
 * يضيف id و data-auto-refresh للرسائل ويضيف JavaScript في النهاية
 */

$baseDir = __DIR__;
$modulesDir = $baseDir . '/modules';

// نمط البحث عن رسائل الخطأ والنجاح
$errorPattern = '/<\?php\s+if\s*\(\s*\$error\s*\)\s*:\s*\?>\s*<div\s+class="alert\s+alert-danger[^"]*">/i';
$successPattern = '/<\?php\s+if\s*\(\s*\$success\s*\)\s*:\s*\?>\s*<div\s+class="alert\s+alert-success[^"]*">/i';

// نمط البحث عن نهاية script
$scriptEndPattern = '/<\/script>\s*$/m';

// دالة لتطبيق التعديل على ملف
function applyAutoRefresh($filePath) {
    $content = file_get_contents($filePath);
    $modified = false;
    
    // إضافة id و data-auto-refresh لرسالة الخطأ
    if (preg_match('/<\?php\s+if\s*\(\s*\$error\s*\)\s*:\s*\?>\s*<div\s+class="alert\s+alert-danger\s+alert-dismissible\s+fade\s+show">/i', $content) 
        && !preg_match('/id="errorAlert"/i', $content)) {
        $content = preg_replace(
            '/(<\?php\s+if\s*\(\s*\$error\s*\)\s*:\s*\?>\s*<div\s+class="alert\s+alert-danger\s+alert-dismissible\s+fade\s+show")>/i',
            '$1 id="errorAlert" data-auto-refresh="true"',
            $content
        );
        $modified = true;
    }
    
    // إضافة id و data-auto-refresh لرسالة النجاح
    if (preg_match('/<\?php\s+if\s*\(\s*\$success\s*\)\s*:\s*\?>\s*<div\s+class="alert\s+alert-success\s+alert-dismissible\s+fade\s+show"/i', $content)
        && !preg_match('/id="successAlert"/i', $content)) {
        $content = preg_replace(
            '/(<\?php\s+if\s*\(\s*\$success\s*\)\s*:\s*\?>\s*<div\s+class="alert\s+alert-success\s+alert-dismissible\s+fade\s+show")/i',
            '$1 id="successAlert" data-auto-refresh="true"',
            $content
        );
        $modified = true;
    }
    
    // إضافة JavaScript في النهاية إذا لم يكن موجوداً
    $jsCode = '
<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById(\'successAlert\');
    const errorAlert = document.getElementById(\'errorAlert\');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === \'true\') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete(\'success\');
            currentUrl.searchParams.delete(\'error\');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>';
    
    if ($modified && !preg_match('/إعادة تحميل الصفحة تلقائياً/i', $content)) {
        // إضافة JavaScript قبل آخر </script> أو في النهاية
        if (preg_match('/<\/script>\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1] + strlen($matches[0][0]);
            $content = substr_replace($content, $jsCode . "\n", $pos, 0);
        } else {
            $content .= $jsCode;
        }
        $modified = true;
    }
    
    if ($modified) {
        file_put_contents($filePath, $content);
        return true;
    }
    
    return false;
}

// البحث عن جميع ملفات PHP في modules
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modulesDir),
    RecursiveIteratorIterator::SELF_FIRST
);

$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}

$modifiedCount = 0;
foreach ($files as $file) {
    if (applyAutoRefresh($file)) {
        $modifiedCount++;
        echo "تم تعديل: " . basename($file) . "\n";
    }
}

echo "\nتم تعديل $modifiedCount ملف.\n";
?>

