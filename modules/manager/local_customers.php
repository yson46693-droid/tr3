<?php
/**
 * صفحة إدارة العملاء المحليين للمدير والمحاسب
 * منفصلة تماماً عن عملاء المندوبين
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// إضافة Permissions-Policy header للسماح بالوصول إلى Geolocation, Camera, Microphone, Notifications
if (!headers_sent()) {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self), notifications=(self)");
    // Feature-Policy كبديل للمتصفحات القديمة
    header("Feature-Policy: geolocation 'self'; camera 'self'; microphone 'self'; notifications 'self'");
}

if (!defined('LOCAL_CUSTOMERS_MODULE_BOOTSTRAPPED')) {
    define('LOCAL_CUSTOMERS_MODULE_BOOTSTRAPPED', true);

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/audit_log.php';
    require_once __DIR__ . '/../../includes/path_helper.php';

    requireRole(['accountant', 'manager']);
}

if (!defined('LOCAL_CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
    require_once __DIR__ . '/../../includes/table_styles.php';
}

$currentUser = getCurrentUser();
$db = db();

// التأكد من وجود الجداول
try {
    $localCustomersTable = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (empty($localCustomersTable)) {
        // تحميل ملف SQL وإنشاء الجداول
        $sqlFile = __DIR__ . '/../../database/migrations/create_local_customers_tables.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            // تنفيذ كل استعلام على حدة
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                    try {
                        $db->execute($statement);
                    } catch (Throwable $e) {
                        error_log('Error executing migration: ' . $e->getMessage());
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Error checking local_customers table: ' . $e->getMessage());
}

// معالجة update_location قبل أي شيء آخر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
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
        $customer = $db->queryOne("SELECT id FROM local_customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            throw new InvalidArgumentException('العميل المطلوب غير موجود.');
        }

        $db->execute(
            "UPDATE local_customers SET latitude = ?, longitude = ?, location_captured_at = NOW() WHERE id = ?",
            [$latitude, $longitude, $customerId]
        );

        logAudit(
            $currentUser['id'],
            'update_local_customer_location',
            'local_customer',
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
        error_log('Update local customer location error: ' . $updateLocationError->getMessage());
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

// قراءة الرسائل من session
applyPRGPattern($error, $success);

$customerStats = [
    'total_count' => 0,
    'debtor_count' => 0,
    'total_debt' => 0.0,
];
$totalCollectionsAmount = 0.0;

// تحديد المسار الأساسي للروابط
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$localCustomersBaseScript = 'manager.php';
if ($currentRole === 'accountant') {
    $localCustomersBaseScript = 'accountant.php';
}
$localCustomersPageBase = $localCustomersBaseScript . '?page=local_customers';

// معالجة POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
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
                    "SELECT id, name, balance FROM local_customers WHERE id = ? FOR UPDATE",
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
                    "UPDATE local_customers SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );

                logAudit(
                    $currentUser['id'],
                    'collect_local_customer_debt',
                    'local_customer',
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

                // حفظ التحصيل في جدول local_collections
                $localCollectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
                if (!empty($localCollectionsTableExists)) {
                    $hasStatusColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'status'"));
                    $hasCollectionNumberColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'collection_number'"));
                    $hasNotesColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'notes'"));

                    // توليد رقم التحصيل
                    if ($hasCollectionNumberColumn) {
                        $year = date('Y');
                        $month = date('m');
                        $lastCollection = $db->queryOne(
                            "SELECT collection_number FROM local_collections WHERE collection_number LIKE ? ORDER BY collection_number DESC LIMIT 1 FOR UPDATE",
                            ["LOC-COL-{$year}{$month}-%"]
                        );

                        $serial = 1;
                        if (!empty($lastCollection['collection_number'])) {
                            $parts = explode('-', $lastCollection['collection_number']);
                            $serial = intval($parts[3] ?? 0) + 1;
                        }

                        $collectionNumber = sprintf("LOC-COL-%s%s-%04d", $year, $month, $serial);
                    }

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
                        $collectionValues[] = 'تحصيل من صفحة العملاء المحليين';
                        $collectionPlaceholders[] = '?';
                    }

                    if ($hasStatusColumn) {
                        $collectionColumns[] = 'status';
                        // التحصيلات من هذه الصفحة تُضاف كإيراد معتمد مباشرة في خزنة الشركة
                        // لذلك يجب أن تكون معتمدة مباشرة
                        $collectionValues[] = 'approved';
                        $collectionPlaceholders[] = '?';
                    }

                    $db->execute(
                        "INSERT INTO local_collections (" . implode(', ', $collectionColumns) . ") VALUES (" . implode(', ', $collectionPlaceholders) . ")",
                        $collectionValues
                    );

                    $collectionId = $db->getLastInsertId();

                    logAudit(
                        $currentUser['id'],
                        'add_local_collection_from_customers_page',
                        'local_collection',
                        $collectionId,
                        null,
                        [
                            'collection_number' => $collectionNumber,
                            'customer_id' => $customerId,
                            'amount' => $amount,
                        ]
                    );
                }

                // توزيع التحصيل على الفواتير المحلية (إن وجدت)
                $localInvoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'local_invoices'");
                if (!empty($localInvoicesTableExists)) {
                    try {
                        require_once __DIR__ . '/../../includes/local_invoices_helper.php';
                        if (function_exists('distributeLocalCollectionToInvoices')) {
                            distributeLocalCollectionToInvoices($customerId, $amount, $currentUser['id']);
                        }
                    } catch (Throwable $e) {
                        error_log('Error distributing local collection to invoices: ' . $e->getMessage());
                    }
                }

                // إضافة إيراد معتمد في خزنة الشركة (accountant_transactions)
                try {
                    // التأكد من وجود جدول accountant_transactions
                    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                    if (!empty($accountantTableExists)) {
                        // التحقق من وجود عمود local_customer_id وإضافته إذا لم يكن موجوداً
                        $hasLocalCustomerIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM accountant_transactions LIKE 'local_customer_id'"));
                        
                        // التحقق من وجود عمود local_collection_id وإضافته إذا لم يكن موجوداً
                        $hasLocalCollectionIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM accountant_transactions LIKE 'local_collection_id'"));
                        
                        // إعداد الوصف
                        $customerName = $customer['name'] ?? 'عميل محلي';
                        $description = 'تحصيل من عميل محلي: ' . $customerName;
                        
                        // إعداد رقم مرجعي
                        $referenceNumber = $collectionNumber ?? ('LOC-CUST-' . $customerId . '-' . date('YmdHis'));
                        
                        // إعداد الأعمدة والقيم
                        $transactionColumns = [
                            'transaction_type',
                            'amount',
                            'description',
                            'reference_number',
                            'payment_method',
                            'status',
                            'created_by',
                            'approved_by',
                            'approved_at'
                        ];
                        
                        $transactionValues = [
                            'income',
                            $amount,
                            $description,
                            $referenceNumber,
                            'cash',
                            'approved',
                            $currentUser['id'],
                            $currentUser['id'],
                            date('Y-m-d H:i:s')
                        ];
                        
                        // إضافة local_customer_id إذا كان العمود موجوداً
                        if ($hasLocalCustomerIdColumn) {
                            $transactionColumns[] = 'local_customer_id';
                            $transactionValues[] = $customerId;
                        }
                        
                        // إضافة local_collection_id إذا كان العمود موجوداً وكان هناك collection_id
                        if ($hasLocalCollectionIdColumn && $collectionId !== null) {
                            $transactionColumns[] = 'local_collection_id';
                            $transactionValues[] = $collectionId;
                        }
                        
                        $transactionPlaceholders = array_fill(0, count($transactionColumns), '?');
                        
                        // إدراج السجل في accountant_transactions
                        $db->execute(
                            "INSERT INTO accountant_transactions (" . implode(', ', $transactionColumns) . ") 
                             VALUES (" . implode(', ', $transactionPlaceholders) . ")",
                            $transactionValues
                        );
                        
                        $transactionId = $db->getLastInsertId();
                        
                        logAudit(
                            $currentUser['id'],
                            'add_income_from_local_customer_collection',
                            'accountant_transaction',
                            $transactionId,
                            null,
                            [
                                'local_customer_id' => $customerId,
                                'amount' => $amount,
                                'collection_id' => $collectionId,
                                'reference_number' => $referenceNumber,
                            ]
                        );
                    }
                } catch (Throwable $incomeError) {
                    error_log('Error adding income from local customer collection: ' . $incomeError->getMessage());
                    // لا نوقف العملية في حالة فشل إضافة الإيراد، فقط نسجل الخطأ
                }

                $db->commit();
                $transactionStarted = false;

                $messageParts = ['تم تحصيل المبلغ بنجاح.'];
                if ($collectionNumber !== null) {
                    $messageParts[] = 'رقم التحصيل: ' . $collectionNumber . '.';
                }

                $_SESSION['success_message'] = implode(' ', array_filter($messageParts));

                redirectAfterPost(
                    'local_customers',
                    [],
                    [],
                    $currentRole
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
                error_log('Local customer collection error: ' . $collectionError->getMessage());
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
                // التحقق من عدم تكرار بيانات العميل
                $duplicateCheckConditions = ["name = ?"];
                $duplicateCheckParams = [$name];
                
                if (!empty($phone)) {
                    $duplicateCheckConditions[] = "phone = ?";
                    $duplicateCheckParams[] = $phone;
                }
                
                if (!empty($address)) {
                    $duplicateCheckConditions[] = "address = ?";
                    $duplicateCheckParams[] = $address;
                }
                
                $duplicateQuery = "SELECT id, name, phone, address FROM local_customers WHERE " . implode(" AND ", $duplicateCheckConditions) . " LIMIT 1";
                $duplicateCustomer = $db->queryOne($duplicateQuery, $duplicateCheckParams);
                
                if ($duplicateCustomer) {
                    $duplicateInfo = [];
                    if (!empty($duplicateCustomer['phone'])) {
                        $duplicateInfo[] = "رقم الهاتف: " . $duplicateCustomer['phone'];
                    }
                    if (!empty($duplicateCustomer['address'])) {
                        $duplicateInfo[] = "العنوان: " . $duplicateCustomer['address'];
                    }
                    $duplicateMessage = "يوجد عميل محلي مسجل مسبقاً بنفس البيانات";
                    if (!empty($duplicateInfo)) {
                        $duplicateMessage .= " (" . implode(", ", $duplicateInfo) . ")";
                    }
                    $duplicateMessage .= ". يرجى اختيار العميل الموجود من القائمة أو تعديل البيانات.";
                    throw new InvalidArgumentException($duplicateMessage);
                }

                // التحقق من وجود أعمدة اللوكيشن
                $hasLatitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'latitude'"));
                $hasLongitudeColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'longitude'"));
                $hasLocationCapturedAtColumn = !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'location_captured_at'"));
                
                $customerColumns = ['name', 'phone', 'balance', 'address', 'status', 'created_by'];
                $customerValues = [
                    $name,
                    $phone ?: null,
                    $balance,
                    $address ?: null,
                    'active',
                    $currentUser['id'],
                ];
                $customerPlaceholders = ['?', '?', '?', '?', '?', '?'];
                
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
                    "INSERT INTO local_customers (" . implode(', ', $customerColumns) . ") 
                     VALUES (" . implode(', ', $customerPlaceholders) . ")",
                    $customerValues
                );

                logAudit($currentUser['id'], 'add_local_customer', 'local_customer', $result['insert_id'], null, [
                    'name' => $name
                ]);

                $_SESSION['success_message'] = 'تم إضافة العميل المحلي بنجاح';

                redirectAfterPost(
                    'local_customers',
                    [],
                    [],
                    $currentRole
                );
            } catch (InvalidArgumentException $userError) {
                $error = $userError->getMessage();
            } catch (Throwable $addCustomerError) {
                error_log('Add local customer error: ' . $addCustomerError->getMessage());
                $error = 'حدث خطأ أثناء إضافة العميل. يرجى المحاولة لاحقاً.';
            }
        }
    }
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
        FROM local_customers c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM local_customers WHERE 1=1";
$statsSql = "SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS debtor_count,
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt
            FROM local_customers
            WHERE 1=1";
$params = [];
$countParams = [];
$statsParams = [];

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
    // حساب إجمالي التحصيلات من جميع العملاء المحليين (بغض النظر عن الفلتر)
    $localCollectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
    if (!empty($localCollectionsTableExists)) {
        $collectionsStatusExists = false;
        $statusCheck = $db->query("SHOW COLUMNS FROM local_collections LIKE 'status'");
        if (!empty($statusCheck)) {
            $collectionsStatusExists = true;
        }

        // حساب إجمالي التحصيلات من جميع العملاء المحليين بدون فلاتر
        // نحسب جميع التحصيلات (pending و approved) لأنها جميعاً من العملاء المحليين
        $collectionsSql = "SELECT COALESCE(SUM(col.amount), 0) AS total_collections
                           FROM local_collections col";
        $collectionsParams = [];

        // حساب جميع التحصيلات (pending و approved) - نستثني المرفوضة فقط
        if ($collectionsStatusExists) {
            $collectionsSql .= " WHERE col.status IN ('pending', 'approved')";
        }

        $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
        if (!empty($collectionsResult)) {
            $totalCollectionsAmount = (float)($collectionsResult['total_collections'] ?? 0);
        }
    } else {
        // إذا لم يكن جدول local_collections موجوداً، استخدم 0
        $totalCollectionsAmount = 0.0;
    }
} catch (Throwable $collectionsError) {
    error_log('Local customers collections summary error: ' . $collectionsError->getMessage());
    $totalCollectionsAmount = 0.0;
}

$summaryDebtorCount = $customerStats['debtor_count'] ?? 0;
$summaryTotalDebt = $customerStats['total_debt'] ?? 0.0;
$summaryTotalCustomers = $customerStats['total_count'] ?? $totalCustomers;
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <h2 class="mb-2 mb-md-0">
        <i class="bi bi-people me-2"></i>العملاء المحليين
    </h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocalCustomerModal">
        <i class="bi bi-person-plus me-2"></i>إضافة عميل محلي جديد
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">عدد العملاء المحليين</div>
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
                    <div class="text-muted small fw-semibold">العملاء المدينون</div>
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
                    <div class="text-muted small fw-semibold">إجمالي الديون</div>
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
                    <div class="text-muted small fw-semibold">إجمالي التحصيلات</div>
                    <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($totalCollectionsAmount); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
</div>

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
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-2 g-md-3 align-items-end">
            <input type="hidden" name="page" value="local_customers">
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
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة العملاء المحليين (<?php echo $totalCustomers; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
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
                            <td colspan="7" class="text-center text-muted">لا توجد عملاء محليين</td>
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
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info js-local-customer-purchase-history"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        >
                                            <i class="bi bi-receipt me-1"></i>سجل مشتريات
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-warning js-local-customer-return-products"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-arrow-return-left me-1"></i>إرجاع منتجات
                                        </button>
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
                    <a class="page-link" href="?page=local_customers&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=local_customers&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=local_customers&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=local_customers&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=local_customers&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
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
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل المحلي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="collect_debt">
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

<!-- Modal إضافة عميل محلي جديد -->
<div class="modal fade" id="addLocalCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عميل محلي جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="add_customer">
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

<!-- Modal سجل مشتريات العميل المحلي -->
<div class="modal fade" id="localCustomerPurchaseHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>
                    سجل مشتريات العميل المحلي
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
                                <div class="fs-5 fw-bold" id="localPurchaseHistoryCustomerName">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">الهاتف</div>
                                <div id="localPurchaseHistoryCustomerPhone">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">العنوان</div>
                                <div id="localPurchaseHistoryCustomerAddress">-</div>
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
                                       id="localPurchaseHistorySearchProduct" 
                                       placeholder="البحث باسم المنتج">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" 
                                       id="localPurchaseHistorySearchBatch" 
                                       placeholder="البحث برقم التشغيلة">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-primary btn-sm w-100" 
                                        onclick="loadLocalCustomerPurchaseHistory()">
                                    <i class="bi bi-search me-1"></i>بحث
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading -->
                <div class="text-center py-4" id="localPurchaseHistoryLoading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>

                <!-- Error -->
                <div class="alert alert-danger d-none" id="localPurchaseHistoryError"></div>

                <!-- Purchase History Table -->
                <div id="localPurchaseHistoryTable" class="d-none">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">
                                        <input type="checkbox" id="localSelectAllItems" onchange="localToggleAllItems()">
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
                            <tbody id="localPurchaseHistoryTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="printLocalCustomerStatementBtn" onclick="printLocalCustomerStatement()" style="display: none;">
                    <i class="bi bi-printer me-1"></i>طباعة كشف الحساب
                </button>
                <button type="button" class="btn btn-success" id="localCustomerReturnBtn" onclick="openLocalCustomerReturnModal()" style="display: none;">
                    <i class="bi bi-arrow-return-left me-1"></i>إرجاع منتجات
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal عرض موقع العميل -->
<div class="modal fade" id="viewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل المحلي</h5>
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
                        allow="geolocation; camera; microphone"
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    // معالج نموذج التحصيل
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

    // معالج الموقع الجغرافي
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

            // التحقق من الصلاحيات قبل الطلب
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                    if (result.state === 'denied') {
                        showAlert('تم رفض إذن الموقع الجغرافي. يرجى السماح بالوصول في إعدادات المتصفح.');
                        return;
                    }
                    requestGeolocation();
                }).catch(function() {
                    // إذا فشل query، حاول مباشرة
                    requestGeolocation();
                });
            } else {
                requestGeolocation();
            }

            function requestGeolocation() {
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
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        setButtonLoading(button, false);
                        if (data.success) {
                            showAlert('تم حفظ الموقع بنجاح!');
                            location.reload();
                        } else {
                            showAlert(data.message || 'حدث خطأ أثناء حفظ الموقع.');
                        }
                    })
                    .catch(function (error) {
                        setButtonLoading(button, false);
                        showAlert('حدث خطأ في الاتصال بالخادم.');
                        console.error('Error:', error);
                    });
                }, function (error) {
                    setButtonLoading(button, false);
                    var errorMessage = 'تعذر الحصول على الموقع.';
                    if (error.code === 1) {
                        errorMessage = 'تم رفض طلب الحصول على الموقع. يرجى السماح بالوصول إلى الموقع في إعدادات المتصفح.';
                    } else if (error.code === 2) {
                        errorMessage = 'تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.';
                    } else if (error.code === 3) {
                        errorMessage = 'انتهت مهلة طلب الموقع. يرجى المحاولة مرة أخرى.';
                    }
                    showAlert(errorMessage);
                }, {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                });
            }
        });
    });

    if (locationViewButtons && locationViewButtons.length > 0 && viewLocationModal) {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var customerName = button.getAttribute('data-customer-name') || '-';
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');

                if (locationCustomerName) {
                    locationCustomerName.textContent = customerName;
                }

                if (latitude && longitude && locationMapFrame) {
                    var mapUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                    locationMapFrame.src = mapUrl;

                    if (locationExternalLink) {
                        locationExternalLink.href = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                    }

                    var modal = new bootstrap.Modal(viewLocationModal);
                    modal.show();
                }
            });
        });
    }

    // معالج الحصول على الموقع عند إضافة عميل جديد
    var getLocationBtn = document.getElementById('getLocationBtn');
    var addCustomerLatitudeInput = document.getElementById('addCustomerLatitude');
    var addCustomerLongitudeInput = document.getElementById('addCustomerLongitude');
    var addCustomerModal = document.getElementById('addLocalCustomerModal');

    if (getLocationBtn && addCustomerLatitudeInput && addCustomerLongitudeInput) {
        getLocationBtn.addEventListener('click', function() {
            if (!navigator.geolocation) {
                showAlert('المتصفح لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

            var button = this;
            var originalText = button.innerHTML;
            
            // التحقق من الصلاحيات قبل الطلب
            function requestGeolocationForNewCustomer() {
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
                            errorMessage = 'تم رفض طلب الحصول على الموقع. يرجى السماح بالوصول إلى الموقع في إعدادات المتصفح.';
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
            }
            
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                    if (result.state === 'denied') {
                        showAlert('تم رفض إذن الموقع الجغرافي. يرجى السماح بالوصول في إعدادات المتصفح.');
                        return;
                    }
                    requestGeolocationForNewCustomer();
                }).catch(function() {
                    // إذا فشل query، حاول مباشرة
                    requestGeolocationForNewCustomer();
                });
            } else {
                requestGeolocationForNewCustomer();
            }
        });
    }

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

// إعادة تحميل الصفحة تلقائياً بعد أي رسالة
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        setTimeout(function() {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();

// معالجة سجل مشتريات العملاء المحليين
var currentLocalCustomerId = null;
var currentLocalCustomerName = null;
var localPurchaseHistoryData = [];
var localSelectedItemsForReturn = [];

// دالة طباعة كشف حساب العميل المحلي
function printLocalCustomerStatement() {
    if (!currentLocalCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    // استخدام صفحة طباعة كشف الحساب مع تحديد نوع العميل
    const printUrl = basePath + '/print_customer_statement.php?customer_id=' + encodeURIComponent(currentLocalCustomerId) + '&type=local';
    window.open(printUrl, '_blank');
}

// دالة فتح modal إرجاع المنتجات للعميل المحلي
function openLocalCustomerReturnModal() {
    if (!currentLocalCustomerId) {
        alert('يرجى فتح سجل مشتريات عميل أولاً');
        return;
    }
    if (localSelectedItemsForReturn.length === 0) {
        alert('يرجى تحديد منتج واحد على الأقل للإرجاع');
        return;
    }
    // سيتم تنفيذ هذه الوظيفة لاحقاً
    alert('وظيفة إرجاع المنتجات للعملاء المحليين قيد التطوير');
}

// دالة تحميل سجل المشتريات للعميل المحلي
function loadLocalCustomerPurchaseHistory() {
    if (!currentLocalCustomerId) {
        return;
    }
    
    const basePath = '<?php echo getBasePath(); ?>';
    const loadingElement = document.getElementById('localPurchaseHistoryLoading');
    const contentElement = document.getElementById('localPurchaseHistoryTable');
    const errorElement = document.getElementById('localPurchaseHistoryError');
    const tableBody = document.getElementById('localPurchaseHistoryTableBody');
    
    // إظهار loading وإخفاء المحتوى
    if (loadingElement) loadingElement.classList.remove('d-none');
    if (contentElement) contentElement.classList.add('d-none');
    if (errorElement) {
        errorElement.classList.add('d-none');
        errorElement.textContent = '';
    }
    
    // جلب البيانات من API (سنستخدم نفس API مع تحديد نوع العميل)
    fetch(basePath + '/api/customer_purchase_history.php?action=get_history&customer_id=' + currentLocalCustomerId + '&type=local', {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('استجابة غير صحيحة من الخادم');
            });
        }
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            });
        }
        return response.json();
    })
    .then(data => {
        if (loadingElement) loadingElement.classList.add('d-none');
        
        if (data.success && data.purchase_history) {
            localPurchaseHistoryData = data.purchase_history || [];
            displayLocalPurchaseHistory(localPurchaseHistoryData);
            if (contentElement) contentElement.classList.remove('d-none');
            
            // إظهار زر الطباعة
            const printBtn = document.getElementById('printLocalCustomerStatementBtn');
            if (printBtn) printBtn.style.display = 'inline-block';
        } else {
            if (errorElement) {
                errorElement.textContent = data.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                errorElement.classList.remove('d-none');
            }
        }
    })
    .catch(error => {
        if (loadingElement) loadingElement.classList.add('d-none');
        if (errorElement) {
            errorElement.textContent = 'خطأ: ' + (error.message || 'حدث خطأ في الاتصال بالخادم');
            errorElement.classList.remove('d-none');
        }
        console.error('Error loading purchase history:', error);
    });
}

// دالة عرض سجل المشتريات
function displayLocalPurchaseHistory(history) {
    const tableBody = document.getElementById('localPurchaseHistoryTableBody');
    
    if (!tableBody) {
        console.error('localPurchaseHistoryTableBody element not found');
        return;
    }
    
    tableBody.innerHTML = '';
    
    if (!history || history.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4"><i class="bi bi-info-circle me-2"></i>لا توجد مشتريات مسجلة لهذا العميل</td></tr>';
        return;
    }
    
    history.forEach(function(item) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                ${item.can_return ? `<input type="checkbox" class="local-item-checkbox" 
                       data-invoice-id="${item.invoice_id}"
                       data-invoice-item-id="${item.invoice_item_id}"
                       data-product-id="${item.product_id}"
                       data-product-name="${item.product_name}"
                       data-unit-price="${item.unit_price}"
                       data-batch-number-ids='${JSON.stringify(item.batch_number_ids || [])}'
                       data-batch-numbers='${JSON.stringify(item.batch_numbers || [])}'
                       onchange="localUpdateSelectedItems()">` : '-'}
            </td>
            <td>${item.invoice_number || '-'}</td>
            <td>${item.batch_numbers ? (Array.isArray(item.batch_numbers) ? item.batch_numbers.join(', ') : item.batch_numbers) : '-'}</td>
            <td>${item.product_name || '-'}</td>
            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
            <td>${parseFloat(item.returned_quantity || 0).toFixed(2)}</td>
            <td><strong>${parseFloat(item.available_to_return || 0).toFixed(2)}</strong></td>
            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
            <td>${item.invoice_date || '-'}</td>
            <td>
                ${item.can_return ? `<button class="btn btn-sm btn-primary" 
                        onclick="localSelectItemForReturn(${item.invoice_item_id}, ${item.product_id})"
                        title="إرجاع جزئي">
                    <i class="bi bi-arrow-return-left"></i>
                </button>` : '<span class="text-muted small">-</span>'}
            </td>
        `;
        tableBody.appendChild(row);
    });
}

// دوال مساعدة
function localToggleAllItems() {
    const selectAll = document.getElementById('localSelectAllItems');
    const checkboxes = document.querySelectorAll('.local-item-checkbox');
    
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = selectAll.checked;
    });
    
    localUpdateSelectedItems();
}

function localUpdateSelectedItems() {
    const checkboxes = document.querySelectorAll('.local-item-checkbox:checked');
    localSelectedItemsForReturn = [];
    
    checkboxes.forEach(function(checkbox) {
        const row = checkbox.closest('tr');
        const available = parseFloat(row.querySelector('td:nth-child(7)').textContent.trim());
        const invoiceNumber = row.querySelector('td:nth-child(2)').textContent.trim();
        
        const invoiceItemId = parseInt(checkbox.dataset.invoiceItemId);
        let latestAvailable = available;
        
        if (localPurchaseHistoryData && localPurchaseHistoryData.length > 0) {
            const historyItem = localPurchaseHistoryData.find(function(h) {
                return h.invoice_item_id === invoiceItemId;
            });
            if (historyItem) {
                latestAvailable = parseFloat(historyItem.available_to_return) || 0;
            }
        }
        
        if (latestAvailable > 0) {
            localSelectedItemsForReturn.push({
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
    
    const returnBtn = document.getElementById('localCustomerReturnBtn');
    if (returnBtn) {
        returnBtn.style.display = localSelectedItemsForReturn.length > 0 ? 'inline-block' : 'none';
    }
}

function localSelectItemForReturn(invoiceItemId, productId) {
    const checkbox = document.querySelector(`.local-item-checkbox[data-invoice-item-id="${invoiceItemId}"][data-product-id="${productId}"]`);
    if (checkbox) {
        checkbox.checked = true;
        localUpdateSelectedItems();
        openLocalCustomerReturnModal();
    }
}

// معالج فتح modal سجل المشتريات
document.addEventListener('DOMContentLoaded', function() {
    const purchaseHistoryModal = document.getElementById('localCustomerPurchaseHistoryModal');
    
    // استخدام event delegation للتعامل مع الأزرار الديناميكية
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.js-local-customer-purchase-history');
        if (!button) return;
        
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        const customerPhone = button.getAttribute('data-customer-phone') || '-';
        const customerAddress = button.getAttribute('data-customer-address') || '-';
        
        if (!customerId) return;
        
        currentLocalCustomerId = customerId;
        currentLocalCustomerName = customerName;
        
        // تعيين معلومات العميل في الـ modal
        const nameElement = document.getElementById('localPurchaseHistoryCustomerName');
        const phoneElement = document.getElementById('localPurchaseHistoryCustomerPhone');
        const addressElement = document.getElementById('localPurchaseHistoryCustomerAddress');
        
        if (nameElement) nameElement.textContent = customerName || '-';
        if (phoneElement) phoneElement.textContent = customerPhone || '-';
        if (addressElement) addressElement.textContent = customerAddress || '-';
        
        // إظهار loading وإخفاء المحتوى
        const loadingElement = document.getElementById('localPurchaseHistoryLoading');
        const contentElement = document.getElementById('localPurchaseHistoryTable');
        const errorElement = document.getElementById('localPurchaseHistoryError');
        
        if (loadingElement) loadingElement.classList.remove('d-none');
        if (contentElement) contentElement.classList.add('d-none');
        if (errorElement) {
            errorElement.classList.add('d-none');
            errorElement.textContent = '';
        }
        
        // إخفاء الأزرار مؤقتاً
        const printBtn = document.getElementById('printLocalCustomerStatementBtn');
        const returnBtn = document.getElementById('localCustomerReturnBtn');
        if (printBtn) printBtn.style.display = 'none';
        if (returnBtn) returnBtn.style.display = 'none';
        
        // إعادة تعيين العناصر المحددة
        localSelectedItemsForReturn = [];
        
        // إظهار الـ modal
        if (purchaseHistoryModal) {
            const modal = new bootstrap.Modal(purchaseHistoryModal);
            modal.show();
            
            // تحميل البيانات بعد إظهار الـ modal
            purchaseHistoryModal.addEventListener('shown.bs.modal', function loadData() {
                purchaseHistoryModal.removeEventListener('shown.bs.modal', loadData);
                
                // تحميل سجل المشتريات
                loadLocalCustomerPurchaseHistory();
            }, { once: true });
        }
    });
    
    // معالج زر إرجاع المنتجات - يفتح modal سجل المشتريات مباشرة
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.js-local-customer-return-products');
        if (!button) return;
        
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        
        if (!customerId) return;
        
        // استخدام نفس معالج زر سجل المشتريات
        const purchaseHistoryButton = document.querySelector('.js-local-customer-purchase-history[data-customer-id="' + customerId + '"]');
        if (purchaseHistoryButton) {
            purchaseHistoryButton.click();
        }
    });
    
    // إعادة تعيين المتغيرات عند إغلاق الـ modal
    if (purchaseHistoryModal) {
        purchaseHistoryModal.addEventListener('hidden.bs.modal', function() {
            currentLocalCustomerId = null;
            currentLocalCustomerName = null;
            localSelectedItemsForReturn = [];
            localPurchaseHistoryData = [];
        });
    }
});
</script>
