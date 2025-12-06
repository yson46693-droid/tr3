# Cursor AI Prompt: تحسينات أمنية متوافقة مع InfinityFree

## ⚠️ تنبيه مهم: استضافة InfinityFree المجانية

هذا المشروع سيعمل على **InfinityFree** التي لديها قيود خاصة:
- ✅ حد Entry Process محدود
- ✅ حد Inodes (30,000 ملف)
- ✅ PHP Memory 128MB فقط
- ✅ مشاكل محتملة مع PHP Sessions
- ✅ Browser Security System

**لذلك، يجب تطبيق نسخة مُحسّنة وخفيفة من التحسينات الأمنية**

---

## المرحلة 1: Session Security (معدلة لـ InfinityFree)

### أنشئ ملف: `includes/session_security.php`

```php
<?php
/**
 * Security Enhancement: Session Management (InfinityFree Compatible)
 * تأمين الجلسات - متوافق مع InfinityFree
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// تكوين آمن للجلسات - محسّن لـ InfinityFree
function initSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    
    // إنشاء مجلد sessions داخل tmp
    $sessionPath = __DIR__ . '/../tmp/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0750, true);
    }
    
    // تعيين مسار الجلسات إذا كان قابل للكتابة
    if (is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    
    // إعدادات آمنة للكوكيز
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => false,         // InfinityFree غالباً لا يدعم HTTPS المجاني بشكل كامل
        'httponly' => true,
        'samesite' => 'Lax'        // Lax بدلاً من Strict لتجنب مشاكل
    ]);
    
    session_start();
    
    // تجديد معرف الجلسة للجلسات الجديدة فقط
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['created_at'] = time();
    }
    
    // تحديث آخر نشاط
    $_SESSION['last_activity'] = time();
    
    // انتهاء صلاحية الجلسة (30 دقيقة)
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
    }
}

// تجديد معرف الجلسة بعد تسجيل الدخول
function regenerateSessionAfterLogin() {
    session_regenerate_id(true);
    $_SESSION['regenerated_at'] = time();
}
```

---

## المرحلة 2: CSRF Protection (مبسطة)

### أنشئ ملف: `includes/csrf_protection.php`

```php
<?php
/**
 * CSRF Protection (InfinityFree Compatible)
 * حماية CSRF - نسخة خفيفة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class CSRFProtection {
    private static $tokenName = 'csrf_token';
    
    public static function generateToken() {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::$tokenName] = $token;
        $_SESSION[self::$tokenName . '_time'] = time();
        
        return $token;
    }
    
    public static function getToken() {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        if (!isset($_SESSION[self::$tokenName])) {
            return self::generateToken();
        }
        
        // التحقق من انتهاء الصلاحية (ساعة واحدة)
        if (time() - $_SESSION[self::$tokenName . '_time'] > 3600) {
            return self::generateToken();
        }
        
        return $_SESSION[self::$tokenName];
    }
    
    public static function verifyToken($token = null) {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        if ($token === null) {
            $token = $_POST[self::$tokenName] ?? $_GET[self::$tokenName] ?? null;
        }
        
        if ($token === null || !isset($_SESSION[self::$tokenName])) {
            return false;
        }
        
        return hash_equals($_SESSION[self::$tokenName], $token);
    }
    
    public static function getTokenField() {
        $token = self::getToken();
        return '<input type="hidden" name="' . self::$tokenName . '" value="' . htmlspecialchars($token) . '">';
    }
    
    public static function protectForm() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            
            // استثناءات
            if (strpos($uri, '/api/') !== false || 
                strpos($uri, '/webauthn/') !== false ||
                isset($_POST['login_method']) && $_POST['login_method'] === 'webauthn') {
                return true;
            }
            
            if (!self::verifyToken()) {
                http_response_code(403);
                die('خطأ في التحقق الأمني. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
            }
        }
        
        return true;
    }
}

function csrf_token_field() {
    return CSRFProtection::getTokenField();
}

function csrf_token() {
    return CSRFProtection::getToken();
}
```

---

## المرحلة 3: Rate Limiter (يستخدم MySQL بدلاً من الملفات)

### أنشئ جدول قاعدة البيانات أولاً:

```sql
CREATE TABLE IF NOT EXISTS `rate_limit_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(64) NOT NULL UNIQUE,
  `attempts` INT DEFAULT 0,
  `first_attempt` INT,
  `last_attempt` INT,
  `username` VARCHAR(100),
  `ip_address` VARCHAR(45),
  INDEX `idx_identifier` (`identifier`),
  INDEX `idx_last_attempt` (`last_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### أنشئ ملف: `includes/rate_limiter.php`

```php
<?php
/**
 * Rate Limiting (InfinityFree Compatible - Uses MySQL)
 * حماية من Brute Force - يستخدم MySQL
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class RateLimiter {
    private static $maxAttempts = 5;
    private static $timeWindow = 300;       // 5 دقائق
    private static $blockDuration = 900;    // 15 دقيقة
    
    private static function getIdentifier($username = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $username ? md5($ip . '_' . $username) : md5($ip);
    }
    
    private static function cleanup() {
        global $pdo;
        $cutoff = time() - self::$blockDuration;
        
        try {
            $stmt = $pdo->prepare("DELETE FROM rate_limit_attempts WHERE last_attempt < ?");
            $stmt->execute([$cutoff]);
        } catch (PDOException $e) {
            // تجاهل الأخطاء
        }
    }
    
    public static function checkLoginAttempt($username) {
        global $pdo;
        $identifier = self::getIdentifier($username);
        
        // تنظيف السجلات القديمة
        self::cleanup();
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM rate_limit_attempts WHERE identifier = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                $timeSinceLastAttempt = time() - $record['last_attempt'];
                
                if ($record['attempts'] >= self::$maxAttempts && 
                    $timeSinceLastAttempt < self::$blockDuration) {
                    
                    $remainingTime = self::$blockDuration - $timeSinceLastAttempt;
                    $minutes = ceil($remainingTime / 60);
                    
                    return [
                        'allowed' => false,
                        'message' => "تم حظر المحاولات. يرجى المحاولة بعد {$minutes} دقيقة",
                        'remaining_time' => $remainingTime
                    ];
                }
                
                // إعادة تعيين إذا مر وقت كافٍ
                if ($timeSinceLastAttempt > self::$timeWindow) {
                    $stmt = $pdo->prepare("DELETE FROM rate_limit_attempts WHERE identifier = ?");
                    $stmt->execute([$identifier]);
                }
            }
        } catch (PDOException $e) {
            // في حالة خطأ قاعدة البيانات، اسمح بالمحاولة
            return ['allowed' => true];
        }
        
        return ['allowed' => true];
    }
    
    public static function recordFailedAttempt($username) {
        global $pdo;
        $identifier = self::getIdentifier($username);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            // التحقق من وجود سجل
            $stmt = $pdo->prepare("SELECT * FROM rate_limit_attempts WHERE identifier = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                // تحديث السجل
                $stmt = $pdo->prepare("
                    UPDATE rate_limit_attempts 
                    SET attempts = attempts + 1, 
                        last_attempt = ? 
                    WHERE identifier = ?
                ");
                $stmt->execute([time(), $identifier]);
                
                $attempts = $record['attempts'] + 1;
            } else {
                // إنشاء سجل جديد
                $stmt = $pdo->prepare("
                    INSERT INTO rate_limit_attempts 
                    (identifier, attempts, first_attempt, last_attempt, username, ip_address) 
                    VALUES (?, 1, ?, ?, ?, ?)
                ");
                $stmt->execute([$identifier, time(), time(), $username, $ip]);
                
                $attempts = 1;
            }
            
            $remaining = self::$maxAttempts - $attempts;
            return max(0, $remaining);
            
        } catch (PDOException $e) {
            return self::$maxAttempts;
        }
    }
    
    public static function resetAttempts($username) {
        global $pdo;
        $identifier = self::getIdentifier($username);
        
        try {
            $stmt = $pdo->prepare("DELETE FROM rate_limit_attempts WHERE identifier = ?");
            $stmt->execute([$identifier]);
        } catch (PDOException $e) {
            // تجاهل الخطأ
        }
    }
}
```

---

## المرحلة 4: Security Headers (مبسطة)

### أنشئ ملف: `includes/security_headers.php`

```php
<?php
/**
 * Security Headers (InfinityFree Compatible)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class SecurityHeaders {
    public static function apply() {
        if (headers_sent()) {
            return;
        }
        
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // CSP مبسطة لتجنب مشاكل InfinityFree
        $csp = "default-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
               "img-src 'self' data: https:; " .
               "font-src 'self' data: https://cdn.jsdelivr.net;";
        
        header("Content-Security-Policy: {$csp}");
    }
}
```

---

## المرحلة 5: Input Validation (خفيفة)

### أنشئ ملف: `includes/input_validation.php`

```php
<?php
/**
 * Input Validation (Lightweight)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class InputValidator {
    public static function sanitizeString($input) {
        return strip_tags($input);
    }
    
    public static function validateUsername($username) {
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            return [
                'valid' => false, 
                'error' => 'اسم المستخدم يجب أن يحتوي على 3-30 حرف/رقم فقط'
            ];
        }
        return ['valid' => true, 'value' => $username];
    }
    
    public static function validatePassword($password) {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    public static function preventXSS($input) {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
```

---

## المرحلة 6: Security Logger (معطل افتراضياً)

### أنشئ ملف: `includes/security_logger.php`

```php
<?php
/**
 * Security Logger (Disabled by default on InfinityFree)
 * معطل افتراضياً لتوفير موارد InfinityFree
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class SecurityLogger {
    public static function log($type, $message, $data = []) {
        // معطل على InfinityFree لتوفير Inodes
        if (!defined('ENABLE_SECURITY_LOGGING') || ENABLE_SECURITY_LOGGING !== true) {
            return;
        }
        
        // الكود الأصلي هنا إذا أردت تفعيله لاحقاً
    }
}

function logSecurityEvent($type, $data = []) {
    // لا تفعل شيء على InfinityFree
}
```

---

## المرحلة 7: ملف الإعدادات

### أنشئ ملف: `includes/security_config.php`

```php
<?php
/**
 * Security Configuration (InfinityFree Optimized)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// إعدادات الجلسات
define('SESSION_TIMEOUT', 1800);              // 30 دقيقة
define('USE_IP_VALIDATION', false);           // معطل لتجنب مشاكل

// إعدادات Rate Limiting
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW', 300);
define('LOGIN_BLOCK_DURATION', 900);

// إعدادات كلمات المرور
define('MIN_PASSWORD_LENGTH', 8);
define('REQUIRE_PASSWORD_SPECIAL_CHAR', false);  // مبسط
define('REQUIRE_PASSWORD_NUMBER', false);         // مبسط

// إعدادات HTTPS
define('FORCE_HTTPS', false);                    // معطل على InfinityFree المجاني

// إعدادات التسجيل
define('ENABLE_SECURITY_LOGGING', false);        // معطل لتوفير Inodes

// وضع التطوير
define('SECURITY_DEBUG_MODE', false);
```

---

## المرحلة 8: تعديل index.php

```php
// في بداية index.php، بعد define('ACCESS_ALLOWED', true);

// تحميل الإعدادات الأمنية
require_once __DIR__ . '/includes/security_config.php';

// تطبيق Security Headers
require_once __DIR__ . '/includes/security_headers.php';
SecurityHeaders::apply();

// تهيئة الجلسات الآمنة
require_once __DIR__ . '/includes/session_security.php';
initSecureSession();

// تحميل CSRF Protection
require_once __DIR__ . '/includes/csrf_protection.php';

// تحميل Rate Limiter
require_once __DIR__ . '/includes/rate_limiter.php';

// تحميل Input Validation
require_once __DIR__ . '/includes/input_validation.php';

// تحميل Logger (معطل افتراضياً)
require_once __DIR__ . '/includes/security_logger.php';

// ... باقي الكود الأصلي

// في معالجة POST:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $login_method = $_POST['login_method'] ?? 'password';
    
    if ($login_method === 'webauthn') {
        // WebAuthn كما هو
    } else {
        // التحقق من CSRF
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/api/') === false) {
            CSRFProtection::protectForm();
        }
        
        if (empty($username) || empty($password)) {
            $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        } else {
            // فحص Rate Limiting
            $rateLimitCheck = RateLimiter::checkLoginAttempt($username);
            
            if (!$rateLimitCheck['allowed']) {
                $error = $rateLimitCheck['message'];
            } else {
                $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
                $result = login($username, $password, $rememberMe);
                
                if ($result['success']) {
                    RateLimiter::resetAttempts($username);
                    regenerateSessionAfterLogin();
                    
                    // ... باقي كود إعادة التوجيه
                } else {
                    $remaining = RateLimiter::recordFailedAttempt($username);
                    
                    if ($remaining > 0) {
                        $error = $result['message'] . " (المحاولات المتبقية: {$remaining})";
                    } else {
                        $error = "تم استنفاد المحاولات. تم حظر الحساب لمدة 15 دقيقة.";
                    }
                }
            }
        }
    }
}
```

---

## المرحلة 9: إضافة CSRF Token للنموذج

في نموذج تسجيل الدخول، قبل `</form>`:

```php
<!-- حماية CSRF -->
<?php echo csrf_token_field(); ?>
```

---

## قائمة الاختبار لـ InfinityFree

```markdown
### اختبارات ضرورية:
- [ ] تسجيل دخول عادي
- [ ] تسجيل خروج
- [ ] WebAuthn (إذا كان يعمل على InfinityFree)
- [ ] 5 محاولات تسجيل دخول خاطئة
- [ ] التأكد من الحظر
- [ ] التأكد من عدم رسالة "508 Resource Limit"
- [ ] التأكد من عمل PHP Sessions
- [ ] فحص استخدام Memory
- [ ] فحص عدد Inodes المستخدمة

### مراقبة:
- راقب Entry Process في cPanel
- راقب استخدام CPU
- راقب عدد Hits اليومية
```

---

## ملاحظات هامة لـ InfinityFree:

### ✅ ما تم تحسينه:
1. **Sessions**: استخدام مجلد tmp محلي
2. **Rate Limiter**: يستخدم MySQL بدلاً من ملفات JSON
3. **Logger**: معطل افتراضياً
4. **Headers**: CSP مبسطة
5. **عدد الملفات**: تقليل من 10+ إلى 6 ملفات فقط

### ⚠️ ما زال قد يسبب مشاكل:
1. Entry Process قد يصل للحد مع زيارات متزامنة
2. Browser Security System قد يتعارض مع API
3. PHP Memory قد لا تكفي للمواقع الكبيرة

### 💡 نصائح:
1. اختبر جيداً قبل النشر
2. راقب cPanel بانتظام
3. استعد للانتقال لاستضافة مدفوعة إذا نما الموقع
4. احتفظ بـ backup دائماً

---

## هل أنت مستعد؟

قل: "نعم، ابدأ بالمرحلة 1 (Session Security)"
```