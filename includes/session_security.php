<?php
/**
 * Security Enhancement: Session Management (InfinityFree Compatible)
 * تأمين الجلسات - متوافق مع InfinityFree ومدمج مع النظام الحالي
 * 
 * هذا الملف يحسّن إدارة الجلسات دون تعارض مع config.php
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * تهيئة الجلسة الآمنة - متوافقة مع النظام الحالي
 * لا تبدأ الجلسة إذا كانت بدأت بالفعل في config.php
 */
function initSecureSession() {
    // إذا كانت الجلسة بدأت بالفعل، فقط تحديث الإعدادات
    if (session_status() === PHP_SESSION_ACTIVE) {
        // تحديث آخر نشاط
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
        }
        
        // التحقق من انتهاء صلاحية الجلسة
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800; // 30 دقيقة افتراضياً
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            session_unset();
            session_destroy();
            // إعادة بدء جلسة جديدة
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['last_activity'] = time();
        } else {
            // تحديث آخر نشاط
            $_SESSION['last_activity'] = time();
        }
        
        return;
    }
    
    // محاولة إنشاء مجلد sessions داخل tmp (اختياري)
    $sessionPath = __DIR__ . '/../tmp/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0750, true);
    }
    
    // تعيين مسار الجلسات إذا كان قابل للكتابة (اختياري)
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        @session_save_path($sessionPath);
    }
    
    // إعدادات آمنة للكوكيز (متوافقة مع config.php)
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    
    // استخدام نفس الإعدادات من config.php إذا كانت موجودة
    if (defined('SESSION_LIFETIME')) {
        $lifetime = SESSION_LIFETIME;
    } else {
        $lifetime = 0; // session cookie
    }
    
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
    
    // تجديد معرف الجلسة للجلسات الجديدة فقط
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['created_at'] = time();
        $_SESSION['last_activity'] = time();
    } else {
        // تحديث آخر نشاط
        $_SESSION['last_activity'] = time();
    }
    
    // التحقق من انتهاء صلاحية الجلسة
    $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['last_activity'] = time();
    }
}

/**
 * تجديد معرف الجلسة بعد تسجيل الدخول
 * متوافق مع النظام الحالي
 */
function regenerateSessionAfterLogin() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();
        $_SESSION['last_activity'] = time();
    }
}

/**
 * تنظيف الجلسات القديمة (اختياري - لتوفير المساحة)
 */
function cleanupOldSessions() {
    $sessionPath = __DIR__ . '/../tmp/sessions';
    if (!is_dir($sessionPath)) {
        return;
    }
    
    $files = glob($sessionPath . '/sess_*');
    $now = time();
    $cleaned = 0;
    
    foreach ($files as $file) {
        // حذف الجلسات الأقدم من ساعة
        if (filemtime($file) < ($now - 3600)) {
            @unlink($file);
            $cleaned++;
        }
    }
    
    return $cleaned;
}
