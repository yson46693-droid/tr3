<?php
/**
 * لوحة التحكم للمحاسب
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/table_styles.php';

requireRole('accountant');

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

if ($page === 'pos') {
    $page = 'dashboard';
}

// معالجة AJAX قبل أي إخراج HTML - خاصة لصفحة مخزن أدوات التعبئة
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    // تحميل ملف packaging_warehouse.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب';
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-speedometer2"></i><?php echo isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب'; ?></h2>
                </div>
                
                <!-- لوحة مالية مصغرة -->
                <div class="cards-grid">
                    <?php
                    $cashBalance = $db->queryOne(
                        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) as balance
                         FROM financial_transactions WHERE status = 'approved'"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo (isset($lang) && isset($lang['cash_balance'])) ? $lang['cash_balance'] : 'رصيد الخزينة'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($cashBalance['balance'] ?? 0); ?></div>
                    </div>
                    
                    <?php
                    $expenses = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'expense' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon red">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['expenses']) ? $lang['expenses'] : 'المصروفات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($expenses['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                    
                    <?php
                    // التحقق من وجود عمود status في جدول collections
                    $collectionsQuery = "SELECT COALESCE(SUM(amount), 0) as total FROM collections WHERE 1=1";
                    
                    // محاولة استخدام status إذا كان موجوداً
                    try {
                        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                        if ($conn) {
                            $result = mysqli_query($conn, "SHOW COLUMNS FROM collections LIKE 'status'");
                            if ($result && mysqli_num_rows($result) > 0) {
                                $collectionsQuery .= " AND status = 'approved'";
                            }
                            mysqli_close($conn);
                        }
                    } catch (Exception $e) {
                        // إذا لم يكن status موجوداً، تجاهل
                    }
                    
                    $collectionsQuery .= " AND MONTH(date) = MONTH(NOW()) AND YEAR(date) = YEAR(NOW())";
                    $collections = $db->queryOne($collectionsQuery);
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($collections['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                </div>
                
                <!-- آخر المعاملات -->
                <?php
                // Pagination
                $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                $perPageTrans = 10;
                $offsetTrans = ($pageNum - 1) * $perPageTrans;
                
                $totalTrans = $db->queryOne("SELECT COUNT(*) as total FROM financial_transactions")['total'] ?? 0;
                $totalPagesTrans = ceil($totalTrans / $perPageTrans);
                
                $transactions = $db->query(
                    "SELECT * FROM financial_transactions 
                     ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$perPageTrans, $offsetTrans]
                );
                ?>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>المعاملات المالية (<?php echo $totalTrans; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table align-middle">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>المبلغ</th>
                                        <th>الوصف</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">لا توجد معاملات</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $trans): ?>
                                            <tr>
                                                <td><?php echo $trans['type']; ?></td>
                                                <td><?php echo formatCurrency($trans['amount']); ?></td>
                                                <td><?php echo htmlspecialchars($trans['description']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $trans['status'] === 'approved' ? 'success' : 
                                                            ($trans['status'] === 'rejected' ? 'danger' : 'warning'); 
                                                    ?>">
                                                        <?php echo $lang[$trans['status']] ?? $trans['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatDateTime($trans['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPagesTrans > 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?p=<?php echo $pageNum - 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                
                                <?php
                                $startPageTrans = max(1, $pageNum - 2);
                                $endPageTrans = min($totalPagesTrans, $pageNum + 2);
                                
                                if ($startPageTrans > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?p=1">1</a></li>
                                    <?php if ($startPageTrans > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPageTrans; $i <= $endPageTrans; $i++): ?>
                                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                        <a class="page-link" href="?p=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPageTrans < $totalPagesTrans): ?>
                                    <?php if ($endPageTrans < $totalPagesTrans - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?p=<?php echo $totalPagesTrans; ?>"><?php echo $totalPagesTrans; ?></a></li>
                                <?php endif; ?>
                                
                                <li class="page-item <?php echo $pageNum >= $totalPagesTrans ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?p=<?php echo $pageNum + 1; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($page === 'financial'): ?>
                <!-- صفحة المالية -->
                <div class="page-header mb-4">
                    <h2><i class="bi bi-wallet2 me-2"></i><?php echo isset($lang['menu_financial']) ? $lang['menu_financial'] : 'المالية'; ?></h2>
                </div>
                
                <!-- لوحة مالية -->
                <div class="cards-grid">
                    <?php
                    $cashBalance = $db->queryOne(
                        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) as balance
                         FROM financial_transactions WHERE status = 'approved'"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo (isset($lang) && isset($lang['cash_balance'])) ? $lang['cash_balance'] : 'رصيد الخزينة'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($cashBalance['balance'] ?? 0); ?></div>
                    </div>
                    
                    <?php
                    $expenses = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'expense' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon red">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['expenses']) ? $lang['expenses'] : 'المصروفات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($expenses['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                    
                    <?php
                    // التحقق من وجود عمود status في جدول collections
                    $collectionsQuery = "SELECT COALESCE(SUM(amount), 0) as total FROM collections WHERE 1=1";
                    
                    // محاولة استخدام status إذا كان موجوداً
                    try {
                        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                        if ($conn) {
                            $result = mysqli_query($conn, "SHOW COLUMNS FROM collections LIKE 'status'");
                            if ($result && mysqli_num_rows($result) > 0) {
                                $collectionsQuery .= " AND status = 'approved'";
                            }
                            mysqli_close($conn);
                        }
                    } catch (Exception $e) {
                        // إذا لم يكن status موجوداً، تجاهل
                    }
                    
                    $collectionsQuery .= " AND MONTH(date) = MONTH(NOW()) AND YEAR(date) = YEAR(NOW())";
                    $collections = $db->queryOne($collectionsQuery);
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($collections['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                    
                    <?php
                    $income = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'income' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['income']) ? $lang['income'] : 'الإيرادات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($income['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                </div>
                
                <!-- آخر المعاملات -->
                <?php
                // Pagination
                $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                $perPageTrans = 10;
                $offsetTrans = ($pageNum - 1) * $perPageTrans;
                
                $totalTrans = $db->queryOne("SELECT COUNT(*) as total FROM financial_transactions")['total'] ?? 0;
                $totalPagesTrans = ceil($totalTrans / $perPageTrans);
                
                $transactions = $db->query(
                    "SELECT * FROM financial_transactions 
                     ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$perPageTrans, $offsetTrans]
                );
                ?>
                
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>المعاملات المالية (<?php echo $totalTrans; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table align-middle">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>المبلغ</th>
                                        <th>الوصف</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">لا توجد معاملات</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $trans): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-<?php echo $trans['type'] === 'income' ? 'success' : 'danger'; ?>">
                                                        <?php echo $trans['type'] === 'income' ? (isset($lang['income']) ? $lang['income'] : 'إيراد') : (isset($lang['expense']) ? $lang['expense'] : 'مصروف'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatCurrency($trans['amount']); ?></td>
                                                <td><?php echo htmlspecialchars($trans['description'] ?? '-'); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $trans['status'] === 'approved' ? 'success' : 
                                                            ($trans['status'] === 'rejected' ? 'danger' : 'warning'); 
                                                    ?>">
                                                        <?php echo $lang[$trans['status']] ?? $trans['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatDateTime($trans['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPagesTrans > 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=financial&p=<?php echo $pageNum - 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                
                                <?php
                                $startPageTrans = max(1, $pageNum - 2);
                                $endPageTrans = min($totalPagesTrans, $pageNum + 2);
                                
                                if ($startPageTrans > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?page=financial&p=1">1</a></li>
                                    <?php if ($startPageTrans > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPageTrans; $i <= $endPageTrans; $i++): ?>
                                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=financial&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPageTrans < $totalPagesTrans): ?>
                                    <?php if ($endPageTrans < $totalPagesTrans - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?page=financial&p=<?php echo $totalPagesTrans; ?>"><?php echo $totalPagesTrans; ?></a></li>
                                <?php endif; ?>
                                
                                <li class="page-item <?php echo $pageNum >= $totalPagesTrans ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=financial&p=<?php echo $pageNum + 1; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($page === 'suppliers'): ?>
                <!-- صفحة الموردين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/suppliers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'orders'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customer_orders.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant orders module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة طلبات العملاء: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات العملاء غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'inventory'): ?>
                <!-- صفحة المخزون -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/inventory.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'inventory_movements'): ?>
                <!-- صفحة حركات المخزون -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/inventory_movements.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'invoices'): ?>
                <!-- صفحة الفواتير -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/invoices.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'collections'): ?>
                <!-- صفحة التحصيلات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/collections.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<h2><i class="bi bi-cash-coin me-2"></i>' . (isset($lang['collections']) ? $lang['collections'] : 'التحصيلات') . '</h2>';
                    echo '<div class="card shadow-sm"><div class="card-body"><p>صفحة التحصيلات - سيتم إضافتها</p></div></div>';
                }
                ?>
                
            <?php elseif ($page === 'salaries'): ?>
                <!-- صفحة الرواتب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/salaries.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    include __DIR__ . '/../modules/accountant/salaries.php';
                }
                ?>
                
            <?php elseif ($page === 'attendance'): ?>
                <!-- صفحة الحضور -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'attendance_management'): ?>
                <!-- صفحة متابعة الحضور مع الإحصائيات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance_management.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'advance_requests'): ?>
                <!-- صفحة طلبات السلفة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/advance_requests.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
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
                
            <?php elseif ($page === 'reports'): ?>
                <!-- صفحة التقارير -->
                <h2><i class="bi bi-file-earmark-text me-2"></i><?php echo (isset($lang) && isset($lang['reports'])) ? $lang['reports'] : 'التقارير'; ?></h2>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <button class="btn btn-primary" onclick="generatePDFReport('financial', {}, event)">
                            <i class="bi bi-file-pdf me-2"></i>توليد تقرير PDF
                        </button>
                        <button class="btn btn-success ms-2" onclick="generateExcelReport('financial', {}, event)">
                            <i class="bi bi-file-excel me-2"></i>توليد تقرير Excel
                        </button>
                    </div>
                </div>
            <?php endif; ?>

<script>
    // تمرير بيانات المستخدم للـ JavaScript
    window.currentUser = {
        id: <?php echo $currentUser['id']; ?>,
        role: '<?php echo htmlspecialchars($currentUser['role']); ?>'
    };
</script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?php echo ASSETS_URL; ?>js/reports.js"></script>

