<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('manager');

// تعيين متغير لإخفاء قسم القوالب في صفحة التقارير للمدير
define('PRODUCTION_REPORTS_MODE', true);

$productionPagePath = __DIR__ . '/../production/production.php';

if (!file_exists($productionPagePath)) {
    ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-exclamation-triangle text-warning display-5 mb-3"></i>
            <h4 class="mb-2">تعذر تحميل صفحة الإنتاج</h4>
            <p class="text-muted mb-0">ملف صفحة الإنتاج غير موجود في الوقت الحالي. يرجى التواصل مع فريق التطوير.</p>
        </div>
    </div>
    <?php
    return;
}

$previousPageParam = $_GET['page'] ?? null;
$_GET['page'] = 'production';
$_GET['section'] = 'reports'; // تحديد قسم التقارير

include $productionPagePath;

if ($previousPageParam === null) {
    unset($_GET['page']);
} else {
    $_GET['page'] = $previousPageParam;
}
 