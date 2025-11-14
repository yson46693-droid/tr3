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
require_once __DIR__ . '/../includes/path_helper.php';

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

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];

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
        const printableHtml = buildPrintableSection(section, sanitizedTitle || 'تقرير قابل للطباعة');
        const headLinks = stylesheets
            .map(function (href) {
                return '<link rel="stylesheet" href="' + href + '" media="all">';
            })
            .join('');
        const documentTitle = escapeHtmlForPrint(sanitizedTitle || 'تقرير قابل للطباعة');
        const metaInfo = escapeHtmlForPrint('تم الإنشاء في: ' + generatedAt);
        const printableDocument = '<!DOCTYPE html>'
            + '<html lang="' + pageLang + '" dir="' + pageDirection + '">'
            + '<head>'
            + '<meta charset="UTF-8">'
            + '<title>' + documentTitle + '</title>'
            + headLinks
            + '<style>'
            + 'body{background:#fff;color:#000;padding:32px;font-family:"Segoe UI",Tahoma,sans-serif;}'
            + '.print-header{border-bottom:1px solid #dee2e6;margin-bottom:24px;padding-bottom:12px;}'
            + '.print-header h1{font-size:1.6rem;margin-bottom:0;font-weight:700;}'
            + '.print-meta{font-size:0.9rem;color:#6c757d;}'
            + '.print-section{display:flex;flex-direction:column;gap:20px;}'
            + '.print-block{border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#f9fafb;}'
            + '.print-block-title{font-weight:700;margin-bottom:12px;font-size:1.05rem;}'
            + '.print-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}'
            + '.print-summary-item{display:flex;flex-direction:column;gap:4px;padding:12px;border-radius:8px;background:#fff;border:1px solid #e5e7eb;}'
            + '.print-summary-label{font-size:0.85rem;color:#6c757d;}'
            + '.print-summary-value{font-weight:600;font-size:1rem;}'
            + '.print-stats-inline{display:flex;flex-wrap:wrap;gap:16px;}'
            + '.print-stat-card{flex:1 1 160px;padding:14px;border-radius:10px;background:#fff;border:1px solid #e5e7eb;display:flex;flex-direction:column;gap:4px;}'
            + '.print-stat-label{font-size:0.85rem;color:#6c757d;}'
            + '.print-stat-value{font-size:1.25rem;font-weight:700;color:#0d6efd;}'
            + '.print-content{display:flex;flex-direction:column;gap:18px;}'
            + '.print-content table{width:100%;border-collapse:collapse;}'
            + '.print-content table thead th{background:#0d6efd;color:#fff;padding:10px;border:1px solid #e5e7eb;font-weight:600;}'
            + '.print-content table tbody td{padding:10px;border:1px solid #e5e7eb;font-size:0.95rem;}'
            + '.print-content table tbody tr:nth-child(even){background:#f8f9fa;}'
            + '.print-content .btn,.print-content .form-control,.print-content select,.print-content input{display:none!important;}'
            + '.print-placeholder{padding:16px;border-radius:8px;background:#fff;border:1px dashed #ced4da;color:#6c757d;text-align:center;}'
            + '@media print{.print-controls{display:none!important;}}'
            + '</style>'
            + '</head>'
            + '<body>'
            + '<div class="print-header text-center">'
            + '<h1>' + documentTitle + '</h1>'
            + '<div class="print-meta">' + metaInfo + '</div>'
            + '</div>'
            + '<div class="print-content">' + printableHtml + '</div>'
            + '<script>window.addEventListener("load",function(){window.focus();window.print();});<' + '/script>'
            + '</body>'
            + '</html>';

        if (typeof window.openHtmlInAppModal === 'function') {
            const opener = document.activeElement instanceof Element ? document.activeElement : null;
            window.openHtmlInAppModal(printableDocument, { opener: opener });
            return;
        }

        const printWindow = window.open('', '_blank', 'width=1024,height=768,resizable=yes,scrollbars=yes');
        if (!printWindow) {
            alert('يرجى السماح بالنوافذ المنبثقة لإنشاء التقرير');
            return;
        }

        try {
            printWindow.opener = null;
        } catch (error) {
            console.warn('Unable to clear window opener:', error);
        }

        const doc = printWindow.document;
        doc.open();
        doc.write(printableDocument);
        doc.close();
                    }

                    function buildPrintableSection(section, reportTitle) {
                        const clone = section.cloneNode(true);

                        // إزالة عناصر لا نحتاجها في التقرير
                        clone.querySelectorAll('.combined-actions, .print-controls, [data-print-hide="true"]').forEach(function (el) {
                            el.remove();
                        });
                        clone.querySelectorAll('script').forEach(function (el) {
                            el.remove();
                        });

                        // جمع بيانات الفلاتر من العنصر الأصلي (قبل التعديل)
                        const filtersSummary = collectFilterSummaries(section);
                        const filtersBlock = filtersSummary ? renderFiltersBlock(filtersSummary) : null;

                        // جمع إحصائيات بسيطة من الجداول
                        const statsSummary = collectTableStats(section);
                        const statsBlock = statsSummary ? renderStatsBlock(statsSummary, reportTitle) : null;

                        // إزالة النماذج من النسخة للطباعة
                        clone.querySelectorAll('form').forEach(function (form) {
                            form.remove();
                        });

                        // تكييف الجداول مع الطباعة
                        clone.querySelectorAll('table').forEach(function (table) {
                            table.classList.add('print-table');
                            table.removeAttribute('style');
                        });

                        const container = document.createElement('div');
                        container.className = 'print-section';

                        if (filtersBlock) {
                            container.appendChild(filtersBlock);
                        }
                        if (statsBlock) {
                            container.appendChild(statsBlock);
                        }

                        const bodyWrapper = document.createElement('div');
                        while (clone.firstChild) {
                            bodyWrapper.appendChild(clone.firstChild);
                        }
                        container.appendChild(bodyWrapper);

                        return container.innerHTML;
                    }

                    function collectFilterSummaries(section) {
                        const forms = Array.from(section.querySelectorAll('form'));
                        if (!forms.length) {
                            return null;
                        }

                        const groups = [];

                        forms.forEach(function (form, index) {
                            if (form.matches('[data-print-ignore="true"]')) {
                                return;
                            }

                            const items = [];
                            Array.from(form.elements).forEach(function (element) {
                                if (!shouldIncludeInPrintSummary(element)) {
                                    return;
                                }

                                const label = findFieldLabel(form, element);
                                const value = extractFieldValue(element);
                                if (!label || !value) {
                                    return;
                                }

                                items.push({
                                    label: label,
                                    value: value
                                });
                            });

                            if (items.length) {
                                groups.push({
                                    title: form.getAttribute('data-print-title') || (index === 0 ? 'الفلاتر المطبقة' : 'إعدادات إضافية'),
                                    items: items
                                });
                            }
                        });

                        return groups.length ? groups : null;
                    }

                    function shouldIncludeInPrintSummary(element) {
                        if (!element || element.disabled) {
                            return false;
                        }
                        if (element.type === 'hidden' || element.type === 'submit' || element.type === 'button' || element.type === 'reset') {
                            return false;
                        }
                        if (element.closest('[data-print-ignore-field="true"]')) {
                            return false;
                        }
                        return true;
                    }

                    function findFieldLabel(form, element) {
                        const id = element.id;
                        if (id) {
                            const label = form.querySelector('label[for="' + CSS.escape(id) + '"]');
                            if (label) {
                                return label.textContent.trim();
                            }
                        }

                        let container = element.closest('.col-12, .col-sm-6, .col-md-3, .col-md-4, .col-md-6, .col-lg-3, .col-lg-4, .mb-3');
                        if (!container) {
                            container = element.parentElement;
                        }
                        if (container) {
                            const labelEl = container.querySelector('.form-label');
                            if (labelEl) {
                                return labelEl.textContent.trim();
                            }
                        }

                        return (element.getAttribute('aria-label') || element.name || '').replace(/[_-]+/g, ' ').trim();
                    }

                    function extractFieldValue(element) {
                        if (element.tagName === 'SELECT') {
                            const selectedOptions = Array.from(element.selectedOptions);
                            const text = selectedOptions.map(function (option) {
                                return option.text.trim();
                            }).filter(Boolean).join('، ');
                            return text || 'غير محدد';
                        }

                        if (element.type === 'checkbox' || element.type === 'radio') {
                            return element.checked ? 'نعم' : 'لا';
                        }

                        const value = element.value ? element.value.trim() : '';
                        return value || 'غير محدد';
                    }

                    function renderFiltersBlock(groups) {
                        const block = document.createElement('div');
                        block.className = 'print-block print-filters-block';

                        let html = '';
                        groups.forEach(function (group, index) {
                            html += '<div class="print-block-title">' + escapeHtmlForPrint(group.title || 'الفلاتر') + '</div>';
                            html += '<div class="print-summary-grid">';
                            group.items.forEach(function (item) {
                                html += '<div class="print-summary-item">'
                                    + '<span class="print-summary-label">' + escapeHtmlForPrint(item.label) + '</span>'
                                    + '<span class="print-summary-value">' + escapeHtmlForPrint(item.value) + '</span>'
                                    + '</div>';
                            });
                            html += '</div>';
                            if (index !== groups.length - 1) {
                                html += '<div style="height:8px;"></div>';
                            }
                        });

                        block.innerHTML = html;
                        return block;
                    }

                    function collectTableStats(section) {
                        const tables = Array.from(section.querySelectorAll('table'));
                        if (!tables.length) {
                            return null;
                        }

                        const stats = [];

                        tables.forEach(function (table) {
                            const tbodyRows = Array.from(table.querySelectorAll('tbody tr'));
                            if (!tbodyRows.length) {
                                return;
                            }
                            let records = 0;
                            tbodyRows.forEach(function (row) {
                                const cells = row.querySelectorAll('td');
                                if (cells.length <= 1) {
                                    return;
                                }
                                records += 1;
                            });

                            const caption = table.querySelector('caption');
                            const title = caption ? caption.textContent.trim() : (table.getAttribute('data-print-title') || 'ملخص الجدول');

                            stats.push({
                                title: title,
                                records: records
                            });
                        });

                        return stats.length ? stats : null;
                    }

                    function renderStatsBlock(stats, reportTitle) {
                        const block = document.createElement('div');
                        block.className = 'print-block print-stats-block';

                        let html = '<div class="print-block-title">نظرة سريعة على البيانات</div>';
                        html += '<div class="print-stats-inline">';
                        stats.forEach(function (stat) {
                            html += '<div class="print-stat-card">'
                                + '<span class="print-stat-label">' + escapeHtmlForPrint(stat.title || reportTitle || 'البيانات') + '</span>'
                                + '<span class="print-stat-value">' + escapeHtmlForPrint(String(stat.records || 0)) + '</span>'
                                + '</div>';
                        });
                        html += '</div>';

                        block.innerHTML = html;
                        return block;
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

