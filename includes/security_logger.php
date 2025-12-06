<?php
/**
 * Security Logger (Disabled by default on InfinityFree)
 * مسجل الأحداث الأمنية - معطل افتراضياً لتوفير موارد InfinityFree
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class SecurityLogger {
    /**
     * تسجيل حدث أمني
     * معطل افتراضياً لتوفير Inodes على InfinityFree
     */
    public static function log($type, $message, $data = []) {
        // معطل على InfinityFree لتوفير Inodes
        if (!defined('ENABLE_SECURITY_LOGGING') || ENABLE_SECURITY_LOGGING !== true) {
            return;
        }
        
        // الكود الأصلي هنا إذا أردت تفعيله لاحقاً
        // يمكن إضافة تسجيل في قاعدة البيانات أو ملفات log
    }
}

/**
 * دالة مساعدة لتسجيل الأحداث الأمنية
 */
function logSecurityEvent($type, $data = []) {
    // لا تفعل شيء على InfinityFree (معطل افتراضياً)
    if (defined('ENABLE_SECURITY_LOGGING') && ENABLE_SECURITY_LOGGING === true) {
        SecurityLogger::log($type, '', $data);
    }
}
