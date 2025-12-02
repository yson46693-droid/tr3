<?php
/**
 * نظام المصادقة والتحقق من الأدوار
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// تحميل path_helper إذا كان متوفراً
if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
    require_once __DIR__ . '/path_helper.php';
}

/**
 * الحصول على الحد الأدنى لطول كلمة المرور من الإعدادات
 *
 * @return int
 */
function getPasswordMinLength(): int
{
    static $cachedValue = null;

    if ($cachedValue !== null) {
        return $cachedValue;
    }

    if (defined('PASSWORD_MIN_LENGTH')) {
        $value = (int) PASSWORD_MIN_LENGTH;
    } else {
        $value = 8;
    }

    if ($value < 1) {
        $value = 1;
    }

    return $cachedValue = $value;
}

/**
 * التحقق من تسجيل الدخول
 */
function isLoggedIn() {
    // التحقق الأمني: إذا لم يكن هناك session cookie، إلغاء الجلسة
    $sessionName = session_name();
    if (!isset($_COOKIE[$sessionName]) && session_status() === PHP_SESSION_ACTIVE) {
        // إذا تم مسح session cookie من المتصفح، يجب إلغاء الجلسة
        session_unset();
        session_destroy();
        return false;
    }
    
    // التحقق من أن session ID في cookie يطابق session ID الحالي
    if (session_status() === PHP_SESSION_ACTIVE && isset($_COOKIE[$sessionName])) {
        $cookieSessionId = $_COOKIE[$sessionName];
        $currentSessionId = session_id();
        
        // إذا كان session ID في cookie لا يطابق session ID الحالي، إلغاء الجلسة
        if ($cookieSessionId !== $currentSessionId) {
            session_unset();
            session_destroy();
            return false;
        }
    }
    
    // التحقق من الجلسة أولاً
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        // التحقق الإضافي: التأكد من وجود user_id في الجلسة
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            // إذا لم يكن هناك user_id، إلغاء الجلسة
            session_unset();
            session_destroy();
            return false;
        }
        return true;
    }
    
    // إذا لم تكن هناك جلسة، التحقق من cookie "تذكرني"
    if (isset($_COOKIE['remember_token'])) {
        return checkRememberToken($_COOKIE['remember_token']);
    }
    
    return false;
}

/**
 * إنشاء جدول remember_tokens إذا لم يكن موجوداً
 */
function ensureRememberTokensTable() {
    $db = db();
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'remember_tokens'");
    
    if (empty($tableCheck)) {
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `remember_tokens` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(11) NOT NULL,
                  `token` varchar(64) NOT NULL,
                  `expires_at` datetime NOT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `user_agent` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `last_used` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `user_id_token` (`user_id`, `token`),
                  KEY `token` (`token`),
                  KEY `expires_at` (`expires_at`),
                  KEY `user_id` (`user_id`),
                  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log("Error creating remember_tokens table: " . $e->getMessage());
            return false;
        }
    }
    return true;
}

/**
 * التحقق من token "تذكرني"
 */
function checkRememberToken($cookieValue) {
    try {
        // التأكد من وجود الجدول
        if (!ensureRememberTokensTable()) {
            return false;
        }
        
        $decoded = base64_decode($cookieValue);
        if (!$decoded) {
            return false;
        }
        
        $parts = explode(':', $decoded);
        if (count($parts) !== 2) {
            return false;
        }
        
        $userId = intval($parts[0]);
        $token = $parts[1];
        
        if ($userId <= 0 || empty($token)) {
            return false;
        }
        
        $db = db();
        $tokenRecord = $db->queryOne(
            "SELECT rt.*, u.* FROM remember_tokens rt
             INNER JOIN users u ON rt.user_id = u.id
             WHERE rt.user_id = ? AND rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'",
            [$userId, $token]
        );
        
        if (!$tokenRecord) {
            // حذف cookie غير صالح
            $isHttps = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
            );
            setcookie(
                'remember_token',
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
            return false;
        }
        
        // تحديث آخر استخدام
        $db->execute(
            "UPDATE remember_tokens SET last_used = NOW() WHERE id = ?",
            [$tokenRecord['id']]
        );
        
        // إنشاء جلسة جديدة
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $tokenRecord['user_id'];
        $_SESSION['username'] = $tokenRecord['username'];
        $_SESSION['role'] = $tokenRecord['role'];
        $_SESSION['logged_in'] = true;
        generateCSRFToken(true);
        
        return true;
    } catch (Exception $e) {
        error_log("Remember Token Check Error: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على معلومات المستخدم الحالي
 * مع التحقق من وجود المستخدم وحالته وإلغاء تسجيل الدخول تلقائياً إذا كان محذوفاً أو غير مفعّل
 * مع استخدام Cache لتحسين الأداء
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }
    
    // تحميل نظام Cache
    if (!class_exists('Cache')) {
        $cacheFile = __DIR__ . '/cache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
        }
    }
    
    // استخدام Cache لحفظ بيانات المستخدم لمدة 5 دقائق
    $cacheKey = "user_{$userId}";
    
    // إذا كان Cache متاحاً، استخدمه
    if (class_exists('Cache')) {
        $user = Cache::remember($cacheKey, function() use ($userId) {
            return getCurrentUserFromDatabase($userId);
        }, 300); // 5 دقائق
        
        // إذا كان المستخدم null (محذوف)، احذف من Cache
        if ($user === null) {
            Cache::forget($cacheKey);
            return null;
        }
        
        return $user;
    }
    
    // إذا لم يكن Cache متاحاً، استخدم الطريقة القديمة
    return getCurrentUserFromDatabase($userId);
}

/**
 * جلب بيانات المستخدم من قاعدة البيانات (دالة مساعدة)
 * 
 * @param int $userId معرف المستخدم
 * @return array|null بيانات المستخدم أو null
 */
function getCurrentUserFromDatabase($userId) {
    // جلب جميع بيانات المستخدم من قاعدة البيانات
    $db = db();
    $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
    
    // إذا كان المستخدم غير موجود أو محذوف من قاعدة البيانات
    if (!$user) {
        // إلغاء تسجيل الدخول تلقائياً لأسباب أمنية
        error_log("Security: User ID {$userId} not found in database - Auto logout");
        // حذف الجلسة مباشرة دون استدعاء logout() لتجنب حلقة لا نهائية
        session_unset();
        session_destroy();
        if (isset($_COOKIE['remember_token'])) {
            try {
                if (ensureRememberTokensTable()) {
                    $decoded = base64_decode($_COOKIE['remember_token']);
                    if ($decoded) {
                        $parts = explode(':', $decoded);
                        if (count($parts) === 2) {
                            $tokenUserId = intval($parts[0]);
                            $token = $parts[1];
                            $db->execute(
                                "DELETE FROM remember_tokens WHERE user_id = ? AND token = ?",
                                [$tokenUserId, $token]
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Security: Error deleting remember token: " . $e->getMessage());
            }
        }
        setcookie('remember_token', '', time() - 3600, '/');
        return null;
    }
    
    // التحقق من حالة المستخدم - إذا كان غير مفعّل
    if (isset($user['status']) && $user['status'] !== 'active') {
        // إلغاء تسجيل الدخول تلقائياً لأسباب أمنية
        error_log("Security: User ID {$userId} status is '{$user['status']}' - Auto logout");
        // حذف الجلسة مباشرة دون استدعاء logout() لتجنب حلقة لا نهائية
        session_unset();
        session_destroy();
        if (isset($_COOKIE['remember_token'])) {
            try {
                if (ensureRememberTokensTable()) {
                    $decoded = base64_decode($_COOKIE['remember_token']);
                    if ($decoded) {
                        $parts = explode(':', $decoded);
                        if (count($parts) === 2) {
                            $tokenUserId = intval($parts[0]);
                            $token = $parts[1];
                            $db->execute(
                                "DELETE FROM remember_tokens WHERE user_id = ? AND token = ?",
                                [$tokenUserId, $token]
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Security: Error deleting remember token: " . $e->getMessage());
            }
        }
        setcookie('remember_token', '', time() - 3600, '/');
        return null;
    }
    
    // تنظيف جميع القيم المالية
    $financialFields = ['hourly_rate', 'salary', 'basic_salary', 'bonus', 'deductions', 'total_amount'];
    foreach ($financialFields as $field) {
        if (isset($user[$field])) {
            $user[$field] = cleanFinancialValue($user[$field]);
        }
    }
    
    return $user;
}

/**
 * الحصول على معلومات المستخدم حسب ID
 */
function getUserById($userId) {
    $db = db();
    return $db->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
}

/**
 * الحصول على معلومات المستخدم حسب اسم المستخدم
 */
function getUserByUsername($username) {
    $db = db();
    return $db->queryOne(
        "SELECT * FROM users WHERE username = ?",
        [$username]
    );
}

/**
 * التحقق من كلمة المرور
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * تسجيل الدخول
 */
function login($username, $password, $rememberMe = false) {
    // التحقق من حظر IP
    require_once __DIR__ . '/security.php';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    if (isIPBlocked($ipAddress)) {
        logLoginAttempt($username, false, 'IP محظور');
        return ['success' => false, 'message' => 'عنوان IP محظور. يرجى الاتصال بالإدارة.'];
    }
    
    $user = getUserByUsername($username);
    
    if (!$user) {
        logLoginAttempt($username, false, 'مستخدم غير موجود');
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }
    
    if ($user['status'] !== 'active') {
        logLoginAttempt($username, false, 'حساب غير مفعّل');
        return ['success' => false, 'message' => 'الحساب غير مفعّل'];
    }
    
    if (!verifyPassword($password, $user['password_hash'])) {
        logLoginAttempt($username, false, 'كلمة مرور خاطئة');
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }
    
    // تسجيل الدخول
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    generateCSRFToken(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    
    // إذا تم تفعيل "تذكرني"، إنشاء cookie
    if ($rememberMe) {
        // التأكد من وجود الجدول
        if (!ensureRememberTokensTable()) {
            // إذا فشل إنشاء الجدول، متابعة بدون "تذكرني"
            error_log("Failed to create remember_tokens table, continuing without remember me");
        } else {
            // توليد token آمن
            $token = bin2hex(random_bytes(32));
            
            // حفظ token في قاعدة البيانات
            $db = db();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days')); // 30 يوم
            
            // حذف أي token موجود لنفس المستخدم
            try {
                $db->execute("DELETE FROM remember_tokens WHERE user_id = ?", [$user['id']]);
            } catch (Exception $e) {
                error_log("Error deleting remember token: " . $e->getMessage());
            }
            
            // إضافة token جديد
            try {
                $db->execute(
                    "INSERT INTO remember_tokens (user_id, token, expires_at, ip_address, user_agent) 
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $user['id'], 
                        $token, 
                        $expiresAt, 
                        $ipAddress, 
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]
                );
            } catch (Exception $e) {
                error_log("Error inserting remember token: " . $e->getMessage());
                // متابعة بدون إنشاء cookie
                $rememberMe = false;
            }
            
            // إنشاء cookie آمن
            if ($rememberMe) {
                $cookieValue = base64_encode($user['id'] . ':' . $token);
                setcookie(
                    'remember_token',
                    $cookieValue,
                    [
                        'expires' => time() + (30 * 24 * 60 * 60), // 30 يوم
                        'path' => '/',
                        'domain' => '',
                        'secure' => $isHttps,
                        'httponly' => true, // منع JavaScript من الوصول
                        'samesite' => 'Lax'
                    ]
                );
            }
        }
    }
    
    // تسجيل محاولة ناجحة
    logLoginAttempt($username, true);
    
    // تسجيل سجل التدقيق
    require_once __DIR__ . '/audit_log.php';
    logAudit($user['id'], 'login', 'user', $user['id'], null, [
        'method' => 'password',
        'remember_me' => $rememberMe ? 'yes' : 'no'
    ]);
    
    return ['success' => true, 'user' => $user];
}

/**
 * تنظيف Cache للمستخدم
 * 
 * @param int|null $userId معرف المستخدم (null لحذف Cache المستخدم الحالي)
 */
function clearUserCache($userId = null) {
    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    if ($userId) {
        // تحميل نظام Cache
        if (!class_exists('Cache')) {
            $cacheFile = __DIR__ . '/cache.php';
            if (file_exists($cacheFile)) {
                require_once $cacheFile;
            }
        }
        
        if (class_exists('Cache')) {
            Cache::forget("user_{$userId}");
        }
    }
}

/**
 * تسجيل الخروج
 */
function logout() {
    // تنظيف Cache قبل تسجيل الخروج
    clearUserCache();
    
    // حذف remember token من قاعدة البيانات
    if (isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
        try {
            // التأكد من وجود الجدول أولاً
            if (ensureRememberTokensTable()) {
                $db = db();
                $decoded = base64_decode($_COOKIE['remember_token']);
                if ($decoded) {
                    $parts = explode(':', $decoded);
                    if (count($parts) === 2) {
                        $userId = intval($parts[0]);
                        $token = $parts[1];
                        $db->execute(
                            "DELETE FROM remember_tokens WHERE user_id = ? AND token = ?",
                            [$userId, $token]
                        );
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Logout Remember Token Delete Error: " . $e->getMessage());
        }
    }
    
    // حذف cookie
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    );
    setcookie(
        'remember_token',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
    if (isLoggedIn()) {
        $userId = $_SESSION['user_id'] ?? null;
        
        // تسجيل سجل التدقيق
        if ($userId) {
            require_once __DIR__ . '/audit_log.php';
            logAudit($userId, 'logout', 'user', $userId, null, null);
        }
    }
    
    session_unset();
    unset($_SESSION['csrf_token']);
    session_destroy();
}

/**
 * التحقق من الدور
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }

    if (is_array($role)) {
        return hasAnyRole($role);
    }

    if (!is_string($role) || $role === '') {
        return false;
    }
    
    $currentRole = $_SESSION['role'] ?? null;
    if (!is_string($currentRole) || $currentRole === '') {
        return false;
    }
    
    return strtolower($currentRole) === strtolower($role);
}

/**
 * التحقق من أي دور من الأدوار المحددة
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentRole = $_SESSION['role'] ?? null;
    if ($currentRole === null) {
        return false;
    }
    
    $normalizedRoles = array_map(static function ($role) {
        return strtolower((string) $role);
    }, array_filter((array) $roles, static function ($role) {
        return $role !== null && $role !== '';
    }));
    
    if (empty($normalizedRoles)) {
        return false;
    }
    
    return in_array(strtolower((string) $currentRole), $normalizedRoles, true);
}

/**
 * التحقق من جميع الأدوار المحددة
 */
function hasAllRoles($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    foreach ($roles as $role) {
        if (!hasRole($role)) {
            return false;
        }
    }
    
    return true;
}

/**
 * التحقق من الوصول - إعادة توجيه إذا لم يكن مسجلاً
 */
function requireLogin() {
    if (isLoggedIn()) {
        if (!function_exists('logRequestUsage')) {
            $monitorPath = __DIR__ . '/request_monitor.php';
            if (file_exists($monitorPath)) {
                require_once $monitorPath;
            }
        }
        if (function_exists('logRequestUsage')) {
            logRequestUsage();
        }
        return;
    }

    if (!isLoggedIn()) {
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getRelativeUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $loginUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl) . '"></noscript>';
        exit;
    }
}

/**
 * التحقق من الدور المحدد
 * يدعم string أو array من الأدوار
 */
function requireRole($role) {
    requireLogin();
    
    // فحص أمني: التأكد من أن المستخدم موجود في قاعدة البيانات
    $currentUser = getCurrentUser();
    if (!$currentUser || !is_array($currentUser) || empty($currentUser)) {
        // المستخدم محذوف أو غير موجود - تم إلغاء تسجيل الدخول تلقائياً من getCurrentUser()
        $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
        if (!headers_sent()) {
            header('Location: ' . $loginUrl);
            exit;
        } else {
            echo '<script>window.location.href = "' . htmlspecialchars($loginUrl) . '";</script>';
            exit;
        }
    }
    
    // إذا كان array، استخدم requireAnyRole
    if (is_array($role)) {
        return requireAnyRole($role);
    }
    
    if (!hasRole($role)) {
        $userRole = $_SESSION['role'] ?? 'accountant';
        
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getDashboardUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $dashboardUrl = function_exists('getDashboardUrl') ? getDashboardUrl($userRole) : '/dashboard/' . $userRole . '.php';
        
        // تنظيف شامل للمسار لضمان عدم تكرار الخطأ
        // 1. إزالة أي بروتوكول
        $dashboardUrl = preg_replace('/^https?:\/\//', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 3. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 4. إزالة أي hostname إذا كان موجوداً
        if (preg_match('/^\/[^\/]+\.[a-z]/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 5. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 6. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 7. تنظيف نهائي
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $dashboardUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

/**
 * التحقق من أي دور من الأدوار المحددة
 */
function requireAnyRole($roles) {
    requireLogin();
    
    if (!hasAnyRole($roles)) {
        $userRole = $_SESSION['role'] ?? 'accountant';
        
        // محاولة تحميل path_helper إذا لم يكن محملاً
        if (!function_exists('getDashboardUrl') && file_exists(__DIR__ . '/path_helper.php')) {
            require_once __DIR__ . '/path_helper.php';
        }
        $dashboardUrl = function_exists('getDashboardUrl') ? getDashboardUrl($userRole) : '/dashboard/' . $userRole . '.php';
        
        // تنظيف شامل للمسار لضمان عدم تكرار الخطأ
        // 1. إزالة أي بروتوكول
        $dashboardUrl = preg_replace('/^https?:\/\//', '', $dashboardUrl);
        $dashboardUrl = preg_replace('/^\/\//', '/', $dashboardUrl);
        
        // 2. التأكد من أن المسار يبدأ بـ /
        if (strpos($dashboardUrl, '/') !== 0) {
            $dashboardUrl = '/' . $dashboardUrl;
        }
        
        // 3. تنظيف المسار (إزالة // المكررة)
        $dashboardUrl = preg_replace('/\/+/', '/', $dashboardUrl);
        
        // 4. إزالة أي hostname إذا كان موجوداً
        if (preg_match('/^\/[^\/]+\.[a-z]/i', $dashboardUrl)) {
            $parts = explode('/', $dashboardUrl);
            $dashboardIndex = array_search('dashboard', $parts);
            if ($dashboardIndex !== false) {
                $dashboardUrl = '/' . implode('/', array_slice($parts, $dashboardIndex));
            } else {
                $dashboardUrl = '/dashboard/' . $userRole . '.php';
            }
        }
        
        // 5. التحقق النهائي: إذا كان المسار لا يحتوي على 'dashboard'، أضفه
        if (strpos($dashboardUrl, '/dashboard') === false) {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // 6. التأكد من أن المسار لا يحتوي على http:// أو https:// مرة أخرى
        if (strpos($dashboardUrl, 'http://') === 0 || strpos($dashboardUrl, 'https://') === 0) {
            $parsed = parse_url($dashboardUrl);
            $dashboardUrl = $parsed['path'] ?? '/dashboard/' . $userRole . '.php';
        }
        
        // 7. تنظيف نهائي
        $dashboardUrl = trim($dashboardUrl);
        if (empty($dashboardUrl) || $dashboardUrl === '/') {
            $dashboardUrl = '/dashboard/' . $userRole . '.php';
        }
        
        // التحقق من إرسال الـ headers أو تضمين header.php
        $headersSent = @headers_sent($file, $line);
        $headerIncluded = defined('HEADER_INCLUDED') && HEADER_INCLUDED;
        
        // إذا تم تضمين header.php أو كانت الـ headers قد أُرسلت، استخدم JavaScript redirect دائماً
        if ($headerIncluded || $headersSent) {
            echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
            exit;
        }
        
        // محاولة إرسال header فقط إذا لم تكن الـ headers قد أُرسلت ولم يتم تضمين header.php
        if (!@headers_sent()) {
            @header('Location: ' . $dashboardUrl);
            if (!@headers_sent()) {
                exit;
            }
        }
        
        // إذا فشل header()، استخدم JavaScript redirect
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

/**
 * إنشاء كلمة مرور مشفرة
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * إنشاء رمز CSRF
 */
function generateCSRFToken($forceRefresh = false) {
    if ($forceRefresh || !isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * التحقق من رمز CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

