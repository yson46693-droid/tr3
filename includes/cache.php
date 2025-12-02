<?php
/**
 * نظام التخزين المؤقت (Cache)
 * يحسن الأداء عبر تخزين النتائج في الذاكرة والملفات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

class Cache {
    private static $memoryCache = [];
    private static $cacheDir = null;
    
    /**
     * تهيئة نظام Cache
     */
    public static function init() {
        if (self::$cacheDir === null) {
            // استخدام PRIVATE_STORAGE_PATH إذا كان متوفراً، وإلا استخدم storage
            if (defined('PRIVATE_STORAGE_PATH')) {
                $baseDir = PRIVATE_STORAGE_PATH;
            } else {
                $baseDir = dirname(__DIR__) . '/storage';
            }
            
            self::$cacheDir = $baseDir . '/cache';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }
    }
    
    /**
     * الحصول على قيمة من Cache أو تنفيذ callback وحفظها
     * 
     * @param string $key مفتاح Cache
     * @param callable $callback الدالة التي تُنفذ إذا لم تكن القيمة موجودة
     * @param int $ttl مدة الصلاحية بالثواني (افتراضياً 300 = 5 دقائق)
     * @return mixed القيمة من Cache أو نتيجة callback
     */
    public static function remember($key, $callback, $ttl = 300) {
        self::init();
        
        // التحقق من الذاكرة أولاً (أسرع)
        if (isset(self::$memoryCache[$key])) {
            return self::$memoryCache[$key];
        }
        
        // التحقق من ملف cache
        $file = self::$cacheDir . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = @unserialize(@file_get_contents($file));
            if ($data && isset($data['expires']) && isset($data['value']) && $data['expires'] > time()) {
                self::$memoryCache[$key] = $data['value'];
                return $data['value'];
            }
            // الملف منتهي الصلاحية - حذفه
            @unlink($file);
        }
        
        // تنفيذ الدالة وحفظ النتيجة
        try {
            $value = $callback();
            
            // حفظ في الذاكرة
            self::$memoryCache[$key] = $value;
            
            // حفظ في ملف (فقط إذا كان TTL > 0)
            if ($ttl > 0) {
                $data = [
                    'value' => $value,
                    'expires' => time() + $ttl,
                    'created_at' => time()
                ];
                @file_put_contents($file, serialize($data), LOCK_EX);
            }
            
            return $value;
        } catch (Exception $e) {
            error_log("Cache callback error for key '{$key}': " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * الحصول على قيمة من Cache
     * 
     * @param string $key مفتاح Cache
     * @return mixed القيمة أو null إذا لم تكن موجودة
     */
    public static function get($key) {
        self::init();
        
        // التحقق من الذاكرة أولاً
        if (isset(self::$memoryCache[$key])) {
            return self::$memoryCache[$key];
        }
        
        // التحقق من ملف cache
        $file = self::$cacheDir . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = @unserialize(@file_get_contents($file));
            if ($data && isset($data['expires']) && isset($data['value']) && $data['expires'] > time()) {
                self::$memoryCache[$key] = $data['value'];
                return $data['value'];
            }
            // الملف منتهي الصلاحية - حذفه
            @unlink($file);
        }
        
        return null;
    }
    
    /**
     * حفظ قيمة في Cache
     * 
     * @param string $key مفتاح Cache
     * @param mixed $value القيمة المراد حفظها
     * @param int $ttl مدة الصلاحية بالثواني
     * @return bool نجح أم لا
     */
    public static function put($key, $value, $ttl = 300) {
        self::init();
        
        // حفظ في الذاكرة
        self::$memoryCache[$key] = $value;
        
        // حفظ في ملف
        if ($ttl > 0) {
            $file = self::$cacheDir . '/' . md5($key) . '.cache';
            $data = [
                'value' => $value,
                'expires' => time() + $ttl,
                'created_at' => time()
            ];
            return @file_put_contents($file, serialize($data), LOCK_EX) !== false;
        }
        
        return true;
    }
    
    /**
     * حذف قيمة من Cache
     * 
     * @param string $key مفتاح Cache
     * @return bool نجح أم لا
     */
    public static function forget($key) {
        self::init();
        
        // حذف من الذاكرة
        unset(self::$memoryCache[$key]);
        
        // حذف الملف
        $file = self::$cacheDir . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            @unlink($file);
        }
        
        return true;
    }
    
    /**
     * حذف جميع قيم Cache
     * 
     * @return bool نجح أم لا
     */
    public static function flush() {
        self::init();
        
        // مسح الذاكرة
        self::$memoryCache = [];
        
        // حذف جميع الملفات
        $files = glob(self::$cacheDir . '/*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
        
        return true;
    }
    
    /**
     * تنظيف Cache المنتهية الصلاحية
     * 
     * @return int عدد الملفات المحذوفة
     */
    public static function cleanExpired() {
        self::init();
        
        $files = glob(self::$cacheDir . '/*.cache');
        $deleted = 0;
        $now = time();
        
        foreach ($files as $file) {
            $data = @unserialize(@file_get_contents($file));
            if (!$data || !isset($data['expires']) || $data['expires'] <= $now) {
                @unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * الحصول على معلومات عن Cache (للتطوير)
     * 
     * @return array معلومات Cache
     */
    public static function info() {
        self::init();
        
        $files = glob(self::$cacheDir . '/*.cache');
        $totalSize = 0;
        $expiredCount = 0;
        $activeCount = 0;
        $now = time();
        
        foreach ($files as $file) {
            $size = filesize($file);
            $totalSize += $size;
            
            $data = @unserialize(@file_get_contents($file));
            if ($data && isset($data['expires']) && $data['expires'] > $now) {
                $activeCount++;
            } else {
                $expiredCount++;
            }
        }
        
        return [
            'memory_items' => count(self::$memoryCache),
            'file_items' => count($files),
            'active_items' => $activeCount,
            'expired_items' => $expiredCount,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
    }
}

