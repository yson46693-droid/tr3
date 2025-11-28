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
            [
                'title' => 'الدردشة الجماعية',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'manager.php?page=chat',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'chat'),
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
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=company_products',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_products'),
                'badge' => null
            ],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'manager.php?page=batch_reader',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],
            [
                'title' => ' عملاء الشركة',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'manager.php?page=customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المندوبين',
                'icon' => 'bi-people-fill',
                'url' => $baseUrl . 'manager.php?page=representatives_customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'representatives_customers'),
                'badge' => null
            ],
            [
                'title' => ' تسجيل طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'manager.php?page=orders',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => 'نقطة البيع',
                'icon' => 'bi-cart4',
                'url' => $baseUrl . 'manager.php?page=pos',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'manager.php?page=invoices',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'invoices'),
                'badge' => null
            ],
            [
                'title' => 'خزنة الشركة',
                'icon' => 'bi-bank',
                'url' => $baseUrl . 'manager.php?page=company_cash',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_cash'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_returns_exchanges']) ? $lang['menu_returns_exchanges'] : 'المرتجعات والاستبدال',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=returns',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'returns'),
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
                'title' => ' تسجيل مهام الإنتاج',
                'icon' => 'bi-list-task',
                'url' => $baseUrl . 'manager.php?page=production_tasks',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'production_tasks'),
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
                'title' => 'قوالب  و وصفات المنتجات',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=product_templates',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'product_templates'),
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
                'title' => 'السيارات',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=vehicles',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'vehicles'),
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
            ['divider' => true, 'title' => isset($lang['attendance_section']) ? $lang['attendance_section'] : 'الحضور والانصراف'],
            [
                'title' => 'متابعة الحضور والانصراف',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'manager.php?page=attendance_management',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'attendance_management'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
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
            [
                'title' => 'الدردشة الجماعية',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'accountant.php?page=chat',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'chat'),
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
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=company_products',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'company_products'),
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
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'accountant.php?page=customers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المندوبين',
                'icon' => 'bi-people-fill',
                'url' => $baseUrl . 'accountant.php?page=representatives_customers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'representatives_customers'),
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
            ],
            ['divider' => true, 'title' => isset($lang['attendance_management']) ? $lang['attendance_management'] : 'إدارة الحضور'],
            [
                'title' => 'متابعة الحضور والانصراف',
                'icon' => 'bi-bar-chart',
                'url' => $baseUrl . 'accountant.php?page=attendance_management',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'attendance_management'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'accountant.php?page=batch_reader',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'batch_reader'),
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
            [
                'title' => 'الدردشة الجماعية',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'sales.php?page=chat',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'chat'),
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
                'title' => 'خزنة المندوب',
                'icon' => 'bi-cash-stack',
                'url' => $baseUrl . 'sales.php?page=cash_register',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'cash_register'),
                'badge' => null
            ],
            [
                'title' => 'سجلات المندوب',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'sales.php?page=my_records',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_records'),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'sales.php?page=my_salary',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'sales.php?page=batch_reader',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'batch_reader'),
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
            [
                'title' => 'الدردشة الجماعية',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'production.php?page=chat',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'chat'),
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
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'production.php?page=batch_reader',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],
        ];
        break;
}

if (empty($menuItems)) {
    $menuItems = [
        [
            'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
            'icon' => 'bi-speedometer2',
            'url' => $baseUrl . 'accountant.php',
            'active' => true,
            'badge' => null
        ],
        [
            'title' => 'الدردشة الجماعية',
            'icon' => 'bi-chat-dots',
            'url' => $baseUrl . 'accountant.php?page=chat',
            'active' => false,
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


