<?php
/**
 * القائمة الجانبية لمندوب المبيعات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$baseUrl = getDashboardUrl();
?>
<div class="sidebar">
    <div class="sidebar-header">
        <h5>Menu</h5>
        <i class="bi bi-chevron-down"></i>
    </div>
    
    <nav class="sidebar-nav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentPage === 'sales.php' && (!isset($_GET['page']) || $_GET['page'] === '') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>sales.php">
                    <i class="bi bi-speedometer2"></i>
                    <span><?php echo $lang['dashboard']; ?></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'chat') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>sales.php?page=chat">
                    <i class="bi bi-chat-dots"></i>
                    <span>الدردشة الجماعية</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=customers">
                    <i class="bi bi-people"></i>
                    <span><?php echo $lang['customers']; ?></span>
                </a>
            </li>

            <div class="sidebar-section">
                <div class="sidebar-section-title">Sales</div>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=sales_collections">
                        <i class="bi bi-receipt"></i>
                        <span><?php echo isset($lang['sales_and_collections']) ? $lang['sales_and_collections'] : 'مبيعات و تحصيلات'; ?></span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=orders">
                        <i class="bi bi-cart-check"></i>
                        <span>طلبات العملاء</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=payment_schedules">
                        <i class="bi bi-calendar-check"></i>
                        <span>جداول التحصيل</span>
                    </a>
                </li>
            </div>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=vehicle_inventory">
                    <i class="bi bi-truck"></i>
                    <span>مخزن السيارة</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'pos') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>sales.php?page=pos">
                    <i class="bi bi-shop"></i>
                    <span><?php echo isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع'; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=returns">
                    <i class="bi bi-arrow-return-left"></i>
                    <span>المرتجعات</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=exchanges">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>الاستبدال</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>sales.php?page=reports">
                    <i class="bi bi-file-earmark-text"></i>
                    <span><?php echo $lang['menu_reports']; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'attendance') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>sales.php?page=attendance">
                    <i class="bi bi-clock-history"></i>
                    <span>تسجيل الحضور</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <div class="sidebar-footer-item">
            <div>
                <i class="bi bi-moon me-2"></i>
                <span>Dark Mode</span>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="darkModeToggle">
            </div>
        </div>
        <a href="<?php echo getRelativeUrl('logout.php'); ?>" class="sidebar-footer-item text-decoration-none">
            <div>
                <i class="bi bi-box-arrow-right me-2"></i>
                <span>Sign Out</span>
            </div>
        </a>
    </div>
</div>

