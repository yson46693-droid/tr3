<?php
/**
 * Helper functions for path management
 */

// السماح بالتحميل من ملفات أخرى (مثل auth.php) حتى لو لم يكن ACCESS_ALLOWED معرف
// هذا ضروري لأن auth.php قد يحتاج تحميل path_helper في حالات الطوارئ
// عندما تكون الـ headers قد أُرسلت بالفعل

/**
 * Get base URL path
 */
function getBasePath() {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Normalize paths
    $scriptName = str_replace('\\', '/', $scriptName);
    $requestUri = str_replace('\\', '/', $requestUri);
    
    // استخراج base path من SCRIPT_NAME
    // المسار سيكون ديناميكياً بناءً على موقع الملف
    $parts = explode('/', trim($scriptName, '/'));
    
    // إزالة 'dashboard', 'modules', وملفات PHP من المسار
    $baseParts = [];
    foreach ($parts as $part) {
        // توقف عند الوصول إلى مجلدات خاصة أو ملفات PHP
        if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
            break;
        }
        $baseParts[] = $part;
    }
    
    // إذا كان هناك base path، ارجعه
    if (!empty($baseParts)) {
        return '/' . implode('/', $baseParts);
    }
    
    // محاولة اكتشاف المسار من REQUEST_URI
    $parsedUri = parse_url($requestUri);
    $path = $parsedUri['path'] ?? '';
    $path = str_replace('\\', '/', $path);
    
    // استخراج base path من REQUEST_URI
    $pathParts = explode('/', trim($path, '/'));
    $baseParts = [];
    foreach ($pathParts as $part) {
        // توقف عند الوصول إلى مجلدات خاصة أو ملفات PHP
        if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
            break;
        }
        $baseParts[] = $part;
    }
    
    // إذا كان هناك base path، ارجعه
    if (!empty($baseParts)) {
        return '/' . implode('/', $baseParts);
    }
    
    // إذا كان المسار في الجذر، ارجع string فارغ
    if ($path === '/' || $path === '') {
        return '';
    }
    
    // Fallback: إذا كان dirname فقط
    $scriptDir = dirname($scriptName);
    
    // إزالة /dashboard و /modules من المسار
    while (strpos($scriptDir, '/dashboard') !== false || strpos($scriptDir, '/modules') !== false) {
        $scriptDir = dirname($scriptDir);
    }
    
    // Normalize path separators
    $scriptDir = str_replace('\\', '/', $scriptDir);
    
    // If in root, return empty string
    if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
        return '';
    }
    
    // التأكد من أن المسار يبدأ بـ /
    if (strpos($scriptDir, '/') !== 0) {
        $scriptDir = '/' . $scriptDir;
    }
    
    return $scriptDir;
}

/**
 * Get dashboard URL
 * دالة محسّنة لضمان عدم تكرار خطأ DNS_PROBE_FINISHED_NXDOMAIN
 */
function getDashboardUrl($role = null) {
    $base = getBasePath();
    
    // التأكد من أن base يبدأ بـ / أو يكون فارغاً
    if (!empty($base) && strpos($base, '/') !== 0) {
        $base = '/' . $base;
    }
    
    // إزالة / من النهاية إذا كان موجوداً
    $base = rtrim($base, '/');
    
    // بناء المسار - دائماً يبدأ بـ /
    if ($role) {
        $url = ($base ? $base : '') . '/dashboard/' . $role . '.php';
    } else {
        $url = ($base ? $base : '') . '/dashboard/';
    }
    
    // تنظيف شامل للمسار
    // 1. إزالة أي بروتوكول (http://, https://, //)
    $url = preg_replace('/^https?:\/\//', '', $url);
    $url = preg_replace('/^\/\//', '/', $url);
    
    // 2. التأكد من أن المسار يبدأ بـ /
    if (strpos($url, '/') !== 0) {
        $url = '/' . $url;
    }
    
    // 3. تنظيف المسار (إزالة // المكررة)
    $url = preg_replace('/\/+/', '/', $url);
    
    // 4. إزالة أي hostname إذا كان موجوداً (مثل dashboard.com أو www.dashboard)
    // إذا كان المسار يحتوي على نقطة بعد / مباشرة، قد يكون hostname
    if (preg_match('/^\/[^\/]+\.[a-z]/i', $url)) {
        // إذا كان يبدو كـ hostname، استخراج المسار فقط
        $parts = explode('/', $url);
        // البحث عن 'dashboard' في المسار
        $dashboardIndex = array_search('dashboard', $parts);
        if ($dashboardIndex !== false) {
            $url = '/' . implode('/', array_slice($parts, $dashboardIndex));
        } else {
            // إذا لم يكن هناك dashboard، استخدم المسار الافتراضي
            $url = '/dashboard/' . ($role ? $role . '.php' : '');
        }
    }
    
    // 5. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
    if (strpos($url, '/dashboard') === false) {
        $url = '/dashboard/' . ($role ? $role . '.php' : '');
    }
    
    // 6. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        $parsed = parse_url($url);
        $url = $parsed['path'] ?? '/dashboard/' . ($role ? $role . '.php' : '');
    }
    
    // 7. التحقق النهائي: التأكد من أن المسار يبدأ بـ / ولا يحتوي على بروتوكول
    if (strpos($url, '/') !== 0) {
        $url = '/' . $url;
    }
    
    // 8. تنظيف نهائي: إزالة أي مسافات أو أحرف خاصة
    $url = trim($url);
    $url = preg_replace('/[^\/a-zA-Z0-9._-]/', '', $url);
    
    // 9. التأكد من أن المسار صحيح نهائياً
    if (empty($url) || $url === '/') {
        $url = '/dashboard/' . ($role ? $role . '.php' : '');
    }
    
    return $url;
}

/**
 * Get relative URL (for use in templates)
 */
function getRelativeUrl($path) {
    // إذا كان المسار مطلقاً (يبدأ بـ /)، استخدمه مباشرة
    if (strpos($path, '/') === 0) {
        return $path;
    }
    
    $base = getBasePath();
    
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    // إذا كان base فارغاً، استخدم المسار مباشرة
    if (empty($base)) {
        return '/' . $path;
    }
    
    // التأكد من أن base يبدأ بـ /
    if (strpos($base, '/') !== 0) {
        $base = '/' . $base;
    }
    
    // إزالة / من النهاية
    $base = rtrim($base, '/');
    
    return $base . '/' . $path;
}

/**
 * Get absolute URL (for use in templates)
 */
function getAbsoluteUrl($path) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $base = getBasePath();
    
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    return $protocol . $host . $base . '/' . $path;
}

/**
 * إعادة التوجيه بعد معالجة POST (POST-Redirect-GET pattern)
 * لمنع تكرار الطلب عند refresh
 * 
 * @param string $page اسم الصفحة (مثل 'warehouse_transfers')
 * @param array $filters معاملات الفلترة للحفاظ عليها
 * @param array $excludeParams معاملات لإزالتها من URL (مثل 'id')
 * @param string $role دور المستخدم (manager, accountant, etc.)
 * @param int|null $pageNum رقم الصفحة للباجينيشن
 */
function redirectAfterPost($page, $filters = [], $excludeParams = ['id'], $role = 'manager', $pageNum = null) {
    // إزالة المعاملات المطلوب استثناؤها
    $redirectParams = array_merge(['page' => $page], $filters);
    
    foreach ($excludeParams as $param) {
        unset($redirectParams[$param]);
    }
    
    // إضافة رقم الصفحة إذا كان موجوداً
    if ($pageNum !== null && $pageNum > 1) {
        $redirectParams['p'] = $pageNum;
    }
    
    $redirectUrl = getDashboardUrl($role) . '?' . http_build_query($redirectParams);
    
    // تسجيل محاولة الـ redirect
    $logDir = __DIR__ . '/../storage/logs';
    if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
        $logFile = $logDir . '/php-errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] redirectAfterPost called | URL: {$redirectUrl} | Page: {$page} | Role: {$role} | Headers sent: " . (headers_sent() ? 'yes' : 'no') . PHP_EOL;
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }

    $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<script>window.location.href = "' . $escapedUrl . '";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $escapedUrl . '"></noscript>';
    exit;
}

/**
 * التحقق من وجود قيمة في قائمة قبل تعيين selected في select dropdown
 * 
 * @param mixed $value القيمة للتحقق منها
 * @param array $list قائمة العناصر (مصفوفة من arrays مع 'id' key)
 * @param string $keyName اسم المفتاح للبحث (افتراضي: 'id')
 * @return bool true إذا كانت القيمة موجودة في القائمة
 */
function isValidSelectValue($value, $list, $keyName = 'id') {
    if (empty($value) || $value == 0 || $value == '') {
        return false;
    }
    
    $value = intval($value);
    if ($value <= 0 || $value > 100000) {
        return false; // قيم كبيرة غير منطقية (مثل 262145)
    }
    
    foreach ($list as $item) {
        if (isset($item[$keyName]) && intval($item[$keyName]) == $value) {
            return true;
        }
    }
    
    return false;
}

