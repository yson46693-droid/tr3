<?php
/**
 * صفحة خزنة الشركة (المدير)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';

requireRole('manager');

$db = db();
$lang = isset($translations) ? $translations : [];

$month = date('n');
$year = date('Y');

$incomeRow = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('income', 'transfer')
");
$expenseRow = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('expense', 'payment')
");
$companyBalance = floatval($incomeRow['total'] ?? 0) - floatval($expenseRow['total'] ?? 0);

$approvedExpensesMonth = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('expense','payment')
      AND MONTH(created_at) = ? AND YEAR(created_at) = ?
", [$month, $year]);

$collectionsStatusColumn = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
$collectionsStatusCondition = !empty($collectionsStatusColumn) ? "status IN ('approved','pending')" : '1=1';
$collectionsMonth = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM collections
    WHERE $collectionsStatusCondition
      AND MONTH(date) = ? AND YEAR(date) = ?
", [$month, $year]);

$pendingTransactions = $db->queryOne("
    SELECT COUNT(*) AS total
    FROM financial_transactions
    WHERE status = 'pending'
");

$salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
$pendingSalaries = ['total' => 0];
if (!empty($salariesTableExists)) {
    $pendingSalaries = $db->queryOne("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM salaries
        WHERE status IN ('pending', 'approved')
    ");
}

// أحدث التحصيلات
$latestCollections = [];
if ($collectionsStatusColumn !== false) {
    $statusFilter = !empty($collectionsStatusColumn) ? "WHERE c.status IN ('approved','pending')" : '';
    $latestCollections = $db->query("
        SELECT c.*, cust.name AS customer_name, u.full_name AS collector_name
        FROM collections c
        LEFT JOIN customers cust ON c.customer_id = cust.id
        LEFT JOIN users u ON c.collected_by = u.id
        $statusFilter
        ORDER BY c.date DESC, c.created_at DESC
        LIMIT 10
    ");
}

// سجل المعاملات المالية (مختصر)
$transactions = $db->query("
    SELECT ft.*, creator.full_name AS creator_name, creator.username AS creator_username
    FROM financial_transactions ft
    LEFT JOIN users creator ON ft.created_by = creator.id
    ORDER BY ft.created_at DESC
    LIMIT 15
");
?>

<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap">
    <div>
        <h2 class="mb-1"><i class="bi bi-building me-2"></i>خزنة الشركة</h2>
        <p class="text-muted mb-0">رؤية شاملة للتدفقات النقدية والالتزامات المالية.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon success">
                    <i class="bi bi-wallet2"></i>
                </div>
                <div class="stat-card-title">رصيد الشركة الحالي</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($companyBalance); ?></div>
                <div class="stat-card-description text-muted">إجمالي الإيرادات - المصروفات</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon primary">
                    <i class="bi bi-graph-up"></i>
                </div>
                <div class="stat-card-title">تحصيلات الشهر</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($collectionsMonth['total'] ?? 0); ?></div>
                <div class="stat-card-description text-muted">يشمل التحصيلات المعتمدة والمعلقة</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon red">
                    <i class="bi bi-arrow-down-circle"></i>
                </div>
                <div class="stat-card-title">مصروفات الشهر</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($approvedExpensesMonth['total'] ?? 0); ?></div>
                <div class="stat-card-description text-muted">معاملات معتمدة فقط</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon purple">
                    <i class="bi bi-clipboard-data"></i>
                </div>
                <div class="stat-card-title">رواتب بانتظار الصرف</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($pendingSalaries['total'] ?? 0); ?></div>
                <div class="stat-card-description text-muted">حالات معتمدة/معلقة</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>أحدث المعاملات المالية</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>التاريخ</th>
                                <th>النوع</th>
                                <th>المبلغ</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">لا توجد معاملات مسجلة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td>
                                            <?php echo date('Y-m-d', strtotime($transaction['created_at'])); ?>
                                            <div class="text-muted small"><?php echo date('H:i', strtotime($transaction['created_at'])); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo $transaction['type']; ?></span>
                                            <div class="text-muted small"><?php echo htmlspecialchars(mb_strimwidth($transaction['description'], 0, 40, '...')); ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $isOut = in_array($transaction['type'], ['expense', 'payment'], true);
                                            $amount = formatCurrency($transaction['amount']);
                                            ?>
                                            <span class="<?php echo $isOut ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo $isOut ? '-' : '+'; ?><?php echo $amount; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $transaction['status'] === 'approved' ? 'success' : ($transaction['status'] === 'pending' ? 'warning text-dark' : 'danger'); ?>">
                                                <?php echo $transaction['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>آخر التحصيلات من العملاء</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>التاريخ</th>
                                <th>العميل</th>
                                <th>المبلغ</th>
                                <th>المحصل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($latestCollections)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">لا توجد تحصيلات حديثة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($latestCollections as $collection): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($collection['date']); ?></td>
                                        <td><?php echo htmlspecialchars($collection['customer_name'] ?? 'عميل مجهول'); ?></td>
                                        <td class="text-success fw-bold"><?php echo formatCurrency($collection['amount']); ?></td>
                                        <td><?php echo htmlspecialchars($collection['collector_name'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white border-0">
        <h5 class="mb-0"><i class="bi bi-exclamation-octagon me-2"></i>إشارات هامة</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="alert alert-warning mb-0">
                    <strong>معاملات قيد الاعتماد:</strong>
                    <div class="fs-5 fw-bold mt-2"><?php echo (int)($pendingTransactions['total'] ?? 0); ?></div>
                    <small class="text-muted">يرجى مراجعتها لاعتماد الرصيد النهائي.</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="alert alert-info mb-0">
                    <strong>إلتزامات الرواتب:</strong>
                    <div class="fs-5 fw-bold mt-2"><?php echo formatCurrency($pendingSalaries['total'] ?? 0); ?></div>
                    <small class="text-muted">مبالغ يجب توفيرها قبل موعد الصرف.</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="alert alert-success mb-0">
                    <strong>تحصيلات الشهر الحالي:</strong>
                    <div class="fs-5 fw-bold mt-2"><?php echo formatCurrency($collectionsMonth['total'] ?? 0); ?></div>
                    <small class="text-muted">تساعد في دعم السيولة.</small>
                </div>
            </div>
        </div>
    </div>
</div>

