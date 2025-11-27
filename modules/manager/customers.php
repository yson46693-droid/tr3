<?php

declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/components/customers/section_header.php';
require_once __DIR__ . '/../../includes/components/customers/customer_table.php';
require_once __DIR__ . '/../../includes/components/customers/rep_card.php';
require_once __DIR__ . '/../sales/table_styles.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$db = db();
$error = '';
$success = '';

applyPRGPattern($error, $success);

$sectionInput = $_POST['section'] ?? $_GET['section'] ?? 'company';
$section = $sectionInput;
$allowedSections = ['company', 'representatives'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'company';
}

$dashboardScript = basename($_SERVER['PHP_SELF'] ?? 'manager.php');
$basePageUrl = getRelativeUrl($dashboardScript . '?page=customers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($section === 'company') {
        if ($action === 'add_company_customer') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0.0;

            if ($name === '') {
                $error = 'يجب إدخال اسم العميل.';
            } else {
                try {
                    $db->execute(
                        "INSERT INTO customers (name, phone, email, address, balance, status, created_by, rep_id, created_from_pos, created_by_admin)
                         VALUES (?, ?, ?, ?, ?, 'active', ?, NULL, 0, 1)",
                        [
                            $name,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $address !== '' ? $address : null,
                            $balance,
                            $currentUser['id'],
                        ]
                    );

                    $customerId = (int)$db->getLastInsertId();
                    logAudit($currentUser['id'], 'manager_add_company_customer', 'customer', $customerId, null, [
                        'name' => $name,
                        'created_by_admin' => 1,
                    ]);

                    $_SESSION['success_message'] = 'تمت إضافة العميل بنجاح.';
                    redirectAfterPost('customers', ['section' => 'company'], [], $currentRole);
                } catch (Throwable $addError) {
                    error_log('Manager add customer error: ' . $addError->getMessage());
                    $error = 'تعذر إضافة العميل. يرجى المحاولة لاحقاً.';
                }
            }
        } elseif ($action === 'edit_company_customer') {
            $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0.0;

            if ($customerId <= 0) {
                $error = 'معرف العميل غير صالح.';
            } elseif ($name === '') {
                $error = 'يجب إدخال اسم العميل.';
            } else {
                try {
                    $existing = $db->queryOne(
                        "SELECT id FROM customers WHERE id = ? AND (created_from_pos = 1 OR created_by_admin = 1)",
                        [$customerId]
                    );
                    if (!$existing) {
                        throw new InvalidArgumentException('لا يمكن تعديل هذا العميل من هذا القسم.');
                    }

                    $db->execute(
                        "UPDATE customers
                         SET name = ?, phone = ?, email = ?, address = ?, balance = ?
                         WHERE id = ?",
                        [
                            $name,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $address !== '' ? $address : null,
                            $balance,
                            $customerId,
                        ]
                    );

                    logAudit($currentUser['id'], 'manager_edit_company_customer', 'customer', $customerId, null, [
                        'name' => $name,
                    ]);

                    $_SESSION['success_message'] = 'تم تحديث بيانات العميل بنجاح.';
                    redirectAfterPost('customers', ['section' => 'company'], [], $currentRole);
                } catch (InvalidArgumentException $invalidEdit) {
                    $error = $invalidEdit->getMessage();
                } catch (Throwable $editError) {
                    error_log('Manager edit customer error: ' . $editError->getMessage());
                    $error = 'تعذر تحديث بيانات العميل.';
                }
            }
        } elseif ($action === 'delete_company_customer') {
            if ($currentRole !== 'manager') {
                $error = 'يُسمح للمدير فقط بحذف العملاء.';
            } else {
                $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
                if ($customerId <= 0) {
                    $error = 'معرف العميل غير صالح.';
                } else {
                    try {
                        $existing = $db->queryOne(
                            "SELECT id FROM customers WHERE id = ? AND (created_from_pos = 1 OR created_by_admin = 1)",
                            [$customerId]
                        );
                        if (!$existing) {
                            throw new InvalidArgumentException('لا يمكن حذف هذا العميل من هذا القسم.');
                        }

                        $db->execute("DELETE FROM customers WHERE id = ?", [$customerId]);
                        logAudit($currentUser['id'], 'manager_delete_company_customer', 'customer', $customerId);

                        $_SESSION['success_message'] = 'تم حذف العميل بنجاح.';
                        redirectAfterPost('customers', ['section' => 'company'], [], $currentRole);
                    } catch (InvalidArgumentException $invalidDelete) {
                        $error = $invalidDelete->getMessage();
                    } catch (Throwable $deleteError) {
                        error_log('Manager delete customer error: ' . $deleteError->getMessage());
                        $error = 'تعذر حذف العميل. تحقق من عدم وجود معاملات مرتبطة به.';
                    }
                }
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$debtStatus = $_GET['debt_status'] ?? 'all';
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}

$pageNum = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$companyCustomers = [];
$companyTotalPages = 1;
$companyTotals = [
    'total' => 0,
    'debtor' => 0,
    'debt' => 0.0,
];
$companyCollections = 0.0;

if ($section === 'company') {
    $baseCondition = '(c.created_from_pos = 1 OR c.created_by_admin = 1)';
    $whereParts = [$baseCondition];
    $listParams = [];
    $countParams = [];
    $statsParams = [];

    if ($debtStatus === 'debtor') {
        $whereParts[] = '(c.balance IS NOT NULL AND c.balance > 0)';
    } elseif ($debtStatus === 'clear') {
        $whereParts[] = '(c.balance IS NULL OR c.balance <= 0)';
    }

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
    $companyTotals['total'] = (int)($countResult['total'] ?? 0);

    $statsResult = $db->queryOne($statsSql, $statsParams);
    if ($statsResult) {
        $companyTotals['total'] = (int)($statsResult['total_count'] ?? $companyTotals['total']);
        $companyTotals['debtor'] = (int)($statsResult['debtor_count'] ?? 0);
        $companyTotals['debt'] = (float)($statsResult['total_debt'] ?? 0.0);
    }

    $companyTotalPages = max(1, (int)ceil(max(1, $companyTotals['total']) / $perPage));

    $listQueryParams = array_merge($listParams, [$perPage, $offset]);
    $companyCustomers = $db->query($listSql, $listQueryParams);

    try {
        $collectionsSql = "
            SELECT COALESCE(SUM(col.amount), 0) AS total_collections
            FROM collections col
            INNER JOIN customers c ON col.customer_id = c.id
            WHERE {$baseCondition}";
        $collectionsParams = [];
        if ($debtStatus === 'debtor') {
            $collectionsSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
        } elseif ($debtStatus === 'clear') {
            $collectionsSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
        }
        if ($search !== '') {
            $collectionsSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
            $collectionsParams[] = '%' . $search . '%';
            $collectionsParams[] = '%' . $search . '%';
            $collectionsParams[] = '%' . $search . '%';
        }
        $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
        $companyCollections = (float)($collectionsResult['total_collections'] ?? 0);
    } catch (Throwable $collectionsError) {
        error_log('Manager company customers collections error: ' . $collectionsError->getMessage());
    }
}

$representatives = [];
$representativeSummary = [
    'total' => 0,
    'customers' => 0,
    'debtors' => 0,
    'debt' => 0.0,
];

if ($section === 'representatives') {
    try {
        $representatives = $db->query(
            "SELECT 
                u.id,
                u.full_name,
                u.username,
                u.phone,
                u.email,
                u.status,
                u.last_login_at,
                u.profile_image,
                COUNT(DISTINCT c.id) AS customer_count,
                COALESCE(SUM(CASE WHEN c.balance > 0 THEN c.balance ELSE 0 END), 0) AS total_debt,
                COALESCE(SUM(CASE WHEN c.balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count
            FROM users u
            LEFT JOIN customers c ON (c.rep_id = u.id OR c.created_by = u.id)
            WHERE u.role = 'sales'
            GROUP BY u.id, u.full_name, u.username, u.phone, u.email, u.status, u.last_login_at, u.profile_image
            ORDER BY customer_count DESC, u.full_name ASC"
        );

        foreach ($representatives as $repRow) {
            $representativeSummary['total']++;
            $representativeSummary['customers'] += (int)($repRow['customer_count'] ?? 0);
            $representativeSummary['debtors'] += (int)($repRow['debtor_count'] ?? 0);
            $representativeSummary['debt'] += (float)($repRow['total_debt'] ?? 0.0);
        }
    } catch (Throwable $repsError) {
        error_log('Manager representatives list error: ' . $repsError->getMessage());
        $representatives = [];
    }
}

$tabs = [
    [
        'id' => 'company',
        'label' => 'عملاء الشركة',
        'href' => getRelativeUrl($basePageUrl . '&section=company'),
        'icon' => 'bi bi-building',
    ],
    
];

$primaryButton = null;
if ($section === 'company') {
    $primaryButton = [
        'tag' => 'button',
        'label' => 'إضافة عميل جديد',
        'icon' => 'bi bi-person-plus',
        'attrs' => [
            'type' => 'button',
            'data-bs-toggle' => 'modal',
            'data-bs-target' => '#addCustomerModal',
        ],
    ];
}

renderCustomersSectionHeader([
    'title' => 'إدارة العملاء',
    'active_tab' => $section,
    'tabs' => $tabs,
    'primary_btn' => $primaryButton,
]);

if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($section === 'company'): ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">عدد العملاء</div>
                        <div class="fs-4 fw-bold mb-0"><?php echo number_format($companyTotals['total']); ?></div>
                    </div>
                    <span class="text-primary display-6"><i class="bi bi-people-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">العملاء المدينون</div>
                        <div class="fs-4 fw-bold text-warning mb-0"><?php echo number_format($companyTotals['debtor']); ?></div>
                    </div>
                    <span class="text-warning display-6"><i class="bi bi-cash-coin"></i></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">إجمالي الديون</div>
                        <div class="fs-4 fw-bold text-danger mb-0"><?php echo formatCurrency($companyTotals['debt']); ?></div>
                    </div>
                    <span class="text-danger display-6"><i class="bi bi-bar-chart"></i></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 col-xl-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">إجمالي التحصيلات</div>
                        <div class="fs-4 fw-bold text-success mb-0"><?php echo formatCurrency($companyCollections); ?></div>
                    </div>
                    <span class="text-success display-6"><i class="bi bi-wallet2"></i></span>
                </div>
            </div>
        </div>
    </div>

    <?php
    renderCustomerListSection([
        'form_action' => getRelativeUrl($dashboardScript),
        'hidden_fields' => [
            'page' => 'customers',
            'section' => 'company',
        ],
        'search' => $search,
        'debt_status' => $debtStatus,
        'customers' => $companyCustomers,
        'total_customers' => $companyTotals['total'],
        'pagination' => [
            'page' => $pageNum,
            'total_pages' => $companyTotalPages,
            'base_url' => getRelativeUrl($basePageUrl . '&section=company'),
        ],
        'current_role' => $currentRole,
        'actions' => [
            'collect' => false,
            'history' => false,
            'returns' => false,
            'edit' => true,
            'delete' => ($currentRole === 'manager'),
            'location_capture' => false,
            'location_view' => true,
        ],
    ]);
    ?>

   
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
<?php endif; ?>
<!-- End of page scripts -->
