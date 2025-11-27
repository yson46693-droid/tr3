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
        // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ? AND status IN ('pending', 'approved')",
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

// حساب المبيعات المعلقة (الديون) بالاعتماد على أرصدة العملاء لضمان التطابق مع صفحة العملاء
$pendingSales = 0.0;
$customerDebtResult = null;
try {
    $customerDebtSql = "SELECT COALESCE(SUM(balance), 0) AS total_debt FROM customers WHERE balance > 0";
    $customerDebtParams = [];
    
    // إذا كان هناك مندوب محدد، نعرض العملاء الخاصين به فقط (نفس منطق صفحة العملاء)
    if ($salesRepId) {
        $customerDebtSql .= " AND created_by = ?";
        $customerDebtParams[] = $salesRepId;
    }
    
    $customerDebtResult = $db->queryOne($customerDebtSql, $customerDebtParams);
    $pendingSales = (float)($customerDebtResult['total_debt'] ?? 0);
} catch (Throwable $customerDebtError) {
    // في حالة حدوث خطأ، نستخدم الطريقة القديمة كحل احتياطي
    error_log('Sales cash register debt calculation fallback: ' . $customerDebtError->getMessage());
    $pendingSales = $totalSales - $fullyPaidSales - $totalCollections;
    if ($pendingSales < 0) {
        $pendingSales = 0;
    }
}

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
    
    // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
    $statusFilter = $hasStatusColumn ? "AND status IN ('pending', 'approved')" : "";
    
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

// حساب الديون القديمة (العملاء المدينين بدون سجل مشتريات)
$oldDebtsCustomers = [];
$oldDebtsTotal = 0.0;

try {
    // التحقق من وجود جدول customer_purchase_history
    $purchaseHistoryTableExists = $db->queryOne("SHOW TABLES LIKE 'customer_purchase_history'");
    
    if (!empty($purchaseHistoryTableExists)) {
        // جلب العملاء المدينين الذين ليس لديهم سجل مشتريات
        $oldDebtsQuery = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at
            FROM customers c
            WHERE c.created_by = ?
            AND c.balance IS NOT NULL 
            AND c.balance > 0
            AND NOT EXISTS (
                SELECT 1 
                FROM customer_purchase_history cph 
                WHERE cph.customer_id = c.id
            )
            ORDER BY c.balance DESC, c.name ASC
        ";
        
        $oldDebtsCustomers = $db->query($oldDebtsQuery, [$salesRepId]);
        
        // حساب إجمالي الديون القديمة
        if (!empty($oldDebtsCustomers)) {
            foreach ($oldDebtsCustomers as $customer) {
                $oldDebtsTotal += (float)($customer['balance'] ?? 0);
            }
        }
    } else {
        // إذا لم يكن الجدول موجوداً، نستخدم استعلام مختلف
        // جلب العملاء المدينين الذين ليس لديهم فواتير
        $oldDebtsQuery = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at
            FROM customers c
            WHERE c.created_by = ?
            AND c.balance IS NOT NULL 
            AND c.balance > 0
            AND NOT EXISTS (
                SELECT 1 
                FROM invoices inv 
                WHERE inv.customer_id = c.id
            )
            ORDER BY c.balance DESC, c.name ASC
        ";
        
        $oldDebtsCustomers = $db->query($oldDebtsQuery, [$salesRepId]);
        
        // حساب إجمالي الديون القديمة
        if (!empty($oldDebtsCustomers)) {
            foreach ($oldDebtsCustomers as $customer) {
                $oldDebtsTotal += (float)($customer['balance'] ?? 0);
            }
        }
    }
} catch (Throwable $oldDebtsError) {
    error_log('Old debts calculation error: ' . $oldDebtsError->getMessage());
    $oldDebtsCustomers = [];
    $oldDebtsTotal = 0.0;
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



<!-- ملخص الحسابات -->
<style>
/* Glassmorphism Styles for Cash Register */
.glass-card {
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    position: relative;
}

.glass-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
}

.glass-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.glass-card-header i {
    font-size: 24px;
}

.glass-card-body {
    padding: 24px 20px;
}

.glass-card-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.2;
    margin: 0;
}

.glass-card-title {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    margin: 0;
    margin-bottom: 8px;
}

.glass-card-blue {
    color: #0057ff;
}

.glass-card-green {
    color: #0fa55a;
}

.glass-card-orange {
    color: #c98200;
}

.glass-card-red {
    color: #d00000;
}

.glass-card-red-bg {
    background: rgba(208, 0, 0, 0.1);
    border-color: rgba(208, 0, 0, 0.2);
}

.glass-card-red-bg .glass-card-header {
    background: rgba(208, 0, 0, 0.05);
}

.glass-debts-table {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 16px;
    overflow: hidden;
    margin-top: 20px;
}

.glass-debts-table table {
    margin: 0;
}

.glass-debts-table thead th {
    background: rgba(208, 0, 0, 0.08);
    border: none;
    padding: 16px;
    font-weight: 600;
    color: #374151;
}

.glass-debts-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.glass-debts-table tbody tr:last-child td {
    border-bottom: none;
}

.glass-debts-table tbody tr:hover {
    background: rgba(208, 0, 0, 0.03);
}

.glass-debts-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    margin-bottom: 16px;
}

.glass-debts-summary i {
    font-size: 20px;
    color: #d00000;
}

@media (max-width: 768px) {
    .glass-card-value {
        font-size: 24px;
    }
    
    .glass-card-header {
        padding: 14px 16px;
    }
    
    .glass-card-body {
        padding: 20px 16px;
    }
}
</style>

<div class="mb-4">
    <div class="row g-4">
        <!-- إجمالي المبيعات -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-bar-chart-fill glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي المبيعات</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0"><?php echo formatCurrency($totalSales); ?></p>
                </div>
            </div>
        </div>
        
        <!-- التحصيلات من العملاء -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-wallet-fill glass-card-green"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي التحصيلات من العملاء</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-green mb-0">+ <?php echo formatCurrency($totalCollections); ?></p>
                </div>
            </div>
        </div>
        
        <!-- مبيعات مدفوعة بالكامل -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-cash-coin glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">مبيعات مدفوعة بالكامل (بدون ديون)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0">+ <?php echo formatCurrency($fullyPaidSales); ?></p>
                </div>
            </div>
        </div>
        
        <!-- المبيعات المعلقة -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-exclamation-triangle-fill glass-card-orange"></i>
                    <h6 class="mb-0 fw-semibold">المبيعات المعلقة (يشمل الديون اليدوية)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-orange mb-0">- <?php echo formatCurrency($pendingSales); ?></p>
                    <?php if ($oldDebtsTotal > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        ديون بدون سجل مشتريات: <?php echo formatCurrency($oldDebtsTotal); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- رصيد الخزنة الإجمالي -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-bank glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">رصيد الخزنة الإجمالي</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0"><?php echo formatCurrency($cashRegisterBalance); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="alert alert-info mt-4 mb-0" style="border-radius: 12px;">
        <i class="bi bi-info-circle me-2"></i>
        <strong>ملاحظة:</strong> رصيد الخزنة = إجمالي التحصيلات + المبيعات المدفوعة بالكامل (التي تم تحصيل المبلغ بالكامل فوراً دون أي ديون).
    </div>
</div>

<!-- جدول الديون القديمة -->
<div class="glass-card glass-card-red-bg mb-4">
    <div class="glass-card-header">
        <i class="bi bi-clipboard-x-fill glass-card-red"></i>
        <h5 class="mb-0 fw-bold">الديون القديمة</h5>
    </div>
    <div class="glass-card-body">
        <div class="glass-debts-summary">
            <i class="bi bi-people-fill"></i>
            <div>
                <p class="mb-1 text-muted small">العملاء المدينين الذين ليس لديهم سجل مشتريات في النظام.</p>
                <div class="d-flex align-items-center gap-4 flex-wrap">
                    <div>
                        <span class="text-muted small">عدد العملاء: </span>
                        <strong><?php echo count($oldDebtsCustomers); ?></strong>
                    </div>
                    <div>
                        <span class="text-muted small">إجمالي الديون: </span>
                        <strong class="glass-card-red fs-5"><?php echo formatCurrency($oldDebtsTotal); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($oldDebtsCustomers)): ?>
            <div class="glass-debts-table">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>
                                    <i class="bi bi-person-fill me-2"></i>اسم العميل
                                </th>
                                <th>
                                    <i class="bi bi-telephone-fill me-2"></i>الهاتف
                                </th>
                                <th>
                                    <i class="bi bi-geo-alt-fill me-2"></i>العنوان
                                </th>
                                <th class="text-end">
                                    <i class="bi bi-cash-stack me-2"></i>الديون
                                </th>
                                <th>
                                    <i class="bi bi-calendar-event-fill me-2"></i>تاريخ الإضافة
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($oldDebtsCustomers as $customer): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($customer['phone'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($customer['address'] ?? '-'); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <strong class="glass-card-red">
                                            <?php echo formatCurrency((float)($customer['balance'] ?? 0)); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            if (!empty($customer['created_at'])) {
                                                echo date('Y-m-d', strtotime($customer['created_at']));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(208, 0, 0, 0.1);">
                                <th colspan="3" class="text-end">
                                    <strong>الإجمالي:</strong>
                                </th>
                                <th class="text-end">
                                    <strong class="glass-card-red"><?php echo formatCurrency($oldDebtsTotal); ?></strong>
                                </th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0" style="border-radius: 12px;">
                <i class="bi bi-check-circle me-2"></i>
                لا توجد ديون قديمة للعملاء المدينين بدون سجل مشتريات.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
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

