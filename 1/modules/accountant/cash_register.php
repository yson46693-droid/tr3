<?php
/**
 * صفحة خزنة المحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';

requireRole(['accountant', 'manager']);

$db = db();
$currentUser = getCurrentUser();

$lang = isset($translations) ? $translations : [];

$today = date('Y-m-d');
$currentMonth = date('n');
$currentYear = date('Y');

// أرصدة موجزة
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
$cashBalance = floatval($incomeRow['total'] ?? 0) - floatval($expenseRow['total'] ?? 0);

$todayIncome = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('income', 'transfer')
      AND DATE(created_at) = CURDATE()
");
$todayExpenses = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('expense', 'payment')
      AND DATE(created_at) = CURDATE()
");

$monthIncome = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved'
      AND type IN ('income', 'transfer')
      AND MONTH(created_at) = ? AND YEAR(created_at) = ?
", [$currentMonth, $currentYear]);

$monthExpenses = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved'
      AND type IN ('expense', 'payment')
      AND MONTH(created_at) = ? AND YEAR(created_at) = ?
", [$currentMonth, $currentYear]);

$pendingTransactions = $db->queryOne("
    SELECT COUNT(*) AS total
    FROM financial_transactions
    WHERE status = 'pending'
");

// تحصيلات اليوم
$collectionsStatusColumn = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
$collectionsStatusFilter = !empty($collectionsStatusColumn) ? "AND status IN ('approved','pending')" : '';
$collectionsToday = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM collections
    WHERE DATE(date) = CURDATE() $collectionsStatusFilter
");

// فلاتر سجل المعاملات
$validTypes = [
    '' => 'جميع الأنواع',
    'income' => 'إيراد',
    'expense' => 'مصروف',
    'transfer' => 'تحويل',
    'payment' => 'دفعة'
];

$validStatuses = [
    '' => 'جميع الحالات',
    'approved' => 'معتمدة',
    'pending' => 'قيد المراجعة',
    'rejected' => 'مرفوضة'
];

$filterType = isset($_GET['type']) && array_key_exists($_GET['type'], $validTypes) ? $_GET['type'] : '';
$filterStatus = isset($_GET['status']) && array_key_exists($_GET['status'], $validStatuses) ? $_GET['status'] : '';
$filterFrom = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
$filterTo = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereParts = [];
$whereParams = [];

if ($filterType !== '') {
    $whereParts[] = 'ft.type = ?';
    $whereParams[] = $filterType;
}

if ($filterStatus !== '') {
    $whereParts[] = 'ft.status = ?';
    $whereParams[] = $filterStatus;
}

if ($filterFrom !== '') {
    $whereParts[] = 'DATE(ft.created_at) >= ?';
    $whereParams[] = $filterFrom;
}

if ($filterTo !== '') {
    $whereParts[] = 'DATE(ft.created_at) <= ?';
    $whereParams[] = $filterTo;
}

if ($searchTerm !== '') {
    $whereParts[] = '(ft.description LIKE ? OR ft.reference_number LIKE ?)';
    $like = '%' . $searchTerm . '%';
    $whereParams[] = $like;
    $whereParams[] = $like;
}

$whereClause = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$perPage = 15;
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;

$countRow = $db->queryOne("SELECT COUNT(*) AS total FROM financial_transactions ft $whereClause", $whereParams);
$totalRows = (int) ($countRow['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}
$offset = ($pageNum - 1) * $perPage;

$transactions = $db->query(
    "SELECT ft.*, u.full_name AS creator_name, u.username AS creator_username,
            approver.full_name AS approver_name
     FROM financial_transactions ft
     LEFT JOIN users u ON ft.created_by = u.id
     LEFT JOIN users approver ON ft.approved_by = approver.id
     $whereClause
     ORDER BY ft.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($whereParams, [$perPage, $offset])
);
?>

<div class="page-header mb-4">
    <h2><i class="bi bi-safe2 me-2"></i>خزنة المحاسب</h2>
    <p class="text-muted mb-0">التحكم الكامل في التدفق النقدي والمعاملات المالية المعتمدة.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon success">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-card-title">رصيد الخزنة</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($cashBalance); ?></div>
                <div class="stat-card-description text-muted">صافي (إيرادات - مصروفات) معتمدة</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon primary">
                    <i class="bi bi-calendar-event"></i>
                </div>
                <div class="stat-card-title">إيرادات اليوم</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($todayIncome['total'] ?? 0); ?></div>
                <div class="stat-card-description text-muted">المعاملات المعتمدة فقط</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon red">
                    <i class="bi bi-arrow-down-circle"></i>
                </div>
                <div class="stat-card-title">مصروفات اليوم</div>
                <div class="h4 fw-bold mb-0"><?php echo formatCurrency($todayExpenses['total'] ?? 0); ?></div>
                <div class="stat-card-description text-muted">يشمل الدفعات النقدية</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-3 col-md-6">
        <div class="card stat-card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="stat-card-icon purple">
                    <i class="bi bi-list-check"></i>
                </div>
                <div class="stat-card-title">معاملات قيد الاعتماد</div>
                <div class="h4 fw-bold mb-0"><?php echo (int)($pendingTransactions['total'] ?? 0); ?></div>
                <div class="stat-card-description text-muted">بانتظار اعتماد المحاسب</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title">ملخص شهري</h5>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">إيرادات الشهر</span>
                    <strong class="text-success"><?php echo formatCurrency($monthIncome['total'] ?? 0); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">مصروفات الشهر</span>
                    <strong class="text-danger"><?php echo formatCurrency($monthExpenses['total'] ?? 0); ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">تحصيلات اليوم</span>
                    <strong><?php echo formatCurrency($collectionsToday['total'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title">فلترة السجل المالي</h5>
                <form class="row g-3" method="GET">
                    <input type="hidden" name="page" value="accountant_cash">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">النوع</label>
                        <select class="form-select" name="type">
                            <?php foreach ($validTypes as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $filterType === $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">الحالة</label>
                        <select class="form-select" name="status">
                            <?php foreach ($validStatuses as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $filterStatus === $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">من تاريخ</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filterFrom); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">إلى تاريخ</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filterTo); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">بحث بالكلمات المفتاحية</label>
                        <input type="text" class="form-control" name="search" placeholder="وصف، رقم مرجعي..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a href="accountant.php?page=accountant_cash" class="btn btn-outline-secondary">
                            مسح الفلاتر
                        </a>
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-funnel me-1"></i>تطبيق
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>سجل المعاملات المالية</h5>
        <span class="text-muted small">إجمالي النتائج: <?php echo $totalRows; ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>التاريخ</th>
                        <th>النوع</th>
                        <th>الوصف</th>
                        <th>المبلغ</th>
                        <th>الحالة</th>
                        <th>المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                لا توجد معاملات مطابقة للمعايير الحالية.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('Y-m-d', strtotime($transaction['created_at'])); ?></strong>
                                    <div class="text-muted small"><?php echo date('H:i', strtotime($transaction['created_at'])); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo $validTypes[$transaction['type']] ?? $transaction['type']; ?>
                                    </span>
                                    <?php if (!empty($transaction['reference_number'])): ?>
                                        <div class="text-muted small">#<?php echo htmlspecialchars($transaction['reference_number']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo nl2br(htmlspecialchars($transaction['description'])); ?>
                                </td>
                                <td>
                                    <?php
                                    $amount = floatval($transaction['amount']);
                                    $isExpense = in_array($transaction['type'], ['expense', 'payment'], true);
                                    $formattedAmount = formatCurrency($amount);
                                    ?>
                                    <span class="<?php echo $isExpense ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $isExpense ? '-' : '+'; ?><?php echo $formattedAmount; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $status = $transaction['status'];
                                    $statusClasses = [
                                        'approved' => 'badge bg-success',
                                        'pending' => 'badge bg-warning text-dark',
                                        'rejected' => 'badge bg-danger'
                                    ];
                                    ?>
                                    <span class="<?php echo $statusClasses[$status] ?? 'badge bg-secondary'; ?>">
                                        <?php echo $validStatuses[$status] ?? $status; ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($transaction['creator_name'] ?? $transaction['creator_username'] ?? '—'); ?></div>
                                    <?php if (!empty($transaction['approver_name'])): ?>
                                        <div class="text-muted small">
                                            <i class="bi bi-check2-circle me-1"></i><?php echo htmlspecialchars($transaction['approver_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small">
            صفحة <?php echo $pageNum; ?> من <?php echo $totalPages; ?>
        </span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $query = $_GET;
                $query['page'] = 'accountant_cash';
                $prevPage = max(1, $pageNum - 1);
                $nextPage = min($totalPages, $pageNum + 1);
                $query['p'] = $prevPage;
                ?>
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo '?' . http_build_query($query); ?>">&laquo;</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php $query['p'] = $i; ?>
                    <li class="page-item <?php echo $i === $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo '?' . http_build_query($query); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php $query['p'] = $nextPage; ?>
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo '?' . http_build_query($query); ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

