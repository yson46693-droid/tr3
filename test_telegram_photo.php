<?php
/**
 * ملف اختبار بسيط لإرسال صورة إلى تليجرام
 * 
 * كيفية الاستخدام:
 * 1. افتح هذا الملف في المتصفح
 * 2. سيتم إرسال صورة اختبارية إلى تليجرام
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/simple_telegram.php';

header('Content-Type: text/html; charset=utf-8');

if (!isLoggedIn()) {
    die('يجب تسجيل الدخول أولاً');
}

echo '<h1>اختبار إرسال صورة إلى تليجرام</h1>';

// التحقق من إعدادات تليجرام
if (!isTelegramConfigured()) {
    die('<p style="color: red;">❌ تليجرام غير مهيأ. تحقق من إعدادات TELEGRAM_BOT_TOKEN و TELEGRAM_CHAT_ID</p>');
}

echo '<p style="color: green;">✅ تليجرام مهيأ بشكل صحيح</p>';

// إنشاء صورة اختبارية
echo '<p>🔄 إنشاء صورة اختبارية...</p>';

$canvas = imagecreatetruecolor(400, 300);
$bgColor = imagecolorallocate($canvas, 76, 175, 80);
$textColor = imagecolorallocate($canvas, 255, 255, 255);
imagefill($canvas, 0, 0, $bgColor);
imagestring($canvas, 5, 100, 120, 'Test Image', $textColor);
imagestring($canvas, 3, 80, 160, date('Y-m-d H:i:s'), $textColor);

$tempFile = sys_get_temp_dir() . '/telegram_test_' . uniqid() . '.jpg';
imagejpeg($canvas, $tempFile, 90);
imagedestroy($canvas);

echo '<p>✅ تم إنشاء الصورة: ' . $tempFile . '</p>';
echo '<p>📏 حجم الصورة: ' . filesize($tempFile) . ' bytes</p>';

// إرسال الصورة
echo '<p>🔄 محاولة إرسال الصورة إلى تليجرام...</p>';

$caption = "🧪 <b>اختبار إرسال صورة</b>\n\n";
$caption .= "👤 <b>المستخدم:</b> " . (getCurrentUser()['full_name'] ?? getCurrentUser()['username']) . "\n";
$caption .= "🕐 <b>الوقت:</b> " . date('Y-m-d H:i:s') . "\n";
$caption .= "✅ هذه صورة اختبارية";

$result = sendTelegramPhoto($tempFile, $caption, null, false);

if ($result) {
    echo '<p style="color: green; font-weight: bold;">✅ تم إرسال الصورة بنجاح إلى تليجرام!</p>';
    echo '<pre>' . print_r($result, true) . '</pre>';
} else {
    echo '<p style="color: red; font-weight: bold;">❌ فشل إرسال الصورة إلى تليجرام</p>';
    echo '<p>راجع ملف error_log للتفاصيل</p>';
}

// حذف الملف المؤقت
if (file_exists($tempFile)) {
    @unlink($tempFile);
    echo '<p>🗑️ تم حذف الملف المؤقت</p>';
}

echo '<hr>';
echo '<h2>اختبار إرسال صورة base64</h2>';

// تحويل الصورة إلى base64
$imageData = file_get_contents($tempFile);
$base64Image = 'data:image/jpeg;base64,' . base64_encode($imageData);

echo '<p>🔄 محاولة إرسال صورة base64 إلى تليجرام...</p>';

$result2 = sendTelegramPhoto($base64Image, $caption . "\n\n(من base64)", null, true);

if ($result2) {
    echo '<p style="color: green; font-weight: bold;">✅ تم إرسال الصورة base64 بنجاح إلى تليجرام!</p>';
    echo '<pre>' . print_r($result2, true) . '</pre>';
} else {
    echo '<p style="color: red; font-weight: bold;">❌ فشل إرسال الصورة base64 إلى تليجرام</p>';
    echo '<p>راجع ملف error_log للتفاصيل</p>';
}

echo '<hr>';
echo '<p><a href="debug_telegram_attendance.php">← العودة إلى صفحة التشخيص</a></p>';

