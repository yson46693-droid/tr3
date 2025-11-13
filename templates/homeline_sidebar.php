<?php
/**
 * Homeline Style Sidebar - Modern Collapsible Sidebar
 * شريط جانبي حديث قابل للطي بتصميم Homeline
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

$currentPage = basename($_SERVER['PHP_SELF']);
// الحصول على base path فقط (بدون /dashboard/)
$basePath = getBasePath();
$baseUrl = rtrim($basePath, '/') . '/dashboard/';
$role = $currentUser['role'] ?? '';
$currentPageParam = $_GET['page'] ?? '';

// تحديد الروابط بناءً على الدور
$menuItems = [];

switch ($role) {
    case 'manager':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'manager.php',
                'active' => ($currentPage === 'manager.php' && ($currentPageParam === 'overview' || $currentPageParam === '')),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['management']) ? $lang['management'] : 'الإدارة'],
            [
                'title' => isset($lang['menu_approvals']) ? $lang['menu_approvals'] : 'الموافقات',
                'icon' => 'bi-check-circle',
                'url' => $baseUrl . 'manager.php?page=approvals',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'approvals'),
                'badge' => '<span class="badge" id="approvalBadge">0</span>'
            ],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=reports',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'reports'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_security']) ? $lang['menu_security'] : 'الأمان',
                'icon' => 'bi-lock',
                'url' => $baseUrl . 'manager.php?page=security',
                'active' => ($currentPage === 'manager.php' && in_array($currentPageParam, ['security', 'permissions'])),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_warehouse_transfers']) ? $lang['menu_warehouse_transfers'] : 'نقل المخازن',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=warehouse_transfers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'warehouse_transfers'),
                'badge' => null
            ],
            [
                'title' => 'مهام الإنتاج',
                'icon' => 'bi-list-task',
                'url' => $baseUrl . 'manager.php?page=production_tasks',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'production_tasks'),
                'badge' => null
            ],
            [
                'title' => 'مخزن المنتجات',
                'icon' => 'bi-boxes',
                'url' => $baseUrl . 'manager.php?page=final_products',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'final_products'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=packaging_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_returns']) ? $lang['menu_returns'] : 'المرتجعات',
                'icon' => 'bi-arrow-return-left',
                'url' => $baseUrl . 'manager.php?page=returns',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'returns'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_exchanges']) ? $lang['menu_exchanges'] : 'الاستبدال',
                'icon' => 'bi-arrow-repeat',
                'url' => $baseUrl . 'manager.php?page=exchanges',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'exchanges'),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['listing']) ? $lang['listing'] : 'القوائم'],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=suppliers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'suppliers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'manager.php?page=customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],
            [
                'title' => 'طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'manager.php?page=orders',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'manager.php?page=salaries',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'salaries'),
                'badge' => null
            ],
            [
                'title' => 'نقطة البيع',
                'icon' => 'bi-cart4',
                'url' => $baseUrl . 'manager.php?page=pos',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'pos'),
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
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === ''),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['accounting_section']) ? $lang['accounting_section'] : 'المحاسبة'],
            [
                'title' => isset($lang['menu_financial']) ? $lang['menu_financial'] : 'الخزنة',
                'icon' => 'bi-safe',
                'url' => $baseUrl . 'accountant.php?page=financial',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'financial'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'accountant.php?page=suppliers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'suppliers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_collections']) ? $lang['menu_collections'] : 'التحصيلات',
                'icon' => 'bi-cash-coin',
                'url' => $baseUrl . 'accountant.php?page=collections',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'collections'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'accountant.php?page=salaries',
                'active' => ($currentPage === 'accountant.php' && ($currentPageParam === 'salaries' || $currentPageParam === 'salary_details')),
                'badge' => null
            ],
            [
                'title' => 'مخزن المنتجات',
                'icon' => 'bi-boxes',
                'url' => $baseUrl . 'accountant.php?page=inventory',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'inventory'),
                'badge' => null
            ],
            [
                'title' => 'حركات المخزون',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'accountant.php?page=inventory_movements',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'inventory_movements'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=packaging_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'accountant.php?page=invoices',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'invoices'),
                'badge' => null
            ],
            [
                'title' => 'طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'accountant.php?page=orders',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
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
                'active' => ($currentPage === 'sales.php' && $currentPageParam === ''),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['sales_section']) ? $lang['sales_section'] : 'المبيعات'],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'sales.php?page=customers',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['sales_and_collections']) ? $lang['sales_and_collections'] : 'مبيعات و تحصيلات',
                'icon' => 'bi-cart-check',
                'url' => $baseUrl . 'sales.php?page=sales_collections',
                'active' => ($currentPage === 'sales.php' && in_array($currentPageParam, ['sales', 'collections', 'sales_collections'], true)),
                'badge' => null
            ],
            [
                'title' => isset($lang['customer_orders']) ? $lang['customer_orders'] : 'طلبات العملاء',
                'icon' => 'bi-clipboard-check',
                'url' => $baseUrl . 'sales.php?page=orders',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => isset($lang['payment_schedules']) ? $lang['payment_schedules'] : 'جداول التحصيل',
                'icon' => 'bi-calendar-event',
                'url' => $baseUrl . 'sales.php?page=payment_schedules',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'payment_schedules'),
                'badge' => null
            ],
            [
                'title' => isset($lang['vehicle_inventory']) ? $lang['vehicle_inventory'] : 'مخزون السيارات',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'sales.php?page=vehicle_inventory',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'vehicle_inventory'),
                'badge' => null
            ],
            [
                'title' => isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع',
                'icon' => 'bi-shop',
                'url' => $baseUrl . 'sales.php?page=pos',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => isset($lang['returns_exchanges']) ? $lang['returns_exchanges'] : 'المرتجعات والاستبدال',
                'icon' => 'bi-arrow-return-left',
                'url' => $baseUrl . 'sales.php?page=returns',
                'active' => ($currentPage === 'sales.php' && ($currentPageParam === 'returns' || $currentPageParam === 'exchanges')),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'sales.php?page=my_salary',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_salary'),
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
                'active' => ($currentPage === 'production.php' && $currentPageParam === ''),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['production_section']) ? $lang['production_section'] : 'الإنتاج'],
            [
                'title' => isset($lang['menu_production']) ? $lang['menu_production'] : 'الإنتاج',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=production',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'production'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_tasks']) ? $lang['menu_tasks'] : 'المهام',
                'icon' => 'bi-list-check',
                'url' => $baseUrl . 'production.php?page=tasks',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'tasks'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=packaging_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن المنتجات',
                'icon' => 'bi-boxes',
                'url' => $baseUrl . 'production.php?page=inventory',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'inventory'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'production.php?page=my_salary',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
        ];
        break;
}

// إذا لم يكن هناك عناصر قائمة، استخدم القائمة الافتراضية
if (empty($menuItems)) {
    $menuItems = [
        [
            'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
            'icon' => 'bi-speedometer2',
            'url' => $baseUrl . 'accountant.php',
            'active' => true,
            'badge' => null
        ]
    ];
}
?>

<aside class="homeline-sidebar">
    <div class="sidebar-header">
        <a href="<?php echo getDashboardUrl($role); ?>" class="sidebar-logo">
            <i class="bi bi-building"></i>
            <span class="sidebar-logo-text"><?php echo APP_NAME; ?></span>
        </a>
        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <ul class="nav">
            <?php
            foreach ($menuItems as $item): if (!isset($item['divider'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $item['active'] ? 'active' : ''; ?>" 
                       href="<?php echo htmlspecialchars($item['url']); ?>">
                        <i class="bi <?php echo htmlspecialchars($item['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($item['title']); ?></span>
                        <?php if ($item['badge']): ?>
                            <?php echo $item['badge']; ?>
                        <?php endif; ?>
                    </a>
                </li>
            <?php
                endif;
            endforeach;
            ?>
        </ul>
    </nav>
</aside>


