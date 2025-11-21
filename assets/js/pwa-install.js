/**
 * PWA Install Handler
 */

// استخدام نطاق عام للتأكد من توفر المتغير
if (typeof window.pwaInstallHandler === 'undefined') {
    window.pwaInstallHandler = {};
}

let deferredPrompt = null;

// التحقق من الجهاز المحمول
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
}

// التحقق من iOS
function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

// التحقق من Android
function isAndroid() {
    return /Android/.test(navigator.userAgent);
}

// التحقق من التثبيت
function isInstalled() {
    return window.matchMedia('(display-mode: standalone)').matches ||
           window.navigator.standalone === true ||
           (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
}

// التحقق من حالة إخفاء الإشعار
function shouldShowBanner() {
    try {
        const dismissed = localStorage.getItem('pwa_install_dismissed');
        const dismissedTime = localStorage.getItem('pwa_install_dismissed_time');
        
        if (dismissed === 'true' && dismissedTime) {
            const timeDiff = Date.now() - parseInt(dismissedTime);
            const oneDay = 24 * 60 * 60 * 1000; // يوم واحد بالميلي ثانية
            
            if (timeDiff < oneDay) {
                // تم إخفاء الإشعار منذ أقل من يوم، لا نعرضه
                const remainingHours = Math.ceil((oneDay - timeDiff) / (60 * 60 * 1000));
                console.log(`Install banner was dismissed, will show again in ${remainingHours} hours`);
                return false;
            } else {
                // أكثر من يوم، يمكن إظهاره مرة أخرى - حذف القيم القديمة
                localStorage.removeItem('pwa_install_dismissed');
                localStorage.removeItem('pwa_install_dismissed_time');
                return true;
            }
        }
        return true; // لم يتم إخفاؤه من قبل، يمكن عرضه
    } catch (e) {
        console.error('Error checking dismiss state:', e);
        return true; // في حالة الخطأ، نعرض البانر
    }
}

// إظهار البانر
function showInstallBanner() {
    // التحقق من حالة إخفاء الإشعار أولاً
    if (!shouldShowBanner()) {
        console.log('Install banner was dismissed, not showing');
        return;
    }
    
    const banner = document.getElementById('installBanner');
    if (banner && !isInstalled()) {
        banner.classList.add('show');
        console.log('Install banner shown');
        
        // على الهواتف، إظهار البانر بعد تأخير قصير
        if (isMobileDevice()) {
            // إخفاء البانر تلقائياً بعد 30 ثانية إذا لم يتم التثبيت
            setTimeout(() => {
                if (banner.classList.contains('show') && !isInstalled()) {
                    banner.classList.add('auto-hide');
                    setTimeout(() => {
                        banner.classList.remove('show');
                    }, 500);
                }
            }, 30000);
        }
    }
}

// إخفاء البانر
function hideInstallBanner() {
    const banner = document.getElementById('installBanner');
    if (banner) {
        banner.classList.remove('show');
        console.log('Install banner hidden');
    }
}

// إخفاء البانر إذا كان التطبيق مثبتاً بالفعل
if (isInstalled()) {
    hideInstallBanner();
}

// معالجة حدث beforeinstallprompt
window.addEventListener('beforeinstallprompt', (e) => {
    console.log('beforeinstallprompt event fired');
    
    // Stash the event so it can be triggered later
    deferredPrompt = e;
    
    // التحقق من حالة تحميل الصفحة
    if (document.readyState === 'loading') {
        // إذا كانت الصفحة لا تزال تحمّل، انتظر حتى يتم تحميل DOM
        document.addEventListener('DOMContentLoaded', function() {
            handleInstallPrompt(e);
        });
    } else {
        // إذا كانت الصفحة محملة بالفعل، معالجة مباشرة
        handleInstallPrompt(e);
    }
});

// دالة معالجة beforeinstallprompt
function handleInstallPrompt(e) {
    // التحقق من حالة إخفاء الإشعار أولاً
    if (!shouldShowBanner()) {
        console.log('Install banner was dismissed, not showing prompt');
        return; // لا نمنع البانر الأصلي إذا كان المستخدم قد أخفى البانر
    }
    
    // التحقق من وجود زر التثبيت والبانر في الصفحة
    const installButton = document.getElementById('installButton');
    const installBanner = document.getElementById('installBanner');
    
    if (installButton && installBanner) {
        // إذا كان هناك زر تثبيت وبانر، منع البانر التلقائي وعرض البانر الخاص بنا
        e.preventDefault();
        console.log('Prevented default install prompt, showing custom banner');
        // Show install banner (سيتم التحقق من حالة الإخفاء داخلياً)
        showInstallBanner();
    } else {
        // إذا لم يكن هناك زر أو بانر، لا نمنع البانر الأصلي
        console.log('Install button or banner not found, allowing default install prompt');
        // لا نستدعي preventDefault() - نسمح للمتصفح بعرض البانر الأصلي
    }
}

// معالجة النقر على زر التثبيت وإغلاق البانر
document.addEventListener('DOMContentLoaded', function() {
    const installButton = document.getElementById('installButton');
    const dismissButton = document.getElementById('dismissInstallBanner');
    
    // معالجة إغلاق البانر
    if (dismissButton) {
        dismissButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            hideInstallBanner();
            
            // إذا كان deferredPrompt موجوداً ولم يتم استدعاء prompt() بعد، 
            // نعيد تفعيله للسماح للمتصفح بعرض البانر الأصلي في المرة القادمة
            if (deferredPrompt) {
                console.log('Banner dismissed, deferredPrompt will be available for next time');
                // لا نحذف deferredPrompt - نتركه للمتصفح لاستخدامه لاحقاً
            }
            
            // حفظ في localStorage أن المستخدم رفض التثبيت لمدة يوم
            try {
                localStorage.setItem('pwa_install_dismissed', 'true');
                localStorage.setItem('pwa_install_dismissed_time', Date.now().toString());
                console.log('Install banner dismissed, will not show for 24 hours');
            } catch (e) {
                console.error('Error saving dismiss state:', e);
            }
        });
    }
    
    // التحقق من حالة إخفاء الإشعار
    const canShowBanner = shouldShowBanner();
    
    // إظهار البانر على الهواتف حتى لو لم يكن beforeinstallprompt متاحاً
    if (canShowBanner) {
        // انتظر قليلاً قبل إظهار البانر
        setTimeout(() => {
            if (isMobileDevice() && !isInstalled()) {
                // على Android، إظهار البانر إذا لم يكن هناك deferredPrompt
                if (isAndroid() && !deferredPrompt) {
                    // إظهار البانر بعد 5 ثوان من تحميل الصفحة
                    setTimeout(() => {
                        showInstallBanner();
                    }, 5000);
                }
                
                // على iOS، إظهار البانر دائماً مع تعليمات خاصة
                if (isIOS()) {
                    // إظهار البانر بعد 3 ثوان
                    setTimeout(() => {
                        showInstallBanner();
                        // تحديث نص البانر لـ iOS
                        const banner = document.getElementById('installBanner');
                        if (banner) {
                            const bannerContent = banner.querySelector('div > div.flex-grow-1');
                            if (bannerContent) {
                                bannerContent.innerHTML = `
                                    <strong><i class="bi bi-download me-2"></i>تثبيت التطبيق</strong>
                                    <p class="mb-0 small">اضغط على زر المشاركة <i class="bi bi-share"></i> ثم اختر "إضافة إلى الشاشة الرئيسية"</p>
                                `;
                            }
                            const installBtn = document.getElementById('installButton');
                            if (installBtn) {
                                installBtn.innerHTML = '<i class="bi bi-info-circle me-1"></i>كيفية التثبيت';
                            }
                        }
                    }, 3000);
                }
            }
        }, 1000);
    }
    
    if (installButton) {
        console.log('Install button found, adding event listener');
        
        installButton.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Install button clicked');
            
            // على iOS، عرض تعليمات التثبيت
            if (isIOS()) {
                e.preventDefault();
                alert('لتثبيت التطبيق على iPhone/iPad:\n\n1. اضغط على زر المشاركة (Share) في أسفل المتصفح\n2. اختر "إضافة إلى الشاشة الرئيسية" (Add to Home Screen)\n3. اضغط "إضافة" (Add) في الأعلى\n\nسيظهر التطبيق على الشاشة الرئيسية بعد ذلك.');
                return;
            }
            
            if (!deferredPrompt) {
                console.warn('No deferred prompt available');
                // على Android، إعطاء تعليمات بديلة
                if (isAndroid()) {
                    alert('لتثبيت التطبيق:\n\n1. اضغط على القائمة (⋮) في المتصفح\n2. اختر "إضافة إلى الشاشة الرئيسية" أو "Install app"\n3. اضغط "تثبيت" أو "Install"\n\nأو انتظر حتى يظهر إشعار التثبيت تلقائياً.');
                } else {
                    alert('زر التثبيت غير متاح حالياً. يرجى المحاولة لاحقاً أو تثبيت التطبيق من قائمة المتصفح.');
                }
                return;
            }
            
            try {
                // Show the install prompt
                console.log('Showing install prompt');
                
                // التأكد من أن deferredPrompt صالح
                if (!deferredPrompt || typeof deferredPrompt.prompt !== 'function') {
                    throw new Error('Install prompt is not available');
                }
                
                // استدعاء prompt() - هذا يحل مشكلة التحذير
                deferredPrompt.prompt();
                
                // Wait for the user to respond to the prompt
                const { outcome } = await deferredPrompt.userChoice;
                
                console.log(`User response: ${outcome}`);
                
                if (outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                    hideInstallBanner();
                    // البانر سيختفي تلقائياً بعد التثبيت
                } else {
                    console.log('User dismissed the install prompt');
                    // إذا رفض المستخدم، إخفاء البانر
                    hideInstallBanner();
                }
                
                // Clear the deferredPrompt بعد استخدامه
                deferredPrompt = null;
                
            } catch (error) {
                console.error('Error showing install prompt:', error);
                
                // إذا فشل prompt()، إعادة تفعيل deferredPrompt
                if (deferredPrompt && error.message.includes('not available')) {
                    deferredPrompt = null;
                }
                
                alert('حدث خطأ أثناء محاولة التثبيت: ' + error.message);
            }
        });
    } else {
        console.warn('Install button not found');
    }
});

// تتبع التثبيت
window.addEventListener('appinstalled', () => {
    console.log('PWA was installed');
    hideInstallBanner();
    deferredPrompt = null;
    
    // إظهار رسالة نجاح
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        <i class="bi bi-check-circle-fill me-2"></i>
        تم تثبيت التطبيق بنجاح!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
});

// حفظ في النطاق العام للوصول من أي مكان
window.pwaInstallHandler = {
    deferredPrompt: () => deferredPrompt,
    showInstallBanner: showInstallBanner,
    hideInstallBanner: hideInstallBanner,
    isInstalled: isInstalled
};

