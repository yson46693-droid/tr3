<?php
/**
 * صفحة تشخيصية لفحص مشكلة إرسال صور الحضور والانصراف إلى تليجرام
 * 
 * كيفية الاستخدام:
 * 1. افتح هذا الملف في المتصفح: debug_telegram_attendance.php
 * 2. اتبع التعليمات المعروضة
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/simple_telegram.php';

header('Content-Type: text/html; charset=utf-8');

if (!isLoggedIn()) {
    die('يجب تسجيل الدخول أولاً');
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تشخيص إرسال صور الحضور إلى تليجرام</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
            border-right: 4px solid #2196F3;
        }
        .test-section h2 {
            color: #2196F3;
            margin-top: 0;
        }
        .result {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        button {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 5px;
        }
        button:hover {
            background: #45a049;
        }
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            direction: ltr;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 تشخيص إرسال صور الحضور والانصراف إلى تليجرام</h1>
        
        <div class="test-section">
            <h2>1️⃣ التحقق من إعدادات تليجرام</h2>
            <?php
            $telegramConfigured = isTelegramConfigured();
            if ($telegramConfigured) {
                echo '<div class="result success">✅ تليجرام مهيأ بشكل صحيح</div>';
                echo '<div class="result info">';
                echo 'Bot Token: ' . (defined('TELEGRAM_BOT_TOKEN') ? substr(TELEGRAM_BOT_TOKEN, 0, 10) . '...' : 'غير محدد') . "\n";
                echo 'Chat ID: ' . (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : 'غير محدد');
                echo '</div>';
            } else {
                echo '<div class="result error">❌ تليجرام غير مهيأ</div>';
                echo '<div class="result warning">تحقق من إعدادات TELEGRAM_BOT_TOKEN و TELEGRAM_CHAT_ID في includes/config.php</div>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>2️⃣ اختبار إرسال رسالة نصية إلى تليجرام</h2>
            <?php
            if (isset($_GET['test_message'])) {
                $testMessage = "🧪 <b>اختبار إرسال رسالة</b>\n\n";
                $testMessage .= "👤 <b>المستخدم:</b> " . ($currentUser['full_name'] ?? $currentUser['username']) . "\n";
                $testMessage .= "🕐 <b>الوقت:</b> " . date('Y-m-d H:i:s') . "\n";
                $testMessage .= "✅ هذه رسالة اختبارية";
                
                $result = sendTelegramMessage($testMessage);
                if ($result) {
                    echo '<div class="result success">✅ تم إرسال الرسالة النصية بنجاح إلى تليجرام</div>';
                } else {
                    echo '<div class="result error">❌ فشل إرسال الرسالة النصية إلى تليجرام</div>';
                }
            }
            ?>
            <button onclick="window.location.href='?test_message=1'">إرسال رسالة اختبارية</button>
        </div>
        
        <div class="test-section">
            <h2>3️⃣ اختبار إرسال صورة إلى تليجرام</h2>
            <?php
            if (isset($_GET['test_photo'])) {
                // إنشاء صورة اختبارية
                $canvas = imagecreatetruecolor(400, 300);
                $bgColor = imagecolorallocate($canvas, 76, 175, 80);
                $textColor = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $bgColor);
                imagestring($canvas, 5, 100, 130, 'Test Image', $textColor);
                imagestring($canvas, 3, 80, 160, date('Y-m-d H:i:s'), $textColor);
                
                $tempFile = sys_get_temp_dir() . '/telegram_test_' . uniqid() . '.jpg';
                imagejpeg($canvas, $tempFile, 90);
                imagedestroy($canvas);
                
                $caption = "🧪 <b>اختبار إرسال صورة</b>\n\n";
                $caption .= "👤 <b>المستخدم:</b> " . ($currentUser['full_name'] ?? $currentUser['username']) . "\n";
                $caption .= "🕐 <b>الوقت:</b> " . date('Y-m-d H:i:s');
                
                $result = sendTelegramPhoto($tempFile, $caption, null, false);
                
                if ($result) {
                    echo '<div class="result success">✅ تم إرسال الصورة بنجاح إلى تليجرام</div>';
                } else {
                    echo '<div class="result error">❌ فشل إرسال الصورة إلى تليجرام</div>';
                }
                
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }
            ?>
            <button onclick="window.location.href='?test_photo=1'">إرسال صورة اختبارية</button>
        </div>
        
        <div class="test-section">
            <h2>4️⃣ فحص آخر سجلات الحضور</h2>
            <?php
            $db = db();
            $lastRecord = $db->queryOne(
                "SELECT * FROM attendance_records 
                 WHERE user_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 1",
                [$userId]
            );
            
            if ($lastRecord) {
                echo '<div class="result info">';
                echo "📅 آخر تسجيل حضور:\n";
                echo "   - التاريخ: " . $lastRecord['date'] . "\n";
                echo "   - وقت الحضور: " . $lastRecord['check_in_time'] . "\n";
                echo "   - وقت الانصراف: " . ($lastRecord['check_out_time'] ?? 'لم يتم') . "\n";
                echo "   - مسار الصورة: " . ($lastRecord['photo_path'] ?? 'غير موجود') . "\n";
                echo "   - مسار صورة الانصراف: " . ($lastRecord['checkout_photo_path'] ?? 'غير موجود');
                echo '</div>';
            } else {
                echo '<div class="result warning">⚠️ لا توجد سجلات حضور</div>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>5️⃣ فحص سجلات الأخطاء</h2>
            <?php
            $errorLogPath = ini_get('error_log');
            if ($errorLogPath && file_exists($errorLogPath)) {
                $logContent = file_get_contents($errorLogPath);
                $attendanceLogs = [];
                $lines = explode("\n", $logContent);
                
                // البحث عن سجلات الحضور في آخر 100 سطر
                $recentLines = array_slice($lines, -100);
                foreach ($recentLines as $line) {
                    if (stripos($line, 'attendance') !== false || 
                        stripos($line, 'telegram') !== false ||
                        stripos($line, 'check-in') !== false ||
                        stripos($line, 'check-out') !== false) {
                        $attendanceLogs[] = $line;
                    }
                }
                
                if (!empty($attendanceLogs)) {
                    echo '<div class="result info">';
                    echo "📋 آخر " . count($attendanceLogs) . " سجل متعلق بالحضور:\n\n";
                    echo htmlspecialchars(implode("\n", array_slice($attendanceLogs, -10)));
                    echo '</div>';
                } else {
                    echo '<div class="result warning">⚠️ لا توجد سجلات متعلقة بالحضور في ملف error_log</div>';
                }
                
                echo '<div class="result info">';
                echo "📁 مسار ملف error_log: " . $errorLogPath;
                echo '</div>';
            } else {
                echo '<div class="result warning">⚠️ ملف error_log غير موجود أو غير قابل للقراءة</div>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>6️⃣ كود JavaScript للتشخيص في Console</h2>
            <p>انسخ هذا الكود والصقه في Console المتصفح (F12):</p>
            <div class="code-block" id="jsCode">
// كود التشخيص
(async function() {
    // الحصول على API path
    const currentPath = window.location.pathname;
    const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php') && p !== 'dashboard' && p !== 'modules');
    let basePath = '/';
    if (pathParts.length > 0) {
        basePath = '/' + pathParts[0] + '/';
    }
    const apiPath = basePath + 'api/attendance.php';
    
    // إنشاء صورة اختبارية
    const canvas = document.createElement('canvas');
    canvas.width = 400;
    canvas.height = 300;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#4CAF50';
    ctx.fillRect(0, 0, 400, 300);
    ctx.fillStyle = '#FFFFFF';
    ctx.font = '30px Arial';
    ctx.fillText('Test Image', 100, 150);
    ctx.fillText(new Date().toLocaleString('ar'), 50, 200);
    
    const testPhoto = canvas.toDataURL('image/jpeg', 0.8);
    
    console.log('📸 حجم الصورة:', testPhoto.length);
    console.log('🔄 إرسال الصورة...');
    
    try {
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'include',
            body: JSON.stringify({
                action: 'check_in',
                photo: testPhoto
            })
        });
        
        const data = await response.json();
        console.log('📦 النتيجة:', data);
        
        if (data.success) {
            console.log('✅ نجح الإرسال!');
        } else {
            console.error('❌ فشل الإرسال:', data.message);
        }
    } catch (error) {
        console.error('❌ خطأ:', error);
    }
})();
            </div>
            <button onclick="copyToClipboard('jsCode')">نسخ الكود</button>
        </div>
        
        <div class="test-section">
            <h2>7️⃣ معلومات إضافية</h2>
            <div class="result info">
                <?php
                echo "👤 المستخدم الحالي: " . ($currentUser['full_name'] ?? $currentUser['username']) . "\n";
                echo "🆔 ID المستخدم: " . $userId . "\n";
                echo "🕐 الوقت الحالي: " . date('Y-m-d H:i:s') . "\n";
                echo "🌐 PHP Version: " . PHP_VERSION . "\n";
                echo "📁 مسار الملف: " . __FILE__;
                ?>
            </div>
        </div>
    </div>
    
    <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            navigator.clipboard.writeText(text).then(() => {
                alert('تم نسخ الكود بنجاح!');
            }).catch(err => {
                console.error('فشل النسخ:', err);
            });
        }
        
        function getAttendanceApiPath() {
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php') && p !== 'dashboard' && p !== 'modules');
            let basePath = '/';
            if (pathParts.length > 0) {
                basePath = '/' + pathParts[0] + '/';
            }
            return basePath + 'api/attendance.php';
        }
    </script>
</body>
</html>

