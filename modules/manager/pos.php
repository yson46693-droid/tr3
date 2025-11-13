<?php
/**
 * صفحة نقطة البيع للمدير - تتضمن نقطة البيع المحلية وطلبات شركات الشحن
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();

$basePosUrl = getRelativeUrl('manager.php?page=pos');
$section = $_GET['section'] ?? 'local';
$allowedSections = ['local', 'shipping'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'local';
}

$localPosUrl = $basePosUrl . '&section=local';
$shippingPosUrl = $basePosUrl . '&section=shipping';
?>

<div class="page-header mb-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h2 class="mb-1"><i class="bi bi-shop-window me-2"></i>نقطة البيع للمدير</h2>
        <p class="text-muted mb-0">إدارة المبيعات المحلية وطلبات شركات الشحن من مكان واحد.</p>
    </div>
</div>

<ul class="nav nav-pills gap-2 mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $section === 'local' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($localPosUrl); ?>">
            <i class="bi bi-cash-register me-1"></i>نقطة البيع المحلية
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $section === 'shipping' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($shippingPosUrl); ?>">
            <i class="bi bi-truck me-1"></i>طلبات شركات الشحن
        </a>
    </li>
</ul>

<div class="manager-pos-section">
    <?php
    if ($section === 'shipping') {
        $shippingModule = __DIR__ . '/shipping_orders.php';
        if (file_exists($shippingModule)) {
            include $shippingModule;
        } else {
            echo '<div class="alert alert-warning">صفحة طلبات شركات الشحن غير متاحة حالياً</div>';
        }
    } else {
        $localModule = __DIR__ . '/../accountant/pos.php';
        if (file_exists($localModule)) {
            include $localModule;
        } else {
            echo '<div class="alert alert-warning">صفحة نقطة البيع المحلية غير متاحة حالياً</div>';
        }
    }
    ?>
</div>
