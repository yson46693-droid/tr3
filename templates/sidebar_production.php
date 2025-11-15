<?php
/**
 * القائمة الجانبية لعمال الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$baseUrl = getDashboardUrl();
?>
<div class="sidebar">
    <nav class="sidebar-nav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentPage === 'production.php' && (!isset($_GET['page']) || $_GET['page'] === '') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>production.php">
                    <i class="bi bi-speedometer2"></i>
                    <span><?php echo isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم'; ?></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'chat') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>production.php?page=chat">
                    <i class="bi bi-chat-dots"></i>
                    <span>الدردشة الجماعية</span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'product_specifications') ? 'active' : ''; ?>"
                   href="<?php echo $baseUrl; ?>production.php?page=product_specifications">
                    <i class="bi bi-file-text"></i>
                    <span>مواصفات المنتجات</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>production.php?page=production">
                    <i class="bi bi-box-seam"></i>
                    <span><?php echo isset($lang['menu_production']) ? $lang['menu_production'] : 'الإنتاج'; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>production.php?page=tasks">
                    <i class="bi bi-list-check"></i>
                    <span><?php echo isset($lang['menu_tasks']) ? $lang['menu_tasks'] : 'المهام'; ?></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link" href="<?php echo $baseUrl; ?>production.php?page=inventory">
                    <i class="bi bi-boxes"></i>
                    <span><?php echo isset($lang['menu_inventory']) ? $lang['menu_inventory'] : 'المخزون'; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?php echo getRelativeUrl('attendance.php'); ?>">
                    <i class="bi bi-calendar-check"></i>
                    <span><?php echo isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور'; ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($_GET['page']) && $_GET['page'] === 'batch_reader') ? 'active' : ''; ?>" 
                   href="<?php echo $baseUrl; ?>production.php?page=batch_reader">
                    <i class="bi bi-upc-scan"></i>
                    <span>قارئ أرقام التشغيلات</span>
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

