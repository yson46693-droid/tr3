<?php
/**
 * Security Configuration (InfinityFree Optimized)
 * إعدادات الأمان - محسّنة لـ InfinityFree
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// إعدادات الجلسات
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 1800);              // 30 دقيقة
}
if (!defined('USE_IP_VALIDATION')) {
    define('USE_IP_VALIDATION', false);           // معطل لتجنب مشاكل InfinityFree
}

// إعدادات Rate Limiting
if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', 5);
}
if (!defined('LOGIN_ATTEMPT_WINDOW')) {
    define('LOGIN_ATTEMPT_WINDOW', 300);          // 5 دقائق
}
if (!defined('LOGIN_BLOCK_DURATION')) {
    define('LOGIN_BLOCK_DURATION', 900);          // 15 دقيقة
}

// إعدادات كلمات المرور
if (!defined('MIN_PASSWORD_LENGTH')) {
    define('MIN_PASSWORD_LENGTH', 8);
}
if (!defined('REQUIRE_PASSWORD_SPECIAL_CHAR')) {
    define('REQUIRE_PASSWORD_SPECIAL_CHAR', false);  // مبسط لـ InfinityFree
}
if (!defined('REQUIRE_PASSWORD_NUMBER')) {
    define('REQUIRE_PASSWORD_NUMBER', false);         // مبسط لـ InfinityFree
}

// إعدادات HTTPS
if (!defined('FORCE_HTTPS')) {
    define('FORCE_HTTPS', false);                    // معطل على InfinityFree المجاني
}

// إعدادات التسجيل (معطل افتراضياً لتوفير Inodes)
if (!defined('ENABLE_SECURITY_LOGGING')) {
    define('ENABLE_SECURITY_LOGGING', false);        // معطل لتوفير Inodes
}

// وضع التطوير
if (!defined('SECURITY_DEBUG_MODE')) {
    define('SECURITY_DEBUG_MODE', false);
}
