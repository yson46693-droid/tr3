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

<!-- Hero Section -->
<div class="manager-pos-hero mb-4">
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="card-body p-4 p-lg-5" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="row align-items-center">
                <div class="col-lg-8 mb-3 mb-lg-0">
                    <h2 class="text-white mb-2 fw-bold">
                        <i class="bi bi-shop-window me-2"></i>نقطة البيع للمدير
                    </h2>
                    <p class="text-white-50 mb-0 fs-5">
                        إدارة المبيعات المحلية وطلبات شركات الشحن من مكان واحد
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="d-flex flex-column flex-lg-row gap-3 justify-content-lg-end">
                        <a href="<?php echo htmlspecialchars($localPosUrl); ?>" 
                           class="btn btn-light btn-lg shadow-sm pos-hero-btn <?php echo $section === 'local' ? 'active' : ''; ?>"
                           style="min-width: 200px; position: relative; overflow: hidden;">
                            <span class="position-relative z-index-1 d-flex align-items-center justify-content-center">
                                <i class="bi bi-cash-register me-2 fs-5"></i>
                                <span class="fw-semibold">نقطة البيع المحلية</span>
                            </span>
                        </a>
                        <a href="<?php echo htmlspecialchars($shippingPosUrl); ?>" 
                           class="btn btn-light btn-lg shadow-sm pos-hero-btn <?php echo $section === 'shipping' ? 'active' : ''; ?>"
                           style="min-width: 200px; position: relative; overflow: hidden;">
                            <span class="position-relative z-index-1 d-flex align-items-center justify-content-center">
                                <i class="bi bi-truck me-2 fs-5"></i>
                                <span class="fw-semibold">طلبات شركات الشحن</span>
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Navigation Pills -->
<div class="mb-4">
    <ul class="nav nav-pills nav-pills-modern gap-2">
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
</div>

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

<style>
/* Manager POS Hero Section Styles */
.manager-pos-hero .card {
    border-radius: 20px;
    overflow: hidden;
}

.manager-pos-hero .pos-hero-btn {
    background: rgba(255, 255, 255, 0.95);
    border: none;
    transition: all 0.3s ease;
    font-weight: 600;
    position: relative;
    overflow: hidden;
}

.manager-pos-hero .pos-hero-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.5s ease;
}

.manager-pos-hero .pos-hero-btn:hover::before {
    left: 100%;
}

.manager-pos-hero .pos-hero-btn:hover {
    background: #ffffff;
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

.manager-pos-hero .pos-hero-btn.active {
    background: #ffffff;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
    position: relative;
}

.manager-pos-hero .pos-hero-btn.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 70%;
    height: 4px;
    background: linear-gradient(90deg, transparent, #ffffff, transparent);
    border-radius: 2px;
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.6;
    }
}

.manager-pos-hero .z-index-1 {
    z-index: 1;
}

/* Modern Nav Pills */
.nav-pills-modern .nav-link {
    border-radius: 12px;
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.nav-pills-modern .nav-link:not(.active) {
    background: #f8fafc;
    color: #64748b;
}

.nav-pills-modern .nav-link:not(.active):hover {
    background: #e2e8f0;
    color: #475569;
}

.nav-pills-modern .nav-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #ffffff;
    border-color: transparent;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

/* Local POS Header Styles */
.local-pos-header .card {
    border-radius: 16px;
    border: 1px solid rgba(15, 23, 42, 0.08);
}

.local-pos-header .card-body {
    background: linear-gradient(to bottom, #ffffff, #f8fafc);
}

.local-pos-header h2 {
    font-size: 1.75rem;
}

.local-pos-header .card.bg-light {
    border-radius: 12px;
    border: 1px solid rgba(99, 102, 241, 0.15);
    background: linear-gradient(to right, #f0f9ff, #ffffff);
}

.local-pos-header .btn-primary {
    border-radius: 12px;
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.local-pos-header .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(13, 110, 253, 0.3);
}

/* Responsive Design */
@media (max-width: 768px) {
    .manager-pos-hero .btn-lg {
        width: 100%;
        min-width: unset;
    }
    
    .local-pos-header .d-flex {
        flex-direction: column;
    }
    
    .local-pos-header .card.bg-light {
        min-width: 100%;
    }
}
</style>
