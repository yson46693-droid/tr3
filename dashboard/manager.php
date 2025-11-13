<?php
/**
 * لوحة التحكم للمدير
 */

define('ACCESS_ALLOWED', true);
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
$page = $_GET['page'] ?? 'overview';

// معالجة AJAX قبل أي إخراج HTML - خاصة لصفحة مخزن أدوات التعبئة
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && isset($_GET['material_id'])) {
    // تحميل ملف packaging_warehouse.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];
require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = $translations;
$pageTitle = isset($lang['manager_dashboard']) ? $lang['manager_dashboard'] : 'لوحة المدير';
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
                        'label' => 'مخزن المنتجات',
                        'icon' => 'bi-boxes',
                        'url' => getRelativeUrl('dashboard/manager.php?page=final_products')
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
                        'label' => 'العملاء',
                        'icon' => 'bi-people',
                        'url' => getRelativeUrl('dashboard/manager.php?page=customers')
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
                        <div class="row g-3">
                            <?php foreach ($quickLinks as $shortcut): ?>
                                <div class="col-md-4 col-lg-3 col-sm-6">
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

                <!-- ملخص الأنشطة السريع -->
                <div class="analytics-card mb-4">
                    <div class="analytics-card-header">
                        <h3 class="analytics-card-title"><i class="bi bi-activity me-2"></i>ملخص الأنشطة السريع</h3>
                        <div>
                            <button class="btn btn-sm btn-link" data-bs-toggle="tooltip" title="معلومات">
                                <i class="bi bi-info-circle"></i>
                            </button>
                            <button class="btn btn-sm btn-link" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                        </div>
                    </div>
                    <div class="analytics-card-content">
                        <div class="cards-grid">
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <div class="stat-card-icon orange">
                                        <i class="bi bi-hourglass-split"></i>
                                    </div>
                                </div>
                                <div class="stat-card-title">موافقات معلقة</div>
                                <div class="stat-card-value"><?php echo $activitySummary['pending_approvals'] ?? 0; ?></div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <div class="stat-card-icon red">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </div>
                                </div>
                                <div class="stat-card-title">منتجات منخفضة المخزون</div>
                                <div class="stat-card-value"><?php echo $activitySummary['low_stock_products'] ?? 0; ?></div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <div class="stat-card-icon blue">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                </div>
                                <div class="stat-card-title">إنتاج معلق</div>
                                <div class="stat-card-value"><?php echo $activitySummary['pending_production'] ?? 0; ?></div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-card-header">
                                    <div class="stat-card-icon green">
                                        <i class="bi bi-cart-check"></i>
                                    </div>
                                </div>
                                <div class="stat-card-title">مبيعات معلقة</div>
                                <div class="stat-card-value"><?php echo $activitySummary['pending_sales'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- بطاقات ملخص إضافية -->
                <div class="cards-grid mt-4">
                    <?php
                    $lastBackup = $db->queryOne(
                        "SELECT created_at FROM backups WHERE status IN ('completed', 'success') ORDER BY created_at DESC LIMIT 1"
                    );
                    $totalUsers = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
                    $balance = $db->queryOne(
                        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) as balance
                         FROM financial_transactions WHERE status = 'approved'"
                    );
                    $monthlySales = $db->queryOne(
                        "SELECT COALESCE(SUM(total), 0) as total
                         FROM sales WHERE status = 'approved' AND MONTH(date) = MONTH(NOW())"
                    );
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
                        <div class="stat-card-value"><?php echo formatCurrency($balance['balance'] ?? 0); ?></div>
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
                <h2><i class="bi bi-check-circle me-2"></i><?php echo isset($lang['approvals']) ? $lang['approvals'] : 'الموافقات'; ?></h2>
                
                <?php
                // Pagination
                $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                $perPageApprovals = 10;
                $offsetApprovals = ($pageNum - 1) * $perPageApprovals;
                
                $totalApprovals = getPendingApprovalsCount();
                $totalPagesApprovals = ceil($totalApprovals / $perPageApprovals);
                $approvals = getPendingApprovals($perPageApprovals, $offsetApprovals);
                ?>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">الموافقات المعلقة (<?php echo $totalApprovals; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table align-middle">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>الطلب من</th>
                                        <th>التاريخ</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($approvals)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">لا توجد موافقات معلقة</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($approvals as $approval): ?>
                                            <tr>
                                                <td><?php echo $approval['type']; ?></td>
                                                <td><?php echo htmlspecialchars($approval['requested_by_full_name'] ?? $approval['requested_by_name']); ?></td>
                                                <td><?php echo formatDateTime($approval['created_at']); ?></td>
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
                        
                        <!-- Pagination -->
                        <?php if ($totalPagesApprovals > 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=approvals&p=<?php echo $pageNum - 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                
                                <?php
                                $startPageApprovals = max(1, $pageNum - 2);
                                $endPageApprovals = min($totalPagesApprovals, $pageNum + 2);
                                
                                if ($startPageApprovals > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?page=approvals&p=1">1</a></li>
                                    <?php if ($startPageApprovals > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPageApprovals; $i <= $endPageApprovals; $i++): ?>
                                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=approvals&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPageApprovals < $totalPagesApprovals): ?>
                                    <?php if ($endPageApprovals < $totalPagesApprovals - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?page=approvals&p=<?php echo $totalPagesApprovals; ?>"><?php echo $totalPagesApprovals; ?></a></li>
                                <?php endif; ?>
                                
                                <li class="page-item <?php echo $pageNum >= $totalPagesApprovals ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=approvals&p=<?php echo $pageNum + 1; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
                
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
                <?php
                $reportSections = [
                    [
                        'id' => 'reportsQuickTools',
                        'label' => 'أدوات سريعة',
                        'icon' => 'bi-lightning-charge-fill'
                    ],
                    [
                        'id' => 'reportsProduction',
                        'label' => 'تقارير الإنتاج',
                        'icon' => 'bi-graph-up-arrow'
                    ]
                ];
                ?>

                <div class="page-header mb-4">
                    <h2 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i><?php echo isset($lang['reports']) ? $lang['reports'] : 'التقارير'; ?></h2>
                    <p class="text-muted mb-0">اختر القسم المطلوب عبر الأزرار العلوية أو قم بالتمرير للاطلاع على كل التقارير.</p>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-start reports-sections-nav">
                        <?php foreach ($reportSections as $section): ?>
                            <a class="btn btn-outline-primary reports-nav-link" href="#<?php echo htmlspecialchars($section['id']); ?>">
                                <i class="bi <?php echo htmlspecialchars($section['icon']); ?> me-2"></i><?php echo htmlspecialchars($section['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <section id="reportsQuickTools" class="report-section">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <div>
                                <h5 class="mb-1"><i class="bi bi-lightning-charge-fill me-2"></i>أدوات سريعة</h5>
                                <p class="text-muted mb-0">توليد تقرير مالي سريع بصيغة PDF أو Excel.</p>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-primary" onclick="if(typeof generatePDFReport === 'function') { generatePDFReport('financial', {}, event); } else { alert('دالة التقرير غير متاحة. يرجى التأكد من تحميل ملف reports.js'); }">
                                    <i class="bi bi-file-pdf me-2"></i>توليد تقرير PDF
                                </button>
                                <button class="btn btn-outline-primary" onclick="if(typeof generateExcelReport === 'function') { generateExcelReport('financial', {}, event); } else { alert('دالة التقرير غير متاحة. يرجى التأكد من تحميل ملف reports.js'); }">
                                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>توليد تقرير Excel
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="reportsProduction" class="report-section">
                    <?php 
                    $productionReportsModule = __DIR__ . '/../modules/manager/production_reports.php';
                    if (file_exists($productionReportsModule)) {
                        include $productionReportsModule;
                    }
                    ?>
                </section>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const navLinks = Array.from(document.querySelectorAll('.reports-nav-link'));
                        const sections = Array.from(document.querySelectorAll('.report-section'));

                        if (!navLinks.length || !sections.length) {
                            return;
                        }

                        const setActiveLink = (targetId) => {
                            navLinks.forEach((link) => {
                                const isActive = link.getAttribute('href') === '#' + targetId;
                                link.classList.toggle('active', isActive);
                                link.classList.toggle('btn-primary', isActive);
                                link.classList.toggle('text-white', isActive);
                                link.classList.toggle('btn-outline-primary', !isActive);
                            });
                        };

                        navLinks.forEach((link) => {
                            link.addEventListener('click', function (event) {
                                const targetId = this.getAttribute('href').replace('#', '');
                                const targetSection = document.getElementById(targetId);
                                if (!targetSection) {
                                    return;
                                }
                                event.preventDefault();
                                const headerOffset = 80;
                                const elementPosition = targetSection.getBoundingClientRect().top + window.pageYOffset;
                                const offsetPosition = elementPosition - headerOffset;
                                window.scrollTo({
                                    top: offsetPosition,
                                    behavior: 'smooth'
                                });
                                setActiveLink(targetId);
                            });
                        });

                        const observerOptions = {
                            root: null,
                            rootMargin: '-40% 0px -40% 0px',
                            threshold: 0
                        };

                        const observer = new IntersectionObserver((entries) => {
                            entries.forEach((entry) => {
                                if (entry.isIntersecting) {
                                    setActiveLink(entry.target.id);
                                }
                            });
                        }, observerOptions);

                        sections.forEach((section) => observer.observe(section));
                        setActiveLink(sections[0].id);
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
                
            <?php elseif ($page === 'customers'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customers.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Manager customers module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة العملاء: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة العملاء غير متاحة حالياً</div>';
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
                
            <?php elseif ($page === 'final_products'): ?>
                <?php 
                $section = $_GET['section'] ?? 'company';
                $allowedSections = ['company', 'delegates'];
                if (!in_array($section, $allowedSections, true)) {
                    $section = 'company';
                }
                ?>
                <div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center">
                    <h2 class="mb-2 mb-md-0"><i class="bi bi-boxes me-2"></i>مخازن المنتجات</h2>
                </div>
                <ul class="nav nav-pills gap-2">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $section === 'company' ? 'active' : ''; ?>" href="manager.php?page=final_products&section=company">
                            <i class="bi bi-building me-2"></i>مخزن منتجات الشركة
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $section === 'delegates' ? 'active' : ''; ?>" href="manager.php?page=final_products&section=delegates">
                            <i class="bi bi-truck me-2"></i>مخازن المناديب
                        </a>
                    </li>
                </ul>
                <div class="mt-4">
                    <?php 
                    if ($section === 'delegates') {
                        $delegatesModule = __DIR__ . '/../modules/sales/vehicle_inventory.php';
                        if (file_exists($delegatesModule)) {
                            include $delegatesModule;
                        } else {
                            echo '<div class="alert alert-warning">صفحة مخازن المناديب غير متاحة حالياً</div>';
                        }
                    } else {
                        $_GET['section'] = 'company';
                        $modulePath = __DIR__ . '/../modules/production/final_products.php';
                        if (file_exists($modulePath)) {
                            include $modulePath;
                        } else {
                            echo '<div class="alert alert-warning">صفحة مخزن المنتجات غير متاحة حالياً</div>';
                        }
                    }
                    ?>
                </div>
                
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
            <?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?php echo ASSETS_URL; ?>js/reports.js"></script>
<script>
function approveRequest(id) {
    if (!id) {
        console.error('approveRequest: Missing ID');
        alert('خطأ: معرّف الطلب غير موجود');
        return;
    }
    
    if (!confirm('هل أنت متأكد من الموافقة على هذا الطلب؟')) {
        return;
    }
    
    const btn = event?.target?.closest('button');
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch('api/approve.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            id: id
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تمت الموافقة';
            }
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
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
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
    
    fetch('api/reject.php', {
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
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-x-circle me-2"></i>تم الرفض';
            }
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
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    });
}
</script>

