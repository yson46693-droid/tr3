<?php
/**
 * القائمة الجانبية للمدير
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
                <a class="nav-link <?php echo $currentPage === 'manager.php' && (!isset($_GET['page']) || $_GET['page'] === 'overview' || $_GET['page'] === '') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>manager.php">
                    <i class="bi bi-speedometer2"></i>
                    <span><?php echo isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم'; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=approvals">
                    <i class="bi bi-check-circle"></i>
                    <span><?php echo isset($lang['menu_approvals']) ? $lang['menu_approvals'] : 'الموافقات'; ?></span>
                    <span class="badge badge-danger" id="approvalBadge">0</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'invoices') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>manager.php?page=invoices">
                    <i class="bi bi-receipt"></i>
                    <span>الفواتير</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=reports">
                    <i class="bi bi-file-earmark-text"></i>
                    <span><?php echo isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير'; ?></span>
                </a>
            </li>

            <div class="sidebar-section">
                <div class="sidebar-section-title">Listing</div>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=users">
                        <i class="bi bi-people"></i>
                        <span>المستخدمين</span>
                    </a>
                </li>
                
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Management</div>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=backups">
                        <i class="bi bi-database"></i>
                        <span>النسخ الاحتياطية</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=permissions">
                        <i class="bi bi-shield-check"></i>
                        <span>الصلاحيات</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=security">
                        <i class="bi bi-shield-lock"></i>
                        <span>الأمان</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=warehouse_transfers">
                        <i class="bi bi-arrow-left-right"></i>
                        <span>نقل المخازن</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=returns">
                        <i class="bi bi-arrow-return-left"></i>
                        <span>المرتجعات</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $baseUrl; ?>manager.php?page=exchanges">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>الاستبدال</span>
                    </a>
                </li>
            </div>
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

