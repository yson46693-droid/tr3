<?php
/**
 * API: التحقق من وجود تحديثات في الموقع
 */

header('Content-Type: application/json; charset=utf-8');

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';

// الحصول على رقم الإصدار الحالي
$currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
$storedVersion = isset($_COOKIE['app_current_version']) ? $_COOKIE['app_current_version'] : null;

// احتساب الإصدار المقترح بناءً على الفرق
if ($storedVersion && $storedVersion === $currentVersion) {
    // لا تغييرات على الإصدار
} elseif ($storedVersion) {
    // هناك اختلاف بين المخزن والحالي، الترميز تم في الواجهة الأمامية
} else {
    // حفظ الإصدار لأول مرة
    setcookie('app_current_version', $currentVersion, time() + (365 * 24 * 60 * 60), '/');
}

// حساب hash للملفات الرئيسية للتحقق من التغييرات
$mainFiles = [
    __DIR__ . '/../index.php',
    __DIR__ . '/../templates/header.php',
    __DIR__ . '/../templates/footer.php',
    __DIR__ . '/../includes/config.php'
];

$fileHashes = [];
$lastModified = 0;

foreach ($mainFiles as $file) {
    if (file_exists($file)) {
        $fileHashes[] = md5_file($file);
        $mtime = filemtime($file);
        if ($mtime > $lastModified) {
            $lastModified = $mtime;
        }
    }
}

// إنشاء hash فريد بناءً على جميع الملفات
$contentHash = md5(implode('', $fileHashes) . $currentVersion);

// إرجاع المعلومات
echo json_encode([
    'success' => true,
    'version' => $currentVersion,
    'last_modified' => $lastModified,
    'content_hash' => $contentHash,
    'timestamp' => time()
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

