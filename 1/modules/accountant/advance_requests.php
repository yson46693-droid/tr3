<?php

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireAnyRole(['accountant', 'manager']);

$currentUser = getCurrentUser();

// التحويل إلى الصفحة الأساسية للموافقة على طلبات السلفة
$redirectUrl = getDashboardUrl($currentUser['role']) . '?page=salaries&view=advances&month=' . date('n') . '&year=' . date('Y');

// استخدام JavaScript redirect لأن headers قد تم إرسالها بالفعل
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحويل...</title>
</head>
<body>
    <script>
        window.location.href = <?php echo json_encode($redirectUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <noscript>
        <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>">
    </noscript>
    <p>جاري التحويل... <a href="<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>">انقر هنا إذا لم يتم التحويل تلقائياً</a></p>
</body>
</html>
<?php
exit;