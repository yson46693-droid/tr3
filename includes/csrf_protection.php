<?php
/**
 * CSRF Protection (InfinityFree Compatible)
 * حماية CSRF - مدمجة مع النظام الحالي
 * 
 * هذا الملف يحسّن حماية CSRF ويتكامل مع generateCSRFToken() الموجود في auth.php
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * تحسين دالة التحقق من CSRF - متوافقة مع النظام الحالي
 * تستخدم نفس النظام الموجود في auth.php
 */
function verifyCSRFTokenEnhanced($token = null) {
    if (!isset($_SESSION)) {
        return false;
    }
    
    // إذا لم يتم تمرير token، احصل عليه من POST أو GET
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
    }
    
    // استخدام الدالة الموجودة في auth.php إذا كانت متاحة
    if (function_exists('verifyCSRFToken')) {
        return verifyCSRFToken($token);
    }
    
    // Fallback: التحقق المباشر
    if ($token === null || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * الحصول على CSRF Token - متوافق مع النظام الحالي
 */
function getCSRFToken() {
    if (!isset($_SESSION)) {
        return '';
    }
    
    // استخدام الدالة الموجودة في auth.php إذا كانت متاحة
    if (function_exists('generateCSRFToken')) {
        return generateCSRFToken();
    }
    
    // Fallback: إنشاء token جديد
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * إنشاء حقل CSRF Token للنماذج
 */
function csrf_token_field() {
    $token = getCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * حماية النموذج من CSRF - متوافقة مع النظام الحالي
 */
function protectFormFromCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // استثناءات (APIs و WebAuthn)
        if (strpos($uri, '/api/') !== false || 
            strpos($uri, '/webauthn/') !== false ||
            (isset($_POST['login_method']) && $_POST['login_method'] === 'webauthn')) {
            return true;
        }
        
        // التحقق من CSRF Token
        if (!verifyCSRFTokenEnhanced()) {
            http_response_code(403);
            die('خطأ في التحقق الأمني. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
    }
    
    return true;
}

/**
 * دالة مساعدة للحصول على CSRF Token (للاستخدام في JavaScript)
 */
function csrf_token() {
    return getCSRFToken();
}
