<?php
/**
 * Rate Limiting (InfinityFree Compatible - Uses Existing login_attempts Table)
 * حماية من Brute Force - يستخدم جدول login_attempts الموجود
 * 
 * هذا الملف يحسّن Rate Limiting ويتكامل مع security.php الموجود
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/db.php';

class RateLimiter {
    private static $maxAttempts = 5;
    private static $timeWindow = 300;       // 5 دقائق
    private static $blockDuration = 900;    // 15 دقيقة
    
    /**
     * الحصول على معرف فريد (IP + Username)
     */
    private static function getIdentifier($username = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $username ? md5($ip . '_' . $username) : md5($ip);
    }
    
    /**
     * التحقق من محاولات تسجيل الدخول - يستخدم جدول login_attempts الموجود
     */
    public static function checkLoginAttempt($username) {
        try {
            $db = db();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // استخدام النظام الموجود في security.php أولاً
            if (function_exists('isIPBlocked')) {
                require_once __DIR__ . '/security.php';
                if (isIPBlocked($ipAddress)) {
                    return [
                        'allowed' => false,
                        'message' => 'عنوان IP محظور. يرجى الاتصال بالإدارة.',
                        'remaining_time' => 0
                    ];
                }
            }
            
            // استخدام checkFailedAttempts الموجود في security.php
            if (function_exists('checkFailedAttempts')) {
                require_once __DIR__ . '/security.php';
                $isBlocked = checkFailedAttempts($ipAddress, $username);
                if ($isBlocked) {
                    return [
                        'allowed' => false,
                        'message' => 'تم حظر المحاولات. يرجى المحاولة بعد 15 دقيقة',
                        'remaining_time' => 900
                    ];
                }
            }
            
            // فحص إضافي: عدد المحاولات الفاشلة في آخر 5 دقائق
            $timeLimit = date('Y-m-d H:i:s', strtotime('-' . self::$timeWindow . ' seconds'));
            
            $where = "ip_address = ? AND success = 0 AND created_at >= ?";
            $params = [$ipAddress, $timeLimit];
            
            if ($username) {
                $where .= " AND username = ?";
                $params[] = $username;
            }
            
            $result = $db->queryOne(
                "SELECT COUNT(*) as count FROM login_attempts WHERE $where",
                $params
            );
            
            $failedCount = $result['count'] ?? 0;
            
            // إذا تجاوز الحد المسموح
            if ($failedCount >= self::$maxAttempts) {
                // حساب الوقت المتبقي
                $firstAttempt = $db->queryOne(
                    "SELECT MIN(created_at) as first_attempt FROM login_attempts WHERE $where",
                    $params
                );
                
                $firstAttemptTime = strtotime($firstAttempt['first_attempt'] ?? 'now');
                $elapsed = time() - $firstAttemptTime;
                $remainingTime = max(0, self::$blockDuration - $elapsed);
                $minutes = ceil($remainingTime / 60);
                
                return [
                    'allowed' => false,
                    'message' => "تم حظر المحاولات. يرجى المحاولة بعد {$minutes} دقيقة",
                    'remaining_time' => $remainingTime
                ];
            }
            
            // حساب المحاولات المتبقية
            $remaining = self::$maxAttempts - $failedCount;
            
            return [
                'allowed' => true,
                'remaining_attempts' => max(0, $remaining)
            ];
            
        } catch (Exception $e) {
            // في حالة خطأ قاعدة البيانات، اسمح بالمحاولة
            error_log("Rate Limiter Error: " . $e->getMessage());
            return ['allowed' => true];
        }
    }
    
    /**
     * تسجيل محاولة فاشلة - يستخدم logLoginAttempt الموجود
     */
    public static function recordFailedAttempt($username) {
        try {
            // استخدام النظام الموجود في security.php
            if (function_exists('logLoginAttempt')) {
                require_once __DIR__ . '/security.php';
                logLoginAttempt($username, false, 'كلمة مرور خاطئة');
            }
            
            // حساب المحاولات المتبقية
            $check = self::checkLoginAttempt($username);
            if ($check['allowed']) {
                return $check['remaining_attempts'] ?? self::$maxAttempts;
            } else {
                return 0;
            }
            
        } catch (Exception $e) {
            error_log("Rate Limiter Record Error: " . $e->getMessage());
            return self::$maxAttempts;
        }
    }
    
    /**
     * إعادة تعيين المحاولات بعد تسجيل دخول ناجح
     */
    public static function resetAttempts($username) {
        try {
            // النظام الموجود في security.php يتعامل مع هذا تلقائياً
            // لكن يمكن إضافة تنظيف إضافي هنا إذا لزم الأمر
            return true;
        } catch (Exception $e) {
            error_log("Rate Limiter Reset Error: " . $e->getMessage());
            return false;
        }
    }
}
