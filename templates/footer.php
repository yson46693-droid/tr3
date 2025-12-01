            
<?php


if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}
?>
    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light border-top safe-area-bottom">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 text-center text-md-start">
                    <small class="text-muted">
                        &copy; <?php echo date('Y'); ?> <?php echo COMPANY_NAME; ?>. <?php echo $lang['all_rights_reserved'] ?? 'جميع الحقوق محفوظة'; ?>
                    </small>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <small class="text-muted">
                        <?php
                        $appInfo = APP_NAME === COMPANY_NAME
                            ? 'v' . APP_VERSION
                            : APP_NAME . ' v' . APP_VERSION;
                        echo $appInfo;
                        ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Install Banner -->
    <div class="install-banner" id="installBanner">
        <div class="d-flex align-items-center justify-content-between">
            <div class="flex-grow-1">
                <strong><i class="bi bi-download me-2"></i>تثبيت التطبيق</strong>
                <p class="mb-0 small">ثبت التطبيق للوصول السريع والاستخدام بدون إنترنت</p>
            </div>
            <button class="btn btn-light btn-sm" id="installButton">
                <i class="bi bi-plus-circle me-1"></i>تثبيت
            </button>
        </div>
        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" id="dismissInstallBanner" aria-label="إغلاق"></button>
    </div>

    <div id="pwa-modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
        <div id="pwa-modal">
            <button type="button" data-modal-close>إغلاق</button>
            <iframe src="about:blank" title="Embedded content"></iframe>
        </div>
    </div>
    
    <?php
    // استخدام نفس cache version من header.php لتحسين caching
    $cacheVersion = defined('ASSETS_VERSION') ? ASSETS_VERSION : (defined('APP_VERSION') ? APP_VERSION : '1.0.0');
    ?>
    <!-- Performance: Load jQuery with defer for better performance -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" defer crossorigin="anonymous"></script>
    <script>
        // الانتظار حتى تحميل jQuery
        (function() {
            function initJQuery() {
                if (typeof jQuery === 'undefined' && typeof $ === 'undefined') {
                    setTimeout(initJQuery, 50);
                    return;
                }
                // التأكد من أن jQuery متاح عالمياً
                if (typeof window.jQuery === 'undefined') {
                    window.jQuery = typeof jQuery !== 'undefined' ? jQuery : (typeof $ !== 'undefined' ? $ : null);
                }
                if (typeof window.$ === 'undefined') {
                    window.$ = typeof $ !== 'undefined' ? $ : (typeof jQuery !== 'undefined' ? jQuery : null);
                }
            }
            window.addEventListener('load', function() {
                setTimeout(initJQuery, 100);
            });
        })();
    </script>
    <!-- Performance: Load Bootstrap JS with defer -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer crossorigin="anonymous"></script>
    <!-- Custom JS -->
    <?php
    // التأكد من أن ASSETS_URL صحيح
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
    // كشف الموبايل لتحسين الأداء (نفس المستخدم في header.php)
    if (!isset($isMobile)) {
        $isMobile = (bool) preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    ?>
    
    <!-- Critical JS - تحميل مباشر -->
    <script src="<?php echo $assetsUrl; ?>js/main.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/sidebar.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Medium Priority JS - تحميل مباشر -->
    <script src="<?php echo $assetsUrl; ?>js/fix-modal-interaction.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/notifications.js?v=<?php echo $cacheVersion; ?>" defer></script>
    
    <!-- Low Priority JS - تحميل متأخر على الموبايل -->
    <?php if (!$isMobile): ?>
    <!-- Desktop: تحميل جميع الملفات -->
    <script src="<?php echo $assetsUrl; ?>js/tables.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/dark-mode.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/pwa-install.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/modal-link-interceptor.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo $assetsUrl; ?>js/keyboard-shortcuts-global.js?v=<?php echo $cacheVersion; ?>" defer></script>
    <?php else: ?>
    <!-- Mobile: تحميل متأخر للـ JS غير الحرجة -->
    <script>
        // تحميل JS غير الحرجة بعد تحميل الصفحة على الموبايل
        window.addEventListener('load', function() {
            setTimeout(function() {
                const scripts = [
                    '<?php echo $assetsUrl; ?>js/tables.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/dark-mode.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/pwa-install.js?v=<?php echo $cacheVersion; ?>',
                    '<?php echo $assetsUrl; ?>js/modal-link-interceptor.js?v=<?php echo $cacheVersion; ?>'
                ];
                
                scripts.forEach(function(src) {
                    const script = document.createElement('script');
                    script.src = src;
                    script.defer = true;
                    document.body.appendChild(script);
                });
            }, 1000); // بعد ثانية من تحميل الصفحة
        });
    </script>
    <?php endif; ?>
    <script>
    // التحقق من تحميل ملفات JavaScript بشكل صحيح
    (function() {
        const scripts = document.querySelectorAll('script[src*=".js"]');
        scripts.forEach(function(script) {
            script.addEventListener('error', function() {
                console.error('Failed to load script:', script.src);
                // محاولة تحميل من مسار بديل
                const src = script.getAttribute('src');
                if (src && !src.startsWith('http')) {
                    const basePath = '<?php echo getBasePath(); ?>';
                    const fallbackSrc = (basePath ? basePath : '') + src.replace(/^\/[^\/]+/, '/assets');
                    console.warn('Trying fallback path:', fallbackSrc);
                }
            });
        });
    })();
    </script>
    
    <?php if (isset($extraScripts)): ?>
        <?php foreach ($extraScripts as $script): ?>
            <script src="<?php echo $script; ?>" defer></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
        // تنظيف console.log في production (Best Practices)
        (function() {
            const isProduction = window.location.hostname !== 'localhost' && 
                                 window.location.hostname !== '127.0.0.1' && 
                                 !window.location.hostname.includes('.local');
            
            if (isProduction && typeof console !== 'undefined') {
                const noop = function() {};
                // الاحتفاظ بـ console.error للخطوط الحقيقية
                console.log = noop;
                console.debug = noop;
                console.info = noop;
                // console.warn و console.error تبقى كما هي للأخطاء المهمة
            }
        })();
        
        // تهيئة النظام
        document.addEventListener('DOMContentLoaded', function() {
            // تحميل العمليات الخلفية بشكل غير متزامن (بعد تحميل الصفحة)
            // هذا يحسن الأداء عن طريق تأخير العمليات الثقيلة
            setTimeout(function() {
                try {
                    // حساب مسار API
                    const currentPath = window.location.pathname;
                    const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                    let apiPath = '/api/background-tasks.php';
                    if (pathParts.length > 0) {
                        apiPath = '/' + pathParts[0] + '/api/background-tasks.php';
                    }
                    
                    // استدعاء API للعمليات الخلفية (بدون انتظار النتيجة)
                    fetch(apiPath, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-cache',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).catch(function(error) {
                        // تجاهل الأخطاء بصمت - العمليات الخلفية اختيارية
                        console.log('Background tasks skipped:', error.message);
                    });
                } catch (error) {
                    // تجاهل الأخطاء
                    console.log('Background tasks error:', error.message);
                }
            }, 2000); // بعد ثانيتين من تحميل الصفحة
            
            // إغلاق القائمة المنسدلة عند النقر على أي رابط
            const mainMenuDropdown = document.getElementById('mainMenuDropdown');
            const mainMenuDropdownMenu = document.querySelector('.main-menu-dropdown');
            
            if (mainMenuDropdown && mainMenuDropdownMenu) {
                // إغلاق القائمة عند النقر على أي رابط
                const menuLinks = mainMenuDropdownMenu.querySelectorAll('.dropdown-item');
                menuLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        // إغلاق القائمة باستخدام Bootstrap
                        const dropdownInstance = bootstrap.Dropdown.getInstance(mainMenuDropdown);
                        if (dropdownInstance) {
                            dropdownInstance.hide();
                        }
                    });
                });
                
                // إغلاق القائمة عند النقر خارجها
                document.addEventListener('click', function(event) {
                    if (!mainMenuDropdown.contains(event.target) && !mainMenuDropdownMenu.contains(event.target)) {
                        const dropdownInstance = bootstrap.Dropdown.getInstance(mainMenuDropdown);
                        if (dropdownInstance && mainMenuDropdownMenu.classList.contains('show')) {
                            dropdownInstance.hide();
                        }
                    }
                });
            }
            
            // إعداد معلمات الإشعارات العالمية
            window.NOTIFICATION_POLL_INTERVAL = <?php echo (int) NOTIFICATION_POLL_INTERVAL; ?>;
            window.NOTIFICATION_AUTO_REFRESH_ENABLED = <?php echo NOTIFICATION_AUTO_REFRESH_ENABLED ? 'true' : 'false'; ?>;
            window.NOTIFICATION_POLL_INTERVAL = Number(window.NOTIFICATION_POLL_INTERVAL) || 60000;
            if (typeof loadNotifications === 'function') {
                if (!window.__notificationInitialLoadDone) {
                    loadNotifications();
                    window.__notificationInitialLoadDone = true;
                }
            }
            
            // تهيئة نظام التحقق من التحديثات
            initUpdateChecker();
        });
        
        // Register Service Worker (يتم تسجيله في header.php)
        
        // Offline Detection
        const offlineIndicator = document.getElementById('offlineIndicator');
        if (offlineIndicator) {
            window.addEventListener('online', () => {
                offlineIndicator.classList.remove('show');
            });
            
            window.addEventListener('offline', () => {
                offlineIndicator.classList.add('show');
            });
        }
        
        /**
         * نظام التحقق من التحديثات
         */
        function initUpdateChecker() {
            const STORAGE_KEY = 'app_last_version';
            const VERSION_STORAGE_KEY = 'app_display_version';
            const LAST_CHECK_KEY = 'app_last_update_check';
            const CHECK_INTERVAL = 30 * 60 * 1000; // كل 30 دقيقة
            const MIN_MANUAL_INTERVAL = 5 * 60 * 1000; // الحد الأدنى بين التحقق اليدوي
            let updateCheckInterval = null;
            let updateCheckTimeout = null;
            let isChecking = false;
            
            // حساب مسار API
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            let apiPath = '/api/check_update.php';
            if (pathParts.length > 0) {
                apiPath = '/' + pathParts[0] + '/api/check_update.php';
            }
            
            /**
             * التحقق من وجود تحديثات
             */
            async function checkForUpdates() {
                if (isChecking) return;
                isChecking = true;
                
                try {
                    const response = await fetch(apiPath + '?t=' + Date.now(), {
                        method: 'GET',
                        cache: 'no-cache',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('Failed to check for updates');
                    }
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const currentHash = data.content_hash || data.version || data.last_modified;
                        const storedHash = localStorage.getItem(STORAGE_KEY);
                        const storedDisplay = localStorage.getItem(VERSION_STORAGE_KEY) || '';
                        const serverVersion = (data.version || '').toString().trim();
                        let displayVersion = storedDisplay || serverVersion || 'جديد';

                        if (storedHash && storedHash !== currentHash) {
                            displayVersion = serverVersion || 'جديد';
                            showUpdateAvailableNotification(displayVersion);
                        }

                        localStorage.setItem(STORAGE_KEY, currentHash);
                        localStorage.setItem(VERSION_STORAGE_KEY, displayVersion);
                    }
                } catch (error) {
                    console.log('Update check error:', error);
                } finally {
                    try {
                        localStorage.setItem(LAST_CHECK_KEY, Date.now().toString());
                    } catch (storageError) {
                        console.log('Update check storage error:', storageError);
                    }
                    isChecking = false;
                }
            }
            
            /**
             * إظهار إشعار التحديث
             */
            function showUpdateAvailableNotification(version) {
                // التحقق من عدم وجود إشعار موجود بالفعل
                if (document.getElementById('updateNotification')) {
                    return;
                }
                
                const notification = document.createElement('div');
                notification.id = 'updateNotification';
                notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
                
                const displayVersion = (version || '').toString().trim() || 'جديد';
                localStorage.setItem(VERSION_STORAGE_KEY, displayVersion);
                
                notification.innerHTML = `
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-arrow-clockwise me-2 fs-5"></i>
                                <strong>تحديث متاح!</strong>
                            </div>
                            <p class="mb-2 small">يتوفر تحديث جديد للموقع. يرجى تحديث الصفحة للحصول على أحدث الميزات.</p>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-primary" onclick="refreshPage()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>تحديث الآن
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="dismissUpdateNotification()">
                                    لاحقاً
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn-close ms-2" onclick="dismissUpdateNotification()" aria-label="إغلاق"></button>
                    </div>
                `;
                
                document.body.appendChild(notification);
                
                // إضافة دوال عامة
                window.refreshPage = function() {
                    // إزالة cache
                    if ('caches' in window) {
                        caches.keys().then(names => {
                            names.forEach(name => {
                                caches.delete(name);
                            });
                        });
                    }
                    // تحديث الصفحة
                    window.location.reload(true);
                };
                
                window.dismissUpdateNotification = function() {
                    const notif = document.getElementById('updateNotification');
                    if (notif) {
                        notif.classList.remove('show');
                        setTimeout(() => notif.remove(), 300);
                    }
                };
                
                notification.dataset.version = version;
                
                // إزالة الإشعار تلقائياً بعد 60 ثانية
                setTimeout(() => {
                    window.dismissUpdateNotification();
                }, 60000);
            }
            
            function getLastCheckTimestamp() {
                try {
                    const raw = localStorage.getItem(LAST_CHECK_KEY);
                    const parsed = raw ? parseInt(raw, 10) : 0;
                    return Number.isFinite(parsed) ? parsed : 0;
                } catch (error) {
                    return 0;
                }
            }

            function shouldCheckNow(minInterval = CHECK_INTERVAL) {
                const lastCheck = getLastCheckTimestamp();
                if (!lastCheck) {
                    return true;
                }
                return (Date.now() - lastCheck) >= minInterval;
            }

            function scheduleBackgroundChecks() {
                if (updateCheckTimeout) {
                    clearTimeout(updateCheckTimeout);
                    updateCheckTimeout = null;
                }
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                    updateCheckInterval = null;
                }

                if (shouldCheckNow()) {
                    checkForUpdates();
                    updateCheckInterval = setInterval(checkForUpdates, CHECK_INTERVAL);
                } else {
                    const lastCheck = getLastCheckTimestamp();
                    const elapsed = Date.now() - lastCheck;
                    const remaining = Math.max(CHECK_INTERVAL - elapsed, MIN_MANUAL_INTERVAL);
                    updateCheckTimeout = setTimeout(function() {
                        checkForUpdates();
                        updateCheckInterval = setInterval(checkForUpdates, CHECK_INTERVAL);
                    }, remaining);
                }
            }

            scheduleBackgroundChecks();
            
            // التحقق عند إعادة التركيز على النافذة
            window.addEventListener('focus', function() {
                if (!isChecking && shouldCheckNow(MIN_MANUAL_INTERVAL)) {
                    checkForUpdates();
                }
            });
            
            // التحقق عند الاتصال بالإنترنت
            window.addEventListener('online', function() {
                if (!isChecking && shouldCheckNow(MIN_MANUAL_INTERVAL)) {
                    setTimeout(checkForUpdates, 2000);
                }
            });
            
            // تنظيف عند إغلاق الصفحة
            window.addEventListener('beforeunload', function() {
                if (updateCheckInterval) {
                    clearInterval(updateCheckInterval);
                    updateCheckInterval = null;
                }
                if (updateCheckTimeout) {
                    clearTimeout(updateCheckTimeout);
                    updateCheckTimeout = null;
                }
            });
        }
    </script>
    
    <!-- 🎬 Page Loading Animation Script -->
    <script>
        (function() {
            'use strict';
            
            const pageLoader = document.getElementById('pageLoader');
            const dashboardMain = document.querySelector('.dashboard-main');
            
            if (!pageLoader) {
                return;
            }
            
            // التأكد من أن pageLoader.style موجود
            if (!pageLoader.style) {
                console.warn('pageLoader element found but style property is not available');
                return;
            }
            
            // PWA Splash Screen - استخدام قاعدة البيانات لإدارة الجلسات
            let splashSessionToken = sessionStorage.getItem('pwaSplashToken');
            let inactivityTimer = null;
            let deleteSessionOnUnload = false;
            
            function hideSplashScreen() {
                setTimeout(function() {
                    pageLoader.classList.add('hidden');
                    
                    // إضافة تأثير fade-in للمحتوى
                    if (dashboardMain) {
                        dashboardMain.classList.add('content-fade-in');
                    }
                    
                    // إزالة شاشة التحميل من DOM بعد انتهاء التأثير
                    setTimeout(function() {
                        if (pageLoader && pageLoader.style) {
                            pageLoader.style.display = 'none';
                        }
                    }, 500);
                }, 100); // تقليل التأخير إلى 100ms للاستجابة السريعة
            }
            
            // حذف الجلسة من قاعدة البيانات
            function deleteSplashSession(token) {
                if (!token) return;
                
                fetch('<?php echo getRelativeUrl("api/pwa_splash_session.php"); ?>?action=delete&token=' + encodeURIComponent(token), {
                    method: 'GET',
                    credentials: 'same-origin'
                }).catch(function(err) {
                    console.log('Failed to delete splash session:', err);
                });
                
                sessionStorage.removeItem('pwaSplashToken');
                splashSessionToken = null;
            }
            
            // إنشاء جلسة جديدة في قاعدة البيانات
            function createSplashSession() {
                return fetch('<?php echo getRelativeUrl("api/pwa_splash_session.php"); ?>?action=create', {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.success && data.token) {
                        splashSessionToken = data.token;
                        sessionStorage.setItem('pwaSplashToken', data.token);
                        return data.token;
                    }
                    return null;
                })
                .catch(function(err) {
                    console.log('Failed to create splash session:', err);
                    return null;
                });
            }
            
            // التحقق من وجود جلسة نشطة
            function checkSplashSession(token) {
                if (!token) return Promise.resolve(false);
                
                return fetch('<?php echo getRelativeUrl("api/pwa_splash_session.php"); ?>?action=check&token=' + encodeURIComponent(token), {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    return data.exists === true;
                })
                .catch(function(err) {
                    console.log('Failed to check splash session:', err);
                    return false;
                });
            }
            
            // إعادة تعيين مؤقت الخمول
            function resetInactivityTimer() {
                if (inactivityTimer) {
                    clearTimeout(inactivityTimer);
                }
                
                // حذف الجلسة بعد 3 ثواني من الخمول
                inactivityTimer = setTimeout(function() {
                    if (splashSessionToken) {
                        deleteSplashSession(splashSessionToken);
                    }
                }, 3000);
            }
            
            // مراقبة النشاط
            // إضافة دالة throttled للأحداث الثقيلة
            let inactivityTimerThrottle = null;
            function throttledResetInactivityTimer() {
                if (inactivityTimerThrottle) {
                    return; // تخطي إذا كان هناك استدعاء قريب
                }
                inactivityTimerThrottle = setTimeout(function() {
                    resetInactivityTimer();
                    inactivityTimerThrottle = null;
                }, 100); // throttle إلى 100ms
            }
            
            // الأحداث الثقيلة مع throttling و passive
            ['mousemove', 'scroll'].forEach(function(event) {
                document.addEventListener(event, throttledResetInactivityTimer, { passive: true });
            });
            
            // الأحداث الأخرى بدون throttling
            ['mousedown', 'keypress', 'touchstart'].forEach(function(event) {
                document.addEventListener(event, resetInactivityTimer, true);
            });
            
            // استخدام pageshow event للتحقق من أن الصفحة تم تحميلها من جديد
            window.addEventListener('pageshow', function(event) {
                // إذا كانت الصفحة من cache (back/forward)، لا تظهر splash screen
                if (event.persisted && pageLoader) {
                    if (pageLoader.style) {
                        pageLoader.style.display = 'none';
                    }
                    pageLoader.classList.add('hidden');
                    if (dashboardMain) {
                        dashboardMain.classList.add('content-fade-in');
                    }
                    return;
                }
                
                // التحقق من أن الصفحة قادمة من رابط داخلي
                const isInternalNavigation = sessionStorage.getItem('internalNavigation') === 'true';
                sessionStorage.removeItem('internalNavigation'); // تنظيف بعد الاستخدام
                
                // إذا كان التنقل داخلي، تخطي منطق الجلسات بالكامل
                if (isInternalNavigation) {
                    if (pageLoader) {
                        pageLoader.classList.add('hidden');
                        if (pageLoader.style) {
                            pageLoader.style.display = 'none';
                        }
                    }
                    if (dashboardMain) {
                        dashboardMain.classList.add('content-fade-in');
                    }
                    return; // تخطي كل منطق الجلسات والـ API calls
                }
                
                // إذا كانت الصفحة جديدة (ليست من cache)، امسح الجلسة وأظهر splash screen
                if (splashSessionToken) {
                    deleteSplashSession(splashSessionToken);
                }
                
                // إنشاء جلسة جديدة وإظهار splash screen
                createSplashSession().then(function(token) {
                    if (token && pageLoader) {
                        pageLoader.classList.remove('hidden');
                        if (pageLoader.style) {
                            pageLoader.style.display = 'flex';
                        }
                        resetInactivityTimer();
                        
                        // إخفاء الشاشة بعد تحميل الصفحة
                        if (document.readyState === 'complete') {
                            hideSplashScreen();
                        } else {
                            window.addEventListener('load', hideSplashScreen);
                        }
                    } else {
                        // إذا فشل إنشاء الجلسة، أخفي splash screen
                        hideSplashScreen();
                    }
                }).catch(function() {
                    // إذا فشل API، أخفي splash screen
                    hideSplashScreen();
                });
            });
            
            // التحقق من الجلسة عند تحميل الصفحة لأول مرة
            // استخدام timeout لضمان إخفاء splash screen حتى لو فشل API
            let splashCheckTimeout = setTimeout(function() {
                // إذا استغرق التحقق أكثر من 2 ثانية، أخفي splash screen تلقائياً
                if (pageLoader && !pageLoader.classList.contains('hidden')) {
                    hideSplashScreen();
                }
            }, 2000);
            
            if (splashSessionToken) {
                checkSplashSession(splashSessionToken).then(function(exists) {
                    clearTimeout(splashCheckTimeout);
                    
                    if (exists && pageLoader) {
                        // الجلسة موجودة، لا تظهر splash screen
                        if (pageLoader.style) {
                            pageLoader.style.display = 'none';
                        }
                        pageLoader.classList.add('hidden');
                        
                        if (dashboardMain) {
                            dashboardMain.classList.add('content-fade-in');
                        }
                        
                        resetInactivityTimer();
                    } else {
                        // الجلسة غير موجودة، أنشئ جلسة جديدة وأظهر splash screen
                        createSplashSession().then(function(token) {
                            if (token && pageLoader) {
                                pageLoader.classList.remove('hidden');
                                if (pageLoader.style) {
                                    pageLoader.style.display = 'flex';
                                }
                                resetInactivityTimer();
                                
                                if (document.readyState === 'complete') {
                                    hideSplashScreen();
                                } else if (document.readyState === 'interactive') {
                                    window.addEventListener('load', hideSplashScreen);
                                } else {
                                    window.addEventListener('load', hideSplashScreen);
                                }
                            } else {
                                // إذا فشل إنشاء الجلسة، أخفي splash screen
                                hideSplashScreen();
                            }
                        }).catch(function() {
                            // إذا فشل API، أخفي splash screen
                            hideSplashScreen();
                        });
                    }
                }).catch(function() {
                    // إذا فشل API، أخفي splash screen
                    clearTimeout(splashCheckTimeout);
                    hideSplashScreen();
                });
            } else {
                // لا توجد جلسة، أنشئ جلسة جديدة وأظهر splash screen
                createSplashSession().then(function(token) {
                    clearTimeout(splashCheckTimeout);
                    
                    if (token && pageLoader) {
                        pageLoader.classList.remove('hidden');
                        if (pageLoader.style) {
                            pageLoader.style.display = 'flex';
                        }
                        resetInactivityTimer();
                        
                        if (document.readyState === 'complete') {
                            hideSplashScreen();
                        } else if (document.readyState === 'interactive') {
                            window.addEventListener('load', hideSplashScreen);
                        } else {
                            window.addEventListener('load', hideSplashScreen);
                        }
                    } else {
                        // إذا فشل إنشاء الجلسة، أخفي splash screen
                        hideSplashScreen();
                    }
                }).catch(function() {
                    // إذا فشل API، أخفي splash screen
                    clearTimeout(splashCheckTimeout);
                    hideSplashScreen();
                });
            }
            
            // حذف الجلسة عند إغلاق التطبيق
            window.addEventListener('beforeunload', function() {
                if (splashSessionToken) {
                    deleteSplashSession(splashSessionToken);
                }
            });
            
            // حذف الجلسة عند فقدان التركيز (إغلاق التطبيق)
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    deleteSessionOnUnload = true;
                    // حذف الجلسة بعد 3 ثواني من فقدان التركيز
                    setTimeout(function() {
                        if (deleteSessionOnUnload && splashSessionToken) {
                            deleteSplashSession(splashSessionToken);
                        }
                    }, 3000);
                } else {
                    deleteSessionOnUnload = false;
                    resetInactivityTimer();
                }
            });
            
            // إخفاء شاشة التحميل عند فتح أي Modal
            document.addEventListener('show.bs.modal', function() {
                if (pageLoader) {
                    pageLoader.classList.add('hidden');
                    if (pageLoader.style) {
                        pageLoader.style.display = 'none';
                    }
                }
            });
            
            // تعطيل شاشة التحميل عند التنقل بين الأقسام لتجنب التجميد
            // فقط للروابط الخارجية التي تغير الصفحة بالكامل
            let isNavigating = false;
            document.addEventListener('click', function(e) {
                const link = e.target.closest('a');
                
                // تخطي الروابط التي لديها data-no-splash (روابط الشريط الجانبي)
                if (link && link.hasAttribute('data-no-splash')) {
                    // تسجيل أن هذا تنقل داخلي لتخطي منطق الجلسات في pageshow
                    sessionStorage.setItem('internalNavigation', 'true');
                    return; // لا تعرض شاشة التحميل للروابط الداخلية
                }
                
                // تحقق من أن الرابط يؤدي لتغيير الصفحة الكامل (ليس tabs أو sections)
                if (link && 
                    link.href && 
                    !link.href.includes('section=') && // تجاهل روابط الأقسام
                    !link.href.includes('&tab=') && 
                    !link.href.includes('#') &&
                    !link.href.startsWith('javascript:') &&
                    !link.href.startsWith('mailto:') &&
                    !link.href.startsWith('tel:') &&
                    !link.target &&
                    !link.download &&
                    link.hostname === window.location.hostname &&
                    !link.hasAttribute('data-bs-toggle') && 
                    !link.hasAttribute('data-bs-target') &&
                    !link.classList.contains('dropdown-item') &&
                    !link.closest('.nav-tabs') && // تجاهل روابط التبويبات
                    !link.closest('.section-tabs') && // تجاهل روابط أقسام المخزن
                    !isNavigating) {
                    
                    isNavigating = true;
                    // إظهار شاشة التحميل فقط للتنقل بين الصفحات الرئيسية
                    if (pageLoader) {
                        pageLoader.classList.remove('hidden');
                        if (pageLoader.style) {
                            pageLoader.style.display = 'flex';
                        }
                    }
                }
            });
            
            // إخفاء شاشة التحميل عند الرجوع للصفحة
            window.addEventListener('pageshow', function(event) {
                isNavigating = false;
                if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                    if (pageLoader) {
                        pageLoader.classList.add('hidden');
                        if (pageLoader.style) {
                            pageLoader.style.display = 'none';
                        }
                    }
                }
            });
            
        })();
    </script>
    
    <?php if (isset($currentUser) && ($currentUser['role'] ?? '') === 'manager'): ?>
    <script>
    /**
     * تحديث عداد الموافقات المعلقة للمديرين
     */
    (function() {
        async function updateApprovalBadge() {
            try {
                const badge = document.getElementById('approvalBadge');
                if (!badge) {
                    return;
                }
                
                const basePath = '<?php echo getBasePath(); ?>';
                const apiPath = basePath + '/api/approvals.php';
                const response = await fetch(apiPath, {
                    credentials: 'same-origin',
                    cache: 'no-cache',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                if (!response.ok) {
                    return;
                }
                
                // التحقق من content-type قبل parse JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('updateApprovalBadge: Expected JSON but got', contentType);
                    return;
                }
                
                const text = await response.text();
                if (!text || text.trim().startsWith('<')) {
                    console.warn('updateApprovalBadge: Received HTML instead of JSON');
                    return;
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.warn('updateApprovalBadge: Failed to parse JSON:', parseError);
                    return;
                }
                
                if (data && data.success && typeof data.count === 'number') {
                    const count = Math.max(0, parseInt(data.count, 10));
                    if (badge) {
                        badge.textContent = count.toString();
                        if (badge.style) {
                            if (count > 0) {
                                badge.style.display = 'inline-block';
                                badge.classList.add('badge-danger', 'bg-danger');
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    }
                }
            } catch (error) {
                // تجاهل الأخطاء بصمت لتجنب إزعاج المستخدم
                if (error.name !== 'SyntaxError') {
                    console.error('Error updating approval badge:', error);
                }
            }
        }
        
        // تحديث العداد عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                updateApprovalBadge();
                // تحديث العداد كل 30 ثانية
                setInterval(updateApprovalBadge, 30000);
            });
        } else {
            updateApprovalBadge();
            // تحديث العداد كل 30 ثانية
            setInterval(updateApprovalBadge, 30000);
        }
        
        // تحديث العداد عند استلام حدث
        document.addEventListener('approvalUpdated', function() {
            setTimeout(updateApprovalBadge, 1000);
        });
    })();
    </script>
    <?php endif; ?>
        </main>
    </div>
</body>
</html>

