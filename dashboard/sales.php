<?php
/**
 * لوحة التحكم للمندوب/المبيعات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';

requireRole('sales');

$currentUser = getCurrentUser();
$db = db();
$pageParam = $_GET['page'] ?? 'dashboard';
$page = $pageParam;
$activeCombinedTab = 'sales';

// تحديد التبويب النشط بناءً على الطلب الأصلي
if ($pageParam === 'collections') {
    $activeCombinedTab = 'collections';
}
if ($pageParam === 'sales_collections') {
    $sectionParam = $_GET['section'] ?? '';
    if ($sectionParam === 'collections') {
        $activeCombinedTab = 'collections';
    }
}

// توحيد مسار صفحات المبيعات والتحصيلات تحت صفحة واحدة
if (in_array($pageParam, ['sales', 'collections', 'sales_collections'], true)) {
    $page = 'sales_collections';
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['sales_dashboard']) ? $lang['sales_dashboard'] : 'لوحة المبيعات';
if ($page === 'sales_collections') {
    $pageTitle = isset($lang['sales_and_collections']) ? $lang['sales_and_collections'] : 'مبيعات و تحصيلات';
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
                        $todaySales = $db->queryOne(
                            "SELECT COALESCE(SUM(total), 0) as total 
                             FROM sales 
                             WHERE DATE(date) = CURDATE()"
                        );
                        
                        $monthSales = $db->queryOne(
                            "SELECT COALESCE(SUM(total), 0) as total 
                             FROM sales 
                             WHERE MONTH(date) = MONTH(NOW()) AND YEAR(date) = YEAR(NOW())"
                        );
                    } else {
                        $todaySales = ['total' => 0];
                        $monthSales = ['total' => 0];
                    }
                    
                    $customersCount = ['count' => 0];
                    $salesUserId = (int) ($currentUser['id'] ?? 0);
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
                        $salesUrl = rtrim($basePath, '/') . '/dashboard/sales.php?page=sales_collections';
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
                                    "SELECT s.*, c.name as customer_name, p.name as product_name 
                                     FROM sales s 
                                     LEFT JOIN customers c ON s.customer_id = c.id 
                                     LEFT JOIN products p ON s.product_id = p.id 
                                     WHERE s.salesperson_id = ? 
                                     ORDER BY s.created_at DESC 
                                     LIMIT 10",
                                    [$currentUser['id']]
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
                                    <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
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
                
            <?php elseif ($page === 'sales_collections'): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-diagram-3"></i><?php echo isset($lang['sales_and_collections']) ? $lang['sales_and_collections'] : 'مبيعات و تحصيلات'; ?></h2>
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
                        }
                        .combined-tabs .nav-link:not(.active) {
                            background-color: rgba(13, 110, 253, 0.08);
                            color: inherit;
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

                    <ul class="nav nav-pills combined-tabs mb-4 flex-column flex-sm-row gap-2" id="salesCollectionsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="sales-tab" data-bs-toggle="pill" data-bs-target="#sales-section" type="button" role="tab" aria-controls="sales-section" aria-selected="true">
                                <i class="bi bi-receipt"></i>
                                <span><?php echo isset($lang['sales']) ? $lang['sales'] : 'المبيعات'; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="collections-tab" data-bs-toggle="pill" data-bs-target="#collections-section" type="button" role="tab" aria-controls="collections-section" aria-selected="false">
                                <i class="bi bi-cash-coin"></i>
                                <span><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content combined-tab-content" id="salesCollectionsTabContent">
                        <div class="tab-pane fade show active combined-tab-pane" id="sales-section" role="tabpanel" aria-labelledby="sales-tab">
                             <div class="combined-actions">
                                 <button type="button"
                                         class="btn btn-outline-primary"
                                         data-report-target="sales-section-content"
                                         data-report-title="<?php echo htmlspecialchars(isset($lang['sales_report']) ? $lang['sales_report'] : 'تقرير المبيعات', ENT_QUOTES, 'UTF-8'); ?>">
                                     <i class="bi bi-printer"></i>
                                     <span><?php echo isset($lang['print_ready_report']) ? $lang['print_ready_report'] : 'إنشاء تقرير جاهز للطباعة'; ?></span>
                                 </button>
                             </div>
                             <div id="sales-section-content" class="printable-section">
                            <?php 
                            $salesModulePath = __DIR__ . '/../modules/sales/sales.php';
                            if (file_exists($salesModulePath)) {
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

                        <div class="tab-pane fade combined-tab-pane" id="collections-section" role="tabpanel" aria-labelledby="collections-tab">
                             <div class="combined-actions">
                                 <button type="button"
                                         class="btn btn-outline-success"
                                         data-report-target="collections-section-content"
                                         data-report-title="<?php echo htmlspecialchars(isset($lang['collections_report']) ? $lang['collections_report'] : 'تقرير التحصيلات', ENT_QUOTES, 'UTF-8'); ?>">
                                     <i class="bi bi-printer"></i>
                                     <span><?php echo isset($lang['print_ready_report']) ? $lang['print_ready_report'] : 'إنشاء تقرير جاهز للطباعة'; ?></span>
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
                    </div>
                </div>
                <script>
                    (function () {
                        const assetsBaseUrl = '<?php echo rtrim(ASSETS_URL, '/'); ?>';

                        function initCombinedTabs() {
                            const defaultTab = '<?php echo $activeCombinedTab === 'collections' ? 'collections' : 'sales'; ?>';
                            if (defaultTab === 'collections') {
                                const tabTrigger = document.getElementById('collections-tab');
                                if (tabTrigger && window.bootstrap && typeof window.bootstrap.Tab === 'function') {
                                    const tab = new bootstrap.Tab(tabTrigger);
                                    tab.show();
                                }
                            }
                        }

                        function handlePrintableButtons() {
                            const printableButtons = document.querySelectorAll('[data-report-target]');
                            if (!printableButtons.length) {
                                return;
                            }

                            printableButtons.forEach(function (button) {
                                button.addEventListener('click', function () {
                                    const targetId = this.getAttribute('data-report-target');
                                    const reportTitle = this.getAttribute('data-report-title') || '';
                                    openPrintableReport(targetId, reportTitle, assetsBaseUrl);
                                }, { once: false });
                            });
                        }

                        function initPrintableReports() {
                            initCombinedTabs();
                            handlePrintableButtons();
                        }

                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', initPrintableReports);
                        } else {
                            initPrintableReports();
                        }

                        window.openPrintableReport = openPrintableReport;
                    })();

                    function openPrintableReport(targetId, reportTitle, assetsBaseUrl) {
                        if (!targetId) {
                            console.warn('Missing target for printable report.');
                            return;
                        }

                        const section = document.getElementById(targetId);
                        if (!section) {
                            console.warn('Printable section not found:', targetId);
                            return;
                        }

                        const printWindow = window.open('', '_blank', 'width=1024,height=768,resizable=yes,scrollbars=yes');
                        if (!printWindow) {
                            alert('يرجى السماح بالنوافذ المنبثقة لإنشاء التقرير');
                            return;
                        }

                        try {
                            // حماية من هجمات reverse tabnabbing مع الحفاظ على إمكانية الكتابة في بعض المتصفحات
                            printWindow.opener = null;
                        } catch (error) {
                            console.warn('Unable to clear window opener:', error);
                        }

                        const doc = printWindow.document;
                        const pageDirection = document.documentElement.getAttribute('dir') || 'rtl';
                        const pageLang = document.documentElement.getAttribute('lang') || 'ar';

                        const sanitizedTitle = typeof reportTitle === 'string' ? reportTitle : '';
                        const generatedAt = new Date().toLocaleString('ar-EG', { hour12: false });
                        const stylesheets = [
                            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
                            assetsBaseUrl + '/css/homeline-dashboard.css',
                            assetsBaseUrl + '/css/tables.css',
                            assetsBaseUrl + '/css/cards.css'
                        ];

                        doc.open();
                        doc.write('<!DOCTYPE html><html lang="' + pageLang + '" dir="' + pageDirection + '"><head><meta charset="UTF-8">');
                        doc.write('<title>' + escapeHtmlForPrint(sanitizedTitle || 'تقرير قابل للطباعة') + '</title>');
                        stylesheets.forEach(function (href) {
                            doc.write('<link rel="stylesheet" href="' + href + '" media="all">');
                        });
                        doc.write('<style>body{background:#fff;color:#000;padding:24px;font-family:\'Segoe UI\',Tahoma,sans-serif;} .print-header{border-bottom:1px solid #dee2e6;margin-bottom:24px;padding-bottom:12px;} .print-header h1{font-size:1.5rem;margin-bottom:0;} .print-meta{font-size:0.9rem;color:#6c757d;} @media print{.print-controls{display:none!important;}}</style>');
                        doc.write('</head><body>');
                        doc.write('<div class="print-header text-center">');
                        doc.write('<h1>' + escapeHtmlForPrint(sanitizedTitle || 'تقرير قابل للطباعة') + '</h1>');
                        doc.write('<div class="print-meta">' + escapeHtmlForPrint('تم الإنشاء في: ' + generatedAt) + '</div>');
                        doc.write('</div>');
                        doc.write('<div class="print-content">' + section.innerHTML + '</div>');
                        doc.write('<script>window.addEventListener("load",function(){window.focus();window.print();});<' + '/script>');
                        doc.write('</body></html>');
                        doc.close();
                    }

                    function escapeHtmlForPrint(value) {
                        if (typeof value !== 'string') {
                            return '';
                        }
                        return value
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }
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
                
            <?php elseif ($page === 'returns'): ?>
                <!-- صفحة المرتجعات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/returns.php';
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
                
            <?php elseif ($page === 'my_salary'): ?>
                <!-- صفحة مرتب المستخدم -->
                <?php 
                $modulePath = __DIR__ . '/../modules/user/my_salary.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
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

