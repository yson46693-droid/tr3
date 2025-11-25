/**
 * JavaScript للإشعارات
 */

let notificationCheckInterval = null;
const seenNotificationIds = new Set();
const NOTIFICATION_DEFAULT_LIMIT = 10;
const CANCELLATION_KEYWORDS = ['تم إلغاء المهمة', 'المهمة الملغية'];

function filterDisplayableNotifications(notifications) {
    if (!Array.isArray(notifications)) {
        return [];
    }

    const completionKeywords = ['كمكتملة', 'تم إكمال', 'status=completed'];
    const fiveMinutes = 5 * 60 * 1000;
    const filtered = [];
    const now = Date.now();

    notifications.forEach(notification => {
        if (!notification) {
            return;
        }

        const title = (notification.title || '').toString();
        const message = (notification.message || '').toString();
        const link = (notification.link || '').toString();
        const timestamp = getNotificationTimestamp(notification);

        const containsKeyword = text => {
            if (typeof text !== 'string' || text.trim() === '') {
                return false;
            }
            return completionKeywords.some(keyword => text.includes(keyword));
        };

        if (containsKeyword(title) || containsKeyword(message) || containsKeyword(link)) {
            if (notification.id) {
                markNotificationAsRead(notification.id, { silent: true }).catch(console.error);
            }
            return;
        }

        const isCancellation = CANCELLATION_KEYWORDS.some(keyword => title.includes(keyword) || message.includes(keyword));
        if (isCancellation && timestamp) {
            const age = now - timestamp.getTime();
            if (age >= fiveMinutes) {
                if (notification.id) {
                    markNotificationAsRead(notification.id, { silent: true }).catch(console.error);
                }
                return;
            }
        }

        filtered.push(notification);
    });

    return filtered;
}

/**
 * دالة مساعدة لحساب المسار الصحيح لـ API
 */
function getApiPath(endpoint) {
    const cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
    const currentPath = window.location.pathname || '/';
    const parts = currentPath.split('/').filter(Boolean);
    const stopSegments = new Set(['dashboard', 'modules', 'api', 'assets', 'includes']);
    const baseParts = [];

    for (const part of parts) {
        if (stopSegments.has(part) || part.endsWith('.php')) {
            break;
        }
        baseParts.push(part);
    }

    const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
    const apiPath = (basePath + '/' + cleanEndpoint).replace(/\/+/g, '/');

    return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
}

/**
 * التحقق من حالة الإذن لإشعارات المتصفح (بدون طلبه)
 */
function checkNotificationPermission() {
    if ('Notification' in window) {
        return Notification.permission;
    }
    return null;
}

/**
 * طلب الإذن لإشعارات المتصفح (يجب استدعاؤه فقط من user event مثل click)
 */
function requestNotificationPermission() {
    if (!('Notification' in window)) {
        console.warn('This browser does not support notifications');
        return Promise.resolve('not-supported');
    }
    
    if (Notification.permission === 'granted') {
        return Promise.resolve('granted');
    }
    
    if (Notification.permission === 'denied') {
        return Promise.resolve('denied');
    }
    
    // طلب الإذن فقط إذا كان default
    return Notification.requestPermission().then(permission => {
        if (permission === 'granted') {
            console.log('Notification permission granted');
        } else {
            console.log('Notification permission denied');
        }
        return permission;
    });
}

/**
 * إرسال إشعار متصفح
 */
function showBrowserNotification(title, body, icon = null, tag = null) {
    if ('Notification' in window && Notification.permission === 'granted') {
        const notification = new Notification(title, {
            body: body,
            icon: icon || '/assets/icons/notification.png',
            tag: tag,
            badge: '/assets/icons/badge.png',
            requireInteraction: false
        });
        
        notification.onclick = function() {
            window.focus();
            this.close();
        };
        
        // إغلاق تلقائي بعد 5 ثوان
        setTimeout(() => {
            notification.close();
        }, 5000);
    }
}

/**
 * تحميل الإشعارات
 */
async function loadNotifications() {
    try {
        const apiPath = getApiPath('api/notifications.php');
        const limit = typeof NOTIFICATION_LIMIT !== 'undefined' ? NOTIFICATION_LIMIT : NOTIFICATION_DEFAULT_LIMIT;
        const response = await fetch(`${apiPath}?action=list&limit=${limit}`, {
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // قراءة الاستجابة كنص أولاً للتحقق من أنها JSON صالحة
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            // إذا لم تكن JSON، تجاهل بصمت
            return;
        }
        
        const responseText = await response.text();
        
        // التحقق من أن الاستجابة ليست فارغة
        if (!responseText || responseText.trim() === '') {
            return;
        }
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            // إذا فشل parse JSON، تجاهل بصمت
            console.warn('Invalid JSON response from notifications API:', parseError);
            return;
        }
        
        if (data && data.success) {
            const notifications = Array.isArray(data.data) ? data.data : [];
            const displayNotifications = filterDisplayableNotifications(notifications);
            const unreadCount = displayNotifications.filter(isNotificationUnread).length;

            updateNotificationBadge(unreadCount);
            handleBrowserNotifications(displayNotifications);
            updateNotificationList(displayNotifications);

            return;
        }
    } catch (error) {
        // تجاهل أخطاء CORS بصمت
        if (error.name === 'TypeError' && (error.message.includes('CORS') || error.message.includes('fetch'))) {
            return;
        }
        // تجاهل أخطاء JSON parse بصمت
        if (error.name === 'SyntaxError' && error.message.includes('JSON')) {
            return;
        }
        // تسجيل الأخطاء الأخرى فقط
        if (error.message && !error.message.includes('JSON') && !error.message.includes('Unexpected end')) {
            console.error('Error loading notifications:', error);
        }
    }
}

function isNotificationUnread(notification) {
    if (notification == null || typeof notification !== 'object') {
        return false;
    }
    if (Object.prototype.hasOwnProperty.call(notification, 'read')) {
        const value = notification.read;
        if (typeof value === 'boolean') {
            return value === false;
        }
        return parseInt(value, 10) === 0;
    }
    if (Object.prototype.hasOwnProperty.call(notification, 'read_at')) {
        return !notification.read_at;
    }
    return true;
}

function getNotificationTimestamp(notification) {
    if (!notification) {
        return null;
    }
    const dateValue = notification.created_at || notification.createdAt || notification.timestamp;
    if (!dateValue) {
        return null;
    }
    const parsed = new Date(dateValue);
    if (Number.isNaN(parsed.getTime())) {
        return null;
    }
    return parsed;
}

function handleBrowserNotifications(notifications) {
    if (!Array.isArray(notifications) || notifications.length === 0) {
        return;
    }

    const now = Date.now();
    notifications.forEach(notification => {
        const notificationId = parseInt(notification.id ?? notification.notification_id, 10);
        if (!notificationId || seenNotificationIds.has(notificationId)) {
            return;
        }
        seenNotificationIds.add(notificationId);

        const createdAt = getNotificationTimestamp(notification);
        if (!createdAt) {
            return;
        }

        const ageMs = now - createdAt.getTime();
        if (ageMs <= 60000 && isNotificationUnread(notification)) {
            // التحقق من إشعار تغيير الدور أولاً
            if (checkForRoleChangeNotification([notification])) {
                return; // سيتم إعادة التوجيه
            }
            showBrowserNotification(
                notification.title || '',
                notification.message || '',
                null,
                'notification_' + notificationId
            );
        }
    });
}

/**
 * تحديث عداد الإشعارات
 */
async function updateNotificationBadge(count = null) {
    if (count === null) {
        try {
            const apiPath = getApiPath('api/notifications.php');
            const response = await fetch(apiPath + '?action=count');
            const data = await response.json();
            count = data.count || 0;
        } catch (error) {
            // تجاهل أخطاء CORS بصمت
            if (error.name === 'TypeError' && error.message.includes('CORS')) {
                console.log('CORS error ignored in notification count');
                return;
            }
            console.error('Error getting notification count:', error);
            return;
        }
    }
    
    const badge = document.getElementById('notificationBadge');
    if (badge) {
        const safeCount = Number.isFinite(Number(count)) ? Math.max(0, parseInt(count, 10)) : 0;
        badge.textContent = safeCount.toString();
        badge.style.display = 'inline-block';
        if (safeCount > 0) {
            badge.classList.add('has-notifications');
        } else {
            badge.classList.remove('has-notifications');
        }
    }
}

/**
 * التحقق من إشعار تغيير الدور وإجبار إعادة تسجيل الدخول
 */
function checkForRoleChangeNotification(notifications) {
    // البحث عن إشعار تغيير الدور غير المقروء
    const roleChangeNotification = notifications.find(n => 
        n.read == 0 && 
        (n.title.includes('تغيير دور') || n.title.includes('تغيير دور الحساب') || n.message.includes('تم تغيير دور'))
    );
    
    if (roleChangeNotification) {
        // إشعار تغيير الدور موجود - إجبار إعادة تسجيل الدخول
        console.warn('Role change detected. Forcing logout and cache clear.');
        
        // مسح الكاش
        if ('caches' in window) {
            caches.keys().then(function(names) {
                for (let name of names) {
                    caches.delete(name);
                }
            });
        }
        
        // مسح localStorage و sessionStorage
        try {
            localStorage.clear();
            sessionStorage.clear();
        } catch (e) {
            console.error('Error clearing storage:', e);
        }
        
        // إظهار رسالة للمستخدم
        const message = 'تم تغيير دور حسابك. يرجى تسجيل الخروج وإعادة تسجيل الدخول لتفعيل التغييرات.';
        alert(message);
        
        // إعادة توجيه إلى صفحة تسجيل الخروج
        // حساب المسار ديناميكياً
        let logoutUrl = roleChangeNotification.link;
        if (!logoutUrl) {
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php') && p !== 'dashboard' && p !== 'modules' && p !== 'api');
            const basePath = pathParts.length > 0 ? '/' + pathParts[0] + '/' : '/';
            logoutUrl = basePath + 'logout.php';
        }
        window.location.href = logoutUrl;
        
        return true;
    }
    
    return false;
}

/**
 * تحديث قائمة الإشعارات
 */
function updateNotificationList(notifications) {
    const list = document.getElementById('notificationsList');
    if (!list) return;
    
    // التحقق من إشعار تغيير الدور أولاً
    if (checkForRoleChangeNotification(notifications)) {
        return; // سيتم إعادة التوجيه، لا حاجة لتحديث القائمة
    }
    
    if (!Array.isArray(notifications) || notifications.length === 0) {
        list.innerHTML = '<small class="text-muted">لا توجد إشعارات</small>';
        return;
    }
    
    let html = '';
    const now = Date.now();
    notifications.slice(0, NOTIFICATION_DEFAULT_LIMIT).forEach(notification => {
        const typeClass = {
            'info': 'text-info',
            'success': 'text-success',
            'warning': 'text-warning',
            'error': 'text-danger',
            'approval': 'text-primary',
            'attendance_checkin': 'text-warning',
            'attendance_checkout': 'text-warning'
        }[notification.type] || 'text-info';
        
        const icon = {
            'info': 'bi-info-circle',
            'success': 'bi-check-circle',
            'warning': 'bi-exclamation-triangle',
            'error': 'bi-x-circle',
            'approval': 'bi-check-square',
            'attendance_checkin': 'bi-alarm',
            'attendance_checkout': 'bi-door-open'
        }[notification.type] || 'bi-bell';
        
        const unread = isNotificationUnread(notification);
        const unreadClass = unread ? 'unread' : 'read';
        const timeAgo = getTimeAgo(notification.created_at);
        const recentClass = (() => {
            const timestamp = getNotificationTimestamp(notification);
            if (!timestamp) return '';
            return (now - timestamp.getTime()) <= 300000 ? 'recent' : '';
        })();
        const notificationId = notification.id ?? '';
        const safeTitle = sanitizeText(notification.title || '');
        const safeMessage = sanitizeText(notification.message || '');
        const markReadButton = unread ? `
                        <button type="button" class="btn btn-sm btn-outline-secondary notification-mark-read" data-id="${notificationId}" title="تمت الرؤية">
                            <i class="bi bi-check2 me-1"></i>تم الرؤية
                        </button>` : `
                        <span class="badge bg-light text-muted border">تمت الرؤية</span>`;
        
        html += `
            <div class="notification-item ${unreadClass} ${recentClass}" data-id="${notificationId}">
                <div class="d-flex align-items-start">
                    <i class="bi ${icon} ${typeClass} me-2 mt-1"></i>
                    <div class="flex-grow-1">
                        <div class="fw-bold">${safeTitle}</div>
                        <div class="small text-muted">${safeMessage}</div>
                        <div class="small text-muted mt-1">${timeAgo}</div>
                    </div>
                    <div class="notification-actions">
                        ${markReadButton}
                        <button type="button" class="btn btn-sm btn-outline-danger notification-delete" data-id="${notificationId}" title="حذف الإشعار">
                            <i class="bi bi-trash me-1"></i>حذف
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    
    list.innerHTML = html;
    
    // إضافة مستمعي الأحداث
    list.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            const notificationId = this.getAttribute('data-id');
            if (this.classList.contains('unread')) {
                markNotificationAsRead(notificationId);
            }
            
                if (notifications.find(n => (n.id == notificationId || String(n.id) === String(notificationId)) && n.link)) {
                    const notification = notifications.find(n => (n.id == notificationId || String(n.id) === String(notificationId)));
                window.location.href = notification.link;
            }
        });
    });

    list.querySelectorAll('.notification-mark-read').forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
            const notificationId = this.getAttribute('data-id');
            markNotificationAsRead(notificationId).catch(console.error);
        });
    });

    list.querySelectorAll('.notification-delete').forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
            const notificationId = this.getAttribute('data-id');
            deleteNotification(notificationId).then(() => {
                const item = this.closest('.notification-item');
                if (item) {
                    item.remove();
                }
                if (!list.querySelector('.notification-item')) {
                    list.innerHTML = '<small class="text-muted">لا توجد إشعارات</small>';
                }
            }).catch(console.error);
        });
    });
}

function sanitizeText(text) {
    if (typeof text !== 'string') {
        return '';
    }
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * تحديد إشعار كمقروء
 */
async function markNotificationAsRead(notificationId, options = {}) {
    const { silent = false } = options;

    try {
        const apiPath = getApiPath('api/notifications.php');
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'mark_read',
                id: notificationId
            })
        });
        
        // إعادة تحميل الإشعارات
        if (!silent) {
            loadNotifications();
        }

        return response;
    } catch (error) {
        // تجاهل أخطاء CORS بصمت
        if (error.name === 'TypeError' && error.message.includes('CORS')) {
            console.log('CORS error ignored when marking notification as read');
            return;
        }
        console.error('Error marking notification as read:', error);
    }
}

/**
 * حذف إشعار
 */
async function deleteNotification(notificationId) {
    try {
        const apiPath = getApiPath('api/notifications.php');
        await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                id: notificationId
            })
        });

        loadNotifications();
    } catch (error) {
        if (error.name === 'TypeError' && error.message.includes('CORS')) {
            console.log('CORS error ignored when deleting notification');
            return;
        }
        console.error('Error deleting notification:', error);
    }
}

/**
 * تحديد جميع الإشعارات كمقروءة
 */
async function markAllNotificationsAsRead() {
    try {
        const apiPath = getApiPath('api/notifications.php');
        await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'mark_all_read'
            })
        });
        
        // إعادة تحميل الإشعارات
        loadNotifications();
    } catch (error) {
        // تجاهل أخطاء CORS بصمت
        if (error.name === 'TypeError' && error.message.includes('CORS')) {
            console.log('CORS error ignored when marking all notifications as read');
            return;
        }
        console.error('Error marking all notifications as read:', error);
    }
}

/**
 * حساب الوقت المنقضي
 */
function getTimeAgo(dateString) {
    const now = new Date();
    const date = new Date(dateString);
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) {
        return 'الآن';
    } else if (diffMins < 60) {
        return `منذ ${diffMins} دقيقة`;
    } else if (diffHours < 24) {
        return `منذ ${diffHours} ساعة`;
    } else if (diffDays < 7) {
        return `منذ ${diffDays} يوم`;
    } else {
        return date.toLocaleDateString('ar-EG');
    }
}

// تهيئة النظام عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    // التحقق من حالة الإذن (بدون طلبه تلقائياً)
    const permission = checkNotificationPermission();
    if (permission === 'granted') {
        console.log('Notification permission already granted');
    } else if (permission === 'default') {
        console.log('Notification permission not yet requested');
    }
    
    if (Array.isArray(window.initialNotifications)) {
        window.initialNotifications.forEach(notification => {
            const notificationId = parseInt(notification.id ?? notification.notification_id, 10);
            if (notificationId) {
                seenNotificationIds.add(notificationId);
            }
        });
        const displayNotifications = filterDisplayableNotifications(window.initialNotifications);
        const unreadCount = displayNotifications.filter(isNotificationUnread).length;
        updateNotificationBadge(unreadCount);
        updateNotificationList(displayNotifications);
    }
    
    // تحميل الإشعارات فوراً
    loadNotifications();
    
    // تحديث الإشعارات (استخدام الفترة من config أو 60 ثانية افتراضياً)
    let pollInterval = 60000;
    if (typeof window.NOTIFICATION_POLL_INTERVAL !== 'undefined') {
        const parsed = Number(window.NOTIFICATION_POLL_INTERVAL);
        if (Number.isFinite(parsed) && parsed > 0) {
            pollInterval = parsed;
        }
    }
    const autoRefreshEnabled = (function () {
        if (typeof window.NOTIFICATION_AUTO_REFRESH_ENABLED === 'undefined') {
            return true;
        }
        const value = window.NOTIFICATION_AUTO_REFRESH_ENABLED;
        if (typeof value === 'string') {
            return value === 'true' || value === '1';
        }
        return Boolean(value);
    })();

    if (autoRefreshEnabled) {
        if (window.currentUser && window.currentUser.role === 'production') {
            pollInterval = Math.min(pollInterval, 15000);
            if (checkNotificationPermission() === 'default') {
                const requestOnInteraction = () => {
                    requestNotificationPermission().catch(err => console.error('Error requesting notification permission:', err));
                    document.body.removeEventListener('click', requestOnInteraction);
                    document.body.removeEventListener('touchstart', requestOnInteraction);
                };
                document.body.addEventListener('click', requestOnInteraction, { once: true });
                document.body.addEventListener('touchstart', requestOnInteraction, { once: true });
            }
        }

        if (!window.__notificationAutoRefreshActive) {
            window.__notificationAutoRefreshActive = true;
            notificationCheckInterval = setInterval(loadNotifications, pollInterval);
        }
    }
    
    // طلب الإذن عند النقر على أيقونة الإشعارات (user-generated event)
    // البحث عن أيقونة الإشعارات في header
    const notificationDropdown = document.getElementById('notificationsDropdown');
    const notificationBadge = document.getElementById('notificationBadge');
    
    // استخدام أي عنصر متاح (dropdown link أو badge container)
    const notificationIcon = notificationDropdown || 
                             (notificationBadge?.closest('a')) ||
                             (notificationBadge?.closest('button')) ||
                             document.querySelector('[data-notification-icon]') ||
                             document.querySelector('.notification-icon');
    
    if (notificationIcon) {
        // إضافة event listener عند النقر على أيقونة الإشعارات
        // يجب طلب الإذن مباشرة في event handler (user-generated event)
        notificationIcon.addEventListener('click', function(e) {
            // طلب الإذن فقط إذا لم يكن مُمنحاً بالفعل
            if (checkNotificationPermission() === 'default') {
                requestNotificationPermission().catch(err => {
                    console.error('Error requesting notification permission:', err);
                });
            }
        }, { once: false, passive: true });
    }
});

// إيقاف التحديث عند مغادرة الصفحة
window.addEventListener('beforeunload', function() {
    if (notificationCheckInterval) {
        clearInterval(notificationCheckInterval);
    }
});

