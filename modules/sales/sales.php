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

    // بناء استعلام SQL - جلب اسم المنتج من finished_products إذا كان متوفراً
    $sql = "SELECT s.*, c.name as customer_name, 
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
                       CONCAT('منتج رقم ', p.id)
                   ) as product_name,
                   u.full_name as salesperson_name,
                   (SELECT i.invoice_number 
                    FROM invoices i 
                    WHERE i.customer_id = s.customer_id 
                      AND DATE(i.date) = DATE(s.date)
                      AND (i.sales_rep_id = s.salesperson_id OR i.sales_rep_id IS NULL)
                    ORDER BY i.id DESC 
                    LIMIT 1) as invoice_number,
                   (SELECT i.id 
                    FROM invoices i 
                    WHERE i.customer_id = s.customer_id 
                      AND DATE(i.date) = DATE(s.date)
                      AND (i.sales_rep_id = s.salesperson_id OR i.sales_rep_id IS NULL)
                    ORDER BY i.id DESC 
                    LIMIT 1) as invoice_id
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN products p ON s.product_id = p.id
            LEFT JOIN users u ON s.salesperson_id = u.id
            WHERE 1=1";

    $countSql = "SELECT COUNT(*) as total FROM sales WHERE 1=1";
    $params = [];
    $countParams = [];

    // إذا كان المستخدم مندوب مبيعات، فلتر حسب salesperson_id
    if ($currentUser['role'] === 'sales') {
        $sql .= " AND s.salesperson_id = ?";
        $countSql .= " AND salesperson_id = ?";
        $params[] = $currentUser['id'];
        $countParams[] = $currentUser['id'];
    }

    if (!empty($filters['customer_id'])) {
        $sql .= " AND s.customer_id = ?";
        $countSql .= " AND customer_id = ?";
        $params[] = $filters['customer_id'];
        $countParams[] = $filters['customer_id'];
    }

    if (!empty($filters['status'])) {
        $sql .= " AND s.status = ?";
        $countSql .= " AND status = ?";
        $params[] = $filters['status'];
        $countParams[] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(s.date) >= ?";
        $countSql .= " AND DATE(date) >= ?";
        $params[] = $filters['date_from'];
        $countParams[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(s.date) <= ?";
        $countSql .= " AND DATE(date) <= ?";
        $params[] = $filters['date_to'];
        $countParams[] = $filters['date_to'];
    }

    $totalResult = $db->queryOne($countSql, $countParams);
    $totalSales = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalSales / $perPage);

    $sql .= " ORDER BY s.date DESC, s.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    $sales = $db->query($sql, $params);
}

// الحصول على العملاء
$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");
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

<!-- الفلاتر -->
<?php 
$isSalesRecords = isset($_GET['page']) && $_GET['page'] === 'sales_records';
$filterCardClass = $isSalesRecords ? 'border-0 shadow-lg' : 'shadow-sm';
$filterCardStyle = $isSalesRecords ? 'background: linear-gradient(135deg,rgb(12, 45, 194) 0%,rgb(11, 94, 218) 100%); border-radius: 12px;' : '';
?>
<div class="card <?php echo $filterCardClass; ?> mb-4" style="<?php echo $filterCardStyle; ?>">
    <div class="card-body" style="<?php echo $isSalesRecords ? 'padding: 1.5rem;' : ''; ?>">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="page" value="<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>">
            <div class="col-md-3">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">العميل</label>
                <select class="form-select <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($filters['customer_id']) ? intval($filters['customer_id']) : 0;
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
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                    <option value="completed" <?php echo ($filters['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">من تاريخ</label>
                <input type="date" class="form-control <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label <?php echo $isSalesRecords ? 'text-white fw-semibold' : ''; ?>">إلى تاريخ</label>
                <input type="date" class="form-control <?php echo $isSalesRecords ? 'border-0 shadow-sm' : ''; ?>" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
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

<!-- قائمة المبيعات -->
<?php 
$tableCardClass = $isSalesRecords ? 'border-0 shadow-lg' : 'shadow-sm';
$tableHeaderClass = $isSalesRecords ? 'bg-gradient' : 'bg-primary';
$tableHeaderStyle = $isSalesRecords ? 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px 12px 0 0;' : '';
?>
<div class="card <?php echo $tableCardClass; ?>" style="<?php echo $isSalesRecords ? 'border-radius: 12px; overflow: hidden;' : ''; ?>">
    <div class="card-header <?php echo $tableHeaderClass; ?> text-white" style="<?php echo $tableHeaderStyle; ?>">
        <h5 class="mb-0 fw-bold"><i class="bi bi-<?php echo $isSalesRecords ? 'journal-text' : 'cart-check'; ?> me-2"></i><?php echo $isSalesRecords ? 'قائمة السجلات' : 'قائمة المبيعات'; ?> (<?php echo $totalSales ?? 0; ?>)</h5>
    </div>
    <div class="card-body" style="<?php echo $isSalesRecords ? 'padding: 1.5rem;' : ''; ?>">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle <?php echo $isSalesRecords ? 'table-hover' : ''; ?>" style="<?php echo $isSalesRecords ? 'margin-bottom: 0;' : ''; ?>">
                <thead>
                    <tr style="<?php echo $isSalesRecords ? 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);' : ''; ?>">
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">التاريخ</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">رقم الفاتورة</th>
                        <th class="<?php echo $isSalesRecords ? 'text-white fw-bold' : ''; ?>" style="<?php echo $isSalesRecords ? 'border: none; padding: 1rem;' : ''; ?>">العميل</th>
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
                            <td colspan="<?php echo $currentUser['role'] !== 'sales' ? '10' : '9'; ?>" class="text-center text-muted">لا توجد مبيعات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <?php
                            // حل اسم المنتج باستخدام الدالة المساعدة
                            $productName = resolveProductName([
                                $sale['product_name'] ?? null
                            ]);
                            ?>
                            <tr style="<?php echo $isSalesRecords ? 'transition: all 0.3s ease; border-bottom: 1px solid #e9ecef;' : ''; ?>" 
                                <?php if ($isSalesRecords): ?>
                                onmouseover="this.style.background='#f8f9fa'; this.style.transform='translateX(-2px)';" 
                                onmouseout="this.style.background=''; this.style.transform='';"
                                <?php endif; ?>>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo formatDate($sale['date']); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <?php if (!empty($sale['invoice_number'])): ?>
                                        <span class="badge <?php echo $isSalesRecords ? 'bg-gradient shadow-sm' : 'bg-info'; ?>" style="<?php echo $isSalesRecords ? 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 0.5rem 0.75rem; font-weight: 600; color: #000;' : ''; ?>"><?php echo htmlspecialchars($sale['invoice_number']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo htmlspecialchars($sale['customer_name'] ?? '-'); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo htmlspecialchars($productName); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo number_format($sale['quantity'], 2); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem; font-weight: 500;' : ''; ?>"><?php echo formatCurrency($sale['price']); ?></td>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><strong class="<?php echo $isSalesRecords ? 'text-primary' : ''; ?>" style="<?php echo $isSalesRecords ? 'font-size: 1.1rem;' : ''; ?>"><?php echo formatCurrency($sale['total']); ?></strong></td>
                                <?php if ($currentUser['role'] !== 'sales'): ?>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>"><?php echo htmlspecialchars($sale['salesperson_name'] ?? '-'); ?></td>
                                <?php endif; ?>
                                <td style="<?php echo $isSalesRecords ? 'padding: 1rem;' : ''; ?>">
                                    <?php if (!empty($sale['invoice_id']) && !empty($sale['invoice_number'])): ?>
                                        <a href="<?php echo getRelativeUrl('print_invoice.php?id=' . (int)$sale['invoice_id']); ?>" 
                                           target="_blank" 
                                           class="btn btn-sm <?php echo $isSalesRecords ? 'btn-light shadow-sm' : 'btn-primary'; ?>" 
                                           title="طباعة الفاتورة"
                                           style="<?php echo $isSalesRecords ? 'font-weight: 600;' : ''; ?>">
                                            <i class="bi bi-printer me-1"></i>طباعة
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if (isset($totalPages) && $totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?page=<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>&p=<?php echo $pageNum - 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?page=<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>&p=1">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?page=<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?page=<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>&p=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link <?php echo $isSalesRecords ? 'shadow-sm' : ''; ?>" href="?page=<?php echo $isSalesRecords ? 'sales_records' : 'sales_collections'; ?>&p=<?php echo $pageNum + 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

