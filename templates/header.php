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

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/packaging_alerts.php';
require_once __DIR__ . '/../includes/payment_schedules.php';

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
$currentUser = getCurrentUser();
$currentUserRole = strtolower((string) ($currentUser['role'] ?? ''));
if ($currentUser && function_exists('handleAttendanceRemindersForUser')) {
    handleAttendanceRemindersForUser($currentUser);
}

if (function_exists('processDailyPackagingAlert')) {
    processDailyPackagingAlert();
}

if ($currentUser && $currentUserRole === 'sales') {
    try {
        notifyTodayPaymentSchedules((int) $currentUser['id']);
    } catch (Throwable $paymentNotificationError) {
        error_log('Sales payment notification error: ' . $paymentNotificationError->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS - Homeline Dashboard Design -->
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
    ?>
    <?php
    // استخدام timestamp لـ cache busting
    $cacheVersion = time(); // أو يمكن استخدام رقم version ثابت وتحديثه يدوياً
    ?>
    <link href="<?php echo $assetsUrl; ?>css/homeline-dashboard.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/sidebar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/topbar.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/cards.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/responsive.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/mobile-tables.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/pwa.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo $assetsUrl; ?>css/dark-mode.css?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
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
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo ASSETS_URL; ?>icons/favicon.svg">
    <link rel="icon" type="image/svg+xml" sizes="32x32" href="<?php echo ASSETS_URL; ?>icons/icon-32x32.svg">
    <link rel="icon" type="image/svg+xml" sizes="16x16" href="<?php echo ASSETS_URL; ?>icons/icon-16x16.svg">
    <?php if (file_exists(__DIR__ . '/../favicon.ico')): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo getRelativeUrl('favicon.ico'); ?>">
    <link rel="shortcut icon" href="<?php echo getRelativeUrl('favicon.ico'); ?>">
    <?php endif; ?>
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo ASSETS_URL; ?>icons/apple-touch-icon.svg">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo ASSETS_URL; ?>icons/icon-152x152.svg">
    <link rel="apple-touch-icon" sizes="144x144" href="<?php echo ASSETS_URL; ?>icons/icon-144x144.svg">
    
    <!-- Android Icons -->
    <link rel="icon" type="image/svg+xml" sizes="192x192" href="<?php echo ASSETS_URL; ?>icons/icon-192x192.svg">
    <link rel="icon" type="image/svg+xml" sizes="512x512" href="<?php echo ASSETS_URL; ?>icons/icon-512x512.svg">
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#1e3a5f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo APP_NAME; ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo APP_NAME; ?>">
    <link rel="apple-touch-icon" href="<?php echo ASSETS_URL; ?>icons/icon-192x192.png">
    
    <!-- Manifest -->
    <link rel="manifest" href="<?php echo getRelativeUrl('manifest.json'); ?>">
    
    <!-- 🎬 Page Loading Animation CSS -->
    <style>
        /* شاشة التحميل الرئيسية - تدرجات أزرق متناسقة */
        #pageLoader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 50%, #667eea 100%);
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
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        
        #pageLoader:not(.hidden) {
            pointer-events: all;
        }
        
        /* التأكد من أن الـ Modal قابل للنقر */
        .modal {
            z-index: 1050 !important;
        }
        
        .modal-backdrop {
            z-index: 1040 !important;
            pointer-events: none !important;
            opacity: 1 !important;
            background-color: transparent !important;
        }
        
        .modal .modal-content {
            position: relative;
            z-index: 1060;
        }
        
        .modal-dialog {
            pointer-events: all !important;
            margin-top: 60px !important;
        }
        
        /* الشعار أو الأيقونة */
        .loader-icon {
            font-size: 4.5rem;
            color: white;
            animation: pulse 1.5s ease-in-out infinite, float 3s ease-in-out infinite;
            margin-bottom: 1.5rem;
            filter: drop-shadow(0 4px 15px rgba(255, 255, 255, 0.4));
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        
        /* العنوان */
        .loader-title {
            color: white;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 2.5rem;
            letter-spacing: 3px;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            animation: titleGlow 2s ease-in-out infinite;
        }
        
        @keyframes titleGlow {
            0%, 100% { text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); }
            50% { text-shadow: 0 4px 30px rgba(255, 255, 255, 0.6), 0 0 40px rgba(79, 172, 254, 0.8); }
        }
        
        /* حاوية السبينر */
        .loader-spinner {
            position: relative;
            width: 130px;
            height: 130px;
        }
        
        /* الدوائر المتحركة - ألوان أزرق متدرجة */
        .spinner-circle {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 5px solid transparent;
            border-radius: 50%;
            animation: spin 1.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) infinite;
        }
        
        .spinner-circle:nth-child(1) {
            border-top-color: #fff;
            border-right-color: rgba(255, 255, 255, 0.8);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.5);
        }
        
        .spinner-circle:nth-child(2) {
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
                #4facfe 0%, 
                #00f2fe 25%, 
                #fff 50%, 
                #00f2fe 75%, 
                #4facfe 100%
            );
            background-size: 200% 100%;
            animation: progressMove 1.8s ease-in-out infinite;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(79, 172, 254, 0.8), 0 0 30px rgba(0, 242, 254, 0.6);
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
      data-user-role="<?php echo htmlspecialchars($currentUser['role'] ?? ''); ?>"
      data-user-id="<?php echo isset($currentUser['id']) ? (int) $currentUser['id'] : 0; ?>">
    <!-- 🎬 شاشة التحميل -->
    <div id="pageLoader">
        <div class="loader-icon">
            <i class="bi bi-box-seam"></i>
        </div>
        <div class="loader-title"><?php echo APP_NAME; ?></div>
        <div class="loader-spinner">
            <div class="spinner-circle"></div>
            <div class="spinner-circle"></div>
            <div class="spinner-circle"></div>
        </div>
        <div class="loader-text">
            جاري التحميل<span class="loading-dots"><span>.</span><span>.</span><span>.</span></span>
        </div>
        <div class="loader-progress">
            <div class="loader-progress-bar"></div>
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
                 <button class="mobile-menu-toggle d-md-none" id="mobileMenuToggle" type="button">
                     <i class="bi bi-list"></i>
                 </button>
                 <button class="mobile-reload-btn d-md-none" id="mobileReloadBtn" type="button">
                     <i class="bi bi-arrow-clockwise"></i>
                 </button>
                 <button class="mobile-dark-toggle d-md-none" id="mobileDarkToggle" type="button">
                     <i class="bi bi-moon-stars"></i>
                 </button>
                <div class="breadcrumb-nav">
                    <?php 
                    $pageTitleText = isset($pageTitle) ? $pageTitle : (isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم');
                    ?>
                    <a href="<?php echo getDashboardUrl($currentUser['role'] ?? 'accountant'); ?>"><?php echo APP_NAME; ?></a>
                    <span class="mx-2">/</span>
                    <span><?php echo $pageTitleText; ?></span>
                </div>
            </div>
            
            <div class="topbar-center">
                <div class="topbar-search">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث'; ?>" id="globalSearch">
                    <span class="search-shortcut">⌘K</span>
                </div>
            </div>
            
            <div class="topbar-right">
                <!-- Settings -->
                <a href="<?php echo getRelativeUrl('profile.php'); ?>" class="topbar-action" data-bs-toggle="tooltip" title="<?php echo isset($lang['settings']) ? $lang['settings'] : 'الإعدادات'; ?>">
                    <i class="bi bi-gear"></i>
                </a>
                
                <!-- Notifications -->
                <?php if (isLoggedIn()): ?>
                <div class="topbar-dropdown">
                    <a href="#" class="topbar-action" id="notificationsDropdown" role="button" data-bs-toggle="dropdown" data-bs-toggle="tooltip" title="<?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?>">
                        <i class="bi bi-bell"></i>
                        <span class="badge" id="notificationBadge">0</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end notifications-dropdown" aria-labelledby="notificationsDropdown">
                        <li><h6 class="dropdown-header"><?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?></h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><div class="dropdown-item-text text-center" id="notificationsList">
                            <small class="text-muted"><?php echo isset($lang['loading']) ? $lang['loading'] : 'جاري التحميل...'; ?></small>
                        </div></li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Refresh Page Button -->
                <a href="#" class="topbar-action" id="refreshPageBtn" role="button" data-bs-toggle="tooltip" title="<?php echo isset($lang['refresh']) ? $lang['refresh'] : 'تحديث الصفحة'; ?>" onclick="event.preventDefault(); window.location.reload(); return false;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
                
                <!-- Dark Mode Toggle -->
                <div class="topbar-action" data-bs-toggle="tooltip" title="<?php echo isset($lang['dark_mode']) ? $lang['dark_mode'] : 'الوضع الداكن'; ?>">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="darkModeToggle" style="cursor: pointer;">
                    </div>
                </div>
                
                <!-- User Avatar -->
                <?php if (isLoggedIn()): ?>
                <div class="topbar-dropdown">
                    <div class="topbar-user dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" role="button">
                        <?php if (isset($currentUser['profile_photo']) && !empty($currentUser['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($currentUser['profile_photo']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo htmlspecialchars(mb_substr($currentUser['username'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li class="px-3 py-2">
                            <div class="fw-bold"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                            <small class="text-muted"><?php echo isset($lang['role_' . $currentUser['role']]) ? $lang['role_' . $currentUser['role']] : ucfirst($currentUser['role']); ?></small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo getRelativeUrl('profile.php'); ?>"><i class="bi bi-person me-2"></i><?php echo isset($lang['profile']) ? $lang['profile'] : 'الملف الشخصي'; ?></a></li>
                        <?php if ($currentUser['role'] !== 'manager'): ?>
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
        <main class="dashboard-main">

