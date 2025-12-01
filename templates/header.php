<?php
/**
 * رأس الصفحة المشتركة
 * دعم RTL/LTR وتبديل اللغة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// تعريف ثابت للإشارة إلى أن header.php تم تضمينه - يجب أن يكون في البداية
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
}

// إضافة Permissions-Policy header للسماح بالوصول إلى Geolocation, Camera, Microphone, Notifications
if (!headers_sent()) {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self), notifications=(self)");
    // Feature-Policy كبديل للمتصفحات القديمة
    header("Feature-Policy: geolocation 'self'; camera 'self'; microphone 'self'; notifications 'self'");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/packaging_alerts.php';
require_once __DIR__ . '/../includes/payment_schedules.php';
require_once __DIR__ . '/../includes/production_reports.php';

// تحديد اللغة الحالية
$currentLang = getCurrentLanguage();
$dir = getDirection();

// تحميل ملفات اللغة - فقط إذا لم يتم تحميلها بالفعل
if (!isset($translations) || empty($translations)) {
    $translations = [];
    if (file_exists(__DIR__ . '/../includes/lang/' . $currentLang . '.php')) {
        require_once __DIR__ . '/../includes/lang/' . $currentLang . '.php';
    }
}

// استخدام $lang الموجود إذا كان موجوداً، وإلا استخدام $translations
if (!isset($lang) || empty($lang)) {
    $lang = isset($translations) ? $translations : [];
}
// تحميل $currentUser فقط إذا لم يكن محملاً بالفعل
if (!isset($currentUser) || $currentUser === null) {
    $currentUser = getCurrentUser();
}

// فحص أمني: إذا كان المستخدم مسجل دخول لكن غير موجود في قاعدة البيانات
// (getCurrentUser() يقوم بإلغاء تسجيل الدخول تلقائياً، لكن نتأكد من عدم وجود جلسة نشطة)
if (isLoggedIn() && (!$currentUser || !is_array($currentUser) || empty($currentUser))) {
    // المستخدم مسجل دخول لكن غير موجود أو محذوف - تم إلغاء تسجيل الدخول تلقائياً
    // إعادة التوجيه لتسجيل الدخول
    $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
    if (!headers_sent()) {
        header('Location: ' . $loginUrl);
        exit;
    } else {
        echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
        exit;
    }
}

$currentUserRole = strtolower((string) (isset($currentUser['role']) ? $currentUser['role'] : ''));

// تم نقل العمليات الثقيلة إلى api/background-tasks.php لتحسين الأداء
// سيتم تنفيذها بشكل غير متزامن بعد تحميل الصفحة

// العمليات السريعة فقط - يمكن تنفيذها مباشرة
if ($currentUser && function_exists('handleAttendanceRemindersForUser')) {
    // هذا قد يحتاج أن يعمل مباشرة لإظهار الإشعارات
    // لكن يمكن تحسينه لاحقاً ليعمل عبر AJAX أيضاً
    try {
        handleAttendanceRemindersForUser($currentUser);
    } catch (Throwable $e) {
        error_log('Attendance reminders error: ' . $e->getMessage());
    }
}

/* تم نقل العمليات التالية إلى api/background-tasks.php
 * سيتم تنفيذها بشكل غير متزامن بعد تحميل الصفحة لتحسين الأداء
 * 
if (function_exists('processDailyPackagingAlert')) {
    processDailyPackagingAlert();
}

if (function_exists('processAutoCheckoutForMissingEmployees')) {
    try {
        processAutoCheckoutForMissingEmployees();
    } catch (Throwable $autoCheckoutError) {
        error_log('Auto checkout processing error: ' . $autoCheckoutError->getMessage());
    }
}

if (function_exists('resetWarningCountsForNewMonth')) {
    try {
        resetWarningCountsForNewMonth();
    } catch (Throwable $resetWarningError) {
        error_log('Warning count reset error: ' . $resetWarningError->getMessage());
    }
}

if ($currentUser && $currentUserRole === 'sales') {
    try {
        notifyTodayPaymentSchedules((int) (isset($currentUser['id']) ? $currentUser['id'] : 0));
    } catch (Throwable $paymentNotificationError) {
        error_log('Sales payment notification error: ' . $paymentNotificationError->getMessage());
    }
}

if ($currentUser) {
    try {
        maybeSendMonthlyProductionDetailedReport((int) date('n'), (int) date('Y'));
    } catch (Throwable $productionReportAutoError) {
        error_log('Automatic monthly production detailed report dispatch failed: ' . $productionReportAutoError->getMessage());
    }
}
*/

// التأكد من عدم وجود محتوى قبل DOCTYPE - تنظيف أي output غير مرغوب
if (ob_get_level() > 0) {
    $bufferContent = ob_get_contents();
    // إذا كان هناك محتوى في الـ buffer ولا يبدأ بـ DOCTYPE، امسحه
    if (!empty(trim($bufferContent)) && stripos(trim($bufferContent), '<!DOCTYPE') !== 0) {
        ob_clean();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Permissions-Policy" content="geolocation=(self), camera=(self), microphone=(self), notifications=(self)">
    <meta http-equiv="Feature-Policy" content="geolocation 'self'; camera 'self'; microphone 'self'; notifications 'self'">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <?php
    // تحديد pageDescription إذا لم يكن محدداً
    if (!isset($pageDescription)) {
        $pageDescription = 'نظام إدارة متكامل لشركة البركة - إدارة المخازن والمبيعات والموارد البشرية';
    }
    
    // تحديد Canonical URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $canonicalUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
    $ogImage = $baseUrl . ASSETS_URL . 'icons/icon-512x512.png';
    ?>
    
    <!-- Meta Tags for SEO -->
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="نظام إدارة, شركة البركة, إدارة المخازن, إدارة المبيعات, إدارة الموارد البشرية, نظام محاسبة, إدارة العملاء">
    <meta name="author" content="<?php echo COMPANY_NAME; ?>">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta name="language" content="Arabic">
    <meta name="geo.region" content="EG">
    <meta name="geo.placename" content="مصر">
    <meta name="application-name" content="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- Open Graph Tags -->
    <meta property="og:title" content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' - ' : ''; ?><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale" content="ar_EG">
    <meta property="og:site_name" content="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' - ' : ''; ?><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    
    <?php
    // كشف الموبايل لتحسين الأداء (يجب أن يكون قبل استخدامه)
    if (!isset($isMobile)) {
        $isMobile = (bool) preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    ?>
    
    <!-- Performance: Preconnect to CDNs - محسّن للموبايل -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    <link rel="dns-prefetch" href="https://code.jquery.com">
    
    <!-- Performance: Preload Critical Resources - فقط على Desktop -->
    <?php if (!$isMobile): ?>
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style" crossorigin="anonymous">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" as="style" crossorigin="anonymous">
    <link rel="preload" href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="https://code.jquery.com/jquery-3.7.0.min.js" as="script" crossorigin="anonymous">
    <link rel="preload" href="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>" as="script">
    <?php endif; ?>
    
    <!-- Performance: Resource Hints للموبايل -->
    <?php if ($isMobile): ?>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://code.jquery.com">
    <?php endif; ?>
    
    <?php
    // تحديد ASSETS_URL بشكل صحيح
    $assetsUrl = ASSETS_URL;
    // إذا كان ASSETS_URL يبدأ بـ //، أزل /
    if (strpos($assetsUrl, '//') === 0) {
        $assetsUrl = '/' . ltrim($assetsUrl, '/');
    }
    // إذا لم يبدأ بـ /، أضفه
    if (strpos($assetsUrl, '/') !== 0) {
        $assetsUrl = '/' . $assetsUrl;
    }
    // إزالة /assets/ المكرر
    $assetsUrl = rtrim($assetsUrl, '/') . '/';
    
    // استخدام رقم version ثابت لتحسين caching - يمكن تحديثه يدوياً عند الحاجة
    // بدلاً من time() لتجنب cache invalidation في كل طلب وتحسين الأداء
    $cacheVersion = defined('ASSETS_VERSION') ? ASSETS_VERSION : (defined('APP_VERSION') ? APP_VERSION : '1.0.0');
    ?>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    
    <!-- Bootstrap Icons - تحميل مشروط للموبايل (أقل) -->
    <?php if ($isMobile): ?>
    <!-- Mobile: تحميل Bootstrap Icons مع lazy loading -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" media="print" onload="this.media='all'" crossorigin="anonymous">
    <noscript><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous"></noscript>
    <?php else: ?>
    <!-- Desktop: تحميل عادي -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" crossorigin="anonymous">
    <?php endif; ?>
    
    <!-- Custom CSS - Homeline Dashboard Design -->
    <!-- Critical CSS - تحميل مباشر -->
    <link href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/responsive.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    
    <!-- Medium Priority CSS - تحميل مشروط -->
    <link href="<?php echo $assetsUrl; ?>css/sidebar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/sidebar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/cards.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/cards.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <!-- Mobile-specific CSS - تحميل فقط على الموبايل -->
    <?php if ($isMobile): ?>
    <link href="<?php echo $assetsUrl; ?>css/mobile-tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <?php else: ?>
    <!-- Desktop: تحميل مع lazy loading -->
    <link href="<?php echo $assetsUrl; ?>css/mobile-tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="(max-width: 767.98px)">
    <?php endif; ?>
    
    <!-- Low Priority CSS - تحميل متأخر -->
    <link href="<?php echo $assetsUrl; ?>css/pwa.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/pwa.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/modal-iframe.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/modal-iframe.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <link href="<?php echo $assetsUrl; ?>css/dark-mode.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo $assetsUrl; ?>css/dark-mode.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    
    <!-- Accessibility Improvements -->
    <link href="<?php echo $assetsUrl; ?>css/accessibility-improvements.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    
    <!-- Image Optimization -->
    <link href="<?php echo $assetsUrl; ?>css/image-optimization.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <?php if (!empty($pageStylesheets) && is_array($pageStylesheets)): ?>
        <?php foreach ($pageStylesheets as $stylesheetPath): ?>
            <?php
            if (!is_string($stylesheetPath)) {
                continue;
            }
            $stylesheetPath = trim($stylesheetPath);
            if ($stylesheetPath === '') {
                continue;
            }

            $hasProtocol = (bool) preg_match('#^https?://#i', $stylesheetPath);
            $isProtocolRelative = !$hasProtocol && strpos($stylesheetPath, '//') === 0;
            if ($hasProtocol || $isProtocolRelative) {
                $href = $stylesheetPath;
            } else {
                if (strpos($stylesheetPath, '/') === 0) {
                    $normalizedPath = preg_replace('#/+#', '/', $stylesheetPath);
                    $href = getRelativeUrl(ltrim($normalizedPath, '/'));
                } else {
                    $baseHref = (strpos($stylesheetPath, 'assets/') === 0)
                        ? '/' . ltrim($stylesheetPath, '/')
                        : rtrim($assetsUrl, '/') . '/' . ltrim($stylesheetPath, '/');
                    $href = getRelativeUrl(ltrim($baseHref, '/'));
                }
            }

            if (strpos($href, '?') === false) {
                $href .= '?v=' . $cacheVersion;
            }
            ?>
            <link href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($dir === 'rtl'): ?>
    <link href="<?php echo $assetsUrl; ?>css/rtl.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <?php endif; ?>
    
    <!-- Structured Data (JSON-LD) for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebApplication",
      "name": "<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>",
      "alternateName": "<?php echo htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'); ?>",
      "description": "<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>",
      "url": "<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>",
      "applicationCategory": "BusinessApplication",
      "operatingSystem": "Web Browser",
      "softwareVersion": "<?php echo defined('APP_VERSION') ? htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') : '1.0.0'; ?>",
      "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "EGP",
        "availability": "https://schema.org/InStock"
      },
      "provider": {
        "@type": "Organization",
        "name": "<?php echo htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'); ?>",
        "url": "<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>",
          "width": 512,
          "height": 512
        }
      },
      "inLanguage": "<?php echo $currentLang; ?>",
      "browserRequirements": "Requires JavaScript. Requires HTML5.",
      "featureList": [
        "إدارة المخازن",
        "إدارة المبيعات",
        "إدارة العملاء",
        "إدارة الموارد البشرية",
        "تقارير شاملة"
      ],
      "screenshot": "<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>"
    }
    </script>
    
    <!-- Additional Structured Data: Organization for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "<?php echo htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8'); ?>",
      "url": "<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>",
      "logo": "<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>",
      "contactPoint": {
        "@type": "ContactPoint",
        "contactType": "customer service",
        "areaServed": "EG",
        "availableLanguage": ["ar", "Arabic"]
      },
      "sameAs": []
    }
    </script>
    
    <!-- Additional Structured Data: BreadcrumbList for SEO -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      "itemListElement": [
        {
          "@type": "ListItem",
          "position": 1,
          "name": "<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>",
          "item": "<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>"
        },
        {
          "@type": "ListItem",
          "position": 2,
          "name": "<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'الصفحة'; ?>",
          "item": "<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>"
        }
      ]
    }
    </script>
    
    <style>
        /* منع Layout forced - إخفاء المحتوى حتى تحميل CSS */
        body:not(.css-loaded) {
            visibility: hidden;
        }
        body.css-loaded {
            visibility: visible;
        }
        
        /* Accessibility: Skip to main content link */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: #000;
            color: #fff;
            padding: 8px 16px;
            text-decoration: none;
            z-index: 10000;
            border-radius: 0 0 4px 0;
        }
        .skip-link:focus {
            top: 0;
            outline: 3px solid #3498db;
            outline-offset: 2px;
        }
        
        /* Accessibility: Enhanced Focus Indicators */
        *:focus-visible {
            outline: 3px solid #3498db;
            outline-offset: 2px;
            border-radius: 2px;
        }
        button:focus-visible,
        a:focus-visible,
        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible {
            outline: 3px solid #3498db;
            outline-offset: 2px;
        }
        
        /* Accessibility: Visually Hidden but accessible to screen readers */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }
        .visually-hidden-focusable:focus {
            position: static;
            width: auto;
            height: auto;
            padding: inherit;
            margin: inherit;
            overflow: visible;
            clip: auto;
            white-space: normal;
        }
    </style>
    <script>
        window.APP_CONFIG = window.APP_CONFIG || {};
        window.APP_CONFIG.passwordMinLength = <?php echo json_encode(getPasswordMinLength(), JSON_UNESCAPED_UNICODE); ?>;
        
        // دالة للتحقق من تحميل جميع stylesheets
        window.stylesheetsLoaded = false;
        (function() {
            function checkStylesheets() {
                const stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
                let allLoaded = true;
                
                stylesheets.forEach(function(link) {
                    if (!link.sheet && link.href && !link.href.startsWith('data:')) {
                        allLoaded = false;
                    }
                });
                
                if (allLoaded && stylesheets.length > 0) {
                    window.stylesheetsLoaded = true;
                    document.dispatchEvent(new CustomEvent('stylesheetsLoaded'));
                } else if (stylesheets.length === 0) {
                    window.stylesheetsLoaded = true;
                    document.dispatchEvent(new CustomEvent('stylesheetsLoaded'));
                } else {
                    setTimeout(checkStylesheets, 50);
                }
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(checkStylesheets, 100);
                });
            } else {
                setTimeout(checkStylesheets, 100);
            }
            
            // Fallback: اعتبارها محملة بعد وقت معقول
            window.addEventListener('load', function() {
                setTimeout(function() {
                    if (!window.stylesheetsLoaded) {
                        window.stylesheetsLoaded = true;
                        document.dispatchEvent(new CustomEvent('stylesheetsLoaded'));
                    }
                    // إظهار المحتوى بعد تحميل CSS
                    document.body.classList.add('css-loaded');
                }, 300);
            });
            
            // عند تحميل stylesheets، أظهر المحتوى
            document.addEventListener('stylesheetsLoaded', function() {
                document.body.classList.add('css-loaded');
            });
        })();
    </script>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo ASSETS_URL; ?>icons/favicon.svg">
    <link rel="icon" type="image/svg+xml" sizes="32x32" href="<?php echo ASSETS_URL; ?>icons/icon-32x32.svg">
    <link rel="icon" type="image/svg+xml" sizes="16x16" href="<?php echo ASSETS_URL; ?>icons/icon-16x16.svg">
    <?php if (file_exists(__DIR__ . '/../favicon.ico')): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo getRelativeUrl('favicon.ico'); ?>">
    <link rel="shortcut icon" href="<?php echo getRelativeUrl('favicon.ico'); ?>">
    <?php endif; ?>
    
    <!-- Apple Touch Icons -->
    <?php if (file_exists(__DIR__ . '/../assets/icons/apple-touch-icon.png')): ?>
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo ASSETS_URL; ?>icons/apple-touch-icon.png">
    <?php else: ?>
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo ASSETS_URL; ?>icons/apple-touch-icon.svg">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-152x152.png')): ?>
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo ASSETS_URL; ?>icons/icon-152x152.png">
    <?php else: ?>
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo ASSETS_URL; ?>icons/icon-152x152.svg">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-144x144.png')): ?>
    <link rel="apple-touch-icon" sizes="144x144" href="<?php echo ASSETS_URL; ?>icons/icon-144x144.png">
    <?php else: ?>
    <link rel="apple-touch-icon" sizes="144x144" href="<?php echo ASSETS_URL; ?>icons/icon-144x144.svg">
    <?php endif; ?>
    
    <!-- Android Icons -->
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-192x192.png')): ?>
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo ASSETS_URL; ?>icons/icon-192x192.png">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" sizes="192x192" href="<?php echo ASSETS_URL; ?>icons/icon-192x192.svg">
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/../assets/icons/icon-512x512.png')): ?>
    <link rel="icon" type="image/png" sizes="512x512" href="<?php echo ASSETS_URL; ?>icons/icon-512x512.png">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" sizes="512x512" href="<?php echo ASSETS_URL; ?>icons/icon-512x512.svg">
    <?php endif; ?>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#f1c40f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo APP_NAME; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- Manifest -->
    <link rel="manifest" href="<?php echo getRelativeUrl('manifest.php'); ?>">
    
    <!-- 🎬 Page Loading Animation CSS -->
    <?php if (!defined('ENABLE_PAGE_LOADER') || ENABLE_PAGE_LOADER): ?>
    <style>
        /* شاشة التحميل الرئيسية - ألوان التطبيق */
        #pageLoader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f4d03f 0%, #f1c40f 50%, #f4d03f 100%);
            background-size: 400% 400%;
            animation: gradientShift 8s ease infinite;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
            pointer-events: none;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        #pageLoader.hidden {
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
            z-index: -1 !important;
            display: none !important;
        }
        
        #pageLoader:not(.hidden) {
            pointer-events: all;
        }
        
        /* التأكد من أن pageLoader لا يمنع النقرات بعد إخفائه */
        #pageLoader[style*="display: none"],
        #pageLoader.hidden[style*="display: none"],
        #pageLoader[style*="display:none"] {
            pointer-events: none !important;
            z-index: -1 !important;
            display: none !important;
        }
        
        /* التأكد من أن الأزرار والعناصر التفاعلية قابلة للنقر */
        .topbar-action,
        .topbar-action *,
        button,
        input[type="checkbox"],
        input[type="button"],
        input[type="submit"],
        a.topbar-action {
            pointer-events: auto !important;
            z-index: auto !important;
            position: relative !important;
        }
        
        /* التأكد من أن topbar قابلة للنقر */
        .homeline-topbar,
        .homeline-topbar * {
            pointer-events: auto !important;
        }
        
        /* إصلاح Modal - قيم z-index صحيحة */
        .modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1055 !important;
            width: 100% !important;
            height: 100% !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            outline: 0 !important;
        }
        
        .modal.show {
            display: block !important;
        }
        
        .modal:not(.show) {
            display: none !important;
        }
        
        .modal-backdrop {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1050 !important;
            width: 100vw !important;
            height: 100vh !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
            opacity: 1 !important;
        }
        
        .modal-backdrop.show {
            opacity: 0.5 !important;
        }
        
        /* التأكد من وجود backdrop واحد فقط */
        .modal-backdrop ~ .modal-backdrop {
            display: none !important;
        }
        
        .modal-dialog {
            position: relative !important;
            width: auto !important;
            margin: 1.75rem auto !important;
            pointer-events: none !important;
            z-index: auto !important;
        }
        
        .modal.show .modal-dialog {
            pointer-events: auto !important;
        }
        
        .modal-content {
            position: relative !important;
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            pointer-events: auto !important;
            background-color: #fff !important;
            background-clip: padding-box !important;
            border: 1px solid rgba(0, 0, 0, 0.2) !important;
            border-radius: 0.3rem !important;
            outline: 0 !important;
            z-index: auto !important;
        }
        
        /* منع تداخل الجداول مع الـ modal */
        .dashboard-table-wrapper,
        .table-responsive,
        .table {
            position: relative !important;
            z-index: 1 !important;
        }
        
        .dashboard-table thead th {
            position: sticky !important;
            z-index: 10 !important;
        }
        
        /* لوجو PWA */
        .loader-logo {
            width: 180px;
            height: 180px;
            margin-bottom: 2rem;
            animation: logoFadeIn 0.8s ease-out, logoFloat 3s ease-in-out infinite 0.8s;
            filter: drop-shadow(0 8px 25px rgba(0, 0, 0, 0.3));
        }
        
        @keyframes logoFadeIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        /* العنوان */
        .loader-title {
            color: white;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: 2px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            animation: titleFadeIn 1s ease-out 0.3s both;
        }
        
        @keyframes titleFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* حاوية السبينر - مخفية */
        .loader-spinner {
            display: none;
        }
        
        /* الدوائر المتحركة - مخفية */
        .spinner-circle {
            border-top-color: rgba(79, 172, 254, 1);
            border-right-color: rgba(79, 172, 254, 0.6);
            animation-duration: 2s;
            animation-direction: reverse;
            box-shadow: 0 0 20px rgba(79, 172, 254, 0.5);
        }
        
        .spinner-circle:nth-child(3) {
            border-top-color: rgba(0, 242, 254, 1);
            border-right-color: rgba(0, 242, 254, 0.5);
            animation-duration: 2.5s;
            box-shadow: 0 0 20px rgba(0, 242, 254, 0.5);
        }
        
        /* نص التحميل */
        .loader-text {
            color: white;
            font-size: 1.1rem;
            margin-top: 2.5rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        /* النقاط المتحركة */
        .loading-dots {
            display: inline-block;
        }
        
        .loading-dots span {
            animation: blink 1.4s infinite;
            animation-fill-mode: both;
        }
        
        .loading-dots span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .loading-dots span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        /* شريط التقدم - تدرج أزرق */
        .loader-progress {
            width: 280px;
            height: 5px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 2.5rem;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .loader-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, 
                #f1c40f 0%, 
                #fff 25%, 
                #f4d03f 50%, 
                #fff 75%, 
                #f1c40f 100%
            );
            background-size: 200% 100%;
            animation: progressMove 1.8s ease-in-out infinite;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(241, 196, 15, 0.8), 0 0 30px rgba(244, 208, 63, 0.6);
        }
        
        /* الأنيميشنات */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.85; }
        }
        
        @keyframes blink {
            0%, 80%, 100% { opacity: 0.3; }
            40% { opacity: 1; }
        }
        
        @keyframes progressMove {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* تأثير التلاشي للمحتوى */
        .content-fade-in {
            animation: contentFadeIn 0.6s ease-out forwards;
        }
        
        @keyframes contentFadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* تأثير للروابط */
        a {
            transition: all 0.3s ease;
        }
    </style>
    <?php endif; ?>
    
    <!-- Service Worker Registration with Auto-Update -->
    <script>
        // تعطيل Service Worker مؤقتاً لحل مشكلة ERR_FAILED
        if (false && 'serviceWorker' in navigator) {
            let registration;
            let updateCheckInterval;
            
            window.addEventListener('load', function() {
                // حساب مسار Service Worker - استخدام مسار مطلق بسيط
                const currentPath = window.location.pathname;
                const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && !p.endsWith('.php'));
                
                // حساب المسار من الجذر (ديناميكي - يعمل مع أي مسار)
                let swPath = '/service-worker.js';
                if (pathParts.length > 0) {
                    // إذا كنا في مجلد فرعي مثل /v1/ أو /tr/
                    swPath = '/' + pathParts[0] + '/service-worker.js';
                }
                
                const scope = pathParts.length > 0 ? '/' + pathParts[0] + '/' : '/';
                
                navigator.serviceWorker.register(swPath, { scope: scope })
                    .then(function(reg) {
                        registration = reg;
                        console.log('Service Worker registered:', reg);
                        
                        // تعطيل التحقق التلقائي من التحديثات لتجنب إعادة التحميل المستمرة
                        // reg.update();
                        
                        // التحقق من التحديثات كل 5 دقائق - معطل مؤقتاً
                        // updateCheckInterval = setInterval(function() {
                        //     reg.update().catch(function(error) {
                        //         console.log('Update check failed:', error);
                        //     });
                        // }, 5 * 60 * 1000); // 5 دقائق
                        
                        // الاستماع للتحديثات
                        reg.addEventListener('updatefound', function() {
                            const newWorker = reg.installing;
                            
                            newWorker.addEventListener('statechange', function() {
                                if (newWorker.state === 'installed') {
                                    if (navigator.serviceWorker.controller) {
                                        // هناك إصدار جديد متاح
                                        showUpdateNotification();
                                    } else {
                                        // أول تثبيت
                                        console.log('Service Worker installed for the first time');
                                    }
                                }
                                
                                if (newWorker.state === 'activated') {
                                    // تحديث تم تفعيله - لا نعيد التحميل تلقائياً
                                    console.log('Service Worker activated');
                                    // إظهار إشعار للمستخدم بدلاً من إعادة التحميل التلقائية
                                    showUpdateNotification();
                                }
                            });
                        });
                        
                        // الاستماع للرسائل من Service Worker
                        navigator.serviceWorker.addEventListener('message', function(event) {
                            if (event.data && event.data.type === 'SW_ACTIVATED') {
                                console.log('New Service Worker activated, cache:', event.data.cacheName);
                                // إظهار إشعار بدلاً من إعادة التحميل التلقائية
                                showUpdateNotification();
                            }
                        });
                    })
                    .catch(function(error) {
                        if (error.message && error.message.includes('CORS')) {
                            console.log('Service Worker registration skipped due to CORS policy');
                            return;
                        }
                        console.log('Service Worker registration failed:', error);
                    });
            });
            
            // دالة لإظهار إشعار التحديث
            function showUpdateNotification() {
                // إنشاء عنصر إشعار
                const notification = document.createElement('div');
                notification.className = 'alert alert-info alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                notification.style.zIndex = '9999';
                notification.style.maxWidth = '500px';
                notification.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="bi bi-arrow-clockwise me-2"></i>
                        <strong>تحديث متاح!</strong>
                        <span class="ms-2">تم اكتشاف نسخة جديدة من الموقع</span>
                        <button type="button" class="btn btn-sm btn-primary ms-auto me-2" onclick="updateNow()">
                            تحديث الآن
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                document.body.appendChild(notification);
                
                // إضافة دالة التحديث
                window.updateNow = function() {
                    if (registration && registration.waiting) {
                        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                    }
                    notification.remove();
                };
                
                // إزالة الإشعار بعد 30 ثانية
                setTimeout(function() {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 30000);
            }
            
            // إعادة تحميل عند تركيز النافذة (للتحقق من التحديثات)
            window.addEventListener('focus', function() {
                if (registration) {
                    registration.update().catch(function(error) {
                        console.log('Update check on focus failed:', error);
                    });
                }
            });
            
            // تنظيف عند إغلاق الصفحة
            window.addEventListener('beforeunload', function() {
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                }
            });
        }
        
        // إلغاء تسجيل Service Workers القديمة إذا كانت موجودة
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for(let registration of registrations) {
                    registration.unregister().then(function(success) {
                        if (success) {
                            console.log('Old Service Worker unregistered');
                        }
                    });
                }
            });
        }
    </script>
</head>
<body class="dashboard-body"
      data-user-role="<?php echo htmlspecialchars(isset($currentUser['role']) ? $currentUser['role'] : ''); ?>"
      data-user-id="<?php echo isset($currentUser['id']) ? (int) $currentUser['id'] : 0; ?>">
    <!-- Accessibility: Skip to main content -->
    <a href="#main-content" class="skip-link visually-hidden-focusable">
        <?php echo isset($lang['skip_to_main']) ? $lang['skip_to_main'] : 'تخطي إلى المحتوى الرئيسي'; ?>
    </a>
    <!-- 🎬 PWA Splash Screen -->
    <?php if (!defined('ENABLE_PAGE_LOADER') || ENABLE_PAGE_LOADER): ?>
    <div id="pageLoader" role="status" aria-live="polite" aria-label="<?php echo isset($lang['loading']) ? $lang['loading'] : 'جاري التحميل'; ?>">
        <img src="<?php echo ASSETS_URL; ?>icons/icon-192x192.png" 
             alt="<?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?>" 
             class="loader-logo"
             width="192"
             height="192"
             loading="eager"
             decoding="async">
        <div class="loader-title"><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <?php endif; ?>
    
    <div id="sessionEndOverlay"
         class="session-end-overlay"
         hidden
         aria-hidden="true"
         data-login-url="<?php echo htmlspecialchars(getRelativeUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="session-end-overlay__backdrop" aria-hidden="true"></div>
        <div class="session-end-overlay__dialog" role="dialog" aria-modal="true" aria-labelledby="sessionEndTitle" aria-describedby="sessionEndDescription" tabindex="-1">
            <div class="session-end-overlay__icon" aria-hidden="true">
                <i class="bi bi-exclamation-octagon-fill"></i>
            </div>
            <h2 id="sessionEndTitle">انتهت الجلسة</h2>
            <p id="sessionEndDescription">لأسباب أمنية، تم إنهاء جلستك. يرجى تسجيل الدخول مرة أخرى للمتابعة.</p>
            <button type="button" class="btn session-end-overlay__action" data-action="return-login">
                <i class="bi bi-box-arrow-in-right me-2"></i>
                العودة إلى تسجيل الدخول
            </button>
        </div>
    </div>

    <div class="dashboard-wrapper">
        <!-- Homeline Style Sidebar -->
        <?php if (isLoggedIn()): ?>
        <?php include __DIR__ . '/homeline_sidebar.php'; ?>
        <?php endif; ?>
        
        <!-- Top Bar -->
        <div class="homeline-topbar">
            <div class="topbar-left">
                <!-- Mobile Menu Toggle -->
                 <button class="mobile-menu-toggle d-md-none" 
                         id="mobileMenuToggle" 
                         type="button"
                         aria-label="<?php echo isset($lang['menu']) ? $lang['menu'] : 'القائمة'; ?>"
                         aria-expanded="false"
                         aria-controls="sidebar">
                     <i class="bi bi-list" aria-hidden="true"></i>
                     <span class="visually-hidden"><?php echo isset($lang['menu']) ? $lang['menu'] : 'القائمة'; ?></span>
                 </button>
                 <button class="mobile-reload-btn d-md-none" 
                         id="mobileReloadBtn" 
                         type="button"
                         aria-label="<?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?>">
                     <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                     <span class="visually-hidden"><?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?></span>
                 </button>
                 <button class="mobile-dark-toggle d-md-none" 
                         id="mobileDarkToggle" 
                         type="button"
                         aria-label="<?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>"
                         aria-pressed="false">
                     <i class="bi bi-moon-stars" aria-hidden="true"></i>
                     <span class="visually-hidden"><?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?></span>
                 </button>
                <div class="breadcrumb-nav">
                    <?php 
                    $pageTitleText = isset($pageTitle) ? $pageTitle : (isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم');
                    ?>
                    <a href="<?php echo getDashboardUrl(isset($currentUser['role']) ? $currentUser['role'] : 'accountant'); ?>"><?php echo APP_NAME; ?></a>
                    <span class="mx-2">/</span>
                    <span><?php echo $pageTitleText; ?></span>
                </div>
            </div>
            
            <div class="topbar-center">
                <div class="topbar-search">
                    <label for="globalSearch" class="visually-hidden">
                        <?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>
                    </label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="text" 
                           placeholder="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>" 
                           id="globalSearch"
                           aria-label="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>"
                           autocomplete="off"
                           aria-describedby="search-help">
                    <span class="search-shortcut" aria-hidden="true">⌘K</span>
                    <span id="search-help" class="visually-hidden">
                        <?php echo isset($lang['search_help']) ? $lang['search_help'] : 'استخدم للبحث في النظام'; ?>
                    </span>
                </div>
            </div>
            
            <div class="topbar-right">
                <!-- Settings -->
                <a href="<?php echo getRelativeUrl('profile.php'); ?>" 
                   class="topbar-action" 
                   data-bs-toggle="tooltip" 
                   title="<?php echo isset($lang['settings']) ? $lang['settings'] : 'الإعدادات'; ?>"
                   aria-label="<?php echo isset($lang['settings']) ? $lang['settings'] : 'الإعدادات'; ?>">
                    <i class="bi bi-gear" aria-hidden="true"></i>
                    <span class="visually-hidden"><?php echo isset($lang['settings']) ? $lang['settings'] : 'الإعدادات'; ?></span>
                </a>
                
                <!-- Notifications -->
                <?php if (isLoggedIn()): ?>
                <div class="topbar-dropdown">
                    <a href="#" 
                       class="topbar-action" 
                       id="notificationsDropdown" 
                       role="button" 
                       aria-label="<?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>"
                       aria-expanded="false"
                       aria-haspopup="true"
                       data-bs-toggle="dropdown" 
                       data-bs-toggle="tooltip" 
                       title="<?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span class="badge" id="notificationBadge" aria-live="polite" aria-atomic="true">0</span>
                        <span class="visually-hidden"><?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end notifications-dropdown" aria-labelledby="notificationsDropdown">
                        <li><h6 class="dropdown-header">
                            <?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>
                            <button type="button" 
                                    class="btn btn-sm btn-link text-danger float-end p-0 ms-2" 
                                    id="clearAllNotificationsBtn" 
                                    title="مسح كل الإشعارات" 
                                    aria-label="<?php echo isset($lang['clear_all_notifications']) ? $lang['clear_all_notifications'] : 'مسح كل الإشعارات'; ?>"
                                    style="font-size: 11px; text-decoration: none;">
                                <i class="bi bi-trash" aria-hidden="true"></i> 
                                <span>مسح الكل</span>
                            </button>
                        </h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><div class="dropdown-item-text text-center" id="notificationsList">
                            <small class="text-muted"><?php echo isset($lang['loading']) ? $lang['loading'] : 'جاري التحميل...'; ?></small>
                        </div></li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Refresh Page Button -->
                <a href="#" 
                   class="topbar-action" 
                   id="refreshPageBtn" 
                   role="button" 
                   data-bs-toggle="tooltip" 
                   title="<?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?>" 
                   aria-label="<?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?>"
                   onclick="event.preventDefault(); window.location.reload(); return false;">
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                    <span class="visually-hidden"><?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?></span>
                </a>
                
                <!-- Dark Mode Toggle -->
                <div class="topbar-action" data-bs-toggle="tooltip" title="<?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>">
                    <div class="form-check form-switch mb-0">
                        <label for="darkModeToggle" class="visually-hidden">
                            <?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>
                        </label>
                        <input class="form-check-input" 
                               type="checkbox" 
                               id="darkModeToggle" 
                               aria-label="<?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>"
                               aria-pressed="false"
                               style="cursor: pointer;">
                    </div>
                </div>
                
                <!-- User Avatar -->
                <?php 
                // التأكد من أن $currentUser موجود
                if (!isset($currentUser) || $currentUser === null) {
                    $currentUser = getCurrentUser();
                }
                if (isLoggedIn() && $currentUser): 
                    $userFullName = htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? '');
                ?>
                <div class="topbar-dropdown">
                    <div class="topbar-user dropdown-toggle" 
                         id="userDropdown" 
                         data-bs-toggle="dropdown" 
                         role="button"
                         aria-label="<?php echo isset($lang['user_menu']) ? $lang['user_menu'] : 'قائمة المستخدم'; ?>"
                         aria-expanded="false"
                         aria-haspopup="true">
                        <?php if (isset($currentUser['profile_photo']) && !empty($currentUser['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($currentUser['profile_photo'], ENT_QUOTES, 'UTF-8'); ?>" 
                                 alt="<?php echo $userFullName; ?>"
                                 width="40"
                                 height="40"
                                 loading="lazy"
                                 decoding="async">
                        <?php else: ?>
                            <span aria-hidden="true"><?php echo htmlspecialchars(mb_substr(isset($currentUser['username']) ? $currentUser['username'] : '', 0, 1)); ?></span>
                            <span class="visually-hidden"><?php echo $userFullName; ?></span>
                        <?php endif; ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li class="px-3 py-2">
                            <div class="fw-bold"><?php echo htmlspecialchars(isset($currentUser['username']) ? $currentUser['username'] : ''); ?></div>
                            <small class="text-muted"><?php 
                                $userRole = isset($currentUser['role']) ? $currentUser['role'] : '';
                                echo isset($lang['role_' . $userRole]) ? $lang['role_' . $userRole] : ($userRole ? ucfirst($userRole) : ''); 
                            ?></small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('profile.php'); ?>"><i class="bi bi-person me-2"></i><?php echo isset($lang['profile']) ? $lang['profile'] : 'الملف الشخصي'; ?></a></li>
                        <?php if ((isset($currentUser['role']) ? $currentUser['role'] : '') !== 'manager'): ?>
                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('attendance.php'); ?>"><i class="bi bi-calendar-check me-2"></i><?php echo isset($lang['attendance']) ? $lang['attendance'] : 'الحضور والانصراف'; ?></a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('logout.php'); ?>"><i class="bi bi-box-arrow-right me-2"></i><?php echo isset($lang['logout']) ? $lang['logout'] : 'تسجيل الخروج'; ?></a></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Main Content Area -->
        <main class="dashboard-main" id="main-content" role="main" aria-label="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'المحتوى الرئيسي'; ?>">

