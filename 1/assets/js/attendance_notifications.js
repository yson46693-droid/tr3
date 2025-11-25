/**
 * نظام إشعارات الحضور والانصراف
 * Attendance Notifications System
 */

class AttendanceNotificationManager {
    constructor() {
        this.notificationPermission = null;
        this.reminderTimeout = null;
        this.checkoutReminderTimeout = null;
        this.dailyCheckInterval = null;
        this.workTime = null;
        this.userId = null;
        this.userRole = null;
        this.storageAvailable = this.checkStorageAvailability();
        this.statusCacheTTL = 5 * 60 * 1000; // 5 دقائق
        this.todayStatusCache = null;
        this.todayStatusCacheTimestamp = 0;
        this.todayStatusCachePromise = null;
        this.notificationsEnabled = false;
    }

    /**
     * تهيئة نظام الإشعارات
     */
    async init() {
        if (!this.notificationsEnabled) {
            console.log('Attendance notifications are globally disabled.');
            return;
        }

        // التحقق من أن المستخدم لديه صفحة حضور
        if (!this.hasAttendanceAccess()) {
            console.log('User does not have attendance access');
            return;
        }

        // الحصول على موعد العمل
        this.workTime = await this.getWorkTime();
        if (!this.workTime) {
            console.log('No work time found for user');
            return;
        }

        // طلب الإذن للإشعارات
        await this.requestNotificationPermission();

        // جدولة الإشعارات
        this.scheduleReminders();
        this.showImmediateRemindersOnLogin();
        
        // فحص يومي للتأكد من جدولة الإشعارات
        this.startDailyCheck();
    }

    /**
     * التحقق من أن المستخدم لديه صفحة حضور
     */
    hasAttendanceAccess() {
        // التحقق من وجود عنصر attendance في الصفحة
        // أو من خلال role (المدير ليس له حضور)
        const userRole = document.body.getAttribute('data-user-role') || 
                        window.currentUser?.role;
        
        if (userRole === 'manager') {
            return false;
        }

        this.userRole = userRole;
        const userIdAttr = document.body.getAttribute('data-user-id');
        if (userIdAttr) {
            const parsed = parseInt(userIdAttr, 10);
            if (!Number.isNaN(parsed) && parsed > 0) {
                this.userId = parsed;
            }
        } else if (window.currentUser?.id) {
            this.userId = parseInt(window.currentUser.id, 10) || null;
        }
        return true;
    }

    checkStorageAvailability() {
        try {
            if (typeof window === 'undefined' || !window.localStorage) {
                return false;
            }
            const testKey = '__attendance_storage_test__';
            window.localStorage.setItem(testKey, '1');
            window.localStorage.removeItem(testKey);
            return true;
        } catch (error) {
            console.warn('Local storage unavailable for attendance reminders:', error);
            return false;
        }
    }

    getReminderStorageKey(kind) {
        const today = new Date().toISOString().split('T')[0];
        const userId = this.userId || 'guest';
        return `attendanceReminder:${userId}:${kind}:${today}`;
    }

    hasReminderShownToday(kind) {
        if (!this.storageAvailable) {
            return false;
        }
        try {
            const key = this.getReminderStorageKey(kind);
            return window.localStorage.getItem(key) === '1';
        } catch (error) {
            console.warn('Unable to read reminder storage:', error);
            return false;
        }
    }

    markReminderShown(kind) {
        if (!this.storageAvailable) {
            return;
        }
        try {
            const key = this.getReminderStorageKey(kind);
            window.localStorage.setItem(key, '1');
        } catch (error) {
            console.warn('Unable to persist reminder marker:', error);
        }
    }

    /**
     * الحصول على موعد العمل من السيرفر
     */
    async getWorkTime() {
        try {
            // الحصول على المسار الصحيح لـ API
            const apiPath = this.getApiPath('attendance.php');
            const response = await fetch(apiPath + '?action=get_work_time', {
                method: 'GET',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Failed to get work time');
            }

            const data = await response.json();
            
            if (data.success && data.work_time) {
                return data.work_time;
            }

            return null;
        } catch (error) {
            console.error('Error getting work time:', error);
            // استخدام مواعيد افتراضية حسب الدور
            return this.getDefaultWorkTime();
        }
    }

    /**
     * الحصول على مواعيد افتراضية حسب الدور
     */
    getDefaultWorkTime() {
        if (this.userRole === 'accountant') {
            return {
                start: '10:00:00',
                end: '19:00:00'
            };
        } else {
            // عمال الإنتاج والمندوبين
            return {
                start: '09:00:00',
                end: '19:00:00'
            };
        }
    }

    /**
     * طلب الإذن للإشعارات
     */
    async requestNotificationPermission() {
        if (!('Notification' in window)) {
            console.warn('This browser does not support notifications');
            return false;
        }

        if (this.notificationPermission === 'granted') {
            return true;
        }

        if (this.notificationPermission === 'denied') {
            console.warn('Notification permission denied');
            return false;
        }

        // طلب الإذن
        try {
            const permission = await Notification.requestPermission();
            this.notificationPermission = permission;
            
            if (permission === 'granted') {
                console.log('Notification permission granted');
                return true;
            } else {
                console.warn('Notification permission denied or dismissed');
                return false;
            }
        } catch (error) {
            console.error('Error requesting notification permission:', error);
            return false;
        }
    }

    /**
     * جدولة إشعارات التذكير
     */
    scheduleReminders() {
        if (!this.notificationsEnabled) {
            return;
        }

        // إلغاء أي جدولة سابقة
        if (this.reminderTimeout) {
            clearTimeout(this.reminderTimeout);
            this.reminderTimeout = null;
        }
        if (this.checkoutReminderTimeout) {
            clearTimeout(this.checkoutReminderTimeout);
            this.checkoutReminderTimeout = null;
        }

        if (!this.workTime) {
            return;
        }

        // حساب الوقت قبل 10 دقائق من موعد العمل والانصراف
        const checkinReminderTime = this.calculateReminderTime(this.workTime.start);
        const checkoutReminderTime = this.calculateReminderTime(this.workTime.end);

        if (!checkinReminderTime) {
            console.log('Check-in reminder time calculation failed or already passed');
        }

        const now = new Date();

        if (checkinReminderTime) {
            const timeUntilCheckin = checkinReminderTime.getTime() - now.getTime();

            if (timeUntilCheckin > 0) {
                console.log(`Scheduling check-in reminder in ${Math.round(timeUntilCheckin / 1000 / 60)} minutes`);
                this.reminderTimeout = setTimeout(() => {
                    this.showReminderNotification('checkin');
                    this.scheduleNextDayReminder();
                }, timeUntilCheckin);
            } else {
                console.log('Check-in reminder time has already passed today, scheduling for next day.');
                this.scheduleNextDayReminder();
            }
        }

        if (checkoutReminderTime) {
            const timeUntilCheckout = checkoutReminderTime.getTime() - now.getTime();

            if (timeUntilCheckout > 0) {
                console.log(`Scheduling checkout reminder in ${Math.round(timeUntilCheckout / 1000 / 60)} minutes`);
                this.checkoutReminderTimeout = setTimeout(() => {
                    this.showReminderNotification('checkout');
                }, timeUntilCheckout);
            } else {
                console.log('Checkout reminder time has already passed today; will be scheduled with next day cycle.');
            }
        }
    }

    /**
     * حساب وقت التذكير (10 دقائق قبل موعد العمل)
     */
    calculateReminderTime(workStartTime) {
        const today = new Date();
        const [hours, minutes, seconds] = workStartTime.split(':').map(Number);
        
        // إنشاء كائن تاريخ لموعد العمل اليوم
        const workStart = new Date();
        workStart.setHours(hours, minutes, seconds || 0, 0);

        // حساب وقت التذكير (10 دقائق قبل)
        const reminderTime = new Date(workStart.getTime() - (10 * 60 * 1000));

        return reminderTime;
    }

    /**
     * جدولة إشعار لليوم التالي
     */
    scheduleNextDayReminder() {
        if (!this.workTime) {
            return;
        }

        const checkinReminderTime = this.calculateReminderTime(this.workTime.start);
        const checkoutReminderTime = this.calculateReminderTime(this.workTime.end);

        if (!checkinReminderTime && !checkoutReminderTime) {
            return;
        }

        const now = new Date();

        if (checkinReminderTime) {
            checkinReminderTime.setDate(checkinReminderTime.getDate() + 1);
            const timeUntilCheckin = checkinReminderTime.getTime() - now.getTime();
            if (timeUntilCheckin > 0) {
                this.reminderTimeout = setTimeout(() => {
                    this.showReminderNotification('checkin');
                    this.scheduleNextDayReminder();
                }, timeUntilCheckin);
            }
        }

        if (checkoutReminderTime) {
            checkoutReminderTime.setDate(checkoutReminderTime.getDate() + 1);
            const timeUntilCheckout = checkoutReminderTime.getTime() - now.getTime();
            if (timeUntilCheckout > 0) {
                this.checkoutReminderTimeout = setTimeout(() => {
                    this.showReminderNotification('checkout');
                }, timeUntilCheckout);
            }
        }
    }

    /**
     * عرض إشعار التذكير
     */
    async showReminderNotification(eventType = 'checkin') {
        if (!this.notificationsEnabled) {
            return;
        }

        const status = await this.getTodayStatus();

        if (eventType === 'checkin' && status.checked_in) {
            console.log('Check-in reminder skipped (already checked in).');
            return;
        }

        if (eventType === 'checkout' && (!status.checked_in || status.checked_out)) {
            console.log('Checkout reminder skipped (not eligible).');
            return;
        }

        if (this.hasReminderShownToday(eventType)) {
            console.log(`Reminder for ${eventType} already shown today – skipping.`);
            return;
        }

        if (this.notificationPermission !== 'granted') {
            await this.requestNotificationPermission();
            if (this.notificationPermission !== 'granted') {
                return;
            }
        }

        const targetTime = eventType === 'checkin' ? this.workTime.start : this.workTime.end;
        const formattedTime = this.formatTime(targetTime);

        const notificationOptions = {
            body: eventType === 'checkin'
                ? `موعد العمل يبدأ في الساعة ${formattedTime}. يرجى تسجيل الحضور قبل الموعد.`
                : `موعد الانصراف المحدد في الساعة ${formattedTime}. لا تنس تسجيل الانصراف.`,
            icon: '/assets/images/logo.png',
            badge: '/assets/images/badge.png',
            tag: eventType === 'checkin' ? 'attendance-reminder' : 'checkout-reminder',
            requireInteraction: false,
            silent: false,
            data: {
                url: window.location.origin + '/attendance.php',
                type: eventType === 'checkin' ? 'attendance_reminder' : 'checkout_reminder'
            }
        };

        try {
            const notification = new Notification(
                eventType === 'checkin' ? 'تذكير بتسجيل الحضور' : 'تذكير بتسجيل الانصراف',
                notificationOptions
            );

            notification.onclick = function(event) {
                event.preventDefault();
                window.focus();
                if (notification.data && notification.data.url) {
                    window.open(notification.data.url, '_self');
                }
                notification.close();
            };

            setTimeout(() => {
                notification.close();
            }, 10000);

            console.log(`Reminder notification shown (${eventType})`);
            this.markReminderShown(eventType);
        } catch (error) {
            console.error('Error showing notification:', error);
        }
    }

    async showImmediateReminder(eventType) {
        if (!this.notificationsEnabled) {
            return;
        }
        await this.showReminderNotification(eventType);
    }

    async showImmediateRemindersOnLogin() {
        const status = await this.getTodayStatus();

        if (!status.checked_in) {
            await this.showReminderNotification('checkin');
        } else if (!status.checked_out && status.past_work_end) {
            await this.showOverdueCheckoutReminder();
        } else if (!status.checked_out) {
            await this.showReminderNotification('checkout');
        }
    }

    invalidateTodayStatusCache() {
        this.todayStatusCache = null;
        this.todayStatusCacheTimestamp = 0;
        this.todayStatusCachePromise = null;
    }

    async getTodayStatus(forceRefresh = false) {
        const nowMs = Date.now();

        if (
            !forceRefresh &&
            this.todayStatusCache &&
            (nowMs - this.todayStatusCacheTimestamp) < this.statusCacheTTL
        ) {
            return this.todayStatusCache;
        }

        if (!forceRefresh && this.todayStatusCachePromise) {
            return this.todayStatusCachePromise;
        }

        const fetchPromise = (async () => {
            try {
                const apiPath = this.getApiPath('attendance.php');
                const response = await fetch(apiPath + '?action=check_today', {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Failed to get today status');
                }

                const data = await response.json();
                const now = new Date();
                const workEndTime = this.parseTimeToDate(this.workTime?.end);
                const pastWorkEnd = workEndTime ? now > workEndTime : false;

                const status = {
                    checked_in: Boolean(data.checked_in),
                    checked_out: Boolean(data.checked_out),
                    past_work_end: pastWorkEnd
                };

                this.todayStatusCache = status;
                this.todayStatusCacheTimestamp = Date.now();
                return status;
            } catch (error) {
                console.error('Error getting today status:', error);
                if (this.todayStatusCache) {
                    return this.todayStatusCache;
                }
                return { checked_in: false, checked_out: false, past_work_end: false };
            } finally {
                this.todayStatusCachePromise = null;
            }
        })();

        this.todayStatusCachePromise = fetchPromise;
        return fetchPromise;
    }

    parseTimeToDate(timeString) {
        if (!timeString) {
            return null;
        }
        const [hours, minutes, seconds] = timeString.split(':').map(Number);
        const date = new Date();
        date.setHours(hours, minutes, seconds || 0, 0);
        return date;
    }

    async hasCheckedInToday() {
        const status = await this.getTodayStatus();
        return status.checked_in;
    }

    async hasCheckedOutToday() {
        const status = await this.getTodayStatus();
        return status.checked_out;
    }

    /**
     * الحصول على مسار API ديناميكياً
     */
    getApiPath(filename) {
        const currentPath = window.location.pathname || '/';
        const rawParts = currentPath.split('/').filter(Boolean);

        if (rawParts.length > 0 && rawParts[rawParts.length - 1].includes('.')) {
            rawParts.pop();
        }

        const stopIndex = rawParts.findIndex(part => part === 'dashboard' || part === 'modules');
        const baseParts = stopIndex === -1 ? rawParts : rawParts.slice(0, stopIndex);

        let basePath = '/';
        if (baseParts.length > 0) {
            basePath = '/' + baseParts.join('/') + '/';
        }

        const fullPath = `${basePath}api/${filename}`.replace(/\/{2,}/g, '/');

        return fullPath.startsWith('/') ? fullPath : '/' + fullPath;
    }

    /**
     * تنسيق الوقت
     */
    formatTime(timeString) {
        const [hours, minutes] = timeString.split(':');
        const hour = parseInt(hours);
        const minute = parseInt(minutes);
        const period = hour >= 12 ? 'م' : 'ص';
        const hour12 = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
        return `${hour12}:${minute.toString().padStart(2, '0')} ${period}`;
    }

    /**
     * بدء الفحص اليومي
     */
    startDailyCheck() {
        if (!this.notificationsEnabled) {
            return;
        }

        this.dailyCheckInterval = setInterval(async () => {
            const now = new Date();
            if (now.getHours() === 0 && now.getMinutes() === 0) {
                this.invalidateTodayStatusCache();
                this.scheduleReminders();
            }

            const status = await this.getTodayStatus();
            if (status.checked_in && !status.checked_out && status.past_work_end) {
                this.showOverdueCheckoutReminder();
            }
        }, 60000);
    }

    /**
     * إيقاف نظام الإشعارات
     */
    destroy() {
        if (this.reminderTimeout) {
            clearTimeout(this.reminderTimeout);
            this.reminderTimeout = null;
        }
        if (this.checkoutReminderTimeout) {
            clearTimeout(this.checkoutReminderTimeout);
            this.checkoutReminderTimeout = null;
        }

        if (this.dailyCheckInterval) {
            clearInterval(this.dailyCheckInterval);
            this.dailyCheckInterval = null;
        }
    }

    async showOverdueCheckoutReminder() {
        if (!this.notificationsEnabled) {
            return;
        }

        const status = await this.getTodayStatus();
        if (!status.checked_in || status.checked_out || !status.past_work_end) {
            return;
        }

        if (this.hasReminderShownToday('checkout')) {
            return;
        }

        if (this.notificationPermission !== 'granted') {
            await this.requestNotificationPermission();
        }

        const message = 'انتهى وقت العمل المحدد. يرجى تسجيل الانصراف للحفاظ على بيانات الحضور.';

        if (window.showBrowserNotification) {
            showBrowserNotification('تذكير تسجيل الانصراف', message, null, 'checkout-overdue');
        } else if ('Notification' in window && Notification.permission === 'granted') {
            try {
                const notification = new Notification('تذكير تسجيل الانصراف', {
                    body: message,
                    icon: '/assets/images/logo.png',
                    badge: '/assets/images/badge.png',
                    tag: 'checkout-overdue',
                    requireInteraction: false,
                    data: {
                        url: window.location.origin + '/attendance.php',
                        type: 'checkout_overdue'
                    }
                });

                notification.onclick = function(event) {
                    event.preventDefault();
                    window.focus();
                    if (notification.data && notification.data.url) {
                        window.open(notification.data.url, '_self');
                    }
                    notification.close();
                };

                setTimeout(() => notification.close(), 10000);
                this.markReminderShown('checkout');
            } catch (error) {
                console.error('Error showing overdue checkout notification:', error);
            }
        }

        // تم تعطيل الأصوات المصاحبة للتنبيهات بناءً على طلب الإدارة
    }

    playAlertSound() {
        // تم إيقاف تشغيل أي أصوات للتنقل أو الإشعارات
        return;
    }
}

// تهيئة النظام عند تحميل الصفحة
let attendanceNotificationManager = null;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        attendanceNotificationManager = new AttendanceNotificationManager();
        attendanceNotificationManager.init();
    });
} else {
    attendanceNotificationManager = new AttendanceNotificationManager();
    attendanceNotificationManager.init();
}

// تصدير للاستخدام العام
if (typeof window !== 'undefined') {
    window.AttendanceNotificationManager = AttendanceNotificationManager;
    window.attendanceNotificationManager = attendanceNotificationManager;
}

