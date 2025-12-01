<?php
/**
 * صفحة إدارة عملاء الشركة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!defined('COMPANY_CUSTOMERS_MODULE_BOOTSTRAPPED')) {
    define('COMPANY_CUSTOMERS_MODULE_BOOTSTRAPPED', true);

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/audit_log.php';
    require_once __DIR__ . '/../../includes/path_helper.php';
    require_once __DIR__ . '/../../includes/customer_history.php';
    require_once __DIR__ . '/../../includes/invoices.php';

    requireRole(['manager', 'accountant']);
}

require_once __DIR__ . '/../sales/table_styles.php';

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

$baseQueryString = '?page=customers';
$customerStats = [
    'total_count' => 0,
    'debtor_count' => 0,
    'total_debt' => 0.0,
];
$totalCollectionsAmount = 0.0;

// تحديد المسار الأساسي للروابط
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$customersBaseScript = 'manager.php';
$customersPageBase = $customersBaseScript . '?page=customers';

// معالجة POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0;
        $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? trim($_POST['latitude']) : null;
        $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? trim($_POST['longitude']) : null;

        if (empty($name)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            try {
                // التحقق من عدم تكرار بيانات العميل
                $duplicateCheckConditions = [
                    "created_by_admin = 1",
                    "(rep_id IS NULL OR rep_id = 0)",
                    "name = ?"
                ];
                $duplicateCheckParams = [$name];
                
                if (!empty($phone)) {
                    $duplicateCheckConditions[] = "phone = ?";
                    $duplicateCheckParams[] = $phone;
                }
                
                if (!empty($address)) {
                    $duplicateCheckConditions[] = "address = ?";
                    $duplicateCheckParams[] = $address;
                }
                
                $duplicateQuery = "SELECT id, name, phone, address FROM customers WHERE " . implode(" AND ", $duplicateCheckConditions) . " LIMIT 1";
                $duplicateCustomer = $db->queryOne($duplicateQuery, $duplicateCheckParams);
                
                if ($duplicateCustomer) {
                    $duplicateInfo = [];
                    if (!empty($duplicateCustomer['phone'])) {
                        $duplicateInfo[] = "رقم الهاتف: " . $duplicateCustomer['phone'];
                    }
                    if (!empty($duplicateCustomer['address'])) {
                        $duplicateInfo[] = "العنوان: " . $duplicateCustomer['address'];
                    }
                    $duplicateMessage = "يوجد عميل مسجل مسبقاً بنفس البيانات";
                    if (!empty($duplicateInfo)) {
                        $duplicateMessage .= " (" . implode(", ", $duplicateInfo) . ")";
                    }
                    $duplicateMessage .= ".";
                    throw new InvalidArgumentException($duplicateMessage);
                }

                // التحقق من وجود أعمدة اللوكيشن
                $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'"));
                $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'"));
                $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'"));
                
                $customerColumns = ['name', 'phone', 'balance', 'address', 'status', 'created_by', 'rep_id', 'created_from_pos', 'created_by_admin'];
                $customerValues = [
                    $name,
                    $phone ?: null,
                    $balance,
                    $address ?: null,
                    'active',
                    $currentUser['id'],
                    null, // rep_id = NULL لعملاء الشركة
                    0,
                    1, // created_by_admin = 1 لعملاء الشركة
                ];
                $customerPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?'];
                
                if ($hasLatitudeColumn && $latitude !== null) {
                    $customerColumns[] = 'latitude';
                    $customerValues[] = (float)$latitude;
                    $customerPlaceholders[] = '?';
                }
                
                if ($hasLongitudeColumn && $longitude !== null) {
                    $customerColumns[] = 'longitude';
                    $customerValues[] = (float)$longitude;
                    $customerPlaceholders[] = '?';
                }
                
                if ($hasLocationCapturedAtColumn && $latitude !== null && $longitude !== null) {
                    $customerColumns[] = 'location_captured_at';
                    $customerValues[] = date('Y-m-d H:i:s');
                    $customerPlaceholders[] = '?';
                }

                $result = $db->execute(
                    "INSERT INTO customers (" . implode(', ', $customerColumns) . ") 
                     VALUES (" . implode(', ', $customerPlaceholders) . ")",
                    $customerValues
                );

                $customerId = (int)($result['insert_id'] ?? 0);
                if ($customerId <= 0) {
                    throw new RuntimeException('فشل إضافة العميل: لم يتم الحصول على معرف العميل.');
                }

                logAudit($currentUser['id'], 'manager_add_company_customer', 'customer', $customerId, null, [
                    'name' => $name,
                    'created_by_admin' => 1,
                ]);

                $_SESSION['success_message'] = 'تم إضافة العميل بنجاح';

                $redirectFilters = [];
                $currentSearch = trim($_GET['search'] ?? '');
                if ($currentSearch !== '') {
                    $redirectFilters['search'] = $currentSearch;
                }

                $currentDebtStatus = $_GET['debt_status'] ?? 'all';
                if (in_array($currentDebtStatus, ['debtor', 'clear'], true)) {
                    $redirectFilters['debt_status'] = $currentDebtStatus;
                }

                $currentPageParam = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                if ($currentPageParam > 1) {
                    $redirectFilters['p'] = $currentPageParam;
                }

                redirectAfterPost(
                    'customers',
                    $redirectFilters,
                    [],
                    'manager'
                );
            } catch (InvalidArgumentException $userError) {
                $error = $userError->getMessage();
            } catch (Throwable $addCustomerError) {
                error_log('Add company customer error: ' . $addCustomerError->getMessage());
                error_log('Stack trace: ' . $addCustomerError->getTraceAsString());
                $error = 'حدث خطأ أثناء إضافة العميل. يرجى المحاولة لاحقاً.';
            }
        }
    } elseif ($action === 'edit_customer') {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0;

        if ($customerId <= 0) {
            $error = 'معرف العميل غير صالح.';
        } elseif (empty($name)) {
            $error = 'يجب إدخال اسم العميل.';
        } else {
            try {
                // التحقق من أن العميل هو عميل شركة
                $existing = $db->queryOne(
                    "SELECT id FROM customers WHERE id = ? AND created_by_admin = 1 AND (rep_id IS NULL OR rep_id = 0)",
                    [$customerId]
                );
                if (!$existing) {
                    throw new InvalidArgumentException('لا يمكن تعديل هذا العميل من هذا القسم.');
                }

                $db->execute(
                    "UPDATE customers SET name = ?, phone = ?, address = ?, balance = ? WHERE id = ?",
                    [
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $balance,
                        $customerId,
                    ]
                );

                logAudit($currentUser['id'], 'manager_edit_company_customer', 'customer', $customerId, null, [
                    'name' => $name,
                ]);

                $_SESSION['success_message'] = 'تم تحديث بيانات العميل بنجاح.';
                redirectAfterPost('customers', [], [], 'manager');
            } catch (InvalidArgumentException $invalidEdit) {
                $error = $invalidEdit->getMessage();
            } catch (Throwable $editError) {
                error_log('Manager edit company customer error: ' . $editError->getMessage());
                $error = 'تعذر تحديث بيانات العميل.';
            }
        }
    } elseif ($action === 'delete_customer') {
        if ($currentRole !== 'manager') {
            $error = 'يُسمح للمدير فقط بحذف العملاء.';
        } else {
            $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
            if ($customerId <= 0) {
                $error = 'معرف العميل غير صالح.';
            } else {
                try {
                    $existing = $db->queryOne(
                        "SELECT id FROM customers WHERE id = ? AND created_by_admin = 1 AND (rep_id IS NULL OR rep_id = 0)",
                        [$customerId]
                    );
                    if (!$existing) {
                        throw new InvalidArgumentException('لا يمكن حذف هذا العميل من هذا القسم.');
                    }

                    $db->execute("DELETE FROM customers WHERE id = ?", [$customerId]);
                    logAudit($currentUser['id'], 'manager_delete_company_customer', 'customer', $customerId);

                    $_SESSION['success_message'] = 'تم حذف العميل بنجاح.';
                    redirectAfterPost('customers', [], [], 'manager');
                } catch (InvalidArgumentException $invalidDelete) {
                    $error = $invalidDelete->getMessage();
                } catch (Throwable $deleteError) {
                    error_log('Manager delete company customer error: ' . $deleteError->getMessage());
                    $error = 'تعذر حذف العميل. تحقق من عدم وجود معاملات مرتبطة به.';
                }
            }
        }
    }
}

// التحقق من وجود الأعمدة المطلوبة
try {
    $createdByColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
    if (empty($createdByColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN created_by INT NULL AFTER status");
    }
} catch (Throwable $migrationError) {
    error_log('Customers created_by migration error: ' . $migrationError->getMessage());
}

try {
    $latitudeColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'latitude'");
    if (empty($latitudeColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER address");
    }

    $longitudeColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'longitude'");
    if (empty($longitudeColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
    }

    $locationCapturedColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'location_captured_at'");
    if (empty($locationCapturedColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN location_captured_at DATETIME NULL AFTER longitude");
    }
} catch (Throwable $locationMigrationError) {
    error_log('Customers location migration error: ' . $locationMigrationError->getMessage());
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = trim($_GET['search'] ?? '');
$debtStatus = $_GET['debt_status'] ?? 'all';
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}

// بناء استعلام SQL لعملاء الشركة فقط
$sql = "SELECT c.*, u.full_name as created_by_name
        FROM customers c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE c.created_by_admin = 1 AND (c.rep_id IS NULL OR c.rep_id = 0)";

$countSql = "SELECT COUNT(*) as total FROM customers WHERE created_by_admin = 1 AND (rep_id IS NULL OR rep_id = 0)";
$statsSql = "SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS debtor_count,
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt
            FROM customers
            WHERE created_by_admin = 1 AND (rep_id IS NULL OR rep_id = 0)";
$params = [];
$countParams = [];
$statsParams = [];

// إضافة فلاتر البحث
if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $statsSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $searchWildcard = '%' . $search . '%';
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $countParams[] = $searchWildcard;
    $countParams[] = $searchWildcard;
    $countParams[] = $searchWildcard;
    $statsParams[] = $searchWildcard;
    $statsParams[] = $searchWildcard;
    $statsParams[] = $searchWildcard;
}

// فلتر حالة الديون
if ($debtStatus === 'debtor') {
    $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
    $statsSql .= " AND (balance IS NOT NULL AND balance > 0)";
} elseif ($debtStatus === 'clear') {
    $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    $countSql .= " AND (balance IS NULL OR balance <= 0)";
    $statsSql .= " AND (balance IS NULL OR balance <= 0)";
}

// ترتيب النتائج
$sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// تنفيذ الاستعلامات
try {
    $countResult = $db->queryOne($countSql, $countParams);
    $totalCustomers = (int)($countResult['total'] ?? 0);

    $statsResult = $db->queryOne($statsSql, $statsParams);
    if ($statsResult) {
        $customerStats['total_count'] = (int)($statsResult['total_count'] ?? 0);
        $customerStats['debtor_count'] = (int)($statsResult['debtor_count'] ?? 0);
        $customerStats['total_debt'] = (float)($statsResult['total_debt'] ?? 0.0);
    }

    $totalPages = max(1, (int)ceil($totalCustomers / $perPage));
    $customers = $db->query($sql, $params);
} catch (Throwable $queryError) {
    error_log('Company customers query error: ' . $queryError->getMessage());
    $customers = [];
    $totalCustomers = 0;
    $totalPages = 1;
}

// حساب إجمالي التحصيلات
try {
    $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
    if (!empty($collectionsTableExists)) {
        $collectionsSql = "SELECT COALESCE(SUM(col.amount), 0) AS total_collections
            FROM collections col
            INNER JOIN customers c ON col.customer_id = c.id
            WHERE c.created_by_admin = 1 AND (c.rep_id IS NULL OR c.rep_id = 0)";
        $collectionsParams = [];
        
        if ($search !== '') {
            $collectionsSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
            $searchWildcard = '%' . $search . '%';
            $collectionsParams[] = $searchWildcard;
            $collectionsParams[] = $searchWildcard;
            $collectionsParams[] = $searchWildcard;
        }
        
        if ($debtStatus === 'debtor') {
            $collectionsSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
        } elseif ($debtStatus === 'clear') {
            $collectionsSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
        }
        
        $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
        $totalCollectionsAmount = (float)($collectionsResult['total_collections'] ?? 0);
    }
} catch (Throwable $collectionsError) {
    error_log('Company customers collections error: ' . $collectionsError->getMessage());
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <h2 class="mb-2 mb-md-0">
        <i class="bi bi-building me-2"></i>عملاء الشركة
    </h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
        <i class="bi bi-person-plus me-2"></i>إضافة عميل جديد
    </button>
</div>

<?php if ($error): ?>
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

<style>
    .customers-search-card .card-body {
        padding: 1.25rem 1.5rem;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(59, 130, 246, 0));
        border-radius: 20px;
    }

    .customers-search-card .form-control,
    .customers-search-card .form-select {
        height: 48px;
        border-radius: 0.85rem;
        font-weight: 500;
    }

    .customers-search-card .btn {
        height: 48px;
        border-radius: 0.85rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }

    .customers-list-card .card-header {
        padding: 1rem 1.35rem;
        border-radius: 18px 18px 0 0;
    }

    .customers-list-card .card-body {
        padding: 1.35rem 1.25rem;
    }

    .customers-table-container.dashboard-table-wrapper {
        overflow-x: auto;
        overflow-y: hidden;
        border-radius: 18px;
    }

    .customers-table-container .table {
        min-width: 780px;
    }
</style>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">عدد العملاء</div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format($customerStats['total_count']); ?></div>
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
                    <div class="fs-4 fw-bold text-warning mb-0"><?php echo number_format($customerStats['debtor_count']); ?></div>
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
                    <div class="fs-4 fw-bold text-danger mb-0"><?php echo formatCurrency($customerStats['total_debt']); ?></div>
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
                    <div class="fs-4 fw-bold text-success mb-0"><?php echo formatCurrency($totalCollectionsAmount); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- البحث -->
<div class="card shadow-sm mb-4 customers-search-card">
    <div class="card-body">
        <form method="GET" action="<?php echo htmlspecialchars(getRelativeUrl($customersBaseScript)); ?>" class="row g-2 g-md-3 align-items-end">
            <input type="hidden" name="page" value="customers">
            <div class="col-12 col-md-6 col-lg-5">
                <label for="customerSearch" class="visually-hidden">بحث عن العملاء</label>
                <div class="input-group input-group-sm shadow-sm">
                    <span class="input-group-text bg-light text-muted border-end-0">
                        <i class="bi bi-search"></i>
                    </span>
                    <input
                        type="text"
                        class="form-control border-start-0"
                        id="customerSearch"
                        name="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="بحث سريع بالاسم أو الهاتف"
                        autocomplete="off"
                    >
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <label for="debtStatusFilter" class="visually-hidden">تصفية حسب حالة الديون</label>
                <select class="form-select form-select-sm shadow-sm" id="debtStatusFilter" name="debt_status">
                    <option value="all" <?php echo $debtStatus === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="debtor" <?php echo $debtStatus === 'debtor' ? 'selected' : ''; ?>>مدين</option>
                    <option value="clear" <?php echo $debtStatus === 'clear' ? 'selected' : ''; ?>>غير مدين / لديه رصيد</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>
                    <span>بحث</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة العملاء -->
<div class="card shadow-sm customers-list-card">
    <div class="card-header bg-primary text-white">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <h5 class="mb-0">
                <i class="bi bi-people-fill me-2"></i>قائمة عملاء الشركة
                (<?php echo number_format($totalCustomers); ?>)
            </h5>
            <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                <i class="bi bi-person-plus me-1"></i>إضافة عميل جديد
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper customers-table-container">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>رقم الهاتف</th>
                        <th>الرصيد</th>
                        <th>العنوان</th>
                        <th>الموقع</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">لا توجد عملاء</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                        $customerBalanceValue = isset($customer['balance']) ? (float) $customer['balance'] : 0.0;
                                        $balanceBadgeClass = $customerBalanceValue > 0
                                            ? 'bg-warning-subtle text-warning'
                                            : ($customerBalanceValue < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
                                        $displayBalanceValue = $customerBalanceValue < 0 ? abs($customerBalanceValue) : $customerBalanceValue;
                                    ?>
                                    <strong><?php echo formatCurrency($displayBalanceValue); ?></strong>
                                    <?php if ($customerBalanceValue !== 0.0): ?>
                                        <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                                            <?php echo $customerBalanceValue > 0 ? 'رصيد مدين' : 'رصيد دائن'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                    $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                                        $customer['latitude'] !== null &&
                                        $customer['longitude'] !== null;
                                    $latValue = $hasLocation ? (float)$customer['latitude'] : null;
                                    $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
                                    ?>
                                    <?php if ($hasLocation): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info location-view-btn"
                                            data-latitude="<?php echo htmlspecialchars(number_format($latValue, 8, '.', '')); ?>"
                                            data-longitude="<?php echo htmlspecialchars(number_format($lngValue, 8, '.', '')); ?>"
                                        >
                                            <i class="bi bi-map me-1"></i>عرض
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDate($customer['created_at'] ?? ''); ?></td>
                                <td>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editCustomerModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                            data-customer-balance="<?php echo number_format($customerBalanceValue, 2, '.', ''); ?>"
                                        >
                                            <i class="bi bi-pencil me-1"></i>تعديل
                                        </button>
                                        <?php if ($currentRole === 'manager'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteCustomerModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-trash me-1"></i>حذف
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo $baseQueryString; ?>&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars(getRelativeUrl($customersBaseScript)); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="action" value="add_customer">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="number" name="balance" class="form-control" step="0.01" value="0">
                        <div class="form-text">أدخل قيمة موجبة للديون الحالية (إن وجدت).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ العميل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars(getRelativeUrl($customersBaseScript)); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات العميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="number" name="balance" class="form-control" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Customer Modal -->
<div class="modal fade" id="deleteCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars(getRelativeUrl($customersBaseScript)); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="action" value="delete_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>حذف العميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">هل أنت متأكد من حذف العميل <strong class="delete-customer-name">-</strong>؟ لا يمكن التراجع عن هذه العملية.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var editModal = document.getElementById('editCustomerModal');
    var deleteModal = document.getElementById('deleteCustomerModal');

    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;

            var customerId = button.getAttribute('data-customer-id') || '';
            var customerName = button.getAttribute('data-customer-name') || '';
            var customerPhone = button.getAttribute('data-customer-phone') || '';
            var customerAddress = button.getAttribute('data-customer-address') || '';
            var customerBalance = button.getAttribute('data-customer-balance') || '0';

            this.querySelector('input[name="customer_id"]').value = customerId;
            this.querySelector('input[name="name"]').value = customerName;
            this.querySelector('input[name="phone"]').value = customerPhone;
            this.querySelector('textarea[name="address"]').value = customerAddress;
            this.querySelector('input[name="balance"]').value = customerBalance;
        });
    }

    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            var customerId = button.getAttribute('data-customer-id') || '';
            var customerName = button.getAttribute('data-customer-name') || '-';
            this.querySelector('input[name="customer_id"]').value = customerId;
            this.querySelector('.delete-customer-name').textContent = customerName;
        });
    }

    // معالجة أزرار عرض الموقع
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