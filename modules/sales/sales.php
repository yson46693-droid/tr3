<?php
/**
 * صفحة إدارة المبيعات للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/product_name_helper.php';
require_once __DIR__ . '/../../includes/exchanges.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// التحقق من وجود جدول sales
$salesTableCheck = $db->queryOne("SHOW TABLES LIKE 'sales'");
if (empty($salesTableCheck)) {
    $error = 'جدول المبيعات غير موجود. يرجى التحقق من قاعدة البيانات.';
    $sales = [];
    $totalSales = 0;
    $totalPages = 0;
} else {
    // Pagination
    $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $perPage = 20;
    $offset = ($pageNum - 1) * $perPage;

    // البحث والفلترة
    $filters = [
        'customer_id' => $_GET['customer_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? ''
    ];

    // إذا كان المستخدم مندوب مبيعات، عرض فقط مبيعاته
    if ($currentUser['role'] === 'sales') {
        $filters['salesperson_id'] = $currentUser['id'];
    }

    $filters = array_filter($filters, function($value) {
        return $value !== '';
    });

    // التأكد من وجود عمود credit_used في جدول invoices
    try {
        $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
        if (!$hasCreditUsedColumn) {
            $db->execute("ALTER TABLE invoices ADD COLUMN credit_used DECIMAL(15,2) DEFAULT 0.00 COMMENT 'المبلغ المخصوم من الرصيد الدائن' AFTER paid_from_credit");
        }
    } catch (Throwable $e) {
        error_log('Error checking/adding credit_used column: ' . $e->getMessage());
    }

    // بناء استعلام SQL - استخدام invoice_items بدلاً من sales لضمان ربط كل عنصر بفاتورته الصحيحة
    // هذا يمنع تكرار أرقام الفواتير عند إعادة بيع منتج مرتجع
    $sql = "SELECT 
                   ii.id as sale_id,
                   ii.invoice_id,
                   i.invoice_number,
                   i.date,
                   i.customer_id,
                   i.sales_rep_id as salesperson_id,
                   ii.product_id,
                   ii.quantity,
                   ii.unit_price as price,
                   ii.total_price as total,
                   i.status,
                   c.name as customer_name,
                   COALESCE(
                       (SELECT fp2.product_name 
                        FROM finished_products fp2 
                        WHERE fp2.product_id = ii.product_id 
                          AND fp2.product_name IS NOT NULL 
                          AND TRIM(fp2.product_name) != ''
                          AND fp2.product_name NOT LIKE 'منتج رقم%'
                        ORDER BY fp2.id DESC 
                        LIMIT 1),
                       NULLIF(TRIM(p.name), ''),
                       CONCAT('منتج رقم ', ii.product_id)
                   ) as product_name,
                   u.full_name as salesperson_name,
                   COALESCE(i.credit_used, 0) as credit_used
            FROM invoice_items ii
            INNER JOIN invoices i ON ii.invoice_id = i.id
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN products p ON ii.product_id = p.id
            LEFT JOIN users u ON i.sales_rep_id = u.id
            WHERE i.status != 'cancelled'";

    $countSql = "SELECT COUNT(*) as total 
                 FROM invoice_items ii
                 INNER JOIN invoices i ON ii.invoice_id = i.id
                 WHERE i.status != 'cancelled'";
    $params = [];
    $countParams = [];

    // إذا كان المستخدم مندوب مبيعات، فلتر حسب sales_rep_id
    if ($currentUser['role'] === 'sales') {
        $sql .= " AND i.sales_rep_id = ?";
        $countSql .= " AND i.sales_rep_id = ?";
        $params[] = $currentUser['id'];
        $countParams[] = $currentUser['id'];
    }

    if (!empty($filters['customer_id'])) {
        $sql .= " AND i.customer_id = ?";
        $countSql .= " AND i.customer_id = ?";
        $params[] = $filters['customer_id'];
        $countParams[] = $filters['customer_id'];
    }

    if (!empty($filters['status'])) {
        $sql .= " AND i.status = ?";
        $countSql .= " AND i.status = ?";
        $params[] = $filters['status'];
        $countParams[] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(i.date) >= ?";
        $countSql .= " AND DATE(i.date) >= ?";
        $params[] = $filters['date_from'];
        $countParams[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(i.date) <= ?";
        $countSql .= " AND DATE(i.date) <= ?";
        $params[] = $filters['date_to'];
        $countParams[] = $filters['date_to'];
    }

    $totalResult = $db->queryOne($countSql, $countParams);
    $totalSales = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalSales / $perPage);

    $sql .= " ORDER BY i.date DESC, i.id DESC, ii.id DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    $sales = $db->query($sql, $params);
}

// الحصول على العملاء
$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// ========== إعداد فلاتر الاستبدالات ==========
$exchangeFilters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// إذا كان المستخدم مندوب مبيعات، عرض فقط استبدالاته
if ($currentUser['role'] === 'sales') {
    $exchangeFilters['sales_rep_id'] = $currentUser['id'];
}

$exchangeFilters = array_filter($exchangeFilters, function($value) {
    return $value !== '';
});
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-<?php echo isset($_GET['page']) && $_GET['page'] === 'sales_records' ? 'journal-text' : 'cart-check'; ?> me-2"></i><?php echo isset($_GET['page']) && $_GET['page'] === 'sales_records' ? 'السجلات' : 'المبيعات'; ?></h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <script>
    // إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
    (function() {
        const successAlert = document.getElementById('successAlert');
        const errorAlert = document.getElementById('errorAlert');
        
        // التحقق من وجود رسالة نجاح أو خطأ
        const alertElement = successAlert || errorAlert;
        
        if (alertElement && alertElement.dataset.autoRefresh === 'true') {
            // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
            setTimeout(function() {
                // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
                const currentUrl = new URL(window.location.href);
                // إزالة معاملات success و error من URL
                currentUrl.searchParams.delete('success');
                currentUrl.searchParams.delete('error');
                // إعادة تحميل الصفحة
                window.location.href = currentUrl.toString();
            }, 3000);
        }
    })();
    </script>
<?php endif; ?>

<?php if ($error): ?>
    <script>
    // إعادة تحميل الصفحة تلقائياً بعد رسالة الخطأ
    (function() {
        const errorAlert = document.getElementById('errorAlert');
        if (errorAlert && errorAlert.dataset.autoRefresh === 'true') {
            setTimeout(function() {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.delete('success');
                currentUrl.searchParams.delete('error');
                window.location.href = currentUrl.toString();
            }, 3000);
        }
    })();
    </script>
<?php endif; ?>

<?php 
$isSalesRecords = isset($_GET['page']) && $_GET['page'] === 'sales_records';
// التحقق من section أولاً (من dashboard/sales.php) ثم tab
$activeTab = isset($_GET['section']) ? $_GET['section'] : (isset($_GET['tab']) ? $_GET['tab'] : 'sales');
// التأكد من أن activeTab إما 'sales' أو 'exchanges'
if ($activeTab !== 'exchanges' && $activeTab !== 'sales') {
    $activeTab = 'sales';
}
?>

<!-- الفلاتر -->
<?php 
$filterCardClass = $isSalesRecords ? 'border-0 shadow-lg' : 'shadow-sm';
$filterCardStyle = $isSalesRecords ? 'background: linear-gradient(135deg,rgb(12, 45, 194) 0%,rgb(11, 94, 218) 100%); border-radius: 12px;' : '';
// استخدام الفلتر المناسب حسب التبويب النشط
$currentFilters = ($activeTab === 'exchanges') ? $exchangeFilters : $filters;
?>
<div class="card <?php echo $filterCardClass; ?> mb-4" style="<?php echo $filterCardStyle; ?>">
    <div class="card-body" style="<?php echo $isSalesRecords ? 'padding: 1.5rem;' : ''; ?>">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="page" value="<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>">
            <?php if (isset($_GET['section'])): ?>
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($activeTab); ?>">
            <?php else: ?>
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">العميل</label>
                <select class="form-select <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($currentFilters['customer_id']) ? intval($currentFilters['customer_id']) : 0;
                    $customerValid = isValidSelectValue($selectedCustomerId, $customers, 'id');
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $customerValid && $selectedCustomerId == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">الحالة</label>
                <select class="form-select <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($currentFilters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($currentFilters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($currentFilters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                    <option value="completed" <?php echo ($currentFilters['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">من تاريخ</label>
                <input type="date" class="form-control <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="date_from" value="<?php echo htmlspecialchars($currentFilters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">إلى تاريخ</label>
                <input type="date" class="form-control <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="date_to" value="<?php echo htmlspecialchars($currentFilters['date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">&nbsp;</label>
                <button type="submit" class="btn <?php echo $isSalesRecords ? 'btn-light fw-semibold' : 'btn-primary'; ?> w-100 shadow-sm">
                    <i class="bi bi-search me-2"></i>بحث
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($activeTab === 'exchanges'): ?>
<?php
// ========== قسم سجلات الاستبدال ==========
// جلب عمليات الاستبدال للمندوب
$exchangesPageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$exchangesPerPage = 20;
$exchangesOffset = ($exchangesPageNum - 1) * $exchangesPerPage;

// التأكد من وجود جدول exchanges
$exchangesTableCheck = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
$exchanges = [];
$totalExchanges = 0;
$totalExchangePages = 0;

if (!empty($exchangesTableCheck)) {
    // استخدام دالة getExchanges من includes/exchanges.php
    ensureExchangeSchema();
    
    // حساب العدد الإجمالي
    $exchangeCountSql = "SELECT COUNT(*) as total FROM exchanges e WHERE 1=1";
    $exchangeCountParams = [];
    
    if ($currentUser['role'] === 'sales') {
        $exchangeCountSql .= " AND e.sales_rep_id = ?";
        $exchangeCountParams[] = $currentUser['id'];
    }
    
    if (!empty($exchangeFilters['customer_id'])) {
        $exchangeCountSql .= " AND e.customer_id = ?";
        $exchangeCountParams[] = $exchangeFilters['customer_id'];
    }
    
    if (!empty($exchangeFilters['status'])) {
        $exchangeCountSql .= " AND e.status = ?";
        $exchangeCountParams[] = $exchangeFilters['status'];
    }
    
    if (!empty($exchangeFilters['date_from'])) {
        $exchangeCountSql .= " AND DATE(e.exchange_date) >= ?";
        $exchangeCountParams[] = $exchangeFilters['date_from'];
    }
    
    if (!empty($exchangeFilters['date_to'])) {
        $exchangeCountSql .= " AND DATE(e.exchange_date) <= ?";
        $exchangeCountParams[] = $exchangeFilters['date_to'];
    }
    
    $totalExchangeResult = $db->queryOne($exchangeCountSql, $exchangeCountParams);
    $totalExchanges = $totalExchangeResult['total'] ?? 0;
    $totalExchangePages = ceil($totalExchanges / $exchangesPerPage);
    
    // جلب الاستبدالات
    $exchanges = getExchanges($exchangeFilters, $exchangesPerPage, $exchangesOffset);
}
?>

<!-- قسم سجلات الاستبدال -->
<?php 
$tableCardClass = $isSalesRecords ? 'border-0 shadow-lg' : 'shadow-sm';
$tableHeaderClass = $isSalesRecords ? 'bg-gradient' : 'bg-primary';
$tableHeaderStyle = $isSalesRecords ? 'background: linear-gradient(135deg,rgb(37, 70, 213) 0%,rgb(5, 46, 134) 100%); border-radius: 12px 12px 0 0;' : '';
?>
<div class="card <?php echo $tableCardClass; ?>" style="<?php echo $isSalesRecords ? 'border-radius: 12px; overflow: hidden;' : ''; ?>">
    <div class="card-header <?php echo $tableHeaderClass; ?> text-white" style="<?php echo $tableHeaderStyle; ?>">
        <h5 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right me-2"></i>سجلات الاستبدال (<?php echo $totalExchanges; ?>)</h5>
    </div>
    <div class="card-body" style="<?php echo $isSalesRecords ? 'padding: 1.5rem;' : ''; ?>">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle <?php echo $isSalesRecords ? 'table-hover' : ''; ?>" style="<?php echo $isSalesRecords ? 'margin-bottom: 0;' : ''; ?>">
                <thead>
                    <tr style="<?php echo $isSalesRecords ? 'background: linear-gradient(135deg,rgb(40, 74, 225) 0%,rgb(28, 49, 186) 100%);' : ''; ?>">
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">التاريخ</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">رقم الاستبدال</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">العميل</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">النوع</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">القيمة الأصلية</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">القيمة الجديدة</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">الفرق</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">الحالة</th>
                        <?php if ($currentUser['role'] !== 'sales'): ?>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">مندوب المبيعات</th>
                        <?php endif; ?>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>" width="100">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exchanges)): ?>
                        <tr>
                            <td colspan="<?php echo $currentUser['role'] !== 'sales' ? '10' : '9'; ?>" class="text-center text-muted">لا توجد عمليات استبدال</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exchanges as $exchange): ?>
                            <?php
                            $exchangeTypeLabels = [
                                'same_product' => 'نفس المنتج',
                                'different_product' => 'منتج مختلف',
                                'upgrade' => 'ترقية',
                                'downgrade' => 'تخفيض'
                            ];
                            $exchangeTypeLabel = $exchangeTypeLabels[$exchange['exchange_type']] ?? $exchange['exchange_type'];
                            
                            $statusLabels = [
                                'pending' => 'معلق',
                                'approved' => 'معتمد',
                                'rejected' => 'مرفوض',
                                'completed' => 'مكتمل'
                            ];
                            $statusColors = [
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'completed' => 'info'
                            ];
                            $statusLabel = $statusLabels[$exchange['status']] ?? $exchange['status'];
                            $statusColor = $statusColors[$exchange['status']] ?? 'secondary';
                            
                            $differenceAmount = (float)($exchange['difference_amount'] ?? 0);
                            $differenceClass = $differenceAmount >= 0 ? 'text-success' : 'text-danger';
                            ?>
                            <tr style="<?php echo $isSalesRecords ? 'transition: all 0.3s ease; border-bottom: 1px solid #e9ecef;' : ''; ?>" 
                                <?php if ($isSalesRecords): ?>
                                onmouseover="this.style.background='#f8f9fa'; this.style.transform='translateX(-2px)';" 
                                onmouseout="this.style.background=''; this.style.transform='';"
                                <?php endif; ?>>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo formatDate($exchange['exchange_date']); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <span class="badge <?php echo $isSalesRecords ? 'bg-gradient shadow-sm' : 'bg-info'; ?>" style="<?php echo $isSalesRecords ? 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 0.5rem 0.75rem; font-weight: 600; color: #000;' : ''; ?>">
                                        <?php echo htmlspecialchars($exchange['exchange_number'] ?? 'EXC-' . $exchange['id']); ?>
                                    </span>
                                </td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo htmlspecialchars($exchange['customer_name'] ?? '-'); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($exchangeTypeLabel); ?></span>
                                </td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo formatCurrency($exchange['original_total'] ?? 0); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo formatCurrency($exchange['new_total'] ?? 0); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <strong class="<?php echo $differenceClass; ?>" style="<?php echo $isSalesRecords ? 'font-size: 1.1rem;' : ''; ?>">
                                        <?php echo ($differenceAmount >= 0 ? '+' : '') . formatCurrency($differenceAmount); ?>
                                    </strong>
                                </td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <span class="badge bg-<?php echo $statusColor; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </td>
                                <?php if ($currentUser['role'] !== 'sales'): ?>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo htmlspecialchars($exchange['sales_rep_name'] ?? '-'); ?></td>
                                <?php endif; ?>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <a href="<?php echo getRelativeUrl('print_exchange_invoice.php?id=' . (int)$exchange['id']); ?>" 
                                       target="_blank" 
                                       class="btn btn-sm <?php echo $isSalesRecords ? 'btn-light shadow-sm' : 'btn-primary'; ?>" 
                                       title="طباعة فاتورة الاستبدال"
                                       style="<?php echo $isSalesRecords ? 'font-weight: 600;' : ''; ?>">
                                        <i class="bi bi-printer me-1"></i>طباعة
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination للاستبدالات -->
        <?php if (isset($totalExchangePages) && $totalExchangePages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $exchangesPageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $exchangesPageNum - 1; ?><?php echo !empty($exchangeFilters['customer_id']) ? '&customer_id=' . urlencode($exchangeFilters['customer_id']) : ''; ?><?php echo !empty($exchangeFilters['status']) ? '&status=' . urlencode($exchangeFilters['status']) : ''; ?><?php echo !empty($exchangeFilters['date_from']) ? '&date_from=' . urlencode($exchangeFilters['date_from']) : ''; ?><?php echo !empty($exchangeFilters['date_to']) ? '&date_to=' . urlencode($exchangeFilters['date_to']) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $exchangesPageNum - 2);
                $endPage = min($totalExchangePages, $exchangesPageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=1<?php echo !empty($exchangeFilters['customer_id']) ? '&customer_id=' . urlencode($exchangeFilters['customer_id']) : ''; ?><?php echo !empty($exchangeFilters['status']) ? '&status=' . urlencode($exchangeFilters['status']) : ''; ?><?php echo !empty($exchangeFilters['date_from']) ? '&date_from=' . urlencode($exchangeFilters['date_from']) : ''; ?><?php echo !empty($exchangeFilters['date_to']) ? '&date_to=' . urlencode($exchangeFilters['date_to']) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $exchangesPageNum ? 'active' : ''; ?>">
                        <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $i; ?><?php echo !empty($exchangeFilters['customer_id']) ? '&customer_id=' . urlencode($exchangeFilters['customer_id']) : ''; ?><?php echo !empty($exchangeFilters['status']) ? '&status=' . urlencode($exchangeFilters['status']) : ''; ?><?php echo !empty($exchangeFilters['date_from']) ? '&date_from=' . urlencode($exchangeFilters['date_from']) : ''; ?><?php echo !empty($exchangeFilters['date_to']) ? '&date_to=' . urlencode($exchangeFilters['date_to']) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalExchangePages): ?>
                    <?php if ($endPage < $totalExchangePages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $totalExchangePages; ?><?php echo !empty($exchangeFilters['customer_id']) ? '&customer_id=' . urlencode($exchangeFilters['customer_id']) : ''; ?><?php echo !empty($exchangeFilters['status']) ? '&status=' . urlencode($exchangeFilters['status']) : ''; ?><?php echo !empty($exchangeFilters['date_from']) ? '&date_from=' . urlencode($exchangeFilters['date_from']) : ''; ?><?php echo !empty($exchangeFilters['date_to']) ? '&date_to=' . urlencode($exchangeFilters['date_to']) : ''; ?>"><?php echo $totalExchangePages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $exchangesPageNum >= $totalExchangePages ? 'disabled' : ''; ?>">
                    <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $exchangesPageNum + 1; ?><?php echo !empty($exchangeFilters['customer_id']) ? '&customer_id=' . urlencode($exchangeFilters['customer_id']) : ''; ?><?php echo !empty($exchangeFilters['status']) ? '&status=' . urlencode($exchangeFilters['status']) : ''; ?><?php echo !empty($exchangeFilters['date_from']) ? '&date_from=' . urlencode($exchangeFilters['date_from']) : ''; ?><?php echo !empty($exchangeFilters['date_to']) ? '&date_to=' . urlencode($exchangeFilters['date_to']) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'sales'): ?>
<?php
// ========== قسم جدول المبيعات ==========
?>
<!-- قسم جدول المبيعات -->
<?php 
$tableCardClass = $isSalesRecords ? 'border-0 shadow-lg' : 'shadow-sm';
$tableHeaderClass = $isSalesRecords ? 'bg-gradient' : 'bg-primary';
$tableHeaderStyle = $isSalesRecords ? 'background: linear-gradient(135deg,rgb(37, 70, 213) 0%,rgb(5, 46, 134) 100%); border-radius: 12px 12px 0 0;' : '';
?>
<div class="card <?php echo $tableCardClass; ?>" style="<?php echo $isSalesRecords ? 'border-radius: 12px; overflow: hidden;' : ''; ?>">
    <div class="card-header <?php echo $tableHeaderClass; ?> text-white" style="<?php echo $tableHeaderStyle; ?>">
        <h5 class="mb-0 fw-bold"><i class="bi bi-cart-check me-2"></i>سجلات المبيعات (<?php echo $totalSales; ?>)</h5>
    </div>
    <div class="card-body" style="<?php echo $isSalesRecords ? 'padding: 1.5rem;' : ''; ?>">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle <?php echo $isSalesRecords ? 'table-hover' : ''; ?>" style="<?php echo $isSalesRecords ? 'margin-bottom: 0;' : ''; ?>">
                <thead>
                    <tr style="<?php echo $isSalesRecords ? 'background: linear-gradient(135deg,rgb(40, 74, 225) 0%,rgb(2, 71, 220) 100%);' : ''; ?>">
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">التاريخ</th>
                        <th class="<?php echo $isSalesRecords ? 'fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem; color: #000;' : ''; ?>">رقم الفاتورة</th>
                        <th class="<?php echo $isSalesRecords ? 'fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem; color: #000;' : ''; ?>">العميل</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">المنتج</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">الكمية</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">السعر</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">الإجمالي</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">الحالة</th>
                        <?php if ($currentUser['role'] !== 'sales'): ?>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">مندوب المبيعات</th>
                        <?php endif; ?>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>" width="100">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="<?php echo $currentUser['role'] !== 'sales' ? '9' : '8'; ?>" class="text-center text-muted">لا توجد مبيعات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <?php
                            $statusLabels = [
                                'pending' => 'معلق',
                                'approved' => 'موافق عليه',
                                'rejected' => 'مرفوض',
                                'completed' => 'مكتمل'
                            ];
                            $statusColors = [
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'completed' => 'info'
                            ];
                            $statusLabel = $statusLabels[$sale['status']] ?? $sale['status'];
                            $statusColor = $statusColors[$sale['status']] ?? 'secondary';
                            ?>
                            <tr style="<?php echo $isSalesRecords ? 'transition: all 0.3s ease; border-bottom: 1px solid #e9ecef;' : ''; ?>" 
                                <?php if ($isSalesRecords): ?>
                                onmouseover="this.style.background='#f8f9fa'; this.style.transform='translateX(-2px)';" 
                                onmouseout="this.style.background=''; this.style.transform='';"
                                <?php endif; ?>>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo formatDate($sale['date']); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <span class="badge <?php echo $isSalesRecords ? 'bg-gradient shadow-sm' : 'bg-info'; ?>" style="<?php echo $isSalesRecords ? 'background: linear-gradient(135deg,rgb(13, 56, 250) 0%,rgb(40, 15, 139) 100%); padding: 0.5rem 0.75rem; font-weight: 600; color: #000;' : ''; ?>">
                                        <?php echo htmlspecialchars($sale['invoice_number'] ?? 'INV-' . $sale['invoice_id']); ?>
                                    </span>
                                </td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo htmlspecialchars($sale['product_name'] ?? '-'); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo number_format($sale['quantity'], 2); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo formatCurrency($sale['price'] ?? 0); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <strong style="<?php echo $isSalesRecords ? 'font-size: 1.1rem;' : ''; ?>">
                                        <?php echo formatCurrency($sale['total'] ?? 0); ?>
                                    </strong>
                                </td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <span class="badge bg-<?php echo $statusColor; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </td>
                                <?php if ($currentUser['role'] !== 'sales'): ?>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo htmlspecialchars($sale['salesperson_name'] ?? '-'); ?></td>
                                <?php endif; ?>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <a href="<?php echo getRelativeUrl('print_invoice.php?id=' . (int)$sale['invoice_id']); ?>" 
                                       target="_blank" 
                                       class="btn btn-sm <?php echo $isSalesRecords ? 'btn-light shadow-sm' : 'btn-primary'; ?>" 
                                       title="طباعة الفاتورة"
                                       style="<?php echo $isSalesRecords ? 'font-weight: 600;' : ''; ?>">
                                        <i class="bi bi-printer me-1"></i>طباعة
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination للمبيعات -->
        <?php if (isset($totalPages) && $totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $pageNum - 1; ?><?php echo !empty($filters['customer_id']) ? '&customer_id=' . urlencode($filters['customer_id']) : ''; ?><?php echo !empty($filters['status']) ? '&status=' . urlencode($filters['status']) : ''; ?><?php echo !empty($filters['date_from']) ? '&date_from=' . urlencode($filters['date_from']) : ''; ?><?php echo !empty($filters['date_to']) ? '&date_to=' . urlencode($filters['date_to']) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=1<?php echo !empty($filters['customer_id']) ? '&customer_id=' . urlencode($filters['customer_id']) : ''; ?><?php echo !empty($filters['status']) ? '&status=' . urlencode($filters['status']) : ''; ?><?php echo !empty($filters['date_from']) ? '&date_from=' . urlencode($filters['date_from']) : ''; ?><?php echo !empty($filters['date_to']) ? '&date_to=' . urlencode($filters['date_to']) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $i; ?><?php echo !empty($filters['customer_id']) ? '&customer_id=' . urlencode($filters['customer_id']) : ''; ?><?php echo !empty($filters['status']) ? '&status=' . urlencode($filters['status']) : ''; ?><?php echo !empty($filters['date_from']) ? '&date_from=' . urlencode($filters['date_from']) : ''; ?><?php echo !empty($filters['date_to']) ? '&date_to=' . urlencode($filters['date_to']) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $totalPages; ?><?php echo !empty($filters['customer_id']) ? '&customer_id=' . urlencode($filters['customer_id']) : ''; ?><?php echo !empty($filters['status']) ? '&status=' . urlencode($filters['status']) : ''; ?><?php echo !empty($filters['date_from']) ? '&date_from=' . urlencode($filters['date_from']) : ''; ?><?php echo !empty($filters['date_to']) ? '&date_to=' . urlencode($filters['date_to']) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?<?php echo isset($_GET['section']) ? 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&section=' . urlencode($activeTab) : 'page=' . ($isSalesRecords ? 'sales_records' : 'sales_collections') . '&tab=' . urlencode($activeTab); ?>&p=<?php echo $pageNum + 1; ?><?php echo !empty($filters['customer_id']) ? '&customer_id=' . urlencode($filters['customer_id']) : ''; ?><?php echo !empty($filters['status']) ? '&status=' . urlencode($filters['status']) : ''; ?><?php echo !empty($filters['date_from']) ? '&date_from=' . urlencode($filters['date_from']) : ''; ?><?php echo !empty($filters['date_to']) ? '&date_to=' . urlencode($filters['date_to']) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
