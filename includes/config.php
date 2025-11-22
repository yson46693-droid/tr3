<?php
/**
 * إعدادات النظام العامة
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}
// إعدادات قاعدة البيانات - يمكن تعديلها حسب الاستضافة
// للاستضافة المحلية (localhost/XAMPP):
if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['SERVER_NAME'] == 'localhost') {
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'tr'); // يمكن تغيير اسم قاعدة البيانات هنا
} else {
    // 🌍 إعدادات قاعدة البيانات على الاستضافة (InfinityFree)
    define('DB_HOST', 'sql110.infinityfree.com');
    define('DB_PORT', '3306');
    define('DB_USER', 'if0_40278066');
    define('DB_PASS', 'Osama744');
    define('DB_NAME', 'if0_40278066_co_db');
}

// إعدادات المنطقة الزمنية - مصر/القاهرة
date_default_timezone_set('Africa/Cairo');

// إعدادات اللغة
if (!defined('DEFAULT_LANGUAGE')) {
    define('DEFAULT_LANGUAGE', 'ar');
}
if (!defined('SUPPORTED_LANGUAGES')) {
    define('SUPPORTED_LANGUAGES', ['ar', 'en']);
}

// إعدادات العملة
if (!defined('CURRENCY')) {
    define('CURRENCY', 'جنيه');
}
if (!defined('CURRENCY_SYMBOL')) {
    // تنظيف رمز العملة من أي آثار لـ 262145
    $currencySymbol = 'ج.م';
    $currencySymbol = str_replace('262145', '', $currencySymbol);
    $currencySymbol = preg_replace('/262145\s*/', '', $currencySymbol);
    $currencySymbol = preg_replace('/\s*262145/', '', $currencySymbol);
    $currencySymbol = trim($currencySymbol);
    // إذا أصبح فارغاً بعد التنظيف، استخدم القيمة الافتراضية
    if (empty($currencySymbol)) {
        $currencySymbol = 'ج.م';
    }
    define('CURRENCY_SYMBOL', $currencySymbol);
}
if (!defined('CURRENCY_CODE')) {
    define('CURRENCY_CODE', 'EGP');
}

// إعدادات التاريخ والوقت
define('DATE_FORMAT', 'd/m/Y');
define('TIME_FORMAT', 'g:i A'); // نظام 12 ساعة صباحاً ومساءً
define('DATETIME_FORMAT', 'd/m/Y g:i A');

// إعدادات الجلسة
define('SESSION_LIFETIME', 3600 * 24); // 24 ساعة
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.cookie_lifetime', SESSION_LIFETIME);

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
);

$sessionCookieOptions = [
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params($sessionCookieOptions);
    session_start();
} else {
    // تحديث إعدادات الكوكي الحالية إن كانت الجلسة قد بدأت بالفعل قبل تضمين الملف
    if (!headers_sent() && session_id()) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// إعدادات الأمان
define('PASSWORD_MIN_LENGTH', 1);
define('CSRF_TOKEN_NAME', 'csrf_token');
define('REQUEST_USAGE_MONITOR_ENABLED', true);
define('REQUEST_USAGE_THRESHOLD_PER_USER', 4000); // الحد اليومي لكل مستخدم قبل إنشاء تنبيه
define('REQUEST_USAGE_THRESHOLD_PER_IP', 30000);    // الحد اليومي لكل عنوان IP قبل إنشاء تنبيه
define('REQUEST_USAGE_ALERT_WINDOW_MINUTES', 1440); // فترة المراقبة بالدقائق (افتراضياً يوم كامل)

// إعدادات المسارات
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('REPORTS_PATH', BASE_PATH . '/reports/');

$privateStorageBase = dirname(BASE_PATH) . '/storage';
if (!defined('PRIVATE_STORAGE_PATH')) {
    define('PRIVATE_STORAGE_PATH', $privateStorageBase);
}
if (!defined('REPORTS_PRIVATE_PATH')) {
    define('REPORTS_PRIVATE_PATH', PRIVATE_STORAGE_PATH . '/reports');
}

/**
 * ضمان وجود مجلد خاص للتخزين وإنشائه تلقائياً إذا لم يكن موجوداً.
 *
 * @param string $directory
 * @return void
 */
function ensurePrivateDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    $parent = dirname($directory);
    if (!is_dir($parent) && $parent !== $directory) {
        ensurePrivateDirectory($parent);
    }

    if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log('Failed to create directory: ' . $directory);
    }
}

ensurePrivateDirectory(PRIVATE_STORAGE_PATH);
ensurePrivateDirectory(REPORTS_PRIVATE_PATH);

$logsDirectory = PRIVATE_STORAGE_PATH . '/logs';
ensurePrivateDirectory($logsDirectory);

$defaultErrorLog = $logsDirectory . '/php-errors.log';
if (is_dir($logsDirectory) && is_writable($logsDirectory)) {
    if (!file_exists($defaultErrorLog)) {
        @touch($defaultErrorLog);
    }

    if (is_writable($defaultErrorLog)) {
        ini_set('log_errors', '1');
        ini_set('error_log', $defaultErrorLog);
        if (!defined('APP_ERROR_LOG')) {
            define('APP_ERROR_LOG', $defaultErrorLog);
        }
    } else {
        error_log('Error log file is not writable: ' . $defaultErrorLog);
    }
} else {
    error_log('Logs directory is not writable: ' . $logsDirectory);
}
define('ASSETS_PATH', dirname(__DIR__) . '/assets/');

// إعدادات تكامل aPDF.io - يمكن تخزين المفتاح في متغير بيئة APDF_IO_API_KEY لأمان أفضل
define('APDF_IO_ENDPOINT', 'https://api.apdf.io/v1/pdf/html');
define('APDF_IO_API_KEY', getenv('APDF_IO_API_KEY') ?: 'UQFfHN7tBIgv0Zjy1nelyZWMJC93m3NMXCWfWe9246a95eed');

// تحديد ASSETS_URL بناءً على موقع الملف
// استخدام REQUEST_URI للحصول على المسار الكامل (يعمل بشكل أفضل على الموبايل)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

// استخراج base path من REQUEST_URI أو SCRIPT_NAME
$basePath = '';

// محاولة 1: من REQUEST_URI (أفضل للموبايل)
if (!empty($requestUri)) {
    $parsedUri = parse_url($requestUri);
    $path = $parsedUri['path'] ?? '';
    $path = str_replace('\\', '/', $path);
    
    // إزالة /dashboard و /modules و API من المسار
    $pathParts = explode('/', trim($path, '/'));
    $baseParts = [];
    
    foreach ($pathParts as $part) {
        if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
            break;
        }
        if (!empty($part)) {
            $baseParts[] = $part;
        }
    }
    
    if (!empty($baseParts)) {
        $basePath = '/' . implode('/', $baseParts);
    }
}

// محاولة 2: من SCRIPT_NAME إذا فشلت المحاولة الأولى
if (empty($basePath)) {
    $scriptDir = dirname($scriptName);
    
    // إزالة /dashboard أو /modules من المسار
    if (strpos($scriptDir, '/dashboard') !== false) {
        $scriptDir = dirname($scriptDir);
    }
    if (strpos($scriptDir, '/modules') !== false) {
        $scriptDir = dirname(dirname($scriptDir));
    }
    
    // تنظيف المسار
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $scriptDir = trim($scriptDir, '/');
    
    if (!empty($scriptDir) && $scriptDir !== '.' && $scriptDir !== '/') {
        $basePath = '/' . $scriptDir;
    }
}

// تحديد ASSETS_URL النهائي
if (empty($basePath)) {
    define('ASSETS_URL', '/assets/');
} else {
    define('ASSETS_URL', rtrim($basePath, '/') . '/assets/');
}

// إعدادات التطبيق
define('APP_NAME', 'شركة البركة');
define('APP_VERSION', '1.0.0');
define('COMPANY_NAME', 'شركة البركة');

// إعدادات التقارير
define('REPORTS_AUTO_DELETE', true); // حذف التقارير بعد الإرسال
define('REPORTS_RETENTION_HOURS', 24); // الاحتفاظ بالتقارير لمدة 24 ساعة

// إعدادات الإشعارات
if (!defined('NOTIFICATIONS_ENABLED')) {
    define('NOTIFICATIONS_ENABLED', true);
}
if (!defined('BROWSER_NOTIFICATIONS_ENABLED')) {
    define('BROWSER_NOTIFICATIONS_ENABLED', true);
}
if (!defined('NOTIFICATION_POLL_INTERVAL')) {
    define('NOTIFICATION_POLL_INTERVAL', 120000); // 120 ثانية افتراضياً
}
if (!defined('NOTIFICATION_AUTO_REFRESH_ENABLED')) {
    define('NOTIFICATION_AUTO_REFRESH_ENABLED', true);
}

// إعدادات Telegram Bot
// للحصول على Bot Token: تحدث مع @BotFather في Telegram
// للحصول على Chat ID: أرسل رسالة للبوت ثم افتح: https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
define('TELEGRAM_BOT_TOKEN', '6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew'); // ضع توكن البوت هنا
define('TELEGRAM_CHAT_ID', '-1003293835035'); // ضع معرف المحادثة هنا (يمكن أن يكون رقم أو -100... للمجموعات)

// إعدادات WebAuthn
define('WEBAUTHN_RP_NAME', 'نظام الإدارة المتكاملة');
define('WEBAUTHN_RP_ID', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
define('WEBAUTHN_ORIGIN', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));

// إعدادات التصميم
define('PRIMARY_COLOR', '#1e3a5f');
define('SECONDARY_COLOR', '#2c5282');
define('ACCENT_COLOR', '#3498db');

// تمكين عرض الأخطاء في وضع التطوير (يجب تعطيله في الإنتاج)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// إعدادات UTF-8
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// دالة مساعدة للحصول على اللغة الحالية
function getCurrentLanguage() {
    return $_SESSION['language'] ?? DEFAULT_LANGUAGE;
}

// دالة مساعدة للحصول على رمز العملة بعد تنظيفه من 262145
function getCurrencySymbol() {
    $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'ج.م';
    // تنظيف رمز العملة من 262145
    $symbol = str_replace('262145', '', $symbol);
    $symbol = preg_replace('/262145\s*/', '', $symbol);
    $symbol = preg_replace('/\s*262145/', '', $symbol);
    $symbol = trim($symbol);
    // إذا أصبح فارغاً بعد التنظيف، استخدم القيمة الافتراضية
    if (empty($symbol)) {
        $symbol = 'ج.م';
    }
    return $symbol;
}

// دالة مساعدة لتنسيق الأرقام
function formatCurrency($amount, $allowNegative = true) {
    // تنظيف القيمة باستخدام cleanFinancialValue
    // السماح بالقيم السالبة افتراضياً لأنها تستخدم للرصيد الدائن للعملاء
    $amount = cleanFinancialValue($amount, $allowNegative);
    
    // استخدام getCurrencySymbol للحصول على رمز العملة المنظف
    $currencySymbol = function_exists('getCurrencySymbol') ? getCurrencySymbol() : (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'ج.م');
    
    $formatted = number_format($amount, 2, '.', ',') . ' ' . $currencySymbol;
    
    // حذف أي آثار لـ 262145 من النص النهائي (حماية إضافية)
    $formatted = str_replace('262145', '', $formatted);
    $formatted = str_replace('262,145', '', $formatted);
    $formatted = preg_replace('/\s+/', ' ', $formatted);
    
    return trim($formatted);
}

/**
 * دالة لتنظيف القيم المالية وضمان صحتها
 * Validate and clean financial values
 * @param mixed $value القيمة المراد تنظيفها
 * @param bool $allowNegative السماح بالقيم السالبة (للرصيد الدائن)
 */
function cleanFinancialValue($value, $allowNegative = false) {
    // إذا كانت القيمة null أو فارغة، إرجاع 0
    if ($value === null || $value === '' || $value === false) {
        return 0;
    }
    
    // تحويل إلى نص أولاً
    $valueStr = (string)$value;

    // إزالة آثار الرقم الافتراضي القديم 262145 إن وُجدت بأي شكل
    $valueStr = str_replace('262145', '', $valueStr);
    $valueStr = preg_replace('/262145\s*/', '', $valueStr);
    $valueStr = preg_replace('/\s*262145/', '', $valueStr);

    // إزالة أي أحرف غير رقمية (باستثناء النقطة والعلامة السالبة)
    $valueStr = preg_replace('/[^0-9.\-]/', '', trim($valueStr));
    
    // إذا أصبح النص فارغاً بعد التنظيف، إرجاع 0
    if (empty($valueStr) || $valueStr === '-') {
        return 0;
    }
    
    // تحويل إلى رقم
    $value = floatval($valueStr);
    
    // التحقق من القيم غير المنطقية
    if (is_nan($value) || is_infinite($value)) {
        return 0;
    }
    
    // التحقق من القيم الكبيرة جداً أو السالبة
    if ($allowNegative) {
        // إذا كان مسموحاً بالقيم السالبة (للرصيد الدائن)، فقط التحقق من الحد الأقصى
        // القيم المقبولة: من -1000000 إلى 1000000
        if ($value > 1000000 || $value < -1000000) {
            return 0;
        }
    } else {
        // القيم المقبولة: من 0 إلى 10000 جنيه/ساعة (للأجور والمدفوعات)
        if ($value > 10000 || $value < 0) {
            return 0;
        }
    }
    
    // تقريب إلى منزلتين عشريتين
    return round($value, 2);
}

// دالة مساعدة لتنسيق التاريخ
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date)) return '';
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق الوقت
function formatTime($time, $format = TIME_FORMAT) {
    if (empty($time)) return '';
    $timestamp = is_numeric($time) ? $time : strtotime($time);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق التاريخ والوقت
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime)) return '';
    $timestamp = is_numeric($datetime) ? $datetime : strtotime($datetime);
    return date($format, $timestamp);
}

// دالة مساعدة لتنسيق الساعات من الصيغة العشرية إلى ساعات ودقائق
// مثال: 2.30 ساعة → "2 ساعة و 30 دقيقة"
function formatHours($decimalHours) {
    if (empty($decimalHours) || $decimalHours == 0) {
        return '0 ساعة';
    }
    
    $decimalHours = floatval($decimalHours);
    
    // استخراج الساعات الكاملة
    $hours = floor($decimalHours);
    
    // استخراج الدقائق من الجزء العشري
    $decimalPart = $decimalHours - $hours;
    $minutes = round($decimalPart * 60);
    
    // إذا كانت الدقائق 60، أضف ساعة واحدة
    if ($minutes >= 60) {
        $hours += 1;
        $minutes = 0;
    }
    
    // بناء النص
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ساعة';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' دقيقة';
    }
    
    if (empty($parts)) {
        return '0 ساعة';
    }
    
    return implode(' و ', $parts);
}

// دالة مساعدة للحصول على الاتجاه (RTL/LTR)
function getDirection() {
    return getCurrentLanguage() === 'ar' ? 'rtl' : 'ltr';
}

// دالة مساعدة للحصول على الاتجاه المعاكس في CSS
function getTextAlign() {
    return getCurrentLanguage() === 'ar' ? 'right' : 'left';
}

/**
 * منع تكرار الطلبات عند refresh
 * يستخدم Post-Redirect-Get (PRG) pattern
 * 
 * @param string|null $successMessage رسالة النجاح (اختياري)
 * @param array $redirectParams معاملات إعادة التوجيه
 * @param string|null $redirectUrl URL لإعادة التوجيه (اختياري)
 * @param string|null $role دور المستخدم لإعادة التوجيه (للاستخدام مع getDashboardUrl)
 * @param string|null $errorMessage رسالة الخطأ (اختياري)
 */
function preventDuplicateSubmission($successMessage = null, $redirectParams = [], $redirectUrl = null, $role = null, $errorMessage = null) {
    // إذا كانت هناك رسالة نجاح، حفظها في session
    if ($successMessage !== null && $successMessage !== '') {
        $_SESSION['success_message'] = $successMessage;
    }
    
    // إذا كانت هناك رسالة خطأ، حفظها في session
    if ($errorMessage !== null && $errorMessage !== '') {
        $_SESSION['error_message'] = $errorMessage;
    }
    
    // بناء URL إعادة التوجيه
    if ($redirectUrl === null) {
        // إذا كان هناك role و page في redirectParams، استخدم getDashboardUrl
        if ($role !== null && isset($redirectParams['page'])) {
            require_once __DIR__ . '/path_helper.php';
            $page = $redirectParams['page'];
            unset($redirectParams['page']);
            
            $baseUrl = getDashboardUrl($role);
            if (!empty($redirectParams)) {
                $queryString = http_build_query($redirectParams);
                $redirectUrl = $baseUrl . '?page=' . urlencode($page) . '&' . $queryString;
            } else {
                $redirectUrl = $baseUrl . '?page=' . urlencode($page);
            }
        } else {
            // استخدام URL الحالي بدون POST parameters
            $currentUrl = $_SERVER['REQUEST_URI'];
            $urlParts = parse_url($currentUrl);
            $path = $urlParts['path'] ?? '';
            
            // إضافة GET parameters إذا كانت موجودة
            if (!empty($redirectParams)) {
                $queryString = http_build_query($redirectParams);
                $redirectUrl = $path . '?' . $queryString;
            } else {
                // إزالة query string من URL الحالي
                $redirectUrl = $path;
            }
        }
    }
    
    // إذا لم يكن الرابط مطلقاً، تأكد من أنه يبدأ بشرطة مائلة
    if (!preg_match('/^https?:\/\//i', $redirectUrl)) {
        // استخدام substr بدلاً من str_starts_with للتوافق مع PHP < 8.0
        if (substr($redirectUrl, 0, 1) !== '/') {
            $redirectUrl = '/' . ltrim($redirectUrl, '/');
        }
    }
    
    // التحقق من أن headers لم يتم إرسالها بعد
    if (headers_sent($file, $line)) {
        // إذا تم إرسال headers بالفعل، استخدم JavaScript redirect
        echo '<script>window.location.href = ' . json_encode($redirectUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
    
    // إعادة التوجيه
    header('Location: ' . $redirectUrl);
    exit;
}

/**
 * التحقق من وجود رسالة نجاح في session وعرضها
 * يجب استدعاؤها في بداية الصفحة بعد معالجة POST
 * 
 * @return string|null رسالة النجاح أو null
 */
function getSuccessMessage() {
    if (isset($_SESSION['success_message'])) {
        $message = $_SESSION['success_message'];
        unset($_SESSION['success_message']);
        return $message;
    }
    return null;
}

/**
 * التحقق من وجود رسالة خطأ في session وعرضها
 * يجب استدعاؤها في بداية الصفحة بعد معالجة POST
 * 
 * @return string|null رسالة الخطأ أو null
 */
function getErrorMessage() {
    if (isset($_SESSION['error_message'])) {
        $message = $_SESSION['error_message'];
        unset($_SESSION['error_message']);
        return $message;
    }
    return null;
}

/**
 * دالة مساعدة لتطبيق PRG pattern على الطلبات POST
 * تقرأ الرسائل من session وتعرضها
 * 
 * @param string|null $defaultError متغير لرسالة الخطأ الافتراضي
 * @param string|null $defaultSuccess متغير لرسالة النجاح الافتراضي
 * @return void
 */
function applyPRGPattern(&$defaultError = null, &$defaultSuccess = null) {
    $sessionSuccess = getSuccessMessage();
    $sessionError = getErrorMessage();
    
    if ($sessionSuccess !== null) {
        $defaultSuccess = $sessionSuccess;
    }
    
    if ($sessionError !== null) {
        $defaultError = $sessionError;
    }
}


if (!defined('ENABLE_DAILY_LOW_STOCK_REPORT')) {
    define('ENABLE_DAILY_LOW_STOCK_REPORT', true);
}
if (!defined('ENABLE_DAILY_PACKAGING_ALERT')) {
    define('ENABLE_DAILY_PACKAGING_ALERT', true);
}
if (!defined('ENABLE_DAILY_CONSUMPTION_REPORT')) {
    define('ENABLE_DAILY_CONSUMPTION_REPORT', false);
}
if (!defined('ENABLE_PAGE_LOADER')) {
    define('ENABLE_PAGE_LOADER', false);
}
if (!defined('ENABLE_DAILY_BACKUP_DELIVERY')) {
    define('ENABLE_DAILY_BACKUP_DELIVERY', true);
}
if (!defined('ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP')) {
    define('ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP', true);
}

# وظيفة مساعده لجدولة المهام اليومية بفاصل زمني
if (ENABLE_DAILY_LOW_STOCK_REPORT) {
    require_once __DIR__ . '/daily_low_stock_report.php';
    triggerDailyLowStockReport();
}

if (ENABLE_DAILY_PACKAGING_ALERT) {
    require_once __DIR__ . '/packaging_alerts.php';
    processDailyPackagingAlert();
}

if (ENABLE_DAILY_CONSUMPTION_REPORT) {
    require_once __DIR__ . '/daily_consumption_sender.php';
    triggerDailyConsumptionReport();
}

if (ENABLE_DAILY_BACKUP_DELIVERY) {
    require_once __DIR__ . '/daily_backup_sender.php';
    triggerDailyBackupDelivery();
}

/**
 * تشغيل تنظيف صور الحضور والانصراف تلقائياً مرة واحدة يومياً
 * يتم تشغيله مع أول زائر لأي صفحة من صفحات الموقع
 */
if (ENABLE_DAILY_ATTENDANCE_PHOTOS_CLEANUP) {
    // ملف العلم لتتبع آخر مرة تم فيها التنظيف
    $cleanupFlagFile = PRIVATE_STORAGE_PATH . '/logs/attendance_photos_cleanup_last_run.txt';
    $today = date('Y-m-d');
    $shouldRun = false;
    
    // التحقق من آخر مرة تم فيها التنظيف
    if (file_exists($cleanupFlagFile)) {
        $lastRunDate = trim(@file_get_contents($cleanupFlagFile));
        if ($lastRunDate !== $today) {
            $shouldRun = true;
        }
    } else {
        // إذا لم يكن الملف موجوداً، قم بالتنظيف
        $shouldRun = true;
    }
    
    // تشغيل التنظيف إذا لزم الأمر
    if ($shouldRun) {
        try {
            // حفظ تاريخ اليوم في ملف العلم أولاً لمنع التشغيل المتكرر
            $logsDir = dirname($cleanupFlagFile);
            if (!is_dir($logsDir)) {
                @mkdir($logsDir, 0755, true);
            }
            @file_put_contents($cleanupFlagFile, $today, LOCK_EX);
            
            // تحميل الملفات المطلوبة
            if (!function_exists('cleanupOldAttendancePhotos')) {
                require_once __DIR__ . '/attendance.php';
            }
            if (!function_exists('db')) {
                require_once __DIR__ . '/db.php';
            }
            
            // تشغيل التنظيف (30 يوم كافتراضي)
            if (function_exists('cleanupOldAttendancePhotos')) {
                $stats = cleanupOldAttendancePhotos(30);
                
                // تسجيل النتائج
                $message = sprintf(
                    "Attendance photos cleanup (automatic daily): %d files deleted, %d folders deleted, %.2f MB freed, %d errors",
                    $stats['deleted_files'],
                    $stats['deleted_folders'],
                    $stats['total_size_freed'] / (1024 * 1024),
                    $stats['errors']
                );
                error_log($message);
            }
        } catch (Exception $e) {
            error_log('Daily attendance photos cleanup error: ' . $e->getMessage());
            // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
            if (file_exists($cleanupFlagFile)) {
                @unlink($cleanupFlagFile);
            }
        } catch (Throwable $e) {
            error_log('Daily attendance photos cleanup error: ' . $e->getMessage());
            // في حالة الخطأ، احذف ملف العلم للسماح بإعادة المحاولة لاحقاً
            if (file_exists($cleanupFlagFile)) {
                @unlink($cleanupFlagFile);
            }
        }
    }
}


