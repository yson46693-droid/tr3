<?php
session_start();
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/install.php';

// تهيئة قاعدة البيانات تلقائياً في الخلفية
if (needsInstallation()) {
    // تنفيذ التهيئة في الخلفية
    $installResult = initializeDatabase();
    
    // إذا فشلت التهيئة، عرض رسالة خطأ
    if (!$installResult['success']) {
        die('<!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>خطأ في التهيئة</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                .error { color: #dc3545; background: #f8d7da; padding: 20px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="error">
                <h2>خطأ في تهيئة قاعدة البيانات</h2>
                <p>' . htmlspecialchars($installResult['message']) . '</p>
                <p>يرجى التحقق من إعدادات قاعدة البيانات في ملف includes/config.php</p>
            </div>
        </body>
        </html>');
    }
    
    if (!isset($_SESSION['db_initialized'])) {
        $_SESSION['db_initialized'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

// تعريف ASSETS_URL إذا لم يكن معرّفاً
// (يجب أن يكون معرّفاً بالفعل من config.php، لكن للتأكد)
if (!defined('ASSETS_URL')) {
    // استخدام نفس الطريقة من config.php
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $basePath = '';
    
    if (!empty($requestUri)) {
        $parsedUri = parse_url($requestUri);
        $path = $parsedUri['path'] ?? '';
        $pathParts = explode('/', trim($path, '/'));
        $baseParts = [];
        
        foreach ($pathParts as $part) {
            if ($part === 'dashboard' || $part === 'modules' || $part === 'api' || strpos($part, '.php') !== false) {
                break;
            }
            if (!empty($part)) {
                $baseParts[] = $part;
            }
        }
        
        if (!empty($baseParts)) {
            $basePath = '/' . implode('/', $baseParts);
        }
    }
    
    if (empty($basePath)) {
        define('ASSETS_URL', '/assets/');
    } else {
        define('ASSETS_URL', rtrim($basePath, '/') . '/assets/');
    }
}

// إذا كان المستخدم مسجلاً بالفعل، إعادة توجيه
if (isLoggedIn()) {
    $userRole = $_SESSION['role'] ?? 'accountant';
    $dashboardUrl = getDashboardUrl($userRole);
    
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
    
    // منع حلقة إعادة التوجيه - التحقق من أننا لسنا في dashboard بالفعل
    $currentScript = basename($_SERVER['PHP_SELF']);
    if ($currentScript !== 'index.php') {
        // إذا كنا في صفحة أخرى، لا نعيد التوجيه
        return;
    }
    
    // استخدام header redirect إذا كان متاحاً
    if (!headers_sent()) {
        header('Location: ' . $dashboardUrl);
        exit;
    } else {
        // استخدام JavaScript redirect كبديل
        echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
        exit;
    }
}

$error = '';
$success = '';

// معالجة تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $login_method = $_POST['login_method'] ?? 'password';
    
    if ($login_method === 'webauthn') {
        // سيتم التعامل مع WebAuthn عبر JavaScript
        // لا حاجة لمعالجة هنا
    } else {
        if (empty($username) || empty($password)) {
            $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        } else {
            $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
            $result = login($username, $password, $rememberMe);
            
            if ($result['success']) {
                // إعادة توجيه إلى لوحة التحكم المناسبة
                $userRole = $result['user']['role'] ?? 'accountant';
                $dashboardUrl = getDashboardUrl($userRole);
                
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
                
                if (!headers_sent()) {
                    header('Location: ' . $dashboardUrl);
                    exit;
                } else {
                    // استخدام window.location.pathname للتأكد من المسار المطلق
                    echo '<script>window.location.href = "' . htmlspecialchars($dashboardUrl) . '";</script>';
                    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dashboardUrl) . '"></noscript>';
                    exit;
                }
            } else {
                $error = $result['message'];
            }
        }
    }
}

require_once __DIR__ . '/includes/lang/ar.php';
$lang = $translations;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['login_title']; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo ASSETS_URL; ?>css/style.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>css/rtl.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="container-fluid py-4 py-md-5">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-11 col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
                <div class="card shadow-xxl border-0 login-card">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-shield-lock-fill text-primary" style="font-size: 3rem;"></i>
                            <h3 class="mt-3 mb-1"><?php echo $lang['login_title']; ?></h3>
                            <p class="text-muted"><?php echo $lang['login_subtitle']; ?></p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form id="loginForm" method="POST" action="">
                            <input type="hidden" name="login_method" id="loginMethod" value="password">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person-fill me-2"></i>
                                    <?php echo $lang['username']; ?>
                                </label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="<?php echo $lang['username_placeholder']; ?>" required autofocus>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <i class="bi bi-key-fill me-2"></i>
                                    <?php echo $lang['password']; ?>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="<?php echo $lang['password_placeholder']; ?>" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="bi bi-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1">
                                <label class="form-check-label" for="remember_me">
                                    <i class="bi bi-bookmark-check me-2"></i>
                                    <?php echo $lang['remember_me']; ?>
                                </label>
                            </div>
                            
                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    <?php echo $lang['login_button']; ?>
                                </button>
                            </div>
                            
                            <?php if (true): // WebAuthn support check ?>
                                <div class="text-center mb-3">
                                    <hr class="my-3">
                                    <p class="text-muted small">أو</p>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="button" class="btn btn-outline-primary" id="webauthnLoginBtn">
                                        <i class="bi bi-fingerprint me-2"></i>
                                        تسجيل الدخول بالبصمة / المفتاح الأمني
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                        
                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                شركة البركة © <?php echo date('Y'); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- WebAuthn JS -->
    <script src="<?php echo ASSETS_URL; ?>js/webauthn.js"></script>
    <!-- Custom JS -->
    <script src="<?php echo ASSETS_URL; ?>js/main.js"></script>
    
    <script>
        // إظهار/إخفاء كلمة المرور
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('bi-eye');
                eyeIcon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('bi-eye-slash');
                eyeIcon.classList.add('bi-eye');
            }
        });
        
        // تسجيل الدخول عبر WebAuthn
        document.getElementById('webauthnLoginBtn')?.addEventListener('click', async function() {
            const username = document.getElementById('username').value;
            
            if (!username) {
                alert('يرجى إدخال اسم المستخدم أولاً');
                document.getElementById('username').focus();
                return;
            }
            
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التحقق...';
            
            try {
                const result = await webauthnManager.login(username);
                if (result && result.success) {
                    // سيتم إعادة التوجيه تلقائياً من داخل login()
                    console.log('Login successful, redirecting...');
                }
            } catch (error) {
                console.error('Login error:', error);
                alert(error.message || 'حدث خطأ أثناء تسجيل الدخول');
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-fingerprint me-2"></i>تسجيل الدخول بالبصمة / المفتاح الأمني';
            }
        });
    </script>
</body>
</html>

