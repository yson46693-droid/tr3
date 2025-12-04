<?php
/**
 * لوحة التحكم للمدير
 */

define('ACCESS_ALLOWED', true);

// إضافة Permissions-Policy header للسماح بالوصول إلى Geolocation, Camera, Microphone, Notifications
// يجب أن يكون في البداية قبل أي output
if (!headers_sent()) {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self), notifications=(self)");
    // Feature-Policy كبديل للمتصفحات القديمة
    header("Feature-Policy: geolocation 'self'; camera 'self'; microphone 'self'; notifications 'self'");
}

// تنظيف أي output buffer سابق قبل أي شيء
while (ob_get_level() > 0) {
    ob_end_clean();
}

// بدء output buffering لضمان عدم وجود محتوى قبل DOCTYPE
if (!ob_get_level()) {
    ob_start();
}

$page = $_GET['page'] ?? 'overview';

// معالجة POST لصفحة representatives_customers قبل أي شيء
if ($page === 'representatives_customers' && 
    $_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_POST['action']) && 
    $_POST['action'] === 'collect_debt') {
    
    // تحميل الملفات الأساسية فقط
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        header('Location: ' . getRelativeUrl('index.php'));
        exit;
    }
    
    // تضمين الملف مباشرة لمعالجة POST
    $modulePath = __DIR__ . '/../modules/manager/representatives_customers.php';
    if (file_exists($modulePath)) {
        // سيتم معالجة POST داخل الملف وإعادة التوجيه
        include $modulePath;
        // بعد معالجة POST، يجب إيقاف التنفيذ
        exit;
    }
}

// معالجة AJAX قبل أي require أو include قد يخرج محتوى HTML
// خاصة لصفحة قوالب المنتجات
if ($page === 'product_templates' && isset($_GET['ajax']) && $_GET['ajax'] === 'template_details' && isset($_GET['template_id'])) {
    // تحميل الملفات الأساسية فقط
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    require_once __DIR__ . '/../includes/production_helper.php';
    
    requireRole(['production', 'manager']);
    
    // تحميل ملف product_templates.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/product_templates.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

// معالجة AJAX قبل أي إخراج HTML - خاصة لصفحة مخزن أدوات التعبئة
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && isset($_GET['material_id'])) {
    // تحميل الملفات الأساسية فقط
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    require_once __DIR__ . '/../includes/production_helper.php';
    
    requireRole(['production', 'manager']);
    
    // تحميل ملف packaging_warehouse.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

// معالجة AJAX لسجل المشتريات من صفحة representatives_customers
if ($page === 'representatives_customers' && 
    isset($_GET['ajax'], $_GET['action']) && 
    $_GET['ajax'] === 'purchase_history' && 
    $_GET['action'] === 'purchase_history') {
    
    // استخدام API endpoint منفصل
    $apiPath = __DIR__ . '/../api/customer_history_api.php';
    if (file_exists($apiPath)) {
        include $apiPath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

// معالجة AJAX لجلب رصيد المندوب من صفحة company_cash
if ($page === 'company_cash' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_sales_rep_balance') {
    // تحميل الملفات الأساسية فقط
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/audit_log.php';
    require_once __DIR__ . '/../includes/notifications.php';
    require_once __DIR__ . '/../includes/approval_system.php';
    require_once __DIR__ . '/../includes/path_helper.php';
    
    requireRole(['manager', 'accountant']);
    
    // تحميل ملف company_cash.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/manager/company_cash.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

// معالجة AJAX لجلب عملاء المندوب من صفحة orders
if ($page === 'orders' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_customers' && isset($_GET['sales_rep_id'])) {
    // تنظيف أي output buffer قبل أي شيء
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إيقاف عرض الأخطاء على الشاشة
    $oldErrorReporting = error_reporting(E_ALL);
    $oldDisplayErrors = ini_set('display_errors', '0');
    
    try {
        // تحميل الملفات الأساسية فقط
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/../includes/path_helper.php';
        
        // التحقق من الصلاحيات بدون إخراج HTML
        $currentUser = getCurrentUser();
        if (!$currentUser) {
            throw new Exception('غير مصرح بالوصول');
        }
        
        $allowedRoles = ['manager', 'accountant', 'sales'];
        if (!in_array(strtolower($currentUser['role'] ?? ''), $allowedRoles, true)) {
            throw new Exception('غير مصرح بالوصول');
        }
        
        // تنظيف أي output buffer مرة أخرى بعد تحميل الملفات
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تعيين header JSON
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        $salesRepId = intval($_GET['sales_rep_id']);
        
        if ($salesRepId > 0) {
            $db = db();
            $customers = $db->query(
                "SELECT id, name FROM customers WHERE (created_by = ? OR rep_id = ?) AND status = 'active' ORDER BY name ASC",
                [$salesRepId, $salesRepId]
            );
            
            $response = [
                'success' => true,
                'customers' => $customers
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'معرف المندوب غير صحيح'
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
    } catch (Throwable $e) {
        // تنظيف أي output buffer في حالة الخطأ
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } finally {
        // استعادة إعدادات الأخطاء
        if (isset($oldErrorReporting)) {
            error_reporting($oldErrorReporting);
        }
        if (isset($oldDisplayErrors)) {
            ini_set('display_errors', $oldDisplayErrors);
        }
    }
    
    exit;
}

// تحميل باقي الملفات المطلوبة للصفحة العادية
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/activity_summary.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/table_styles.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];
if ($page === 'final_products' && !in_array('assets/css/production-page.css', $pageStylesheets, true)) {
    $pageStylesheets[] = 'assets/css/production-page.css';
}
if ($page === 'reports' && !in_array('assets/css/production-page.css', $pageStylesheets, true)) {
    $pageStylesheets[] = 'assets/css/production-page.css';
}
require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
$pageTitle = isset($lang['manager_dashboard']) ? $lang['manager_dashboard'] : 'لوحة المدير';
$pageDescription = 'لوحة تحكم المدير - إدارة شاملة للنظام والمخازن والموظفين والتقارير - ' . APP_NAME;
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'overview' || $page === ''): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-graph-up"></i><?php echo isset($lang['manager_dashboard']) ? $lang['manager_dashboard'] : 'لوحة المدير'; ?></h2>
                </div>

                <?php
                $quickLinks = [
                    [
                        'label' => 'مهام الإنتاج',
                        'icon' => 'bi-list-task',
                        'url' => getRelativeUrl('dashboard/manager.php?page=production_tasks')
                    ],
                    [
                        'label' => 'منتجات الشركة',
                        'icon' => 'bi-box-seam',
                        'url' => getRelativeUrl('dashboard/manager.php?page=company_products')
                    ],
                    [
                        'label' => 'قوالب المنتجات',
                        'icon' => 'bi-file-earmark-text',
                        'url' => getRelativeUrl('dashboard/manager.php?page=product_templates')
                    ],
                    [
                        'label' => 'مواصفات المنتجات',
                        'icon' => 'bi-journal-text',
                        'url' => getRelativeUrl('dashboard/manager.php?page=product_templates&section=specifications')
                    ],
                    [
                        'label' => 'مخزن أدوات التعبئة',
                        'icon' => 'bi-box-seam',
                        'url' => getRelativeUrl('dashboard/manager.php?page=packaging_warehouse')
                    ],
                    [
                        'label' => 'مخزن الخامات',
                        'icon' => 'bi-box2-heart',
                        'url' => getRelativeUrl('dashboard/manager.php?page=raw_materials_warehouse')
                    ],
                    [
                        'label' => 'الموردين',
                        'icon' => 'bi-truck',
                        'url' => getRelativeUrl('dashboard/manager.php?page=suppliers')
                    ],
                    [
                        'label' => 'طلبات العملاء',
                        'icon' => 'bi-cart-check',
                        'url' => getRelativeUrl('dashboard/manager.php?page=orders')
                    ],
                    [
                        'label' => 'نقطة البيع',
                        'icon' => 'bi-cart4',
                        'url' => getRelativeUrl('dashboard/manager.php?page=pos')
                    ]
                ];
                ?>

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0"><i class="bi bi-lightning-charge-fill me-2"></i>اختصارات سريعة</h5>
                        <span class="text-muted small">روابط سريعة لأهم الصفحات</span>
                    </div>
                    <div class="card-body">
                        <style>
                        /* تحسين عرض الروابط السريعة على الهواتف */
                        @media (max-width: 575.98px) {
                            .quick-links-grid .col-6 {
                                flex: 0 0 auto;
                                width: 50%;
                            }
                            
                            .quick-links-grid .btn {
                                font-size: 0.85rem;
                                padding: 0.6rem 0.5rem;
                                white-space: nowrap;
                                overflow: hidden;
                                text-overflow: ellipsis;
                            }
                            
                            .quick-links-grid .btn i {
                                font-size: 1rem;
                                flex-shrink: 0;
                            }
                            
                            .quick-links-grid .btn span {
                                overflow: hidden;
                                text-overflow: ellipsis;
                            }
                        }
                        
                        @media (max-width: 400px) {
                            .quick-links-grid .btn {
                                font-size: 0.75rem;
                                padding: 0.5rem 0.4rem;
                                gap: 0.3rem;
                            }
                            
                            .quick-links-grid .btn i {
                                font-size: 0.9rem;
                            }
                        }
                        </style>
                        <div class="row g-2 g-md-3 quick-links-grid">
                            <?php foreach ($quickLinks as $shortcut): ?>
                                <div class="col-6 col-md-4 col-lg-3 col-sm-6">
                                    <a href="<?php echo htmlspecialchars($shortcut['url']); ?>" class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center gap-2">
                                        <i class="bi <?php echo htmlspecialchars($shortcut['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($shortcut['label']); ?></span>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php
                $activitySummary = getManagerActivitySummary();
                ?>

                <div class="cards-grid mt-4">
                    <?php
                    $lastBackup = $db->queryOne(
                        "SELECT created_at FROM backups WHERE status IN ('completed', 'success') ORDER BY created_at DESC LIMIT 1"
                    );
                    $totalUsers = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
                    
                    // حساب رصيد الخزنة من financial_transactions و accountant_transactions
                    $cashBalanceResult = $db->queryOne("
                        SELECT
                            (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                            (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS total_income,
                            (SELECT COALESCE(SUM(CASE WHEN type IN ('expense', 'payment') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
                            (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('expense', 'payment') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS total_expenses
                    ");
                    
                    $totalIncome = (float)($cashBalanceResult['total_income'] ?? 0);
                    $totalExpenses = (float)($cashBalanceResult['total_expenses'] ?? 0);
                    
                    // حساب إجمالي المرتبات (المعتمدة والمدفوعة) لخصمها من الرصيد
                    $totalSalaries = 0.0;
                    $salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
                    if (!empty($salariesTableExists)) {
                        $salariesResult = $db->queryOne(
                            "SELECT COALESCE(SUM(total_amount), 0) as total_salaries
                             FROM salaries
                             WHERE status IN ('approved', 'paid')"
                        );
                        $totalSalaries = (float)($salariesResult['total_salaries'] ?? 0);
                    }
                    
                    $balance = $totalIncome - $totalExpenses - $totalSalaries;
                    
                    // حساب المبيعات الشهرية من جدول invoices (كما في تقارير المبيعات)
                    $invoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
                    if (!empty($invoicesTableExists)) {
                        $monthlySales = $db->queryOne(
                            "SELECT COALESCE(SUM(total_amount), 0) as total
                             FROM invoices
                             WHERE status != 'cancelled'
                             AND MONTH(date) = MONTH(NOW())
                             AND YEAR(date) = YEAR(NOW())"
                        );
                    } else {
                        // إذا لم يكن جدول invoices موجوداً، نستخدم جدول sales (للتوافق مع الإصدارات القديمة)
                        $monthlySales = $db->queryOne(
                            "SELECT COALESCE(SUM(total), 0) as total
                             FROM sales WHERE status = 'approved' AND MONTH(date) = MONTH(NOW())"
                        );
                    }
                    ?>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-database-check"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">آخر نسخة احتياطية</div>
                        <div class="stat-card-value">
                            <?php 
                            if ($lastBackup && isset($lastBackup['created_at'])) {
                                echo formatDate($lastBackup['created_at']);
                            } else {
                                echo 'لا توجد';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon purple">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">إجمالي المستخدمين</div>
                        <div class="stat-card-value"><?php echo $totalUsers['count'] ?? 0; ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">رصيد الخزنة</div>
                        <div class="stat-card-value"><?php echo formatCurrency($balance); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-cart-check"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">المبيعات الشهرية</div>
                        <div class="stat-card-value"><?php echo formatCurrency($monthlySales['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                </div>

            <?php elseif ($page === 'invoices'): ?>
                <?php include __DIR__ . '/../modules/accountant/invoices.php'; ?>
                
            <?php elseif ($page === 'production_tasks'): ?>
                <?php include __DIR__ . '/../modules/manager/production_tasks.php'; ?>

            <?php elseif ($page === 'approvals'): ?>
                <?php
                $pendingApprovalsCount = getPendingApprovalsCount();
                $approvalsSection = $_GET['section'] ?? 'pending';
                $validApprovalSections = ['pending', 'warehouse_transfers', 'returns'];
                if (!in_array($approvalsSection, $validApprovalSections, true)) {
                    $approvalsSection = 'pending';
                }
                
                // Get pending returns count
                require_once __DIR__ . '/../includes/approval_system.php';
                $entityColumn = getApprovalsEntityColumn();
                $pendingReturnsCount = $db->queryOne(
                    "SELECT COUNT(*) as total
                     FROM returns r
                     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
                     WHERE r.status = 'pending' AND a.status = 'pending'"
                );
                $pendingReturnsCount = (int)($pendingReturnsCount['total'] ?? 0);
                ?>

                <h2><i class="bi bi-check-circle me-2"></i><?php echo isset($lang['approvals']) ? $lang['approvals'] : 'الموافقات'; ?></h2>

                <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Approvals sections">
                    <a href="?page=approvals&section=pending"
                       class="btn <?php echo $approvalsSection === 'pending' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        الموافقات المعلقة
                        <span class="badge bg-light text-dark ms-1"><?php echo $pendingApprovalsCount; ?></span>
                    </a>
                    <a href="?page=approvals&section=warehouse_transfers"
                       class="btn <?php echo $approvalsSection === 'warehouse_transfers' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        طلبات النقل بين المخازن
                    </a>
                    <a href="?page=approvals&section=returns"
                       class="btn <?php echo $approvalsSection === 'returns' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        طلبات المرتجعات
                        <?php if ($pendingReturnsCount > 0): ?>
                            <span class="badge bg-light text-dark ms-1"><?php echo $pendingReturnsCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <?php if ($approvalsSection === 'pending'): ?>
                    <?php
                    $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                    $perPageApprovals = 10;
                    $offsetApprovals = ($pageNum - 1) * $perPageApprovals;
                    $totalPagesApprovals = (int) ceil(($pendingApprovalsCount ?: 0) / $perPageApprovals);
                    if ($totalPagesApprovals < 1) {
                        $totalPagesApprovals = 1;
                    }
                    if ($pageNum > $totalPagesApprovals) {
                        $pageNum = $totalPagesApprovals;
                        $offsetApprovals = ($pageNum - 1) * $perPageApprovals;
                    }
                    $approvals = getPendingApprovals($perPageApprovals, $offsetApprovals);
                    ?>

                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">الموافقات المعلقة (<?php echo $pendingApprovalsCount; ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive dashboard-table-wrapper">
                                <table class="table dashboard-table align-middle">
                                    <thead>
                                        <tr>
                                            <th>النوع</th>
                                            <th>الطلب من</th>
                                            <th>التاريخ</th>
                                            <th>التفاصيل</th>
                                            <th>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($approvals)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">لا توجد موافقات معلقة</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($approvals as $approval): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($approval['type']); ?></td>
                                                    <td><?php echo htmlspecialchars($approval['requested_by_full_name'] ?? $approval['requested_by_name']); ?></td>
                                                    <td><?php echo formatDateTime($approval['created_at']); ?></td>
                                                    <td>
                                                        <?php
                                                        // استخدام دالة getEntityName لعرض تفاصيل الكيان
                                                        require_once __DIR__ . '/../includes/approval_system.php';
                                                        $entityColumn = getApprovalsEntityColumn();
                                                        $entityId = $approval[$entityColumn] ?? null;
                                                        
                                                        if ($entityId) {
                                                            $entityName = getEntityName($approval['type'], $entityId);
                                                            
                                                            // عرض تفاصيل خاصة حسب نوع الموافقة
                                                            if ($approval['type'] === 'warehouse_transfer') {
                                                                // جلب تفاصيل طلب النقل
                                                                $transferItems = $db->query(
                                                                    "SELECT wti.*, p.name as product_name 
                                                                     FROM warehouse_transfer_items wti
                                                                     LEFT JOIN products p ON wti.product_id = p.id
                                                                     WHERE wti.transfer_id = ?
                                                                     ORDER BY wti.id",
                                                                    [$entityId]
                                                                );
                                                                if (!empty($transferItems)) {
                                                                    echo '<div class="small">';
                                                                    echo '<strong>' . htmlspecialchars($entityName) . '</strong><br>';
                                                                    foreach ($transferItems as $item) {
                                                                        $batchInfo = !empty($item['batch_number']) ? ' - تشغيلة ' . htmlspecialchars($item['batch_number']) : '';
                                                                        echo '<span class="badge bg-info me-1 mb-1">';
                                                                        echo htmlspecialchars($item['product_name'] ?? '-');
                                                                        echo ' (' . number_format((float)$item['quantity'], 2) . ')';
                                                                        echo $batchInfo;
                                                                        echo '</span>';
                                                                    }
                                                                    echo '</div>';
                                                                } else {
                                                                    echo '<span class="text-muted small">' . htmlspecialchars($entityName) . '</span>';
                                                                }
                                                            } elseif ($approval['type'] === 'salary_modification') {
                                                                // عرض تفاصيل تعديل الراتب
                                                                $salary = $db->queryOne(
                                                                    "SELECT s.*, u.full_name, u.username 
                                                                     FROM salaries s 
                                                                     LEFT JOIN users u ON s.user_id = u.id 
                                                                     WHERE s.id = ?",
                                                                    [$entityId]
                                                                );
                                                                if ($salary) {
                                                                    $employeeName = $salary['full_name'] ?? $salary['username'] ?? 'غير محدد';
                                                                    echo '<div class="small">';
                                                                    echo '<strong>تعديل راتب:</strong> ' . htmlspecialchars($employeeName) . '<br>';
                                                                    
                                                                    // محاولة استخراج بيانات التعديل من notes
                                                                    $approvalNotes = $approval['notes'] ?? $approval['approval_notes'] ?? '';
                                                                    if (preg_match('/\[DATA\]:(.+)/s', $approvalNotes, $matches)) {
                                                                        $modificationData = json_decode(trim($matches[1]), true);
                                                                        if ($modificationData) {
                                                                            $bonus = floatval($modificationData['bonus'] ?? 0);
                                                                            $deductions = floatval($modificationData['deductions'] ?? 0);
                                                                            $notes = trim($modificationData['notes'] ?? '');
                                                                            
                                                                            if ($bonus > 0) {
                                                                                echo '<span class="badge bg-success me-1">مكافأة: ' . number_format($bonus, 2) . ' ج.م</span>';
                                                                            }
                                                                            if ($deductions > 0) {
                                                                                echo '<span class="badge bg-danger me-1">خصومات: ' . number_format($deductions, 2) . ' ج.م</span>';
                                                                            }
                                                                            if ($notes) {
                                                                                echo '<br><small class="text-muted">' . htmlspecialchars($notes) . '</small>';
                                                                            }
                                                                        }
                                                                    } else {
                                                                        echo '<span class="text-muted">' . htmlspecialchars($entityName) . '</span>';
                                                                    }
                                                                    echo '</div>';
                                                                } else {
                                                                    echo '<span class="text-muted small">' . htmlspecialchars($entityName) . '</span>';
                                                                }
                                                            } elseif ($approval['type'] === 'financial') {
                                                                // عرض تفاصيل المعاملة المالية
                                                                $financialTransaction = $db->queryOne(
                                                                    "SELECT ft.*, u.full_name as created_by_name
                                                                     FROM financial_transactions ft
                                                                     LEFT JOIN users u ON ft.created_by = u.id
                                                                     WHERE ft.id = ?",
                                                                    [$entityId]
                                                                );
                                                                if ($financialTransaction) {
                                                                    $typeLabels = [
                                                                        'income' => 'إيراد',
                                                                        'expense' => 'مصروف',
                                                                        'transfer' => 'تحويل',
                                                                        'payment' => 'دفعة'
                                                                    ];
                                                                    $typeLabel = $typeLabels[$financialTransaction['type']] ?? $financialTransaction['type'];
                                                                    $typeColor = $financialTransaction['type'] === 'expense' ? 'danger' : ($financialTransaction['type'] === 'income' ? 'success' : 'info');
                                                                    
                                                                    echo '<div class="small">';
                                                                    echo '<span class="badge bg-' . $typeColor . ' me-1">' . htmlspecialchars($typeLabel) . '</span>';
                                                                    echo '<strong class="text-' . ($financialTransaction['type'] === 'expense' ? 'danger' : 'success') . '">';
                                                                    echo ($financialTransaction['type'] === 'expense' ? '-' : '+') . formatCurrency($financialTransaction['amount']);
                                                                    echo '</strong><br>';
                                                                    echo '<span class="text-muted">' . htmlspecialchars($financialTransaction['description']) . '</span>';
                                                                    if ($financialTransaction['reference_number']) {
                                                                        echo '<br><small class="text-muted">مرجع: ' . htmlspecialchars($financialTransaction['reference_number']) . '</small>';
                                                                    }
                                                                    echo '</div>';
                                                                } else {
                                                                    echo '<span class="text-muted small">' . htmlspecialchars($entityName) . '</span>';
                                                                }
                                                            } else {
                                                                // للأنواع الأخرى، عرض اسم الكيان فقط
                                                                echo '<span class="text-muted small">' . htmlspecialchars($entityName) . '</span>';
                                                                
                                                                // عرض الملاحظات إن وجدت
                                                                $approvalNotes = $approval['notes'] ?? $approval['approval_notes'] ?? '';
                                                                if ($approvalNotes && strlen($approvalNotes) > 0) {
                                                                    // إزالة [DATA]: من الملاحظات للعرض
                                                                    $displayNotes = preg_replace('/\[DATA\]:.*/s', '', $approvalNotes);
                                                                    $displayNotes = trim($displayNotes);
                                                                    if ($displayNotes) {
                                                                        echo '<br><small class="text-muted">' . htmlspecialchars(mb_substr($displayNotes, 0, 100)) . '</small>';
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            echo '<span class="text-muted small">لا توجد تفاصيل</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button class="btn btn-success" onclick="approveRequest(<?php echo $approval['id']; ?>, event)">
                                                                <i class="bi bi-check"></i> موافقة
                                                            </button>
                                                            <button class="btn btn-danger" onclick="rejectRequest(<?php echo $approval['id']; ?>, event)">
                                                                <i class="bi bi-x"></i> رفض
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPagesApprovals > 1): ?>
                            <nav aria-label="Page navigation" class="mt-3">
                                <ul class="pagination justify-content-center flex-wrap">
                                    <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=approvals&section=pending&p=<?php echo $pageNum - 1; ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>

                                    <?php
                                    $startPageApprovals = max(1, $pageNum - 2);
                                    $endPageApprovals = min(max(1, $totalPagesApprovals), $pageNum + 2);

                                    if ($startPageApprovals > 1): ?>
                                        <li class="page-item"><a class="page-link" href="?page=approvals&section=pending&p=1">1</a></li>
                                        <?php if ($startPageApprovals > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $startPageApprovals; $i <= $endPageApprovals; $i++): ?>
                                        <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=approvals&section=pending&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($endPageApprovals < $totalPagesApprovals): ?>
                                        <?php if ($endPageApprovals < $totalPagesApprovals - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="?page=approvals&section=pending&p=<?php echo $totalPagesApprovals; ?>"><?php echo $totalPagesApprovals; ?></a></li>
                                    <?php endif; ?>

                                    <li class="page-item <?php echo $pageNum >= $totalPagesApprovals ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=approvals&section=pending&p=<?php echo $pageNum + 1; ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($approvalsSection === 'warehouse_transfers'): ?>
                    <?php
                    $warehouseTransfersParentPage = 'approvals';
                    $warehouseTransfersSectionParam = 'warehouse_transfers';
                    $warehouseTransfersShowHeading = false;
                    include __DIR__ . '/../modules/manager/warehouse_transfers.php';
                    ?>
                <?php elseif ($approvalsSection === 'returns'): ?>
                    <?php
                    include __DIR__ . '/../modules/manager/return_approvals.php';
                    ?>
                <?php endif; ?>

            <?php elseif ($page === 'audit'): ?>
                <h2><i class="bi bi-journal-text me-2"></i><?php echo isset($lang['audit_logs']) ? $lang['audit_logs'] : 'سجل التدقيق'; ?></h2>
                
                <?php
                // Pagination
                $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                $perPageLogs = 20;
                
                $totalLogs = getAuditLogsCount([]);
                $totalPagesLogs = ceil($totalLogs / $perPageLogs);
                $logs = getAuditLogs([], $perPageLogs, ($pageNum - 1) * $perPageLogs);
                ?>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">سجل التدقيق (<?php echo $totalLogs; ?> سجل)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table align-middle">
                                <thead>
                                    <tr>
                                        <th>المستخدم</th>
                                        <th>الإجراء</th>
                                        <th>النوع</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">لا توجد سجلات</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($log['username'] ?? 'غير معروف'); ?></td>
                                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                                <td><?php echo htmlspecialchars($log['entity_type']); ?></td>
                                                <td><?php echo formatDateTime($log['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPagesLogs > 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=audit&p=<?php echo $pageNum - 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                
                                <?php
                                $startPageLogs = max(1, $pageNum - 2);
                                $endPageLogs = min($totalPagesLogs, $pageNum + 2);
                                
                                if ($startPageLogs > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?page=audit&p=1">1</a></li>
                                    <?php if ($startPageLogs > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPageLogs; $i <= $endPageLogs; $i++): ?>
                                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=audit&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPageLogs < $totalPagesLogs): ?>
                                    <?php if ($endPageLogs < $totalPagesLogs - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?page=audit&p=<?php echo $totalPagesLogs; ?>"><?php echo $totalPagesLogs; ?></a></li>
                                <?php endif; ?>
                                
                                <li class="page-item <?php echo $pageNum >= $totalPagesLogs ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=audit&p=<?php echo $pageNum + 1; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($page === 'reports'): ?>
                <div class="page-header mb-4">
                    <h2 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i><?php echo isset($lang['reports']) ? $lang['reports'] : 'التقارير'; ?></h2>
                    <p class="text-muted mb-0">اختر قسم التقارير المطلوب باستخدام الأزرار العلوية.</p>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-start reports-tabs">
                        <button type="button" class="btn btn-primary reports-tab active" data-target="reportsProductionSection">
                            <i class="bi bi-gear-wide-connected me-2"></i>تقارير الإنتاج
                        </button>
                        <button type="button" class="btn btn-outline-primary reports-tab" data-target="reportsFinancialSection">
                            <i class="bi bi-graph-up-arrow me-2"></i>تقارير المبيعات
                        </button>
                    </div>
                </div>

                <section id="reportsProductionSection" class="report-section">
                    <?php 
                    $managerProductionReports = __DIR__ . '/../modules/manager/production_reports.php';
                    if (file_exists($managerProductionReports)) {
                        include $managerProductionReports;
                    } else {
                        ?>
                        <div class="card shadow-sm">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-exclamation-triangle text-warning display-5 mb-3"></i>
                                <h4 class="mb-2">تقارير الإنتاج غير متاحة حالياً</h4>
                                <p class="text-muted mb-0">يرجى التحقق من الملفات أو التواصل مع فريق التطوير.</p>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </section>

                <section id="reportsFinancialSection" class="report-section d-none">
                    <style>
                        .sales-reports-section {
                            background: linear-gradient(135deg, #e3f2fd 0%, #ffffff 50%, #f0f7ff 100%);
                            padding: 1.5rem;
                            border-radius: 12px;
                            margin-bottom: 2rem;
                        }
                        .stat-card {
                            background: linear-gradient(135deg, #1e3a5f 0%, #2a4d7a 100%);
                            border: none;
                            border-radius: 16px;
                            box-shadow: 0 8px 24px rgba(30, 58, 95, 0.15);
                            transition: transform 0.3s ease, box-shadow 0.3s ease;
                            overflow: hidden;
                        }
                        .stat-card:hover {
                            transform: translateY(-4px);
                            box-shadow: 0 12px 32px rgba(30, 58, 95, 0.25);
                        }
                        .stat-card.rep-card {
                            background: linear-gradient(135deg, #1e3a5f 0%, #3b5f8f 100%);
                        }
                        .stat-card.manager-card {
                            background: linear-gradient(135deg, #2a4d7a 0%, #4a6fa5 100%);
                        }
                        .stat-card.shipping-card {
                            background: linear-gradient(135deg, #3b5f8f 0%, #5a7fb8 100%);
                        }
                        .stat-card.total-card {
                            background: linear-gradient(135deg, #1e3a5f 0%, #2a4d7a 50%, #3b5f8f 100%);
                        }
                        .stat-icon {
                            width: 64px;
                            height: 64px;
                            background: rgba(255, 255, 255, 0.2);
                            border-radius: 16px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            backdrop-filter: blur(10px);
                        }
                        .sales-table-card {
                            background: #ffffff;
                            border-radius: 16px;
                            box-shadow: 0 4px 20px rgba(30, 58, 95, 0.1);
                            border: 1px solid #e3f2fd;
                            overflow: hidden;
                        }
                        .sales-table-header {
                            background: linear-gradient(135deg, #1e3a5f 0%, #2a4d7a 100%);
                            color: white;
                            padding: 1.25rem 1.5rem;
                            border: none;
                        }
                        .sales-table thead {
                            background: linear-gradient(135deg, #e3f2fd 0%, #f0f7ff 100%);
                            border-bottom: 2px solid #1e3a5f;
                        }
                        .sales-table thead th {
                            color: #1e3a5f;
                            font-weight: 700;
                            padding: 1rem;
                            border-right: 1px solid #cfe2f3;
                            text-align: center;
                            vertical-align: middle;
                        }
                        .sales-table thead th:first-child {
                            text-align: right;
                        }
                        .sales-table tbody tr {
                            transition: background-color 0.2s ease;
                            border-bottom: 1px solid #e3f2fd;
                        }
                        .sales-table tbody tr:hover {
                            background-color: #f0f7ff;
                        }
                        .sales-table tbody td {
                            padding: 1rem;
                            border-right: 1px solid #e3f2fd;
                            vertical-align: middle;
                        }
                        .sales-table tfoot {
                            background: linear-gradient(135deg, #f0f7ff 0%, #e3f2fd 100%);
                            border-top: 3px solid #1e3a5f;
                        }
                        .sales-table tfoot td {
                            padding: 1.25rem 1rem;
                            font-weight: 700;
                            font-size: 1.05rem;
                            border-right: 1px solid #cfe2f3;
                        }
                        .btn-search {
                            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                            border: none;
                            color: white;
                            border-radius: 8px;
                            padding: 0.5rem 1rem;
                            transition: all 0.3s ease;
                        }
                        .btn-search:hover {
                            background: linear-gradient(135deg, #059669 0%, #047857 100%);
                            transform: translateY(-2px);
                            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
                            color: white;
                        }
                        .btn-clear {
                            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                            border: none;
                            color: white;
                            border-radius: 8px;
                            padding: 0.5rem 1rem;
                            transition: all 0.3s ease;
                        }
                        .btn-clear:hover {
                            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
                            transform: translateY(-2px);
                            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
                            color: white;
                        }
                        .search-input {
                            border: 2px solid #e3f2fd;
                            border-radius: 8px;
                            padding: 0.6rem 1rem;
                            transition: all 0.3s ease;
                        }
                        .search-input:focus {
                            border-color: #1e3a5f;
                            box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.1);
                            outline: none;
                        }
                        .qty-badge {
                            display: inline-block;
                            padding: 0.35rem 0.75rem;
                            border-radius: 8px;
                            font-weight: 600;
                            font-size: 0.95rem;
                        }
                        .qty-badge.rep {
                            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
                            color: #1e40af;
                        }
                        .qty-badge.manager {
                            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
                            color: #991b1b;
                        }
                        .qty-badge.shipping {
                            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
                            color: #0c4a6e;
                        }
                        .qty-badge.total {
                            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
                            color: #166534;
                        }
                        .amount-text {
                            font-size: 0.85rem;
                            color: #64748b;
                            margin-top: 0.25rem;
                        }
                    </style>
                    <?php
                    // استخدام دالة formatCurrency من ملف الإعدادات
                    if (!function_exists('formatCurrency')) {
                        require_once __DIR__ . '/../includes/config.php';
                    }
                    
                    // جلب بيانات المبيعات
                    $searchQuery = $_GET['search'] ?? '';
                    $searchFilter = '';
                    $searchParams = [];
                    
                    if (!empty($searchQuery)) {
                        $searchFilter = " AND (p.name LIKE ? OR p.description LIKE ?)";
                        $searchParams = ["%{$searchQuery}%", "%{$searchQuery}%"];
                    }
                    
                    // جلب جميع المبيعات من جميع المصادر
                    $salesQuery = "
                        SELECT 
                            p.id AS product_id,
                            p.name AS product_name,
                            p.unit AS product_unit,
                            COALESCE(SUM(CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM shipping_company_orders sco 
                                    WHERE sco.invoice_id = i.id
                                ) THEN ii.quantity
                                ELSE 0
                            END), 0) AS shipping_qty,
                            COALESCE(SUM(CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM shipping_company_orders sco 
                                    WHERE sco.invoice_id = i.id
                                ) THEN ii.total_price
                                ELSE 0
                            END), 0) AS shipping_total,
                            COALESCE(SUM(CASE 
                                WHEN NOT EXISTS (
                                    SELECT 1 FROM shipping_company_orders sco 
                                    WHERE sco.invoice_id = i.id
                                ) AND i.sales_rep_id IS NOT NULL AND u.role = 'sales' THEN ii.quantity
                                ELSE 0
                            END), 0) AS rep_sales_qty,
                            COALESCE(SUM(CASE 
                                WHEN NOT EXISTS (
                                    SELECT 1 FROM shipping_company_orders sco 
                                    WHERE sco.invoice_id = i.id
                                ) AND i.sales_rep_id IS NOT NULL AND u.role = 'sales' THEN ii.total_price
                                ELSE 0
                            END), 0) AS rep_sales_total,
                            COALESCE(SUM(CASE 
                                WHEN NOT EXISTS (
                                    SELECT 1 FROM shipping_company_orders sco 
                                    WHERE sco.invoice_id = i.id
                                ) AND (i.sales_rep_id IS NULL OR u.role != 'sales' OR u.role IS NULL) THEN ii.quantity
                                ELSE 0
                            END), 0) AS manager_pos_qty,
                            COALESCE(SUM(CASE 
                                WHEN NOT EXISTS (
                                    SELECT 1 FROM shipping_company_orders sco 
                                    WHERE sco.invoice_id = i.id
                                ) AND (i.sales_rep_id IS NULL OR u.role != 'sales' OR u.role IS NULL) THEN ii.total_price
                                ELSE 0
                            END), 0) AS manager_pos_total
                        FROM invoice_items ii
                        INNER JOIN invoices i ON ii.invoice_id = i.id
                        INNER JOIN products p ON ii.product_id = p.id
                        LEFT JOIN users u ON i.sales_rep_id = u.id
                        WHERE i.status != 'cancelled'
                        {$searchFilter}
                        GROUP BY p.id, p.name, p.unit
                    ";
                    
                    $salesData = $db->query($salesQuery, $searchParams);
                    
                    // جلب المرتجعات لكل منتج
                    $returnsQuery = "
                        SELECT 
                            ri.product_id,
                            COALESCE(SUM(ri.quantity), 0) AS returned_qty,
                            COALESCE(SUM(ri.total_price), 0) AS returned_total
                        FROM return_items ri
                        INNER JOIN sales_returns sr ON ri.return_id = sr.id
                        WHERE sr.status IN ('approved', 'processed')
                        GROUP BY ri.product_id
                    ";
                    
                    $returnsData = $db->query($returnsQuery);
                    $returnsByProduct = [];
                    foreach ($returnsData as $return) {
                        $returnsByProduct[$return['product_id']] = [
                            'qty' => (float)$return['returned_qty'],
                            'total' => (float)$return['returned_total']
                        ];
                    }
                    
                    // حساب الإجماليات
                    $totalRepSales = 0;
                    $totalManagerPosSales = 0;
                    $totalShippingSales = 0;
                    $totalNetSales = 0;
                    $totalRepQty = 0;
                    $totalManagerPosQty = 0;
                    $totalShippingQty = 0;
                    $totalNetQty = 0;
                    
                    foreach ($salesData as &$sale) {
                        $productId = $sale['product_id'];
                        $returnedQty = isset($returnsByProduct[$productId]) ? $returnsByProduct[$productId]['qty'] : 0;
                        $returnedTotal = isset($returnsByProduct[$productId]) ? $returnsByProduct[$productId]['total'] : 0;
                        
                        // حساب إجمالي المبيعات لكل منتج
                        $totalSalesQty = (float)$sale['rep_sales_qty'] + (float)$sale['manager_pos_qty'] + (float)$sale['shipping_qty'];
                        $totalSalesAmount = (float)$sale['rep_sales_total'] + (float)$sale['manager_pos_total'] + (float)$sale['shipping_total'];
                        
                        // توزيع المرتجعات بشكل متناسب حسب حجم المبيعات من كل مصدر
                        if ($totalSalesQty > 0) {
                            $repRatio = (float)$sale['rep_sales_qty'] / $totalSalesQty;
                            $managerRatio = (float)$sale['manager_pos_qty'] / $totalSalesQty;
                            $shippingRatio = (float)$sale['shipping_qty'] / $totalSalesQty;
                            
                            $repReturnedQty = $returnedQty * $repRatio;
                            $managerReturnedQty = $returnedQty * $managerRatio;
                            $shippingReturnedQty = $returnedQty * $shippingRatio;
                            
                            $repReturnedTotal = $returnedTotal * $repRatio;
                            $managerReturnedTotal = $returnedTotal * $managerRatio;
                            $shippingReturnedTotal = $returnedTotal * $shippingRatio;
                        } else {
                            $repReturnedQty = $managerReturnedQty = $shippingReturnedQty = 0;
                            $repReturnedTotal = $managerReturnedTotal = $shippingReturnedTotal = 0;
                        }
                        
                        // خصم المرتجعات بشكل متناسب
                        $sale['net_rep_qty'] = max(0, (float)$sale['rep_sales_qty'] - $repReturnedQty);
                        $sale['net_manager_pos_qty'] = max(0, (float)$sale['manager_pos_qty'] - $managerReturnedQty);
                        $sale['net_shipping_qty'] = max(0, (float)$sale['shipping_qty'] - $shippingReturnedQty);
                        $sale['total_net_qty'] = $sale['net_rep_qty'] + $sale['net_manager_pos_qty'] + $sale['net_shipping_qty'];
                        
                        $sale['net_rep_total'] = max(0, (float)$sale['rep_sales_total'] - $repReturnedTotal);
                        $sale['net_manager_pos_total'] = max(0, (float)$sale['manager_pos_total'] - $managerReturnedTotal);
                        $sale['net_shipping_total'] = max(0, (float)$sale['shipping_total'] - $shippingReturnedTotal);
                        $sale['total_net_total'] = $sale['net_rep_total'] + $sale['net_manager_pos_total'] + $sale['net_shipping_total'];
                        
                        $totalRepSales += $sale['net_rep_total'];
                        $totalManagerPosSales += $sale['net_manager_pos_total'];
                        $totalShippingSales += $sale['net_shipping_total'];
                        $totalNetSales += $sale['total_net_total'];
                        
                        $totalRepQty += $sale['net_rep_qty'];
                        $totalManagerPosQty += $sale['net_manager_pos_qty'];
                        $totalShippingQty += $sale['net_shipping_qty'];
                        $totalNetQty += $sale['total_net_qty'];
                    }
                    unset($sale);
                    
                    // ترتيب حسب إجمالي الكمية
                    usort($salesData, function($a, $b) {
                        return $b['total_net_qty'] <=> $a['total_net_qty'];
                    });
                    
                    // تصفية المنتجات التي لديها مبيعات فقط
                    $salesData = array_filter($salesData, function($sale) {
                        return $sale['total_net_qty'] > 0;
                    });
                    ?>
                    
                    <!-- بطاقات الإحصائيات -->
                    <div class="row g-3 mb-4">
                        <div class="col-lg-3 col-md-6">
                            <div class="card stat-card rep-card text-white h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.875rem; font-weight: 500;">مبيعات المناديب</h6>
                                            <h3 class="mb-1 fw-bold" style="font-size: 1.75rem;"><?php echo formatCurrency($totalRepSales); ?></h3>
                                            <p class="mb-0 text-white-50" style="font-size: 0.8rem;">
                                                <i class="bi bi-box-seam me-1"></i>
                                                <?php echo number_format($totalRepQty, 2); ?> وحدة
                                            </p>
                                        </div>
                                        <div class="stat-icon">
                                            <i class="bi bi-people-fill fs-2"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card stat-card manager-card text-white h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.875rem; font-weight: 500;">نقطة بيع المدير</h6>
                                            <h3 class="mb-1 fw-bold" style="font-size: 1.75rem;"><?php echo formatCurrency($totalManagerPosSales); ?></h3>
                                            <p class="mb-0 text-white-50" style="font-size: 0.8rem;">
                                                <i class="bi bi-box-seam me-1"></i>
                                                <?php echo number_format($totalManagerPosQty, 2); ?> وحدة
                                            </p>
                                        </div>
                                        <div class="stat-icon">
                                            <i class="bi bi-cash-stack fs-2"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card stat-card shipping-card text-white h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.875rem; font-weight: 500;">طلبات الشحن</h6>
                                            <h3 class="mb-1 fw-bold" style="font-size: 1.75rem;"><?php echo formatCurrency($totalShippingSales); ?></h3>
                                            <p class="mb-0 text-white-50" style="font-size: 0.8rem;">
                                                <i class="bi bi-box-seam me-1"></i>
                                                <?php echo number_format($totalShippingQty, 2); ?> وحدة
                                            </p>
                                        </div>
                                        <div class="stat-icon">
                                            <i class="bi bi-truck fs-2"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="card stat-card total-card text-white h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h6 class="text-white-50 mb-2" style="font-size: 0.875rem; font-weight: 500;">الإجمالي الصافي</h6>
                                            <h3 class="mb-1 fw-bold" style="font-size: 1.75rem;"><?php echo formatCurrency($totalNetSales); ?></h3>
                                            <p class="mb-0 text-white-50" style="font-size: 0.8rem;">
                                                <i class="bi bi-box-seam me-1"></i>
                                                <?php echo number_format($totalNetQty, 2); ?> وحدة
                                            </p>
                                        </div>
                                        <div class="stat-icon">
                                            <i class="bi bi-graph-up-arrow fs-2"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- البحث والجدول -->
                    <div class="sales-table-card">
                        <div class="sales-table-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <h5 class="mb-0 text-white fw-bold">
                                <i class="bi bi-table me-2"></i>جدول المنتجات المباعة
                            </h5>
                            <form method="GET" action="" class="d-flex gap-2">
                                <input type="hidden" name="page" value="<?php echo htmlspecialchars($_GET['page'] ?? 'reports'); ?>">
                                <input type="text" 
                                       name="search" 
                                       class="form-control search-input" 
                                       placeholder="ابحث عن منتج..." 
                                       value="<?php echo htmlspecialchars($searchQuery); ?>"
                                       style="min-width: 250px;">
                                <button class="btn btn-search" type="submit">
                                    <i class="bi bi-search me-1"></i>بحث
                                </button>
                                <?php if (!empty($searchQuery)): ?>
                                <a href="?page=<?php echo htmlspecialchars($_GET['page'] ?? 'reports'); ?>" class="btn btn-clear">
                                    <i class="bi bi-x-lg me-1"></i>إلغاء
                                </a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table sales-table mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;">اسم المنتج</th>
                                        <th class="text-center" style="width: 17.5%;">مبيعات المناديب</th>
                                        <th class="text-center" style="width: 17.5%;">نقطة بيع المدير</th>
                                        <th class="text-center" style="width: 17.5%;">طلبات الشحن</th>
                                        <th class="text-center" style="width: 17.5%;">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($salesData)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-1 d-block mb-3" style="color: #94a3b8;"></i>
                                            <h6 class="text-muted">لا توجد منتجات مباعة</h6>
                                            <?php if (!empty($searchQuery)): ?>
                                            <p class="text-muted small mb-0">جرب البحث بكلمات مختلفة</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($salesData as $sale): ?>
                                        <tr>
                                            <td class="fw-semibold" style="color: #1e3a5f;">
                                                <?php echo htmlspecialchars($sale['product_name']); ?>
                                                <?php if (!empty($sale['product_unit'])): ?>
                                                    <small class="text-muted d-block mt-1">(<?php echo htmlspecialchars($sale['product_unit']); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="qty-badge rep"><?php echo number_format($sale['net_rep_qty'], 2); ?></span>
                                                <div class="amount-text"><?php echo formatCurrency($sale['net_rep_total']); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <span class="qty-badge manager"><?php echo number_format($sale['net_manager_pos_qty'], 2); ?></span>
                                                <div class="amount-text"><?php echo formatCurrency($sale['net_manager_pos_total']); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <span class="qty-badge shipping"><?php echo number_format($sale['net_shipping_qty'], 2); ?></span>
                                                <div class="amount-text"><?php echo formatCurrency($sale['net_shipping_total']); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <span class="qty-badge total"><?php echo number_format($sale['total_net_qty'], 2); ?></span>
                                                <div class="amount-text fw-bold" style="color: #166534;"><?php echo formatCurrency($sale['total_net_total']); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($salesData)): ?>
                                <tfoot>
                                    <tr>
                                        <td class="fw-bold" style="color: #1e3a5f; font-size: 1.1rem;">الإجمالي</td>
                                        <td class="text-center">
                                            <div class="fw-bold" style="color: #1e40af; font-size: 1.05rem;"><?php echo formatCurrency($totalRepSales); ?></div>
                                            <div class="amount-text"><?php echo number_format($totalRepQty, 2); ?> وحدة</div>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold" style="color: #991b1b; font-size: 1.05rem;"><?php echo formatCurrency($totalManagerPosSales); ?></div>
                                            <div class="amount-text"><?php echo number_format($totalManagerPosQty, 2); ?> وحدة</div>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold" style="color: #0c4a6e; font-size: 1.05rem;"><?php echo formatCurrency($totalShippingSales); ?></div>
                                            <div class="amount-text"><?php echo number_format($totalShippingQty, 2); ?> وحدة</div>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold" style="color: #166534; font-size: 1.1rem;"><?php echo formatCurrency($totalNetSales); ?></div>
                                            <div class="amount-text"><?php echo number_format($totalNetQty, 2); ?> وحدة</div>
                                        </td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </section>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const tabButtons = Array.from(document.querySelectorAll('.reports-tab'));
                        const reportSections = Array.from(document.querySelectorAll('.report-section'));

                        if (!tabButtons.length || !reportSections.length) {
                            return;
                        }

                        const activateSection = (targetId) => {
                            reportSections.forEach((section) => {
                                section.classList.toggle('d-none', section.id !== targetId);
                            });

                            tabButtons.forEach((button) => {
                                const isActive = button.dataset.target === targetId;
                                button.classList.toggle('btn-primary', isActive);
                                button.classList.toggle('text-white', isActive);
                                button.classList.toggle('btn-outline-primary', !isActive);
                                button.classList.toggle('active', isActive);
                            });
                        };

                        tabButtons.forEach((button) => {
                            button.addEventListener('click', function () {
                                const targetId = this.dataset.target;
                                if (!targetId) {
                                    return;
                                }
                                activateSection(targetId);
                                const targetSection = document.getElementById(targetId);
                                if (targetSection) {
                                    targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }
                            });
                        });

                        activateSection('reportsProductionSection');
                    });
                </script>
            <?php elseif ($page === 'performance'): ?>
                <h2><i class="bi bi-graph-up-arrow me-2"></i><?php echo isset($lang['performance']) ? $lang['performance'] : 'الأداء'; ?></h2>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <p>صفحة الأداء - سيتم إضافتها</p>
                    </div>
                </div>
                
            <?php elseif ($page === 'backups'): ?>
                <?php 
                header('Location: manager.php?page=security&tab=backup');
                exit;
                ?>
                
            <?php elseif ($page === 'users'): ?>
                <?php 
                header('Location: manager.php?page=security&tab=users');
                exit;
                ?>
                
            <?php elseif ($page === 'chat'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/chat/group_chat.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">وحدة الدردشة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'suppliers'): ?>
                <!-- صفحة إدارة الموردين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/suppliers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الموردين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'representatives_customers'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/representatives_customers.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Manager representatives customers module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة عملاء المندوبين: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة عملاء المندوبين غير متاحة حالياً</div>';
                }
                ?>

            <?php elseif ($page === 'rep_customers_view'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/shared/rep_customers_view.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Manager rep customers view error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">تعذر تحميل صفحة عملاء المندوب: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة عرض عملاء المندوب غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'orders'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customer_orders.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Manager orders module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة طلبات العملاء: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات العملاء غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'salaries'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/salaries.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الرواتب غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'vehicles'): ?>
                <!-- صفحة إدارة السيارات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/vehicles.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'vehicle_inventory'): ?>
                <!-- صفحة مخزون السيارات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/vehicle_inventory.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزون السيارات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'warehouse_transfers'): ?>
                <!-- صفحة نقل المخازن -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/warehouse_transfers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'pos'): ?>
                <!-- صفحة نقطة البيع المحلية وشركات الشحن -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/pos.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'company_cash'): ?>
                <!-- صفحة خزنة الشركة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/company_cash.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة خزنة الشركة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'shipping_orders'): ?>
                <!-- صفحة طلبات شركات الشحن -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/shipping_orders.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات شركات الشحن غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'returns'): ?>
                <!-- صفحة المرتجعات - حساب المدير -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/returns_overview.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المرتجعات غير متاحة حالياً</div>';
                }
                ?>
                
                
            <?php elseif ($page === 'packaging_warehouse'): ?>
                <!-- صفحة مخزن أدوات التعبئة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن أدوات التعبئة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'product_templates'): ?>
                <!-- صفحة قوالب المنتجات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/product_templates.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة قوالب المنتجات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'company_products'): ?>
                <!-- صفحة منتجات الشركة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/company_products.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة منتجات الشركة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'product_specifications'): ?>
                <?php 
                // إعادة توجيه إلى صفحة قوالب المنتجات مع قسم المواصفات
                header('Location: ' . getRelativeUrl('dashboard/manager.php?page=product_templates&section=specifications'));
                exit;
                ?>
                
            <?php elseif ($page === 'import_packaging'): ?>
                <!-- صفحة استيراد أدوات التعبئة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/import_packaging.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة استيراد أدوات التعبئة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'raw_materials_warehouse'): ?>
                <!-- صفحة مخزن الخامات - المدير (عرض فقط) -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/raw_materials_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن الخامات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'factory_waste_warehouse'): ?>
                <!-- صفحة مخزن توالف المصنع -->
                <?php 
                $modulePath = __DIR__ . '/../modules/warehouse/factory_waste_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن توالف المصنع غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'honey_warehouse'): ?>
                <!-- إعادة توجيه من الرابط القديم -->
                <?php 
                header('Location: manager.php?page=raw_materials_warehouse&section=honey');
                exit;
                ?>
                
            <?php elseif ($page === 'security'): ?>
                <!-- صفحة الأمان والصلاحيات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/security.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الأمان غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'attendance_management'): ?>
                <!-- صفحة متابعة الحضور والانصراف -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance_management.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة متابعة الحضور والانصراف غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'batch_reader'): ?>
                <!-- صفحة قارئ أرقام التشغيلات -->
                <div class="container-fluid p-0" style="height: 100vh; overflow: hidden;">
                    <iframe src="<?php echo getRelativeUrl('reader/index.php'); ?>" 
                            style="width: 100%; height: 100%; border: none; display: block;"></iframe>
                </div>
                
            <?php elseif ($page === 'local_customers'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/local_customers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة العملاء المحليين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?php echo ASSETS_URL; ?>js/reports.js" defer></script>
<script>
// الانتظار حتى تحميل جميع الموارد قبل تنفيذ الكود
(function() {
    function waitForStylesheets(callback) {
        if (typeof callback !== 'function') return;
        
        // إذا كانت stylesheets محملة بالفعل (من header.php)
        if (window.stylesheetsLoaded === true) {
            setTimeout(callback, 50);
            return;
        }
        
        // الانتظار حتى event stylesheetsLoaded
        const handler = function() {
            document.removeEventListener('stylesheetsLoaded', handler);
            setTimeout(callback, 50);
        };
        
        document.addEventListener('stylesheetsLoaded', handler);
        
        // Fallback: انتظر window.load
        window.addEventListener('load', function() {
            setTimeout(function() {
                if (!window.stylesheetsLoaded) {
                    window.stylesheetsLoaded = true;
                    callback();
                }
            }, 300);
        });
    }
    
    function initWhenReady() {
        // الانتظار حتى window.load + stylesheets محملة
        if (document.readyState === 'complete') {
            waitForStylesheets(initManagerCode);
        } else {
            window.addEventListener('load', function() {
                waitForStylesheets(initManagerCode);
            });
        }
    }
    
    function initManagerCode() {
        // جعل الدوال متاحة عالمياً
        window.approveRequest = approveRequest;
        window.rejectRequest = rejectRequest;
        window.updateApprovalBadge = updateApprovalBadge;
        
        // تهيئة العداد
        initApprovalBadgeUpdater();
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWhenReady);
    } else {
        initWhenReady();
    }
})();

function approveRequest(id, event) {
    // استخدام event الممرر أو window.event
    const evt = event || window.event;
    
    if (!id) {
        console.error('approveRequest: Missing ID');
        alert('خطأ: معرّف الطلب غير موجود');
        return;
    }
    
    if (!confirm('هل أنت متأكد من الموافقة على هذا الطلب؟')) {
        return;
    }
    
    const btn = evt?.target?.closest('button');
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch('<?php echo getRelativeUrl("api/approve.php"); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            id: id
        })
    })
    .then(response => {
        // قراءة النص أولاً لمعرفة ما إذا كان JSON صالح
        return response.text().then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                // إذا لم يكن JSON صالحاً، عرض النص كخطأ
                throw new Error(text || 'خطأ غير معروف من الخادم');
            }
            
            // إذا كان status code غير 200، اعرض الخطأ
            if (!response.ok) {
                throw new Error(data.error || data.message || 'خطأ في الاستجابة من الخادم');
            }
            
            return data;
        });
    })
    .then(data => {
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تمت الموافقة';
            }
            // إرسال حدث لتحديث العداد
            document.dispatchEvent(new CustomEvent('approvalUpdated'));
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.error || data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error approving request:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم: ' + (error.message || 'يرجى المحاولة مرة أخرى.'));
    });
}

function rejectRequest(id, evt) {
    if (!id) {
        console.error('rejectRequest: Missing ID');
        alert('خطأ: معرّف الطلب غير موجود');
        return;
    }
    
    const reason = prompt('أدخل سبب الرفض:');
    if (!reason || reason.trim() === '') {
        return;
    }
    
    let btn = null;
    let originalHTML = '';
    
    // محاولة الحصول على الزر من event parameter أو window.event
    const e = evt || window.event || event;
    if (e && e.target) {
        btn = e.target.closest('button');
        originalHTML = btn ? btn.innerHTML : '';
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch('<?php echo getRelativeUrl("api/reject.php"); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            id: id,
            reason: reason.trim()
        })
    })
    .then(response => {
        // قراءة النص أولاً لمعرفة ما إذا كان JSON صالح
        return response.text().then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                // إذا لم يكن JSON صالحاً، عرض النص كخطأ
                throw new Error(text || 'خطأ غير معروف من الخادم');
            }
            
            // إذا كان status code غير 200، اعرض الخطأ
            if (!response.ok) {
                throw new Error(data.error || data.message || 'خطأ في الاستجابة من الخادم');
            }
            
            return data;
        });
    })
    .then(data => {
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-x-circle me-2"></i>تم الرفض';
            }
            // إرسال حدث لتحديث العداد
            document.dispatchEvent(new CustomEvent('approvalUpdated'));
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.error || data.message || 'حدث خطأ غير معروف'));
        }
    }) 
    .catch(error => {
        console.error('Error rejecting request:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم: ' + (error.message || 'يرجى المحاولة مرة أخرى.'));
    });
}

/**
 * تحديث عداد الموافقات المعلقة
 */
async function updateApprovalBadge() {
    try {
        const basePath = '<?php echo getBasePath(); ?>';
        const apiPath = basePath + '/api/approvals.php';
        const response = await fetch(apiPath, {
            credentials: 'same-origin',
            cache: 'no-cache',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            return;
        }
        
        // التحقق من content-type قبل parse JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            console.warn('updateApprovalBadge: Expected JSON but got', contentType);
            return;
        }
        
        const text = await response.text();
        if (!text || text.trim().startsWith('<')) {
            console.warn('updateApprovalBadge: Received HTML instead of JSON');
            return;
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            console.warn('updateApprovalBadge: Failed to parse JSON:', parseError);
            return;
        }
        
        if (data && data.success && typeof data.count === 'number') {
            const badge = document.getElementById('approvalBadge');
            if (badge) {
                const count = Math.max(0, parseInt(data.count, 10));
                badge.textContent = count.toString();
                if (count > 0) {
                    badge.style.display = 'inline-block';
                    badge.classList.add('badge-danger');
                } else {
                    badge.style.display = 'none';
                }
            }
        }
    } catch (error) {
        // تجاهل الأخطاء بصمت لتجنب إزعاج المستخدم
        if (error.name !== 'SyntaxError') {
            console.error('Error updating approval badge:', error);
        }
    }
}

// دالة تهيئة جميع الدوال
function initFunctions() {
    // الدوال معرّفة بالفعل في النطاق
}

// دالة تحديث عداد الموافقات
function initApprovalBadgeUpdater() {
    if (typeof updateApprovalBadge === 'function') {
        updateApprovalBadge();
        
        // تحديث العداد كل 30 ثانية
        setInterval(updateApprovalBadge, 30000);
        
        // تحديث العداد بعد الموافقة أو الرفض
        document.addEventListener('approvalUpdated', function() {
            setTimeout(updateApprovalBadge, 1000);
        });
    }
}
</script>

