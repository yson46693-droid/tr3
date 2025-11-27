<?php

declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/components/customers/section_header.php';
require_once __DIR__ . '/../../includes/components/customers/customer_table.php';
require_once __DIR__ . '/../sales/table_styles.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$db = db();

$repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;
$search = trim($_GET['search'] ?? '');
$debtStatus = $_GET['debt_status'] ?? 'all';
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}
$pageNum = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$dashboardScript = basename($_SERVER['PHP_SELF'] ?? 'manager.php');
$pageBaseUrl = getRelativeUrl($dashboardScript . '?page=rep_customers_view&rep_id=' . $repId);

if ($repId <= 0) {
    echo '<div class="alert alert-warning">لم يتم تحديد مندوب لعرض عملائه.</div>';
    return;
}

$rep = $db->queryOne(
    "SELECT id, full_name, username, phone, email, status, last_login_at, profile_image 
     FROM users 
     WHERE id = ? AND role = 'sales' LIMIT 1",
    [$repId]
);

if (!$rep) {
    echo '<div class="alert alert-danger">المندوب المطلوب غير موجود.</div>';
    return;
}

$whereParts = ['(c.rep_id = ? OR (c.created_by = ? AND (c.created_from_pos = 0 AND c.created_by_admin = 0)))'];
$listParams = [$repId, $repId];
$countParams = [$repId, $repId];
$statsParams = [$repId, $repId];

if ($debtStatus === 'debtor') {
    $whereParts[] = '(c.balance IS NOT NULL AND c.balance > 0)';
} elseif ($debtStatus === 'clear') {
    $whereParts[] = '(c.balance IS NULL OR c.balance <= 0)';
}

$searchParams = [];
if ($search !== '') {
    $whereParts[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)';
    $searchWildcard = '%' . $search . '%';
    $listParams[] = $searchWildcard;
    $listParams[] = $searchWildcard;
    $listParams[] = $searchWildcard;
    $countParams[] = $searchWildcard;
    $countParams[] = $searchWildcard;
    $countParams[] = $searchWildcard;
    $statsParams[] = $searchWildcard;
    $statsParams[] = $searchWildcard;
    $statsParams[] = $searchWildcard;
    $searchParams = [$searchWildcard, $searchWildcard, $searchWildcard];
}

$whereClause = implode(' AND ', $whereParts);

$countSql = "SELECT COUNT(*) AS total FROM customers c WHERE {$whereClause}";
$statsSql = "
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN c.balance > 0 THEN 1 ELSE 0 END) AS debtor_count,
        SUM(CASE WHEN c.balance > 0 THEN c.balance ELSE 0 END) AS total_debt
    FROM customers c
    WHERE {$whereClause}";

$listSql = "
    SELECT c.*
    FROM customers c
    WHERE {$whereClause}
    ORDER BY c.name ASC
    LIMIT ? OFFSET ?";

$countResult = $db->queryOne($countSql, $countParams);
$totalCustomers = (int)($countResult['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalCustomers / $perPage));

$listQueryParams = $listParams;
$listQueryParams[] = $perPage;
$listQueryParams[] = $offset;
$customers = $db->query($listSql, $listQueryParams);

$statsResult = $db->queryOne($statsSql, $statsParams);
$summaryTotalCustomers = (int)($statsResult['total_count'] ?? 0);
$summaryDebtorCount = (int)($statsResult['debtor_count'] ?? 0);
$summaryTotalDebt = (float)($statsResult['total_debt'] ?? 0.0);

$collectionsTotal = 0.0;
try {
    $collectionsSql = "
        SELECT COALESCE(SUM(col.amount), 0) AS total_collections
        FROM collections col
        INNER JOIN customers c ON col.customer_id = c.id
        WHERE (c.rep_id = ? OR (c.created_by = ? AND (c.created_from_pos = 0 AND c.created_by_admin = 0)))";
    $collectionsParams = [$repId, $repId];

    if ($debtStatus === 'debtor') {
        $collectionsSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    } elseif ($debtStatus === 'clear') {
        $collectionsSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    }

    if ($search !== '') {
        $collectionsSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
        $collectionsParams = array_merge($collectionsParams, $searchParams);
    }

    $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
    if (!empty($collectionsResult)) {
        $collectionsTotal = (float)($collectionsResult['total_collections'] ?? 0);
    }
} catch (Throwable $collectionError) {
    error_log('Rep customers collections error: ' . $collectionError->getMessage());
}

renderCustomersSectionHeader([
    'title' => 'عملاء المندوب: ' . ($rep['full_name'] ?: $rep['username']),
    'active_tab' => '',
    'tabs' => [],
    'primary_btn' => [
        'tag' => 'a',
        'label' => 'العودة',
        'icon' => 'bi bi-arrow-right',
        'class' => 'btn btn-outline-secondary',
        'attrs' => [
            'href' => getRelativeUrl($dashboardScript . '?page=customers&section=delegates'),
        ]
    ],
]);
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex gap-3">
                <div class="rep-avatar rep-avatar-lg">
                    <?php if (!empty($rep['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($rep['profile_image']); ?>" alt="<?php echo htmlspecialchars($rep['full_name'] ?: $rep['username']); ?>">
                    <?php else: ?>
                        <div class="rep-avatar-placeholder">
                            <i class="bi bi-person"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h5 class="mb-1"><?php echo htmlspecialchars($rep['full_name'] ?: $rep['username']); ?></h5>
                    <?php if (!empty($rep['phone'])): ?>
                        <div class="text-muted small mb-1"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($rep['phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($rep['email'])): ?>
                        <div class="text-muted small mb-1"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($rep['email']); ?></div>
                    <?php endif; ?>
                    <span class="badge <?php echo strtolower((string)($rep['status'] ?? 'inactive')) === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>">
                        <?php echo strtolower((string)($rep['status'] ?? 'inactive')) === 'active' ? 'نشط' : 'غير نشط'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-8">
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">عدد العملاء</div>
                        <div class="fs-4 fw-bold mb-0"><?php echo number_format($summaryTotalCustomers); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">العملاء المدينون</div>
                        <div class="fs-4 fw-bold text-warning mb-0"><?php echo number_format($summaryDebtorCount); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">إجمالي الديون</div>
                        <div class="fs-4 fw-bold text-danger mb-0"><?php echo formatCurrency($summaryTotalDebt); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="text-muted small">إجمالي التحصيلات</div>
                        <div class="fs-4 fw-bold text-success mb-0"><?php echo formatCurrency($collectionsTotal); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
renderCustomerListSection([
    'form_action' => getRelativeUrl($dashboardScript),
    'hidden_fields' => [
        'page' => 'rep_customers_view',
        'rep_id' => (string)$repId,
    ],
    'search' => $search,
    'debt_status' => $debtStatus,
    'customers' => $customers,
    'total_customers' => $totalCustomers,
    'pagination' => [
        'page' => $pageNum,
        'total_pages' => $totalPages,
        'base_url' => $pageBaseUrl,
    ],
    'current_role' => $currentRole,
    'actions' => [
        'collect' => false,
        'history' => false,
        'returns' => false,
        'edit' => false,
        'delete' => false,
        'location_capture' => false,
        'location_view' => true,
    ],
]);
?>

<style>
.rep-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
}
.rep-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.rep-avatar-placeholder {
    font-size: 1.5rem;
    color: #94a3b8;
}
.rep-avatar-lg {
    width: 72px;
    height: 72px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.location-view-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            var latitude = button.getAttribute('data-latitude');
            var longitude = button.getAttribute('data-longitude');
            if (!latitude || !longitude) {
                alert('لا يوجد موقع مسجل لهذا العميل.');
                return;
            }
            var url = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
            window.open(url, '_blank');
        });
    });
});
</script>

