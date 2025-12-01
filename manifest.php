<?php
/**
 * Serve manifest.json with correct Content-Type
 * This file ensures the manifest is served without BOM or encoding issues
 */

// إزالة أي output buffer قديم
while (ob_get_level()) {
    ob_end_clean();
}

// التأكد من عدم وجود أي output قبل headers
if (ob_get_level() === 0) {
    ob_start();
}

// قراءة ملف manifest.json مباشرة
$manifestPath = __DIR__ . '/manifest.json';
if (!file_exists($manifestPath)) {
    ob_clean();
    http_response_code(404);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Manifest not found'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

$content = @file_get_contents($manifestPath);
if ($content === false) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Failed to read manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// إزالة BOM بطرق متعددة (UTF-8, UTF-16, UTF-32)
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // UTF-8 BOM
$content = preg_replace('/^\xFF\xFE/', '', $content); // UTF-16 LE BOM
$content = preg_replace('/^\xFE\xFF/', '', $content); // UTF-16 BE BOM
$content = preg_replace('/^\x00\x00\xFE\xFF/', '', $content); // UTF-32 BE BOM
$content = preg_replace('/^\xFF\xFE\x00\x00/', '', $content); // UTF-32 LE BOM

// إزالة أي مسافات أو أحرف غير مرئية في البداية والنهاية
$content = trim($content);
$content = ltrim($content);
$content = rtrim($content);

// إزالة أي أحرف غير مرئية أخرى في البداية
$content = preg_replace('/^[\x00-\x1F\x7F-\x9F]+/u', '', $content);

// التحقق من أن المحتوى JSON صحيح
$json = @json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// تصحيح المسارات في manifest.json
// تحديد base path بناءً على موقع الملف ديناميكياً
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = '';
if ($scriptPath !== '/' && $scriptPath !== '' && $scriptPath !== '.') {
    $basePath = rtrim($scriptPath, '/');
}

// تحديث المسارات في JSON
if ($json && isset($json['icons'])) {
    foreach ($json['icons'] as &$icon) {
        if (isset($icon['src']) && strpos($icon['src'], '/assets/') === 0) {
            $icon['src'] = $basePath . $icon['src'];
        }
    }
    unset($icon);
}

// تحديث shortcuts icons أيضاً
if (isset($json['shortcuts'])) {
    foreach ($json['shortcuts'] as &$shortcut) {
        if (isset($shortcut['icons'])) {
            foreach ($shortcut['icons'] as &$icon) {
                if (isset($icon['src']) && strpos($icon['src'], '/assets/') === 0) {
                    $icon['src'] = $basePath . $icon['src'];
                }
            }
            unset($icon);
        }
        if (isset($shortcut['url']) && strpos($shortcut['url'], '/') === 0 && strpos($shortcut['url'], $basePath) !== 0) {
            $shortcut['url'] = $basePath . $shortcut['url'];
        }
    }
    unset($shortcut);
}

// تحديث start_url و scope
if (isset($json['start_url']) && strpos($json['start_url'], $basePath) !== 0) {
    $json['start_url'] = $basePath . $json['start_url'];
}
if (isset($json['scope']) && strpos($json['scope'], $basePath) !== 0) {
    $json['scope'] = $basePath . $json['scope'];
}

// تحويل JSON مرة أخرى
$content = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// تنظيف output buffer قبل إرسال headers
ob_clean();

// إرسال headers - يجب أن يكون قبل أي output
if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
}

// إرسال المحتوى بدون أي output إضافي
echo $content;
ob_end_flush();
exit(0);
