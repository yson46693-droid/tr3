<?php
/**
 * لوحة التحكم للمندوب/المبيعات
 */

define('ACCESS_ALLOWED', true);

// معالجة طلبات AJAX قبل أي require قد يطبع HTML
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_products') {
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'vehicle_inventory') {
        // تحميل الملفات الأساسية فقط
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        
        // التحقق من تسجيل الدخول
        if (!isLoggedIn()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        $modulePath = __DIR__ . '/../modules/sales/vehicle_inventory.php';
        if (file_exists($modulePath)) {
            define('VEHICLE_INVENTORY_AJAX', true);
            include $modulePath;
            exit; // الخروج بعد معالجة AJAX
        }
    }
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole('sales');

$currentUser = getCurrentUser();
$db = db();
$pageParam = $_GET['page'] ?? 'dashboard';
$page = $pageParam;

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['sales_dashboard']) ? $lang['sales_dashboard'] : 'لوحة المبيعات';

// معالجة طلبات AJAX قبل إرسال أي HTML
if (isset($_GET['ajax'], $_GET['action'])) {

    // طلب سجل مشتريات العميل (يحتاج للوحدة customers)
    if ($_GET['ajax'] === 'purchase_history' && $_GET['action'] === 'purchase_history') {
        if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
            define('CUSTOMERS_PURCHASE_HISTORY_AJAX', true);
        }
        $customersModulePath = __DIR__ . '/../modules/sales/customers.php';
        if (file_exists($customersModulePath)) {
            include $customersModulePath;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'وحدة العملاء غير متاحة.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// معالجة طلبات AJAX لـ my_salary قبل إرسال أي HTML
$isAjaxRequest = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (!empty($_POST['is_ajax'])) ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_advance') {
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'my_salary') {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل الملفات الأساسية
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        
        // التحقق من تسجيل الدخول
        if (!isLoggedIn()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        
        // تضمين وحدة my_salary
        $modulePath = __DIR__ . '/../modules/user/my_salary.php';
        if (file_exists($modulePath)) {
            include $modulePath;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'صفحة الراتب غير متاحة.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// معالجة طلب update_location قبل إرسال أي HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    // التأكد من أن الصفحة الحالية هي customers
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'customers') {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل الملفات الأساسية
        if (!defined('CUSTOMERS_MODULE_BOOTSTRAPPED')) {
            require_once __DIR__ . '/../includes/config.php';
            require_once __DIR__ . '/../includes/db.php';
            require_once __DIR__ . '/../includes/auth.php';
            require_once __DIR__ . '/../includes/audit_log.php';
            require_once __DIR__ . '/../includes/path_helper.php';
            require_once __DIR__ . '/../includes/customer_history.php';
            require_once __DIR__ . '/../includes/invoices.php';
            require_once __DIR__ . '/../includes/salary_calculator.php';
            
            requireRole(['sales', 'accountant', 'manager']);
        }
        
        // تضمين وحدة customers التي تحتوي على معالج update_location
        $customersModulePath = __DIR__ . '/../modules/sales/customers.php';
        if (file_exists($customersModulePath)) {
            define('CUSTOMERS_MODULE_BOOTSTRAPPED', true);
            if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
                define('CUSTOMERS_PURCHASE_HISTORY_AJAX', true);
            }
            include $customersModulePath;
        } else {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'وحدة العملاء غير متاحة.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// معالجة طلبات cash_register قبل إرسال أي HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_cash_balance') {
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'cash_register') {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل الملفات الأساسية
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/../includes/audit_log.php';
        require_once __DIR__ . '/../includes/path_helper.php';
        
        requireRole(['sales', 'accountant', 'manager']);
        
        $currentUser = getCurrentUser();
        $isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
        $db = db();
        $error = '';
        $success = '';
        
        // التأكد من أن المستخدم مندوب مبيعات فقط
        if (!$isSalesUser) {
            $error = 'غير مصرح لك بإضافة رصيد للخزنة';
        } else {
            $amount = floatval($_POST['amount'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            
            if ($amount <= 0) {
                $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
            } else {
                try {
                    // التأكد من وجود جدول cash_register_additions
                    $tableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
                    if (empty($tableExists)) {
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `cash_register_additions` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `sales_rep_id` int(11) NOT NULL,
                              `amount` decimal(15,2) NOT NULL,
                              `description` text DEFAULT NULL,
                              `created_by` int(11) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `sales_rep_id` (`sales_rep_id`),
                              KEY `created_at` (`created_at`),
                              CONSTRAINT `cash_register_additions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                              CONSTRAINT `cash_register_additions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    
                    // إضافة الرصيد
                    $userId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                    $db->execute(
                        "INSERT INTO cash_register_additions (sales_rep_id, amount, description, created_by) VALUES (?, ?, ?, ?)",
                        [$userId, $amount, $description ?: null, $userId]
                    );
                    
                    // تسجيل في سجل التدقيق
                    try {
                        logAudit($userId, 'add_cash_balance', 'cash_register_addition', $db->getLastInsertId(), null, [
                            'amount' => $amount,
                            'description' => $description
                        ]);
                    } catch (Throwable $auditError) {
                        error_log('Error logging audit for cash addition: ' . $auditError->getMessage());
                    }
                    
                    $success = 'تم إضافة الرصيد إلى الخزنة بنجاح';
                } catch (Throwable $e) {
                    error_log('Error adding cash balance: ' . $e->getMessage());
                    $error = 'حدث خطأ في إضافة الرصيد. يرجى المحاولة لاحقاً.';
                }
            }
        }
        
        // إعادة التوجيه لتجنب إعادة إرسال النموذج
        if (!empty($success)) {
            $_SESSION['success'] = $success;
        }
        if (!empty($error)) {
            $_SESSION['error'] = $error;
        }
        $redirectUrl = $_SERVER['PHP_SELF'] . '?page=cash_register';
        $salesRepId = isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : null;
        if ($salesRepId && !$isSalesUser) {
            $redirectUrl .= '&sales_rep_id=' . $salesRepId;
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-speedometer2"></i><?php echo isset($lang['sales_dashboard']) ? $lang['sales_dashboard'] : 'لوحة المبيعات'; ?></h2>
                </div>
                
                <!-- Sales Dashboard Content -->
                <div class="cards-grid">
                    <?php
                    // إحصائيات المبيعات - التحقق من وجود جدول sales أولاً
                    $salesTableCheck = $db->queryOne("SHOW TABLES LIKE 'sales'");
                    if (!empty($salesTableCheck)) {
                        $currentUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                        $todaySales = $db->queryOne(
                            "SELECT COALESCE(SUM(total), 0) as total 
                             FROM sales 
                             WHERE DATE(date) = CURDATE() 
                               AND salesperson_id = ?",
                            [$currentUserId]
                        );
                        
                        $monthSales = $db->queryOne(
                            "SELECT COALESCE(SUM(total), 0) as total 
                             FROM sales 
                             WHERE MONTH(date) = MONTH(NOW()) 
                               AND YEAR(date) = YEAR(NOW())
                               AND salesperson_id = ?",
                            [$currentUserId]
                        );
                    } else {
                        $todaySales = ['total' => 0];
                        $monthSales = ['total' => 0];
                    }
                    
                    $customersCount = ['count' => 0];
                    $salesUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                    $customersTableExists = $db->queryOne("SHOW TABLES LIKE 'customers'");
                    if (!empty($customersTableExists) && $salesUserId > 0) {
                        try {
                            $createdByColumnExists = $db->queryOne("
                                SELECT COLUMN_NAME 
                                FROM information_schema.COLUMNS 
                                WHERE TABLE_SCHEMA = DATABASE()
                                  AND TABLE_NAME = 'customers'
                                  AND COLUMN_NAME = 'created_by'
                            ");

                            if (!empty($createdByColumnExists)) {
                                $customersCount = $db->queryOne(
                                    "SELECT COUNT(*) AS count 
                                     FROM customers 
                                     WHERE created_by = ?",
                                    [$salesUserId]
                                );
                            }
                        } catch (Exception $e) {
                            error_log('Sales dashboard customers count error: ' . $e->getMessage());
                            $customersCount = ['count' => 0];
                        }
                    }
                    ?>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-cart-check"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">مبيعات اليوم</div>
                        <div class="stat-card-value"><?php echo formatCurrency($todaySales['total'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-graph-up"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">مبيعات الشهر</div>
                        <div class="stat-card-value"><?php echo formatCurrency($monthSales['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon purple">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">عدد العملاء</div>
                        <div class="stat-card-value"><?php echo $customersCount['count'] ?? 0; ?></div>
                    </div>
                </div>
                
                <!-- آخر المبيعات -->
                <div class="table-card mt-4">
                    <div class="table-card-header">
                        <h3 class="table-card-title">آخر المبيعات</h3>
                        <?php 
                        $basePath = getBasePath();
                        $salesUrl = rtrim($basePath, '/') . '/dashboard/sales.php?page=sales_records';
                        ?>
                        <a href="<?php echo $salesUrl; ?>" class="analytics-card-action">
                            عرض الكل <i class="bi bi-arrow-left"></i>
                        </a>
                    </div>
                    <div class="table-card-body">
                        <?php
                        // التحقق من وجود جدول sales
                        $salesTableCheck = $db->queryOne("SHOW TABLES LIKE 'sales'");
                        if (!empty($salesTableCheck)) {
                            try {
                                $recentSales = $db->query(
                                    "SELECT s.*, 
                                            c.name as customer_name, 
                                            COALESCE(
                                                (SELECT fp2.product_name 
                                                 FROM finished_products fp2 
                                                 WHERE fp2.product_id = p.id 
                                                   AND fp2.product_name IS NOT NULL 
                                                   AND TRIM(fp2.product_name) != ''
                                                   AND fp2.product_name NOT LIKE 'منتج رقم%'
                                                 ORDER BY fp2.id DESC 
                                                 LIMIT 1),
                                                NULLIF(TRIM(p.name), ''),
                                                CONCAT('منتج رقم ', s.product_id)
                                            ) as product_name 
                                     FROM sales s 
                                     LEFT JOIN customers c ON s.customer_id = c.id 
                                     LEFT JOIN products p ON s.product_id = p.id 
                                     WHERE s.salesperson_id = ? 
                                     ORDER BY s.created_at DESC 
                                     LIMIT 10",
                                    [isset($currentUser['id']) ? (int)$currentUser['id'] : 0]
                                );
                            } catch (Exception $e) {
                                error_log("Sales query error: " . $e->getMessage());
                                $recentSales = [];
                            }
                        } else {
                            $recentSales = [];
                        }
                        ?>
                        <?php if (!empty($recentSales)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>العميل</th>
                                    <th>المنتج</th>
                                    <th>الكمية</th>
                                    <th>الإجمالي</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $statusMap = [
                                    'approved' => ['class' => 'success', 'label' => 'مكتمل'],
                                    'pending' => ['class' => 'info', 'label' => 'مسجل'],
                                    'rejected' => ['class' => 'danger', 'label' => 'ملغي'],
                                ];
                                ?>
                                <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td><?php echo formatDate($sale['date']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($sale['product_name'] ?? 'منتج غير محدد'); ?></td>
                                    <td><?php echo $sale['quantity']; ?></td>
                                    <td><?php echo formatCurrency($sale['total']); ?></td>
                                    <td>
                                        <?php 
                                        $statusKey = strtolower($sale['status'] ?? '');
                                        $badgeClass = $statusMap[$statusKey]['class'] ?? 'secondary';
                                        $badgeLabel = $statusMap[$statusKey]['label'] ?? htmlspecialchars($sale['status'] ?? 'غير محدد');
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo $badgeLabel; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state-card">
                            <div class="empty-state-icon"><i class="bi bi-cart-x"></i></div>
                            <div class="empty-state-title">لا توجد مبيعات</div>
                            <div class="empty-state-description">لم يتم تسجيل أي مبيعات بعد</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($page === 'chat'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/chat/group_chat.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">وحدة الدردشة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'customers'): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-people"></i><?php echo isset($lang['customers']) ? $lang['customers'] : 'العملاء'; ?></h2>
                </div>
                
                <!-- Customers Page -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                ?>
                <div class="empty-state-card">
                    <div class="empty-state-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-state-title">صفحة العملاء</div>
                    <div class="empty-state-description"><?php echo isset($lang['customers_page_coming_soon']) ? $lang['customers_page_coming_soon'] : 'صفحة العملاء - سيتم إضافتها'; ?></div>
                </div>
                <?php } ?>
                
            <?php elseif ($page === 'sales'): ?>
                <!-- صفحة المبيعات -->
                <?php
                // الحصول على قائمة العملاء للنماذج - فقط عملاء المندوب الحالي
                $currentUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                $reportCustomers = $db->query(
                    "SELECT id, name, phone 
                     FROM customers 
                     WHERE status = 'active' 
                       AND created_by = ?
                     ORDER BY name",
                    [$currentUserId]
                );
                ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-receipt"></i><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></h2>
                </div>

                <div class="combined-actions mb-4">
                    <button type="button"
                            class="btn btn-primary shadow-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#generateSalesReportModal">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>إنشاء تقرير</span>
                    </button>
                    <button type="button"
                            class="btn btn-success shadow-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#generateCustomerSalesReportModal">
                        <i class="bi bi-person-badge"></i>
                        <span>تقرير العميل</span>
                    </button>
                </div>
                
                <div id="sales-section-content" class="printable-section">
                    <?php 
                    $salesModulePath = __DIR__ . '/../modules/sales/sales.php';
                    if (file_exists($salesModulePath)) {
                        $_GET['page'] = 'sales_records';
                        include $salesModulePath;
                    } else {
                    ?>
                    <div class="empty-state-card">
                        <div class="empty-state-icon"><i class="bi bi-cart-check"></i></div>
                        <div class="empty-state-title"><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></div>
                        <div class="empty-state-description"><?php echo isset($lang['sales_page_coming_soon']) ? $lang['sales_page_coming_soon'] : 'صفحة المبيعات - سيتم إضافتها'; ?></div>
                    </div>
                    <?php } ?>
                </div>

                <!-- Modal: إنشاء تقرير المبيعات -->
                <div class="modal fade" id="generateSalesReportModal" tabindex="-1" aria-labelledby="generateSalesReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateSalesReportModalLabel">
                                    <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير المبيعات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateSalesReportForm">
                                    <div class="mb-3">
                                        <label for="salesReportDateFrom" class="form-label">من تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="salesReportDateFrom" name="date_from" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="salesReportDateTo" class="form-label">إلى تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="salesReportDateTo" name="date_to" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-primary" id="generateSalesReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: تقرير العميل - المبيعات -->
                <div class="modal fade" id="generateCustomerSalesReportModal" tabindex="-1" aria-labelledby="generateCustomerSalesReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateCustomerSalesReportModalLabel">
                                    <i class="bi bi-person-badge me-2"></i>تقرير العميل - المبيعات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateCustomerSalesReportForm">
                                    <div class="mb-3">
                                        <label for="customerSalesReportCustomerId" class="form-label">العميل <span class="text-danger">*</span></label>
                                        <select class="form-select" id="customerSalesReportCustomerId" name="customer_id" required>
                                            <option value="">اختر العميل</option>
                                            <?php foreach ($reportCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                    <?php if (!empty($customer['phone'])): ?>
                                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-success" id="generateCustomerSalesReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page === 'collections'): ?>
                <!-- صفحة التحصيلات -->
                <?php
                // الحصول على قائمة العملاء للنماذج - فقط عملاء المندوب الحالي
                $currentUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                $reportCustomers = $db->query(
                    "SELECT id, name, phone 
                     FROM customers 
                     WHERE status = 'active' 
                       AND created_by = ?
                     ORDER BY name",
                    [$currentUserId]
                );
                ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-cash-coin"></i><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></h2>
                </div>

                <div class="combined-actions mb-4">
                    <button type="button"
                            class="btn btn-primary shadow-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#generateCollectionsReportModal">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>إنشاء تقرير</span>
                    </button>
                    <button type="button"
                            class="btn btn-success shadow-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#generateCustomerCollectionsReportModal">
                        <i class="bi bi-person-badge"></i>
                        <span>تقرير العميل</span>
                    </button>
                </div>
                
                <div id="collections-section-content" class="printable-section">
                    <?php 
                    $collectionsModulePath = __DIR__ . '/../modules/sales/collections.php';
                    if (file_exists($collectionsModulePath)) {
                        include $collectionsModulePath;
                    } else {
                    ?>
                    <div class="empty-state-card">
                        <div class="empty-state-icon"><i class="bi bi-cash-coin"></i></div>
                        <div class="empty-state-title"><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></div>
                        <div class="empty-state-description"><?php echo isset($lang['collections_page_coming_soon']) ? $lang['collections_page_coming_soon'] : 'صفحة التحصيلات - سيتم إضافتها'; ?></div>
                    </div>
                    <?php } ?>
                </div>

                <!-- Modal: إنشاء تقرير التحصيلات -->
                <div class="modal fade" id="generateCollectionsReportModal" tabindex="-1" aria-labelledby="generateCollectionsReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateCollectionsReportModalLabel">
                                    <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير التحصيلات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateCollectionsReportForm">
                                    <div class="mb-3">
                                        <label for="collectionsReportDateFrom" class="form-label">من تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="collectionsReportDateFrom" name="date_from" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="collectionsReportDateTo" class="form-label">إلى تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="collectionsReportDateTo" name="date_to" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-primary" id="generateCollectionsReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: تقرير العميل - التحصيلات -->
                <div class="modal fade" id="generateCustomerCollectionsReportModal" tabindex="-1" aria-labelledby="generateCustomerCollectionsReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateCustomerCollectionsReportModalLabel">
                                    <i class="bi bi-person-badge me-2"></i>تقرير العميل - التحصيلات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateCustomerCollectionsReportForm">
                                    <div class="mb-3">
                                        <label for="customerCollectionsReportCustomerId" class="form-label">العميل <span class="text-danger">*</span></label>
                                        <select class="form-select" id="customerCollectionsReportCustomerId" name="customer_id" required>
                                            <option value="">اختر العميل</option>
                                            <?php foreach ($reportCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                    <?php if (!empty($customer['phone'])): ?>
                                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-success" id="generateCustomerCollectionsReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page === 'returns'): ?>
                <!-- صفحة المرتجعات -->
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-arrow-return-left"></i>المرتجعات</h2>
                </div>
                
                <div id="returns-section-content" class="printable-section">
                    <?php 
                    $returnsModulePath = __DIR__ . '/../modules/sales/new_returns.php';
                    if (file_exists($returnsModulePath)) {
                        include $returnsModulePath;
                    } else {
                    ?>
                    <div class="empty-state-card">
                        <div class="empty-state-icon"><i class="bi bi-arrow-return-left"></i></div>
                        <div class="empty-state-title">المرتجعات</div>
                        <div class="empty-state-description">صفحة المرتجعات - سيتم إضافتها قريباً</div>
                    </div>
                    <?php } ?>
                </div>

            <?php if (in_array($page, ['sales', 'collections'])): ?>
<script>
// JavaScript لتهيئة أزرار التقارير في صفحات المبيعات والتحصيلات
(function() {
    let reportButtonsInitialized = false;
    
    function initReportButtons() {
        if (reportButtonsInitialized) return;
        
        const bs = window.bootstrap || bootstrap;
        if (!bs || typeof bs.Modal === 'undefined') {
            setTimeout(initReportButtons, 100);
            return;
        }
        
        const basePath = '<?php echo getBasePath(); ?>';
        
        <?php if ($page === 'sales'): ?>
        // معالج إنشاء تقرير المبيعات
        const generateSalesReportBtn = document.getElementById('generateSalesReportBtn');
        if (generateSalesReportBtn) {
            generateSalesReportBtn.addEventListener('click', function() {
                const dateFrom = document.getElementById('salesReportDateFrom').value;
                const dateTo = document.getElementById('salesReportDateTo').value;
                
                if (!dateFrom || !dateTo) {
                    alert('يرجى اختيار الفترة المطلوبة');
                    return;
                }
                
                if (new Date(dateFrom) > new Date(dateTo)) {
                    alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
                    return;
                }
                
                const url = basePath + '/api/generate_sales_report.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
                const reportWindow = window.open(url, 'salesReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateSalesReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير العميل - المبيعات
        const generateCustomerSalesReportBtn = document.getElementById('generateCustomerSalesReportBtn');
        if (generateCustomerSalesReportBtn) {
            generateCustomerSalesReportBtn.addEventListener('click', function() {
                const customerId = document.getElementById('customerSalesReportCustomerId').value;
                
                if (!customerId) {
                    alert('يرجى اختيار العميل');
                    return;
                }
                
                const url = basePath + '/api/generate_customer_sales_report.php?customer_id=' + encodeURIComponent(customerId);
                const reportWindow = window.open(url, 'customerSalesReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateCustomerSalesReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        <?php endif; ?>
        
        <?php if ($page === 'collections'): ?>
        // معالج إنشاء تقرير التحصيلات
        const generateCollectionsReportBtn = document.getElementById('generateCollectionsReportBtn');
        if (generateCollectionsReportBtn) {
            generateCollectionsReportBtn.addEventListener('click', function() {
                const dateFrom = document.getElementById('collectionsReportDateFrom').value;
                const dateTo = document.getElementById('collectionsReportDateTo').value;
                
                if (!dateFrom || !dateTo) {
                    alert('يرجى اختيار الفترة المطلوبة');
                    return;
                }
                
                if (new Date(dateFrom) > new Date(dateTo)) {
                    alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
                    return;
                }
                
                const url = basePath + '/api/generate_collections_report.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
                const reportWindow = window.open(url, 'collectionsReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateCollectionsReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير العميل - التحصيلات
        const generateCustomerCollectionsReportBtn = document.getElementById('generateCustomerCollectionsReportBtn');
        if (generateCustomerCollectionsReportBtn) {
            generateCustomerCollectionsReportBtn.addEventListener('click', function() {
                const customerId = document.getElementById('customerCollectionsReportCustomerId').value;
                
                if (!customerId) {
                    alert('يرجى اختيار العميل');
                    return;
                }
                
                const url = basePath + '/api/generate_customer_collections_report.php?customer_id=' + encodeURIComponent(customerId);
                const reportWindow = window.open(url, 'customerCollectionsReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateCustomerCollectionsReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        <?php endif; ?>
        
        reportButtonsInitialized = true;
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            requestAnimationFrame(function() {
                initReportButtons();
            });
        });
    } else {
        requestAnimationFrame(function() {
            initReportButtons();
        });
    }
})();
</script>
            <?php endif; ?>

            <?php elseif ($page === 'my_records'): ?>
                <!-- صفحة سجلات المندوب -->
                <?php
                // الحصول على قائمة العملاء للنماذج - فقط عملاء المندوب الحالي
                $currentUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                $reportCustomers = $db->query(
                    "SELECT id, name, phone 
                     FROM customers 
                     WHERE status = 'active' 
                       AND created_by = ?
                     ORDER BY name",
                    [$currentUserId]
                );
                
                // تحديد التبويب النشط
                $activeTab = $_GET['section'] ?? 'sales';
                if ($activeTab === 'collections') {
                    $activeTab = 'collections';
                } elseif ($activeTab === 'returns') {
                    $activeTab = 'returns';
                } elseif ($activeTab === 'exchanges') {
                    $activeTab = 'exchanges';
                } elseif ($activeTab === 'damaged_returns') {
                    $activeTab = 'damaged_returns';
                } else {
                    $activeTab = 'sales';
                }
                ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-journal-text"></i>سجلات المندوب</h2>
                </div>

                <div class="combined-sections">
                    <style>
                        .combined-tabs .nav-link {
                            font-weight: 600;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                            padding: 0.75rem 1.5rem;
                            box-shadow: 0 2px 6px rgba(14, 30, 37, 0.08);
                            transition: all 0.3s ease;
                        }
                        .combined-tabs .nav-link:not(.active) {
                            background-color: rgba(12, 45, 194, 0.08);
                            color: inherit;
                        }
                        .combined-tabs .nav-link.active {
                            background: linear-gradient(135deg, rgb(12, 45, 194) 0%, rgb(11, 94, 218) 100%);
                            color: white;
                        }
                        .combined-tabs .nav-link:hover:not(.active) {
                            background-color: rgba(12, 45, 194, 0.15);
                            transform: translateY(-2px);
                        }
                        .combined-tabs .nav-link i {
                            font-size: 1.1rem;
                        }
                        .combined-tab-pane {
                            animation: fadeUp 0.25s ease;
                        }
                        .combined-actions {
                            display: flex;
                            justify-content: flex-end;
                            gap: 0.75rem;
                            margin-bottom: 1.5rem;
                            flex-wrap: wrap;
                        }
                        .combined-actions .btn i {
                            font-size: 1rem;
                        }
                        .combined-actions .btn span {
                            font-weight: 600;
                        }
                        @keyframes fadeUp {
                            from {
                                opacity: 0;
                                transform: translateY(10px);
                            }
                            to {
                                opacity: 1;
                                transform: translateY(0);
                            }
                        }
                        @media (max-width: 576px) {
                            .combined-tabs {
                                gap: 0.75rem;
                            }
                            .combined-tabs .nav-link {
                                width: 100%;
                                justify-content: center;
                            }
                        }
                    </style>

                    <ul class="nav nav-pills combined-tabs mb-4 flex-column flex-sm-row gap-2" id="myRecordsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'sales' ? 'active' : ''; ?>" 
                                    id="my-sales-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#my-sales-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="my-sales-section" 
                                    aria-selected="<?php echo $activeTab === 'sales' ? 'true' : 'false'; ?>">
                                <i class="bi bi-receipt"></i>
                                <span><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'collections' ? 'active' : ''; ?>" 
                                    id="my-collections-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#my-collections-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="my-collections-section" 
                                    aria-selected="<?php echo $activeTab === 'collections' ? 'true' : 'false'; ?>">
                                <i class="bi bi-cash-coin"></i>
                                <span><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'returns' ? 'active' : ''; ?>" 
                                    id="my-returns-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#my-returns-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="my-returns-section" 
                                    aria-selected="<?php echo $activeTab === 'returns' ? 'true' : 'false'; ?>">
                                <i class="bi bi-arrow-return-left"></i>
                                <span>المرتجعات</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'exchanges' ? 'active' : ''; ?>" 
                                    id="my-exchanges-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#my-exchanges-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="my-exchanges-section" 
                                    aria-selected="<?php echo $activeTab === 'exchanges' ? 'true' : 'false'; ?>">
                                <i class="bi bi-arrow-left-right"></i>
                                <span>الاستبدالات</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'damaged_returns' ? 'active' : ''; ?>" 
                                    id="my-damaged-returns-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#my-damaged-returns-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="my-damaged-returns-section" 
                                    aria-selected="<?php echo $activeTab === 'damaged_returns' ? 'true' : 'false'; ?>">
                                <i class="bi bi-exclamation-triangle"></i>
                                <span>المرتجعات التالفة</span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content combined-tab-content" id="myRecordsTabContent">
                        <div class="tab-pane fade <?php echo $activeTab === 'sales' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="my-sales-section" 
                             role="tabpanel" 
                             aria-labelledby="my-sales-tab">
                            <div class="combined-actions">
                                <button type="button"
                                        class="btn btn-primary shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#myGenerateSalesReportModal">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span>إنشاء تقرير</span>
                                </button>
                                <button type="button"
                                        class="btn btn-success shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#myGenerateCustomerSalesReportModal">
                                    <i class="bi bi-person-badge"></i>
                                    <span>تقرير العميل</span>
                                </button>
                            </div>
                            <div id="my-sales-section-content" class="printable-section">
                                <?php 
                                $salesModulePath = __DIR__ . '/../modules/sales/sales.php';
                                if (file_exists($salesModulePath)) {
                                    $_GET['page'] = 'sales_records';
                                    $_GET['section'] = 'sales';  // تحديث دائماً إلى 'sales'
                                    include $salesModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-cart-check"></i></div>
                                    <div class="empty-state-title"><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></div>
                                    <div class="empty-state-description"><?php echo isset($lang['sales_page_coming_soon']) ? $lang['sales_page_coming_soon'] : 'صفحة المبيعات - سيتم إضافتها'; ?></div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $activeTab === 'collections' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="my-collections-section" 
                             role="tabpanel" 
                             aria-labelledby="my-collections-tab">
                            <div class="combined-actions">
                                <button type="button"
                                        class="btn btn-primary shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#myGenerateCollectionsReportModal">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span>إنشاء تقرير</span>
                                </button>
                                <button type="button"
                                        class="btn btn-success shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#myGenerateCustomerCollectionsReportModal">
                                    <i class="bi bi-person-badge"></i>
                                    <span>تقرير العميل</span>
                                </button>
                            </div>
                            <div id="my-collections-section-content" class="printable-section">
                                <?php 
                                $collectionsModulePath = __DIR__ . '/../modules/sales/collections.php';
                                if (file_exists($collectionsModulePath)) {
                                    include $collectionsModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-cash-coin"></i></div>
                                    <div class="empty-state-title"><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></div>
                                    <div class="empty-state-description"><?php echo isset($lang['collections_page_coming_soon']) ? $lang['collections_page_coming_soon'] : 'صفحة التحصيلات - سيتم إضافتها'; ?></div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $activeTab === 'returns' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="my-returns-section" 
                             role="tabpanel" 
                             aria-labelledby="my-returns-tab">
                            <div id="my-returns-section-content" class="printable-section">
                                <?php 
                                $returnsModulePath = __DIR__ . '/../modules/sales/new_returns.php';
                                if (file_exists($returnsModulePath)) {
                                    include $returnsModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-arrow-return-left"></i></div>
                                    <div class="empty-state-title">المرتجعات</div>
                                    <div class="empty-state-description">صفحة المرتجعات - سيتم إضافتها قريباً</div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $activeTab === 'exchanges' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="my-exchanges-section" 
                             role="tabpanel" 
                             aria-labelledby="my-exchanges-tab">
                            <div id="my-exchanges-section-content" class="printable-section">
                                <?php 
                                $salesModulePath = __DIR__ . '/../modules/sales/sales.php';
                                if (file_exists($salesModulePath)) {
                                    $_GET['page'] = 'sales_records';
                                    $_GET['section'] = 'exchanges';  // تحديث دائماً إلى 'exchanges'
                                    include $salesModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-arrow-left-right"></i></div>
                                    <div class="empty-state-title">الاستبدالات</div>
                                    <div class="empty-state-description">صفحة الاستبدالات - سيتم إضافتها</div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $activeTab === 'damaged_returns' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="my-damaged-returns-section" 
                             role="tabpanel" 
                             aria-labelledby="my-damaged-returns-tab">
                            <div id="my-damaged-returns-section-content" class="printable-section">
                                <?php 
                                $damagedReturnsModulePath = __DIR__ . '/../modules/sales/damaged_returns.php';
                                if (file_exists($damagedReturnsModulePath)) {
                                    include $damagedReturnsModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-exclamation-triangle"></i></div>
                                    <div class="empty-state-title">المرتجعات التالفة</div>
                                    <div class="empty-state-description">صفحة المرتجعات التالفة - سيتم إضافتها قريباً</div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: إنشاء تقرير المبيعات -->
                <div class="modal fade" id="myGenerateSalesReportModal" tabindex="-1" aria-labelledby="myGenerateSalesReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="myGenerateSalesReportModalLabel">
                                    <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير المبيعات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="myGenerateSalesReportForm">
                                    <div class="mb-3">
                                        <label for="mySalesReportDateFrom" class="form-label">من تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="mySalesReportDateFrom" name="date_from" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="mySalesReportDateTo" class="form-label">إلى تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="mySalesReportDateTo" name="date_to" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-primary" id="myGenerateSalesReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: إنشاء تقرير التحصيلات -->
                <div class="modal fade" id="myGenerateCollectionsReportModal" tabindex="-1" aria-labelledby="myGenerateCollectionsReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="myGenerateCollectionsReportModalLabel">
                                    <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير التحصيلات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="myGenerateCollectionsReportForm">
                                    <div class="mb-3">
                                        <label for="myCollectionsReportDateFrom" class="form-label">من تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="myCollectionsReportDateFrom" name="date_from" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="myCollectionsReportDateTo" class="form-label">إلى تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="myCollectionsReportDateTo" name="date_to" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-primary" id="myGenerateCollectionsReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: تقرير العميل - المبيعات -->
                <div class="modal fade" id="myGenerateCustomerSalesReportModal" tabindex="-1" aria-labelledby="myGenerateCustomerSalesReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="myGenerateCustomerSalesReportModalLabel">
                                    <i class="bi bi-person-badge me-2"></i>تقرير العميل - المبيعات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="myGenerateCustomerSalesReportForm">
                                    <div class="mb-3">
                                        <label for="myCustomerSalesReportCustomerId" class="form-label">العميل <span class="text-danger">*</span></label>
                                        <select class="form-select" id="myCustomerSalesReportCustomerId" name="customer_id" required>
                                            <option value="">اختر العميل</option>
                                            <?php foreach ($reportCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                    <?php if (!empty($customer['phone'])): ?>
                                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-success" id="myGenerateCustomerSalesReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: تقرير العميل - التحصيلات -->
                <div class="modal fade" id="myGenerateCustomerCollectionsReportModal" tabindex="-1" aria-labelledby="myGenerateCustomerCollectionsReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="myGenerateCustomerCollectionsReportModalLabel">
                                    <i class="bi bi-person-badge me-2"></i>تقرير العميل - التحصيلات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="myGenerateCustomerCollectionsReportForm">
                                    <div class="mb-3">
                                        <label for="myCustomerCollectionsReportCustomerId" class="form-label">العميل <span class="text-danger">*</span></label>
                                        <select class="form-select" id="myCustomerCollectionsReportCustomerId" name="customer_id" required>
                                            <option value="">اختر العميل</option>
                                            <?php foreach ($reportCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                    <?php if (!empty($customer['phone'])): ?>
                                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-success" id="myGenerateCustomerCollectionsReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

<script>
// JavaScript لتهيئة التبويبات وأزرار التقارير في صفحة سجلات المندوب
(function() {
    let tabsInitialized = false;
    let reportButtonsInitialized = false;
    
    // تهيئة التبويبات
    function initTabs() {
        const tabButtons = document.querySelectorAll('#myRecordsTabs button[data-bs-toggle="tab"]');
        if (tabButtons.length === 0) {
            if (!tabsInitialized) {
                setTimeout(initTabs, 100);
            }
            return;
        }
        
        if (tabsInitialized) {
            let hasListeners = false;
            tabButtons.forEach(function(btn) {
                if (btn.hasAttribute('data-tab-initialized')) {
                    hasListeners = true;
                }
            });
            if (hasListeners) {
                return;
            }
        }
        
        const bs = window.bootstrap || bootstrap;
        const useBootstrap = bs && typeof bs.Tab !== 'undefined';
        
        tabButtons.forEach(function(button) {
            if (button.hasAttribute('data-tab-initialized')) {
                return;
            }
            
            if (useBootstrap) {
                button.addEventListener('shown.bs.tab', function(event) {
                    const currentTabButtons = document.querySelectorAll('#myRecordsTabs button[data-bs-toggle="tab"]');
                    updateTabState(event.target, currentTabButtons);
                    updateURL(event.target);
                });
            } else {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const targetId = this.getAttribute('data-bs-target');
                    if (!targetId) return;
                    
                    document.querySelectorAll('.tab-pane').forEach(function(pane) {
                        if (pane) {
                            pane.classList.remove('show', 'active');
                        }
                    });
                    
                    const targetPane = document.querySelector(targetId);
                    if (targetPane) {
                        targetPane.classList.add('show', 'active');
                    }
                    
                    const currentTabButtons = document.querySelectorAll('#myRecordsTabs button[data-bs-toggle="tab"]');
                    if (this && currentTabButtons && currentTabButtons.length > 0) {
                        updateTabState(this, currentTabButtons);
                        updateURL(this);
                    }
                });
            }
            
            button.setAttribute('data-tab-initialized', 'true');
        });
        
        tabsInitialized = true;
    }
    
    function updateTabState(activeButton, allButtons) {
        if (!activeButton) return;
        
        allButtons.forEach(function(btn) {
            if (btn) {
                btn.classList.remove('active');
                btn.setAttribute('aria-selected', 'false');
            }
        });
        
        if (activeButton) {
            activeButton.classList.add('active');
            activeButton.setAttribute('aria-selected', 'true');
        }
    }
    
    function updateURL(button) {
        if (!button) return;
        
        const targetId = button.getAttribute('data-bs-target');
        let section = 'sales';
        if (targetId === '#my-collections-section') {
            section = 'collections';
        } else if (targetId === '#my-returns-section') {
            section = 'returns';
        } else if (targetId === '#my-exchanges-section') {
            section = 'exchanges';
        } else if (targetId === '#my-damaged-returns-section') {
            section = 'damaged_returns';
        }
        const url = new URL(window.location);
        url.searchParams.set('section', section);
        window.history.pushState({}, '', url);
    }
    
    function initReportButtons() {
        if (reportButtonsInitialized) return;
        
        const bs = window.bootstrap || bootstrap;
        if (!bs || typeof bs.Modal === 'undefined') {
            setTimeout(initReportButtons, 100);
            return;
        }
        
        const basePath = '<?php echo getBasePath(); ?>';
        
        // معالج إنشاء تقرير المبيعات
        const generateSalesReportBtn = document.getElementById('myGenerateSalesReportBtn');
        if (generateSalesReportBtn) {
            generateSalesReportBtn.addEventListener('click', function() {
                const dateFrom = document.getElementById('mySalesReportDateFrom').value;
                const dateTo = document.getElementById('mySalesReportDateTo').value;
                
                if (!dateFrom || !dateTo) {
                    alert('يرجى اختيار الفترة المطلوبة');
                    return;
                }
                
                if (new Date(dateFrom) > new Date(dateTo)) {
                    alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
                    return;
                }
                
                const url = basePath + '/api/generate_sales_report.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
                const reportWindow = window.open(url, 'salesReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('myGenerateSalesReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير العميل - المبيعات
        const generateCustomerSalesReportBtn = document.getElementById('myGenerateCustomerSalesReportBtn');
        if (generateCustomerSalesReportBtn) {
            generateCustomerSalesReportBtn.addEventListener('click', function() {
                const customerId = document.getElementById('myCustomerSalesReportCustomerId').value;
                
                if (!customerId) {
                    alert('يرجى اختيار العميل');
                    return;
                }
                
                const url = basePath + '/api/generate_customer_sales_report.php?customer_id=' + encodeURIComponent(customerId);
                const reportWindow = window.open(url, 'customerSalesReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('myGenerateCustomerSalesReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير التحصيلات
        const generateCollectionsReportBtn = document.getElementById('myGenerateCollectionsReportBtn');
        if (generateCollectionsReportBtn) {
            generateCollectionsReportBtn.addEventListener('click', function() {
                const dateFrom = document.getElementById('myCollectionsReportDateFrom').value;
                const dateTo = document.getElementById('myCollectionsReportDateTo').value;
                
                if (!dateFrom || !dateTo) {
                    alert('يرجى اختيار الفترة المطلوبة');
                    return;
                }
                
                if (new Date(dateFrom) > new Date(dateTo)) {
                    alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
                    return;
                }
                
                const url = basePath + '/api/generate_collections_report.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
                const reportWindow = window.open(url, 'collectionsReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('myGenerateCollectionsReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير العميل - التحصيلات
        const generateCustomerCollectionsReportBtn = document.getElementById('myGenerateCustomerCollectionsReportBtn');
        if (generateCustomerCollectionsReportBtn) {
            generateCustomerCollectionsReportBtn.addEventListener('click', function() {
                const customerId = document.getElementById('myCustomerCollectionsReportCustomerId').value;
                
                if (!customerId) {
                    alert('يرجى اختيار العميل');
                    return;
                }
                
                const url = basePath + '/api/generate_customer_collections_report.php?customer_id=' + encodeURIComponent(customerId);
                const reportWindow = window.open(url, 'customerCollectionsReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('myGenerateCustomerCollectionsReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        reportButtonsInitialized = true;
    }
    
    function initAll() {
        initTabs();
        initReportButtons();
    }
    
    function scheduleInit() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                requestAnimationFrame(function() {
                    initAll();
                });
            }, { once: true });
        } else {
            requestAnimationFrame(function() {
                initAll();
            });
        }
    }
    
    scheduleInit();
    
    window.addEventListener('load', function() {
        if (!tabsInitialized || !reportButtonsInitialized) {
            requestAnimationFrame(function() {
                initAll();
            });
        }
    }, { once: true });
})();
</script>

            <?php elseif ($page === 'sales_records'): ?>
                <?php
                // الحصول على قائمة العملاء للنماذج - فقط عملاء المندوب الحالي
                $currentUserId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
                $reportCustomers = $db->query(
                    "SELECT id, name, phone 
                     FROM customers 
                     WHERE status = 'active' 
                       AND created_by = ?
                     ORDER BY name",
                    [$currentUserId]
                );
                
                // تحديد التبويب النشط
                $activeTab = $_GET['section'] ?? 'sales';
                if ($activeTab === 'collections') {
                    $activeTab = 'collections';
                } elseif ($activeTab === 'returns') {
                    $activeTab = 'returns';
                } elseif ($activeTab === 'exchanges') {
                    $activeTab = 'exchanges';
                } elseif ($activeTab === 'damaged_returns') {
                    $activeTab = 'damaged_returns';
                } else {
                    $activeTab = 'sales';
                }
                ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-journal-text"></i>السجلات</h2>
                </div>

                <div class="combined-sections">
                    <style>
                        .combined-tabs .nav-link {
                            font-weight: 600;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                            padding: 0.75rem 1.5rem;
                            box-shadow: 0 2px 6px rgba(14, 30, 37, 0.08);
                            transition: all 0.3s ease;
                        }
                        .combined-tabs .nav-link:not(.active) {
                            background-color: rgba(12, 45, 194, 0.08);
                            color: inherit;
                        }
                        .combined-tabs .nav-link.active {
                            background: linear-gradient(135deg, rgb(12, 45, 194) 0%, rgb(11, 94, 218) 100%);
                            color: white;
                        }
                        .combined-tabs .nav-link:hover:not(.active) {
                            background-color: rgba(12, 45, 194, 0.15);
                            transform: translateY(-2px);
                        }
                        .combined-tabs .nav-link i {
                            font-size: 1.1rem;
                        }
                        .combined-tab-pane {
                            animation: fadeUp 0.25s ease;
                        }
                        .combined-actions {
                            display: flex;
                            justify-content: flex-end;
                            gap: 0.75rem;
                            margin-bottom: 1.5rem;
                            flex-wrap: wrap;
                        }
                        .combined-actions .btn i {
                            font-size: 1rem;
                        }
                        .combined-actions .btn span {
                            font-weight: 600;
                        }
                        @keyframes fadeUp {
                            from {
                                opacity: 0;
                                transform: translateY(10px);
                            }
                            to {
                                opacity: 1;
                                transform: translateY(0);
                            }
                        }
                        @media (max-width: 576px) {
                            .combined-tabs {
                                gap: 0.75rem;
                            }
                            .combined-tabs .nav-link {
                                width: 100%;
                                justify-content: center;
                            }
                        }
                    </style>

                    <ul class="nav nav-pills combined-tabs mb-4 flex-column flex-sm-row gap-2" id="salesRecordsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'sales' ? 'active' : ''; ?>" 
                                    id="sales-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#sales-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="sales-section" 
                                    aria-selected="<?php echo $activeTab === 'sales' ? 'true' : 'false'; ?>">
                                <i class="bi bi-receipt"></i>
                                <span><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'collections' ? 'active' : ''; ?>" 
                                    id="collections-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#collections-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="collections-section" 
                                    aria-selected="<?php echo $activeTab === 'collections' ? 'true' : 'false'; ?>">
                                <i class="bi bi-cash-coin"></i>
                                <span><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activeTab === 'returns' ? 'active' : ''; ?>" 
                                    id="returns-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#returns-section" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="returns-section" 
                                    aria-selected="<?php echo $activeTab === 'returns' ? 'true' : 'false'; ?>">
                                <i class="bi bi-arrow-return-left"></i>
                                <span>المرتجعات</span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content combined-tab-content" id="salesRecordsTabContent">
                        <div class="tab-pane fade <?php echo $activeTab === 'sales' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="sales-section" 
                             role="tabpanel" 
                             aria-labelledby="sales-tab">
                            <div class="combined-actions">
                                <button type="button"
                                        class="btn btn-primary shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#generateSalesReportModal">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span>إنشاء تقرير</span>
                                </button>
                                <button type="button"
                                        class="btn btn-success shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#generateCustomerSalesReportModal">
                                    <i class="bi bi-person-badge"></i>
                                    <span>تقرير العميل</span>
                                </button>
                            </div>
                            <div id="sales-section-content" class="printable-section">
                                <?php 
                                $salesModulePath = __DIR__ . '/../modules/sales/sales.php';
                                if (file_exists($salesModulePath)) {
                                    // تعديل قيمة page في GET قبل تضمين الملف
                                    $_GET['page'] = 'sales_records';
                                    if (!isset($_GET['section'])) {
                                        $_GET['section'] = $activeTab;
                                    }
                                    include $salesModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-cart-check"></i></div>
                                    <div class="empty-state-title"><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></div>
                                    <div class="empty-state-description"><?php echo isset($lang['sales_page_coming_soon']) ? $lang['sales_page_coming_soon'] : 'صفحة المبيعات - سيتم إضافتها'; ?></div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $activeTab === 'collections' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="collections-section" 
                             role="tabpanel" 
                             aria-labelledby="collections-tab">
                            <div class="combined-actions">
                                <button type="button"
                                        class="btn btn-primary shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#generateCollectionsReportModal">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span>إنشاء تقرير</span>
                                </button>
                                <button type="button"
                                        class="btn btn-success shadow-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#generateCustomerCollectionsReportModal">
                                    <i class="bi bi-person-badge"></i>
                                    <span>تقرير العميل</span>
                                </button>
                            </div>
                            <div id="collections-section-content" class="printable-section">
                                <?php 
                                $collectionsModulePath = __DIR__ . '/../modules/sales/collections.php';
                                if (file_exists($collectionsModulePath)) {
                                    include $collectionsModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-cash-coin"></i></div>
                                    <div class="empty-state-title"><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></div>
                                    <div class="empty-state-description"><?php echo isset($lang['collections_page_coming_soon']) ? $lang['collections_page_coming_soon'] : 'صفحة التحصيلات - سيتم إضافتها'; ?></div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo $activeTab === 'returns' ? 'show active' : ''; ?> combined-tab-pane" 
                             id="returns-section" 
                             role="tabpanel" 
                             aria-labelledby="returns-tab">
                            <div id="returns-section-content" class="printable-section">
                                <?php 
                                $returnsModulePath = __DIR__ . '/../modules/sales/new_returns.php';
                                if (file_exists($returnsModulePath)) {
                                    include $returnsModulePath;
                                } else {
                                ?>
                                <div class="empty-state-card">
                                    <div class="empty-state-icon"><i class="bi bi-arrow-return-left"></i></div>
                                    <div class="empty-state-title">المرتجعات</div>
                                    <div class="empty-state-description">صفحة المرتجعات - سيتم إضافتها قريباً</div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: إنشاء تقرير المبيعات -->
                <div class="modal fade" id="generateSalesReportModal" tabindex="-1" aria-labelledby="generateSalesReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateSalesReportModalLabel">
                                    <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير المبيعات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateSalesReportForm">
                                    <div class="mb-3">
                                        <label for="salesReportDateFrom" class="form-label">من تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="salesReportDateFrom" name="date_from" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="salesReportDateTo" class="form-label">إلى تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="salesReportDateTo" name="date_to" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-primary" id="generateSalesReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: إنشاء تقرير التحصيلات -->
                <div class="modal fade" id="generateCollectionsReportModal" tabindex="-1" aria-labelledby="generateCollectionsReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateCollectionsReportModalLabel">
                                    <i class="bi bi-file-earmark-text me-2"></i>إنشاء تقرير التحصيلات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateCollectionsReportForm">
                                    <div class="mb-3">
                                        <label for="collectionsReportDateFrom" class="form-label">من تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="collectionsReportDateFrom" name="date_from" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="collectionsReportDateTo" class="form-label">إلى تاريخ <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="collectionsReportDateTo" name="date_to" required>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-primary" id="generateCollectionsReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: تقرير العميل - المبيعات -->
                <div class="modal fade" id="generateCustomerSalesReportModal" tabindex="-1" aria-labelledby="generateCustomerSalesReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateCustomerSalesReportModalLabel">
                                    <i class="bi bi-person-badge me-2"></i>تقرير العميل - المبيعات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateCustomerSalesReportForm">
                                    <div class="mb-3">
                                        <label for="customerSalesReportCustomerId" class="form-label">العميل <span class="text-danger">*</span></label>
                                        <select class="form-select" id="customerSalesReportCustomerId" name="customer_id" required>
                                            <option value="">اختر العميل</option>
                                            <?php foreach ($reportCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                    <?php if (!empty($customer['phone'])): ?>
                                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-success" id="generateCustomerSalesReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: تقرير العميل - التحصيلات -->
                <div class="modal fade" id="generateCustomerCollectionsReportModal" tabindex="-1" aria-labelledby="generateCustomerCollectionsReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="generateCustomerCollectionsReportModalLabel">
                                    <i class="bi bi-person-badge me-2"></i>تقرير العميل - التحصيلات
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="generateCustomerCollectionsReportForm">
                                    <div class="mb-3">
                                        <label for="customerCollectionsReportCustomerId" class="form-label">العميل <span class="text-danger">*</span></label>
                                        <select class="form-select" id="customerCollectionsReportCustomerId" name="customer_id" required>
                                            <option value="">اختر العميل</option>
                                            <?php foreach ($reportCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                    <?php if (!empty($customer['phone'])): ?>
                                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="button" class="btn btn-success" id="generateCustomerCollectionsReportBtn">
                                    <i class="bi bi-file-earmark-pdf me-2"></i>إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                // استبدل الكود من السطر 755 إلى 1059 بهذا الكود المحسّن:

<script>
// JavaScript لتهيئة التبويبات وإنشاء التقارير
(function() {
    let tabsInitialized = false;
    let reportButtonsInitialized = false;
    
    // تهيئة التبويبات
    function initTabs() {
        const tabButtons = document.querySelectorAll('#salesRecordsTabs button[data-bs-toggle="tab"]');
        if (tabButtons.length === 0) {
            if (!tabsInitialized) {
                setTimeout(initTabs, 100);
            }
            return;
        }
        
        // إذا تم التهيئة مسبقاً، تأكد من أن الأزرار لا تزال تحتوي على event listeners
        if (tabsInitialized) {
            // التحقق من أن الأزرار لديها event listeners
            let hasListeners = false;
            tabButtons.forEach(function(btn) {
                if (btn.hasAttribute('data-tab-initialized')) {
                    hasListeners = true;
                }
            });
            if (hasListeners) {
                return; // تم التهيئة بالفعل
            }
        }
        
        // التحقق من تحميل Bootstrap
        const bs = window.bootstrap || bootstrap;
        const useBootstrap = bs && typeof bs.Tab !== 'undefined';
        
        // إضافة event listener لكل زر (mutually exclusive: إما Bootstrap أو fallback يدوي)
        tabButtons.forEach(function(button) {
            // التحقق من أن الزر لم يتم تهيئته مسبقاً
            if (button.hasAttribute('data-tab-initialized')) {
                return; // تم التهيئة بالفعل
            }
            
            if (useBootstrap) {
                // استخدام Bootstrap Tabs - معالج shown.bs.tab فقط
                button.addEventListener('shown.bs.tab', function(event) {
                    const currentTabButtons = document.querySelectorAll('#salesRecordsTabs button[data-bs-toggle="tab"]');
                    if (event.target && currentTabButtons && currentTabButtons.length > 0) {
                        updateTabState(event.target, currentTabButtons);
                        updateURL(event.target);
                    }
                });
            } else {
                // Fallback يدوي - معالج click فقط
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const targetId = this.getAttribute('data-bs-target');
                    if (!targetId) {
                        console.warn('Tab button missing data-bs-target:', this);
                        return;
                    }
                    
                    // إخفاء جميع المحتويات
                    document.querySelectorAll('.tab-pane').forEach(function(pane) {
                        if (pane) {
                            pane.classList.remove('show', 'active');
                        }
                    });
                    
                    // إظهار المحتوى المطلوب
                    const targetPane = document.querySelector(targetId);
                    if (targetPane) {
                        targetPane.classList.add('show', 'active');
                    } else {
                        console.warn('Tab pane not found:', targetId);
                    }
                    
                    // تحديث حالة التبويبات
                    const currentTabButtons = document.querySelectorAll('#salesRecordsTabs button[data-bs-toggle="tab"]');
                    if (this && currentTabButtons && currentTabButtons.length > 0) {
                        updateTabState(this, currentTabButtons);
                        updateURL(this);
                    }
                });
            }
            
            // وضع علامة أن الزر تم تهيئته
            button.setAttribute('data-tab-initialized', 'true');
        });
        
        // تحديد التبويب النشط بناءً على URL
        const urlParams = new URLSearchParams(window.location.search);
        const section = urlParams.get('section');
        let activeTabId = 'sales-tab';
        
        // التحقق من وجود التبويبات المتاحة في الصفحة الحالية
        const availableTabs = document.querySelectorAll('#salesRecordsTabs button[data-bs-toggle="tab"]');
        const availableTabIds = Array.from(availableTabs).map(btn => btn.id);
        
        if (section === 'collections' && availableTabIds.includes('collections-tab')) {
            activeTabId = 'collections-tab';
        } else if (section === 'returns' && availableTabIds.includes('returns-tab')) {
            activeTabId = 'returns-tab';
        } else if (section === 'exchanges' && availableTabIds.includes('my-exchanges-tab')) {
            activeTabId = 'my-exchanges-tab';
        } else if (section === 'damaged_returns' && availableTabIds.includes('my-damaged-returns-tab')) {
            activeTabId = 'my-damaged-returns-tab';
        } else if (section === 'sales' && availableTabIds.includes('sales-tab')) {
            activeTabId = 'sales-tab';
        }
        
        // تفعيل التبويب النشط
        const activeTabButton = document.getElementById(activeTabId);
        if (activeTabButton && availableTabIds.includes(activeTabId)) {
            const currentActiveTab = document.querySelector('#salesRecordsTabs .nav-link.active');
            if (!currentActiveTab || currentActiveTab.id !== activeTabId) {
                if (useBootstrap) {
                    try {
                        const tab = new bs.Tab(activeTabButton);
                        tab.show();
                    } catch (e) {
                        console.warn('Error showing tab with Bootstrap:', e);
                        activateTabManually(activeTabButton);
                    }
                } else {
                    activateTabManually(activeTabButton);
                }
            }
        }
        
        tabsInitialized = true;
    }
    
    function updateTabState(activeButton, allButtons) {
        if (!activeButton) return;
        
        allButtons.forEach(function(btn) {
            if (btn) {
                btn.classList.remove('active');
                btn.setAttribute('aria-selected', 'false');
            }
        });
        
        if (activeButton) {
            activeButton.classList.add('active');
            activeButton.setAttribute('aria-selected', 'true');
        }
    }
    
    function updateURL(button) {
        if (!button) return;
        
        const targetId = button.getAttribute('data-bs-target');
        let section = 'sales';
        if (targetId === '#collections-section') {
            section = 'collections';
        } else if (targetId === '#returns-section') {
            section = 'returns';
        }
        const url = new URL(window.location);
        url.searchParams.set('section', section);
        window.history.pushState({}, '', url);
    }
    
    function activateTabManually(button) {
        if (!button) return;
        
        const targetId = button.getAttribute('data-bs-target');
        if (!targetId) return;
        
        // إخفاء جميع المحتويات
        document.querySelectorAll('.tab-pane').forEach(function(pane) {
            if (pane) {
                pane.classList.remove('show', 'active');
            }
        });
        
        // إظهار المحتوى المطلوب
        const targetPane = document.querySelector(targetId);
        if (targetPane) {
            targetPane.classList.add('show', 'active');
        }
        
        // تحديث التبويبات
        const tabButtons = document.querySelectorAll('#salesRecordsTabs button[data-bs-toggle="tab"]');
        if (tabButtons && tabButtons.length > 0 && button) {
            updateTabState(button, tabButtons);
        }
    }
    
    function initReportButtons() {
        if (reportButtonsInitialized) return;
        
        const bs = window.bootstrap || bootstrap;
        if (!bs || typeof bs.Modal === 'undefined') {
            setTimeout(initReportButtons, 100);
            return;
        }
        
        const basePath = '<?php echo getBasePath(); ?>';
        
        // معالج إنشاء تقرير المبيعات
        const generateSalesReportBtn = document.getElementById('generateSalesReportBtn');
        if (generateSalesReportBtn) {
            const newBtn = generateSalesReportBtn.cloneNode(true);
            generateSalesReportBtn.parentNode.replaceChild(newBtn, generateSalesReportBtn);
            
            newBtn.addEventListener('click', function() {
                const dateFrom = document.getElementById('salesReportDateFrom').value;
                const dateTo = document.getElementById('salesReportDateTo').value;
                
                if (!dateFrom || !dateTo) {
                    alert('يرجى اختيار الفترة المطلوبة');
                    return;
                }
                
                if (new Date(dateFrom) > new Date(dateTo)) {
                    alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
                    return;
                }
                
                const url = basePath + '/api/generate_sales_report.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
                const reportWindow = window.open(url, 'salesReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateSalesReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير التحصيلات
        const generateCollectionsReportBtn = document.getElementById('generateCollectionsReportBtn');
        if (generateCollectionsReportBtn) {
            const newBtn = generateCollectionsReportBtn.cloneNode(true);
            generateCollectionsReportBtn.parentNode.replaceChild(newBtn, generateCollectionsReportBtn);
            
            newBtn.addEventListener('click', function() {
                const dateFrom = document.getElementById('collectionsReportDateFrom').value;
                const dateTo = document.getElementById('collectionsReportDateTo').value;
                
                if (!dateFrom || !dateTo) {
                    alert('يرجى اختيار الفترة المطلوبة');
                    return;
                }
                
                if (new Date(dateFrom) > new Date(dateTo)) {
                    alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
                    return;
                }
                
                const url = basePath + '/api/generate_collections_report.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
                const reportWindow = window.open(url, 'collectionsReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateCollectionsReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        // معالج إنشاء تقرير العميل - المبيعات
        const generateCustomerSalesReportBtn = document.getElementById('generateCustomerSalesReportBtn');
        if (generateCustomerSalesReportBtn) {
            const newBtn = generateCustomerSalesReportBtn.cloneNode(true);
            generateCustomerSalesReportBtn.parentNode.replaceChild(newBtn, generateCustomerSalesReportBtn);
            
            newBtn.addEventListener('click', function() {
                const customerId = document.getElementById('customerSalesReportCustomerId').value;
                
                if (!customerId) {
                    alert('يرجى اختيار العميل');
                    return;
                }
                
                const url = basePath + '/api/generate_customer_sales_report.php?customer_id=' + encodeURIComponent(customerId);
                const reportWindow = window.open(url, 'customerSalesReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateCustomerSalesReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }

        // معالج إنشاء تقرير العميل - التحصيلات
        const generateCustomerCollectionsReportBtn = document.getElementById('generateCustomerCollectionsReportBtn');
        if (generateCustomerCollectionsReportBtn) {
            const newBtn = generateCustomerCollectionsReportBtn.cloneNode(true);
            generateCustomerCollectionsReportBtn.parentNode.replaceChild(newBtn, generateCustomerCollectionsReportBtn);
            
            newBtn.addEventListener('click', function() {
                const customerId = document.getElementById('customerCollectionsReportCustomerId').value;
                
                if (!customerId) {
                    alert('يرجى اختيار العميل');
                    return;
                }
                
                const url = basePath + '/api/generate_customer_collections_report.php?customer_id=' + encodeURIComponent(customerId);
                const reportWindow = window.open(url, 'customerCollectionsReport', 'width=1000,height=800,scrollbars=yes,resizable=yes');
                
                if (reportWindow) {
                    const modalElement = document.getElementById('generateCustomerCollectionsReportModal');
                    if (modalElement) {
                        const modal = bs.Modal.getInstance(modalElement);
                        if (modal) modal.hide();
                    }
                } else {
                    alert('يرجى السماح للموقع بفتح النوافذ المنبثقة');
                }
            });
        }
        
        reportButtonsInitialized = true;
    }
    
    // تهيئة كل شيء
    function initAll() {
        initTabs();
        initReportButtons();
    }
    
    // تهيئة واحدة فقط - استخدام requestAnimationFrame للتأكد من جاهزية DOM
    function scheduleInit() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                requestAnimationFrame(function() {
                    initAll();
                });
            }, { once: true });
        } else {
            // DOM جاهز بالفعل
            requestAnimationFrame(function() {
                initAll();
            });
        }
    }
    
    // استدعاء واحد فقط للتهيئة
    scheduleInit();
    
    // إعادة محاولة واحدة فقط بعد تحميل الصفحة بالكامل (fallback)
    window.addEventListener('load', function() {
        if (!tabsInitialized || !reportButtonsInitialized) {
            requestAnimationFrame(function() {
                initAll();
            });
        }
    }, { once: true });
})();
</script>
                
            <?php elseif ($page === 'orders'): ?>
                <!-- صفحة طلبات العملاء -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customer_orders.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'payment_schedules'): ?>
                <!-- صفحة الجداول الزمنية للتحصيل -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/payment_schedules.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'pos'): ?>
                <!-- صفحة نقطة البيع للمندوب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/pos.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'vehicle_inventory'): ?>
                <!-- صفحة مخازن سيارات المندوبين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/vehicle_inventory.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
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
                
            <?php elseif ($page === 'exchanges'): ?>
                <!-- صفحة الاستبدال -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/exchanges.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'cash_register'): ?>
                <!-- صفحة خزنة المندوب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/cash_register.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                ?>
                <div class="empty-state-card">
                    <div class="empty-state-icon"><i class="bi bi-cash-stack"></i></div>
                    <div class="empty-state-title">خزنة المندوب</div>
                    <div class="empty-state-description">صفحة خزنة المندوب - غير متاحة حالياً</div>
                </div>
                <?php } ?>
                
            <?php elseif ($page === 'attendance'): ?>
                <!-- صفحة تسجيل الحضور -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/attendance.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                ?>
                <div class="empty-state-card">
                    <div class="empty-state-icon"><i class="bi bi-clock-history"></i></div>
                    <div class="empty-state-title">تسجيل الحضور</div>
                    <div class="empty-state-description">صفحة تسجيل الحضور - سيتم إضافتها</div>
                </div>
                <?php } ?>
                
            <?php elseif ($page === 'my_salary'): ?>
                <!-- صفحة مرتب المستخدم -->
                <?php 
                $modulePath = __DIR__ . '/../modules/user/my_salary.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'batch_reader'): ?>
                <!-- صفحة قارئ أرقام التشغيلات -->
                <div class="container-fluid p-0" style="height: 100vh; overflow: hidden;">
                    <iframe src="<?php echo getRelativeUrl('reader/index.php'); ?>" 
                            style="width: 100%; height: 100%; border: none; display: block;"></iframe>
                </div>
                
            <?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

<script src="<?php echo ASSETS_URL; ?>js/reports.js"></script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>