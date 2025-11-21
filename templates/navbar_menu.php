<?php
/**
 * قائمة منسدلة في الـ navbar تحتوي على جميع روابط الـ sidebar
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$baseUrl = getDashboardUrl();
$role = $currentUser['role'] ?? '';

// تحديد الروابط بناءً على الدور
$menuItems = [];

switch ($role) {
    case 'manager':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'manager.php',
                'active' => ($currentPage === 'manager.php' && (!isset($_GET['page']) || $_GET['page'] === 'overview' || $_GET['page'] === '')),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_approvals']) ? $lang['menu_approvals'] : 'الموافقات',
                'icon' => 'bi-check-circle',
                'url' => $baseUrl . 'manager.php?page=approvals',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'approvals'),
                'badge' => '<span class="badge bg-danger ms-2" id="approvalBadge">0</span>'
            ],
            [
                'title' => isset($lang['menu_audit_logs']) ? $lang['menu_audit_logs'] : 'سجل التدقيق',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'manager.php?page=audit',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'audit'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=reports',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'reports'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'Listing'],
            [
                'title' => 'المستخدمين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'manager.php?page=users',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'users'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'Management'],
            [
                'title' => 'النسخ الاحتياطية',
                'icon' => 'bi-database',
                'url' => $baseUrl . 'manager.php?page=backups',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'backups'),
                'badge' => null
            ],
            [
                'title' => 'الصلاحيات',
                'icon' => 'bi-shield-check',
                'url' => $baseUrl . 'manager.php?page=permissions',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'permissions'),
                'badge' => null
            ],
            [
                'title' => 'الأمان',
                'icon' => 'bi-shield-lock',
                'url' => $baseUrl . 'manager.php?page=security',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'security'),
                'badge' => null
            ],
            [
                'title' => 'نقل المخازن',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=warehouse_transfers',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'warehouse_transfers'),
                'badge' => null
            ],
            [
                'title' => 'المرتجعات والاستبدال',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=returns',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'returns'),
                'badge' => null
            ]
        ];
        break;
        
    case 'accountant':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'accountant.php',
                'active' => ($currentPage === 'accountant.php' && (!isset($_GET['page']) || $_GET['page'] === '')),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_financial']) ? $lang['menu_financial'] : 'الخزنة',
                'icon' => 'bi-safe',
                'url' => $baseUrl . 'accountant.php?page=financial',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'financial'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'Finance'],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'accountant.php?page=suppliers',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'suppliers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_collections']) ? $lang['menu_collections'] : 'التحصيلات',
                'icon' => 'bi-cash-coin',
                'url' => $baseUrl . 'accountant.php?page=collections',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'collections'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'accountant.php?page=salaries',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'salaries'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'Inventory'],
            [
                'title' => 'المخزون',
                'icon' => 'bi-boxes',
                'url' => $baseUrl . 'accountant.php?page=inventory',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'inventory'),
                'badge' => null
            ],
            [
                'title' => 'حركات المخزون',
                'icon' => 'bi-arrows-move',
                'url' => $baseUrl . 'accountant.php?page=inventory_movements',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'inventory_movements'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'accountant.php?page=invoices',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'invoices'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'accountant.php?page=attendance',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'attendance'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'accountant.php?page=reports',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'reports'),
                'badge' => null
            ]
        ];
        break;
        
    case 'sales':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'sales.php',
                'active' => ($currentPage === 'sales.php' && (!isset($_GET['page']) || $_GET['page'] === '')),
                'badge' => null
            ],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'sales.php?page=customers',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'customers'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'Sales'],
            [
                'title' => isset($lang['sales_and_collections']) ? $lang['sales_and_collections'] : 'مبيعات و تحصيلات',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'sales.php?page=sales_collections',
                'active' => (isset($_GET['page']) && in_array($_GET['page'], ['sales', 'collections', 'sales_collections'], true)),
                'badge' => null
            ],
            [
                'title' => 'طلبات العملاء',
                'icon' => 'bi-cart-check',
                'url' => $baseUrl . 'sales.php?page=orders',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'orders'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'sales.php?page=payment_schedules',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'payment_schedules'),
                'badge' => null
            ],
            [
                'title' => 'مخزن السيارة',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'sales.php?page=vehicle_inventory',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'vehicle_inventory'),
                'badge' => null
            ],
            [
                'title' => isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع',
                'icon' => 'bi-shop',
                'url' => $baseUrl . 'sales.php?page=pos',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'خزنة المندوب',
                'icon' => 'bi-cash-stack',
                'url' => $baseUrl . 'sales.php?page=cash_register',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'cash_register'),
                'badge' => null
            ],
            [
                'title' => 'المرتجعات',
                'icon' => 'bi-arrow-return-left',
                'url' => $baseUrl . 'sales.php?page=returns',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'returns'),
                'badge' => null
            ],
            [
                'title' => 'الاستبدال',
                'icon' => 'bi-arrow-repeat',
                'url' => $baseUrl . 'sales.php?page=exchanges',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'exchanges'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'sales.php?page=reports',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'reports'),
                'badge' => null
            ]
        ];
        break;
        
    case 'production':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'production.php',
                'active' => ($currentPage === 'production.php' && (!isset($_GET['page']) || $_GET['page'] === '')),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_production']) ? $lang['menu_production'] : 'الإنتاج',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=production',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'production'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'Production'],
            [
                'title' => 'تقارير الإنتاجية',
                'icon' => 'bi-graph-up-arrow',
                'url' => $baseUrl . 'production.php?page=productivity_reports',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'productivity_reports'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_tasks']) ? $lang['menu_tasks'] : 'المهام',
                'icon' => 'bi-list-check',
                'url' => $baseUrl . 'production.php?page=tasks',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'tasks'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_inventory']) ? $lang['menu_inventory'] : 'المخزون',
                'icon' => 'bi-boxes',
                'url' => $baseUrl . 'production.php?page=inventory',
                'active' => (isset($_GET['page']) && $_GET['page'] === 'inventory'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => false,
                'badge' => null
            ]
        ];
        break;
}
?>

<!-- Main Menu Dropdown -->
<div class="dropdown">
    <button class="btn btn-link nav-link dropdown-toggle d-flex align-items-center" type="button" id="mainMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-list me-2"></i>
        <span class="d-none d-md-inline"><?php echo isset($lang['menu']) ? $lang['menu'] : 'القائمة'; ?></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-start main-menu-dropdown" aria-labelledby="mainMenuDropdown">
        <?php foreach ($menuItems as $item): ?>
            <?php if (isset($item['divider'])): ?>
                <li><h6 class="dropdown-header"><?php echo htmlspecialchars($item['title']); ?></h6></li>
            <?php else: ?>
                <li>
                    <a class="dropdown-item <?php echo $item['active'] ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>" data-bs-dismiss="dropdown">
                        <i class="bi <?php echo htmlspecialchars($item['icon']); ?> me-2"></i>
                        <?php echo htmlspecialchars($item['title']); ?>
                        <?php echo $item['badge'] ?? ''; ?>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</div>

