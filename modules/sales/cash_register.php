<?php
/**
 * صفحة خزنة المندوب - عرض التفاصيل المالية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

// إذا كان المستخدم مندوب، عرض فقط بياناته
$salesRepId = $isSalesUser ? $currentUser['id'] : (isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : null);

if (!$salesRepId) {
    $error = 'يجب تحديد مندوب المبيعات';
    $salesRepId = $currentUser['id'];
}

// التحقق من وجود الجداول
$invoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
$collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
$salesTableExists = $db->queryOne("SHOW TABLES LIKE 'sales'");

// حساب إجمالي المبيعات من الفواتير
$totalSalesFromInvoices = 0.0;
if (!empty($invoicesTableExists)) {
    $salesResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_sales
         FROM invoices
         WHERE sales_rep_id = ? AND status != 'cancelled'",
        [$salesRepId]
    );
    $totalSalesFromInvoices = (float)($salesResult['total_sales'] ?? 0);
}

// حساب إجمالي المبيعات من جدول sales
$totalSalesFromSalesTable = 0.0;
if (!empty($salesTableExists)) {
    $salesTableResult = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as total_sales
         FROM sales
         WHERE salesperson_id = ? AND status IN ('approved', 'completed')",
        [$salesRepId]
    );
    $totalSalesFromSalesTable = (float)($salesTableResult['total_sales'] ?? 0);
}

// إجمالي المبيعات (نستخدم الفواتير إذا كانت موجودة، وإلا نستخدم جدول sales)
$totalSales = $totalSalesFromInvoices > 0 ? $totalSalesFromInvoices : $totalSalesFromSalesTable;

// حساب إجمالي التحصيلات
$totalCollections = 0.0;
if (!empty($collectionsTableExists)) {
    // التحقق من وجود عمود status
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    if ($hasStatusColumn) {
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ? AND status = 'approved'",
            [$salesRepId]
        );
    } else {
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ?",
            [$salesRepId]
        );
    }
    $totalCollections = (float)($collectionsResult['total_collections'] ?? 0);
}

// حساب المبيعات المدفوعة بالكامل (من الفواتير)
$fullyPaidSales = 0.0;
if (!empty($invoicesTableExists)) {
    $fullyPaidResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
         FROM invoices
         WHERE sales_rep_id = ? 
         AND status = 'paid' 
         AND paid_amount >= total_amount
         AND status != 'cancelled'",
        [$salesRepId]
    );
    $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
}

// رصيد الخزنة = التحصيلات + المبيعات المدفوعة بالكامل
$cashRegisterBalance = $totalCollections + $fullyPaidSales;

// حساب المبيعات المعلقة (الديون)
$pendingSales = $totalSales - $fullyPaidSales - $totalCollections;

// إحصائيات إضافية
$todaySales = 0.0;
$monthSales = 0.0;
$todayCollections = 0.0;
$monthCollections = 0.0;

if (!empty($invoicesTableExists)) {
    $todaySalesResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total
         FROM invoices
         WHERE sales_rep_id = ? AND DATE(date) = CURDATE() AND status != 'cancelled'",
        [$salesRepId]
    );
    $todaySales = (float)($todaySalesResult['total'] ?? 0);
    
    $monthSalesResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total
         FROM invoices
         WHERE sales_rep_id = ? 
         AND MONTH(date) = MONTH(NOW()) 
         AND YEAR(date) = YEAR(NOW())
         AND status != 'cancelled'",
        [$salesRepId]
    );
    $monthSales = (float)($monthSalesResult['total'] ?? 0);
}

if (!empty($collectionsTableExists)) {
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    $statusFilter = $hasStatusColumn ? "AND status = 'approved'" : "";
    
    $todayCollectionsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total
         FROM collections
         WHERE collected_by = ? AND DATE(date) = CURDATE() $statusFilter",
        [$salesRepId]
    );
    $todayCollections = (float)($todayCollectionsResult['total'] ?? 0);
    
    $monthCollectionsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total
         FROM collections
         WHERE collected_by = ? 
         AND MONTH(date) = MONTH(NOW()) 
         AND YEAR(date) = YEAR(NOW())
         $statusFilter",
        [$salesRepId]
    );
    $monthCollections = (float)($monthCollectionsResult['total'] ?? 0);
}

// جلب معلومات المندوب
$salesRepInfo = $db->queryOne(
    "SELECT id, full_name, username, email, phone
     FROM users
     WHERE id = ? AND role = 'sales'",
    [$salesRepId]
);

?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-stack me-2"></i>خزنة المندوب</h2>
    <?php if ($salesRepInfo): ?>
        <div class="text-muted">
            <i class="bi bi-person-circle me-2"></i>
            <strong><?php echo htmlspecialchars($salesRepInfo['full_name'] ?? $salesRepInfo['username']); ?></strong>
        </div>
    <?php endif; ?>
</div>

<!-- بطاقات الإحصائيات الرئيسية -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">إجمالي المبيعات</div>
                    <div class="fs-4 fw-bold mb-0 text-primary"><?php echo formatCurrency($totalSales); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-receipt-cutoff"></i></span>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">إجمالي التحصيلات</div>
                    <div class="fs-4 fw-bold mb-0 text-success"><?php echo formatCurrency($totalCollections); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">مبيعات مدفوعة بالكامل</div>
                    <div class="fs-4 fw-bold mb-0 text-info"><?php echo formatCurrency($fullyPaidSales); ?></div>
                </div>
                <span class="text-info display-6"><i class="bi bi-check-circle"></i></span>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100" style="background: linear-gradient(135deg,rgb(9, 49, 227) 0%,rgba(34, 5, 105, 0.39) 100%);">
            <div class="card-body d-flex align-items-center justify-content-between" style="color: #ffffff !important;">
                <div>
                    <div class="small fw-semibold" style="color: rgba(255, 255, 255, 0.9) !important;">رصيد الخزنة</div>
                    <div class="fs-4 fw-bold mb-0" style="color: #ffffff !important;"><?php echo formatCurrency($cashRegisterBalance); ?></div>
                    <div class="small mt-1" style="color: rgba(255, 255, 255, 0.85) !important;">
                        (تحصيلات + مبيعات كاملة)
                    </div>
                </div>
                <span class="display-6" style="color: rgba(255, 255, 255, 0.8) !important;"><i class="bi bi-safe"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- بطاقات إحصائيات إضافية -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">مبيعات اليوم</div>
                <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($todaySales); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">مبيعات الشهر</div>
                <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($monthSales); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">تحصيلات اليوم</div>
                <div class="fs-5 fw-bold mb-0 text-success"><?php echo formatCurrency($todayCollections); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">تحصيلات الشهر</div>
                <div class="fs-5 fw-bold mb-0 text-success"><?php echo formatCurrency($monthCollections); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- تفاصيل المبيعات المعلقة -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">
            <i class="bi bi-exclamation-triangle me-2"></i>
            المبيعات المعلقة (الديون)
        </h5>
    </div>
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <p class="mb-0 text-muted">
                    المبيعات التي لم يتم تحصيلها بالكامل أو المبيعات التي تم تحصيل جزء منها فقط.
                </p>
            </div>
            <div class="col-md-4 text-end">
                <div class="fs-3 fw-bold text-warning"><?php echo formatCurrency($pendingSales); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ملخص الحسابات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-calculator me-2"></i>
            ملخص الحسابات
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>البند</th>
                        <th class="text-end">المبلغ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>إجمالي المبيعات</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency($totalSales); ?></strong></td>
                    </tr>
                    <tr class="table-success">
                        <td><i class="bi bi-plus-circle me-2"></i>إجمالي التحصيلات من العملاء</td>
                        <td class="text-end text-success">+ <?php echo formatCurrency($totalCollections); ?></td>
                    </tr>
                    <tr class="table-info">
                        <td><i class="bi bi-plus-circle me-2"></i>مبيعات مدفوعة بالكامل (بدون ديون)</td>
                        <td class="text-end text-info">+ <?php echo formatCurrency($fullyPaidSales); ?></td>
                    </tr>
                    <tr class="table-warning">
                        <td><i class="bi bi-dash-circle me-2"></i>المبيعات المعلقة (الديون)</td>
                        <td class="text-end text-warning">- <?php echo formatCurrency($pendingSales); ?></td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong><i class="bi bi-equal me-2"></i>رصيد الخزنة الإجمالي</strong></td>
                        <td class="text-end"><strong class="text-primary"><?php echo formatCurrency($cashRegisterBalance); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="alert alert-info mt-3 mb-0">
            <i class="bi bi-info-circle me-2"></i>
            <strong>ملاحظة:</strong> رصيد الخزنة = إجمالي التحصيلات + المبيعات المدفوعة بالكامل (التي تم تحصيل المبلغ بالكامل فوراً دون أي ديون).
        </div>
    </div>
</div>

