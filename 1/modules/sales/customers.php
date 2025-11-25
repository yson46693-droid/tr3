
<?php
/**
 * صفحة إدارة العملاء للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/customer_history.php';

requireRole(['sales', 'accountant', 'manager']);

require_once __DIR__ . '/table_styles.php';

$currentUser = getCurrentUser();
$isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);
$section = $_GET['section'] ?? ($_POST['section'] ?? 'company');
$allowedSections = $isSalesUser ? ['company'] : ['company', 'delegates'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'company';
}
$baseQueryString = '?page=customers';
if ($section) {
    $baseQueryString .= '&section=' . urlencode($section);
}
$customerStats = [
    'total_count' => 0,
    'debtor_count' => 0,
    'total_debt' => 0.0,
];
$totalCollectionsAmount = 0.0;

// جلب رسالة النجاح من الجلسة (إن وجدت) بعد عمليات إعادة التوجيه
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess !== null) {
    $success = $sessionSuccess;
}

// تحديد المسار الأساسي للروابط بناءً على دور المستخدم
$currentRole = strtolower((string)($currentUser['role'] ?? 'sales'));
$customersBaseScript = 'sales.php';
if ($currentRole === 'manager') {
    $customersBaseScript = 'manager.php';
} elseif ($currentRole === 'accountant') {
    $customersBaseScript = 'accountant.php';
}
$customersPageBase = $customersBaseScript . '?page=customers';
$customersPageBaseWithSection = $customersPageBase . '&section=' . urlencode($section);

// معالجة طلبات سجل مشتريات العميل (للمدير فقط)
if (
    $currentRole === 'manager' &&
    isset($_GET['ajax'], $_GET['action']) &&
    $_GET['ajax'] === 'purchase_history' &&
    $_GET['action'] === 'purchase_history'
) {
    header('Content-Type: application/json; charset=utf-8');

    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    if ($customerId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'معرف العميل غير صالح.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $historyPayload = customerHistoryGetHistory($customerId);
        echo json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $historyError) {
        error_log('customers purchase history ajax error: ' . $historyError->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'تعذر تحميل سجل مشتريات العميل.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// بيانات قسم مناديب المبيعات
$delegateSearch = '';
$delegates = [];
$delegateCustomersMap = [];
$delegateSummary = [
    'total_delegates'      => 0,
    'total_customers'      => 0,
    'debtor_customers'     => 0,
    'total_debt'           => 0.0,
    'active_delegates'     => 0,
    'inactive_delegates'   => 0,
];

if ($section === 'delegates' && !$isSalesUser) {
    $delegateSearch = trim($_GET['delegate_search'] ?? '');
    $searchFilterSql = '';
    $searchParams = [];

    if ($delegateSearch !== '') {
        $searchLike = '%' . $delegateSearch . '%';
        $searchFilterSql = "
            AND (
                u.full_name LIKE ?
                OR u.username LIKE ?
                OR u.email LIKE ?
                OR u.phone LIKE ?
            )";
        $searchParams = [$searchLike, $searchLike, $searchLike, $searchLike];
    }

    try {
        $delegatesQuery = "
            SELECT 
                u.id,
                u.full_name,
                u.username,
                u.email,
                u.phone,
                u.status,
                u.last_login_at,
                COALESCE(COUNT(c.id), 0) AS customer_count,
                COALESCE(SUM(CASE WHEN c.balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count,
                COALESCE(SUM(CASE WHEN c.balance > 0 THEN c.balance ELSE 0 END), 0) AS total_debt,
                COALESCE(MAX(c.updated_at), MAX(c.created_at)) AS last_activity_at
            FROM users u
            LEFT JOIN customers c ON c.created_by = u.id
            WHERE u.role = 'sales'
              {$searchFilterSql}
            GROUP BY u.id, u.full_name, u.username, u.email, u.phone, u.status, u.last_login_at
            ORDER BY customer_count DESC, u.full_name ASC
        ";

        $delegates = $db->query($delegatesQuery, $searchParams);

        if (!empty($delegates)) {
            $delegateIds = array_map(static function ($delegate) {
                return (int)($delegate['id'] ?? 0);
            }, $delegates);
            $delegateIds = array_filter($delegateIds);

            foreach ($delegates as $delegateRow) {
                $delegateStatus = strtolower((string)($delegateRow['status'] ?? 'inactive'));
                $delegateSummary['total_delegates']++;
                if ($delegateStatus === 'active') {
                    $delegateSummary['active_delegates']++;
                } else {
                    $delegateSummary['inactive_delegates']++;
                }
                $delegateSummary['total_customers'] += (int)($delegateRow['customer_count'] ?? 0);
                $delegateSummary['debtor_customers'] += (int)($delegateRow['debtor_count'] ?? 0);
                $delegateSummary['total_debt'] += (float)($delegateRow['total_debt'] ?? 0.0);
            }

            if (!empty($delegateIds)) {
                $placeholders = implode(',', array_fill(0, count($delegateIds), '?'));
                $customersByDelegate = $db->query(
                    "
                        SELECT 
                            c.id,
                            c.name,
                            c.phone,
                            c.address,
                            c.balance,
                            c.status,
                            c.latitude,
                            c.longitude,
                            c.created_at,
                            c.updated_at,
                            c.created_by
                        FROM customers c
                        WHERE c.created_by IN ({$placeholders})
                        ORDER BY c.name ASC
                    ",
                    $delegateIds
                );

                foreach ($customersByDelegate as $customerRow) {
                    $ownerId = (int)($customerRow['created_by'] ?? 0);
                    if ($ownerId <= 0) {
                        continue;
                    }
                    if (!isset($delegateCustomersMap[$ownerId])) {
                        $delegateCustomersMap[$ownerId] = [];
                    }
                    $delegateCustomersMap[$ownerId][] = [
                        'id'                   => (int)($customerRow['id'] ?? 0),
                        'name'                 => (string)($customerRow['name'] ?? ''),
                        'phone'                => (string)($customerRow['phone'] ?? ''),
                        'address'              => (string)($customerRow['address'] ?? ''),
                        'balance'              => (float)($customerRow['balance'] ?? 0.0),
                        'balance_formatted'    => formatCurrency((float)($customerRow['balance'] ?? 0.0)),
                        'status'               => (string)($customerRow['status'] ?? ''),
                        'latitude'             => $customerRow['latitude'] !== null ? (float)$customerRow['latitude'] : null,
                        'longitude'            => $customerRow['longitude'] !== null ? (float)$customerRow['longitude'] : null,
                        'created_at'           => (string)($customerRow['created_at'] ?? ''),
                        'created_at_formatted' => formatDateTime($customerRow['created_at'] ?? ''),
                        'updated_at'           => (string)($customerRow['updated_at'] ?? ''),
                        'updated_at_formatted' => formatDateTime($customerRow['updated_at'] ?? ''),
                    ];
                }
            }
        }
    } catch (Throwable $delegatesError) {
        error_log('Delegates customers section error: ' . $delegatesError->getMessage());
        $delegates = [];
        $delegateCustomersMap = [];
        $delegateSummary = [
            'total_delegates'      => 0,
            'total_customers'      => 0,
            'debtor_customers'     => 0,
            'total_debt'           => 0.0,
            'active_delegates'     => 0,
            'inactive_delegates'   => 0,
        ];
        $error = $error ?: 'تعذر تحميل بيانات مناديب المبيعات في الوقت الحالي. يرجى المحاولة لاحقاً.';
    }
}

// معالجة طلبات AJAX أولاً قبل أي output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    
    // معالجة update_location قبل أي شيء آخر
    if ($action === 'update_location') {
        // تنظيف أي output سابق
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // التأكد من أن الطلب AJAX
        $isAjaxRequest = (
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        );
        
        if (!$isAjaxRequest) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'طلب غير صالح.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;

        if ($customerId <= 0 || $latitude === null || $longitude === null) {
            echo json_encode([
                'success' => false,
                'message' => 'بيانات الموقع غير مكتملة.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            echo json_encode([
                'success' => false,
                'message' => 'إحداثيات الموقع غير صالحة.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $latitude = (float)$latitude;
        $longitude = (float)$longitude;

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            echo json_encode([
                'success' => false,
                'message' => 'نطاق الإحداثيات غير صحيح.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $customer = $db->queryOne("SELECT id, created_by FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                throw new InvalidArgumentException('العميل المطلوب غير موجود.');
            }

            if ($isSalesUser && (int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
                throw new InvalidArgumentException('غير مصرح لك بتحديث موقع هذا العميل.');
            }

            $db->execute(
                "UPDATE customers SET latitude = ?, longitude = ?, location_captured_at = NOW() WHERE id = ?",
                [$latitude, $longitude, $customerId]
            );

            logAudit(
                $currentUser['id'],
                'update_customer_location',
                'customer',
                $customerId,
                null,
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]
            );

            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث موقع العميل بنجاح.',
            ], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $invalidLocation) {
            echo json_encode([
                'success' => false,
                'message' => $invalidLocation->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $updateLocationError) {
            error_log('Update customer location error: ' . $updateLocationError->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ الموقع. حاول مرة أخرى.',
            ], JSON_UNESCAPED_UNICODE);
        }

        exit;
    }

    if ($action === 'collect_debt') {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;

        if ($customerId <= 0) {
            $error = 'معرف العميل غير صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } else {
            $transactionStarted = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $customer = $db->queryOne(
                    "SELECT id, name, balance, created_by FROM customers WHERE id = ? FOR UPDATE",
                    [$customerId]
                );

                if (!$customer) {
                    throw new InvalidArgumentException('لم يتم العثور على العميل المطلوب.');
                }

                if ($isSalesUser && (int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
                    throw new InvalidArgumentException('غير مصرح لك بتحصيل ديون هذا العميل.');
                }

                $currentBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;

                if ($currentBalance <= 0) {
                    throw new InvalidArgumentException('لا توجد ديون نشطة على هذا العميل.');
                }

                if ($amount > $currentBalance) {
                    throw new InvalidArgumentException('المبلغ المدخل أكبر من ديون العميل الحالية.');
                }

                $newBalance = round(max($currentBalance - $amount, 0), 2);

                $db->execute(
                    "UPDATE customers SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );

                logAudit(
                    $currentUser['id'],
                    'collect_customer_debt',
                    'customer',
                    $customerId,
                    null,
                    [
                        'collected_amount'   => $amount,
                        'previous_balance'   => $currentBalance,
                        'new_balance'        => $newBalance,
                    ]
                );

                $db->commit();
                $transactionStarted = false;

                $_SESSION['success_message'] = 'تم تحصيل المبلغ بنجاح.';

                $redirectFilters = [];
                if (!empty($section)) {
                    $redirectFilters['section'] = $section;
                }

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
                    strtolower((string)($currentUser['role'] ?? 'manager'))
                );
            } catch (InvalidArgumentException $userError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $error = $userError->getMessage();
            } catch (Throwable $collectionError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('Customer collection error: ' . $collectionError->getMessage());
                $error = 'حدث خطأ أثناء تحصيل المبلغ. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance']) : 0;

        if (empty($name)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            try {
                if (!empty($phone)) {
                    $existing = $db->queryOne("SELECT id FROM customers WHERE phone = ?", [$phone]);
                    if ($existing) {
                        throw new InvalidArgumentException('رقم الهاتف موجود بالفعل');
                    }
                }

                $result = $db->execute(
                    "INSERT INTO customers (name, phone, balance, address, status, created_by) 
                     VALUES (?, ?, ?, ?, 'active', ?)",
                    [$name, $phone ?: null, $balance, $address ?: null, $currentUser['id']]
                );

                logAudit($currentUser['id'], 'add_customer', 'customer', $result['insert_id'], null, [
                    'name' => $name
                ]);

                $_SESSION['success_message'] = 'تم إضافة العميل بنجاح';

                $redirectFilters = [];
                if (!empty($section)) {
                    $redirectFilters['section'] = $section;
                }

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
                    strtolower((string)($currentUser['role'] ?? 'manager'))
                );
            } catch (InvalidArgumentException $userError) {
                $error = $userError->getMessage();
            } catch (Throwable $addCustomerError) {
                error_log('Add customer error: ' . $addCustomerError->getMessage());
                $error = 'حدث خطأ أثناء إضافة العميل. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

try {
    $createdByColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
    if (empty($createdByColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN created_by INT NULL AFTER status");
        $db->execute("ALTER TABLE customers ADD CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
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

// بناء استعلام SQL
$sql = "SELECT c.*, u.full_name as created_by_name
        FROM customers c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM customers WHERE 1=1";
$statsSql = "SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS debtor_count,
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt
            FROM customers
            WHERE 1=1";
$params = [];
$countParams = [];
$statsParams = [];

if ($isSalesUser) {
    $sql .= " AND c.created_by = ?";
    $countSql .= " AND created_by = ?";
    $statsSql .= " AND created_by = ?";
    $params[] = $currentUser['id'];
    $countParams[] = $currentUser['id'];
    $statsParams[] = $currentUser['id'];
}

if ($debtStatus === 'debtor') {
    $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
    $statsSql .= " AND (balance IS NOT NULL AND balance > 0)";
} elseif ($debtStatus === 'clear') {
    $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    $countSql .= " AND (balance IS NULL OR balance <= 0)";
    $statsSql .= " AND (balance IS NULL OR balance <= 0)";
}

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $statsSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCustomers = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCustomers / $perPage);

$sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$customers = $db->query($sql, $params);
$statsResult = $db->queryOne($statsSql, $statsParams);
if (!empty($statsResult)) {
    $customerStats['total_count'] = (int)($statsResult['total_count'] ?? 0);
    $customerStats['debtor_count'] = (int)($statsResult['debtor_count'] ?? 0);
    $customerStats['total_debt'] = (float)($statsResult['total_debt'] ?? 0);
}

try {
    $collectionsStatusExists = false;
    $statusCheck = $db->query("SHOW COLUMNS FROM collections LIKE 'status'");
    if (!empty($statusCheck)) {
        $collectionsStatusExists = true;
    }

    $collectionsSql = "SELECT COALESCE(SUM(col.amount), 0) AS total_collections
                       FROM collections col
                       INNER JOIN customers c ON col.customer_id = c.id
                       WHERE 1=1";
    $collectionsParams = [];

    if ($collectionsStatusExists) {
        $collectionsSql .= " AND col.status = 'approved'";
    }

    if ($isSalesUser) {
        $collectionsSql .= " AND c.created_by = ?";
        $collectionsParams[] = $currentUser['id'];
    }

    if ($debtStatus === 'debtor') {
        $collectionsSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    } elseif ($debtStatus === 'clear') {
        $collectionsSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    }

    if ($search) {
        $collectionsSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
        $collectionsParams[] = '%' . $search . '%';
        $collectionsParams[] = '%' . $search . '%';
        $collectionsParams[] = '%' . $search . '%';
    }

    $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
    if (!empty($collectionsResult)) {
        $totalCollectionsAmount = (float)($collectionsResult['total_collections'] ?? 0);
    }
} catch (Throwable $collectionsError) {
    error_log('Customers collections summary error: ' . $collectionsError->getMessage());
}

$summaryDebtorCount = $customerStats['debtor_count'] ?? 0;
$summaryTotalDebt = $customerStats['total_debt'] ?? 0.0;
$summaryTotalCustomers = $customerStats['total_count'] ?? $totalCustomers;
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <h2 class="mb-2 mb-md-0">
        <i class="bi bi-people me-2"></i><?php echo $isSalesUser ? 'عملائي' : 'العملاء'; ?>
    </h2>
    <?php if ($section === 'company'): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
        <i class="bi bi-person-plus me-2"></i>إضافة عميل جديد
    </button>
    <?php endif; ?>
</div>

<?php if (!$isSalesUser): ?>
    <ul class="nav nav-pills gap-2 mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'company' ? 'active' : ''; ?>" href="<?php echo getRelativeUrl($customersPageBase . '&section=company'); ?>">
                <i class="bi bi-building me-2"></i>عملاء الشركة
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'delegates' ? 'active' : ''; ?>" href="<?php echo getRelativeUrl($customersPageBase . '&section=delegates'); ?>">
                <i class="bi bi-people-fill me-2"></i>عملاء المندوبين
            </a>
        </li>
    </ul>
<?php else: ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <div class="flex-grow-1">
            هذه الصفحة تعرض فقط العملاء الذين قمت بإضافتهم أو متابعتهم بصفتك مندوب المبيعات الحالي.
        </div>
    </div>
<?php endif; ?>

<?php if ($section === 'company'): ?>

<?php
$customersLabel = $isSalesUser ? 'عدد عملائي' : 'عدد العملاء';
$debtorsLabel = $isSalesUser ? 'عملائي المدينون' : 'العملاء المدينون';
$totalDebtLabel = $isSalesUser ? 'إجمالي ديون عملائي' : 'إجمالي الديون';
$collectionsLabel = $isSalesUser ? 'تحصيلاتي' : 'إجمالي التحصيلات';
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold"><?php echo $customersLabel; ?></div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format((int)$summaryTotalCustomers); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-people-fill"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold"><?php echo $debtorsLabel; ?></div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format((int)$summaryDebtorCount); ?></div>
                </div>
                <span class="text-danger display-6"><i class="bi bi-exclamation-circle-fill"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold"><?php echo $totalDebtLabel; ?></div>
                    <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($summaryTotalDebt); ?></div>
                </div>
                <span class="text-warning display-6"><i class="bi bi-cash-stack"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold"><?php echo $collectionsLabel; ?></div>
                    <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($totalCollectionsAmount); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
</div>

<?php if ($currentRole === 'manager'): ?>
<!-- Modal سجل مشتريات العميل -->
<div class="modal fade" id="customerHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="bi bi-journal-text me-2"></i>
                    سجل مشتريات العميل
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-4 fw-bold customer-history-name">-</div>
                    <div class="text-muted small">
                        يعرض هذا السجل آخر ستة أشهر فقط من مشتريات العميل.
                    </div>
                </div>

                <div class="customer-history-loading text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>

                <div class="alert alert-danger d-none customer-history-error"></div>

                <div class="customer-history-content d-none">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">عدد الفواتير</div>
                                    <div class="fs-4 fw-semibold history-total-invoices">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">إجمالي المشتريات</div>
                                    <div class="fs-4 fw-semibold history-total-invoiced">0.00</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">إجمالي المرتجعات</div>
                                    <div class="fs-4 fw-semibold history-total-returns text-danger">0.00</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">القيمة الصافية</div>
                                    <div class="fs-4 fw-semibold history-net-total">0.00</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>الفواتير خلال آخر 6 أشهر</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0 customer-history-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>رقم الفاتورة</th>
                                            <th>التاريخ</th>
                                            <th>الإجمالي</th>
                                            <th>المدفوع</th>
                                            <th>المرتجعات</th>
                                            <th>الاستبدالات</th>
                                            <th>الصافي</th>
                                            <th>الحالة</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-secondary text-white">
                                    <h6 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>المرتجعات الأخيرة</h6>
                                </div>
                                <div class="card-body customer-history-returns">
                                    <div class="text-muted">لا توجد مرتجعات خلال الفترة.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-secondary text-white">
                                    <h6 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>حالات الاستبدال</h6>
                                </div>
                                <div class="card-body customer-history-exchanges">
                                    <div class="text-muted">لا توجد حالات استبدال خلال الفترة.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($currentRole === 'manager'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var historyModal = document.getElementById('customerHistoryModal');
    if (!historyModal) {
        return;
    }

    var purchaseHistoryEndpointBase = <?php echo json_encode($customersPageBaseWithSection); ?>;
    var nameTarget = historyModal.querySelector('.customer-history-name');
    var loadingIndicator = historyModal.querySelector('.customer-history-loading');
    var errorAlert = historyModal.querySelector('.customer-history-error');
    var contentWrapper = historyModal.querySelector('.customer-history-content');
    var invoicesTableBody = historyModal.querySelector('.customer-history-table tbody');
    var returnsContainer = historyModal.querySelector('.customer-history-returns');
    var exchangesContainer = historyModal.querySelector('.customer-history-exchanges');
    var totalInvoicesEl = historyModal.querySelector('.history-total-invoices');
    var totalInvoicedEl = historyModal.querySelector('.history-total-invoiced');
    var totalReturnsEl = historyModal.querySelector('.history-total-returns');
    var netTotalEl = historyModal.querySelector('.history-net-total');

    function formatCurrency(value) {
        var number = Number(value || 0);
        return number.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    }

    function renderInvoices(rows) {
        if (!invoicesTableBody) {
            return;
        }
        invoicesTableBody.innerHTML = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 8;
            emptyCell.className = 'text-center text-muted py-4';
            emptyCell.textContent = 'لا توجد فواتير خلال النافذة الزمنية.';
            emptyRow.appendChild(emptyCell);
            invoicesTableBody.appendChild(emptyRow);
            return;
        }

        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${row.invoice_number || '—'}</td>
                <td>${row.invoice_date || '—'}</td>
                <td>${formatCurrency(row.invoice_total || 0)}</td>
                <td>${formatCurrency(row.paid_amount || 0)}</td>
                <td>
                    <span class="text-danger fw-semibold">${formatCurrency(row.return_total || 0)}</span>
                    <div class="text-muted small">${row.return_count || 0} مرتجع</div>
                </td>
                <td>
                    <span class="text-success fw-semibold">${formatCurrency(row.exchange_total || 0)}</span>
                    <div class="text-muted small">${row.exchange_count || 0} استبدال</div>
                </td>
                <td>${formatCurrency(row.net_total || 0)}</td>
                <td>${row.invoice_status || '—'}</td>
            `;
            invoicesTableBody.appendChild(tr);
        });
    }

    function renderReturns(list) {
        if (!returnsContainer) {
            return;
        }
        returnsContainer.innerHTML = '';

        if (!Array.isArray(list) || list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'text-muted';
            empty.textContent = 'لا توجد مرتجعات خلال الفترة.';
            returnsContainer.appendChild(empty);
            return;
        }

        var group = document.createElement('div');
        group.className = 'list-group list-group-flush';

        list.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'list-group-item';
            row.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">رقم المرتجع: ${item.return_number || '—'}</div>
                        <div class="text-muted small">
                            التاريخ: ${item.return_date || '—'} | النوع: ${item.return_type || '—'}
                        </div>
                    </div>
                    <div class="text-danger fw-semibold">${formatCurrency(item.refund_amount || 0)}</div>
                </div>
                <div class="text-muted small mt-1">الحالة: ${item.status || '—'}</div>
            `;
            group.appendChild(row);
        });

        returnsContainer.appendChild(group);
    }

    function renderExchanges(list) {
        if (!exchangesContainer) {
            return;
        }
        exchangesContainer.innerHTML = '';

        if (!Array.isArray(list) || list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'text-muted';
            empty.textContent = 'لا توجد حالات استبدال خلال الفترة.';
            exchangesContainer.appendChild(empty);
            return;
        }

        var group = document.createElement('div');
        group.className = 'list-group list-group-flush';

        list.forEach(function (item) {
            var difference = Number(item.difference_amount || 0);
            var row = document.createElement('div');
            row.className = 'list-group-item';
            row.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">رقم الاستبدال: ${item.exchange_number || '—'}</div>
                        <div class="text-muted small">
                            التاريخ: ${item.exchange_date || '—'} | النوع: ${item.exchange_type || '—'}
                        </div>
                    </div>
                    <div class="fw-semibold ${difference >= 0 ? 'text-success' : 'text-danger'}">
                        ${formatCurrency(difference)}
                    </div>
                </div>
                <div class="text-muted small mt-1">الحالة: ${item.status || '—'}</div>
            `;
            group.appendChild(row);
        });

        exchangesContainer.appendChild(group);
    }

    function resetModalState() {
        if (errorAlert) {
            errorAlert.classList.add('d-none');
            errorAlert.textContent = '';
        }
        if (contentWrapper) {
            contentWrapper.classList.add('d-none');
        }
        if (loadingIndicator) {
            loadingIndicator.classList.remove('d-none');
        }
        if (invoicesTableBody) {
            invoicesTableBody.innerHTML = '';
        }
        if (returnsContainer) {
            returnsContainer.innerHTML = '';
        }
        if (exchangesContainer) {
            exchangesContainer.innerHTML = '';
        }
    }

    var historyButtons = document.querySelectorAll('.js-customer-history');
    historyButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name') || '-';

            if (nameTarget) {
                nameTarget.textContent = customerName;
            }
            resetModalState();

            var modalInstance = bootstrap.Modal.getOrCreateInstance(historyModal);
            modalInstance.show();

            var url = purchaseHistoryEndpointBase
                + '&action=purchase_history&ajax=purchase_history&customer_id='
                + encodeURIComponent(customerId);

            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('تعذر تحميل البيانات.');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.message ? payload.message : 'فشل تحميل بيانات السجل.');
                    }

                    var history = payload.history || {};
                    var totals = history.totals || {};

                    if (totalInvoicesEl) {
                        totalInvoicesEl.textContent = Number(totals.invoice_count || 0).toLocaleString('ar-EG');
                    }
                    if (totalInvoicedEl) {
                        totalInvoicedEl.textContent = formatCurrency(totals.total_invoiced || 0);
                    }
                    if (totalReturnsEl) {
                        totalReturnsEl.textContent = formatCurrency(totals.total_returns || 0);
                    }
                    if (netTotalEl) {
                        netTotalEl.textContent = formatCurrency(totals.net_total || 0);
                    }

                    renderInvoices(history.invoices || []);
                    renderReturns(history.returns || []);
                    renderExchanges(history.exchanges || []);

                    if (loadingIndicator) {
                        loadingIndicator.classList.add('d-none');
                    }
                    if (contentWrapper) {
                        contentWrapper.classList.remove('d-none');
                    }
                })
                .catch(function (error) {
                    if (loadingIndicator) {
                        loadingIndicator.classList.add('d-none');
                    }
                    if (errorAlert) {
                        errorAlert.textContent = error.message || 'حدث خطأ غير متوقع.';
                        errorAlert.classList.remove('d-none');
                    }
                });
        });
    });

    historyModal.addEventListener('hidden.bs.modal', resetModalState);
});
</script>
<?php endif; ?>

<?php endif; ?>

<?php if ($section === 'delegates' && !$isSalesUser): ?>

<?php
$delegatesSummaryTitle = 'ملخص مناديب المبيعات';
$delegateCards = [
    [
        'label' => 'إجمالي المناديب',
        'value' => number_format((int)$delegateSummary['total_delegates']),
        'icon'  => 'bi-people-fill',
        'class' => 'primary'
    ],
    [
        'label' => 'المناديب النشطون',
        'value' => number_format((int)$delegateSummary['active_delegates']),
        'icon'  => 'bi-person-check-fill',
        'class' => 'success'
    ],
    [
        'label' => 'إجمالي العملاء',
        'value' => number_format((int)$delegateSummary['total_customers']),
        'icon'  => 'bi-people',
        'class' => 'info'
    ],
    [
        'label' => 'إجمالي ديون العملاء',
        'value' => formatCurrency($delegateSummary['total_debt']),
        'icon'  => 'bi-cash-stack',
        'class' => 'warning'
    ],
];
?>

<div class="delegates-summary-grid mb-4">
    <?php foreach ($delegateCards as $card): ?>
        <div class="delegates-summary-card border-0 shadow-sm h-100 delegates-summary-card-<?php echo htmlspecialchars($card['class']); ?>">
            <div class="delegates-summary-icon">
                <i class="bi <?php echo htmlspecialchars($card['icon']); ?>"></i>
            </div>
            <div class="delegates-summary-content">
                <div class="delegates-summary-label"><?php echo htmlspecialchars($card['label']); ?></div>
                <div class="delegates-summary-value"><?php echo htmlspecialchars($card['value']); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-4 delegates-search-card">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 g-md-3 align-items-end">
            <input type="hidden" name="page" value="customers">
            <input type="hidden" name="section" value="delegates">
            <div class="col-md-10">
                <label class="form-label fw-semibold text-muted">البحث عن مندوب</label>
                <input
                    type="text"
                    class="form-control"
                    name="delegate_search"
                    value="<?php echo htmlspecialchars($delegateSearch); ?>"
                    placeholder="ابحث باسم المندوب، البريد، الهاتف..."
                >
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-search me-2"></i>بحث
                </button>
                <?php if ($delegateSearch !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo getRelativeUrl($customersPageBase . '&section=delegates'); ?>">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm delegates-list-card">
    <div class="card-header bg-primary text-white">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <h5 class="mb-0">
                <i class="bi bi-people-fill me-2"></i>مناديب المبيعات
                (<?php echo number_format((int)$delegateSummary['total_delegates']); ?>)
            </h5>
            <span class="small text-white-50">إجمالي العملاء: <?php echo number_format((int)$delegateSummary['total_customers']); ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper delegates-table-container">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>المندوب</th>
                        <th>التواصل</th>
                        <th>عدد العملاء</th>
                        <th>العملاء المدينون</th>
                        <th>إجمالي الديون</th>
                        <th>آخر نشاط</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($delegates)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                لا توجد بيانات للمندوبين حالياً.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($delegates as $delegate): ?>
                            <?php
                            $delegateId = (int)($delegate['id'] ?? 0);
                            $statusRaw = strtolower((string)($delegate['status'] ?? 'inactive'));
                            $statusBadgeClass = 'secondary';
                            $statusLabel = 'غير محدد';

                            if ($statusRaw === 'active') {
                                $statusBadgeClass = 'success';
                                $statusLabel = 'نشط';
                            } elseif (in_array($statusRaw, ['inactive', 'suspended', 'disabled'], true)) {
                                $statusBadgeClass = 'secondary';
                                $statusLabel = 'غير نشط';
                            } elseif ($statusRaw === 'pending') {
                                $statusBadgeClass = 'warning';
                                $statusLabel = 'بانتظار التفعيل';
                            }

                            $delegateCustomers = $delegateCustomersMap[$delegateId] ?? [];
                            $delegateCustomersJson = htmlspecialchars(
                                json_encode($delegateCustomers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ENT_QUOTES,
                                'UTF-8'
                            );

                            $lastActivity = $delegate['last_activity_at'] ?? null;
                            $lastActivityFormatted = $lastActivity ? formatDateTime($lastActivity) : '—';

                            $contactInfoParts = [];
                            if (!empty($delegate['phone'])) {
                                $contactInfoParts[] = htmlspecialchars($delegate['phone']);
                            }
                            if (!empty($delegate['email'])) {
                                $contactInfoParts[] = htmlspecialchars($delegate['email']);
                            }
                            $contactInfo = !empty($contactInfoParts) ? implode('<br>', $contactInfoParts) : '<span class="text-muted">غير متوفر</span>';
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-primary"><?php echo htmlspecialchars($delegate['full_name'] ?: $delegate['username'] ?: '—'); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($delegate['username'] ?? ''); ?></div>
                                </td>
                                <td><?php echo $contactInfo; ?></td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary fw-semibold">
                                        <?php echo number_format((int)($delegate['customer_count'] ?? 0)); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-warning-subtle text-warning fw-semibold">
                                        <?php echo number_format((int)($delegate['debtor_count'] ?? 0)); ?>
                                    </span>
                                </td>
                                <td><?php echo formatCurrency($delegate['total_debt'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($lastActivityFormatted); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $statusBadgeClass; ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary view-delegate-customers-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#delegateCustomersModal"
                                        data-delegate-id="<?php echo $delegateId; ?>"
                                        data-delegate-name="<?php echo htmlspecialchars($delegate['full_name'] ?: $delegate['username'] ?: '—', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-delegate-customers="<?php echo $delegateCustomersJson; ?>"
                                        data-total-customers="<?php echo (int)($delegate['customer_count'] ?? 0); ?>"
                                        data-total-debt="<?php echo htmlspecialchars(formatCurrency($delegate['total_debt'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="bi bi-eye me-1"></i>عرض العملاء
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="delegateCustomersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-people me-2"></i>عملاء المندوب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">المندوب</div>
                    <div class="fs-5 fw-bold delegate-modal-name">-</div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="delegate-modal-stat">
                            <div class="delegate-modal-stat-label">عدد العملاء</div>
                            <div class="delegate-modal-stat-value delegate-modal-total-customers">0</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="delegate-modal-stat">
                            <div class="delegate-modal-stat-label">إجمالي الديون</div>
                            <div class="delegate-modal-stat-value delegate-modal-total-debt">0</div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive delegate-modal-table">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>العميل</th>
                                <th>رقم الهاتف</th>
                                <th>العنوان</th>
                                <th>الرصيد</th>
                                <th>آخر تحديث</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="delegate-modal-body">
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    لا توجد بيانات متاحة.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<style>
    .delegates-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }

    .delegates-summary-card {
        border-radius: 18px;
        padding: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        position: relative;
        overflow: hidden;
        background: #fff;
    }

    .delegates-summary-icon {
        height: 52px;
        width: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        color: #fff;
        flex-shrink: 0;
    }

    .delegates-summary-card-primary .delegates-summary-icon {
        background: linear-gradient(135deg, #2563eb, #3b82f6);
    }

    .delegates-summary-card-success .delegates-summary-icon {
        background: linear-gradient(135deg, #16a34a, #22c55e);
    }

    .delegates-summary-card-info .delegates-summary-icon {
        background: linear-gradient(135deg, #0ea5e9, #38bdf8);
    }

    .delegates-summary-card-warning .delegates-summary-icon {
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
    }

    .delegates-summary-content {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .delegates-summary-label {
        font-size: 0.9rem;
        color: #6b7280;
        font-weight: 600;
    }

    .delegates-summary-value {
        font-size: 1.4rem;
        font-weight: 700;
        color: #0f172a;
    }

    .delegates-search-card .card-body {
        padding: 1.35rem 1.5rem;
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(59, 130, 246, 0));
    }

    .delegates-search-card .form-control {
        border-radius: 0.95rem;
        height: 52px;
        font-weight: 500;
    }

    .delegates-search-card .btn {
        height: 52px;
        border-radius: 0.95rem;
        font-weight: 600;
    }

    .delegates-list-card .card-header {
        padding: 1.1rem 1.5rem;
        border-radius: 18px 18px 0 0;
    }

    .delegates-list-card .card-body {
        padding: 1.35rem 1.25rem;
    }

    .delegates-table-container.table-responsive {
        border-radius: 18px;
        overflow-x: auto;
    }

    .delegates-table-container table {
        min-width: 920px;
    }

    .delegate-modal-stat {
        border: 1px solid rgba(99, 102, 241, 0.2);
        border-radius: 14px;
        padding: 0.85rem 1rem;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(59, 130, 246, 0.04));
    }

    .delegate-modal-stat-label {
        font-size: 0.8rem;
        color: #6b7280;
        font-weight: 600;
    }

    .delegate-modal-stat-value {
        font-size: 1.2rem;
        font-weight: 700;
        color: #1d4ed8;
    }

    .delegate-location-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    @media (max-width: 992px) {
        .delegates-summary-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .delegates-search-card .card-body {
            padding: 1.1rem 1.25rem 1.25rem;
        }

        .delegates-search-card .col-md-10,
        .delegates-search-card .col-md-2 {
            flex: 0 0 100%;
            max-width: 100%;
        }
    }

    @media (max-width: 768px) {
        .delegates-list-card .card-body {
            padding: 1.1rem 0.75rem 1rem;
        }

        .delegates-table-container table {
            min-width: 820px;
            font-size: 0.95rem;
        }
    }

    @media (max-width: 576px) {
        .delegates-summary-card {
            border-radius: 16px;
        }

        .delegates-list-card .card-header {
            border-radius: 16px 16px 0 0;
        }

        .delegates-list-card .card-body {
            padding: 1rem 0.5rem 0.9rem;
        }

        .delegates-table-container table {
            min-width: 720px;
            font-size: 0.88rem;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var delegatesModal = document.getElementById('delegateCustomersModal');
    if (!delegatesModal) {
        return;
    }

    var nameElement = delegatesModal.querySelector('.delegate-modal-name');
    var totalCustomersElement = delegatesModal.querySelector('.delegate-modal-total-customers');
    var totalDebtElement = delegatesModal.querySelector('.delegate-modal-total-debt');
    var tableBody = delegatesModal.querySelector('.delegate-modal-body');

    function renderEmptyState(message) {
        if (!tableBody) {
            return;
        }
        tableBody.innerHTML = '';
        var emptyRow = document.createElement('tr');
        var emptyCell = document.createElement('td');
        emptyCell.colSpan = 7;
        emptyCell.className = 'text-center text-muted py-4';
        emptyCell.textContent = message || 'لا توجد بيانات متاحة.';
        emptyRow.appendChild(emptyCell);
        tableBody.appendChild(emptyRow);
    }

    function createBadge(status) {
        var badge = document.createElement('span');
        var normalized = (status || '').toString().toLowerCase();
        var badgeClass = 'bg-secondary';
        var label = 'غير محدد';

        if (normalized === 'active') {
            badgeClass = 'bg-success';
            label = 'نشط';
        } else if (normalized === 'inactive') {
            badgeClass = 'bg-secondary';
            label = 'غير نشط';
        } else if (normalized === 'suspended') {
            badgeClass = 'bg-danger';
            label = 'موقوف';
        } else if (normalized === 'pending') {
            badgeClass = 'bg-warning text-dark';
            label = 'بانتظار التفعيل';
        }

        badge.className = 'badge ' + badgeClass;
        badge.textContent = label;
        return badge;
    }

    function buildCustomerRow(customer) {
        var row = document.createElement('tr');

        var nameCell = document.createElement('td');
        var nameStrong = document.createElement('strong');
        nameStrong.textContent = customer.name || '—';
        nameCell.appendChild(nameStrong);
        row.appendChild(nameCell);

        var phoneCell = document.createElement('td');
        phoneCell.textContent = customer.phone || '—';
        row.appendChild(phoneCell);

        var addressCell = document.createElement('td');
        addressCell.textContent = customer.address || '—';
        row.appendChild(addressCell);

        var balanceCell = document.createElement('td');
        balanceCell.textContent = customer.balance_formatted || customer.balance || '0';
        row.appendChild(balanceCell);

        var updatedCell = document.createElement('td');
        updatedCell.textContent = customer.updated_at_formatted || customer.created_at_formatted || '—';
        row.appendChild(updatedCell);

        var statusCell = document.createElement('td');
        statusCell.appendChild(createBadge(customer.status || ''));
        row.appendChild(statusCell);

        var actionsCell = document.createElement('td');
        if (customer.latitude !== null && customer.longitude !== null) {
            var mapLink = document.createElement('a');
            mapLink.className = 'btn btn-sm btn-outline-info delegate-location-link';
            mapLink.href = 'https://www.google.com/maps?q='
                + encodeURIComponent(String(customer.latitude) + ',' + String(customer.longitude))
                + '&hl=ar&z=16';
            mapLink.target = '_blank';
            mapLink.rel = 'noopener';
            mapLink.innerHTML = '<i class="bi bi-geo-alt"></i>عرض الموقع';
            actionsCell.appendChild(mapLink);
        } else {
            var noLocation = document.createElement('span');
            noLocation.className = 'text-muted small';
            noLocation.textContent = 'لا يوجد موقع';
            actionsCell.appendChild(noLocation);
        }
        row.appendChild(actionsCell);

        return row;
    }

    delegatesModal.addEventListener('show.bs.modal', function (event) {
        var triggerButton = event.relatedTarget;
        if (!triggerButton || !tableBody || !nameElement || !totalCustomersElement || !totalDebtElement) {
            return;
        }

        var delegateName = triggerButton.getAttribute('data-delegate-name') || '-';
        var customersJson = triggerButton.getAttribute('data-delegate-customers') || '[]';
        var totalCustomers = parseInt(triggerButton.getAttribute('data-total-customers') || '0', 10) || 0;
        var totalDebt = triggerButton.getAttribute('data-total-debt') || '0';

        nameElement.textContent = delegateName;
        totalCustomersElement.textContent = totalCustomers.toLocaleString('ar-EG');
        totalDebtElement.textContent = totalDebt;

        var customersData = [];
        try {
            customersData = JSON.parse(customersJson);
        } catch (parseError) {
            console.warn('Unable to parse delegate customers payload.', parseError);
            customersData = [];
        }

        tableBody.innerHTML = '';

        if (!Array.isArray(customersData) || customersData.length === 0) {
            renderEmptyState('لا توجد عملاء مرتبطة بهذا المندوب حالياً.');
            return;
        }

        customersData.forEach(function (customer) {
            tableBody.appendChild(buildCustomerRow(customer || {}));
        });
    });

    delegatesModal.addEventListener('hidden.bs.modal', function () {
        if (nameElement) {
            nameElement.textContent = '-';
        }
        if (totalCustomersElement) {
            totalCustomersElement.textContent = '0';
        }
        if (totalDebtElement) {
            totalDebtElement.textContent = '0';
        }
        renderEmptyState('لا توجد بيانات متاحة.');
    });
});
</script>

<?php endif; ?>

<?php if ($section === 'company'): ?>

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

    .customers-table-container .table thead th,
    .customers-table-container .table tbody td {
        white-space: nowrap;
    }

    .customers-table-container::-webkit-scrollbar {
        height: 8px;
    }

    .customers-table-container::-webkit-scrollbar-track {
        background: rgba(226, 232, 240, 0.6);
        border-radius: 999px;
    }

    .customers-table-container::-webkit-scrollbar-thumb {
        background: rgba(37, 99, 235, 0.35);
        border-radius: 999px;
    }

    .customers-table-container::-webkit-scrollbar-thumb:hover {
        background: rgba(37, 99, 235, 0.55);
    }

    @media (max-width: 992px) {
        .customers-search-card .col-md-8,
        .customers-search-card .col-md-2 {
            flex: 0 0 100%;
            max-width: 100%;
        }

        .customers-search-card .card-body {
            padding: 1.1rem 1rem 1.35rem;
        }
    }

    @media (max-width: 768px) {
        .customers-list-card .card-body {
            padding: 1.1rem 0.75rem 1rem;
        }

        .customers-table-container .table {
            min-width: 720px;
            font-size: 0.92rem;
        }
    }

    @media (max-width: 576px) {
        .customers-search-card .card-body {
            padding: 1rem 0.75rem 1.25rem;
            border-radius: 16px;
        }

        .customers-table-container .table {
            min-width: 660px;
            font-size: 0.86rem;
        }

        .customers-list-card .card-header {
            border-radius: 16px 16px 0 0;
        }

        .customers-list-card .card-body {
            padding: 1rem 0.5rem 0.9rem;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var GEO_PROMPT_STORAGE_KEY = 'customersGeoPromptResponse';

    function markGeoPromptState(state) {
        try {
            localStorage.setItem(GEO_PROMPT_STORAGE_KEY, state);
        } catch (storageError) {
            console.warn('Unable to persist geolocation prompt state.', storageError);
        }
    }

    function shouldAskForGeolocation() {
        try {
            var stored = localStorage.getItem(GEO_PROMPT_STORAGE_KEY);
            return stored === null || stored === '';
        } catch (storageError) {
            console.warn('Unable to read geolocation prompt state.', storageError);
            return true;
        }
    }

    function requestInitialGeolocation() {
        if (!('geolocation' in navigator)) {
            return;
        }

        var handleResult = function (status) {
            markGeoPromptState(status);
        };

        var askPermission = function () {
            var confirmMessage = 'يحتاج النظام إلى الوصول إلى موقع جهازك لتحديد مواقع العملاء بدقة. هل ترغب في منح الصلاحية الآن؟';
            if (window.confirm(confirmMessage)) {
                navigator.geolocation.getCurrentPosition(function () {
                    handleResult('granted');
                }, function (error) {
                    if (error && error.code === error.PERMISSION_DENIED) {
                        handleResult('denied');
                        showAlert('لم يتم منح صلاحية الموقع. يمكنك تمكينها لاحقاً من إعدادات المتصفح.');
                    } else {
                        handleResult('error');
                    }
                }, {
                    enableHighAccuracy: false,
                    timeout: 8000,
                    maximumAge: 0
                });
            } else {
                handleResult('dismissed');
            }
        };

        if (!shouldAskForGeolocation()) {
            return;
        }

        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function (result) {
                if (result.state === 'prompt') {
                    setTimeout(askPermission, 600);
                } else {
                    handleResult(result.state);
                }
            }).catch(function () {
                setTimeout(askPermission, 600);
            });
        } else {
            setTimeout(askPermission, 600);
        }
    }

    requestInitialGeolocation();

    var collectionModal = document.getElementById('collectPaymentModal');
    if (collectionModal) {
        var nameElement = collectionModal.querySelector('.collection-customer-name');
        var debtElement = collectionModal.querySelector('.collection-current-debt');
        var customerIdInput = collectionModal.querySelector('input[name="customer_id"]');
        var amountInput = collectionModal.querySelector('input[name="amount"]');

        if (nameElement && debtElement && customerIdInput && amountInput) {
            collectionModal.addEventListener('show.bs.modal', function (event) {
                var triggerButton = event.relatedTarget;
                if (!triggerButton) {
                    return;
                }

                var customerName = triggerButton.getAttribute('data-customer-name') || '-';
                var balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
                var balanceFormatted = triggerButton.getAttribute('data-customer-balance-formatted') || balanceRaw;
                var numericBalance = parseFloat(balanceRaw);
                if (!Number.isFinite(numericBalance)) {
                    numericBalance = 0;
                }
                var debtAmount = numericBalance > 0 ? numericBalance : 0;

                nameElement.textContent = customerName;
                debtElement.textContent = balanceFormatted;
                customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';

                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
                amountInput.focus();
            });

            collectionModal.addEventListener('hidden.bs.modal', function () {
                nameElement.textContent = '-';
                debtElement.textContent = '-';
                customerIdInput.value = '';
                amountInput.value = '';
                amountInput.removeAttribute('max');
            });
        }
    }

    var locationCaptureButtons = document.querySelectorAll('.location-capture-btn');
    var viewLocationModal = document.getElementById('viewLocationModal');
    var locationMapFrame = viewLocationModal ? viewLocationModal.querySelector('.location-map-frame') : null;
    var locationCustomerName = viewLocationModal ? viewLocationModal.querySelector('.location-customer-name') : null;
    var locationExternalLink = viewLocationModal ? viewLocationModal.querySelector('.location-open-map') : null;
    var locationViewButtons = document.querySelectorAll('.location-view-btn');

    function setButtonLoading(button, isLoading) {
        if (!button) {
            return;
        }

        if (isLoading) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>جارٍ التحديد';
        } else {
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
            button.disabled = false;
        }
    }

    function showAlert(message) {
        window.alert(message);
    }

    locationCaptureButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name') || '';

            if (!customerId) {
                showAlert('تعذر تحديد العميل.');
                return;
            }

            if (!navigator.geolocation) {
                showAlert('المتصفح الحالي لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

            setButtonLoading(button, true);

            navigator.geolocation.getCurrentPosition(function (position) {
                var latitude = position.coords.latitude.toFixed(8);
                var longitude = position.coords.longitude.toFixed(8);
                var requestUrl = window.location.pathname + window.location.search;

                var formData = new URLSearchParams();
                formData.append('action', 'update_location');
                formData.append('customer_id', customerId);
                formData.append('latitude', latitude);
                formData.append('longitude', longitude);

                fetch(requestUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData.toString()
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    return response.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error:', text);
                            throw new Error('استجابة غير صالحة من الخادم');
                        }
                    });
                }).then(function (data) {
                    if (data && data.success) {
                        showAlert('تم حفظ موقع العميل ' + customerName + ' بنجاح.');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        setButtonLoading(button, false);
                        showAlert(data && data.message ? data.message : 'تعذر حفظ الموقع.');
                    }
                }).catch(function (error) {
                    setButtonLoading(button, false);
                    console.error('Location update error:', error);
                    showAlert('حدث خطأ أثناء الاتصال بالخادم: ' + (error.message || 'خطأ غير معروف'));
                });
            }, function (error) {
                setButtonLoading(button, false);
                var errorMessage = 'تعذر الحصول على الموقع الحالي.';
                if (error && error.code === error.PERMISSION_DENIED) {
                    errorMessage = 'تم رفض صلاحية الوصول إلى الموقع. يرجى السماح بذلك من إعدادات المتصفح.';
                } else if (error && error.code === error.POSITION_UNAVAILABLE) {
                    errorMessage = 'معلومات الموقع غير متاحة حالياً.';
                } else if (error && error.code === error.TIMEOUT) {
                    errorMessage = 'انتهى الوقت قبل الحصول على الموقع. حاول مرة أخرى.';
                }
                showAlert(errorMessage);
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            });
        });
    });

    if (viewLocationModal && locationMapFrame && locationCustomerName && locationExternalLink) {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');
                var customerName = button.getAttribute('data-customer-name') || '-';

                if (!latitude || !longitude) {
                    showAlert('لا يوجد موقع مسجل لهذا العميل.');
                    return;
                }

                locationCustomerName.textContent = customerName;
                var embedUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                var externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                locationMapFrame.src = embedUrl;
                locationExternalLink.href = externalUrl;

                if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                    var modalInstance = window.bootstrap.Modal.getOrCreateInstance(viewLocationModal);
                    modalInstance.show();
                } else {
                    window.open(externalUrl, '_blank');
                }
            });
        });

        viewLocationModal.addEventListener('hidden.bs.modal', function () {
            locationMapFrame.src = '';
            locationCustomerName.textContent = '-';
            locationExternalLink.href = '#';
        });
    } else {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');
                if (!latitude || !longitude) {
                    showAlert('لا يوجد موقع مسجل لهذا العميل.');
                    return;
                }
                var externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                window.open(externalUrl, '_blank');
            });
        });
    }
});
</script>

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

<!-- البحث -->
<div class="card shadow-sm mb-4 customers-search-card">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 g-md-3 align-items-end">
            <input type="hidden" name="page" value="customers">
            <?php if ($section): ?>
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
            <?php endif; ?>
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
        <h5 class="mb-0">
            <?php echo $isSalesUser ? 'قائمة عملائي' : 'قائمة عملاء الشركة'; ?>
            (<?php echo $totalCustomers; ?>)
        </h5>
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
                            <td colspan="7" class="text-center text-muted">لا توجد عملاء</td>
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
                                    ?>
                                    <strong><?php echo formatCurrency($customerBalanceValue); ?></strong>
                                    <?php if ($customerBalanceValue !== 0.0): ?>
                                        <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                                            <?php echo $customerBalanceValue > 0 ? 'رصيد مستحق' : 'رصيد دائن'; ?>
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
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary location-capture-btn"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-geo-alt me-1"></i>تحديد
                                        </button>
                                        <?php if ($hasLocation): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-info location-view-btn"
                                                data-customer-id="<?php echo (int)$customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-latitude="<?php echo htmlspecialchars(number_format($latValue, 8, '.', '')); ?>"
                                                data-longitude="<?php echo htmlspecialchars(number_format($lngValue, 8, '.', '')); ?>"
                                            >
                                                <i class="bi bi-map me-1"></i>عرض
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo formatDate($customer['created_at']); ?></td>
                                <td>
                                    <?php
                                    $customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                                    $formattedBalance = formatCurrency($customerBalance);
                                    $rawBalance = number_format($customerBalance, 2, '.', '');
                                    ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm <?php echo $customerBalance > 0 ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#collectPaymentModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-balance="<?php echo $rawBalance; ?>"
                                            data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                                            <?php echo $customerBalance > 0 ? '' : 'disabled'; ?>
                                        >
                                            <i class="bi bi-cash-coin me-1"></i>تحصيل
                                        </button>
                                        <?php if ($currentRole === 'manager'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-dark js-customer-history"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-journal-text me-1"></i>سجل المشتريات
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

<!-- Modal تحصيل ديون العميل -->
<div class="modal fade" id="collectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="collect_debt">
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="collectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="collectionAmount"
                            name="amount"
                            step="0.01"
                            min="0.01"
                            required
                        >
                        <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal عرض موقع العميل -->
<div class="modal fade" id="viewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-5 fw-bold location-customer-name">-</div>
                </div>
                <div class="ratio ratio-16x9">
                    <iframe
                        class="location-map-frame border rounded"
                        src=""
                        title="معاينة موقع العميل"
                        allowfullscreen
                        loading="lazy"
                    ></iframe>
                </div>
                <p class="mt-3 text-muted mb-0">
                    يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <a href="#" target="_blank" rel="noopener" class="btn btn-primary location-open-map">
                    <i class="bi bi-map"></i> فتح في الخرائط
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة عميل جديد -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عميل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="add_customer">
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" class="form-control" name="phone" placeholder="مثال: 01234567890">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ديون العميل</label>
                        <input type="number" class="form-control" name="balance" step="0.01" value="0" placeholder="مثال: 0 أو -500">
                        <small class="text-muted">يمكن إدخال قيمة سالبة لتمثل رصيداً دائنًا لصالح العميل.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>
