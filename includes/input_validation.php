<?php
/**
 * Input Validation (Lightweight)
 * التحقق من المدخلات - خفيف ومتوافق مع InfinityFree
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class InputValidator {
    /**
     * تنظيف النص من HTML tags
     */
    public static function sanitizeString($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeString'], $input);
        }
        return strip_tags($input);
    }
    
    /**
     * التحقق من اسم المستخدم
     */
    public static function validateUsername($username) {
        if (empty($username)) {
            return [
                'valid' => false, 
                'error' => 'اسم المستخدم مطلوب'
            ];
        }
        
        // اسم المستخدم: 3-30 حرف/رقم/underscore فقط
        if (!preg_match('/^[a-zA-Z0-9_\u0600-\u06FF]{3,30}$/u', $username)) {
            return [
                'valid' => false, 
                'error' => 'اسم المستخدم يجب أن يحتوي على 3-30 حرف/رقم فقط (يدعم العربية)'
            ];
        }
        
        return ['valid' => true, 'value' => $username];
    }
    
    /**
     * التحقق من كلمة المرور
     */
    public static function validatePassword($password) {
        $errors = [];
        
        if (empty($password)) {
            $errors[] = 'كلمة المرور مطلوبة';
        } else {
            $minLength = defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8;
            
            if (strlen($password) < $minLength) {
                $errors[] = "كلمة المرور يجب أن تكون {$minLength} أحرف على الأقل";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * منع XSS - تنظيف المدخلات
     */
    public static function preventXSS($input) {
        if (is_array($input)) {
            return array_map([self::class, 'preventXSS'], $input);
        }
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * التحقق من البريد الإلكتروني
     */
    public static function validateEmail($email) {
        if (empty($email)) {
            return [
                'valid' => false,
                'error' => 'البريد الإلكتروني مطلوب'
            ];
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'error' => 'البريد الإلكتروني غير صحيح'
            ];
        }
        
        return ['valid' => true, 'value' => $email];
    }
    
    /**
     * التحقق من رقم (integer)
     */
    public static function validateInteger($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return [
                'valid' => false,
                'error' => 'القيمة يجب أن تكون رقماً'
            ];
        }
        
        $intValue = (int)$value;
        
        if ($min !== null && $intValue < $min) {
            return [
                'valid' => false,
                'error' => "القيمة يجب أن تكون أكبر من أو تساوي {$min}"
            ];
        }
        
        if ($max !== null && $intValue > $max) {
            return [
                'valid' => false,
                'error' => "القيمة يجب أن تكون أصغر من أو تساوي {$max}"
            ];
        }
        
        return ['valid' => true, 'value' => $intValue];
    }
    
    /**
     * التحقق من رقم عشري (float)
     */
    public static function validateFloat($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return [
                'valid' => false,
                'error' => 'القيمة يجب أن تكون رقماً'
            ];
        }
        
        $floatValue = (float)$value;
        
        if ($min !== null && $floatValue < $min) {
            return [
                'valid' => false,
                'error' => "القيمة يجب أن تكون أكبر من أو تساوي {$min}"
            ];
        }
        
        if ($max !== null && $floatValue > $max) {
            return [
                'valid' => false,
                'error' => "القيمة يجب أن تكون أصغر من أو تساوي {$max}"
            ];
        }
        
        return ['valid' => true, 'value' => $floatValue];
    }
    
    /**
     * تنظيف SQL Injection - استخدام Prepared Statements دائماً
     * هذه الدالة للتنظيف الإضافي فقط
     */
    public static function sanitizeForSQL($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeForSQL'], $input);
        }
        // إزالة الأحرف الخطيرة
        return str_replace([';', '--', '/*', '*/', 'xp_', 'sp_'], '', $input);
    }
}
