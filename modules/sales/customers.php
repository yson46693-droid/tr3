<?php
/**
 * صفحة إدارة العملاء للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!defined('CUSTOMERS_MODULE_BOOTSTRAPPED')) {
    define('CUSTOMERS_MODULE_BOOTSTRAPPED', true);

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/audit_log.php';
    require_once __DIR__ . '/../../includes/path_helper.php';
    require_once __DIR__ . '/../../includes/customer_history.php';
    require_once __DIR__ . '/../../includes/invoices.php';
    require_once __DIR__ . '/../../includes/salary_calculator.php';

    requireRole(['sales', 'accountant', 'manager']);
}

if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
    require_once __DIR__ . '/table_styles.php';
}

$currentUser = getCurrentUser();
$isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
$db = db();

// معالجة update_location قبل أي شيء آخر لمنع أي output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    // تنظيف أي output سابق بشكل كامل
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
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $invalidLocation->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $updateLocationError) {
        error_log('Update customer location error: ' . $updateLocationError->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ أثناء حفظ الموقع. حاول مرة أخرى.',
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

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

// معالجة طلبات سجل مشتريات العميل (للمدير والمندوب)
// ملاحظة: يجب أن يكون هذا قبل أي output HTML
if (
    in_array($currentRole, ['manager', 'sales'], true) &&
    isset($_GET['ajax'], $_GET['action']) &&
    $_GET['ajax'] === 'purchase_history' &&
    $_GET['action'] === 'purchase_history'
) {
    // تنظيف أي output قبل إرسال JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    if ($customerId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'معرف العميل غير صالح.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // التحقق من ملكية العميل للمندوب (إذا كان المستخدم مندوب)
        if ($isSalesUser) {
            $customer = $db->queryOne("SELECT id, created_by FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                echo json_encode([
                    'success' => false,
                    'message' => 'العميل غير موجود.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // التحقق من أن العميل ينتمي للمندوب
            if ((int)($customer['created_by'] ?? 0) !== (int)$currentUser['id']) {
                echo json_encode([
                    'success' => false,
                    'message' => 'غير مصرح لك بعرض سجل مشتريات هذا العميل.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        $historyPayload = customerHistoryGetHistory($customerId);
        
        // التأكد من أن النتيجة في التنسيق الصحيح
        if (!isset($historyPayload['success'])) {
            $historyPayload = [
                'success' => true,
                'customer' => $historyPayload['customer'] ?? null,
                'history' => $historyPayload['history'] ?? $historyPayload
            ];
        }
        
        // التأكد من أن history موجود وبه البيانات المطلوبة
        if (!isset($historyPayload['history'])) {
            $historyPayload['history'] = [
                'window_start' => date('Y-m-d', strtotime('-6 months')),
                'invoices' => [],
                'totals' => [
                    'invoice_count' => 0,
                    'total_invoiced' => 0.0,
                    'total_paid' => 0.0,
                    'total_returns' => 0.0,
                    'total_exchanges' => 0.0,
                    'net_total' => 0.0,
                ],
                'returns' => [],
                'exchanges' => [],
            ];
        }
        
        // التأكد من أن totals موجود
        if (!isset($historyPayload['history']['totals'])) {
            $historyPayload['history']['totals'] = [
                'invoice_count' => 0,
                'total_invoiced' => 0.0,
                'total_paid' => 0.0,
                'total_returns' => 0.0,
                'total_exchanges' => 0.0,
                'net_total' => 0.0,
            ];
        }
        
        // التأكد من أن المصفوفات موجودة
        if (!isset($historyPayload['history']['invoices']) || !is_array($historyPayload['history']['invoices'])) {
            $historyPayload['history']['invoices'] = [];
        }
        if (!isset($historyPayload['history']['returns']) || !is_array($historyPayload['history']['returns'])) {
            $historyPayload['history']['returns'] = [];
        }
        if (!isset($historyPayload['history']['exchanges']) || !is_array($historyPayload['history']['exchanges'])) {
            $historyPayload['history']['exchanges'] = [];
        }
        
        echo json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $historyError) {
        error_log('customers purchase history ajax error: ' . $historyError->getMessage());
        error_log('customers purchase history ajax error trace: ' . $historyError->getTraceAsString());
        echo json_encode([
            'success' => false,
            'message' => 'تعذر تحميل سجل مشتريات العميل: ' . $historyError->getMessage()
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
        // بناء الاستعلام بشكل آمن
        $delegatesQuery = "
            SELECT 
                u.id,
                u.full_name,
                u.username,
                u.email,
                u.phone,
                u.status,
                u.last_login_at,
                COALESCE(COUNT(DISTINCT c.id), 0) AS customer_count,
                COALESCE(SUM(CASE WHEN c.balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count,
                COALESCE(SUM(CASE WHEN c.balance > 0 THEN c.balance ELSE 0 END), 0) AS total_debt,
                COALESCE(GREATEST(MAX(c.updated_at), MAX(c.created_at)), NULL) AS last_activity_at
            FROM users u
            LEFT JOIN customers c ON c.created_by = u.id
            WHERE u.role = 'sales'
              {$searchFilterSql}
            GROUP BY u.id, u.full_name, u.username, u.email, u.phone, u.status, u.last_login_at
            ORDER BY customer_count DESC, u.full_name ASC
        ";

        // تنفيذ الاستعلام مع معالجة الأخطاء
        try {
            $delegates = $db->query($delegatesQuery, $searchParams);
        } catch (Throwable $queryError) {
            error_log('Delegates query error: ' . $queryError->getMessage());
            error_log('Delegates query SQL: ' . $delegatesQuery);
            error_log('Delegates query params: ' . json_encode($searchParams));
            throw $queryError;
        }

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
                        'balance_formatted'    => formatCurrency(abs((float)($customerRow['balance'] ?? 0.0))),
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
        error_log('Delegates customers section error trace: ' . $delegatesError->getTraceAsString());
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
} else {
    // إذا لم يكن القسم 'delegates' أو كان المستخدم مندوب، تأكد من تهيئة المتغيرات
    if ($section !== 'delegates' || $isSalesUser) {
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
    }
}

// معالجة طلبات AJAX أولاً قبل أي output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);

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

                $collectionNumber = null;
                $collectionId = null;
                $distributionResult = null;

                // حفظ التحصيل في جدول collections ليظهر في صفحات التحصيلات وخزنة المندوب
                $collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
                if (!empty($collectionsTableExists)) {
                    // التحقق من وجود الأعمدة
                    $hasStatusColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'"));
                    $hasCollectionNumberColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'collection_number'"));
                    $hasNotesColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'notes'"));

                    // إضافة عمود collection_number إذا لم يكن موجوداً
                    if (!$hasCollectionNumberColumn) {
                        try {
                            $db->execute("ALTER TABLE collections ADD COLUMN collection_number VARCHAR(50) NULL AFTER id");
                            $hasCollectionNumberColumn = true;
                        } catch (Throwable $alterError) {
                            error_log('Failed to add collection_number column: ' . $alterError->getMessage());
                        }
                    }

                    // توليد رقم التحصيل إذا كان العمود موجوداً
                    if ($hasCollectionNumberColumn) {
                        $year = date('Y');
                        $month = date('m');
                        $lastCollection = $db->queryOne(
                            "SELECT collection_number FROM collections WHERE collection_number LIKE ? ORDER BY collection_number DESC LIMIT 1 FOR UPDATE",
                            ["COL-{$year}{$month}-%"]
                        );

                        $serial = 1;
                        if (!empty($lastCollection['collection_number'])) {
                            $parts = explode('-', $lastCollection['collection_number']);
                            $serial = intval($parts[2] ?? 0) + 1;
                        }

                        $collectionNumber = sprintf("COL-%s%s-%04d", $year, $month, $serial);
                    }

                    // بناء قائمة الأعمدة والقيم
                    $collectionDate = date('Y-m-d');
                    $collectionColumns = ['customer_id', 'amount', 'date', 'payment_method', 'collected_by'];
                    $collectionValues = [$customerId, $amount, $collectionDate, 'cash', $currentUser['id']];
                    $collectionPlaceholders = array_fill(0, count($collectionColumns), '?');

                    if ($hasCollectionNumberColumn && $collectionNumber !== null) {
                        array_unshift($collectionColumns, 'collection_number');
                        array_unshift($collectionValues, $collectionNumber);
                        array_unshift($collectionPlaceholders, '?');
                    }

                    if ($hasNotesColumn) {
                        $collectionColumns[] = 'notes';
                        $collectionValues[] = 'تحصيل من صفحة العملاء';
                        $collectionPlaceholders[] = '?';
                    }

                    if ($hasStatusColumn) {
                        $collectionColumns[] = 'status';
                        $collectionValues[] = 'pending';
                        $collectionPlaceholders[] = '?';
                    }

                    $db->execute(
                        "INSERT INTO collections (" . implode(', ', $collectionColumns) . ") VALUES (" . implode(', ', $collectionPlaceholders) . ")",
                        $collectionValues
                    );

                    $collectionId = $db->getLastInsertId();

                    logAudit(
                        $currentUser['id'],
                        'add_collection_from_customers_page',
                        'collection',
                        $collectionId,
                        null,
                        [
                            'collection_number' => $collectionNumber,
                            'customer_id' => $customerId,
                            'amount' => $amount,
                        ]
                    );
                    
                    // تحديث راتب المندوب المسؤول عن العميل (المستحق للعمولة)
                    // وليس بالضرورة الشخص الذي قام بالتحصيل
                    try {
                        $salesRepId = getSalesRepForCustomer($customerId);
                        if ($salesRepId && $salesRepId > 0) {
                            refreshSalesCommissionForUser(
                                $salesRepId,
                                $collectionDate,
                                'تحديث تلقائي بعد تحصيل من صفحة العملاء'
                            );
                        }
                    } catch (Throwable $e) {
                        // لا نوقف العملية إذا فشل تحديث الراتب
                        error_log('Error updating sales commission after collection from customers page: ' . $e->getMessage());
                    }
                } else {
                    error_log('collect_debt: collections table not found, skipping collection record.');
                }

                $distributionResult = distributeCollectionToInvoices($customerId, $amount, $currentUser['id']);

                $db->commit();
                $transactionStarted = false;

                $messageParts = ['تم تحصيل المبلغ بنجاح.'];
                if ($collectionNumber !== null) {
                    $messageParts[] = 'رقم التحصيل: ' . $collectionNumber . '.';
                }

                if (!empty($distributionResult['updated_invoices'])) {
                    $messageParts[] = 'تم تحديث ' . count($distributionResult['updated_invoices']) . ' فاتورة.';
                } elseif (!empty($distributionResult['message'])) {
                    $messageParts[] = 'ملاحظة: ' . $distributionResult['message'];
                }

                $_SESSION['success_message'] = implode(' ', array_filter($messageParts));

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
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance'], true) : 0;
        $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? trim($_POST['latitude']) : null;
        $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? trim($_POST['longitude']) : null;

        if (empty($name)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            try {
                $repIdForCustomer = $isSalesUser ? (int)$currentUser['id'] : null;
                $createdByAdminFlag = $isSalesUser ? 0 : 1;

                // التحقق من عدم تكرار بيانات العميل الجديد مع عملاء المندوب الحاليين
                if ($repIdForCustomer) {
                    $duplicateCheckConditions = [
                        "(rep_id = ? OR created_by = ?)",
                        "name = ?"
                    ];
                    $duplicateCheckParams = [$repIdForCustomer, $repIdForCustomer, $name];
                    
                    // إضافة فحص رقم الهاتف إذا كان موجوداً
                    if (!empty($phone)) {
                        $duplicateCheckConditions[] = "phone = ?";
                        $duplicateCheckParams[] = $phone;
                    }
                    
                    // إضافة فحص العنوان إذا كان موجوداً
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
                        $duplicateMessage = "يوجد عميل مسجل مسبقاً بنفس البيانات في قائمة عملائك";
                        if (!empty($duplicateInfo)) {
                            $duplicateMessage .= " (" . implode(", ", $duplicateInfo) . ")";
                        }
                        $duplicateMessage .= ". يرجى اختيار العميل الموجود من القائمة أو تعديل البيانات.";
                        throw new InvalidArgumentException($duplicateMessage);
                    }
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
                    $repIdForCustomer,
                    0,
                    $createdByAdminFlag,
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

<?php if (in_array($currentRole, ['manager', 'sales'], true)): ?>
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
                                            <th>المنتج ورقم التشغيلة</th>
                                            <th>التاريخ</th>
                                            <th>الإجمالي</th>
                                            <th>المدفوع</th>
                                            <th>المرتجعات</th>
                                            <th>الاستبدالات</th>
                                            <th>الصافي</th>
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
                <button type="button" class="btn btn-primary" id="printHistoryStatementBtn" onclick="printCustomerStatementFromHistory()">
                    <i class="bi bi-printer me-1"></i>طباعة كشف الحساب
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($currentRole, ['manager', 'sales'], true)): ?>
<script>
// ========== معالج أخطاء عام شامل لمنع ظهور رسائل خطأ classList ==========
(function() {
    'use strict';
    
    // معالج أخطاء عام لمنع ظهور رسائل خطأ classList
    const originalErrorHandler = window.onerror;
    window.onerror = function(message, source, lineno, colno, error) {
        if (message && (
            typeof message === 'string' && (
                message.includes('classList') || 
                message.includes('Cannot read properties of null') ||
                message.includes("Cannot read property 'classList'") ||
                message.includes("reading 'classList'") ||
                message.includes("null is not an object")
            )
        )) {
            console.warn('تم منع خطأ classList:', message);
            return true; // منع ظهور الخطأ
        }
        
        // استدعاء المعالج الأصلي للأخطاء الأخرى
        if (originalErrorHandler && typeof originalErrorHandler === 'function') {
            return originalErrorHandler.apply(this, arguments);
        }
        
        return false;
    };
    
    // معالج أخطاء للوعود المرفوضة
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason) {
            let errorMessage = '';
            if (typeof event.reason === 'string') {
                errorMessage = event.reason;
            } else if (event.reason && typeof event.reason === 'object' && event.reason.message) {
                errorMessage = event.reason.message;
            } else if (event.reason && typeof event.reason === 'object' && event.reason.toString) {
                errorMessage = event.reason.toString();
            }
            
            if (errorMessage && (
                errorMessage.includes('classList') || 
                errorMessage.includes('Cannot read properties of null') ||
                errorMessage.includes("Cannot read property 'classList'") ||
                errorMessage.includes("reading 'classList'")
            )) {
                event.preventDefault();
                console.warn('تم منع خطأ classList في promise:', errorMessage);
                return true;
            }
        }
    }, true);
    
    // معالج أخطاء لأخطاء runtime
    window.addEventListener('error', function(event) {
        if (event.message && (
            event.message.includes('classList') || 
            event.message.includes('Cannot read properties of null') ||
            event.message.includes("Cannot read property 'classList'") ||
            event.message.includes("reading 'classList'")
        )) {
            event.preventDefault();
            event.stopPropagation();
            console.warn('تم منع خطأ classList في event:', event.message);
            return true;
        }
    }, true);
})();
// ========== نهاية معالج الأخطاء ==========

// متغير عام لتخزين معرف العميل الحالي في customerHistoryModal
var currentHistoryCustomerId = null;

// دالة طباعة كشف حساب من customerHistoryModal
function printCustomerStatementFromHistory() {
    if (!currentHistoryCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    const printUrl = basePath + '/print_customer_statement.php?customer_id=' + encodeURIComponent(currentHistoryCustomerId);
    window.open(printUrl, '_blank');
}

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
                <td>
                    <div class="text-wrap" style="max-width: 300px;">
                        ${row.products_info || '—'}
                    </div>
                </td>
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
        if (errorAlert && errorAlert.classList) {
            errorAlert.classList.add('d-none');
            errorAlert.textContent = '';
        }
        if (contentWrapper && contentWrapper.classList) {
            contentWrapper.classList.add('d-none');
        }
        if (loadingIndicator && loadingIndicator.classList) {
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
            
            // حفظ معرف العميل في المتغير العام
            currentHistoryCustomerId = customerId;

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
                        throw new Error('تعذر تحميل البيانات. حالة الخادم: ' + response.status);
                    }
                    // التحقق من نوع المحتوى
                    const contentType = response.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        return response.text().then(function(text) {
                            console.error('Expected JSON but got:', contentType, text);
                            throw new Error('استجابة غير صحيحة من الخادم.');
                        });
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload) {
                        throw new Error('لم يتم استلام أي بيانات من الخادم.');
                    }
                    
                    if (!payload.success) {
                        throw new Error(payload.message || 'فشل تحميل بيانات السجل.');
                    }

                    var history = payload.history || {};
                    var totals = history.totals || {};

                    // تحديث الإحصائيات
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

                    // عرض البيانات (حتى لو كانت فارغة)
                    renderInvoices(Array.isArray(history.invoices) ? history.invoices : []);
                    renderReturns(Array.isArray(history.returns) ? history.returns : []);
                    renderExchanges(Array.isArray(history.exchanges) ? history.exchanges : []);

                    // إخفاء مؤشر التحميل وإظهار المحتوى دائماً
                    if (loadingIndicator && loadingIndicator.classList) {
                        loadingIndicator.classList.add('d-none');
                    }
                    if (contentWrapper && contentWrapper.classList) {
                        contentWrapper.classList.remove('d-none');
                    }
                    // إخفاء رسالة الخطأ إذا كانت موجودة
                    if (errorAlert && errorAlert.classList) {
                        errorAlert.classList.add('d-none');
                    }
                })
                .catch(function (error) {
                    if (loadingIndicator && loadingIndicator.classList) {
                        loadingIndicator.classList.add('d-none');
                    }
                    if (errorAlert && errorAlert.classList) {
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

<!-- Modal سجل مشتريات العميل - إنشاء مرتجع -->
<?php if (in_array($currentRole, ['manager', 'sales'], true)): ?>
<div class="modal fade" id="customerPurchaseHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    سجل مشتريات العميل - إنشاء مرتجع
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <!-- Customer Info -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-muted small">العميل</div>
                                <div class="fs-5 fw-bold" id="purchaseHistoryCustomerName">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">الهاتف</div>
                                <div id="purchaseHistoryCustomerPhone">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">العنوان</div>
                                <div id="purchaseHistoryCustomerAddress">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" 
                                       id="purchaseHistorySearchProduct" 
                                       placeholder="البحث باسم المنتج">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" 
                                       id="purchaseHistorySearchBatch" 
                                       placeholder="البحث برقم التشغيلة">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-primary btn-sm w-100" 
                                        onclick="loadPurchaseHistory()">
                                    <i class="bi bi-search me-1"></i>بحث
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading -->
                <div class="text-center py-4" id="purchaseHistoryLoading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>

                <!-- Error -->
                <div class="alert alert-danger d-none" id="purchaseHistoryError"></div>

                <!-- Purchase History Table -->
                <div id="purchaseHistoryTable" class="d-none">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">
                                        <input type="checkbox" id="selectAllItems" onchange="toggleAllItems()">
                                    </th>
                                    <th>رقم الفاتورة</th>
                                    <th>رقم التشغيلة</th>
                                    <th>اسم المنتج</th>
                                    <th>الكمية المشتراة</th>
                                    <th>الكمية المرتجعة</th>
                                    <th>المتاح للإرجاع</th>
                                    <th>سعر الوحدة</th>
                                    <th>السعر الإجمالي</th>
                                    <th>تاريخ الشراء</th>
                                    <th style="width: 100px;">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="purchaseHistoryTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="printStatementBtn" onclick="printCustomerStatement()" style="display: none;">
                    <i class="bi bi-printer me-1"></i>طباعة كشف الحساب
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" id="createReturnBtn" onclick="openCreateReturnModal()" style="display: none;">
                    <i class="bi bi-arrow-return-left me-1"></i>إنشاء مرتجع
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Create Return Modal -->
<div class="modal fade" id="createReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    إنشاء طلب مرتجع
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="createReturnContent">
                <!-- Content will be filled by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="goBackToPurchaseHistory()">
                    <i class="bi bi-arrow-right me-1"></i>رجوع
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" onclick="submitReturnRequest()">
                    <i class="bi bi-check-circle me-1"></i>إرسال طلب الإرجاع
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ========== معالج أخطاء عام شامل لمنع ظهور رسائل خطأ classList ==========
(function() {
    'use strict';
    
    // معالج أخطاء عام لمنع ظهور رسائل خطأ classList
    const originalErrorHandler = window.onerror;
    window.onerror = function(message, source, lineno, colno, error) {
        if (message && (
            typeof message === 'string' && (
                message.includes('classList') || 
                message.includes('Cannot read properties of null') ||
                message.includes("Cannot read property 'classList'") ||
                message.includes("reading 'classList'") ||
                message.includes("null is not an object")
            )
        )) {
            console.warn('تم منع خطأ classList:', message);
            return true; // منع ظهور الخطأ
        }
        
        // استدعاء المعالج الأصلي للأخطاء الأخرى
        if (originalErrorHandler && typeof originalErrorHandler === 'function') {
            return originalErrorHandler.apply(this, arguments);
        }
        
        return false;
    };
    
    // معالج أخطاء للوعود المرفوضة
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason) {
            let errorMessage = '';
            if (typeof event.reason === 'string') {
                errorMessage = event.reason;
            } else if (event.reason && typeof event.reason === 'object' && event.reason.message) {
                errorMessage = event.reason.message;
            } else if (event.reason && typeof event.reason === 'object' && event.reason.toString) {
                errorMessage = event.reason.toString();
            }
            
            if (errorMessage && (
                errorMessage.includes('classList') || 
                errorMessage.includes('Cannot read properties of null') ||
                errorMessage.includes("Cannot read property 'classList'") ||
                errorMessage.includes("reading 'classList'")
            )) {
                event.preventDefault();
                console.warn('تم منع خطأ classList في promise:', errorMessage);
                return true;
            }
        }
    }, true);
    
    // معالج أخطاء لأخطاء runtime
    window.addEventListener('error', function(event) {
        if (event.message && (
            event.message.includes('classList') || 
            event.message.includes('Cannot read properties of null') ||
            event.message.includes("Cannot read property 'classList'") ||
            event.message.includes("reading 'classList'")
        )) {
            event.preventDefault();
            event.stopPropagation();
            console.warn('تم منع خطأ classList في event:', event.message);
            return true;
        }
    }, true);
})();
// ========== نهاية معالج الأخطاء ==========

const basePath = '<?php echo getBasePath(); ?>';
let currentCustomerId = null;
let selectedItemsForReturn = [];
let purchaseHistoryData = []; // Store original purchase history data

// Open purchase history modal - using event delegation for dynamic content
document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.getElementById('customerPurchaseHistoryModal');
    const createReturnModalElement = document.getElementById('createReturnModal');
    
    // Add event listener to reload data every time modal is shown
    if (modalElement) {
        modalElement.addEventListener('shown.bs.modal', function() {
            // استخدام requestAnimationFrame لضمان أن النموذج أصبح مرئياً بالكامل في DOM
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    // التحقق من وجود العناصر قبل الوصول إليها
                    const productSearch = document.getElementById('purchaseHistorySearchProduct');
                    const batchSearch = document.getElementById('purchaseHistorySearchBatch');
                    const createBtn = document.getElementById('createReturnBtn');
                    const selectAll = document.getElementById('selectAllItems');
                    
                    // Reset filters and selected items
                    if (productSearch) productSearch.value = '';
                    if (batchSearch) batchSearch.value = '';
                    selectedItemsForReturn = [];
                    if (createBtn) createBtn.style.display = 'none';
                    if (selectAll) selectAll.checked = false;
                    
                    // إخفاء زر الطباعة حتى يتم تحميل البيانات
                    const printBtn = document.getElementById('printStatementBtn');
                    if (printBtn) {
                        printBtn.style.display = 'none';
                    }
                    
                    // Reload purchase history if customer ID is set
                    if (currentCustomerId) {
                        loadPurchaseHistory(currentCustomerId);
                    }
                });
            });
        });
        
        // Reset state when modal is hidden
        modalElement.addEventListener('hidden.bs.modal', function() {
            // Clear the table and reset states
            const tableBody = document.getElementById('purchaseHistoryTableBody');
            if (tableBody) {
                tableBody.innerHTML = '';
            }
            selectedItemsForReturn = [];
            purchaseHistoryData = [];
        });
    }
    
    // Clear content when create return modal is hidden
    if (createReturnModalElement) {
        createReturnModalElement.addEventListener('hidden.bs.modal', function() {
            const contentElement = document.getElementById('createReturnContent');
            if (contentElement) {
                contentElement.innerHTML = '<!-- Content will be filled by JavaScript -->';
            }
        });
    }
    
    // Use event delegation to handle clicks on buttons that may be added dynamically
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.js-customer-purchase-history');
        if (!button) return;
        
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        
        if (!customerId) return;
        
        currentCustomerId = customerId;
        
        // Set customer info
        const nameElement = document.getElementById('purchaseHistoryCustomerName');
        if (nameElement) {
            nameElement.textContent = customerName;
        }
        
        // Show modal
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
            
            // Data will be loaded automatically via the shown.bs.modal event listener
        }
    });
});

// Overload function to support calling without parameters (uses currentCustomerId)
function loadPurchaseHistory(customerIdParam, retryCount = 0) {
    const customerId = customerIdParam || currentCustomerId;
    
    if (!customerId) {
        console.error('No customer ID provided for loadPurchaseHistory');
        return;
    }
    
    const loading = document.getElementById('purchaseHistoryLoading');
    const tableDiv = document.getElementById('purchaseHistoryTable');
    const errorDiv = document.getElementById('purchaseHistoryError');
    const tableBody = document.getElementById('purchaseHistoryTableBody');
    const productSearchInput = document.getElementById('purchaseHistorySearchProduct');
    const batchSearchInput = document.getElementById('purchaseHistorySearchBatch');
    
    // التحقق من وجود النموذج نفسه أولاً
    const modalElement = document.getElementById('customerPurchaseHistoryModal');
    if (!modalElement) {
        if (retryCount < 3) {
            setTimeout(function() {
                loadPurchaseHistory(customerIdParam, retryCount + 1);
            }, 200);
            return;
        }
        console.error('Modal element not found in DOM');
        return;
    }
    
    // التحقق من وجود العناصر الأساسية قبل استخدامها
    // loading و tableDiv ضروريان، errorDiv اختياري
    if (!loading || !tableDiv) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كانت العناصر غير موجودة
        if (retryCount < 3) {
            setTimeout(function() {
                loadPurchaseHistory(customerIdParam, retryCount + 1);
            }, 200);
            return;
        }
        console.error('Required elements not found in DOM after retries', {
            loading: !!loading,
            tableDiv: !!tableDiv,
            errorDiv: !!errorDiv
        });
        return;
    }
    
    // إنشاء errorDiv ديناميكياً إذا لم يكن موجوداً
    if (!errorDiv) {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
            const errorDivElement = document.createElement('div');
            errorDivElement.className = 'alert alert-danger d-none';
            errorDivElement.id = 'purchaseHistoryError';
            // إدراجه بعد loading div
            if (loading.nextSibling) {
                modalBody.insertBefore(errorDivElement, loading.nextSibling);
            } else {
                modalBody.appendChild(errorDivElement);
            }
        }
    }
    
    const finalErrorDiv = document.getElementById('purchaseHistoryError');
    
    if (loading && loading.classList) {
        loading.classList.remove('d-none');
    }
    if (tableDiv && tableDiv.classList) {
        tableDiv.classList.add('d-none');
    }
    if (finalErrorDiv && finalErrorDiv.classList) {
        finalErrorDiv.classList.add('d-none');
    }
    
    const productFilter = productSearchInput ? productSearchInput.value : '';
    const batchFilter = batchSearchInput ? batchSearchInput.value : '';
    
    let url = basePath + '/api/returns.php?action=get_purchase_history&customer_id=' + customerId;
    
    if (productFilter) {
        url += '&product_name=' + encodeURIComponent(productFilter);
    }
    
    if (batchFilter) {
        url += '&batch_number=' + encodeURIComponent(batchFilter);
    }
    
    fetch(url, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        // التحقق من نوع المحتوى
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but got:', contentType, text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            }).catch(() => {
                throw new Error('حدث خطأ في الطلب: ' + response.status);
            });
        }
        
        return response.json();
    })
    .then(data => {
        if (loading && loading.classList) {
            loading.classList.add('d-none');
        }
        
        console.log('Purchase history data:', data); // للتشخيص
        
        if (data.success) {
            // Update customer info
            if (data.customer) {
                const phoneEl = document.getElementById('purchaseHistoryCustomerPhone');
                const addressEl = document.getElementById('purchaseHistoryCustomerAddress');
                if (phoneEl) phoneEl.textContent = data.customer.phone || '-';
                if (addressEl) addressEl.textContent = data.customer.address || '-';
            }
            
            // Store purchase history data
            purchaseHistoryData = data.purchase_history || [];
            console.log('Purchase history items:', purchaseHistoryData.length); // للتشخيص
            displayPurchaseHistory(purchaseHistoryData);
            if (tableDiv && tableDiv.classList) {
                tableDiv.classList.remove('d-none');
            }
            
            // إظهار زر الطباعة
            const printBtn = document.getElementById('printStatementBtn');
            if (printBtn) {
                printBtn.style.display = 'inline-block';
            }
        } else {
            const errorDivEl = document.getElementById('purchaseHistoryError');
            if (errorDivEl && errorDivEl.classList) {
                errorDivEl.textContent = data.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                errorDivEl.classList.remove('d-none');
            }
        }
    })
    .catch(error => {
        if (loading && loading.classList) {
            loading.classList.add('d-none');
        }
        const errorDivEl = document.getElementById('purchaseHistoryError');
        if (errorDivEl && errorDivEl.classList) {
            errorDivEl.textContent = 'خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم');
            errorDivEl.classList.remove('d-none');
        }
        console.error('Error loading purchase history:', error);
    });
}

// دالة طباعة كشف حساب العميل
function printCustomerStatement() {
    if (!currentCustomerId) {
        alert('يرجى تحديد عميل أولاً');
        return;
    }
    
    const printUrl = basePath + '/print_customer_statement.php?customer_id=' + encodeURIComponent(currentCustomerId);
    window.open(printUrl, '_blank');
}

function displayPurchaseHistory(history) {
    const tableBody = document.getElementById('purchaseHistoryTableBody');
    
    if (!tableBody) {
        console.error('purchaseHistoryTableBody element not found');
        return;
    }
    
    tableBody.innerHTML = '';
    
    if (!history || history.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4"><i class="bi bi-info-circle me-2"></i>لا توجد منتجات متاحة للإرجاع من مشتريات هذا العميل</td></tr>';
        return;
    }
    
    history.forEach(function(item) {
        if (!item.can_return) {
            return; // Skip items that can't be returned
        }
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <input type="checkbox" class="item-checkbox" 
                       data-invoice-id="${item.invoice_id}"
                       data-invoice-item-id="${item.invoice_item_id}"
                       data-product-id="${item.product_id}"
                       data-product-name="${item.product_name}"
                       data-unit-price="${item.unit_price}"
                       data-batch-number-ids='${JSON.stringify(item.batch_number_ids || [])}'
                       data-batch-numbers='${JSON.stringify(item.batch_numbers || [])}'
                       onchange="updateSelectedItems()">
            </td>
            <td>${item.invoice_number || '-'}</td>
            <td>${Array.isArray(item.batch_numbers) ? item.batch_numbers.join(', ') : (item.batch_numbers || '-')}</td>
            <td>${item.product_name || '-'}</td>
            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
            <td>${parseFloat(item.returned_quantity || 0).toFixed(2)}</td>
            <td><strong>${parseFloat(item.available_to_return || 0).toFixed(2)}</strong></td>
            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
            <td>${item.invoice_date || '-'}</td>
            <td>
                <button class="btn btn-sm btn-primary" 
                        onclick="selectItemForReturn(${item.invoice_item_id}, ${item.product_id})"
                        title="إرجاع جزئي">
                    <i class="bi bi-arrow-return-left"></i>
                </button>
            </td>
        `;
        tableBody.appendChild(row);
    });
}

function toggleAllItems() {
    const selectAll = document.getElementById('selectAllItems');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = selectAll.checked;
    });
    
    updateSelectedItems();
}

function updateSelectedItems() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    selectedItemsForReturn = [];
    
    checkboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        const available = parseFloat(row.querySelector('td:nth-child(7)').textContent.trim());
        const invoiceNumber = row.querySelector('td:nth-child(2)').textContent.trim();
        
        // Get invoice_item_id to find latest data from purchaseHistoryData
        const invoiceItemId = parseInt(checkbox.dataset.invoiceItemId);
        
        // Try to get latest available quantity from purchaseHistoryData
        let latestAvailable = available;
        if (purchaseHistoryData && purchaseHistoryData.length > 0) {
            const historyItem = purchaseHistoryData.find(function(h) {
                return h.invoice_item_id === invoiceItemId;
            });
            if (historyItem) {
                latestAvailable = parseFloat(historyItem.available_to_return) || 0;
            }
        }
        
        if (latestAvailable > 0) {
            selectedItemsForReturn.push({
                invoice_id: parseInt(checkbox.dataset.invoiceId),
                invoice_number: invoiceNumber,
                invoice_item_id: invoiceItemId,
                product_id: parseInt(checkbox.dataset.productId),
                product_name: checkbox.dataset.productName,
                unit_price: parseFloat(checkbox.dataset.unitPrice),
                batch_number_ids: JSON.parse(checkbox.dataset.batchNumberIds || '[]'),
                batch_numbers: JSON.parse(checkbox.dataset.batchNumbers || '[]'),
                available_to_return: latestAvailable
            });
        }
    });
    
    const createBtn = document.getElementById('createReturnBtn');
    if (selectedItemsForReturn.length > 0) {
        createBtn.style.display = 'block';
    } else {
        createBtn.style.display = 'none';
    }
}

function selectItemForReturn(invoiceItemId, productId) {
    // Select this specific item
    const checkbox = document.querySelector(`.item-checkbox[data-invoice-item-id="${invoiceItemId}"][data-product-id="${productId}"]`);
    if (checkbox) {
        checkbox.checked = true;
        updateSelectedItems();
        openCreateReturnModal();
    }
}

function openCreateReturnModal() {
    if (selectedItemsForReturn.length === 0) {
        alert('يرجى تحديد منتج واحد على الأقل للإرجاع');
        return;
    }
    
    if (!currentCustomerId) {
        alert('خطأ: لم يتم تحديد العميل');
        return;
    }
    
    // Show loading state
    const contentElement = document.getElementById('createReturnContent');
    contentElement.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-success" role="status"><span class="visually-hidden">جاري التحميل...</span></div><p class="mt-3 text-muted">جاري تحميل أحدث البيانات...</p></div>';
    
    // Show modal first
    const modal = new bootstrap.Modal(document.getElementById('createReturnModal'));
    modal.show();
    
    // Reload latest purchase history data before building modal content
    fetch(basePath + '/api/returns.php?action=get_purchase_history&customer_id=' + currentCustomerId, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but got:', contentType, text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            }).catch(() => {
                throw new Error('حدث خطأ في الطلب: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success && data.purchase_history) {
            // Update purchaseHistoryData with latest data
            purchaseHistoryData = data.purchase_history || [];
            
            // Update selectedItemsForReturn with latest available quantities
            selectedItemsForReturn = selectedItemsForReturn.map(function(item) {
                const historyItem = purchaseHistoryData.find(function(h) {
                    return h.invoice_item_id === item.invoice_item_id;
                });
                
                if (historyItem) {
                    return {
                        ...item,
                        available_to_return: parseFloat(historyItem.available_to_return) || 0
                    };
                }
                return item;
            });
            
            // Build modal content with updated data
            buildReturnModalContent();
        } else {
            contentElement.innerHTML = '<div class="alert alert-danger">خطأ: ' + (data.message || 'حدث خطأ أثناء تحميل البيانات') + '</div>';
        }
    })
    .catch(error => {
        console.error('Error loading purchase history:', error);
        contentElement.innerHTML = '<div class="alert alert-danger">خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم') + '</div>';
    });
}

function buildReturnModalContent() {
    if (selectedItemsForReturn.length === 0) {
        document.getElementById('createReturnContent').innerHTML = '<div class="alert alert-warning">لا توجد منتجات محددة للإرجاع</div>';
        return;
    }
    
    // Group items by invoice
    const itemsByInvoice = {};
    selectedItemsForReturn.forEach(function(item) {
        if (!itemsByInvoice[item.invoice_id]) {
            itemsByInvoice[item.invoice_id] = [];
        }
        itemsByInvoice[item.invoice_id].push(item);
    });
    
    // Build modal content
    let html = '<form id="returnRequestForm">';
    
    Object.keys(itemsByInvoice).forEach(function(invoiceId) {
        const firstItem = itemsByInvoice[invoiceId][0];
        html += `<div class="card mb-3">
            <div class="card-header">فاتورة رقم: ${firstItem.invoice_number || invoiceId}</div>
            <div class="card-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>رقم التشغيلة</th>
                            <th>الكمية المتاحة</th>
                            <th>الكمية للإرجاع</th>
                            <th>تالف</th>
                            <th>سبب التلف</th>
                        </tr>
                    </thead>
                    <tbody>`;
        
        itemsByInvoice[invoiceId].forEach(function(item, index) {
            const batchNumbers = (item.batch_numbers || []).join(', ') || '-';
            const availableQty = parseFloat(item.available_to_return) || 0;
            html += `
                <tr>
                    <td>${item.product_name}</td>
                    <td>${batchNumbers}</td>
                    <td id="available-qty-${item.invoice_item_id}">${availableQty.toFixed(2)}</td>
                    <td>
                        <input type="number" 
                               class="form-control form-control-sm return-qty" 
                               data-invoice-item-id="${item.invoice_item_id}"
                               data-product-id="${item.product_id}"
                               data-unit-price="${item.unit_price}"
                               data-batch-number-id="${item.batch_number_ids[0] || ''}"
                               min="0" 
                               max="${availableQty}"
                               step="0.01" 
                               data-available-qty="${availableQty}"
                               required>
                    </td>
                    <td>
                        <input type="checkbox" 
                               class="form-check-input is-damaged-checkbox"
                               data-invoice-item-id="${item.invoice_item_id}">
                    </td>
                    <td>
                        <input type="text" 
                               class="form-control form-control-sm damage-reason"
                               data-invoice-item-id="${item.invoice_item_id}"
                               placeholder="سبب التلف (اختياري)"
                               disabled>
                    </td>
                </tr>
            `;
        });
        
        html += `</tbody></table>
            </div>
        </div>`;
    });
    
    html += `
        <div class="mb-3">
            <label class="form-label">سبب الإرجاع</label>
            <select class="form-select" name="reason" required>
                <option value="customer_request">طلب العميل</option>
                <option value="defective">منتج تالف</option>
                <option value="wrong_item">منتج خاطئ</option>
                <option value="other">أخرى</option>
            </select>
        </div>
        
        <div class="mb-3">
            <label class="form-label">ملاحظات (اختياري)</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
        </div>
        <input type="hidden" name="invoice_id" value="${Object.keys(itemsByInvoice)[0]}">
    </form>`;
    
    document.getElementById('createReturnContent').innerHTML = html;
    
    // Enable/disable damage reason based on checkbox
    document.querySelectorAll('.is-damaged-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const reasonInput = document.querySelector(`.damage-reason[data-invoice-item-id="${this.dataset.invoiceItemId}"]`);
            if (reasonInput) {
                reasonInput.disabled = !this.checked;
                if (!this.checked) {
                    reasonInput.value = '';
                }
            }
        });
    });
}

function goBackToPurchaseHistory() {
    // Close the create return modal
    const createReturnModal = bootstrap.Modal.getInstance(document.getElementById('createReturnModal'));
    if (createReturnModal) {
        createReturnModal.hide();
    }
    
    // Clear selected items when going back
    selectedItemsForReturn = [];
    document.getElementById('createReturnBtn').style.display = 'none';
    document.getElementById('selectAllItems').checked = false;
    
    // Show the purchase history modal
    const purchaseHistoryModalElement = document.getElementById('customerPurchaseHistoryModal');
    if (purchaseHistoryModalElement) {
        const purchaseHistoryModal = new bootstrap.Modal(purchaseHistoryModalElement);
        purchaseHistoryModal.show();
        // Data will be reloaded automatically via the shown.bs.modal event listener
    }
}

function submitReturnRequest() {
    const form = document.getElementById('returnRequestForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    const invoiceId = formData.get('invoice_id');
    
    const items = [];
    document.querySelectorAll('.return-qty').forEach(function(input) {
        const qty = parseFloat(input.value);
        if (qty > 0) {
            const isDamaged = document.querySelector(`.is-damaged-checkbox[data-invoice-item-id="${input.dataset.invoiceItemId}"]`).checked;
            const damageReason = document.querySelector(`.damage-reason[data-invoice-item-id="${input.dataset.invoiceItemId}"]`).value;
            
            items.push({
                invoice_item_id: parseInt(input.dataset.invoiceItemId),
                product_id: parseInt(input.dataset.productId),
                quantity: qty,
                unit_price: parseFloat(input.dataset.unitPrice),
                batch_number_id: input.dataset.batchNumberId ? parseInt(input.dataset.batchNumberId) : null,
                is_damaged: isDamaged,
                damage_reason: isDamaged ? damageReason : null
            });
        }
    });
    
    if (items.length === 0) {
        alert('يرجى إدخال كمية للإرجاع');
        return;
    }
    
    const requestData = {
        customer_id: currentCustomerId,
        invoice_id: parseInt(invoiceId),
        items: items,
        reason: formData.get('reason'),
        reason_description: formData.get('reason_description'),
        notes: formData.get('notes')
    };
    
    fetch(basePath + '/api/returns.php?action=create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(requestData)
    })
    .then(response => {
        // التحقق من نوع المحتوى أولاً
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but got:', contentType, text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم. يرجى المحاولة مرة أخرى.');
            });
        }
        
        // التحقق من حالة الاستجابة
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            }).catch(() => {
                throw new Error('حدث خطأ في الطلب: ' + response.status);
            });
        }
        
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('تم إنشاء طلب الإرجاع بنجاح وتم إرساله للمدير للموافقة');
            bootstrap.Modal.getInstance(document.getElementById('createReturnModal')).hide();
            bootstrap.Modal.getInstance(document.getElementById('customerPurchaseHistoryModal')).hide();
            // Reload page or refresh table
            location.reload();
        } else {
            alert('خطأ: ' + (data.message || 'حدث خطأ أثناء إنشاء طلب الإرجاع'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.'));
    });
}
</script>
<?php endif; ?>

<?php endif; // end if ($section === 'company') from line 738 ?>

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
                    // التحقق من نوع المحتوى
                    var contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(function(text) {
                            console.error('Invalid content type. Response:', text.substring(0, 200));
                            throw new Error('استجابة غير صالحة من الخادم: الخادم لم يعد JSON');
                        });
                    }
                    
                    if (!response.ok) {
                        return response.text().then(function(text) {
                            try {
                                var errorData = JSON.parse(text);
                                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
                            } catch (e) {
                                throw new Error('خطأ في الطلب: ' + response.status);
                            }
                        });
                    }
                    
                    return response.text().then(function(text) {
                        // تنظيف النص من أي مسافات بيضاء في البداية أو النهاية
                        text = text.trim();
                        
                        if (!text) {
                            throw new Error('استجابة فارغة من الخادم');
                        }
                        
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error. Response text:', text.substring(0, 500));
                            throw new Error('استجابة غير صالحة من الخادم: ' + (text.substring(0, 50) || 'لا يمكن تحليل الاستجابة'));
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
                    var errorMessage = 'حدث خطأ أثناء الاتصال بالخادم';
                    if (error.message) {
                        errorMessage += ': ' + error.message;
                    } else if (error.name) {
                        errorMessage += ': ' + error.name;
                    }
                    showAlert(errorMessage);
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

    // JavaScript للحصول على الموقع في نموذج إضافة العميل
    var getLocationBtn = document.getElementById('getLocationBtn');
    var addCustomerLatitudeInput = document.getElementById('addCustomerLatitude');
    var addCustomerLongitudeInput = document.getElementById('addCustomerLongitude');
    var addCustomerModal = document.getElementById('addCustomerModal');

    if (getLocationBtn && addCustomerLatitudeInput && addCustomerLongitudeInput) {
        getLocationBtn.addEventListener('click', function() {
            if (!navigator.geolocation) {
                showAlert('المتصفح لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

            var button = this;
            var originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحصول على الموقع...';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    addCustomerLatitudeInput.value = position.coords.latitude.toFixed(8);
                    addCustomerLongitudeInput.value = position.coords.longitude.toFixed(8);
                    button.disabled = false;
                    button.innerHTML = originalText;
                    showAlert('تم الحصول على الموقع بنجاح!');
                },
                function(error) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                    var errorMessage = 'تعذر الحصول على الموقع.';
                    if (error.code === 1) {
                        errorMessage = 'تم رفض طلب الحصول على الموقع. يرجى السماح بالوصول إلى الموقع.';
                    } else if (error.code === 2) {
                        errorMessage = 'تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.';
                    } else if (error.code === 3) {
                        errorMessage = 'انتهت مهلة طلب الموقع. يرجى المحاولة مرة أخرى.';
                    }
                    showAlert(errorMessage);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        });
    }

    // مسح حقول الموقع عند إغلاق النموذج
    if (addCustomerModal) {
        addCustomerModal.addEventListener('hidden.bs.modal', function() {
            if (addCustomerLatitudeInput) {
                addCustomerLatitudeInput.value = '';
            }
            if (addCustomerLongitudeInput) {
                addCustomerLongitudeInput.value = '';
            }
        });
    }
});
</script>

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
                                        // عند عرض الرصيد الدائن، نعرض القيمة المطلقة
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
                                    // عند عرض الرصيد الدائن، نعرض القيمة المطلقة
                                    $displayBalanceForButton = $customerBalance < 0 ? abs($customerBalance) : $customerBalance;
                                    $formattedBalance = formatCurrency($displayBalanceForButton);
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
                                        <?php if (in_array($currentRole, ['manager', 'sales'], true)): ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-dark js-customer-history"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-journal-text me-1"></i>سجل المشتريات
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary js-customer-purchase-history"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            title="سجل مشتريات العميل - إنشاء مرتجع"
                                        >
                                            <i class="bi bi-arrow-return-left me-1"></i>إرجاع
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-warning js-replacement-btn"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            title="استبدال منتجات"
                                            onclick="openReplacementModal(this)"
                                        >
                                            <i class="bi bi-arrow-repeat me-1"></i>استبدال
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
                        <label class="form-label">ديون العميل / رصيد العميل</label>
                        <input type="number" class="form-control" name="balance" step="0.01" value="0" placeholder="مثال: 0 أو -500">
                        <small class="text-muted">
                            <strong>إدخال قيمة سالبة:</strong> يتم اعتبارها رصيد دائن للعميل (مبلغ متاح للعميل). 
                            لا يتم تحصيل هذا الرصيد، ويمكن للعميل استخدامه عند شراء فواتير حيث يتم خصم قيمة الفاتورة من الرصيد تلقائياً دون تسجيلها كدين.
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الموقع الجغرافي</label>
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="getLocationBtn">
                                <i class="bi bi-geo-alt"></i> الحصول على الموقع الحالي
                            </button>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="text" class="form-control" name="latitude" id="addCustomerLatitude" placeholder="خط العرض" readonly>
                            </div>
                            <div class="col-6">
                                <input type="text" class="form-control" name="longitude" id="addCustomerLongitude" placeholder="خط الطول" readonly>
                            </div>
                        </div>
                        <small class="text-muted">يمكنك الحصول على الموقع تلقائياً أو إدخاله يدوياً</small>
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

<!-- Modal استبدال المنتجات -->
<div class="modal fade" id="replacementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-repeat me-2"></i>استبدال منتجات
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-4 fw-bold replacement-customer-name">-</div>
                </div>
                
                <div class="replacement-loading text-center py-4">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
                
                <div class="alert alert-danger d-none replacement-error"></div>
                
                <div class="replacement-content d-none">
                    <div class="row g-4">
                        <!-- جدول مشتريات العميل -->
                        <div class="col-12 col-lg-6">
                            <div class="card shadow-sm">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-bag-check me-2"></i>منتجات العميل (المطلوب استبدالها)
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive" style="max-height: 400px;">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="table-light sticky-top">
                                                <tr>
                                                    <th style="width: 40px;">
                                                        <input type="checkbox" class="form-check-input" id="selectAllCustomerItems">
                                                    </th>
                                                    <th>المنتج</th>
                                                    <th>الكمية المتاحة</th>
                                                    <th>الكمية المطلوبة</th>
                                                    <th>السعر</th>
                                                    <th>الإجمالي</th>
                                                </tr>
                                            </thead>
                                            <tbody id="customerPurchasesTableBody">
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong>الإجمالي:</strong>
                                        <strong class="text-primary" id="customerTotal">0.00 ج.م</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- جدول مخزن السيارة -->
                        <div class="col-12 col-lg-6">
                            <div class="card shadow-sm">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-box-seam me-2"></i>منتجات مخزن السيارة
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive" style="max-height: 400px;">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="table-light sticky-top">
                                                <tr>
                                                    <th style="width: 40px;">
                                                        <input type="checkbox" class="form-check-input" id="selectAllCarItems">
                                                    </th>
                                                    <th>المنتج</th>
                                                    <th>الكمية المتاحة</th>
                                                    <th>الكمية المطلوبة</th>
                                                    <th>السعر</th>
                                                    <th>الإجمالي</th>
                                                </tr>
                                            </thead>
                                            <tbody id="carInventoryTableBody">
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong>الإجمالي:</strong>
                                        <strong class="text-success" id="carTotal">0.00 ج.م</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ملخص العملية -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card shadow-sm border-info">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص العملية</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <div class="text-muted small">إجمالي منتجات العميل</div>
                                            <div class="fs-5 fw-bold text-primary" id="summaryCustomerTotal">0.00 ج.م</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="text-muted small">إجمالي منتجات السيارة</div>
                                            <div class="fs-5 fw-bold text-success" id="summaryCarTotal">0.00 ج.م</div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="text-muted small">الفرق</div>
                                            <div class="fs-5 fw-bold" id="summaryDifference">0.00 ج.م</div>
                                        </div>
                                    </div>
                                    <div class="alert alert-info mt-3 mb-0 d-none" id="replacementInfo">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-warning" id="executeReplacementBtn" disabled>
                    <i class="bi bi-check-circle me-2"></i>تنفيذ الاستبدال
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; // end if ($section === 'company') ?>

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

// ========== معالج أخطاء عام لمنع ظهور رسائل الخطأ ==========
(function() {
    // معالج أخطاء عام لمنع ظهور رسائل خطأ classList
    window.addEventListener('error', function(event) {
        if (event.message && (
            event.message.includes('classList') || 
            event.message.includes('Cannot read properties of null') ||
            event.message.includes("Cannot read property 'classList'") ||
            event.message.includes("reading 'classList'")
        )) {
            event.preventDefault();
            event.stopPropagation();
            console.warn('تم منع خطأ classList:', event.message);
            return true;
        }
    }, true);
    
    // معالج أخطاء للوعود المرفوضة
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && typeof event.reason === 'object' && event.reason.message) {
            const errorMessage = event.reason.message;
            if (errorMessage.includes('classList') || 
                errorMessage.includes('Cannot read properties of null') ||
                errorMessage.includes("Cannot read property 'classList'")) {
                event.preventDefault();
                console.warn('تم منع خطأ classList في promise:', errorMessage);
                return true;
            }
        }
    });
})();

// ========== JavaScript لإدارة عملية الاستبدال ==========
(function() {
    const replacementModal = document.getElementById('replacementModal');
    if (!replacementModal) {
        console.warn('Replacement modal not found');
        return;
    }
    
    let currentCustomerId = null;
    let customerPurchases = [];
    let carInventory = [];
    let selectedCustomerItems = [];
    let selectedCarItems = [];
    
    // الحصول على المسار الأساسي
    const basePath = '<?php echo getBasePath(); ?>';
    const apiPath = basePath + '/api/get_replacement_data.php';
    
    // دالة عامة لفتح مودال الاستبدال (يمكن استدعاؤها من onclick)
    window.openReplacementModal = function(btn) {
        if (!btn) {
            console.error('Button element is required');
            return;
        }
        
        currentCustomerId = parseInt(btn.getAttribute('data-customer-id'));
        const customerName = btn.getAttribute('data-customer-name');
        
        if (!currentCustomerId) {
            alert('خطأ: لم يتم تحديد معرف العميل');
            return;
        }
        
        // تعيين اسم العميل
        const customerNameEl = replacementModal.querySelector('.replacement-customer-name');
        if (customerNameEl) {
            customerNameEl.textContent = customerName;
        }
        
        // إعادة تعيين
        selectedCustomerItems = [];
        selectedCarItems = [];
        customerPurchases = [];
        carInventory = [];
        
        // إظهار التحميل وإخفاء المحتوى
        const loadingEl = replacementModal.querySelector('.replacement-loading');
        const contentEl = replacementModal.querySelector('.replacement-content');
        const errorEl = replacementModal.querySelector('.replacement-error');
        
        if (loadingEl && loadingEl.classList) {
            loadingEl.classList.remove('d-none');
        }
        if (contentEl && contentEl.classList) {
            contentEl.classList.add('d-none');
        }
        if (errorEl && errorEl.classList) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }
        
        // إزالة أي backdrop موجود مسبقاً
        const existingBackdrop = document.querySelector('.modal-backdrop');
        if (existingBackdrop) {
            existingBackdrop.remove();
        }
        if (document.body && document.body.classList) {
            document.body.classList.remove('modal-open');
        }
        if (document.body && document.body.style) {
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
        
        // فتح المودال
        const modal = new bootstrap.Modal(replacementModal, {
            backdrop: true,
            keyboard: true,
            focus: true
        });
        modal.show();
        
        // تحميل البيانات
        fetch(apiPath + '?customer_id=' + currentCustomerId)
            .then(response => {
                // التحقق من نوع المحتوى
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Invalid content type. Response:', text.substring(0, 200));
                        throw new Error('استجابة غير صالحة من الخادم');
                    });
                }
                
                if (!response.ok) {
                    return response.json().then(errData => {
                        throw new Error(errData.message || 'فشل تحميل البيانات من الخادم');
                    }).catch(() => {
                        throw new Error('فشل تحميل البيانات من الخادم (HTTP ' + response.status + ')');
                    });
                }
                
                return response.json();
            })
            .then(data => {
                if (data && data.success) {
                    customerPurchases = data.customer_purchases || [];
                    carInventory = data.car_inventory || [];
                    
                    // عرض البيانات
                    renderCustomerPurchases();
                    renderCarInventory();
                    
                    // تحديث الإجماليات بعد عرض البيانات
                    calculateTotals();
                    
                    if (loadingEl) loadingEl.classList.add('d-none');
                    if (contentEl) contentEl.classList.remove('d-none');
                    if (errorEl) errorEl.classList.add('d-none');
                } else {
                    throw new Error(data.message || 'فشل تحميل البيانات');
                }
            })
            .catch(error => {
                console.error('Replacement data loading error:', error);
                
                if (loadingEl) loadingEl.classList.add('d-none');
                if (errorEl) {
                    errorEl.textContent = error.message || 'حدث خطأ أثناء تحميل البيانات';
                    errorEl.classList.remove('d-none');
                    // إظهار المحتوى حتى لو كان هناك خطأ (ربما البيانات موجودة)
                    if (contentEl && (customerPurchases.length > 0 || carInventory.length > 0)) {
                        contentEl.classList.remove('d-none');
                    }
                } else {
                    alert('خطأ: ' + (error.message || 'حدث خطأ أثناء تحميل البيانات'));
                }
            });
    };
    
    // دالة تنسيق العملة
    function formatCurrency(value) {
        const number = Number(value || 0);
        return number.toLocaleString('ar-EG', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        }) + ' ج.م';
    }
    
    // دالة حساب الإجماليات مع التحقق من الكميات
    function calculateTotals() {
        let customerTotal = 0;
        let carTotal = 0;
        let hasErrors = false;
        let errorMessages = [];
        
        // حساب إجمالي منتجات العميل مع التحقق
        selectedCustomerItems.forEach(item => {
            const invoiceItemId = item.invoice_item_id;
            const quantityInput = document.querySelector(`.customer-quantity-input[data-invoice-item-id="${invoiceItemId}"]`);
            const checkbox = document.querySelector(`.customer-item-checkbox[data-invoice-item-id="${invoiceItemId}"]`);
            
            if (quantityInput && checkbox && checkbox.checked) {
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(item.unit_price) || parseFloat(checkbox.getAttribute('data-unit-price')) || 0;
                let quantity = parseFloat(quantityInput.value) || 0;
                
                // التحقق من الكمية
                if (quantity <= 0) {
                    hasErrors = true;
                    errorMessages.push('يجب إدخال كمية أكبر من صفر');
                    if (quantityInput && quantityInput.classList) {
                        quantityInput.classList.add('is-invalid');
                    }
                    quantity = 0;
                } else if (quantity > availableQty) {
                    hasErrors = true;
                    errorMessages.push('الكمية المدخلة (' + quantity + ') أكبر من الكمية المتاحة (' + availableQty + ')');
                    if (quantityInput && quantityInput.classList) {
                        quantityInput.classList.add('is-invalid');
                    }
                    quantity = availableQty; // استخدام الكمية المتاحة كحد أقصى
                } else {
                    if (quantityInput && quantityInput.classList) {
                        quantityInput.classList.remove('is-invalid');
                    }
                }
                
                // تحديث القيمة في selectedCustomerItems
                item.quantity = quantity;
                item.total_price = quantity * unitPrice;
                
                // إضافة للإجمالي
                customerTotal += item.total_price;
            }
        });
        
        // حساب إجمالي منتجات السيارة مع التحقق
        selectedCarItems.forEach(item => {
            const productId = item.product_id;
            const finishedBatchId = item.finished_batch_id || null;
            // استخدام finished_batch_id للعثور على العنصر الصحيح
            const checkbox = Array.from(document.querySelectorAll('.car-item-checkbox')).find(cb => {
                const cbProductId = parseInt(cb.getAttribute('data-product-id'));
                const cbBatchId = cb.getAttribute('data-finished-batch-id') || null;
                return cbProductId === productId && 
                       (cbBatchId === finishedBatchId || (cbBatchId === null && finishedBatchId === null));
            });
            
            if (checkbox && checkbox.checked) {
                const uniqueKey = checkbox.getAttribute('data-unique-key');
                const quantityInput = document.querySelector(`.car-quantity-input[data-unique-key="${uniqueKey}"]`);
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(item.unit_price) || parseFloat(checkbox.getAttribute('data-unit-price')) || 0;
                // استخدام item.quantity من selectedCarItems بدلاً من quantityInput.value
                let quantity = item.quantity || (quantityInput ? parseFloat(quantityInput.value) || 0 : 0);
                
                // التحقق من الكمية
                if (quantity <= 0) {
                    hasErrors = true;
                    errorMessages.push('يجب إدخال كمية أكبر من صفر للمنتج من مخزن السيارة');
                    if (quantityInput && quantityInput.classList) {
                        quantityInput.classList.add('is-invalid');
                    }
                    quantity = 0;
                } else if (quantity > availableQty) {
                    hasErrors = true;
                    errorMessages.push('الكمية المدخلة (' + quantity.toFixed(2) + ') أكبر من الكمية المتاحة (' + availableQty.toFixed(2) + ')');
                    if (quantityInput && quantityInput.classList) {
                        quantityInput.classList.add('is-invalid');
                    }
                    quantity = availableQty; // استخدام الكمية المتاحة كحد أقصى
                } else {
                    if (quantityInput && quantityInput.classList) {
                        quantityInput.classList.remove('is-invalid');
                    }
                }
                
                // تحديث القيمة في selectedCarItems
                item.quantity = quantity;
                item.total_price = quantity * unitPrice;
                
                // تحديث قيمة حقل الإدخال إذا كان موجوداً
                if (quantityInput) {
                    quantityInput.value = quantity;
                }
                
                // إضافة للإجمالي
                carTotal += item.total_price;
            }
        });
        
        const difference = customerTotal - carTotal;
        
        // تحديث الإجماليات في الجداول
        const customerTotalEl = document.getElementById('customerTotal');
        const carTotalEl = document.getElementById('carTotal');
        const summaryCustomerTotalEl = document.getElementById('summaryCustomerTotal');
        const summaryCarTotalEl = document.getElementById('summaryCarTotal');
        
        if (customerTotalEl) customerTotalEl.textContent = formatCurrency(customerTotal);
        if (carTotalEl) carTotalEl.textContent = formatCurrency(carTotal);
        if (summaryCustomerTotalEl) summaryCustomerTotalEl.textContent = formatCurrency(customerTotal);
        if (summaryCarTotalEl) summaryCarTotalEl.textContent = formatCurrency(carTotal);
        
        const differenceEl = document.getElementById('summaryDifference');
        if (differenceEl) {
            differenceEl.textContent = formatCurrency(Math.abs(difference));
            
            if (Math.abs(difference) < 0.01) {
                differenceEl.className = 'fs-5 fw-bold text-secondary';
            } else if (difference > 0) {
                differenceEl.className = 'fs-5 fw-bold text-danger';
            } else {
                differenceEl.className = 'fs-5 fw-bold text-success';
            }
        }
        
        // عرض معلومات العملية
        const infoEl = document.getElementById('replacementInfo');
        if (infoEl && infoEl.classList) {
            infoEl.classList.remove('d-none');
            
            if (hasErrors) {
                infoEl.className = 'alert alert-danger mt-3 mb-0';
                infoEl.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i><strong>تحذير:</strong> ' + errorMessages.join('<br>');
            } else if (Math.abs(difference) < 0.01) {
                infoEl.className = 'alert alert-info mt-3 mb-0';
                infoEl.innerHTML = '<i class="bi bi-info-circle me-2"></i>لا يوجد فرق في القيمة. لن يتأثر رصيد العميل أو خزنة المندوب.';
            } else if (difference < 0) {
                infoEl.className = 'alert alert-warning mt-3 mb-0';
                infoEl.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>سيتم إضافة ' + formatCurrency(Math.abs(difference)) + ' إلى رصيد العميل المدين وإضافة المبلغ إلى خزنة المندوب.';
            } else {
                infoEl.className = 'alert alert-danger mt-3 mb-0';
                infoEl.innerHTML = '<i class="bi bi-exclamation-circle me-2"></i>سيتم خصم ' + formatCurrency(difference) + ' من رصيد العميل وخصم المبلغ من خزنة المندوب وخصم 2% من راتب المندوب.';
            }
        }
        
        // تحديث حالة زر التنفيذ
        updateExecuteButtonState();
    }
    
    // دالة تحديث حالة زر التنفيذ
    function updateExecuteButtonState() {
        const executeBtn = document.getElementById('executeReplacementBtn');
        if (!executeBtn) return;
        
        // تفعيل الزر فقط إذا كان هناك منتجات محددة من العميل والسيارة
        const hasCustomerItems = selectedCustomerItems.length > 0;
        const hasCarItems = selectedCarItems.length > 0;
        
        if (hasCustomerItems && hasCarItems) {
            executeBtn.disabled = false;
        } else {
            executeBtn.disabled = true;
        }
    }
    
    // فتح المودال - استخدام event delegation
    document.addEventListener('click', function(e) {
        const replacementBtn = e.target.closest('.js-replacement-btn');
        if (replacementBtn) {
            e.preventDefault();
            e.stopPropagation();
            
            const btn = replacementBtn;
            currentCustomerId = parseInt(btn.getAttribute('data-customer-id'));
            const customerName = btn.getAttribute('data-customer-name');
            
            if (!currentCustomerId) {
                alert('خطأ: لم يتم تحديد معرف العميل');
                return;
            }
            
            // التأكد من وجود المودال
            const modalElement = document.getElementById('replacementModal');
            if (!modalElement) {
                alert('خطأ: لم يتم العثور على المودال');
                return;
            }
            
            // تعيين اسم العميل
            const customerNameEl = modalElement.querySelector('.replacement-customer-name');
            if (customerNameEl) {
                customerNameEl.textContent = customerName;
            }
            
            // إعادة تعيين
            selectedCustomerItems = [];
            selectedCarItems = [];
            customerPurchases = [];
            carInventory = [];
            
            // إظهار التحميل وإخفاء المحتوى
            const loadingEl = modalElement.querySelector('.replacement-loading');
            const contentEl = modalElement.querySelector('.replacement-content');
            const errorEl = modalElement.querySelector('.replacement-error');
            
            if (loadingEl) loadingEl.classList.remove('d-none');
            if (contentEl) contentEl.classList.add('d-none');
            if (errorEl) {
                errorEl.classList.add('d-none');
                errorEl.textContent = '';
            }
            
            // إزالة أي backdrop موجود مسبقاً
            const existingBackdrop = document.querySelector('.modal-backdrop');
            if (existingBackdrop) {
                existingBackdrop.remove();
            }
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // فتح المودال
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modal.show();
            
            // تحميل البيانات
            const basePath = '<?php echo getBasePath(); ?>';
            const apiPath = basePath + '/api/get_replacement_data.php';
            fetch(apiPath + '?customer_id=' + currentCustomerId)
                .then(response => {
                    // التحقق من نوع المحتوى
                    const contentType = response.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Invalid content type. Response:', text.substring(0, 300));
                            // محاولة parsing JSON يدوياً
                            try {
                                const jsonData = JSON.parse(text);
                                return jsonData;
                            } catch (e) {
                                throw new Error('استجابة غير صالحة من الخادم: ' + text.substring(0, 100));
                            }
                        });
                    }
                    
                    if (!response.ok) {
                        return response.json().then(errData => {
                            throw new Error(errData.message || 'فشل تحميل البيانات من الخادم');
                        }).catch(() => {
                            throw new Error('فشل تحميل البيانات من الخادم (HTTP ' + response.status + ')');
                        });
                    }
                    
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        customerPurchases = data.customer_purchases || [];
                        carInventory = data.car_inventory || [];
                        
                        // عرض البيانات
                        renderCustomerPurchases();
                        renderCarInventory();
                        
                        // تحديث الإجماليات بعد عرض البيانات
                        calculateTotals();
                        
                        const loadingEl = modalElement.querySelector('.replacement-loading');
                        const contentEl = modalElement.querySelector('.replacement-content');
                        const errorEl = modalElement.querySelector('.replacement-error');
                        
                        if (loadingEl) loadingEl.classList.add('d-none');
                        if (contentEl) contentEl.classList.remove('d-none');
                        if (errorEl) errorEl.classList.add('d-none');
                    } else {
                        throw new Error(data.message || 'فشل تحميل البيانات');
                    }
                })
                .catch(error => {
                    console.error('Replacement data loading error:', error);
                    
                    const loadingEl = modalElement.querySelector('.replacement-loading');
                    const contentEl = modalElement.querySelector('.replacement-content');
                    const errorEl = modalElement.querySelector('.replacement-error');
                    
                    if (loadingEl) loadingEl.classList.add('d-none');
                    
                    // إذا كانت البيانات موجودة، نعرضها رغم الخطأ
                    if (customerPurchases.length > 0 || carInventory.length > 0) {
                        renderCustomerPurchases();
                        renderCarInventory();
                        if (contentEl) contentEl.classList.remove('d-none');
                    }
                    
                    if (errorEl) {
                        errorEl.textContent = error.message || 'حدث خطأ أثناء تحميل البيانات';
                        errorEl.classList.remove('d-none');
                    }
                });
        }
    });
    
    // بديل: استخدام event listener مباشر على الأزرار الموجودة
    document.addEventListener('DOMContentLoaded', function() {
        // إضافة event listeners للأزرار الموجودة حالياً
        document.querySelectorAll('.js-replacement-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const replacementBtn = e.currentTarget;
                currentCustomerId = parseInt(replacementBtn.getAttribute('data-customer-id'));
                const customerName = replacementBtn.getAttribute('data-customer-name');
                
                if (!currentCustomerId) {
                    alert('خطأ: لم يتم تحديد معرف العميل');
                    return;
                }
                
                // فتح المودال
                const modalElement = document.getElementById('replacementModal');
                if (!modalElement) {
                    alert('خطأ: لم يتم العثور على المودال');
                    return;
                }
                
                // تعيين اسم العميل
                const customerNameEl = modalElement.querySelector('.replacement-customer-name');
                if (customerNameEl) {
                    customerNameEl.textContent = customerName;
                }
                
                // إعادة تعيين
                selectedCustomerItems = [];
                selectedCarItems = [];
                customerPurchases = [];
                carInventory = [];
                
                // إظهار التحميل وإخفاء المحتوى
                const loadingEl = modalElement.querySelector('.replacement-loading');
                const contentEl = modalElement.querySelector('.replacement-content');
                const errorEl = modalElement.querySelector('.replacement-error');
                
                if (loadingEl) loadingEl.classList.remove('d-none');
                if (contentEl) contentEl.classList.add('d-none');
                if (errorEl) {
                    errorEl.classList.add('d-none');
                    errorEl.textContent = '';
                }
                
                // فتح المودال
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
                
                // تحميل البيانات
                const basePath = '<?php echo getBasePath(); ?>';
                const apiPath = basePath + '/api/get_replacement_data.php';
                fetch(apiPath + '?customer_id=' + currentCustomerId)
                    .then(response => {
                        // التحقق من نوع المحتوى
                        const contentType = response.headers.get('content-type') || '';
                        if (!contentType.includes('application/json')) {
                            return response.text().then(text => {
                                console.error('Invalid content type. Response:', text.substring(0, 300));
                                // محاولة parsing JSON يدوياً
                                try {
                                    const jsonData = JSON.parse(text);
                                    return jsonData;
                                } catch (e) {
                                    throw new Error('استجابة غير صالحة من الخادم: ' + text.substring(0, 100));
                                }
                            });
                        }
                        
                        if (!response.ok) {
                            return response.json().then(errData => {
                                throw new Error(errData.message || 'فشل تحميل البيانات من الخادم');
                            }).catch(() => {
                                throw new Error('فشل تحميل البيانات من الخادم (HTTP ' + response.status + ')');
                            });
                        }
                        
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.success) {
                            customerPurchases = data.customer_purchases || [];
                            carInventory = data.car_inventory || [];
                            
                            // عرض البيانات
                            renderCustomerPurchases();
                            renderCarInventory();
                            
                            // تحديث الإجماليات بعد عرض البيانات
                            calculateTotals();
                            
                            if (loadingEl) loadingEl.classList.add('d-none');
                            if (contentEl) contentEl.classList.remove('d-none');
                            if (errorEl) errorEl.classList.add('d-none');
                        } else {
                            throw new Error(data.message || 'فشل تحميل البيانات');
                        }
                    })
                    .catch(error => {
                        console.error('Replacement data loading error:', error);
                        
                        if (loadingEl) loadingEl.classList.add('d-none');
                        
                        // إذا كانت البيانات موجودة، نعرضها رغم الخطأ
                        if (customerPurchases.length > 0 || carInventory.length > 0) {
                            renderCustomerPurchases();
                            renderCarInventory();
                            if (contentEl) contentEl.classList.remove('d-none');
                        }
                        
                        if (errorEl) {
                            errorEl.textContent = error.message || 'حدث خطأ أثناء تحميل البيانات';
                            errorEl.classList.remove('d-none');
                        }
                    });
            });
        });
    });
    
    // عرض مشتريات العميل
    function renderCustomerPurchases() {
        const tbody = document.getElementById('customerPurchasesTableBody');
        tbody.innerHTML = '';
        
        if (customerPurchases.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">لا توجد مشتريات متاحة للاستبدال</td></tr>';
            return;
        }
        
        customerPurchases.forEach(item => {
            const tr = document.createElement('tr');
            const existingItem = selectedCustomerItems.find(sel => sel.invoice_item_id === item.invoice_item_id);
            const checked = existingItem !== undefined;
            const selectedQty = existingItem ? existingItem.quantity : item.available_quantity;
            
            const batchNumberIds = Array.isArray(item.batch_number_ids) ? item.batch_number_ids : [];
            const finishedBatchIds = Array.isArray(item.finished_batch_ids) ? item.finished_batch_ids : [];
            
            tr.innerHTML = `
                <td>
                    <input type="checkbox" class="form-check-input customer-item-checkbox" 
                           data-invoice-item-id="${item.invoice_item_id}"
                           data-product-id="${item.product_id}"
                           data-available-quantity="${item.available_quantity}"
                           data-unit-price="${item.unit_price}"
                           data-batch-number-ids='${JSON.stringify(batchNumberIds)}'
                           data-finished-batch-ids='${JSON.stringify(finishedBatchIds)}'
                           ${checked ? 'checked' : ''}>
                </td>
                <td>
                    <div>${item.product_name || 'غير معروف'}</div>
                    <small class="text-muted">فاتورة: ${item.invoice_number}</small>
                    ${item.batch_numbers && item.batch_numbers.length > 0 ? `<br><small class="text-info">تشغيلة: ${item.batch_numbers.join(', ')}</small>` : ''}
                </td>
                <td>${item.available_quantity} ${item.unit || 'قطعة'}</td>
                <td>
                    <input type="number" 
                           class="form-control form-control-sm customer-quantity-input" 
                           data-invoice-item-id="${item.invoice_item_id}"
                           min="0.01" 
                           max="${item.available_quantity}" 
                           step="0.01" 
                           value="${selectedQty}"
                           ${!checked ? 'disabled' : ''}
                           style="width: 100px;">
                    <small class="text-muted d-block">${item.unit || 'قطعة'}</small>
                </td>
                <td>${formatCurrency(item.unit_price)}</td>
                <td class="customer-item-total">${formatCurrency(selectedQty * item.unit_price)}</td>
            `;
            
            tbody.appendChild(tr);
        });
        
        // إضافة مستمعات للـ checkboxes
        document.querySelectorAll('.customer-item-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const invoiceItemId = parseInt(this.getAttribute('data-invoice-item-id'));
                const quantityInput = document.querySelector(`.customer-quantity-input[data-invoice-item-id="${invoiceItemId}"]`);
                
                if (this.checked) {
                    if (quantityInput) {
                        quantityInput.disabled = false;
                        quantityInput.focus();
                    }
                    
                    const batchNumberIdsStr = this.getAttribute('data-batch-number-ids') || '[]';
                    const finishedBatchIdsStr = this.getAttribute('data-finished-batch-ids') || '[]';
                    const batchNumberIds = JSON.parse(batchNumberIdsStr);
                    const finishedBatchIds = JSON.parse(finishedBatchIdsStr);
                    const availableQty = parseFloat(this.getAttribute('data-available-quantity'));
                    const unitPrice = parseFloat(this.getAttribute('data-unit-price'));
                    const quantity = quantityInput ? parseFloat(quantityInput.value) || availableQty : availableQty;
                    
                    // التحقق من أن الكمية <= المتاحة
                    const validQuantity = Math.min(quantity, availableQty);
                    if (quantityInput) {
                        quantityInput.value = validQuantity;
                    }
                    
                    selectedCustomerItems.push({
                        invoice_item_id: invoiceItemId,
                        product_id: parseInt(this.getAttribute('data-product-id')),
                        quantity: validQuantity,
                        unit_price: unitPrice,
                        total_price: validQuantity * unitPrice,
                        batch_number_ids: batchNumberIds,
                        finished_batch_ids: finishedBatchIds
                    });
                } else {
                    if (quantityInput) {
                        quantityInput.disabled = true;
                    }
                    selectedCustomerItems = selectedCustomerItems.filter(
                        item => item.invoice_item_id !== invoiceItemId
                    );
                }
                
                // تحديث select all
                const allChecked = document.querySelectorAll('.customer-item-checkbox:checked').length === customerPurchases.length;
                document.getElementById('selectAllCustomerItems').checked = allChecked;
                
                // تحديث حالة زر التنفيذ
                updateExecuteButtonState();
                calculateTotals();
            });
        });
        
        // إضافة مستمعات لحقول الكمية
        document.querySelectorAll('.customer-quantity-input').forEach(input => {
            // event listener للكتابة الفورية
            input.addEventListener('input', function() {
                const invoiceItemId = parseInt(this.getAttribute('data-invoice-item-id'));
                const checkbox = document.querySelector(`.customer-item-checkbox[data-invoice-item-id="${invoiceItemId}"]`);
                if (!checkbox) return;
                
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
                
                let quantity = parseFloat(this.value) || 0;
                
                // التحقق من أن الكمية <= المتاحة
                if (quantity > availableQty) {
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                // تحديث الإجمالي في الجدول فوراً
                const totalCell = this.closest('tr').querySelector('.customer-item-total');
                if (totalCell) {
                    const itemTotal = quantity > 0 ? quantity * unitPrice : 0;
                    totalCell.textContent = formatCurrency(itemTotal);
                }
                
                // تحديث selectedCustomerItems
                const itemIndex = selectedCustomerItems.findIndex(item => item.invoice_item_id === invoiceItemId);
                if (itemIndex !== -1 && checkbox.checked) {
                    selectedCustomerItems[itemIndex].quantity = quantity > 0 ? quantity : 0;
                    selectedCustomerItems[itemIndex].total_price = quantity > 0 ? quantity * unitPrice : 0;
                }
                
                // تحديث الإجماليات الكلية فوراً
                calculateTotals();
            });
            
            // event listener للتغيير (عند فقدان التركيز)
            input.addEventListener('change', function() {
                const invoiceItemId = parseInt(this.getAttribute('data-invoice-item-id'));
                const checkbox = document.querySelector(`.customer-item-checkbox[data-invoice-item-id="${invoiceItemId}"]`);
                if (!checkbox) return;
                
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
                
                let quantity = parseFloat(this.value) || 0;
                
                if (quantity <= 0) {
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                if (quantity > availableQty) {
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                // تحديث selectedCustomerItems
                const itemIndex = selectedCustomerItems.findIndex(item => item.invoice_item_id === invoiceItemId);
                if (itemIndex !== -1 && checkbox.checked) {
                    selectedCustomerItems[itemIndex].quantity = quantity;
                    selectedCustomerItems[itemIndex].total_price = quantity * unitPrice;
                }
                
                // تحديث الإجماليات
                calculateTotals();
            });
            
            input.addEventListener('blur', function() {
                const invoiceItemId = parseInt(this.getAttribute('data-invoice-item-id'));
                const checkbox = document.querySelector(`.customer-item-checkbox[data-invoice-item-id="${invoiceItemId}"]`);
                if (!checkbox) return;
                
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
                
                let quantity = parseFloat(this.value) || 0;
                
                if (quantity <= 0) {
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                if (quantity > availableQty) {
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                // تحديث selectedCustomerItems
                const itemIndex = selectedCustomerItems.findIndex(item => item.invoice_item_id === invoiceItemId);
                if (itemIndex !== -1 && checkbox.checked) {
                    selectedCustomerItems[itemIndex].quantity = quantity;
                    selectedCustomerItems[itemIndex].total_price = quantity * unitPrice;
                }
                
                // تحديث الإجماليات
                calculateTotals();
            });
        });
        
        // select all للمشتريات
        document.getElementById('selectAllCustomerItems').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.customer-item-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                cb.dispatchEvent(new Event('change'));
            });
        });
    }
    
    // عرض مخزن السيارة
    function renderCarInventory() {
        const tbody = document.getElementById('carInventoryTableBody');
        tbody.innerHTML = '';
        
        if (carInventory.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">لا توجد منتجات في مخزن السيارة</td></tr>';
            return;
        }
        
        carInventory.forEach(item => {
            const tr = document.createElement('tr');
            // استخدام product_id + finished_batch_id كمعرف فريد
            const finishedBatchId = item.finished_batch_id || null;
            const uniqueKey = `${item.product_id}_${finishedBatchId || 'no_batch'}`;
            const existingItem = selectedCarItems.find(sel => 
                sel.product_id === item.product_id && 
                (sel.finished_batch_id === finishedBatchId || (sel.finished_batch_id === null && finishedBatchId === null))
            );
            const checked = existingItem !== undefined;
            const selectedQty = existingItem ? existingItem.quantity : item.quantity;
            
            tr.innerHTML = `
                <td>
                    <input type="checkbox" class="form-check-input car-item-checkbox" 
                           data-product-id="${item.product_id}"
                           data-finished-batch-id="${finishedBatchId || ''}"
                           data-unique-key="${uniqueKey}"
                           data-available-quantity="${item.quantity}"
                           data-unit-price="${item.unit_price}"
                           data-finished-batch-number="${item.finished_batch_number || ''}"
                           ${checked ? 'checked' : ''}>
                </td>
                <td>
                    <div>${item.product_name || 'غير معروف'}</div>
                    ${item.finished_batch_number ? `<small class="text-muted d-block mt-1">تشغيلة: ${item.finished_batch_number}</small>` : ''}
                </td>
                <td>${item.quantity} ${item.unit || 'قطعة'}</td>
                <td>
                    <input type="number" 
                           class="form-control form-control-sm car-quantity-input" 
                           data-product-id="${item.product_id}"
                           data-finished-batch-id="${finishedBatchId || ''}"
                           data-unique-key="${uniqueKey}"
                           min="0.01" 
                           max="${item.quantity}" 
                           step="0.01" 
                           value="${selectedQty}"
                           ${!checked ? 'disabled' : ''}
                           style="width: 100px;">
                    <small class="text-muted d-block">${item.unit || 'قطعة'}</small>
                </td>
                <td>${formatCurrency(item.unit_price)}</td>
                <td class="car-item-total">${formatCurrency(selectedQty * item.unit_price)}</td>
            `;
            
            tbody.appendChild(tr);
        });
        
        // إضافة مستمعات للـ checkboxes
        document.querySelectorAll('.car-item-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const productId = parseInt(this.getAttribute('data-product-id'));
                const finishedBatchId = this.getAttribute('data-finished-batch-id') || null;
                const uniqueKey = this.getAttribute('data-unique-key');
                // استخدام unique-key للعثور على حقل الإدخال الصحيح
                const quantityInput = document.querySelector(`.car-quantity-input[data-unique-key="${uniqueKey}"]`);
                
                if (this.checked) {
                    // التحقق من عدم وجود منتج بنفس product_id و finished_batch_id
                    const existing = selectedCarItems.find(item => 
                        item.product_id === productId && 
                        (item.finished_batch_id === finishedBatchId || (item.finished_batch_id === null && finishedBatchId === null))
                    );
                    
                    if (existing) {
                        alert('هذا المنتج مع نفس رقم التشغيلة مضاف بالفعل');
                        this.checked = false;
                        return;
                    }
                    
                    if (quantityInput) {
                        quantityInput.disabled = false;
                        quantityInput.focus();
                    }
                    
                    const availableQty = parseFloat(this.getAttribute('data-available-quantity'));
                    const unitPrice = parseFloat(this.getAttribute('data-unit-price'));
                    const finishedBatchNumber = this.getAttribute('data-finished-batch-number') || null;
                    // الحصول على اسم المنتج من الصف
                    const productNameCell = this.closest('tr').querySelector('td:nth-child(2)');
                    const productName = productNameCell ? productNameCell.querySelector('div')?.textContent?.trim() || 'غير معروف' : 'غير معروف';
                    const quantity = quantityInput ? parseFloat(quantityInput.value) || availableQty : availableQty;
                    
                    // التحقق من أن الكمية <= المتاحة مع رسالة تحذير
                    let validQuantity = Math.min(quantity, availableQty);
                    if (quantity > availableQty) {
                        alert(`⚠️ الكمية المدخلة (${quantity.toFixed(2)}) تتجاوز الكمية المتاحة (${availableQty.toFixed(2)}).\nتم تقليل الكمية تلقائياً إلى ${availableQty.toFixed(2)}`);
                        validQuantity = availableQty;
                    }
                    
                    if (quantityInput) {
                        quantityInput.value = validQuantity;
                    }
                    
                    selectedCarItems.push({
                        product_id: productId,
                        product_name: productName,
                        quantity: validQuantity,
                        unit_price: unitPrice,
                        total_price: validQuantity * unitPrice,
                        finished_batch_id: finishedBatchId,
                        finished_batch_number: finishedBatchNumber
                    });
                } else {
                    if (quantityInput) {
                        quantityInput.disabled = true;
                    }
                    // استخدام finished_batch_id للتمييز بين المنتجات
                    selectedCarItems = selectedCarItems.filter(item => 
                        !(item.product_id === productId && 
                          (item.finished_batch_id === finishedBatchId || (item.finished_batch_id === null && finishedBatchId === null)))
                    );
                }
                
                // تحديث select all
                const allChecked = document.querySelectorAll('.car-item-checkbox:checked').length === carInventory.length;
                document.getElementById('selectAllCarItems').checked = allChecked;
                
                // تحديث حالة زر التنفيذ
                updateExecuteButtonState();
                calculateTotals();
            });
        });
        
        // إضافة مستمعات لحقول الكمية
        document.querySelectorAll('.car-quantity-input').forEach(input => {
            // event listener للكتابة الفورية
            input.addEventListener('input', function() {
                const uniqueKey = this.getAttribute('data-unique-key');
                const productId = parseInt(this.getAttribute('data-product-id'));
                const finishedBatchId = this.getAttribute('data-finished-batch-id') || null;
                const checkbox = document.querySelector(`.car-item-checkbox[data-unique-key="${uniqueKey}"]`);
                if (!checkbox) return;
                
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
                
                let quantity = parseFloat(this.value) || 0;
                let showWarning = false;
                
                // التحقق من أن الكمية <= المتاحة مع رسالة تحذير
                if (quantity > availableQty) {
                    showWarning = true;
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                if (showWarning) {
                    // عرض تحذير بدون إزعاج (يمكن استخدام toast notification بدلاً من alert)
                    const warningMsg = `⚠️ الكمية المدخلة تتجاوز الكمية المتاحة (${availableQty.toFixed(2)}). تم تقليل الكمية تلقائياً.`;
                    // استخدام console.warn بدلاً من alert لتجنب الإزعاج أثناء الكتابة
                    console.warn(warningMsg);
                }
                
                // تحديث الإجمالي في الجدول فوراً
                const totalCell = this.closest('tr').querySelector('.car-item-total');
                if (totalCell) {
                    const itemTotal = quantity > 0 ? quantity * unitPrice : 0;
                    totalCell.textContent = formatCurrency(itemTotal);
                }
                
                // تحديث selectedCarItems فوراً باستخدام finished_batch_id للتمييز
                const itemIndex = selectedCarItems.findIndex(item => 
                    item.product_id === productId && 
                    (item.finished_batch_id === finishedBatchId || (item.finished_batch_id === null && finishedBatchId === null))
                );
                if (itemIndex !== -1 && checkbox.checked) {
                    selectedCarItems[itemIndex].quantity = quantity > 0 ? quantity : 0;
                    selectedCarItems[itemIndex].total_price = quantity > 0 ? quantity * unitPrice : 0;
                }
                
                // تحديث الإجماليات الكلية فوراً
                calculateTotals();
            });
            
            // event listener للتغيير (عند فقدان التركيز)
            input.addEventListener('change', function() {
                const uniqueKey = this.getAttribute('data-unique-key');
                const productId = parseInt(this.getAttribute('data-product-id'));
                const finishedBatchId = this.getAttribute('data-finished-batch-id') || null;
                const checkbox = document.querySelector(`.car-item-checkbox[data-unique-key="${uniqueKey}"]`);
                if (!checkbox) return;
                
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const unitPrice = parseFloat(checkbox.getAttribute('data-unit-price'));
                
                let quantity = parseFloat(this.value) || 0;
                let showWarning = false;
                
                if (quantity <= 0) {
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                if (quantity > availableQty) {
                    showWarning = true;
                    alert(`⚠️ الكمية المدخلة (${quantity.toFixed(2)}) تتجاوز الكمية المتاحة (${availableQty.toFixed(2)}).\nتم تقليل الكمية تلقائياً إلى ${availableQty.toFixed(2)}`);
                    quantity = availableQty;
                    this.value = availableQty;
                }
                
                // تحديث selectedCarItems باستخدام finished_batch_id للتمييز
                const itemIndex = selectedCarItems.findIndex(item => 
                    item.product_id === productId && 
                    (item.finished_batch_id === finishedBatchId || (item.finished_batch_id === null && finishedBatchId === null))
                );
                if (itemIndex !== -1 && checkbox.checked) {
                    selectedCarItems[itemIndex].quantity = quantity;
                    selectedCarItems[itemIndex].total_price = quantity * unitPrice;
                }
                
                // تحديث الإجماليات
                calculateTotals();
            });
            
        });
        
        // select all للمخزن
        document.getElementById('selectAllCarItems').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.car-item-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                cb.dispatchEvent(new Event('change'));
            });
        });
    }
    
    // تنفيذ الاستبدال
    document.getElementById('executeReplacementBtn').addEventListener('click', function() {
        if (selectedCustomerItems.length === 0 || selectedCarItems.length === 0) {
            alert('يرجى اختيار منتجات من العميل ومخزن السيارة');
            return;
        }
        
        // التحقق من الكميات قبل التنفيذ
        let validationErrors = [];
        
        selectedCustomerItems.forEach(item => {
            const invoiceItemId = item.invoice_item_id;
            const quantityInput = document.querySelector(`.customer-quantity-input[data-invoice-item-id="${invoiceItemId}"]`);
            const checkbox = document.querySelector(`.customer-item-checkbox[data-invoice-item-id="${invoiceItemId}"]`);
            
            if (quantityInput && checkbox) {
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                const quantity = parseFloat(quantityInput.value) || 0;
                
                if (quantity <= 0) {
                    validationErrors.push('يجب إدخال كمية أكبر من صفر للمنتج من مشتريات العميل');
                } else if (quantity > availableQty) {
                    validationErrors.push('الكمية المدخلة (' + quantity + ') أكبر من الكمية المتاحة (' + availableQty + ')');
                }
            }
        });
        
        selectedCarItems.forEach(item => {
            const productId = item.product_id;
            const finishedBatchId = item.finished_batch_id || null;
            // استخدام finished_batch_id للعثور على العنصر الصحيح
            const checkbox = Array.from(document.querySelectorAll('.car-item-checkbox')).find(cb => {
                const cbProductId = parseInt(cb.getAttribute('data-product-id'));
                const cbBatchId = cb.getAttribute('data-finished-batch-id') || null;
                return cbProductId === productId && 
                       (cbBatchId === finishedBatchId || (cbBatchId === null && finishedBatchId === null));
            });
            
            if (checkbox) {
                const availableQty = parseFloat(checkbox.getAttribute('data-available-quantity'));
                // استخدام item.quantity من selectedCarItems بدلاً من quantityInput.value
                const quantity = item.quantity || 0;
                
                if (quantity <= 0) {
                    validationErrors.push('يجب إدخال كمية أكبر من صفر للمنتج "' + (item.product_name || 'غير معروف') + '" من مخزن السيارة');
                } else if (quantity > availableQty) {
                    validationErrors.push('الكمية المدخلة (' + quantity.toFixed(2) + ') للمنتج "' + (item.product_name || 'غير معروف') + '" أكبر من الكمية المتاحة (' + availableQty.toFixed(2) + ')');
                }
            }
        });
        
        if (validationErrors.length > 0) {
            alert('يرجى تصحيح الأخطاء التالية:\n\n' + validationErrors.join('\n'));
            return;
        }
        
        // عرض ملخص العملية
        const customerTotal = selectedCustomerItems.reduce((sum, item) => sum + (item.total_price || 0), 0);
        const carTotal = selectedCarItems.reduce((sum, item) => sum + (item.total_price || 0), 0);
        const difference = customerTotal - carTotal;
        
        let confirmMessage = 'هل أنت متأكد من تنفيذ عملية الاستبدال؟\n\n';
        confirmMessage += 'إجمالي منتجات العميل: ' + formatCurrency(customerTotal) + '\n';
        confirmMessage += 'إجمالي منتجات السيارة: ' + formatCurrency(carTotal) + '\n';
        confirmMessage += 'الفرق: ' + formatCurrency(Math.abs(difference)) + '\n\n';
        
        if (Math.abs(difference) < 0.01) {
            confirmMessage += 'لا يوجد فرق في القيمة.';
        } else if (difference < 0) {
            confirmMessage += 'سيتم إضافة ' + formatCurrency(Math.abs(difference)) + ' إلى رصيد العميل المدين.';
        } else {
            confirmMessage += 'سيتم خصم ' + formatCurrency(difference) + ' من رصيد العميل وخصم 2% من راتب المندوب.';
        }
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التنفيذ...';
        
        const formData = new FormData();
        formData.append('customer_id', currentCustomerId);
        formData.append('customer_items', JSON.stringify(selectedCustomerItems));
        formData.append('car_items', JSON.stringify(selectedCarItems));
        
        const basePath = '<?php echo getBasePath(); ?>';
        const processApiPath = basePath + '/api/process_replacement.php';
        fetch(processApiPath, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('تم تنفيذ عملية الاستبدال بنجاح!\nرقم العملية: ' + (data.exchange_number || ''));
                    
                    // إغلاق المودال وإزالة backdrop
                    const modal = bootstrap.Modal.getInstance(replacementModal);
                    if (modal) {
                        modal.hide();
                    }
                    
                    // إزالة backdrop يدوياً
                    setTimeout(function() {
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                    }, 300);
                    
                    // إعادة تحميل الصفحة
                    window.location.reload();
                } else {
                    throw new Error(data.message || 'فشل تنفيذ العملية');
                }
            })
            .catch(error => {
                alert('حدث خطأ: ' + error.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تنفيذ الاستبدال';
            });
    });
    
    // إعادة تعيين عند إغلاق المودال
    replacementModal.addEventListener('hidden.bs.modal', function() {
        selectedCustomerItems = [];
        selectedCarItems = [];
        customerPurchases = [];
        carInventory = [];
        document.getElementById('selectAllCustomerItems').checked = false;
        document.getElementById('selectAllCarItems').checked = false;
        
        // إزالة جميع backdrops إذا بقيت موجودة
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(function(backdrop) {
            backdrop.remove();
        });
        
        // إزالة class modal-open من body إذا بقي موجوداً
        document.body.classList.remove('modal-open');
        
        // إزالة style overflow: hidden من body
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    });
    
    // إزالة backdrop عند بدء إخفاء المودال أيضاً
    replacementModal.addEventListener('hide.bs.modal', function() {
        // التأكد من إزالة backdrop عند الإغلاق
        setTimeout(function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(function(backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }, 100);
    });
    
    // إزالة backdrop عند بدء إخفاء المودال أيضاً
    replacementModal.addEventListener('hide.bs.modal', function() {
        // التأكد من إزالة backdrop عند الإغلاق
        setTimeout(function() {
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }, 100);
    });
})();
</script>
