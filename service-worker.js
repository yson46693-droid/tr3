/**
 * Service Worker لـ PWA مع تحديث تلقائي
 */

// استخدام version ثابت لتجنب إعادة التحميل المستمرة
const CACHE_VERSION = '1.0.0';
const CACHE_NAME = 'company-management-v' + CACHE_VERSION;
const UPDATE_CHECK_INTERVAL = 5 * 60 * 1000; // التحقق من التحديثات كل 5 دقائق
const urlsToCache = [
    '/',
    '/index.php',
    '/offline.html', // إضافة صفحة offline.html
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css'
];

// دالة لإنشاء محتوى offline.html
function getOfflinePageContent() {
    return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>لا يوجد اتصال بالإنترنت</title>
<style>
    body {
        margin: 0;
        padding: 0;
        font-family: "Cairo", sans-serif;
        background: linear-gradient(135deg, #1d3557, #457b9d);
        color: #fff;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        text-align: center;
    }
    .container {
        background: rgba(255,255,255,0.08);
        padding: 40px 30px;
        border-radius: 18px;
        backdrop-filter: blur(8px);
        width: 90%;
        max-width: 380px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    }
    .icon {
        font-size: 70px;
        margin-bottom: 20px;
    }
    @keyframes pulse {
        0% { transform: scale(1); opacity: .8; }
        50% { transform: scale(1.1); opacity: 1; }
        100% { transform: scale(1); opacity: .8; }
    }
    h2 {
        font-size: 26px;
        margin-bottom: 12px;
    }
    p {
        font-size: 16px;
        line-height: 1.6;
        opacity: .9;
    }
    button {
        margin-top: 25px;
        padding: 12px 25px;
        font-size: 18px;
        background: #e63946;
        border: none;
        border-radius: 10px;
        color: #fff;
        cursor: pointer;
        transition: 0.3s ease;
    }
    button:hover {
        background: #ff4757;
        transform: translateY(-2px);
    }
</style>
</head>
<body>
    <div class="container">
        <div class="icon">📡</div>
        <h2>لا يوجد اتصال بالإنترنت</h2>
        <p>يبدو أنك غير متصل. تأكد من الشبكة ثم أعد المحاولة.</p>
        <button onclick="location.reload()">إعادة المحاولة 🔄</button>
    </div>
</body>
</html>`;
}

// دالة لإنشاء Response من محتوى offline.html
function createOfflineResponse() {
    const offlineContent = getOfflinePageContent();
    return new Response(offlineContent, {
        status: 200,
        statusText: 'OK',
        headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });
}

// Install Event
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                // محاولة إضافة الملفات إلى الـ cache، مع تجاهل الأخطاء
                return Promise.allSettled(
                    urlsToCache.map(function(url) {
                        // تجاهل URLs التي قد تسبب مشاكل CORS
                        if (url.includes('errors.infinityfree.net') || url.includes('403')) {
                            return Promise.resolve();
                        }
                        
                        // معالجة خاصة لـ offline.html
                        if (url === '/offline.html') {
                            return fetch(url, {
                                mode: 'same-origin',
                                credentials: 'omit'
                            }).then(function(response) {
                                if (response && response.ok) {
                                    return cache.put(url, response);
                                }
                                // إذا فشل fetch، قم بإنشاء المحتوى مباشرة
                                return cache.put(url, createOfflineResponse());
                            }).catch(function(error) {
                                // إذا فشل fetch، قم بإنشاء المحتوى مباشرة
                                console.log('Cache fetch error for offline.html, creating inline:', error.message);
                                return cache.put(url, createOfflineResponse());
                            });
                        }
                        
                        return fetch(url, {
                            mode: url.startsWith('http') ? 'no-cors' : 'same-origin',
                            credentials: 'omit'
                        }).then(function(response) {
                            if (response && (response.ok || response.type === 'opaque')) {
                                return cache.put(url, response);
                            }
                            return Promise.resolve();
                        }).catch(function(error) {
                            // تجاهل الأخطاء - لا نريد أن يفشل التثبيت بسبب ملف مفقود
                            console.log('Cache fetch error for', url, ':', error.message);
                            return Promise.resolve();
                        });
                    })
                );
            })
            .then(function() {
                // إجبار التفعيل الفوري للـ service worker
                return self.skipWaiting();
            })
            .catch(function(error) {
                console.log('Service Worker install failed:', error);
                // حتى لو فشل، نحاول التفعيل
                return self.skipWaiting();
            })
    );
});

// Fetch Event - مبسط جداً
self.addEventListener('fetch', function(event) {
    // تجاهل POST, PUT, DELETE وغيرها من methods غير GET تماماً
    if (event.request.method !== 'GET') {
        return; // لا تفعل أي شيء مع non-GET requests
    }
    
    // تجاهل API requests (عادة ما تكون dynamic)
    const url = new URL(event.request.url);
    
    // تجاهل أي URLs تحتوي على errors.infinityfree.net أو 403
    if (url.hostname.includes('errors.infinityfree.net') || 
        url.pathname.includes('/errors/403') ||
        url.pathname.includes('/errors/')) {
        // إرجاع استجابة فارغة بدلاً من محاولة fetch
        event.respondWith(new Response('', { status: 200, statusText: 'OK' }));
        return;
    }
    
    // للصفحات الرئيسية وملفات PHP: عند فشل الاتصال، عرض offline.html
    const isNavigationRequest = event.request.mode === 'navigate' || 
                                url.pathname === '/' || 
                                url.pathname === '/index.php' ||
                                (url.pathname.endsWith('.php') && !url.pathname.includes('/api/') && !url.pathname.includes('/ajax/'));
    
    // إذا كان طلب navigational (صفحة رئيسية)، معالجة خاصة
    if (isNavigationRequest) {
        event.respondWith(
            fetch(event.request)
                .then(function(response) {
                    // إذا نجح الطلب، أرجع الاستجابة
                    return response;
                })
                .catch(function(error) {
                    // عند فشل الاتصال، أرجع offline.html
                    return caches.match('/offline.html').then(function(cached) {
                        if (cached) {
                            return cached;
                        }
                        // إذا لم يكن موجوداً في cache، قم بإنشائه مباشرة
                        return createOfflineResponse();
                    });
                })
        );
        return;
    }
    
    // تجاهل جميع API requests وملفات PHP الأخرى
    if (url.pathname.includes('/api/') || 
        url.pathname.includes('/ajax/') ||
        url.pathname.includes('/dashboard/') ||
        url.pathname.includes('/modules/') ||
        (url.pathname.endsWith('.php') && !isNavigationRequest)) {
        return; // لا تفعل أي شيء مع API requests أو PHP files
    }
    
    // فقط معالجة GET requests للـ static assets
    event.respondWith(
        caches.match(event.request)
            .then(function(response) {
                // Cache hit - return response
                if (response) {
                    return response;
                }
                
                // Fetch من الشبكة مع معالجة أفضل للأخطاء
                return fetch(event.request, {
                    mode: 'cors',
                    credentials: 'same-origin',
                    redirect: 'follow'
                }).then(
                    function(response) {
                        // التحقق من أن الاستجابة ليست redirect إلى صفحة خطأ
                        if (response.redirected && response.url.includes('errors.infinityfree.net')) {
                            // إذا كان redirect إلى صفحة خطأ، استخدم cache إن وجد
                            return caches.match(event.request).then(function(cachedResponse) {
                                return cachedResponse || new Response('', { status: 200 });
                            });
                        }
                        
                        // Don't cache if not a valid response
                        if (!response || response.status !== 200 || response.type !== 'basic') {
                            return response;
                        }
                        
                        // فقط احفظ static assets
                        if (event.request.method === 'GET' && 
                            (event.request.url.includes('/assets/') || 
                             event.request.url.includes('/css/') || 
                             event.request.url.includes('/js/') ||
                             event.request.url.includes('/images/') ||
                             event.request.url.match(/\.(css|js|png|jpg|jpeg|svg|gif|ico|woff|woff2|ttf|eot|html)$/i))) {
                            // Clone the response
                            var responseToCache = response.clone();
                            
                            // محاولة حفظ في cache بشكل آمن
                            caches.open(CACHE_NAME)
                                .then(function(cache) {
                                    // تأكد مرة أخرى أن الطلب GET
                                    if (event.request.method === 'GET') {
                                        cache.put(event.request, responseToCache).catch(function(err) {
                                            // تجاهل أخطاء cache بصمت
                                        });
                                    }
                                }).catch(function(error) {
                                    // تجاهل أخطاء cache
                                });
                        }
                        
                        return response;
                    }
                ).catch(function(error) {
                    // معالجة أفضل لأخطاء CORS
                    if (error.name === 'TypeError' && error.message.includes('CORS')) {
                        console.log('CORS error ignored for:', event.request.url);
                        // محاولة إرجاع من cache
                        return caches.match(event.request).then(function(cachedResponse) {
                            return cachedResponse || new Response('', { status: 200 });
                        });
                    }
                    
                    // Return cached version if available, otherwise return offline page
                    return caches.match('/offline.html').then(function(cached) {
                        if (cached) {
                            return cached;
                        }
                        // إذا لم يكن موجوداً في cache، قم بإنشائه مباشرة
                        return createOfflineResponse();
                    });
                });
            })
    );
});

// Activate Event
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.map(function(cacheName) {
                    if (!cacheName.startsWith('company-management-v')) {
                        return caches.delete(cacheName);
                    }
                    // حذف الـ cache القديم فقط إذا كان مختلفاً
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(function() {
            // إجبار السيطرة على جميع الصفحات
            return self.clients.claim();
        })
        // تعطيل إرسال رسالة التفعيل لتجنب إعادة التحميل المستمرة
        // .then(function() {
        //     // إرسال رسالة إلى جميع الصفحات بإشعار التحديث
        //     return self.clients.matchAll().then(function(clients) {
        //         clients.forEach(function(client) {
        //             client.postMessage({
        //                 type: 'SW_ACTIVATED',
        //                 cacheName: CACHE_NAME
        //             });
        //         });
        //     });
        // })
    );
});

// Background Sync
self.addEventListener('sync', function(event) {
    if (event.tag === 'sync-data') {
        event.waitUntil(syncData());
    }
});

function syncData() {
    // Sync pending data
    return Promise.resolve();
}

// Message Handler - للتواصل مع الصفحات
self.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data && event.data.type === 'CHECK_UPDATE') {
        // التحقق من وجود تحديثات
        self.registration.update().then(function() {
            event.ports[0].postMessage({ type: 'UPDATE_CHECKED' });
        });
    }
});


self.addEventListener('push', function(event) {
    const options = {
        body: event.data ? event.data.text() : 'إشعار جديد',
        icon: '/assets/icons/icon-192x192.png',
        badge: '/assets/icons/icon-96x96.png',
        vibrate: [200, 100, 200],
        tag: 'notification',
        requireInteraction: true
    };
    
    event.waitUntil(
        self.registration.showNotification('نظام الإدارة الخاص بشركة البركة', options)
    );
});

// Notification Click
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    event.waitUntil(
        clients.openWindow('/')
    );
});
