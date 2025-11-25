<?php
error_reporting(0);
ini_set('display_errors', 0);

// بدء output buffering
if (!ob_get_level()) {
    ob_start();
}

define('ACCESS_ALLOWED', true);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

try {
    require_once __DIR__ . '/includes/config.php';
    
    if (file_exists(__DIR__ . '/includes/path_helper.php')) {
        require_once __DIR__ . '/includes/path_helper.php';
    }
    
    require_once __DIR__ . '/includes/auth.php';
    
    if (function_exists('logout')) {
        try {
            logout();
        } catch (Exception $e) {
            error_log("Logout Function Error: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log("Logout Page Error: " . $e->getMessage());
}

if (isset($_COOKIE['remember_token'])) {
    @setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $cookieParams = session_get_cookie_params();
    @setcookie(session_name(), '', time() - 3600, $cookieParams['path'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
    
    $_SESSION = [];
    @session_unset();
    @session_destroy();
}

while (ob_get_level()) {
    @ob_end_clean();
}

$redirectUrl = '/index.php';

try {
    if (function_exists('getRelativeUrl')) {
        $tempUrl = getRelativeUrl('index.php');
        if (!empty($tempUrl)) {
            $redirectUrl = $tempUrl;
        }
    } else {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);
        
        // تنظيف المسار
        $scriptDir = str_replace('\\', '/', $scriptDir);
        $scriptDir = rtrim($scriptDir, '/');
        
        if ($scriptDir && $scriptDir !== '/' && $scriptDir !== '.') {
            $redirectUrl = $scriptDir . '/index.php';
        } else {
            $redirectUrl = '/index.php';
        }
    }
    
    $redirectUrl = str_replace('//', '/', $redirectUrl);
    if (empty($redirectUrl) || $redirectUrl === '/') {
        $redirectUrl = '/index.php';
    }
    
    if (strpos($redirectUrl, '/') !== 0) {
        $redirectUrl = '/' . $redirectUrl;
    }
    
} catch (Exception $e) {
    error_log("Logout Redirect URL Error: " . $e->getMessage());
    $redirectUrl = '/index.php';
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الخروج</title>
    <script>
        (function() {
            var redirectUrl = <?php echo json_encode($redirectUrl, JSON_UNESCAPED_UNICODE); ?>;
            
            try {
                if (typeof window !== 'undefined' && window.location && window.location.replace) {
                    window.location.replace(redirectUrl);
                    return;
                }
            } catch(e) {}
            
            try {
                if (typeof window !== 'undefined' && window.location && window.location.href) {
                    window.location.href = redirectUrl;
                    return;
                }
            } catch(e) {}
            
            if (typeof document !== 'undefined') {
                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                    setTimeout(function() {
                        if (window.location) {
                            window.location = redirectUrl;
                        }
                    }, 100);
                } else {
                    document.addEventListener('DOMContentLoaded', function() {
                        setTimeout(function() {
                            if (window.location) {
                                window.location = redirectUrl;
                            }
                        }, 100);
                    });
                }
            }
        })();
    </script>
    <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body style="font-family: Arial, sans-serif; text-align: center; padding: 50px; direction: rtl; background: #f5f5f5; margin: 0;">
    <div style="max-width: 400px; margin: 100px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="color: #333; margin-bottom: 20px;">تسجيل الخروج</h2>
        <p style="color: #666; margin-bottom: 20px;">جاري تسجيل الخروج...</p>
        <div style="margin: 20px 0;">
            <div class="spinner-border text-primary" role="status" style="display: inline-block; width: 2rem; height: 2rem; border: 0.25em solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spinner-border 0.75s linear infinite;"></div>
        </div>
        <p style="margin-top: 20px;">
            <a href="<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>" 
               style="color: #007bff; text-decoration: none; font-weight: bold; display: inline-block; margin-top: 10px;">
                اضغط هنا إذا لم يتم إعادة التوجيه تلقائياً
            </a>
        </p>
    </div>
    <style>
        @keyframes spinner-border {
            to { transform: rotate(360deg); }
        }
    </style>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                var redirectUrl = <?php echo json_encode($redirectUrl, JSON_UNESCAPED_UNICODE); ?>;
                if (window.location && window.location.pathname !== redirectUrl) {
                    window.location.href = redirectUrl;
                }
            }, 500);
        });
    </script>
</body>
</html>
<?php
exit;

