<?php
/**
 * صفحة خزنة الشركة - تحويل إلى صفحة المعاملات المالية للمحاسب
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

// الحصول على رابط صفحة المحاسب - المعاملات المالية
$accountantUrl = getDashboardUrl('accountant') . '?page=financial';

// إعادة التوجيه
if (!headers_sent()) {
    header('Location: ' . $accountantUrl);
    exit;
} else {
    echo '<script>window.location.href = ' . json_encode($accountantUrl) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($accountantUrl) . '"></noscript>';
    exit;
}

