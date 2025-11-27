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
$allowedSections = ['company', 'representatives', 'rep_customers'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'company';
}

$dashboardScript = basename($_SERVER['PHP_SELF'] ?? 'manager.php');
$basePageUrl = getRelativeUrl($dashboardScript . '?page=customers');

// معالجة update_location (AJAX فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_POST['action']) 
    && trim($_POST['action']) === 'update_location'
    && ($section === 'company' || isset($_GET['section']) && $_GET['section'] === 'company')) {
    
    // التأكد من أن الطلب AJAX
    $isAjaxRequest = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
    
    if (!$isAjaxRequest) {
        // إذا لم يكن AJAX، تجاهل الطلب وتابع التحميل العادي
        // لا نخرج هنا لأن هذا قد يكون طلب POST عادي
    } else {
        // تنظيف أي output سابق بشكل كامل
        while (ob_get_level() > 0) {
            ob_end_clean();
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
            'message' => 'إحداثيات الموقع خارج النطاق المسموح.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // التأكد من وجود الأعمدة
        $latitudeColumn = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'latitude'");
        if (empty($latitudeColumn)) {
            $db->execute("ALTER TABLE customers ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER address");
        }
        $longitudeColumn = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'longitude'");
        if (empty($longitudeColumn)) {
            $db->execute("ALTER TABLE customers ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
        }
        $locationCapturedColumn = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'location_captured_at'");
        if (empty($locationCapturedColumn)) {
            $db->execute("ALTER TABLE customers ADD COLUMN location_captured_at TIMESTAMP NULL AFTER longitude");
        }

        // التحقق من وجود العميل
        $customer = $db->queryOne("SELECT id FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            throw new InvalidArgumentException('العميل غير موجود.');
        }

        // تحديث الموقع
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
        exit;
    } catch (InvalidArgumentException $invalidLocation) {
        echo json_encode([
            'success' => false,
            'message' => $invalidLocation->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $updateLocationError) {
        error_log('Update customer location error: ' . $updateLocationError->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء تحديث الموقع.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    }
}

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
        } elseif ($action === 'collect_debt') {
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
                        "SELECT id, name, balance FROM customers WHERE id = ? FOR UPDATE",
                        [$customerId]
                    );

                    if (!$customer) {
                        throw new InvalidArgumentException('لم يتم العثور على العميل المطلوب.');
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

                    // حفظ التحصيل في جدول collections
                    $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
                    if (!empty($collectionsTableExists)) {
                        try {
                            $db->execute(
                                "INSERT INTO collections (customer_id, amount, status, created_by, created_at)
                                 VALUES (?, ?, 'approved', ?, NOW())",
                                [$customerId, $amount, $currentUser['id']]
                            );
                        } catch (Throwable $collectionError) {
                            error_log('Manager collect debt - collection insert error: ' . $collectionError->getMessage());
                        }
                    }

                    $db->commit();
                    $transactionStarted = false;

                    $_SESSION['success_message'] = 'تم تحصيل المبلغ بنجاح.';
                    redirectAfterPost('customers', ['section' => 'company'], [], $currentRole);
                } catch (InvalidArgumentException $invalidCollect) {
                    if ($transactionStarted) {
                        $db->rollBack();
                    }
                    $error = $invalidCollect->getMessage();
                } catch (Throwable $collectError) {
                    if ($transactionStarted) {
                        $db->rollBack();
                    }
                    error_log('Manager collect debt error: ' . $collectError->getMessage());
                    $error = 'تعذر تحصيل المبلغ. يرجى المحاولة لاحقاً.';
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

// معاملات البحث المتقدم
$searchEmail = trim($_GET['search_email'] ?? '');
$searchAddress = trim($_GET['search_address'] ?? '');
$hasLocation = isset($_GET['has_location']) ? $_GET['has_location'] : '';
$balanceMin = isset($_GET['balance_min']) && $_GET['balance_min'] !== '' ? cleanFinancialValue($_GET['balance_min'], true) : null;
$balanceMax = isset($_GET['balance_max']) && $_GET['balance_max'] !== '' ? cleanFinancialValue($_GET['balance_max'], true) : null;

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
    
    // البحث المتقدم
    if ($searchEmail !== '') {
        $whereParts[] = 'c.email LIKE ?';
        $emailWildcard = '%' . $searchEmail . '%';
        $listParams[] = $emailWildcard;
        $countParams[] = $emailWildcard;
        $statsParams[] = $emailWildcard;
    }
    
    if ($searchAddress !== '') {
        $whereParts[] = 'c.address LIKE ?';
        $addressWildcard = '%' . $searchAddress . '%';
        $listParams[] = $addressWildcard;
        $countParams[] = $addressWildcard;
        $statsParams[] = $addressWildcard;
    }
    
    if ($hasLocation === '1') {
        $whereParts[] = '(c.latitude IS NOT NULL AND c.longitude IS NOT NULL)';
    } elseif ($hasLocation === '0') {
        $whereParts[] = '(c.latitude IS NULL OR c.longitude IS NULL)';
    }
    
    if ($balanceMin !== null) {
        $whereParts[] = 'c.balance >= ?';
        $listParams[] = $balanceMin;
        $countParams[] = $balanceMin;
        $statsParams[] = $balanceMin;
    }
    
    if ($balanceMax !== null) {
        $whereParts[] = 'c.balance <= ?';
        $listParams[] = $balanceMax;
        $countParams[] = $balanceMax;
        $statsParams[] = $balanceMax;
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
        SELECT c.*, c.latitude, c.longitude, c.location_captured_at
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
        // التحقق من وجود الأعمدة في جدول users
        $hasLastLoginAt = false;
        $hasProfileImage = false;
        $hasProfilePhoto = false;
        try {
            $lastLoginCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'last_login_at'");
            $hasLastLoginAt = !empty($lastLoginCheck);
            $profileImageCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_image'");
            $hasProfileImage = !empty($profileImageCheck);
            $profilePhotoCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_photo'");
            $hasProfilePhoto = !empty($profilePhotoCheck);
        } catch (Throwable $e) {
            // تجاهل الخطأ
        }
        
        // بناء SELECT و GROUP BY بشكل ديناميكي
        $selectColumns = [
            'u.id',
            'u.full_name',
            'u.username',
            'u.phone',
            'u.email',
            'u.status'
        ];
        
        $groupByColumns = [
            'u.id',
            'u.full_name',
            'u.username',
            'u.phone',
            'u.email',
            'u.status'
        ];
        
        if ($hasLastLoginAt) {
            $selectColumns[] = 'u.last_login_at';
            $groupByColumns[] = 'u.last_login_at';
        } else {
            $selectColumns[] = 'NULL AS last_login_at';
        }
        
        if ($hasProfileImage) {
            $selectColumns[] = 'u.profile_image';
            $groupByColumns[] = 'u.profile_image';
        } elseif ($hasProfilePhoto) {
            $selectColumns[] = 'u.profile_photo AS profile_image';
            $groupByColumns[] = 'u.profile_photo';
        } else {
            $selectColumns[] = 'NULL AS profile_image';
        }
        
        $selectColumns[] = 'COUNT(DISTINCT c.id) AS customer_count';
        $selectColumns[] = 'COALESCE(SUM(CASE WHEN c.balance > 0 THEN c.balance ELSE 0 END), 0) AS total_debt';
        $selectColumns[] = 'COALESCE(SUM(CASE WHEN c.balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count';
        
        $selectSql = implode(', ', $selectColumns);
        $groupBySql = implode(', ', $groupByColumns);
        
        $representatives = $db->query(
            "SELECT {$selectSql}
            FROM users u
            LEFT JOIN customers c ON (c.rep_id = u.id OR c.created_by = u.id)
            WHERE u.role = 'sales'
            GROUP BY {$groupBySql}
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
